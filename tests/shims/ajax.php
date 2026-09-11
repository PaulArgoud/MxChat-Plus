<?php
/**
 * AJAX + nonce shims — the most subtle part of the test infrastructure.
 *
 * Production handlers wrap their work in:
 *
 *     try {
 *         …;
 *         wp_send_json_success($payload);
 *     } catch (\Throwable $e) {
 *         wp_send_json_error(['message' => $e->getMessage()]);
 *     }
 *
 * Our shim throws MxChat_Test_AjaxResponseException to mimic the die()
 * semantics — but that exception is itself a Throwable and gets caught
 * by the production try/catch, which then "reports" it via a second
 * wp_send_json_error call. The "first response wins" pattern below
 * (stash in $GLOBALS, ignore subsequent stashes) lets the test see the
 * original intent regardless of what production does with the exception.
 *
 * add_settings_error appends to a global array so OptionsSanitizeTest can
 * assert the user-facing warnings.
 */

if (!class_exists('MxChat_Test_AjaxResponseException')) {
    class MxChat_Test_AjaxResponseException extends Exception {
        public $payload;
        public bool $success;
        public ?int $status_code;
        public function __construct(bool $success, $payload, ?int $status_code = null) {
            $msg = 'ajax response (' . ($success ? 'success' : 'error') . ')';
            if (is_array($payload) && isset($payload['message']) && is_scalar($payload['message'])) {
                $msg = (string) $payload['message'];
            }
            parent::__construct($msg);
            $this->success     = $success;
            $this->payload     = $payload;
            $this->status_code = $status_code;
        }
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, $status_code = null) {
        if (!isset($GLOBALS['__test_ajax_response'])) {
            $GLOBALS['__test_ajax_response'] = ['success' => true, 'payload' => $data, 'status' => $status_code];
        }
        throw new MxChat_Test_AjaxResponseException(true, $data, $status_code);
    }
}
if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $status_code = null) {
        if (!isset($GLOBALS['__test_ajax_response'])) {
            $GLOBALS['__test_ajax_response'] = ['success' => false, 'payload' => $data, 'status' => $status_code];
        }
        throw new MxChat_Test_AjaxResponseException(false, $data, $status_code);
    }
}
if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action, $query_arg = '_ajax_nonce', $die = true) {
        $nonce = $_POST[$query_arg] ?? $_REQUEST[$query_arg] ?? '';
        $valid = $GLOBALS['__test_valid_nonces'] ?? [];
        $ok = isset($valid[$nonce]) && $valid[$nonce] === $action;
        if (!$ok && $die) {
            if (!isset($GLOBALS['__test_ajax_response'])) {
                $GLOBALS['__test_ajax_response'] = ['success' => false, 'payload' => ['code' => 'invalid_nonce'], 'status' => 403];
            }
            throw new MxChat_Test_AjaxResponseException(false, ['code' => 'invalid_nonce'], 403);
        }
        return $ok;
    }
}

// Nonces — tests register valid ones in $GLOBALS['__test_valid_nonces'].
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action) {
        $valid = $GLOBALS['__test_valid_nonces'] ?? [];
        return isset($valid[$nonce]) && $valid[$nonce] === $action;
    }
}
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action) {
        $n = 'nonce_' . md5($action);
        $GLOBALS['__test_valid_nonces'][$n] = $action;
        return $n;
    }
}

if (!function_exists('add_settings_error')) {
    function add_settings_error($setting, $code, $message, $type = 'error') {
        $GLOBALS['__test_settings_errors'][] = compact('setting', 'code', 'message', 'type');
    }
}
