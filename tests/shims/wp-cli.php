<?php
/**
 * WP-CLI shims. The CLI command class in includes/class-duckdb-cli.php
 * is wrapped in `if (!defined('WP_CLI') || !WP_CLI) return;` and registers
 * itself at file-load via `\WP_CLI::add_command(...)`. Tests flip
 * WP_CLI=true and provide a minimal stub here so the class definition is
 * reachable AND every command's output is capturable.
 *
 * WP_CLI::error() die()s in production; the shim throws
 * MxChat_Test_CliExit so tests can assert a command terminated via error.
 *
 * `\WP_CLI\Utils\format_items` and `make_progress_bar` are declared via
 * eval() in the WP_CLI\Utils namespace — the only way to land functions
 * in a namespace from outside a namespaced file.
 */

if (!defined('WP_CLI')) define('WP_CLI', true);

if (!class_exists('MxChat_Test_CliExit')) {
    class MxChat_Test_CliExit extends Exception {}
}

if (!class_exists('WP_CLI')) {
    class WP_CLI {
        public static array $log_buf      = [];
        public static array $success_buf  = [];
        public static array $error_buf    = [];
        public static array $warning_buf  = [];
        public static array $commands     = [];

        public static function reset(): void {
            self::$log_buf = self::$success_buf = self::$error_buf = self::$warning_buf = [];
        }
        public static function log($msg)     { self::$log_buf[] = (string) $msg; }
        public static function success($msg) { self::$success_buf[] = (string) $msg; }
        public static function warning($msg) { self::$warning_buf[] = (string) $msg; }
        public static function error($msg)   {
            self::$error_buf[] = (string) $msg;
            throw new MxChat_Test_CliExit((string) $msg);
        }
        public static function add_command($name, $class) {
            self::$commands[$name] = $class;
        }
    }
}

if (!class_exists('WP_CLI\\Utils\\TestProgressBar')) {
    eval('namespace WP_CLI\\Utils;
    class TestProgressBar {
        public int $total;
        public int $ticked = 0;
        public bool $finished = false;
        public function __construct(int $total) { $this->total = $total; }
        public function tick(int $delta = 1) { $this->ticked += $delta; }
        public function finish() { $this->finished = true; }
    }
    function make_progress_bar(string $label, int $count) {
        return new TestProgressBar($count);
    }
    function format_items(string $format, array $items, $fields = null) {
        $GLOBALS["__test_cli_format_items"] = compact("format", "items", "fields");
    }');
}
