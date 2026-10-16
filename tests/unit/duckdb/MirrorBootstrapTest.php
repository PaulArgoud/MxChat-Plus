<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the bootstrap pipeline that walks MotherDuck → local mirror in
 * batches. Tests inject a recording mock connection (no real DuckDB
 * needed) and verify:
 *
 *   - start() flips status to 'bootstrapping' and enqueues a tick
 *   - first tick probes target_count + ensures local schema + runs
 *     first batch
 *   - subsequent tick uses the persisted (last_vector_id, last_bot_id)
 *     cursor — schema v4 keys rows on (bot_id, vector_id), so one id can
 *     exist once per bot
 *   - the copy names its columns (no positional SELECT *), so a primary
 *     still on the pre-v4 layout copies into a v4 local table
 *   - completion flips status to 'active' and stops enqueueing
 *   - target_count = 0 short-circuits to 'active' on the first tick
 *   - exceptions flip status to 'error', persist last_error, and
 *     still re-enqueue so transient failures self-heal
 */
final class MirrorBootstrapTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['__test_options']    = [];
        $GLOBALS['__test_transients'] = [];
        $GLOBALS['__test_as_queue']   = [];
        MxChat_Plus_DuckDB_Mirror_Bootstrap::reset_state();
        MxChat_Plus_DuckDB_Mirror_Bootstrap::reset_instance();
        // Schema's per-request memoisation cache must be cleared so
        // ensure_local_schema() actually runs the DDL we want to
        // observe.
        $r = new ReflectionProperty(MxChat_Plus_DuckDB_Vector_Store_Schema::class, 'ensured');
        $r->setValue(null, []);
    }

    /**
     * Recording mock connection. Returns row sets matched by SQL
     * substring patterns, records every (sql, params) call, and
     * optionally throws on a configured substring so we can simulate
     * transient bootstrap failures.
     */
    private function makeConn(array $responses = [], ?string $throw_on = null): MxChat_Plus_DuckDB_Connection {
        return new class($responses, $throw_on) implements MxChat_Plus_DuckDB_Connection {
            public array $log = [];
            public array $params_log = [];
            public function __construct(public array $responses, public ?string $throw_on) {}
            public function execute(string $sql, array $params = []): array {
                $this->log[] = $sql;
                $this->params_log[] = $params;
                if ($this->throw_on !== null && stripos($sql, $this->throw_on) !== false) {
                    // An introspection of a missing table fails the way DuckDB
                    // does; table_columns() only reads that error as "absent".
                    $missing = stripos($this->throw_on, 'pragma_table_info') !== false
                        ? ' — Catalog Error: Table with name x does not exist!' : '';
                    throw new RuntimeException("mock throw on '{$this->throw_on}'{$missing}");
                }
                foreach ($this->responses as $needle => $rows) {
                    if (stripos($sql, (string) $needle) !== false) return $rows;
                }
                return [];
            }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'mock:bootstrap'; }
            public function supports_capability(string $cap): bool { return $cap === MxChat_Plus_DuckDB_Connection::CAP_VSS_PERSISTENT_INDEX; }
        };
    }

    private function setMotherDuckMode(array $extra = []): void {
        update_option('mxchat_plus_duckdb_options', array_merge(
            MxChat_Plus_DuckDB_Options::defaults(),
            [
                'mode'                      => 'motherduck',
                'motherduck_token'          => 'tok',
                'motherduck_database'       => 'mydb',
                'motherduck_mirror_enabled' => true,
                'embedding_dim'             => 3,
            ],
            $extra
        ));
    }

    // ─── start() ──────────────────────────────────────────────────────────

    public function test_start_flips_status_and_enqueues_when_enabled(): void {
        $this->setMotherDuckMode();

        $ok = MxChat_Plus_DuckDB_Mirror_Bootstrap::start();

        $this->assertTrue($ok);
        $this->assertSame(
            MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_BOOTSTRAPPING,
            MxChat_Plus_DuckDB_Mirror_Bootstrap::get_status(),
            'status should flip to bootstrapping'
        );
        $state = MxChat_Plus_DuckDB_Mirror_Bootstrap::get_state();
        $this->assertNull($state['target_count'],
            'target_count starts null and is probed on the first tick');
        $this->assertSame('', $state['last_vector_id'],
            'cursor starts at empty string so WHERE vector_id > "" picks up the lowest vector_id first');
        $this->assertGreaterThan(0, $state['started_at']);

        // Action Scheduler queue gained exactly one tick entry.
        $queued = array_filter($GLOBALS['__test_as_queue'], fn($a) =>
            $a['hook'] === MxChat_Plus_DuckDB_Mirror_Bootstrap::ACTION_HOOK);
        $this->assertCount(1, $queued);
    }

    public function test_start_is_a_noop_when_mode_is_not_motherduck(): void {
        update_option('mxchat_plus_duckdb_options', array_merge(
            MxChat_Plus_DuckDB_Options::defaults(),
            ['mode' => 'embedded', 'motherduck_mirror_enabled' => true]
        ));
        $this->assertFalse(MxChat_Plus_DuckDB_Mirror_Bootstrap::start());
        $this->assertEmpty($GLOBALS['__test_as_queue']);
    }

    public function test_start_is_a_noop_when_mirror_toggle_is_off(): void {
        $this->setMotherDuckMode(['motherduck_mirror_enabled' => false]);
        $this->assertFalse(MxChat_Plus_DuckDB_Mirror_Bootstrap::start());
        $this->assertEmpty($GLOBALS['__test_as_queue']);
    }

    // ─── first tick ───────────────────────────────────────────────────────

    public function test_first_tick_probes_target_creates_schema_runs_first_batch(): void {
        $this->setMotherDuckMode();
        MxChat_Plus_DuckDB_Mirror_Bootstrap::start();

        $conn = $this->makeConn([
            'SELECT COUNT(*) AS c FROM md_remote' => [['c' => 2500]],
            'SELECT COUNT(*) AS c FROM "mxchat_vectors"' => [['c' => 50]],
            'SELECT vector_id, ' => array_map(
                fn($i) => ['vector_id' => sprintf('v%04d', $i), 'bot_id' => 'default'],
                range(1, 50)
            ),
        ]);
        $bootstrap = new MxChat_Plus_DuckDB_Mirror_Bootstrap($conn);

        $result = $bootstrap->tick();

        $log = implode("\n", $conn->log);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS "mxchat_vectors"', $log,
            'first tick must create the local target table');
        $this->assertStringContainsString('SELECT COUNT(*) AS c FROM md_remote', $log,
            'first tick must probe the remote count');
        $this->assertStringContainsString('INSERT OR REPLACE INTO "mxchat_vectors"', $log,
            'first tick must run the first batch INSERT');
        $this->assertStringContainsString('FROM md_remote."mxchat_vectors"', $log,
            'INSERT must SELECT from the md_remote-attached table');
        $this->assertStringContainsString('WHERE vector_id > ? OR (vector_id = ? AND', $log,
            'cursor must be a bound (vector_id, bot_id) tuple, not inlined');
        $this->assertStringNotContainsString('SELECT * FROM md_remote', $log,
            'the copy must name its columns — the two sides may have different layouts');

        $state = MxChat_Plus_DuckDB_Mirror_Bootstrap::get_state();
        $this->assertSame(2500, $state['target_count'],
            'target_count populated from the COUNT probe');
        $this->assertSame('v0050', $state['last_vector_id'],
            'cursor advanced to the last vector_id of this batch');
        $this->assertSame('default', $state['last_bot_id'],
            'and to its bot_id');

        $this->assertFalse($result['done'], 'not done — local count below target');
        $this->assertSame(MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_BOOTSTRAPPING, $result['status']);

        // One follow-up tick scheduled (plus the start() one = 2).
        $pending = array_filter($GLOBALS['__test_as_queue'], fn($a) =>
            $a['hook'] === MxChat_Plus_DuckDB_Mirror_Bootstrap::ACTION_HOOK
            && $a['status'] === 'pending');
        $this->assertCount(2, $pending);
    }

    public function test_subsequent_tick_uses_persisted_cursor(): void {
        $this->setMotherDuckMode();
        // Pre-seed state as if a previous tick already ran.
        update_option(MxChat_Plus_DuckDB_Mirror_Bootstrap::STATE_OPTION, [
            'started_at'      => time() - 60,
            'target_count'    => 2500,
            'processed_count' => 1000,
            'last_vector_id'  => 'v1000',
            'last_error'      => '',
            'completed_at'    => null,
        ]);

        $conn = $this->makeConn([
            'SELECT COUNT(*) AS c FROM "mxchat_vectors"' => [['c' => 2000]],
            'SELECT vector_id, ' => array_map(
                fn($i) => ['vector_id' => sprintf('v%04d', $i), 'bot_id' => 'default'],
                range(1001, 2000)
            ),
        ]);
        $bootstrap = new MxChat_Plus_DuckDB_Mirror_Bootstrap($conn);

        $bootstrap->tick();

        // Params on the INSERT carry the cursor we persisted.
        $insert_idx = null;
        foreach ($conn->log as $i => $sql) {
            if (stripos($sql, 'INSERT OR REPLACE INTO "mxchat_vectors"') !== false) {
                $insert_idx = $i;
                break;
            }
        }
        $this->assertNotNull($insert_idx, 'INSERT must have been emitted');
        $this->assertSame(['v1000', 'v1000', ''], $conn->params_log[$insert_idx],
            'cursor (v1000, "") must be bound on the INSERT — a state saved before last_bot_id existed resumes after every bot of v1000');

        $state = MxChat_Plus_DuckDB_Mirror_Bootstrap::get_state();
        $this->assertSame('v2000', $state['last_vector_id'],
            'cursor advanced to v2000');
    }

    public function test_target_count_zero_marks_active_immediately(): void {
        $this->setMotherDuckMode();
        MxChat_Plus_DuckDB_Mirror_Bootstrap::start();

        $conn = $this->makeConn([
            'SELECT COUNT(*) AS c FROM md_remote' => [['c' => 0]],
        ]);
        $bootstrap = new MxChat_Plus_DuckDB_Mirror_Bootstrap($conn);

        $result = $bootstrap->tick();

        $this->assertTrue($result['done']);
        $this->assertSame(0, $result['target']);
        $this->assertSame(
            MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_ACTIVE,
            MxChat_Plus_DuckDB_Mirror_Bootstrap::get_status(),
            'empty primary table short-circuits straight to active'
        );

        $log = implode("\n", $conn->log);
        // Schema migration legitimately writes to mxchat_plus_duckdb_schema_meta
        // via INSERT OR REPLACE (for the fts_available / hnsw_available
        // flags). We're checking that no data-table INSERT runs when
        // target_count = 0 — the bootstrap short-circuits before any
        // batch is attempted.
        $this->assertStringNotContainsString('INSERT OR REPLACE INTO "mxchat_vectors"', $log,
            'no INSERT into the data table must fire when target_count = 0');
    }

    // ─── completion ───────────────────────────────────────────────────────

    public function test_local_count_reaches_target_flips_status_to_active(): void {
        $this->setMotherDuckMode();
        update_option(MxChat_Plus_DuckDB_Mirror_Bootstrap::STATE_OPTION, [
            'started_at'      => time() - 60,
            'target_count'    => 100,
            'processed_count' => 99,
            'last_vector_id'  => 'v0099',
            'last_error'      => '',
            'completed_at'    => null,
        ]);

        // After this tick's INSERT, local has 100 rows = target.
        $conn = $this->makeConn([
            'SELECT COUNT(*) AS c FROM "mxchat_vectors"' => [['c' => 100]],
            'SELECT vector_id, ' => [['vector_id' => 'v0100', 'bot_id' => 'default']],
        ]);
        $bootstrap = new MxChat_Plus_DuckDB_Mirror_Bootstrap($conn);

        $result = $bootstrap->tick();

        $this->assertTrue($result['done']);
        $this->assertSame(100, $result['processed']);
        $this->assertSame(MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_ACTIVE, $result['status']);
        $this->assertSame(MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_ACTIVE,
            MxChat_Plus_DuckDB_Mirror_Bootstrap::get_status());

        $state = MxChat_Plus_DuckDB_Mirror_Bootstrap::get_state();
        $this->assertNotNull($state['completed_at']);
        $this->assertSame(100, $state['processed_count']);

        // No further tick enqueued past this point.
        $follow_ups = array_filter($GLOBALS['__test_as_queue'], fn($a) =>
            $a['hook'] === MxChat_Plus_DuckDB_Mirror_Bootstrap::ACTION_HOOK
            && $a['status'] === 'pending');
        $this->assertCount(0, $follow_ups,
            'completed bootstrap must not enqueue further ticks');
    }

    // ─── error path ───────────────────────────────────────────────────────

    public function test_exception_flips_status_to_error_and_re_enqueues(): void {
        $this->setMotherDuckMode();
        MxChat_Plus_DuckDB_Mirror_Bootstrap::start();
        // Clear the start()'s enqueue so we only see the post-error one.
        $GLOBALS['__test_as_queue'] = [];

        $conn = $this->makeConn([], 'SELECT COUNT(*) AS c FROM md_remote');
        $bootstrap = new MxChat_Plus_DuckDB_Mirror_Bootstrap($conn);

        $result = $bootstrap->tick();

        $this->assertSame(MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_ERROR, $result['status']);
        $this->assertFalse($result['done']);
        $this->assertStringContainsString("mock throw on", $result['last_error'] ?? '');

        $this->assertSame(
            MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_ERROR,
            MxChat_Plus_DuckDB_Mirror_Bootstrap::get_status(),
            'status must be visible to admin / health endpoint as error'
        );

        $state = MxChat_Plus_DuckDB_Mirror_Bootstrap::get_state();
        $this->assertStringContainsString('mock throw on', $state['last_error'],
            'last_error persisted for the admin notice');

        // The transient-error self-heal contract: a failing tick still
        // schedules the next one. Ops can disable via Action Scheduler
        // if needed.
        $pending = array_filter($GLOBALS['__test_as_queue'], fn($a) =>
            $a['hook'] === MxChat_Plus_DuckDB_Mirror_Bootstrap::ACTION_HOOK
            && $a['status'] === 'pending');
        $this->assertCount(1, $pending,
            'failing tick re-enqueues so transient failures self-heal');
    }

    public function test_disabling_mirror_mid_bootstrap_stops_cleanly(): void {
        $this->setMotherDuckMode();
        MxChat_Plus_DuckDB_Mirror_Bootstrap::start();
        $GLOBALS['__test_as_queue'] = [];

        // Simulate admin toggling the mirror off between ticks.
        update_option('mxchat_plus_duckdb_options', array_merge(
            MxChat_Plus_DuckDB_Options::defaults(),
            ['mode' => 'motherduck', 'motherduck_mirror_enabled' => false]
        ));

        $bootstrap = new MxChat_Plus_DuckDB_Mirror_Bootstrap($this->makeConn());

        $result = $bootstrap->tick();

        $this->assertTrue($result['done']);
        $this->assertSame(MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_DISABLED, $result['status']);
        $this->assertEmpty($GLOBALS['__test_as_queue'],
            'must NOT re-enqueue when the mirror has been disabled');
    }

    // ─── composite key (schema v4) ───────────────────────────────────────

    public function test_cursor_carries_the_bot_so_a_shared_id_is_not_skipped(): void {
        // md5(/pricing) exists for 'default' AND 'sales'. A batch boundary
        // that falls between the two copies must resume at ('id', 'default')
        // and still pick up ('id', 'sales') — a cursor on vector_id alone
        // (WHERE vector_id > 'id') skipped it.
        $this->setMotherDuckMode();
        update_option(MxChat_Plus_DuckDB_Mirror_Bootstrap::STATE_OPTION, [
            'started_at'      => time() - 60,
            'target_count'    => 10,
            'processed_count' => 1,
            'last_vector_id'  => 'shared',
            'last_bot_id'     => 'default',
            'last_error'      => '',
            'completed_at'    => null,
        ]);
        $conn = $this->makeConn([
            'SELECT COUNT(*) AS c FROM "mxchat_vectors"' => [['c' => 2]],
            'SELECT vector_id, ' => [['vector_id' => 'shared', 'bot_id' => 'sales']],
        ]);
        (new MxChat_Plus_DuckDB_Mirror_Bootstrap($conn))->tick();

        $insert_idx = null;
        foreach ($conn->log as $i => $sql) {
            if (stripos($sql, 'INSERT OR REPLACE INTO "mxchat_vectors"') !== false) { $insert_idx = $i; break; }
        }
        $this->assertNotNull($insert_idx);
        $this->assertSame(['shared', 'shared', 'default'], $conn->params_log[$insert_idx]);
        $this->assertStringContainsString('ORDER BY vector_id, ', $conn->log[$insert_idx]);

        $state = MxChat_Plus_DuckDB_Mirror_Bootstrap::get_state();
        $this->assertSame('shared', $state['last_vector_id']);
        $this->assertSame('sales', $state['last_bot_id']);
    }

    public function test_pre_v4_primary_is_copied_by_name_into_the_v4_table(): void {
        // The primary reports the pre-v4 columns (no title, no row_key, no
        // updated_at). The INSERT must list the v4 columns, fill the missing
        // ones with defaults and recompute row_key from the normalised bot.
        $this->setMotherDuckMode();
        update_option(MxChat_Plus_DuckDB_Mirror_Bootstrap::STATE_OPTION, [
            'started_at' => time(), 'target_count' => 5, 'processed_count' => 0,
            'last_vector_id' => '', 'last_bot_id' => '', 'last_error' => '', 'completed_at' => null,
        ]);
        $legacy_cols = array_map(fn($n) => ['name' => $n, 'pk' => $n === 'vector_id', 'type' => 'VARCHAR'],
            ['vector_id', 'bot_id', 'embedding', 'content', 'source_url', 'role_restriction',
             'content_type', 'chunk_index', 'total_chunks', 'is_chunked', 'created_at']);
        $conn = $this->makeConn([
            "pragma_table_info('md_remote.mxchat_vectors')" => $legacy_cols,
            'SELECT COUNT(*) AS c FROM "mxchat_vectors"' => [['c' => 1]],
        ]);
        (new MxChat_Plus_DuckDB_Mirror_Bootstrap($conn))->tick();

        $insert = '';
        foreach ($conn->log as $sql) {
            if (stripos($sql, 'INSERT OR REPLACE INTO "mxchat_vectors"') !== false) { $insert = $sql; break; }
        }
        $this->assertStringContainsString('INSERT OR REPLACE INTO "mxchat_vectors" (' . implode(', ', MxChat_Plus_DuckDB_Vector_Store_Schema::COLUMNS) . ')', $insert);
        $this->assertStringContainsString("COALESCE(NULLIF(bot_id, ''), 'default')", $insert,
            'a NULL/empty bot_id on the primary lands under default');
        $this->assertStringContainsString('chr(31)', $insert, 'row_key is recomputed');
        $this->assertStringContainsString('FROM md_remote."mxchat_vectors" WHERE', $insert);
    }

    public function test_missing_primary_table_is_an_error_not_an_empty_copy(): void {
        $this->setMotherDuckMode();
        update_option(MxChat_Plus_DuckDB_Mirror_Bootstrap::STATE_OPTION, [
            'started_at' => time(), 'target_count' => 5, 'processed_count' => 0,
            'last_vector_id' => '', 'last_bot_id' => '', 'last_error' => '', 'completed_at' => null,
        ]);
        $conn = $this->makeConn([], "pragma_table_info('md_remote.");
        $result = (new MxChat_Plus_DuckDB_Mirror_Bootstrap($conn))->tick();
        $this->assertSame(MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_ERROR, $result['status']);
        $this->assertStringContainsString('does not exist', $result['last_error'] ?? '');
    }

    public function test_an_exhausted_cursor_completes_even_below_the_target(): void {
        // The primary shrank after the first tick (the host deleted entries
        // meanwhile): the cursor is past the last row, the local count will
        // never reach the stale target, and the run must not tick forever.
        $this->setMotherDuckMode();
        update_option(MxChat_Plus_DuckDB_Mirror_Bootstrap::STATE_OPTION, [
            'started_at' => time() - 60, 'target_count' => 100, 'processed_count' => 90,
            'last_vector_id' => 'v0090', 'last_bot_id' => 'default', 'last_error' => '', 'completed_at' => null,
        ]);
        $GLOBALS['__test_as_queue'] = [];
        $conn = $this->makeConn(['SELECT COUNT(*) AS c FROM "mxchat_vectors"' => [['c' => 90]]]);

        $result = (new MxChat_Plus_DuckDB_Mirror_Bootstrap($conn))->tick();

        $this->assertTrue($result['done']);
        $this->assertSame(MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_ACTIVE, $result['status']);
        $this->assertEmpty($GLOBALS['__test_as_queue'], 'no further tick');
    }

    // ─── state helpers ────────────────────────────────────────────────────

    public function test_get_state_returns_defaults_when_unset(): void {
        $state = MxChat_Plus_DuckDB_Mirror_Bootstrap::get_state();
        $this->assertSame(0, $state['started_at']);
        $this->assertNull($state['target_count']);
        $this->assertSame('', $state['last_vector_id']);
        $this->assertSame('', $state['last_bot_id']);
    }

    public function test_get_status_defaults_to_disabled(): void {
        $this->assertSame(
            MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_DISABLED,
            MxChat_Plus_DuckDB_Mirror_Bootstrap::get_status()
        );
    }

    public function test_reset_state_clears_both_state_and_status(): void {
        update_option(MxChat_Plus_DuckDB_Mirror_Bootstrap::STATE_OPTION, ['target_count' => 999]);
        update_option(MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_OPTION,
            MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_ACTIVE);

        MxChat_Plus_DuckDB_Mirror_Bootstrap::reset_state();

        $this->assertNull(MxChat_Plus_DuckDB_Mirror_Bootstrap::get_state()['target_count']);
        $this->assertSame(
            MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_DISABLED,
            MxChat_Plus_DuckDB_Mirror_Bootstrap::get_status()
        );
    }
}
