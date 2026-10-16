<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the two Option B seams of the search adapter and what protects the
 * host's real settings around them:
 *
 *   - the default-bot takeover (`pre_option_mxchat_pinecone_addon_options`):
 *     the stored row with five keys replaced, never a fresh array; MxChat's
 *     own sanitizer and document-index AJAX see the stored row; a card save
 *     that posts the proxy view back is put right before it is written;
 *   - the document-index AJAX refusal and the outbound token guard;
 *   - Multi-Bot routing (`mxchat_get_bot_pinecone_config`): opt-in, with the
 *     one-time upgrade that keeps an older install's working bots.
 *
 * The WordPress shims' get_option() never runs pre_option filters, so the
 * stored row is what get_option() returns and the filter is called directly.
 */
final class SearchAdapterTakeoverTest extends TestCase {

    private MxChat_Plus_DuckDB_Search_Adapter $adapter;

    /** A real 3.2.25 row, as MxChat_Admin::sanitize_pinecone_settings() writes it. */
    private const STORED = [
        'mxchat_use_pinecone'                => '1',
        'mxchat_pinecone_api_key'            => 'pcsk_real_key',
        'mxchat_pinecone_host'               => 'real-index-abc.svc.aped-1234.pinecone.io',
        'mxchat_pinecone_index'              => 'kb',
        'mxchat_pinecone_environment'        => '',
        'mxchat_pinecone_top_k'              => '200',
        'mxchat_pinecone_index_type'         => 'document',
        'mxchat_pinecone_docs_verified_host' => 'real-index-abc.svc.aped-1234.pinecone.io',
        'mxchat_pinecone_vector_host'        => 'old-classic.svc.aped-1234.pinecone.io',
        'mxchat_pinecone_vector_index'       => 'kb-classic',
        'mxchat_pinecone_docs_cloud'         => 'gcp',
        'mxchat_pinecone_docs_region'        => 'us-central1',
        'mxchat_pinecone_docs_language'      => 'fr',
    ];

    protected function setUp(): void {
        $GLOBALS['__test_options']      = [];
        $GLOBALS['__test_transients']   = [];
        $GLOBALS['__test_doing_filter'] = [];
        $GLOBALS['__test_doing_ajax']   = false;
        $GLOBALS['__test_current_user_can'] = true;
        unset($GLOBALS['__test_ajax_response']);
        $_REQUEST = [];
        MxChat_Test_Helpers::reset_schema_memoisation();

        $this->set_opts(['enabled' => true, 'takeover_default_bot_pinecone' => true]);
        $this->adapter = MxChat_Plus_DuckDB_Search_Adapter::instance();
    }

    protected function tearDown(): void {
        $GLOBALS['__test_doing_filter'] = [];
        $GLOBALS['__test_doing_ajax']   = false;
        $_REQUEST = [];
    }

    private function set_opts(array $patch): void {
        update_option(MXCHAT_PLUS_DUCKDB_OPTION_KEY, array_merge(MxChat_Plus_DuckDB_Options::defaults(), $patch));
    }

    private function proxy_token(): string {
        return MxChat_Plus_DuckDB_Pinecone_Proxy::get_or_create_token_for('default');
    }

    // ─── The proxy view of the stored row ────────────────────────────────

    public function test_takeover_answers_with_the_stored_row_and_five_keys_replaced(): void {
        update_option('mxchat_pinecone_addon_options', self::STORED);

        $view = $this->adapter->inject_pinecone_addon_options(false);

        $this->assertIsArray($view);
        $this->assertSame('1', $view['mxchat_use_pinecone']);
        $this->assertSame($this->proxy_token(), $view['mxchat_pinecone_api_key']);
        $this->assertSame(MxChat_Plus_DuckDB_Pinecone_Proxy::pinecone_host(), $view['mxchat_pinecone_host']);
        $this->assertSame('default', $view['mxchat_pinecone_namespace']);
        $this->assertSame('vector', $view['mxchat_pinecone_index_type'],
            'a stored document index would route MxChat to the documents API, which the proxy does not speak');

        // Everything else MxChat keeps in the row survives the read.
        $this->assertSame('200', $view['mxchat_pinecone_top_k'], "MxChat reads Top K from this value");
        foreach (['mxchat_pinecone_index', 'mxchat_pinecone_docs_verified_host', 'mxchat_pinecone_vector_host',
                  'mxchat_pinecone_vector_index', 'mxchat_pinecone_docs_cloud', 'mxchat_pinecone_docs_region',
                  'mxchat_pinecone_docs_language'] as $key) {
            $this->assertSame(self::STORED[$key], $view[$key], $key . ' must come from the stored row');
        }
        // And nothing was written to the real row.
        $this->assertSame(self::STORED, get_option('mxchat_pinecone_addon_options'));
    }

