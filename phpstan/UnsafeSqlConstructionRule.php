<?php declare(strict_types=1);

namespace MxChat\DuckDB\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbid ad-hoc SQL construction outside the approved helper sites.
 *
 * What gets flagged
 * -----------------
 *   $sql = "SELECT * FROM x WHERE y = " . $value;            // Concat with SQL kw
 *   $sql = sprintf('SELECT … WHERE y = %s', $value);          // sprintf with SQL kw + %s
 *
 * What doesn't
 * ------------
 *   - sprintf() where ALL substitutions are %d / %f (numeric — DuckDB-safe).
 *   - sprintf() where the substituted expressions are literal int/float
 *     constants (table over-fetch, LIMIT, etc.).
 *   - Anything inside the allow-listed files: the shared SQL helpers
 *     (trait) and the Vector_Store schema/query/orchestrator classes are
 *     where SQL is legitimately assembled. Filter values inside those
 *     files already round-trip through the helpers (literal_string /
 *     literal_for / literal_int_or_float_array).
 *
 * Why this rule exists
 * --------------------
 * Every SQL-injection-class bug in this plugin would necessarily begin
 * with a ad-hoc `'… ' . $user_value` outside the helper trait. Locking
 * the construction site to a small audit-able set means a regression
 * gets caught at `composer stan` time instead of in production.
 *
 * The rule is intentionally narrow (high precision, may miss exotic
 * patterns) so it never blocks innocuous code while still catching the
 * single pattern that matters.
 *
 * @implements Rule<Node>
 */
final class UnsafeSqlConstructionRule implements Rule {

    /** @var string[] basename suffixes whose files are allowed to build SQL */
    private const ALLOWED_FILE_SUFFIXES = [
        'trait-duckdb-sql-helpers.php',
        'class-duckdb-vector-store.php',
        'class-duckdb-vector-store-query.php',
        'class-duckdb-vector-store-schema.php',
        'class-duckdb-embedded-connection.php',
        'class-duckdb-motherduck-connection.php',
        'class-duckdb-mysql-sync.php',
        'class-duckdb-compactor.php',
        'class-duckdb-pinecone-migrator.php',
    ];

    /**
     * Require a multi-word SQL phrase rather than a bare keyword. Bare
     * `DELETE` / `FROM` show up in unrelated strings (URL paths like
     * `/vectors/delete`, user-facing copy like "imported %d rows from %s"),
     * so the pattern only fires on the actual SQL shapes the rule cares
     * about: statement keywords paired with their typical operand.
     */
    private const SQL_KEYWORDS_PATTERN = '/(?:'
        . 'SELECT\s+.{0,200}?\s+FROM'                       // SELECT … FROM
        . '|INSERT(?:\s+OR\s+REPLACE)?\s+INTO'              // INSERT [OR REPLACE] INTO
        . '|UPDATE\s+\S+\s+SET'                             // UPDATE x SET
        . '|DELETE\s+FROM'                                  // DELETE FROM
        . '|CREATE\s+(?:TABLE|INDEX|VIEW|TEMP\s+VIEW|OR\s+REPLACE)'  // CREATE TABLE/INDEX/VIEW
        . '|ALTER\s+TABLE'                                  // ALTER TABLE
        . '|DROP\s+(?:TABLE|INDEX|VIEW)'                    // DROP …
        . '|COPY\s+\S+\s+TO'                                // COPY x TO
        . '|TRUNCATE\s+TABLE'                               // TRUNCATE TABLE
        . '|PRAGMA\s+\w+'                                   // PRAGMA … (DuckDB-specific)
        . '|WITH\s+\w+\s+AS\s*\('                           // CTE
        . ')/is';

    public function getNodeType(): string {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array {
        if (self::file_is_allowed($scope->getFile())) {
            return [];
        }

        if ($node instanceof Concat) {
            return $this->check_concat($node);
        }
        if ($node instanceof FuncCall && $node->name instanceof Name
            && strtolower($node->name->toString()) === 'sprintf') {
            return $this->check_sprintf($node);
        }
        return [];
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    private function check_concat(Concat $node): array {
        // Find a string operand that contains SQL keywords; if none, this
        // concat has nothing to do with SQL and we skip it.
        $left  = self::extract_string($node->left);
        $right = self::extract_string($node->right);
        $sql_text = ($left ?? '') . ' ' . ($right ?? '');
        if (!preg_match(self::SQL_KEYWORDS_PATTERN, $sql_text)) {
            return [];
        }
        // String + string is constant — fine. The danger is string + variable.
        if ($left !== null && $right !== null) {
            return [];
        }
        return [
            RuleErrorBuilder::message(
                'SQL string built by `.` concatenation outside the approved helper files. '
                . 'Move the construction into trait-duckdb-sql-helpers (or one of the Vector_Store classes), '
                . 'or pass the value via the `?`-parameter path of MxChat_Plus_DuckDB_Connection::execute().'
            )->identifier('mxchat.unsafeSqlConcat')->build(),
        ];
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    private function check_sprintf(FuncCall $node): array {
        $args = $node->getArgs();
        if (count($args) < 2) return [];
        $first = $args[0]->value;
        if (!$first instanceof String_) return [];          // dynamic format string — rare; skip
        $format = $first->value;
        if (!preg_match(self::SQL_KEYWORDS_PATTERN, $format)) return [];

        // Find each substitution token (%s, %d, …) in the format and pair
        // it with the corresponding argument. Only %s with a non-constant
        // argument is dangerous — %d / %f / %x cast at the SQL layer.
        preg_match_all('/%(?:(\d+)\$)?([sdfxob])/i', $format, $matches, PREG_OFFSET_CAPTURE);
        if (empty($matches[0])) return [];

        $any_unsafe = false;
        $arg_cursor = 1; // sprintf is 1-indexed once you skip the format
        foreach ($matches[2] as $i => $token) {
            $kind = strtolower($token[0]);
            $explicit_index = $matches[1][$i][0] !== '' ? (int) $matches[1][$i][0] : null;
            $arg_index = $explicit_index !== null ? $explicit_index : $arg_cursor++;
            if ($kind !== 's') continue;                       // numeric tokens are safe
            $arg = $args[$arg_index] ?? null;
            if ($arg === null) continue;                       // sprintf will substitute empty string
            if (self::is_constant_literal($arg->value)) continue;
            $any_unsafe = true;
            break;
        }

        if (!$any_unsafe) return [];

        return [
            RuleErrorBuilder::message(
                'sprintf() builds an SQL string with a `%s` substituted from a non-constant value outside the approved helper files. '
                . 'Use `?`-placeholders + the params array on MxChat_Plus_DuckDB_Connection::execute(), or run the value through the literal_* helpers in trait-duckdb-sql-helpers.'
            )->identifier('mxchat.unsafeSqlSprintf')->build(),
        ];
    }

    private static function extract_string(Node $expr): ?string {
        return $expr instanceof String_ ? $expr->value : null;
    }

    private static function is_constant_literal(Node $expr): bool {
        return $expr instanceof Node\Scalar\String_
            || $expr instanceof Node\Scalar\LNumber
            || $expr instanceof Node\Scalar\DNumber;
    }

    private static function file_is_allowed(?string $file): bool {
        if ($file === null) return true;
        $base = basename($file);
        foreach (self::ALLOWED_FILE_SUFFIXES as $allowed) {
            if ($base === $allowed) return true;
        }
        return false;
    }
}
