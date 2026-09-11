<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the wire-protocol handlers on the Pinecone-emulation proxy:
 *
 *   - handle_upsert      — incoming Pinecone /vectors/upsert; alias-key
 *                          metadata fallback (AI-Engine-style aliases added
 *                          in mxchat-basic 3.2.6).
 *   - handle_list        — chunk-aware listing via the `prefix` filter, which
 *                          mxchat's chunk-reassembly and bulk-delete paths
 *                          depend on (3.2.6 line ~6088 / ~6321).
 *
 * Authentication (check_token) lives in ProxyAuthTest; this file is about
 * the request-body interpretation that turns raw Pinecone JSON into the
 * correct DuckDB SQL.
 */
final class ProxyHandlersTest extends TestCase {

    private MxChat_Plus_DuckDB_Pinecone_Proxy $proxy;
    private MxChat_Test_RecordingConnection $mock_conn;

    protected function setUp(): void {
        $GLOBALS['__test_options']    = [];
        $GLOBALS['__test_transients'] = [];
        // Tests use 3-dim embeddings so the upsert dim guard passes.
        $defaults = MxChat_Plus_DuckDB_Options::defaults();
        update_option('mxchat_plus_duckdb_options', array_merge($defaults, [
            'enabled'       => true,
            'embedding_dim' => 3,
        ]));

        MxChat_Test_Helpers::reset_schema_memoisation();
        MxChat_Test_Helpers::reset_vector_store_current();

        $this->mock_conn = new MxChat_Test_RecordingConnection('mock:proxy');
        MxChat_Test_Helpers::inject_mock_connection($this->mock_conn);

        $this->proxy = MxChat_Plus_DuckDB_Pinecone_Proxy::instance();
    }

    // ─── handle_upsert: chunk-metadata alias fallbacks ───────────────────

    public function test_upsert_uses_canonical_metadata_keys_when_present(): void {
        $req = new WP_REST_Request();
        $req->set_json_params([
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
        // mxchat-basic 3.2.6's chunk metadata exposes (`source` / `part_index`
        // / `part_total`) as aliases for filter consumers. A third-party
        // plugin filtering `mxchat_embedding_chunk_metadata` could rewrite
        // metadata to use only the aliases — we should still preserve the
        // chunk shape on the DuckDB side.
        $req = new WP_REST_Request();
        $req->set_json_params([
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
        $this->assertStringContainsString("'https://alias.test/post'", $log,
            'source alias must populate source_url when source_url is missing');
    }

    public function test_upsert_canonical_keys_win_over_aliases(): void {
        $req = new WP_REST_Request();
        $req->set_json_params([
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
        ]);

        $this->proxy->handle_upsert($req);
        $log = implode("\n", $this->mock_conn->log);

        $this->assertStringContainsString("'https://canonical.win/post'", $log);
        $this->assertStringNotContainsString('alias.lose', $log,
            'when both keys present, canonical must win');
    }

    // ─── handle_list: prefix filter forwarding ───────────────────────────

    public function test_list_forwards_prefix_filter_to_vector_store(): void {
        // The chunk-reassembly path in mxchat sends a prefix like
        // `{md5(source_url)}_chunk_` and expects only the chunk vectors back.
        // Our proxy must propagate that filter to the SQL layer; otherwise
        // mxchat reassembles unrelated content into the RAG context.
        $req = new WP_REST_Request();
        $req->set_json_params([
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
        // mxchat's chunk-delete path uses GET (wp_remote_get) with a query
        // string — we already accept that, but must still extract `prefix`.
        $req = new WP_REST_Request();
        $req->set_query_params([
            'namespace' => 'default',
            'prefix'    => 'xyz_chunk_',
            'limit'     => 25,
        ]);

        $this->mock_conn->responses['SELECT vector_id FROM'] = [['vector_id' => 'xyz_chunk_0']];

        $resp = $this->proxy->handle_list($req);
        $this->assertSame(200, $resp->status);

        $sql = end($this->mock_conn->log);
        $this->assertStringContainsString("LIKE 'xyz\\_chunk\\_%'", $sql);
        $this->assertStringContainsString('LIMIT 25', $sql);
    }

    public function test_list_without_prefix_returns_unfiltered_ids(): void {
        $req = new WP_REST_Request();
        $req->set_json_params(['namespace' => 'default']);

        $this->mock_conn->responses['SELECT vector_id FROM'] = [['vector_id' => 'any_id']];

        $resp = $this->proxy->handle_list($req);
        $this->assertSame(200, $resp->status);

        $sql = end($this->mock_conn->log);
        $this->assertStringNotContainsString('LIKE', $sql);
    }
}
