# Backup & restore

The plugin doesn't add itself to your usual WordPress backup workflow — the
backend is either a `.duckdb` file on disk (Embedded mode) or remote tables
on MotherDuck (cloud mode). Neither shows up in `mysqldump`. This document
walks through the supported backup, verification, and restore flows.

## What needs to be backed up

| Item | Where | How |
|---|---|---|
| Vector data (the actual KB embeddings) | Embedded: `.duckdb` file on disk · MotherDuck: cloud tables | `wp mxchat-plus export --path=…` (Parquet) **or** filesystem copy of the `.duckdb` file (Embedded only) |
| Plugin settings | `wp_options` row `mxchat_plus_duckdb_options` (+ sidecars) | Normal MySQL backup picks it up |
| Pinecone-proxy auth tokens | `wp_options` rows `mxchat_plus_duckdb_proxy_token` and `mxchat_plus_duckdb_proxy_token_map` | Normal MySQL backup picks them up |
| Mirror state (MotherDuck installs with mirror enabled) | `.duckdb` file at the resolved mirror path + `wp_options` rows for the bootstrap cursor / pending queue | Mirror is regenerable from MotherDuck via `wp mxchat-plus mirror-bootstrap --reset`; backing it up is **optional** |

The MotherDuck token is the only secret that doesn't show up in a regular
WordPress backup if it lives in `wp-config.php` via the
`MXCHAT_PLUS_MOTHERDUCK_TOKEN` constant. Capture it from your secrets
manager separately.

## Backup — Parquet export (recommended)

Parquet is the portable format; it works across DuckDB versions, between
Embedded and MotherDuck, and across servers. The CLI command emits one
file containing every vector + metadata.

```bash
# One-off backup to a path of your choice:
wp mxchat-plus export --path=/var/backups/mxchat-vectors-$(date +%Y%m%d).parquet

# Verify the file is non-empty and roughly the size you expect:
ls -lh /var/backups/mxchat-vectors-*.parquet
```

The export streams from DuckDB directly via `COPY (SELECT …) TO '…' (FORMAT
PARQUET, COMPRESSION ZSTD)`, so it doesn't materialise the table in PHP
memory — it works on KBs of any size.

### Verifying the backup

```bash
# Use duckdb directly to inspect the file (any duckdb binary, any version).
# This should print the row count and a small sample of vector_ids.
duckdb -c "SELECT COUNT(*) AS rows, MIN(vector_id) AS first, MAX(vector_id) AS last FROM read_parquet('/var/backups/mxchat-vectors-YYYYMMDD.parquet')"
```

Expected output (counts will obviously differ):

```
┌─────────┬──────────────────────────────────┬──────────────────────────────────┐
│  rows   │              first               │               last               │
├─────────┼──────────────────────────────────┼──────────────────────────────────┤
│ 12 487  │ 0007f1d6c2ab14...                │ ffd2b3ce810094...                │
└─────────┴──────────────────────────────────┴──────────────────────────────────┘
```

A row count of zero on a non-empty install means the export ran but the table
was empty when it did — check `wp mxchat-plus stats` to confirm.

## Restore — Parquet import

```bash
# Drop the current contents and replace from the backup:
wp mxchat-plus import --path=/var/backups/mxchat-vectors-YYYYMMDD.parquet
```

Under the hood this creates a temporary view over the parquet file and
runs `INSERT OR REPLACE INTO <table> SELECT * FROM <view>`. Existing rows
with the same `vector_id` are overwritten; rows present in the current
table but absent from the backup remain. To get a clean replace, either:

1. Recreate the backend first (delete the `.duckdb` file for Embedded mode,
   or `DROP TABLE` on MotherDuck) and re-activate the plugin so it
   reprovisions the schema, then import; **or**
2. Run `wp mxchat-plus sync` after the import — incremental sync is
   idempotent and will reconcile against the MySQL KB if you keep that
   as the source of truth.

## Filesystem snapshot (Embedded backend only)

For Embedded mode you can also just copy the `.duckdb` file. This is
slightly faster than Parquet export and preserves the HNSW index (the
Parquet path rebuilds the index on import), but it locks you to a
DuckDB-version-compatible file format on restore.

```bash
# Where the file lives (default — overridden by the `embedded_path` option):
ls wp-content/uploads/mxchat-plus-private/store.duckdb

# Snapshot it while WP-cron is not running a sync. The .duckdb format is
# crash-safe (WAL-style), but a hot copy may miss the latest few writes —
# either back up shortly after a sync, or use cp + a transient WP-cron
# pause.
cp wp-content/uploads/mxchat-plus-private/store.duckdb \
   /var/backups/store-$(date +%Y%m%d).duckdb
```

To restore: stop traffic to the site, replace the file, restart. The plugin
will pick up the new file on its next request.

## Cross-environment move (Embedded ⇄ MotherDuck)

This is exactly the workflow Parquet was designed for. The same backup
file works on either side:

```bash
# On the source server (e.g. Embedded → MotherDuck migration):
wp mxchat-plus export --path=/tmp/mxchat-vectors.parquet
scp /tmp/mxchat-vectors.parquet user@destination:/tmp/

# On the destination server (now configured for MotherDuck):
wp mxchat-plus import --path=/tmp/mxchat-vectors.parquet
```

The embedding dimension, storage format (float32 vs int8), and table
schema must match across the two sides; if they don't, the import will
fail loudly. Adjust `embedding_dim` and `embedding_storage` in the
destination's plugin settings before running `import`.

## What gets reconstructed automatically

You **don't** need to back up:

- **HNSW indexes** — rebuilt on the next `ensure_schema()` call. Parquet
  import triggers this automatically; filesystem snapshot preserves them.
- **The query result cache** (`mxp_q_*` transients) — expire on TTL or get
  bumped to a fresh generation on every write. Backing them up would be a
  waste; they're stale in seconds.
- **Mirror state** (for MotherDuck installs with the mirror enabled) — the
  local mirror is fully regenerable from MotherDuck:
  ```bash
  wp mxchat-plus mirror-bootstrap --reset
  ```
  See [docs/MIRROR.md](MIRROR.md) for the bootstrap status flow.
- **Pinecone proxy tokens** — if `wp_options` is restored from a normal
  MySQL backup, the tokens come back automatically. If you start fresh,
  they're regenerated on the first request (and any cached client tokens
  on the mxchat side become invalid — you'll re-test the connection from
  the admin page).

## Disaster recovery checklist

Order of operations after a total loss of the WordPress install:

1. Restore WordPress + database (`wp_options` carries the plugin settings).
2. Reinstall the plugin (same version as the backup if possible — Parquet
   is portable across versions, but the schema-migration order assumes you
   land on a compatible snapshot).
3. Activate. The plugin recreates the table and HNSW index.
4. Either:
   - Run `wp mxchat-plus import --path=<backup>.parquet` to restore from
     the snapshot, **or**
   - Run `wp mxchat-plus sync` if MxChat's MySQL KB is still intact — the
     embeddings will be re-derived from the source content (free if you're
     re-using already-embedded rows; costs API calls if you ran the
     reprocess path instead).
5. Verify with `wp mxchat-plus stats` + `wp mxchat-plus test`.

For MotherDuck installs the equivalent of step 4 is trivial: MotherDuck
already has the data; just point the plugin at it and click **Test
connection**.
