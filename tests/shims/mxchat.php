<?php
/**
 * Stubs for the two upstream/companion-plugin classes the production code
 * calls into:
 *
 *   - MxChat_Utils — mxchat-basic's public utility class. The plugin's
 *     post_reprocessor + detect_embedding_dim call into it. The stub
 *     records every submit_content_to_db() call into $submit_calls so
 *     tests can assert on what content was sent; the return value is
 *     controllable via $submit_returns (set to a WP_Error to force the
 *     failure path).
 *
 *   - MxChat_Plus_DuckDB_Cache — the orchestration class in mxchat-plus.php
 *     itself. We don't load that file (it calls register_*_hook on
 *     constants that aren't defined under PHPUnit), so we stub the static
 *     surface that Vector_Store writes call into (flush_query_cache,
 *     bump_cache_generation, cache_generation).
 */

if (!class_exists('MxChat_Utils')) {
    class MxChat_Utils {
        public static array $submit_calls = [];
        /** @var mixed Set to a WP_Error to force a failure path. */
        public static $submit_returns = true;

        public static function submit_content_to_db(
            $content, $source_url, $api_key, $vector_id = null,
            $bot_id = 'default', $content_type = 'content'
        ) {
            self::$submit_calls[] = compact('content', 'source_url', 'api_key', 'vector_id', 'bot_id', 'content_type');
            return self::$submit_returns;
        }

        /**
         * @var mixed Controls generate_embedding_custom(): set to a numeric
         * array to simulate a successful probe (its length is the dimension),
         * or to a string to simulate the provider error path.
         */
        public static $custom_embedding_returns = [0.1, 0.2, 0.3];

        public static function generate_embedding_custom($text, $options) {
            return self::$custom_embedding_returns;
        }

        public static function embedding_model_dimensions($model) {
            // Mirrors mxchat-basic v3.x's centralised registry — keep in
            // sync with includes/class-mxchat-utils.php upstream so
            // OptionsSanitizeTest / detect_embedding_dim assertions
            // reflect real-world values.
            $known = [
                'text-embedding-ada-002' => 1536,
                'text-embedding-3-small' => 1536,
                'text-embedding-3-large' => 3072,
                'voyage-3-large'         => 2048,
                'gemini-embedding-001'   => 1536,
            ];
            return $known[$model] ?? 0;
        }
    }
}

if (!class_exists('MxChat_Plus_DuckDB_Cache')) {
    class MxChat_Plus_DuckDB_Cache {
        public static array $flushed = [];
        /** @var array<string,int> per-bot cache generation, mirrors the real class. */
        public static array $cache_gen_map = [];

        /** @return array<string,int> */
        public static function cache_generation_map(): array {
            return self::$cache_gen_map;
        }
        public static function cache_generation(string $bot_id = 'default'): int {
            $g = self::$cache_gen_map[$bot_id] ?? 1;
            return $g > 0 ? $g : 1;
        }
        public static function bump_cache_generation(string $bot_id = 'default'): void {
            self::$cache_gen_map[$bot_id] = self::cache_generation($bot_id) + 1;
        }
        public static function flush_query_cache(?string $bot_id = null): void {
            self::$flushed[] = microtime(true);
            if ($bot_id !== null) {
                self::bump_cache_generation($bot_id);
                return;
            }
            if (self::$cache_gen_map === []) {
                self::bump_cache_generation('default');
                return;
            }
            foreach (array_keys(self::$cache_gen_map) as $b) {
                self::bump_cache_generation((string) $b);
            }
        }
    }
}
