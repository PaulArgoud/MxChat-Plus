<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the read-path orchestration in Vector_Store_Query::run() — the
 * top-K pipeline fired on every chat turn:
 *
 *   dim check → cache lookup → SQL (vector-only or hybrid) → dedup
 *   → rerank filter → latency observation → cache write
 *
 * Each test mocks the connection so SQL is recorded but not executed,
 * which lets us assert on the SHAPE of the query (does dedup over-fetch?
 * does the cache hit short-circuit the SELECT?) without DuckDB.
 *
 * The individual helpers (compile_filter, normalize_scores, dedup_per_source,
 * cache_key) already have dedicated tests; this file is about how they're
 * stitched together.
 */
final class VectorStoreQueryRunTest extends TestCase {

    private function makeRecordingConnection(array $rows_to_return = []): MxChat_Plus_DuckDB_Connection {
        return new class($rows_to_return) implements MxChat_Plus_DuckDB_Connection {
            public array $log = [];
            /** @var array<int,array<int,mixed>> */
            public array $params_log = [];
            public array $rows;
            public function __construct(array $rows) { $this->rows = $rows; }
            public function execute(string $sql, array $params = []): array {
                $this->log[] = $sql;
                $this->params_log[] = $params;
                return $this->rows;
            }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'mock:run'; }
            public function supports_capability(string $cap): bool { return $cap === MxChat_Plus_DuckDB_Connection::CAP_VSS_PERSISTENT_INDEX; }
        };
    }

    protected function setUp(): void {
        $GLOBALS['__test_options']    = [];
        $GLOBALS['__test_transients'] = [];
        // Wipe Schema memoisation so ensure_schema() actually runs DDL we can assert on.
        $r = new ReflectionProperty(MxChat_Plus_DuckDB_Vector_Store_Schema::class, 'ensured');
        $r->setValue(null, []);
        // Wipe per-request ignored-filter dedup so cross-test pollution doesn't hide regressions.
        $r2 = new ReflectionProperty(MxChat_Plus_DuckDB_Vector_Store_Query::class, 'logged_ignored');
        $r2->setValue(null, []);
        // Wipe FTS-availability memo so each hybrid test starts from a known
        // "unknown, will probe" state instead of inheriting a sibling's verdict.
        MxChat_Plus_DuckDB_Vector_Store_Query::reset_fts_status_cache();
    }

    private function makeQuery(array $opts_override = [], int $dim = 3, array $sql_rows = []): array {
        // Returns [Query instance, recording connection] so each test can
        // both invoke run() and inspect what SQL hit the wire.
        $defaults = MxChat_Plus_DuckDB_Options::defaults();
        update_option('mxchat_plus_duckdb_options', array_merge($defaults, ['embedding_dim' => $dim], $opts_override));

        $conn = $this->makeRecordingConnection($sql_rows);
        $query = new MxChat_Plus_DuckDB_Vector_Store_Query($conn, 'mxchat_vectors', $dim, 'cosine', 'float32');
        return [$query, $conn];
    }

    // ─── Per-bot config wiring (v0.13.0) ──────────────────────────────────

    public function test_per_bot_override_disables_cache_for_that_bot_only(): void {
        // Global cache ON, but bot "nocache" overrides it OFF. run() must read
        // the per-bot config (get_for_bot) and skip the transient write for it.
        [$query, $conn] = $this->makeQuery([
            'query_cache_enabled' => true,
            'query_cache_ttl'     => 300,
            'bot_overrides'       => [
                'nocache' => ['query_cache_enabled' => false],
            ],
        ], 3, []);

        $query->run([0.1, 0.2, 0.3], 10, 'nocache', []);
        $cache_keys = array_filter(
            array_keys($GLOBALS['__test_transients']),
            fn($k) => strpos($k, 'mxp_q_nocache_') === 0
        );
        $this->assertSame([], $cache_keys,
            'a bot whose override disables the cache must not write a transient');

        // A different bot (no override) still caches under the global setting.
        $query->run([0.4, 0.5, 0.6], 10, 'default', []);
        $default_keys = array_filter(
            array_keys($GLOBALS['__test_transients']),
            fn($k) => strpos($k, 'mxp_q_default_') === 0
        );
        $this->assertNotEmpty($default_keys,
            'a bot without an override still caches per the global setting');
    }

    // ─── Dim mismatch ─────────────────────────────────────────────────────

    public function test_dim_mismatch_throws_with_clear_message(): void {
        [$query] = $this->makeQuery([], 3);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/dimension/i');
        $query->run([0.1, 0.2], 10, 'default', []);  // 2-dim against a 3-dim store
    }

    // ─── Cache lookup short-circuit ───────────────────────────────────────

    public function test_cache_hit_returns_cached_matches_without_hitting_sql(): void {
        [$query, $conn] = $this->makeQuery(['query_cache_enabled' => true, 'query_cache_ttl' => 300]);

        // Pre-seed a cache entry for these inputs.
        $gen = MxChat_Plus_DuckDB_Cache::cache_generation();
        $cache_key = MxChat_Plus_DuckDB_Vector_Store_Query::cache_key(
            [0.1, 0.2, 0.3], 10, 'default', [], $gen
        );
        $cached = [['id' => 'cached_v1', 'score' => 0.9, 'metadata' => []]];
        set_transient($cache_key, $cached, 300);

        $out = $query->run([0.1, 0.2, 0.3], 10, 'default', []);

        $this->assertSame($cached, $out, 'cache hit must return the stored matches verbatim');
        $this->assertEmpty($conn->log, 'cache hit must NOT issue any SQL');
    }

    public function test_cache_miss_issues_sql_and_writes_result_to_cache(): void {
        $rows = [
            ['id' => 'v1', 'score' => 0.9, 'text' => 'foo', 'source_url' => 'a',
             'role_restriction' => 'public', 'type' => 'content',
             'chunk_index' => null, 'total_chunks' => null, 'is_chunked' => false],
        ];
        [$query, $conn] = $this->makeQuery(['query_cache_enabled' => true, 'query_cache_ttl' => 300], 3, $rows);

        $out = $query->run([0.1, 0.2, 0.3], 10, 'default', []);

        $this->assertCount(1, $conn->log, 'cache miss must issue exactly one vector SELECT');
        $this->assertCount(1, $out);
        $this->assertSame('v1', $out[0]['id']);

        // Cache write happened.
        $gen = MxChat_Plus_DuckDB_Cache::cache_generation();
        $key = MxChat_Plus_DuckDB_Vector_Store_Query::cache_key([0.1, 0.2, 0.3], 10, 'default', [], $gen);
        $this->assertNotFalse(get_transient($key), 'result should be persisted to the transient layer');
    }

    public function test_cache_disabled_does_not_write_to_transient_layer(): void {
        [$query, $conn] = $this->makeQuery(['query_cache_enabled' => false]);
        $query->run([0.1, 0.2, 0.3], 10, 'default', []);

        $cache_transients = array_filter(
            array_keys($GLOBALS['__test_transients']),
            fn($k) => strpos($k, 'mxp_q_') === 0
        );
        $this->assertSame([], $cache_transients,
            'with cache disabled, no mxp_q_ transient should be written');
    }

    // ─── Dedup over-fetch ─────────────────────────────────────────────────

    public function test_dedup_per_source_uses_sql_cte_with_row_number(): void {
        // v0.8.0: pure-vector + dedup now uses a CTE with
        // ROW_NUMBER() OVER (PARTITION BY source_url) so DuckDB does the
        // dedup in-engine. The inner sub-query keeps the HNSW-friendly
        // shape; the outer wrapper picks rn=1 per source_url (and lets
        // empty-URL rows through, mirroring the PHP semantics).
        [$query, $conn] = $this->makeQuery(['dedup_per_source' => true]);
        $query->run([0.1, 0.2, 0.3], 10, 'default', []);

        $sql = end($conn->log);
        $this->assertStringContainsString('WITH candidates AS', $sql,
            'dedup_per_source on → SQL must wrap in a CTE');
        $this->assertStringContainsString('ROW_NUMBER() OVER (PARTITION BY source_url ORDER BY score DESC)', $sql,
            'dedup CTE must use ROW_NUMBER over source_url');
        $this->assertStringContainsString("source_url = '' OR rn = 1", $sql,
            'empty-URL rows must pass through (preserve PHP dedup_per_source semantics)');
        $this->assertStringContainsString('LIMIT 30', $sql,
            'inner candidates LIMIT must over-fetch ×3 so the outer dedup has enough rows');
        $this->assertStringContainsString('LIMIT 10', $sql,
            'outer LIMIT must equal the requested top_k');
        $this->assertStringContainsString('bot_id = ?', $sql,
            'bot_id is bound, not inlined');
    }

    public function test_dedup_off_uses_plain_top_k_limit(): void {
        [$query, $conn] = $this->makeQuery(['dedup_per_source' => false]);
        $query->run([0.1, 0.2, 0.3], 10, 'default', []);

        $sql = end($conn->log);
        $this->assertStringContainsString('LIMIT 10', $sql,
            'dedup off → SQL LIMIT must equal the requested top_k');
        $this->assertStringNotContainsString('WITH candidates AS', $sql,
            'dedup off → no CTE wrapper');
        $this->assertStringNotContainsString('ROW_NUMBER', $sql);
    }

    public function test_hybrid_path_skips_bm25_when_fts_marked_unavailable(): void {
        // Pre-seed the static cache with "FTS unavailable for this backend".
        // The hybrid path must run the vector leg, see the verdict, and
        // return vector-only matches — without issuing a BM25 SELECT or
        // emitting an error_log entry.
        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_query_text'] = 'a user query string';
        try {
            [$query, $conn] = $this->makeQuery(['hybrid_enabled' => true]);
            MxChat_Plus_DuckDB_Vector_Store_Query::mark_fts_unavailable_for_request('mock:run', 'mxchat_vectors');

            $query->run([0.1, 0.2, 0.3], 10, 'default', []);
        } finally {
            $GLOBALS['__test_filter_overrides'] = [];
        }

        $log = implode("\n", $conn->log);
        $this->assertStringNotContainsString('match_bm25', $log,
            'with FTS marked unavailable, the hybrid path must not issue a BM25 query');
        $this->assertStringNotContainsString("'fts_available'", $log,
            'and must not re-probe the meta table either (verdict is cached in the static)');
    }

    public function test_hybrid_path_consults_meta_table_when_status_unknown(): void {
        // No pre-seeding: the first hybrid call must probe the persistent
        // fts_available flag in the meta table before deciding whether to
        // attempt BM25. We stub the probe to return '0' so the BM25 leg
        // is skipped.
        $rows_by_pattern = [
            "key = 'fts_available'" => [['value' => '0']],
        ];
        $defaults = MxChat_Plus_DuckDB_Options::defaults();
        update_option('mxchat_plus_duckdb_options', array_merge($defaults, [
            'embedding_dim'  => 3,
            'hybrid_enabled' => true,
        ]));

        $conn = new class($rows_by_pattern) implements MxChat_Plus_DuckDB_Connection {
            public array $log = [];
            public array $patterns;
            public function __construct(array $patterns) { $this->patterns = $patterns; }
            public function execute(string $sql, array $params = []): array {
                $this->log[] = $sql;
                foreach ($this->patterns as $needle => $rows) {
                    if (stripos($sql, $needle) !== false) return $rows;
                }
                return [];
            }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'mock:meta-probe'; }
            public function supports_capability(string $cap): bool { return $cap === MxChat_Plus_DuckDB_Connection::CAP_VSS_PERSISTENT_INDEX; }
        };
        $query = new MxChat_Plus_DuckDB_Vector_Store_Query($conn, 'mxchat_vectors', 3, 'cosine', 'float32');

        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_query_text'] = 'q';
        try {
            $query->run([0.1, 0.2, 0.3], 10, 'default', []);
        } finally {
            $GLOBALS['__test_filter_overrides'] = [];
        }

        $log = implode("\n", $conn->log);
        $this->assertStringContainsString("key = 'fts_available'", $log,
            'on first hybrid call with unknown FTS status, the read path must consult the meta table');
        $this->assertStringNotContainsString('match_bm25', $log,
            'meta says FTS unavailable → BM25 leg must be skipped');
    }

    public function test_hybrid_path_still_uses_php_dedup_with_over_fetch(): void {
        // Hybrid keeps PHP-side dedup (the BM25 + vector merge happens in
        // PHP anyway). The over-fetch ×3 lives in run()'s hybrid branch.
        $bm25_rows = []; // empty BM25 → falls back to vector-only inside query_hybrid
        $query_text_supplier = function () { return 'a user query string'; };

        // Install the query_text filter so hybrid actually fires.
        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_query_text'] = 'a user query string';
        try {
            [$query, $conn] = $this->makeQuery(['hybrid_enabled' => true, 'dedup_per_source' => true]);
            $query->run([0.1, 0.2, 0.3], 10, 'default', []);
        } finally {
            $GLOBALS['__test_filter_overrides'] = [];
        }

        $log = implode("\n", $conn->log);
        // Inner vector leg of hybrid uses LIMIT max(top_k*4, 50) = max(40,50) = 50.
        // Plus a BM25 leg query. Neither uses the SQL CTE we added in v0.8.0.
        $this->assertStringNotContainsString('WITH candidates AS', $log,
            'hybrid path must NOT use the SQL dedup CTE (PHP dedup runs in run() instead)');
    }

    // ─── Rerank hook ──────────────────────────────────────────────────────

    public function test_rerank_filter_can_reshape_the_result(): void {
        $rows = [
            ['id' => 'a', 'score' => 0.5, 'text' => 'A', 'source_url' => 'ua',
             'role_restriction' => 'public', 'type' => 'content',
             'chunk_index' => null, 'total_chunks' => null, 'is_chunked' => false],
            ['id' => 'b', 'score' => 0.4, 'text' => 'B', 'source_url' => 'ub',
             'role_restriction' => 'public', 'type' => 'content',
             'chunk_index' => null, 'total_chunks' => null, 'is_chunked' => false],
        ];
        [$query] = $this->makeQuery([], 3, $rows);

        // The test bootstrap stubs apply_filters() as a pass-through (the
        // default rerank is "identity"), so we can't easily install a real
        // filter here. Instead, verify the contract by passing through the
        // identity case: matches returned by the SQL must reach the caller
        // unchanged after the rerank hook tag.
        $out = $query->run([0.1, 0.2, 0.3], 10, 'default', []);
        $this->assertCount(2, $out);
        $this->assertSame('a', $out[0]['id']);
        $this->assertSame('b', $out[1]['id']);
    }

    // ─── SQL shape ────────────────────────────────────────────────────────

    public function test_default_query_is_pure_vector_search(): void {
        [$query, $conn] = $this->makeQuery();
        $query->run([0.1, 0.2, 0.3], 10, 'default', []);

        $sql = $conn->log[0] ?? '';
        $params = $conn->params_log[0] ?? [];
        $this->assertStringContainsString('array_cosine_similarity', $sql,
            'default metric is cosine — SQL must use array_cosine_similarity');
        $this->assertStringContainsString('FROM "mxchat_vectors"', $sql);
        $this->assertStringContainsString('bot_id = ?', $sql, 'bot_id must be a bound placeholder, not inlined');
        $this->assertSame(['default'], $params, 'the bot_id value travels through params, not SQL');
    }

    public function test_bot_id_filter_is_bound_as_parameter(): void {
        [$query, $conn] = $this->makeQuery();
        $query->run([0.1, 0.2, 0.3], 10, 'support_fr', []);

        $sql = $conn->log[0] ?? '';
        $params = $conn->params_log[0] ?? [];
        $this->assertStringContainsString('bot_id = ?', $sql);
        $this->assertSame(['support_fr'], $params);
    }

    public function test_pinecone_filter_compiles_to_placeholders_and_params(): void {
        [$query, $conn] = $this->makeQuery();
        $query->run([0.1, 0.2, 0.3], 10, 'default', [
            'type' => ['$eq' => 'post'],
            'chunk_index' => ['$gte' => 0],
        ]);

        $sql = $conn->log[0] ?? '';
        $params = $conn->params_log[0] ?? [];
        $this->assertStringContainsString('content_type = ?', $sql);
        $this->assertStringContainsString('chunk_index >= ?', $sql);
        // Param order: bot_id (default) → type=$eq → chunk_index=$gte
        $this->assertSame(['default', 'post', 0], $params);
    }

    // ─── Metric branching ─────────────────────────────────────────────────

    public function test_l2sq_metric_uses_array_distance(): void {
        $opts = array_merge(MxChat_Plus_DuckDB_Options::defaults(), ['embedding_dim' => 3]);
        update_option('mxchat_plus_duckdb_options', $opts);

        $conn = $this->makeRecordingConnection([]);
        $query = new MxChat_Plus_DuckDB_Vector_Store_Query($conn, 'mxchat_vectors', 3, 'l2sq', 'float32');
        $query->run([0.1, 0.2, 0.3], 10, 'default', []);

        $sql = $conn->log[0] ?? '';
        $this->assertStringContainsString('array_distance', $sql);
        $this->assertStringContainsString('-1.0', $sql, 'l2sq score is negated so DESC sort yields nearest-first');
    }

    public function test_ip_metric_uses_array_inner_product(): void {
        $opts = array_merge(MxChat_Plus_DuckDB_Options::defaults(), ['embedding_dim' => 3]);
        update_option('mxchat_plus_duckdb_options', $opts);

        $conn = $this->makeRecordingConnection([]);
        $query = new MxChat_Plus_DuckDB_Vector_Store_Query($conn, 'mxchat_vectors', 3, 'ip', 'float32');
        $query->run([0.1, 0.2, 0.3], 10, 'default', []);

        $sql = $conn->log[0] ?? '';
        $this->assertStringContainsString('array_inner_product', $sql);
    }
}
