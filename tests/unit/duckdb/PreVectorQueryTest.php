<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the `mxchat_pre_vector_query` filter handler — the runtime RAG
 * short-circuit added in v0.6.0. The handler must:
 *
 *   1. honour `$previous !== null` (another filter already answered);
 *   2. return null when the plugin is disabled (fall through to Pinecone);
 *   3. return null when the embedding vector is empty;
 *   4. delegate to Vector_Store::current() on the happy path and wrap the
 *      matches in the Pinecone response shape ['matches' => …, 'namespace' => …];
 *   5. swallow Vector_Store exceptions, emit an admin notice transient, and
 *      return an empty matches array (better than misrouting to a wrong host).
 *
 * Vector_Store::current() is replaced with a stub via reflection so the
 * happy path doesn't need a real DuckDB.
 */
final class PreVectorQueryTest extends TestCase {

    private MxChat_Plus_DuckDB_Search_Adapter $adapter;

    protected function setUp(): void {
        $GLOBALS['__test_options']   = [];
        $GLOBALS['__test_transients'] = [];

        MxChat_Test_Helpers::reset_vector_store_current();

        // Install a baseline "enabled" options bundle. Individual tests
        // override as needed.
        update_option('mxchat_plus_duckdb_options', array_merge(
            MxChat_Plus_DuckDB_Options::defaults(),
            ['enabled' => true]
        ));

        $this->adapter = MxChat_Plus_DuckDB_Search_Adapter::instance();
    }

    private function installStubStore(callable $query_callback): void {
        $stub = new class($query_callback) extends MxChat_Plus_DuckDB_Vector_Store {
            private $cb;
            public function __construct(callable $cb) {
                $this->cb = $cb;
            }
            public function query_pinecone_shape(array $embedding, int $top_k, string $bot_id = 'default', array $filter = []): array {
                return ($this->cb)($embedding, $top_k, $bot_id, $filter);
            }
        };
        $r = new ReflectionProperty(MxChat_Plus_DuckDB_Vector_Store::class, 'current');
        $r->setValue(null, $stub);
    }

    // ─── Early-return guards ──────────────────────────────────────────────

    public function test_previous_non_null_short_circuits_immediately(): void {
        // The filter contract: if a higher-priority handler already supplied
        // matches, we must not override.
        $prior = ['matches' => [['id' => 'beat-me-to-it', 'score' => 1.0]]];
        $out = $this->adapter->pre_vector_query($prior, ['vector' => [0.1]]);
        $this->assertSame($prior, $out);
    }

    public function test_disabled_plugin_returns_null(): void {
        update_option('mxchat_plus_duckdb_options', array_merge(
            MxChat_Plus_DuckDB_Options::defaults(),
            ['enabled' => false]
        ));
        $out = $this->adapter->pre_vector_query(null, ['vector' => [0.1, 0.2]]);
        $this->assertNull($out);
    }

    public function test_empty_vector_returns_null(): void {
        $this->assertNull($this->adapter->pre_vector_query(null, []));
        $this->assertNull($this->adapter->pre_vector_query(null, ['vector' => []]));
        $this->assertNull($this->adapter->pre_vector_query(null, ['vector' => 'not-an-array']));
    }

    // ─── Happy path ───────────────────────────────────────────────────────

    public function test_happy_path_wraps_matches_in_pinecone_response_shape(): void {
        $expected_matches = [
            ['id' => 'v1', 'score' => 0.95, 'metadata' => ['text' => 'foo']],
            ['id' => 'v2', 'score' => 0.87, 'metadata' => ['text' => 'bar']],
        ];
        $captured = [];
        $this->installStubStore(function ($embedding, $top_k, $bot_id, $filter) use ($expected_matches, &$captured) {
            $captured = compact('embedding', 'top_k', 'bot_id', 'filter');
            return $expected_matches;
        });

        $out = $this->adapter->pre_vector_query(null, [
            'vector'    => [0.1, 0.2, 0.3],
            'top_k'     => 10,
            'namespace' => 'support_fr',
            'bot_id'    => 'unused-when-namespace-present',
            'filter'    => ['type' => ['$eq' => 'post']],
        ]);

        $this->assertSame(['matches' => $expected_matches, 'namespace' => 'support_fr'], $out);
        $this->assertSame([0.1, 0.2, 0.3], $captured['embedding']);
        $this->assertSame(10, $captured['top_k']);
        $this->assertSame('support_fr', $captured['bot_id']);
        $this->assertSame(['type' => ['$eq' => 'post']], $captured['filter']);
    }

