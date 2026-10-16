/**
 * Matomo / GA4 events for clicks inside chatbot answers and on suggested
 * questions — and, opt-in, for the chat window being opened.
 *
 * MxChat already logs *some* of this server-side, into
 * {$wpdb->prefix}mxchat_url_clicks, but only for absolute http(s) links: both
 * attachLinkTracking() and its delegated fallback on `.chat-box a[href]` gate
 * on `href.startsWith('http://' | 'https://')` (js/chat-script.js, MxChat
 * 3.2.25). Relative links and suggested questions are invisible to it, and it
 * records nothing about internal vs external. This script is the client-side
 * half that fills those gaps, and it reports to the analytics stack the site
 * already runs rather than to a table of ours.
 *
 * Two host behaviours shape everything below and are documented at their call
 * sites: the host binds its own handler directly on each <a> and stops
 * propagation (so we must listen in the CAPTURE phase), and since MxChat
 * 3.2.24 it never cancels a link click — the browser follows the anchor itself
 * while the host records the click with sendBeacon — so nothing navigates on
 * the visitor's behalf any more, and we must never cancel an event either.
 *
 * Loaded with no dependencies: it runs whether or not jQuery, Matomo or gtag
 * are on the page, and degrades to doing nothing when a destination is missing.
 */
(function () {
    'use strict';

    var cfg = window.mxchatPlusTracking || {};

    /**
     * wp_localize_script stringifies every value it passes: a PHP `false`
     * arrives as "" and a PHP `true` as "1", so a plain truthiness test on the
     * raw value reads "0" as enabled. Absent keys fall back to the default
     * rather than to false, so a stale localize payload cannot silently turn
     * tracking off.
     */
    function readBool(v, defaultVal) {
        if (v === undefined || v === null) {
            return defaultVal;
        }
        return !(v === false || v === '' || v === '0' || v === 0);
    }

    var trackMatomo   = readBool(cfg.trackMatomo, true);
    var trackGa4      = readBool(cfg.trackGa4, false);
    var sendSessionId = readBool(cfg.sendSessionId, true);
    // Opt-in, and off for a stale payload that predates the key: turning it on
    // adds a new action to the site's existing Matomo reports.
    var trackChatOpen = readBool(cfg.trackChatOpen, false);
    var debug         = readBool(cfg.debug, false);

    // 0 means "no custom dimension configured"; so does an unparseable value.
    var dimensionId = parseInt(cfg.matomoDimensionId, 10);
    if (isNaN(dimensionId) || dimensionId < 0) {
        dimensionId = 0;
    }

    /**
     * The assistant's own bubbles, and only those. `.chat-box` must NEVER be
     * added to this list: it would also match links inside `.user-message`, so
     * every URL a visitor typed or pasted into the chat would be shipped to
     * Matomo as an event label.
     */
    var CHAT_SELECTOR = '.bot-message, .agent-message';

    var SUGGESTION_SELECTOR = '.mxchat-popular-question';

    // Never tracked: these open a mail client or dialler, not a destination we
    // can meaningfully report on.
    var SKIPPED_PROTOCOLS = /^(mailto:|tel:|sms:|javascript:)/i;

    var DEDUP_MS = 500;
    var MAX_LABEL = 120;

    /**
     * Matomo action labels. These are analytics dimensions, not UI strings:
     * they are the row keys of the user's existing Matomo reports. Translating
     * them — or passing them through any i18n layer — would start a fresh set
     * of rows and fork the history in two at the day of the update. They stay
     * in French, exactly as the standalone plugin wrote them, and any new one
     * follows the same convention.
     */
    var ACTION_INTERNAL   = 'Clic lien interne';
    var ACTION_EXTERNAL   = 'Clic lien externe';
    var ACTION_SUGGESTION = 'Clic suggestion';
    var ACTION_OPEN       = 'Ouverture chat';

    function log() {
        if (!debug) {
            return;
        }
        try {
            var args = Array.prototype.slice.call(arguments);
            args.unshift('[MxChat Plus tracking]');
            window.console.log.apply(window.console, args);
        } catch (e) {
            // console is absent or throws in some embedded webviews. A
            // debugging aid must never be what breaks a visitor's navigation.
        }
    }

    // Mobile browsers can fire a synthesized click after a touch sequence, and
    // a double-tap sends two. Keyed on the event rather than timed alone, so
    // two genuine clicks on two different links still both count.
    var lastEvent = { key: '', at: 0 };

    function isDuplicate(key) {
        var now = new Date().getTime();
        if (key === lastEvent.key && (now - lastEvent.at) < DEDUP_MS) {
            return true;
        }
        lastEvent.key = key;
        lastEvent.at = now;
        return false;
    }

    function isInternal(href) {
        try {
            // Resolved against the current page, because a link written by the
            // assistant is often relative ("/contact").
            return new URL(href, location.href).hostname === location.hostname;
        } catch (e) {
            // Unparseable href: call it external rather than guess. That is the
            // conservative side — it costs an outlink row, not a wrong domain.
            return false;
        }
    }

    function shortText(el) {
        var text = '';
        if (el) {
            text = el.innerText || el.textContent || '';
        }
        text = text.replace(/\s+/g, ' ').trim();
        return text.length > MAX_LABEL ? text.slice(0, MAX_LABEL) + '…' : text;
    }

    /**
     * Bot id for any element inside the widget.
     *
     * WAS BROKEN: the standalone version walked up to `[id^="chat-box-"]` and
     * stripped the prefix. That finds nothing for a suggested question, because
     * #mxchat-popular-questions-{botId} is a SIBLING of #chat-box-{botId} and
     * not a child of it (render_chatbot_shortcode() prints it after the chat
     * box's closing tag, includes/class-mxchat-public.php, MxChat 3.2.25) — so
     * on a multi-bot page every suggestion event was filed under the wrong
     * bot, or under none.
     *
     * The host exports its own resolver for add-ons (window.getBotIdFromElement,
     * assigned at the end of chat-script.js's ready handler, MxChat 3.2.25); it
     * walks .mxchat-chatbot-wrapper and also knows the floating and pre-chat
     * containers, so it is the single source of truth. The rest are fallbacks
     * for the window in which our script is live but the host's has not
     * finished booting.
     */
    function botIdFor(el) {
        if (typeof window.getBotIdFromElement === 'function') {
            try {
                var hostId = window.getBotIdFromElement(el);
                if (hostId) {
                    return String(hostId);
                }
            } catch (e) {
                // Host internals; fall through to our own walk.
            }
        }

        if (el && typeof el.closest === 'function') {
            var scoped = el.closest('[data-bot-id]');
            if (scoped) {
                var attr = scoped.getAttribute('data-bot-id');
                if (attr) {
                    return attr;
                }
            }

            var box = el.closest('[id^="chat-box-"]');
            if (box && box.id) {
                return box.id.replace(/^chat-box-/, '');
            }
        }

        return 'default';
    }

    function escapeForRegex(value) {
        return value.replace(/[.*+?^${}()|[\]\\-]/g, '\\$&');
    }

    /**
     * Cookie then localStorage, under the host's own key. Fallback only: see
     * sessionIdToReport().
     */
    function readStoredSession(botId) {
        var key = 'mxchat_session_id_' + botId;
        var match = document.cookie.match(
            new RegExp('(?:^|;\\s*)' + escapeForRegex(key) + '=([^;]*)')
        );
        var value = match ? decodeURIComponent(match[1]) : '';

        if (!value) {
            try {
                value = localStorage.getItem(key) || '';
            } catch (e) {
                // Storage blocked (private mode, partitioned third-party
                // context). Not an error here — there is simply no id to read.
            }
        }

        // The host writes these sentinels when an earlier write failed, and
        // guards against them itself (MxChatInstances.getChatSession(),
        // js/chat-script.js, MxChat 3.2.25). They are strings, so a truthiness
        // test alone lets them through as if they were a real session id.
        if (value === 'null' || value === 'undefined') {
            value = '';
        }
        return value;
    }

    /**
     * The session id we are allowed to report, or '' when there is none.
     *
     * Gated at the source rather than at each send site: when send_session_id
     * is off, no id must reach Matomo or GA4 by any route — no custom
     * dimension, no " [session: …]" suffix, no GA4 field. Returning '' here
     * makes that structurally true instead of depending on every call site
     * remembering the setting.
     *
     * WAS BROKEN, twice: the standalone version read the cookie itself, which
     * misses the in-memory copy the host keeps when cookies AND localStorage
     * are both blocked (Safari ITP, partitioned storage) — those visitors got
     * empty session ids. And it fell back to a hardcoded
     * 'mxchat_session_id_default', which on a multi-bot page attributes one
     * bot's clicks to another bot's conversation. window.getChatSession()
     * (exported for add-ons at the end of chat-script.js's ready handler,
     * MxChat 3.2.25) covers both.
     *
     * Looked up afresh on every event, never cached. The host replaces the id
     * when the visitor starts a new chat (resetChatSession()) and when the
     * server asks for a silent reset (MxChatInstances.silentResetSession()); a
     * cached copy would go on filing events under the conversation that had
     * just ended. The lookup is a cookie / localStorage / memory read.
     *
     * An empty result is normal: the host creates the session lazily — on the
     * first message sent (sendMessageToChatbot() → ensureSession()), or on
     * widget open only when chat persistence is on (the launcher and pre-chat
     * bubble handlers) — so an event can legitimately precede it.
     */
    function sessionIdToReport(botId) {
        if (!sendSessionId) {
            return '';
        }

        var sid = '';
        if (typeof window.getChatSession === 'function') {
            try {
                sid = window.getChatSession(botId) || '';
            } catch (e) {
                sid = '';
            }
        }
        if (!sid) {
            sid = readStoredSession(botId);
        }
        return sid;
    }

    /**
     * @param {string} action    Matomo event action — one of the labels above.
     * @param {string} name      Matomo event name.
     * @param {string} outlink   URL to also report via trackLink, '' for none.
     * @param {string} sessionId Session id, or '' when unknown or suppressed.
     */
    function sendMatomo(action, name, outlink, sessionId) {
        if (!trackMatomo) {
            log('Matomo disabled, skipping', action, name);
            return;
        }
        if (!window._paq) {
            log('Matomo not on this page (_paq undefined), skipping', action, name);
            return;
        }

        var eventName = name;

        if (sessionId) {
            if (dimensionId) {
                // A custom dimension keeps the event name clean, so the report
                // still groups by label instead of exploding into one row per
                // visitor.
                window._paq.push(['setCustomDimension', dimensionId, sessionId]);
            } else {
                // No dimension configured: the id has nowhere structured to go,
                // and carrying it in the label is the only way left to line an
                // event up with a transcript.
                eventName = eventName + ' [session: ' + sessionId + ']';
            }
        }

        window._paq.push(['trackEvent', 'MxChat', action, eventName]);

        if (outlink) {
            window._paq.push(['trackLink', outlink, 'link']);
        }

        log('Matomo', action, eventName, outlink);
    }

    function sendGa4(eventName, payload) {
        if (!trackGa4) {
            log('GA4 disabled, skipping', eventName);
            return;
        }
        if (typeof window.gtag !== 'function') {
            log('GA4 not on this page (gtag undefined), skipping', eventName);
            return;
        }

        window.gtag('event', eventName, payload);
        log('GA4', eventName, payload);
    }

    /**
     * Run fn on a later task — setTimeout(fn, 0) — and NEVER on a microtask.
     *
     * Our listeners run in the capture phase, before the host's own handlers
     * of the same dispatch, and some of those handlers are what create the
     * session an event should carry. Browsers drain the microtask queue
     * between the listener callbacks of a user-initiated dispatch, so a
     * Promise.then() or queueMicrotask() would still run BEFORE the host's
     * bubble-phase handler and find no session. Do not "optimise" this into a
     * Promise.
     *
     * Only for events that never navigate (a suggestion is a <button>; an
     * open is not a click at all). Link clicks are sent synchronously: the
     * page may be unloading as soon as the dispatch ends.
     */
    function later(fn) {
        window.setTimeout(function () {
            try {
                fn();
            } catch (e) {
                log('Deferred event failed', e);
            }
        }, 0);
    }

    /**
     * A suggested question was clicked.
     *
     * Label, bot and dedup are resolved now, while the button is certainly
     * where it was clicked; only the session lookup and the sends are
     * deferred. The host's delegated suggestion handler runs after this
     * capture listener and calls sendMessageToChatbot(), whose ensureSession()
     * is what gives a visitor who has never chatted their session id
     * (js/chat-script.js, MxChat 3.2.25). Read synchronously, the very click
     * that starts a conversation could never be joined to it.
     */
    function handleSuggestion(button) {
        var label = shortText(button);
        if (isDuplicate('suggestion|' + label)) {
            log('Duplicate suggestion click ignored', label);
            return;
        }

        var botId = botIdFor(button);

        later(function () {
            var sessionId = sessionIdToReport(botId);

            sendMatomo(ACTION_SUGGESTION, label, '', sessionId);

            var payload = {
                question_text: label,
                mxchat_bot_id: botId
            };
            // Omitted rather than sent empty: in GA4 an empty string is a
            // value, and it would show up in reports as a legitimate blank
            // dimension member next to the real session ids.
            if (sessionId) {
                payload.mxchat_session_id = sessionId;
            }
            sendGa4('mxchat_suggestion_click', payload);
        });
    }

    /**
     * Does the host give this link target="_blank" at click time?
     *
     * Mirrors prepareTrackedLink() (js/chat-script.js, MxChat 3.2.25) — keep
     * the two in step. With "Open links in new tab" on, the host retargets
     * every absolute link it tracks. For links it formats or binds itself
     * that happens before any click; for the ones only its delegated fallback
     * handles (the intro message, replayed raw-HTML history, a click in the
     * few hundred milliseconds before attachLinkTracking() binds) it happens
     * in the bubble phase, after this capture listener has read the
     * attribute.
     *
     * The host's own conditions, in its own terms:
     *   - the RAW href starts with http:// or https://, case-sensitively.
     *     Never test link.href, which the browser resolves to an absolute URL
     *     for relative links too;
     *   - the link sits inside .chat-box, the delegate's scope;
     *   - the host has booted, i.e. the delegate is bound. window.getChatSession
     *     is exported after it. window.mxchatChat alone proves nothing: under
     *     the delay and on-interaction loading strategies it is printed inline
     *     long before chat-script.js runs.
     */
    function hostOpensInNewTab(link, rawHref) {
        var absolute = rawHref.slice(0, 7) === 'http://' || rawHref.slice(0, 8) === 'https://';
        if (!absolute || typeof window.getChatSession !== 'function') {
            return false;
        }
        var hostCfg = window.mxchatChat;
        if (!hostCfg || hostCfg.link_target_toggle !== 'on') {
            return false;
        }
        return typeof link.closest === 'function' && !!link.closest('.chat-box');
    }

    /**
     * The target the link actually opens in: an explicit _blank, the host's
     * retargeting above, or else the attribute — `_self` when there is none.
     * The host overrides any other attribute value when it retargets, so the
     * order matters.
     */
    function effectiveTarget(link, rawHref) {
        var attr = link.getAttribute('target') || '';
        if (attr === '_blank' || hostOpensInNewTab(link, rawHref)) {
            return '_blank';
        }
        return attr || '_self';
    }

    function handleLink(link) {
        var rawHref = link.getAttribute('href') || '';

        if (!rawHref || rawHref.charAt(0) === '#' || SKIPPED_PROTOCOLS.test(rawHref)) {
            return;
        }
        // A fragment written as a full URL is still a jump inside the current
        // page, not a click-through, and the browser never leaves.
        if (link.hash && link.pathname === location.pathname && link.hostname === location.hostname) {
            return;
        }

        // The resolved form: a relative href would otherwise arrive as
        // "/contact", which is not comparable between two pages of the site.
        var url = link.href || rawHref;

        if (isDuplicate('link|' + url)) {
            log('Duplicate link click ignored', url);
            return;
        }

        var external = !isInternal(rawHref);
        var botId = botIdFor(link);
        var sessionId = sessionIdToReport(botId);
        var label = shortText(link);

        // trackLink is passed only for external destinations: an internal one
        // already produces a pageview when it lands, and reporting it as an
        // outlink too would count the same visit twice.
        sendMatomo(
            external ? ACTION_EXTERNAL : ACTION_INTERNAL,
            // An image or icon link has no text at all; the URL is a better
            // report row than a blank one.
            label || url,
            external ? url : '',
            sessionId
        );

        var payload = {
            link_url: url,
            link_text: label,
            link_scope: external ? 'external' : 'internal',
            link_target: effectiveTarget(link, rawHref),
            mxchat_bot_id: botId
        };
        if (sessionId) {
            payload.mxchat_session_id = sessionId;
        }
        sendGa4('mxchat_link_click', payload);
    }

    function handleClick(event) {
        var target = event.target;
        // closest() lives on Element; a click can land on a text or SVG node.
        if (target && typeof target.closest !== 'function') {
            target = target.parentElement || null;
        }
        if (!target || typeof target.closest !== 'function') {
            return;
        }

        // Suggestions first: they are buttons, never links, and live outside
        // the chat box, so testing them first avoids walking the DOM twice.
        var suggestion = target.closest(SUGGESTION_SELECTOR);
        if (suggestion) {
            // The host sends the question on 'click' only: a middle- or
            // right-click on a suggestion asks nothing, so it is not reported.
            if (event.type === 'click') {
                handleSuggestion(suggestion);
            }
            return;
        }

        var link = target.closest('a[href]');
        if (!link || !link.closest(CHAT_SELECTOR)) {
            return;
        }
        handleLink(link);
    }

    // ─── Chat opened (opt-in) ────────────────────────────────────────────

    // One event per bot per page load, whichever of the two routes below sees
    // the open first. The host dedupes its own dispatch the same way.
    var reportedOpens = {};

    /** Mirrors the host's isEmbeddedBot(): no floating wrapper means embedded. */
    function isEmbedded(botId) {
        return !document.getElementById('floating-chatbot-' + botId);
    }

    /**
     * The host dispatched mxchat:opened (MxChat 3.2.22+), or reported an
     * earlier open through MxChatInstances.opened.
     *
     * Embedded chatbots are never reported. The host fires mxchat:opened for
     * them at DOM-ready on every page view (an embedded widget "is on screen
     * from the first paint"), so nobody opened anything — and since Matomo
     * counts every event as an interaction, reporting it would turn every
     * single-page visit to such a page into a non-bounce.
     *
     * A floating window counts however it was revealed: the launcher, the
     * pre-chat bubble, or a script the host does not own (the Trigger add-on,
     * a theme button, an auto-open), which the host catches with a
     * MutationObserver. That is the host's definition of "opened" and the
     * docs say so.
     *
     * Deferred like suggestions: the launcher dispatches the event before it
     * calls ensureSession() (when chat persistence is on), in the same
     * handler.
     */
    function handleOpen(botId, embedded) {
        botId = botId ? String(botId) : 'default';
        if (embedded) {
            log('Embedded chatbot shown at load, not reported as an open', botId);
            return;
        }
        if (reportedOpens[botId]) {
            return;
        }
        reportedOpens[botId] = true;

        later(function () {
            var sessionId = sessionIdToReport(botId);

            sendMatomo(ACTION_OPEN, botId, '', sessionId);

            var payload = { mxchat_bot_id: botId };
            if (sessionId) {
                payload.mxchat_session_id = sessionId;
            }
            sendGa4('mxchat_chat_open', payload);
        });
    }

    /**
     * Opens that happened before this script ran — an optimiser that delays
     * our file but not the host's. window.MxChatInstances is exported at the
     * end of the host's ready handler; in the normal footer load it does not
     * exist yet here, and the listener below catches the dispatch instead.
     */
    function reportEarlierOpens() {
        var instances = window.MxChatInstances;
        if (!instances || typeof instances.opened !== 'object' || !instances.opened) {
            return;
        }
        for (var id in instances.opened) {
            if (Object.prototype.hasOwnProperty.call(instances.opened, id) && instances.opened[id]) {
                handleOpen(id, isEmbedded(id));
            }
        }
    }

    /**
     * The capture phase is MANDATORY here. The host binds its own click handler
     * directly on each <a> inside a bubble and calls stopPropagation() on it
     * (attachLinkTracking(), js/chat-script.js, MxChat 3.2.25), so a
     * bubble-phase listener on document never sees those link clicks at all.
     * Capture runs on the way down, before the target's own handlers, and
     * therefore always fires.
     *
     * passive: true is both a performance hint and a promise we must keep: this
     * handler must NEVER call preventDefault(). Since MxChat 3.2.24 the host
     * cancels no link click anywhere — neither in attachLinkTracking() nor in
     * its delegated fallback — and lets the browser follow the anchor while it
     * records the click with navigator.sendBeacon (trackLinkClick()). Nobody
     * navigates programmatically any more, so a preventDefault() of ours would
     * strand the visitor on EVERY link in an answer, absolute ones included,
     * with no error anywhere. Nor may anything here hold a navigation back to
     * resume it from a callback: a window.open() or location change made
     * outside the visitor's tap is what Safari's pop-up blocker drops without
     * a message — the very bug the host removed in 3.2.24.
     *
     * The price: a same-tab click unloads the page as soon as this dispatch
     * ends, so its Matomo/GA4 hit survives only through the tracker's own
     * unload-safe transport (sendBeacon; Matomo 4+ uses it by default). That
     * was always true of relative links, and since 3.2.24 it is true of
     * absolute ones too. It is the tracker's setting to make, never ours.
     */
    document.addEventListener('click', handleClick, { capture: true, passive: true });

    // Middle-click and open-in-new-tab never fire 'click'.
    document.addEventListener('auxclick', handleClick, { capture: true, passive: true });

    if (trackChatOpen) {
        // Feature-detected by the event itself: a host older than 3.2.22
        // never dispatches it, and the listener simply stays idle.
        document.addEventListener('mxchat:opened', function (event) {
            var detail = (event && event.detail) || {};
            handleOpen(detail.botId, detail.embedded === true);
        });
        reportEarlierOpens();
    }

    log('Ready', {
        matomo: trackMatomo,
        ga4: trackGa4,
        sessionId: sendSessionId,
        dimension: dimensionId,
        chatOpen: trackChatOpen
    });
})();
