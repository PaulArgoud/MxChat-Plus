<?php
/**
 * Lightweight metrics aggregator.
 *
 * Two storage strategies:
 *   - Latency histogram: a rolling array of (timestamp, ms) samples kept in
 *     an option, capped at MAX_SAMPLES. Computes p50/p95 on demand.
 *   - Named counters: monotonic counts (searches, cache hits, errors, …)
 *     kept in the same option, reset only by explicit user action.
 *
 * Storage as a single non-autoloaded option avoids hammering the DB with
 * transient writes and makes the data trivially exportable.
 *
 * ## Write batching (v0.12.0+)
 *
 * `observe_latency()` / `record()` are on the hottest path in the plugin —
 * every chatbot query calls at least one, and a cache *hit* still records a
 * counter. The previous implementation did a read-modify-write of the option
 * (plus an O(n) window trim) on every single call, which meant:
 *   - 1–3 `update_option` writes to `wp_options` per chatbot message, defeating
 *     part of the query cache (a hit still wrote a row); and
 *   - a lost-update race under concurrency (two requests read, both append,
 *     both write → samples/counters dropped) plus `wp_options` lock contention.
 *
 * Now the calls only append to a request-scoped static buffer, and a single
 * merge-write happens once per request on `shutdown`. Reads (`snapshot()`)
 * merge the persisted option with the pending buffer so within-request reads
 * stay consistent. This drops writes from N-per-request to 1-per-request and
 * moves the window trim off the hot path. A residual cross-request race
 * remains (two concurrent flushes can still clobber), but it is bounded to one
 * write per request instead of one per query — acceptable for observability
 * data that is approximate by design.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Metrics {

    const OPTION_KEY = 'mxchat_plus_duckdb_metrics';
    const MAX_SAMPLES = 500;
    /** Drop samples older than this from the rolling window. */
    const SAMPLE_TTL_SECONDS = 3600;

    /**
     * Request-buffered latency samples, flushed to the option on shutdown.
     * @var array<int,array{0:int,1:int}>
     */
    private static array $pending_latency = [];

    /**
     * Request-buffered counter deltas, merged additively into the option on
     * shutdown.
     * @var array<string,int>
     */
    private static array $pending_counters = [];

    /** Guards one-time registration of the shutdown flush per request. */
    private static bool $flush_registered = false;

    public static function observe_latency(int $ms): void {
        self::$pending_latency[] = [time(), max(0, $ms)];
        self::$pending_counters['searches'] = (self::$pending_counters['searches'] ?? 0) + 1;
        self::register_flush();
    }

    public static function record(string $counter, int $delta = 1): void {
        self::$pending_counters[$counter] = (self::$pending_counters[$counter] ?? 0) + $delta;
        self::register_flush();
    }

    /**
     * Register the shutdown flush exactly once per request. Guarded by a static
     * so repeated observe/record calls don't stack handlers. In a non-WP
     * context (unit tests) `add_action` is a no-op shim; `snapshot()` still sees
     * the buffer via merge, and tests reset the buffer in setUp.
     */
    private static function register_flush(): void {
        if (self::$flush_registered) {
            return;
        }
        self::$flush_registered = true;
        if (function_exists('add_action')) {
            add_action('shutdown', [self::class, 'flush'], 99);
        }
    }

    /**
     * Merge the request buffer into the persisted option in a single
     * read-modify-write, then clear the buffer. Idempotent: a manual call
     * followed by the shutdown hook firing is a no-op the second time.
     */
    public static function flush(): void {
        if (empty(self::$pending_latency) && empty(self::$pending_counters)) {
            return;
        }
        $data = self::load();
        foreach (self::$pending_latency as $s) {
            $data['latency'][] = $s;
        }
        foreach (self::$pending_counters as $name => $delta) {
            $data['counters'][$name] = ($data['counters'][$name] ?? 0) + $delta;
        }
        $data['latency'] = self::trim_window($data['latency']);
        self::save($data);
        self::$pending_latency  = [];
        self::$pending_counters = [];
    }

    /**
     * Trim the rolling latency window by age then by count. Runs once per
     * request at flush time rather than on every observe() call. Entries are
     * validated defensively (the window is loaded from an option that could be
     * corrupted/hand-edited), so a malformed sample is dropped rather than
     * fatal'ing the flush.
     *
     * @param array<int,mixed> $latency
     * @return array<int,mixed>
     */
    private static function trim_window(array $latency): array {
        $cutoff = time() - self::SAMPLE_TTL_SECONDS;
        $latency = array_values(array_filter(
            $latency,
            static fn($s) => is_array($s) && (int) ($s[0] ?? 0) >= $cutoff
        ));
        if (count($latency) > self::MAX_SAMPLES) {
            $latency = array_slice($latency, -self::MAX_SAMPLES);
        }
        return $latency;
    }

    /**
     * Returns ['searches' => n, 'p50_ms' => int, 'p95_ms' => int,
     *          'cache_hit_rate' => float, 'errors' => n, 'window_seconds' => …]
     *
     * Note: `searches` counts cache *misses* (incremented only by
     * observe_latency, which runs after the cache check). `cache_hit_rate`
     * accounts for that by using `hits / (misses + hits)` as the denominator.
     */
    public static function snapshot(): array {
        $data = self::merged();
        $samples = array_map(fn($s) => (int) $s[1], $data['latency'] ?? []);
        sort($samples, SORT_NUMERIC);
        $n = count($samples);
        $searches = (int) ($data['counters']['searches'] ?? 0);
        $cache_hits = (int) ($data['counters']['query_cache_hit'] ?? 0);

        return [
            'searches'       => $searches,
            'p50_ms'         => $n ? $samples[(int) ($n * 0.50)] : 0,
            'p95_ms'         => $n ? $samples[(int) min($n - 1, $n * 0.95)] : 0,
            'p99_ms'         => $n ? $samples[(int) min($n - 1, $n * 0.99)] : 0,
            'sample_count'   => $n,
            'cache_hits'     => $cache_hits,
            'cache_hit_rate' => $searches > 0 ? round($cache_hits / max(1, $searches + $cache_hits), 3) : 0.0,
            'errors'         => (int) ($data['counters']['errors'] ?? 0),
            'window_seconds' => self::SAMPLE_TTL_SECONDS,
        ];
    }

    public static function reset(): void {
        self::$pending_latency  = [];
        self::$pending_counters = [];
        delete_option(self::OPTION_KEY);
    }

    private static function load(): array {
        $data = get_option(self::OPTION_KEY, []);
        if (!is_array($data)) $data = [];
        if (!isset($data['latency']) || !is_array($data['latency'])) $data['latency'] = [];
        if (!isset($data['counters']) || !is_array($data['counters'])) $data['counters'] = [];
        return $data;
    }

    /**
     * Persisted option overlaid with the not-yet-flushed request buffer, so
     * reads issued mid-request (admin stats, tests) reflect work already
     * observed this request. Pure — never mutates the buffer or the option.
     *
     * @return array<string,mixed>
     */
    private static function merged(): array {
        $data = self::load();
        foreach (self::$pending_latency as $s) {
            $data['latency'][] = $s;
        }
        foreach (self::$pending_counters as $name => $delta) {
            $data['counters'][$name] = ($data['counters'][$name] ?? 0) + $delta;
        }
        return $data;
    }

    private static function save(array $data): void {
        update_option(self::OPTION_KEY, $data, false);
    }
}