    public function test_missing_top_k_is_filled_from_this_plugins_top_k(): void {
        $this->set_opts(['enabled' => true, 'takeover_default_bot_pinecone' => true, 'top_k' => 12]);
        $view = $this->adapter->inject_pinecone_addon_options(false);
        $this->assertSame('12', $view['mxchat_pinecone_top_k']);
    }

    public function test_no_stored_row_still_yields_a_usable_view(): void {
        $view = $this->adapter->inject_pinecone_addon_options(false);
        $this->assertSame('1', $view['mxchat_use_pinecone']);
        $this->assertSame('default', $view['mxchat_pinecone_namespace']);
        $this->assertSame('50', $view['mxchat_pinecone_top_k']);
    }

    public function test_hosts_sanitizer_reads_the_stored_row(): void {
        // MxChat_Admin::sanitize_pinecone_settings() (3.2.22+) carries the
        // namespace and the verified document host from its own get_option()
        // read. While its filter runs, our pre_option must step aside.
        update_option('mxchat_pinecone_addon_options', self::STORED);
        $GLOBALS['__test_doing_filter'] = ['sanitize_option_mxchat_pinecone_addon_options'];

        $this->assertFalse($this->adapter->inject_pinecone_addon_options(false));
    }

    public function test_hosts_sanitizer_simulation_keeps_the_real_namespace_and_verified_host(): void {
        $stored = self::STORED + ['mxchat_pinecone_namespace' => ''];
        update_option('mxchat_pinecone_addon_options', $stored);
        $GLOBALS['__test_doing_filter'] = ['sanitize_option_mxchat_pinecone_addon_options'];

        // What the host's `$existing = get_option(...)` resolves to.
        $pre = $this->adapter->inject_pinecone_addon_options(false);
        $existing = $pre !== false ? $pre : get_option('mxchat_pinecone_addon_options', []);

        $this->assertSame('', $existing['mxchat_pinecone_namespace'], "our 'default' must never be carried into the real row");
        $this->assertSame(self::STORED['mxchat_pinecone_docs_verified_host'], $existing['mxchat_pinecone_docs_verified_host']);
    }

    public function test_document_index_ajax_reads_the_stored_row(): void {
        update_option('mxchat_pinecone_addon_options', self::STORED);
        $GLOBALS['__test_doing_ajax'] = true;
        $_REQUEST['action'] = 'mxchat_pinecone_docs_check_index';

        $this->assertFalse($this->adapter->inject_pinecone_addon_options(false));

        // Any other AJAX action still gets the proxy view.
        $_REQUEST['action'] = 'mxchat_refresh_pinecone_entries';
        $this->assertIsArray($this->adapter->inject_pinecone_addon_options(false));
    }

    // ─── restore_real_pinecone_fields (pre_update_option backstop) ───────

    public function test_card_save_of_the_proxy_view_restores_the_real_fields(): void {
        update_option('mxchat_pinecone_addon_options', self::STORED);
        $posted = $this->adapter->inject_pinecone_addon_options(false);
        // What the host's sanitizer then produces from the posted card: the
        // view's values, with the owner's real edit of Top K.
        $posted['mxchat_pinecone_top_k'] = '300';
        $posted['mxchat_pinecone_docs_verified_host'] = '';

        $saved = $this->adapter->restore_real_pinecone_fields($posted);

        $this->assertSame('pcsk_real_key', $saved['mxchat_pinecone_api_key']);
        $this->assertSame(self::STORED['mxchat_pinecone_host'], $saved['mxchat_pinecone_host']);
        $this->assertSame('1', $saved['mxchat_use_pinecone']);
        $this->assertSame('document', $saved['mxchat_pinecone_index_type']);
        $this->assertSame(self::STORED['mxchat_pinecone_docs_verified_host'], $saved['mxchat_pinecone_docs_verified_host']);
        $this->assertArrayNotHasKey('mxchat_pinecone_namespace', $saved,
            'the stored row had no namespace: our override must not be persisted');
        $this->assertSame('300', $saved['mxchat_pinecone_top_k'], 'a real edit goes through');
    }

