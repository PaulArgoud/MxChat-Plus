<?php
/**
 * WP-CLI command surface. Only loaded when WP_CLI is defined.
 *
 *   wp mxchat-plus test              # ping the backend
 *   wp mxchat-plus stats             # vector count + metrics + last sync
 *   wp mxchat-plus sync               # full MySQL → DuckDB sync
 *   wp mxchat-plus reprocess          # walk WP posts through MxChat ingestion
 *   wp mxchat-plus compact            # run the orphan compactor now
 *   wp mxchat-plus metrics --reset    # reset metrics counters
 *   wp mxchat-plus cache --flush      # flush the query result cache
 *   wp mxchat-plus export --path=…    # Parquet dump of every vector
 *   wp mxchat-plus import --path=…    # Parquet restore (INSERT OR REPLACE)
 *   wp mxchat-plus async-reprocess --post-types=post,page
 *                                       # enqueue every post into Action Scheduler
 *   wp mxchat-plus migrate-from-pinecone --api-key=… --host=… [--namespace=…]
 *                                       # one-shot vector copy, no re-embedding
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

class MxChat_Plus_DuckDB_CLI {

    /**
     * Ping the active backend and report identity + row count.
     *
     * ## EXAMPLES
     *     wp mxchat-plus test
     */
    public function test(array $args, array $assoc_args): void { /* -param array<int, string> $args; -param array<string, string|true> $assoc_args */
        try {
            $conn = MxChat_Plus_DuckDB_Connection_Factory::current();
            $ok = $conn->ping();
            \WP_CLI::log('Backend: ' . $conn->identifier());
            \WP_CLI::log('Ping: ' . ($ok ? 'OK' : 'FAILED'));
            if (!$ok) \WP_CLI::error('Backend did not respond to SELECT 1.');

            $store = new MxChat_Plus_DuckDB_Vector_Store($conn);
            $store->ensure_schema();
            \WP_CLI::log('Vectors: ' . $store->count());
            \WP_CLI::success('Backend healthy.');
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Show counters, latency percentiles, and last sync/compaction timestamps.
     *
     * ## EXAMPLES
     *     wp mxchat-plus stats
     */
    public function stats(array $args, array $assoc_args): void { /* -param array<int, string> $args; -param array<string, string|true> $assoc_args */
        $opts = MxChat_Plus_DuckDB_Options::get();
        $metrics = MxChat_Plus_DuckDB_Metrics::snapshot();

        try {
            $store = new MxChat_Plus_DuckDB_Vector_Store();
            $count = $store->count();
        } catch (\Throwable $e) {
            $count = '?';
        }

        $rows = [
            ['key' => 'enabled',           'value' => $opts['enabled'] ? 'yes' : 'no'],
            ['key' => 'mode',              'value' => $opts['mode']],
            ['key' => 'embedding_dim',     'value' => (string) $opts['embedding_dim']],
            ['key' => 'vectors',           'value' => (string) $count],
            ['key' => 'searches',          'value' => (string) $metrics['searches']],
            ['key' => 'cache_hit_rate',    'value' => (string) $metrics['cache_hit_rate']],
            ['key' => 'p50_ms',            'value' => (string) $metrics['p50_ms']],
            ['key' => 'p95_ms',            'value' => (string) $metrics['p95_ms']],
            ['key' => 'p99_ms',            'value' => (string) $metrics['p99_ms']],
            ['key' => 'last_sync_at',      'value' => $opts['last_sync_at'] ? gmdate('c', (int) $opts['last_sync_at']) : 'never'],
            ['key' => 'last_compact_at',   'value' => !empty($opts['last_compact_at']) ? gmdate('c', (int) $opts['last_compact_at']) : 'never'],
            ['key' => 'last_error',        'value' => $opts['last_error'] ?: '-'],
        ];

        \WP_CLI\Utils\format_items('table', $rows, ['key', 'value']);
    }

    /**
     * Full MySQL → DuckDB sync. Identical to the "Sync now" admin button.
     *
     * ## OPTIONS
     *
     * [--native]
     * : Use the DuckDB-native fast path (ATTACH 'mysql' + single INSERT-SELECT
     *   with regex-parsed embeddings). Requires the DuckDB mysql extension.
     *   5–10× faster on large catalogues but skips the per-row PHP guards;
     *   if it fails for any reason, fall back to `wp mxchat-plus sync`
     *   without the flag.
     *
     * ## EXAMPLES
     *     wp mxchat-plus sync
     *     wp mxchat-plus sync --native
     */
    public function sync(array $args, array $assoc_args): void { /* -param array<int, string> $args; -param array<string, string|true> $assoc_args */
        if (isset($assoc_args['native'])) {
            try {
                $start = microtime(true);
                $count = (new MxChat_Plus_DuckDB_Mysql_Sync())->full_sync_native();
                $elapsed = round((microtime(true) - $start) * 1000);
                \WP_CLI::success(sprintf('Native sync: %d vectors in %d ms.', $count, $elapsed));
            } catch (\Throwable $e) {
                \WP_CLI::error('Native sync failed: ' . $e->getMessage()
                    . ' (re-run without --native to use the PHP fallback)');
            }
            return;
        }

        try {
            // The progress bar needs the total up-front; full_sync() only
            // discovers it on the first callback. Defer creation to that point
            // and tick by the delta on subsequent callbacks.
            $progress = null;
            $last_done = 0;
            $count = MxChat_Plus_DuckDB_Sync::instance()->full_sync(
                function ($done, $total) use (&$progress, &$last_done) {
                    if ($progress === null) {
                        $progress = \WP_CLI\Utils\make_progress_bar('Syncing', max(1, (int) $total));
                    }
                    $delta = max(0, (int) $done - $last_done);
                    if ($delta > 0) $progress->tick($delta);
                    $last_done = (int) $done;
                }
            );
            if ($progress) $progress->finish();
            \WP_CLI::success(sprintf('Synced %d vectors.', $count));
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Walk WP posts through MxChat's embedding pipeline.
     *
     * ## OPTIONS
     * [--post-types=<csv>]
     * : Comma-separated post types. Default: post,page
     *
     * [--batch=<n>]
     * : Posts per batch. Default: 10
     *
     * ## EXAMPLES
     *     wp mxchat-plus reprocess --post-types=post,page,product --batch=25
     */
    public function reprocess(array $args, array $assoc_args): void { /* -param array<int, string> $args; -param array<string, string|true> $assoc_args */
        $post_types_raw = (string) ($assoc_args['post-types'] ?? 'post,page');
        $post_types = array_filter(array_map('sanitize_key', array_map('trim', explode(',', $post_types_raw))));
        if (empty($post_types)) $post_types = ['post', 'page'];
        $batch = max(1, (int) ($assoc_args['batch'] ?? 10));

        $sync = MxChat_Plus_DuckDB_Sync::instance();
        $offset = 0;
        $processed = 0; $failed = 0; $total = null;
        $progress = null;

        do {
            // Default `$r` so a hypothetical WP_CLI::error() that doesn't
            // die (overridden in tests, or a stub) still leaves us with a
            // typed array rather than an undefined variable below.
            $r = ['total' => 0, 'processed' => 0, 'failed' => 0, 'next_offset' => null];
            try {
                $r = $sync->reprocess_posts($post_types, $batch, $offset);
            } catch (\Throwable $e) {
                if ($progress) $progress->finish();
                \WP_CLI::error($e->getMessage());
            }

            if ($total === null) {
                $total = (int) ($r['total'] ?? 0);
                $progress = \WP_CLI\Utils\make_progress_bar('Reprocessing', max(1, $total));
            }
            $processed += (int) ($r['processed'] ?? 0);
            $failed    += (int) ($r['failed'] ?? 0);
            $progress->tick((int) ($r['processed'] ?? 0) + (int) ($r['failed'] ?? 0));

            $offset = $r['next_offset'];
        } while ($offset !== null);

        if ($progress) $progress->finish();
        \WP_CLI::success(sprintf('Reprocessed: %d processed, %d failed.', $processed, $failed));
    }

    /**
     * Run the orphan compactor immediately.
     *
     * ## EXAMPLES
     *     wp mxchat-plus compact
     */
    public function compact(array $args, array $assoc_args): void { /* -param array<int, string> $args; -param array<string, string|true> $assoc_args */
        $r = MxChat_Plus_DuckDB_Compactor::instance()->run();
        if (!empty($r['ok'])) {
            \WP_CLI::success(sprintf('Pruned %d orphan vectors.', (int) ($r['deleted'] ?? 0)));
        } else {
            \WP_CLI::warning('Skipped: ' . ($r['reason'] ?? 'unknown'));
        }
    }

    /**
     * Inspect or reset rolling metrics.
     *
     * ## OPTIONS
     * [--reset]
     * : Clear all metrics counters and samples.
     *
     * ## EXAMPLES
     *     wp mxchat-plus metrics
     *     wp mxchat-plus metrics --reset
     */
    public function metrics(array $args, array $assoc_args): void { /* -param array<int, string> $args; -param array<string, string|true> $assoc_args */
        if (isset($assoc_args['reset'])) {
            MxChat_Plus_DuckDB_Metrics::reset();
            \WP_CLI::success('Metrics reset.');
            return;
        }
        $m = MxChat_Plus_DuckDB_Metrics::snapshot();
        $rows = [];
        foreach ($m as $k => $v) {
            $rows[] = ['key' => $k, 'value' => (string) $v];
        }
        \WP_CLI\Utils\format_items('table', $rows, ['key', 'value']);
    }

    /**
     * Flush the query result cache by bumping the generation counter.
     *
     * O(1) since v0.6.0 — existing transients become unreachable and expire
     * via their TTL; no `LIKE DELETE` over wp_options. See
     * MxChat_Plus_DuckDB_Cache::bump_cache_generation().
     *
     * ## OPTIONS
     * [--flush]
     * : Required for confirmation.
     *
     * ## EXAMPLES
     *     wp mxchat-plus cache --flush
     */
    public function cache(array $args, array $assoc_args): void { /* -param array<int, string> $args; -param array<string, string|true> $assoc_args */
        if (!isset($assoc_args['flush'])) {
            \WP_CLI::log('Pass --flush to confirm.');
            return;
        }
        $bots_before = count(MxChat_Plus_DuckDB_Cache::cache_generation_map());
        MxChat_Plus_DuckDB_Cache::flush_query_cache(); // null = flush every tracked bot
        \WP_CLI::success(sprintf(
            'Query cache flushed across %d bot(s). Existing cached results are now unreachable and will expire via TTL or the compactor sweep.',
            max(1, $bots_before)
        ));
    }

    /**
     * Export every vector to a Parquet file.
     *
     * ## OPTIONS
     * --path=<path>
     * : Output file path. Must be writable by the PHP process (or by MotherDuck
     *   when in cloud mode — see DuckDB COPY TO docs for remote URIs).
     *
     * ## EXAMPLES
     *     wp mxchat-plus export --path=/tmp/mxchat-backup.parquet
     */
    public function export(array $args, array $assoc_args): void { /* -param array<int, string> $args; -param array<string, string|true> $assoc_args */
        $path = (string) ($assoc_args['path'] ?? '');
        if ($path === '') \WP_CLI::error('Missing --path=<file>.');
        try {
            $store = new MxChat_Plus_DuckDB_Vector_Store();
            $count = $store->export_parquet($path);
            \WP_CLI::success(sprintf('Exported %d vectors to %s.', $count, $path));
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Import vectors from a Parquet file produced by `export`.
     *
     * ## OPTIONS
     * --path=<path>
     * : Input file path.
     *
     * ## EXAMPLES
     *     wp mxchat-plus import --path=/tmp/mxchat-backup.parquet
     */
    public function import(array $args, array $assoc_args): void { /* -param array<int, string> $args; -param array<string, string|true> $assoc_args */
        $path = (string) ($assoc_args['path'] ?? '');
        if ($path === '') \WP_CLI::error('Missing --path=<file>.');
        try {
            $store = new MxChat_Plus_DuckDB_Vector_Store();
            $count = $store->import_parquet($path);
            \WP_CLI::success(sprintf('Imported %d vectors from %s.', $count, $path));
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Enqueue every published post of the given types into Action Scheduler
     * for asynchronous reprocessing. Survives PHP timeouts on large catalogs.
     *
     * ## OPTIONS
     * [--post-types=<csv>]
     * : Default: post,page
     *
     * [--bot-id=<id>]
     * : Default: default
     *
     * ## EXAMPLES
     *     wp mxchat-plus async-reprocess --post-types=post,page,product
     */
    public function async_reprocess(array $args, array $assoc_args): void { /* -param array<int, string> $args; -param array<string, string|true> $assoc_args */
        $post_types_raw = (string) ($assoc_args['post-types'] ?? 'post,page');
        $post_types = array_filter(array_map('sanitize_key', array_map('trim', explode(',', $post_types_raw))));
        if (empty($post_types)) $post_types = ['post', 'page'];
        $bot_id = (string) ($assoc_args['bot-id'] ?? 'default');

        try {
            $r = MxChat_Plus_DuckDB_Async_Reprocess::instance()->enqueue_batch($post_types, $bot_id);
            \WP_CLI::success(sprintf(
                'Queued %d of %d posts. Action Scheduler will process them in the background. Run `wp action-scheduler run --group=mxchat-plus` to drain inline.',
                $r['scheduled'], $r['total']
            ));
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Migrate vectors from a Pinecone index into DuckDB. No re-embedding —
     * pulls existing vectors + metadata directly.
     *
     * ## OPTIONS
     * --api-key=<key>
     * --host=<host>
     * : The Pinecone index host (e.g. my-index-abcd.svc.us-east1-aws.pinecone.io).
     *
     * [--namespace=<ns>]
     *
     * ## EXAMPLES
     *     wp mxchat-plus migrate-from-pinecone \
     *         --api-key=pcsk_... \
     *         --host=my-index-abcd.svc.us-east1-aws.pinecone.io \
     *         --namespace=default
     */
    public function migrate_from_pinecone(array $args, array $assoc_args): void { /* -param array<int, string> $args; -param array<string, string|true> $assoc_args */
        $api_key = (string) ($assoc_args['api-key'] ?? '');
        $host    = (string) ($assoc_args['host'] ?? '');
        $ns      = (string) ($assoc_args['namespace'] ?? '');
        if ($api_key === '' || $host === '') {
            \WP_CLI::error('Both --api-key=<key> and --host=<host> are required.');
        }
        try {
            $migrator = new MxChat_Plus_DuckDB_Pinecone_Migrator($api_key, $host, $ns);
            $r = $migrator->run(function ($copied) {
                \WP_CLI::log(sprintf('… copied %d so far', $copied));
            });
            \WP_CLI::success(sprintf('Migration complete: %d copied, %d failed.', $r['copied'], $r['failed']));
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Manually trigger or step the MotherDuck → local mirror bootstrap.
     *
     * ## OPTIONS
     *
     * [--reset]
     * : Wipe bootstrap state before starting. Useful after a drift event
     *   or a failed run where you want to restart from scratch rather
     *   than resume from the persisted offset.
     *
     * [--step]
     * : Run a single Action-Scheduler tick inline (synchronous) instead
     *   of enqueueing. Lets ops step through the pipeline interactively;
     *   useful for debugging stuck bootstrap runs.
     *
     * ## EXAMPLES
     *     wp mxchat-plus mirror-bootstrap            # enqueue first tick + return
     *     wp mxchat-plus mirror-bootstrap --reset    # restart from offset 0
     *     wp mxchat-plus mirror-bootstrap --step     # run one batch synchronously
     */
    public function mirror_bootstrap(array $args, array $assoc_args): void {
        if (isset($assoc_args['reset'])) {
            MxChat_Plus_DuckDB_Mirror_Bootstrap::reset_state();
            \WP_CLI::log('Mirror bootstrap state cleared.');
        }

        if (isset($assoc_args['step'])) {
            try {
                $result = MxChat_Plus_DuckDB_Mirror_Bootstrap::instance()->tick();
                \WP_CLI::log(sprintf(
                    'status=%s processed=%d target=%s done=%s',
                    $result['status'],
                    $result['processed'],
                    $result['target'] === null ? 'unknown' : (string) $result['target'],
                    $result['done'] ? 'yes' : 'no'
                ));
                if (!empty($result['last_error'])) {
                    \WP_CLI::warning('last_error: ' . $result['last_error']);
                }
            } catch (\Throwable $e) {
                \WP_CLI::error($e->getMessage());
            }
            return;
        }

        if (!MxChat_Plus_DuckDB_Mirror_Bootstrap::start()) {
            \WP_CLI::error('Cannot start: motherduck_mirror_enabled is off, or mode is not motherduck.');
        }
        \WP_CLI::success('Mirror bootstrap queued. Use --step to run a tick inline, or watch Action Scheduler for progress.');
    }

    /**
     * Drain the mirror_pending queue (failed local writes) immediately
     * instead of waiting for the next 5-minute Action Scheduler tick.
     * Useful after diagnosing + fixing the underlying cause (disk
     * space, file permissions) to flush the backlog without delay.
     *
     * ## OPTIONS
     *
     * [--status]
     * : Show pending + quarantine counts without running a drain.
     *
     * ## EXAMPLES
     *     wp mxchat-plus mirror-drain
     *     wp mxchat-plus mirror-drain --status
     */
    public function mirror_drain(array $args, array $assoc_args): void {
        if (isset($assoc_args['status'])) {
            $state = MxChat_Plus_DuckDB_Mirrored_Connection::pending_state();
            \WP_CLI::log(sprintf(
                'pending=%d quarantine=%d drained_total=%d quarantine_total=%d',
                count($state['pending']),
                count($state['quarantine']),
                (int) $state['drained_total'],
                (int) $state['quarantine_total']
            ));
            return;
        }
        try {
            $result = MxChat_Plus_DuckDB_Mirror_Drain::instance()->run();
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
        if (!empty($result['skipped'])) {
            \WP_CLI::warning('Skipped: ' . ($result['reason'] ?? 'unknown'));
            return;
        }
        \WP_CLI::success(sprintf(
            'Drain: drained=%d retried=%d quarantined=%d remaining=%d',
            $result['drained'],
            $result['retried'],
            $result['quarantined'],
            $result['remaining']
        ));
    }

    /**
     * Run a drift check between MotherDuck (primary) and the local
     * mirror immediately, without waiting for the next daily tick.
     * Prints per-bot_id (count, signature) and flags divergence.
     *
     * ## EXAMPLES
     *     wp mxchat-plus mirror-drift-check
     */
    public function mirror_drift_check(array $args, array $assoc_args): void {
        try {
            $result = MxChat_Plus_DuckDB_Mirror_Drift_Check::instance()->run();
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
        if (!empty($result['skipped'])) {
            \WP_CLI::warning('Skipped: ' . ($result['reason'] ?? 'unknown'));
            return;
        }
        if (empty($result['drift'])) {
            \WP_CLI::success('No drift detected. Primary and local agree on every bot_id.');
            return;
        }
        $note = $result['recoverable_via_drain']
            ? ' (drainable — within SMALL_DIFFERENTIAL with pending entries; let drain catch up)'
            : ' (real drift — run mirror-bootstrap --reset to re-converge, or investigate manually)';
        \WP_CLI::warning('Drift on bot_ids=[' . implode(',', $result['drifted_bots']) . ']' . $note);

        // Print a per-bot table for visibility.
        $rows = [];
        $bots = array_unique(array_merge(
            array_keys($result['primary'] ?? []),
            array_keys($result['local']   ?? [])
        ));
        foreach ($bots as $bot) {
            $p = $result['primary'][$bot] ?? ['count' => 0, 'sig' => ''];
            $l = $result['local'][$bot]   ?? ['count' => 0, 'sig' => ''];
            $rows[] = [
                'bot_id'        => $bot,
                'primary_count' => (string) $p['count'],
                'local_count'   => (string) $l['count'],
                'primary_sig'   => substr($p['sig'], 0, 8),
                'local_sig'     => substr($l['sig'], 0, 8),
                'drift'         => in_array($bot, $result['drifted_bots'], true) ? 'YES' : '-',
            ];
        }
        \WP_CLI\Utils\format_items('table', $rows,
            ['bot_id', 'primary_count', 'local_count', 'primary_sig', 'local_sig', 'drift']);
    }
}

\WP_CLI::add_command('mxchat-plus duckdb', 'MxChat_Plus_DuckDB_CLI');
