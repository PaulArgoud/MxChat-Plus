<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the daily drift detection between primary (MotherDuck) and
 * local. Tests inject the two-connection pair via the
 * `mxchat_plus_duckdb_mirror_drift_check_pair` filter so no real DuckDB
 * process is needed.
 *
 * Behaviours covered:
 *
 *   - identical (count, signature) per bot_id → drift=false, last
 *     check timestamp updated
 *   - signature mismatch → drift=true, STATUS_DRIFTED, list of bots
 *   - count differs by ≤ SMALL_DIFFERENTIAL AND mirror_pending is
 *     non-empty → drift=true BUT recoverable_via_drain=true, status
 *     unchanged (drain will catch up)
 *   - count differs by > SMALL_DIFFERENTIAL → real drift, drainable=false
 *   - bot present on one side only → drift=true
 *   - skip paths: mirror disabled, no Mirrored connection available
 *   - clearing drift (next check matches) flips DRIFTED back to ACTIVE
 *   - register_hooks schedules a recurring daily tick exactly once
 */
final class MirrorDriftCheckTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['__test_options']    = [];
        $GLOBALS['__test_transients'] = [];
        $GLOBALS['__test_as_queue']   = [];
        $GLOBALS['__test_filter_overrides'] = [];
        MxChat_Plus_DuckDB_Mirrored_Connection::reset_pending_state();
        MxChat_Plus_DuckDB_Mirror_Drift_Check::reset_instance();
        delete_option(MxChat_Plus_DuckDB_Mirror_Drift_Check::LAST_CHECK_OPTION);
        delete_option(MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_OPTION);
    }

    private function enableMirror(): void {
        update_option('mxchat_plus_duckdb_options', array_merge(
            MxChat_Plus_DuckDB_Options::defaults(),
            ['mode' => 'motherduck', 'motherduck_mirror_enabled' => true]
        ));
    }

    /**
     * Returns a connection that yields a pre-baked signature map for
     * the `SELECT bot_id, COUNT(*), md5(string_agg(...)) ... GROUP BY bot_id`
     * shape Drift_Check emits.
     *
     * @param array<string, array{count:int, sig:string}> $sig_map
     */
    private function makeSigConn(array $sig_map): MxChat_Plus_DuckDB_Connection {
        $rows = [];
        foreach ($sig_map as $bot_id => $info) {
            $rows[] = ['bot_id' => $bot_id, 'c' => $info['count'], 'sig' => $info['sig']];
        }
        return new class($rows) implements MxChat_Plus_DuckDB_Connection {
            public function __construct(public array $rows) {}
            public function execute(string $sql, array $params = []): array {
                if (stripos($sql, 'GROUP BY bot_id') !== false) return $this->rows;
                return [];
            }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'mock:drift'; }
            public function supports_capability(string $cap): bool { return false; }
        };
    }

    /**
     * @param array<string, array{count:int, sig:string}> $primary
     * @param array<string, array{count:int, sig:string}> $local
     */
    private function injectPair(array $primary, array $local): void {
        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_mirror_drift_check_pair'] = [
            $this->makeSigConn($primary),
            $this->makeSigConn($local),
        ];
    }

    // ─── No drift ─────────────────────────────────────────────────────────

    public function test_identical_signatures_yield_no_drift_and_stamp_timestamp(): void {
        $this->enableMirror();
        $sig = ['default' => ['count' => 1000, 'sig' => 'abc123']];
        $this->injectPair($sig, $sig);

        $before = time();
        $result = MxChat_Plus_DuckDB_Mirror_Drift_Check::instance()->run();

        $this->assertFalse($result['drift']);
        $this->assertSame([], $result['drifted_bots']);
        $this->assertGreaterThanOrEqual($before,
            MxChat_Plus_DuckDB_Mirror_Drift_Check::get_last_check_timestamp(),
            'last_drift_check_at must be stamped on every successful run, drift or not');
    }

    public function test_no_drift_clears_a_previously_drifted_status_to_active(): void {
        $this->enableMirror();
        // Simulate a previously drifted install.
        update_option(MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_OPTION,
            MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_DRIFTED);

        $sig = ['default' => ['count' => 10, 'sig' => 'same']];
        $this->injectPair($sig, $sig);

        MxChat_Plus_DuckDB_Mirror_Drift_Check::instance()->run();

        $this->assertSame(
            MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_ACTIVE,
            MxChat_Plus_DuckDB_Mirror_Bootstrap::get_status(),
            'clearing drift must flip DRIFTED back to ACTIVE'
        );
    }

    public function test_no_drift_does_not_clobber_unrelated_statuses(): void {
        // If the install is currently 'bootstrapping' or 'error', a
        // no-drift result must NOT clobber that — those statuses have
        // their own state machines.
        $this->enableMirror();
        update_option(MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_OPTION,
            MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_BOOTSTRAPPING);

        $sig = ['default' => ['count' => 10, 'sig' => 'same']];
        $this->injectPair($sig, $sig);

        MxChat_Plus_DuckDB_Mirror_Drift_Check::instance()->run();

        $this->assertSame(
            MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_BOOTSTRAPPING,
            MxChat_Plus_DuckDB_Mirror_Bootstrap::get_status(),
            'no-drift result must leave BOOTSTRAPPING alone'
        );
    }

    // ─── Real drift ───────────────────────────────────────────────────────

    public function test_signature_mismatch_flips_status_to_drifted(): void {
        $this->enableMirror();
        $this->injectPair(
            ['default' => ['count' => 1000, 'sig' => 'sigA']],
            ['default' => ['count' => 1000, 'sig' => 'sigB']]
        );

        $result = MxChat_Plus_DuckDB_Mirror_Drift_Check::instance()->run();

        $this->assertTrue($result['drift']);
        $this->assertSame(['default'], $result['drifted_bots']);
        $this->assertFalse($result['recoverable_via_drain'],
            'signature mismatch with no pending entries is NOT drainable');
        $this->assertSame(
            MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_DRIFTED,
            MxChat_Plus_DuckDB_Mirror_Bootstrap::get_status()
        );
    }

    public function test_large_count_differential_flips_status_to_drifted(): void {
        $this->enableMirror();
        $differential = MxChat_Plus_DuckDB_Mirror_Drift_Check::SMALL_DIFFERENTIAL + 100;
        $this->injectPair(
            ['default' => ['count' => 10000, 'sig' => 'shared-sig-because-it-is-a-mock']],
            ['default' => ['count' => 10000 - $differential, 'sig' => 'shared-sig-because-it-is-a-mock']]
        );

        $result = MxChat_Plus_DuckDB_Mirror_Drift_Check::instance()->run();

        // Count differs by 150 → drift detected (different counts mean
        // a different set in production; the shared sig here is a mock
        // artefact). 150 > SMALL_DIFFERENTIAL → not drainable.
        $this->assertTrue($result['drift']);
        $this->assertFalse($result['recoverable_via_drain']);
        $this->assertSame(
            MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_DRIFTED,
            MxChat_Plus_DuckDB_Mirror_Bootstrap::get_status()
        );
    }

    public function test_small_differential_with_pending_is_drainable_no_status_flip(): void {
        $this->enableMirror();
        // Seed mirror_pending with one entry — represents a write that
        // failed locally but is queued for drain.
        update_option(MxChat_Plus_DuckDB_Mirrored_Connection::PENDING_OPTION, [
            'pending' => [['sql' => 'X', 'params' => [], 'queued_at' => time(), 'retries' => 0, 'last_error' => '']],
            'quarantine' => [], 'drained_total' => 0, 'quarantine_total' => 0,
        ]);
        // Pre-set status to ACTIVE so we can verify it's NOT changed
        // (the drainable case should NOT flip to DRIFTED).
        update_option(MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_OPTION,
            MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_ACTIVE);

        $this->injectPair(
            ['default' => ['count' => 1000, 'sig' => 'sigA']],
            ['default' => ['count' => 999,  'sig' => 'sigA-but-shorter']]
        );

        $result = MxChat_Plus_DuckDB_Mirror_Drift_Check::instance()->run();

        $this->assertTrue($result['drift']);
        $this->assertTrue($result['recoverable_via_drain'],
            'count differential of 1 with pending queue → drainable');
        $this->assertSame(
            MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_ACTIVE,
            MxChat_Plus_DuckDB_Mirror_Bootstrap::get_status(),
            'drainable drift must NOT flip status to DRIFTED — drain will close the gap'
        );
    }

    public function test_bot_present_on_one_side_only_is_drift(): void {
        $this->enableMirror();
        // primary has bot_b, local doesn't — straight drift.
        $this->injectPair(
            ['default' => ['count' => 10, 'sig' => 'd'], 'bot_b' => ['count' => 5, 'sig' => 'b']],
            ['default' => ['count' => 10, 'sig' => 'd']]
        );

        $result = MxChat_Plus_DuckDB_Mirror_Drift_Check::instance()->run();

        $this->assertTrue($result['drift']);
        $this->assertSame(['bot_b'], $result['drifted_bots']);
    }

    public function test_drift_only_on_one_bot_doesnt_taint_the_others(): void {
        $this->enableMirror();
        $this->injectPair(
            ['default' => ['count' => 10, 'sig' => 'same'], 'bot_b' => ['count' => 5, 'sig' => 'A']],
            ['default' => ['count' => 10, 'sig' => 'same'], 'bot_b' => ['count' => 5, 'sig' => 'B']]
        );

        $result = MxChat_Plus_DuckDB_Mirror_Drift_Check::instance()->run();

        $this->assertSame(['bot_b'], $result['drifted_bots']);
        $this->assertNotContains('default', $result['drifted_bots']);
    }

    // ─── Skip paths ───────────────────────────────────────────────────────

    public function test_skip_when_mirror_disabled(): void {
        update_option('mxchat_plus_duckdb_options', array_merge(
            MxChat_Plus_DuckDB_Options::defaults(),
            ['mode' => 'motherduck', 'motherduck_mirror_enabled' => false]
        ));
        $this->injectPair(['default' => ['count' => 5, 'sig' => 'X']],
                          ['default' => ['count' => 5, 'sig' => 'X']]);

        $result = MxChat_Plus_DuckDB_Mirror_Drift_Check::instance()->run();

        $this->assertTrue($result['skipped']);
        $this->assertSame('mirror not enabled', $result['reason']);
    }

    public function test_skip_when_no_mirrored_connection_available(): void {
        $this->enableMirror();
        // No filter override + no real Mirrored_Connection in Factory → skip.
        $result = MxChat_Plus_DuckDB_Mirror_Drift_Check::instance()->run();

        $this->assertTrue($result['skipped']);
        $this->assertSame('no mirrored connection available', $result['reason']);
    }

    // ─── Scheduling ───────────────────────────────────────────────────────

    public function test_register_hooks_schedules_recurring_daily_tick(): void {
        $this->enableMirror();
        $GLOBALS['__test_as_queue'] = [];

        MxChat_Plus_DuckDB_Mirror_Drift_Check::instance()->register_hooks();

        $recurring = array_filter($GLOBALS['__test_as_queue'], fn($a) =>
            $a['hook'] === MxChat_Plus_DuckDB_Mirror_Drift_Check::ACTION_HOOK
            && !empty($a['recurring']));
        $this->assertCount(1, $recurring);

        $tick = reset($recurring);
        $this->assertSame(MxChat_Plus_DuckDB_Mirror_Drift_Check::INTERVAL_SECONDS, $tick['interval']);
    }
}
