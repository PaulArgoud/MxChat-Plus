<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks MxChat_Plus_DuckDB_Sync's two ways of following writes MxChat makes
 * outside our own pipelines:
 *
 *   - the Pinecone write mirror (`http_response` observer): while DuckDB
 *     holds a copy of a real Pinecone index, every successful delete or
 *     upsert MxChat sends that index is replayed into DuckDB — the only
 *     point MxChat's REST, media, trash, unpublish and re-store paths share;
 *   - the debounced re-sync of MySQL KB rows MxChat rewrites in place
 *     (kb-reimport apply, a role change), which keep their timestamp.
 */
final class SyncMirrorTest extends TestCase {

    private const REAL_HOST = 'kb-abc123.svc.aped-4627-b74a.pinecone.io';

    private MxChat_Test_RecordingConnection $conn;
    private MxChat_Plus_DuckDB_Sync $sync;

    protected function setUp(): void {
        $GLOBALS['__test_options']      = [];
        $GLOBALS['__test_transients']   = [];
        $GLOBALS['__test_cron']         = [];
        $GLOBALS['__test_valid_nonces'] = [];
        $GLOBALS['__test_current_user_can'] = true;
        $GLOBALS['__test_filter_overrides'] = [];
        $_POST = [];
        MxChat_Test_Helpers::reset_schema_memoisation();

        update_option(MXCHAT_PLUS_DUCKDB_OPTION_KEY, array_merge(MxChat_Plus_DuckDB_Options::defaults(), [
            'enabled' => true, 'embedding_dim' => 3,
        ]));
        update_option(MxChat_Plus_DuckDB_Pinecone_Migrator::IMPORTED_MARKER_OPTION, [
            'copied' => 120, 'namespace' => '', 'host' => self::REAL_HOST, 'index_type' => 'vector', 'imported_at' => 1,
        ]);

        $this->conn = new MxChat_Test_RecordingConnection('mock:mirror');
        MxChat_Test_Helpers::inject_mock_connection($this->conn);
        $this->sync = new MxChat_Plus_DuckDB_Sync();
    }

    protected function tearDown(): void {
        $GLOBALS['__test_filter_overrides'] = [];
        $_POST = [];
    }

    private function ok(): array {
        return ['headers' => [], 'body' => '{}', 'response' => ['code' => 200, 'message' => 'OK']];
    }

    private function post(array $body): array {
        return ['method' => 'POST', 'headers' => ['Api-Key' => 'pcsk_x'], 'body' => wp_json_encode($body)];
    }

    private function sql(): string {
        return implode("\n", $this->conn->log);
    }

    // ─── Deletes ─────────────────────────────────────────────────────────

    public function test_classic_delete_by_ids_is_replayed_into_the_default_bot(): void {
        $response = $this->ok();
        $out = $this->sync->mirror_pinecone_write($response, $this->post(['ids' => ['a1', 'a1_chunk_0']]), 'https://' . self::REAL_HOST . '/vectors/delete');

        $this->assertSame($response, $out, 'an observer never alters the response');
        $this->assertStringContainsString("DELETE FROM \"mxchat_vectors\" WHERE bot_id = 'default' AND vector_id IN ('a1','a1_chunk_0')", $this->sql());
    }

    public function test_namespace_maps_to_its_bot_and_the_default_namespace_to_default(): void {
        update_option('mxchat_pinecone_addon_options', ['mxchat_pinecone_namespace' => 'site']);

        $this->sync->mirror_pinecone_write($this->ok(), $this->post(['ids' => ['x'], 'namespace' => 'sales']), 'https://' . self::REAL_HOST . '/vectors/delete');
        $this->assertStringContainsString("bot_id = 'sales' AND vector_id IN ('x')", $this->sql());

        $this->sync->mirror_pinecone_write($this->ok(), $this->post(['ids' => ['y'], 'namespace' => 'site']), 'https://' . self::REAL_HOST . '/vectors/delete');
        $this->assertStringContainsString("bot_id = 'default' AND vector_id IN ('y')", $this->sql(),
            "the default bot's configured namespace is DuckDB's 'default'");
    }

