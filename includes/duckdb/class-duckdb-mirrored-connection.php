<?php
/**
 * Mirrored connection: wraps a primary backend (MotherDuck cloud) and a
 * local DuckDB shadow, routing reads to local for HNSW speed and writes
 * to both in sequence (primary first, mirror best-effort) for durability.
 *
 * Implements the same MxChat_Plus_DuckDB_Connection interface as the
 * underlying connections, so callers don't need to know the wrapper
 * exists — Schema/Query/REST proxy/CLI all keep using the connection
 * exactly as before.
 *
 * Architecture rationale lives in docs/DESIGN-motherduck-mirror.md.
 * Short version:
 *   - Consistency model: synchronous write-through, primary-first.
 *     A user-facing call returns success when primary accepted the
 *     write; if the mirror fails afterwards, the SQL is queued for
 *     reconciliation (mirror_pending option) and the call still
 *     returns success.
 *   - Reads: route to local. On local failure (corruption, lock,
 *     disk error), fall back to primary for the rest of the request.
 *   - DDL: applies to both sides via the write-through path.
 *
 * The MVP queue stores `{sql, params}` pairs rather than the
 * `{vector_id}` shape originally sketched in the design doc — works
 * for any SQL kind (not just upserts) and avoids parsing the SQL to
 * extract IDs. Replaying the original statement is idempotent for the
 * INSERT OR REPLACE + DELETE writes the plugin actually performs.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Mirrored_Connection implements MxChat_Plus_DuckDB_Connection {

    const PENDING_OPTION       = 'mxchat_plus_duckdb_mirror_pending';
    const PENDING_RETRY_LIMIT  = 10;     // moves to quarantine after this many failed drain attempts
    const PENDING_MAX_QUEUE    = 5000;   // hard cap to avoid wp_options bloat; surfaces an admin warning

    protected MxChat_Plus_DuckDB_Connection $primary;
    protected MxChat_Plus_DuckDB_Connection $local;

    /**
     * Per-request flag: once the local connection has thrown for a read,
     * we route the rest of this request's reads to primary directly to
     * avoid thrashing on a known-bad local. A background health probe
     * re-tests the local connection on a separate cron tick — this flag
     * is intentionally request-scoped.
     */
    protected bool $local_read_unhealthy = false;

    public function __construct(MxChat_Plus_DuckDB_Connection $primary, MxChat_Plus_DuckDB_Connection $local) {
        $this->primary = $primary;
        $this->local   = $local;
    }

    // ─── Connection interface ─────────────────────────────────────────────

    /**
     * Read SQL goes to local (HNSW-accelerated). Write SQL goes to
     * primary first (canonical) and then local (best-effort). On
     * local-side failure for a write, the SQL is queued in
     * `mxchat_plus_duckdb_mirror_pending` for a later 5-min drain run.
     *
     * The classification is intentionally conservative: anything not
     * positively identified as a read is treated as a write, so a typo
     * never accidentally sends a mutation to local-only and skips the
     * canonical store.
     */
    public function execute(string $sql, array $params = []): array {
        if (self::is_read_sql($sql)) {
            return $this->execute_read($sql, $params);
        }
        return $this->execute_write($sql, $params);
    }

    /**
     * Both sides must respond. A flapping local is reported as
     * unhealthy here so monitors can see the degraded mode even when
     * reads are silently falling back to primary.
     */
    public function ping(): bool {
        return $this->primary->ping() && $this->local->ping();
    }

    public function identifier(): string {
        return 'mirrored:primary=' . $this->primary->identifier()
            . ',local=' . $this->local->identifier();
    }

    /**
     * Capability negotiation: a capability is supported when EITHER
     * side reports support. Schema uses CAP_VSS_PERSISTENT_INDEX to
     * decide whether to create the HNSW index — we want it created on
     * the local side, so we report true here even though primary
     * (MotherDuck) reports false.
     *
     * Note: schema migrations should run separately against each side
     * so the per-side answer is honoured (see Schema integration in
     * the design doc). This OR-semantics is the right answer for
     * "should the read path even bother with HNSW pushdown?" — the
     * read path runs against local, so yes if local has HNSW.
     */
    public function supports_capability(string $capability): bool {
        return $this->primary->supports_capability($capability)
            || $this->local->supports_capability($capability);
    }

    // ─── Accessors (used by Schema during dual-side migration) ────────────

    public function primary_connection(): MxChat_Plus_DuckDB_Connection {
        return $this->primary;
    }

    public function local_connection(): MxChat_Plus_DuckDB_Connection {
        return $this->local;
    }

    // ─── Read path ────────────────────────────────────────────────────────

    /**
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    protected function execute_read(string $sql, array $params): array {
        if (!$this->local_read_unhealthy) {
            try {
                return $this->local->execute($sql, $params);
            } catch (\Throwable $e) {
                // Flip the per-request flag so subsequent reads in this
                // request go straight to primary — no point re-attacking
                // a known-bad local connection.
                $this->local_read_unhealthy = true;
                error_log('[mxchat-plus] mirror: local read failed, falling back to primary for this request: ' . $e->getMessage());
            }
        }
        return $this->primary->execute($sql, $params);
    }

    // ─── Write path ───────────────────────────────────────────────────────

    /**
     * Primary first — the call's success/failure mirrors the primary's.
     * Local second — failures here are non-fatal: log + enqueue for
     * later reconciliation, return the primary's result.
     *
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    protected function execute_write(string $sql, array $params): array {
        $result = $this->primary->execute($sql, $params);
        try {
            $this->local->execute($sql, $params);
        } catch (\Throwable $e) {
            self::enqueue_pending($sql, $params, $e);
            error_log('[mxchat-plus] mirror: local write failed, queued for drain: ' . $e->getMessage());
        }
        return $result;
    }

    // ─── SQL classification ───────────────────────────────────────────────

    /**
     * Identify a read statement. Conservative — anything we don't
     * positively recognise is treated as a write so a typo never
     * accidentally bypasses the canonical store.
     *
     * Trailing whitespace + leading parentheses (for sub-queries used
     * at the top-level via WITH ... AS ( SELECT ... )) are handled.
     */
    public static function is_read_sql(string $sql): bool {
        $first = strtoupper(ltrim($sql, "( \t\n\r"));
        return str_starts_with($first, 'SELECT')
            || str_starts_with($first, 'WITH')
            || str_starts_with($first, 'PRAGMA')
            || str_starts_with($first, 'SHOW')
            || str_starts_with($first, 'DESCRIBE')
            || str_starts_with($first, 'EXPLAIN');
    }

    // ─── Pending queue (drain target) ─────────────────────────────────────

    /**
     * Append a failed local write to the mirror_pending queue. The
     * 5-minute drain cron walks this queue and replays each entry.
     * Successful replays remove the entry; failed replays bump
     * `retries`. Entries that hit PENDING_RETRY_LIMIT move to the
     * quarantine bucket and surface in the admin notice + /health.
     *
     * Hard-capped at PENDING_MAX_QUEUE so a runaway local failure
     * doesn't fill wp_options indefinitely. When the cap is reached,
     * additional failures are dropped after a single error_log —
     * better to lose the mirror-pending record than to corrupt the
     * options table.
     *
     * @param array<int, mixed> $params
     */
    public static function enqueue_pending(string $sql, array $params, \Throwable $error): void {
        $state = self::pending_state();
        if (count($state['pending']) >= self::PENDING_MAX_QUEUE) {
            error_log(sprintf(
                '[mxchat-plus] mirror_pending queue is full (%d entries), dropping: %s',
                self::PENDING_MAX_QUEUE,
                substr($sql, 0, 200)
            ));
            return;
        }
        $state['pending'][] = [
            'sql'        => $sql,
            'params'     => $params,
            'queued_at'  => time(),
            'retries'    => 0,
            'last_error' => $error->getMessage(),
        ];
        update_option(self::PENDING_OPTION, $state, false);
    }

    /**
     * @return array{
     *     pending: array<int, array{sql:string, params:array, queued_at:int, retries:int, last_error:string}>,
     *     quarantine: array<int, array{sql:string, params:array, queued_at:int, retries:int, last_error:string}>,
     *     drained_total: int,
     *     quarantine_total: int,
     * }
     */
    public static function pending_state(): array {
        $raw = get_option(self::PENDING_OPTION, []);
        $defaults = [
            'pending'          => [],
            'quarantine'       => [],
            'drained_total'    => 0,
            'quarantine_total' => 0,
        ];
        if (!is_array($raw)) return $defaults;
        return array_replace($defaults, $raw);
    }

    public static function pending_count(): int {
        return count(self::pending_state()['pending']);
    }

    public static function quarantine_count(): int {
        return count(self::pending_state()['quarantine']);
    }

    /**
     * Test helper: clear the queue. Production code should let the
     * drain cron consume it; this is for unit tests that need a
     * clean slate between runs.
     */
    public static function reset_pending_state(): void {
        delete_option(self::PENDING_OPTION);
    }
}
