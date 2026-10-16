# Usage guides

In-depth howtos for the specialised workflows. The high-level README points here from its Quick Start.

- [Reprocess all posts](#reprocess-all-posts)
- [Async reprocess via Action Scheduler](#async-reprocess-via-action-scheduler)
- [Pinecone migration](#pinecone-migration)
- [Backups & cross-backend moves (Parquet)](#backups--cross-backend-moves-parquet)
- [INT8 quantization (experimental)](#int8-quantization-experimental)
- [Health endpoint](#health-endpoint)
- [Verification (end-to-end test)](#verification-end-to-end-test)

---

## Reprocess all posts

**MxChat → DuckDB / MotherDuck → Diagnostics → Reprocess all posts** (or `wp mxchat-plus duckdb reprocess`) walks published posts and pages and sends each one through MxChat's own ingestion pipeline — chunking, embedding, upsert — so the result matches what MxChat's own import would store:

- the text is built by MxChat's own assembler (`MxChat_Knowledge_Manager`, reached by reflection): WooCommerce price, SKU, categories, tags and custom tabs, the short description, ACF fields (and ACF PDFs when MxChat's *ACF PDF extraction* is on), synced patterns and WoodMart blocks. `the_content` is **not** run. When the assembler is unavailable, a fallback uses the title, the excerpt and the post content with shortcode brackets removed;
- the host filter `mxchat_before_process_post` applies, and `mxchat_plus_duckdb_post_content` still has the last word;
- the page title is passed along (MxChat 3.2.23+), and tag and per-page role restrictions are re-applied after storing;
- whether MxChat can embed at all is decided by its own `MxChat_Utils::embedding_preflight()`, so a Custom Provider site with no OpenAI key works, and a missing key or Base URL is refused before any post is sent.

It calls the embedding API configured in MxChat (OpenAI, Voyage, Gemini or a Custom Provider) and costs real money. **Media library files** (PDF, Word…) are not posts and are not reprocessed: re-import them with **MxChat → Knowledge → Import → Media**.

## Async reprocess via Action Scheduler

For large catalogs (5 k+ posts), the synchronous reprocess can hit PHP's `max_execution_time`. The async path enqueues one job per post in [Action Scheduler](https://actionscheduler.org/) — bundled with WooCommerce and many WP plugins; otherwise install the standalone Action Scheduler plugin.

### Trigger

The async path is WP-CLI only — there is no admin button for it:

```bash
wp mxchat-plus duckdb async-reprocess --post-types=post,page,product
```

The whole batch is refused up front, with MxChat's own reason, when MxChat cannot embed for the bot (no API key for the selected model, a Custom Provider without a Base URL) — rather than queueing one job per post that would each fail.

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

The Diagnostics progress bar belongs to the synchronous **Reprocess all posts** button; the async snapshot is read through `status()` or Action Scheduler's own screen.

### Cancel

```php
MxChat_Plus_DuckDB_Async_Reprocess::cancel_all();
```

Action Scheduler's per-action retry/failure inspection lives under **Tools → Scheduled Actions** — failed jobs surface the original exception message there.

---

## Pinecone migration

Moving an existing Pinecone deployment to DuckDB without re-paying for embeddings.

```bash
# The default namespace — Pinecone and MxChat name it '' (omit --namespace):
wp mxchat-plus duckdb migrate-from-pinecone \
    --api-key=pcsk_xxx \
    --host=my-index-abcd.svc.us-east1-aws.pinecone.io

# Every namespace the index reports, the default one first:
wp mxchat-plus duckdb migrate-from-pinecone --api-key=pcsk_xxx --host=… --all-namespaces

# One namespace of a MxChat 3.2.22+ document index, stored under a given bot:
wp mxchat-plus duckdb migrate-from-pinecone --api-key=pcsk_xxx --host=… \
    --index-type=document --namespace=sales --bot-id=sales
```

| Option | Meaning |
|---|---|
| `--namespace=<ns>` | Namespace to copy. Omit it for the default namespace (`''`, not `default`). |
| `--all-namespaces` | Copy every namespace `describe_index_stats` reports; a resumed run skips the finished ones. Not combinable with `--namespace`. |
| `--index-type=auto\|vector\|document` | `auto` (default) asks MxChat's own helpers whether the host is a document index (`MxChat_Pinecone_Documents`, 3.2.22+) and assumes `vector` otherwise. |
| `--bot-id=<id>` | Store every copied vector under this DuckDB bot id. |
| `--reset` | Forget a saved cursor and start from the beginning. |

### What it does

1. **Classic vector index**: pages `GET /vectors/list` (with `paginationToken`) to enumerate every id of the namespace, then fetches vectors and metadata in batches of 100 with `GET /vectors/fetch`, the ids **repeated** (`ids=a&ids=b`). Serverless Pinecone answers the `POST` form of those two endpoints with an empty or non-200 response, which is why the importer shipped before this release copied nothing there.
2. **Document index** (MxChat 3.2.22+): `POST /namespaces/{ns|__default__}/documents/list` and `/fetch`, with the `X-Pinecone-Api-Version` header MxChat uses, and reshapes the flat document fields into a vector + metadata.
3. Translates each vector into the plugin's DuckDB row through the same mapping the REST proxy uses (`Vector_Store::row_from_pinecone()`): text, source URL, type, chunk fields, role, title, `parent_url_hash`, `created_at` / `last_updated`, and every other metadata key into `extra_metadata`. A missing role is stored as `public`, the column default.
4. Puts the row under `--bot-id` when given, else the vector's own `metadata.bot_id` (MxChat writes it on every vector), else the namespace, else `default`.
5. Persists a resumption cursor after every batch. Ids listed but not returned count as failed, and a non-2xx or non-JSON answer stops the run with a hint about `--index-type`.

### What it does *not* do

- **No re-embedding.** Vectors are copied verbatim; cosine scores on the destination side will match what Pinecone produced.
- **No automatic backend switch.** After migration completes, you still need to flip `mode = 'embedded'` (or whichever target you migrated to) in the admin and run **Test connection**, and point MxChat at DuckDB (Default-bot routing, or Multi-Bot routing).

### After the import

Two things keep the copy and the source consistent while MxChat may still write to the real index:

- the nightly compactor leaves orphans alone while the import marker (`mxchat_plus_duckdb_pinecone_imported`) reports copied vectors (see [HOOKS.md](HOOKS.md#mxchat_plus_duckdb_compactor_prune_orphans));
- every successful delete or upsert MxChat sends to that Pinecone host is replayed into DuckDB — REST `DELETE /mxchat/v1/knowledge`, media auto-sync, trash and unpublish, kb-reimport included (filter `mxchat_plus_duckdb_mirrored_pinecone_host`, `''` turns it off). Deletes made in the Pinecone console are not seen.

### Resumption

If the network drops mid-migration, the next invocation of the same command picks up where it left off — the cursor, index type, finished namespaces and per-row counters are persisted to the `mxchat_plus_duckdb_pinecone_migration_state` option. A saved cursor is reused only for the same host, index type and namespace. The option is deleted on successful completion; `--reset` clears it by hand.

### Embedding dimension

The migration assumes the Pinecone index dimension matches `embedding_dim` in plugin settings. Mismatches raise `Embedding dimension mismatch on upsert: …` and stop the batch. Adjust `embedding_dim` before re-running, or wipe the DuckDB table and start fresh.

---

## Backups & cross-backend moves (Parquet)

DuckDB has native Parquet I/O. The plugin exposes it as both WP-CLI and a vector-store method.

### Backup

```bash
# Embedded backend → local file
wp mxchat-plus duckdb export --path=/var/backups/mxchat-$(date +%F).parquet

# MotherDuck backend → MotherDuck-attached storage (s3://… also works)
wp mxchat-plus duckdb export --path=s3://my-bucket/mxchat-backup.parquet
```

The Parquet file is portable: open it in `duckdb` CLI, inspect with `pandas` / `pyarrow`, ship it to cold storage. No proprietary format.

### Restore

```bash
wp mxchat-plus duckdb import --path=/var/backups/mxchat-2026-05-17.parquet
```

Existing vectors with the same `(bot_id, vector_id)` are **replaced**. Vectors present in the table but missing from the Parquet are **kept** (this is a restore, not a sync). For a full replace, drop the table first. Columns are matched by name, so a backup taken before schema v4 restores into the current layout.

### Cross-backend move

The most common use case: try out MotherDuck on a copy of your embedded data without re-embedding.

```bash
# 1. While in embedded mode:
wp mxchat-plus duckdb export --path=/tmp/mxchat.parquet

# 2. Switch backend to MotherDuck in MxChat → DuckDB / MotherDuck.

# 3. Confirm connectivity:
wp mxchat-plus duckdb test

# 4. Import:
wp mxchat-plus duckdb import --path=/tmp/mxchat.parquet
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

The plugin ships a unit test (`tests/unit/duckdb/QuantizationTest.php`) that verifies the cosine similarity between an original 1536-dim unit-normalised vector and its INT8 round-trip is > 0.9999. Run it:

```bash
vendor/bin/phpunit --filter Quantization
```

### Switching layout on an existing table

The layout is **locked** once rows exist (same constraint as `embedding_dim`). To switch:

```bash
# 1. Export
wp mxchat-plus duckdb export --path=/tmp/before.parquet

# 2. Drop the table manually:
#    duckdb> DROP TABLE mxchat_vectors; DROP TABLE mxchat_plus_duckdb_schema_meta;

# 3. Flip the option in plugin settings (or in the DB):
#    update_option('mxchat_plus_duckdb_options', array_merge(get_option('mxchat_plus_duckdb_options'), ['embedding_storage' => 'int8']))

# 4. Trigger schema rebuild
wp mxchat-plus duckdb test

# 5. Re-import
wp mxchat-plus duckdb import --path=/tmp/before.parquet
```

The import quantizes on the way in.

### SQL impact

INT8 queries inject a `list_transform(embedding, x -> x::FLOAT / 127.0)` into the score expression — a per-row transform over the rows scanned. The score MxChat receives is the cosine of the dequantised vector, as with `float32`.

---

## Health endpoint

```
GET /wp-json/mxchat-plus/v1/health
```

Returns `200 OK` + JSON when healthy, `503 Service Unavailable` + error JSON otherwise. Suitable for UptimeRobot / Pingdom / k6 probes.

```json
{
  "plugin_version": "1.0.0",
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
5. Run `SELECT bot_id, COUNT(*) FROM mxchat_vectors GROUP BY bot_id;` directly in DuckDB (CLI or MotherDuck UI) — count should match the number of synced/reprocessed entries.
6. Hit `/wp-json/mxchat-plus/v1/health` and confirm `ok: true`.
7. `wp mxchat-plus duckdb stats` — `searches > 0` and `p95_ms` looks reasonable.

If any step fails, the diagnostic panel's **Last error** + `wp mxchat-plus duckdb stats` are the right thing to paste into a bug report.
