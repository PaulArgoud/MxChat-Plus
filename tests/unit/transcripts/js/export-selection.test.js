'use strict';
/**
 * DOM tests for assets/js/admin-transcripts-export.js: the selection mirror,
 * "All", the toolbar button's choices and the open-conversation tracker.
 * PHP's suite cannot see any of this, and a regression here (a .data() read,
 * a dropped success check, a reordered handler) exports the wrong
 * conversations with every PHP test still green.
 *
 * Dev tooling only, never shipped. From this directory:
 *
 *     npm install
 *     npm test
 *
 * `composer test` runs it too, through TranscriptsExportScriptTest, and skips
 * it when Node or the two packages are missing.
 *
 * Each test builds a fresh page in jsdom: transcripts-page.html, jQuery, a
 * stand-in for MxChat's own script (host-fixture.js), then ours, talking to a
 * fake admin-ajax. Environment:
 *
 *   MXCHAT_PLUS_JS_MODULES  a node_modules directory holding jsdom and jquery
 *                           (default: ./node_modules)
 *   MXCHAT_PLUS_JQUERY      a jquery.js to load instead, such as WordPress's
 *                           wp-includes/js/jquery/jquery.js
 *   MXCHAT_HOST_JS          the real mxchat-basic/js/mxchat_transcripts.js:
 *                           every test then runs against it as well
 */
