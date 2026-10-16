<?php
/**
 * Where the host cache-purge filter is registered — not what it returns, which
 * TrackingOptionsTest covers.
 *
 * MxChat applies `mxchat_cache_purge_watched_options` exactly once, in the
 * constructor of MxChat_Cache_Purge, which mxchat_init() runs on
 * plugins_loaded at priority 10 (MxChat 3.2.25). Our boot() runs at 20. So the
 * add_filter() call only works at FILE SCOPE in mxchat-plus.php, which
 * WordPress includes before any plugins_loaded callback runs. Moved into
 * boot(), into the tracking module's register_hooks(), behind an is_admin(),
 * the callback still exists and every unit test of it still passes — but the
 * host has already built its watch list, so saving the tracking settings or
 * switching the module off stops purging WP Rocket / LiteSpeed, and cached
 * pages keep the old settings (session id included) with no error anywhere.
 *
 * The main file is read as tokens, not included: mxchat-plus.php calls
 * define() without guards, which would collide with the bootstrap's constants
 * under failOnWarning, and the add_filter() shim records nothing anyway.
 */

use PHPUnit\Framework\TestCase;

final class TrackingCachePurgeRegistrationTest extends TestCase {

    /** A host literal: MxChat_Cache_Purge::watched_options() applies it. Never rename. */
    private const HOOK = 'mxchat_cache_purge_watched_options';

    public function test_the_filter_is_registered_once_at_file_scope_in_the_main_file(): void {
        $code = (string) file_get_contents(MXCHAT_PLUS_FILE);
        $this->assertNotSame('', $code, 'mxchat-plus.php could not be read');

        $calls = self::registrations($code);

        $this->assertCount(1, $calls, 'exactly one add_filter(\'' . self::HOOK . '\', …) in mxchat-plus.php');
        $this->assertSame(
            0,
            $calls[0]['depth'],
            'inside a class, function, closure or braced block: it would run after MxChat read the filter'
        );
        $this->assertTrue(
            $calls[0]['statement'],
            'not a plain statement (brace-less if/else, assignment…): it may not run at all'
        );
        $this->assertStringContainsString("'MxChat_Plus_Tracking_Options'", $calls[0]['call']);
        $this->assertStringContainsString("'add_host_cache_purge_watch'", $calls[0]['call']);
    }

    public function test_the_registered_callback_exists(): void {
        // WordPress resolves the callback only when the host applies the
        // filter; a renamed method would then be skipped, silently.
        $this->assertTrue(is_callable(['MxChat_Plus_Tracking_Options', 'add_host_cache_purge_watch']));
    }

