<?php
/**
 * The host gate.
 *
 * mxchat-plus is a companion to MxChat (mxchat-basic), a third-party plugin it
 * never modifies: every feature here is grafted on through WordPress hooks
 * alone. That is a deliberate constraint — mxchat-basic ships its own updates,
 * and any patch applied to its files would be silently overwritten by the next
 * one.
 *
 * Consequence: without the host active, nothing this plugin does makes sense
 * (no knowledge base to index, no LLM calls to cache). The bootstrap therefore
 * refuses to register a single hook and shows an admin notice instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_Host {

    /**
     * Host version this plugin was developed and verified against. Compared
     * with the running host by version_status() and shown on the Modules
     * screen; bump it only once the suite and PHPStan pass against the new
     * host, and never claim more than was checked.
     */
    const TESTED_UP_TO = '3.2.25';

    /**
     * Oldest host version whose integration seams we rely on, as the README
     * and readme.txt state. Below it the seams this plugin prefers
     * (MxChat_Utils::embedding_preflight(), the reflection targets of the
     * DuckDB module, the host's own system breakpoint) are missing and only
     * degraded fallbacks run. Notice only, never a boot gate.
     */
    const MINIMUM = '3.2.21';

    /**
     * Is the host loaded? Checked on `plugins_loaded` at a late priority, so
     * both signals are reliable by then. MxChat exposes the integrator class
     * and a set of global functions; either signal is enough, and accepting
     * both keeps us working if the host reorganises one of them.
     */
    public static function is_active(): bool {
        return class_exists('MxChat_Integrator') || function_exists('mxchat_activate');
    }

    /**
     * Host version, or '' when it cannot be determined. MXCHAT_BASE_VERSION is
     * the header version the host defines for itself; we prefer it over
     * MXCHAT_VERSION, which carries a time() suffix in the host's dev mode and
     * would break any comparison.
     */
    public static function version(): string {
        if (defined('MXCHAT_BASE_VERSION')) {
            return (string) MXCHAT_BASE_VERSION;
        }
        if (defined('MXCHAT_VERSION')) {
            return (string) MXCHAT_VERSION;
        }
        return '';
    }

    /**
     * Does the host already place its own Anthropic prompt-cache breakpoint?
     *
     * Since 3.2.21 mxchat-basic wraps its system prompt in content blocks
     * carrying `cache_control: ephemeral` (MxChat_Integrator::
     * mxchat_anthropic_system_blocks). Anthropic allows at most four
     * breakpoints per request, so the prompt-cache module must detect what the
     * host already marked rather than spend a second breakpoint on the same
     * bytes. The module inspects the outgoing payload for this; the method is
     * here so the admin screen and `doctor` can explain the situation.
     */
    public static function has_native_prompt_cache(): bool {
        // Version-based, deliberately: the host method that does this
        // (mxchat_anthropic_system_blocks) is private, so probing for it says
        // nothing reliable about behaviour. 3.2.21 is the release that
        // introduced it.
        $version = self::version();
        return $version !== '' && version_compare($version, '3.2.21', '>=');
    }

    /**
     * Is the host's streaming mode on? Streaming goes out through curl_exec,
     * which bypasses the WordPress HTTP API entirely — `http_request_args`
     * never fires, so prompt caching cannot be injected on that path. This is
     * the single most common reason for "the cache does nothing".
     *
     * Mirrors the server-side gate in MxChat_Integrator::mxchat_handle_chat_request()
     * (MxChat 3.2.21 → 3.2.25): `isset($o['enable_streaming_toggle']) &&
     * $o['enable_streaming_toggle'] === 'on'`. `enable_streaming_toggle` is the
     * host's own key in the host's `mxchat_options` (both host literals). A
     * missing key therefore means NOT streaming: the widget's 'on' default only
     * decides which AJAX action the browser calls, and the server then refuses
     * to stream. The keys this method used to read (`enable_streaming`,
     * `mxchat_enable_streaming`) never existed in any MxChat release, so the
     * Modules screen always said "Off".
     *
     * Global setting only: a Multi-Bot bot whose own options carry another
     * value replaces the global options entirely for its requests.
     */
    public static function streaming_enabled(): bool {
        $options = get_option('mxchat_options', []);
        return is_array($options)
            && isset($options['enable_streaming_toggle'])
            && $options['enable_streaming_toggle'] === 'on';
    }

    /**
     * Would MxChat answer a main-chat turn through its buffered AI Tools
     * (function-calling) loop with Claude? That loop posts through
     * wp_remote_post — so this module caches it — and runs even with streaming
     * on: mxchat_handle_chat_request() enters it before the streaming branch.
     *
     * Mirrors, from our side, the host's private gate
     * MxChat_Integrator::mxchat_fc_should_run() and the 'claude' case of
     * mxchat_fc_resolve_provider() (MxChat 3.2.25):
     *   - MxChat_Tool_Registry::is_enabled() and a non-empty enabled_tools()
     *     (which withholds live-agent handover tools outside staffed hours, so
     *     the answer can depend on the time of day);
     *   - MxChat_Model_Catalog::supports_tools($model) when the catalog exists;
     *   - a `claude-` model and a `claude_api_key` in mxchat_options.
     * Every host call is guarded: these are third-party symbols that may move,
     * and an older host without them simply has no such loop.
     *
     * Reads the global options only, like streaming_enabled().
     */
    public static function ai_tools_route_claude(): bool {
        $options = get_option('mxchat_options', []);
        if (!is_array($options)) {
            return false;
        }
        $model = isset($options['model']) && is_string($options['model']) ? $options['model'] : '';
        if (!str_starts_with(strtolower($model), 'claude-')) {
            return false;
        }
        if (empty($options['claude_api_key'])) {
            return false;
        }
        if (self::call_host('MxChat_Tool_Registry', 'is_enabled') !== true) {
            return false;
        }
        if (self::host_has('MxChat_Model_Catalog', 'supports_tools')
            && self::call_host('MxChat_Model_Catalog', 'supports_tools', [$model]) !== true
        ) {
            return false;
        }
        $tools = self::call_host('MxChat_Tool_Registry', 'enabled_tools');
        return is_array($tools) && $tools !== [];
    }

    /**
     * Does the host keep a no-tool first hop of its AI Tools loop as the
     * answer? Since 3.2.22 (MxChat_Integrator::mxchat_fc_keep_first_hop_text)
     * it does by default, so with AI Tools active MOST turns are buffered, not
     * only the ones that call a tool. Before 3.2.22 a no-tool turn fell back to
     * the normal (possibly streamed) path.
     *
     * Applies the host's own filter `mxchat_fc_keep_first_hop_text` (a host
     * literal) read-only, with the same default, so an add-on that turned the
     * behaviour off is reflected here.
     */
    public static function keeps_first_hop_text(string $model = ''): bool {
        $version = self::version();
        if ($version !== '' && version_compare($version, '3.2.22', '<')) {
            return false;
        }
        return (bool) apply_filters('mxchat_fc_keep_first_hop_text', true, $model);
    }

    /**
     * How MxChat's Anthropic AI Tools loop (MxChat_Integrator::
     * mxchat_fc_loop_anthropic(), MxChat 3.2.25) decides what each hop
     * offers, for the prompt-cache module's in-loop breakpoints:
     *   - depth / budget: what the loop itself reads,
     *     MxChat_Tool_Registry::max_depth() and max_tool_calls_per_turn()
     *     (public statics, filtered by the host's mxchat_function_call_max_depth
     *     and mxchat_function_call_max_per_turn; present in 3.2.21 → 3.2.25).
     *     null when the host does not expose them;
     *   - budget_gate: no tools are offered once the turn's call budget is
     *     spent (3.2.22+, "plan 3c23ab" in the host);
     *   - single_call_choice: tool_choice carries disable_parallel_tool_use
     *     when one call is left (3.2.23+, "plan 150b72").
     * Both feature versions come from the host changelog; only the 3.2.21
     * and 3.2.25 code was read. An undetectable version gets the newest
     * behaviour, like keeps_first_hop_text().
     *
     * @return array{depth:int|null, budget:int|null, budget_gate:bool, single_call_choice:bool}
     */
    public static function tool_loop_profile(): array {
        $version = self::version();
        return [
            'depth'              => self::positive_int(self::call_host('MxChat_Tool_Registry', 'max_depth')),
            'budget'             => self::positive_int(self::call_host('MxChat_Tool_Registry', 'max_tool_calls_per_turn')),
            'budget_gate'        => $version === '' || version_compare($version, '3.2.22', '>='),
            'single_call_choice' => $version === '' || version_compare($version, '3.2.23', '>='),
        ];
    }

    /** A host count, or null when it is not a positive integer. */
    private static function positive_int(mixed $value): ?int {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $value = (int) $value;
            return $value > 0 ? $value : null;
        }
        return null;
    }

    /**
     * How the running host version compares with what this plugin knows.
     * Informational only — never a boot gate: a host older than MINIMUM still
     * boots every module (each host call is guarded and falls back), and the
     * Modules screen only shows a notice.
     *
     * @param string|null $version Defaults to version().
     * @return string One of 'unknown', 'below_minimum', 'newer_than_tested', 'supported'.
     */
    public static function version_status(?string $version = null): string {
        $version ??= self::version();
        if ($version === '') {
            // version_compare('', MINIMUM, '<') is true: skipping avoids
            // calling an undetectable host "too old".
            return 'unknown';
        }
        if (version_compare($version, self::MINIMUM, '<')) {
            return 'below_minimum';
        }
        if (version_compare($version, self::TESTED_UP_TO, '>')) {
            return 'newer_than_tested';
        }
        return 'supported';
    }

    /** True when the host class exists and exposes a callable static method. */
    private static function host_has(string $class, string $method): bool {
        return class_exists($class) && is_callable([$class, $method]);
    }

    /**
     * Calls a host static method when it exists, null otherwise. The class
     * name stays a variable so static analysis does not require the host's
     * classes, which are not part of this project.
     *
     * @param array<int,mixed> $args
     */
    private static function call_host(string $class, string $method, array $args = []): mixed {
        $callable = [$class, $method];
        if (!class_exists($class) || !is_callable($callable)) {
            return null;
        }
        try {
            return call_user_func_array($callable, $args);
        } catch (\Throwable $e) {
            // A diagnostic must never take the settings screen down.
            return null;
        }
    }

    public static function render_missing_notice(): void {
        echo '<div class="notice notice-error"><p><strong>MxChat Plus</strong>: ';
        echo esc_html__(
            'The MxChat plugin must be installed and activated for MxChat Plus to do anything. MxChat Plus extends MxChat and never modifies it.',
            'mxchat-plus'
        );
        echo '</p></div>';
    }
}
