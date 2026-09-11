<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the Action-Scheduler-driven async reprocess path — the only
 * sane way to reprocess a multi-thousand-post catalog without hitting
 * PHP's max_execution_time. The contract here is:
 *
 *   - is_available() correctly detects Action Scheduler presence;
 *   - enqueue_batch() queues one job per post id, dedups against already-
 *     pending jobs, and persists a job-wide state snapshot;
 *   - process_post() routes to Sync::reprocess_single_post, bumps the
 *     right counter, and re-throws so AS can record + retry;
 *   - status() merges the persisted snapshot with the live pending count;
 *   - cancel_all() removes pending jobs and stamps cancelled_at.
 *
 * The Action Scheduler API is shimmed in tests/bootstrap.php — a fake
 * queue lives in $GLOBALS['__test_as_queue'] and the as_* functions
 * read/write it.
 */
final class AsyncReprocessTest extends TestCase {

    private MxChat_Plus_DuckDB_Async_Reprocess $async;

    protected function setUp(): void {
        $GLOBALS['__test_options']         = [];
        $GLOBALS['__test_transients']      = [];
        $GLOBALS['__test_as_queue']        = [];
        $GLOBALS['__test_wp_query_matcher'] = null;
        $GLOBALS['__test_posts']           = [];
        MxChat_Utils::$submit_calls = [];
        MxChat_Utils::$submit_returns = true;

        $this->async = MxChat_Plus_DuckDB_Async_Reprocess::instance();
    }

    // ─── is_available ─────────────────────────────────────────────────────

    public function test_is_available_returns_true_when_as_functions_exist(): void {
        // Bootstrap declares as_enqueue_async_action + as_get_scheduled_actions,
        // so is_available() must return true under tests.
        $this->assertTrue(MxChat_Plus_DuckDB_Async_Reprocess::is_available());
    }

    // ─── enqueue_batch ────────────────────────────────────────────────────

    public function test_enqueue_batch_queues_one_job_per_post_and_persists_state(): void {
        $GLOBALS['__test_wp_query_matcher'] = function (array $args) {
            return ['posts' => [101, 102, 103]];
        };

        $r = $this->async->enqueue_batch(['post', 'page'], 'default');

        $this->assertSame(['scheduled' => 3, 'total' => 3], $r);
        $this->assertCount(3, $GLOBALS['__test_as_queue'],
            'one Action Scheduler job per post id');

        // Every queued job must target our worker hook with the right shape.
        foreach ($GLOBALS['__test_as_queue'] as $job) {
            $this->assertSame(MxChat_Plus_DuckDB_Async_Reprocess::ACTION_HOOK, $job['hook']);
            $this->assertSame(MxChat_Plus_DuckDB_Async_Reprocess::GROUP, $job['group']);
            $this->assertCount(2, $job['args'], '[post_id, bot_id]');
        }

        $state = get_option(MxChat_Plus_DuckDB_Async_Reprocess::STATE_OPTION);
        $this->assertSame(3, $state['total']);
        $this->assertSame(3, $state['enqueued']);
        $this->assertSame(0, $state['processed']);
        $this->assertSame(0, $state['failed']);
        $this->assertSame('default', $state['bot_id']);
        $this->assertNotEmpty($state['started_at']);
    }

    public function test_enqueue_batch_dedupes_against_already_pending_jobs(): void {
        // Pre-seed the queue with a pending job for post 102 → enqueue_batch
        // must skip 102 but still queue 101 and 103.
        $GLOBALS['__test_as_queue'][999] = [
            'hook'   => MxChat_Plus_DuckDB_Async_Reprocess::ACTION_HOOK,
            'args'   => [102, 'default'],
            'group'  => MxChat_Plus_DuckDB_Async_Reprocess::GROUP,
            'status' => 'pending',
        ];
        $GLOBALS['__test_wp_query_matcher'] = function () {
            return ['posts' => [101, 102, 103]];
        };

        $r = $this->async->enqueue_batch(['post'], 'default');

        $this->assertSame(2, $r['scheduled'], 'one already pending → 2 new jobs queued');
        $this->assertSame(3, $r['total'], 'total still reflects what WP_Query returned');

        $queued_post_ids = array_map(
            fn($job) => $job['args'][0],
            array_filter($GLOBALS['__test_as_queue'], fn($j) => $j['status'] === 'pending')
        );
        sort($queued_post_ids);
        $this->assertSame([101, 102, 103], $queued_post_ids);
    }

    public function test_enqueue_batch_throws_when_action_scheduler_is_unavailable(): void {
        // The "unavailable" branch checks function_exists('as_enqueue_async_action').
        // In the test environment we always shim those functions, and the
        // production code calls self::is_available() (not static::), so a
        // subclass override doesn't help either. This branch is impossible
        // to exercise from a unit test without runkit-style monkey-patching;
        // exercise it manually by activating the plugin on a WP install
        // without Action Scheduler. Marking skipped (not omitted) so the
        // intent stays visible in the suite output.
        $this->markTestSkipped(
            'Cannot un-declare global functions in PHP; production uses self::is_available() so subclassing does not help.'
        );
    }

    // ─── process_post (the worker hook) ───────────────────────────────────