    public function test_source_url_filter_delete_is_replayed_per_url(): void {
        // MxChat_Pinecone_Manager sends ids + {source_url: {$in: urls}}.
        $this->sync->mirror_pinecone_write($this->ok(), $this->post([
            'ids'    => [md5('https://s.test/a')],
            'filter' => ['source_url' => ['$in' => ['https://s.test/a', 'https://s.test/b']]],
        ]), 'https://' . self::REAL_HOST . '/vectors/delete');

        $sql = $this->sql();
        $this->assertStringContainsString("source_url = 'https://s.test/a'", $sql);
        $this->assertStringContainsString("source_url = 'https://s.test/b'", $sql);
    }

    public function test_document_index_delete_uses_the_url_namespace(): void {
        $this->sync->mirror_pinecone_write($this->ok(), $this->post(['ids' => ['d1']]), 'https://' . self::REAL_HOST . '/namespaces/__default__/documents/delete');
        $this->assertStringContainsString("bot_id = 'default' AND vector_id IN ('d1')", $this->sql());

        $this->sync->mirror_pinecone_write($this->ok(), $this->post(['ids' => ['d2']]), 'https://' . self::REAL_HOST . '/namespaces/sales%20fr/documents/delete');
        $this->assertStringContainsString("bot_id = 'sales fr' AND vector_id IN ('d2')", $this->sql());
    }

    public function test_delete_all_pages_through_the_bot_and_drops_every_id(): void {
        $calls = 0;
        $this->conn->responses['SELECT vector_id FROM'] = function () use (&$calls) {
            $calls++;
            return $calls === 1 ? [['vector_id' => 'v1'], ['vector_id' => 'v2']] : [];
        };
        $this->sync->mirror_pinecone_write($this->ok(), $this->post(['deleteAll' => true, 'namespace' => 'sales']), 'https://' . self::REAL_HOST . '/vectors/delete');
        $this->assertStringContainsString("bot_id = 'sales' AND vector_id IN ('v1','v2')", $this->sql());

        $this->conn->log = [];
        $calls = 0;
        $this->sync->mirror_pinecone_write($this->ok(), $this->post(['delete_all' => true]), 'https://' . self::REAL_HOST . '/namespaces/__default__/documents/delete');
        $this->assertStringContainsString("bot_id = 'default' AND vector_id IN ('v1','v2')", $this->sql());
    }

    // ─── Upserts ─────────────────────────────────────────────────────────

    public function test_classic_upsert_is_replayed_with_metadata(): void {
        $this->sync->mirror_pinecone_write($this->ok(), $this->post([
            'vectors' => [[
                'id'       => 'u1',
                'values'   => [0.1, 0.2, 0.3],
                'metadata' => ['text' => 'hello', 'source_url' => 'https://s.test/u', 'type' => 'post', 'title' => 'Hello page', 'bot_id' => 'default'],
            ]],
        ]), 'https://' . self::REAL_HOST . '/vectors/upsert');

        $sql = $this->sql();
        $this->assertStringContainsString('INSERT OR REPLACE INTO "mxchat_vectors"', $sql);
        $this->assertStringContainsString("'u1'", $sql);
        $this->assertStringContainsString("'hello'", $sql);
        $this->assertStringContainsString("'Hello page'", $sql);
    }

    public function test_upsert_bot_comes_from_metadata_like_the_migrator(): void {
        $this->sync->mirror_pinecone_write($this->ok(), $this->post([
            'namespace' => 'ns-x',
            'vectors'   => [['id' => 'u2', 'values' => [0.1, 0.2, 0.3], 'metadata' => ['text' => 't', 'bot_id' => 'sales']]],
        ]), 'https://' . self::REAL_HOST . '/vectors/upsert');
        $this->assertStringContainsString("('u2', 'sales',", $this->sql());
    }

    public function test_document_upsert_is_reshaped(): void {
        $this->sync->mirror_pinecone_write($this->ok(), $this->post([
            'documents' => [['_id' => 'doc1', 'embedding' => [0.1, 0.2, 0.3], 'text' => 'doc text', 'source_url' => 'https://s.test/d']],
        ]), 'https://' . self::REAL_HOST . '/namespaces/__default__/documents/upsert');
        $sql = $this->sql();
        $this->assertStringContainsString("'doc1'", $sql);
        $this->assertStringContainsString("'doc text'", $sql);
    }

