<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks MxChat_Plus_DuckDB_Pinecone_Migrator: the translation from
 * Pinecone's response shapes (classic vector index and the MxChat 3.2.22+
 * document index) to our row shape, and — through an injected HTTP
 * transport and a recording DuckDB connection — the wire calls themselves:
 * GET with query parameters and REPEATED `ids=` on a classic index (a POST
 * reads as "no vectors"), the document API's POST + version header, paging,
 * resume rules and namespace enumeration. Real Pinecone is not contacted.
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
        MxChat_Plus_DuckDB_Cache::$cache_gen_map = [];
    }

    protected function tearDown(): void {
        MxChat_Test_Helpers::reset_all();
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
                'title'            => 'Post one',
                'parent_url_hash'  => 'abc',
                'created_at'       => 1700000000,
                'last_updated'     => 1700000100,
                'custom'           => 'kept',
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
            'title'            => 'Post one',
            'parent_url_hash'  => 'abc',
            'created_at'       => 1700000000,
            'last_updated'     => 1700000100,
            'extra_metadata'   => ['custom' => 'kept'],
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
        ], '']);

        $this->assertSame('legacy text field name', $row['content']);
        $this->assertSame('https://example.com/legacy', $row['source_url']);
        $this->assertSame('page', $row['content_type']);
    }

    public function test_pinecone_to_row_applies_safe_defaults_for_missing_metadata(): void {
        $m = $this->makeMigrator();
        $row = $this->callPrivate($m, 'pinecone_to_row', ['vec_bare', [
            'values' => [0.1, 0.2],
        ], '']);

        $this->assertSame('', $row['content']);
        $this->assertSame('', $row['source_url']);
        $this->assertSame('public', $row['role_restriction'],
            'role_restriction keeps the stored default; the read path never emits it as a claim');
        $this->assertSame('content', $row['content_type'], 'content_type defaults to "content"');
        $this->assertNull($row['chunk_index']);
        $this->assertNull($row['total_chunks']);
        $this->assertFalse($row['is_chunked']);
        $this->assertNull($row['title']);
        $this->assertNull($row['created_at'], 'no invented date');
        $this->assertSame('default', $row['bot_id'], "the default namespace ('') is the default bot");
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
            'metadata' => ['chunk_index' => '7', 'total_chunks' => 12.0, 'is_chunked' => 'false'],
        ], 'default']);

        $this->assertSame(7, $row['chunk_index'], 'string "7" must become int 7');
        $this->assertSame(12, $row['total_chunks'], 'the document index returns floats');
        $this->assertFalse($row['is_chunked'], "'false' is false (filter_var), not !empty()");
    }

    public function test_the_row_bot_follows_flag_then_metadata_then_namespace(): void {
        // DuckDB partitions retrieval on bot_id, and a Multi-Bot namespace
        // need not be named after its bot: `--namespace=prod` used to land
        // every vector under bot 'prod', which no bot ever queries.
        $v = ['values' => [0.1], 'metadata' => ['bot_id' => 'sales']];

        $m = new MxChat_Plus_DuckDB_Pinecone_Migrator('k', 'h', 'prod');
        $this->assertSame('sales', $this->callPrivate($m, 'pinecone_to_row', ['v', $v, 'prod'])['bot_id'],
            'metadata.bot_id — MxChat writes it on every vector');
        $this->assertSame('prod', $this->callPrivate($m, 'pinecone_to_row', ['v', ['values' => [0.1]], 'prod'])['bot_id'],
            'no metadata.bot_id → the namespace');

        $forced = new MxChat_Plus_DuckDB_Pinecone_Migrator('k', 'h', 'prod', 'vector', 'support');
        $this->assertSame('support', $this->callPrivate($forced, 'pinecone_to_row', ['v', $v, 'prod'])['bot_id'],
            '--bot-id wins');
    }

    // ─── HTTP shapes ──────────────────────────────────────────────────────

    /**
     * A recording transport: routes on a URL substring, returns a WP-style
     * response array.
     *
     * @param array<string, array<string, mixed>|callable> $routes
     */
    private function http(array $routes, array &$calls): callable {
        return function (string $method, string $url, array $args) use ($routes, &$calls) {
            $calls[] = ['method' => $method, 'url' => $url, 'args' => $args];
            foreach ($routes as $needle => $answer) {
                if (strpos($url, $needle) !== false) {
                    $answer = is_callable($answer) ? $answer($url, $args, count($calls)) : $answer;
                    if ($answer instanceof WP_Error) return $answer;
                    $code = (int) ($answer['__code'] ?? 200);
                    unset($answer['__code']);
                    $body = isset($answer['__raw']) ? (string) $answer['__raw'] : json_encode($answer);
                    return ['response' => ['code' => $code], 'body' => $body];
                }
            }
            return ['response' => ['code' => 404], 'body' => 'no route'];
        };
    }

    private function injectStore(): MxChat_Test_RecordingConnection {
        MxChat_Test_Helpers::reset_all();
        update_option('mxchat_plus_duckdb_options', array_merge(MxChat_Plus_DuckDB_Options::defaults(), ['embedding_dim' => 2]));
        $conn = new MxChat_Test_RecordingConnection('mock:migrator');
        MxChat_Test_Helpers::inject_mock_connection($conn);
        return $conn;
    }

    public function test_classic_index_is_read_with_get_and_repeated_ids(): void {
        // The host's own notes (plan 793b82): /vectors/list and /vectors/fetch
        // are GET endpoints with query parameters; a POST reads as "no
        // vectors" or a non-200.
        $conn = $this->injectStore();
        $calls = [];
        $http = $this->http([
            '/vectors/list' => function ($url) {
                return str_contains($url, 'paginationToken=t1')
                    ? ['vectors' => [['id' => 'c']], 'pagination' => []]
                    : ['vectors' => [['id' => 'a'], ['id' => 'b/x']], 'pagination' => ['next' => 't1']];
            },
            '/vectors/fetch' => function ($url) {
                $out = [];
                foreach (['a', 'c'] as $id) {
                    if (str_contains($url, 'ids=' . $id)) $out[$id] = ['id' => $id, 'values' => [0.1, 0.2], 'metadata' => ['text' => $id, 'title' => 'T' . $id]];
                }
                return ['vectors' => $out];
            },
        ], $calls);
        $m = new MxChat_Plus_DuckDB_Pinecone_Migrator('pcsk', 'https://idx.pinecone.io/', 'sales', 'vector', '', $http);

        $r = $m->run();

        $this->assertSame(['copied' => 2, 'failed' => 1, 'namespace' => 'sales', 'index_type' => 'vector'], $r,
            "'b/x' was listed but not returned by fetch: counted as failed, never silently lost");
        $this->assertSame(['GET', 'GET', 'GET', 'GET'], array_column($calls, 'method'));
        $this->assertSame('https://idx.pinecone.io/vectors/list?limit=100&namespace=sales', $calls[0]['url']);
        $this->assertSame('https://idx.pinecone.io/vectors/fetch?ids=a&ids=b%2Fx&namespace=sales', $calls[1]['url']);
        $this->assertSame('https://idx.pinecone.io/vectors/list?limit=100&namespace=sales&paginationToken=t1', $calls[2]['url']);
        $this->assertSame('pcsk', $calls[0]['args']['headers']['Api-Key']);
        $this->assertArrayNotHasKey('X-Pinecone-Api-Version', $calls[0]['args']['headers']);

        $upserts = array_values(array_filter($conn->log, fn($sql) => str_contains($sql, 'INSERT OR REPLACE INTO "mxchat_vectors"')));
        $this->assertCount(2, $upserts);
        $this->assertStringContainsString("'Ta'", $upserts[0], 'title carried');
        $this->assertStringContainsString("('sales') || chr(31) || ('a')", $upserts[0]);

        $this->assertFalse(get_option(MxChat_Plus_DuckDB_Pinecone_Migrator::STATE_OPTION, false), 'cursor cleared on completion');
        $marker = get_option(MxChat_Plus_DuckDB_Pinecone_Migrator::IMPORTED_MARKER_OPTION);
        $this->assertSame(2, $marker['copied']);
        $this->assertSame('vector', $marker['index_type']);
    }

    public function test_the_default_namespace_is_never_sent_as_a_name(): void {
        // Pinecone and MxChat name the default namespace '' — the docs used
        // to suggest --namespace=default, which lists an empty namespace.
        $this->injectStore();
        $calls = [];
        $http = $this->http(['/vectors/list' => ['vectors' => []]], $calls);
        (new MxChat_Plus_DuckDB_Pinecone_Migrator('k', 'idx.io', '', 'vector', '', $http))->run();
        $this->assertSame('https://idx.io/vectors/list?limit=100', $calls[0]['url']);
        $this->assertSame('https://idx.io/vectors/fetch?ids=a&ids=b', MxChat_Plus_DuckDB_Pinecone_Migrator::classic_fetch_url('idx.io', '', ['a', 'b']));
    }

    public function test_document_index_uses_the_document_api(): void {
        // MxChat 3.2.22+ document index (MxChat_Pinecone_Documents): POST
        // /namespaces/{ns|__default__}/documents/list|fetch with the 2026-07
        // version header, a snake_case pagination_token, flat fields.
        $conn = $this->injectStore();
        $calls = [];
        $http = $this->http([
            '/documents/list' => function ($url, $args) {
                $body = json_decode($args['body'], true);
                return isset($body['pagination_token'])
                    ? ['documents' => []]
                    : ['documents' => [['_id' => 'd1'], ['_id' => 'd2']], 'pagination' => ['next' => 'n1']];
            },
            '/documents/fetch' => ['documents' => [
                'd1' => ['_id' => 'd1', 'text' => 'doc one', 'embedding' => [0.3, 0.4], 'chunk_index' => 0.0, 'codes' => ['AB12'], 'title' => 'D'],
                'd2' => ['_id' => 'd2', 'text' => 'no vector'],
            ]],
        ], $calls);
        $m = new MxChat_Plus_DuckDB_Pinecone_Migrator('k', 'docs.io', '', 'document', '', $http);

        $r = $m->run();

        $this->assertSame(1, $r['copied']);
        $this->assertSame(1, $r['failed'], 'a document without its embedding cannot be copied');
        $this->assertSame('document', $r['index_type']);
        $this->assertSame('POST', $calls[0]['method']);
        $this->assertSame('https://docs.io/namespaces/__default__/documents/list', $calls[0]['url']);
        $this->assertSame('https://docs.io/namespaces/__default__/documents/fetch', $calls[1]['url']);
        $this->assertSame(MxChat_Plus_DuckDB_Pinecone_Migrator::DOCUMENT_API_VERSION, $calls[0]['args']['headers']['X-Pinecone-Api-Version']);
        $fetch_body = json_decode($calls[1]['args']['body'], true);
        $this->assertSame(['d1', 'd2'], $fetch_body['ids']);
        $this->assertContains('embedding', $fetch_body['include_fields']);
        $this->assertSame(['limit' => 100, 'pagination_token' => 'n1'], json_decode($calls[2]['args']['body'], true));

        $upsert = implode("\n", array_filter($conn->log, fn($sql) => str_contains($sql, 'INSERT OR REPLACE INTO "mxchat_vectors"')));
        $this->assertStringContainsString("'doc one'", $upsert);
        $this->assertStringContainsString('[0.3,0.4]', $upsert);
        $this->assertStringNotContainsString('AB12', $upsert, "'codes' is rebuilt by the host, never stored");
    }

    public function test_document_to_vector_reshapes_flat_fields(): void {
        $this->assertSame(
            ['values' => [1, 2], 'metadata' => ['text' => 't', 'source_url' => 'u']],
            MxChat_Plus_DuckDB_Pinecone_Migrator::document_to_vector(['_id' => 'x', '_score' => 0.4, 'text' => 't', 'embedding' => [1, 2], 'source_url' => 'u'])
        );
    }

    public function test_http_errors_abort_with_a_hint_and_keep_the_cursor(): void {
        $this->injectStore();
        $calls = [];
        $http = $this->http([
            'paginationToken=t1' => ['__code' => 400, '__raw' => 'bad request'],
            '/vectors/list'      => ['vectors' => [['id' => 'a']], 'pagination' => ['next' => 't1']],
            '/vectors/fetch'     => ['vectors' => ['a' => ['values' => [0.1, 0.2]]]],
        ], $calls);
        $m = new MxChat_Plus_DuckDB_Pinecone_Migrator('k', 'idx.io', 'ns', 'auto', '', $http);
        try {
            $m->run();
            $this->fail('a non-2xx must abort');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 400', $e->getMessage());
            $this->assertStringContainsString('--index-type=document', $e->getMessage());
        }
        $state = get_option(MxChat_Plus_DuckDB_Pinecone_Migrator::STATE_OPTION);
        $this->assertSame('t1', $state['next_token'], 'the first page is written; a re-run resumes after it');
        $this->assertSame(1, $state['copied']);
        $this->assertSame('ns', $state['namespace']);
        $this->assertSame('idx.io', $state['host']);
    }

    public function test_non_json_and_transport_errors_throw(): void {
        $calls = [];
        $m = new MxChat_Plus_DuckDB_Pinecone_Migrator('k', 'idx.io', '', 'vector', '', $this->http(['/describe_index_stats' => ['__raw' => '']], $calls));
        try {
            $m->list_namespaces();
            $this->fail('empty body must throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('non-JSON', $e->getMessage());
        }
        $m = new MxChat_Plus_DuckDB_Pinecone_Migrator('k', 'idx.io', '', 'vector', '', $this->http(['/describe_index_stats' => new WP_Error('x', 'timed out')], $calls));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('timed out');
        $m->list_namespaces();
    }

    public function test_a_saved_cursor_is_only_resumed_for_the_same_source(): void {
        $this->injectStore();
        update_option(MxChat_Plus_DuckDB_Pinecone_Migrator::STATE_OPTION, [
            'host' => 'other.io', 'index_type' => 'vector', 'namespace' => 'ns', 'next_token' => 'foreign', 'copied' => 99, 'failed' => 0,
        ]);
        $calls = [];
        $http = $this->http(['/vectors/list' => ['vectors' => []]], $calls);
        $r = (new MxChat_Plus_DuckDB_Pinecone_Migrator('k', 'idx.io', 'ns', 'vector', '', $http))->run();
        $this->assertStringNotContainsString('foreign', $calls[0]['url'], "another index's token is never replayed");
        $this->assertSame(0, $r['copied']);

        update_option(MxChat_Plus_DuckDB_Pinecone_Migrator::STATE_OPTION, [
            'host' => 'idx.io', 'index_type' => 'vector', 'namespace' => 'ns', 'next_token' => 'mine', 'copied' => 5, 'failed' => 1,
        ]);
        $calls = [];
        $r = (new MxChat_Plus_DuckDB_Pinecone_Migrator('k', 'idx.io', 'ns', 'vector', '', $http))->run();
        $this->assertStringContainsString('paginationToken=mine', $calls[0]['url']);
        $this->assertSame(5, $r['copied'], 'the counters resume too');
    }

    public function test_every_namespace_can_be_copied_default_first(): void {
        $this->injectStore();
        $calls = [];
        $http = $this->http([
            '/describe_index_stats' => ['namespaces' => ['zeta' => ['vectorCount' => 1], '' => ['vectorCount' => 1], 'alpha' => ['recordCount' => 1]], 'dimension' => 2],
            '/vectors/list' => function ($url) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $ns = $q['namespace'] ?? '';
                return ['vectors' => [['id' => 'id-' . ($ns === '' ? 'default' : $ns)]]];
            },
            '/vectors/fetch' => function ($url) {
                preg_match('/ids=([^&]+)/', $url, $m);
                return ['vectors' => [$m[1] => ['values' => [0.1, 0.2], 'metadata' => []]]];
            },
        ], $calls);
        $m = new MxChat_Plus_DuckDB_Pinecone_Migrator('k', 'idx.io', '', 'vector', '', $http);

        $this->assertSame(['' => 1, 'alpha' => 1, 'zeta' => 1], $m->list_namespaces());
        $r = $m->run_all_namespaces();
        $this->assertSame(3, $r['copied']);
        $this->assertSame(['', 'alpha', 'zeta'], array_map('strval', array_keys($r['namespaces'])));

        // Resume: a failed all-namespaces run had finished '' and 'alpha'.
        update_option(MxChat_Plus_DuckDB_Pinecone_Migrator::STATE_OPTION, [
            'host' => 'idx.io', 'index_type' => 'vector', 'namespace' => 'alpha', 'next_token' => '',
            'copied' => 0, 'failed' => 0, 'done_namespaces' => ['', 'alpha'],
        ]);
        $calls = [];
        $r = (new MxChat_Plus_DuckDB_Pinecone_Migrator('k', 'idx.io', '', 'vector', '', $http))->run_all_namespaces();
        $this->assertSame(['zeta'], array_map('strval', array_keys($r['namespaces'])));
    }

    public function test_auto_detection_falls_back_to_a_vector_index_on_old_hosts(): void {
        // MxChat_Pinecone_Documents (3.2.22+) is not loaded in the suite.
        $m = new MxChat_Plus_DuckDB_Pinecone_Migrator('k', 'idx.io');
        $this->assertSame('vector', $m->resolved_index_type());
    }

    public function test_unknown_index_type_is_refused(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/index type/i');
        new MxChat_Plus_DuckDB_Pinecone_Migrator('k', 'idx.io', '', 'pod');
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
