<?php
/**
 * Pinecone wire-protocol emulator (Option B).
 *
 * Exposes a REST namespace under /wp-json/mxchat-plus/v1/pinecone-proxy/
 * that MxChat's classic (vector-index) Pinecone code path calls as if it
 * were a real Pinecone serverless index. Implements the subset MxChat 3.2.25
 * actually sends (MxChat_Integrator, MxChat_Utils, MxChat_Pinecone_Manager,
 * MxChat_Knowledge_Manager and MxChat_Pinecone_Documents::classic_*):
 *
 *   POST      /query                 top-K similarity search
 *   GET|POST  /vectors/fetch         fetch by id — GET with the ids REPEATED
 *                                    (ids=a&ids=b), which is how every host
 *                                    caller sends it since 3.2.20; POST with
 *                                    a JSON body is kept for older callers
 *   POST      /vectors/upsert        write (metadata round-trips: title,
 *                                    created_at, last_updated, add-on keys)
 *   POST      /vectors/delete        delete by id array
 *   GET|POST  /vectors/list          keyset-paginated id listing (prefix,
 *                                    paginationToken → pagination.next)
 *   GET|POST  /describe_index_stats  per-namespace vector counts
 *
 * Document-index calls (/namespaces/{ns}/documents/…, MxChat 3.2.22+) are
 * answered with an explicit Pinecone-style 501: this proxy speaks the vector
 * protocol only, and a silent 404 would read as "empty index" in places.
 *
 * Authentication: a shared token, sent as the `Api-Key` header like real
 * Pinecone. Per-namespace tokens (one per bot) only reach their own
 * namespace; the legacy global token reaches every namespace.
 *
 * Rate limit: 120 requests/minute per namespace for external callers.
 * MxChat's own loopback calls are recognised by an HMAC header that
 * sign_loopback_request() adds on `http_request_args` for requests to this
 * proxy only, and are exempt — except /query, which keeps a separate, larger
 * bucket as a CPU guard — so a bulk admin flow (REST DELETE /knowledge × 50,
 * kb-reimport) neither fails with 429 nor starves visitors' chat queries.
 *
 * Query text: the visitor's retrieval query never reaches this separate
 * loopback request on its own. It is captured on the host filter
 * `mxchat_retrieval_query` and added to the /query body on
 * `http_request_args`, only when the target is this proxy, so the BM25 leg
 * of hybrid retrieval gets the words while a real Pinecone index never sees
 * them. This only delivers the text: what BM25 then matches is up to the
 * store, whose limits (an FTS index built only by the schema migrations,
 * the default tokenizer dropping digits, BM25-only hits skipped by
 * Vector_Store_Query::blend_hybrid()) are listed under "Hybrid retrieval"
 * in docs/duckdb/ARCHITECTURE.md.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Pinecone_Proxy {

    private static ?self $instance = null;

    const REST_NS = 'mxchat-plus/v1';
    const REST_BASE = 'pinecone-proxy';
    /** Legacy single global token (still used as a fallback). */
    const PROXY_TOKEN_OPTION = 'mxchat_plus_duckdb_proxy_token';
    /** New per-namespace token map: { 'default' => 'abc…', 'bot_42' => 'xyz…' }. */
    const PROXY_TOKEN_MAP_OPTION = 'mxchat_plus_duckdb_proxy_token_map';

    /** Request param carrying the namespace the token authorised ('*' = any). */
    const AUTH_NS_PARAM = '_mxchat_plus_duckdb_auth_ns';

    /** Default of `mxchat_plus_duckdb_proxy_rate_limit_per_minute` (external callers). */
    const RATE_LIMIT_DEFAULT = 120;

    /**
     * Default of `mxchat_plus_duckdb_proxy_internal_rate_limit_per_minute`:
     * the CPU guard on MxChat's own /query loopbacks (every other loopback
     * call is unthrottled).
     */
    const INTERNAL_QUERY_RATE_LIMIT_DEFAULT = 600;

    /** Header carrying the loopback signature (see sign_loopback_request()). */
    const INTERNAL_HEADER = 'X-MxChat-Plus-Internal';

    /** /query body key carrying the visitor's retrieval text to handle_query(). */
    const QUERY_TEXT_KEY = 'mxchat_plus_query_text';

    /** Cap on the query text accepted in a /query body (characters). */
    const QUERY_TEXT_MAX_CHARS = 2000;

    /** topK when a /query body carries none (MxChat always sends one). */
    const DEFAULT_TOP_K = 50;

    /** /vectors/list page size when the request names none (Pinecone's default). */
    const DEFAULT_LIST_LIMIT = 100;

    /**
     * Host literal: the option behind MxChat's "Hybrid keyword boost" switch
     * (admin/class-ajax-handler.php and MxChat_Integrator, MxChat 3.2.25),
     * 'on' | 'off'. Read only, and only when the site opts in through
     * `mxchat_plus_duckdb_proxy_follow_host_hybrid_toggle`.
     */
    const HOST_HYBRID_TOGGLE_OPTION = 'mxchat_hybrid_keyword_toggle';

    private bool $hooks_registered = false;

    /**
     * Retrieval text per bot, captured on `mxchat_retrieval_query` during
     * the current (chat) request.
     *
     * @var array<string, string>
     */
    private array $retrieval_queries = [];

    /** @var array<string, string> the unfiltered message per bot (the host's own fallback) */
    private array $original_queries = [];

    /** Bot whose classic /query body the host has just built (mxchat_pinecone_query_body). */
    private ?string $pending_query_bot = null;

    /** Bot of the /query this proxy is answering right now, null outside handle_query(). */
    private ?string $request_bot = null;

    /** Query text of that /query ('' when the body carried none). */
    private string $request_query_text = '';

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function register_routes(): void {
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        $this->register_loopback_hooks();
    }

    /** rest_api_init handler (public so the route table can be tested). */
    public function register_rest_routes(): void {
        $args = [
            'permission_callback' => [$this, 'check_token'],
        ];
        $base = '/' . self::REST_BASE;

        register_rest_route(self::REST_NS, $base . '/query', array_merge($args, [
            'methods'  => 'POST',
            'callback' => [$this, 'handle_query'],
        ]));

        // GET is what MxChat sends (repeated ids=, see fetch_ids()); POST
        // with a JSON body stays for older callers.
        register_rest_route(self::REST_NS, $base . '/vectors/fetch', array_merge($args, [
            'methods'  => ['GET', 'POST'],
            'callback' => [$this, 'handle_fetch'],
        ]));

        register_rest_route(self::REST_NS, $base . '/vectors/delete', array_merge($args, [
            'methods'  => 'POST',
            'callback' => [$this, 'handle_delete'],
        ]));

        register_rest_route(self::REST_NS, $base . '/vectors/list', array_merge($args, [
            'methods'  => ['POST', 'GET'],
            'callback' => [$this, 'handle_list'],
        ]));

        register_rest_route(self::REST_NS, $base . '/vectors/upsert', array_merge($args, [
            'methods'  => 'POST',
            'callback' => [$this, 'handle_upsert'],
        ]));

        // MxChat POSTs '{}' (MxChat_Pinecone_Manager::mxchat_get_pinecone_total_count,
        // MxChat 3.2.25), so the namespace comes from the token, not the body.
        register_rest_route(self::REST_NS, $base . '/describe_index_stats', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'handle_describe_index_stats'],
            'permission_callback' => [$this, 'check_token_any_namespace'],
        ]);

        // Document-index protocol (MxChat_Pinecone_Documents::data_url(),
        // MxChat 3.2.25): refused explicitly rather than by a bare 404.
        register_rest_route(self::REST_NS, $base . '/(?P<mxchat_plus_document_path>(?:namespaces|documents)(?:/.*)?)', [
            'methods'             => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
            'callback'            => [$this, 'handle_unsupported_document_protocol'],
            'permission_callback' => [$this, 'check_token_any_namespace'],
        ]);
    }

    /**
     * Hooks on MxChat's side of the loopback: they run in the request that
     * CALLS the proxy (a chat answer, an admin screen, WP-CLI), not in the
     * proxy's own REST request. Registered from register_routes() so that
     * boot keeps a single entry point for the proxy.
     */
    private function register_loopback_hooks(): void {
        if ($this->hooks_registered) return;
        $this->hooks_registered = true;

        // Late, after every integration: the text the host will embed is
        // what BM25 must search. The early twin records the original
        // message, which is what the host falls back to when a filter
        // returns something unusable.
        add_filter('mxchat_retrieval_query', [$this, 'capture_original_retrieval_query'], PHP_INT_MIN, 3);
        add_filter('mxchat_retrieval_query', [$this, 'capture_retrieval_query'], PHP_INT_MAX, 3);

        // Observed only, to learn which bot the next /query belongs to; the
        // body itself is changed on http_request_args, where the target URL
        // is known — this filter is not told which host it is for.
        add_filter('mxchat_pinecone_query_body', [$this, 'note_query_body'], PHP_INT_MAX, 2);

        add_filter('http_request_args', [$this, 'sign_loopback_request'], 99, 2);

        // Runs first so an integrator's own callback at the default
        // priority still sees (and may replace) the visitor's text.
        add_filter('mxchat_plus_duckdb_query_text', [$this, 'default_query_text'], PHP_INT_MIN, 3);
        add_filter('mxchat_plus_duckdb_bot_config', [$this, 'follow_host_hybrid_toggle'], PHP_INT_MIN, 2);

        // The query cache key does not carry the hybrid state, so a site
        // following the host switch would keep serving pure-vector results
        // for query_cache_ttl after flipping it.
        add_action('update_option_' . self::HOST_HYBRID_TOGGLE_OPTION, [$this, 'flush_cache_on_host_hybrid_toggle'], 10, 0);
        add_action('add_option_' . self::HOST_HYBRID_TOGGLE_OPTION, [$this, 'flush_cache_on_host_hybrid_toggle'], 10, 0);
    }

    /** Clear the per-request state (the singleton outlives a test case). */
    public function reset_state(): void {
        $this->retrieval_queries  = [];
        $this->original_queries   = [];
        $this->pending_query_bot  = null;
        $this->request_bot        = null;
        $this->request_query_text = '';
    }

    // ─── Authentication + rate limit ────────────────────────────────────

    /**
     * Permission callback of the namespaced routes: the token must authorise
     * the namespace the request targets.
     *
     * @return bool|WP_Error true to allow, false → 401 (auth), WP_Error 429 (throttled).
     */
    public function check_token(WP_REST_Request $req) {
        return $this->authorize($req, true);
    }

    /**
     * Permission callback of the routes that carry no namespace
     * (describe_index_stats, whose body MxChat sends as '{}'): any valid
     * token passes, and the handler scopes its answer to the namespace the
     * token authorises. Enforcing the body namespace there would reject
     * every Multi-Bot bot's own token against the 'default' fallback.
     *
     * @return bool|WP_Error
     */
    public function check_token_any_namespace(WP_REST_Request $req) {
        return $this->authorize($req, false);
    }

    /**
     * @return bool|WP_Error
     */
    private function authorize(WP_REST_Request $req, bool $enforce_namespace) {
        $provided = $req->get_header('api-key');
        if (!is_string($provided) || $provided === '') return false;

        // Per-namespace tokens take precedence; the global token is the fallback.
        // We compare against every known token in constant time so a caller can
        // present any valid token, and the resolved namespace is stashed on the
        // request for downstream handlers to enforce.
        $map = (array) get_option(self::PROXY_TOKEN_MAP_OPTION, []);
        $matched_ns = null;
        foreach ($map as $ns => $tok) {
            if (is_string($tok) && $tok !== '' && hash_equals($tok, $provided)) {
                $matched_ns = (string) $ns;
                break;
            }
        }
        if ($matched_ns === null) {
            $global = (string) get_option(self::PROXY_TOKEN_OPTION, '');
            if ($global === '' || !hash_equals($global, $provided)) {
                return false;
            }
            $matched_ns = '*'; // wildcard: legacy token may access any namespace
        }

        if ($enforce_namespace && $matched_ns !== '*' && $matched_ns !== self::namespace_from_request($req)) {
            // Caller's token doesn't authorise the namespace they're targeting.
            return false;
        }

        $req->set_param(self::AUTH_NS_PARAM, $matched_ns);

        // Per-namespace bucket: a misbehaving bot can't starve the others.
        // Wildcard (legacy) tokens fall back to the global bucket so a leaked
        // legacy key is still rate-limited in aggregate.
        $bucket_ns = $matched_ns === '*' ? '_global' : $matched_ns;

        if ($this->is_signed_loopback($req, $provided)) {
            // MxChat's own call. Bulk admin flows run hundreds of list/fetch/
            // delete calls a minute (REST DELETE /knowledge takes 50 URLs at
            // 3 calls each; kb-reimport pages in a tight loop) and must not
            // fail with 429, nor eat the bucket visitors' chats draw from.
            // /query alone keeps a CPU guard, in a bucket of its own.
            if (self::route_path($req) === '/query' && !$this->within_rate_limit($bucket_ns, true)) {
                return self::rate_limited_error();
            }
            return true;
        }

        if (!$this->within_rate_limit($bucket_ns)) {
            // Distinguish throttling (429) from auth failure (the other return
            // false paths → 401). Returning bare false here would have made a
            // throttled client look like it presented a bad token.
            return self::rate_limited_error();
        }
        return true;
    }

    private static function rate_limited_error(): WP_Error {
        return new WP_Error(
            'mxchat_plus_duckdb_rate_limited',
            __('Rate limit exceeded for this namespace. Try again shortly.', 'mxchat-plus'),
            ['status' => 429]
        );
    }

    /**
     * Namespace a request targets: JSON body first, then the query string
     * (GET /vectors/list and /vectors/fetch carry it there), else 'default'.
     * The handlers resolve it the same way, so the namespace the token was
     * checked against is the one they act on.
     */
    private static function namespace_from_request(WP_REST_Request $req): string {
        $body = $req->get_json_params();
        if (is_array($body) && isset($body['namespace']) && is_scalar($body['namespace']) && (string) $body['namespace'] !== '') {
            return (string) $body['namespace'];
        }
        $qp = $req->get_query_params();
        if (isset($qp['namespace']) && is_scalar($qp['namespace']) && (string) $qp['namespace'] !== '') {
            return (string) $qp['namespace'];
        }
        return 'default';
    }

    /**
     * Token-bucket-lite: count requests in a 1-minute window via a single
     * transient per namespace. Defaults: 120 req/min per namespace. Override
     * with the filter below. Search queries are heavy (vector math + HNSW);
     * a misbehaving client could otherwise saturate CPU.
     *
     * Namespaced so a runaway bot doesn't starve the others. The filter
     * receives the namespace so per-tenant ceilings are configurable.
     *
     * $internal selects the separate bucket of MxChat's own signed /query
     * loopbacks (`mxchat_plus_duckdb_proxy_internal_rate_limit_per_minute`,
     * default 600; 0 disables it).
     */
    private function within_rate_limit(string $namespace = '_global', bool $internal = false): bool {
        $max = $internal
            ? (int) apply_filters('mxchat_plus_duckdb_proxy_internal_rate_limit_per_minute', self::INTERNAL_QUERY_RATE_LIMIT_DEFAULT, $namespace)
            : (int) apply_filters('mxchat_plus_duckdb_proxy_rate_limit_per_minute', self::RATE_LIMIT_DEFAULT, $namespace);
        if ($max <= 0) return true;

        // Keep the key short and ASCII-safe: hash unusual namespace names.
        $ns_key = preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $namespace) ? $namespace : substr(md5($namespace), 0, 12);
        // Fixed 1-minute window. (A burst can still hit up to 2x the cap across
        // a minute boundary — acceptable for a soft CPU guard; a sliding window
        // isn't worth the extra state here.)
        $key = 'mxchat_plus_duckdb_rl_' . ($internal ? 'i_' : '') . $ns_key . '_' . gmdate('YmdHi');

        // Atomic path: with a persistent object cache (Redis/Memcached),
        // wp_cache_incr() is atomic, so concurrent requests can't all read the
        // same count and each write count+1 — the failure mode of the transient
        // get→set below, which lets a concurrent burst sail past the cap (the
        // one CPU guard on the public Option B endpoint). `add` seeds the window
        // with a TTL exactly once; `incr` preserves it (Redis/Memcached don't
        // reset TTL on INCR).
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            $group = 'mxchat_plus_duckdb_rl';
            wp_cache_add($key, 0, $group, 70);
            $count = wp_cache_incr($key, 1, $group);
            // Fail open on a cache hiccup: don't lock out legitimate traffic.
            if ($count === false) return true;
            return (int) $count <= $max;
        }

        // Fallback: transient (DB-backed or non-persistent cache). Still a
        // read-modify-write, so it can soft-over-admit under heavy concurrency,
        // but bounded and acceptable for a soft cap on installs without a
        // persistent object cache.
        $count = (int) get_transient($key);
        if ($count >= $max) {
            return false;
        }
        // Window of 70s so the bucket survives clock skew across the minute boundary.
        set_transient($key, $count + 1, 70);
        return true;
    }

    // ─── Loopback signature (MxChat's own calls) ────────────────────────

    /**
     * `http_request_args` handler. For a request to THIS proxy only:
     *   - adds the loopback signature header (rate-limit exemption);
     *   - on POST /query, adds the visitor's retrieval text to the body.
     * Every other URL — real Pinecone included — is returned untouched, so
     * the visitor's text never leaves the site.
     *
     * @param mixed $args
     * @param mixed $url
     * @return mixed
     */
    public function sign_loopback_request($args, $url) {
        if (!is_array($args) || !is_string($url)) return $args;
        $path = self::proxy_path_from_url($url);
        if ($path === null) return $args;

        $method = strtoupper(is_string($args['method'] ?? null) ? $args['method'] : 'GET');
        if ($path === '/query' && $method === 'POST') {
            $args = $this->inject_query_text($args);
        }

        // WordPress accepts a raw header string here and only parses it
        // after this filter; MxChat always passes an array. Anything else
        // stays unsigned (and so rate-limited like an external caller).
        if (isset($args['headers']) && !is_array($args['headers'])) return $args;
        $headers = $args['headers'] ?? [];
        $secret = self::loopback_secret();
        if ($secret === '') return $args;

        $headers[self::INTERNAL_HEADER] = self::loopback_signature($method, $path, self::loopback_window(), self::header_value($headers, 'api-key'), $secret);
        $args['headers'] = $headers;
        return $args;
    }

    /**
     * Path of a URL under this proxy ('/query', '/vectors/fetch', …), or
     * null when the URL targets anything else. MxChat builds
     * 'https://' . <host> . '/query' from the host we hand it
     * (pinecone_host()), so a prefix match is exact.
     */
    public static function proxy_path_from_url(string $url): ?string {
        if (strpos($url, self::REST_BASE) === false) return null; // cheap pre-check: runs on every HTTP call
        try {
            // rest_url() needs $wp_rewrite, which does not exist yet during
            // plugins_loaded; an HTTP call that early is simply not ours.
            $host = self::pinecone_host();
        } catch (\Throwable $e) {
            return null;
        }
        foreach (['https://', 'http://'] as $scheme) {
            $prefix = $scheme . $host;
            if (strncasecmp($url, $prefix, strlen($prefix)) !== 0) continue;
            $rest = (string) substr($url, strlen($prefix));
            if ($rest === '' || $rest[0] !== '/') return null;
            $q = strpos($rest, '?');
            return $q === false ? $rest : (string) substr($rest, 0, $q);
        }
        return null;
    }

    /** Does this URL target the proxy? (For callers deciding whether a host is ours.) */
    public static function is_proxy_url(string $url): bool {
        return self::proxy_path_from_url($url) !== null;
    }

    /**
     * Signature of one loopback call: bound to the method, the proxy path,
     * the minute and the token, keyed by a secret derived from the site's
     * salts (wp-config.php), which a database leak of the token alone does
     * not reveal.
     */
    public static function loopback_signature(string $method, string $path, int $window, string $api_key, string $secret = ''): string {
        if ($secret === '') $secret = self::loopback_secret();
        if ($secret === '') return '';
        return hash_hmac('sha256', strtoupper($method) . '|' . $path . '|' . $window . '|' . hash('sha256', $api_key), $secret);
    }

    /** Current signature window (one per minute). */
    public static function loopback_window(): int {
        return intdiv(time(), 60);
    }

    /**
     * '' when wp_salt() is unavailable: signing is then off and every
     * caller is rate-limited, which fails safe.
     */
    private static function loopback_secret(): string {
        if (!function_exists('wp_salt')) return '';
        $salt = (string) wp_salt('auth');
        return $salt === '' ? '' : hash_hmac('sha256', 'mxchat_plus_duckdb_proxy_loopback', $salt);
    }

    /**
     * Accepts the current and the previous minute, so a call signed at
     * hh:mm:59.9 and checked at hh:mm+1:00.1 still passes.
     */
    private function is_signed_loopback(WP_REST_Request $req, string $api_key): bool {
        $sig = $req->get_header(strtolower(self::INTERNAL_HEADER));
        if (!is_string($sig) || $sig === '') return false;
        $secret = self::loopback_secret();
        if ($secret === '') return false;
        $path = self::route_path($req);
        if ($path === null) return false;
        $method = strtoupper((string) $req->get_method());
        $now = self::loopback_window();
        foreach ([$now, $now - 1] as $window) {
            if (hash_equals(self::loopback_signature($method, $path, $window, $api_key, $secret), $sig)) {
                return true;
            }
        }
        return false;
    }

    /** Route of the request relative to the proxy base ('/query', …), or null. */
    private static function route_path(WP_REST_Request $req): ?string {
        $route = (string) $req->get_route();
        $prefix = '/' . self::REST_NS . '/' . self::REST_BASE;
        if (strpos($route, $prefix . '/') !== 0) return null;
        return (string) substr($route, strlen($prefix));
    }

    /**
     * @param array<mixed> $headers
     */
    private static function header_value(array $headers, string $name): string {
        foreach ($headers as $k => $v) {
            if (is_string($k) && strcasecmp($k, $name) === 0 && is_scalar($v)) {
                return (string) $v;
            }
        }
        return '';
    }

    // ─── Retrieval query text (hybrid BM25 over the loopback) ───────────

    /**
     * Host filter `mxchat_retrieval_query` (MxChat_Integrator, MxChat 3.2.25)
     * at PHP_INT_MIN: remembers the visitor's original message. Returns it
     * unchanged.
     *
     * @param mixed $query
     * @param mixed $session_id
     * @param mixed $bot_id
     * @return mixed
     */
    public function capture_original_retrieval_query($query, $session_id = '', $bot_id = 'default') {
        if (is_string($query) && trim($query) !== '') {
            $this->original_queries[self::bot_key($bot_id)] = $query;
        }
        return $query;
    }

    /**
     * Same filter at PHP_INT_MAX: remembers the text the host will embed.
     * The host falls back to the original message when the filtered value
     * is not a non-empty string, and so does this. Returns it unchanged.
     *
     * @param mixed $query
     * @param mixed $session_id
     * @param mixed $bot_id
     * @return mixed
     */
    public function capture_retrieval_query($query, $session_id = '', $bot_id = 'default') {
        $bot = self::bot_key($bot_id);
        $text = (is_string($query) && trim($query) !== '') ? $query : ($this->original_queries[$bot] ?? '');
        $text = self::clip_query_text($text);
        if ($text !== '') {
            $this->retrieval_queries[$bot] = $text;
        }
        return $query;
    }

    /**
     * Host filter `mxchat_pinecone_query_body` (MxChat_Integrator, MxChat
     * 3.2.25): fired right before the classic /query is POSTed. Notes the
     * bot and returns the body unchanged — adding the text here would also
     * send it to a real Pinecone index whenever the bot is not routed to us.
     *
     * @param mixed $body
     * @param mixed $bot_id
     * @return mixed
     */
    public function note_query_body($body, $bot_id = 'default') {
        $this->pending_query_bot = self::bot_key($bot_id);
        return $body;
    }

    /**
     * @param array<mixed> $args
     * @return array<mixed>
     */
    private function inject_query_text(array $args): array {
        $pending = $this->pending_query_bot;
        $this->pending_query_bot = null;
        if ($this->retrieval_queries === [] || !isset($args['body']) || !is_string($args['body'])) return $args;

        $body = json_decode($args['body'], true);
        if (!is_array($body) || !isset($body['vector']) || array_key_exists(self::QUERY_TEXT_KEY, $body)) return $args;

        $namespace = isset($body['namespace']) && is_scalar($body['namespace']) ? (string) $body['namespace'] : '';
        $text = '';
        foreach ([$pending, $namespace !== '' ? $namespace : 'default'] as $bot) {
            if ($bot !== null && isset($this->retrieval_queries[$bot])) {
                $text = $this->retrieval_queries[$bot];
                break;
            }
        }
        if ($text === '') return $args;

        $body[self::QUERY_TEXT_KEY] = $text;
        $encoded = wp_json_encode($body);
        if (is_string($encoded)) {
            $args['body'] = $encoded;
        }
        return $args;
    }

    /**
     * `mxchat_plus_duckdb_query_text` at PHP_INT_MIN: inside handle_query(),
     * the default value becomes the text the /query body carried, for that
     * query's bot only. Later callbacks may still replace it.
     *
     * @param mixed $value
     * @param mixed $bot_id
     * @param mixed $filter
     * @return mixed
     */
    public function default_query_text($value, $bot_id = 'default', $filter = []) {
        if (is_string($value) && $value !== '') return $value;
        if ($this->request_bot === null || $this->request_query_text === '') return $value;
        if (self::bot_key($bot_id) !== $this->request_bot) return $value;
        return $this->request_query_text;
    }

    /**
     * `mxchat_plus_duckdb_bot_config` at PHP_INT_MIN, inside handle_query()
     * only: turns our BM25 leg on when MxChat's own "Hybrid keyword boost"
     * is on — but only for a site that opted in through
     * `mxchat_plus_duckdb_proxy_follow_host_hybrid_toggle` (default false).
     * Off by default because the host's switch documents itself as having
     * no effect on a classic Pinecone index; our own hybrid_enabled stays
     * the primary switch.
     *
     * @param mixed $config
     * @param mixed $bot_id
     * @return mixed
     */
    public function follow_host_hybrid_toggle($config, $bot_id = 'default') {
        if (!is_array($config) || $this->request_bot === null || !empty($config['hybrid_enabled'])) return $config;
        if (!self::follows_host_hybrid_toggle(self::bot_key($bot_id))) return $config;
        if (get_option(self::HOST_HYBRID_TOGGLE_OPTION, 'off') !== 'on') return $config;
        $config['hybrid_enabled'] = true;
        return $config;
    }

    private static function follows_host_hybrid_toggle(string $bot_id): bool {
        return (bool) apply_filters('mxchat_plus_duckdb_proxy_follow_host_hybrid_toggle', false, $bot_id);
    }

    /** update_option / add_option of the host toggle: drop every cached top-K. */
    public function flush_cache_on_host_hybrid_toggle(): void {
        if (class_exists('MxChat_Plus_DuckDB_Cache')) {
            MxChat_Plus_DuckDB_Cache::flush_query_cache();
        }
    }

    private static function clip_query_text(string $text): string {
        $text = trim($text);
        if ($text === '') return '';
        return function_exists('mb_substr')
            ? (string) mb_substr($text, 0, self::QUERY_TEXT_MAX_CHARS, 'UTF-8')
            : (string) substr($text, 0, self::QUERY_TEXT_MAX_CHARS);
    }

    /** @param mixed $bot_id */
    private static function bot_key($bot_id): string {
        $b = is_scalar($bot_id) ? (string) $bot_id : '';
        return $b !== '' ? $b : 'default';
    }

    // ─── Handlers ───────────────────────────────────────────────────────

    public function handle_query(WP_REST_Request $req): WP_REST_Response {
        $body = self::json_body($req);
        $vector = isset($body['vector']) && is_array($body['vector']) ? $body['vector'] : [];
        // Pinecone's own bounds: 1 ≤ topK ≤ 10000. Also guards LIMIT 0 or a
        // negative LIMIT; Vector_Store_Query::run() clamps again.
        $top_k = isset($body['topK']) && is_numeric($body['topK']) ? (int) $body['topK'] : self::DEFAULT_TOP_K;
        $top_k = max(1, min(MxChat_Plus_DuckDB_Vector_Store_Query::MAX_TOP_K, $top_k));
        $namespace = self::namespace_from_request($req);

        if (isset($body['filter']) && !is_array($body['filter'])) {
            return new WP_REST_Response([
                'matches'   => [],
                'namespace' => $namespace,
                'error'     => 'filter must be an object',
            ], 400);
        }
        $filter = isset($body['filter']) && is_array($body['filter']) ? $body['filter'] : [];

        if (empty($vector)) {
            return new WP_REST_Response([
                'matches'   => [],
                'namespace' => $namespace,
                'error'     => 'missing vector',
            ], 400);
        }

        // Validate the vector dimension up-front so callers get a 400 with a
        // clear message instead of a 500 from deep inside the SQL layer.
        $opts = MxChat_Plus_DuckDB_Options::get();
        $expected_dim = (int) ($opts['embedding_dim'] ?? 0);
        if ($expected_dim > 0 && count($vector) !== $expected_dim) {
            return new WP_REST_Response([
                'matches'   => [],
                'namespace' => $namespace,
                'error'     => sprintf('vector dimension mismatch: expected %d, got %d', $expected_dim, count($vector)),
            ], 400);
        }

        $text = isset($body[self::QUERY_TEXT_KEY]) && is_string($body[self::QUERY_TEXT_KEY])
            ? self::clip_query_text($body[self::QUERY_TEXT_KEY])
            : '';

        // Request-scoped: read by default_query_text() and
        // follow_host_hybrid_toggle() while the store runs this query.
        $this->request_bot = $namespace;
        $this->request_query_text = $text;
        try {
            // `score` is the raw cosine similarity whatever the distance
            // metric and the hybrid settings (Vector_Store_Query): MxChat
            // compares it with its similarity threshold and the YouTube-card
            // floor. Hybrid only selects and orders the top-K, and MxChat
            // re-sorts by score anyway. Roles are resolved after the query
            // cache, and 'public' is never claimed (see wire_metadata()).
            $matches = MxChat_Plus_DuckDB_Vector_Store::current()
                ->query_pinecone_shape($vector, $top_k, $namespace, $filter);
            foreach ($matches as $i => $m) {
                if (is_array($m) && isset($m['metadata']) && is_array($m['metadata'])) {
                    $matches[$i]['metadata'] = self::wire_metadata($m['metadata']);
                }
            }
            // Values are not returned even though MxChat sends
            // includeValues: true: it never reads them from a match.
            return new WP_REST_Response([
                'matches'   => array_values($matches),
                'namespace' => $namespace,
            ], 200);
        } catch (\InvalidArgumentException $e) {
            // Malformed filter or unsupported operator: Pinecone answers 400.
            return new WP_REST_Response([
                'matches'   => [],
                'namespace' => $namespace,
                'error'     => $e->getMessage(),
            ], 400);
        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'matches'   => [],
                'namespace' => $namespace,
                'error'     => $e->getMessage(),
            ], 500);
        } finally {
            $this->request_bot = null;
            $this->request_query_text = '';
        }
    }

    /**
     * GET /vectors/fetch?ids=a&ids=b&namespace=… (MxChat 3.2.20+: chunk
     * reassembly in MxChat_Integrator, View/Inspect entry and the KB
     * listing in MxChat_Knowledge_Manager / MxChat_Pinecone_Manager,
     * kb-reimport through MxChat_Pinecone_Documents::classic_fetch()), or
     * POST with {ids, namespace}. Answers the Pinecone shape
     * {vectors: {<id>: {id, values, metadata}}, namespace, usage}.
     */
    public function handle_fetch(WP_REST_Request $req): WP_REST_Response {
        $ids = self::fetch_ids($req);
        $namespace = self::namespace_from_request($req);

        if (empty($ids)) {
            return new WP_REST_Response(['vectors' => (object) [], 'namespace' => $namespace, 'usage' => ['readUnits' => 1]], 200);
        }

        // Real Pinecone returns the values with every fetched vector; no
        // MxChat 3.2.25 caller of this proxy reads them, so a site can drop
        // them to shrink the payload.
        $with_values = (bool) apply_filters('mxchat_plus_duckdb_proxy_fetch_include_values', true, $namespace);

        try {
            $store = MxChat_Plus_DuckDB_Vector_Store::current();
            // fetch_by_ids() selects the schema-v4 metadata columns (title,
            // timestamps, extra_metadata), and nothing migrates the table
            // when the plugin is updated: WordPress never re-runs activation.
            // On a pure-proxy site the first call after an update can be a
            // fetch (kb-reimport through MxChat_Pinecone_Documents::classic_fetch(),
            // View / Inspect through MxChat_Knowledge_Manager::get_pinecone_entry_content()
            // and inspect_pinecone_entry(), MxChat 3.2.25), and a v3 table
            // would answer it with a binder error, so a 500, until a /query
            // or an upsert happened to run the rebuild. Memoised per
            // request, as in handle_upsert(). list, delete and
            // describe_index_stats read only vector_id and bot_id, which v3
            // already has, so they skip it.
            $store->ensure_schema();
            $vectors = $store->fetch_by_ids($ids, $namespace, $with_values);
            foreach ($vectors as $id => $v) {
                if (is_array($v) && isset($v['metadata']) && is_array($v['metadata'])) {
                    $vectors[$id]['metadata'] = self::wire_metadata($v['metadata']);
                }
            }
            // An object even when the ids look numeric: a PHP list would
            // encode as a JSON array, and MxChat looks vectors up by id.
            return new WP_REST_Response([
                'vectors'   => (object) $vectors,
                'namespace' => $namespace,
                'usage'     => ['readUnits' => 1],
            ], 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response(['vectors' => (object) [], 'namespace' => $namespace, 'error' => $e->getMessage()], 500);
        }
    }

    public function handle_delete(WP_REST_Request $req): WP_REST_Response {
        $body = self::json_body($req);
        $ids = isset($body['ids']) && is_array($body['ids']) ? self::clean_ids($body['ids']) : [];
        $namespace = self::namespace_from_request($req);

        if (empty($ids)) {
            // MxChat 3.2.25 always deletes by ids (a source_url filter may
            // ride along with them and is ignored). deleteAll and a
            // filter-only delete are refused rather than answered 200 with
            // nothing deleted.
            if (!empty($body['deleteAll']) || !empty($body['filter'])) {
                return new WP_REST_Response([
                    'error' => ['code' => 'INVALID_ARGUMENT', 'message' => 'The MxChat Plus DuckDB proxy deletes by ids only.'],
                    'status' => 400,
                ], 400);
            }
            return new WP_REST_Response((object) [], 200);
        }

        try {
            MxChat_Plus_DuckDB_Vector_Store::current()
                ->delete_by_ids($ids, $namespace);
            return new WP_REST_Response((object) [], 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Pinecone's /vectors/list is a GET with query parameters, which is how
     * MxChat 3.2.25 sends it everywhere (MxChat_Utils::pinecone_list_ids_by_prefix()
     * and the straggler sweep, MxChat_Integrator::reassemble_chunks_from_pinecone(),
     * MxChat_Pinecone_Manager::mxchat_list_pinecone_records(),
     * MxChat_Pinecone_Documents::classic_list_page()). A JSON body is still
     * read, and wins over the query string.
     *
     * Keyset pagination: `paginationToken` in, `pagination.next` out only
     * when more ids exist. The host's paging loops are `do … while (true)`
     * and stop on an empty next, so a token must never be echoed back
     * without being honoured.
     */
    public function handle_list(WP_REST_Request $req): WP_REST_Response {
        $params = array_merge($req->get_query_params(), self::json_body($req));
        $namespace = self::namespace_from_request($req);
        $limit = isset($params['limit']) && is_numeric($params['limit'])
            ? max(1, min(MxChat_Plus_DuckDB_Vector_Store::LIST_PAGE_MAX, (int) $params['limit']))
            : self::DEFAULT_LIST_LIMIT;
        // `prefix` is how mxchat finds all chunk vectors for a URL — vector
        // IDs are formatted `{md5(source_url)}_chunk_{index}`, so the prefix
        // it sends ends with `_chunk_`. Without this filter we'd return
        // unrelated IDs and the upstream `reassemble_chunks_from_pinecone`
        // would interleave junk into the RAG context.
        $prefix = isset($params['prefix']) && is_scalar($params['prefix']) ? (string) $params['prefix'] : null;
        if ($prefix === '') $prefix = null;
        $token = '';
        foreach (['paginationToken', 'pagination_token'] as $k) {
            if (isset($params[$k]) && is_scalar($params[$k]) && (string) $params[$k] !== '') {
                $token = (string) $params[$k];
                break;
            }
        }

        try {
            $page = MxChat_Plus_DuckDB_Vector_Store::current()
                ->list_ids_page($namespace, $limit, $token, $prefix);
            return new WP_REST_Response([
                'vectors'    => array_map(fn($id) => ['id' => $id], $page['ids']),
                'pagination' => $page['next'] !== '' ? ['next' => $page['next']] : (object) [],
                'namespace'  => $namespace,
                'usage'      => ['readUnits' => 1],
            ], 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response(['vectors' => [], 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Pinecone /vectors/upsert wire format:
     *   { "vectors": [{ "id":"…", "values":[…], "metadata":{…} }, …], "namespace":"…" }
     *
     * Each vector becomes a DuckDB row through
     * Vector_Store::row_from_pinecone(), the one Pinecone-metadata-to-row
     * mapping (shared with the migrator): title, parent_url_hash,
     * created_at / last_updated (the host's own time() ints) and add-on
     * keys round-trip, so kb-reimport's "edited after import" check and the
     * listing dates work. The row's bot is the namespace the token was
     * checked against, never metadata.bot_id, so a token cannot write into
     * another bot.
     *
     * The AI-Engine-style aliases (source / part_index / part_total) are
     * built and passed through `mxchat_embedding_chunk_metadata` by
     * MxChat_Utils::submit_chunked_content(), but
     * MxChat_Utils::store_chunk_in_pinecone() never writes them to Pinecone
     * metadata (MxChat 3.2.25), so the alias fallback in
     * row_from_pinecone() is purely defensive.
     */
    public function handle_upsert(WP_REST_Request $req): WP_REST_Response {
        $body = self::json_body($req);
        $vectors_in = isset($body['vectors']) && is_array($body['vectors']) ? $body['vectors'] : [];
        $bot_id = self::namespace_from_request($req);

        if (empty($vectors_in)) {
            return new WP_REST_Response(['upsertedCount' => 0], 200);
        }

        $rows = [];
        foreach ($vectors_in as $v) {
            if (!is_array($v)) continue;
            $id = isset($v['id']) && is_scalar($v['id']) ? (string) $v['id'] : '';
            $values = isset($v['values']) && is_array($v['values']) ? $v['values'] : [];
            $meta = isset($v['metadata']) && is_array($v['metadata']) ? $v['metadata'] : [];
            if ($id === '' || empty($values)) continue;
            $rows[] = MxChat_Plus_DuckDB_Vector_Store::row_from_pinecone($id, $values, $meta, $bot_id);
        }

        if (empty($rows)) {
            return new WP_REST_Response(['upsertedCount' => 0], 200);
        }

        try {
            $store = MxChat_Plus_DuckDB_Vector_Store::current();
            $store->ensure_schema();
            $count = $store->upsert($rows);
        } catch (\Throwable $e) {
            MxChat_Plus_DuckDB_Options::update(['last_error' => 'upsert: ' . $e->getMessage()]);
            return new WP_REST_Response(['upsertedCount' => 0, 'error' => $e->getMessage()], 500);
        }

        if ($count > 0) {
            // The host writes this bot through the proxy (takeover, Multi-Bot,
            // media auto-sync, REST knowledge, kb-reimport): its rows are not
            // in the MySQL KB, so the compactor must never prune them as
            // orphans. One durable option write per bot, ever.
            try {
                if (class_exists('MxChat_Plus_DuckDB_Compactor')) {
                    MxChat_Plus_DuckDB_Compactor::note_proxy_write($bot_id);
                }
            } catch (\Throwable $e) {
                error_log('[mxchat-plus] proxy upsert: could not record the proxy-written bot: ' . $e->getMessage());
            }
        }
        return new WP_REST_Response(['upsertedCount' => $count], 200);
    }

    /**
     * Pinecone describe_index_stats: {namespaces: {<ns>: {vectorCount}},
     * dimension, indexFullness, totalVectorCount}. MxChat reads
     * namespaces[<its namespace>].vectorCount (or totalVectorCount without
     * one) to decide whether the Knowledge screen pages by cursor
     * (MxChat_Pinecone_Manager, MxChat 3.2.22+). Namespaces with no vectors
     * are omitted, as Pinecone does; the host reads a missing one as 0.
     *
     * Scoped to the token: a per-namespace token sees only its own
     * namespace (and a total equal to it); only the legacy global token
     * sees every bot.
     */
    public function handle_describe_index_stats(WP_REST_Request $req): WP_REST_Response {
        $auth_ns = $req->get_param(self::AUTH_NS_PARAM);
        if (!is_string($auth_ns) || $auth_ns === '') {
            // Only reachable when the permission callback did not run.
            return new WP_REST_Response(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'missing token scope'], 'status' => 401], 401);
        }

        try {
            $store = MxChat_Plus_DuckDB_Vector_Store::current();
            if ($auth_ns === '*') {
                $counts = $store->count_by_bot();
            } else {
                $n = $store->count($auth_ns);
                $counts = $n > 0 ? [$auth_ns => $n] : [];
            }
            $namespaces = [];
            foreach ($counts as $ns => $n) {
                $namespaces[(string) $ns] = ['vectorCount' => (int) $n];
            }
            return new WP_REST_Response([
                // An object even for an all-digit bot id (see handle_fetch()).
                'namespaces'       => (object) $namespaces,
                'dimension'        => $store->dimension(),
                'indexFullness'    => 0,
                'totalVectorCount' => (int) array_sum($counts),
                // matches[].score is always the cosine similarity.
                'metric'           => 'cosine',
                'vectorType'       => 'dense',
            ], 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response(['namespaces' => (object) [], 'totalVectorCount' => 0, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * /namespaces/{ns}/documents/… — the Pinecone document-index protocol
     * MxChat 3.2.22+ speaks to a document index (MxChat_Pinecone_Documents).
     * Not emulated: answered with a Pinecone-style error whose message
     * MxChat_Pinecone_Documents::request() surfaces as is, instead of a
     * bare 404 that some callers would read as an empty index.
     */
    public function handle_unsupported_document_protocol(WP_REST_Request $req): WP_REST_Response {
        $message = __('The MxChat Plus DuckDB proxy only speaks the Pinecone vector-index protocol; document-index requests are not supported. Set the Pinecone index type to "vector" for this bot.', 'mxchat-plus');
        return new WP_REST_Response([
            'error'  => ['code' => 'UNIMPLEMENTED', 'message' => $message],
            'status' => 501,
        ], 501);
    }

    // ─── Request parsing ────────────────────────────────────────────────

    /** @return array<mixed> */
    private static function json_body(WP_REST_Request $req): array {
        $body = $req->get_json_params();
        return is_array($body) ? $body : [];
    }

    /**
     * Ids of a fetch, in request order, deduplicated:
     *   - POST: the JSON body's `ids` array;
     *   - GET `ids[0]=a&ids[1]=b` (http_build_query, sent by
     *     MxChat_Pinecone_Manager's delete check): PHP already parsed it
     *     into an array;
     *   - GET `ids=a&ids=b` (every other host caller): PHP's $_GET — and so
     *     get_query_params() — keeps only the LAST `ids`, so the others are
     *     recovered from the raw query string. Only when that string is
     *     demonstrably this request's (its last id is the one PHP kept);
     *     otherwise, e.g. inside rest_do_request(), the parsed value is used.
     *
     * @return list<string>
     */
    private static function fetch_ids(WP_REST_Request $req): array {
        $body = self::json_body($req);
        if (isset($body['ids']) && is_array($body['ids'])) {
            return self::clean_ids($body['ids']);
        }

        $qp = $req->get_query_params();
        $q_ids = $qp['ids'] ?? null;
        if (is_array($q_ids)) {
            return self::clean_ids($q_ids);
        }
        if (is_scalar($q_ids) && (string) $q_ids !== '') {
            $raw = self::parse_fetch_ids(self::raw_query_string());
            if ($raw !== [] && end($raw) === (string) $q_ids) {
                return self::clean_ids($raw);
            }
            return [(string) $q_ids];
        }
        return [];
    }

    /**
     * Every `ids` value of a raw query string, in order, duplicates kept:
     * bare `ids=` and bracketed `ids[]=` / `ids[N]=` (also percent-encoded,
     * `ids%5B0%5D=`). Values are urldecoded, which reads both the host's
     * rawurlencode() ('%20') and http_build_query() ('+').
     *
     * @return list<string>
     */
    public static function parse_fetch_ids(string $raw_query): array {
        $ids = [];
        foreach (explode('&', $raw_query) as $pair) {
            if ($pair === '') continue;
            $eq = strpos($pair, '=');
            $key = urldecode($eq === false ? $pair : (string) substr($pair, 0, $eq));
            if ($key !== 'ids' && preg_match('/^ids\[\d*\]$/', $key) !== 1) continue;
            $value = $eq === false ? '' : urldecode((string) substr($pair, $eq + 1));
            if ($value !== '') $ids[] = $value;
        }
        return $ids;
    }

    private static function raw_query_string(): string {
        $qs = isset($_SERVER['QUERY_STRING']) && is_string($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
        if ($qs === '' && isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])) {
            $qs = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
        }
        return $qs;
    }

    /**
     * @param array<mixed> $ids
     * @return list<string>
     */
    private static function clean_ids(array $ids): array {
        $out = [];
        foreach ($ids as $id) {
            if (!is_scalar($id)) continue;
            $id = (string) $id;
            if ($id !== '' && !isset($out[$id])) $out[$id] = true;
        }
        return array_map('strval', array_keys($out));
    }

    /**
     * Last line of the role contract at the wire: 'public' (or an empty
     * role) is never claimed. MxChat's get_single_vector_role()
     * (MxChat_Integrator, MxChat 3.2.25) only reads its own roles table when
     * the key is absent, exactly as with a real Pinecone index.
     *
     * @param array<mixed> $meta
     * @return array<mixed>
     */
    private static function wire_metadata(array $meta): array {
        if (array_key_exists('role_restriction', $meta)) {
            $role = is_scalar($meta['role_restriction']) ? trim((string) $meta['role_restriction']) : '';
            if ($role === '' || strtolower($role) === 'public') {
                unset($meta['role_restriction']);
            }
        }
        return $meta;
    }

    // ─── Tokens + host ──────────────────────────────────────────────────

    /**
     * Lazily generate and persist the legacy global proxy auth token. Used as
     * a fallback when no per-namespace token has been issued.
     */
    public static function get_or_create_token(): string {
        $token = (string) get_option(self::PROXY_TOKEN_OPTION, '');
        if ($token === '') {
            $token = bin2hex(random_bytes(24));
            update_option(self::PROXY_TOKEN_OPTION, $token, false);
        }
        return $token;
    }

    /**
     * Return (or create on demand) a token scoped to a single namespace.
     * Used when the search adapter injects a per-bot Pinecone config so that
     * a leak on one bot doesn't grant access to others.
     */
    public static function get_or_create_token_for(string $namespace): string {
        $namespace = $namespace !== '' ? $namespace : 'default';
        $map = (array) get_option(self::PROXY_TOKEN_MAP_OPTION, []);
        if (!empty($map[$namespace]) && is_string($map[$namespace])) {
            return (string) $map[$namespace];
        }
        $map[$namespace] = bin2hex(random_bytes(24));
        update_option(self::PROXY_TOKEN_MAP_OPTION, $map, false);
        return $map[$namespace];
    }

    /**
     * Endpoint URL that mxchat will treat as the Pinecone "host".
     * mxchat builds `https://{host}/query`, so we expose a host-like fragment
     * that, when concatenated with the Pinecone-style paths, lands on our REST.
     *
     * Returns the bare host portion (no scheme, no trailing slash), e.g.
     *   example.com/wp-json/mxchat-plus/v1/pinecone-proxy
     */
    public static function pinecone_host(): string {
        $rest = rest_url(self::REST_NS . '/' . self::REST_BASE);
        // mxchat builds "https://{host}/query". rest_url() includes scheme — strip it.
        $rest = preg_replace('#^https?://#', '', $rest);
        return untrailingslashit((string) $rest);
    }
}
