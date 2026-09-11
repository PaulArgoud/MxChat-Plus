# Design: local mirror for MotherDuck installs

**Status:** draft — pre-implementation. Awaiting review before any code is
written. **Target release:** 0.10.0 (tentative).

**Decision summary (TL;DR):**

- **Consistency model**: synchronous write-through, MotherDuck-first.
- **Bootstrap**: opt-in admin button → Action Scheduler job that copies
  rows via ATTACH + INSERT INTO local SELECT from md, resumable.
- **Race conditions**: rely on DuckDB's file lock + the existing
  retry-with-backoff logic; extend `is_transient_error()` to recognise
  DuckDB lock errors.
- **Drift detection**: daily cron compares `(COUNT(*), SUM(hash(vector_id)))`
  per `bot_id`. Divergence triggers a reconciliation job that re-bootstraps.
- **Partial failure**: MotherDuck-only writes that fail to mirror enqueue
  the vector_ids into a `mirror_pending` queue drained by Action Scheduler.

These five defaults are the answers to the five questions raised when we
diagnosed the MotherDuck HNSW limitation in 0.9.0. Each is explained in
detail below with the alternatives we considered and rejected.

---

## Why this design exists

MotherDuck does not support the VSS extension cloud-side
([source](https://motherduck.com/docs/concepts/duckdb-extensions/)). The
plugin currently degrades to brute-force `array_cosine_similarity` scans
on MotherDuck-backed installs (with a clean admin notice as of 0.9.0).
This is **workable** up to ~100k vectors but quickly becomes a tail-
latency problem beyond.

The local-mirror feature gives MotherDuck users HNSW acceleration
*without giving up MotherDuck's durability + multi-server access*. The
canonical write path stays on MotherDuck; a local `.duckdb` shadow is
maintained on the same server and serves reads with HNSW.

## Goals

1. **Sub-100ms top-K queries** for catalogs of 100k-1M vectors on
   MotherDuck-backed installs (vs current ~500ms-3s brute-force).
2. **Same Pinecone-shape API** to callers (REST proxy, Option A filter).
   Mirror is invisible: callers ask `Vector_Store::query_pinecone_shape()`
   and get the same result, just faster.
3. **Durability anchored on MotherDuck.** A local-file corruption or a
   server replacement does not lose data.
4. **Opt-in.** Existing MotherDuck installs see no change unless they
   toggle `motherduck_mirror_enabled`.
5. **Graceful degradation.** If the mirror is unreachable (disk full,
   file corruption, locking timeout), reads transparently fall back to
   MotherDuck. Plugin keeps working.

## Non-goals

- **Replacing MotherDuck.** The mirror is a read accelerator, not a
  primary store. It must not become a single point of failure.
- **Cross-server replication.** Multi-server setups still need
  MotherDuck for shared state. Each server has its own local mirror.
- **Write acceleration.** Writes still pay the MotherDuck network cost
  (plus the local mirror cost). Optimising hot-path writes is out of
  scope; the plugin's writes are batched sync operations, not
  user-interactive.
- **Sub-second cold-start.** The mirror is allowed to take seconds to
  open on the first request after a PHP-FPM restart. We're optimising
  warm-path latency, not cold-start.

---

## Current state (0.9.0)

The plugin has one connection per request via `Connection_Factory`. For
MotherDuck mode, that's a `MxChat_Plus_DuckDB_MotherDuck_Connection` (which
wraps an embedded DuckDB process that ATTACHes the cloud database).

Read path: `Vector_Store_Query::run()` → `Connection::execute(SELECT…)`
→ MotherDuck cloud-side scan (no VSS pushdown). Latency dominated by
network + brute-force.

Write path: `Vector_Store::upsert()` → batched `INSERT OR REPLACE`
through the same connection. Latency dominated by network + DuckDB
processing.

**Diagram (current):**

```
┌──────────────┐  query/upsert  ┌──────────────────┐
│ Caller       │ ─────────────▶ │ Vector_Store     │
│ (REST proxy, │                │  → Schema        │
│ Option A,    │                │  → Query         │
│ CLI, admin)  │                │  → Connection    │
└──────────────┘                └────────┬─────────┘
                                         │ SQL via DuckDB protocol
                                         ▼
                                ┌──────────────────┐
                                │  MotherDuck      │
                                │  (cloud)         │
                                └──────────────────┘
```

---

## Proposed architecture (0.10.0)

Introduce a new `MxChat_Plus_DuckDB_Mirrored_Connection` that **wraps two
connections** (primary = MotherDuck, mirror = local embedded `.duckdb`)
and routes SQL based on a static read/write classification:

```
┌──────────────┐  query/upsert  ┌────────────────────────────┐
│ Caller       │ ─────────────▶ │ Vector_Store               │
└──────────────┘                │  → Schema                  │
                                │  → Query                   │
                                │  → MIRRORED CONNECTION ───┐│
                                └───────────────────────────┼┘
                                                            │
                          ┌─────────────────────────────────┘
                          │
            ┌─────────────┴──────────────┐
            │ Mirrored_Connection        │
            │  - reads → local           │
            │  - writes → primary, then  │
            │    fan out to local        │
            │  - schema → both           │
            │  - on local failure:       │
            │    queue mirror_pending    │
            └──┬─────────────────────┬───┘
               │ read SQL            │ write SQL
               ▼                     ▼ (both, sequenced)
       ┌──────────────┐      ┌──────────────────┐
       │ Local DuckDB │      │  MotherDuck      │
       │ (.duckdb +   │      │  (cloud)         │
       │  HNSW)       │      │  ← writes first  │
       │  ← reads     │      │                  │
       └──────────────┘      └──────────────────┘
```

**Why this shape:**

- The capability interface from 0.9.0 already lets Schema decide whether
  to create HNSW. On the mirror connection: capability check on the
  *local* side (returns true → HNSW created), but the primary side stays
  capability-aware too (MotherDuck refuses, schema migration handles).
- The `MxChat_Plus_DuckDB_Connection` interface stays unchanged. Callers
  don't need to know the connection is mirrored. The wrapper *is* a
  Connection.
- Reads go straight to local; writes fan out. The Schema/Query split
  from 0.5 already separates these concerns, so this is a connection-
  level wiring change, not a higher-level refactor.

---

## Question 1 — Consistency model

### Options considered

| | A. Sync write-through (MD-first) | B. Sync write-through with rollback | C. Write-behind async | D. Local-first + periodic sync |
|---|---|---|---|---|
| Write latency | 2× | 2× | 1× | 1× |
| Read freshness | Immediate | Immediate | Eventually consistent | Up to sync interval |
| Failure complexity | Low (one canonical) | High (rollback semantics) | Medium (queue + retry) | Low |
| Reasoning effort | Low | Medium | High | Medium |

### Decision: **A. Synchronous write-through, MotherDuck-first**

Sequence per write:
1. Execute write on **MotherDuck**. If it fails, the whole upsert call
   fails — caller sees the error, no partial mirror state.
2. On success, attempt the same write on **local**. If that fails,
   queue the vector_ids to `mirror_pending` and log; the user-facing
   call still returns success.

Why this default:
- **Reasoning is local.** "Did the call succeed?" = "Did MotherDuck
  accept the write?" The mirror is a cache, not part of the durability
  contract.
- **No rollback semantics needed.** INSERT OR REPLACE on DuckDB is
  atomic per statement. There's no half-applied state to undo.
- **Latency cost is acceptable for batched writes.** The plugin's
  writes are bulk sync operations (full_sync, post-reprocess,
  Pinecone migration), not user-interactive. Adding ~10-30ms for a
  local INSERT to each batch is invisible.
- **Reads are the optimisation target**, and they stay 1× latency
  against the fast local store.

What's explicitly rejected:
- **B (rollback)** is overengineering. We'd need snapshots of the
  pre-write state, which DuckDB doesn't trivially expose, and the
  failure scenarios B addresses are the same ones A handles via the
  `mirror_pending` queue + reconciliation.
- **C (write-behind)** trades read freshness for write speed. For
  this plugin's workload (writes are batched + admin-initiated, reads
  are user-facing), that trade is the wrong way around.