const { describe, test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const MODULES = process.env.MXCHAT_PLUS_JS_MODULES || path.join(__dirname, 'node_modules');
const { JSDOM } = require(path.join(MODULES, 'jsdom'));
const JQUERY = fs.readFileSync(process.env.MXCHAT_PLUS_JQUERY || path.join(MODULES, 'jquery', 'dist', 'jquery.js'), 'utf8');
const PAGE = fs.readFileSync(path.join(__dirname, 'transcripts-page.html'), 'utf8');
const OURS = fs.readFileSync(path.join(__dirname, '..', '..', '..', '..', 'assets', 'js', 'admin-transcripts-export.js'), 'utf8');

const HOSTS = [{ name: 'host fixture', source: fs.readFileSync(path.join(__dirname, 'host-fixture.js'), 'utf8') }];
if (process.env.MXCHAT_HOST_JS) {
    HOSTS.push({ name: 'MxChat host script', source: fs.readFileSync(process.env.MXCHAT_HOST_JS, 'utf8') });
}

const OUR_ACTION = 'mxchat_plus_export_transcripts';
const OUR_NONCE = 'plus-nonce';
const SEARCH_DEBOUNCE = 350; // the host waits 300 ms after the last keystroke
const TIMEOUT = { timeout: 20000 };

const wait = (ms = 15) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * A fresh transcripts screen. Returns the window plus the handles the tests
 * drive it with; `server` is the fake admin-ajax behind it.
 */
async function boot(hostSource, options = {}) {
    const dom = new JSDOM(PAGE, {
        runScripts: 'outside-only',
        url: 'https://example.test/wp-admin/admin.php?page=mxchat-transcripts'
    });
    const w = dom.window;
    w.eval(JQUERY);
    const $ = w.jQuery;
    $.fx.off = true;
    // jsdom has no layout, so jQuery's :visible would always be false.
    $.expr.pseudos.visible = function (el) {
        for (let n = el; n && n.nodeType === 1; n = n.parentNode) {
            if (w.getComputedStyle(n).display === 'none') {
                return false;
            }
        }
        return true;
    };

    w.ajaxurl = '/wp-admin/admin-ajax.php';
    w.mxchatAdmin = { export_nonce: 'host-export-nonce' };
    w.mxchatPlusTranscriptsExport = {
        ajaxUrl: '/wp-admin/admin-ajax.php',
        action: OUR_ACTION,
        nonce: OUR_NONCE,
        maxSessions: options.maxSessions || 2000,
        i18n: {}
    };

    const server = {
        pages: { 1: ['s1', 's2', 's3'], 2: ['s4', 's5', 's6'] },
        deleteSucceeds: true,
        fetchSucceeds: true,
        echoSessionId: true,
        hold: false,
        held: [],
        async release() {
            this.hold = false;
            this.held.splice(0).forEach((finish) => finish());
            await wait();
        }
    };

    function reply(p) {
        switch (p.get('action')) {
            case 'mxchat_fetch_chat_history': {
                if (p.get('search')) {
                    return { json: { success: true, sessions: [] } };
                }
                const page = parseInt(p.get('page'), 10) || 1;
                const ids = server.pages[page] || [];
                return {
                    json: {
                        success: true, page: page, total_pages: Object.keys(server.pages).length,
                        showing_start: 1, showing_end: ids.length, total_sessions: 6,
                        sessions: ids.map((id) => ({ session_id: id, initials: 'XX', display_name: id, preview: '', time_display: '', message_count: 1 }))
                    }
                };
            }
            case 'mxchat_fetch_conversation':
                if (!server.fetchSucceeds) {
                    return { json: { success: false, data: { message: 'Session not found' } } };
                }
                return {
                    json: {
                        success: true,
                        session_id: server.echoSessionId ? p.get('session_id') : undefined,
                        user: { initials: 'XX', name: 'Visitor', sub: '', email: '' },
                        page: { url: '' }, clicked_urls: [], messages: [], message_count: 0, started: '-'
                    }
                };
            case 'mxchat_delete_chat_history':
                // The host echoes JSON through admin-ajax's text/html header,
                // so jQuery hands both scripts a string.
                return {
                    text: JSON.stringify(server.deleteSucceeds ? { success: '1 chat session(s) deleted.' } : { error: 'Nope' }),
                    type: 'text/html; charset=UTF-8'
                };
            default:
                return { json: { success: false } };
        }
    }

    $.ajaxTransport('+*', function (opts) {
        return {
            send: function (headers, done) {
                const p = new w.URLSearchParams(opts.data);
                const finish = () => {
                    const r = reply(p);
                    const body = r.text !== undefined ? r.text : JSON.stringify(r.json);
                    done(200, 'OK', { text: body }, 'Content-Type: ' + (r.type || 'application/json') + '\r\n');
                };
                if (server.hold) {
                    server.held.push(finish);
                } else {
                    w.setTimeout(finish, 0);
                }
            },
            abort: function () {}
        };
    });

    const submits = [];
    $(w.document).on('submit', 'form', function (e) {
        const fields = {};
        $(this).find('input').each(function () { fields[this.name] = this.value; });
        submits.push(fields);
        e.preventDefault();
    });

    const confirms = [];
    const alerts = [];
    const page = {
        w: w,
        $: $,
        server: server,
        submits: submits,
        confirms: confirms,
        alerts: alerts,
        confirmAnswer: true,
        wait: wait,
        close() { w.close(); },
        box(id) { return w.document.querySelector('.mxch-chat-item[data-session-id="' + id + '"] .mxch-chat-checkbox'); },
        tick(...ids) { ids.forEach((id) => this.box(id).click()); },
        selectAllBox() { return w.document.getElementById('mxch-select-all'); },
        // Array.from() in this realm: an array built inside the window has
        // the window's Array prototype, and deepStrictEqual compares those.
        rowIds() { return Array.from(w.document.querySelectorAll('.mxch-chat-item'), (el) => el.getAttribute('data-session-id')); },
        counter() { return $('#mxch-selected-count'); },
        hostCount() { return this.counter().hasClass('has-selection') ? this.counter().text() : '0 selected'; },
        ours() { return w.document.getElementById('mxchat-plus-export-selected'); },
        oursCurrent() { return w.document.getElementById('mxchat-plus-export-current'); },
        rangeDialogOpen() { return $('#mxchat-plus-export-range').length === 1; },
        closeRangeDialog() { $('#mxchat-plus-export-range [data-mxchat-plus-close]').first().trigger('click'); },
        async goToPage(n) {
            $('.mxch-page-btn[data-page="' + n + '"]').get(0).click();
            await wait();
        },
        async refresh() {
            w.document.getElementById('mxch-refresh-list').click();
            await wait();
        },
        async search(term) {
            $('#mxch-search-transcripts').val(term).trigger('input');
            await wait(SEARCH_DEBOUNCE);
        },
        async openConversation(id) {
            $('.mxch-chat-item[data-session-id="' + id + '"]').get(0).click();
            await wait();
        },
        async deleteSelection() {
            w.document.getElementById('mxch-delete-selected').click();
            w.document.getElementById('mxch-transcript-confirm-go').click();
            await wait();
        },
        /** Clicks our toolbar button; the ids it submitted, or null. */
        exportSelection() {
            const before = submits.length;
            confirms.length = 0;
            this.ours().click();
            return submits.length > before ? JSON.parse(submits[submits.length - 1].session_ids_json || '[]') : null;
        },
        lastSubmit() { return submits[submits.length - 1]; }
    };

    w.confirm = (message) => { confirms.push(message); return page.confirmAnswer; };
    w.alert = (message) => { alerts.push(message); };

    w.eval(hostSource);
    w.eval(OURS);

    // jQuery's ready fires asynchronously in jsdom; then the first list load.
    await wait(30);
    await wait();
    assert.ok(page.ours(), 'toolbar button injected');
    assert.deepEqual(page.rowIds(), ['s1', 's2', 's3'], 'page 1 rendered');
    return page;
}

for (const host of HOSTS) {
    describe(host.name, () => {
        const it = (name, fn, options) => test(name, TIMEOUT, async () => {
            const page = await boot(host.source, options);
            try {
                await fn(page);
            } finally {
                page.close();
            }
        });

        // ─── Selection mirror ────────────────────────────────────────

        it('exports a selection kept across pages, under our action and nonce', async (p) => {
            p.tick('s1', 's2');
            await p.goToPage(2);
            p.tick('s4');
            assert.equal(p.hostCount(), '3 selected');

            assert.deepEqual(p.exportSelection(), ['s1', 's2', 's4']);
            assert.equal(p.lastSubmit().action, OUR_ACTION);
            assert.equal(p.lastSubmit().security, OUR_NONCE);
            assert.deepEqual(p.confirms, [], 'no warning when the counts agree');
        });

        it('exports an off-screen selection instead of opening the date-range dialog', async (p) => {
            p.tick('s1', 's2');
            await p.goToPage(2);
            assert.equal(p.$('.mxch-chat-checkbox:checked').length, 0);

            assert.deepEqual(p.exportSelection(), ['s1', 's2']);
            assert.equal(p.rangeDialogOpen(), false);
        });

        it('keeps the selection across Refresh', async (p) => {
            p.tick('s2');
            await p.refresh();
            assert.deepEqual(p.exportSelection(), ['s2']);
        });

        it('reads row ids as strings, the way the host does', async (p) => {
            // .data() would turn these into a number, null and true.
            p.server.pages[1] = ['123', 'null', 'true'];
            await p.refresh();
            p.tick('123', 'null', 'true');
            await p.goToPage(2);
            assert.deepEqual(p.exportSelection(), ['123', 'null', 'true']);
        });

        // ─── "All" ───────────────────────────────────────────────────

        it('"All" adds the rows on screen, and unticked clears every page', async (p) => {
            p.tick('s1', 's2');
            await p.goToPage(2);
            p.selectAllBox().click();
            assert.equal(p.hostCount(), '5 selected');
            assert.deepEqual(p.exportSelection(), ['s1', 's2', 's4', 's5', 's6']);

            p.selectAllBox().click();
            assert.equal(p.counter().hasClass('has-selection'), false);
            assert.equal(p.exportSelection(), null, 'nothing submitted');
            assert.equal(p.rangeDialogOpen(), true, 'an empty selection opens the range dialog');
            assert.deepEqual(p.confirms, []);
        });

        it('"All" ticked while the list is loading keeps the selection', async (p) => {
            p.tick('s1', 's2');
            p.server.hold = true;
            p.w.document.querySelector('.mxch-page-btn[data-page="2"]').click();
            assert.equal(p.rowIds().length, 0, 'spinner, no rows');

            p.selectAllBox().click();
            assert.equal(p.selectAllBox().checked, false, 'the host unticks it at once');
            assert.equal(p.hostCount(), '2 selected', 'the host kept its Set');

            await p.server.release();
            assert.deepEqual(p.rowIds(), ['s4', 's5', 's6']);
            assert.deepEqual(p.exportSelection(), ['s1', 's2']);
            assert.deepEqual(p.confirms, [], 'our copy and the host agree');
        });

        it('"All" clicked through its label while the list is loading keeps the selection', async (p) => {
            p.tick('s3');
            p.server.hold = true;
            p.w.document.querySelector('.mxch-page-btn[data-page="2"]').click();
            p.w.document.querySelector('.mxch-bulk-select-all span').click();
            await p.server.release();
            assert.deepEqual(p.rowIds(), ['s4', 's5', 's6'], 's3 is off screen');

            assert.equal(p.hostCount(), '1 selected');
            assert.deepEqual(p.exportSelection(), ['s3']);
        });

        it('"All" ticked on "No chats found" keeps the selection', async (p) => {
            p.tick('s1');
            const page1 = p.server.pages[1];
            p.server.pages[1] = [];
            await p.refresh();
            assert.match(p.$('#mxch-chat-list').text(), /No chats found/);

            p.selectAllBox().click();
            p.server.pages[1] = page1;
            await p.refresh();
            await p.goToPage(2);

            assert.equal(p.hostCount(), '1 selected');
            assert.deepEqual(p.exportSelection(), ['s1']);
        });

        it('"All" ticked on an empty search keeps the selection', async (p) => {
            await p.goToPage(2);
            p.tick('s4', 's5');
            await p.search('zzz');
            assert.equal(p.rowIds().length, 0);

            p.selectAllBox().click();
            await p.search('');
            assert.deepEqual(p.rowIds(), ['s1', 's2', 's3'], 'back on page 1');
            assert.equal(p.hostCount(), '2 selected');

            assert.deepEqual(p.exportSelection(), ['s4', 's5']);
            assert.equal(p.rangeDialogOpen(), false);
            assert.deepEqual(p.confirms, []);
        });

        it('"All" unticked while the list is loading still clears everything', async (p) => {
            p.selectAllBox().click();
            assert.equal(p.hostCount(), '3 selected');
            p.server.hold = true;
            p.w.document.getElementById('mxch-refresh-list').click();
            assert.equal(p.selectAllBox().checked, true, 'nothing rewrote the box yet');

            p.selectAllBox().click();
            await p.server.release();

            assert.equal(p.counter().hasClass('has-selection'), false);
            assert.equal(p.exportSelection(), null);
            assert.equal(p.rangeDialogOpen(), true);
            assert.deepEqual(p.confirms, []);
        });

        it('a programmatic change of "All" falls back to the box state', async (p) => {
            p.$('#mxch-select-all').prop('checked', true).trigger('change');
            await p.goToPage(2);
            assert.deepEqual(p.exportSelection(), ['s1', 's2', 's3']);
        });

        it('a cancelled click on "All" leaves no stale intent behind', async (p) => {
            p.$('#mxch-select-all').one('click', (e) => e.preventDefault());
            p.selectAllBox().click();
            assert.equal(p.selectAllBox().checked, false, 'the click was cancelled');
            await p.wait();

            p.tick('s2');
            p.$('#mxch-select-all').prop('checked', false).trigger('change');
            assert.equal(p.counter().hasClass('has-selection'), false, 'the host cleared');
            assert.equal(p.exportSelection(), null, 'and so did we');
        });

        // ─── The toolbar button's warnings ───────────────────────────

        it('warns when the host counts more than the export holds; cancel aborts', async (p) => {
            p.tick('s1');
            p.counter().text('7 selected');
            p.confirmAnswer = false;
            assert.equal(p.exportSelection(), null);
            assert.equal(p.confirms.length, 1);
            assert.match(p.confirms[0], /7 selected conversations, but this export will contain 1/);

            p.confirmAnswer = true;
            assert.deepEqual(p.exportSelection(), ['s1']);
        });

        it('reads the host counter only while it shows a selection', async (p) => {
            p.tick('s1');
            p.tick('s1'); // back to zero: the host leaves "1 selected" as text
            assert.equal(p.counter().text(), '1 selected');
            assert.equal(p.counter().hasClass('has-selection'), false);

            assert.equal(p.exportSelection(), null);
            assert.deepEqual(p.confirms, [], 'stale text is not a selection');
            assert.equal(p.rangeDialogOpen(), true);
            p.closeRangeDialog();

            p.tick('s2');
            assert.deepEqual(p.exportSelection(), ['s2']);
            assert.deepEqual(p.confirms, []);
        });

        it('says so when the host counts a selection our copy lost, instead of "nothing is selected"', async (p) => {
            // A divergence no mirrored gesture produces: the backstop.
            p.counter().text('3 selected').addClass('has-selection');
            p.confirmAnswer = false;
            assert.equal(p.exportSelection(), null);
            assert.equal(p.confirms.length, 1);
            assert.match(p.confirms[0], /MxChat shows 3 selected conversations, but this export lost track of them/);
            assert.equal(p.rangeDialogOpen(), false, 'cancel opens nothing');

            p.confirmAnswer = true;
            assert.equal(p.exportSelection(), null);
            assert.equal(p.rangeDialogOpen(), true, 'OK goes on to the date range');
        });

        it('warns before the server caps the export', async (p) => {
            p.selectAllBox().click();
            await p.goToPage(2);
            p.selectAllBox().click();
            p.confirmAnswer = false;
            assert.equal(p.exportSelection(), null);
            assert.match(p.confirms[0], /first 5 of the 6/);
        }, { maxSessions: 5 });

        it('posts the selection as one JSON field, never one input per id', async (p) => {
            p.tick('s1', 's2');
            p.exportSelection();
            const fields = Object.keys(p.lastSubmit()).sort();
            assert.deepEqual(fields, ['action', 'security', 'session_ids_json']);
        });

        // ─── Deletes ─────────────────────────────────────────────────

        it('keeps the selection after a failed delete and drops it after a successful one', async (p) => {
            p.tick('s1');
            await p.goToPage(2);
            p.server.deleteSucceeds = false;
            await p.deleteSelection();
            assert.equal(p.alerts.length, 1, 'the host reported the failure');
            assert.deepEqual(p.exportSelection(), ['s1']);

            p.server.deleteSucceeds = true;
            await p.deleteSelection();
            assert.equal(p.counter().hasClass('has-selection'), false);
            assert.equal(p.exportSelection(), null);
            assert.equal(p.rangeDialogOpen(), true);
        });

        // ─── The open conversation ───────────────────────────────────

        it('exports the open conversation alone, and only once it has loaded', async (p) => {
            const current = p.oursCurrent();
            assert.ok(current, 'drawer button injected');
            assert.equal(current.previousElementSibling.id, 'mxch-export-current');
            assert.equal(current.disabled, true, 'disabled while nothing is open');

            p.tick('s1', 's2');
            p.$('.mxch-chat-item[data-session-id="s3"]').get(0).click();
            assert.equal(current.disabled, true, 'disabled while loading');
            await p.wait();
            assert.equal(current.disabled, false);

            current.click();
            assert.deepEqual(JSON.parse(p.lastSubmit().session_ids_json), ['s3']);
            assert.equal(p.lastSubmit().action, OUR_ACTION);
        });

        it('never exports the previous conversation after a failed load', async (p) => {
            await p.openConversation('s1');
            p.server.fetchSucceeds = false;
            await p.openConversation('s2');
            assert.equal(p.oursCurrent().disabled, true);
            const before = p.submits.length;
            p.oursCurrent().click();
            assert.equal(p.submits.length, before);
        });

        it('forgets a conversation deleted from its own drawer', async (p) => {
            await p.openConversation('s2');
            p.w.document.getElementById('mxch-delete-current').click();
            p.w.document.getElementById('mxch-transcript-confirm-go').click();
            await p.wait();
            assert.equal(p.oursCurrent().disabled, true);
            assert.equal(p.$('#mxch-conversation-content').css('display'), 'none');
        });

        it('forgets the conversation on the mobile back button', async (p) => {
            await p.openConversation('s1');
            assert.equal(p.oursCurrent().disabled, false);
            p.w.document.getElementById('mxch-transcript-back-btn').click();
            assert.equal(p.oursCurrent().disabled, true);
        });

        it('falls back to the requested id when the host does not echo it', async (p) => {
            p.server.echoSessionId = false;
            await p.openConversation('s2');
            p.oursCurrent().click();
            assert.deepEqual(JSON.parse(p.lastSubmit().session_ids_json), ['s2']);
        });

        it('leaves the host\'s own Export alone and injects each button once', async (p) => {
            await p.openConversation('s1');
            p.w.document.getElementById('mxch-export-current').click();
            assert.equal(p.lastSubmit().action, 'mxchat_export_transcripts');
            await p.refresh();
            assert.equal(p.w.document.querySelectorAll('#mxchat-plus-export-selected').length, 1);
            assert.equal(p.w.document.querySelectorAll('#mxchat-plus-export-current').length, 1);
        });
    });
}
