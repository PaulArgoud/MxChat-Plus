# WP-CLI reference

Every plugin operation is also exposed as a WP-CLI subcommand under `wp mxchat-plus`. The CLI surface is only loaded when `WP_CLI` is defined, so it adds zero runtime cost in non-CLI contexts.

```
wp mxchat-plus test
wp mxchat-plus stats
wp mxchat-plus sync
wp mxchat-plus reprocess        [--post-types=<csv>] [--batch=<n>]
wp mxchat-plus async-reprocess  [--post-types=<csv>] [--bot-id=<id>]
wp mxchat-plus compact
wp mxchat-plus metrics          [--reset]
wp mxchat-plus cache            --flush
wp mxchat-plus export           --path=<file>
wp mxchat-plus import           --path=<file>
wp mxchat-plus migrate-from-pinecone --api-key=<key> --host=<host> [--namespace=<ns>]
```

## Diagnostics

### `wp mxchat-plus test`

Pings the active backend and reports backend identity + vector count. Exits non-zero on connection failure — suitable for liveness probes.

```bash
$ wp mxchat-plus test
Backend: motherduck:my_db (ext)
Ping: OK
Vectors: 12483
Success: Backend healthy.
```

### `wp mxchat-plus stats`

Tabular dump of the most useful diagnostics in one paste. Include this in bug reports.

```bash
$ wp mxchat-plus stats
+----------------------+--------------------------+
| key                  | value                    |
+----------------------+--------------------------+
| enabled              | yes                      |
| mode                 | motherduck               |
| embedding_dim        | 1536                     |
| vectors              | 12483                    |
| searches             | 8421                     |
| cache_hit_rate       | 0.412                    |
| p50_ms               | 38                       |
| p95_ms               | 124                      |
| p99_ms               | 287                      |
| last_sync_at         | 2026-05-17T12:34:56+00:00 |
| last_compact_at      | 2026-05-17T03:17:00+00:00 |
| last_error           | -                        |
+----------------------+--------------------------+
```

## Ingestion

### `wp mxchat-plus sync`

Full MySQL → DuckDB sync. Identical to the **Sync now** admin button. Shows a progress bar; idempotent thanks to the stable `vector_id` scheme.

### `wp mxchat-plus reprocess [--post-types=post,page,product] [--batch=10]`

Synchronous reprocess: walks the post types and re-runs MxChat's chunking + embedding pipeline against each. Blocking — runs to completion before returning, but uses the same batching as the admin AJAX path to keep memory in check. Calls the configured embedding API → **costs money**.

```bash
$ wp mxchat-plus reprocess --post-types=post,page,product --batch=25
Reprocessing 100% [============================>] 5000/5000
Success: Reprocessed: 4983 processed, 17 failed.
```

### `wp mxchat-plus async-reprocess [--post-types=post,page] [--bot-id=default]`

Asynchronous variant: enqueues one Action Scheduler job per post and returns immediately. **Required for large catalogs** (5k+ posts) where the synchronous path hits PHP's `max_execution_time`. See [USAGE.md → Async reprocess](USAGE.md#async-reprocess-via-action-scheduler).

```bash
$ wp mxchat-plus async-reprocess --post-types=post,page
Success: Queued 4983 of 4983 posts. Action Scheduler will process them in the background.
  Run `wp action-scheduler run --group=mxchat-plus` to drain inline.
```

To drain the queue inline (no waiting for AS cron):

```bash
wp action-scheduler run --group=mxchat-plus --batches=10
```

## Maintenance

### `wp mxchat-plus compact`

Runs the daily orphan-vector compactor immediately. Deletes vectors whose `vector_id` no longer maps to any MySQL KB row. Skipped if the last sync was within the past hour (the sync may still be in flight).

### `wp mxchat-plus metrics [--reset]`

Inspect the rolling 1-hour latency window + counters. Pass `--reset` to clear them — useful before running a benchmark.

```bash
$ wp mxchat-plus metrics
$ wp mxchat-plus metrics --reset
Success: Metrics reset.
```

### `wp mxchat-plus cache --flush`

Flushes every cached top-K (transient layer). Use after a bulk re-ingest if you don't want to wait for the TTL to expire. The `--flush` flag is required — running the command without it just prints a help line.

```bash
$ wp mxchat-plus cache --flush
Success: Flushed 1247 cached query results.
```

## Data lifecycle

### `wp mxchat-plus export --path=<file>`

Dumps every vector + metadata to a Parquet file via DuckDB's native `COPY ... TO`. Works against both backends.

```bash
$ wp mxchat-plus export --path=/tmp/mxchat-backup.parquet
Success: Exported 12483 vectors to /tmp/mxchat-backup.parquet.
```

### `wp mxchat-plus import --path=<file>`

Restores from a Parquet file produced by `export`. Existing vectors with the same `vector_id` are **replaced** (`INSERT OR REPLACE`). The Parquet schema must match — we don't try to remap columns.

```bash
$ wp mxchat-plus import --path=/tmp/mxchat-backup.parquet
Success: Imported 12483 vectors from /tmp/mxchat-backup.parquet.
```

### `wp mxchat-plus migrate-from-pinecone --api-key=<key> --host=<host> [--namespace=<ns>]`

One-shot Pinecone → DuckDB copy. Paginates `/vectors/list`, batches `/vectors/fetch`, writes straight to DuckDB. **No re-embedding** — pure vector copy. Resumable: state is persisted between batches so a network failure mid-run can be retried with the same command.

```bash
$ wp mxchat-plus migrate-from-pinecone \
    --api-key=pcsk_xxx \
    --host=my-index-abcd.svc.us-east1-aws.pinecone.io \
    --namespace=default
… copied 100 so far
… copied 200 so far
… copied 12483 so far
Success: Migration complete: 12483 copied, 0 failed.
```

See [USAGE.md → Pinecone migration](USAGE.md#pinecone-migration) for the full guide.

## Exit codes

Every subcommand exits 0 on success, non-zero on failure (WP-CLI's default behaviour). `wp mxchat-plus test` is the recommended liveness check for scripted deployments — wrap it in your healthcheck.
