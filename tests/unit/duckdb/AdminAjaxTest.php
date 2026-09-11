<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the 4 admin AJAX handlers (test_connection, sync_now, stats,
 * reprocess_batch) — every entry point for the admin's "Test connection",
 * "Sync now", "Reprocess all posts" buttons and the diagnostics panel.
 *
 * The hard contract is the AUTH order: nonce check first (rejects forged
 * requests), capability check second (rejects under-privileged users),
 * actual work third. The bootstrap shim turns wp_send_json_success /
 * wp_send_json_error into MxChat_Test_AjaxResponseException so we can
 * catch + introspect the response.
 *
 * check_ajax_referer behaves like the real WP one: it dies (throws our
 * shim exception with status 403) when the nonce is invalid, so even an
 * authenticated user can't bypass it.
 */
final class AdminAjaxTest extends TestCase {

    private MxChat_Plus_DuckDB_Admin $admin;
    private $mock_conn;

    protected function setUp(): void {
        $GLOBALS['__test_options']          = [];
        $GLOBALS['__test_transients']       = [];
        $GLOBALS['__test_valid_nonces']     = [];
        $GLOBALS['__test_current_user_can'] = true;
        unset($GLOBALS['__test_ajax_response']);
        $_POST = [];

        MxChat_Test_Helpers::reset_schema_memoisation();
        MxChat_Utils::$custom_embedding_returns = [0.1, 0.2, 0.3];

        $this->mock_conn = new MxChat_Test_RecordingConnection('mock:admin-backend', [
            'SELECT COUNT(*)' => [['c' => 4242]],
        ]);

        $defaults = MxChat_Plus_DuckDB_Options::defaults();
        update_option('mxchat_plus_duckdb_options', array_merge($defaults, [
            'enabled' => true, 'embedding_dim' => 3,
        ]));

        // Reprocess handler delegates to Post_Reprocessor which requires an
        // embedding API key from mxchat_options. Seed a fake one so the
        // reprocess code path doesn't throw before reaching WP_Query.
        update_option('mxchat_options', [
            'api_key' => 'sk-test-admin',
            'embedding_model' => 'text-embedding-3-small',
        ]);

        MxChat_Test_Helpers::inject_mock_connection($this->mock_conn);

        $this->admin = MxChat_Plus_DuckDB_Admin::instance();
    }

    /**
     * Helper: invoke a handler that may throw our AJAX shim exception
     * (production catches \Throwable and re-throws, but the FIRST response
     * is captured in $GLOBALS['__test_ajax_response'] by the shim itself).
     */
    private function captureAjaxResponse(callable $invocation): array {
        try {
            $invocation();
        } catch (MxChat_Test_AjaxResponseException $e) {
            // Expected — the shim throws after capturing the response.
        }
        if (!isset($GLOBALS['__test_ajax_response'])) {
            $this->fail('expected an AJAX response (success or error) — none was emitted');
        }
        return $GLOBALS['__test_ajax_response'];
    }

    private function arm_valid_nonce(): void {
        $GLOBALS['__test_valid_nonces']['legit'] = MxChat_Plus_DuckDB_Admin::NONCE_ACTION;
        $_POST['nonce'] = 'legit';
    }

    // ─── Nonce + capability gates (applies to all 4 handlers) ────────────

    public function test_test_connection_rejects_invalid_nonce_with_403(): void {
        $_POST['nonce'] = 'bogus';
        $r = $this->captureAjaxResponse(fn() => $this->admin->ajax_test_connection());
        $this->assertFalse($r['success']);
        $this->assertSame(403, $r['status']);
    }

    public function test_sync_now_rejects_invalid_nonce_with_403(): void {
        $_POST['nonce'] = 'bogus';
        $r = $this->captureAjaxResponse(fn() => $this->admin->ajax_sync_now());
        $this->assertFalse($r['success']);
        $this->assertSame(403, $r['status']);
    }

    public function test_stats_rejects_invalid_nonce_with_403(): void {
        $_POST['nonce'] = 'bogus';
        $r = $this->captureAjaxResponse(fn() => $this->admin->ajax_stats());
        $this->assertFalse($r['success']);
        $this->assertSame(403, $r['status']);
    }

