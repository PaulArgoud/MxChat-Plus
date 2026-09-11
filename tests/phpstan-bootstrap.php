<?php
/**
 * Minimal stubs so PHPStan can analyse the plugin without pulling in the full
 * WordPress codebase. Every symbol here is something WordPress provides at
 * runtime that PHPStan would otherwise flag as unknown.
 *
 * Only declare what the plugin actually references — keep this file small.
 */

if (!defined('ABSPATH')) define('ABSPATH', '/wp/');
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
if (!defined('WPINC')) define('WPINC', 'wp-includes');

// Functions are declared inside an if(false) so they never run, but PHPStan
// sees their signature. This is the standard WordPress-stubs pattern.
if (false) {
    function __($text, $domain = 'default') { return $text; }
    function _e($text, $domain = 'default') {}
    function esc_html__($text, $domain = 'default') { return $text; }
    function esc_html_e($text, $domain = 'default') {}
    function esc_attr__($text, $domain = 'default') { return $text; }
    function esc_attr_e($text, $domain = 'default') {}
    function esc_html($text) { return $text; }
    function esc_attr($text) { return $text; }
    function esc_url($url) { return $url; }
    function wp_kses_post($data) { return $data; }
    function wp_strip_all_tags($string, $remove_breaks = false) { return $string; }
    function sanitize_text_field($str) { return $str; }
    function sanitize_key($key) { return $key; }
    function wp_unslash($value) { return $value; }
    function wp_json_encode($data, $options = 0, $depth = 512) { return ''; }
    function wp_date($format, $timestamp = null, $timezone = null) { return ''; }
    function wp_create_nonce($action) { return ''; }
    function wp_verify_nonce($nonce, $action) { return 1; }
    function check_ajax_referer($action, $query_arg = false, $die = true) { return 1; }
    function current_user_can($capability) { return true; }
    function get_option($name, $default = false) { return $default; }
    function update_option($name, $value, $autoload = null) { return true; }
    function add_option($name, $value, $deprecated = '', $autoload = 'yes') { return true; }
    function delete_option($name) { return true; }
    function get_transient($transient) { return false; }
    function set_transient($transient, $value, $expiration = 0) { return true; }
    function delete_transient($transient) { return true; }
    function get_post($id = null, $output = OBJECT, $filter = 'raw') { return null; }
    function get_permalink($post = 0) { return ''; }
    function get_sites($args = []) { return []; }
    function switch_to_blog($id) {}
    function restore_current_blog() {}
    function is_multisite() { return false; }
    function is_admin() { return false; }
    function is_dir($d) { return false; }
    function plugin_dir_path($file) { return ''; }
    function plugin_dir_url($file) { return ''; }
    function plugin_basename($file) { return ''; }
    function load_plugin_textdomain($domain, $deprecated = false, $plugin_rel_path = false) { return true; }
    function register_activation_hook($file, $callback) {}
    function register_deactivation_hook($file, $callback) {}
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {}
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {}
    function apply_filters($hook_name, $value, ...$args) { return $value; }
    function do_action($hook_name, ...$args) {}
    function add_settings_error($setting, $code, $message, $type = 'error') {}
    function settings_fields($option_group) {}
    function submit_button($text = null) {}
    function selected($selected, $current = true, $echo = true) {}
    function checked($checked, $current = true, $echo = true) {}
    function trailingslashit($string) { return $string; }
    function untrailingslashit($string) { return $string; }
    function admin_url($path = '', $scheme = 'admin') { return ''; }
    function rest_url($path = '', $scheme = 'rest') { return ''; }
    function wp_upload_dir() { return ['basedir' => '', 'baseurl' => '']; }
    function wp_mkdir_p($target) { return true; }
    function wp_remote_post($url, $args = []) { return []; }
    function wp_remote_get($url, $args = []) { return []; }
    function wp_remote_retrieve_response_code($response) { return 200; }
    function wp_remote_retrieve_body($response) { return ''; }
    function is_wp_error($thing) { return false; }
    function wp_send_json_success($data = null, $status_code = null) {}
    function wp_send_json_error($data = null, $status_code = null) {}
    function wp_schedule_event($timestamp, $recurrence, $hook, $args = []) {}
    function wp_clear_scheduled_hook($hook, $args = []) {}
    function wp_next_scheduled($hook, $args = []) { return false; }
    function wp_unschedule_event($timestamp, $hook, $args = []) {}
    function register_setting($option_group, $option_name, $args = []) {}
    function register_rest_route($namespace, $route, $args = [], $override = false) {}
    function add_menu_page($page_title, $menu_title, $capability, $menu_slug, $function = '', $icon_url = '', $position = null) {}
    function add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $function = '') {}
    function wp_enqueue_script($handle, $src = '', $deps = [], $ver = false, $in_footer = false) {}
    function wp_localize_script($handle, $object_name, $l10n) {}
    function flush_rewrite_rules($hard = true) {}
    function get_sites_count_default() { return 0; }
}

if (!class_exists('WP_REST_Request')) {
    /** @phpstan-ignore-next-line */
    class WP_REST_Request {
        public function get_json_params() { return []; }
        public function get_query_params() { return []; }
        public function get_header($name) { return ''; }
        public function set_param($name, $value) {}
    }
}
if (!class_exists('WP_REST_Response')) {
    /** @phpstan-ignore-next-line */
    class WP_REST_Response {
        public function __construct($data = null, $status = 200, $headers = []) {}
    }
}
if (!class_exists('WP_Post')) {
    /** @phpstan-ignore-next-line */
    class WP_Post {
        public $ID;
        public $post_title = '';
        public $post_content = '';
        public $post_type = '';
    }
}
if (!class_exists('WP_Query')) {
    /** @phpstan-ignore-next-line */
    class WP_Query {
        public $posts = [];
        public $found_posts = 0;
        public function __construct($query = '') {}
    }
}
if (!class_exists('WP_Error')) {
    /** @phpstan-ignore-next-line */
    class WP_Error {
        public function get_error_message($code = '') { return ''; }
    }
}
if (!class_exists('wpdb')) {
    /** @phpstan-ignore-next-line */
    class wpdb {
        public $prefix = 'wp_';
        public $options = 'wp_options';
        public function get_var($query, $x = 0, $y = 0) { return null; }
        public function get_results($query, $output = OBJECT) { return []; }
        public function get_col($query, $x = 0) { return []; }
        public function query($query) { return 0; }
        public function prepare($query, ...$args) { return ''; }
    }
}

// WP-CLI namespaced helpers used by class-duckdb-cli.php live in a
// separate stub file (phpstan/wp-cli-stubs.php) registered via the
// `stubFiles` directive in phpstan.neon.dist. Splitting the file is
// necessary because PHP namespace declarations cannot follow non-
// namespaced statements in the same file.
