# Architecture

How **mxchat-plus** plugs into MxChat, how a query flows through the system, and where each class lives.

---

## How it plugs into MxChat

The plugin offers **two parallel integration paths** with MxChat's vector-search dispatch. Both are registered unconditionally; at runtime the faster one wins when its prerequisite is present.

```mermaid
flowchart LR
    User([👤 User message])
    User --> MxChat

    subgraph MxChat[MxChat core]
        direction TB
        Find["find_relevant_content_pinecone()"]
    end

    Find -.->|"Option A<br/>filter mxchat_pre_vector_query<br/>(needs ~12-line upstream patch)"| Adapter
    Find ==>|"Option B<br/>wp_remote_post to 'Pinecone host'<br/>(zero MxChat patch)"| Proxy

    subgraph Plugin["mxchat-plus"]
        direction TB
        Adapter["Search adapter<br/>filter hooks"]
        Proxy["Pinecone proxy<br/>REST endpoints<br/>+ rate-limit + token check"]
        Store[("DuckDB Vector Store<br/>HNSW · cosine · FTS<br/>query cache · dedup · rerank")]
        Adapter --> Store
        Proxy --> Store
    end

    Store --> Embedded[("Embedded backend<br/>local .duckdb file")]
    Store --> MotherDuck[("MotherDuck backend<br/>ATTACH 'md:<db>?token=…'")]

    style Plugin fill:#fff3e0,stroke:#e65100
    style Embedded fill:#e3f2fd,stroke:#1565c0
    style MotherDuck fill:#fffde7,stroke:#f9a825
```

### Option A — filter override (preferred)

When `mxchat-basic` is patched with the ~12-line snippet in [`patches/README.md`](patches/README.md), the plugin hooks the `mxchat_pre_vector_query` filter (WordPress-canonical `pre_*` short-circuit convention) and returns matches from DuckDB *before* MxChat would have made the Pinecone HTTP call. **Zero round-trip overhead.** The legacy `mxchat_pinecone_matches_override` hook stays registered for installs that applied the previous patch contract.

### Option B — Pinecone wire-protocol proxy (zero patch)

Without the patch, the plugin uses the existing `mxchat_get_bot_pinecone_config` filter to register itself as the Pinecone backend, and serves a REST namespace at `/wp-json/mxchat-plus/v1/pinecone-proxy/` that emulates the Pinecone wire protocol (`/query`, `/vectors/fetch`, `/vectors/upsert`, `/vectors/delete`, `/vectors/list`). MxChat thinks it's talking to Pinecone; the proxy translates each call to DuckDB SQL.

Both paths return identical results.

---

## Query lifecycle

What actually happens when the chatbot answers a question. Numbered arrows show the order; dashed boxes are conditional steps.

```mermaid
sequenceDiagram
    autonumber
    actor User
    participant MxChat
    participant Adapter as Search adapter
    participant Cache as Query cache<br/>(WP transient)
    participant Store as Vector store
    participant DB as DuckDB / MotherDuck
    participant Metrics

    User->>MxChat: question
    MxChat->>MxChat: embed query (OpenAI / Voyage / Gemini)
    MxChat->>Adapter: find_relevant_content_pinecone(embedding, bot_id, filter)

    Note over Adapter: apply_filters('mxchat_pre_vector_query')

    Adapter->>Store: query_pinecone_shape(embedding, top_k, bot_id, filter)
    Store->>Cache: get_transient(mxp_q_<md5(emb·filter·bot·k)>)

    alt cache hit
        Cache-->>Store: cached top-K
        Store->>Metrics: record('query_cache_hit')
    else cache miss
        Note over Store: build SQL — cosine + WHERE bot_id<br/>+ optional BM25 leg if hybrid_enabled<br/>+ Pinecone-style filter compiler
        Store->>DB: SELECT … FROM mxchat_vectors WHERE … LIMIT 2k
        DB-->>Store: candidate rows
        Store->>Store: per-source dedup<br/>+ mxchat_plus_duckdb_rerank_matches hook
        Store->>Cache: set_transient(matches, ttl=300s)
        Store->>Metrics: observe_latency(ms)
    end

    Store-->>Adapter: Pinecone-shaped matches<br/>[{id, score, metadata}, …]
    Adapter-->>MxChat: matches
    MxChat-->>User: LLM answer grounded in retrieved chunks
```

**Cache invalidation**: any `upsert()` or `delete_*()` call invalidates the query cache via `MxChat_Plus_DuckDB_Cache::flush_query_cache($bot_id)`, which bumps a **per-bot** generation counter woven into the transient key (`mxp_q_<botkey>_<gen>_<hash>`). A write to one bot leaves other bots' cached top-Ks intact. So a stale top-K can survive at most `query_cache_ttl` seconds, or until the next write **to that bot**, whichever comes first. The daily compactor sweeps each bot's superseded generations; orphans also lapse via TTL.

**Per-bot config**: `run()` resolves its retrieval settings via `MxChat_Plus_DuckDB_Options::get_for_bot($bot_id)` — the global config with any per-bot overrides applied (hybrid, dedup, query cache, slow-query threshold), then passed through the `mxchat_plus_duckdb_bot_config` filter. Storage-level settings (backend, table, dimension, metric, HNSW, layout) stay global — every bot shares one physical store.

