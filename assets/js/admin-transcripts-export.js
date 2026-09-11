/**
 * Adds an "Export selected as CSV" button to MxChat's transcripts screen.
 *
 * MxChat is a third-party plugin we never modify, which shapes every choice
 * here:
 *
 *  - The button is injected next to the host's "Delete Selected" control
 *    because the host renders that toolbar itself and exposes no hook to add
 *    to it.
 *  - The selection is read from the DOM. The host tracks it in a
 *    `selectedSessions` Set that lives inside its own closure and is never
 *    published on window, so it is unreachable — but it also maintains the
 *    checkboxes, which are.
 *  - Enabled state is MIRRORED from the host's delete button via a
 *    MutationObserver rather than recomputed. The host owns the definition of
 *    "something is selected"; duplicating that logic would mean re-deriving it
 *    every time the host changes its list rendering.
 *  - The chat list is re-rendered on every page change and search, which
 *    destroys the toolbar; the observer re-injects the button when that
 *    happens.
 */
(function ($) {
    'use strict';

    var cfg = window.mxchatPlusTranscriptsExport || {};
    if (!cfg.ajaxUrl || !cfg.nonce) {
        return;
    }

    var BUTTON_ID = 'mxchat-plus-export-selected';
    var DELETE_BTN = '#mxch-delete-selected';

    var ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14"' +
        ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"' +
        ' stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>' +
        '<polyline points="7 10 12 15 17 10"></polyline>' +
        '<line x1="12" y1="15" x2="12" y2="3"></line></svg>';

    function selectedSessionIds() {
        var ids = [];
        $('.mxch-chat-checkbox:checked').each(function () {
            // .attr() rather than .data(): jQuery coerces "null"/"true"/numeric
            // strings to JS types, which corrupts session ids. The host's own
            // code carries the same warning.
            var id = $(this).closest('.mxch-chat-item').attr('data-session-id');
            if (id && ids.indexOf(id) === -1) {
                ids.push(id);
            }
        });
        return ids;
    }

    var MODAL_ID = 'mxchat-plus-export-range';

    function t(key, fallback) {
        return (cfg.i18n && cfg.i18n[key]) ? cfg.i18n[key] : fallback;
    }

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
     * Date-range dialog, shown when the button is clicked with nothing
     * selected. The markup mirrors the host's own confirm dialogs
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

        ids.forEach(function (id) {
            $form.append($('<input>', { type: 'hidden', name: 'session_ids[]', value: id }));
        });

        if (range && range.from && range.to) {
            $form.append($('<input>', { type: 'hidden', name: 'date_from', value: range.from }));
            $form.append($('<input>', { type: 'hidden', name: 'date_to', value: range.to }));
        }

        $('body').append($form);
        $form.trigger('submit');
        setTimeout(function () { $form.remove(); }, 1000);
    }

    function injectButton() {
        var $deleteBtn = $(DELETE_BTN);
        if (!$deleteBtn.length || $('#' + BUTTON_ID).length) {
            return;
        }

        var $btn = $('<button>', {
            type: 'button',
            id: BUTTON_ID,
            'class': 'mxch-bulk-btn',
            title: cfg.i18n && cfg.i18n.buttonTitle ? cfg.i18n.buttonTitle : 'Export selected as CSV'
        }).html(ICON);

        // Unlike the host's Delete button, this one stays enabled with an empty
        // selection: that is the entry point to the date-range export.
        $btn.on('click', function () {
            var ids = selectedSessionIds();
            if (!ids.length) {
                openRangeModal();
                return;
            }
            submitExport(ids, null);
        });

        $deleteBtn.after($btn);
    }

    $(function () {
        injectButton();

        // The host re-renders the whole list (and its toolbar) on paging,
        // sorting and search. Watching the container re-injects the button
        // after each rebuild; without this it silently disappears on page 2.
        var list = document.getElementById('mxch-chat-list');
        var host = list ? list.parentNode : document.body;
        if (host && typeof MutationObserver !== 'undefined') {
            new MutationObserver(function () {
                injectButton();
            }).observe(host, { childList: true, subtree: true });
        }
    });
})(jQuery);
