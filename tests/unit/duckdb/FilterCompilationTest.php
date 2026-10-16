<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the Pinecone-style filter compiler. Each op should produce a
 * predictable SQL fragment with `?` placeholders + an aligned params
 * array.
 *
 * 1.0.0 (#69): `$and` / `$or` nest, `{"field": scalar}` means `$eq`, and
 * `$exists` is supported. A positive clause on a field we do not store fails
 * CLOSED (`FALSE`) — Pinecone filters on any metadata key and records
 * without it fail the clause — where it used to be dropped, which WIDENED
 * the result set. An unsupported operator or a malformed filter throws
 * InvalidArgumentException (Pinecone answers HTTP 400).
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

    public function test_in_with_empty_array_matches_nothing(): void {
        // Nothing is in the empty set; dropping the clause matched everything.
        $store = $this->makeQuery();
        $this->assertSame([['FALSE'], []], $this->compile($store, ['type' => ['$in' => []]]));
    }

    public function test_nin_with_empty_array_adds_no_constraint(): void {
        $store = $this->makeQuery();
        $this->assertSame([[], []], $this->compile($store, ['type' => ['$nin' => []]]));
    }

    public function test_range_operators_on_chunk_index(): void {
        $store = $this->makeQuery();
        [$fragments, $params] = $this->compile($store, [
            'chunk_index' => ['$gte' => 0, '$lt' => 10],
        ]);
        $this->assertSame(['chunk_index >= ?', 'chunk_index < ?'], $fragments);
        $this->assertSame([0, 10], $params);
    }

    public function test_positive_clause_on_an_unknown_field_fails_closed(): void {
        // An add-on restricting retrieval to `product_line = x` must get no
        // rows rather than the unfiltered top-K.
        $store = $this->makeQuery();
        $this->assertSame([['FALSE'], []], $this->compile($store, ['some_random_field' => ['$eq' => 'x']]));
        $this->assertSame([['FALSE'], []], $this->compile($store, ['some_random_field' => ['$in' => ['x']]]));
        $this->assertSame([['FALSE'], []], $this->compile($store, ['some_random_field' => ['$exists' => true]]));
    }

    public function test_negative_clause_on_an_unknown_field_holds_for_every_row(): void {
        // No stored row carries the field, so `!= x` / `not in` / `not
        // exists` are true for all of them: no constraint.
        $store = $this->makeQuery();
        $this->assertSame([[], []], $this->compile($store, ['some_random_field' => ['$ne' => 'x']]));
        $this->assertSame([[], []], $this->compile($store, ['some_random_field' => ['$nin' => ['x']]]));
        $this->assertSame([[], []], $this->compile($store, ['some_random_field' => ['$exists' => false]]));
    }

    public function test_unknown_field_is_counted(): void {
        $GLOBALS['__test_options'] = [];
        MxChat_Plus_DuckDB_Metrics::reset();
        $this->compile($this->makeQuery(), ['nope' => ['$eq' => 'x']]);
        MxChat_Plus_DuckDB_Metrics::flush();
        $data = get_option(MxChat_Plus_DuckDB_Metrics::OPTION_KEY, []);
        $this->assertSame(1, (int) ($data['counters']['filter_unknown_field'] ?? 0));
        MxChat_Plus_DuckDB_Metrics::reset();
    }

    public function test_unknown_operator_throws(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('$unknown');
        $this->compile($this->makeQuery(), ['type' => ['$unknown' => 'x']]);
    }

    public function test_unknown_top_level_operator_throws(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->compile($this->makeQuery(), ['$not' => [['type' => 'x']]]);
    }

    public function test_scalar_shorthand_means_eq(): void {
        // MxChat's own idiom elsewhere: {"type": "product"}.
        $store = $this->makeQuery();
        [$fragments, $params] = $this->compile($store, ['type' => 'product', 'is_chunked' => true]);
        $this->assertSame(['content_type = ?', 'is_chunked = ?'], $fragments);
        $this->assertSame(['product', true], $params);
    }

    public function test_and_or_nest_into_parenthesised_groups(): void {
        // MxChat_Pinecone_Documents::merge_filters() returns
        // ['$and' => [$a, $b]] (MxChat 3.2.25).
        $store = $this->makeQuery();
        [$fragments, $params] = $this->compile($store, [
            '$and' => [
                ['type' => ['$eq' => 'product']],
                ['$or' => [
                    ['source_url' => ['$in' => ['https://a', 'https://b']]],
                    ['chunk_index' => ['$gte' => 2], 'title' => 'Specs'],
                ]],
            ],
        ]);
        $this->assertSame(
            ['((content_type = ?) AND (((source_url IN (?,?)) OR (chunk_index >= ? AND title = ?))))'],
            $fragments
        );
        $this->assertSame(['product', 'https://a', 'https://b', 2, 'Specs'], $params,
            'params follow placeholder order across the nesting');
    }

    public function test_or_branch_on_an_unknown_field_is_false_not_dropped(): void {
        // Dropping a failing $or branch would be harmless, but dropping a
        // failing $and member would widen — both compile to FALSE.
        $store = $this->makeQuery();
        [$fragments] = $this->compile($store, ['$or' => [['nope' => 'x'], ['type' => 'post']]]);
        $this->assertSame(['((FALSE) OR (content_type = ?))'], $fragments);
        [$fragments] = $this->compile($store, ['$and' => [['nope' => 'x'], ['type' => 'post']]]);
        $this->assertSame(['((FALSE) AND (content_type = ?))'], $fragments);
    }

    public function test_exists_operator(): void {
        $store = $this->makeQuery();
        [$fragments, $params] = $this->compile($store, ['title' => ['$exists' => true], 'parent_url_hash' => ['$exists' => false]]);
        $this->assertSame(['title IS NOT NULL', 'parent_url_hash IS NULL'], $fragments);
        $this->assertSame([], $params);
    }

    public function test_host_timestamp_fields_map_to_the_unix_columns(): void {
        $store = $this->makeQuery();
        [$fragments, $params] = $this->compile($store, ['last_updated' => ['$gte' => 1700000000], 'created_at' => ['$lt' => 1800000000]]);
        $this->assertSame(['last_updated_unix >= ?', 'created_unix < ?'], $fragments);
        $this->assertSame([1700000000, 1800000000], $params);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function malformedFilters(): array {
        return [
            '$and not a list'         => [['$and' => ['type' => 'x']]],
            '$or empty'               => [['$or' => []]],
            '$and of scalars'         => [['$and' => ['x', 'y']]],
            'operator list'           => [['type' => ['post', 'page']]],
            'empty operator object'   => [['type' => []]],
            '$in of a scalar'         => [['type' => ['$in' => 'post']]],
            '$in of objects'          => [['type' => ['$in' => [['a' => 1]]]]],
            '$eq of a list'           => [['type' => ['$eq' => ['post']]]],
            '$exists of a string'     => [['title' => ['$exists' => 'yes']]],
        ];
    }

    /**
     * @dataProvider malformedFilters
     * @param array<string, mixed> $filter
     */
    public function test_malformed_filter_throws(array $filter): void {
        $this->expectException(InvalidArgumentException::class);
        $this->compile($this->makeQuery(), $filter);
    }

    public function test_nesting_is_bounded(): void {
        $filter = ['type' => 'x'];
        for ($i = 0; $i < MxChat_Plus_DuckDB_Vector_Store_Query::FILTER_MAX_DEPTH + 2; $i++) {
            $filter = ['$and' => [$filter]];
        }
        $this->expectException(InvalidArgumentException::class);
        $this->compile($this->makeQuery(), $filter);
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

    public function test_filter_returning_unsafe_column_name_fails_closed_not_injected(): void {
        $store = $this->makeQuery();
        // A hostile/buggy callback maps a field to an injection payload.
        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_filter_fields'] = [
            'evil' => 'content_type = 1; DROP TABLE x; --',
        ];
        [$fragments, $params] = $this->compile($store, ['evil' => ['$eq' => 'x']]);
        $this->assertSame(['FALSE'], $fragments, 'unsafe column name must never reach SQL — the clause matches nothing');
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
