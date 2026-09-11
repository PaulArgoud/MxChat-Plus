<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the WP-CLI command surface — 11 sub-commands, each delegating
 * to a class we already cover. The tests focus on the CLI-specific
 * behaviour (argument parsing, error-path on missing flags, the table
 * formatting + progress bar usage), not the underlying business logic.
 *
 * WP_CLI is shimmed in tests/bootstrap.php: every ::log / ::success /
 * ::error call lands in a static buffer; ::error additionally throws
 * MxChat_Test_CliExit so we can assert that a command terminated via
 * an error rather than a success.
 */
final class CliTest extends TestCase {

    private MxChat_Plus_DuckDB_CLI $cli;
    private $mock_conn;

    protected function setUp(): void {
        $GLOBALS['__test_options']     = [];
        $GLOBALS['__test_transients']  = [];
        $GLOBALS['__test_filter_overrides'] = [];
        unset($GLOBALS['__test_cli_format_items']);
        WP_CLI::reset();

        MxChat_Test_Helpers::reset_schema_memoisation();
        MxChat_Plus_DuckDB_Cache::$cache_gen_map = [];

        $this->mock_conn = new MxChat_Test_RecordingConnection('mock:cli-backend', [
            'SELECT COUNT(*)' => [['c' => 8421]],
        ]);

        $defaults = MxChat_Plus_DuckDB_Options::defaults();
        update_option('mxchat_plus_duckdb_options', array_merge($defaults, [
            'enabled'       => true,
            'embedding_dim' => 3,
            'last_sync_at'  => 1700000000,
            'last_compact_at' => 1700001000,
            'last_error'    => '',
        ]));

        MxChat_Test_Helpers::inject_mock_connection($this->mock_conn);

        $this->cli = new MxChat_Plus_DuckDB_CLI();
    }

    // ─── test ─────────────────────────────────────────────────────────────

    public function test_test_command_succeeds_with_backend_identity_and_count(): void {
        $this->cli->test([], []);

        $log = implode("\n", WP_CLI::$log_buf);
        $this->assertStringContainsString('mock:cli-backend', $log);
        $this->assertStringContainsString('Ping: OK', $log);
        $this->assertStringContainsString('Vectors: 8421', $log);
        $this->assertNotEmpty(WP_CLI::$success_buf, 'a final ::success message is required');
    }

    public function test_test_command_errors_when_ping_fails(): void {
        $this->mock_conn->ping_returns = false;
        $this->expectException(MxChat_Test_CliExit::class);
        $this->cli->test([], []);
    }

    // ─── stats ────────────────────────────────────────────────────────────

    public function test_stats_command_emits_a_table_with_the_documented_fields(): void {
        $this->cli->stats([], []);

        $captured = $GLOBALS['__test_cli_format_items'] ?? null;
        $this->assertNotNull($captured, 'stats must call WP_CLI\\Utils\\format_items');
        $this->assertSame('table', $captured['format']);

        $keys = array_column($captured['items'], 'key');
        // These rows are what dashboards / `wp mxchat-plus stats` ops parse;
        // a rename = breaking change for any operator script.
        foreach (['enabled', 'mode', 'embedding_dim', 'vectors', 'searches',
                  'p50_ms', 'p95_ms', 'p99_ms', 'last_sync_at', 'last_compact_at', 'last_error'] as $expected_key) {
            $this->assertContains($expected_key, $keys, "stats table must include the '$expected_key' row");
        }
    }

    // ─── cache --flush ────────────────────────────────────────────────────

    public function test_cache_command_without_flush_just_prints_hint(): void {
        $this->cli->cache([], []);

        $log = implode(' ', WP_CLI::$log_buf);
        $this->assertStringContainsString('Pass --flush to confirm', $log);
        $this->assertEmpty(WP_CLI::$success_buf, 'no flush flag → no action taken');
    }

    public function test_cache_flush_bumps_the_generation_counter(): void {
        $before = MxChat_Plus_DuckDB_Cache::cache_generation('default');
        $this->cli->cache([], ['flush' => true]);

        $after = MxChat_Plus_DuckDB_Cache::cache_generation('default');
        $this->assertGreaterThan($before, $after,
            'cache --flush must bump the (per-bot) generation counter — O(1) invalidation');
        $msg = WP_CLI::$success_buf[0] ?? '';
        $this->assertMatchesRegularExpression('/flushed/i', $msg);
        $this->assertMatchesRegularExpression('/bot/i', $msg);
    }

    // ─── export / import ─────────────────────────────────────────────────

    public function test_export_errors_when_path_argument_missing(): void {
        $this->expectException(MxChat_Test_CliExit::class);
        $this->expectExceptionMessageMatches('/path/i');
        $this->cli->export([], []);
    }

    public function test_import_errors_when_path_argument_missing(): void {
        $this->expectException(MxChat_Test_CliExit::class);
        $this->expectExceptionMessageMatches('/path/i');
        $this->cli->import([], []);
    }

    public function test_export_invokes_copy_to_with_the_given_path(): void {
        $this->cli->export([], ['path' => '/tmp/cli-export.parquet']);

        $sql = implode("\n", $this->mock_conn->log);
        $this->assertStringContainsString("'/tmp/cli-export.parquet'", $sql);
        $this->assertStringContainsString('FORMAT PARQUET', $sql);
        $msg = WP_CLI::$success_buf[0] ?? '';
        $this->assertStringContainsString('Exported', $msg);
        $this->assertStringContainsString('/tmp/cli-export.parquet', $msg);
    }

    // ─── metrics ─────────────────────────────────────────────────────────

