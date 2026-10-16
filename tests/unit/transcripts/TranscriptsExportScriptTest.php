<?php
/**
 * Runs the DOM tests of assets/js/admin-transcripts-export.js — the selection
 * mirror, "All", the toolbar button's warnings and the open-conversation
 * tracker — so that `composer test` reports them rather than letting them rot
 * unseen. They live in tests/unit/transcripts/js/ and run in jsdom, against a
 * stand-in for MxChat's own script (host-fixture.js).
 *
 * Skipped, with the reason, when Node or the two npm packages are missing.
 * To enable them:
 *
 *     npm install --prefix tests/unit/transcripts/js
 *
 * Environment, passed through to Node:
 *   MXCHAT_PLUS_NODE        the node binary (default: node, from PATH)
 *   MXCHAT_PLUS_JS_MODULES  a node_modules directory holding jsdom and jquery
 *   MXCHAT_PLUS_JQUERY      a jquery.js to load instead, e.g. WordPress's own
 *   MXCHAT_HOST_JS          the real mxchat-basic/js/mxchat_transcripts.js, to
 *                           run every test against it as well
 */

use PHPUnit\Framework\TestCase;

final class TranscriptsExportScriptTest extends TestCase {

    private const DIR = __DIR__ . '/js';

    public function test_the_export_script_passes_its_dom_tests(): void {
        $modules = self::env('MXCHAT_PLUS_JS_MODULES') ?? self::DIR . '/node_modules';
        $jquery  = self::env('MXCHAT_PLUS_JQUERY') ?? $modules . '/jquery/dist/jquery.js';

        if (!is_file($modules . '/jsdom/package.json') || !is_file($jquery)) {
            $this->markTestSkipped(
                'jsdom and jquery are not installed; run `npm install --prefix tests/unit/transcripts/js` '
                . 'to enable the DOM tests of admin-transcripts-export.js.'
            );
        }
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is disabled, so Node cannot be started.');
        }

        $node = self::env('MXCHAT_PLUS_NODE') ?? 'node';
        [$code] = self::exec_command([$node, '--version']);
        if ($code !== 0) {
            $this->markTestSkipped('Node.js was not found (set MXCHAT_PLUS_NODE to its path).');
        }

        [$code, $output] = self::exec_command([$node, '--test', self::DIR . '/export-selection.test.js']);

        $this->assertSame(0, $code, "The DOM tests of admin-transcripts-export.js failed:\n" . $output);
    }

    private static function env(string $name): ?string {
        $value = getenv($name);
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Runs a command without a shell; the exit code (-1 when it could not be
     * started) and its combined output.
     *
     * @param list<string> $command
     * @return array{0:int,1:string}
     */
    private static function exec_command(array $command): array {
        $pipes   = [];
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (!is_resource($process)) {
            return [-1, ''];
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), is_string($output) ? $output : ''];
    }
}