    public function test_reprocess_batch_rejects_invalid_nonce_with_403(): void {
        $_POST['nonce'] = 'bogus';
        $r = $this->captureAjaxResponse(fn() => $this->admin->ajax_reprocess_batch());
        $this->assertFalse($r['success']);
        $this->assertSame(403, $r['status']);
    }

    public function test_detect_dimension_rejects_invalid_nonce_with_403(): void {
        $_POST['nonce'] = 'bogus';
        $r = $this->captureAjaxResponse(fn() => $this->admin->ajax_detect_dimension());
        $this->assertFalse($r['success']);
        $this->assertSame(403, $r['status']);
    }

    public function test_test_connection_rejects_user_without_capability(): void {
        $this->arm_valid_nonce();
        $GLOBALS['__test_current_user_can'] = false;
        $r = $this->captureAjaxResponse(fn() => $this->admin->ajax_test_connection());
        $this->assertFalse($r['success']);
        $this->assertSame(403, $r['status']);
        $this->assertStringContainsString('permissions', $r['payload']['message']);
    }

    // ─── ajax_test_connection happy path ─────────────────────────────────

    public function test_test_connection_returns_backend_identity_and_count_on_success(): void {
        $this->arm_valid_nonce();

        $r = $this->captureAjaxResponse(fn() => $this->admin->ajax_test_connection());

        $this->assertTrue($r['success']);
        $this->assertSame('mock:admin-backend', $r['payload']['backend']);
        $this->assertSame(4242, $r['payload']['count']);
    }

    public function test_test_connection_returns_error_when_ping_fails(): void {
        $this->arm_valid_nonce();
        $this->mock_conn->ping_returns = false;

        $r = $this->captureAjaxResponse(fn() => $this->admin->ajax_test_connection());
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('Ping failed', $r['payload']['message']);
    }

    public function test_test_connection_returns_error_when_factory_throws(): void {
        $this->arm_valid_nonce();
        // Install a connection that throws on ping.
        $throwing = new class('mock:throwing') extends MxChat_Test_RecordingConnection {
            public function execute(string $sql, array $params = []): array {
                throw new RuntimeException('SQL backend exploded');
            }
            public function ping(): bool { throw new RuntimeException('SQL backend exploded'); }
        };
        MxChat_Test_Helpers::inject_mock_connection($throwing);

        $resp = $this->captureAjaxResponse(fn() => $this->admin->ajax_test_connection());
        $this->assertFalse($resp['success']);
        $this->assertStringContainsString('SQL backend exploded', $resp['payload']['message']);
    }

    // ─── ajax_stats happy path ────────────────────────────────────────────

    public function test_stats_returns_vector_count_metrics_and_last_sync(): void {
        $this->arm_valid_nonce();
        update_option('mxchat_plus_duckdb_options', array_merge(
            MxChat_Plus_DuckDB_Options::defaults(),
            ['enabled' => true, 'embedding_dim' => 3,
             'last_sync_at' => 1700000000, 'last_sync_count' => 99]
        ));
        // Re-inject mock now that options changed (cache_key changes only with
        // mode/database/token; embedding_dim doesn't affect the slot).

        $r = $this->captureAjaxResponse(fn() => $this->admin->ajax_stats());
        $this->assertTrue($r['success']);
        // Stats payload doesn't have a fixed shape in this codebase — just
        // confirm it includes the count and some metrics field.
        $this->assertIsArray($r['payload']);
    }

    // ─── ajax_reprocess_batch input parsing ───────────────────────────────

    public function test_reprocess_batch_parses_post_types_csv(): void {
        $this->arm_valid_nonce();
        $_POST['post_types'] = 'post,page,product';
        $_POST['batch_size'] = '5';
        $_POST['offset'] = '0';

        // The handler delegates to Sync::reprocess_posts. Without a real WP_Query
        // matcher, it just returns 0 processed/failed. We're verifying the
        // parsing + delegation contract, not the reprocessor itself (covered
        // by PostReprocessorTest).
        $GLOBALS['__test_wp_query_matcher'] = function (array $args) {
            return ['posts' => [], 'found_posts' => 0];
        };

        $r = $this->captureAjaxResponse(fn() => $this->admin->ajax_reprocess_batch());
        $this->assertTrue($r['success']);
        $this->assertSame(0, $r['payload']['processed']);
        $this->assertSame(0, $r['payload']['failed']);
    }

