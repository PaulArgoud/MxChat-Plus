<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the wire-protocol handlers of the Pinecone-emulation proxy against
 * the request shapes MxChat 3.2.25 actually sends:
 *
 *   - handle_upsert  — every vector goes through Vector_Store::row_from_pinecone()
 *                      (title, created_at, last_updated, parent_url_hash and
 *                      add-on keys round-trip); the row's bot is the request
 *                      namespace, never metadata.bot_id; a successful write
 *                      records the bot as proxy-written for the compactor.
 *   - handle_fetch   — GET with the ids REPEATED (ids=a&ids=b), which PHP's
 *                      $_GET collapses to the last one, the ids[N]= form and
 *                      the JSON POST; Pinecone's {vectors: {<id>: …}} shape;
 *                      a table still at schema v3 is migrated before the
 *                      fetch reads the v4 metadata columns.
 *   - handle_list    — prefix filter, keyset paging (paginationToken in,
 *                      pagination.next out only while more ids exist).
 *   - handle_query   — topK clamp, 400 on a malformed filter, the visitor's
 *                      text as the default of mxchat_plus_duckdb_query_text,
 *                      the raw score passed through, 'public' never claimed.
 *   - handle_describe_index_stats — counts scoped to the token.
 *   - document-index paths — an explicit 501, never a silent 404.
 *
 * Authentication (check_token) lives in ProxyAuthTest; the calling side of
 * the loopback (http_request_args) and the hook wiring, end to end, in
 * ProxyLoopbackTest.
 */
final class ProxyHandlersTest extends TestCase {

    private MxChat_Plus_DuckDB_Pinecone_Proxy $proxy;
    private MxChat_Test_RecordingConnection $mock_conn;

    protected function setUp(): void {
        $GLOBALS['__test_options']    = [];
        $GLOBALS['__test_transients'] = [];
        $GLOBALS['__test_filter_overrides'] = [];
        $GLOBALS['__test_rest_routes'] = [];
        // Tests use 3-dim embeddings so the upsert dim guard passes.
        $defaults = MxChat_Plus_DuckDB_Options::defaults();
        update_option('mxchat_plus_duckdb_options', array_merge($defaults, [
            'enabled'       => true,
            'embedding_dim' => 3,
        ]));

        MxChat_Test_Helpers::reset_schema_memoisation();
        MxChat_Test_Helpers::reset_vector_store_current();
        MxChat_Plus_DuckDB_Compactor::reset_noted_cache();

        $this->mock_conn = new MxChat_Test_RecordingConnection('mock:proxy');
        MxChat_Test_Helpers::inject_mock_connection($this->mock_conn);

        $this->proxy = MxChat_Plus_DuckDB_Pinecone_Proxy::instance();
        $this->proxy->reset_state();
    }