- **D (local-first)** decouples writes from MotherDuck — but if the
  next sync hasn't run, MotherDuck is stale for any *other* server
  sharing the database. Defeats the multi-server use case.

### What this means for the code

- `Mirrored_Connection::execute(string $sql, array $params)` classifies
  the SQL as read or write:
  - **Read** (SELECT / WITH / PRAGMA / SHOW / DESCRIBE / EXPLAIN): route
    to `local` only.
  - **Write** (everything else): execute on `primary`, then on `local`,
    catch local errors and enqueue.
- The classification reuses `Embedded_Connection::looks_idempotent()`
  (already there for the retry logic).

---

## Question 2 — Bootstrap

### The problem

Activating the mirror on an install with 50k existing vectors in
MotherDuck: how does the local get populated?

### Options considered

| | A. Inline at toggle-save | B. Lazy on first query | C. Action Scheduler one-shot | D. Stream via CLI sync command |
|---|---|---|---|---|
| User experience | Admin form hangs for minutes | First query is slow | Background; UI shows progress | Manual CLI invocation |
| Failure recovery | Have to redo on retry | Same lazy retry next query | Resumable via state option | Manual re-run |
| Concurrency safety | One save = one bootstrap | First request wins; others wait | Action Scheduler dedup | One process |

### Decision: **C. Action Scheduler one-shot, resumable**

