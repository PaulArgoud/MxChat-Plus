<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks MxChat_Plus_DuckDB_Options::scheduled_hooks() — the SINGLE source of
 * truth for every recurring job the DuckDB module schedules.
 *
 * Why this test exists: the hook list used to be hard-coded in three places
 * (the scheduling classes themselves, the deactivation routine and
 * uninstall-duckdb.php). Deactivation's copy was missing
 * `mxchat_plus_duckdb_reprocess_post` (group 'mxchat-plus'), so disabling the
 * plugin left Action Scheduler jobs queued with no worker left to run them —
 * they stayed pending forever and fired the moment the plugin came back.
 *
 * The assertions below are deliberately derived from the classes' OWN
 * constants, so:
 *   - adding a cron/AS job without declaring it in scheduled_hooks() fails;
 *   - renaming a hook constant without updating the map fails;
 *   - declaring a hook with the wrong Action Scheduler group fails.
 */
final class ScheduledHooksTest extends TestCase {

    /**
     * The full expected map, spelled out from the class constants (never from
     * string literals — a literal here would just be a fourth copy).
     *
     * @return array<string, string> hook => AS group ('' = WP-cron)
     */
    private function expected(): array {
        return [
            MxChat_Plus_DuckDB_Sync::CRON_HOOK                 => '',
            MxChat_Plus_DuckDB_Compactor::CRON_HOOK            => '',
            MxChat_Plus_DuckDB_Async_Reprocess::ACTION_HOOK    => MxChat_Plus_DuckDB_Async_Reprocess::GROUP,
            MxChat_Plus_DuckDB_Mirror_Bootstrap::ACTION_HOOK   => MxChat_Plus_DuckDB_Mirror_Bootstrap::ACTION_GROUP,
            MxChat_Plus_DuckDB_Mirror_Drain::ACTION_HOOK       => MxChat_Plus_DuckDB_Mirror_Drain::ACTION_GROUP,
            MxChat_Plus_DuckDB_Mirror_Drift_Check::ACTION_HOOK => MxChat_Plus_DuckDB_Mirror_Drift_Check::ACTION_GROUP,
        ];
    }

    public function test_scheduled_hooks_matches_the_class_constants_exactly(): void {
        $actual   = MxChat_Plus_DuckDB_Options::scheduled_hooks();
        $expected = $this->expected();

        ksort($actual);
        ksort($expected);

        $this->assertSame($expected, $actual,
            'scheduled_hooks() must list every scheduling class hook, with its own group');
    }

    /**
     * The regression that motivated the fix: the async post-reprocess job is an
     * Action Scheduler job in group 'mxchat-plus' and MUST be cancellable from
     * the shared list, not only from uninstall's private copy.
     */
    public function test_async_reprocess_job_is_declared_with_its_group(): void {
        $hooks = MxChat_Plus_DuckDB_Options::scheduled_hooks();

        $this->assertArrayHasKey(MxChat_Plus_DuckDB_Async_Reprocess::ACTION_HOOK, $hooks,
            'the async reprocess action must be in the shared unschedule list');
        $this->assertSame(
            MxChat_Plus_DuckDB_Async_Reprocess::GROUP,
            $hooks[MxChat_Plus_DuckDB_Async_Reprocess::ACTION_HOOK],
            'an Action Scheduler hook is only cancellable together with its group'
        );
    }

    public function test_wp_cron_jobs_carry_no_action_scheduler_group(): void {
        $hooks = MxChat_Plus_DuckDB_Options::scheduled_hooks();

        $this->assertSame('', $hooks[MxChat_Plus_DuckDB_Sync::CRON_HOOK]);
        $this->assertSame('', $hooks[MxChat_Plus_DuckDB_Compactor::CRON_HOOK]);
    }

    /**
     * Every declared hook must be a non-empty, plugin-namespaced string —
     * catches a class constant that was emptied or renamed out of the
     * `mxchat_plus_` namespace (host-plugin hooks must never appear here).
     */
    public function test_every_declared_hook_is_namespaced_and_non_empty(): void {
        foreach (MxChat_Plus_DuckDB_Options::scheduled_hooks() as $hook => $group) {
            $this->assertNotSame('', $hook);
            $this->assertStringStartsWith('mxchat_plus_', $hook);
            if ($group !== '') {
                $this->assertStringStartsWith('mxchat-plus', $group);
            }
        }
    }

    /**
     * unschedule_all() must clear WP-cron for every hook and call Action
     * Scheduler (with the hook's group and empty args, which cancels every
     * queued instance whatever arguments it carries) for the AS ones.
     */
    public function test_unschedule_all_cancels_every_declared_action_scheduler_job(): void {
        $GLOBALS['__test_as_queue'] = [];

        $hooks = MxChat_Plus_DuckDB_Options::scheduled_hooks();
        $this->assertNotEmpty($hooks);

        // Queue one pending action per Action-Scheduler-backed hook…
        $as_hooks = [];
        foreach ($hooks as $hook => $group) {
            if ($group === '') continue;
            $as_hooks[] = $hook;
            as_enqueue_async_action($hook, ['post_id' => 7], $group);
        }
        $this->assertNotEmpty($as_hooks, 'the module does schedule Action Scheduler work');

        // …and an unrelated third-party job that must survive untouched.
        as_enqueue_async_action('some_other_plugin_job', [], 'other-group');

        MxChat_Plus_DuckDB_Options::unschedule_all();

        foreach ($GLOBALS['__test_as_queue'] as $action) {
            if (in_array($action['hook'], $as_hooks, true)) {
                $this->assertSame('cancelled', $action['status'],
                    "{$action['hook']} must be cancelled on deactivation/uninstall");
            } else {
                $this->assertSame('pending', $action['status'],
                    'unschedule_all() must not touch another plugin\'s actions');
            }
        }
    }
}