    protected function tearDown(): void {
        unset($_SERVER['QUERY_STRING'], $_SERVER['REQUEST_URI']);
        $GLOBALS['__test_filter_overrides'] = [];
        MxChat_Test_Helpers::reset_vector_store_current();
        $this->proxy->reset_state();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /** The response as MxChat receives it: through JSON. */
    private static function wire(WP_REST_Response $resp): array {
        $decoded = json_decode((string) json_encode($resp->data), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Replace Vector_Store::current() with a recorder. $returns maps a
     * method name to its return value, a Closure computing it, or a
     * Throwable to throw.
     */
    private function stubStore(array $returns = []): object {
        $stub = new class($returns) extends MxChat_Plus_DuckDB_Vector_Store {
            /** @var array<string, list<array>> */
            public array $calls = [];
            public array $returns;
            public function __construct(array $returns) {
                $this->returns = $returns;
            }
            private function answer(string $method, array $args, $default) {
                $this->calls[$method][] = $args;
                $r = $this->returns[$method] ?? $default;
                if ($r instanceof \Throwable) throw $r;
                return $r instanceof \Closure ? $r(...$args) : $r;
            }
            public function ensure_schema(): void {}
            public function query_pinecone_shape(array $embedding, int $top_k, string $bot_id = 'default', array $filter = []): array {
                return $this->answer(__FUNCTION__, [$embedding, $top_k, $bot_id, $filter], []);
            }
            public function fetch_by_ids(array $vector_ids, string $bot_id = 'default', bool $with_values = false): array {
                return $this->answer(__FUNCTION__, [$vector_ids, $bot_id, $with_values], []);
            }
            public function delete_by_ids(array $vector_ids, string $bot_id = 'default'): int {
                return $this->answer(__FUNCTION__, [$vector_ids, $bot_id], count($vector_ids));
            }
            public function list_ids_page(string $bot_id = 'default', int $limit = 100, string $pagination_token = '', ?string $prefix = null): array {
                return $this->answer(__FUNCTION__, [$bot_id, $limit, $pagination_token, $prefix], ['ids' => [], 'next' => '']);
            }
            public function upsert(array $vectors): int {
                return $this->answer(__FUNCTION__, [$vectors], count($vectors));
            }
        };
        $r = new ReflectionProperty(MxChat_Plus_DuckDB_Vector_Store::class, 'current');
        $r->setValue(null, $stub);
        return $stub;
    }

    private static function getRequest(array $query): WP_REST_Request {
        $req = new WP_REST_Request();
        $req->set_method('GET');
        $req->set_query_params($query);
        return $req;
    }

    private static function postRequest(array $body): WP_REST_Request {
        $req = new WP_REST_Request();
        $req->set_method('POST');
        $req->set_json_params($body);
        return $req;
    }

    // ─── Route table ─────────────────────────────────────────────────────

    public function test_routes_accept_the_methods_mxchat_uses(): void {
        $this->proxy->register_rest_routes();
        $routes = $GLOBALS['__test_rest_routes'];
        $base = 'mxchat-plus/v1/pinecone-proxy';

        $this->assertSame(['GET', 'POST'], $routes[$base . '/vectors/fetch']['methods'],
            'every MxChat caller of /vectors/fetch sends a GET (plan 793b82)');
        $this->assertContains('GET', (array) $routes[$base . '/vectors/list']['methods']);
        $this->assertContains('POST', (array) $routes[$base . '/describe_index_stats']['methods']);
        $this->assertSame([$this->proxy, 'check_token_any_namespace'], $routes[$base . '/describe_index_stats']['permission_callback'],
            'MxChat posts {} to describe_index_stats: the token, not the body, decides the namespace');
        $this->assertSame([$this->proxy, 'check_token'], $routes[$base . '/vectors/fetch']['permission_callback']);
    }

    public function test_document_index_paths_are_routed_to_an_explicit_refusal(): void {
        $this->proxy->register_rest_routes();
        $doc_route = null;
        foreach ($GLOBALS['__test_rest_routes'] as $key => $args) {
            if (($args['callback'][1] ?? '') === 'handle_unsupported_document_protocol') $doc_route = $key;
        }
        $this->assertNotNull($doc_route);
        $pattern = '@^' . substr($doc_route, strlen('mxchat-plus/v1')) . '$@i';
        foreach (['/namespaces/__default__/documents/search', '/namespaces/sales/documents/upsert', '/documents/fetch'] as $path) {
            $this->assertSame(1, preg_match($pattern, '/pinecone-proxy' . $path), $path);
        }
        $this->assertSame(0, preg_match($pattern, '/pinecone-proxy/vectors/fetch'));

        $resp = $this->proxy->handle_unsupported_document_protocol(new WP_REST_Request());
        $this->assertSame(501, $resp->status);
        $wire = self::wire($resp);
        // MxChat_Pinecone_Documents::request() surfaces error.message as is.
        $this->assertStringContainsString('vector-index protocol', $wire['error']['message']);
        $this->assertSame('UNIMPLEMENTED', $wire['error']['code']);
    }

    // ─── handle_upsert ───────────────────────────────────────────────────

    public function test_upsert_uses_canonical_metadata_keys_when_present(): void {
        $req = self::postRequest([
            'namespace' => 'default',
            'vectors'   => [[
                'id'     => 'v_canonical',
                'values' => [0.1, 0.2, 0.3],
                'metadata' => [
                    'text'         => 'hello',
                    'source_url'   => 'https://canonical.test/post',
                    'chunk_index'  => 2,
                    'total_chunks' => 5,
                    'is_chunked'   => true,
                    'type'         => 'post',
                ],
            ]],
        ]);

        $resp = $this->proxy->handle_upsert($req);
        $this->assertSame(200, $resp->status);
        $this->assertSame(1, $resp->data['upsertedCount']);

        $log = implode("\n", $this->mock_conn->log);
        $this->assertStringContainsString("'v_canonical'", $log);
        $this->assertStringContainsString("'https://canonical.test/post'", $log);
        // chunk_index = 2, total_chunks = 5 should be persisted directly.
        $this->assertMatchesRegularExpression('/2.*5/', $log);
    }

    public function test_upsert_falls_back_to_alias_keys_when_canonical_missing(): void {
        // MxChat_Utils::store_chunk_in_pinecone() never writes the
        // AI-Engine-style aliases to Pinecone metadata, so this fallback is
        // defensive: a third party writing only `source` / `part_index` /
        // `part_total` still gets a usable chunk row.
        $req = self::postRequest([
            'namespace' => 'default',
            'vectors'   => [[
                'id'     => 'v_alias',
                'values' => [0.4, 0.5, 0.6],
                'metadata' => [
                    'text'       => 'aliased chunk',
                    'source'     => 'https://alias.test/post',
                    'part_index' => 1,
                    'part_total' => 3,
                    'is_chunked' => true,
                ],
            ]],
        ]);

        $resp = $this->proxy->handle_upsert($req);
        $this->assertSame(200, $resp->status);
        $this->assertSame(1, $resp->data['upsertedCount']);

        $log = implode("\n", $this->mock_conn->log);
        $this->assertStringContainsString("'https://alias.test/post', 'public'", $log,
            'source alias must populate the source_url column when source_url is missing');
    }

    public function test_upsert_canonical_keys_win_over_aliases(): void {
        $stub = $this->stubStore();
        $this->proxy->handle_upsert(self::postRequest([
            'namespace' => 'default',
            'vectors'   => [[
                'id'     => 'v_both',
                'values' => [0.1, 0.1, 0.1],
                'metadata' => [
                    'source_url' => 'https://canonical.win/post',
                    'source'     => 'https://alias.lose/post',
                    'chunk_index'  => 7,
                    'part_index'   => 99,
                    'total_chunks' => 10,
                    'part_total'   => 99,
                ],
            ]],
        ]));

        $row = $stub->calls['upsert'][0][0][0];
        $this->assertSame('https://canonical.win/post', $row['source_url'], 'when both keys are present, canonical must win');
        $this->assertSame(7, $row['chunk_index']);
        $this->assertSame(10, $row['total_chunks']);
        // As with Pinecone, the aliases themselves round-trip as metadata keys.
        $this->assertSame('https://alias.lose/post', $row['extra_metadata']['source']);
    }

    public function test_upsert_persists_title_dates_and_parent_hash(): void {
        // MxChat_Utils::store_chunk_in_pinecone() (3.2.23+) sends all of these;
        // kb-reimport's "edited after import" check needs last_updated back.
        $this->proxy->handle_upsert(self::postRequest([
            'namespace' => 'default',
            'vectors'   => [[
                'id'       => md5('https://site.test/about') . '_chunk_0',
                'values'   => [0.1, 0.2, 0.3],
                'metadata' => [
                    'text'            => 'About us',
                    'source_url'      => 'https://site.test/about',
                    'type'            => 'url',
                    'is_chunked'      => true,
                    'chunk_index'     => 0,
                    'total_chunks'    => 2,
                    'parent_url_hash' => md5('https://site.test/about'),
                    'last_updated'    => 1767225600,
                    'created_at'      => 1767225500,
                    'bot_id'          => 'default',
                    'title'           => 'About Us',
                ],
            ]],
        ]));

        $log = implode("\n", $this->mock_conn->log);
        $this->assertStringContainsString("'About Us'", $log);
        $this->assertStringContainsString("'" . md5('https://site.test/about') . "'", $log);
        $this->assertStringContainsString('1767225600', $log);
        $this->assertStringContainsString('1767225500', $log);
    }

    public function test_upsert_writes_under_the_request_namespace_never_metadata_bot_id(): void {
        // The token was checked against the namespace; metadata.bot_id is
        // just data — honouring it would let one bot's token write into another.
        $stub = $this->stubStore();
        $this->proxy->handle_upsert(self::postRequest([
            'namespace' => 'sales',
            'vectors'   => [[
                'id' => 'v1', 'values' => [0.1, 0.2, 0.3],
                'metadata' => ['text' => 't', 'bot_id' => 'support'],
            ]],
        ]));
        $this->assertSame('sales', $stub->calls['upsert'][0][0][0]['bot_id']);
    }

    public function test_upsert_never_stores_a_role_the_host_did_not_send(): void {
        $stub = $this->stubStore();
        $this->proxy->handle_upsert(self::postRequest([
            'namespace' => 'default',
            'vectors'   => [
                ['id' => 'v1', 'values' => [0.1, 0.2, 0.3], 'metadata' => ['text' => 't']],
                ['id' => 'v2', 'values' => [0.1, 0.2, 0.3], 'metadata' => ['text' => 't', 'role_restriction' => 'subscriber']],
            ],
        ]));
        $rows = $stub->calls['upsert'][0][0];
        $this->assertNull($rows[0]['role_restriction'], 'absent stays absent (the store keeps its own default)');
        $this->assertSame('subscriber', $rows[1]['role_restriction']);
    }

    public function test_successful_upsert_records_the_bot_as_proxy_written(): void {
        // The compactor must never prune these rows as orphans: the host
        // never writes them to the MySQL KB.
        $this->stubStore();
        $this->proxy->handle_upsert(self::postRequest([
            'namespace' => 'sales',
            'vectors'   => [['id' => 'v1', 'values' => [0.1, 0.2, 0.3], 'metadata' => ['text' => 't']]],
        ]));
        $this->assertContains('sales', MxChat_Plus_DuckDB_Compactor::proxy_written_bots());
    }

    public function test_failed_or_empty_upsert_records_nothing(): void {
        $this->stubStore(['upsert' => new RuntimeException('disk full')]);
        $resp = $this->proxy->handle_upsert(self::postRequest([
            'namespace' => 'sales',
            'vectors'   => [['id' => 'v1', 'values' => [0.1, 0.2, 0.3], 'metadata' => []]],
        ]));
        $this->assertSame(500, $resp->status);
        $this->assertSame(0, $resp->data['upsertedCount']);

        $this->proxy->handle_upsert(self::postRequest(['namespace' => 'support', 'vectors' => []]));
        $this->assertSame([], MxChat_Plus_DuckDB_Compactor::proxy_written_bots());
    }

    // ─── handle_fetch ────────────────────────────────────────────────────

    public function test_parse_fetch_ids_reads_every_form_mxchat_sends(): void {
        $this->assertSame(
            ['a', 'b c', 'd+e', 'f', 'g', 'h'],
            MxChat_Plus_DuckDB_Pinecone_Proxy::parse_fetch_ids(
                // rawurlencode (host), http_build_query brackets (raw and
                // encoded), a '+' space, noise keys and an empty value.
                'ids=a&ids=b%20c&ids=d%2Be&namespace=x&ids%5B0%5D=f&ids[]=g&idsx=nope&ids=&ids[1]=h'
            )
        );
        $this->assertSame(['x y'], MxChat_Plus_DuckDB_Pinecone_Proxy::parse_fetch_ids('ids=x+y'));
        $this->assertSame([], MxChat_Plus_DuckDB_Pinecone_Proxy::parse_fetch_ids(''));
    }

    public function test_get_fetch_recovers_repeated_ids_from_the_raw_query_string(): void {
        // What MxChat_Integrator::reassemble_chunks_from_pinecone() and
        // MxChat_Pinecone_Documents::classic_fetch() send. PHP keeps only the
        // last `ids` in $_GET; answering with that one alone would make the
        // reassembly non-empty and silently drop the other chunks.
        $stub = $this->stubStore();
        $_SERVER['QUERY_STRING'] = 'ids=h_chunk_0&ids=h_chunk_1&ids=h_chunk_2&namespace=sales';
        $this->proxy->handle_fetch(self::getRequest(['ids' => 'h_chunk_2', 'namespace' => 'sales']));

        [$ids, $bot, $with_values] = $stub->calls['fetch_by_ids'][0];
        $this->assertSame(['h_chunk_0', 'h_chunk_1', 'h_chunk_2'], $ids);
        $this->assertSame('sales', $bot, 'the namespace comes from the query string on GET');
        $this->assertTrue($with_values, 'real Pinecone returns values with a fetch');
    }

    public function test_get_fetch_with_bracketed_ids_uses_the_parsed_array(): void {
        // MxChat_Pinecone_Manager's delete check builds the query with
        // http_build_query(): ids[0]=…, which PHP parses into an array.
        $stub = $this->stubStore();
        $this->proxy->handle_fetch(self::getRequest(['ids' => ['x', 'y', 'x']]));
        $this->assertSame(['x', 'y'], $stub->calls['fetch_by_ids'][0][0]);
        $this->assertSame('default', $stub->calls['fetch_by_ids'][0][1]);
    }

    public function test_get_fetch_ignores_a_raw_query_string_that_is_not_this_request(): void {
        // Inside rest_do_request() the server's query string belongs to the
        // outer request: only trust it when its last id is the parsed one.
        $stub = $this->stubStore();
        $_SERVER['QUERY_STRING'] = 'page=mxchat&ids=unrelated_1&ids=unrelated_2';
        $this->proxy->handle_fetch(self::getRequest(['ids' => 'only_this']));
        $this->assertSame(['only_this'], $stub->calls['fetch_by_ids'][0][0]);
    }

    public function test_post_fetch_reads_the_json_body(): void {
        $stub = $this->stubStore();
        $this->proxy->handle_fetch(self::postRequest(['ids' => ['p1', 'p2'], 'namespace' => 'support']));
        $this->assertSame(['p1', 'p2'], $stub->calls['fetch_by_ids'][0][0]);
        $this->assertSame('support', $stub->calls['fetch_by_ids'][0][1]);
    }

    public function test_fetch_values_can_be_dropped_with_a_filter(): void {
        $stub = $this->stubStore();
        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_proxy_fetch_include_values'] = false;
        $this->proxy->handle_fetch(self::getRequest(['ids' => ['a']]));
        $this->assertFalse($stub->calls['fetch_by_ids'][0][2]);
    }

    public function test_fetch_answers_the_pinecone_shape_end_to_end(): void {
        // Real SQL path: bot-scoped, values selected, metadata from the row —
        // dates as the host's ints, 'public' never claimed.
        $this->mock_conn->responses['vector_id IN'] = [[
            'id' => 'a', 'text' => 'hello', 'source_url' => 'https://s.test/a', 'role_restriction' => 'public',
            'type' => 'url', 'chunk_index' => null, 'total_chunks' => null, 'is_chunked' => false,
            'bot_id' => 'sales', 'title' => 'A page', 'created_unix' => 1767225500, 'last_updated_unix' => 1767225600,
            'embedding_values' => [0.1, 0.2, 0.3],
        ]];
        $_SERVER['QUERY_STRING'] = 'ids=a&ids=b&namespace=sales';
        $resp = $this->proxy->handle_fetch(self::getRequest(['ids' => 'b', 'namespace' => 'sales']));

        $sql = (string) end($this->mock_conn->log);
        $this->assertStringContainsString("bot_id = 'sales' AND vector_id IN ('a','b')", $sql);

        $this->assertSame(200, $resp->status);
        $wire = self::wire($resp);
        $a = $wire['vectors']['a'];
        $this->assertSame('a', $a['id']);
        $this->assertSame([0.1, 0.2, 0.3], $a['values']);
        $this->assertSame(1767225600, $a['metadata']['last_updated'], 'kb-reimport casts this with (int)');
        $this->assertSame(1767225500, $a['metadata']['created_at']);
        $this->assertSame('A page', $a['metadata']['title']);
        $this->assertSame('sales', $a['metadata']['bot_id']);
        $this->assertArrayNotHasKey('role_restriction', $a['metadata']);
    }

    /**
     * A store still at schema v3: a tiny catalog (main table, staging copy,
     * meta version) that the v4 rebuild's introspection, CREATE and DROP
     * statements update the way DuckDB would. A fetch against the v3 layout
     * fails with DuckDB's binder error, since its SELECT names the v4
     * metadata columns.
     */
    private static function schemaV3Connection(string $table): MxChat_Plus_DuckDB_Connection {
        return new class($table) implements MxChat_Plus_DuckDB_Connection {
            public array $log = [];
            public string $version = '3';
            /** @var array<string, string> table => 'v3' | 'v4' */
            public array $tables;
            public function __construct(public string $table) {
                $this->tables = [$table => 'v3'];
            }
            private static function columns(string $layout): array {
                $names = $layout === 'v4'
                    ? MxChat_Plus_DuckDB_Vector_Store_Schema::COLUMNS
                    : ['vector_id', 'bot_id', 'embedding', 'content', 'source_url', 'role_restriction',
                       'content_type', 'chunk_index', 'total_chunks', 'is_chunked', 'created_at', 'updated_at'];
                return array_map(fn($c) => [
                    'name' => $c,
                    'pk'   => $c === 'vector_id' || ($layout === 'v4' && $c === 'bot_id'),
                    'type' => $c === 'embedding' ? 'FLOAT[3]' : 'VARCHAR',
                ], $names);
            }
            public function execute(string $sql, array $params = []): array {
                $this->log[] = $sql;
                if (stripos($sql, 'SELECT value FROM "mxchat_plus_duckdb_schema_meta"') !== false) {
                    return stripos($sql, "'schema_version'") !== false ? [['value' => $this->version]] : [];
                }
                if (preg_match("/schema_meta\" \\(key, value\\) VALUES \\('schema_version', '(\\d+)'\\)/", $sql, $m)) {
                    $this->version = $m[1];
                    return [];
                }
                if (preg_match("/pragma_table_info\\('([^']+)'\\)/", $sql, $m)) {
                    if (!isset($this->tables[$m[1]])) throw new RuntimeException('Catalog Error: Table with name ' . $m[1] . ' does not exist!');
                    return self::columns($this->tables[$m[1]]);
                }
                if (preg_match('/^CREATE TABLE "([^"]+)" AS SELECT \* FROM "([^"]+)"/', $sql, $m)) {
                    $this->tables[$m[1]] = $this->tables[$m[2]];
                    return [];
                }
                if (preg_match('/^DROP TABLE (?:IF EXISTS )?"([^"]+)"/', $sql, $m)) {
                    unset($this->tables[$m[1]]);
                    return [];
                }
                if (preg_match('/^CREATE TABLE IF NOT EXISTS "([^"]+)" \(/', $sql, $m) && $m[1] === $this->table && !isset($this->tables[$m[1]])) {
                    $this->tables[$m[1]] = 'v4';
                    return [];
                }
                if (stripos($sql, 'vector_id IN') !== false) {
                    if (($this->tables[$this->table] ?? '') !== 'v4') {
                        throw new RuntimeException('Binder Error: Referenced column "title" not found in FROM clause!');
                    }
                    return [[
                        'id' => 'a', 'text' => 'hello', 'source_url' => 'https://s.test/a', 'role_restriction' => 'public',
                        'type' => 'url', 'chunk_index' => null, 'total_chunks' => null, 'is_chunked' => false,
                        'bot_id' => 'default', 'title' => 'A page', 'created_unix' => 1767225500, 'last_updated_unix' => 1767225600,
                    ]];
                }
                return [];
            }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'mock:proxy-v3'; }
            public function supports_capability(string $cap): bool { return $cap === MxChat_Plus_DuckDB_Connection::CAP_VSS_PERSISTENT_INDEX; }
        };
    }

    public function test_fetch_migrates_a_v3_table_before_reading_the_v4_columns(): void {
        // Plugin updated over a v3 install, Default-bot routing on (the
        // MySQL KB is empty, so no sync migrates anything), and the first
        // proxy call is kb-reimport's or View/Inspect's GET fetch. Without
        // ensure_schema() here it was a 500 until a /query or an upsert.
        $table = MxChat_Plus_DuckDB_Options::get()['table_name'];
        $conn = self::schemaV3Connection($table);
        MxChat_Test_Helpers::inject_mock_connection($conn);
        MxChat_Test_Helpers::reset_vector_store_current();
        MxChat_Test_Helpers::reset_schema_memoisation();

        $_SERVER['QUERY_STRING'] = 'ids=a&namespace=default';
        $resp = $this->proxy->handle_fetch(self::getRequest(['ids' => 'a', 'namespace' => 'default']));

        $this->assertSame(200, $resp->status, (string) ($resp->data['error'] ?? ''));
        $wire = self::wire($resp);
        $this->assertSame('A page', $wire['vectors']['a']['metadata']['title']);
        $this->assertSame(1767225600, $wire['vectors']['a']['metadata']['last_updated']);
        $this->assertSame('4', $conn->version, 'the v4 rebuild ran and was recorded');
        $this->assertSame('v4', $conn->tables[$table]);

        $rebuild = $fetch = -1;
        foreach ($conn->log as $i => $sql) {
            if ($rebuild < 0 && stripos($sql, 'CREATE TABLE IF NOT EXISTS "' . $table . '" (') !== false) $rebuild = $i;
            if ($fetch < 0 && stripos($sql, 'vector_id IN') !== false) $fetch = $i;
        }
        $this->assertGreaterThanOrEqual(0, $rebuild);
        $this->assertGreaterThan($rebuild, $fetch, 'the table is migrated before the fetch reads it');
        $this->assertCount(1, array_filter($conn->log, fn($s) => stripos($s, 'vector_id IN') !== false),
            'one fetch, no failed attempt first');
    }

    public function test_fetch_vectors_is_a_json_object_even_for_numeric_ids(): void {
        $this->stubStore(['fetch_by_ids' => ['0' => ['id' => '0', 'metadata' => ['text' => 'x', 'role_restriction' => 'Public']]]]);
        $resp = $this->proxy->handle_fetch(self::getRequest(['ids' => ['0']]));
        $json = (string) json_encode($resp->data);
        $this->assertStringContainsString('"vectors":{"0":', $json);
        $this->assertStringNotContainsString('role_restriction', $json);

        $empty = (string) json_encode($this->proxy->handle_fetch(self::getRequest([]))->data);
        $this->assertStringContainsString('"vectors":{}', $empty);
    }

    // ─── handle_delete ───────────────────────────────────────────────────

    public function test_delete_is_scoped_to_the_namespace(): void {
        $this->proxy->handle_delete(self::postRequest(['ids' => ['a', 'b'], 'namespace' => 'sales']));
        $deletes = array_values(array_filter($this->mock_conn->log, fn($s) => stripos($s, 'DELETE FROM') !== false));
        $this->assertCount(1, $deletes);
        $this->assertStringContainsString("bot_id = 'sales' AND vector_id IN ('a','b')", $deletes[0]);
    }

    public function test_delete_ignores_the_source_url_filter_riding_along_with_ids(): void {
        // MxChat_Pinecone_Manager's URL delete sends ids AND a source_url filter.
        $stub = $this->stubStore();
        $resp = $this->proxy->handle_delete(self::postRequest([
            'ids' => [md5('https://s.test/a')],
            'filter' => ['source_url' => ['$in' => ['https://s.test/a']]],
        ]));
        $this->assertSame(200, $resp->status);
        $this->assertSame([[md5('https://s.test/a')], 'default'], $stub->calls['delete_by_ids'][0]);
    }

    public function test_delete_all_or_filter_only_is_refused_not_silently_ignored(): void {
        $stub = $this->stubStore();
        foreach ([['deleteAll' => true], ['filter' => ['type' => 'pdf']]] as $body) {
            $resp = $this->proxy->handle_delete(self::postRequest($body + ['namespace' => 'default']));
            $this->assertSame(400, $resp->status);
        }
        $this->assertArrayNotHasKey('delete_by_ids', $stub->calls);
        $this->assertSame(200, $this->proxy->handle_delete(self::postRequest(['ids' => []]))->status);
    }

    // ─── handle_list ─────────────────────────────────────────────────────

    public function test_list_forwards_prefix_filter_to_vector_store(): void {
        // The chunk-reassembly path in mxchat sends a prefix like
        // `{md5(source_url)}_chunk_` and expects only the chunk vectors back.
        // Our proxy must propagate that filter to the SQL layer; otherwise
        // mxchat reassembles unrelated content into the RAG context.
        $req = self::postRequest([
            'namespace' => 'default',
            'prefix'    => 'abc123_chunk_',
            'limit'     => 50,
        ]);

        // Pre-seed the recorder so handle_list returns a deterministic shape.
        $this->mock_conn->responses['SELECT vector_id FROM'] = [
            ['vector_id' => 'abc123_chunk_0'],
            ['vector_id' => 'abc123_chunk_1'],
        ];

        $resp = $this->proxy->handle_list($req);
        $this->assertSame(200, $resp->status);
        $this->assertSame([
            ['id' => 'abc123_chunk_0'],
            ['id' => 'abc123_chunk_1'],
        ], $resp->data['vectors']);

        $sql = end($this->mock_conn->log);
        $this->assertStringContainsString("LIKE 'abc123\\_chunk\\_%'", $sql,
            'prefix filter must reach the SQL with underscores escaped');
    }

    public function test_list_reads_prefix_from_query_string_when_no_json_body(): void {
        // Every MxChat 3.2.25 caller sends a GET with a query string.
        $req = self::getRequest([
            'namespace' => 'default',
            'prefix'    => 'xyz_chunk_',
            'limit'     => 25,
        ]);

        $this->mock_conn->responses['SELECT vector_id FROM'] = [['vector_id' => 'xyz_chunk_0']];

        $resp = $this->proxy->handle_list($req);
        $this->assertSame(200, $resp->status);

        $sql = end($this->mock_conn->log);
        $this->assertStringContainsString("LIKE 'xyz\\_chunk\\_%'", $sql);
        // limit + 1: the extra row only says whether a next page exists.
        $this->assertStringContainsString('ORDER BY vector_id LIMIT 26', $sql);
    }

    public function test_list_without_prefix_returns_unfiltered_ids(): void {
        $req = self::postRequest(['namespace' => 'default']);

        $this->mock_conn->responses['SELECT vector_id FROM'] = [['vector_id' => 'any_id']];

        $resp = $this->proxy->handle_list($req);
        $this->assertSame(200, $resp->status);

        $sql = end($this->mock_conn->log);
        $this->assertStringNotContainsString('LIKE', $sql);
        $this->assertStringContainsString('LIMIT 101', $sql, "Pinecone's default page is 100");
    }

    public function test_list_pagination_token_is_read_from_query_or_body(): void {
        $token = MxChat_Plus_DuckDB_Vector_Store::encode_list_token('abc_chunk_1');
        foreach ([self::getRequest(['paginationToken' => $token]), self::postRequest(['paginationToken' => $token])] as $req) {
            $this->mock_conn->log = [];
            $this->proxy->handle_list($req);
            $this->assertStringContainsString("vector_id > 'abc_chunk_1'", (string) end($this->mock_conn->log));
        }
    }

    public function test_last_page_carries_no_next_token(): void {
        $this->mock_conn->responses['SELECT vector_id FROM'] = [['vector_id' => 'a'], ['vector_id' => 'b']];
        $resp = $this->proxy->handle_list(self::getRequest(['limit' => 2]));
        $this->assertSame('{}', (string) json_encode($resp->data['pagination']),
            'an empty next ends the host loops (they are do … while (true))');
    }

    /**
     * Drives the host's own paging loop (MxChat_Utils::pinecone_list_ids_by_prefix(),
     * MxChat 3.2.25) over a keyset-emulating connection: every id under the
     * prefix comes back exactly once, in Pinecone's (lexicographic) order,
     * and the loop terminates.
     */
    public function test_host_paging_loop_sees_every_id_once(): void {
        $all = ['h_chunk_0', 'h_chunk_1', 'h_chunk_10', 'h_chunk_11', 'h_chunk_2', 'h_chunk_3', 'other_0'];
        $this->mock_conn->responses['SELECT vector_id FROM'] = function (string $sql) use ($all): array {
            preg_match('/LIMIT (\d+)/', $sql, $lm);
            $after = preg_match("/vector_id > '((?:[^']|'')*)'/", $sql, $am) === 1 ? str_replace("''", "'", $am[1]) : null;
            $prefix = preg_match("/LIKE '((?:[^']|'')*)%' ESCAPE/", $sql, $pm) === 1 ? preg_replace('/\\\\(.)/', '$1', $pm[1]) : '';
            $rows = [];
            foreach ($all as $id) {
                if ($prefix !== '' && strpos($id, (string) $prefix) !== 0) continue;
                if ($after !== null && strcmp($id, $after) <= 0) continue;
                $rows[] = ['vector_id' => $id];
            }
            return array_slice($rows, 0, (int) $lm[1]);
        };

        $seen = [];
        $query = ['prefix' => 'h_chunk_', 'limit' => 2, 'namespace' => 'default'];
        $guard = 0;
        do {
            $data = self::wire($this->proxy->handle_list(self::getRequest($query)));
            foreach ($data['vectors'] as $v) $seen[] = $v['id'];
            $next = $data['pagination']['next'] ?? '';
            $query['paginationToken'] = $next;
            $this->assertLessThan(10, ++$guard, 'the host loop must terminate');
        } while ($next !== '');

        $this->assertSame(['h_chunk_0', 'h_chunk_1', 'h_chunk_10', 'h_chunk_11', 'h_chunk_2', 'h_chunk_3'], $seen);
    }

    // ─── handle_query ────────────────────────────────────────────────────

    public function test_query_clamps_top_k_to_pinecone_bounds(): void {
        $stub = $this->stubStore();
        foreach ([[50000, 10000], [0, 1], [-5, 1], [null, 50], [7, 7]] as [$in, $expected]) {
            $body = ['vector' => [0.1, 0.2, 0.3]];
            if ($in !== null) $body['topK'] = $in;
            $this->proxy->handle_query(self::postRequest($body));
            $call = end($stub->calls['query_pinecone_shape']);
            $this->assertSame($expected, $call[1], 'topK ' . var_export($in, true));
        }
    }

    public function test_query_maps_a_malformed_filter_to_400_and_a_store_failure_to_500(): void {
        $this->stubStore(['query_pinecone_shape' => new InvalidArgumentException('Unsupported filter operator: $regex')]);
        $resp = $this->proxy->handle_query(self::postRequest(['vector' => [0.1, 0.2, 0.3], 'filter' => ['type' => ['$regex' => 'x']]]));
        $this->assertSame(400, $resp->status);
        $this->assertSame([], $resp->data['matches']);
        $this->assertStringContainsString('$regex', $resp->data['error']);

        $this->stubStore(['query_pinecone_shape' => new RuntimeException('connection refused')]);
        $this->assertSame(500, $this->proxy->handle_query(self::postRequest(['vector' => [0.1, 0.2, 0.3]]))->status);

        $bad = $this->proxy->handle_query(self::postRequest(['vector' => [0.1, 0.2, 0.3], 'filter' => 'type = pdf']));
        $this->assertSame(400, $bad->status, 'a filter must be an object');
    }

    public function test_query_never_claims_public_and_passes_the_score_through(): void {
        // The score is the store's raw cosine (whatever the metric or the
        // hybrid blend): MxChat compares it with its similarity threshold
        // and the YouTube-card floor.
        $this->stubStore(['query_pinecone_shape' => [
            ['id' => 'a', 'score' => 0.12, 'metadata' => ['text' => 'a', 'role_restriction' => 'public']],
            ['id' => 'b', 'score' => 0.81, 'metadata' => ['text' => 'b', 'role_restriction' => 'subscriber']],
            ['id' => 'c', 'score' => 0.5,  'metadata' => ['text' => 'c', 'role_restriction' => '']],
        ]]);
        $resp = $this->proxy->handle_query(self::postRequest(['vector' => [0.1, 0.2, 0.3], 'namespace' => 'sales']));
        $this->assertSame(200, $resp->status);
        $m = self::wire($resp)['matches'];
        $this->assertSame([0.12, 0.81, 0.5], array_column($m, 'score'));
        $this->assertArrayNotHasKey('role_restriction', $m[0]['metadata'], "MxChat reads its roles table only when the key is absent");
        $this->assertSame('subscriber', $m[1]['metadata']['role_restriction']);
        $this->assertArrayNotHasKey('role_restriction', $m[2]['metadata']);
        $this->assertSame('sales', self::wire($resp)['namespace']);
    }

    public function test_query_text_from_the_body_is_the_default_of_the_query_text_filter(): void {
        // Vector_Store_Query::run() asks `mxchat_plus_duckdb_query_text`; the
        // proxy's PHP_INT_MIN callback answers with the body's text while
        // this query runs, for this bot only.
        $seen = [];
        $proxy = $this->proxy;
        $this->stubStore(['query_pinecone_shape' => function ($e, $k, $bot, $f) use (&$seen, $proxy) {
            $seen['same_bot']   = $proxy->default_query_text('', $bot, $f);
            $seen['other_bot']  = $proxy->default_query_text('', 'support', $f);
            $seen['integrator'] = $proxy->default_query_text('rewritten by an add-on', $bot, $f);
            return [];
        }]);

        $this->proxy->handle_query(self::postRequest([
            'vector' => [0.1, 0.2, 0.3],
            'namespace' => 'default',
            MxChat_Plus_DuckDB_Pinecone_Proxy::QUERY_TEXT_KEY => '  Do you stock 4471-AB-12?  ',
        ]));

        $this->assertSame('Do you stock 4471-AB-12?', $seen['same_bot']);
        $this->assertSame('', $seen['other_bot']);
        $this->assertSame('rewritten by an add-on', $seen['integrator'], 'a later callback still wins');
        $this->assertSame('', $this->proxy->default_query_text('', 'default', []), 'cleared once the query is answered');
    }

    public function test_query_text_is_capped(): void {
        $seen = '';
        $proxy = $this->proxy;
        $this->stubStore(['query_pinecone_shape' => function ($e, $k, $bot, $f) use (&$seen, $proxy) {
            $seen = (string) $proxy->default_query_text('', $bot, $f);
            return [];
        }]);
        $this->proxy->handle_query(self::postRequest([
            'vector' => [0.1, 0.2, 0.3],
            MxChat_Plus_DuckDB_Pinecone_Proxy::QUERY_TEXT_KEY => str_repeat('é', 5000),
        ]));
        $this->assertSame(MxChat_Plus_DuckDB_Pinecone_Proxy::QUERY_TEXT_MAX_CHARS, mb_strlen($seen));
    }

    public function test_host_hybrid_toggle_is_followed_only_when_opted_in_and_only_during_a_query(): void {
        $seen = [];
        $proxy = $this->proxy;
        $this->stubStore(['query_pinecone_shape' => function ($e, $k, $bot) use (&$seen, $proxy) {
            $seen[] = $proxy->follow_host_hybrid_toggle(['hybrid_enabled' => false], $bot)['hybrid_enabled'];
            return [];
        }]);
        $query = self::postRequest(['vector' => [0.1, 0.2, 0.3]]);

        update_option('mxchat_hybrid_keyword_toggle', 'on');
        $this->proxy->handle_query($query);                // not opted in
        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_proxy_follow_host_hybrid_toggle'] = true;
        $this->proxy->handle_query($query);                // opted in, host on
        update_option('mxchat_hybrid_keyword_toggle', 'off');
        $this->proxy->handle_query($query);                // opted in, host off

        $this->assertSame([false, true, false], $seen);
        update_option('mxchat_hybrid_keyword_toggle', 'on');
        $this->assertFalse($this->proxy->follow_host_hybrid_toggle(['hybrid_enabled' => false], 'default')['hybrid_enabled'],
            'outside a proxy query (admin screens) the stored setting is shown as is');
    }

    public function test_host_hybrid_toggle_change_flushes_the_query_cache(): void {
        MxChat_Plus_DuckDB_Cache::$cache_gen_map = ['default' => 3, 'sales' => 1];
        $this->proxy->flush_cache_on_host_hybrid_toggle();
        $this->assertSame(['default' => 4, 'sales' => 2], MxChat_Plus_DuckDB_Cache::$cache_gen_map);
        MxChat_Plus_DuckDB_Cache::$cache_gen_map = [];
    }

    // ─── handle_describe_index_stats ─────────────────────────────────────

    private static function statsRequest(string $auth_ns): WP_REST_Request {
        $req = self::postRequest([]);
        $req->set_param(MxChat_Plus_DuckDB_Pinecone_Proxy::AUTH_NS_PARAM, $auth_ns);
        return $req;
    }

    public function test_stats_for_a_bot_token_report_only_that_namespace(): void {
        $this->mock_conn->responses['SELECT COUNT(*) AS c'] = [['c' => 3000]];
        $resp = $this->proxy->handle_describe_index_stats(self::statsRequest('sales'));
        $this->assertSame(200, $resp->status);
        $wire = self::wire($resp);
        // MxChat_Pinecone_Manager reads namespaces[<its namespace>].vectorCount.
        $this->assertSame(['sales' => ['vectorCount' => 3000]], $wire['namespaces']);
        $this->assertSame(3000, $wire['totalVectorCount'], 'no other bot leaks into the total');
        $this->assertSame(3, $wire['dimension']);
        $this->assertSame(0, $wire['indexFullness']);
        $this->assertStringContainsString("WHERE bot_id = 'sales'", (string) end($this->mock_conn->log));
    }

    public function test_stats_omit_an_empty_namespace(): void {
        $this->mock_conn->responses['SELECT COUNT(*) AS c'] = [['c' => 0]];
        $resp = $this->proxy->handle_describe_index_stats(self::statsRequest('default'));
        $json = (string) json_encode($resp->data);
        $this->assertStringContainsString('"namespaces":{}', $json, 'Pinecone omits empty namespaces; MxChat reads missing as 0');
        $this->assertSame(0, $resp->data['totalVectorCount']);
    }

    public function test_stats_for_the_legacy_token_report_every_bot(): void {
        $this->mock_conn->responses['GROUP BY bot_id'] = [
            ['bot_id' => '42', 'c' => 5],
            ['bot_id' => 'default', 'c' => 10],
        ];
        $resp = $this->proxy->handle_describe_index_stats(self::statsRequest('*'));
        $json = (string) json_encode($resp->data);
        $this->assertStringContainsString('"namespaces":{"42":{"vectorCount":5},"default":{"vectorCount":10}}', $json);
        $this->assertSame(15, $resp->data['totalVectorCount']);
    }

    public function test_stats_refuse_a_request_the_permission_callback_did_not_scope(): void {
        $this->assertSame(401, $this->proxy->handle_describe_index_stats(self::postRequest([]))->status);
    }
}
