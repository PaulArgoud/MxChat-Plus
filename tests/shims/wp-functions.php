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
//
// Registrations are recorded, never run by default: add_filter() and
// add_action() (one function in core too) append a
// ['hook', 'callback', 'priority', 'accepted_args'] row to
// $GLOBALS['__test_hooks'], so a test can assert what a class registers.
// Two opt-ins, both off unless a test sets them (and resets them after):
//   - $GLOBALS['__test_dispatch_filters'] = true: apply_filters() runs the
//     recorded callbacks of the hook by priority, then registration order,
//     each given accepted_args arguments, as WP_Hook does. An override in
//     __test_filter_overrides still wins. Reset __test_hooks first, or the
//     registrations of earlier tests run too.
//   - $GLOBALS['__test_filter_calls'] = []: every apply_filters() call
//     appends ['hook', 'value', 'args'].
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args) {
        if (isset($GLOBALS['__test_filter_calls']) && is_array($GLOBALS['__test_filter_calls'])) {
            $GLOBALS['__test_filter_calls'][] = ['hook' => (string) $hook, 'value' => $value, 'args' => $args];
        }
        if (isset($GLOBALS['__test_filter_overrides'][$hook])) {
            return $GLOBALS['__test_filter_overrides'][$hook];
        }
        if (!empty($GLOBALS['__test_dispatch_filters'])) {
            return mxchat_test_dispatch_filter((string) $hook, $value, $args);
        }
        return $value;
    }
}
if (!function_exists('mxchat_test_dispatch_filter')) {
    function mxchat_test_dispatch_filter(string $hook, $value, array $args) {
        $callbacks = [];
        foreach ((array) ($GLOBALS['__test_hooks'] ?? []) as $order => $row) {
            if (is_array($row) && ($row['hook'] ?? null) === $hook) {
                $callbacks[] = [(int) $row['priority'], (int) $order, $row];
            }
        }
        usort($callbacks, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        foreach ($callbacks as [, , $row]) {
            $call_args = array_slice(array_merge([$value], $args), 0, max(0, (int) $row['accepted_args']));
            $value = call_user_func_array($row['callback'], $call_args);
        }
        return $value;
    }
}
if (!function_exists('add_filter')) {
    function add_filter($hook_name = '', $callback = null, $priority = 10, $accepted_args = 1) {
        $GLOBALS['__test_hooks'][] = [
            'hook'          => (string) $hook_name,
            'callback'      => $callback,
            'priority'      => (int) $priority,
            'accepted_args' => (int) $accepted_args,
        ];
        return true;
    }
}
if (!function_exists('add_action')) {
    function add_action($hook_name = '', $callback = null, $priority = 10, $accepted_args = 1) {
        return add_filter($hook_name, $callback, $priority, $accepted_args);
    }
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
if (!function_exists('add_option')) {
    // Like WordPress: refuses (false) when the option already exists.
    function add_option($name, $value = '', $deprecated = '', $autoload = 'yes') {
        if (array_key_exists($name, $GLOBALS['__test_options'] ?? [])) {
            return false;
        }
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
    // Behaves like core's esc_url($url, null, 'db') where it matters to the
    // tests: characters outside core's URL set are dropped, a scheme-less
    // host gets http://, and a scheme outside core's allowed protocols
    // (wp_allowed_protocols()) empties the URL — which is why MxChat's
    // upload:// and mxchat:// identities do not survive it.
    function esc_url_raw($url, $protocols = null) {
        if (!is_scalar($url)) return '';
        $url = str_replace(' ', '%20', ltrim((string) $url));
        $url = (string) preg_replace('|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\\x80-\\xff]|i', '', $url);
        if ($url === '') return '';
        if (strpos($url, ':') === false && !in_array($url[0], ['/', '#', '?'], true)
            && !preg_match('/^[a-z0-9-]+?\.php/i', $url)) {
            $url = 'http://' . $url;
        }
        $allowed = is_array($protocols) ? $protocols : [
            'http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'irc6', 'ircs', 'gopher',
            'nntp', 'feed', 'telnet', 'mms', 'rtsp', 'sms', 'svn', 'tel', 'fax', 'xmpp', 'webcal', 'urn',
        ];
        if (preg_match('/^([^:\/?#]+):/', $url, $m) && !in_array(strtolower($m[1]), $allowed, true)) {
            return '';
        }
        return $url;
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
if (!function_exists('absint')) {
    function absint($maybeint) {
        return abs((int) $maybeint);
    }
}
if (!function_exists('home_url')) {
    // Overridable per test: the tracking report classifies a URL as internal or
    // external by comparing its host with this one.
    function home_url($path = '') {
        $base = $GLOBALS['__test_home_url'] ?? 'https://example.test';
        return $path === '' ? $base : rtrim($base, '/') . '/' . ltrim((string) $path, '/');
    }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) {
        return parse_url((string) $url, $component);
    }
}

// Site timezone. Defaults to UTC, which makes every local-to-GMT conversion a
// no-op, so tests that assert raw bounds keep passing; a test that needs a
// non-UTC site sets $GLOBALS['__test_timezone'] (and unsets it afterwards).
if (!function_exists('wp_timezone')) {
    function wp_timezone() {
        return new DateTimeZone($GLOBALS['__test_timezone'] ?? 'UTC');
    }
}
if (!function_exists('get_gmt_from_date')) {
    // Same algorithm as core since WordPress 5.3.
    function get_gmt_from_date($date_string, $format = 'Y-m-d H:i:s') {
        $datetime = date_create((string) $date_string, wp_timezone());
        if ($datetime === false) {
            return gmdate($format, 0);
        }
        return $datetime->setTimezone(new DateTimeZone('UTC'))->format($format);
    }
}

// wp_date(): PHP's own formatter in the site timezone (wp_timezone() above),
// without WordPress's translation of month and day names. Enough for code that
// compares two values formatted the same way, such as the prompt-cache module's
// mirror of the host's {current_datetime} value.
if (!function_exists('wp_date')) {
    function wp_date($format, $timestamp = null, $timezone = null) {
        $tz = $timezone instanceof DateTimeZone ? $timezone : wp_timezone();
        return (new DateTimeImmutable('@' . (int) ($timestamp ?? time())))->setTimezone($tz)->format((string) $format);
    }
}
if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('wp_remote_retrieve_body')) {
    // Same contract as core: the body of a response array, '' for anything else.
    function wp_remote_retrieve_body($response) {
        return is_array($response) && isset($response['body']) ? (string) $response['body'] : '';
    }
}

// Script and style queues, recorded rather than printed:
// $GLOBALS['__test_scripts']['enqueued'|'localized'|'printed'] and
// $GLOBALS['__test_styles']['enqueued']. Tests reset both in setUp. Added for
// the tracking module's enqueue gate; is_feed() reads $GLOBALS['__test_is_feed'].
if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script($handle, $src = '', $deps = [], $ver = false, $args = []) {
        $GLOBALS['__test_scripts']['enqueued'][$handle] = ['src' => $src, 'deps' => $deps, 'ver' => $ver, 'args' => $args];
    }
}
if (!function_exists('wp_localize_script')) {
    function wp_localize_script($handle, $object_name, $l10n) {
        $GLOBALS['__test_scripts']['localized'][$handle][$object_name] = $l10n;
        return true;
    }
}
if (!function_exists('wp_print_scripts')) {
    // As in core: the global `wp_print_scripts` action fires on EVERY call,
    // whatever the handles (recorded in $GLOBALS['__test_actions'] by the
    // do_action() shim below), then the printing is left to
    // wp_scripts()->do_items() (wp-classes.php).
    function wp_print_scripts($handles = false) {
        do_action('wp_print_scripts');
        return wp_scripts()->do_items($handles);
    }
}
if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style($handle, $src = '', $deps = [], $ver = false, $media = 'all') {
        $GLOBALS['__test_styles']['enqueued'][$handle] = true;
    }
}
if (!function_exists('wp_style_is')) {
    // Only the 'enqueued' list is modelled; as in core, a printed handle stays in it.
    function wp_style_is($handle, $status = 'enqueued') {
        return !empty($GLOBALS['__test_styles']['enqueued'][$handle]);
    }
}
if (!function_exists('is_feed')) {
    function is_feed($feeds = '') {
        return !empty($GLOBALS['__test_is_feed']);
    }
}

// Template helpers, enough to include an admin view and read its markup.
if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = 'default') {
        echo htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_url')) {
    // Core returns '' for a scheme outside its allow-list; http(s), mailto and
    // scheme-less URLs are enough here.
    function esc_url($url) {
        $url = trim((string) $url);
        if ($url === '' || preg_match('#^(?:https?:|mailto:|/|\?|\#)#i', $url) !== 1) {
            return '';
        }
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('checked')) {
    function checked($checked, $current = true, $display = true) {
        $result = ((string) $checked === (string) $current) ? " checked='checked'" : '';
        if ($display) {
            echo $result;
        }
        return $result;
    }
}
if (!function_exists('settings_fields')) {
    function settings_fields($option_group) {
        echo '<input type="hidden" name="option_page" value="' . htmlspecialchars((string) $option_group, ENT_QUOTES, 'UTF-8') . '">';
    }
}
if (!function_exists('submit_button')) {
    function submit_button($text = null) {
        echo '<input type="submit" class="button button-primary" value="' . htmlspecialchars((string) ($text ?? 'Save Changes'), ENT_QUOTES, 'UTF-8') . '">';
    }
}
if (!function_exists('number_format_i18n')) {
    function number_format_i18n($number, $decimals = 0) {
        return number_format((float) $number, (int) $decimals);
    }
}

// Site salts (wp-config.php in core). Fixed per scheme, so a signature the
// Pinecone proxy computes on the calling side verifies on the receiving side.
if (!function_exists('wp_salt')) {
    function wp_salt($scheme = 'auth') {
        return 'test-salt-' . (string) $scheme;
    }
}

// Hook-state, AJAX-context and WP-cron shims for the DuckDB host-integration
// layer. doing_filter() reads $GLOBALS['__test_doing_filter'] (a list of hook
// names "currently running"); wp_doing_ajax() reads
// $GLOBALS['__test_doing_ajax']. Scheduled events are recorded in
// $GLOBALS['__test_cron'] as ['hook', 'args', 'timestamp'] rows; actions fired
// with do_action() in $GLOBALS['__test_actions']. Tests reset what they use.
if (!function_exists('doing_filter')) {
    function doing_filter($hook_name = null) {
        $running = (array) ($GLOBALS['__test_doing_filter'] ?? []);
        return $hook_name === null ? $running !== [] : in_array($hook_name, $running, true);
    }
}
if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax() {
        return !empty($GLOBALS['__test_doing_ajax']);
    }
}
if (!function_exists('do_action')) {
    function do_action($hook_name, ...$args) {
        $GLOBALS['__test_actions'][] = ['hook' => (string) $hook_name, 'args' => $args];
    }
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = []) {
        foreach ((array) ($GLOBALS['__test_cron'] ?? []) as $event) {
            if ($event['hook'] === $hook && $event['args'] === $args) return $event['timestamp'];
        }
        return false;
    }
}
if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook, $args = [], $wp_error = false) {
        $GLOBALS['__test_cron'][] = ['hook' => (string) $hook, 'args' => $args, 'timestamp' => (int) $timestamp];
        return true;
    }
}
if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook, $args = [], $wp_error = false) {
        $GLOBALS['__test_cron'][] = ['hook' => (string) $hook, 'args' => $args, 'timestamp' => (int) $timestamp];
        return true;
    }
}
if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook, $args = [], $wp_error = false) {
        $kept = [];
        $cleared = 0;
        foreach ((array) ($GLOBALS['__test_cron'] ?? []) as $event) {
            if ($event['hook'] === $hook && $event['args'] === $args) { $cleared++; continue; }
            $kept[] = $event;
        }
        $GLOBALS['__test_cron'] = $kept;
        return $cleared;
    }
}
