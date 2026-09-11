# Configuration

All settings live in a single WP option, `mxchat_plus_duckdb_options`. The admin UI under **MxChat → DuckDB / MotherDuck** edits this option directly through `register_setting()` + the [`MxChat_Plus_DuckDB_Options::sanitize_for_save()`](../../includes/duckdb/class-duckdb-options.php) sanitiser.

## Options reference

| Key | Type | Default | Purpose |
|---|---|---|---|
| `enabled` | bool | `false` | Master switch. When off, MxChat falls back to its native behaviour. |
| `mode` | enum | `motherduck` | `motherduck` or `embedded`. |
| `motherduck_token` | string | `""` | MotherDuck auth token (from [app.motherduck.com](https://app.motherduck.com)). Stored encrypted-at-rest only if your `wp-config.php` enables an option-encrypting plugin — by default it lives in plaintext in `wp_options`. |
| `motherduck_database` | string | `my_db` | MotherDuck database name. Validated `[a-zA-Z0-9_]+` to prevent SQL injection through the `ATTACH` literal. |
| `embedded_path` | string | autodetect | Path to the `.duckdb` file. Empty = `wp-content/uploads/mxchat-plus-private/store.duckdb`, with auto-generated `.htaccess` + `index.php` + `web.config` blockers. |
| `embedded_binary` | string | autodetect | Path to the `duckdb` CLI binary (used when the PECL `duckdb` extension is not loaded). |
| `table_name` | string | `mxchat_vectors` | DuckDB table name. Sanitised to `[a-zA-Z0-9_]+` on save. |
| `embedding_dim` | int | `1536` | Must match the embedding model active in MxChat. **Locked** once the table contains rows (the `FLOAT[N]` / `TINYINT[N]` column type is fixed at CREATE time). |
| `distance_metric` | enum | `cosine` | `cosine` (default), `l2sq`, or `ip` (inner product). The HNSW index is created with the matching VSS metric. |
| `hnsw_enabled` | bool | `true` | Create an HNSW index over the embedding column. Strongly recommended above ~10 k vectors. |
| `top_k` | int | `50` | Default `topK` for similarity queries when MxChat doesn't override. |
| `hybrid_enabled` | bool | `false` | Blend BM25 full-text scores with vector similarity. Requires DuckDB's FTS extension *and* a non-empty return from the `mxchat_plus_duckdb_query_text` filter. Falls back to pure vector gracefully when either is missing. |
| `hybrid_alpha` | float | `0.7` | Weight on the vector score in the hybrid blend (1.0 = pure vector, 0.0 = pure BM25). |
| `query_cache_enabled` | bool | `true` | Cache top-K results in a transient keyed by `md5(embedding) + bot_id + filter + top_k`. Auto-invalidated on upsert/delete. |
| `query_cache_ttl` | int | `300` | Cache TTL in seconds. `0` skips writes but still reads. |
| `dedup_per_source` | bool | `false` | Collapse multiple chunks from the same `source_url` in the final top-K. Useful when the LLM context would otherwise be spammed with 5 near-duplicate chunks from one article. |
| `slow_query_ms` | int | `500` | Queries slower than this (in ms) are written to the PHP error log with the bot id + hybrid/dedup flags. `0` disables. |
| `embedding_storage` | enum | `float32` | `float32` (default) or `int8` (experimental — 4× smaller storage, ~1 % recall loss on unit-normalised embeddings, locked once the table has rows). See [USAGE.md → INT8 quantization](USAGE.md#int8-quantization-experimental). |

## Runtime-only state

These keys also live in the same option but are *managed by the plugin*, not by the admin form. Don't edit them by hand — they're preserved through `sanitize_for_save()` so admin saves don't wipe them.

| Key | Purpose |
|---|---|
| `last_sync_at` | Unix timestamp of the most recent full / incremental sync. |
| `last_sync_count` | Vector count from the most recent sync. |
| `last_compact_at` | Unix timestamp of the most recent compactor run. |
| `last_error` | Last surfaced error from any subsystem; cleared on next successful op. |

## Sidecar options

The plugin also writes a handful of non-autoloaded options outside the main bundle:

| Option | Purpose |
|---|---|
| `mxchat_plus_duckdb_proxy_token` | Legacy global REST proxy token (wildcard scope, used as fallback). |
| `mxchat_plus_duckdb_proxy_token_map` | Map `{ namespace → token }` for per-namespace REST tokens. |
| `mxchat_plus_duckdb_metrics` | Rolling 1-hour latency histogram + named counters. |
| `mxchat_plus_duckdb_reprocess_state` | Snapshot of the most recent Action-Scheduler-driven reprocess batch. |
| `mxchat_plus_duckdb_pinecone_migration_state` | Resumption token + counters for an in-flight Pinecone → DuckDB migration. |
| `mxchat_plus_duckdb_cache_gen` | Monotonic integer (starts at 1). Woven into every query-cache transient key; bumped by writes via `MxChat_Plus_DuckDB_Cache::bump_cache_generation()` so cached top-K results become unreachable in O(1) without a `LIKE DELETE` over `wp_options`. Orphans expire via `query_cache_ttl`. |

All of the above are deleted on plugin uninstall via [`uninstall.php`](../../uninstall.php).

## Dimension / storage change guards

Two settings refuse to change once data exists:

- **`embedding_dim`** — the column type `FLOAT[N]` / `TINYINT[N]` is fixed at `CREATE TABLE` time. Changing the dimension silently breaks every subsequent insert/search. Wipe the table (`DROP` + re-run `ensure_schema`) and re-sync to switch models.
- **`embedding_storage`** — same reason: `FLOAT[]` ↔ `TINYINT[]` is a column-type change. Use the Parquet export → wipe → flip option → import path documented in [USAGE.md → Backups](USAGE.md#backups--cross-backend-moves-parquet).

Both guards are enforced in `MxChat_Plus_DuckDB_Options::sanitize_for_save()` with an `add_settings_error()` admin notice; the requested change is silently reverted to the current value.

## Where things are stored

| Surface | Location | Notes |
|---|---|---|
| Plugin options | `wp_options` | Single bundled blob `mxchat_plus_duckdb_options`, autoload = `no`. |
| Vectors (embedded mode) | `wp-content/uploads/mxchat-plus-private/store.duckdb` | Protected by `.htaccess` / `index.php` / `web.config` written at runtime. |
| Vectors (MotherDuck mode) | `md:<database>.main.mxchat_vectors` | The schema meta table lives next to it as `mxchat_plus_duckdb_schema_meta`. |
| Translation catalogs | `languages/mxchat-plus-<locale>.mo` | Loaded via `load_plugin_textdomain()`. |
| Metrics window | `wp_options['mxchat_plus_duckdb_metrics']` | Rolling 1-hour latency samples + counters; `MxChat_Plus_DuckDB_Metrics::reset()` clears. |
| Query result cache | `wp_options['_transient_mxp_q_<gen>_*']` | Transient layer, keyed by the generation counter (`mxchat_plus_duckdb_cache_gen`). Writes bump the counter so the entire cache namespace becomes unreachable in O(1); orphans expire by TTL. |
