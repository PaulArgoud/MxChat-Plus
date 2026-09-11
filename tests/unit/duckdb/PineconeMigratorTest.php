<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the data-shape translation layer of MxChat_Plus_DuckDB_Pinecone_Migrator —
 * the parts that don't actually touch the Pinecone HTTP API or DuckDB. The
 * full migration loop is integration territory (real HTTP + real DB) and
 * is out of scope here; what we lock are the pure transforms that translate
 * between Pinecone's response shape and our internal vector row shape.
 *
 * A regression in `pinecone_to_row` silently corrupts every imported vector;
 * a regression in `normalise_host` produces malformed HTTPS URLs. Both
 * would surface as cryptic 4xx/5xx during a migration users would only
 * trigger once — exactly the kind of bug a test should catch up-front.
 */
final class PineconeMigratorTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['__test_options']    = [];
        $GLOBALS['__test_transients'] = [];
    }

    private function makeMigrator(string $host = 'my-index.svc.aws.pinecone.io'): MxChat_Plus_DuckDB_Pinecone_Migrator {
        return new MxChat_Plus_DuckDB_Pinecone_Migrator('pcsk_test', $host, 'default');
    }

    private function callPrivate(MxChat_Plus_DuckDB_Pinecone_Migrator $m, string $method, array $args) {
        $r = new ReflectionMethod(MxChat_Plus_DuckDB_Pinecone_Migrator::class, $method);
        return $r->invokeArgs($m, $args);
    }

    private function callPrivateStatic(string $method, array $args) {
        $r = new ReflectionMethod(MxChat_Plus_DuckDB_Pinecone_Migrator::class, $method);
        return $r->invokeArgs(null, $args);
    }

    // ─── Constructor guards ───────────────────────────────────────────────

    public function test_constructor_throws_on_empty_api_key(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/API key/i');
        new MxChat_Plus_DuckDB_Pinecone_Migrator('', 'my-index.svc.aws.pinecone.io');
    }

    public function test_constructor_throws_on_whitespace_only_api_key(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/API key/i');
        new MxChat_Plus_DuckDB_Pinecone_Migrator('   ', 'my-index.svc.aws.pinecone.io');
    }

    public function test_constructor_throws_on_empty_host(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/host/i');
        new MxChat_Plus_DuckDB_Pinecone_Migrator('pcsk_test', '');
    }

    public function test_constructor_accepts_well_formed_inputs(): void {
        $m = new MxChat_Plus_DuckDB_Pinecone_Migrator('pcsk_test', 'my-index.svc.aws.pinecone.io', 'default');
        $this->assertInstanceOf(MxChat_Plus_DuckDB_Pinecone_Migrator::class, $m);
    }

    // ─── normalise_host ───────────────────────────────────────────────────

    public function test_normalise_host_strips_scheme_and_trailing_slashes(): void {
        // Operators paste hosts in every imaginable shape — full URL, bare
        // host, trailing slash. normalise_host is what stops a typo from
        // turning every request into `https://https://my-index/query`.
        $cases = [
            ['my-index.svc.aws.pinecone.io',              'my-index.svc.aws.pinecone.io'],
            ['https://my-index.svc.aws.pinecone.io',      'my-index.svc.aws.pinecone.io'],
            ['http://my-index.svc.aws.pinecone.io',       'my-index.svc.aws.pinecone.io'],
            ['https://my-index.svc.aws.pinecone.io/',     'my-index.svc.aws.pinecone.io'],
            ['https://my-index.svc.aws.pinecone.io///',   'my-index.svc.aws.pinecone.io'],
            ['  https://my-index.svc.aws.pinecone.io  ',  'my-index.svc.aws.pinecone.io'],
        ];
        foreach ($cases as [$input, $expected]) {
            $this->assertSame(
                $expected,
                self::callPrivateStatic('normalise_host', [$input]),
                "input '$input' should normalise to '$expected'"
            );
        }
    }

    // ─── pinecone_to_row ──────────────────────────────────────────────────

    public function test_pinecone_to_row_maps_canonical_metadata_keys(): void {
        $m = $this->makeMigrator();
        $row = $this->callPrivate($m, 'pinecone_to_row', ['vec_123', [
            'values' => [0.1, 0.2, 0.3],
            'metadata' => [
                'text'             => 'hello world',
                'source_url'       => 'https://example.com/post-1',
                'role_restriction' => 'subscriber',
                'type'             => 'post',
                'chunk_index'      => 2,
                'total_chunks'     => 5,
                'is_chunked'       => true,
            ],
        ], 'my_bot']);

        $this->assertSame([
            'vector_id'        => 'vec_123',
            'bot_id'           => 'my_bot',
            'embedding'        => [0.1, 0.2, 0.3],
            'content'          => 'hello world',
            'source_url'       => 'https://example.com/post-1',
            'role_restriction' => 'subscriber',
            'content_type'     => 'post',
            'chunk_index'      => 2,
            'total_chunks'     => 5,
            'is_chunked'       => true,
        ], $row);
    }

    public function test_pinecone_to_row_accepts_legacy_metadata_field_names(): void {
        // Pinecone is schemaless; different exporters use different keys.
        // The migrator accepts 'content' as an alias for 'text', and 'url'
        // for 'source_url', and 'content_type' for 'type'. If a future
        // refactor breaks these aliases, existing exports stop importing.
        $m = $this->makeMigrator();
        $row = $this->callPrivate($m, 'pinecone_to_row', ['vec_legacy', [
            'values' => [0.5, 0.6],
            'metadata' => [
                'content'      => 'legacy text field name',
                'url'          => 'https://example.com/legacy',
                'content_type' => 'page',
            ],
        ], 'default']);

        $this->assertSame('legacy text field name', $row['content']);
        $this->assertSame('https://example.com/legacy', $row['source_url']);
        $this->assertSame('page', $row['content_type']);
    }

    public function test_pinecone_to_row_applies_safe_defaults_for_missing_metadata(): void {
        $m = $this->makeMigrator();
        $row = $this->callPrivate($m, 'pinecone_to_row', ['vec_bare', [
            'values' => [0.1, 0.2],
        ], 'default']);

        $this->assertSame('', $row['content']);
        $this->assertSame('', $row['source_url']);
        $this->assertSame('public', $row['role_restriction'], 'role_restriction defaults to public');
        $this->assertSame('content', $row['content_type'], 'content_type defaults to "content"');
        $this->assertNull($row['chunk_index']);
        $this->assertNull($row['total_chunks']);
        $this->assertFalse($row['is_chunked']);
    }

    public function test_pinecone_to_row_returns_null_for_missing_values(): void {
        // No embedding → cannot import. The migrator skips and increments
        // the "failed" counter so the operator sees the loss.
        $m = $this->makeMigrator();

        $this->assertNull($this->callPrivate($m, 'pinecone_to_row', ['vec_empty', [], 'default']));
        $this->assertNull($this->callPrivate($m, 'pinecone_to_row', ['vec_empty', ['values' => []], 'default']));
        $this->assertNull($this->callPrivate($m, 'pinecone_to_row', ['vec_empty', ['metadata' => ['text' => 'x']], 'default']));
    }

    public function test_pinecone_to_row_coerces_chunk_index_to_int(): void {
        $m = $this->makeMigrator();
        $row = $this->callPrivate($m, 'pinecone_to_row', ['v', [
            'values' => [0.1],
            'metadata' => ['chunk_index' => '7', 'total_chunks' => '12'],
        ], 'default']);

        $this->assertSame(7, $row['chunk_index'], 'string "7" must become int 7');
        $this->assertSame(12, $row['total_chunks']);
    }

    // ─── Resumption state ─────────────────────────────────────────────────

    public function test_state_option_is_persisted_under_the_documented_key(): void {
        // docs/CONFIGURATION.md → Sidecar options promises
        // mxchat_plus_duckdb_pinecone_migration_state — if someone renames the
        // constant, the documented sidecar key drifts.
        $this->assertSame('mxchat_plus_duckdb_pinecone_migration_state',
            MxChat_Plus_DuckDB_Pinecone_Migrator::STATE_OPTION);
    }
}
