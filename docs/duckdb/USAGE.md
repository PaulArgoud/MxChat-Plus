# Usage guides

In-depth howtos for the specialised workflows. The high-level README points here from its Quick Start.

- [Async reprocess via Action Scheduler](#async-reprocess-via-action-scheduler)
- [Pinecone migration](#pinecone-migration)
- [Backups & cross-backend moves (Parquet)](#backups--cross-backend-moves-parquet)
- [INT8 quantization (experimental)](#int8-quantization-experimental)
- [Health endpoint](#health-endpoint)
- [Verification (end-to-end test)](#verification-end-to-end-test)

---

## Async reprocess via Action Scheduler

For large catalogs (5 k+ posts), the synchronous reprocess can hit PHP's `max_execution_time`. The async path enqueues one job per post in [Action Scheduler](https://actionscheduler.org/) — bundled with WooCommerce and many WP plugins; otherwise install the standalone Action Scheduler plugin.

### Trigger

Either of:

```bash
# WP-CLI
wp mxchat-plus async-reprocess --post-types=post,page,product
```

or the **Reprocess all posts (async)** button in **MxChat → DuckDB / MotherDuck → Diagnostics**.

### Drain the queue

Action Scheduler runs its own cron every minute. To drain immediately (e.g. CI / staging):

```bash
wp action-scheduler run --group=mxchat-plus --batches=10
```

### Monitor progress

The plugin persists a job-wide snapshot at every step. Read it with:

```php
$status = MxChat_Plus_DuckDB_Async_Reprocess::status();
// → ['enqueued' => 4983, 'processed' => 3211, 'failed' => 12,
//    'pending' => 1760, 'percent' => 65, 'is_running' => true]
```

The admin Diagnostics panel renders the same data as a live progress bar.

### Cancel

```php
MxChat_Plus_DuckDB_Async_Reprocess::cancel_all();
```

Action Scheduler's per-action retry/failure inspection lives under **Tools → Scheduled Actions** — failed jobs surface the original exception message there.

---

## Pinecone migration

Moving an existing Pinecone deployment to DuckDB without re-paying for embeddings.

```bash
wp mxchat-plus migrate-from-pinecone \
    --api-key=pcsk_xxx \
    --host=my-index-abcd.svc.us-east1-aws.pinecone.io \
    --namespace=default
```

### What it does

1. Paginates `POST /vectors/list` (100 IDs per page) to enumerate every vector in the namespace.
2. For each page, fetches the vectors + metadata in batches of 100 via `POST /vectors/fetch`.
3. Translates each Pinecone vector into the plugin's DuckDB row shape, preserving `metadata.text|content`, `source_url|url`, `role_restriction`, `content_type|type`, `chunk_index`, `total_chunks`, `is_chunked`.
4. Issues `INSERT OR REPLACE` against the local DuckDB / MotherDuck.
5. Persists a resumption token after every batch.

### What it does *not* do

- **No re-embedding.** Vectors are copied verbatim; cosine scores on the destination side will match what Pinecone produced.
- **No metadata transformation.** Unrecognised metadata keys are silently dropped (Pinecone is schemaless; DuckDB isn't). If you depend on a custom metadata field, pre-process the Pinecone export before importing, or extend the schema migration.
- **No automatic backend switch.** After migration completes, you still need to flip `mode = 'embedded'` (or whichever target you migrated to) in the admin and run **Test connection**.

### Resumption

If the network drops mid-migration, the next invocation of the same command picks up where it left off — the pagination token + per-row counters are persisted to the `mxchat_plus_duckdb_pinecone_migration_state` option. The option is deleted on successful completion.

### Embedding dimension

The migration assumes the Pinecone index dimension matches `embedding_dim` in plugin settings. Mismatches raise `Embedding dimension mismatch on upsert: …` and stop the batch. Adjust `embedding_dim` before re-running, or wipe the DuckDB table and start fresh.

---

## Backups & cross-backend moves (Parquet)

DuckDB has native Parquet I/O. The plugin exposes it as both WP-CLI and a vector-store method.

### Backup

```bash
# Embedded backend → local file
wp mxchat-plus export --path=/var/backups/mxchat-$(date +%F).parquet

# MotherDuck backend → MotherDuck-attached storage (s3://… also works)
wp mxchat-plus export --path=s3://my-bucket/mxchat-backup.parquet
```

The Parquet file is portable: open it in `duckdb` CLI, inspect with `pandas` / `pyarrow`, ship it to cold storage. No proprietary format.

### Restore

```bash
wp mxchat-plus import --path=/var/backups/mxchat-2026-05-17.parquet
```

Existing vectors with the same `vector_id` are **replaced**. Vectors present in the table but missing from the Parquet are **kept** (this is a restore, not a sync). For a full replace, drop the table first.

### Cross-backend move

The most common use case: try out MotherDuck on a copy of your embedded data without re-embedding.

```bash
# 1. While in embedded mode:
wp mxchat-plus export --path=/tmp/mxchat.parquet

# 2. Switch backend to MotherDuck in MxChat → DuckDB / MotherDuck.

# 3. Confirm connectivity:
wp mxchat-plus test

# 4. Import:
wp mxchat-plus import --path=/tmp/mxchat.parquet
```

The Parquet file is a hard cut-off — anything ingested into the embedded backend after the export won't be in the MotherDuck copy. Run with low write traffic, or re-export after.

---

## INT8 quantization (experimental)

Set `embedding_storage = 'int8'` in plugin settings **before any data is ingested**. The embedding column is then `TINYINT[N]` (1 byte per component) instead of `FLOAT[N]` (4 bytes) — a 4× storage saving.

### When to use it

- You're paying for MotherDuck storage and your vector count is in the millions.
- Your embedding model produces unit-normalised vectors. Mainstream models all do (OpenAI `ada-002` / `text-embedding-3-*`, Voyage, BGE, Cohere embed-v3, Gemini-embedding-001).
- You're OK with a < 1 % recall loss in exchange.

### When *not* to use it

- Your vectors aren't roughly unit-normalised. The fixed scale of ±127 clips anything outside [-1, 1].
- You need exact-replay reproducibility against a Pinecone baseline.
- You're not in production yet — quantization is opt-in for a reason.

### Round-trip verification

The plugin ships a unit test (`tests/unit/QuantizationTest.php`) that verifies the cosine similarity between an original 1536-dim unit-normalised vector and its INT8 round-trip is > 0.9999. Run it:

```bash
vendor/bin/phpunit --filter Quantization
```

### Switching layout on an existing table

The layout is **locked** once rows exist (same constraint as `embedding_dim`). To switch:

```bash
# 1. Export
wp mxchat-plus export --path=/tmp/before.parquet

# 2. Drop the table manually:
#    duckdb> DROP TABLE mxchat_vectors; DROP TABLE mxchat_plus_duckdb_schema_meta;

# 3. Flip the option in plugin settings (or in the DB):
#    update_option('mxchat_plus_duckdb_options', array_merge(get_option('mxchat_plus_duckdb_options'), ['embedding_storage' => 'int8']))

# 4. Trigger schema rebuild
wp mxchat-plus test

# 5. Re-import
wp mxchat-plus import --path=/tmp/before.parquet
```

The import quantizes on the way in.

### SQL impact

INT8 queries inject a `list_transform(embedding, x -> x::FLOAT / 127.0)` into the score expression. That's a per-row scan transform. On HNSW-indexed queries the transform runs *after* the index lookup, so the overhead is bounded to top_k × dim multiplications — negligible.

---

## Health endpoint

```
GET /wp-json/mxchat-plus/v1/health
```

Returns `200 OK` + JSON when healthy, `503 Service Unavailable` + error JSON otherwise. Suitable for UptimeRobot / Pingdom / k6 probes.

```json
{
  "plugin_version": "0.6.0",
  "enabled": true,
  "mode": "motherduck",
  "ext_loaded": true,
  "backend": "motherduck:my_db (ext)",
  "ping": true,
  "count": 12483,
  "last_sync_at": 1763375896,
  "last_sync_age_s": 1247,
  "last_error": "",
  "metrics": {
    "searches": 8421,
    "p50_ms": 38, "p95_ms": 124, "p99_ms": 287,
    "sample_count": 421,
    "cache_hits": 3470,
    "cache_hit_rate": 0.412,
    "errors": 0,
    "window_seconds": 3600
  },
  "ok": true,
  "status": "healthy"
}
```

**Public by default.** The payload only exposes aggregate counts, never vector content. To require authentication:

```php
add_filter('mxchat_plus_duckdb_health_public', '__return_false'); // require manage_options
```

When the plugin is disabled (`enabled: false`), the endpoint returns `200 OK` with `status: "disabled"` so it doesn't fail noisy. When the backend is unreachable, `503` + `status: "ping_failed"`.

---

## Verification (end-to-end test)

After install, run through this checklist on a staging WordPress with MxChat active:

1. Activate the plugin, set the backend, **Test connection** → ✅.
2. **Reprocess all posts** → wait for progress bar to reach 100 %.
3. Ask the chatbot a question that matches your KB content.
4. Inspect `wp-content/debug.log` (with `WP_DEBUG_LOG` on): you should see DuckDB queries; you should **not** see successful Pinecone API responses.
5. Run `SELECT COUNT(*) FROM mxchat_vectors;` directly in DuckDB (CLI or MotherDuck UI) — count should match the number of synced/reprocessed entries.
6. Hit `/wp-json/mxchat-plus/v1/health` and confirm `ok: true`.
7. `wp mxchat-plus stats` — `searches > 0` and `p95_ms` looks reasonable.

If any step fails, the diagnostic panel's **Last error** + `wp mxchat-plus stats` are the right thing to paste into a bug report.