When `motherduck_mirror_enabled` is toggled on and the local is empty
(or substantially behind):

1. Settings save handler enqueues
   `MxChat_Plus_DuckDB_Mirror_Bootstrap::queue_initial()`. Returns
   immediately — admin form doesn't block.
2. Action Scheduler worker picks it up and runs:
   ```sql
   INSERT INTO local_table
   SELECT * FROM motherduck_table
   ORDER BY vector_id
   LIMIT 1000 OFFSET <persisted_offset>
   ```
   Loop until `SELECT COUNT(*)` on local matches the recorded total.
3. State stored in `mxchat_plus_duckdb_mirror_bootstrap_state` option:
   `{started_at, target_count, processed_count, current_offset, status}`.
4. On completion: `motherduck_mirror_status = 'active'`. The mirror
   wrapper starts routing reads to local.

Until bootstrap completes, the mirror is in `'bootstrapping'` status
and reads still go to MotherDuck. **No window where the mirror serves
stale data.**

Why this default:
- **Survives PHP timeouts.** Action Scheduler is already a hard
  dependency (used by `async_reprocess`).
- **Resumable.** A crash mid-bootstrap just resumes from the persisted
  offset. The ORDER BY + INSERT OR REPLACE makes the bootstrap
  idempotent.
- **Admin UI gets progress.** The state option drives a progress
  indicator on the settings page.

What's explicitly rejected:
- **A (inline)** doesn't work past ~10 seconds — wp-admin will time
  out, leaving the mirror half-populated.
- **B (lazy)** means the first user query after activation triggers
  a 30+ second wait. Bad UX.
- **D (CLI-only)** is fine as an admin override, but most users won't
  reach for the CLI. We'll provide it as `wp mxchat-plus mirror-bootstrap`
  in parallel for ops use.

### Edge cases

- **MotherDuck is unreachable during bootstrap.** The Action Scheduler
  job catches the error, status flips to `'error'` with a `last_error`
  message, retry next run.
- **Local disk runs out.** Same path — status `'error'`, surface in
  admin notice with a "free up X MB or change `motherduck_mirror_path`"
  hint.
- **Embedding dim changed mid-bootstrap.** The schema check on the
  local side will reject mismatched vectors. Bootstrap status flips to
  error; user has to clear local and restart.

---

## Question 3 — Race conditions on the local file

### The problem

PHP-FPM workers handle requests concurrently. Each worker that wants
to write to the local `.duckdb` file needs an exclusive lock. DuckDB
acquires an OS file lock on `open()`; a second concurrent `open()`
either waits or fails.

### Options considered

| | A. Rely on DuckDB's file lock + retry | B. PHP-side mutex (flock) | C. Dedicated writer worker (Action Scheduler) | D. WAL mode + multi-reader |
|---|---|---|---|---|
| Complexity | Low (lean on DuckDB) | Medium (correctness around stale locks) | High (queue every write) | Depends on DuckDB version |
| Throughput | Limited by lock | Same | Highest (no contention) | Best |
| Failure modes | Lock timeout → retry | Stale flock files | Queue backlog under load | Crash-recovery quirks |

### Decision: **A. DuckDB file lock + retry, with `is_transient_error()` extended**

DuckDB serialises writes via the OS file lock. Concurrent workers
opening the same file is a normal operation — the second waits a few
ms for the first to finish.

Our existing retry-with-backoff (`Embedded_Connection::execute_with_retry`)
already handles transient errors. We extend `is_transient_error()` to
recognise DuckDB-specific lock errors:

