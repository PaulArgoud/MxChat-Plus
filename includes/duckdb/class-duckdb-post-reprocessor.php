<?php
/**
 * WordPress posts → DuckDB pipeline.
 *
 * Reprocesses published WP posts by routing each one through
 * MxChat_Utils::submit_content_to_db(), which re-runs MxChat's full chunking
 * + embedding pipeline. With the default-bot takeover (or Multi-Bot routing)
 * on, MxChat sees our proxy as its Pinecone backend, so the upsert lands in
 * our REST proxy → DuckDB without MxChat being aware of the swap.
 *
 * Each post is handled the way MxChat's own bulk import handles it
 * (MxChat_Knowledge_Manager, MxChat 3.2.25): the `mxchat_before_process_post`
 * filter, MxChat's own content assembler, the page title, and the role
 * restriction re-applied after storage. The assembler and the role helper
 * are private methods; MxChat offers no public API for either, so they are
 * reached through reflection, with a fallback when they are missing.
 *
 * Media library files (PDF, DOCX…) are not posts this pipeline can index:
 * MxChat keys them on the file URL and the text it extracts from the file.
 * They are re-indexed through MxChat → Knowledge → Import → Media.
 *
 * Two entry points:
 *   - reprocess_posts() — synchronous batched (used by the AJAX admin
 *     button and `wp mxchat-plus reprocess`).
 *   - reprocess_single_post() — one post at a time, suitable for
 *     Action Scheduler workers.
 *
 * **Cost**: each call to mxchat triggers an embedding API call (OpenAI /
 * Voyage / Gemini / Custom Provider), so the caller should warn about cost.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Post_Reprocessor {

    /** Host literal: the class holding MxChat's indexing helpers (MxChat 3.2.21+). */
    const HOST_KNOWLEDGE_MANAGER = 'MxChat_Knowledge_Manager';

    /** Host literal: private post-content assembler (MxChat 3.2.21+). */
    const HOST_ASSEMBLER = 'mxchat_prepare_post_content_for_indexing';

    /** Host literal: private tag / per-entry role re-application (MxChat 3.2.21+). */
    const HOST_ROLE_HELPER = 'apply_role_restriction_to_post';

    /** Host literal: install-level ACF → PDF option of MxChat's bulk import. */
    const HOST_ACF_PDF_OPTION = 'mxchat_acf_pdf_extraction';

    /**
     * Exception code of a post with nothing to index (missing, empty, no
     * permalink): counted as failed by the batch, reported to Action
     * Scheduler by the single-post path, but never recorded as the module's
     * last error.
     */
    const SKIPPED = 1001;

    /** @var bool|null memo: does submit_content_to_db() take the 7th $title argument (MxChat 3.2.23+)? */
    private static ?bool $submit_takes_title = null;

    /**
     * Reprocess one batch of posts. Returns a summary with the next offset
     * (null when finished), so the admin AJAX path can re-invoke until done
     * without blowing PHP max_execution_time on big catalogs.
     */
    public function reprocess_posts(array $post_types, int $batch_size, int $offset, string $bot_id = 'default'): array {
        if (!class_exists('MxChat_Utils')) {
            throw new RuntimeException(
                __('MxChat_Utils is not available — is mxchat-basic activated?', 'mxchat-plus')
            );
        }

        $api_key = self::embedding_api_key($bot_id);

        $store = new MxChat_Plus_DuckDB_Vector_Store();
        $store->ensure_schema();

        $query = new WP_Query([
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => $batch_size,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'no_found_rows'  => false,
        ]);

        $processed = 0;
        $failed = 0;

        foreach ($query->posts as $post_id) {
            try {
                self::reprocess_post((int) $post_id, $bot_id, $api_key);
                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                if ($e->getCode() !== self::SKIPPED) {
                    MxChat_Plus_DuckDB_Options::update(['last_error' => $e->getMessage()]);
                }
            }
        }

        $next_offset = ($offset + $batch_size) < (int) $query->found_posts ? ($offset + $batch_size) : null;

        if ($next_offset === null) {
            MxChat_Plus_DuckDB_Options::update([
                'last_sync_at'    => time(),
                'last_sync_count' => $processed + $offset,
            ]);
        }

        return [
            'processed'   => $processed,
            'failed'      => $failed,
            'total'       => (int) $query->found_posts,
            'done'        => $offset + count($query->posts),
            'next_offset' => $next_offset,
        ];
    }

    /**
     * Reprocess a single post — the unit of work consumed by Action Scheduler.
     * Throws on hard failures so Action Scheduler records the failure and
     * optionally retries.
     *
     * @throws RuntimeException
     */
    public function reprocess_single_post(int $post_id, string $bot_id = 'default'): void {
        if (!class_exists('MxChat_Utils')) {
            throw new RuntimeException(
                __('MxChat_Utils is not available — is mxchat-basic activated?', 'mxchat-plus')
            );
        }
        self::reprocess_post($post_id, $bot_id, self::embedding_api_key($bot_id));
    }

    /**
     * One post through MxChat's pipeline, as MxChat's bulk import does it.
     * Throws RuntimeException: with code SKIPPED for a post with nothing to
     * index, without it when MxChat refuses the post.
     */
    private static function reprocess_post(int $post_id, string $bot_id, string $api_key): void {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            throw new RuntimeException(sprintf('Post %d not found.', $post_id), self::SKIPPED);
        }

        /**
         * MxChat's own hook, applied by both of its indexing paths (bulk
         * import and auto-sync) with this signature (MxChat 3.2.25). Host
         * literal — not ours to rename.
         */
        $filtered = apply_filters('mxchat_before_process_post', $post, $bot_id);
        if ($filtered instanceof WP_Post) {
            $post = $filtered;
        }

        $content = self::build_post_content($post);
        if (trim($content) === '') {
            throw new RuntimeException(sprintf('Post %d has no content to ingest.', $post_id), self::SKIPPED);
        }
        $source_url = get_permalink($post_id);
        if (!$source_url) {
            throw new RuntimeException(sprintf('Post %d has no permalink.', $post_id), self::SKIPPED);
        }

        $vector_id = md5($source_url);
        $content_type = self::map_post_type_to_content_type($post->post_type);

        $args = [$content, $source_url, $api_key, $vector_id, $bot_id, $content_type];
        if (self::submit_takes_title()) {
            // Page title for the 3.2.23+ `title` column and Pinecone metadata.
            $args[] = (string) $post->post_title;
        }
        $result = MxChat_Utils::submit_content_to_db(...$args);
        if (is_wp_error($result)) {
            throw new RuntimeException('reprocess post ' . $post_id . ': ' . $result->get_error_message());
        }

        // A chunked re-store deletes the old chunks first, so the entry comes
        // back public; MxChat's bulk import re-applies the role right after
        // storing, and so do we.
        self::reapply_role_restriction($post_id, (string) $source_url);
    }

    /**
     * Build the ingestible content for a post.
     *
     * Preferred: MxChat's own assembler, called with the arguments of its
     * bulk import (raw fields, product tabs, the install's ACF → PDF
     * option). It adds what a plain render misses — short description,
     * WooCommerce price / SKU / categories / tags and Custom Product Tabs,
     * ACF and job fields, synced patterns and WoodMart HTML blocks resolved
     * by lookup — and its text is what MxChat's kb-reimport compares stored
     * entries against.
     *
     * Fallback (assembler missing or returning something unexpected): the
     * title, the excerpt and the content with shortcode brackets removed and
     * tags stripped — never through `the_content`, which would run every
     * block render callback, shortcode and third-party filter inside a batch
     * job.
     *
     * Either way the result passes through `mxchat_plus_duckdb_post_content`.
     */
    private static function build_post_content(WP_Post $post): string {
        $assembled = self::host_assembled_content($post);
        $out = $assembled ?? self::fallback_content($post);
        return (string) apply_filters('mxchat_plus_duckdb_post_content', $out, $post);
    }

    private static function host_assembled_content(WP_Post $post): ?string {
        $manager = self::knowledge_manager();
        if ($manager === null || !method_exists($manager, self::HOST_ASSEMBLER)) {
            return null;
        }
        try {
            $method = new ReflectionMethod($manager, self::HOST_ASSEMBLER);
            $prepared = $method->invoke($manager, (int) $post->ID, $post, [
                'read_display'         => false,
                'extract_acf_pdfs'     => get_option(self::HOST_ACF_PDF_OPTION, '0') === '1',
                'include_product_tabs' => true,
            ]);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($prepared) || !isset($prepared['content']) || !is_string($prepared['content'])) {
            return null;
        }
        return $prepared['content'];
    }

    private static function fallback_content(WP_Post $post): string {
        $decode = static fn(string $t): string => html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Same bracket-only strip as MxChat's strip_shortcode_tags_preserve_content():
        // [tag], [tag attr="v"], [/tag] go, the text between them stays.
        $strip = static function (string $t): string {
            $r = preg_replace('/\[\/?\w[\w-]*[^\]]*\]/', '', $t);
            return $r !== null ? $r : $t;
        };

        $title = trim($decode((string) $post->post_title));
        $out = $title !== '' ? $title . "\n\n" : '';

        $excerpt = trim(wp_strip_all_tags($strip((string) $post->post_excerpt)));
        if ($excerpt !== '') {
            $out .= 'Short Description: ' . $decode($excerpt) . "\n\n";
        }

        $out .= $decode(wp_strip_all_tags($strip((string) $post->post_content)));
        return trim($out);
    }

    /**
     * Re-apply the entry's role restriction (post tags mapped in MxChat's
     * Role Restrictions screen, plus the per-entry setting) through MxChat's
     * own helper. Without it, MxChat's private method gone, fire the action
     * MxChat itself listens on for the same job
     * (MxChat_Knowledge_Manager::apply_role_restriction_after_storage) —
     * weaker: it knows tag mappings to built-in roles only.
     */
    private static function reapply_role_restriction(int $post_id, string $source_url): void {
        $manager = self::knowledge_manager();
        if ($manager !== null && method_exists($manager, self::HOST_ROLE_HELPER)) {
            try {
                (new ReflectionMethod($manager, self::HOST_ROLE_HELPER))->invoke($manager, $post_id, $source_url);
                return;
            } catch (\Throwable $e) {
                // Fall through to the action.
            }
        }
        // Host literal: MxChat's own listener is registered on this action.
        do_action('mxchat_content_stored', $post_id, $source_url);
    }

    private static function knowledge_manager(): ?object {
        if (!is_callable([self::HOST_KNOWLEDGE_MANAGER, 'get_instance'])) {
            return null;
        }
        try {
            $manager = call_user_func([self::HOST_KNOWLEDGE_MANAGER, 'get_instance']);
        } catch (\Throwable $e) {
            return null;
        }
        return is_object($manager) ? $manager : null;
    }

    private static function submit_takes_title(): bool {
        if (self::$submit_takes_title === null) {
            try {
                self::$submit_takes_title = (new ReflectionMethod('MxChat_Utils', 'submit_content_to_db'))->getNumberOfParameters() >= 7;
            } catch (\Throwable $e) {
                self::$submit_takes_title = false;
            }
        }
        return self::$submit_takes_title;
    }

    /** Tests: forget the memoised submit_content_to_db() signature. */
    public static function reset_host_signature_cache(): void {
        self::$submit_takes_title = null;
    }

    private static function map_post_type_to_content_type(string $post_type): string {
        return match ($post_type) {
            'post' => 'post',
            'page' => 'page',
            'product' => 'product',
            default => sanitize_key($post_type) ?: 'content',
        };
    }

    /**
     * The options MxChat's embedding call will read for this bot — the same
     * choice MxChat_Utils::generate_embedding() makes (MxChat 3.2.25): the
     * site options for 'default' or without the Multi-Bot add-on, else the
     * bot's options from `mxchat_get_bot_options`, else the site options.
     *
     * @return array<string, mixed>
     */
    public static function embedding_options(string $bot_id): array {
        $site = get_option('mxchat_options', []);
        $site = is_array($site) ? $site : [];
        if ($bot_id === 'default' || !class_exists('MxChat_Multi_Bot_Manager')) {
            return $site;
        }
        $bot = apply_filters('mxchat_get_bot_options', [], $bot_id);
        return is_array($bot) && !empty($bot) ? $bot : $site;
    }

    /**
     * Decide, once and up front, whether MxChat can embed for this bot, and
     * with which key — through MxChat_Utils::embedding_preflight() (MxChat
     * 3.2.21+), the check every MxChat importer makes. A Custom Provider
     * needs no key: the preflight returns '' and generate_embedding() routes
     * to the custom endpoint first. Throws the preflight's own reason
     * (MxChat's translated text) when MxChat would refuse.
     *
     * Public so the async enqueue can refuse a whole batch at once instead
     * of failing every job.
     *
     * @throws RuntimeException
     */
    public static function embedding_api_key(string $bot_id): string {
        $options = self::embedding_options($bot_id);

        if (is_callable(['MxChat_Utils', 'embedding_preflight'])) {
            $preflight = MxChat_Utils::embedding_preflight($options);
            if (is_array($preflight) && array_key_exists('ok', $preflight)) {
                if (empty($preflight['ok'])) {
                    $reason = is_string($preflight['reason'] ?? null) && $preflight['reason'] !== ''
                        ? $preflight['reason']
                        : __('No embedding API key configured in MxChat.', 'mxchat-plus');
                    throw new RuntimeException($reason);
                }
                return (string) ($preflight['api_key'] ?? '');
            }
        }

        return self::fallback_embedding_api_key($options);
    }

    /**
     * For a host without embedding_preflight(): the same decision, Custom
     * Provider first (custom embeddings predate the helper).
     *
     * @param array<string, mixed> $options
     * @throws RuntimeException
     */
    private static function fallback_embedding_api_key(array $options): string {
        if (($options['custom_provider_for_embeddings'] ?? '') === 'on') {
            $base = isset($options['custom_provider_base_url']) ? trim((string) $options['custom_provider_base_url']) : '';
            if ($base === '') {
                throw new RuntimeException(__('The Custom Provider Base URL is not configured in MxChat.', 'mxchat-plus'));
            }
            return '';
        }

        $model = (string) ($options['embedding_model'] ?? 'text-embedding-ada-002');
        if (strpos($model, 'voyage') === 0) {
            $key = (string) ($options['voyage_api_key'] ?? '');
        } elseif (strpos($model, 'gemini-embedding') === 0) {
            $key = (string) ($options['gemini_api_key'] ?? '');
        } else {
            $key = (string) ($options['api_key'] ?? '');
        }
        if ($key === '') {
            throw new RuntimeException(__('No embedding API key configured in MxChat.', 'mxchat-plus'));
        }
        return $key;
    }
}
