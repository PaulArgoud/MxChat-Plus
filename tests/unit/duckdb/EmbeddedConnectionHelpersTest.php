<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the conservative idempotency sniff used to decide whether a SQL
 * statement may be retried on a transient error. False positives here are
 * the dangerous direction (we'd re-execute an INSERT), so the test list is
 * deliberately strict about negatives.
 */
final class EmbeddedConnectionHelpersTest extends TestCase {

    private static function call(string $method, array $args = []) {
        $r = new ReflectionMethod(MxChat_Plus_DuckDB_Embedded_Connection::class, $method);
        return $r->invokeArgs(null, $args);
    }

    public function test_select_is_idempotent(): void {
        $this->assertTrue(self::call('looks_idempotent', ['SELECT 1']));
        $this->assertTrue(self::call('looks_idempotent', ["  select count(*) from t"]));
    }

    public function test_with_pragma_show_describe_explain_are_idempotent(): void {
        $this->assertTrue(self::call('looks_idempotent', ['WITH x AS (SELECT 1) SELECT * FROM x']));
        $this->assertTrue(self::call('looks_idempotent', ['PRAGMA table_info(foo)']));
        $this->assertTrue(self::call('looks_idempotent', ['SHOW TABLES']));
        $this->assertTrue(self::call('looks_idempotent', ['DESCRIBE foo']));
        $this->assertTrue(self::call('looks_idempotent', ['EXPLAIN SELECT 1']));
    }

    public function test_insert_update_delete_are_not_idempotent(): void {
        $this->assertFalse(self::call('looks_idempotent', ['INSERT INTO t VALUES (1)']));
        $this->assertFalse(self::call('looks_idempotent', ['INSERT OR REPLACE INTO t VALUES (1)']));
        $this->assertFalse(self::call('looks_idempotent', ['UPDATE t SET x=1']));
        $this->assertFalse(self::call('looks_idempotent', ['DELETE FROM t']));
        $this->assertFalse(self::call('looks_idempotent', ['COPY t TO \'/tmp\'']));
    }

    public function test_is_transient_error_recognises_common_signals(): void {
        $this->assertTrue(self::call('is_transient_error', [new RuntimeException('Read timeout')]));
        $this->assertTrue(self::call('is_transient_error', [new RuntimeException('connection reset by peer')]));
        $this->assertTrue(self::call('is_transient_error', [new RuntimeException('HTTP 503 Service Unavailable')]));
        $this->assertTrue(self::call('is_transient_error', [new RuntimeException('rate limit exceeded')]));
        $this->assertTrue(self::call('is_transient_error', [new RuntimeException('TLS handshake failure')]));
    }

    public function test_is_transient_error_rejects_logic_errors(): void {
        $this->assertFalse(self::call('is_transient_error', [new RuntimeException('syntax error at column 12')]));
        $this->assertFalse(self::call('is_transient_error', [new RuntimeException('unknown column foo')]));
        $this->assertFalse(self::call('is_transient_error', [new RuntimeException('permission denied')]));
    }

    public function test_is_transient_error_uses_http_status_code_signal(): void {
        // PECL bindings that wrap HTTP transports surface the upstream
        // status on getCode(); 5xx + 429 must be classified as transient
        // regardless of the message content.
        $this->assertTrue(self::call('is_transient_error', [new RuntimeException('upstream blew up', 503)]));
        $this->assertTrue(self::call('is_transient_error', [new RuntimeException('rate ceiling', 429)]));
        $this->assertTrue(self::call('is_transient_error', [new RuntimeException('gateway', 504)]));
        // 4xx (other than 429) must NOT be transient — those are caller errors.
        $this->assertFalse(self::call('is_transient_error', [new RuntimeException('bad request', 400)]));
        $this->assertFalse(self::call('is_transient_error', [new RuntimeException('forbidden', 403)]));
        $this->assertFalse(self::call('is_transient_error', [new RuntimeException('not found', 404)]));
    }

    // ─── CLI JSON parsing robustness (v0.12.0) ────────────────────────────

    public function test_decode_cli_json_parses_a_single_array(): void {
        $rows = self::call('decode_cli_json', ['[{"id":"a","score":0.9},{"id":"b","score":0.8}]']);
        $this->assertCount(2, $rows);
        $this->assertSame('a', $rows[0]['id']);
    }

    public function test_decode_cli_json_empty_and_garbage_return_empty(): void {
        $this->assertSame([], self::call('decode_cli_json', ['']));
        $this->assertSame([], self::call('decode_cli_json', ["   \n  "]));
        $this->assertSame([], self::call('decode_cli_json', ['not json at all']));
    }

    public function test_decode_cli_json_takes_last_array_of_concatenated_result_sets(): void {
        // init-SQL produced output, then the real query — the query is last.
        $stdout = "[{\"info\":\"attached\"}]\n[{\"id\":\"real\",\"score\":0.5}]";
        $rows = self::call('decode_cli_json', [$stdout]);
        $this->assertCount(1, $rows);
        $this->assertSame('real', $rows[0]['id']);
    }

    public function test_decode_cli_json_handles_ndjson_lines(): void {
        $stdout = "{\"id\":\"x\"}\n{\"id\":\"y\"}\n";
        $rows = self::call('decode_cli_json', [$stdout]);
        $this->assertCount(2, $rows);
        $this->assertSame('x', $rows[0]['id']);
        $this->assertSame('y', $rows[1]['id']);
    }

    public function test_decode_cli_json_wraps_a_bare_single_object(): void {
        $rows = self::call('decode_cli_json', ['{"ok":1}']);
        $this->assertSame([['ok' => 1]], $rows);
    }

    public function test_is_transient_error_word_anchored_rejects_substring_traps(): void {
        // The v0.6 implementation matched a bare "network" / "timeout".
        // After the v0.9 tightening, a logic error whose message happens
        // to contain those words in another context must NOT be retried.
        $this->assertFalse(self::call('is_transient_error', [new RuntimeException('configured network protocol error')]),
            'a logic-level network *protocol* error is not a transient connectivity issue');
        $this->assertFalse(self::call('is_transient_error', [new RuntimeException('statement timeout exceeded')]),
            'a query-level statement timeout is a config problem, not transient — retry will not help');
    }
}
