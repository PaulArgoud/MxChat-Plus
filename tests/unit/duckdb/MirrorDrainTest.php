<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the drain pipeline that consumes the mirror_pending queue
 * populated by Mirrored_Connection write-through failures.
 *
 * Tested behaviours:
 *
 *   - successful replay removes the entry from pending + bumps
 *     drained_total
 *   - failed replay increments retries + persists last_error +
 *     keeps the entry pending (FIFO)
 *   - retries >= PENDING_RETRY_LIMIT moves the entry to quarantine
 *     + bumps quarantine_total
 *   - per-tick cap (DRAIN_MAX_PER_TICK) keeps a stale 5000-entry
 *     queue from monopolising one Action Scheduler worker
 *   - skip when mirror disabled / mode wrong (no SQL, no state churn)
 *   - empty queue is a fast no-op
 *   - register_hooks schedules a recurring tick exactly once
 */
final class MirrorDrainTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['__test_options']    = [];
        $GLOBALS['__test_transients'] = [];
        $GLOBALS['__test_as_queue']   = [];
        $GLOBALS['__test_filter_overrides'] = [];
        MxChat_Plus_DuckDB_Mirrored_Connection::reset_pending_state();
        MxChat_Plus_DuckDB_Mirror_Drain::reset_instance();
    }

    /**
     * Anonymous-class connection that records replays + optionally
     * throws on a specific SQL substring to simulate per-entry local
     * failures during drain.
     */
    private function makeLocal(?string $throw_on = null): MxChat_Plus_DuckDB_Connection {
        return new class($throw_on) implements MxChat_Plus_DuckDB_Connection {
            public array $log = [];
            public array $params_log = [];
            public function __construct(public ?string $throw_on) {}
            public function execute(string $sql, array $params = []): array {
                $this->log[] = $sql;
                $this->params_log[] = $params;
                if ($this->throw_on !== null && stripos($sql, $this->throw_on) !== false) {
                    throw new RuntimeException("local replay still failing on '{$this->throw_on}'");
                }
                return [];
            }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'mock:drain-local'; }
            public function supports_capability(string $cap): bool {
                return $cap === MxChat_Plus_DuckDB_Connection::CAP_VSS_PERSISTENT_INDEX;
            }
        };
    }

    private function enableMirror(): void {
        update_option('mxchat_plus_duckdb_options', array_merge(
            MxChat_Plus_DuckDB_Options::defaults(),
            ['mode' => 'motherduck', 'motherduck_mirror_enabled' => true]
        ));
    }

    /**
     * Pre-seed the pending queue with N entries.
     *
     * @param array<int, array{sql:string, params:array, retries?:int, last_error?:string}> $entries
     */
    private function seedPending(array $entries): void {
        $now = time();
        $state = MxChat_Plus_DuckDB_Mirrored_Connection::pending_state();
        foreach ($entries as $e) {
            $state['pending'][] = [
                'sql'        => $e['sql'],
                'params'     => $e['params'] ?? [],
                'queued_at'  => $now,
                'retries'    => $e['retries']    ?? 0,
                'last_error' => $e['last_error'] ?? '',
            ];
        }
        update_option(MxChat_Plus_DuckDB_Mirrored_Connection::PENDING_OPTION, $state, false);
    }

    private function injectLocal(MxChat_Plus_DuckDB_Connection $conn): void {
        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_mirror_drain_local_connection'] = $conn;
    }

    // ─── Happy path ───────────────────────────────────────────────────────

    public function test_successful_replay_removes_entry_from_pending(): void {
        $this->enableMirror();
        $this->seedPending([
            ['sql' => 'INSERT OR REPLACE INTO t (id) VALUES (?)', 'params' => ['v1']],
            ['sql' => 'INSERT OR REPLACE INTO t (id) VALUES (?)', 'params' => ['v2']],
        ]);
        $local = $this->makeLocal();
        $this->injectLocal($local);

        $result = MxChat_Plus_DuckDB_Mirror_Drain::instance()->run();

        $this->assertSame(2, $result['drained']);
        $this->assertSame(0, $result['retried']);
        $this->assertSame(0, $result['quarantined']);
        $this->assertSame(0, $result['remaining']);
        $this->assertFalse($result['skipped']);

        $state = MxChat_Plus_DuckDB_Mirrored_Connection::pending_state();
        $this->assertCount(0, $state['pending'], 'successful entries must be removed');
        $this->assertSame(2, $state['drained_total']);

        // Replayed SQL + params reached the local connection verbatim.
        $this->assertCount(2, $local->log);
        $this->assertSame(['v1'], $local->params_log[0]);
        $this->assertSame(['v2'], $local->params_log[1]);
    }

    // ─── Retry path ───────────────────────────────────────────────────────

    public function test_failed_replay_increments_retries_keeps_entry(): void {
        $this->enableMirror();
        $this->seedPending([
            ['sql' => 'INSERT INTO broken VALUES (?)', 'params' => ['v1']],
        ]);
        $local = $this->makeLocal('broken');   // throws on this SQL
        $this->injectLocal($local);

        $result = MxChat_Plus_DuckDB_Mirror_Drain::instance()->run();

        $this->assertSame(0, $result['drained']);
        $this->assertSame(1, $result['retried']);
        $this->assertSame(0, $result['quarantined']);
        $this->assertSame(1, $result['remaining']);

        $state = MxChat_Plus_DuckDB_Mirrored_Connection::pending_state();
        $this->assertCount(1, $state['pending']);
        $this->assertSame(1, $state['pending'][0]['retries'],
            'failed replay bumps the retries counter');
        $this->assertStringContainsString('local replay still failing', $state['pending'][0]['last_error']);
    }

    public function test_retries_reach_limit_moves_to_quarantine(): void {
        $this->enableMirror();
        // Pre-seed an entry one retry shy of the limit. The next failed
        // tick should move it to quarantine.
        $limit = MxChat_Plus_DuckDB_Mirrored_Connection::PENDING_RETRY_LIMIT;
        $this->seedPending([
            ['sql' => 'INSERT INTO broken VALUES (?)', 'params' => ['v1'], 'retries' => $limit - 1],
        ]);
        $local = $this->makeLocal('broken');
        $this->injectLocal($local);

        $result = MxChat_Plus_DuckDB_Mirror_Drain::instance()->run();

        $this->assertSame(0, $result['drained']);
        $this->assertSame(1, $result['quarantined']);
        $this->assertSame(0, $result['remaining']);

        $state = MxChat_Plus_DuckDB_Mirrored_Connection::pending_state();
        $this->assertCount(0, $state['pending']);
        $this->assertCount(1, $state['quarantine']);
        $this->assertSame(1, $state['quarantine_total']);
        $this->assertSame($limit, $state['quarantine'][0]['retries']);
    }

    public function test_drained_total_accumulates_across_runs(): void {
        $this->enableMirror();
        // Seed 3 entries, drain succeeds for 2, fails for 1.
        $this->seedPending([
            ['sql' => 'INSERT INTO t VALUES (1)'],
            ['sql' => 'INSERT INTO broken VALUES (2)'],
            ['sql' => 'INSERT INTO t VALUES (3)'],
        ]);
        $local = $this->makeLocal('broken');
        $this->injectLocal($local);

        MxChat_Plus_DuckDB_Mirror_Drain::instance()->run();
        // Second pass on the broken-only remainder — drained_total
        // should stay at 2, retried bumps to 2.
        MxChat_Plus_DuckDB_Mirror_Drain::instance()->run();

        $state = MxChat_Plus_DuckDB_Mirrored_Connection::pending_state();
        $this->assertSame(2, $state['drained_total'],
            'drained_total is cumulative across drain runs');
        $this->assertCount(1, $state['pending']);
        $this->assertSame(2, $state['pending'][0]['retries']);
    }

    // ─── Per-tick cap ─────────────────────────────────────────────────────

    public function test_per_tick_cap_keeps_remainder_for_next_run(): void {
        $this->enableMirror();
        $cap = MxChat_Plus_DuckDB_Mirror_Drain::DRAIN_MAX_PER_TICK;
        // Pre-seed 1.5× the cap. One tick should drain `cap` and leave
        // the rest pending for the next.
        $entries = [];
        for ($i = 0; $i < (int) ($cap * 1.5); $i++) {
            $entries[] = ['sql' => sprintf('INSERT INTO t VALUES (%d)', $i)];
        }
        $this->seedPending($entries);
        $this->injectLocal($this->makeLocal());

        $result = MxChat_Plus_DuckDB_Mirror_Drain::instance()->run();

        $this->assertSame($cap, $result['drained'], 'one tick drains at most $cap entries');
        $this->assertGreaterThan(0, $result['remaining'],
            'overflow remains pending for the next tick — FIFO');

        $state = MxChat_Plus_DuckDB_Mirrored_Connection::pending_state();
        $this->assertCount((int) ($cap * 1.5) - $cap, $state['pending']);
    }

    // ─── Skip paths ───────────────────────────────────────────────────────

    public function test_skip_when_mirror_disabled(): void {
        // mode=motherduck but motherduck_mirror_enabled=false
        update_option('mxchat_plus_duckdb_options', array_merge(
            MxChat_Plus_DuckDB_Options::defaults(),
            ['mode' => 'motherduck', 'motherduck_mirror_enabled' => false]
        ));
        $this->seedPending([['sql' => 'INSERT INTO t VALUES (1)']]);
        $local = $this->makeLocal();
        $this->injectLocal($local);

        $result = MxChat_Plus_DuckDB_Mirror_Drain::instance()->run();

        $this->assertTrue($result['skipped']);
        $this->assertSame('mirror not enabled', $result['reason']);
        $this->assertCount(0, $local->log, 'no replay attempts when mirror is off');
    }

    public function test_skip_when_no_local_connection_available(): void {
        // Mirror is enabled but no local connection is injected and
        // Connection_Factory::current() will not return a Mirrored
        // wrapper (test env has no real backend). The drain should
        // short-circuit with the no-local-connection reason rather
        // than throwing.
        $this->enableMirror();
        $this->seedPending([['sql' => 'INSERT INTO t VALUES (1)']]);

        $result = MxChat_Plus_DuckDB_Mirror_Drain::instance()->run();

        $this->assertTrue($result['skipped']);
        $this->assertSame('no local mirror connection available', $result['reason']);
    }

    public function test_empty_queue_is_a_fast_noop(): void {
        $this->enableMirror();
        $this->injectLocal($this->makeLocal());

        $result = MxChat_Plus_DuckDB_Mirror_Drain::instance()->run();

        $this->assertFalse($result['skipped']);
        $this->assertSame(0, $result['drained']);
        $this->assertSame(0, $result['remaining']);
    }

    public function test_malformed_entry_with_empty_sql_is_silently_dropped(): void {
        $this->enableMirror();
        $this->seedPending([
            ['sql' => '',                                'params' => []],  // malformed
            ['sql' => 'INSERT INTO t VALUES (1)',        'params' => []],  // legit
        ]);
        $local = $this->makeLocal();
        $this->injectLocal($local);

        $result = MxChat_Plus_DuckDB_Mirror_Drain::instance()->run();

        $this->assertSame(1, $result['drained'],
            'only the legit entry is replayed; the empty-sql entry is skipped without counting');
        $this->assertCount(1, $local->log);
    }

    // ─── Scheduling ───────────────────────────────────────────────────────

    public function test_register_hooks_schedules_recurring_tick(): void {
        $this->enableMirror();
        $GLOBALS['__test_as_queue'] = [];

        MxChat_Plus_DuckDB_Mirror_Drain::instance()->register_hooks();

        $recurring = array_filter($GLOBALS['__test_as_queue'], fn($a) =>
            $a['hook'] === MxChat_Plus_DuckDB_Mirror_Drain::ACTION_HOOK
            && !empty($a['recurring']));
        $this->assertCount(1, $recurring,
            'register_hooks must schedule exactly one recurring tick');

        $tick = reset($recurring);
        $this->assertSame(MxChat_Plus_DuckDB_Mirror_Drain::INTERVAL_SECONDS, $tick['interval']);
    }

    public function test_register_hooks_is_idempotent(): void {
        // Calling register_hooks twice (e.g. plugin reactivation
        // while the recurring tick was already scheduled) must not
        // double-schedule.
        $GLOBALS['__test_as_queue'] = [];

        MxChat_Plus_DuckDB_Mirror_Drain::instance()->register_hooks();
        MxChat_Plus_DuckDB_Mirror_Drain::reset_instance();
        MxChat_Plus_DuckDB_Mirror_Drain::instance()->register_hooks();

        $recurring = array_filter($GLOBALS['__test_as_queue'], fn($a) =>
            $a['hook'] === MxChat_Plus_DuckDB_Mirror_Drain::ACTION_HOOK
            && !empty($a['recurring']));
        $this->assertCount(1, $recurring,
            'second register_hooks must be a no-op when a tick is already pending');
    }
}