    public function test_metrics_command_emits_a_snapshot_table(): void {
        $this->cli->metrics([], []);
        $this->assertNotNull($GLOBALS['__test_cli_format_items'] ?? null);
        $this->assertSame('table', $GLOBALS['__test_cli_format_items']['format']);
        $this->assertEmpty(WP_CLI::$success_buf, 'snapshot path must not emit ::success');
    }

    public function test_metrics_reset_flag_clears_counters_and_reports_success(): void {
        // Seed something so we can verify the reset actually happened.
        MxChat_Plus_DuckDB_Metrics::observe_latency(150);
        $before = MxChat_Plus_DuckDB_Metrics::snapshot();
        $this->assertSame(1, $before['searches'], 'precondition');

        $this->cli->metrics([], ['reset' => true]);

        $after = MxChat_Plus_DuckDB_Metrics::snapshot();
        $this->assertSame(0, $after['searches'], 'reset must wipe the counter');
        $this->assertNotEmpty(WP_CLI::$success_buf);
    }

    // ─── migrate-from-pinecone ───────────────────────────────────────────

    public function test_migrate_command_errors_when_required_flags_missing(): void {
        $this->expectException(MxChat_Test_CliExit::class);
        $this->expectExceptionMessageMatches('/api-key|host/i');
        $this->cli->migrate_from_pinecone([], []);
    }

    public function test_migrate_command_errors_when_only_one_required_flag_given(): void {
        $this->expectException(MxChat_Test_CliExit::class);
        $this->expectExceptionMessageMatches('/api-key|host/i');
        $this->cli->migrate_from_pinecone([], ['api-key' => 'pcsk_xyz']);
    }

    // ─── async-reprocess ─────────────────────────────────────────────────

    public function test_async_reprocess_parses_post_types_csv_and_enqueues(): void {
        $GLOBALS['__test_as_queue'] = [];
        $GLOBALS['__test_wp_query_matcher'] = function (array $args) {
            return ['posts' => [1, 2], 'found_posts' => 2];
        };

        $this->cli->async_reprocess([], ['post-types' => 'post,page,product']);

        $this->assertCount(2, $GLOBALS['__test_as_queue']);
        $this->assertNotEmpty(WP_CLI::$success_buf);
        $this->assertStringContainsString('Queued', WP_CLI::$success_buf[0]);
    }

    // ─── compact ─────────────────────────────────────────────────────────

    public function test_compact_command_runs_the_compactor_and_reports(): void {
        // No alive rows + no DuckDB pages → 0 deleted, but the command
        // still completes (success path) and emits a ::success message.
        $GLOBALS['wpdb'] = new MxChat_Test_WPDB();
        $GLOBALS['wpdb']->prefix = 'wp_cli_test_';
        $GLOBALS['wpdb']->set_response('SELECT id, url AS source_url', function () { return []; });

        // last_sync_at is 1700000000 — well past the 1h freshness floor,
        // so the compactor actually runs.
        $this->cli->compact([], []);

        $this->assertNotEmpty(WP_CLI::$success_buf,
            'compact must emit ::success on the happy path');
        $this->assertStringContainsString('Pruned', WP_CLI::$success_buf[0]);
    }

    public function test_compact_command_warns_when_compactor_skips(): void {
        // Force the "last sync too recent" skip path.
        $defaults = MxChat_Plus_DuckDB_Options::defaults();
        update_option('mxchat_plus_duckdb_options', array_merge($defaults, [
            'enabled' => true, 'embedding_dim' => 3,
            'last_sync_at' => time() - 60, // < 1h ago
        ]));

        $this->cli->compact([], []);
        $this->assertNotEmpty(WP_CLI::$warning_buf,
            'a skipped run must emit ::warning, not ::success');
        $this->assertEmpty(WP_CLI::$success_buf);
    }

    // ─── reprocess (synchronous) ─────────────────────────────────────────

    public function test_reprocess_defaults_post_types_to_post_and_page(): void {
        update_option('mxchat_options', ['api_key' => 'sk-test', 'embedding_model' => 'text-embedding-3-small']);
        $captured_post_types = [];
        $GLOBALS['__test_wp_query_matcher'] = function (array $args) use (&$captured_post_types) {
            $captured_post_types = $args['post_type'];
            return ['posts' => [], 'found_posts' => 0];
        };

        $this->cli->reprocess([], []);
        $this->assertSame(['post', 'page'], $captured_post_types);
    }

    public function test_reprocess_honours_post_types_flag(): void {
        update_option('mxchat_options', ['api_key' => 'sk-test', 'embedding_model' => 'text-embedding-3-small']);
        $captured_post_types = [];
        $GLOBALS['__test_wp_query_matcher'] = function (array $args) use (&$captured_post_types) {
            $captured_post_types = $args['post_type'];
            return ['posts' => [], 'found_posts' => 0];
        };

        $this->cli->reprocess([], ['post-types' => 'product,download']);
        $this->assertSame(['product', 'download'], $captured_post_types);
    }

    // ─── Command registration ────────────────────────────────────────────

    public function test_command_is_registered_under_the_documented_namespace(): void {
        // The CLI file does `\WP_CLI::add_command('mxchat-plus duckdb', '…CLI')`.
        // Our WP_CLI shim records this in WP_CLI::$commands; assert it's
        // there so a typo in the registration is caught.
        //
        // The module segment matters: since the merge, one namespace hosts both
        // modules (`wp mxchat-plus duckdb …` / `wp mxchat-plus promptcache …`).
        // Without it, `stats` would be ambiguous — both modules define one.
        $this->assertArrayHasKey('mxchat-plus duckdb', WP_CLI::$commands);
        $this->assertSame('MxChat_Plus_DuckDB_CLI', WP_CLI::$commands['mxchat-plus duckdb']);
    }
}