- `"could not acquire lock"`
- `"file is locked by another process"`
- `"WAL recovery failed"` (transient on crash recovery)

The retry budget (default 3 attempts, exponential backoff 50ms /
150ms / 350ms with jitter) is enough for normal write contention. If
all retries exhaust, the error surfaces — but at that point we're
several hundred ms into a degenerate state and surfacing is correct.

Why this default:
- **Zero new infrastructure.** Reuses what 0.6.0 already shipped.
- **Crash-safe.** DuckDB's WAL handles partial writes; we don't have
  to implement a journaling layer.
- **Bounded latency.** Worst case is 50+150+350 = 550ms before failing,
  versus seconds in a queue-backed system.

What's explicitly rejected:
- **B (PHP flock)** adds a second locking layer that has to stay in
  sync with DuckDB's own. Stale `.lock` files on PHP crashes are a
  classic source of "the plugin won't write anymore" support tickets.
- **C (dedicated writer)** decouples but adds latency for all writes,
  which we already decided not to optimise (Question 1's read-priority
  rationale). Also re-introduces the queue-backlog risk we just rejected.
- **D (multi-reader WAL)** is appealing but DuckDB's multi-reader story
  is still moving (WAL mode caveats around custom indexes per the VSS
  docs — same source as our HNSW limitation). Wait for DuckDB to
  stabilise this.

---

## Question 4 — Drift detection

### The problem

The mirror can diverge from MotherDuck silently:
- A `mirror_pending` write fails reconciliation N times and gets stuck.
- An admin does a direct SQL operation on MotherDuck outside the plugin
  (drops a row, manual UPDATE).
- A backup/restore is performed on one side only.
- A multi-server setup where server B's writes don't replicate to
  server A's mirror until A explicitly pulls.

We need to detect drift, surface it, and fix it.

### Options considered

| | A. Full row hash diff | B. Count + ID-set hash per bot_id | C. Watermark column (updated_at) | D. No detection, trust the queue |
|---|---|---|---|---|
| Detection completeness | Perfect | Counts + ID set | Misses non-monotonic edits | Misses external edits |
| Cost on a 1M-row table | Minutes | Seconds | Seconds | Free |
| What it misses | Nothing | Per-row metadata edits | External direct edits | Most |

### Decision: **B. Count + ID-set hash per bot_id, daily**

A new cron job `mxchat_plus_duckdb_mirror_drift_check`:

```sql
-- Run identical query against both connections:
SELECT bot_id,
       COUNT(*) AS c,
       md5(string_agg(vector_id, ',' ORDER BY vector_id)) AS sig
FROM mxchat_vectors
GROUP BY bot_id;
```

If `(c, sig)` differs for any `bot_id`, drift is detected. Outcomes:

- **Counts differ by a small number (≤ 50) AND `mirror_pending` is
  non-empty**: drain the pending queue first. Re-check.
- **Counts differ by a large number OR signature mismatch**: queue a
  full re-bootstrap of the affected `bot_id` partition. State stored
  separately from the global bootstrap state so partial recovery
  doesn't reset all bots.

Why this default:
- **Cheap.** `string_agg + md5` runs in seconds even on 1M rows. Daily
  is more than enough — drift events are bounded by `mirror_pending`
  failures, which we already log.
- **Catches the cases we care about.** Missing rows, extra rows, and
  ID renames all change the signature. Edits to embedding values
  without changing vector_id are *not* detected — accepted, because
  that's a non-plugin-driven edit and an admin notice tells the user
  to re-run bootstrap if they've done external work.
- **Per-bot_id granularity** keeps recovery scoped. Drifted bot A
  doesn't force re-bootstrap of bot B's 500k vectors.

What's explicitly rejected:
- **A (full row hash)** is overkill for daily cron. We can offer it
  as `wp mxchat-plus mirror-deep-check` for ops use.
- **C (watermark)** would need an `updated_at` column on every row,
  which we have, but doesn't catch deletions. Not enough.
- **D (no detection)** is what the v1 of this feature would look like
  if we wanted to ship faster — but the support cost of "my mirror is
  off, how do I fix it?" is too high.

---

## Question 5 — Partial transaction failure

### The problem

`upsert([50 vectors])` succeeds on MotherDuck, fails on local. What happens?

