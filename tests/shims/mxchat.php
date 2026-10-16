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

        // Seven parameters, as in MxChat 3.2.23+ (the 7th is the page title).
        public static function submit_content_to_db(
            $content, $source_url, $api_key, $vector_id = null,
            $bot_id = 'default', $content_type = 'content', $title = ''
        ) {
            self::$submit_calls[] = compact('content', 'source_url', 'api_key', 'vector_id', 'bot_id', 'content_type', 'title');
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

        /**
         * Host 3.2.24+ helper: clears the media auto-sync signature of an
         * attachment URL. Records each call; reset by assigning [].
         *
         * @var array<int, array{source_url: string, bot_id: string}>
         */
        public static array $forgotten_signatures = [];

        public static function forget_attachment_signature($source_url, $bot_id = 'default') {
            self::$forgotten_signatures[] = ['source_url' => (string) $source_url, 'bot_id' => (string) $bot_id];
        }

        /**
         * Host 3.2.24+ helper: clears every media auto-sync signature of a
         * bot. Records the bot ids; reset by assigning [].
         *
         * @var list<string>
         */
        public static array $forgotten_all_signatures = [];

        public static function forget_all_attachment_signatures($bot_id = 'default') {
            self::$forgotten_all_signatures[] = (string) $bot_id;
        }

        // Copies of MxChat 3.2.25's embedding helpers (includes/class-mxchat-utils.php),
        // so the code paths that prefer them run under PHPUnit.

        public static function embedding_preflight($options = null) {
            if (!is_array($options)) {
                $options = get_option('mxchat_options');
                $options = is_array($options) ? $options : array();
            }
            if (isset($options['custom_provider_for_embeddings']) && $options['custom_provider_for_embeddings'] === 'on') {
                $base_url = isset($options['custom_provider_base_url']) ? rtrim(trim((string) $options['custom_provider_base_url']), '/') : '';
                if ($base_url === '') {
                    return array('ok' => false, 'api_key' => '', 'reason' => 'Custom provider Base URL is not configured.', 'provider' => 'Custom Provider');
                }
                return array('ok' => true, 'api_key' => '', 'reason' => '', 'provider' => 'Custom Provider');
            }
            $selected_model = $options['embedding_model'] ?? 'text-embedding-ada-002';
            if (strpos($selected_model, 'voyage') === 0) {
                $api_key = $options['voyage_api_key'] ?? '';
                $provider = 'Voyage AI';
            } elseif (strpos($selected_model, 'gemini-embedding') === 0) {
                $api_key = $options['gemini_api_key'] ?? '';
                $provider = 'Google Gemini';
            } else {
                $api_key = $options['api_key'] ?? '';
                $provider = 'OpenAI';
            }
            if (empty($api_key)) {
                return array('ok' => false, 'api_key' => '', 'reason' => sprintf('%s API key is not configured. Please add your API key in the settings before submitting content.', $provider), 'provider' => $provider);
            }
            return array('ok' => true, 'api_key' => $api_key, 'reason' => '', 'provider' => $provider);
        }

        public static function resolve_custom_embedding_model($options) {
            if (isset($options['custom_provider_embedding_model']) && trim((string) $options['custom_provider_embedding_model']) !== '') {
                return trim((string) $options['custom_provider_embedding_model']);
            }
            if (isset($options['custom_provider_model']) && trim((string) $options['custom_provider_model']) !== '') {
                return trim((string) $options['custom_provider_model']);
            }
            return 'default';
        }

        public static function get_selected_embedding_model($options = null) {
            if (!is_array($options)) {
                $options = get_option('mxchat_options', array());
            }
            if (isset($options['custom_provider_for_embeddings']) && $options['custom_provider_for_embeddings'] === 'on') {
                return 'custom:' . self::resolve_custom_embedding_model($options);
            }
            return $options['embedding_model'] ?? '';
        }

        public static function standard_embedding_dimension($options = null) {
            if (!is_array($options)) {
                $options = get_option('mxchat_options', array());
                $options = is_array($options) ? $options : array();
            }
            $selected_model = $options['embedding_model'] ?? 'text-embedding-ada-002';
            $model_dimensions = array(
                'text-embedding-ada-002' => 1536,
                'text-embedding-3-small' => 1536,
                'text-embedding-3-large' => 3072,
                'voyage-2'               => 1024,
                'voyage-large-2'         => 1536,
                'voyage-3-large'         => 2048,
                'gemini-embedding-001'   => 1536,
            );
            if (strpos($selected_model, 'voyage-3-large') === 0) {
                return intval($options['voyage_output_dimension'] ?? 2048);
            }
            if (strpos($selected_model, 'gemini-embedding') === 0) {
                return intval($options['gemini_output_dimension'] ?? 1536);
            }
            return $model_dimensions[$selected_model] ?? 1536;
        }
    }
}

