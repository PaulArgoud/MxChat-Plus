/**
 * A stand-in for the parts of MxChat's js/mxchat_transcripts.js (MxChat
 * 3.2.25) that assets/js/admin-transcripts-export.js depends on. The host is
 * third-party code, kept out of this repository, so the DOM tests run against
 * this file by default; set MXCHAT_HOST_JS to the real script to run the same
 * tests against it as well (see export-selection.test.js).
 *
 * Each block reproduces, in shape, the host function it names — the event
 * bindings, their order and the DOM writes our script observes. Everything
 * the export does not depend on (rendering details, translation, the Leads
 * tab, the sort menu) is left out. Change this file only to follow a change
 * in the host, never to make a test pass.
 */
jQuery(document).ready(function ($) {
    'use strict';

    let currentPage = 1;
    const perPage = 50;
    let currentSessionId = null;
    let selectedSessions = new Set();

    // Mobile back button handler: forgets the open conversation without any
    // request, bound directly, no stopPropagation().
    $('#mxch-transcript-back-btn').on('click', function (e) {
        e.preventDefault();
        $('.mxch-chat-item').removeClass('active');
        currentSessionId = null;
    });

    loadChatList(1, '');

    // Search: debounced, back to page 1.
    let searchTimeout;
    $('#mxch-search-transcripts').on('input', function () {
        clearTimeout(searchTimeout);
        const searchTerm = $(this).val().toLowerCase();
        searchTimeout = setTimeout(function () {
            currentPage = 1;
            loadChatList(currentPage, searchTerm);
        }, 300);
    });

    $('#mxch-refresh-list').on('click', function () {
        loadChatList(currentPage, $('#mxch-search-transcripts').val());
    });

    // "All": a direct `change` handler. Rows are ticked with .prop(), which
    // fires no per-row event, and updateSelectionUI() then rewrites the box
    // from the rows on screen — with none, it unticks it.
    $('#mxch-select-all').on('change', function () {
        const isChecked = $(this).is(':checked');
        $('.mxch-chat-checkbox').prop('checked', isChecked);

        if (isChecked) {
            $('.mxch-chat-item').each(function () {
                selectedSessions.add($(this).attr('data-session-id'));
                $(this).addClass('selected');
            });
        } else {
            selectedSessions.clear();
            $('.mxch-chat-item').removeClass('selected');
        }

        updateSelectionUI();
    });

    // updateSelectionUI(): at zero the count keeps its old text and only
    // loses `has-selection`.
    function updateSelectionUI() {
        const count = selectedSessions.size;
        const $countEl = $('#mxch-selected-count');
        const $deleteBtn = $('#mxch-delete-selected');

        if (count > 0) {
            $countEl.text(count + ' selected').addClass('has-selection');
            $deleteBtn.prop('disabled', false);
        } else {
            $countEl.removeClass('has-selection');
            $deleteBtn.prop('disabled', true);
        }

        const totalItems = $('.mxch-chat-checkbox').length;
        const checkedItems = $('.mxch-chat-checkbox:checked').length;
        $('#mxch-select-all').prop('checked', totalItems > 0 && checkedItems === totalItems);
        $('#mxch-select-all').prop('indeterminate', checkedItems > 0 && checkedItems < totalItems);
    }

    $('#mxch-delete-selected').on('click', function () {
        const count = selectedSessions.size;
        if (count === 0) return;
        openTranscriptConfirm(Array.from(selectedSessions), count);
    });

    // The confirm modal shared by the bulk and the single delete.
    let transcriptConfirmSessionIds = [];

    function openTranscriptConfirm(sessionIds) {
        transcriptConfirmSessionIds = sessionIds.slice();
        $('#mxch-transcript-also-delete-lead').prop('checked', false);
        $('#mxch-transcript-confirm').fadeIn(120);
    }

    function closeTranscriptConfirm() {
        $('#mxch-transcript-confirm').fadeOut(120);
        transcriptConfirmSessionIds = [];
    }

    $(document).on('click', '[data-mxch-transcript-close]', closeTranscriptConfirm);

    $('#mxch-transcript-confirm-go').on('click', function () {
        const ids = transcriptConfirmSessionIds.slice();
        if (!ids.length) { closeTranscriptConfirm(); return; }
        const alsoDeleteLead = $('#mxch-transcript-also-delete-lead').is(':checked');
        closeTranscriptConfirm();
        deleteMultipleSessions(ids, alsoDeleteLead);
    });

    // deleteMultipleSessions(): plain-object data (so jQuery.param() sends
    // `delete_session_ids[]`), a reply that may be a JSON string, failure as
    // an `error` key, and the whole Set cleared on success.
    function deleteMultipleSessions(sessionIds, alsoDeleteLead) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mxchat_delete_chat_history',
                delete_session_ids: sessionIds,
                also_delete_lead: alsoDeleteLead ? '1' : '0',
                security: $('#mxchat_delete_chat_nonce').val()
            },
            success: function (response) {
                try {
                    const jsonResponse = typeof response === 'object' ? response : JSON.parse(response);
                    if (jsonResponse.success) {
                        selectedSessions.clear();
                        $('#mxch-select-all').prop('checked', false);
                        updateSelectionUI();

                        if (sessionIds.includes(currentSessionId)) {
                            currentSessionId = null;
                            $('#mxch-conversation-content').hide();
                            $('#mxch-conversation-empty').show();
                            $('#mxch-details-drawer').hide();
                        }

                        loadChatList(currentPage, $('#mxch-search-transcripts').val());
                    } else if (jsonResponse.error) {
                        alert('Error: ' + jsonResponse.error);
                    }
                } catch (e) {
                    alert('An error occurred while processing the response.');
                }
            },
            error: function () {
                alert('An error occurred while deleting conversations.');
            }
        });
    }

    // loadChatList(): a spinner first, then rows, "No chats found" or
    // "Error loading chats". Only a render with rows calls updateSelectionUI().
    function loadChatList(page, searchTerm) {
        const $container = $('#mxch-chat-list');
        $container.html('<div class="mxch-list-loading"><span class="spinner is-active"></span></div>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mxchat_fetch_chat_history',
                page: page,
                per_page: perPage,
                search: searchTerm,
                sort_order: 'desc'
            },
            success: function (response) {
                if (response.success && response.sessions && response.sessions.length > 0) {
                    renderChatList(response.sessions);
                    currentPage = response.page;
                    renderPagination(response.page, response.total_pages, searchTerm);
                } else {
                    $container.html('<div class="mxch-list-empty"><p>No chats found</p></div>');
                    $('#mxch-pagination').html('');
                }
            },
            error: function () {
                $container.html('<div class="mxch-list-empty"><p>Error loading chats</p></div>');
            }
        });
    }

    // renderChatList(): every row re-created, re-ticked from the Set, with a
    // direct `click` handler on each checkbox that stops propagation.
    function renderChatList(sessions) {
        const $container = $('#mxch-chat-list');
        let html = '';

        sessions.forEach(function (session) {
            const isSelected = selectedSessions.has(session.session_id) ? ' selected' : '';
            const isChecked = selectedSessions.has(session.session_id) ? ' checked' : '';
            html += '<div class="mxch-chat-item' + isSelected + '" data-session-id="' + session.session_id + '">' +
                '<input type="checkbox" class="mxch-chat-checkbox"' + isChecked + '>' +
                '<div class="mxch-chat-info"><div class="mxch-chat-name">' + session.display_name + '</div></div>' +
                '</div>';
        });

        $container.html(html);

        $('.mxch-chat-checkbox').on('click', function (e) {
            e.stopPropagation();
            const $item = $(this).closest('.mxch-chat-item');
            const sessionId = $item.attr('data-session-id');

            if ($(this).is(':checked')) {
                selectedSessions.add(sessionId);
                $item.addClass('selected');
            } else {
                selectedSessions.delete(sessionId);
                $item.removeClass('selected');
            }

            updateSelectionUI();
        });

        $('.mxch-chat-item').on('click', function (e) {
            if ($(e.target).is('.mxch-chat-checkbox')) return;
            selectChat($(this).attr('data-session-id'));
            $('.mxch-chat-item').removeClass('active');
            $(this).addClass('active');
        });

        updateSelectionUI();
    }

    function renderPagination(page, totalPages, searchTerm) {
        const $container = $('#mxch-pagination');
        if (totalPages <= 1) {
            $container.html('');
            return;
        }
        let html = '<div class="mxch-pagination-btns">';
        if (page > 1) {
            html += '<button class="mxch-page-btn" data-page="' + (page - 1) + '">&laquo;</button>';
        }
        html += '<span class="mxch-page-info">' + page + ' / ' + totalPages + '</span>';
        if (page < totalPages) {
            html += '<button class="mxch-page-btn" data-page="' + (page + 1) + '">&raquo;</button>';
        }
        html += '</div>';
        $container.html(html);

        $('.mxch-page-btn').on('click', function () {
            loadChatList($(this).data('page'), searchTerm);
        });
    }

    // selectChat(): the id is set before the request; the panel shows at once.
    function selectChat(sessionId) {
        currentSessionId = sessionId;
        $('#mxch-conversation-empty').hide();
        $('#mxch-conversation-content').show();
        $('#mxch-messages-area').html('<div class="mxch-messages-loading"></div>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mxchat_fetch_conversation',
                session_id: sessionId
            },
            success: function (response) {
                if (response.success) {
                    $('#mxch-user-name').text(response.user.name);
                    $('#mxch-messages-area').html('');
                } else {
                    $('#mxch-messages-area').html('<div class="mxch-messages-error">Failed to load conversation</div>');
                }
            },
            error: function () {
                $('#mxch-messages-area').html('<div class="mxch-messages-error">Error loading conversation</div>');
            }
        });
    }

    $('#mxch-delete-current').on('click', function () {
        if (!currentSessionId) return;
        openTranscriptConfirm([currentSessionId], 1);
    });

    // The host's own Export: a whole-table dump under its own action.
    $('#mxch-export-btn, #mxch-export-current').on('click', function () {
        const $form = $('<form>', { method: 'post', action: ajaxurl });
        $form.append($('<input>', { type: 'hidden', name: 'action', value: 'mxchat_export_transcripts' }));
        $form.append($('<input>', { type: 'hidden', name: 'security', value: mxchatAdmin.export_nonce }));
        $form.appendTo('body').submit();
    });
});
