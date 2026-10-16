# Hooks & filters

Every filter the plugin applies, with signature, purpose, and a short example. Use these to bend the plugin's behaviour without forking it.

## Quick reference

| Filter | Signature | Default | Purpose |
|---|---|---|---|
| [`mxchat_pre_vector_query`](#mxchat_pre_vector_query) | `(?array $previous, array $ctx): ?array` | `null` | WordPress-canonical `pre_*` short-circuit hook on the runtime RAG path. Return a Pinecone-shaped response to bypass the HTTP call. Registered unconditionally by this plugin, but **inert**: the hook does not exist in mxchat-basic (verified absent in 3.2.25), so it only fires on a host that was patched by hand (see [`tools/patches/`](../../tools/patches/README.md)) or if upstream ever ships it. |
| [`mxchat_plus_duckdb_post_content`](#mxchat_plus_duckdb_post_content) | `(string $content, WP_Post $post): string` | MxChat's own assembled text for the post | Customise the text content sent to MxChat's ingestion pipeline during reprocess. |
| [`mxchat_plus_duckdb_sync_bot_id`](#mxchat_plus_duckdb_sync_bot_id) | `(string $bot_id, object $row): string` | row's `bot_id` column, else `'default'` | Override the `bot_id` derived from a MySQL KB row. |
| [`mxchat_plus_duckdb_upsert_chunk_size`](#mxchat_plus_duckdb_upsert_chunk_size) | `(int $size, bool $is_remote): int` | 250 local, 50 MotherDuck | Override the batch size for `INSERT OR REPLACE`. |
| [`mxchat_plus_duckdb_proxy_rate_limit_per_minute`](#mxchat_plus_duckdb_proxy_rate_limit_per_minute) | `(int $max, string $namespace): int` | 120 (per namespace) | Per-namespace per-minute request cap on the Pinecone-proxy REST endpoints, for **external** (unsigned) callers. `0` disables. |
| [`mxchat_plus_duckdb_proxy_internal_rate_limit_per_minute`](#mxchat_plus_duckdb_proxy_internal_rate_limit_per_minute) | `(int $max, string $namespace): int` | 600 (per namespace) | Cap on MxChat's own signed `/query` loopbacks. Its other signed calls are not throttled. `0` disables. |
| [`mxchat_plus_duckdb_proxy_fetch_include_values`](#mxchat_plus_duckdb_proxy_fetch_include_values) | `(bool $include, string $namespace): bool` | `true` | Return the vectors' `values` from `/vectors/fetch`, as Pinecone does. |
| [`mxchat_plus_duckdb_proxy_follow_host_hybrid_toggle`](#mxchat_plus_duckdb_proxy_follow_host_hybrid_toggle) | `(bool $follow, string $bot_id): bool` | `false` | Opt-in: let MxChat's own *Hybrid keyword boost* switch turn the BM25 leg on for proxy queries. |
| [`mxchat_plus_duckdb_query_text`](#mxchat_plus_duckdb_query_text) | `(string $text, string $bot_id, array $filter): string` | the visitor's text on Option B, else `''` | The user query text for hybrid BM25 scoring. Empty = BM25 leg disabled. |
| [`mxchat_plus_duckdb_query_cache_max_top_k`](#mxchat_plus_duckdb_query_cache_max_top_k) | `(int $max, string $bot_id): int` | 1000 | Above this `topK`, results are neither read from nor written to the query cache. |
| [`mxchat_plus_duckdb_filter_fields`](#mxchat_plus_duckdb_filter_fields) | `(array<string,string> $fields): array<string,string>` | 12 built-in fields | Map extra Pinecone-style filter field names to physical columns. Column names are validated against `/^[a-zA-Z0-9_]+$/`; unsafe entries are dropped. |
| [`mxchat_plus_duckdb_bot_config`](#mxchat_plus_duckdb_bot_config) | `(array $config, string $bot_id): array` | resolved per-bot config | Override a bot's resolved retrieval config from code (multi-bot installs). Only retrieval knobs take effect; storage-level keys stay global. |
| [`mxchat_plus_duckdb_rerank_matches`](#mxchat_plus_duckdb_rerank_matches) | `(array $matches, array $embedding, string $bot_id, array $filter, string $query_text): array` | identity | Custom reranker hook — return a re-ordered top-K. |
| [`mxchat_plus_duckdb_max_retries`](#mxchat_plus_duckdb_max_retries) | `(int $n): int` | 3 | Retry attempts for idempotent SQL on transient errors. |
| [`mxchat_plus_duckdb_cli_timeout_seconds`](#mxchat_plus_duckdb_cli_timeout_seconds) | `(int $seconds): int` | 30 | Deadline (s) for a single DuckDB CLI invocation; the CLI is `proc_terminate`'d past this point. Minimum 1. |
| [`mxchat_plus_duckdb_health_public`](#mxchat_plus_duckdb_health_public) | `(bool $allow, WP_REST_Request $req): bool` | `true` | Gate the `/health` endpoint behind authentication. |
| [`mxchat_plus_duckdb_compactor_max_deletes`](#mxchat_plus_duckdb_compactor_max_deletes) | `(int $max): int` | 5000 | Per-run delete cap for the orphan compactor. |
| [`mxchat_plus_duckdb_compactor_max_orphan_ratio`](#mxchat_plus_duckdb_compactor_max_orphan_ratio) | `(float $ratio): float` | 0.5 | Refuse a prune run when more than this fraction of the scanned vectors look orphaned. `1.0` or more disables the check. |
| [`mxchat_plus_duckdb_compactor_prune_orphans`](#mxchat_plus_duckdb_compactor_prune_orphans) | `(bool $allowed, array\|null $import_marker): bool` | `false` after a Pinecone import, else `true` | Whether the compactor may prune orphans at all. |
| [`mxchat_plus_duckdb_mirrored_pinecone_host`](#mxchat_plus_duckdb_mirrored_pinecone_host) | `(string $host): string` | the host recorded by `migrate-from-pinecone` | The real Pinecone index whose writes are replayed into DuckDB. `''` turns the write mirror off. |

## Detailed reference

### `mxchat_pre_vector_query`

WordPress-canonical `pre_*` short-circuit hook on the **runtime RAG path** (the one fired during a chat conversation). Same convention as `pre_get_posts`, `pre_user_query`, `pre_option_*`: return `null` to fall through to default behaviour (the Pinecone HTTP call), return an array to bypass it entirely.

This hook does **not** exist in mxchat-basic — verified absent in 3.2.25, across the host tree (75 hook names, multi-line-aware count). It would require hand-patching the host, which its next update silently reverts, so this is not the recommended path: the proxy (Option B) is the default and needs no patch. The handler stays registered at no cost, so a patched host — or a future upstream release shipping the hook — is served from DuckDB before MxChat hits the network. The patch is kept in [`tools/patches/`](../../tools/patches/README.md), outside the release zip.

```php
// Context array shape:
//   [
//     'vector'    => float[],   // the query embedding
//     'top_k'     => int,       // requested result count
//     'namespace' => string,    // Pinecone namespace
//     'bot_id'    => string,    // mxchat bot id
//   ]
//
// Return shape (when short-circuiting):
//   ['matches' => [{id, score, metadata}, …], 'namespace' => '…']
//
// Return null to keep the default Pinecone HTTP path.

add_filter('mxchat_pre_vector_query', function ($previous, array $ctx) {
    if ($previous !== null) return $previous; // another handler already answered
    if ($ctx['namespace'] !== 'my_private_bot') return null; // not our scope

    // Hand off to a custom backend, e.g. an in-house Qdrant proxy.
    $matches = my_qdrant_query($ctx['vector'], $ctx['top_k']);
    return ['matches' => $matches, 'namespace' => $ctx['namespace']];
}, 5, 2); // priority 5 to win over the plugin's own handler at priority 10
```

### `mxchat_plus_duckdb_post_content`

Customise the text content sent through MxChat's ingestion pipeline when **Reprocess all posts** runs. By default the plugin sends the text MxChat's own importer builds for the post (`MxChat_Knowledge_Manager`'s assembler, reached by reflection: WooCommerce data and tabs, short description, ACF, synced patterns), after the host filter `mxchat_before_process_post`. When that assembler is unavailable, the fallback is the decoded title, the excerpt as `Short Description:`, and the post content with shortcode brackets removed and tags stripped — `the_content` is never run. This filter has the last word: use it to append custom meta fields, Yoast SEO descriptions, etc.

```php
add_filter('mxchat_plus_duckdb_post_content', function (string $content, WP_Post $post): string {
    // Append all ACF text fields to the body so they're searchable.
    if (function_exists('get_fields')) {
        $fields = (array) get_fields($post->ID);
        foreach ($fields as $k => $v) {
            if (is_string($v) && $v !== '') {
                $content .= "\n\n" . $k . ': ' . $v;
            }
        }
    }
    return $content;
}, 10, 2);
```

### `mxchat_plus_duckdb_sync_bot_id`

When the bulk / incremental sync copies a row from `wp_mxchat_system_prompt_content` to DuckDB, the `bot_id` is read from the row's `bot_id` column when present, else falls back to `'default'`. Use this filter to derive it from a URL prefix or post-meta instead.

```php
add_filter('mxchat_plus_duckdb_sync_bot_id', function (string $bot_id, $row): string {
    $url = (string) ($row->source_url ?? '');
    if (strpos($url, '/help-fr/') !== false) return 'support_fr';
    if (strpos($url, '/help-en/') !== false) return 'support_en';
    return $bot_id;
}, 10, 2);
```

### `mxchat_plus_duckdb_upsert_chunk_size`

The upsert path batches rows into a single `INSERT OR REPLACE` to amortise the SQL overhead. The default is 250 rows for the embedded backend and 50 for MotherDuck (the smaller default keeps each request well under HTTP body size limits). Drop it lower on slow links, raise it on a beefy local DuckDB.

```php
add_filter('mxchat_plus_duckdb_upsert_chunk_size', fn(int $n, bool $remote): int => $remote ? 25 : 500, 10, 2);
```

### `mxchat_plus_duckdb_proxy_rate_limit_per_minute`

The REST proxy (Option B) caps **external** requests at **120/min per namespace** (i.e. per bot, since the namespace is the bot id under the hood) to protect against a misbehaving client saturating CPU with vector searches. The filter receives the namespace so per-tenant ceilings are tunable. Raise it for high-traffic chatbots, set `0` to disable entirely.

MxChat's own loopback calls are not counted here. On `http_request_args`, every request to the proxy host gets an `X-MxChat-Plus-Internal` header — an HMAC-SHA256 keyed from `wp_salt('auth')`, bound to method, path, minute and token, checked after the token. Signed list, fetch, delete, upsert and stats calls are not throttled at all, and signed `/query` calls have their own bucket ([below](#mxchat_plus_duckdb_proxy_internal_rate_limit_per_minute)), so bulk admin flows (REST `DELETE /mxchat/v1/knowledge`, kb-reimport) neither fail with 429 nor starve visitors' chats. If a CDN or reverse proxy strips the header, those calls fall back to this external limit.

```php
// Same ceiling everywhere:
add_filter('mxchat_plus_duckdb_proxy_rate_limit_per_minute', fn() => 600);

// Or per-bot:
add_filter('mxchat_plus_duckdb_proxy_rate_limit_per_minute', function (int $max, string $ns): int {
    if ($ns === 'support_fr') return 1000; // hot bot
    if ($ns === '_global')    return 60;   // legacy wildcard tokens — keep low
    return $max;
}, 10, 2);
```

### `mxchat_plus_duckdb_proxy_internal_rate_limit_per_minute`

`(int $max = 600, string $namespace): int` — the CPU guard on MxChat's own signed `/query` loopbacks, per namespace and per minute. `0` disables it. Every other signed call is unthrottled.

```php
add_filter('mxchat_plus_duckdb_proxy_internal_rate_limit_per_minute', fn() => 1200);
```

### `mxchat_plus_duckdb_proxy_fetch_include_values`

`(bool $include = true, string $namespace): bool` — `/vectors/fetch` returns each vector's `values` (dequantised for INT8), as real Pinecone does. No MxChat 3.2.25 caller reads them; return `false` to shrink the payloads.

### `mxchat_plus_duckdb_proxy_follow_host_hybrid_toggle`

`(bool $follow = false, string $bot_id): bool` — opt-in. When it returns `true` and MxChat's own **Hybrid keyword boost** switch (host option `mxchat_hybrid_keyword_toggle`) is `on`, the BM25 leg runs for proxy queries of that bot even with this plugin's `hybrid_enabled` off. Flipping the host switch flushes every bot's query cache.

```php
add_filter('mxchat_plus_duckdb_proxy_follow_host_hybrid_toggle', '__return_true');
```

### `mxchat_plus_duckdb_query_text`

The user's raw query text, for the BM25 leg of hybrid retrieval. The proxy is a separate HTTP request that carries only the embedding, so on Option B the plugin carries the text itself: it observes the host filter `mxchat_retrieval_query` (the final value at `PHP_INT_MAX`, falling back to the original message like MxChat does), adds it to the `/query` body as `mxchat_plus_query_text` — only when the target is the proxy, so the visitor's text never reaches a real Pinecone index — and caps it at 2000 characters. In the proxy request it becomes this filter's default value for that bot. A callback at the default priority still receives it and may replace it; `mxchat_plus_duckdb_rerank_matches` receives it too.

```php
// Strip a greeting before BM25 sees the text.
add_filter('mxchat_plus_duckdb_query_text', function (string $text, string $bot_id, array $filter): string {
    return preg_replace('/^(hi|hello|bonjour)[,!\s]+/i', '', $text);
}, 10, 3);
```

(Earlier versions of this page suggested an `mxchat_before_query` action. That hook does not exist in MxChat, and a filter added during the chat request never reaches the separate proxy request anyway.)

### `mxchat_plus_duckdb_query_cache_max_top_k`

`(int $max = 1000, string $bot_id): int` — results for a `topK` above this are neither read from nor written to the query cache. The default covers MxChat's own 1..1000 chat clamp; the host's random-vector admin sweeps, which ask for up to 10 000, no longer bloat `wp_options`. `topK` itself is clamped to Pinecone's 1..10 000.

### `mxchat_plus_duckdb_filter_fields`

The Pinecone-style filter compiler honours a fixed set of metadata fields out of the box: `type` / `content_type`, `role_restriction`, `source_url`, `chunk_index`, `total_chunks`, `is_chunked`, `bot_id`, `title`, `parent_url_hash`, and `created_at` / `last_updated` (mapped to the unix columns `created_unix` / `last_updated_unix`). It supports `$eq`, `$ne`, `$gt`, `$gte`, `$lt`, `$lte`, `$in`, `$nin`, `$exists`, scalar shorthand (`{field: value}` means `$eq`) and nested `$and` / `$or`. A **positive** clause on a field DuckDB does not store matches nothing (it used to be dropped, which widened results); a negative one adds no constraint. Unknown fields are logged under `WP_DEBUG` and counted in the `filter_unknown_field` metric; an unknown operator or a malformed filter raises an error that the proxy answers with HTTP 400, as Pinecone does. If an add-on stores extra columns alongside each vector — e.g. via mxchat-basic's [`mxchat_embedding_chunk_metadata`](https://mxchat.ai/mxchat-developer-hooks-filters/) filter — register them here so callers can constrain retrieval on them.

The map is `filter-field-name => physical-column-name`. **Column names are validated against `/^[a-zA-Z0-9_]+$/` before they touch SQL**, so a malformed or hostile entry is silently dropped (logged under `WP_DEBUG`) rather than becoming an injection vector. The field *key* (left side) is what callers pass in their Pinecone filter; the *value* (right side) must be a real column on the vectors table.

```php
add_filter('mxchat_plus_duckdb_filter_fields', function (array $fields): array {
    $fields['language'] = 'lang_code'; // expose a custom "lang_code" column
    return $fields;
});

// …then a query filter like ['language' => ['$eq' => 'fr']] compiles to
// "lang_code = ?" with 'fr' bound as a parameter.
```

Returning a non-array (or empty) value leaves the built-in fields untouched.

### `mxchat_plus_duckdb_bot_config`

Resolved **per-bot retrieval config**. Fires inside
`MxChat_Plus_DuckDB_Options::get_for_bot($bot_id)` after the global settings have
been merged with any per-bot overrides from the admin **Per-bot retrieval
overrides** table. Receives the merged config array and the bot id; return a
modified array to drive retrieval per bot from code — handy on multi-bot
installs that prefer configuration-as-code over the UI.

Only the *retrieval* keys are consulted downstream: `hybrid_enabled`,
`hybrid_alpha`, `dedup_per_source`, `query_cache_enabled`, `query_cache_ttl`,
`slow_query_ms`. Storage-level keys (backend mode, table, dimension, metric,
HNSW, storage layout) are fixed globally and ignored here — every bot shares one
physical store. `top_k` comes from mxchat per request, not from this config.

```php
add_filter('mxchat_plus_duckdb_bot_config', function (array $config, string $bot_id): array {
    if ($bot_id === 'support_fr') {
        $config['hybrid_enabled']   = true;  // BM25 + vector for the support bot
        $config['hybrid_alpha']     = 0.6;
        $config['query_cache_ttl']  = 60;    // fresher answers
    }
    return $config;
}, 10, 2);
```

The filter runs *after* the stored UI overrides, so code wins over the table
when both touch the same key.

### `mxchat_plus_duckdb_rerank_matches`

The plugin runs cosine (and optionally BM25) but doesn't ship a cross-encoder reranker. This filter receives the top-K and can return a re-ordered set. Plug in BGE-reranker on a local FastAPI, Cohere Rerank, Voyage Rerank, anything.

```php
add_filter('mxchat_plus_duckdb_rerank_matches', function (array $matches, array $embedding, string $bot_id, array $filter, string $query_text): array {
    if (empty($matches) || $query_text === '') return $matches;
    $resp = wp_remote_post('https://api.cohere.com/v1/rerank', [
        'headers' => ['Authorization' => 'Bearer ' . getenv('COHERE_API_KEY')],
        'body' => wp_json_encode([
            'model' => 'rerank-multilingual-v3.0',
            'query' => $query_text,
            'documents' => array_map(fn($m) => $m['metadata']['text'], $matches),
            'top_n' => count($matches),
        ]),
        'timeout' => 5,
    ]);
    if (is_wp_error($resp)) return $matches;
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    $reordered = [];
    foreach (($body['results'] ?? []) as $r) {
        $reordered[] = $matches[$r['index']];
    }
    return $reordered ?: $matches;
}, 10, 5);
```

### `mxchat_plus_duckdb_max_retries`

The embedded backend retries idempotent SQL (SELECT/WITH/PRAGMA/SHOW/DESCRIBE/EXPLAIN) on transient errors (timeout, connection reset, 502/503, rate-limit, TLS handshake) with exponential backoff + jitter. Defaults to 3 attempts. Set higher on flaky links.

```php
add_filter('mxchat_plus_duckdb_max_retries', fn() => 5);
```

### `mxchat_plus_duckdb_cli_timeout_seconds`

The DuckDB CLI fallback path (used when the PECL `duckdb` extension is not loaded) runs under a non-blocking `stream_select` deadline so a hung CLI doesn't freeze the PHP-FPM worker until the web-server timeout. Default 30 s, minimum 1 s. Lower it for read-heavy admin pages, raise it for long-running imports or large Parquet roundtrips.

```php
// Tight cap for chat queries (most should complete in well under a second);
// keep imports running longer:
add_filter('mxchat_plus_duckdb_cli_timeout_seconds', function (int $s): int {
    return wp_doing_cron() ? 120 : 5;
});
```

### `mxchat_plus_duckdb_health_public`

The `/wp-json/mxchat-plus/v1/health` endpoint is **public by default** so external uptime monitors (UptimeRobot, Pingdom, k6) can probe it without an auth token. The payload only leaks aggregate counts, never vector content. Override to require `manage_options` (or your own auth check) if your threat model objects.

```php
add_filter('mxchat_plus_duckdb_health_public', '__return_false'); // require capability check
```

### `mxchat_plus_duckdb_compactor_max_deletes`

The daily orphan compactor caps its delete count per run to avoid blowing up MotherDuck billing on a misconfigured install. Default is 5000. Lower it for paranoid setups; raise it after a big content purge.

```php
add_filter('mxchat_plus_duckdb_compactor_max_deletes', fn() => 50000);
```

### `mxchat_plus_duckdb_compactor_max_orphan_ratio`

`(float $ratio = 0.5): float` — a safety valve. When more than this fraction of the vectors scanned look orphaned (and more than 10 do), the run is refused and `last_error` says why; an empty MySQL knowledge base while prunable vectors exist is refused the same way. Both usually mean the alive set is wrong, not that half the knowledge base was deleted. `wp mxchat-plus duckdb compact --force` overrides the two checks for one run; a value of `1.0` or more disables the ratio check.

```php
add_filter('mxchat_plus_duckdb_compactor_max_orphan_ratio', fn() => 0.8);
```

### `mxchat_plus_duckdb_mirrored_pinecone_host`

`(string $host): string` — the real Pinecone index whose writes MxChat makes are replayed into DuckDB (the host recorded by `migrate-from-pinecone`). Every successful delete or upsert MxChat sends there — classic `/vectors/delete` and `/vectors/upsert`, document-index `/namespaces/{ns}/documents/delete` and `/upsert` — is mirrored, which covers REST `DELETE /mxchat/v1/knowledge`, media auto-sync, trash and unpublish, and kb-reimport. Return `''` to turn the mirror off. The proxy's own host is never mirrored.

```php
add_filter('mxchat_plus_duckdb_mirrored_pinecone_host', '__return_empty_string');
```

## Other extension points

Beyond filters, two action hooks are useful:

- **`mxchat_plus_duckdb_incremental_sync`** (cron, hourly) — picks up new rows from the MySQL KB. You can `do_action('mxchat_plus_duckdb_incremental_sync')` to trigger it manually.
- **`mxchat_plus_duckdb_compact`** (cron, daily) — runs the orphan compactor. Same pattern.
- **`mxchat_plus_duckdb_kb_resync`** (WP-cron single event, debounced) — re-syncs MySQL KB rows MxChat rewrote without changing their timestamp. Scheduled 5 minutes after an applied kb-reimport (AJAX or `wp mxchat kb-reimport`) for a full sync, and 30 seconds after a role change on a WordPress-stored entry, with that row's id as argument. Deactivation and uninstall clear it.

For deeper integration (custom backends, replacing the connection factory, etc.), the codebase uses dependency injection through constructors — see [ARCHITECTURE.md → Design conventions](ARCHITECTURE.md#design-conventions).

### `mxchat_plus_duckdb_compactor_prune_orphans`

`bool $allowed, array|null $import_marker`

Whether the nightly compactor may delete vectors that have no matching row in
the MySQL knowledge base.

Defaults to `true`, but flips to `false` once vectors have been imported
directly from Pinecone. The migrator preserves Pinecone's own vector ids, which
need not follow the `md5(url)[_chunk_N]` convention the sync writes — so those
vectors look like orphans to the sweep and would be deleted the night after an
import that was meant to avoid re-embedding them.

Re-enable it if you know your imported ids do match the sync convention:

```php
add_filter( 'mxchat_plus_duckdb_compactor_prune_orphans', '__return_true' );
```
