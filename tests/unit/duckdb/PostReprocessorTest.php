<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the WP posts → DuckDB ingestion path. This is what the admin
 * "Reprocess all posts" button + the `wp mxchat-plus reprocess` CLI
 * + the Action Scheduler worker all delegate to.
 *
 * The routine routes through MxChat_Utils::submit_content_to_db(), which
 * runs the upstream chunking + embedding pipeline. Tests verify the
 * routing contract (what content gets sent, with which metadata) and
 * the error / fallback paths.
 *
 * MxChat_Utils is shimmed in bootstrap.php: every call is recorded into
 * MxChat_Utils::$submit_calls and the return value is controlled via
 * MxChat_Utils::$submit_returns.
 */
final class PostReprocessorTest extends TestCase {

    private MxChat_Plus_DuckDB_Post_Reprocessor $reprocessor;

    protected function setUp(): void {
        $GLOBALS['__test_options']    = [];
        $GLOBALS['__test_transients'] = [];
        $GLOBALS['__test_posts']      = [];
        $GLOBALS['__test_permalinks'] = [];
        $GLOBALS['__test_wp_query_matcher'] = null;
        MxChat_Utils::$submit_calls = [];
        MxChat_Utils::$submit_returns = true;
        $GLOBALS['__test_filter_overrides'] = [];
        $GLOBALS['__test_actions'] = [];
        MxChat_Knowledge_Manager::reset();
        MxChat_Plus_DuckDB_Post_Reprocessor::reset_host_signature_cache();

        // mxchat_options is read by resolve_embedding_api_key() to pick the
        // right provider's API key. Default to OpenAI for these tests.
        update_option('mxchat_options', [
            'api_key' => 'sk-test-key',
            'embedding_model' => 'text-embedding-3-small',
        ]);

        // Pre-populate the Vector_Store schema memoisation + Connection_Factory
        // cache so `new MxChat_Plus_DuckDB_Vector_Store()` inside reprocess_posts()
        // doesn't try to instantiate a real backend.
        MxChat_Test_Helpers::reset_schema_memoisation();
        $defaults = MxChat_Plus_DuckDB_Options::defaults();
        update_option('mxchat_plus_duckdb_options', array_merge($defaults, ['embedding_dim' => 3]));
        MxChat_Test_Helpers::inject_mock_connection(new MxChat_Test_RecordingConnection('mock:reprocessor'));

        $this->reprocessor = new MxChat_Plus_DuckDB_Post_Reprocessor();
    }

    private function seed_post(int $id, string $title, string $content, string $type = 'post', ?string $permalink = null): void {
        $GLOBALS['__test_posts'][$id] = new WP_Post([
            'ID' => $id, 'post_title' => $title, 'post_content' => $content,
            'post_type' => $type, 'post_status' => 'publish',
        ]);
        $GLOBALS['__test_permalinks'][$id] = $permalink ?? "https://example.com/?p=$id";
    }

    // ─── Guard rails ──────────────────────────────────────────────────────

    public function test_reprocess_throws_when_mxchat_utils_missing(): void {
        // Simulating "MxChat_Utils not loaded" requires un-declaring the
        // class, which PHP doesn't allow. The branch exists for the case
        // where mxchat-basic isn't activated; documented here as covered
        // by the runtime check in the production code itself.
        $this->markTestSkipped(
            'Cannot un-declare MxChat_Utils class in PHP; the !class_exists("MxChat_Utils") branch is exercised in production when mxchat-basic is deactivated.'
        );
    }

    protected function tearDown(): void {
        $GLOBALS['__test_filter_overrides'] = [];
        MxChat_Knowledge_Manager::reset();
    }

    public function test_reprocess_throws_when_no_embedding_api_key_configured(): void {
        update_option('mxchat_options', ['embedding_model' => 'text-embedding-3-small']);

        // The refusal is MxChat's own: MxChat_Utils::embedding_preflight().
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/API key is not configured/i');
        $this->reprocessor->reprocess_posts(['post'], 10, 0, 'default');
    }

