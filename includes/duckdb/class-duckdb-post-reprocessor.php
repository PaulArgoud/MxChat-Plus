<?php
/**
 * WordPress posts → DuckDB pipeline.
 *
 * Reprocesses published WP posts by routing each one through
 * MxChat_Utils::submit_content_to_db(), which re-runs MxChat's full chunking
 * + embedding pipeline. The plugin advertises DuckDB as the Pinecone backend
 * via mxchat_get_bot_pinecone_config, so the upsert lands in our REST proxy
 * → DuckDB without MxChat being aware of the swap.
 *
 * Two entry points:
 *   - reprocess_posts() — synchronous batched (used by the AJAX admin
 *     button and `wp mxchat-plus reprocess`).
 *   - reprocess_single_post() — one post at a time, suitable for
 *     Action Scheduler workers.
 *
 * **Cost**: each call to mxchat triggers an embedding API call (OpenAI /
 * Voyage / Gemini), so the caller should warn about cost.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Post_Reprocessor {

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

        $api_key = self::resolve_embedding_api_key($bot_id);
        if (empty($api_key)) {
            throw new RuntimeException(
                __('No embedding API key configured in MxChat.', 'mxchat-plus')
            );
        }

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
            $post = get_post($post_id);
            if (!$post) { $failed++; continue; }

            $content = self::build_post_content($post);
            if (trim((string) $content) === '') { $failed++; continue; }

            $source_url = get_permalink($post_id);
            if (!$source_url) { $failed++; continue; }

            $vector_id = md5($source_url);
            $content_type = self::map_post_type_to_content_type($post->post_type);

            $result = MxChat_Utils::submit_content_to_db(
                $content, $source_url, $api_key, $vector_id, $bot_id, $content_type
            );

            if (is_wp_error($result)) {
                $failed++;
                MxChat_Plus_DuckDB_Options::update(['last_error' => 'reprocess post ' . $post_id . ': ' . $result->get_error_message()]);
            } else {
                $processed++;
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
        $api_key = self::resolve_embedding_api_key($bot_id);
        if (empty($api_key)) {
            throw new RuntimeException(
                __('No embedding API key configured in MxChat.', 'mxchat-plus')
            );
        }

        $post = get_post($post_id);
        if (!$post) {
            throw new RuntimeException(sprintf('Post %d not found.', $post_id));
        }
        $content = self::build_post_content($post);
        if (trim((string) $content) === '') {
            throw new RuntimeException(sprintf('Post %d has no content to ingest.', $post_id));
        }
        $source_url = get_permalink($post_id);
        if (!$source_url) {
            throw new RuntimeException(sprintf('Post %d has no permalink.', $post_id));
        }

        $vector_id = md5($source_url);
        $content_type = self::map_post_type_to_content_type($post->post_type);

        $result = MxChat_Utils::submit_content_to_db(
            $content, $source_url, $api_key, $vector_id, $bot_id, $content_type
        );
        if (is_wp_error($result)) {
            throw new RuntimeException('reprocess post ' . $post_id . ': ' . $result->get_error_message());
        }
    }

    /**
     * Build the ingestible content for a post. Mirrors mxchat's own ingestion:
     * title + post_content (filtered by `the_content`). Extend via the
     * `mxchat_plus_duckdb_post_content` filter to add meta / ACF / etc.
     */
    private static function build_post_content(WP_Post $post): string {
        $title = trim((string) $post->post_title);
        $content = (string) $post->post_content;
        if (function_exists('apply_filters')) {
            $content = apply_filters('the_content', $content);
        }
        $content = wp_strip_all_tags($content, true);

        $out = $title !== '' ? ($title . "\n\n" . $content) : $content;
        return (string) apply_filters('mxchat_plus_duckdb_post_content', $out, $post);
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
     * Resolve the API key for whichever embedding provider mxchat has active
     * (OpenAI / Voyage / Gemini).
     */
    private static function resolve_embedding_api_key(string $bot_id): string {
        $options = function_exists('apply_filters')
            ? apply_filters('mxchat_get_bot_options', [], $bot_id)
            : [];
        if (empty($options)) {
            $options = get_option('mxchat_options', []);
        }

        $model = $options['embedding_model'] ?? 'text-embedding-ada-002';
        if (strpos($model, 'voyage') === 0) {
            return (string) ($options['voyage_api_key'] ?? '');
        }
        if (strpos($model, 'gemini-embedding') === 0) {
            return (string) ($options['gemini_api_key'] ?? '');
        }
        return (string) ($options['api_key'] ?? '');
    }
}