    public function test_restore_puts_back_a_stored_namespace_and_use_pinecone_off(): void {
        update_option('mxchat_pinecone_addon_options', [
            'mxchat_use_pinecone'       => '0',
            'mxchat_pinecone_api_key'   => '',
            'mxchat_pinecone_host'      => '',
            'mxchat_pinecone_namespace' => 'site-ns',
        ]);
        $posted = $this->adapter->inject_pinecone_addon_options(false);

        $saved = $this->adapter->restore_real_pinecone_fields($posted);

        $this->assertSame('0', $saved['mxchat_use_pinecone'], 'a WordPress-database site must not be switched to Pinecone');
        $this->assertSame('site-ns', $saved['mxchat_pinecone_namespace']);
        $this->assertSame('', $saved['mxchat_pinecone_api_key']);
        $this->assertSame('', $saved['mxchat_pinecone_host']);
    }

    public function test_restore_reverts_a_vector_host_that_points_at_the_proxy(): void {
        update_option('mxchat_pinecone_addon_options', self::STORED);
        $posted = $this->adapter->inject_pinecone_addon_options(false);
        $posted['mxchat_pinecone_vector_host'] = 'https://' . MxChat_Plus_DuckDB_Pinecone_Proxy::pinecone_host() . '/';

        $saved = $this->adapter->restore_real_pinecone_fields($posted);
        $this->assertSame(self::STORED['mxchat_pinecone_vector_host'], $saved['mxchat_pinecone_vector_host']);
    }

    public function test_restore_leaves_a_save_with_real_credentials_alone(): void {
        update_option('mxchat_pinecone_addon_options', self::STORED);
        $typed = array_merge(self::STORED, [
            'mxchat_pinecone_api_key'   => 'pcsk_new_key',
            'mxchat_pinecone_host'      => 'new-index.svc.pinecone.io',
            'mxchat_pinecone_namespace' => 'default',
        ]);
        $this->assertSame($typed, $this->adapter->restore_real_pinecone_fields($typed));
    }

    public function test_restore_is_inert_when_the_takeover_is_off(): void {
        $this->set_opts(['enabled' => true, 'takeover_default_bot_pinecone' => false]);
        update_option('mxchat_pinecone_addon_options', self::STORED);
        $posted = ['mxchat_pinecone_api_key' => $this->proxy_token(), 'mxchat_pinecone_host' => MxChat_Plus_DuckDB_Pinecone_Proxy::pinecone_host()];
        $this->assertSame($posted, $this->adapter->restore_real_pinecone_fields($posted));
    }

    // ─── Document-index AJAX refusal ─────────────────────────────────────

    public function test_document_index_actions_are_refused_while_the_takeover_is_on(): void {
        try {
            $this->adapter->refuse_document_index_action();
            $this->fail('expected a JSON error');
        } catch (MxChat_Test_AjaxResponseException $e) {
            $this->assertFalse($e->success);
            $this->assertSame('mxchat_plus_duckdb_takeover', $e->payload['code']);
            $this->assertStringContainsString('Default-bot routing', $e->payload['message']);
        }
    }

    public function test_document_index_actions_pass_when_the_takeover_is_off(): void {
        $this->set_opts(['enabled' => true, 'takeover_default_bot_pinecone' => false]);
        $this->adapter->refuse_document_index_action();
        $this->assertArrayNotHasKey('__test_ajax_response', $GLOBALS, 'MxChat\'s own handler must run');
    }

    public function test_every_host_document_index_action_is_listed(): void {
        // Host literals from MxChat_Pinecone_Documents::boot() (MxChat 3.2.25).
        $this->assertSame([
            'mxchat_pinecone_docs_create_index',
            'mxchat_pinecone_docs_check_index',
            'mxchat_pinecone_docs_suggest_name',
            'mxchat_pinecone_docs_migrate',
            'mxchat_pinecone_docs_delete_old_index',
        ], MxChat_Plus_DuckDB_Search_Adapter::HOST_DOCUMENT_INDEX_ACTIONS);
    }

    // ─── Outbound token guard ────────────────────────────────────────────