    public function test_reprocess_single_post_throws_when_no_api_key(): void {
        update_option('mxchat_options', []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/API key is not configured/i');
        $this->reprocessor->reprocess_single_post(42, 'default');
    }

    // ─── Embedding preflight (MxChat_Utils::embedding_preflight) ──────────

    public function test_keyless_custom_provider_reprocesses_with_an_empty_key(): void {
        // A site embedding through Ollama / LM Studio has no OpenAI key; MxChat
        // routes generate_embedding() to the custom endpoint and ignores the key.
        update_option('mxchat_options', [
            'custom_provider_for_embeddings' => 'on',
            'custom_provider_base_url'       => 'http://localhost:11434/v1',
            'embedding_model'                => 'voyage-3-large', // stale dropdown value, no Voyage key
        ]);
        $this->seed_post(1, 'Title', 'Body');
        $GLOBALS['__test_wp_query_matcher'] = fn() => ['posts' => [1], 'found_posts' => 1];

        $r = $this->reprocessor->reprocess_posts(['post'], 10, 0, 'default');

        $this->assertSame(1, $r['processed']);
        $this->assertSame('', MxChat_Utils::$submit_calls[0]['api_key']);
    }

    public function test_custom_provider_without_base_url_is_refused_up_front(): void {
        update_option('mxchat_options', ['custom_provider_for_embeddings' => 'on', 'api_key' => 'sk-present']);
        $this->seed_post(1, 'Title', 'Body');
        $GLOBALS['__test_wp_query_matcher'] = fn() => ['posts' => [1], 'found_posts' => 1];

        try {
            $this->reprocessor->reprocess_posts(['post'], 10, 0, 'default');
            $this->fail('expected the preflight to refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Base URL', $e->getMessage());
        }
        $this->assertSame([], MxChat_Utils::$submit_calls, 'refused once, before any post');
    }

    public function test_fallback_for_hosts_without_preflight_is_custom_aware(): void {
        $fallback = new ReflectionMethod(MxChat_Plus_DuckDB_Post_Reprocessor::class, 'fallback_embedding_api_key');

        $this->assertSame('', $fallback->invoke(null, ['custom_provider_for_embeddings' => 'on', 'custom_provider_base_url' => 'http://x/v1']));
        $this->assertSame('vk', $fallback->invoke(null, ['embedding_model' => 'voyage-3-large', 'voyage_api_key' => 'vk']));
        $this->assertSame('sk', $fallback->invoke(null, ['api_key' => 'sk']));

        foreach ([['custom_provider_for_embeddings' => 'on'], ['embedding_model' => 'gemini-embedding-001']] as $opts) {
            try {
                $fallback->invoke(null, $opts);
                $this->fail('expected a refusal');
            } catch (RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_default_bot_reads_the_site_options_not_the_bot_filter(): void {
        // MxChat_Utils::generate_embedding() reads get_option('mxchat_options')
        // for 'default'; the preflight must be fed the same options.
        $GLOBALS['__test_filter_overrides']['mxchat_get_bot_options'] = ['embedding_model' => 'gemini-embedding-001'];
        $this->assertSame('sk-test-key', MxChat_Plus_DuckDB_Post_Reprocessor::embedding_api_key('default'));
    }

    // ─── Content assembly (MxChat's own assembler, else a safe fallback) ──

    public function test_hosts_assembler_builds_the_content_with_its_bulk_arguments(): void {
        MxChat_Knowledge_Manager::$assembled = ['content' => "Mug\n\nPrice: 12 EUR\nSKU: M-1", 'acf_fields_found' => 0, 'pdf_extracted_count' => 0];
        update_option('mxchat_acf_pdf_extraction', '1');
        $this->seed_post(3, 'Mug', 'raw body', 'product');
        $GLOBALS['__test_wp_query_matcher'] = fn() => ['posts' => [3], 'found_posts' => 1];

        $this->reprocessor->reprocess_posts(['product'], 10, 0, 'default');

        $this->assertSame("Mug\n\nPrice: 12 EUR\nSKU: M-1", MxChat_Utils::$submit_calls[0]['content']);
        $call = MxChat_Knowledge_Manager::$assembler_calls[0];
        $this->assertSame(3, $call['post_id']);
        $this->assertSame(['read_display' => false, 'extract_acf_pdfs' => true, 'include_product_tabs' => true], $call['args']);
    }

    public function test_fallback_builder_never_renders_the_content(): void {
        // Assembler returns a non-array (shim default) → our own builder.
        $GLOBALS['__test_filter_overrides']['the_content'] = 'RENDERED BY THE THEME';
        $GLOBALS['__test_posts'][8] = new WP_Post([
            'ID' => 8, 'post_title' => 'Caf&eacute; guide', 'post_type' => 'page', 'post_status' => 'publish',
            'post_content' => '<p>Open [highlight color="red"]daily[/highlight] from 8.</p>',
            'post_excerpt' => 'Short [b]summary[/b]',
        ]);
        $GLOBALS['__test_permalinks'][8] = 'https://example.com/cafe/';

        $this->reprocessor->reprocess_single_post(8, 'default');

        $content = MxChat_Utils::$submit_calls[0]['content'];
        $this->assertStringNotContainsString('RENDERED', $content, 'the_content would run every shortcode and theme filter');
        $this->assertStringStartsWith("Café guide\n\n", $content);
        $this->assertStringContainsString('Short Description: Short summary', $content);
        $this->assertStringContainsString('Open daily from 8.', $content, 'shortcode brackets go, their text stays');
    }

    public function test_post_content_filter_still_has_the_last_word(): void {
        MxChat_Knowledge_Manager::$assembled = ['content' => 'host text'];
        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_post_content'] = 'integrator text';
        $this->seed_post(4, 't', 'b');
        $this->reprocessor->reprocess_single_post(4, 'default');
        $this->assertSame('integrator text', MxChat_Utils::$submit_calls[0]['content']);
    }

    public function test_hosts_before_process_post_filter_is_applied(): void {
        $this->seed_post(5, 'Original', 'Body');
        $GLOBALS['__test_filter_overrides']['mxchat_before_process_post'] = new WP_Post([
            'ID' => 5, 'post_title' => 'Rewritten by an add-on', 'post_content' => 'Body', 'post_type' => 'post',
        ]);
        $this->reprocessor->reprocess_single_post(5, 'default');
        $this->assertStringStartsWith('Rewritten by an add-on', MxChat_Utils::$submit_calls[0]['content']);
    }

    // ─── Title and role restriction, as MxChat's bulk import ──────────────

    public function test_title_is_passed_to_hosts_that_take_it(): void {
        $this->seed_post(6, 'Pricing', 'Body');
        $this->reprocessor->reprocess_single_post(6, 'default');
        $this->assertSame('Pricing', MxChat_Utils::$submit_calls[0]['title']);
    }

    public function test_role_restriction_is_reapplied_after_storage(): void {
        $this->seed_post(7, 'Members', 'Body', 'post', 'https://example.com/members/');
        $this->reprocessor->reprocess_single_post(7, 'default');

        $this->assertSame([['post_id' => 7, 'source_url' => 'https://example.com/members/']], MxChat_Knowledge_Manager::$role_calls);
        $this->assertSame([], $GLOBALS['__test_actions'], 'the weaker action fallback is not needed');
    }

    public function test_role_is_not_reapplied_when_storage_failed(): void {
        $this->seed_post(7, 'Members', 'Body');
        MxChat_Utils::$submit_returns = new WP_Error('e', 'quota');
        try {
            $this->reprocessor->reprocess_single_post(7, 'default');
        } catch (RuntimeException $e) {
            // expected
        }
        $this->assertSame([], MxChat_Knowledge_Manager::$role_calls);
    }

    public function test_skipped_posts_do_not_overwrite_last_error(): void {
        update_option('mxchat_plus_duckdb_options', array_merge(MxChat_Plus_DuckDB_Options::defaults(), ['embedding_dim' => 3, 'last_error' => 'previous']));
        $GLOBALS['__test_wp_query_matcher'] = fn() => ['posts' => [404], 'found_posts' => 1];
        $r = $this->reprocessor->reprocess_posts(['post'], 10, 0, 'default');
        $this->assertSame(1, $r['failed']);
        $this->assertSame('previous', get_option('mxchat_plus_duckdb_options')['last_error']);
    }

    // ─── reprocess_posts (batched) ───────────────────────────────────────

    public function test_reprocess_posts_calls_submit_for_each_published_post(): void {
        $this->seed_post(1, 'Hello', 'World');
        $this->seed_post(2, 'Foo', 'Bar');
        $this->seed_post(3, 'Baz', 'Qux');

        $GLOBALS['__test_wp_query_matcher'] = function () {
            return ['posts' => [1, 2, 3], 'found_posts' => 3];
        };

        $r = $this->reprocessor->reprocess_posts(['post'], 10, 0, 'default');

        $this->assertSame(3, $r['processed']);
        $this->assertSame(0, $r['failed']);
        $this->assertSame(3, $r['total']);
        $this->assertNull($r['next_offset'], 'all done → next_offset must be null');
        $this->assertCount(3, MxChat_Utils::$submit_calls);
    }

    public function test_reprocess_posts_sends_title_plus_content_to_submit(): void {
        $this->seed_post(7, 'Quick brown fox', 'Lorem ipsum dolor sit amet.');

        $GLOBALS['__test_wp_query_matcher'] = function () {
            return ['posts' => [7], 'found_posts' => 1];
        };

        $this->reprocessor->reprocess_posts(['post'], 10, 0, 'default');

        $this->assertCount(1, MxChat_Utils::$submit_calls);
        $call = MxChat_Utils::$submit_calls[0];

        // Production format (fallback builder — the shim's assembler returns
        // nothing): title + "\n\n" + post_content with tags stripped. Title
        // first, then content.
        $this->assertStringStartsWith('Quick brown fox', $call['content']);
        $this->assertStringContainsString('Lorem ipsum', $call['content']);
    }

    public function test_reprocess_posts_passes_the_resolved_api_key(): void {
        $this->seed_post(1, 't', 'b');
        $GLOBALS['__test_wp_query_matcher'] = function () {
            return ['posts' => [1], 'found_posts' => 1];
        };

        $this->reprocessor->reprocess_posts(['post'], 10, 0, 'default');

        $this->assertSame('sk-test-key', MxChat_Utils::$submit_calls[0]['api_key']);
    }

    public function test_reprocess_posts_uses_voyage_key_for_voyage_models(): void {
        update_option('mxchat_options', [
            'voyage_api_key' => 'voyage-key-xyz',
            'embedding_model' => 'voyage-3-large',
        ]);
        $this->seed_post(1, 't', 'b');
        $GLOBALS['__test_wp_query_matcher'] = function () {
            return ['posts' => [1], 'found_posts' => 1];
        };

        $this->reprocessor->reprocess_posts(['post'], 10, 0, 'default');
        $this->assertSame('voyage-key-xyz', MxChat_Utils::$submit_calls[0]['api_key']);
    }

    public function test_reprocess_posts_uses_gemini_key_for_gemini_models(): void {
        update_option('mxchat_options', [
            'gemini_api_key' => 'gemini-key-abc',
            'embedding_model' => 'gemini-embedding-001',
        ]);
        $this->seed_post(1, 't', 'b');
        $GLOBALS['__test_wp_query_matcher'] = function () {
            return ['posts' => [1], 'found_posts' => 1];
        };

        $this->reprocessor->reprocess_posts(['post'], 10, 0, 'default');
        $this->assertSame('gemini-key-abc', MxChat_Utils::$submit_calls[0]['api_key']);
    }

    public function test_reprocess_posts_uses_url_md5_as_vector_id(): void {
        // The vector_id convention matches Mysql_Sync::vector_id_for_row's
        // url-based branch — md5(source_url). This alignment is what makes
        // repeat-reprocess idempotent (INSERT OR REPLACE on the same id).
        $this->seed_post(1, 't', 'b', 'post', 'https://example.com/stable/');

        $GLOBALS['__test_wp_query_matcher'] = function () {
            return ['posts' => [1], 'found_posts' => 1];
        };

        $this->reprocessor->reprocess_posts(['post'], 10, 0, 'default');
        $this->assertSame(md5('https://example.com/stable/'), MxChat_Utils::$submit_calls[0]['vector_id']);
    }

    public function test_reprocess_posts_maps_post_type_to_content_type(): void {
        $this->seed_post(1, 't', 'b', 'page');
        $this->seed_post(2, 't', 'b', 'product');
        $this->seed_post(3, 't', 'b', 'custom_cpt');

        $GLOBALS['__test_wp_query_matcher'] = function () {
            return ['posts' => [1, 2, 3], 'found_posts' => 3];
        };

        $this->reprocessor->reprocess_posts(['page', 'product', 'custom_cpt'], 10, 0, 'default');

        $types = array_column(MxChat_Utils::$submit_calls, 'content_type');
        $this->assertContains('page', $types);
        $this->assertContains('product', $types);
        $this->assertContains('custom_cpt', $types, 'unknown post_type falls back to its sanitized name');
    }

    // ─── Failure paths ────────────────────────────────────────────────────

    public function test_reprocess_posts_increments_failed_when_submit_returns_wp_error(): void {
        $this->seed_post(1, 't', 'b');
        MxChat_Utils::$submit_returns = new WP_Error('e', 'embedding API 429');

        $GLOBALS['__test_wp_query_matcher'] = function () {
            return ['posts' => [1], 'found_posts' => 1];
        };

        $r = $this->reprocessor->reprocess_posts(['post'], 10, 0, 'default');
        $this->assertSame(0, $r['processed']);
        $this->assertSame(1, $r['failed']);

        $opts = get_option('mxchat_plus_duckdb_options');
        $this->assertStringContainsString('embedding API 429', $opts['last_error'] ?? '');
    }

    public function test_reprocess_posts_skips_posts_with_no_permalink(): void {
        $this->seed_post(1, 't', 'b');
        unset($GLOBALS['__test_permalinks'][1]); // permalink lookup returns false

        $GLOBALS['__test_wp_query_matcher'] = function () {
            return ['posts' => [1], 'found_posts' => 1];
        };

        $r = $this->reprocessor->reprocess_posts(['post'], 10, 0, 'default');
        $this->assertSame(0, $r['processed']);
        $this->assertSame(1, $r['failed']);
        $this->assertEmpty(MxChat_Utils::$submit_calls, 'no permalink → never reach submit');
    }

    public function test_reprocess_posts_skips_posts_with_empty_content_and_no_title(): void {
        $this->seed_post(1, '', '   ');
        $GLOBALS['__test_wp_query_matcher'] = function () {
            return ['posts' => [1], 'found_posts' => 1];
        };

        $r = $this->reprocessor->reprocess_posts(['post'], 10, 0, 'default');
        $this->assertSame(1, $r['failed']);
        $this->assertEmpty(MxChat_Utils::$submit_calls);
    }

    // ─── Single-post (Action Scheduler worker entry point) ───────────────

    public function test_reprocess_single_post_throws_on_missing_post(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');
        $this->reprocessor->reprocess_single_post(9999, 'default');
    }

    public function test_reprocess_single_post_throws_on_empty_content(): void {
        $this->seed_post(5, '', '');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no content/i');
        $this->reprocessor->reprocess_single_post(5, 'default');
    }

    public function test_reprocess_single_post_succeeds_on_happy_path(): void {
        $this->seed_post(11, 'Title', 'Body');
        $this->reprocessor->reprocess_single_post(11, 'default');
        $this->assertCount(1, MxChat_Utils::$submit_calls);
        $this->assertSame('default', MxChat_Utils::$submit_calls[0]['bot_id']);
    }

    // ─── Pagination ──────────────────────────────────────────────────────

    public function test_reprocess_posts_returns_next_offset_when_more_remain(): void {
        $this->seed_post(1, 't', 'b'); $this->seed_post(2, 't', 'b');
        $GLOBALS['__test_wp_query_matcher'] = function () {
            return ['posts' => [1, 2], 'found_posts' => 100]; // 2 returned out of 100
        };

        $r = $this->reprocessor->reprocess_posts(['post'], 2, 0, 'default');
        $this->assertSame(2, $r['next_offset'], 'offset advances by batch_size when more remain');
        $this->assertSame(100, $r['total']);
    }
}