### Decision: **Queue to `mirror_pending`, return success**

- The user-facing API returns success because MotherDuck accepted the
  write — that's the canonical store.
- The 50 vector_ids are written to `mxchat_plus_duckdb_mirror_pending`
  option (an array of `{vector_id, queued_at, retries}` entries).
- A 5-minute cron `mxchat_plus_duckdb_mirror_drain` walks the queue,
  re-fetches each batch from MotherDuck via
  `SELECT * FROM motherduck_table WHERE vector_id IN (...)` and
  re-applies to local.
- After 5 successful drains, the entry is removed.
- After 10 failed attempts, the entry is moved to
  `mxchat_plus_duckdb_mirror_quarantine` and an admin notice fires.

State option shape:

```php
[
    'pending' => [
        ['vector_id' => 'abc123', 'queued_at' => 1715000000, 'retries' => 0],
        ...
    ],
    'quarantine' => [
        ['vector_id' => 'broken_xyz', 'queued_at' => 1714000000, 'retries' => 10,
         'last_error' => 'disk full'],
    ],
    'drained_total' => 12345,
    'quarantine_total' => 3,
]
```

Quarantine entries are surfaced in:
- The `/health` REST endpoint as a `mirror_quarantine_count` field.
- An admin notice (only when `count > 0` to avoid noise).

Why this default:
- **Bounded retry.** Doesn't loop forever on a permanent failure.
- **Visible.** Operators see the quarantine count and can intervene.
- **Idempotent.** INSERT OR REPLACE on retry means repeated drain
  attempts don't pile up.

---

## Configuration surface

### New options (in `mxchat_plus_duckdb_options`)

| Key | Type | Default | Notes |
|---|---|---|---|
| `motherduck_mirror_enabled` | bool | `false` | Toggle. Save handler kicks off bootstrap when transitioning false→true. |
| `motherduck_mirror_path` | string | `<wp-content>/uploads/mxchat-plus-private/mirror.duckdb` | Auto-creates the same blocker files (`.htaccess`, `index.php`, `web.config`) as the embedded path. |

### New sidecar options (separate `wp_options`)

| Key | Shape | Purpose |
|---|---|---|
| `mxchat_plus_duckdb_mirror_status` | `'disabled'|'bootstrapping'|'active'|'drifted'|'error'` | Read-only, surfaces in admin + `/health`. |
| `mxchat_plus_duckdb_mirror_bootstrap_state` | `{started_at, target_count, processed_count, current_offset, last_error}` | Resumable bootstrap progress. |
| `mxchat_plus_duckdb_mirror_pending` | `{pending: […], quarantine: […], drained_total, quarantine_total}` | Per-row mirror queue. |
| `mxchat_plus_duckdb_mirror_last_drift_check` | `int` (timestamp) | Last successful drift check. |

### Sanitiser rules

- `motherduck_mirror_enabled` is rejected when `mode !== 'motherduck'`
  (the mirror doesn't make sense for the embedded mode — it'd be
  mirroring a local file to itself).
- `motherduck_mirror_path` runs the same path-validation + blocker-
  write as `embedded_path`.

### Cron schedule

- `mxchat_plus_duckdb_mirror_drift_check`: daily, anchored 03:30 UTC + jitter
  (offset from the compactor's 03:00 UTC anchor so they don't compete).
- `mxchat_plus_duckdb_mirror_drain`: every 5 minutes, no-op when
  `mirror_pending` is empty.

---

## Schema handling

The Schema class (`Vector_Store_Schema`) currently manages one connection.
With a mirror, it needs to apply migrations to both. Two approaches:

### A. Two Schema instances

Construct a Schema per side, run `ensure_schema()` on both. The
`Mirrored_Connection::execute()` write-through path already applies
DDL to both backends — but the existing Schema instances have
per-request memoisation keyed by connection identifier, so they'd
short-circuit the second migration. Need to be careful.

### B. Schema-aware mirror

Schema knows it's running against a mirror and calls migration steps
through the mirror's primary + local connections explicitly.

### Decision: **A**, with Schema's memoisation keyed on the
**underlying connection's identifier** (which is naturally distinct for
primary vs local). The `Mirrored_Connection::execute()` for DDL is
mechanical pass-through; Schema's per-side memoisation handles the
"already migrated" short-circuit.

