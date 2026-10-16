/**
 * Adds CSV export buttons to MxChat's transcripts screen: one in the bulk
 * toolbar (the selection, or a date range when nothing is selected), and one
 * in the open conversation's details drawer (that conversation only).
 *
 * MxChat is a third-party plugin we never modify, which shapes every choice
 * here:
 *
 *  - The toolbar button is injected next to the host's "Delete Selected"
 *    control because the host renders that toolbar itself and exposes no hook
 *    to add to it.
 *  - The selection is MIRRORED, not read back from the checkboxes. The host
 *    tracks it in a `selectedSessions` Set that lives inside its own closure
 *    and is never published on window. That Set survives paging, search,
 *    sorting and Refresh — the host re-renders only the rows and re-ticks the
 *    ones it still holds — so the checkboxes on screen show only the current
 *    page's share of it, while the host's counter and its Delete act on all
 *    of it. trackSelection() keeps our own copy from the same user gestures;
 *    the export takes the union of that copy and whatever is ticked on screen.
 *  - The toolbar button stays enabled with an empty selection, on purpose:
 *    unlike the host's Delete button (disabled at zero), an empty selection is
 *    the entry point to the date-range export.
 *  - The toolbar is static server-side markup: the host only ever rewrites
 *    #mxch-chat-list and #mxch-pagination (loadChatList / renderPagination,
 *    MxChat 3.2.25). The MutationObserver at the bottom is a safety net in
 *    case a future host version starts re-rendering the toolbar, not
 *    something today's host needs. It watches the whole list panel on
 *    purpose: an observer on the toolbar node itself could never see that
 *    node being replaced.
 *  - The host's own "Export" button inside an open conversation dumps the
 *    whole table. Ours, next to it, exports only the conversation on screen.
 *    The host keeps that session id (`currentSessionId`) in its closure too,
 *    so trackOpenConversation() learns it from the host's own AJAX traffic.
 */
