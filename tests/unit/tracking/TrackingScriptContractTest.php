<?php
/**
 * Static locks on assets/js/tracking.js.
 *
 * The repository has no JavaScript test runner, and the behaviours below only
 * show in a real browser driving MxChat's own chat-script.js. Each one has
 * already broken once without an error anywhere, and each is one innocent
 * looking refactor away from breaking again:
 *
 *   - later() must stay a setTimeout. Browsers drain the microtask queue
 *     between the listeners of a user-initiated dispatch, so a Promise.then()
 *     or queueMicrotask() would run BEFORE the host's bubble-phase suggestion
 *     handler, whose ensureSession() creates a first-time visitor's session
 *     (sendMessageToChatbot(), js/chat-script.js, MxChat 3.2.25): their first
 *     suggestion click would lose its session id. Even a jsdom harness cannot
 *     catch that one — an event dispatched from script never empties the
 *     stack between listeners, so a microtask and a macrotask look the same —
 *     which is why it is locked here, on the source.
 *   - hostOpensInNewTab() must test the RAW href, the way the host's
 *     prepareTrackedLink() does (MxChat 3.2.25). link.href is always absolute,
 *     so testing it would report every relative link as opened in a new tab.
 *   - the session id is looked up afresh on every event: a cache keeps
 *     reporting the conversation the visitor just closed with "Start new
 *     chat".
 *   - the chat-opened event rides the host's own literals (the mxchat:opened
 *     event, window.MxChatInstances.opened, MxChat 3.2.22+), and only when the
 *     setting is on.
 *   - the click listeners stay capture-phase and passive, and nothing cancels
 *     an event: since MxChat 3.2.24 the browser follows every link itself.
 *
 * These assertions read the code, not its behaviour: they are crude by
 * design, and a deliberate change to any of them means updating this file on
 * purpose. What they cannot replace is a check in a browser with the module's
 * debug log on (docs/tracking/USAGE.md, Troubleshooting).
 */

use PHPUnit\Framework\TestCase;

final class TrackingScriptContractTest extends TestCase {

    private static ?string $code = null;

    /** The script with comments removed and whitespace runs collapsed; string literals kept. */
    private static function code(): string {
        if (self::$code !== null) {
            return self::$code;
        }

        $js  = (string) file_get_contents(MXCHAT_PLUS_DIR . 'assets/js/tracking.js');
        $len = strlen($js);
        $out = '';

        for ($i = 0; $i < $len; $i++) {
            $c    = $js[$i];
            $next = $i + 1 < $len ? $js[$i + 1] : '';

            if ($c === '/' && $next === '/') {
                $end = strpos($js, "\n", $i);
                $i   = $end === false ? $len : $end - 1;
                continue;
            }
            if ($c === '/' && $next === '*') {
                $end = strpos($js, '*/', $i + 2);
                $i   = $end === false ? $len : $end + 1;
                $out .= ' ';
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $end  = self::string_end($js, $i);
                $out .= substr($js, $i, $end - $i + 1);
                $i    = $end;
                continue;
            }
            if (ctype_space($c)) {
                if ($out !== '' && substr($out, -1) !== ' ') {
                    $out .= ' ';
                }
                continue;
            }
            $out .= $c;
        }

        return self::$code = trim($out);
    }

    /** Index of the quote closing the string literal opened at $start. */
    private static function string_end(string $s, int $start): int {
        $quote = $s[$start];
        $len   = strlen($s);
        for ($j = $start + 1; $j < $len; $j++) {
            if ($s[$j] === '\\') {
                $j++;
                continue;
            }
            if ($s[$j] === $quote) {
                return $j;
            }
        }
        return $len - 1;
    }

