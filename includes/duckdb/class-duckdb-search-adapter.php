<?php
/**
 * Search adapter — connects mxchat to DuckDB via two parallel paths.
 *
 * Option A (preferred, requires upstream patch):
 *   Hooks `mxchat_pre_vector_query` to short-circuit the HTTP call to Pinecone
 *   inside `find_relevant_content_pinecone()`. Returns the matches wrapped in
 *   the Pinecone response shape (`['matches' => [...]]`) so the upstream code
 *   can keep consuming `$results` unchanged. The patch needed in mxchat-basic
 *   is documented in patches/README.md.
 *
 *   The legacy `mxchat_pinecone_matches_override` hook (older patch contract,
 *   matches array returned directly) is also registered for backward compat
 *   with installs that already applied the previous patch — both can coexist
 *   safely; only one of them fires per request depending on which version of
 *   the patch upstream is running.
 *
 * Option B (works on stock mxchat):
 *   Hooks `mxchat_get_bot_pinecone_config` to return a Pinecone-shaped config
 *   pointing at our local Pinecone-proxy REST endpoint. mxchat then performs
 *   wp_remote_post() calls against the proxy, which translates them to DuckDB.
 *
 * All filters are registered unconditionally; Option A wins when the patch
 * is present (because the upstream code short-circuits before the HTTP call).
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Search_Adapter {

    private static ?self $instance = null;

    const ERROR_TRANSIENT = 'mxchat_plus_duckdb_search_error';

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function register_hooks(): void {
        // Preferred contract — WordPress-canonical `pre_*` short-circuit.
        // Takes a context array, returns the full Pinecone response shape.
        add_filter('mxchat_pre_vector_query', [$this, 'pre_vector_query'], 10, 2);

        // Legacy contract — kept for installs that applied the older patch.
        // Takes positional args, returns the matches array directly.
        add_filter('mxchat_pinecone_matches_override', [$this, 'override_matches'], 10, 5);

        add_filter('mxchat_get_bot_pinecone_config', [$this, 'inject_pinecone_config'], 10, 2);

        // mxchat-basic short-circuits the `mxchat_get_bot_pinecone_config`
        // filter for `bot_id === 'default'` when the Multi-Bot Manager isn't
        // loaded (class-mxchat-integrator.php:1960, class-pinecone-manager.php:1238
        // in 3.2.6). It reads `mxchat_pinecone_addon_options` straight from
        // wp_options instead. The `pre_option_*` filter is the canonical
        // WP-core way to intercept that read without persisting anything —
        // explicit opt-in via the `takeover_default_bot_pinecone` setting so
        // sites running real Pinecone aren't surprised by their settings
        // being hijacked.
        add_filter('pre_option_mxchat_pinecone_addon_options', [$this, 'inject_pinecone_addon_options']);

        if (is_admin()) {
            add_action('admin_notices', [$this, 'render_error_notice']);
        }
    }

    /**
     * Preferred filter handler. Follows the WordPress `pre_*` short-circuit
     * convention: receives a context array, returns the full Pinecone response
     * shape (`['matches' => [...]]`) so the upstream code can keep using
     * `$results` exactly as it does after `json_decode`.
     *
     * Expected context keys: vector, top_k, namespace, bot_id, filter (opt).
     *
     * @param mixed $previous null = fall through to HTTP; array = short-circuit
     * @param array $ctx      query context from mxchat
     * @return array|null
     */
    public function pre_vector_query($previous, array $ctx = []) {
        if ($previous !== null) return $previous;

        $opts = MxChat_Plus_DuckDB_Options::get();
        if (empty($opts['enabled'])) return null;

        $embedding = isset($ctx['vector']) && is_array($ctx['vector']) ? $ctx['vector'] : [];
        if (empty($embedding)) return null;

        $top_k = (int) ($ctx['top_k'] ?? $opts['top_k']);
        $bot = (string) ($ctx['namespace'] ?? $ctx['bot_id'] ?? 'default');
        if ($bot === '') $bot = 'default';
        $filter = isset($ctx['filter']) && is_array($ctx['filter']) ? $ctx['filter'] : [];

        try {
            $matches = MxChat_Plus_DuckDB_Vector_Store::current()
                ->query_pinecone_shape($embedding, $top_k ?: 50, $bot, $filter);
            return ['matches' => $matches, 'namespace' => $bot];
        } catch (\Throwable $e) {
            $msg = 'pre_vector_query: ' . $e->getMessage();
            error_log('[mxchat-plus] ' . $msg);
            MxChat_Plus_DuckDB_Options::update(['last_error' => $msg]);
            set_transient(self::ERROR_TRANSIENT, $msg, HOUR_IN_SECONDS);
            // Returning an empty matches array beats falling through to a
            // misrouted Pinecone host; the user sees "no result" + an admin notice.
            return ['matches' => [], 'namespace' => $bot];
        }
    }

    /**
     * Legacy filter handler (older patch contract).
     *
     * @param mixed       $previous     null = let other filters / HTTP run; array = matches
     * @param array       $embedding    query embedding vector
     * @param string      $bot_id
     * @param string|null $namespace
     * @param array|null  $request_body original request body (for filter, topK, etc.)
     *
     * @return array|null  matches in Pinecone format, or null to fall through.
     */
    public function override_matches($previous, $embedding, $bot_id, $namespace = null, $request_body = null) {
        if ($previous !== null) return $previous;

        $opts = MxChat_Plus_DuckDB_Options::get();
        if (empty($opts['enabled'])) return null;

        $top_k = (int) ($request_body['topK'] ?? $opts['top_k']);
        $filter = isset($request_body['filter']) && is_array($request_body['filter']) ? $request_body['filter'] : [];
        $bot = $namespace ?: ($bot_id ?: 'default');

        try {
            return MxChat_Plus_DuckDB_Vector_Store::current()
                ->query_pinecone_shape($embedding, $top_k ?: 50, $bot, $filter);
        } catch (\Throwable $e) {
            $msg = 'override_matches: ' . $e->getMessage();
            error_log('[mxchat-plus] ' . $msg);
            MxChat_Plus_DuckDB_Options::update(['last_error' => $msg]);
            set_transient(self::ERROR_TRANSIENT, $msg, HOUR_IN_SECONDS);
            // Empty matches > falling through to a misrouted Pinecone host; the
            // user sees a "no result" response and a flashing admin notice.
            return [];
        }
    }

    /**
     * If our plugin is enabled, advertise ourselves to mxchat as the Pinecone
     * backend for this bot. The host points to our REST proxy.
     */
    public function inject_pinecone_config($current_config, $bot_id) {
        $opts = MxChat_Plus_DuckDB_Options::get();
        if (empty($opts['enabled'])) return $current_config;

        $namespace = $bot_id ?: 'default';
        return [
            'use_pinecone' => true,
            'api_key'      => MxChat_Plus_DuckDB_Pinecone_Proxy::get_or_create_token_for($namespace),
            'host'         => MxChat_Plus_DuckDB_Pinecone_Proxy::pinecone_host(),
            'namespace'    => $namespace,
        ];
    }

    /**
     * `pre_option_mxchat_pinecone_addon_options` handler — shortcircuits the
     * default-bot Pinecone-config read. mxchat-basic uses the value of this
     * option directly when the bot is `default` AND the Multi-Bot Manager
     * isn't loaded, so neither `inject_pinecone_config` above nor the
     * `mxchat_pre_vector_query` filter would fire. Returning our proxy-
     * shaped config here makes Option B reach the default bot transparently.
     *
     * Default-off (the `takeover_default_bot_pinecone` setting must be
     * explicitly enabled) so sites already configured against real Pinecone
     * don't see their settings hijacked. The DB row is never touched —
     * `update_option` paths still write through normally.
     *
     * The mxchat option shape mxchat-basic expects (per
     * admin/class-pinecone-manager.php:1239):
     *   {
     *     mxchat_use_pinecone:        '1' | '0',
     *     mxchat_pinecone_api_key:    string,
     *     mxchat_pinecone_host:       string (no scheme, no trailing slash),
     *     mxchat_pinecone_namespace:  string,
     *     mxchat_pinecone_environment: string,
     *     mxchat_pinecone_index:       string,
     *   }
     *
     * @param mixed $value WordPress passes `false` when no other pre-filter
     *                     has shortcircuited; returning non-false skips the
     *                     DB read.
     */
    public function inject_pinecone_addon_options($value) {
        $opts = MxChat_Plus_DuckDB_Options::get();
        if (empty($opts['enabled']) || empty($opts['takeover_default_bot_pinecone'])) {
            return $value;
        }

        // Guard against re-entrancy — `get_or_create_token_for()` reads our
        // own option which would loop right back through this filter if the
        // namespace token isn't cached yet. Cheap static flag, scoped to the
        // request.
        static $reentry = false;
        if ($reentry) return $value;
        $reentry = true;
        try {
            $namespace = 'default';
            $token = MxChat_Plus_DuckDB_Pinecone_Proxy::get_or_create_token_for($namespace);
            $host  = MxChat_Plus_DuckDB_Pinecone_Proxy::pinecone_host();
        } finally {
            $reentry = false;
        }

        return [
            'mxchat_use_pinecone'         => '1',
            'mxchat_pinecone_api_key'     => $token,
            'mxchat_pinecone_host'        => $host,
            'mxchat_pinecone_namespace'   => $namespace,
            'mxchat_pinecone_environment' => '',
            'mxchat_pinecone_index'       => '',
        ];
    }

    public function render_error_notice(): void {
        $msg = get_transient(self::ERROR_TRANSIENT);
        if (!$msg) return;
        if (!current_user_can('manage_options')) return;
        echo '<div class="notice notice-error is-dismissible"><p><strong>MxChat DuckDB</strong> : ';
        echo esc_html(sprintf(
            /* translators: %s = underlying error message */
            __('vector search error — %s', 'mxchat-plus'),
            $msg
        ));
        echo '</p></div>';
    }
}
