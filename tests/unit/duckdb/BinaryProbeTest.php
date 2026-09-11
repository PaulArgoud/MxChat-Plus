<?php

use PHPUnit\Framework\TestCase;

/**
 * Sanity-checks the DuckDB CLI probe used by the options sanitiser. The probe
 * must reject anything that doesn't speak the DuckDB `-json` dialect — that's
 * the protection against an admin pasting the wrong binary path in settings
 * and only discovering it through cryptic query failures later.
 *
 * Skipped when running on a host without the necessary POSIX shell binaries
 * (looking at you, Windows CI).
 */
final class BinaryProbeTest extends TestCase {

    protected function setUp(): void {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('POSIX-only probe smoke test.');
        }
    }

    public function test_empty_path_returns_false(): void {
        $this->assertFalse(MxChat_Plus_DuckDB_Embedded_Connection::looks_like_duckdb_binary(''));
    }

    public function test_non_existent_path_returns_false(): void {
        $this->assertFalse(MxChat_Plus_DuckDB_Embedded_Connection::looks_like_duckdb_binary(
            '/this/path/does/not/exist/duckdb_' . bin2hex(random_bytes(4))
        ));
    }

    public function test_non_duckdb_binary_returns_false(): void {
        // /bin/sh is executable, accepts stdin, but ignores -json and does not
        // emit the probe marker. The probe must say "nope" within its 2s cap.
        $candidates = ['/bin/sh', '/bin/cat', '/usr/bin/true'];
        $available = null;
        foreach ($candidates as $c) {
            if (is_executable($c)) { $available = $c; break; }
        }
        if ($available === null) {
            $this->markTestSkipped('no POSIX probe target available');
        }
        $this->assertFalse(MxChat_Plus_DuckDB_Embedded_Connection::looks_like_duckdb_binary($available));
    }
}
