<?php
/**
 * Pinecone wire-protocol emulator (Option B).
 *
 * Exposes a REST namespace under /wp-json/mxchat-plus/v1/pinecone-proxy/
 * that mxchat's existing Pinecone code path can call as if it were the real
 * Pinecone service. Implements the subset of endpoints mxchat actually uses:
 *
 *   POST /query             — top-K similarity search
 *   POST /vectors/fetch     — fetch by ID array
 *   POST /vectors/delete    — delete by ID array
 *   POST /vectors/list      — paginated ID listing
 *
 * Authentication: a shared token (one-time generated, stored in plugin
 * options) that mxchat sends as the `Api-Key` header — same convention as
 * the real Pinecone.
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

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function register_routes(): void {
        add_action('rest_api_init', function () {
            $args = [
                'permission_callback' => [$this, 'check_token'],
            ];

            register_rest_route(self::REST_NS, '/' . self::REST_BASE . '/query', array_merge($args, [
                'methods'  => 'POST',
                'callback' => [$this, 'handle_query'],
            ]));

            register_rest_route(self::REST_NS, '/' . self::REST_BASE . '/vectors/fetch', array_merge($args, [
                'methods'  => 'POST',
                'callback' => [$this, 'handle_fetch'],
            ]));

            register_rest_route(self::REST_NS, '/' . self::REST_BASE . '/vectors/delete', array_merge($args, [
                'methods'  => 'POST',
                'callback' => [$this, 'handle_delete'],
            ]));

            register_rest_route(self::REST_NS, '/' . self::REST_BASE . '/vectors/list', array_merge($args, [
                'methods'  => ['POST', 'GET'],
                'callback' => [$this, 'handle_list'],
            ]));

            register_rest_route(self::REST_NS, '/' . self::REST_BASE . '/vectors/upsert', array_merge($args, [
                'methods'  => 'POST',
                'callback' => [$this, 'handle_upsert'],
            ]));
        });
    }

    /**
     * @return bool|WP_Error true to allow, false → 401 (auth), WP_Error 429 (throttled).
     */
    public function check_token(WP_REST_Request $req) {
        $provided = $req->get_header('api-key');
        if (!is_string($provided) || $provided === '') return false;

        // Per-namespace tokens take precedence; the global token is the fallback.
        // We compare against every known token in constant time so a caller can
        // present any valid token, and the resolved namespace is stashed on the
        // request for downstream handlers to enforce.
        $requested_ns = self::namespace_from_request($req);

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

        if ($matched_ns !== '*' && $matched_ns !== $requested_ns) {
            // Caller's token doesn't authorise the namespace they're targeting.
            return false;
        }

        $req->set_param('_mxchat_plus_duckdb_auth_ns', $matched_ns);
        // Per-namespace bucket: a misbehaving bot can't starve the others.
        // Wildcard (legacy) tokens fall back to the global bucket so a leaked
        // legacy key is still rate-limited in aggregate.
        $bucket_ns = $matched_ns === '*' ? '_global' : $matched_ns;
        if (!$this->within_rate_limit($bucket_ns)) {
            // Distinguish throttling (429) from auth failure (the other return
            // false paths → 401). Returning bare false here would have made a
            // throttled client look like it presented a bad token.
            return new WP_Error(
                'mxchat_plus_duckdb_rate_limited',
                __('Rate limit exceeded for this namespace. Try again shortly.', 'mxchat-plus'),
                ['status' => 429]
            );
        }
        return true;
    }

    private static function namespace_from_request(WP_REST_Request $req): string {
        $body = $req->get_json_params();
        if (is_array($body) && !empty($body['namespace'])) return (string) $body['namespace'];
        $qp = $req->get_query_params();
        if (!empty($qp['namespace'])) return (string) $qp['namespace'];
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
     */
    private function within_rate_limit(string $namespace = '_global'): bool {
        $max = (int) apply_filters('mxchat_plus_duckdb_proxy_rate_limit_per_minute', 120, $namespace);
        if ($max <= 0) return true;

        // Keep the key short and ASCII-safe: hash unusual namespace names.
        $ns_key = preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $namespace) ? $namespace : substr(md5($namespace), 0, 12);
        // Fixed 1-minute window. (A burst can still hit up to 2x the cap across
        // a minute boundary — acceptable for a soft CPU guard; a sliding window
        // isn't worth the extra state here.)
        $key = 'mxchat_plus_duckdb_rl_' . $ns_key . '_' . gmdate('YmdHi');

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

    public function handle_query(WP_REST_Request $req) {
        $body = $req->get_json_params() ?: [];
        $vector = isset($body['vector']) && is_array($body['vector']) ? $body['vector'] : [];
        $top_k = isset($body['topK']) ? (int) $body['topK'] : 50;
        $namespace = isset($body['namespace']) ? (string) $body['namespace'] : 'default';
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

        try {
            $matches = MxChat_Plus_DuckDB_Vector_Store::current()
                ->query_pinecone_shape($vector, $top_k, $namespace ?: 'default', $filter);
            return new WP_REST_Response([
                'matches'   => $matches,
                'namespace' => $namespace,
            ], 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'matches' => [],
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function handle_fetch(WP_REST_Request $req) {
        $body = $req->get_json_params() ?: [];
        $ids = isset($body['ids']) && is_array($body['ids']) ? array_map('strval', $body['ids']) : [];
        $namespace = isset($body['namespace']) ? (string) $body['namespace'] : 'default';

        if (empty($ids)) {
            return new WP_REST_Response(['vectors' => (object) []], 200);
        }

        try {
            $vectors = MxChat_Plus_DuckDB_Vector_Store::current()
                ->fetch_by_ids($ids, $namespace ?: 'default');
            return new WP_REST_Response([
                'vectors'   => empty($vectors) ? (object) [] : $vectors,
                'namespace' => $namespace,
            ], 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response(['vectors' => (object) [], 'error' => $e->getMessage()], 500);
        }
    }

    public function handle_delete(WP_REST_Request $req) {
        $body = $req->get_json_params() ?: [];
        $ids = isset($body['ids']) && is_array($body['ids']) ? array_map('strval', $body['ids']) : [];
        $namespace = isset($body['namespace']) ? (string) $body['namespace'] : 'default';

        if (empty($ids)) {
            return new WP_REST_Response((object) [], 200);
        }

        try {
            MxChat_Plus_DuckDB_Vector_Store::current()
                ->delete_by_ids($ids, $namespace ?: 'default');
            return new WP_REST_Response((object) [], 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }

    public function handle_list(WP_REST_Request $req) {
        // Pinecone's /vectors/list is a GET with query params; mxchat-basic
        // 3.2.6 sends `prefix` + `limit` via either query string (the chunk-
        // reassembly + bulk-delete code paths) or JSON body. Try both so
        // either wire format works.
        $body = $req->get_json_params();
        if (!is_array($body) || $body === []) {
            $body = $req->get_query_params() ?: [];
        }
        $namespace = isset($body['namespace']) ? (string) $body['namespace'] : 'default';
        $limit = isset($body['limit']) ? max(1, min(1000, (int) $body['limit'])) : 100;
        // `prefix` is how mxchat finds all chunk vectors for a URL — vector
        // IDs are formatted `{md5(source_url)}_chunk_{index}`, so the prefix
        // it sends ends with `_chunk_`. Without this filter we'd return
        // unrelated IDs and the upstream `reassemble_chunks_from_pinecone`
        // would interleave junk into the RAG context.
        $prefix = isset($body['prefix']) ? (string) $body['prefix'] : null;
        if ($prefix === '') $prefix = null;

        try {
            $ids = MxChat_Plus_DuckDB_Vector_Store::current()
                ->list_ids($namespace ?: 'default', $limit, 0, $prefix);
            return new WP_REST_Response([
                'vectors' => array_map(fn($id) => ['id' => $id], $ids),
                'namespace' => $namespace,
                'pagination' => (object) [],
            ], 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response(['vectors' => [], 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Pinecone /vectors/upsert wire format:
     *   { "vectors": [{ "id":"…", "values":[…], "metadata":{…} }, …], "namespace":"…" }
     *
     * We translate each vector into a DuckDB row and INSERT OR REPLACE in one batch.
     */
    public function handle_upsert(WP_REST_Request $req) {
        $body = $req->get_json_params() ?: [];
        $vectors_in = isset($body['vectors']) && is_array($body['vectors']) ? $body['vectors'] : [];
        $namespace = isset($body['namespace']) ? (string) $body['namespace'] : 'default';
        $bot_id = $namespace !== '' ? $namespace : 'default';

        if (empty($vectors_in)) {
            return new WP_REST_Response(['upsertedCount' => 0], 200);
        }

        $rows = [];
        foreach ($vectors_in as $v) {
            $id = isset($v['id']) ? (string) $v['id'] : '';
            $values = isset($v['values']) && is_array($v['values']) ? $v['values'] : [];
            $meta = isset($v['metadata']) && is_array($v['metadata']) ? $v['metadata'] : [];
            if ($id === '' || empty($values)) continue;

            // mxchat-basic 3.2.6 added AI-Engine-style alias keys to chunk
            // metadata (`source`/`part_index`/`part_total`) alongside the
            // canonical (`source_url`/`chunk_index`/`total_chunks`). mxchat
            // itself writes both, but third-party consumers of the new
            // `mxchat_embedding_chunk_metadata` filter might write only the
            // aliases — fall back so we don't lose chunk metadata in that case.
            $rows[] = [
                'vector_id'        => $id,
                'bot_id'           => $bot_id,
                'embedding'        => $values,
                'content'          => (string) ($meta['text'] ?? ''),
                'source_url'       => (string) ($meta['source_url'] ?? ($meta['source'] ?? '')),
                'role_restriction' => (string) ($meta['role_restriction'] ?? 'public'),
                'content_type'     => (string) ($meta['type'] ?? 'content'),
                'chunk_index'      => isset($meta['chunk_index']) ? (int) $meta['chunk_index']
                                    : (isset($meta['part_index']) ? (int) $meta['part_index'] : null),
                'total_chunks'     => isset($meta['total_chunks']) ? (int) $meta['total_chunks']
                                    : (isset($meta['part_total']) ? (int) $meta['part_total'] : null),
                'is_chunked'       => !empty($meta['is_chunked']),
            ];
        }

        if (empty($rows)) {
            return new WP_REST_Response(['upsertedCount' => 0], 200);
        }

        try {
            $store = MxChat_Plus_DuckDB_Vector_Store::current();
            $store->ensure_schema();
            $count = $store->upsert($rows);
            return new WP_REST_Response(['upsertedCount' => $count], 200);
        } catch (\Throwable $e) {
            MxChat_Plus_DuckDB_Options::update(['last_error' => 'upsert: ' . $e->getMessage()]);
            return new WP_REST_Response(['upsertedCount' => 0, 'error' => $e->getMessage()], 500);
        }
    }

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
        return untrailingslashit($rest);
    }
}
