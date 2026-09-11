# Local mirror for MotherDuck installs

The MotherDuck cloud doesn't support the VSS extension, so vector
similarity queries on a MotherDuck-backed install fall back to
brute-force `array_cosine_similarity` scans. That's workable up to
~100k vectors but quickly becomes a tail-latency problem beyond.

The **local mirror** maintains a `.duckdb` shadow on the same server
with HNSW indexed. MotherDuck stays the canonical write target;
reads route to the local mirror for HNSW acceleration. The wrapper
is transparent to callers (REST proxy, Option A filter, sync
pipelines) — they just see faster responses on the read path.

The architecture is described in detail in
[DESIGN-motherduck-mirror.md](DESIGN-motherduck-mirror.md). This file
is the operator guide.

---

## When to enable

Enable the mirror when **all** of the following hold:

- You run in MotherDuck mode (`mode = 'motherduck'` in plugin
  settings).
- Your vector catalogue is or will grow past ~100k entries.
- The server has room on disk for a second copy of the table
  (~6 GB per 1M × 1536-dim float32 vectors, plus the HNSW index).
- The server has room on disk for the Action Scheduler queue and
  cron infrastructure (already a hard dep on this plugin, so no
  new requirement).

Don't enable when:

- You're in `embedded` mode (the toggle is silently dropped with a
  settings_error in that case — there'd be nothing to mirror).
- The catalogue is small (< 50k entries). The brute-force MotherDuck
  scan is fast enough; the mirror adds writes-through overhead and
  disk usage with no observable latency win.
- The server's uploads directory is on a slow network mount and you
  can't override `motherduck_mirror_path` to a faster local disk.
  Mirror reads then become slower than MotherDuck.

---

## Enabling

### Via the admin UI

1. **MxChat → DuckDB / MotherDuck** in WP admin.
2. Scroll to the **MotherDuck** section.
3. Check **Local mirror — Maintain a local DuckDB shadow…**.
4. (Optional) Set **Mirror path** to a custom location. Empty value
   resolves to `<wp-content>/uploads/mxchat-plus-private/mirror.duckdb`.
5. **Save changes**.

The save handler enqueues an Action Scheduler bootstrap job. The
status panel below the toggle shows progress (current / target /
percentage). On a 100k-vector catalogue the bootstrap typically
completes in 2–5 minutes; on 1M vectors, expect 10–30 minutes
depending on network round-trip to MotherDuck.

### Via WP-CLI

```bash
# Toggle the option, then trigger the bootstrap manually:
wp option update mxchat_plus_duckdb_options '{"motherduck_mirror_enabled":true}' --format=json
wp mxchat-plus mirror-bootstrap          # enqueues the first tick
wp mxchat-plus mirror-bootstrap --step   # runs one tick synchronously (debug)
```

The `--step` flag runs a single batch inline, prints the resulting
`status / processed / target / done` line, and exits. Useful when
diagnosing a stuck bootstrap.

---

## What happens after enabling

1. **Bootstrap (`status: 'bootstrapping'`)**. An Action Scheduler
   worker walks the MotherDuck → local copy in batches of 1000
   vector_ids. Resumable via a persisted cursor (the last vector_id
   seen). Reads still route to MotherDuck during bootstrap — no
   stale-read window.

2. **Active (`status: 'active'`)**. Local count caught the target.
   The read path now serves HNSW-accelerated queries from local;
   writes go to MotherDuck (canonical) and then to local
   (best-effort, queued on failure).

3. **Daily drift check** runs at +12h after activation and every
   24h thereafter. Compares per-bot_id (count, vector_id-set hash)
   between primary and local. On divergence, status flips to
   `'drifted'` and an admin notice surfaces. The check is read-only
   and cheap (< 5s on 1M rows).

4. **Drain cron** (every 5 minutes) consumes the `mirror_pending`
   queue — writes that succeeded on MotherDuck but failed locally
   (typically transient disk/lock errors). Each entry is replayed;
   10 consecutive failures move it to quarantine and surface as an
   admin notice.

---

## Status states

| Status | Meaning | Action expected |
|---|---|---|
| `disabled` | Toggle off, or never enabled. | None. |
| `bootstrapping` | Initial copy in progress. | Wait for completion (~minutes). |
| `active` | Mirror is in sync. Reads come from local. | None. |
| `drifted` | Daily check found divergence. | `wp mxchat-plus mirror-bootstrap --reset`. |
| `error` | Bootstrap or drain failed repeatedly. | Inspect via CLI / `/health`. |

Read the status programmatically:

```php
$status = MxChat_Plus_DuckDB_Mirror_Bootstrap::get_status();
$state  = MxChat_Plus_DuckDB_Mirror_Bootstrap::get_state();
// $state has: started_at, target_count, processed_count,
// last_vector_id, last_error, completed_at
```

Or via the health endpoint:

```bash
curl https://your-site/wp-json/mxchat-plus/v1/health | jq '.mirror'
# {
#   "enabled": true,
#   "status": "active",
#   "pending_count": 0,
#   "quarantine_count": 0,
#   "drained_total": 142,
#   "quarantine_total": 0,
#   "last_drift_check_at": 1729450200,
#   "last_drift_check_age_s": 3600
# }
```

---

## WP-CLI reference

### `wp mxchat-plus mirror-bootstrap`

Trigger or step the initial MotherDuck → local copy.

```bash
wp mxchat-plus mirror-bootstrap            # enqueue first tick
wp mxchat-plus mirror-bootstrap --reset    # wipe state, restart from offset 0
wp mxchat-plus mirror-bootstrap --step     # run one tick inline (debug)
```