    public function test_namespace_falls_back_to_bot_id_then_default(): void {
        $captured_bot = '';
        $this->installStubStore(function ($emb, $k, $bot, $f) use (&$captured_bot) {
            $captured_bot = $bot;
            return [];
        });

        $this->adapter->pre_vector_query(null, ['vector' => [0.1], 'bot_id' => 'fallback_bot']);
        $this->assertSame('fallback_bot', $captured_bot, 'namespace missing → bot_id wins');

        $this->adapter->pre_vector_query(null, ['vector' => [0.1]]);
        $this->assertSame('default', $captured_bot, 'both missing → default');

        $this->adapter->pre_vector_query(null, ['vector' => [0.1], 'namespace' => '', 'bot_id' => '']);
        $this->assertSame('default', $captured_bot, 'empty strings → default (not "")');
    }

    public function test_top_k_falls_back_to_option_then_50(): void {
        $captured_k = 0;
        $this->installStubStore(function ($emb, $k, $bot, $f) use (&$captured_k) {
            $captured_k = $k;
            return [];
        });

        // Option default top_k is 50 per Options::defaults(); no explicit ctx → option wins.
        $this->adapter->pre_vector_query(null, ['vector' => [0.1]]);
        $this->assertSame(50, $captured_k);

        // ctx wins over option.
        $this->adapter->pre_vector_query(null, ['vector' => [0.1], 'top_k' => 7]);
        $this->assertSame(7, $captured_k);
    }

    // ─── inject_pinecone_addon_options (pre_option_* shortcircuit) ───────

    public function test_pre_option_returns_value_unchanged_when_plugin_disabled(): void {
        // Plugin enabled in setUp; override to disabled for this case.
        update_option('mxchat_plus_duckdb_options', array_merge(
            MxChat_Plus_DuckDB_Options::defaults(),
            ['enabled' => false, 'takeover_default_bot_pinecone' => true]
        ));

        // Passing `false` mirrors what WP passes when no other pre_option_*
        // handler has shortcircuited.
        $this->assertFalse($this->adapter->inject_pinecone_addon_options(false));
    }

    public function test_pre_option_returns_value_unchanged_when_takeover_off(): void {
        // Default: enabled but takeover off — must NOT hijack the option.
        update_option('mxchat_plus_duckdb_options', array_merge(
            MxChat_Plus_DuckDB_Options::defaults(),
            ['enabled' => true, 'takeover_default_bot_pinecone' => false]
        ));

        $this->assertFalse($this->adapter->inject_pinecone_addon_options(false));
    }

    public function test_pre_option_returns_pinecone_shaped_config_when_takeover_on(): void {
        update_option('mxchat_plus_duckdb_options', array_merge(
            MxChat_Plus_DuckDB_Options::defaults(),
            ['enabled' => true, 'takeover_default_bot_pinecone' => true]
        ));

        $out = $this->adapter->inject_pinecone_addon_options(false);

        $this->assertIsArray($out);
        $this->assertSame('1', $out['mxchat_use_pinecone']);
        // Host = our proxy REST endpoint, no scheme, no trailing slash.
        $this->assertNotEmpty($out['mxchat_pinecone_host']);
        $this->assertStringNotContainsString('https://', $out['mxchat_pinecone_host']);
        // Default-bot namespace.
        $this->assertSame('default', $out['mxchat_pinecone_namespace']);
        // API key is the per-namespace proxy token, not empty.
        $this->assertNotEmpty($out['mxchat_pinecone_api_key']);
        // Environment + index are kept empty to match mxchat's shape.
        $this->assertSame('', $out['mxchat_pinecone_environment']);
        $this->assertSame('', $out['mxchat_pinecone_index']);
    }

    // ─── Error path ───────────────────────────────────────────────────────

    public function test_vector_store_exception_returns_empty_matches_and_surfaces_admin_notice(): void {
        $this->installStubStore(function () {
            throw new \RuntimeException('DuckDB connection refused');
        });

        $out = $this->adapter->pre_vector_query(null, [
            'vector'    => [0.1, 0.2],
            'namespace' => 'critical_bot',
        ]);

        // Empty matches for the critical_bot namespace — never null, which
        // would have let mxchat fall through to a misrouted Pinecone host.
        $this->assertSame(['matches' => [], 'namespace' => 'critical_bot'], $out);

        // last_error option captured.
        $opts = get_option('mxchat_plus_duckdb_options');
        $this->assertStringContainsString('DuckDB connection refused', (string) ($opts['last_error'] ?? ''));

        // Admin-notice transient set so the operator sees the failure.
        $notice = get_transient(MxChat_Plus_DuckDB_Search_Adapter::ERROR_TRANSIENT);
        $this->assertStringContainsString('DuckDB connection refused', (string) $notice);
    }
}