    public function test_vectors_of_another_dimension_are_skipped_not_fatal(): void {
        $this->sync->mirror_pinecone_write($this->ok(), $this->post([
            'vectors' => [['id' => 'big', 'values' => array_fill(0, 1536, 0.1), 'metadata' => ['text' => 't']]],
        ]), 'https://' . self::REAL_HOST . '/vectors/upsert');
        $this->assertStringNotContainsString('INSERT', $this->sql());
    }

    // ─── What is never mirrored ──────────────────────────────────────────

    public function test_nothing_is_mirrored_without_a_duckdb_copy(): void {
        delete_option(MxChat_Plus_DuckDB_Pinecone_Migrator::IMPORTED_MARKER_OPTION);
        $this->sync->mirror_pinecone_write($this->ok(), $this->post(['ids' => ['a']]), 'https://' . self::REAL_HOST . '/vectors/delete');
        $this->assertSame([], $this->conn->log);
    }

    public function test_failed_get_other_host_and_proxy_writes_are_ignored(): void {
        $url = 'https://' . self::REAL_HOST . '/vectors/delete';
        $this->sync->mirror_pinecone_write(['response' => ['code' => 500]], $this->post(['ids' => ['a']]), $url);
        $this->sync->mirror_pinecone_write(new WP_Error('http', 'timeout'), $this->post(['ids' => ['a']]), $url);
        $this->sync->mirror_pinecone_write($this->ok(), ['method' => 'GET'], 'https://' . self::REAL_HOST . '/vectors/list?prefix=a');
        $this->sync->mirror_pinecone_write($this->ok(), $this->post(['ids' => ['a']]), 'https://other.svc.pinecone.io/vectors/delete');
        $this->sync->mirror_pinecone_write($this->ok(), $this->post(['ids' => ['a']]), 'https://' . MxChat_Plus_DuckDB_Pinecone_Proxy::pinecone_host() . '/vectors/delete');
        $this->sync->mirror_pinecone_write($this->ok(), $this->post(['ids' => ['a']]), 'https://' . self::REAL_HOST . '/query');
        $this->assertSame([], $this->conn->log);
    }

    public function test_the_mirrored_host_can_be_switched_off_by_filter(): void {
        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_mirrored_pinecone_host'] = '';
        $this->sync->mirror_pinecone_write($this->ok(), $this->post(['ids' => ['a']]), 'https://' . self::REAL_HOST . '/vectors/delete');
        $this->assertSame([], $this->conn->log);
    }

    public function test_disabled_module_mirrors_nothing(): void {
        update_option(MXCHAT_PLUS_DUCKDB_OPTION_KEY, array_merge(MxChat_Plus_DuckDB_Options::defaults(), ['enabled' => false]));
        $this->sync->mirror_pinecone_write($this->ok(), $this->post(['ids' => ['a']]), 'https://' . self::REAL_HOST . '/vectors/delete');
        $this->assertSame([], $this->conn->log);
    }

    public function test_a_backend_error_is_recorded_and_the_response_kept(): void {
        $broken = new class implements MxChat_Plus_DuckDB_Connection {
            public function execute(string $sql, array $params = []): array { throw new RuntimeException('disk full'); }
            public function ping(): bool { return false; }
            public function identifier(): string { return 'mock:broken'; }
            public function supports_capability(string $capability): bool { return false; }
        };
        MxChat_Test_Helpers::inject_mock_connection($broken);
        $response = $this->ok();

        $out = $this->sync->mirror_pinecone_write($response, $this->post(['ids' => ['a']]), 'https://' . self::REAL_HOST . '/vectors/delete');

        $this->assertSame($response, $out);
        $this->assertStringContainsString('disk full', (string) MxChat_Plus_DuckDB_Options::get()['last_error']);
    }

