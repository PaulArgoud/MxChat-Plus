<?php
/**
 * Async reprocess driver — delegates to Action Scheduler when available.
 *
 * Why Action Scheduler:
 *   - The synchronous AJAX-batched path in MxChat_Plus_DuckDB_Sync::reprocess_posts()
 *     dies on PHP `max_execution_time` for catalogs with thousands of posts
 *     and chunky `the_content` filter chains (ACF, page builders).
 *   - Action Scheduler is bundled with WooCommerce and used by dozens of
 *     popular plugins; it's the de-facto background queue on WP.
 *   - We don't hard-depend on it: when AS isn't loaded the admin UI shows a
 *     notice + the AJAX path keeps working as a fallback.
 *
 * Lifecycle:
 *   1. Admin clicks "Reprocess all posts (async)" → REST/AJAX endpoint
 *      schedules one `as_enqueue_async_action('mxchat_plus_duckdb_reprocess_post')`
 *      per post id in a single transient batch.
 *   2. Action Scheduler runs jobs in 25-action batches via its own cron.
 *   3. Each job calls `MxChat_Plus_DuckDB_Sync::reprocess_single_post($post_id, $bot_id)`.
 *   4. Progress (done / total / failed) is read off Action Scheduler's
 *      `as_get_scheduled_actions()` API by the admin UI.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Async_Reprocess {

    const ACTION_HOOK   = 'mxchat_plus_duckdb_reprocess_post';
    const GROUP         = 'mxchat-plus';
    /** Stored under a single non-autoloaded option so the UI can show a job-wide summary. */
    const STATE_OPTION  = 'mxchat_plus_duckdb_reprocess_state';

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function register_hooks(): void {
        // Worker hook — called by Action Scheduler for each enqueued post.
        add_action(self::ACTION_HOOK, [$this, 'process_post'], 10, 2);
    }

    public static function is_available(): bool {
        return function_exists('as_enqueue_async_action')
            && function_exists('as_get_scheduled_actions');
    }

    /**
     * Enqueue every published post of the requested types into Action Scheduler.
     * Returns the number of jobs scheduled.
     *
     * @throws RuntimeException when Action Scheduler isn't loaded.
     */
    public function enqueue_batch(array $post_types, string $bot_id = 'default'): array {
        if (!self::is_available()) {
            throw new RuntimeException(
                __('Action Scheduler is not active. Install/activate WooCommerce or the standalone Action Scheduler plugin, or use the synchronous reprocess instead.', 'mxchat-plus')
            );
        }

        $q = new WP_Query([
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        $ids = array_map('intval', $q->posts);
        $count = 0;
        foreach ($ids as $post_id) {
            // De-dup: skip if a job for this post id is already pending.
            $existing = as_get_scheduled_actions([
                'hook'     => self::ACTION_HOOK,
                'args'     => [$post_id, $bot_id],
                'status'   => 'pending',
                'per_page' => 1,
            ], 'ids');
            if (!empty($existing)) continue;

            as_enqueue_async_action(self::ACTION_HOOK, [$post_id, $bot_id], self::GROUP);
            $count++;
        }

        $state = [
            'started_at'  => time(),
            'finished_at' => 0,
            'total'       => count($ids),
            'enqueued'    => $count,
            'processed'   => 0,
            'failed'      => 0,
            'bot_id'      => $bot_id,
            'post_types'  => $post_types,
        ];
        update_option(self::STATE_OPTION, $state, false);

        return ['scheduled' => $count, 'total' => count($ids)];
    }

    /**
     * Action Scheduler worker callback. Routes to the synchronous single-post
     * helper on MxChat_Plus_DuckDB_Sync.
     */
    public function process_post(int $post_id, string $bot_id = 'default'): void {
        try {
            MxChat_Plus_DuckDB_Sync::instance()->reprocess_single_post($post_id, $bot_id);
            $this->bump_counter('processed');
        } catch (\Throwable $e) {
            $this->bump_counter('failed');
            error_log('[mxchat-plus] async reprocess post ' . $post_id . ': ' . $e->getMessage());
            throw $e; // Let Action Scheduler record the failure for retry/inspection.
        }
    }

    /** Cheap atomic-ish counter (one option write per job). */
    private function bump_counter(string $key): void {
        $state = (array) get_option(self::STATE_OPTION, []);
        $state[$key] = (int) ($state[$key] ?? 0) + 1;
        if ($this->is_complete($state)) {
            $state['finished_at'] = time();
        }
        update_option(self::STATE_OPTION, $state, false);
    }

    private function is_complete(array $state): bool {
        $done = (int) ($state['processed'] ?? 0) + (int) ($state['failed'] ?? 0);
        return $done >= (int) ($state['enqueued'] ?? 0);
    }

    /**
     * Snapshot for the admin UI. Returns null when no batch has ever run.
     */
    public static function status(): ?array {
        $state = get_option(self::STATE_OPTION, null);
        if (!is_array($state)) return null;

        // Cross-reference with Action Scheduler for in-flight numbers.
        $pending = 0;
        if (self::is_available()) {
            $pending = (int) as_get_scheduled_actions([
                'hook'   => self::ACTION_HOOK,
                'status' => 'pending',
                'per_page' => 1,
            ], 'count');
        }

        $done = (int) ($state['processed'] ?? 0) + (int) ($state['failed'] ?? 0);
        $total = (int) ($state['enqueued'] ?? 0);
        $state['pending'] = $pending;
        $state['done']    = $done;
        $state['percent'] = $total > 0 ? min(100, (int) round(100 * $done / $total)) : 0;
        $state['is_running'] = $total > 0 && $done < $total;
        return $state;
    }

    /** Cancel every pending job in our group. */
    public static function cancel_all(): int {
        if (!self::is_available() || !function_exists('as_unschedule_all_actions')) return 0;
        $count = as_unschedule_all_actions(self::ACTION_HOOK);
        $state = (array) get_option(self::STATE_OPTION, []);
        $state['cancelled_at'] = time();
        update_option(self::STATE_OPTION, $state, false);
        return (int) $count;
    }
}
