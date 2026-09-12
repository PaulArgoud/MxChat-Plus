/**
 * Matomo / GA4 events for clicks inside chatbot answers and on suggested
 * questions.
 *
 * MxChat already logs *some* of this server-side, into
 * {$wpdb->prefix}mxchat_url_clicks, but only for absolute http(s) links
 * (mxchat-basic/js/chat-script.js:2188): relative links and suggested questions
 * are invisible to it, and it records nothing about internal vs external. This
 * script is the client-side half that fills those gaps, and it reports to the
 * analytics stack the site already runs rather than to a table of ours.
 *
 * Two host behaviours shape everything below and are documented at their call
 * sites: the host binds its own handler directly on each <a> and stops
 * propagation (so we must listen in the CAPTURE phase), and it navigates
 * programmatically from its own AJAX callback (so we must never cancel an
 * event).
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
     * in French, exactly as the standalone plugin wrote them.
     */
    var ACTION_INTERNAL   = 'Clic lien interne';
    var ACTION_EXTERNAL   = 'Clic lien externe';
    var ACTION_SUGGESTION = 'Clic suggestion';

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
     * not a child of it (mxchat-basic/includes/class-mxchat-public.php:366-370)
     * — so on a multi-bot page every suggestion event was filed under the wrong
     * bot, or under none.
     *
     * The host exports its own resolver for add-ons
     * (mxchat-basic/js/chat-script.js:4432); it walks .mxchat-chatbot-wrapper
     * and also knows the floating and pre-chat containers, so it is the single
     * source of truth. The rest are fallbacks for the window in which our
     * script is live but the host's has not finished booting.
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
        // guards against them itself (mxchat-basic/js/chat-script.js:228).
        // They are strings, so a truthiness test alone lets them through as if
        // they were a real session id.
        if (value === 'null' || value === 'undefined') {
            value = '';
        }
        return value;
    }

    // Only ever holds ids that were actually resolved; see below.
    var sessionCache = {};

    /**
     * The session id we are allowed to report, or '' when there is none.
     *
     * Gated at the source rather than at each send site: when send_session_id
     * is off, no id must reach Matomo or GA4 by any route — no custom
     * dimension, no " [session: …]" suffix, no GA4 field. Returning '' here
     * makes that structurally true instead of depending on three call sites
     * remembering the setting.
     *
     * WAS BROKEN, twice: the standalone version read the cookie itself, which
     * misses the in-memory copy the host keeps when cookies AND localStorage
     * are both blocked (Safari ITP, partitioned storage) — those visitors got
     * empty session ids. And it fell back to a hardcoded
     * 'mxchat_session_id_default', which on a multi-bot page attributes one
     * bot's clicks to another bot's conversation. window.getChatSession()
     * (exported at mxchat-basic/js/chat-script.js:4426) covers both.
     */
    function sessionIdToReport(botId) {
        if (!sendSessionId) {
            return '';
        }
        if (sessionCache[botId]) {
            return sessionCache[botId];
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

        // An empty result is never cached: the host creates the session lazily
        // — on the first sent message, or on widget open only when chat
        // persistence is on (mxchat-basic/js/chat-script.js:3505-3507) — so a
        // click can legitimately precede it, and the next click may well find
        // one.
        if (sid) {
            sessionCache[botId] = sid;
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

    function handleSuggestion(button) {
        var label = shortText(button);
        if (isDuplicate('suggestion|' + label)) {
            log('Duplicate suggestion click ignored', label);
            return;
        }

        var botId = botIdFor(button);
        var sessionId = sessionIdToReport(botId);

        sendMatomo(ACTION_SUGGESTION, label, '', sessionId);

        var payload = {
            question_text: label,
            mxchat_bot_id: botId
        };
        // Omitted rather than sent empty: in GA4 an empty string is a value,
        // and it would show up in reports as a legitimate blank dimension
        // member next to the real session ids.
        if (sessionId) {
            payload.mxchat_session_id = sessionId;
        }
        sendGa4('mxchat_suggestion_click', payload);
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
            link_target: link.getAttribute('target') || '_self',
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
            handleSuggestion(suggestion);
            return;
        }

        var link = target.closest('a[href]');
        if (!link || !link.closest(CHAT_SELECTOR)) {
            return;
        }
        handleLink(link);
    }

    /**
     * The capture phase is MANDATORY here. The host binds its own click handler
     * directly on each <a> inside a bubble and calls stopPropagation() on it
     * (mxchat-basic/js/chat-script.js:2193-2195), so a bubble-phase listener on
     * document never sees a link click at all. Capture runs on the way down,
     * before the target's own handlers, and therefore always fires.
     *
     * passive: true is both a performance hint and a promise we must keep: this
     * handler must NEVER call preventDefault(). The host cancels the event
     * itself and navigates programmatically from the completion callback of its
     * own AJAX call (mxchat-basic/js/chat-script.js:2205-2219); cancelling the
     * links it does not bind — every relative one — would leave the visitor on
     * the page with nothing happening and no error anywhere.
     */
    document.addEventListener('click', handleClick, { capture: true, passive: true });

    // Middle-click and open-in-new-tab never fire 'click'.
    document.addEventListener('auxclick', handleClick, { capture: true, passive: true });

    log('Ready', {
        matomo: trackMatomo,
        ga4: trackGa4,
        sessionId: sendSessionId,
        dimension: dimensionId
    });
})();