    /**
     * The body of the block that $opener (ending in "{", whitespace collapsed)
     * opens. The opener must appear exactly once in the script.
     */
    private function block(string $opener): string {
        $code = self::code();
        $this->assertSame(1, substr_count($code, $opener), "tracking.js no longer has exactly one `$opener`");

        $start = (int) strpos($code, $opener) + strlen($opener) - 1;
        $depth = 0;
        for ($i = $start, $len = strlen($code); $i < $len; $i++) {
            $c = $code[$i];
            if ($c === "'" || $c === '"' || $c === '`') {
                $i = self::string_end($code, $i);
                continue;
            }
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}' && --$depth === 0) {
                return substr($code, $start + 1, $i - $start - 1);
            }
        }

        $this->fail("unbalanced braces after `$opener`");
    }

    /** Offset of $needle in $haystack, failing the test when it is absent. */
    private function offset(string $haystack, string $needle, string $where): int {
        $at = strpos($haystack, $needle);
        $this->assertNotFalse($at, "`$needle` not found in $where");
        return (int) $at;
    }

    // ─── Deferral: a later task, never a microtask ────────────────────────

    public function test_later_defers_to_a_macrotask_and_never_to_a_microtask(): void {
        $body = $this->block('function later(fn) {');

        $this->assertStringContainsString('window.setTimeout(', $body);
        $this->assertMatchesRegularExpression('/\},\s*0\s*\)\s*;?\s*$/', $body, 'setTimeout(…, 0)');
        $this->assertDoesNotMatchRegularExpression(
            '/\bPromise\b|queueMicrotask|\.then\s*\(|\basync\b|\bawait\b|MutationObserver/',
            $body,
            'a microtask runs before the host\'s bubble-phase handler creates the session'
        );
    }

    public function test_a_suggestion_resolves_label_and_bot_now_and_the_session_later(): void {
        $body  = $this->block('function handleSuggestion(button) {');
        $later = $this->offset($body, 'later(function', 'handleSuggestion()');

        // Synchronous: the button is certainly where it was clicked.
        $this->assertLessThan($later, $this->offset($body, 'shortText(button)', 'handleSuggestion()'));
        $this->assertLessThan($later, $this->offset($body, 'isDuplicate(', 'handleSuggestion()'));
        $this->assertLessThan($later, $this->offset($body, 'botIdFor(button)', 'handleSuggestion()'));

        // Deferred: read before the host's handler, the very click that
        // starts a conversation could never be joined to it.
        foreach (['sessionIdToReport(', 'sendMatomo(', 'sendGa4('] as $deferred) {
            $this->assertGreaterThan($later, $this->offset($body, $deferred, 'handleSuggestion()'), $deferred);
        }
    }

    public function test_a_chat_open_reads_the_session_after_the_launcher_created_it(): void {
        // The launcher dispatches mxchat:opened before it calls ensureSession().
        $body  = $this->block('function handleOpen(botId, embedded) {');
        $later = $this->offset($body, 'later(function', 'handleOpen()');

        foreach (['sessionIdToReport(', 'sendMatomo(', 'sendGa4('] as $deferred) {
            $this->assertGreaterThan($later, $this->offset($body, $deferred, 'handleOpen()'), $deferred);
        }
    }

    public function test_a_link_click_is_sent_synchronously(): void {
        // The page may be unloading as soon as the dispatch ends.
        $body = $this->block('function handleLink(link) {');

        $this->assertStringNotContainsString('later(', $body);
        $this->assertStringNotContainsString('setTimeout', $body);
        $this->assertStringContainsString('sendMatomo(', $body);
        $this->assertStringContainsString('sendGa4(', $body);
    }

    // ─── link_target: the host's rule, on the raw href ────────────────────

    public function test_the_link_target_is_decided_on_the_raw_href(): void {
        $link = $this->block('function handleLink(link) {');
        $this->assertMatchesRegularExpression(
            '/var rawHref = link\.getAttribute\([\'"]href[\'"]\)/',
            $link,
            'rawHref must come from the attribute, never from link.href'
        );
        $this->assertStringContainsString('effectiveTarget(link, rawHref)', $link);

        $this->assertStringContainsString('hostOpensInNewTab(link, rawHref)', $this->block('function effectiveTarget(link, rawHref) {'));
    }

    public function test_host_opens_in_new_tab_mirrors_prepare_tracked_link(): void {
        $body = $this->block('function hostOpensInNewTab(link, rawHref) {');

        // Case-sensitive http:// or https:// on the raw href, as the host tests it.
        $this->assertStringContainsString("'http://'", $body);
        $this->assertStringContainsString("'https://'", $body);
        $this->assertStringContainsString('rawHref.', $body);
        $this->assertStringNotContainsString('link.href', $body, 'resolved: every relative link would read as absolute');
        $this->assertDoesNotMatchRegularExpression('/toLowerCase|toUpperCase|\/i\b/', $body, 'the host\'s test is case-sensitive');

        // The delegate is bound: window.getChatSession is exported after it.
        $this->assertStringContainsString("typeof window.getChatSession !== 'function'", $body);
        // The delegate's scope, and the host's own setting.
        $this->assertMatchesRegularExpression('/closest\([\'"]\.chat-box[\'"]\)/', $body);
        $this->assertStringContainsString("link_target_toggle !== 'on'", $body);
    }

    // ─── Session id: gated at the source, never cached ────────────────────

    public function test_the_session_id_is_looked_up_afresh_and_gated_first(): void {
        $body = $this->block('function sessionIdToReport(botId) {');

        // Privacy: with "Include the session id" off, nothing is even read.
        $this->assertLessThan(
            $this->offset($body, 'window.getChatSession(botId)', 'sessionIdToReport()'),
            $this->offset($body, 'if (!sendSessionId) {', 'sessionIdToReport()')
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\[\s*botId\s*\]\s*=[^=]/',
            $body,
            'a per-bot cache outlives resetChatSession() and silentResetSession()'
        );
        $this->assertDoesNotMatchRegularExpression('/\bsessionCache\b/', self::code());
    }

    // ─── Chat opened: the host's literals, opt-in ─────────────────────────

    public function test_the_chat_opened_listener_uses_the_hosts_literals_and_only_when_enabled(): void {
        $gated = $this->block('if (trackChatOpen) {');

        // Host literals (MxChat 3.2.22+). Never rename.
        $this->assertMatchesRegularExpression('/document\.addEventListener\([\'"]mxchat:opened[\'"]/', $gated);
        $this->assertStringContainsString('detail.embedded === true', $gated);
        $this->assertStringContainsString('reportEarlierOpens()', $gated);
        $this->assertSame(1, preg_match_all('/[\'"]mxchat:opened[\'"]/', self::code()), 'registered outside the opt-in gate');

        $late = $this->block('function reportEarlierOpens() {');
        $this->assertStringContainsString('window.MxChatInstances', $late);
        $this->assertStringContainsString('instances.opened', $late);

        // Mirrors the host's isEmbeddedBot().
        $this->assertMatchesRegularExpression(
            '/getElementById\([\'"]floating-chatbot-[\'"] \+ botId\)/',
            $this->block('function isEmbedded(botId) {')
        );
    }

    // ─── Click listeners ──────────────────────────────────────────────────

    public function test_the_click_listeners_are_capture_phase_passive_and_never_cancel(): void {
        $code = self::code();

        foreach (['click', 'auxclick'] as $type) {
            $this->assertStringContainsString(
                "document.addEventListener('$type', handleClick, { capture: true, passive: true })",
                $code
            );
        }
        // The host's per-<a> handler stops propagation; ours must not cancel
        // anything either: since MxChat 3.2.24 the browser follows the link.
        $this->assertDoesNotMatchRegularExpression(
            '/preventDefault|stopPropagation|stopImmediatePropagation|returnValue\s*=/',
            $code
        );
    }

    public function test_a_suggestion_is_reported_on_click_only(): void {
        // The host asks the question on 'click' only; a middle- or right-click
        // (auxclick) on a suggestion asks nothing.
        $body = $this->block('function handleClick(event) {');

        $this->assertLessThan(
            $this->offset($body, 'handleSuggestion(suggestion)', 'handleClick()'),
            $this->offset($body, "if (event.type === 'click') {", 'handleClick()')
        );
    }
}
