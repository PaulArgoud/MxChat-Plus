# WP-CLI reference

Every DuckDB operation is also exposed as a WP-CLI subcommand under **`wp mxchat-plus duckdb`**
(the prompt-cache module has its own `wp mxchat-plus promptcache` group). The CLI surface is
only loaded when `WP_CLI` is defined, so it adds zero runtime cost in non-CLI contexts.

```
wp mxchat-plus duckdb test
wp mxchat-plus duckdb stats
wp mxchat-plus duckdb sync            [--native]
wp mxchat-plus duckdb reprocess       [--post-types=<csv>] [--batch=<n>]
wp mxchat-plus duckdb async-reprocess [--post-types=<csv>] [--bot-id=<id>]
wp mxchat-plus duckdb compact         [--force]
wp mxchat-plus duckdb metrics         [--reset]
wp mxchat-plus duckdb cache           --flush
wp mxchat-plus duckdb export          --path=<file>
wp mxchat-plus duckdb import          --path=<file>
wp mxchat-plus duckdb migrate-from-pinecone --api-key=<key> --host=<host>
                                      [--namespace=<ns> | --all-namespaces]
                                      [--index-type=auto|vector|document] [--bot-id=<id>] [--reset]
wp mxchat-plus duckdb mirror-bootstrap   [--reset] [--step]
wp mxchat-plus duckdb mirror-drain       [--status]
wp mxchat-plus duckdb mirror-drift-check
```

