<?php

use PHPUnit\Framework\TestCase;
use MxChat\DuckDB\PHPStan\UnsafeSqlConstructionRule;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PHPStan\Analyser\Scope;

/**
 * Unit test for the custom PHPStan rule that bans ad-hoc SQL string
 * construction outside the approved helper files.
 *
 * We don't need to spin up PHPStan's full pipeline — the rule's logic is
 * a pure function of (AST node, file path). We parse a snippet with
 * nikic/php-parser (already a transitive PHPStan dependency), build a
 * minimal Scope mock that just exposes the file path, and run each node
 * through the rule.
 */
final class UnsafeSqlConstructionRuleTest extends TestCase {

    private UnsafeSqlConstructionRule $rule;

    protected function setUp(): void {
        if (!class_exists(UnsafeSqlConstructionRule::class)) {
            require_once dirname(__DIR__, 3) . '/phpstan/UnsafeSqlConstructionRule.php';
        }
        if (!class_exists(ParserFactory::class)) {
            $this->markTestSkipped('nikic/php-parser not installed — run composer install with dev deps.');
        }
        $this->rule = new UnsafeSqlConstructionRule();
    }

    /**
     * @return list<string> error messages emitted by the rule across all
     *                     nodes in the snippet's AST.
     */
    private function run_on(string $php_code, string $fake_file_path = '/some/random/file.php'): array {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($php_code);
        $this->assertIsArray($ast);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $ast = $traverser->traverse($ast);

        $scope = $this->mockScopeWithFile($fake_file_path);
        $errors = [];
        $collect = function ($nodes) use (&$collect, $scope, &$errors) {
            foreach ($nodes as $node) {
                if (!$node instanceof \PhpParser\Node) continue;
                foreach ($this->rule->processNode($node, $scope) as $err) {
                    $errors[] = $err->getMessage();
                }
                foreach ($node->getSubNodeNames() as $name) {
                    $child = $node->{$name};
                    if (is_array($child)) $collect($child);
                    elseif ($child instanceof \PhpParser\Node) $collect([$child]);
                }
            }
        };
        $collect($ast);
        return $errors;
    }

    private function mockScopeWithFile(string $file): Scope {
        // Scope is an interface with a large surface — PHPUnit's mock builder
        // synthesises a class implementing it; we only need getFile() stubbed.
        $mock = $this->createMock(Scope::class);
        $mock->method('getFile')->willReturn($file);
        return $mock;
    }

    // ─── Should flag ──────────────────────────────────────────────────────

    public function test_flags_concat_with_variable_in_sql(): void {
        $code = '<?php
            class X { function y($conn, $user) {
                $sql = "SELECT * FROM t WHERE name = " . $user;
                $conn->execute($sql);
            } }';
        $errors = $this->run_on($code, '/plugin/includes/something-else.php');
        $this->assertNotEmpty($errors,
            'concat of a constant SQL prefix with a variable must be flagged outside helper files');
        $this->assertStringContainsString('concatenation', $errors[0]);
    }

    public function test_flags_sprintf_with_percent_s_variable(): void {
        $code = '<?php
            class X { function y($conn, $user) {
                $sql = sprintf("SELECT * FROM t WHERE name = %s", $user);
                $conn->execute($sql);
            } }';
        $errors = $this->run_on($code, '/plugin/includes/admin-handler.php');
        $this->assertNotEmpty($errors,
            'sprintf with %s + a variable substitution in SQL must be flagged outside helpers');
        $this->assertStringContainsString('sprintf', $errors[0]);
    }

    // ─── Should NOT flag ──────────────────────────────────────────────────

    public function test_does_not_flag_files_in_the_allowlist(): void {
        $code = '<?php $sql = "SELECT * FROM t WHERE x = " . $value;';
        $errors = $this->run_on($code, '/plugin/includes/class-duckdb-vector-store-query.php');
        $this->assertSame([], $errors,
            'concat inside Vector_Store_Query is legitimate — that file is on the allowlist');
    }

    public function test_does_not_flag_sprintf_with_only_numeric_substitutions(): void {
        $code = '<?php $sql = sprintf("SELECT * FROM t LIMIT %d OFFSET %d", $limit, $offset);';
        $errors = $this->run_on($code, '/plugin/includes/admin-something.php');
        $this->assertSame([], $errors,
            '%d substitutions cast at the SQL layer — safe even outside helpers');
    }

    public function test_does_not_flag_sprintf_with_percent_s_but_constant_argument(): void {
        $code = '<?php $sql = sprintf("SELECT * FROM t WHERE x = %s", "literal");';
        $errors = $this->run_on($code, '/plugin/includes/admin-something.php');
        $this->assertSame([], $errors,
            'constant string substitution into sprintf — no injection surface');
    }

    public function test_does_not_flag_non_sql_concat(): void {
        $code = '<?php $message = "hello " . $name;';
        $errors = $this->run_on($code, '/plugin/includes/admin-something.php');
        $this->assertSame([], $errors,
            'no SQL keywords in the format — the rule must stay out of innocuous concats');
    }

    public function test_does_not_flag_pure_constant_concat(): void {
        $code = '<?php $sql = "SELECT * FROM t" . " WHERE x = 1";';
        $errors = $this->run_on($code, '/plugin/includes/admin-something.php');
        $this->assertSame([], $errors,
            'constant + constant has no injection surface — fine outside helpers');
    }
}
