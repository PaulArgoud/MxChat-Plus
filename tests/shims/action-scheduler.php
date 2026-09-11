<?php
/**
 * Action Scheduler shims — a fake queue lives in $GLOBALS['__test_as_queue']
 * and the as_* functions read/write it. Used by AsyncReprocessTest and any
 * test that exercises the v0.4.0 async reprocess path.
 *
 * Queue entries: ['hook' => string, 'args' => array, 'group' => string,
 * 'status' => 'pending'|'cancelled'].
 */

if (!function_exists('as_enqueue_async_action')) {
    function as_enqueue_async_action($hook, $args = [], $group = '') {
        if (!isset($GLOBALS['__test_as_queue'])) $GLOBALS['__test_as_queue'] = [];
        $id = count($GLOBALS['__test_as_queue']) + 1;
        $GLOBALS['__test_as_queue'][$id] = compact('hook', 'args', 'group') + ['status' => 'pending'];
        return $id;
    }
}

if (!function_exists('as_get_scheduled_actions')) {
    function as_get_scheduled_actions(array $args = [], $return = '') {
        $queue = $GLOBALS['__test_as_queue'] ?? [];
        $filtered = array_filter($queue, function ($a) use ($args) {
            if (isset($args['hook'])   && $a['hook']   !== $args['hook'])   return false;
            if (isset($args['status']) && $a['status'] !== $args['status']) return false;
            if (isset($args['args'])   && $a['args']   !== $args['args'])   return false;
            return true;
        });
        if ($return === 'ids')   return array_keys($filtered);
        if ($return === 'count') return count($filtered);
        return $filtered;
    }
}

if (!function_exists('as_unschedule_all_actions')) {
    function as_unschedule_all_actions($hook = '', $args = [], $group = '') {
        $count = 0;
        $queue = $GLOBALS['__test_as_queue'] ?? [];
        foreach ($queue as $id => $a) {
            if (($hook === '' || $a['hook'] === $hook) && $a['status'] === 'pending') {
                $GLOBALS['__test_as_queue'][$id]['status'] = 'cancelled';
                $count++;
            }
        }
        return $count;
    }
}

if (!function_exists('as_next_scheduled_action')) {
    function as_next_scheduled_action($hook = '', $args = [], $group = '') {
        $queue = $GLOBALS['__test_as_queue'] ?? [];
        foreach ($queue as $a) {
            if ($a['hook'] === $hook && $a['status'] === 'pending') {
                return $a['scheduled_at'] ?? time();
            }
        }
        return false;
    }
}

if (!function_exists('as_schedule_recurring_action')) {
    function as_schedule_recurring_action($timestamp, $interval, $hook, $args = [], $group = '') {
        if (!isset($GLOBALS['__test_as_queue'])) $GLOBALS['__test_as_queue'] = [];
        $id = count($GLOBALS['__test_as_queue']) + 1;
        $GLOBALS['__test_as_queue'][$id] = [
            'hook'         => $hook,
            'args'         => $args,
            'group'        => $group,
            'status'       => 'pending',
            'scheduled_at' => $timestamp,
            'interval'     => $interval,
            'recurring'    => true,
        ];
        return $id;
    }
}
