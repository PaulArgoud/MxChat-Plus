<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the Pinecone-style filter compiler. Each op should produce a
 * predictable SQL fragment with `?` placeholders + an aligned params
 * array; unknown ops/fields are silently dropped.
 *
 * v0.8.1: filter values moved from inlined literals to bound parameters
 * so the connection's prepared-statement path can take over on the
 * native extension. The CLI path inlines the `?`s back to safe literals
 * via inline_params — observable behavior is identical, but the
 * audit surface shrinks because filter values no longer reach the
 * SQL string directly.
 */
final class FilterCompilationTest extends TestCase {

    /**
     * The filter compiler lives on MxChat_Plus_DuckDB_Vector_Store_Query after
     * the post-v0.4 split. It's `public static` (the trait's `literal_for`
     * needs an instance) so we can call it directly given a Query instance
     * constructed with a no-op connection.
     */
    private function makeQuery(): MxChat_Plus_DuckDB_Vector_Store_Query {
        $dummy = new class implements MxChat_Plus_DuckDB_Connection {
            public function execute(string $sql, array $params = []): array { return []; }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'test'; }
            public function supports_capability(string $cap): bool { return $cap === MxChat_Plus_DuckDB_Connection::CAP_VSS_PERSISTENT_INDEX; }
        };
        return new MxChat_Plus_DuckDB_Vector_Store_Query($dummy, 'mxchat_vectors', 3, 'cosine', 'float32');
    }

    /**
     * @return array{0: string[], 1: array<int,mixed>}
     */
    private function compile(MxChat_Plus_DuckDB_Vector_Store_Query $store, array $filter): array {
        return MxChat_Plus_DuckDB_Vector_Store_Query::compile_filter($filter, $store);
    }

    public function test_empty_filter_returns_no_fragments_and_no_params(): void {
        $store = $this->makeQuery();
        $this->assertSame([[], []], $this->compile($store, []));
    }

    public function test_eq_on_content_type(): void {
        $store = $this->makeQuery();
        [$fragments, $params] = $this->compile($store, ['type' => ['$eq' => 'post']]);
        $this->assertSame(['content_type = ?'], $fragments);
        $this->assertSame(['post'], $params);
    }

    public function test_eq_field_alias_content_type(): void {
        $store = $this->makeQuery();
        [$fragments, $params] = $this->compile($store, ['content_type' => ['$eq' => 'page']]);
        $this->assertSame(['content_type = ?'], $fragments);
        $this->assertSame(['page'], $params);
    }

    public function test_ne_operator(): void {
        $store = $this->makeQuery();
        [$fragments, $params] = $this->compile($store, ['role_restriction' => ['$ne' => 'public']]);
        $this->assertSame(['role_restriction <> ?'], $fragments);
        $this->assertSame(['public'], $params);
    }

    public function test_in_operator_with_strings(): void {
        $store = $this->makeQuery();
        [$fragments, $params] = $this->compile($store, ['type' => ['$in' => ['post', 'page']]]);
        $this->assertSame(['content_type IN (?,?)'], $fragments);
        $this->assertSame(['post', 'page'], $params);
    }

    public function test_nin_operator(): void {
        $store = $this->makeQuery();
        [$fragments, $params] = $this->compile($store, ['type' => ['$nin' => ['private']]]);
        $this->assertSame(['content_type NOT IN (?)'], $fragments);
        $this->assertSame(['private'], $params);
    }

    public function test_in_with_empty_array_is_dropped(): void {
        $store = $this->makeQuery();
        $this->assertSame([[], []], $this->compile($store, ['type' => ['$in' => []]]));
    }

    public function test_range_operators_on_chunk_index(): void {
        $store = $this->makeQuery();
        [$fragments, $params] = $this->compile($store, [
            'chunk_index' => ['$gte' => 0, '$lt' => 10],
        ]);
        $this->assertSame(['chunk_index >= ?', 'chunk_index < ?'], $fragments);
        $this->assertSame([0, 10], $params);
    }

    public function test_unknown_field_is_silently_dropped(): void {
        $store = $this->makeQuery();
        $this->assertSame([[], []], $this->compile($store, ['some_random_field' => ['$eq' => 'x']]));
    }

    public function test_unknown_operator_is_silently_dropped(): void {
        $store = $this->makeQuery();
        $this->assertSame([[], []], $this->compile($store, ['type' => ['$unknown' => 'x']]));
    }

    public function test_string_values_are_passed_through_unescaped(): void {
        // With prepared statements, the value is bound as-is — no need to
        // escape quotes in PHP. The connection's binder (or inline_params
        // for CLI fallback) handles escaping at the SQL layer.
        $store = $this->makeQuery();
        [$fragments, $params] = $this->compile($store, ['source_url' => ['$eq' => "https://x/?q=O'Hara"]]);
        $this->assertSame(['source_url = ?'], $fragments);
        $this->assertSame(["https://x/?q=O'Hara"], $params,
            'the value reaches the params array verbatim — escaping is the connection-layer responsibility');
    }

    public function test_multiple_ops_on_same_field_compose(): void {
        $store = $this->makeQuery();
        [$fragments, $params] = $this->compile($store, [
            'chunk_index' => ['$gte' => 5, '$lte' => 10, '$ne' => 7],
        ]);
        $this->assertCount(3, $fragments);
        $this->assertSame([5, 10, 7], $params,
            'params order must match the left-to-right placeholder order in the assembled SQL');
    }

    // ─── Extensible allowed-fields filter (v0.12.0) ───────────────────────

    protected function tearDown(): void {
        $GLOBALS['__test_filter_overrides'] = [];
    }

    public function test_addon_can_register_extra_filterable_column(): void {
        $store = $this->makeQuery();
        // Add-on exposes a custom column "language" => "lang_code".
        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_filter_fields'] = [
            'language' => 'lang_code',
        ];
        [$fragments, $params] = $this->compile($store, ['language' => ['$eq' => 'fr']]);
        $this->assertSame(['lang_code = ?'], $fragments);
        $this->assertSame(['fr'], $params);
    }

    public function test_filter_returning_unsafe_column_name_is_dropped_not_injected(): void {
        $store = $this->makeQuery();
        // A hostile/buggy callback maps a field to an injection payload.
        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_filter_fields'] = [
            'evil' => 'content_type = 1; DROP TABLE x; --',
        ];
        [$fragments, $params] = $this->compile($store, ['evil' => ['$eq' => 'x']]);
        $this->assertSame([], $fragments, 'unsafe column name must never reach SQL');
        $this->assertSame([], $params);
    }

    public function test_non_array_filter_override_falls_back_to_defaults(): void {
        $store = $this->makeQuery();
        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_filter_fields'] = 'not-an-array';
        // Defaults still apply, so the built-in "type" field keeps working.
        [$fragments, $params] = $this->compile($store, ['type' => ['$eq' => 'post']]);
        $this->assertSame(['content_type = ?'], $fragments);
        $this->assertSame(['post'], $params);
    }
}