The schema-meta table lives on **both sides**. The `hnsw_available`
flag is `'1'` on local (HNSW created) and `'0'` on primary (MotherDuck
skipped). The read path consults the local meta.

---

## Read routing

`Vector_Store_Query::run()` is the hot path. After 0.9.0 it consults
the `fts_available` flag in the schema meta via the connection. We
need to make sure:

- The `fts_available` flag the query reads is the **local** one
  (because that's where BM25 runs).
- The connection it asks is the **mirror wrapper**, which knows to
  route the SELECT to local.

In practice this means `Vector_Store_Query` is unchanged. The
Mirrored_Connection wraps a Connection, and the existing
`fts_available_for_request()` probe is just a SELECT against the
schema_meta table — routed to local by the wrapper's
read/write classifier.

### Fallback when local is unhealthy

If the local connection fails (file corruption, lock timeout exceeded,
disk full mid-read), the wrapper should *fall back to MotherDuck* for
reads. This is the "graceful degradation" goal.

Implementation: try local first; on any `MxChat_Plus_DuckDB_Connection`
exception, log + flip a per-request `local_unhealthy` flag, retry
against primary. The flag prevents thrashing within a single request.

A daily/hourly background probe (`mxchat_plus_duckdb_mirror_health_check`)
re-tests the local connection and clears the persistent unhealthy
state once it recovers.

---

## Test plan

### Unit tests (new file `MirrorConnectionTest.php`)

1. **Reads route to local.** `execute('SELECT 1')` invokes only the
   local mock connection, never primary.
2. **Writes route to primary then local in that order.**
   `execute('INSERT …')` invokes primary first, then local.
3. **Local write failure enqueues to `mirror_pending`.** Inject a
   local mock that throws on INSERT; assert the option grows by the
   right vector_ids.
4. **Local read failure falls back to primary.** Inject a local mock
   that throws on SELECT; assert primary is called with the same SQL.
5. **DDL applies to both sides.** `execute('CREATE TABLE …')` runs
   through both mocks (Schema's `ensure_schema()` short-circuit happens
   at the Schema layer, not here).

### Unit tests (new file `MirrorBootstrapTest.php`)

1. **First run with empty local issues INSERT INTO … SELECT FROM md.**
2. **Resume from persisted offset.** Pre-seed state option;
   assert OFFSET in the SQL matches.
3. **Status transitions correctly.** `'disabled'` → `'bootstrapping'`
   → `'active'` on success; `'error'` on exception with `last_error`
   populated.
4. **MotherDuck-unreachable yields `'error'` status.** Action
   Scheduler retries on next run; resume offset preserved.

### Unit tests (new file `MirrorDriftCheckTest.php`)

1. **Identical sides → no action.** `(c, sig)` matches.
2. **Count differs by small N, pending non-empty → drains pending
   first.** Doesn't trigger re-bootstrap.
3. **Signature mismatch → queues bot-scoped re-bootstrap.**
4. **Per-bot_id isolation.** Drifted bot_A doesn't touch bot_B's data.

### Integration smoke test

(Builds on the integration test framework proposed in the [Unreleased]
roadmap of 0.9.0.) Spin up a real DuckDB CLI:
1. Create a 1k-vector table.
2. Configure plugin in "motherduck-style" using a second `.duckdb` as
   stand-in (we don't want to require a MotherDuck account for CI).
3. Enable mirror; trigger bootstrap; assert local has 1k rows.
4. Run a top-K query; assert result shape matches a brute-force
   reference computed in PHP.
5. Upsert 10 vectors; assert both sides have them.
6. Manually delete a row from "primary"; run drift check; assert
   detection + recovery.

---

## MVP scope (v0.10.0)

**In:**

- `MxChat_Plus_DuckDB_Mirrored_Connection` wrapper class.
- `Connection_Factory` recognises `motherduck_mirror_enabled` and
  returns the wrapped connection.
- Sanitiser rules + admin UI toggle.
- Bootstrap Action Scheduler job + state option.
- Mirror_Drain cron (5-min).
- Mirror_Drift_Check cron (daily).
- Health endpoint surfaces `mirror_status`, `mirror_pending_count`,
  `mirror_quarantine_count`, `mirror_last_drift_check`.
- WP-CLI: `wp mxchat-plus mirror-status`,
  `wp mxchat-plus mirror-bootstrap` (manual trigger),
  `wp mxchat-plus mirror-drain` (manual queue drain),
  `wp mxchat-plus mirror-deep-check` (full row hash diff).
- Unit + integration tests per the plan above.
- Documentation: new docs/MIRROR.md with operator guide.

**Out (deferred to v0.11+):**

- **Per-bot mirror enable/disable.** v1 mirrors everything or nothing.
- **Incremental sync via `updated_at` watermark.** v1 only does full
  re-bootstrap on drift; incremental optimisation can come later.
- **Hot-path mirror health probe.** v1 only re-probes on cron tick.
  A request-time probe (with circuit-breaker) is a later add.
- **Mirror metrics (separate latency p50/p95/p99).** Reuse the global
  metrics for now; per-mirror is later if useful.
- **Multi-server coordination.** v1 assumes one server. Multi-server
  installs must accept that each server has its own mirror that bumps
  on its own cron schedule. A proper coordination story is its own
  design doc.

---

## Risks and open questions

1. **Disk usage doubles.** A 1M-vector × 1536-dim float32 table is
   ~6 GB. Plus the HNSW index. The admin UI should warn the user of
   the disk requirement at toggle time. (Easy fix; flag it.)

2. **MotherDuck cost.** Bootstrap pulls every row over the wire once.
   MotherDuck egress pricing applies. Document this clearly.

3. **Embedding-dim change between sides.** The dim guard in the
   sanitiser blocks changes when the table is non-empty. With a mirror,
   the check must run against the **primary** count (canonical), not
   local. Easy but worth flagging.

4. **What if the mirror file is on a slow disk?** A network-mounted
   uploads/ directory could make the mirror slower than MotherDuck.
   Mitigation: `motherduck_mirror_path` admin field lets ops point it
   at a fast local disk. Document the recommendation.

5. **Compactor interaction.** The orphan compactor deletes vectors not
   in the MySQL KB. With a mirror, the compactor's DELETE goes through
   the mirror wrapper — both sides get cleaned. ✓
   
   Edge: if compactor runs during bootstrap, it may delete vectors the
   bootstrap is about to copy. Bootstrap should set a "locked" flag
   that compactor honours.

6. **Parquet export/import.** The user-initiated `wp mxchat-plus
   export` should dump from the **canonical** side (primary). Import
   should INSERT OR REPLACE through the mirror wrapper (both sides).
   This is a small CLI change but worth being explicit.

7. **What about the existing `last_compact_at` / `last_sync_at`
   sidecar options?** They reference the canonical side. No change.

---

## Acceptance criteria for shipping v0.10.0

Concrete, falsifiable bar:

- [ ] All MVP-in scope items implemented and tested.
- [ ] PHPUnit suite green, including new tests above (~25-35 new cases).
- [ ] Integration smoke test from the test plan passes against a real
      DuckDB binary in CI matrix.
- [ ] PHPStan baseline: zero new errors introduced; the existing 134
      typing nags from 0.9.0 are an acceptable floor.
- [ ] Manual test on a 100k-vector MotherDuck install (paul's test
      account) confirms:
  - Bootstrap completes in < 5 minutes.
  - Subsequent queries are ≥ 10× faster than brute-force MotherDuck.
  - `wp mxchat-plus mirror-deep-check` reports zero drift after a
    fresh bootstrap.
- [ ] CHANGELOG + readme.txt + README updated with the v0.10.0 changes
      and the disk-usage / cost caveats from Risks #1-2.
- [ ] No regression in existing test suite (261 tests must still pass).

---

## Open for review

If you'd like to redirect any of these decisions, the load-bearing ones
are:

1. **Sync write-through is the consistency model** (Question 1). If
   you want write-behind instead, much of the wrapper becomes a queue
   manager rather than a fan-out.
2. **Action Scheduler is acceptable as a hard dependency** for
   bootstrap. We already use it for async_reprocess. If you want to
   support non-Action-Scheduler installs, bootstrap needs a fallback
   path (AJAX-driven from the admin UI, slow but works).
3. **Daily drift check is the right frequency.** Hourly is also
   defensible if you've seen drift events in practice. Free to bump.
4. **Mirror is single-bot-flat.** No per-bot enable/disable in v1.
   If multi-tenancy is a near-term concern, we'd reshape.

Once you give the green light (or amendments), the next step is to
turn this into concrete tasks and start implementing under v0.10.0.