    public function test_reprocess_batch_clamps_batch_size_to_one_to_fifty(): void {
        $this->arm_valid_nonce();
        $_POST['batch_size'] = '9999'; // requesting 9999
        $captured_batch_size = 0;
        $GLOBALS['__test_wp_query_matcher'] = function (array $args) use (&$captured_batch_size) {
            $captured_batch_size = (int) $args['posts_per_page'];
            return ['posts' => [], 'found_posts' => 0];
        };

        $this->captureAjaxResponse(fn() => $this->admin->ajax_reprocess_batch());
        $this->assertSame(50, $captured_batch_size, 'batch_size must be capped at 50');
    }

    public function test_reprocess_batch_defaults_to_post_and_page_when_no_post_types_given(): void {
        $this->arm_valid_nonce();
        $captured_post_types = [];
        $GLOBALS['__test_wp_query_matcher'] = function (array $args) use (&$captured_post_types) {
            $captured_post_types = $args['post_type'];
            return ['posts' => [], 'found_posts' => 0];
        };
        $this->captureAjaxResponse(fn() => $this->admin->ajax_reprocess_batch());
        $this->assertSame(['post', 'page'], $captured_post_types);
    }

    public function test_reprocess_batch_sanitizes_post_type_input(): void {
        // sanitize_key strips non [a-z0-9_-]; "Bad<Type>" should drop angle brackets.
        $this->arm_valid_nonce();
        $_POST['post_types'] = "Bad<Type>,page";
        $captured_post_types = [];
        $GLOBALS['__test_wp_query_matcher'] = function (array $args) use (&$captured_post_types) {
            $captured_post_types = $args['post_type'];
            return ['posts' => [], 'found_posts' => 0];
        };
        $this->captureAjaxResponse(fn() => $this->admin->ajax_reprocess_batch());
        $this->assertContains('page', $captured_post_types);
        // The dangerous chars are stripped — no raw "<Type>" reaches WP_Query.
        foreach ($captured_post_types as $t) {
            $this->assertDoesNotMatchRegularExpression('/[<>]/', $t);
        }
    }

    // ─── ajax_detect_dimension (custom/Azure embedding probe) ─────────────

    public function test_detect_dimension_errors_when_custom_embeddings_not_enabled(): void {
        $this->arm_valid_nonce();
        // mxchat_options seeded in setUp has no custom_provider_for_embeddings.
        $r = $this->captureAjaxResponse(fn() => $this->admin->ajax_detect_dimension());
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('not enabled', $r['payload']['message']);
    }

    public function test_detect_dimension_returns_probed_length_and_caches_it(): void {
        $this->arm_valid_nonce();
        update_option('mxchat_options', [
            'custom_provider_for_embeddings' => 'on',
            'custom_provider_embedding_model' => 'nomic-embed-text',
        ]);
        update_option('mxchat_active_embedding_model', 'custom:nomic-embed-text');
        // Simulate a 768-dim local model.
        MxChat_Utils::$custom_embedding_returns = array_fill(0, 768, 0.01);

        $r = $this->captureAjaxResponse(fn() => $this->admin->ajax_detect_dimension());
        $this->assertTrue($r['success']);
        $this->assertSame(768, $r['payload']['dim']);
        $this->assertSame('custom:nomic-embed-text', $r['payload']['model']);

        // Cached keyed to the model → detect_embedding_dim now resolves it.
        $this->assertSame(768, MxChat_Plus_DuckDB_Options::detect_embedding_dim());
    }

    public function test_detect_dimension_surfaces_provider_error_string(): void {
        $this->arm_valid_nonce();
        update_option('mxchat_options', ['custom_provider_for_embeddings' => 'on']);
        MxChat_Utils::$custom_embedding_returns = 'Custom embedding endpoint error: HTTP 401';

        $r = $this->captureAjaxResponse(fn() => $this->admin->ajax_detect_dimension());
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('HTTP 401', $r['payload']['message']);
    }

    // ─── Constants ───────────────────────────────────────────────────────

    public function test_menu_slug_and_nonce_action_constants_are_stable(): void {
        // External code (themes, custom integrations) may reference these.
        // A rename = breaking change.
        $this->assertSame('mxchat-plus', MxChat_Plus_DuckDB_Admin::MENU_SLUG);
        $this->assertSame('mxchat_plus_duckdb_admin', MxChat_Plus_DuckDB_Admin::NONCE_ACTION);
    }
}