### `wp mxchat-plus mirror-drain`

Replay the pending queue immediately instead of waiting for the
5-minute cron tick.

```bash
wp mxchat-plus mirror-drain                # run one drain pass
wp mxchat-plus mirror-drain --status       # print counters without running
```

Output (after running):

```
Success: Drain: drained=12 retried=3 quarantined=0 remaining=0
```

### `wp mxchat-plus mirror-drift-check`

Compare primary and local per-bot_id, print a divergence report.

```bash
wp mxchat-plus mirror-drift-check
```

On a clean install:

```
Success: No drift detected. Primary and local agree on every bot_id.
```

On drift:

```
Warning: Drift on bot_ids=[bot_b]
 (real drift — run mirror-bootstrap --reset to re-converge, or investigate manually)
+--------+---------------+-------------+-------------+-----------+-------+
| bot_id | primary_count | local_count | primary_sig | local_sig | drift |
+--------+---------------+-------------+-------------+-----------+-------+
| bot_a  | 12345         | 12345       | abc12345    | abc12345  | -     |
| bot_b  | 999           | 998         | def54321    | xyz98765  | YES   |
+--------+---------------+-------------+-------------+-----------+-------+
```

---

## Troubleshooting

### Bootstrap stuck in `'error'`

1. `wp mxchat-plus mirror-bootstrap --step` to run a tick inline
   and see the raw error.
2. Common causes:
   - MotherDuck token expired or revoked → update in plugin
     settings; the bootstrap auto-retries on the next tick.
   - Local disk full → free space, or set `motherduck_mirror_path`
     to a larger disk.
   - File permissions on `uploads/` → make the directory writable
     by the web server.
3. After fixing the root cause: `wp mxchat-plus mirror-bootstrap`
   (no `--reset`) to resume from the persisted cursor.

### Status keeps flipping to `'drifted'`

1. `wp mxchat-plus mirror-drift-check` to see which bot_ids
   diverged.
2. If the count differs by ≤ 50 and `pending_count > 0` in the
   /health output, this is "drainable drift" — wait for the next
   5-minute drain tick and re-check.
3. If the divergence is real, run
   `wp mxchat-plus mirror-bootstrap --reset` to rebuild local
   from MotherDuck. Allow time proportional to the catalogue size.

### Quarantine count is growing

Entries land in quarantine after 10 consecutive replay failures.
The usual root causes:

- Disk full: `df -h` on the mirror path's filesystem. Free space
  or move the path.
- File permissions: the web server user must own (or have write
  access to) the mirror directory.
- Locked file: another DuckDB process holds the file. `lsof`
  the path to find the holder.

Once the root cause is fixed, the quarantine entries don't
auto-recover — they represent writes whose specific SQL the system
has given up replaying. The remediation is a full re-bootstrap:

```bash
wp mxchat-plus mirror-bootstrap --reset
```

### Reads are still slow after enabling

Check that `/health.mirror.status == 'active'`. If it's still
`'bootstrapping'`, the initial copy isn't done yet — reads route
to MotherDuck until it is. If it's `'error'`, see "Bootstrap stuck".

If `'active'` but reads are still slow:

- Verify HNSW is in place: `Vector_Store::current()->hnsw_available()`
  should return `true` (the admin UI shows this in the diagnostics
  section).
- Check the mirror disk's speed. Network mounts on `uploads/` can
  be slower than a single MotherDuck round-trip. Move
  `motherduck_mirror_path` to a local SSD.
- Profile via the slow-query log (`slow_query_ms` option, default
  500ms).

---

## Disabling

Toggle off in the admin UI, or:

```bash
wp option update mxchat_plus_duckdb_options '{"motherduck_mirror_enabled":false}' --format=json
```

Reads immediately resume against MotherDuck. The local `.duckdb`
file stays on disk — delete it manually if you want to reclaim the
space:

```bash
rm /path/to/wp-content/uploads/mxchat-plus-private/mirror.duckdb*
```

The mirror_pending queue, bootstrap state, and quarantine option
are cleared on plugin uninstall (the lifecycle is covered by
`uninstall.php` since v0.10.0). Disabling alone doesn't clear
them — that's intentional, so re-enabling later resumes from where
the previous run left off.

---

## Disk usage

| Vector count × dim | Float32 raw | HNSW overhead | Total mirror footprint |
|---|---|---|---|
| 100k × 1536          | ~600 MB | ~200 MB | ~800 MB |
| 1M × 1536            | ~6 GB | ~2 GB | ~8 GB |
| 10M × 1536           | ~60 GB | ~20 GB | ~80 GB |

`embedding_storage = 'int8'` (experimental, opt-in) divides the
raw size by 4× with ~1% recall loss on unit-normalised embeddings.

The HNSW index is rebuilt on every plugin update (or schema
migration) — budget time accordingly.

---

## Cost considerations

- **MotherDuck egress.** The initial bootstrap pulls every row over
  the wire once. MotherDuck egress pricing applies. For a 1M-vector
  catalogue at ~6 KB per row, that's ~6 GB. Pricing varies; check
  app.motherduck.com.
- **MotherDuck reads.** After bootstrap, only writes touch
  MotherDuck (canonical) plus the daily drift check (a small
  `SELECT bot_id, COUNT(*), md5(...)` per bot_id). No per-query
  cost is added by the mirror.
- **Local compute.** HNSW queries on the mirror cost CPU + memory
  on the WordPress server. Sizing depends on top_k and concurrency;
  a single PHP-FPM worker can typically sustain ~50 queries/second
  on a 1M-vector mirror with HNSW (vs ~5/second brute-force on
  MotherDuck).
