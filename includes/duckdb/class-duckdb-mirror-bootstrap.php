<?php
/**
 * Mirror bootstrap: populates the local DuckDB shadow from MotherDuck.
 *
 * Strategy (see docs/DESIGN-motherduck-mirror.md question 2):
 *   - Single Embedded_Connection on the local file, with an extra
 *     `ATTACH 'md:<db>' AS md_remote` init_sql. The session sees both
 *     the local `mxchat_vectors` table (default catalog) and the
 *     remote `md_remote.mxchat_vectors`.
 *   - Schema is ensured on the local side first so the INSERT-SELECT
 *     has a target table with the same shape (FLOAT[N] / HNSW / etc.).
 *   - Batches via vector_id cursor: WHERE vector_id > <last_seen>
 *     ORDER BY vector_id LIMIT BATCH_SIZE. Stable + resumable; doesn't
 *     suffer OFFSET-on-large-table degeneracy.
 *   - INSERT OR REPLACE is idempotent on resume so a half-applied
 *     batch after a crash doesn't leave dangling state.
 *
 * Each tick runs one batch and re-enqueues itself via Action Scheduler.
 * Status flips to 'active' when local count >= target, or 'error' on
 * exception (next tick retries). On 'active' the read path of the
 * Mirrored_Connection starts serving HNSW-accelerated queries from
 * local — until then, every read in the wrapper falls back to primary
 * because the per-request `local_read_unhealthy` flag never trips on
 * the empty (yet schema-valid) local table; the queries just match no
 * rows, so the user-visible behaviour during bootstrap is "MotherDuck
 * read latency, no errors".
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Mirror_Bootstrap {

    use MxChat_Plus_DuckDB_SQL_Helpers_Trait;

    const STATE_OPTION  = 'mxchat_plus_duckdb_mirror_bootstrap_state';
    const STATUS_OPTION = 'mxchat_plus_duckdb_mirror_status';
    const ACTION_HOOK   = 'mxchat_plus_duckdb_mirror_bootstrap_tick';
    const ACTION_GROUP  = 'mxchat-plus-mirror';

    const STATUS_DISABLED      = 'disabled';
    const STATUS_BOOTSTRAPPING = 'bootstrapping';
    const STATUS_ACTIVE        = 'active';
    const STATUS_ERROR         = 'error';
    /**
     * Mirror is up but the daily drift check found primary and local
     * diverged on count or signature for at least one bot_id. Set by
     * Mirror_Drift_Check; admins should run `wp mxchat-plus
     * mirror-bootstrap --reset` (or wait for the auto-reconcile that
     * a future release will queue) to re-converge.
     */
    const STATUS_DRIFTED       = 'drifted';

    /**
     * Default rows per tick. Sized for ~1 MB of SQL plus parquet-style
     * binary transfer over the DuckDB protocol; small enough to fit
     * inside an Action Scheduler job's typical 30-second timeout even
     * for 1536-dim float32 vectors.
     */
    const BATCH_SIZE = 1000;

    private static ?self $instance = null;

    // Promoted properties so the SQL helper trait can find $table, $dim, $storage.
    protected string $table;
    protected int    $dim;
    protected string $storage;

    /** @var MxChat_Plus_DuckDB_Connection|null Injected by tests; production builds one lazily. */
    private ?MxChat_Plus_DuckDB_Connection $bootstrap_conn;
    /** @var array<string, mixed>|null Test override for options. */
    private ?array $opts_override;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    /**
     * Reset the cached singleton. Production code never calls this;
     * tests use it between cases to avoid cross-pollution of the
     * injected mock connection.
     */
    public static function reset_instance(): void {
        self::$instance = null;
    }

    public function __construct(?MxChat_Plus_DuckDB_Connection $bootstrap_conn = null, ?array $opts_override = null) {
        $this->bootstrap_conn = $bootstrap_conn;
        $this->opts_override  = $opts_override;
        $opts = $this->resolve_opts();
        $this->table   = (string) ($opts['table_name'] ?? 'mxchat_vectors');
        $this->dim     = (int) ($opts['embedding_dim'] ?? 1536);
        $this->storage = in_array($opts['embedding_storage'] ?? 'float32', ['float32', 'int8'], true)
            ? (string) $opts['embedding_storage']
            : 'float32';
    }

    public function register_hooks(): void {
        add_action(self::ACTION_HOOK, [$this, 'tick']);
    }

    // ─── Public API ───────────────────────────────────────────────────────

    /**
     * Kick off a bootstrap. Idempotent — calling start() while a
     * bootstrap is already running just makes sure the next tick is
     * queued (so a stale Action Scheduler queue doesn't strand the
     * process). Returns true when something was scheduled, false when
     * the call was a no-op (mirror disabled, mode wrong, etc.).
     *
     * Called from:
     *   - the options sanitiser, when motherduck_mirror_enabled
     *     transitions false → true,
     *   - the WP-CLI `mirror-bootstrap` command (manual trigger),
     *   - the drift-check cron when it decides to re-bootstrap a
     *     drifted bot (future phase).
     */
    public static function start(): bool {
        $opts = MxChat_Plus_DuckDB_Options::get();
        if (($opts['mode'] ?? '') !== 'motherduck' || empty($opts['motherduck_mirror_enabled'])) {
            return false;
        }

        // Reset state for a fresh bootstrap run.
        update_option(self::STATE_OPTION, [
            'started_at'      => time(),
            'target_count'    => null,           // probed on first tick
            'processed_count' => 0,
            'last_vector_id'  => '',             // empty cursor → starts from the beginning
            'last_error'      => '',
            'completed_at'    => null,
        ], false);
        update_option(self::STATUS_OPTION, self::STATUS_BOOTSTRAPPING, false);

        // Action Scheduler is optional in the test environment; in
        // production it's hard-required (used by async-reprocess too,
        // so this never causes a runtime issue on an install that
        // already works).
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::ACTION_HOOK, [], self::ACTION_GROUP);
        }
        return true;
    }

    /**
     * One bootstrap batch. Designed to be re-enqueued on the same hook
     * until completion. Safe to call directly from the CLI (the test
     * suite exercises this path).
     *
     * @return array{status:string, processed:int, target:?int, done:bool, last_error?:string}
     */
    public function tick(): array {
        $opts = $this->resolve_opts();
        if (($opts['mode'] ?? '') !== 'motherduck' || empty($opts['motherduck_mirror_enabled'])) {
            // The user disabled the mirror mid-bootstrap. Stop cleanly.
            update_option(self::STATUS_OPTION, self::STATUS_DISABLED, false);
            return ['status' => self::STATUS_DISABLED, 'processed' => 0, 'target' => null, 'done' => true];
        }

        $state = self::get_state();

        try {
            $conn = $this->connection($opts);

            // First tick: probe the remote count, set up the local
            // schema, and short-circuit when there's nothing to do.
            if ($state['target_count'] === null) {
                $this->ensure_local_schema($conn, $opts);
                $rows = $conn->execute(sprintf(
                    'SELECT COUNT(*) AS c FROM md_remote.%s',
                    $this->quote_ident($this->table)
                ));
                $state['target_count'] = (int) ($rows[0]['c'] ?? 0);
                if ($state['target_count'] === 0) {
                    $state['completed_at'] = time();
                    self::save_state($state);
                    update_option(self::STATUS_OPTION, self::STATUS_ACTIVE, false);
                    return ['status' => self::STATUS_ACTIVE, 'processed' => 0, 'target' => 0, 'done' => true];
                }
            }

            // One batch: INSERT the next chunk of remote rows whose
            // vector_id is strictly greater than the last cursor.
            $batch_size = (int) apply_filters('mxchat_plus_duckdb_mirror_bootstrap_batch_size', self::BATCH_SIZE);
            $batch_size = max(1, min(10000, $batch_size));
            $table_q = $this->quote_ident($this->table);
            $insert_sql = sprintf(
                'INSERT OR REPLACE INTO %1$s
                 SELECT * FROM md_remote.%1$s
                 WHERE vector_id > ?
                 ORDER BY vector_id
                 LIMIT %2$d',
                $table_q,
                $batch_size
            );
            $conn->execute($insert_sql, [(string) ($state['last_vector_id'] ?? '')]);

            // Advance the cursor to the last vector_id of this batch.
            // We re-query the source so we don't have to read back our
            // own write (which would also work but feels backwards).
            $cursor_sql = sprintf(
                'SELECT vector_id FROM md_remote.%s
                 WHERE vector_id > ?
                 ORDER BY vector_id
                 LIMIT %d',
                $table_q,
                $batch_size
            );
            $batch_rows = $conn->execute($cursor_sql, [(string) ($state['last_vector_id'] ?? '')]);
            if (!empty($batch_rows)) {
                $state['last_vector_id'] = (string) end($batch_rows)['vector_id'];
            }
            $state['processed_count'] = (int) $state['processed_count'] + count($batch_rows);

            // Done check uses local COUNT(*) — authoritative on this
            // side, robust against transient remote miscounts.
            $local_rows = $conn->execute(sprintf('SELECT COUNT(*) AS c FROM %s', $table_q));
            $local_count = (int) ($local_rows[0]['c'] ?? 0);
            $done = $local_count >= (int) $state['target_count'];

            if ($done) {
                $state['completed_at'] = time();
                $state['processed_count'] = $local_count;
                self::save_state($state);
                update_option(self::STATUS_OPTION, self::STATUS_ACTIVE, false);
                return ['status' => self::STATUS_ACTIVE, 'processed' => $local_count, 'target' => (int) $state['target_count'], 'done' => true];
            }

            self::save_state($state);
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(self::ACTION_HOOK, [], self::ACTION_GROUP);
            }
            return [
                'status'    => self::STATUS_BOOTSTRAPPING,
                'processed' => $local_count,
                'target'    => (int) $state['target_count'],
                'done'      => false,
            ];
        } catch (\Throwable $e) {
            $state['last_error'] = $e->getMessage();
            self::save_state($state);
            update_option(self::STATUS_OPTION, self::STATUS_ERROR, false);
            error_log('[mxchat-plus] mirror bootstrap tick failed: ' . $e->getMessage());
            // Re-enqueue so transient failures self-heal on the next tick.
            // The retry budget is implicit: Action Scheduler doesn't bound
            // it, but the error gets surfaced on every tick so a stuck
            // bootstrap is visible.
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(self::ACTION_HOOK, [], self::ACTION_GROUP);
            }
            return [
                'status'     => self::STATUS_ERROR,
                'processed'  => (int) ($state['processed_count'] ?? 0),
                'target'     => isset($state['target_count']) ? (int) $state['target_count'] : null,
                'done'       => false,
                'last_error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{
     *   started_at:int, target_count:?int, processed_count:int,
     *   last_vector_id:string, last_error:string, completed_at:?int
     * }
     */
    public static function get_state(): array {
        $raw = get_option(self::STATE_OPTION, []);
        $defaults = [
            'started_at'      => 0,
            'target_count'    => null,
            'processed_count' => 0,
            'last_vector_id'  => '',
            'last_error'      => '',
            'completed_at'    => null,
        ];
        if (!is_array($raw)) return $defaults;
        return array_replace($defaults, $raw);
    }

    public static function get_status(): string {
        $val = (string) get_option(self::STATUS_OPTION, '');
        if ($val === '') return self::STATUS_DISABLED;
        $known = [self::STATUS_DISABLED, self::STATUS_BOOTSTRAPPING, self::STATUS_ACTIVE,
                  self::STATUS_ERROR, self::STATUS_DRIFTED];
        return in_array($val, $known, true) ? $val : self::STATUS_DISABLED;
    }

    /**
     * Clear bootstrap state. Used by the WP-CLI `mirror-bootstrap`
     * command's `--reset` flag and by the test suite between cases.
     */
    public static function reset_state(): void {
        delete_option(self::STATE_OPTION);
        delete_option(self::STATUS_OPTION);
    }

    private static function save_state(array $state): void {
        update_option(self::STATE_OPTION, $state, false);
    }

    // ─── Internal: connection + schema setup ──────────────────────────────

    /**
     * Build (or return the injected) bootstrap connection. The
     * production connection is a local Embedded_Connection with
     * `ATTACH 'md:<db>' AS md_remote` so cross-database SELECTs work
     * without round-tripping rows through PHP.
     */
    protected function connection(array $opts): MxChat_Plus_DuckDB_Connection {
        if ($this->bootstrap_conn !== null) {
            return $this->bootstrap_conn;
        }
        // Prefer the MXCHAT_PLUS_MOTHERDUCK_TOKEN constant when defined in
        // wp-config.php (compliance-driven secret hygiene); fall back to the
        // persisted option otherwise.
        $token  = MxChat_Plus_DuckDB_Options::resolved_motherduck_token();
        $db     = (string) ($opts['motherduck_database'] ?? '');
        if ($token === '' || $db === '') {
            throw new RuntimeException('Mirror bootstrap requires a MotherDuck token + database.');
        }
        $escaped_token = str_replace("'", "''", $token);
        $init = [
            'INSTALL motherduck',
            'LOAD motherduck',
            sprintf(
                "CREATE OR REPLACE PERSISTENT SECRET mxchat_motherduck (TYPE motherduck, TOKEN '%s')",
                $escaped_token
            ),
            sprintf("ATTACH 'md:%s' AS md_remote", $db),
        ];
        $local_opts = $opts;
        $local_opts['db_path'] = MxChat_Plus_DuckDB_Options::resolved_mirror_path();
        $this->bootstrap_conn = new MxChat_Plus_DuckDB_Embedded_Connection($local_opts, $init);
        return $this->bootstrap_conn;
    }

    /**
     * Ensure the local target table exists with the same shape as
     * primary. Schema migration runs against the local connection
     * directly (not through the Mirrored wrapper) so we don't
     * double-apply on primary.
     */
    protected function ensure_local_schema(MxChat_Plus_DuckDB_Connection $conn, array $opts): void {
        $metric  = (string) ($opts['distance_metric'] ?? 'cosine');
        $hnsw    = !empty($opts['hnsw_enabled']);
        $schema  = new MxChat_Plus_DuckDB_Vector_Store_Schema(
            $conn, $this->table, $this->dim, $metric, $hnsw, $this->storage
        );
        $schema->ensure_schema();
    }

    private function resolve_opts(): array {
        return $this->opts_override ?? MxChat_Plus_DuckDB_Options::get();
    }
}
