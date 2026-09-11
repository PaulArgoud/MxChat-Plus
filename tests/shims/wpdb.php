<?php
/**
 * Minimal $wpdb mock — pattern-matched canned responses for the call
 * surface our classes actually touch (query, get_var, get_results,
 * get_col, prepare).
 *
 *   $wpdb = new MxChat_Test_WPDB();
 *   $wpdb->set_response('SELECT COUNT(*)', 5);
 *   $wpdb->set_response('SELECT id, url', function ($sql) {
 *       return stripos($sql, 'OFFSET 0') !== false ? $rows : [];
 *   });
 *   $GLOBALS['wpdb'] = $wpdb;
 *
 * Callable responses receive the full SQL string — useful for paginated
 * mocks that return one batch then empty.
 *
 * NOT_FOUND sentinel: distinguishes "test explicitly registered null"
 * (signals the production "unreadable table" branch) from "no pattern
 * matched" (degrades to a safe default per accessor).
 */

if (!class_exists('MxChat_Test_WPDB')) {
    class MxChat_Test_WPDB {
        public string $prefix  = 'wp_';
        public string $options = 'wp_options';
        /** @var string[] */
        public array $log = [];
        /** @var array<string, mixed> */
        public array $responses = [];

        const NOT_FOUND = '__mxd_test_wpdb_not_found__';

        public function set_response(string $sql_pattern, $value): void {
            $this->responses[$sql_pattern] = $value;
        }

        private function findResponse(string $sql) {
            foreach ($this->responses as $pattern => $value) {
                if (stripos($sql, $pattern) !== false) {
                    return is_callable($value) ? $value($sql) : $value;
                }
            }
            return self::NOT_FOUND;
        }

        public function query(string $sql) {
            $this->log[] = $sql;
            $r = $this->findResponse($sql);
            return $r === self::NOT_FOUND ? 0 : ($r ?? 0);
        }

        public function get_var(string $sql) {
            $this->log[] = $sql;
            $r = $this->findResponse($sql);
            return $r === self::NOT_FOUND ? null : $r;
        }

        public function get_results(string $sql, $output = null) {
            $this->log[] = $sql;
            $r = $this->findResponse($sql);
            if ($r === self::NOT_FOUND) return [];
            return $r;
        }

        public function get_col(string $sql, $col_offset = 0) {
            $this->log[] = $sql;
            $r = $this->findResponse($sql);
            if ($r === self::NOT_FOUND) return [];
            return is_array($r) ? $r : [];
        }

        /** Minimal sprintf-shaped prepare — covers %s, %d, %f, %i. */
        public function prepare(string $sql, ...$args) {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }
            $i = 0;
            return preg_replace_callback('/%[sdfiF]/', function ($m) use (&$i, $args) {
                $v = $args[$i++] ?? null;
                if (is_int($v) || is_float($v)) return (string) $v;
                if (is_null($v))                return 'NULL';
                return "'" . str_replace("'", "''", (string) $v) . "'";
            }, $sql);
        }
    }
}
