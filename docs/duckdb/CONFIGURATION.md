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
| `motherduck_mirror_enabled` | bool | `false` | Shadow the MotherDuck database in a local `.duckdb` file for fast reads. See [MIRROR.md](MIRROR.md). |
| `motherduck_mirror_path` | string | autodetect | Path of the local mirror file. Empty = `…/mxchat-plus-private/mirror.duckdb`. |
| `takeover_default_bot_pinecone` | bool | `false` | **Default-bot routing.** Answer MxChat's read of its own Pinecone settings (`pre_option_mxchat_pinecone_addon_options`) with the DuckDB proxy, so the default bot is served by DuckDB. The stored row keeps the real credentials: a save of MxChat's Pinecone card is put right, and switching this off gives MxChat its settings back. While it is on, the card's document-index tools are refused. |
| `takeover_multibot_bots` | bool | `false` | **Multi-Bot routing**, all bots: route every bot of MxChat's Multi-Bot add-on through the proxy (`mxchat_get_bot_pinecone_config`). A routed bot reads and writes only its own DuckDB namespace. |
| `takeover_bot_ids` | list | `[]` | **Multi-Bot routing**, selected bots: the bot ids to route when `takeover_multibot_bots` is off. A row stored before this setting existed has no such key and keeps routing the non-default bots that already have vectors in DuckDB until a choice is saved. `default`, `testing` and bots on a Pinecone document index are never routed. |
| `table_name` | string | `mxchat_vectors` | DuckDB table name. Sanitised to `[a-zA-Z0-9_]+` on save. |
| `embedding_dim` | int | `1536` | Must match the embedding model active in MxChat. **Locked** once the table contains rows (the `FLOAT[N]` / `TINYINT[N]` column type is fixed at CREATE time). |
| `distance_metric` | enum | `cosine` | `cosine` (default), `l2sq`, or `ip` (inner product). **Ranking only**: it decides which rows make the top-K and in which order, but the `score` returned to MxChat is always the cosine similarity, because MxChat thresholds on it. The HNSW index is created with the matching VSS metric. |
| `hnsw_enabled` | bool | `true` | Create an HNSW index over the embedding column. Strongly recommended above ~10 k vectors. |
| `top_k` | int | `50` | Default `topK` for similarity queries when MxChat doesn't send one; under Default-bot routing it also fills a missing MxChat Top K. `topK` is clamped to 1..10 000. |
| `hybrid_enabled` | bool | `false` | Blend BM25 full-text scores with vector similarity to choose and order the top-K (the score stays the cosine). Requires DuckDB's FTS extension *and* a query text: on Option B the proxy carries the visitor's text automatically; otherwise the `mxchat_plus_duckdb_query_text` filter must return it. Falls back to pure vector gracefully when either is missing. |
| `hybrid_alpha` | float | `0.7` | Weight on the vector side of the hybrid blend (1.0 = pure vector, 0.0 = pure BM25). |
| `query_cache_enabled` | bool | `true` | Cache top-K results in a transient keyed by `md5(embedding) + bot_id + filter + top_k`. Auto-invalidated on upsert/delete. |
| `query_cache_ttl` | int | `300` | Cache TTL in seconds. `0` skips writes but still reads. |
| `dedup_per_source` | bool | `false` | Collapse multiple chunks from the same `source_url` in the final top-K. Useful when the LLM context would otherwise be spammed with 5 near-duplicate chunks from one article. |
| `slow_query_ms` | int | `500` | Queries slower than this (in ms) are written to the PHP error log with the bot id + hybrid/dedup flags. `0` disables. |
| `embedding_storage` | enum | `float32` | `float32` (default) or `int8` (experimental — 4× smaller storage, ~1 % recall loss on unit-normalised embeddings, locked once the table has rows). See [USAGE.md → INT8 quantization](USAGE.md#int8-quantization-experimental). |
| `bot_overrides` | map | `[]` | Per-bot retrieval overrides (`bot_id → {hybrid_enabled, hybrid_alpha, dedup_per_source, query_cache_enabled, query_cache_ttl, slow_query_ms}`), edited in the *Per-bot retrieval overrides* table. |

## Runtime-only state

These keys also live in the same option but are *managed by the plugin*, not by the admin form. Don't edit them by hand. `sanitize_for_save()` keeps the stored value when a form save omits them, and keeps the value present in its input otherwise — WordPress runs every `update_option()` of this row through the sanitiser in admin requests, so the fresh `last_sync_at` written by **Sync now** survives. `MxChat_Plus_DuckDB_Options::update()` merges onto the stored row.

| Key | Purpose |
|---|---|
| `last_sync_at` | Unix timestamp of the most recent full / incremental sync. `0` means never synced: the compactor does not run until a first sync. |
| `last_sync_count` | Vector count from the most recent sync. |
| `last_sync_max_id` | Highest MySQL KB row id a sync has read. The incremental sync also picks up every newer id, whatever timezone MxChat wrote its `timestamp` in. |
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
| `mxchat_plus_duckdb_pinecone_migration_state` | Resumption cursor, index type, finished namespaces and counters for an in-flight Pinecone → DuckDB migration. |
| `mxchat_plus_duckdb_pinecone_imported` | Durable marker left by a migration that copied vectors: the compactor then leaves orphans alone, and the Pinecone host it names is the one whose writes are mirrored into DuckDB. |
| `mxchat_plus_duckdb_proxy_written_bots` | `{bots: {bot_id: first_seen}, seeded_at}` — the bots MxChat writes through the proxy, which the compactor never prunes. |
| `mxchat_plus_duckdb_custom_embedding_dim` | Dimension probed for a custom-provider embedding model, keyed like MxChat's own cache (`md5(base URL + '\|' + model)`), so a base-URL change invalidates it. |
| `mxchat_plus_duckdb_mirror_status`, `…_mirror_bootstrap_state`, `…_mirror_pending`, `…_mirror_last_drift_check` | Local mirror lifecycle, resumable bootstrap cursor, failed-write queue and drift-check timestamp. See [MIRROR.md](MIRROR.md). |
| `mxchat_plus_duckdb_delete_data_on_uninstall` | Opt-in flag (or the constant `MXCHAT_PLUS_DELETE_DATA_ON_UNINSTALL` in `wp-config.php`): also delete the embedded `.duckdb` data on uninstall. Off by default — that file may hold hours of embedding work. |
| `mxchat_plus_duckdb_cache_gen` | Per-bot generation counters. Woven into every query-cache transient key; bumped by writes via `MxChat_Plus_DuckDB_Cache::flush_query_cache($bot_id)` so cached top-K results become unreachable in O(1) without a `LIKE DELETE` over `wp_options`. Orphans expire via `query_cache_ttl` and the nightly sweep. |

All of the above are deleted on plugin uninstall via [`uninstall.php`](../../uninstall.php). On MotherDuck, the tables themselves (`mxchat_vectors`, `mxchat_plus_duckdb_schema_meta`, and `mxchat_vectors_v4_staging` if a migration was interrupted) must be dropped by hand.

## Host options this module reads

Never written, except `mxchat_pinecone_addon_options`, whose writes are only *corrected* (see `takeover_default_bot_pinecone`):

| Host option | Why |
|---|---|
| `mxchat_pinecone_addon_options` | MxChat's Pinecone settings: answered with the proxy under Default-bot routing; its host is also how the write mirror recognises the copied index. |
| `mxchat_options` | Embedding model and keys (`embedding_preflight()`), Custom Provider settings. |
| `mxchat_custom_embedding_dimension` | MxChat's own probe of a custom-provider dimension (3.2.24+), keyed on base URL + model. |
| `mxchat_hybrid_keyword_toggle` | MxChat's *Hybrid keyword boost* switch, followed only with the opt-in filter `mxchat_plus_duckdb_proxy_follow_host_hybrid_toggle`. |
| `mxchat_acf_pdf_extraction` | Whether *Reprocess all posts* asks MxChat's assembler to extract ACF PDFs. |
| `{prefix}mxchat_pinecone_roles` (table) | Role restrictions, resolved onto query matches. |

## Dimension / storage change guards

Two settings refuse to change once data exists:

- **`embedding_dim`** — the column type `FLOAT[N]` / `TINYINT[N]` is fixed at `CREATE TABLE` time. Changing the dimension silently breaks every subsequent insert/search. Wipe the table (`DROP` + re-run `ensure_schema`) and re-sync to switch models. The settings page reads the model MxChat will embed with next (Custom Provider aware, through `MxChat_Utils::get_selected_embedding_model()`), shows its dimension beside the stored one, and warns when the two models differ.
- **`embedding_storage`** — same reason: `FLOAT[]` ↔ `TINYINT[]` is a column-type change. Use the Parquet export → wipe → flip option → import path documented in [USAGE.md → Backups](USAGE.md#backups--cross-backend-moves-parquet).

Both guards are enforced in `MxChat_Plus_DuckDB_Options::sanitize_for_save()` with an `add_settings_error()` admin notice; the requested change is silently reverted to the current value. The schema v4 rebuild applies the same rule from the other side: it refuses, before touching anything, when the stored vectors no longer fit `embedding_dim` / `embedding_storage`, and the store stays unusable until the settings match again or `table_name` points at a new table.

## Where things are stored

| Surface | Location | Notes |
|---|---|---|
| Plugin options | `wp_options` | Single bundled blob `mxchat_plus_duckdb_options`, autoload = `no`. |
| Vectors (embedded mode) | `wp-content/uploads/mxchat-plus-private/store.duckdb` | Protected by `.htaccess` / `index.php` / `web.config` written at runtime. |
| Vectors (MotherDuck mode) | `md:<database>.main.mxchat_vectors` | The schema meta table lives next to it as `mxchat_plus_duckdb_schema_meta`; an interrupted v4 migration leaves `mxchat_vectors_v4_staging`, which the next run resumes from. |
| Translation catalogs | `languages/mxchat-plus-<locale>.mo` | Loaded via `load_plugin_textdomain()`. |
| Metrics window | `wp_options['mxchat_plus_duckdb_metrics']` | Rolling 1-hour latency samples + counters; `MxChat_Plus_DuckDB_Metrics::reset()` clears. |
| Query result cache | `wp_options['_transient_mxp_q_<botkey>_<gen>_<hash>']` | Transient layer, keyed by the bot's generation counter (`mxchat_plus_duckdb_cache_gen`). A write bumps that bot's counter so its cache namespace becomes unreachable in O(1); orphans expire by TTL. Results for a `topK` above 1000 are not cached. |
