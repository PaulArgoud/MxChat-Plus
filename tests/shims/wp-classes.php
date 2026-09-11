<?php
/**
 * WordPress object stubs: WP_Post, WP_Query, WP_REST_Request,
 * WP_REST_Response, WP_Error + get_post / get_permalink / is_wp_error
 * (which sit closer to the object surface than the function shims).
 *
 * WP_Query is driven by $GLOBALS['__test_wp_query_matcher'] — a closure
 * that takes the args dict and returns ['posts' => …, 'found_posts' => …].
 */

if (!class_exists('WP_Post', false)) {
    class WP_Post {
        public int $ID = 0;
        public string $post_title = '';
        public string $post_content = '';
        public string $post_type = 'post';
        public string $post_status = 'publish';
        public function __construct(array $props = []) {
            foreach ($props as $k => $v) { $this->$k = $v; }
        }
    }
}

if (!class_exists('WP_Query', false)) {
    class WP_Query {
        public array $posts = [];
        public int $found_posts = 0;
        public function __construct(array $args = []) {
            $matcher = $GLOBALS['__test_wp_query_matcher'] ?? null;
            if (is_callable($matcher)) {
                $result = $matcher($args);
                $this->posts       = $result['posts']       ?? [];
                $this->found_posts = $result['found_posts'] ?? count($this->posts);
            }
        }
    }
}

if (!class_exists('WP_Error', false)) {
    class WP_Error {
        public string $message;
        /** @var mixed */
        public $code;
        /** @var mixed */
        public $data;
        public function __construct($code = '', string $message = '', $data = null) {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }
        public function get_error_message(): string { return $this->message; }
        public function get_error_code() { return $this->code; }
        /** @return mixed */
        public function get_error_data() { return $this->data; }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return $thing instanceof WP_Error; }
}

if (!function_exists('get_post')) {
    function get_post($id) {
        return $GLOBALS['__test_posts'][(int) $id] ?? null;
    }
}
if (!function_exists('get_permalink')) {
    function get_permalink($id) {
        return $GLOBALS['__test_permalinks'][(int) $id] ?? false;
    }
}

// REST stubs — covers the surface that Pinecone_Proxy::check_token,
// /health, and the REST handlers actually exercise.
// pass `false` to suppress autoload: composer's optimized classmap in CI
// resolves WP_REST_Request to vendor/php-stubs/wordpress-stubs/wordpress-stubs.php
// (a transitive dev-dep of szepeviktor/phpstan-wordpress) before the shim is
// reached, which would silently strip our test-only methods (set_json_params,
// set_query_params, ...). Skipping autoload lets the shim always win when no
// class is yet declared at the start of bootstrap.
if (!class_exists('WP_REST_Request', false)) {
    class WP_REST_Request {
        private array $headers = [];
        private array $params = [];
        private array $query = [];
        private ?array $json = null;

        public function set_header(string $name, ?string $value): void {
            $this->headers[strtolower($name)] = $value;
        }
        public function get_header(string $name): ?string {
            return $this->headers[strtolower($name)] ?? null;
        }
        public function set_param(string $name, $value): void {
            $this->params[$name] = $value;
        }
        public function get_param(string $name) {
            return $this->params[$name] ?? null;
        }
        public function set_json_params(?array $body): void {
            $this->json = $body;
        }
        public function get_json_params(): ?array {
            return $this->json;
        }
        public function set_query_params(array $params): void {
            $this->query = $params;
        }
        public function get_query_params(): array {
            return $this->query;
        }
    }
}

if (!class_exists('WP_REST_Response', false)) {
    class WP_REST_Response {
        public $data;
        public int $status;
        public function __construct($data = null, int $status = 200) {
            $this->data = $data;
            $this->status = $status;
        }
    }
}

if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args = [], $override = false) {
        $key = $namespace . $route;
        if (!isset($GLOBALS['__test_rest_routes'])) {
            $GLOBALS['__test_rest_routes'] = [];
        }
        $GLOBALS['__test_rest_routes'][$key] = $args;
        return true;
    }
}