    public function test_process_post_routes_to_single_post_reprocess_and_increments_processed(): void {
        // Seed Sync's post_reprocessor entry point: get_post + permalink +
        // an API key in mxchat_options so submit_content_to_db() is reached.
        $GLOBALS['__test_posts'][7] = new WP_Post([
            'ID' => 7, 'post_title' => 'hi', 'post_content' => 'body',
            'post_type' => 'post', 'post_status' => 'publish',
        ]);
        $GLOBALS['__test_permalinks'][7] = 'https://example.com/?p=7';
        update_option('mxchat_options', ['api_key' => 'sk-test', 'embedding_model' => 'text-embedding-3-small']);

        update_option(MxChat_Plus_DuckDB_Async_Reprocess::STATE_OPTION, [
            'started_at' => time(), 'finished_at' => 0, 'total' => 1, 'enqueued' => 1,
            'processed' => 0, 'failed' => 0, 'bot_id' => 'default', 'post_types' => ['post'],
        ]);

        $this->async->process_post(7, 'default');

        $state = get_option(MxChat_Plus_DuckDB_Async_Reprocess::STATE_OPTION);
        $this->assertSame(1, $state['processed'], 'processed counter bumped after a successful run');
        $this->assertSame(0, $state['failed']);
        // finished_at stamped because processed+failed (1) reached enqueued (1).
        $this->assertNotEmpty($state['finished_at']);
    }

    public function test_process_post_rethrows_on_failure_so_action_scheduler_can_retry(): void {
        $GLOBALS['__test_posts'][8] = new WP_Post(['ID' => 8, 'post_content' => 'x']);
        $GLOBALS['__test_permalinks'][8] = 'https://example.com/?p=8';
        update_option('mxchat_options', ['api_key' => 'sk-test', 'embedding_model' => 'text-embedding-3-small']);

        // Force MxChat_Utils::submit_content_to_db to return a WP_Error
        // → the post_reprocessor throws → async catches, bumps failed,
        //   and re-throws so Action Scheduler records the failure.
        MxChat_Utils::$submit_returns = new WP_Error('e', 'embedding API down');

        update_option(MxChat_Plus_DuckDB_Async_Reprocess::STATE_OPTION, [
            'enqueued' => 1, 'processed' => 0, 'failed' => 0,
        ]);

        try {
            $this->async->process_post(8, 'default');
            $this->fail('process_post should have re-thrown on failure');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('embedding API down', $e->getMessage());
        }

        $state = get_option(MxChat_Plus_DuckDB_Async_Reprocess::STATE_OPTION);
        $this->assertSame(1, $state['failed'], 'failed counter bumped');
        $this->assertSame(0, $state['processed']);
    }

    // ─── status() snapshot ────────────────────────────────────────────────

    public function test_status_returns_null_when_no_batch_has_run(): void {
        $this->assertNull(MxChat_Plus_DuckDB_Async_Reprocess::status());
    }

    public function test_status_merges_state_with_pending_count_and_percent(): void {
        update_option(MxChat_Plus_DuckDB_Async_Reprocess::STATE_OPTION, [
            'enqueued' => 100, 'processed' => 60, 'failed' => 5,
        ]);
        // 35 still-pending jobs in the queue.
        for ($i = 0; $i < 35; $i++) {
            $GLOBALS['__test_as_queue'][] = [
                'hook' => MxChat_Plus_DuckDB_Async_Reprocess::ACTION_HOOK,
                'args' => [$i, 'default'],
                'status' => 'pending',
                'group' => MxChat_Plus_DuckDB_Async_Reprocess::GROUP,
            ];
        }

        $s = MxChat_Plus_DuckDB_Async_Reprocess::status();
        $this->assertSame(35, $s['pending']);
        $this->assertSame(65, $s['done'], 'processed + failed');
        $this->assertSame(65, $s['percent'], '65 done / 100 enqueued');
        $this->assertTrue($s['is_running']);
    }

    public function test_status_marks_not_running_when_done_equals_enqueued(): void {
        update_option(MxChat_Plus_DuckDB_Async_Reprocess::STATE_OPTION, [
            'enqueued' => 10, 'processed' => 8, 'failed' => 2,
        ]);
        $s = MxChat_Plus_DuckDB_Async_Reprocess::status();
        $this->assertFalse($s['is_running']);
        $this->assertSame(100, $s['percent']);
    }

    // ─── cancel_all ───────────────────────────────────────────────────────

    public function test_cancel_all_marks_pending_jobs_as_cancelled_and_stamps_cancelled_at(): void {
        for ($i = 0; $i < 5; $i++) {
            $GLOBALS['__test_as_queue'][] = [
                'hook'   => MxChat_Plus_DuckDB_Async_Reprocess::ACTION_HOOK,
                'args'   => [$i, 'default'],
                'status' => 'pending',
                'group'  => MxChat_Plus_DuckDB_Async_Reprocess::GROUP,
            ];
        }
        update_option(MxChat_Plus_DuckDB_Async_Reprocess::STATE_OPTION, ['enqueued' => 5]);

        $cancelled = MxChat_Plus_DuckDB_Async_Reprocess::cancel_all();

        $this->assertSame(5, $cancelled);
        $remaining_pending = array_filter($GLOBALS['__test_as_queue'], fn($j) => $j['status'] === 'pending');
        $this->assertEmpty($remaining_pending);

        $state = get_option(MxChat_Plus_DuckDB_Async_Reprocess::STATE_OPTION);
        $this->assertNotEmpty($state['cancelled_at']);
    }
}