    public function test_proxy_token_is_never_sent_to_pinecone(): void {
        $token = $this->proxy_token();
        $refused = $this->adapter->refuse_proxy_token_to_pinecone(false, ['headers' => ['Api-Key' => $token]], 'https://api.pinecone.io/indexes');
        $this->assertInstanceOf(WP_Error::class, $refused);

        // Header name is case-insensitive, an index host is covered too.
        $refused = $this->adapter->refuse_proxy_token_to_pinecone(false, ['headers' => ['api-key' => $token]], 'https://idx-1.svc.aped.pinecone.io/describe_index_stats');
        $this->assertInstanceOf(WP_Error::class, $refused);

        // Raw string headers.
        $refused = $this->adapter->refuse_proxy_token_to_pinecone(false, ['headers' => "Accept: application/json\r\nApi-Key: {$token}"], 'https://api.pinecone.io/indexes');
        $this->assertInstanceOf(WP_Error::class, $refused);

        // The legacy global token is ours too.
        $legacy = MxChat_Plus_DuckDB_Pinecone_Proxy::get_or_create_token();
        $refused = $this->adapter->refuse_proxy_token_to_pinecone(false, ['headers' => ['Api-Key' => $legacy]], 'https://api.pinecone.io/indexes');
        $this->assertInstanceOf(WP_Error::class, $refused);
    }

    public function test_token_guard_lets_everything_else_through(): void {
        $token = $this->proxy_token();
        $this->assertFalse($this->adapter->refuse_proxy_token_to_pinecone(false, ['headers' => ['Api-Key' => 'pcsk_real']], 'https://api.pinecone.io/indexes'));
        $this->assertFalse($this->adapter->refuse_proxy_token_to_pinecone(false, ['headers' => ['Api-Key' => $token]], 'https://example.test/wp-json/mxchat-plus/v1/pinecone-proxy/query'),
            'the token is meant for our own proxy');
        $this->assertFalse($this->adapter->refuse_proxy_token_to_pinecone(false, ['headers' => ['Api-Key' => $token]], 'https://notpinecone.io.example.com/x'));
        $earlier = ['response' => ['code' => 200]];
        $this->assertSame($earlier, $this->adapter->refuse_proxy_token_to_pinecone($earlier, ['headers' => ['Api-Key' => $token]], 'https://api.pinecone.io/indexes'),
            'an earlier short-circuit wins');
    }

    // ─── Multi-Bot routing (mxchat_get_bot_pinecone_config) ──────────────

    public function test_default_and_testing_bots_pass_through(): void {
        $this->set_opts(['enabled' => true, 'takeover_multibot_bots' => true, 'takeover_bot_ids' => []]);
        $config = ['use_pinecone' => true, 'host' => 'real.pinecone.io'];
        $this->assertSame($config, $this->adapter->inject_pinecone_config($config, 'default'));
        $this->assertSame($config, $this->adapter->inject_pinecone_config($config, 'testing'));
        $this->assertSame([], $this->adapter->inject_pinecone_config([], ''));
    }

    public function test_bot_not_opted_in_keeps_the_add_ons_config(): void {
        $this->set_opts(['enabled' => true, 'takeover_multibot_bots' => false, 'takeover_bot_ids' => ['sales']]);
        $config = ['use_pinecone' => false];
        $this->assertSame($config, $this->adapter->inject_pinecone_config($config, 'support'),
            'a WordPress-database bot must keep the shared knowledge MxChat gives it');
        $this->assertSame([], $this->adapter->inject_pinecone_config([], 'support'));
    }

    public function test_listed_bot_is_routed_with_its_own_token_and_vector_protocol(): void {
        $this->set_opts(['enabled' => true, 'takeover_multibot_bots' => false, 'takeover_bot_ids' => ['sales']]);
        $out = $this->adapter->inject_pinecone_config(['top_k' => 9, 'host' => 'real.pinecone.io'], 'sales');

        $this->assertTrue($out['use_pinecone']);
        $this->assertSame(MxChat_Plus_DuckDB_Pinecone_Proxy::pinecone_host(), $out['host']);
        $this->assertSame('sales', $out['namespace']);
        $this->assertSame('vector', $out['index_type']);
        $this->assertSame(MxChat_Plus_DuckDB_Pinecone_Proxy::get_or_create_token_for('sales'), $out['api_key']);
        $this->assertNotSame($this->proxy_token(), $out['api_key']);
        $this->assertSame(9, $out['top_k'], 'keys we do not own are kept');
    }

