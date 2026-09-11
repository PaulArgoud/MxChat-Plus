# Hooks & filters

Every filter the plugin applies, with signature, purpose, and a short example. Use these to bend the plugin's behaviour without forking it.

## Quick reference

| Filter | Signature | Default | Purpose |
|---|---|---|---|
| [`mxchat_pre_vector_query`](#mxchat_pre_vector_query) | `(?array $previous, array $ctx): ?array` | `null` | WordPress-canonical `pre_*` short-circuit hook on the runtime RAG path. Return a Pinecone-shaped response to bypass the HTTP call. Wired automatically by this plugin when the upstream patch is present (see [`patches/README.md`](../patches/README.md)). |
| [`mxchat_plus_duckdb_post_content`](#mxchat_plus_duckdb_post_content) | `(string $content, WP_Post $post): string` | title + `the_content` filters + stripped tags | Customise the text content sent to MxChat's ingestion pipeline during reprocess. |
| [`mxchat_plus_duckdb_sync_bot_id`](#mxchat_plus_duckdb_sync_bot_id) | `(string $bot_id, object $row): string` | row's `bot_id` column, else `'default'` | Override the `bot_id` derived from a MySQL KB row. |
| [`mxchat_plus_duckdb_upsert_chunk_size`](#mxchat_plus_duckdb_upsert_chunk_size) | `(int $size, bool $is_remote): int` | 250 local, 50 MotherDuck | Override the batch size for `INSERT OR REPLACE`. |
| [`mxchat_plus_duckdb_proxy_rate_limit_per_minute`](#mxchat_plus_duckdb_proxy_rate_limit_per_minute) | `(int $max, string $namespace): int` | 120 (per namespace) | Per-namespace per-minute request cap on the Pinecone-proxy REST endpoints. `0` disables. |
| [`mxchat_plus_duckdb_query_text`](#mxchat_plus_duckdb_query_text) | `(string $text, string $bot_id, array $filter): string` | `''` | Supply the user query text for hybrid BM25 scoring. Empty = BM25 leg disabled. |
| [`mxchat_plus_duckdb_filter_fields`](#mxchat_plus_duckdb_filter_fields) | `(array<string,string> $fields): array<string,string>` | 5 built-in fields | Map extra Pinecone-style filter field names to physical columns. Column names are validated against `/^[a-zA-Z0-9_]+$/`; unsafe entries are dropped. |
| [`mxchat_plus_duckdb_bot_config`](#mxchat_plus_duckdb_bot_config) | `(array $config, string $bot_id): array` | resolved per-bot config | Override a bot's resolved retrieval config from code (multi-bot installs). Only retrieval knobs take effect; storage-level keys stay global. |
| [`mxchat_plus_duckdb_rerank_matches`](#mxchat_plus_duckdb_rerank_matches) | `(array $matches, array $embedding, string $bot_id, array $filter, string $query_text): array` | identity | Custom reranker hook — return a re-ordered top-K. |
| [`mxchat_plus_duckdb_max_retries`](#mxchat_plus_duckdb_max_retries) | `(int $n): int` | 3 | Retry attempts for idempotent SQL on transient errors. |
| [`mxchat_plus_duckdb_cli_timeout_seconds`](#mxchat_plus_duckdb_cli_timeout_seconds) | `(int $seconds): int` | 30 | Deadline (s) for a single DuckDB CLI invocation; the CLI is `proc_terminate`'d past this point. Minimum 1. |
| [`mxchat_plus_duckdb_health_public`](#mxchat_plus_duckdb_health_public) | `(bool $allow, WP_REST_Request $req): bool` | `true` | Gate the `/health` endpoint behind authentication. |
| [`mxchat_plus_duckdb_compactor_max_deletes`](#mxchat_plus_duckdb_compactor_max_deletes) | `(int $max): int` | 5000 | Per-run delete cap for the orphan compactor. |

## Detailed reference

### `mxchat_pre_vector_query`

WordPress-canonical `pre_*` short-circuit hook on the **runtime RAG path** (the one fired during a chat conversation). Same convention as `pre_get_posts`, `pre_user_query`, `pre_option_*`: return `null` to fall through to default behaviour (the Pinecone HTTP call), return an array to bypass it entirely.

Requires the ~12-line upstream patch in [`patches/README.md`](../patches/README.md). When the patch is present, this plugin hooks it automatically and serves matches from DuckDB before MxChat hits the network. When the patch is absent, the proxy path (Option B) takes over transparently.

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

Customise the text content sent through MxChat's ingestion pipeline when **Reprocess all posts** runs. By default the plugin sends `title + apply_filters('the_content', $content) + wp_strip_all_tags()`. Use this filter to append custom meta fields, ACF data, Yoast SEO descriptions, etc.

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

The REST proxy (Option B) caps requests at **120/min per namespace** (i.e. per bot, since the namespace is the bot id under the hood) to protect against a misbehaving client saturating CPU with HNSW searches. The filter receives the namespace so per-tenant ceilings are tunable. Raise it for high-traffic chatbots, set `0` to disable entirely.

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

### `mxchat_plus_duckdb_query_text`

Provide the user's raw query text so the plugin can run the BM25 leg of hybrid retrieval. MxChat's Pinecone integration doesn't naturally pass the text — it only passes the embedding. Wire this filter once and the plugin will run hybrid search whenever `hybrid_enabled` is on.

```php
// Capture the user message before MxChat embeds it, then expose it via the filter.
add_action('mxchat_before_query', function (string $user_message): void {
    add_filter('mxchat_plus_duckdb_query_text', fn() => $user_message, 10, 0);
});
```

### `mxchat_plus_duckdb_filter_fields`

The Pinecone-style filter compiler only honours a fixed set of metadata fields out of the box: `type` / `content_type`, `role_restriction`, `source_url`, and `chunk_index`. If an add-on stores extra columns alongside each vector — e.g. via mxchat-basic's [`mxchat_embedding_chunk_metadata`](https://mxchat.ai/mxchat-developer-hooks-filters/) filter — register them here so callers can constrain retrieval on them.

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

## Other extension points

Beyond filters, two action hooks are useful:

- **`mxchat_plus_duckdb_incremental_sync`** (cron, hourly) — picks up new rows from the MySQL KB. You can `do_action('mxchat_plus_duckdb_incremental_sync')` to trigger it manually.
- **`mxchat_plus_duckdb_compact`** (cron, daily) — runs the orphan compactor. Same pattern.

For deeper integration (custom backends, replacing the connection factory, etc.), the codebase uses dependency injection through constructors — see [ARCHITECTURE.md → Design conventions](../ARCHITECTURE.md#design-conventions).

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
