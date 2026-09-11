<?php
/**
 * Mirror drain: consumes the mirror_pending queue populated by
 * Mirrored_Connection when a local write fails.
 *
 * Lifecycle of a queue entry:
 *   1. Mirror wrapper logs a failed local write → appends to pending
 *      with retries=0.
 *   2. This drain runs on an Action Scheduler recurring tick (every
 *      5 minutes) and replays each pending entry against the LOCAL
 *      connection directly (not through the wrapper — we don't want
 *      to re-fire on primary).
 *   3. Success → remove from pending, bump `drained_total`.
 *   4. Failure → retries++. last_error updated. Re-runs next tick.
 *   5. retries >= PENDING_RETRY_LIMIT → entry moves to `quarantine`.
 *      The /health endpoint reports the count; the admin notice
 *      surfaces a one-line warning so operators see stuck entries.
 *
 * Cap on per-tick work: DRAIN_MAX_PER_TICK entries. Keeps a single
 * tick bounded so a stale 5000-entry queue from a long outage doesn't
 * monopolise the Action Scheduler worker. Subsequent ticks burn it
 * down in chunks.
 *
 * Why Action Scheduler instead of WP-cron: AS supports arbitrary
 * intervals (WP-cron requires registering a custom schedule via the
 * `cron_schedules` filter just to get 5-minute granularity), it's
 * already a hard dep for the bootstrap pipeline, and its dedup
 * semantics for recurring actions are sturdier than wp_schedule_event
 * under concurrent activations.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Mirror_Drain {

    const ACTION_HOOK         = 'mxchat_plus_duckdb_mirror_drain_tick';
    const ACTION_GROUP        = 'mxchat-plus-mirror';
    const INTERVAL_SECONDS    = 300;     // 5 minutes
    const DRAIN_MAX_PER_TICK  = 50;      // bounded so a stale queue doesn't hog the worker

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public static function reset_instance(): void {
        self::$instance = null;
    }

    public function register_hooks(): void {
        add_action(self::ACTION_HOOK, [$this, 'run_as_action']);

        // Schedule a recurring tick if one isn't already pending. The
        // dedup is important: a plugin (de)activate cycle should not
        // double-schedule. Honoured automatically by AS — we only
        // call schedule when no future tick is queued.
        if (function_exists('as_next_scheduled_action')
            && function_exists('as_schedule_recurring_action')
            && !as_next_scheduled_action(self::ACTION_HOOK, [], self::ACTION_GROUP)) {
            as_schedule_recurring_action(
                time() + self::INTERVAL_SECONDS,
                self::INTERVAL_SECONDS,
                self::ACTION_HOOK,
                [],
                self::ACTION_GROUP
            );
        }
    }

    /**
     * Action callback contract — must be void (PHPStan-WP enforces).
     * Wraps the real worker so the return type isn't seen by the
     * action dispatcher; the return value is still useful for tests
     * and the WP-CLI command.
     */
    public function run_as_action(): void {
        $this->run();
    }

    /**
     * Drain up to DRAIN_MAX_PER_TICK pending entries from the queue.
     * Returns counters useful for tests + WP-CLI.
     *
     * @return array{drained:int, retried:int, quarantined:int, remaining:int, skipped:bool, reason?:string}
     */
    public function run(): array {
        $opts = MxChat_Plus_DuckDB_Options::get();
        if (($opts['mode'] ?? '') !== 'motherduck' || empty($opts['motherduck_mirror_enabled'])) {
            return ['drained' => 0, 'retried' => 0, 'quarantined' => 0, 'remaining' => 0, 'skipped' => true,
                    'reason' => 'mirror not enabled'];
        }

        $local = $this->resolve_local_connection();
        if ($local === null) {
            return ['drained' => 0, 'retried' => 0, 'quarantined' => 0, 'remaining' => 0, 'skipped' => true,
                    'reason' => 'no local mirror connection available'];
        }

        $state = MxChat_Plus_DuckDB_Mirrored_Connection::pending_state();
        if (empty($state['pending'])) {
            return ['drained' => 0, 'retried' => 0, 'quarantined' => 0, 'remaining' => 0, 'skipped' => false];
        }

        $drained = 0;
        $retried = 0;
        $quarantined = 0;
        $kept = [];
        $cap = (int) apply_filters('mxchat_plus_duckdb_mirror_drain_per_tick', self::DRAIN_MAX_PER_TICK);
        $cap = max(1, min(1000, $cap));

        $i = 0;
        foreach ($state['pending'] as $entry) {
            if ($i >= $cap) {
                // Keep the rest for the next tick — preserves the FIFO
                // ordering so a hot spot doesn't permanently starve
                // older entries.
                $kept[] = $entry;
                continue;
            }
            $i++;

            $sql    = (string) ($entry['sql'] ?? '');
            $params = is_array($entry['params'] ?? null) ? $entry['params'] : [];
            if ($sql === '') {
                // Defensive: skip malformed entries silently (don't
                // count as quarantined either — they're not
                // recoverable through replay).
                continue;
            }

            try {
                $local->execute($sql, $params);
                $drained++;
            } catch (\Throwable $e) {
                $retries = (int) ($entry['retries'] ?? 0) + 1;
                $entry['retries']    = $retries;
                $entry['last_error'] = $e->getMessage();
                if ($retries >= MxChat_Plus_DuckDB_Mirrored_Connection::PENDING_RETRY_LIMIT) {
                    $state['quarantine'][] = $entry;
                    $quarantined++;
                } else {
                    $kept[] = $entry;
                    $retried++;
                }
            }
        }

        $state['pending']         = $kept;
        $state['drained_total']   = (int) ($state['drained_total'] ?? 0) + $drained;
        $state['quarantine_total'] = (int) ($state['quarantine_total'] ?? 0) + $quarantined;
        update_option(MxChat_Plus_DuckDB_Mirrored_Connection::PENDING_OPTION, $state, false);

        if ($quarantined > 0) {
            error_log(sprintf(
                '[mxchat-plus] mirror drain quarantined %d entries this tick (cumulative: %d). Inspect via wp mxchat-plus mirror-drain --status.',
                $quarantined,
                $state['quarantine_total']
            ));
        }

        return [
            'drained'     => $drained,
            'retried'     => $retried,
            'quarantined' => $quarantined,
            'remaining'   => count($kept),
            'skipped'     => false,
        ];
    }

    /**
     * Returns the LOCAL connection from the configured Mirrored
     * wrapper, or null when the wrapper is unavailable. Tests inject
     * via a filter so they don't need a real DuckDB process.
     */
    protected function resolve_local_connection(): ?MxChat_Plus_DuckDB_Connection {
        $injected = apply_filters('mxchat_plus_duckdb_mirror_drain_local_connection', null);
        if ($injected instanceof MxChat_Plus_DuckDB_Connection) {
            return $injected;
        }
        try {
            $conn = MxChat_Plus_DuckDB_Connection_Factory::current();
        } catch (\Throwable $e) {
            return null;
        }
        if ($conn instanceof MxChat_Plus_DuckDB_Mirrored_Connection) {
            return $conn->local_connection();
        }
        return null;
    }
}
