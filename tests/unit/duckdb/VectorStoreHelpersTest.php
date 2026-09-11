<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests the pure-PHP utility methods on Vector_Store. Most are private static
 * — accessed via reflection. The goal is to lock the behaviour of the easy-
 * to-regress logic: filter compilation, score normalisation, dedup, caching.
 */
final class VectorStoreHelpersTest extends TestCase {

    /**
     * The helpers tested below moved from MxChat_Plus_DuckDB_Vector_Store to
     * MxChat_Plus_DuckDB_Vector_Store_Query in the post-v0.4 refactor — they
     * stay private static there so reflection is still the right escape hatch.
     */
    private static function call(string $method, array $args = []) {
        $r = new ReflectionMethod(MxChat_Plus_DuckDB_Vector_Store_Query::class, $method);
        return $r->invokeArgs(null, $args);
    }

    // ─── normalize_scores ───────────────────────────────────────────────

    public function test_normalize_scores_returns_empty_for_empty_input(): void {
        $this->assertSame([], self::call('normalize_scores', [[]]));
    }

    public function test_normalize_scores_maps_to_zero_one_range(): void {
        $rows = [
            ['id' => 'a', 'score' => 10.0],
            ['id' => 'b', 'score' => 5.0],
            ['id' => 'c', 'score' => 0.0],
        ];
        $norm = self::call('normalize_scores', [$rows]);
        $this->assertEqualsWithDelta(1.0, $norm['a'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $norm['b'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $norm['c'], 1e-9);
    }

    public function test_normalize_scores_handles_constant_scores(): void {
        // When all scores are equal the range is 0 and we degrade to 1.0
        // so every row contributes its full weight (no silent zeroing).
        $rows = [
            ['id' => 'a', 'score' => 4.2],
            ['id' => 'b', 'score' => 4.2],
        ];
        $norm = self::call('normalize_scores', [$rows]);
        $this->assertSame(1.0, $norm['a']);
        $this->assertSame(1.0, $norm['b']);
    }

    public function test_normalize_scores_handles_negatives(): void {
        $rows = [
            ['id' => 'a', 'score' => -1.0],
            ['id' => 'b', 'score' => 1.0],
        ];
        $norm = self::call('normalize_scores', [$rows]);
        $this->assertSame(0.0, $norm['a']);
        $this->assertSame(1.0, $norm['b']);
    }

    // ─── dedup_per_source ───────────────────────────────────────────────

    public function test_dedup_per_source_keeps_first_per_url(): void {
        $matches = [
            ['id' => '1', 'score' => 0.9, 'metadata' => ['source_url' => 'a']],
            ['id' => '2', 'score' => 0.8, 'metadata' => ['source_url' => 'a']],
            ['id' => '3', 'score' => 0.7, 'metadata' => ['source_url' => 'b']],
        ];
        $out = self::call('dedup_per_source', [$matches, 10]);
        $this->assertCount(2, $out);
        $this->assertSame('1', $out[0]['id']);
        $this->assertSame('3', $out[1]['id']);
    }

    public function test_dedup_per_source_preserves_urlless_rows(): void {
        $matches = [
            ['id' => '1', 'score' => 0.9, 'metadata' => ['source_url' => '']],
            ['id' => '2', 'score' => 0.8, 'metadata' => ['source_url' => '']],
        ];
        $out = self::call('dedup_per_source', [$matches, 10]);
        // Both should survive — empty URLs aren't a dedup key.
        $this->assertCount(2, $out);
    }

    public function test_dedup_per_source_respects_top_k(): void {
        $matches = [
            ['id' => '1', 'score' => 0.9, 'metadata' => ['source_url' => 'a']],
            ['id' => '2', 'score' => 0.8, 'metadata' => ['source_url' => 'b']],
            ['id' => '3', 'score' => 0.7, 'metadata' => ['source_url' => 'c']],
        ];
        $out = self::call('dedup_per_source', [$matches, 2]);
        $this->assertCount(2, $out);
    }

    // ─── cache_key ──────────────────────────────────────────────────────

    public function test_cache_key_is_deterministic(): void {
        $emb = [0.1, 0.2, 0.3];
        $a = self::call('cache_key', [$emb, 10, 'default', ['type' => ['$eq' => 'post']]]);
        $b = self::call('cache_key', [$emb, 10, 'default', ['type' => ['$eq' => 'post']]]);
        $this->assertSame($a, $b);
    }

    public function test_cache_key_changes_with_embedding(): void {
        $a = self::call('cache_key', [[0.1, 0.2], 10, 'default', []]);
        $b = self::call('cache_key', [[0.1, 0.3], 10, 'default', []]);
        $this->assertNotSame($a, $b);
    }

    public function test_cache_key_changes_with_bot_id(): void {
        $emb = [0.1, 0.2];
        $a = self::call('cache_key', [$emb, 10, 'bot_a', []]);
        $b = self::call('cache_key', [$emb, 10, 'bot_b', []]);
        $this->assertNotSame($a, $b);
    }

    public function test_cache_key_changes_with_top_k(): void {
        $emb = [0.1, 0.2];
        $a = self::call('cache_key', [$emb, 10, 'default', []]);
        $b = self::call('cache_key', [$emb, 50, 'default', []]);
        $this->assertNotSame($a, $b);
    }

    public function test_cache_key_uses_mxp_q_prefix(): void {
        $emb = [0.1];
        $key = self::call('cache_key', [$emb, 1, 'x', []]);
        $this->assertStringStartsWith('mxp_q_', $key);
    }

    // ─── quote_ident throw on mangle ─────────────────────────────────────

    public function test_quote_ident_throws_on_unsafe_identifier(): void {
        $dummy = new class implements MxChat_Plus_DuckDB_Connection {
            public function execute(string $sql, array $params = []): array { return []; }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'mock:trait'; }
            public function supports_capability(string $cap): bool { return $cap === MxChat_Plus_DuckDB_Connection::CAP_VSS_PERSISTENT_INDEX; }
        };
        $query = new MxChat_Plus_DuckDB_Vector_Store_Query($dummy, 'safe_name', 3, 'cosine', 'float32');

        $r = new ReflectionMethod(MxChat_Plus_DuckDB_Vector_Store_Query::class, 'quote_ident');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unsafe identifier/i');
        $r->invokeArgs($query, ['has-a-dash']);
    }

    public function test_quote_ident_throws_on_empty_identifier(): void {
        $dummy = new class implements MxChat_Plus_DuckDB_Connection {
            public function execute(string $sql, array $params = []): array { return []; }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'mock:trait'; }
            public function supports_capability(string $cap): bool { return $cap === MxChat_Plus_DuckDB_Connection::CAP_VSS_PERSISTENT_INDEX; }
        };
        $query = new MxChat_Plus_DuckDB_Vector_Store_Query($dummy, 'safe_name', 3, 'cosine', 'float32');

        $r = new ReflectionMethod(MxChat_Plus_DuckDB_Vector_Store_Query::class, 'quote_ident');

        $this->expectException(InvalidArgumentException::class);
        $r->invokeArgs($query, ['']);
    }

    public function test_quote_ident_accepts_alphanumeric_underscore(): void {
        $dummy = new class implements MxChat_Plus_DuckDB_Connection {
            public function execute(string $sql, array $params = []): array { return []; }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'mock:trait'; }
            public function supports_capability(string $cap): bool { return $cap === MxChat_Plus_DuckDB_Connection::CAP_VSS_PERSISTENT_INDEX; }
        };
        $query = new MxChat_Plus_DuckDB_Vector_Store_Query($dummy, 'safe_name', 3, 'cosine', 'float32');

        $r = new ReflectionMethod(MxChat_Plus_DuckDB_Vector_Store_Query::class, 'quote_ident');

        $this->assertSame('"mxchat_vectors"', $r->invokeArgs($query, ['mxchat_vectors']));
        $this->assertSame('"my_view_1234"', $r->invokeArgs($query, ['my_view_1234']));
    }
}