    public function test_match_and_filter_helpers(): void {
        $this->assertSame(['op' => 'upsert', 'protocol' => 'vector', 'namespace' => ''],
            MxChat_Plus_DuckDB_Sync::match_pinecone_write('https://' . self::REAL_HOST . '/vectors/upsert', self::REAL_HOST));
        $this->assertNull(MxChat_Plus_DuckDB_Sync::match_pinecone_write('https://' . self::REAL_HOST . '/vectors/fetch', self::REAL_HOST));
        $this->assertSame(['https://a'], MxChat_Plus_DuckDB_Sync::source_urls_of_filter(['source_url' => 'https://a']));
        $this->assertSame(['https://a'], MxChat_Plus_DuckDB_Sync::source_urls_of_filter(['source_url' => ['$eq' => 'https://a']]));
        $this->assertSame([], MxChat_Plus_DuckDB_Sync::source_urls_of_filter(['type' => ['$eq' => 'pdf']]));
    }

    // ─── Debounced re-sync after in-place rewrites ───────────────────────

    public function test_kb_reimport_apply_schedules_one_full_resync(): void {
        $GLOBALS['__test_valid_nonces'] = ['n' => 'mxchat_kb_reimport'];
        $_POST = ['nonce' => 'n', 'op' => 'apply'];

        $this->sync->observe_kb_reimport();
        $this->sync->observe_kb_reimport(); // the next apply step pushes it back

        $this->assertCount(1, $GLOBALS['__test_cron'], 'debounced: one pending event');
        $this->assertSame(MxChat_Plus_DuckDB_Sync::RESYNC_HOOK, $GLOBALS['__test_cron'][0]['hook']);
        $this->assertSame([], $GLOBALS['__test_cron'][0]['args'], 'no argument = full sync');
    }

    public function test_kb_reimport_scan_or_forged_request_schedules_nothing(): void {
        $GLOBALS['__test_valid_nonces'] = ['n' => 'mxchat_kb_reimport'];
        $_POST = ['nonce' => 'n', 'op' => 'scan'];
        $this->sync->observe_kb_reimport();

        $_POST = ['nonce' => 'forged', 'op' => 'apply'];
        $this->sync->observe_kb_reimport();

        $GLOBALS['__test_current_user_can'] = false;
        $_POST = ['nonce' => 'n', 'op' => 'apply'];
        $this->sync->observe_kb_reimport();

        $this->assertSame([], $GLOBALS['__test_cron']);
    }

    public function test_role_change_of_a_wordpress_entry_schedules_a_targeted_resync(): void {
        $GLOBALS['__test_valid_nonces'] = ['r' => 'mxchat_update_role_nonce'];
        $_POST = ['nonce' => 'r', 'entry_id' => '42', 'data_source' => 'wordpress', 'role_restriction' => 'administrator'];
        $this->sync->observe_role_change();

        // One event, whose single argument is the list of row ids.
        $this->assertSame([[[42]]], array_column($GLOBALS['__test_cron'], 'args'));

        // A Pinecone entry's role lives in MxChat's roles table: nothing to do.
        $GLOBALS['__test_cron'] = [];
        $_POST['data_source'] = 'pinecone';
        $this->sync->observe_role_change();
        $this->assertSame([], $GLOBALS['__test_cron']);
    }

    public function test_targeted_resync_rereads_the_rows_and_upserts_them(): void {
        $wpdb = new MxChat_Test_WPDB();
        $wpdb->prefix = 'wp_rs' . bin2hex(random_bytes(3)) . '_';
        $wpdb->set_response('SHOW COLUMNS FROM', ['id', 'url', 'article_content', 'embedding_vector', 'role_restriction', 'content_type']);
        $wpdb->set_response('SELECT id, url AS source_url', [
            (object) ['id' => 42, 'source_url' => 'https://s.test/r', 'article_content' => 'restricted now',
                      'embedding_vector' => serialize([0.1, 0.2, 0.3]), 'role_restriction' => 'administrator', 'content_type' => 'post'],
        ]);
        $GLOBALS['wpdb'] = $wpdb;

        $this->sync->run_scheduled_resync([42]);

        $this->assertStringContainsString('WHERE id IN (42)', implode("\n", $wpdb->log));
        $this->assertStringContainsString("'administrator'", $this->sql());
    }
}