    public function test_nothing_under_includes_or_admin_registers_it_again(): void {
        // A second registration from a module would look right in review and
        // do nothing: by the time any of our modules boot, the host has read
        // the filter.
        $offenders = [];
        foreach (['includes', 'admin'] as $dir) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(MXCHAT_PLUS_DIR . $dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                if (self::registrations((string) file_get_contents($file->getPathname())) !== []) {
                    $offenders[] = $dir . '/' . substr($file->getPathname(), strlen(MXCHAT_PLUS_DIR . $dir) + 1);
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * The detector itself: every placement that would run too late, or not at
     * all, must read as such. Without this, a bug in registrations() could
     * keep the first test green whatever mxchat-plus.php does.
     *
     * @dataProvider latePlacements
     */
    public function test_the_detector_rejects_a_late_or_conditional_placement(string $code): void {
        $calls = self::registrations($code);

        $this->assertCount(1, $calls, 'the call itself is still found');
        $this->assertFalse(
            $calls[0]['depth'] === 0 && $calls[0]['statement'],
            'reported as a file-scope statement'
        );
    }

    /** @return array<string,array{0:string}> */
    public static function latePlacements(): array {
        $call = "add_filter('" . self::HOOK . "', ['MxChat_Plus_Tracking_Options', 'add_host_cache_purge_watch']);";
        return [
            'in boot()'          => ["<?php\nclass MxChat_Plus_Plugin {\n    public static function boot(): void {\n        $call\n    }\n}\n"],
            'in a function'      => ["<?php\nfunction mxchat_plus_late(): void {\n    $call\n}\n"],
            'in a closure'       => ["<?php\nadd_action('plugins_loaded', function () {\n    $call\n}, 20);\n"],
            'in a braced if'     => ["<?php\nif (is_admin()) {\n    $call\n}\n"],
            'in a brace-less if' => ["<?php\nif (is_admin()) $call\n"],
            'after else'         => ["<?php\nif (wp_doing_cron()) {\n} else $call\n"],
            'in a string body'   => ["<?php\n\$x = \"{\$y}\";\nfunction late(): void {\n    $call\n}\n"],
        ];
    }

    public function test_the_detector_accepts_the_shape_in_the_main_file(): void {
        $calls = self::registrations(
            "<?php\nif (!defined('ABSPATH')) {\n    exit;\n}\n"
            . "add_action('plugins_loaded', ['MxChat_Plus_Plugin', 'boot'], 20);\n"
            . "// add_filter('" . self::HOOK . "', 'commented_out');\n"
            . "add_filter('" . self::HOOK . "', ['MxChat_Plus_Tracking_Options', 'add_host_cache_purge_watch']);\n"
            . "class MxChat_Plus_Plugin {}\n"
        );

        // The commented-out call is not code and is not counted.
        $this->assertCount(1, $calls);
        $this->assertSame(0, $calls[0]['depth']);
        $this->assertTrue($calls[0]['statement']);
    }

    /**
     * Every `add_filter('mxchat_cache_purge_watched_options', …)` call in $code,
     * with its brace depth (0 = outside every class, function, closure and
     * braced block), whether it opens a statement of its own, and the call's
     * code with whitespace and comments removed.
     *
     * @return list<array{depth:int,statement:bool,call:string}>
     */
    private static function registrations(string $code): array {
        $tokens = token_get_all($code);
        $count  = count($tokens);
        $depth  = 0;
        $found  = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                }
                continue;
            }

            // "{$x}" and "${x}" inside strings close on a plain '}'.
            if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
                continue;
            }

            if (!in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                || strtolower(ltrim($token[1], '\\')) !== 'add_filter') {
                continue;
            }

            $open = self::significant($tokens, $i + 1, 1);
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }
            $first = self::significant($tokens, $open + 1, 1);
            if ($first === null || !is_array($tokens[$first])
                || $tokens[$first][0] !== T_CONSTANT_ENCAPSED_STRING
                || substr($tokens[$first][1], 1, -1) !== self::HOOK) {
                continue;
            }

            // A statement of its own: what comes before is the end of another
            // statement, a closed block, or the opening tag.
            $before    = self::significant($tokens, $i - 1, -1);
            $statement = $before === null
                || in_array($tokens[$before], [';', '}'], true)
                || (is_array($tokens[$before]) && $tokens[$before][0] === T_OPEN_TAG);

            $call  = '';
            $paren = 0;
            for ($j = $open; $j < $count; $j++) {
                $t = $tokens[$j];
                if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $text  = is_string($t) ? $t : $t[1];
                $call .= $text;
                if ($text === '(') {
                    $paren++;
                } elseif ($text === ')' && --$paren === 0) {
                    break;
                }
            }

            $found[] = ['depth' => $depth, 'statement' => $statement, 'call' => $call];
        }

        return $found;
    }

    /**
     * Index of the nearest token from $from in direction $step (1 or -1) that
     * is neither whitespace nor a comment, or null.
     *
     * @param array<int,string|array{0:int,1:string,2:int}> $tokens
     */
    private static function significant(array $tokens, int $from, int $step): ?int {
        for ($k = $from; $k >= 0 && $k < count($tokens); $k += $step) {
            $t = $tokens[$k];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $k;
        }
        return null;
    }
}