**Hybrid retrieval**: when `hybrid_enabled = true` *and* the `mxchat_plus_duckdb_query_text` filter returns a non-empty string, the store runs two over-fetched queries (vector + BM25), min-max-normalises both score lists, blends them with `hybrid_alpha`, and reranks. Falls back to pure vector when DuckDB FTS isn't available.

**Slow-query log**: every `observe_latency()` checks the result against `slow_query_ms` and writes a one-liner to PHP's error log on breach, with bot id and feature flags inlined.

---

## File layout

```
mxchat-plus/
├── mxchat-plus.php                              Plugin bootstrap
├── uninstall.php                                  Full cleanup on plugin delete
├── readme.txt                                     WordPress.org plugin-directory readme
├── composer.json                                  Classmap autoload + dev-deps
├── phpstan.neon.dist                              PHPStan config (level 6)
├── phpunit.xml.dist                               PHPUnit config
├── LICENSE                                        GPL v2
├── .github/workflows/
│   ├── ci.yml                                     php -l matrix + msgfmt + PHPStan + PHPUnit
│   └── release.yml                                Build & attach release zip on v* tags
├── includes/
│   ├── class-duckdb-options.php                   Settings + defaults + directory blockers
│   ├── class-duckdb-metrics.php                   Rolling latency window + counters
│   ├── class-duckdb-quantization.php              INT8 round-trip helpers
│   ├── class-duckdb-connection.php                Interface + cached factory
│   ├── class-duckdb-motherduck-connection.php     ATTACH wrapper over the embedded backend
│   ├── class-duckdb-embedded-connection.php       PECL + CLI backend with init-SQL + retry
│   ├── class-duckdb-vector-store.php              Versioned schema, hybrid query, Parquet I/O
│   ├── class-duckdb-sync.php                      Bulk sync + reprocess + WP-cron + bot_id detection
│   ├── class-duckdb-async-reprocess.php           Action Scheduler driver
│   ├── class-duckdb-pinecone-migrator.php         Resumable Pinecone → DuckDB copier
│   ├── class-duckdb-compactor.php                 Daily orphan-vector pruning cron
│   ├── class-duckdb-pinecone-proxy.php            REST endpoints (rate-limited, per-namespace tokens)
│   ├── class-duckdb-search-adapter.php            Filter hooks (Option A + Option B) + error notices
│   ├── class-duckdb-health.php                    /wp-json/mxchat-plus/v1/health
│   ├── class-duckdb-cli.php                       WP-CLI commands (only loaded under WP_CLI)
│   └── class-duckdb-admin.php                     Settings page + AJAX
├── admin/views/
│   └── settings.php                               Settings UI template
├── assets/
│   └── admin.js                                   AJAX handlers (test, sync, reprocess)
├── languages/
│   ├── mxchat-plus.pot                          Translation template
│   ├── mxchat-plus-fr_FR.po                     French translation source
│   └── mxchat-plus-fr_FR.mo                     French compiled catalog
├── tests/
│   ├── bootstrap.php                              PHPUnit bootstrap (WP function shims)
│   ├── phpstan-bootstrap.php                      PHPStan WP stubs
│   └── unit/                                      Smoke tests on pure-PHP utilities
├── patches/
│   └── README.md                                  Optional upstream patch (Option A)
├── docs/
│   ├── CONFIGURATION.md                           Every option, sidecar storage, change guards
│   ├── HOOKS.md                                   Every filter / action, with PHP examples
│   ├── CLI.md                                     Full WP-CLI reference
│   └── USAGE.md                                   Howtos: async, Pinecone, Parquet, INT8, health
├── ARCHITECTURE.md                                (this file)
├── CHANGELOG.md
├── CONTRIBUTING.md
└── README.md
```

## Design conventions

- **Single connection per request.** `MxChat_Plus_DuckDB_Connection_Factory::current()` is the only sanctioned way to obtain a backend handle; it caches one instance per request, keyed by mode + token fingerprint. Construct one by hand and you silently bypass the cache.
- **Schema changes are migrations.** Bump `MxChat_Plus_DuckDB_Vector_Store::TARGET_SCHEMA_VERSION` and add an `apply_migration()` branch. Migrations must be idempotent — they may run against an install that already has part of the target state.
- **Writes invalidate the cache.** `upsert()` and `delete_*()` flush the query cache themselves via `MxChat_Plus_DuckDB_Cache::flush_query_cache($bot_id)` (per-bot; pass no argument only for a genuine flush-all such as a full resync or Parquet import). Any new write path must do the same.
- **No HTTP for MotherDuck.** MotherDuck has no public SQL-execution REST API; all traffic goes through DuckDB's native protocol via `ATTACH 'md:…'`.
- **Errors are surfaced, not swallowed.** Use `error_log()` + the `last_error` option + the admin-notice transient instead of returning empty matches silently — except where graceful degradation is explicitly documented (FTS missing, HNSW unavailable).
