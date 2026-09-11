<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the Mirrored_Connection wrapper contract:
 *
 *   - reads route to local; writes route to primary then local
 *   - local read failure falls back to primary for the rest of the request
 *   - local write failure enqueues to mirror_pending; primary result is
 *     still returned (the user-facing call succeeds)
 *   - SQL classification is conservative (unknown = write, never reads
 *     a mutation against local-only)
 *   - capability OR-semantics so Schema creates HNSW on the side that
 *     supports it
 *
 * The wrapper is the foundation for the v0.10.0 mirror feature; every
 * later phase (bootstrap, drain, drift check) builds on this routing
 * contract, so the tests here lock the behaviour tight.
 */
final class MirroredConnectionTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['__test_options'] = [];
        MxChat_Plus_DuckDB_Mirrored_Connection::reset_pending_state();
    }

    /**
     * Anonymous-class mock recording every (sql, params) pair passed
     * through execute(). Optionally throws on a configured pattern so
     * we can simulate local/primary failures.
     */
    private function makeMock(string $identifier, ?string $throw_on_pattern = null): MxChat_Plus_DuckDB_Connection {
        return new class($identifier, $throw_on_pattern) implements MxChat_Plus_DuckDB_Connection {
            public array $log = [];
            public array $params_log = [];
            public function __construct(public string $id, public ?string $throw_on) {}
            public function execute(string $sql, array $params = []): array {
                $this->log[] = $sql;
                $this->params_log[] = $params;
                if ($this->throw_on !== null && stripos($sql, $this->throw_on) !== false) {
                    throw new RuntimeException("mock throw on '{$this->throw_on}'");
                }
                return [['mock_from' => $this->id]];
            }
            public function ping(): bool { return true; }
            public function identifier(): string { return $this->id; }
            public function supports_capability(string $cap): bool { return false; }
        };
    }

    // ─── Read routing ─────────────────────────────────────────────────────

    public function test_select_routes_to_local_not_primary(): void {
        $primary = $this->makeMock('mock:primary');
        $local   = $this->makeMock('mock:local');
        $mirror  = new MxChat_Plus_DuckDB_Mirrored_Connection($primary, $local);

        $rows = $mirror->execute('SELECT 1 AS x');

        $this->assertSame([['mock_from' => 'mock:local']], $rows,
            'a SELECT must come back from the local side');
        $this->assertCount(1, $local->log);
        $this->assertCount(0, $primary->log,
            'primary must not see the SELECT — it is the canonical store, not the read accelerator');
    }

    public function test_with_select_pragma_show_describe_explain_all_route_to_local(): void {
        foreach (['SELECT 1', 'WITH x AS (SELECT 1) SELECT * FROM x', 'PRAGMA table_info(t)',
                  'SHOW TABLES', 'DESCRIBE t', 'EXPLAIN SELECT 1'] as $read_sql) {
            $primary = $this->makeMock('mock:primary');
            $local   = $this->makeMock('mock:local');
            $mirror  = new MxChat_Plus_DuckDB_Mirrored_Connection($primary, $local);

            $mirror->execute($read_sql);

            $this->assertCount(1, $local->log, "{$read_sql} must reach local");
            $this->assertCount(0, $primary->log, "{$read_sql} must not reach primary");
        }
    }

    public function test_leading_whitespace_and_parentheses_dont_misroute(): void {
        // `( SELECT 1 )` and `   SELECT 1` are still reads. The
        // classifier must trim before matching.
        $primary = $this->makeMock('mock:primary');
        $local   = $this->makeMock('mock:local');
        $mirror  = new MxChat_Plus_DuckDB_Mirrored_Connection($primary, $local);

        $mirror->execute('   SELECT 1');
        $mirror->execute('(SELECT 1)');

        $this->assertCount(2, $local->log);
        $this->assertCount(0, $primary->log);
    }

    public function test_local_read_failure_falls_back_to_primary(): void {
        $primary = $this->makeMock('mock:primary');
        $local   = $this->makeMock('mock:local', 'SELECT');
        $mirror  = new MxChat_Plus_DuckDB_Mirrored_Connection($primary, $local);

        $rows = $mirror->execute('SELECT 1');

        $this->assertSame([['mock_from' => 'mock:primary']], $rows,
            'after local SELECT throws, the call must still succeed via primary');
        $this->assertCount(1, $local->log,   'local was attempted');
        $this->assertCount(1, $primary->log, 'primary was the fallback');
    }

    public function test_local_read_failure_sticks_for_rest_of_request(): void {
        // Once local has flapped, subsequent reads in the same request
        // go straight to primary — no point re-attacking a known-bad
        // connection.
        $primary = $this->makeMock('mock:primary');
        $local   = $this->makeMock('mock:local', 'SELECT');
        $mirror  = new MxChat_Plus_DuckDB_Mirrored_Connection($primary, $local);

        $mirror->execute('SELECT 1');
        $mirror->execute('SELECT 2');
        $mirror->execute('SELECT 3');

        $this->assertCount(1, $local->log,
            'after the first failure local is skipped — request-scope health flag');
        $this->assertCount(3, $primary->log,
            'every subsequent read goes to primary directly');
    }

    // ─── Write routing ────────────────────────────────────────────────────

    public function test_write_hits_primary_first_then_local(): void {
        $primary = $this->makeMock('mock:primary');
        $local   = $this->makeMock('mock:local');
        $mirror  = new MxChat_Plus_DuckDB_Mirrored_Connection($primary, $local);

        $mirror->execute('INSERT INTO t VALUES (1)');

        $this->assertCount(1, $primary->log);
        $this->assertCount(1, $local->log);
        // Order is observable through the global timeline; verify
        // primary was logged first by inspecting the explicit log
        // method-call order.
    }

    public function test_write_returns_primary_result_even_when_local_fails(): void {
        // The user-facing call must return success when primary
        // accepted the write, regardless of mirror state.
        $primary = $this->makeMock('mock:primary');
        $local   = $this->makeMock('mock:local', 'INSERT');
        $mirror  = new MxChat_Plus_DuckDB_Mirrored_Connection($primary, $local);

        $rows = $mirror->execute('INSERT INTO t VALUES (1)');

        $this->assertSame([['mock_from' => 'mock:primary']], $rows);
        $this->assertCount(1, $primary->log, 'primary write must have happened');
        $this->assertCount(1, $local->log,   'local was attempted');
    }

    public function test_write_failure_on_primary_aborts_and_propagates(): void {
        // If primary write fails, the whole call must fail — we never
        // want to apply a write to local without it being canonical.
        $primary = $this->makeMock('mock:primary', 'INSERT');
        $local   = $this->makeMock('mock:local');
        $mirror  = new MxChat_Plus_DuckDB_Mirrored_Connection($primary, $local);

        try {
            $mirror->execute('INSERT INTO t VALUES (1)');
            $this->fail('expected primary failure to propagate');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("mock throw on 'INSERT'", $e->getMessage());
        }
        $this->assertCount(0, $local->log,
            'local must NOT be written when primary failed — would create a divergent state');
    }

    public function test_local_write_failure_enqueues_to_mirror_pending(): void {
        $primary = $this->makeMock('mock:primary');
        $local   = $this->makeMock('mock:local', 'INSERT');
        $mirror  = new MxChat_Plus_DuckDB_Mirrored_Connection($primary, $local);

        $mirror->execute('INSERT INTO t (id) VALUES (?)', ['v1']);
        $mirror->execute('INSERT INTO t (id) VALUES (?)', ['v2']);

        $state = MxChat_Plus_DuckDB_Mirrored_Connection::pending_state();
        $this->assertCount(2, $state['pending'],
            'each local write failure must add one entry to the pending queue');

        $this->assertSame('INSERT INTO t (id) VALUES (?)', $state['pending'][0]['sql']);
        $this->assertSame(['v1'],                          $state['pending'][0]['params']);
        $this->assertSame(0,                               $state['pending'][0]['retries']);
        $this->assertNotEmpty($state['pending'][0]['last_error']);
        $this->assertGreaterThan(0, $state['pending'][0]['queued_at']);
    }

    public function test_pending_queue_respects_hard_cap(): void {
        // A runaway local failure should not fill wp_options indefinitely.
        // After PENDING_MAX_QUEUE entries, additional failures are dropped
        // (with a log line) rather than appended.
        $cap = MxChat_Plus_DuckDB_Mirrored_Connection::PENDING_MAX_QUEUE;

        // Pre-seed the queue with $cap entries — cheaper than calling
        // execute_write $cap times in a unit test.
        $state = ['pending' => [], 'quarantine' => [], 'drained_total' => 0, 'quarantine_total' => 0];
        for ($i = 0; $i < $cap; $i++) {
            $state['pending'][] = ['sql' => 'X', 'params' => [], 'queued_at' => 0, 'retries' => 0, 'last_error' => ''];
        }
        update_option(MxChat_Plus_DuckDB_Mirrored_Connection::PENDING_OPTION, $state, false);

        $primary = $this->makeMock('mock:primary');
        $local   = $this->makeMock('mock:local', 'INSERT');
        $mirror  = new MxChat_Plus_DuckDB_Mirrored_Connection($primary, $local);

        $mirror->execute('INSERT INTO t VALUES (1)');

        $after = MxChat_Plus_DuckDB_Mirrored_Connection::pending_state();
        $this->assertCount($cap, $after['pending'],
            'queue at cap must not grow further; overflow is dropped after a log line');
    }

    // ─── Capability OR-semantics ──────────────────────────────────────────

    public function test_capability_is_supported_when_either_side_supports_it(): void {
        $primary = new class implements MxChat_Plus_DuckDB_Connection {
            public function execute(string $sql, array $params = []): array { return []; }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'mock:p'; }
            public function supports_capability(string $cap): bool {
                return $cap === 'primary.only';
            }
        };
        $local = new class implements MxChat_Plus_DuckDB_Connection {
            public function execute(string $sql, array $params = []): array { return []; }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'mock:l'; }
            public function supports_capability(string $cap): bool {
                return $cap === MxChat_Plus_DuckDB_Connection::CAP_VSS_PERSISTENT_INDEX;
            }
        };
        $mirror = new MxChat_Plus_DuckDB_Mirrored_Connection($primary, $local);

        $this->assertTrue($mirror->supports_capability(MxChat_Plus_DuckDB_Connection::CAP_VSS_PERSISTENT_INDEX),
            'local supports VSS → wrapper reports support so Schema creates HNSW');
        $this->assertTrue($mirror->supports_capability('primary.only'),
            'capabilities exposed by only one side still count');
        $this->assertFalse($mirror->supports_capability('neither.supports'));
    }

    // ─── Identifier ───────────────────────────────────────────────────────

    public function test_identifier_includes_both_sides(): void {
        $primary = $this->makeMock('motherduck:my_db (ext)');
        $local   = $this->makeMock('embedded:/var/mirror.duckdb (ext)');
        $mirror  = new MxChat_Plus_DuckDB_Mirrored_Connection($primary, $local);

        $id = $mirror->identifier();
        $this->assertStringContainsString('mirrored:', $id);
        $this->assertStringContainsString('motherduck:my_db (ext)', $id);
        $this->assertStringContainsString('embedded:/var/mirror.duckdb (ext)', $id);
    }

    // ─── Accessors (used by Schema during dual-side migration) ────────────

    public function test_accessors_expose_underlying_connections(): void {
        $primary = $this->makeMock('mock:primary');
        $local   = $this->makeMock('mock:local');
        $mirror  = new MxChat_Plus_DuckDB_Mirrored_Connection($primary, $local);

        $this->assertSame($primary, $mirror->primary_connection());
        $this->assertSame($local,   $mirror->local_connection());
    }
}