    public function test_all_bots_routed_except_a_document_index_bot(): void {
        $this->set_opts(['enabled' => true, 'takeover_multibot_bots' => true, 'takeover_bot_ids' => []]);
        $this->assertSame('anybot', $this->adapter->inject_pinecone_config([], 'anybot')['namespace']);

        $doc = ['use_pinecone' => true, 'host' => 'docs.pinecone.io', 'index_type' => 'document'];
        $this->assertSame($doc, $this->adapter->inject_pinecone_config($doc, 'anybot'));
    }

    public function test_disabled_module_routes_nothing(): void {
        $this->set_opts(['enabled' => false, 'takeover_multibot_bots' => true]);
        $this->assertSame(['x' => 1], $this->adapter->inject_pinecone_config(['x' => 1], 'sales'));
    }

    // ─── Upgrade of an install from before the opt-in ────────────────────

    private function legacy_install(): void {
        $legacy = MxChat_Plus_DuckDB_Options::defaults();
        unset($legacy['takeover_multibot_bots'], $legacy['takeover_bot_ids']);
        $legacy['enabled'] = true;
        update_option(MXCHAT_PLUS_DUCKDB_OPTION_KEY, $legacy);
    }

    public function test_upgrade_keeps_routing_only_the_bots_with_vectors_in_duckdb(): void {
        $this->legacy_install();
        MxChat_Test_Helpers::inject_mock_connection(new MxChat_Test_RecordingConnection('mock:routing', [
            'SELECT DISTINCT bot_id' => [['bot_id' => 'default'], ['bot_id' => 'sales']],
        ]));

        $this->assertSame('sales', $this->adapter->inject_pinecone_config([], 'sales')['namespace']);
        $this->assertSame(['use_pinecone' => false], $this->adapter->inject_pinecone_config(['use_pinecone' => false], 'support'),
            'a bot with no vectors was answering from an empty namespace: it goes back to its own storage');

        $stored = get_option(MXCHAT_PLUS_DUCKDB_OPTION_KEY);
        $this->assertSame(['sales'], $stored['takeover_bot_ids']);
        $this->assertFalse($stored['takeover_multibot_bots']);
    }

    public function test_upgrade_routes_everything_while_duckdb_cannot_be_read(): void {
        $this->legacy_install();
        $failing = new class implements MxChat_Plus_DuckDB_Connection {
            public function execute(string $sql, array $params = []): array {
                if (stripos($sql, 'schema_meta') !== false && stripos($sql, 'SELECT value') !== false) {
                    return [['value' => (string) MxChat_Plus_DuckDB_Vector_Store_Schema::TARGET_SCHEMA_VERSION]];
                }
                throw new RuntimeException('backend down');
            }
            public function ping(): bool { return false; }
            public function identifier(): string { return 'mock:down'; }
            public function supports_capability(string $capability): bool { return false; }
        };
        MxChat_Test_Helpers::inject_mock_connection($failing);

        $this->assertSame('support', $this->adapter->inject_pinecone_config([], 'support')['namespace'],
            'nothing moves until the bots can be listed');
        $this->assertArrayNotHasKey('takeover_bot_ids', get_option(MXCHAT_PLUS_DUCKDB_OPTION_KEY));
        $this->assertNotFalse(get_transient(MxChat_Plus_DuckDB_Options::ROUTING_MIGRATION_TRANSIENT), 'retries are spaced out');
    }

    public function test_upgrade_of_a_disabled_module_records_no_bot(): void {
        $legacy = MxChat_Plus_DuckDB_Options::defaults();
        unset($legacy['takeover_multibot_bots'], $legacy['takeover_bot_ids']);
        update_option(MXCHAT_PLUS_DUCKDB_OPTION_KEY, $legacy);

        $this->assertTrue(MxChat_Plus_DuckDB_Options::maybe_migrate_bot_routing());
        $this->assertSame([], get_option(MXCHAT_PLUS_DUCKDB_OPTION_KEY)['takeover_bot_ids']);
    }

    public function test_telemetry_writes_do_not_mark_a_legacy_install_as_migrated(): void {
        $this->legacy_install();
        MxChat_Plus_DuckDB_Options::update(['last_sync_at' => 1234]);

        $stored = get_option(MXCHAT_PLUS_DUCKDB_OPTION_KEY);
        $this->assertSame(1234, $stored['last_sync_at']);
        $this->assertArrayNotHasKey('takeover_bot_ids', $stored,
            'a default [] stamped here would silently stop routing working bots');
        $this->assertFalse(MxChat_Plus_DuckDB_Options::bot_routing()['migrated']);
    }
}