// MxChat's knowledge manager (admin/class-knowledge-manager.php). Only the
// surface the post reprocessor reaches by reflection: the private content
// assembler and the private role re-application, plus get_instance().
// $assembled sets what the assembler returns (null = a non-array, which makes
// the reprocessor fall back to its own builder); calls are recorded.
// `false`: never let the guard autoload (see ARCHITECTURE.md, test conventions).
if (!class_exists('MxChat_Knowledge_Manager', false)) {
    class MxChat_Knowledge_Manager {
        /** @var array<string, mixed>|null */
        public static $assembled = null;
        /** @var list<array{post_id: int, args: array<string, mixed>}> */
        public static array $assembler_calls = [];
        /** @var list<array{post_id: int, source_url: string}> */
        public static array $role_calls = [];

        public static function get_instance() {
            static $instance = null;
            return $instance ??= new self();
        }

        public static function reset(): void {
            self::$assembled = null;
            self::$assembler_calls = [];
            self::$role_calls = [];
        }

        private function mxchat_prepare_post_content_for_indexing($post_id, $post, $args) {
            self::$assembler_calls[] = ['post_id' => (int) $post_id, 'args' => (array) $args];
            return self::$assembled;
        }

        private function apply_role_restriction_to_post($post_id, $source_url) {
            self::$role_calls[] = ['post_id' => (int) $post_id, 'source_url' => (string) $source_url];
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

// Host AI Tools seams that MxChat_Plus_Host::ai_tools_route_claude() mirrors
// (MxChat_Tool_Registry / MxChat_Model_Catalog in mxchat-basic). Inert by
// default — no tool enabled, every model tool-capable — so nothing changes for
// a test that does not opt in; call reset() in tearDown after opting in.
// `false`: never let the guard autoload (see ARCHITECTURE.md, test conventions).
if (!class_exists('MxChat_Tool_Registry', false)) {
    class MxChat_Tool_Registry {
        public static bool $enabled = false;
        /** @var array<int, array<string, mixed>> */
        public static array $enabled_tools = [];

        public static function is_enabled() {
            return self::$enabled;
        }
        public static function enabled_tools($enabled_map = null) {
            return self::$enabled_tools;
        }
        // Copies of MxChat 3.2.25's loop limits, read by
        // MxChat_Plus_Host::tool_loop_profile(). Same host filters and
        // defaults: set $GLOBALS['__test_filter_overrides'][hook] to change them.
        public static function max_depth() {
            $depth = (int) apply_filters('mxchat_function_call_max_depth', 5);
            return max(1, min(10, $depth));
        }
        public static function max_tool_calls_per_turn() {
            $n = (int) apply_filters('mxchat_function_call_max_per_turn', 8);
            return max(1, min(20, $n));
        }
        public static function reset(): void {
            self::$enabled       = false;
            self::$enabled_tools = [];
        }
    }
}
if (!class_exists('MxChat_Model_Catalog', false)) {
    class MxChat_Model_Catalog {
        public static bool $supports_tools = true;

        public static function supports_tools($model_id) {
            return self::$supports_tools;
        }
        public static function reset(): void {
            self::$supports_tools = true;
        }
    }
}
