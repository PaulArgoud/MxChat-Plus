<?php
/**
 * Search adapter — connects mxchat to DuckDB via two parallel paths.
 *
 * Option A (optional, inert by default — requires hand-patching the host):
 *   Hooks `mxchat_pre_vector_query` to short-circuit the HTTP call to Pinecone
 *   inside `find_relevant_content_pinecone()`. Returns the matches wrapped in
 *   the Pinecone response shape (`['matches' => [...]]`) so the upstream code
 *   can keep consuming `$results` unchanged. The patch needed in mxchat-basic
 *   is documented in tools/patches/README.md. NOTE: `mxchat_pre_vector_query`
 *   does NOT exist in mxchat-basic (verified absent in 3.2.25) — this handler
 *   is registered at no cost so it lights up if upstream ever ships the hook,
 *   but the nominal path is Option B below. Patching a third-party plugin
 *   that auto-updates is discouraged: the next release reverts it silently.
 *
 *   The legacy `mxchat_pinecone_matches_override` hook (older patch contract,
 *   matches array returned directly, also absent in 3.2.25) is registered for
 *   backward compat with installs that already applied the previous patch —
 *   both can coexist safely; only one of them fires per request depending on
 *   which version of the patch upstream is running.
 *
 * Option B (works on stock mxchat):
 *   MxChat is handed a Pinecone-shaped config pointing at our Pinecone-proxy
 *   REST endpoint, and then calls it exactly as it calls Pinecone: GET
 *   /vectors/list and /vectors/fetch, POST /query, /vectors/upsert,
 *   /vectors/delete and /describe_index_stats (MxChat 3.2.25). Two seams,
 *   both opt-in:
 *     - the default bot: `pre_option_mxchat_pinecone_addon_options`
 *       (`takeover_default_bot_pinecone`), because MxChat reads that option
 *       directly for it and never asks the filter below;
 *     - Multi-Bot bots: `mxchat_get_bot_pinecone_config`, which MxChat asks
 *       for every other bot when its Multi-Bot add-on is loaded
 *       (`takeover_multibot_bots` / `takeover_bot_ids`).
 *   The proxy speaks the classic vector-index protocol only, never the
 *   document-index API MxChat 3.2.22+ can also use, so every config we hand
 *   out says index_type 'vector'.
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

    /** Host literal: MxChat's Pinecone settings row. */
    const HOST_PINECONE_OPTION = 'mxchat_pinecone_addon_options';

    /** Host literal: the Knowledge screen's page slug (MxChat 3.2.25). */
    const HOST_KNOWLEDGE_PAGE = 'mxchat-prompts';

    /**
     * Host literals: the AJAX actions behind the Pinecone card's
     * document-index controls (MxChat_Pinecone_Documents::boot, MxChat
     * 3.2.22+). Every one of them talks to Pinecone itself (api.pinecone.io
     * or an index host) with the key typed in the card, falling back to the
     * stored option's key.
     */
    const HOST_DOCUMENT_INDEX_ACTIONS = [
        'mxchat_pinecone_docs_create_index',
        'mxchat_pinecone_docs_check_index',
        'mxchat_pinecone_docs_suggest_name',
        'mxchat_pinecone_docs_migrate',
        'mxchat_pinecone_docs_delete_old_index',
    ];
    const HOST_DOCUMENT_INDEX_ACTION_PREFIX = 'mxchat_pinecone_docs_';

    /**
     * True while stored_pinecone_options() reads the real row through
     * get_option(): inject_pinecone_addon_options() then steps aside, so
     * WordPress serves the stored value (and its object cache) as usual.
     */
    private static bool $reading_stored = false;

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

        // The default bot never goes through `mxchat_get_bot_pinecone_config`:
        // MxChat reads `mxchat_pinecone_addon_options` straight from
        // wp_options for it (MxChat_Integrator::get_bot_pinecone_config,
        // MxChat_Utils::is_pinecone_enabled_for_bot,
        // MxChat_Pinecone_Manager::mxchat_get_bot_pinecone_options,
        // MxChat_Pinecone_Documents::config — MxChat 3.2.25). The `pre_option_*`
        // filter is the canonical WP-core way to answer that read. Explicit
        // opt-in via `takeover_default_bot_pinecone` so sites running real
        // Pinecone aren't surprised by their settings being hijacked.
        add_filter('pre_option_' . self::HOST_PINECONE_OPTION, [$this, 'inject_pinecone_addon_options']);

        // Backstop for the write side of the same takeover: a save of
        // MxChat's Pinecone card posts back the values the card was rendered
        // with — ours. See restore_real_pinecone_fields().
        add_filter('pre_update_option_' . self::HOST_PINECONE_OPTION, [$this, 'restore_real_pinecone_fields']);

        // Priority 1: before MxChat's own handlers (priority 10), which end
        // the request. They would send our proxy token to Pinecone and record
        // our proxy host as the vector index to migrate from.
        foreach (self::HOST_DOCUMENT_INDEX_ACTIONS as $action) {
            add_action('wp_ajax_' . $action, [$this, 'refuse_document_index_action'], 1);
        }

        // Defence in depth: whatever path tries it, a proxy token never
        // leaves this site for Pinecone.
        add_filter('pre_http_request', [$this, 'refuse_proxy_token_to_pinecone'], 10, 3);

        if (is_admin()) {
            add_action('admin_notices', [$this, 'render_error_notice']);
            add_action('admin_notices', [$this, 'render_knowledge_screen_notice']);
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
     * `mxchat_get_bot_pinecone_config` handler: advertise the DuckDB proxy as
     * a Multi-Bot bot's Pinecone backend — only for a bot the owner opted in
     * (Options::is_bot_routed()). MxChat asks this filter for every
     * non-default bot once its Multi-Bot add-on is loaded, and the answer
     * decides where that bot reads and writes its knowledge
     * (MxChat_Integrator::get_bot_pinecone_config, MxChat 3.2.25).
     *
     * $current_config passes through untouched:
     *   - for 'default' and 'testing' (MxChat resolves both through the
     *     default-bot option; the takeover above covers them);
     *   - for a bot whose config says index_type 'document' — its Pinecone
     *     document index (MxChat 3.2.22+) holds its knowledge, and the proxy
     *     does not speak that API;
     *   - for a bot that is not opted in. It keeps whatever the add-on gave it:
     *     its own Pinecone index, or MxChat's WordPress database, which MxChat
     *     shares between bots. A routed bot reads only its own DuckDB
     *     namespace, so it does not see that shared knowledge.
     *
     * Keys of $current_config other than the five below are kept.
     *
     * @param mixed $current_config
     * @param mixed $bot_id
     * @return mixed
     */
    public function inject_pinecone_config($current_config, $bot_id) {
        $opts = MxChat_Plus_DuckDB_Options::get();
        if (empty($opts['enabled'])) return $current_config;

        $bot_id = is_scalar($bot_id) ? (string) $bot_id : '';
        if ($bot_id === '' || in_array($bot_id, MxChat_Plus_DuckDB_Options::RESERVED_BOT_IDS, true)) {
            return $current_config;
        }
        if (is_array($current_config) && ($current_config['index_type'] ?? 'vector') === 'document') {
            return $current_config;
        }
        if (!MxChat_Plus_DuckDB_Options::is_bot_routed($bot_id)) {
            return $current_config;
        }

        return array_merge(is_array($current_config) ? $current_config : [], [
            'use_pinecone' => true,
            'api_key'      => MxChat_Plus_DuckDB_Pinecone_Proxy::get_or_create_token_for($bot_id),
            'host'         => MxChat_Plus_DuckDB_Pinecone_Proxy::pinecone_host(),
            'namespace'    => $bot_id,
            'index_type'   => 'vector',
        ]);
    }

    /**
     * `pre_option_mxchat_pinecone_addon_options` handler — answers the
     * default-bot Pinecone-config read with the DuckDB proxy. MxChat uses
     * this option directly for the default bot, so neither
     * `inject_pinecone_config` above nor the `mxchat_pre_vector_query`
     * filter would fire.
     *
     * Default-off (the `takeover_default_bot_pinecone` setting must be
     * explicitly enabled) so sites already configured against real Pinecone
     * don't see their settings hijacked.
     *
     * The answer is the STORED row with five keys replaced, never a fresh
     * array, so everything else MxChat keeps there survives a read: Top K
     * (MxChat_Integrator reads mxchat_pinecone_top_k from this value), the
     * document-index bookkeeping (docs_*, vector_host, vector_index), index
     * name and environment. The option shape in MxChat 3.2.25
     * (MxChat_Admin::sanitize_pinecone_settings):
     *   mxchat_use_pinecone '1'|'0', mxchat_pinecone_api_key,
     *   mxchat_pinecone_host (no scheme), mxchat_pinecone_namespace (no form
     *   field), mxchat_pinecone_index, mxchat_pinecone_environment,
     *   mxchat_pinecone_top_k, mxchat_pinecone_index_type 'vector'|'document',
     *   mxchat_pinecone_docs_verified_host, mxchat_pinecone_vector_host,
     *   mxchat_pinecone_vector_index, mxchat_pinecone_docs_cloud / _region /
     *   _language.
     * Replaced: use_pinecone '1', api_key = the default namespace's proxy
     * token, host = the proxy, namespace 'default', and index_type 'vector'
     * — forced, because a stored 'document' would send MxChat down the
     * documents API, which the proxy does not speak. A missing Top K is
     * filled from this plugin's own top_k.
     *
     * Two moments get the stored row instead, because MxChat writes back
     * what it reads there:
     *   - while MxChat's sanitizer runs
     *     (`sanitize_option_mxchat_pinecone_addon_options`): since 3.2.22 it
     *     carries the namespace and the verified document host from its own
     *     get_option() read (MxChat_Admin::sanitize_pinecone_settings), which
     *     would otherwise persist our 'default' namespace and an empty
     *     verified host into the real row;
     *   - during the card's document-index AJAX actions, which save straight
     *     to the database (MxChat_Pinecone_Documents::persist_options) and
     *     would record our proxy host as the vector index to migrate from.
     *     They are refused while the takeover is on anyway
     *     (refuse_document_index_action()); this covers an action added by a
     *     later MxChat.
     * The card itself still renders our values; a save of it is put right by
     * restore_real_pinecone_fields().
     *
     * @param mixed $value WordPress passes `false` when no other pre-filter
     *                     has shortcircuited; returning non-false skips the
     *                     DB read.
     * @return mixed
     */
    public function inject_pinecone_addon_options($value) {
        if (self::$reading_stored) return $value;

        $opts = MxChat_Plus_DuckDB_Options::get();
        if (empty($opts['enabled']) || empty($opts['takeover_default_bot_pinecone'])) {
            return $value;
        }
        if (self::host_is_sanitizing_pinecone_settings() || self::in_document_index_ajax()) {
            return $value;
        }

        // Guard against re-entrancy — `get_or_create_token_for()` reads and
        // may write our own token option; cheap static flag, scoped to the
        // request.
        static $reentry = false;
        if ($reentry) return $value;
        $reentry = true;
        try {
            $view = self::proxy_view_of(self::stored_pinecone_options(), $opts);
        } finally {
            $reentry = false;
        }
        return $view;
    }

    /**
     * The stored Pinecone row with the takeover's keys put on top (see
     * inject_pinecone_addon_options()).
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $opts   this plugin's settings
     * @return array<string, mixed>
     */
    public static function proxy_view_of(array $stored, array $opts): array {
        $view = $stored;
        $view['mxchat_use_pinecone']        = '1';
        $view['mxchat_pinecone_api_key']    = MxChat_Plus_DuckDB_Pinecone_Proxy::get_or_create_token_for('default');
        $view['mxchat_pinecone_host']       = MxChat_Plus_DuckDB_Pinecone_Proxy::pinecone_host();
        $view['mxchat_pinecone_namespace']  = 'default';
        $view['mxchat_pinecone_index_type'] = 'vector';
        if (!isset($view['mxchat_pinecone_top_k']) || absint($view['mxchat_pinecone_top_k']) < 1) {
            $view['mxchat_pinecone_top_k'] = (string) max(1, min(1000, (int) ($opts['top_k'] ?? 50)));
        }
        return $view;
    }

    /**
     * The real `mxchat_pinecone_addon_options` row, read through get_option()
     * (so the object cache is used) with our pre_option filter stepped aside.
     *
     * @return array<string, mixed>
     */
    public static function stored_pinecone_options(): array {
        self::$reading_stored = true;
        try {
            $stored = get_option(self::HOST_PINECONE_OPTION, []);
        } finally {
            self::$reading_stored = false;
        }
        return is_array($stored) ? $stored : [];
    }

    /**
     * `pre_update_option_mxchat_pinecone_addon_options` backstop. While the
     * takeover is on, MxChat's Pinecone card is rendered from the proxy view,
     * and a "Save Pinecone Settings" posts that view back: our token, our
     * host, use_pinecone '1', index_type 'vector' (MxChat_Admin::
     * sanitize_pinecone_settings rebuilds the array from the form). Saved as
     * is, the real credentials would be gone — and with the module or the
     * takeover off later, MxChat would keep calling a proxy that no longer
     * answers. MxChat offers no hook on the form, so the fix is here.
     *
     * Only a save that carries our token or our host is touched. Then every
     * key still holding our override goes back to the stored value: api_key,
     * host, use_pinecone, namespace (removed when the stored row has none),
     * index_type, an emptied docs_verified_host, and a vector_host pointing
     * at the proxy. Anything the owner really edited — Top K, the index name
     * — goes through. $old_value is the filtered view, so the stored row is
     * read again here.
     *
     * Known gap: when no row exists yet, update_option() takes our view for
     * the old value and updates nothing, so that first save is lost (nothing
     * real is lost with it).
     *
     * @param mixed $value
     * @return mixed
     */
    public function restore_real_pinecone_fields($value) {
        if (!is_array($value)) return $value;

        $opts = MxChat_Plus_DuckDB_Options::get();
        if (empty($opts['enabled']) || empty($opts['takeover_default_bot_pinecone'])) {
            return $value;
        }

        $carries_token = is_scalar($value['mxchat_pinecone_api_key'] ?? null)
            && self::is_proxy_token((string) $value['mxchat_pinecone_api_key']);
        $carries_host = is_scalar($value['mxchat_pinecone_host'] ?? null)
            && self::is_proxy_host((string) $value['mxchat_pinecone_host']);
        if (!$carries_token && !$carries_host) {
            return $value;
        }

        $stored = self::stored_pinecone_options();

        if ($carries_token) {
            $value['mxchat_pinecone_api_key'] = (string) ($stored['mxchat_pinecone_api_key'] ?? '');
        }
        if ($carries_host) {
            $value['mxchat_pinecone_host'] = (string) ($stored['mxchat_pinecone_host'] ?? '');
        }
        if ((string) ($value['mxchat_use_pinecone'] ?? '') === '1') {
            $value['mxchat_use_pinecone'] = (string) ($stored['mxchat_use_pinecone'] ?? '0') === '1' ? '1' : '0';
        }
        if (($value['mxchat_pinecone_namespace'] ?? null) === 'default') {
            if (array_key_exists('mxchat_pinecone_namespace', $stored)) {
                $value['mxchat_pinecone_namespace'] = $stored['mxchat_pinecone_namespace'];
            } else {
                unset($value['mxchat_pinecone_namespace']);
            }
        }
        if (($value['mxchat_pinecone_index_type'] ?? 'vector') === 'vector' && isset($stored['mxchat_pinecone_index_type'])) {
            $value['mxchat_pinecone_index_type'] = $stored['mxchat_pinecone_index_type'];
        }
        if ((string) ($value['mxchat_pinecone_docs_verified_host'] ?? '') === ''
            && !empty($stored['mxchat_pinecone_docs_verified_host'])) {
            $value['mxchat_pinecone_docs_verified_host'] = $stored['mxchat_pinecone_docs_verified_host'];
        }
        if (is_scalar($value['mxchat_pinecone_vector_host'] ?? null)
            && self::is_proxy_host((string) $value['mxchat_pinecone_vector_host'])) {
            $value['mxchat_pinecone_vector_host'] = (string) ($stored['mxchat_pinecone_vector_host'] ?? '');
        }
        return $value;
    }

    /**
     * Priority-1 `wp_ajax_mxchat_pinecone_docs_*` handler. While the takeover
     * is on, the Pinecone card holds the proxy's host and token, and MxChat's
     * document-index actions would post that token to api.pinecone.io (a
     * secret of this site sent to a third party, answered 401) or, with a real
     * key typed in, record our proxy host as the classic index to migrate
     * from (MxChat_Pinecone_Documents::ajax_check_index / ajax_create_index,
     * MxChat 3.2.25). They are answered here, before MxChat's handler, with
     * the same JSON error shape MxChat's card displays.
     */
    public function refuse_document_index_action(): void {
        $opts = MxChat_Plus_DuckDB_Options::get();
        if (empty($opts['enabled']) || empty($opts['takeover_default_bot_pinecone'])) {
            return;
        }
        wp_send_json_error([
            'message' => __('MxChat Plus serves the default bot from DuckDB (Default-bot routing is on), so the Pinecone document-index tools are unavailable: the API key in this form is the DuckDB proxy token, not a Pinecone key. Turn Default-bot routing off in MxChat Plus → DuckDB to manage a Pinecone index.', 'mxchat-plus'),
            'code'    => 'mxchat_plus_duckdb_takeover',
        ]);
    }

    /**
     * `pre_http_request` guard: refuse any request to a pinecone.io host
     * (the control plane api.pinecone.io or an index host) whose Api-Key
     * header is one of our proxy tokens. Those tokens open this site's vector
     * store; Pinecone has no use for them. Cannot stop MxChat recording our
     * host with a real key — refuse_document_index_action() does that.
     *
     * @param mixed $pre
     * @param mixed $args
     * @param mixed $url
     * @return mixed
     */
    public function refuse_proxy_token_to_pinecone($pre, $args, $url) {
        if ($pre !== false || !is_string($url) || !is_array($args)) {
            return $pre;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host !== 'pinecone.io' && substr($host, -12) !== '.pinecone.io') {
            return $pre;
        }
        $key = self::api_key_header($args['headers'] ?? []);
        if ($key === '' || !self::is_proxy_token($key)) {
            return $pre;
        }
        return new WP_Error(
            'mxchat_plus_duckdb_token_refused',
            __('MxChat Plus stopped a request that would have sent the DuckDB proxy token to Pinecone. That token only opens this site\'s DuckDB proxy.', 'mxchat-plus')
        );
    }

    /**
     * The Api-Key header of a WP HTTP API request, matched case-insensitively
     * (headers may be an array or a raw "Name: value" string).
     *
     * @param mixed $headers
     */
    private static function api_key_header($headers): string {
        if (is_string($headers)) {
            foreach (preg_split('/\r\n|\n/', $headers) ?: [] as $line) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2 && strtolower(trim($parts[0])) === 'api-key') {
                    return trim($parts[1]);
                }
            }
            return '';
        }
        if (!is_array($headers)) return '';
        foreach ($headers as $name => $v) {
            if (is_string($name) && strtolower(trim($name)) === 'api-key' && is_scalar($v)) {
                return trim((string) $v);
            }
        }
        return '';
    }

    /** Is $key one of the proxy's tokens (per-namespace map or the legacy global one)? */
    public static function is_proxy_token(string $key): bool {
        if ($key === '') return false;
        $tokens = array_values((array) get_option(MxChat_Plus_DuckDB_Pinecone_Proxy::PROXY_TOKEN_MAP_OPTION, []));
        $tokens[] = get_option(MxChat_Plus_DuckDB_Pinecone_Proxy::PROXY_TOKEN_OPTION, '');
        foreach ($tokens as $token) {
            if (is_string($token) && $token !== '' && hash_equals($token, $key)) {
                return true;
            }
        }
        return false;
    }

    /** Is $host the proxy's host fragment (scheme, case and trailing slash ignored)? */
    public static function is_proxy_host(string $host): bool {
        $normalise = static function (string $h): string {
            return strtolower(trim((string) preg_replace('#^https?://#i', '', trim($h)), '/'));
        };
        $host = $normalise($host);
        return $host !== '' && $host === $normalise(MxChat_Plus_DuckDB_Pinecone_Proxy::pinecone_host());
    }

    /**
     * `register_setting()` hooks MxChat's sanitizer on this filter, so it is
     * running exactly while MxChat rebuilds the row from a form save.
     */
    private static function host_is_sanitizing_pinecone_settings(): bool {
        return function_exists('doing_filter') && doing_filter('sanitize_option_' . self::HOST_PINECONE_OPTION);
    }

    private static function in_document_index_ajax(): bool {
        if (!function_exists('wp_doing_ajax') || !wp_doing_ajax()) return false;
        $action = isset($_REQUEST['action']) && is_string($_REQUEST['action'])
            ? sanitize_key(wp_unslash($_REQUEST['action']))
            : '';
        return $action !== '' && strpos($action, self::HOST_DOCUMENT_INDEX_ACTION_PREFIX) === 0;
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

    /**
     * On MxChat's Knowledge screen, while the takeover is on: say what the
     * Pinecone card shows. MxChat's card has no hook of its own, hence a
     * standard admin notice.
     */
    public function render_knowledge_screen_notice(): void {
        if (!current_user_can('manage_options') || !self::on_knowledge_screen()) return;
        $opts = MxChat_Plus_DuckDB_Options::get();
        if (empty($opts['enabled']) || empty($opts['takeover_default_bot_pinecone'])) return;

        echo '<div class="notice notice-info"><p><strong>MxChat Plus</strong>: ';
        echo esc_html__('DuckDB serves the default bot (Default-bot routing is on). The Pinecone card shows the DuckDB proxy\'s host and token instead of your Pinecone credentials; saving the card keeps your real credentials. The document-index tools (Check index, Create index, Migrate) are unavailable while routing is on.', 'mxchat-plus');
        echo '</p></div>';
    }

    private static function on_knowledge_screen(): bool {
        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen && strpos((string) $screen->id, self::HOST_KNOWLEDGE_PAGE) !== false) {
                return true;
            }
        }
        $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return $page === self::HOST_KNOWLEDGE_PAGE;
    }
}
