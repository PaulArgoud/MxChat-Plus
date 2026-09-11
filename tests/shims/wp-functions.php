<?php
/**
 * WordPress function shims that have no dependency on a class — i18n,
 * options + transients (backed by $GLOBALS arrays), sanitisation, URL
 * helpers, filter system, plugin path helpers.
 *
 * apply_filters() is a pass-through by default; tests can register a
 * static override via $GLOBALS['__test_filter_overrides'][$hook].
 */

if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') { return $text; }
}

// Filter / action system: tests override return values via
// $GLOBALS['__test_filter_overrides'] without monkey-patching call sites.
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args) {
        if (isset($GLOBALS['__test_filter_overrides'][$hook])) {
            return $GLOBALS['__test_filter_overrides'][$hook];
        }
        return $value;
    }
}
if (!function_exists('add_action')) {
    function add_action(...$a) {}
}
if (!function_exists('add_filter')) {
    function add_filter(...$a) {}
}
if (!function_exists('register_activation_hook')) {
    function register_activation_hook(...$a) {}
}
if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook(...$a) {}
}

// Options + transients live in $GLOBALS arrays. Tests reset by clearing
// $GLOBALS['__test_options'] / $GLOBALS['__test_transients'] in setUp.
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return $GLOBALS['__test_options'][$name] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null) {
        $GLOBALS['__test_options'][$name] = $value;
        return true;
    }
}
if (!function_exists('delete_option')) {
    function delete_option($name) {
        unset($GLOBALS['__test_options'][$name]);
        return true;
    }
}
if (!function_exists('get_transient')) {
    function get_transient($key) {
        return $GLOBALS['__test_transients'][$key] ?? false;
    }
}
if (!function_exists('set_transient')) {
    function set_transient($key, $value, $ttl = 0) {
        $GLOBALS['__test_transients'][$key] = $value;
        return true;
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient($key) {
        unset($GLOBALS['__test_transients'][$key]);
        return true;
    }
}

// Path / URL helpers.
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) { return dirname($file) . '/'; }
}
if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file) { return 'http://example.test/'; }
}
if (!function_exists('plugin_basename')) {
    function plugin_basename($file) { return basename($file); }
}
if (!function_exists('rest_url')) {
    function rest_url($path = '') {
        return 'http://example.test/wp-json/' . ltrim((string) $path, '/');
    }
}
if (!function_exists('untrailingslashit')) {
    function untrailingslashit($string) {
        return rtrim((string) $string, '/\\');
    }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit($string) {
        return rtrim((string) $string, '/\\') . '/';
    }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() {
        return ['basedir' => sys_get_temp_dir() . '/wp-uploads-test'];
    }
}
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($path) {
        return is_dir($path) || mkdir($path, 0755, true);
    }
}

// Sanitisation + misc helpers.
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        return is_string($value) ? stripslashes($value) : $value;
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return is_scalar($str) ? trim(strip_tags((string) $str)) : '';
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        $key = strtolower((string) $key);
        return preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}
if (!function_exists('esc_url_raw')) {
    // Minimal shim — the cascade-delete handlers feed this with already-
    // structured URLs in tests, so we just normalise to a trimmed string.
    function esc_url_raw($url) {
        return is_scalar($url) ? trim((string) $url) : '';
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($string, $remove_breaks = false) {
        $string = (string) $string;
        $string = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $string);
        $string = strip_tags($string);
        if ($remove_breaks) {
            $string = preg_replace('/[\r\n\t ]+/', ' ', $string);
        }
        return trim($string);
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) {
        return bin2hex(random_bytes((int) ceil($length / 2)));
    }
}
if (!function_exists('wp_doing_cron')) {
    function wp_doing_cron() {
        return defined('DOING_CRON') && DOING_CRON;
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        return $GLOBALS['__test_current_user_can'] ?? true;
    }
}
