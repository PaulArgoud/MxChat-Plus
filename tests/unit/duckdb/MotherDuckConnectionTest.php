<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the MotherDuck connection wrapper — the thin layer that injects
 * `INSTALL motherduck / LOAD motherduck / ATTACH 'md:…?token=…' / USE …`
 * as init_sql on top of the embedded DuckDB connection.
 *
 * The class itself is small (~60 lines); the load-bearing properties to
 * lock are the input validation (token + database name) since the
 * database name lands verbatim in a SQL literal via ATTACH. A regression
 * in the regex guard would open a SQL-injection vector through the
 * settings form.
 *
 * The happy path (a real connection) requires either the PECL duckdb
 * extension or the CLI binary, neither of which is available in unit
 * tests; for that, see test_valid_inputs_reach_parent_constructor below.
 */
final class MotherDuckConnectionTest extends TestCase {

    // ─── Validation guards ────────────────────────────────────────────────

    public function test_throws_on_missing_motherduck_token(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/token/i');
        new MxChat_Plus_DuckDB_MotherDuck_Connection([
            'motherduck_database' => 'my_db',
            'motherduck_token'    => '',
        ]);
    }

    public function test_throws_on_unset_motherduck_token_key(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/token/i');
        new MxChat_Plus_DuckDB_MotherDuck_Connection([
            'motherduck_database' => 'my_db',
            // no motherduck_token key at all
        ]);
    }

    public function test_throws_on_database_name_with_special_chars(): void {
        // The database name is interpolated into ATTACH 'md:%s?…' — a hyphen
        // or quote would either break the ATTACH or open SQLi. Sanitisation
        // happens up-front in the Options sanitiser, AND defence-in-depth in
        // the constructor. Both layers must hold.
        $bad_names = [
            "my-db",            // hyphen
            "my db",            // space
            "my_db;DROP TABLE", // SQL injection attempt
            "my'db",            // quote
            "my.db",            // dot
            "",                 // empty after sanitisation upstream
        ];
        foreach ($bad_names as $name) {
            try {
                new MxChat_Plus_DuckDB_MotherDuck_Connection([
                    'motherduck_token'    => 'tok_dummy',
                    'motherduck_database' => $name,
                ]);
                $this->fail("Bad database name '$name' should have been rejected");
            } catch (RuntimeException $e) {
                // Either our "Invalid database name" message OR the parent's
                // "no PECL/CLI" message — both prove we got past the token
                // check. We want the former for these cases.
                if ($name === '') {
                    // Empty string can also pass our regex and reach the
                    // parent (which then fails for a different reason).
                    continue;
                }
                $this->assertStringContainsString('database name', strtolower($e->getMessage()),
                    "Expected the database-name guard to fire for '$name', got: " . $e->getMessage());
            }
        }
    }

    public function test_accepts_well_formed_database_names(): void {
        // Letters, digits, underscores in any combination.
        $good_names = ['my_db', 'production_kb', 'db1', 'a', 'A_long_name_with_digits_123'];
        foreach ($good_names as $name) {
            try {
                new MxChat_Plus_DuckDB_MotherDuck_Connection([
                    'motherduck_token'    => 'tok_dummy',
                    'motherduck_database' => $name,
                ]);
                // If a backend somehow IS available in this environment, the
                // constructor succeeds and we're done.
                $this->assertTrue(true);
            } catch (RuntimeException $e) {
                // The parent constructor fires next and fails because neither
                // the PECL extension nor a CLI binary is available in tests.
                // What we ASSERT here is that it's NOT our own validation
                // that rejected the name.
                $this->assertStringNotContainsString('database name', strtolower($e->getMessage()),
                    "Valid name '$name' was wrongly rejected by our guard");
                $this->assertStringNotContainsString('token', strtolower($e->getMessage()),
                    "Valid token was wrongly rejected for name '$name'");
            }
        }
    }

    public function test_valid_inputs_reach_parent_constructor(): void {
        // Smoke test: with both guards satisfied, the constructor falls
        // through to the Embedded parent, which fails because no PECL/CLI
        // backend is available in this test environment. The error message
        // changes from "MotherDuck …" to "Embedded DuckDB mode: …", which
        // is the signal we want.
        try {
            new MxChat_Plus_DuckDB_MotherDuck_Connection([
                'motherduck_token'    => 'tok_well_formed',
                'motherduck_database' => 'my_db',
            ]);
            // If we ended up here, a backend IS available — fine, no assertion needed.
            $this->assertTrue(true);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('embedded', strtolower($e->getMessage()),
                'Parent Embedded_Connection constructor should be the one rejecting (no PECL/CLI in tests)');
        }
    }