The mirror commands are documented in [MIRROR.md](MIRROR.md#wp-cli-reference). Earlier versions of this
page, and the examples inside the command classes, wrote `wp mxchat-plus <cmd>` without the
`duckdb` group; the registered form has always been `wp mxchat-plus duckdb <cmd>`.

## Diagnostics

### `wp mxchat-plus duckdb test`

Pings the active backend and reports backend identity + vector count. Exits non-zero on connection failure — suitable for liveness probes.

```bash
$ wp mxchat-plus duckdb test
Backend: motherduck:my_db (ext)
Ping: OK
Vectors: 12483
Success: Backend healthy.
```

### `wp mxchat-plus duckdb stats`

Tabular dump of the most useful diagnostics in one paste. Include this in bug reports. `vectors` is the default bot's vector count.

```bash
$ wp mxchat-plus duckdb stats
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

### `wp mxchat-plus duckdb sync [--native]`

Full MySQL → DuckDB sync. Identical to the **Sync now** admin button. Shows a progress bar; idempotent thanks to the stable `vector_id` scheme. Copies the page title (MxChat 3.2.23+) too.

`--native` uses the DuckDB-native fast path (`ATTACH` to MySQL + a single `INSERT … SELECT`). It requires DuckDB's `mysql` extension, writes `row_key` itself and keeps one row per `(bot_id, vector_id)` when MySQL holds the same URL twice. If it fails, run the command without the flag.

### `wp mxchat-plus duckdb reprocess [--post-types=post,page,product] [--batch=10]`

Synchronous reprocess: walks the post types and re-runs MxChat's chunking + embedding pipeline against each, with the text MxChat's own importer would build (see [USAGE.md → Reprocess all posts](USAGE.md#reprocess-all-posts)). Blocking — runs to completion before returning, but uses the same batching as the admin AJAX path to keep memory in check. Calls the configured embedding API → **costs money**. Media library files are not posts and are not included.

```bash
$ wp mxchat-plus duckdb reprocess --post-types=post,page,product --batch=25
Reprocessing 100% [============================>] 5000/5000
Success: Reprocessed: 4983 processed, 17 failed.
```

### `wp mxchat-plus duckdb async-reprocess [--post-types=post,page] [--bot-id=default]`

Asynchronous variant: enqueues one Action Scheduler job per post and returns immediately. **Required for large catalogs** (5k+ posts) where the synchronous path hits PHP's `max_execution_time`. The batch is refused up front, with MxChat's own reason, when MxChat cannot embed for the bot (missing API key, Custom Provider without a Base URL). See [USAGE.md → Async reprocess](USAGE.md#async-reprocess-via-action-scheduler).

```bash
$ wp mxchat-plus duckdb async-reprocess --post-types=post,page
Success: Queued 4983 of 4983 posts. Action Scheduler will process them in the background. Run `wp action-scheduler run --group=mxchat-plus` to drain inline.
```

To drain the queue inline (no waiting for AS cron):

```bash
wp action-scheduler run --group=mxchat-plus --batches=10
```

## Maintenance

### `wp mxchat-plus duckdb compact [--force]`

Runs the daily orphan-vector compactor immediately. Deletes vectors whose `vector_id` no longer maps to any MySQL KB row, bot by bot. It is deliberately conservative:

- it never runs before a first MySQL sync, nor within an hour of the last one (the sync may still be in flight);
- it never prunes a bot MxChat writes through the Pinecone proxy (recorded in `mxchat_plus_duckdb_proxy_written_bots`); with the Multi-Bot add-on loaded, only `default` is prunable;
- it leaves orphans alone after a Pinecone import (see [HOOKS.md](HOOKS.md#mxchat_plus_duckdb_compactor_prune_orphans));
- it **refuses**, and records `last_error`, when the MySQL knowledge base is empty while prunable vectors exist, or when more than half the scanned vectors look orphaned ([`mxchat_plus_duckdb_compactor_max_orphan_ratio`](HOOKS.md#mxchat_plus_duckdb_compactor_max_orphan_ratio)).

`--force` overrides those two refusals for this run only; the proxy-written bots stay protected.

```bash
$ wp mxchat-plus duckdb compact
Warning: Skipped: refused to prune: 6120 of 9000 scanned vectors look orphaned (more than 50%). Run `wp mxchat-plus duckdb compact --force` if that is intended.
```

### `wp mxchat-plus duckdb metrics [--reset]`

Inspect the rolling 1-hour latency window + counters. Pass `--reset` to clear them — useful before running a benchmark.

```bash
$ wp mxchat-plus duckdb metrics
$ wp mxchat-plus duckdb metrics --reset
Success: Metrics reset.
```

### `wp mxchat-plus duckdb cache --flush`

Flushes every cached top-K (transient layer). Use after a bulk re-ingest if you don't want to wait for the TTL to expire. The `--flush` flag is required — running the command without it just prints a help line.

```bash
$ wp mxchat-plus duckdb cache --flush
Success: Flushed 1247 cached query results.
```

## Data lifecycle

### `wp mxchat-plus duckdb export --path=<file>`

Dumps every vector + metadata to a Parquet file via DuckDB's native `COPY ... TO`. Works against both backends.

```bash
$ wp mxchat-plus duckdb export --path=/tmp/mxchat-backup.parquet
Success: Exported 12483 vectors to /tmp/mxchat-backup.parquet.
```

### `wp mxchat-plus duckdb import --path=<file>`

Restores from a Parquet file produced by `export`. Existing vectors with the same `(bot_id, vector_id)` are **replaced** (`INSERT OR REPLACE`). Columns are matched **by name**, so a file exported before schema v4 (no `row_key`, no metadata columns) restores into the current layout; columns the file lacks are left empty and `row_key` is recomputed.

```bash
$ wp mxchat-plus duckdb import --path=/tmp/mxchat-backup.parquet
Success: Imported 12483 vectors from /tmp/mxchat-backup.parquet.
```

### `wp mxchat-plus duckdb migrate-from-pinecone --api-key=<key> --host=<host> [options]`

One-shot Pinecone → DuckDB copy, **no re-embedding**. Reads a classic vector index with `GET /vectors/list` + `GET /vectors/fetch` (repeated `ids=`), or a MxChat 3.2.22+ document index with `POST /namespaces/{ns}/documents/list` + `/fetch`. Resumable: state is persisted between batches so a network failure mid-run can be retried with the same command.

| Option | Meaning |
|---|---|
| `--namespace=<ns>` | Namespace to copy. **Omit it for the default namespace**, which Pinecone and MxChat name `''`, not `default`. |
| `--all-namespaces` | Every namespace `describe_index_stats` reports, the default one first. A resumed run skips finished namespaces. |
| `--index-type=auto\|vector\|document` | `auto` (default) asks MxChat (3.2.22+) whether the host is a document index and assumes `vector` otherwise. |
| `--bot-id=<id>` | Store every copied vector under this DuckDB bot id. Without it, a row lands under the vector's own `metadata.bot_id`, else the namespace, else `default`. |
| `--reset` | Forget a saved cursor and start from the beginning. |

```bash
$ wp mxchat-plus duckdb migrate-from-pinecone \
    --api-key=pcsk_xxx \
    --host=my-index-abcd.svc.us-east1-aws.pinecone.io
… copied 100 so far
… copied 200 so far
… copied 12483 so far
Success: Migration complete (vector index): 12483 copied, 0 failed.
```

Up to 1.0.0 this command sent `POST` to `/vectors/list` and `/vectors/fetch`, which serverless Pinecone answers with an empty or non-200 response, so it copied nothing there. See [USAGE.md → Pinecone migration](USAGE.md#pinecone-migration) for the full guide.

## Exit codes

Every subcommand exits 0 on success, non-zero on failure (WP-CLI's default behaviour). `wp mxchat-plus duckdb test` is the recommended liveness check for scripted deployments — wrap it in your healthcheck.
