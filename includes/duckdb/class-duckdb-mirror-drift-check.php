<?php
/**
 * Mirror drift detection: daily Action Scheduler tick that compares
 * primary (MotherDuck) and local for divergence on (count, vector_id
 * set hash) per bot_id.
 *
 * Sources of drift the check is built to catch (see
 * docs/DESIGN-motherduck-mirror.md question 4):
 *   - A `mirror_pending` write that fails N times and gets stuck in
 *     quarantine — its row is missing from local while present on
 *     primary.
 *   - An admin doing direct SQL against MotherDuck outside the
 *     plugin (manual DELETE, UPDATE).
 *   - A backup/restore performed on one side only.
 *   - Multi-server installs where server B's writes don't replicate
 *     to server A's mirror until A's cron tick.
 *
 * Detection only in v1. Reconciliation (re-bootstrap of the affected
 * bot_id partition) is a manual `wp mxchat-plus mirror-bootstrap
 * --reset` for now; the auto-recovery path is intentionally deferred
 * because partial-bot bootstrap requires a different code path than
 * the full-table bootstrap we ship today. v1.1 lands the scoped
 * re-bootstrap.
 *
 * What we detect, per bot_id:
 *   1. Count differs by > SMALL_DIFFERENTIAL  → real drift.
 *   2. Count matches but signature differs   → real drift (set diff).
 *   3. Count differs by ≤ SMALL_DIFFERENTIAL AND mirror_pending is
 *      non-empty → "drainable" — the next drain tick will close
 *      the gap, no admin intervention needed.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Mirror_Drift_Check {

    use MxChat_Plus_DuckDB_SQL_Helpers_Trait;

    const ACTION_HOOK         = 'mxchat_plus_duckdb_mirror_drift_check_tick';
    const ACTION_GROUP        = 'mxchat-plus-mirror';
    const INTERVAL_SECONDS    = 86400;   // daily
    const LAST_CHECK_OPTION   = 'mxchat_plus_duckdb_mirror_last_drift_check';
    const SMALL_DIFFERENTIAL  = 50;      // ≤ this many missing rows is recoverable via drain

    private static ?self $instance = null;

    // Promoted properties for the SQL helper trait. The trait reads
    // $this->storage on quote_ident()-adjacent helpers we don't use,
    // but it expects the slot to exist; default keeps Schema happy.
    protected string $table   = 'mxchat_vectors';
    protected int    $dim     = 1536;
    protected string $storage = 'float32';

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public static function reset_instance(): void {
        self::$instance = null;
    }

    public function register_hooks(): void {
        add_action(self::ACTION_HOOK, [$this, 'run_as_action']);

        if (function_exists('as_next_scheduled_action')
            && function_exists('as_schedule_recurring_action')
            && !as_next_scheduled_action(self::ACTION_HOOK, [], self::ACTION_GROUP)) {
            // Anchor at +12h so the first tick after activation doesn't
            // race the bootstrap pipeline. Subsequent ticks fire on the
            // 24h interval.
            as_schedule_recurring_action(
                time() + 12 * HOUR_IN_SECONDS,
                self::INTERVAL_SECONDS,
                self::ACTION_HOOK,
                [],
                self::ACTION_GROUP
            );
        }
    }

    /**
     * Action callback wrapper enforcing the void contract that
     * PHPStan-WP expects on add_action callbacks. The real worker
     * returns a status array used by tests + the CLI command.
     */
    public function run_as_action(): void {
        $this->run();
    }

    /**
     * Run one drift check pass. Returns a status array suitable for
     * the WP-CLI command + tests.
     *
     * @return array{
     *   skipped:bool, reason?:string,
     *   drift:bool, drifted_bots:array<int,string>, recoverable_via_drain:bool,
     *   primary?:array<string,array{count:int,sig:string}>,
     *   local?:array<string,array{count:int,sig:string}>,
     * }
     */
    public function run(): array {
        $opts = MxChat_Plus_DuckDB_Options::get();
        if (($opts['mode'] ?? '') !== 'motherduck' || empty($opts['motherduck_mirror_enabled'])) {
            return ['skipped' => true, 'reason' => 'mirror not enabled',
                    'drift' => false, 'drifted_bots' => [], 'recoverable_via_drain' => false];
        }

        [$primary, $local] = $this->resolve_pair();
        if ($primary === null || $local === null) {
            return ['skipped' => true, 'reason' => 'no mirrored connection available',
                    'drift' => false, 'drifted_bots' => [], 'recoverable_via_drain' => false];
        }

        $this->table   = (string) ($opts['table_name'] ?? 'mxchat_vectors');
        $this->dim     = (int) ($opts['embedding_dim'] ?? 1536);
        $this->storage = (string) ($opts['embedding_storage'] ?? 'float32');

        try {
            $primary_sig = $this->signature($primary);
            $local_sig   = $this->signature($local);
        } catch (\Throwable $e) {
            error_log('[mxchat-plus] mirror drift check failed to read signatures: ' . $e->getMessage());
            return ['skipped' => true, 'reason' => 'signature read failed: ' . $e->getMessage(),
                    'drift' => false, 'drifted_bots' => [], 'recoverable_via_drain' => false];
        }

        $drifted = $this->compare($primary_sig, $local_sig);

        // Stamp the timestamp regardless of outcome so /health can
        // surface "last successful check" for operators.
        update_option(self::LAST_CHECK_OPTION, time(), false);

        if (empty($drifted)) {
            // Stay clear of clobbering 'bootstrapping' / 'error' states
            // — only flip back to 'active' from a previous 'drifted'.
            if (MxChat_Plus_DuckDB_Mirror_Bootstrap::get_status() === MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_DRIFTED) {
                update_option(MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_OPTION,
                    MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_ACTIVE, false);
            }
            return ['skipped' => false, 'drift' => false, 'drifted_bots' => [],
                    'recoverable_via_drain' => false,
                    'primary' => $primary_sig, 'local' => $local_sig];
        }

        $pending_count = MxChat_Plus_DuckDB_Mirrored_Connection::pending_count();
        $recoverable   = $pending_count > 0 && $this->is_within_small_differential($drifted, $primary_sig, $local_sig);

        if (!$recoverable) {
            update_option(MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_OPTION,
                MxChat_Plus_DuckDB_Mirror_Bootstrap::STATUS_DRIFTED, false);
            error_log(sprintf(
                '[mxchat-plus] mirror drift detected on bot_ids=[%s]. Manual re-bootstrap recommended: wp mxchat-plus mirror-bootstrap --reset',
                implode(',', $drifted)
            ));
        } else {
            error_log(sprintf(
                '[mxchat-plus] mirror drift on bot_ids=[%s] is within the SMALL_DIFFERENTIAL with %d pending — drain tick will close the gap.',
                implode(',', $drifted),
                $pending_count
            ));
        }

        return [
            'skipped'               => false,
            'drift'                 => true,
            'drifted_bots'          => $drifted,
            'recoverable_via_drain' => $recoverable,
            'primary'               => $primary_sig,
            'local'                 => $local_sig,
        ];
    }

    public static function get_last_check_timestamp(): int {
        return (int) get_option(self::LAST_CHECK_OPTION, 0);
    }

    // ─── Internals ────────────────────────────────────────────────────────

    /**
     * Returns [primary, local] connections from the configured
     * Mirrored wrapper, or [null, null] when not in mirror mode.
     * Tests inject via the `mxchat_plus_duckdb_mirror_drift_check_pair`
     * filter to avoid needing a real DuckDB process.
     *
     * @return array{0: ?MxChat_Plus_DuckDB_Connection, 1: ?MxChat_Plus_DuckDB_Connection}
     */
    protected function resolve_pair(): array {
        $injected = apply_filters('mxchat_plus_duckdb_mirror_drift_check_pair', null);
        if (is_array($injected) && count($injected) === 2
            && $injected[0] instanceof MxChat_Plus_DuckDB_Connection
            && $injected[1] instanceof MxChat_Plus_DuckDB_Connection) {
            return $injected;
        }
        try {
            $conn = MxChat_Plus_DuckDB_Connection_Factory::current();
        } catch (\Throwable $e) {
            return [null, null];
        }
        if (!$conn instanceof MxChat_Plus_DuckDB_Mirrored_Connection) return [null, null];
        return [$conn->primary_connection(), $conn->local_connection()];
    }

    /**
     * Compute per-bot_id (count, signature) on one side. Signature is
     * MD5(string_agg(vector_id, ',' ORDER BY vector_id)) which costs a
     * ~32MB intermediate string on a 1M-row table but is reliable and
     * O(N log N). Acceptable for a daily cron.
     *
     * Empty table returns []. Caller compares the two maps by key.
     *
     * @return array<string, array{count:int, sig:string}>
     */
    private function signature(MxChat_Plus_DuckDB_Connection $conn): array {
        $sql = sprintf(
            'SELECT bot_id,
                    COUNT(*) AS c,
                    md5(string_agg(vector_id, \',\' ORDER BY vector_id)) AS sig
             FROM %s
             GROUP BY bot_id',
            $this->quote_ident($this->table)
        );
        $rows = $conn->execute($sql);
        $out = [];
        foreach ($rows as $r) {
            $bot = (string) ($r['bot_id'] ?? 'default');
            $out[$bot] = [
                'count' => (int)    ($r['c']   ?? 0),
                'sig'   => (string) ($r['sig'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Return the list of bot_ids where primary and local diverge. A
     * bot present on one side but not the other is also a divergence.
     *
     * @param array<string, array{count:int, sig:string}> $primary
     * @param array<string, array{count:int, sig:string}> $local
     * @return array<int, string>
     */
    private function compare(array $primary, array $local): array {
        $bots = array_unique(array_merge(array_keys($primary), array_keys($local)));
        $drifted = [];
        foreach ($bots as $bot) {
            $p = $primary[$bot] ?? ['count' => 0, 'sig' => ''];
            $l = $local[$bot]   ?? ['count' => 0, 'sig' => ''];
            if ($p['count'] !== $l['count'] || $p['sig'] !== $l['sig']) {
                $drifted[] = $bot;
            }
        }
        sort($drifted);
        return $drifted;
    }

    /**
     * "Drainable drift": every drifted bot's count differs by no more
     * than SMALL_DIFFERENTIAL. Below that threshold, the gap is most
     * likely the mirror_pending queue waiting on its next drain
     * tick — no admin intervention warranted.
     *
     * @param array<int, string> $drifted_bots
     * @param array<string, array{count:int, sig:string}> $primary
     * @param array<string, array{count:int, sig:string}> $local
     */
    private function is_within_small_differential(array $drifted_bots, array $primary, array $local): bool {
        foreach ($drifted_bots as $bot) {
            $p = $primary[$bot]['count'] ?? 0;
            $l = $local[$bot]['count']   ?? 0;
            if (abs($p - $l) > self::SMALL_DIFFERENTIAL) {
                return false;
            }
        }
        return true;
    }
}