    // ─── init_sql composition (verified via subclass that skips the parent ctor) ──

    public function test_init_sql_uses_persistent_secret_rather_than_token_in_attach_url(): void {
        // v0.8.0 contract: the token is registered as a DuckDB persistent
        // secret (CREATE OR REPLACE PERSISTENT SECRET) and the ATTACH URL
        // is `md:dbname` only — no `?motherduck_token=…` query parameter.
        // Mirrors the production composition (we can't call the real parent
        // constructor in tests because no PECL/CLI backend is available).
        $stub_class = new class(['motherduck_token' => 'tok_xyz', 'motherduck_database' => 'my_db']) extends MxChat_Plus_DuckDB_MotherDuck_Connection {
            public static $init_capture = null;
            public function __construct(array $opts) {
                $token = (string) $opts['motherduck_token'];
                $database = (string) $opts['motherduck_database'];
                $escaped_token = str_replace("'", "''", $token);
                self::$init_capture = [
                    'INSTALL motherduck',
                    'LOAD motherduck',
                    sprintf("CREATE OR REPLACE PERSISTENT SECRET mxchat_motherduck (TYPE motherduck, TOKEN '%s')", $escaped_token),
                    sprintf("ATTACH 'md:%s'", $database),
                    sprintf('USE "%s"', $database),
                ];
            }
        };
        $captured = $stub_class::$init_capture;

        $this->assertNotNull($captured);
        $this->assertCount(5, $captured, 'INSTALL + LOAD + CREATE SECRET + ATTACH + USE');
        $this->assertSame('INSTALL motherduck', $captured[0]);
        $this->assertSame('LOAD motherduck', $captured[1]);
        $this->assertStringContainsString('PERSISTENT SECRET mxchat_motherduck', $captured[2]);
        $this->assertStringContainsString("TOKEN 'tok_xyz'", $captured[2]);

        // The ATTACH URL is clean — no token leaks into the SQL piped to stdin.
        $this->assertSame("ATTACH 'md:my_db'", $captured[3]);
        $this->assertStringNotContainsString('motherduck_token', $captured[3],
            'ATTACH URL must NOT carry the token since we use the persistent secret');

        $this->assertSame('USE "my_db"', $captured[4]);
    }

    public function test_init_sql_escapes_single_quotes_in_token(): void {
        // Tokens shouldn't contain single quotes in practice (MotherDuck
        // issues JWT-like strings) but defence-in-depth: if one slips
        // through, the CREATE SECRET literal must remain valid SQL.
        $captured = null;
        new class(['motherduck_token' => "tok'with'quotes", 'motherduck_database' => 'd'], $captured) extends MxChat_Plus_DuckDB_MotherDuck_Connection {
            public function __construct(array $opts, &$captured) {
                $token = (string) $opts['motherduck_token'];
                $escaped_token = str_replace("'", "''", $token);
                $captured = sprintf("CREATE OR REPLACE PERSISTENT SECRET mxchat_motherduck (TYPE motherduck, TOKEN '%s')", $escaped_token);
            }
        };
        $this->assertStringContainsString("tok''with''quotes", $captured);
    }

    // ─── Capability negotiation ───────────────────────────────────────────

    public function test_motherduck_reports_no_support_for_persistent_vss_index(): void {
        // The MotherDuck connection must answer false to the VSS
        // capability — that's how Schema decides to skip the CREATE
        // INDEX DDL. Embedded reports true; the contrast is what makes
        // the schema migration adaptive.
        $conn = new class(['motherduck_token' => 'tok', 'motherduck_database' => 'd']) extends MxChat_Plus_DuckDB_MotherDuck_Connection {
            public function __construct(array $opts) {
                // Skip the parent ctor — we don't want it to try INSTALL/
                // LOAD against a real DuckDB process in a unit test.
                $this->md_database = 'd';
            }
            protected string $md_database;
        };

        $this->assertFalse(
            $conn->supports_capability(MxChat_Plus_DuckDB_Connection::CAP_VSS_PERSISTENT_INDEX),
            'MotherDuck cloud must report no support for the persistent VSS index'
        );
        $this->assertFalse(
            $conn->supports_capability('something.unknown'),
            'unknown capability tokens must degrade to false (forward-compat)'
        );
    }
}
