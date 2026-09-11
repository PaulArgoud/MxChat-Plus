<?php

use PHPUnit\Framework\TestCase;

final class MetricsTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['__test_options'] = [];
        $GLOBALS['__test_transients'] = [];
        // The request buffer is a static that survives across tests in the
        // same process (and may be dirtied by any other test that issued a
        // query). reset() clears both the option and the static buffer.
        MxChat_Plus_DuckDB_Metrics::reset();
    }

    public function test_snapshot_is_empty_initially(): void {
        $s = MxChat_Plus_DuckDB_Metrics::snapshot();
        $this->assertSame(0, $s['searches']);
        $this->assertSame(0, $s['p50_ms']);
        $this->assertSame(0, $s['p95_ms']);
        $this->assertSame(0.0, $s['cache_hit_rate']);
    }

    public function test_observe_latency_increments_searches(): void {
        MxChat_Plus_DuckDB_Metrics::observe_latency(50);
        MxChat_Plus_DuckDB_Metrics::observe_latency(80);
        MxChat_Plus_DuckDB_Metrics::observe_latency(110);
        $s = MxChat_Plus_DuckDB_Metrics::snapshot();
        $this->assertSame(3, $s['searches']);
        $this->assertSame(3, $s['sample_count']);
    }

    public function test_percentiles_are_monotonic(): void {
        foreach ([10, 20, 30, 40, 50, 60, 70, 80, 90, 100] as $ms) {
            MxChat_Plus_DuckDB_Metrics::observe_latency($ms);
        }
        $s = MxChat_Plus_DuckDB_Metrics::snapshot();
        $this->assertLessThanOrEqual($s['p95_ms'], $s['p50_ms']);
        $this->assertLessThanOrEqual($s['p99_ms'], $s['p95_ms']);
        // p50 of 10..100 ≈ 60 (we pick the value at index 5 in a sorted 10-sample list)
        $this->assertGreaterThanOrEqual(50, $s['p50_ms']);
        $this->assertLessThanOrEqual(70, $s['p50_ms']);
    }

    public function test_cache_hit_rate_is_correct(): void {
        MxChat_Plus_DuckDB_Metrics::observe_latency(50); // 1 search
        MxChat_Plus_DuckDB_Metrics::record('query_cache_hit'); // 1 hit
        $s = MxChat_Plus_DuckDB_Metrics::snapshot();
        $this->assertSame(0.5, $s['cache_hit_rate']);
    }

    public function test_reset_clears_everything(): void {
        MxChat_Plus_DuckDB_Metrics::observe_latency(123);
        MxChat_Plus_DuckDB_Metrics::record('errors');
        MxChat_Plus_DuckDB_Metrics::reset();
        $s = MxChat_Plus_DuckDB_Metrics::snapshot();
        $this->assertSame(0, $s['searches']);
        $this->assertSame(0, $s['errors']);
    }

    public function test_negative_latency_is_clamped_to_zero(): void {
        MxChat_Plus_DuckDB_Metrics::observe_latency(-50);
        $s = MxChat_Plus_DuckDB_Metrics::snapshot();
        $this->assertGreaterThanOrEqual(0, $s['p50_ms']);
    }

    // ─── Write batching (v0.12.0+) ────────────────────────────────────────

    public function test_observe_and_record_do_not_write_the_option_until_flush(): void {
        MxChat_Plus_DuckDB_Metrics::observe_latency(42);
        MxChat_Plus_DuckDB_Metrics::record('query_cache_hit');
        // Nothing persisted yet — only the request buffer holds the data.
        $this->assertArrayNotHasKey(
            MxChat_Plus_DuckDB_Metrics::OPTION_KEY,
            $GLOBALS['__test_options'],
            'hot path must not touch wp_options before shutdown flush'
        );
        // …but snapshot() still reflects it via the buffer merge.
        $s = MxChat_Plus_DuckDB_Metrics::snapshot();
        $this->assertSame(1, $s['searches']);
        $this->assertSame(1, $s['cache_hits']);
    }

    public function test_flush_persists_buffer_and_is_idempotent(): void {
        MxChat_Plus_DuckDB_Metrics::observe_latency(10);
        MxChat_Plus_DuckDB_Metrics::record('errors', 2);
        MxChat_Plus_DuckDB_Metrics::flush();

        $this->assertArrayHasKey(MxChat_Plus_DuckDB_Metrics::OPTION_KEY, $GLOBALS['__test_options']);
        $persisted = $GLOBALS['__test_options'][MxChat_Plus_DuckDB_Metrics::OPTION_KEY];
        $this->assertSame(1, $persisted['counters']['searches']);
        $this->assertSame(2, $persisted['counters']['errors']);

        // A second flush (e.g. the shutdown hook after a manual flush) must not
        // double-count — the buffer was cleared.
        MxChat_Plus_DuckDB_Metrics::flush();
        $s = MxChat_Plus_DuckDB_Metrics::snapshot();
        $this->assertSame(1, $s['searches']);
        $this->assertSame(2, $s['errors']);
    }

    public function test_flush_merges_additively_onto_existing_persisted_counters(): void {
        // First request.
        MxChat_Plus_DuckDB_Metrics::observe_latency(15);
        MxChat_Plus_DuckDB_Metrics::flush();
        // Second request (new buffer, same process) adds on top.
        MxChat_Plus_DuckDB_Metrics::observe_latency(25);
        MxChat_Plus_DuckDB_Metrics::flush();

        $s = MxChat_Plus_DuckDB_Metrics::snapshot();
        $this->assertSame(2, $s['searches']);
        $this->assertSame(2, $s['sample_count']);
    }
}