(function ($) {
    'use strict';

    var cfg = window.mxchatPlusTranscriptsExport || {};
    if (!cfg.ajaxUrl || !cfg.nonce) {
        return;
    }

    var BUTTON_ID = 'mxchat-plus-export-selected';
    var CURRENT_BUTTON_ID = 'mxchat-plus-export-current';

    // Host markup we anchor to or read (MxChat 3.2.25,
    // includes/admin-transcripts-page.php and js/mxchat_transcripts.js).
    var DELETE_BTN = '#mxch-delete-selected';
    var HOST_EXPORT_CURRENT = '#mxch-export-current';
    var SELECTED_COUNT = '#mxch-selected-count';
    var SELECT_ALL = '#mxch-select-all';
    var CHAT_ITEM = '.mxch-chat-item';
    var CHECKBOX = '.mxch-chat-checkbox';
    var CONVERSATION = '#mxch-conversation-content';
    var MOBILE_BACK = '#mxch-transcript-back-btn';

    // Host AJAX actions we observe — host literals, never sent by us.
    var HOST_DELETE_ACTION = 'mxchat_delete_chat_history';
    var HOST_FETCH_ACTION = 'mxchat_fetch_conversation';

    var ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14"' +
        ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"' +
        ' stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>' +
        '<polyline points="7 10 12 15 17 10"></polyline>' +
        '<line x1="12" y1="15" x2="12" y2="3"></line></svg>';

    function t(key, fallback) {
        return (cfg.i18n && cfg.i18n[key]) ? cfg.i18n[key] : fallback;
    }

    // Replaces every occurrence, so a translation may repeat a placeholder.
    function fill(template, values) {
        Object.keys(values).forEach(function (placeholder) {
            template = template.split(placeholder).join(String(values[placeholder]));
        });
        return template;
    }

    // ─── Host AJAX traffic ──────────────────────────────────────────────

    /**
     * The host's request fields, or null. It passes plain objects to $.ajax
     * with the default processData, so by the time the global AJAX events
     * fire, settings.data is a urlencoded string.
     */
    function hostParams(settings) {
        if (!settings || typeof settings.data !== 'string' || typeof URLSearchParams !== 'function') {
            return null;
        }
        try {
            return new URLSearchParams(settings.data);
        } catch (e) {
            return null;
        }
    }

    /**
     * The response as an object, or null. The host's own delete callback
     * accepts both a parsed object and a JSON string, and reports failure as
     * HTTP 200 with an `error` key — so "success" means `success: true`, not
     * merely that the request completed.
     */
    function hostResponse(data) {
        if (typeof data === 'string') {
            try {
                data = JSON.parse(data);
            } catch (e) {
                return null;
            }
        }
        return (data && typeof data === 'object') ? data : null;
    }

    // ─── Selection mirror ───────────────────────────────────────────────

    // Insertion-ordered, like the host's Set: the server keeps the first
    // MAX_SESSIONS ids it receives, so the order decides what a cap keeps.
    var selection = new Set();

    function trackSelection() {
        // `change`, delegated on document. The host binds `click` directly on
        // each row checkbox and calls stopPropagation() there; that stops the
        // click from bubbling, not the separate `change` event the browser
        // fires after it. Delegation is also what survives the host
        // re-creating every row on each list render.
        $(document).on('change', CHECKBOX, function () {
            // .attr() rather than .data(): jQuery coerces "null"/"true"/numeric
            // strings to JS types, which corrupts session ids. The host's own
            // code carries the same warning.
            var id = $(this).closest(CHAT_ITEM).attr('data-session-id');
            if (!id) {
                return;
            }
            if (this.checked) {
                selection.add(id);
            } else {
                selection.delete(id);
            }
        });

        // "All": the host ticks the rows with .prop(), which fires no `change`
        // per row, so mirror its handler instead. Ticked adds every row on
        // screen; unticked clears the WHOLE selection, other pages included —
        // that is what the host does to its own Set.
        //
        // What the admin chose is read on `click`, NOT from the box in
        // `change`. The host binds its `change` handler directly on the box,
        // so it runs before this delegated one, and it ends in
        // updateSelectionUI() (MxChat 3.2.25), which rewrites `checked` from
        // the rows on screen. With no rows — the spinner while the list
        // reloads after paging, Refresh or a sort, "No chats found", "Error
        // loading chats" — it unticks the box: a tick then reads as an untick
        // here, and clearing on it emptied our copy while the host kept its
        // whole Set. The browser fires `click` (label clicks and the Space key
        // included) before `change`, with the box already toggled, and the
        // host binds no `click` on this box, so the delegated handler below
        // sees the admin's choice. A programmatic .trigger('change') has no
        // click before it and falls back to `checked`.
        var selectAllIntent = null;
        $(document).on('click', SELECT_ALL, function () {
            selectAllIntent = this.checked;
            // `change` follows synchronously, or not at all (a cancelled
            // click); never let a stale intent outlive this click.
            setTimeout(function () { selectAllIntent = null; }, 0);
        });
        $(document).on('change', SELECT_ALL, function () {
            var ticked = selectAllIntent === null ? this.checked : selectAllIntent;
            selectAllIntent = null;
            if (ticked) {
                // With no rows on screen this adds nothing, exactly as the
                // host's own handler does.
                $(CHAT_ITEM).each(function () {
                    var id = $(this).attr('data-session-id');
                    if (id) {
                        selection.add(id);
                    }
                });
            } else {
                selection.clear();
            }
        });
    }

    /** Our copy of the selection, plus anything ticked on screen. */
    function selectedSessionIds() {
        var ids = Array.from(selection);
        $(CHECKBOX + ':checked').each(function () {
            var id = $(this).closest(CHAT_ITEM).attr('data-session-id');
            if (id && ids.indexOf(id) === -1) {
                ids.push(id);
            }
        });
        return ids;
    }

    /**
     * The count the host's toolbar shows, or null when it shows none. At zero
     * the host removes `has-selection` but leaves the old "N selected" text in
     * place, so the text only means something while the class is on.
     */
    function hostSelectedCount() {
        var $count = $(SELECTED_COUNT);
        if (!$count.length || !$count.hasClass('has-selection')) {
            return null;
        }
        var n = parseInt($count.text(), 10);
        return isNaN(n) ? null : n;
    }

    // ─── Open conversation ──────────────────────────────────────────────

    var openSessionId = '';

    function setOpenSession(id) {
        openSessionId = id ? String(id) : '';
        $('#' + CURRENT_BUTTON_ID).prop('disabled', !openSessionId);
    }

    function trackOpenConversation() {
        // A new conversation is loading: until it arrives the panel shows a
        // spinner, and a click must not export the previous one.
        $(document).on('ajaxSend', function (event, xhr, settings) {
            var params = hostParams(settings);
            if (params && params.get('action') === HOST_FETCH_ACTION) {
                setOpenSession('');
            }
        });

        $(document).on('ajaxSuccess', function (event, xhr, settings, data) {
            var params = hostParams(settings);
            if (!params) {
                return;
            }
            var action = params.get('action');
            var response = hostResponse(data);

            if (action === HOST_FETCH_ACTION) {
                // The id the host actually rendered, as it echoes it back
                // (MxChat_Admin::mxchat_fetch_conversation, MxChat 3.2.25).
                // A host whose response does not echo it falls back to the id
                // the host asked for; the server sanitises it either way. A
                // failed load leaves the button disabled.
                var opened = '';
                if (response && response.success) {
                    opened = response.session_id || params.get('session_id') || '';
                }
                setOpenSession(opened);
                return;
            }

            if (action === HOST_DELETE_ACTION && response && response.success) {
                // Mirrors deleteMultipleSessions(): a successful delete clears
                // the host's whole Set, even one started from the open
                // conversation's own Delete button.
                selection.clear();
                // And when it deleted the conversation on screen, the host
                // hides the panel and forgets the id; so do we.
                if (openSessionId && params.getAll('delete_session_ids[]').indexOf(openSessionId) !== -1) {
                    setOpenSession('');
                }
            }
        });

        $(document).on('ajaxError', function (event, xhr, settings) {
            var params = hostParams(settings);
            if (params && params.get('action') === HOST_FETCH_ACTION) {
                setOpenSession('');
            }
        });

        // The mobile back button nulls the host's currentSessionId without any
        // request we could observe. The host binds it directly and does not
        // stop propagation, so a delegated listener still sees the click.
        $(document).on('click', MOBILE_BACK, function () {
            setOpenSession('');
        });
    }

    // ─── Date-range dialog ──────────────────────────────────────────────

    var MODAL_ID = 'mxchat-plus-export-range';

    function todayISO(offsetDays) {
        var d = new Date();
        if (offsetDays) {
            d.setDate(d.getDate() + offsetDays);
        }
        // Local date, not toISOString(): the latter converts to UTC and can
        // land on the wrong day for anyone east or west of Greenwich.
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + m + '-' + day;
    }

    /**
     * Date-range dialog, shown when the toolbar button is clicked with
     * nothing selected. The markup mirrors the host's own confirm dialogs
     * (.mxch-modal-overlay > .mxch-modal-content > header/body/actions) so it
     * inherits their styling rather than shipping a competing look.
     */
    function openRangeModal() {
        $('#' + MODAL_ID).remove();

        var $modal = $(
            '<div id="' + MODAL_ID + '" class="mxch-modal-overlay">' +
              '<div class="mxch-modal-content mxch-leads-confirm-box">' +
                '<div class="mxch-modal-header">' +
                  '<h2></h2>' +
                  '<button type="button" class="mxch-modal-close" data-mxchat-plus-close>&times;</button>' +
                '</div>' +
                '<div class="mxch-modal-body">' +
                  '<p class="mxchat-plus-range-intro"></p>' +
                  '<p class="mxchat-plus-range-error" style="display:none;color:#b32d2e;"></p>' +
                  '<label style="display:block;margin-bottom:12px;">' +
                    '<strong class="mxchat-plus-label-from"></strong><br>' +
                    '<input type="date" class="mxchat-plus-date-from" style="width:100%;max-width:260px;">' +
                  '</label>' +
                  '<label style="display:block;">' +
                    '<strong class="mxchat-plus-label-to"></strong><br>' +
                    '<input type="date" class="mxchat-plus-date-to" style="width:100%;max-width:260px;">' +
                  '</label>' +
                '</div>' +
                '<div class="mxch-leads-confirm-actions">' +
                  '<button type="button" class="mxch-btn mxch-btn-secondary" data-mxchat-plus-close></button>' +
                  '<button type="button" class="mxch-btn mxch-btn-primary mxchat-plus-range-go"></button>' +
                '</div>' +
              '</div>' +
            '</div>'
        );

        // Labels are injected as text, never interpolated into the HTML above:
        // translations are data, and one stray quote would break the markup.
        $modal.find('.mxch-modal-header h2').text(t('rangeTitle', 'Export by date range'));
        $modal.find('.mxchat-plus-range-intro').text(t('rangeIntro', 'No conversation is selected. Choose a period to export.'));
        $modal.find('.mxchat-plus-label-from').text(t('rangeFrom', 'Start date'));
        $modal.find('.mxchat-plus-label-to').text(t('rangeTo', 'End date'));
        $modal.find('[data-mxchat-plus-close].mxch-btn').text(t('cancel', 'Cancel'));
        $modal.find('.mxchat-plus-range-go').text(t('exportBtn', 'Export CSV'));

        var $from = $modal.find('.mxchat-plus-date-from');
        var $to = $modal.find('.mxchat-plus-date-to');
        // A sensible default beats an empty form: last 30 days, ending today.
        $from.val(todayISO(-30));
        $to.val(todayISO(0));

        function close() {
            $(document).off('keydown.mxchatPlusExport');
            $modal.remove();
        }

        $modal.on('click', '[data-mxchat-plus-close]', close);
        $modal.on('click', function (e) {
            if (e.target === $modal[0]) {
                close();
            }
        });
        $(document).on('keydown.mxchatPlusExport', function (e) {
            if (e.key === 'Escape') {
                close();
            }
        });

        $modal.find('.mxchat-plus-range-go').on('click', function () {
            var from = $from.val();
            var to = $to.val();
            var $err = $modal.find('.mxchat-plus-range-error');

            if (!from || !to) {
                $err.text(t('rangeRequired', 'Please provide both a start and an end date.')).show();
                return;
            }
            if (from > to) {
                $err.text(t('rangeOrder', 'The start date must be before the end date.')).show();
                return;
            }

            close();
            submitExport([], { from: from, to: to });
        });

        $('body').append($modal);
        // The host's overlays are styled but hidden inline; ours is created
        // visible, so only set display if a stylesheet left it as none.
        if ($modal.css('display') === 'none') {
            $modal.css('display', 'flex');
        }
        $from.trigger('focus');
    }

    // ─── Submission ─────────────────────────────────────────────────────

    function submitExport(ids, range) {
        // A real form POST rather than fetch(): it hands the response to the
        // browser's download machinery, so the Content-Disposition header does
        // the work and no blob has to be held in memory.
        var $form = $('<form>', {
            method: 'POST',
            action: cfg.ajaxUrl,
            target: '_blank'
        }).css('display', 'none');

        $form.append($('<input>', { type: 'hidden', name: 'action', value: cfg.action }));
        $form.append($('<input>', { type: 'hidden', name: 'security', value: cfg.nonce }));

        if (ids.length) {
            // One field, not one `session_ids[]` input per id: a selection kept
            // across pages can run into the thousands, and PHP silently drops
            // every input past max_input_vars (1000 by default).
            $form.append($('<input>', { type: 'hidden', name: 'session_ids_json', value: JSON.stringify(ids) }));
        }

        if (range && range.from && range.to) {
            $form.append($('<input>', { type: 'hidden', name: 'date_from', value: range.from }));
            $form.append($('<input>', { type: 'hidden', name: 'date_to', value: range.to }));
        }

        $('body').append($form);
        $form.trigger('submit');
        setTimeout(function () { $form.remove(); }, 1000);
    }

    /**
     * Backstop for an empty copy while MxChat still counts a selection: the
     * mirror missed a gesture. The range dialog opens with "No conversation
     * is selected", which would contradict the host's own counter, so say so
     * first. False when the admin backs out.
     */
    function confirmNothingTracked() {
        var hostCount = hostSelectedCount();
        if (hostCount === null || hostCount <= 0) {
            return true;
        }
        var untracked = fill(
            t('selectionUntracked', 'MxChat shows %d selected conversations, but this export lost track of them. To export them, reload the page and select them again. Export by date range instead?'),
            { '%d': hostCount, '%1$d': hostCount }
        );
        return window.confirm(untracked);
    }

    /** False when the admin backs out of a warning. */
    function confirmSelection(ids) {
        var hostCount = hostSelectedCount();
        if (hostCount !== null && hostCount !== ids.length) {
            var mismatch = fill(
                t('selectionMismatch', 'MxChat shows %1$d selected conversations, but this export will contain %2$d. Export anyway?'),
                { '%1$d': hostCount, '%2$d': ids.length }
            );
            if (!window.confirm(mismatch)) {
                return false;
            }
        }

        var max = parseInt(cfg.maxSessions, 10);
        if (max > 0 && ids.length > max) {
            var capped = fill(
                t('selectionCapped', 'Only the first %1$d of the %2$d selected conversations will be exported. Continue?'),
                { '%1$d': max, '%2$d': ids.length }
            );
            if (!window.confirm(capped)) {
                return false;
            }
        }
        return true;
    }

    // ─── Buttons ────────────────────────────────────────────────────────

    function injectButton() {
        var $deleteBtn = $(DELETE_BTN);
        if (!$deleteBtn.length || $('#' + BUTTON_ID).length) {
            return;
        }

        var $btn = $('<button>', {
            type: 'button',
            id: BUTTON_ID,
            'class': 'mxch-bulk-btn',
            title: t('buttonTitle', 'Export selected as CSV')
        }).html(ICON);

        // Unlike the host's Delete button, this one stays enabled with an empty
        // selection: that is the entry point to the date-range export. Only an
        // empty union opens the dialog — ticks on other pages still count.
        $btn.on('click', function () {
            var ids = selectedSessionIds();
            if (!ids.length) {
                if (!confirmNothingTracked()) {
                    return;
                }
                openRangeModal();
                return;
            }
            if (!confirmSelection(ids)) {
                return;
            }
            submitExport(ids, null);
        });

        $deleteBtn.after($btn);
    }

    /**
     * The drawer is static server-side markup outside the list panel, so one
     * injection at DOM ready is enough; the host's button is left alone.
     */
    function injectCurrentButton() {
        var $hostBtn = $(HOST_EXPORT_CURRENT);
        if (!$hostBtn.length || $('#' + CURRENT_BUTTON_ID).length) {
            return;
        }

        var label = t('exportCurrent', 'Export this conversation (CSV)');
        var $btn = $('<button>', {
            type: 'button',
            id: CURRENT_BUTTON_ID,
            'class': 'mxch-btn mxch-btn-secondary mxch-btn-sm',
            title: label
        }).html(ICON);
        // Text node, not HTML: the label is a translation, i.e. data.
        $btn.append(document.createTextNode(' ' + label));

        $btn.on('click', function () {
            // The host hides the panel when it forgets the conversation (after
            // a delete); only export what is actually on screen.
            if (!openSessionId || !$(CONVERSATION).is(':visible')) {
                return;
            }
            submitExport([openSessionId], null);
        });

        $hostBtn.after($btn);
        setOpenSession(openSessionId);
    }

    // Bound right away rather than at DOM ready: every listener is delegated
    // on document, and none may miss the host's first list load.
    trackSelection();
    trackOpenConversation();

    $(function () {
        injectButton();
        injectCurrentButton();

        // Safety net only — see the header comment. Each callback is a cheap
        // no-op while the button is still there.
        var list = document.getElementById('mxch-chat-list');
        var host = list ? list.parentNode : document.body;
        if (host && typeof MutationObserver !== 'undefined') {
            new MutationObserver(function () {
                injectButton();
            }).observe(host, { childList: true, subtree: true });
        }
    });
})(jQuery);
