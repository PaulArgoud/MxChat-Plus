<?php
/**
 * Embedded DuckDB connection.
 *
 * Strategy:
 *   1. If the PECL `duckdb` extension is loaded, use the native C bindings.
 *   2. Otherwise, fall back to invoking the `duckdb` CLI via proc_open with
 *      -json output. The CLI must be present on the server (PATH or configured).
 *
 * Optional `init_sql` (passed by subclasses, e.g. MotherDuck via ATTACH) is
 * executed once at connect time for the extension path, and prepended to each
 * CLI invocation (since CLI sessions are stateless).
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Embedded_Connection implements MxChat_Plus_DuckDB_Connection {

    protected string $db_path;
    protected string $binary_path = '';
    protected bool $use_extension = false;
    /** @var object|null Native DuckDB handle if PECL extension is available. */
    protected $native = null;
    /** @var string[] SQL statements to run once at connect (ext) or prepend per-query (CLI). */
    protected array $init_sql = [];

    /**
     * Capability flags for the prepared-statement path on the native extension.
     * Probed lazily on first call to execute() with non-empty params, and then
     * frozen for the lifetime of this connection. `$prepare_method_name` holds
     * the actual method to call (different bindings have used `preparedStatement`,
     * `prepare`, …). Empty string means "no usable prepared API found, keep
     * inlining literals."
     */
    protected ?bool $native_prepared_supported = null;
    protected string $prepare_method_name = '';

    /**
     * @param array    $opts      Plugin options. Optional 'db_path' overrides resolved path
     *                            (used by MotherDuck wrapper to request ':memory:').
     * @param string[] $init_sql  Statements executed at connect time / prepended to each CLI call.
     */
    public function __construct(array $opts, array $init_sql = []) {
        $this->db_path = !empty($opts['db_path'])
            ? (string) $opts['db_path']
            : MxChat_Plus_DuckDB_Options::resolved_embedded_path();
        $this->init_sql = $init_sql;

        // Prefer the PECL extension if loaded — much faster than CLI roundtrips.
        if (extension_loaded('duckdb') && class_exists('DuckDB\\Connection')) {
            try {
                $cls = 'DuckDB\\Connection';
                $this->native = new $cls($this->db_path);
                $this->use_extension = true;
                foreach ($this->init_sql as $stmt) {
                    $this->native->query($stmt);
                }
                return;
            } catch (\Throwable $e) {
                $this->use_extension = false;
                $this->native = null;
            }
        }

        $bin = !empty($opts['embedded_binary']) ? $opts['embedded_binary'] : self::autodetect_binary();
        if (empty($bin) || !is_executable($bin)) {
            throw new RuntimeException(
                __('Embedded DuckDB mode: neither the PHP duckdb extension nor the CLI binary is available. Install ext-duckdb (PECL) or set the binary path in the plugin settings.', 'mxchat-plus')
            );
        }
        $this->binary_path = $bin;
    }

    public function execute(string $sql, array $params = []): array {
        // Prepared-statement fast path: only meaningful on the native extension
        // when the caller actually has parameters to bind. The CLI path can't
        // do prepared statements (sessions are stateless), and an inline-only
        // SQL string (params=[]) gains nothing from the round-trip. We also
        // gate on a one-shot capability probe so older bindings without a
        // usable prepared API fall back to the inline path transparently.
        if (!empty($params) && $this->use_extension && $this->native
            && $this->probe_prepared_support()) {
            try {
                return $this->execute_native_prepared($sql, $params);
            } catch (\Throwable $e) {
                // Distinguish: did the prepared API itself blow up (binding
                // surface mismatch, FFI error), or did DuckDB reject the SQL?
                // The latter would also fail in the inline path, so we don't
                // mask it; the former should disable prepared for this
                // connection and let the inline path try again.
                if (self::looks_like_binding_failure($e)) {
                    $this->native_prepared_supported = false;
                    $this->prepare_method_name = '';
                    // Fall through to the inline path so the caller still
                    // gets their query executed.
                } else {
                    throw $e;
                }
            }
        }

        $bound_sql = self::inline_params($sql, $params);
        return $this->execute_with_retry($bound_sql, $sql);
    }

    public function supports_prepared(): bool {
        return $this->use_extension && $this->probe_prepared_support();
    }

    /**
     * Wraps the raw execute with a small retry loop for transient errors
     * (network blips on MotherDuck, momentary lock contention, …). Idempotent
     * SQL only (SELECT-ish). Write SQL is detected via a simple keyword sniff
     * and runs without retry to avoid double-applying.
     */
    private function execute_with_retry(string $bound_sql, string $original_sql): array {
        $max_attempts = self::looks_idempotent($original_sql)
            ? (int) apply_filters('mxchat_plus_duckdb_max_retries', 3)
            : 1;

        $attempt = 0;
        $last_error = null;
        while ($attempt < $max_attempts) {
            $attempt++;
            try {
                if ($this->use_extension && $this->native) {
                    return $this->execute_native($bound_sql);
                }
                return $this->execute_cli($bound_sql);
            } catch (\Throwable $e) {
                $last_error = $e;
                if ($attempt >= $max_attempts || !self::is_transient_error($e)) {
                    throw $e;
                }
                // Exponential backoff with jitter: 50ms, 150ms, 350ms.
                $base_us = (int) (50000 * (1 << ($attempt - 1)));
                $jitter_us = random_int(0, 50000);
                usleep($base_us + $jitter_us);
                MxChat_Plus_DuckDB_Metrics::record('retries');
            }
        }
        throw $last_error ?? new RuntimeException('execute_with_retry: exhausted attempts');
    }

    /**
     * One-shot capability probe for the native extension's prepared-statement
     * API. Different DuckDB PHP bindings have used different method names
     * (saturio/duckdb-php exposes `preparedStatement()`, PDO-style bindings
     * use `prepare()`). We try them in order; the first one that lets us
     * round-trip `SELECT ?` with a single bound int wins. The verdict is
     * frozen on the instance, so callers pay this cost at most once per
     * connection lifetime.
     */
    protected function probe_prepared_support(): bool {
        if ($this->native_prepared_supported !== null) {
            return $this->native_prepared_supported;
        }
        if (!$this->native) {
            $this->native_prepared_supported = false;
            return false;
        }
        // Allow a hard opt-out without recompiling — useful when a site owner
        // sees binding-related errors and wants to force the inline path.
        if (!apply_filters('mxchat_plus_duckdb_use_prepared_statements', true)) {
            $this->native_prepared_supported = false;
            return false;
        }

        foreach (['preparedStatement', 'prepare'] as $method) {
            if (!method_exists($this->native, $method)) continue;
            try {
                $stmt = $this->native->{$method}('SELECT ? AS ok');
                if (!is_object($stmt) || !method_exists($stmt, 'execute')) {
                    continue;
                }
                if (method_exists($stmt, 'bindParam')) {
                    // saturio/duckdb-php: 1-based, named arg in newer versions
                    // but positional call also works for the older ones.
                    $stmt->bindParam(1, 1);
                } elseif (method_exists($stmt, 'bind')) {
                    $stmt->bind(1, 1);
                } else {
                    continue;
                }
                $result = $stmt->execute();
                $rows = self::result_to_rows($result);
                if (!empty($rows) && (int) ($rows[0]['ok'] ?? 0) === 1) {
                    $this->native_prepared_supported = true;
                    $this->prepare_method_name = $method;
                    return true;
                }
            } catch (\Throwable $e) {
                // Method exists but doesn't behave as expected — try the next one.
            }
        }
        $this->native_prepared_supported = false;
        return false;
    }

    /**
     * Execute SQL with ? placeholders against the native extension's prepared
     * statement API. Array params (embedding vectors) get inlined as DuckDB
     * list literals *into the SQL string* before the prepare call — vector-
     * shaped placeholders aren't reliably supported across binding versions,
     * and the inlining is the same path as before. Scalars + strings get
     * bound through the API where they get the parameter-typing benefits.
     */
    protected function execute_native_prepared(string $sql, array $params): array {
        [$rewritten_sql, $bind_params] = self::split_array_params($sql, $params);

        $stmt = $this->native->{$this->prepare_method_name}($rewritten_sql);
        $bind = method_exists($stmt, 'bindParam') ? 'bindParam' : 'bind';
        foreach ($bind_params as $i => $val) {
            $stmt->{$bind}($i + 1, $val);
        }
        $result = $stmt->execute();
        return self::result_to_rows($result);
    }

    /**
     * Pre-process the param list: array-shaped params (vector embeddings) are
     * inlined as DuckDB list literals into the SQL because the binding APIs
     * don't reliably accept FLOAT[]. Scalars get returned in a re-indexed
     * list to be bound via bindParam.
     *
     * @return array{0:string,1:array<int,scalar|null>}
     */
    private static function split_array_params(string $sql, array $params): array {
        $bind = [];
        $i = 0;
        $rewritten = preg_replace_callback('/\?/', function () use (&$i, $params, &$bind) {
            if (!array_key_exists($i, $params)) {
                $i++;
                return 'NULL';
            }
            $val = $params[$i++];
            if (is_array($val)) {
                return self::to_literal($val);
            }
            $bind[] = $val;
            return '?';
        }, $sql);
        return [$rewritten, $bind];
    }

    /**
     * Normalize a result returned by the native extension (either `query()`
     * or a prepared statement's `execute()`) into an array of associative-
     * array rows.
     */
    private static function result_to_rows($result): array {
        $rows = [];
        if (is_iterable($result)) {
            foreach ($result as $row) {
                $rows[] = (array) $row;
            }
            return $rows;
        }
        if (is_object($result)) {
            // Some bindings expose a fetchAll() or rows() materialiser.
            foreach (['rows', 'fetchAll'] as $m) {
                if (method_exists($result, $m)) {
                    $maybe = $result->{$m}();
                    if (is_iterable($maybe)) {
                        foreach ($maybe as $row) $rows[] = (array) $row;
                        return $rows;
                    }
                }
            }
        }
        return $rows;
    }

    /**
     * Heuristic: distinguish "the binding API itself misbehaved" (recoverable
     * by falling back to inline) from "DuckDB rejected the SQL" (not
     * recoverable — caller needs to see the error). False positives here are
     * fine: we'd just fall back to inline, where the same SQL error surfaces
     * again. False negatives (treating a real query error as a binding error)
     * would mask it on the first call but the inline path re-runs the SQL
     * and re-throws.
     */
    private static function looks_like_binding_failure(\Throwable $e): bool {
        $msg = strtolower($e->getMessage());
        $needles = [
            'bind',
            'parameter',
            'prepared statement',
            'unsupported type',
            'ffi',
            'method',  // "Call to undefined method …::bindParam()"
        ];
        foreach ($needles as $n) {
            if (str_contains($msg, $n)) return true;
        }
        return $e instanceof \Error; // TypeError / ArgumentCountError on the API itself
    }

    private function execute_native(string $sql): array {
        try {
            return self::result_to_rows($this->native->query($sql));
        } catch (\Throwable $e) {
            throw new RuntimeException(
                __('DuckDB native extension error: ', 'mxchat-plus') . $e->getMessage()
            );
        }
    }

    private static function looks_idempotent(string $sql): bool {
        // Cheap regex against the first keyword. False negatives (we don't
        // retry something that's actually safe) are fine; false positives
        // (we retry an INSERT) are not — so we err conservative.
        $first = strtoupper(ltrim($sql));
        return str_starts_with($first, 'SELECT')
            || str_starts_with($first, 'WITH')
            || str_starts_with($first, 'PRAGMA')
            || str_starts_with($first, 'SHOW')
            || str_starts_with($first, 'DESCRIBE')
            || str_starts_with($first, 'EXPLAIN');
    }

    /**
     * Decide whether an exception is worth retrying. Three signals, in
     * precedence order:
     *
     *   1. Exception class — known transient-class names from various
     *      DuckDB / HTTP / PDO bindings. instanceof checks let us pin to
     *      a type rather than a substring, which is locale- and version-
     *      stable.
     *   2. HTTP-shaped status code on getCode() — 502/503/504/429 are
     *      always transient regardless of the wording on the message.
     *   3. Substring fallback — kept for bindings that flatten everything
     *      to RuntimeException("…"). Anchored on multi-word phrases so a
     *      benign message containing "network protocol error" doesn't
     *      mis-classify a logic error as retryable.
     *
     * False positives here are the dangerous direction (we'd retry an
     * INSERT that may have already landed on the remote), so we err
     * "don't retry". The caller's `looks_idempotent()` guard already
     * blocks retries on non-SELECT anyway, but defense in depth.
     */
    private static function is_transient_error(\Throwable $e): bool {
        // Signal 1: exception type. Different DuckDB PHP bindings + curl
        // wrappers expose different transient hierarchies; we match the
        // ones documented at the time of writing.
        //
        // Build $hierarchy = {class + all ancestors} once and check
        // membership against the known-transient class names. The previous
        // implementation used `is_a($e, $cls)` (string-class form), which
        // forced PHPStan to short-circuit both branches to "always false"
        // because the DuckDB/Saturio extension types aren't in any stub
        // package — the dedicated ignoreErrors entry has been removed too.
        $hierarchy = class_parents($e) ?: [];
        $hierarchy[get_class($e)] = get_class($e);

        foreach (self::TRANSIENT_EXCEPTION_CLASSES as $cls) {
            if (isset($hierarchy[$cls])) return true;
        }
        // PDOException needs SQLSTATE-aware filtering — only 08xxx
        // (connection exception) and 40001 (serialization failure) are
        // worth retrying; integrity errors (23000), syntax errors, etc.
        // are caller bugs that retries can't fix.
        if (isset($hierarchy['PDOException'])) {
            $sqlstate = self::pdo_sqlstate($e);
            if ($sqlstate !== '' && (str_starts_with($sqlstate, '08') || $sqlstate === '40001')) {
                return true;
            }
        }

        // Signal 2: HTTP-shaped status code on getCode(). PECL bindings
        // that wrap an HTTP transport often surface the upstream status
        // here; 5xx + 429 are retryable.
        $code = $e->getCode();
        if (is_int($code) && ($code === 429 || ($code >= 500 && $code <= 599))) {
            return true;
        }

        // Signal 3: substring fallback. Multi-word anchors only — bare
        // "network" or "timeout" can appear in user-facing copy and
        // would mis-classify a logic error as transient.
        $msg = strtolower($e->getMessage());
        foreach (self::TRANSIENT_MESSAGE_ANCHORS as $phrase) {
            if (str_contains($msg, $phrase)) return true;
        }
        return false;
    }

    /**
     * Exception class names (across the DuckDB / Saturio bindings) that are
     * always worth retrying. Hoisted to a constant so the hierarchy check in
     * is_transient_error() doesn't re-allocate the list on every error.
     */
    private const TRANSIENT_EXCEPTION_CLASSES = [
        'DuckDB\\Exception\\NetworkException',
        'DuckDB\\Exception\\TimeoutException',
        'Saturio\\DuckDB\\Exception\\ConnectionException',
    ];

    /**
     * Multi-word message anchors for the substring fallback in
     * is_transient_error(). Word-anchored on purpose: a bare "network" or
     * "timeout" appears in plenty of non-transient (logic/config) errors.
     */
    private const TRANSIENT_MESSAGE_ANCHORS = [
        'connection reset',
        'connection refused',
        'connection timed out',
        'could not connect',
        'broken pipe',
        'tls handshake',
        'rate limit',
        'service unavailable',     // 503 in words
        'bad gateway',             // 502 in words
        'gateway timeout',         // 504 in words
        'temporarily unavailable',
        'try again later',
        'eof from server',
        'read timeout',            // network-side read deadline
        'request timeout',         // HTTP request timed out upstream
        'host unreachable',
        'network is unreachable',
    ];

    /**
     * PDOException's SQLSTATE lives at errorInfo[0]. The exception may
     * not have been initialised by PDO (someone re-threw it), so we
     * tolerate the field being absent or empty.
     */
    private static function pdo_sqlstate(\Throwable $e): string {
        if (!property_exists($e, 'errorInfo')) return '';
        $info = $e->errorInfo ?? null;
        if (!is_array($info) || !isset($info[0])) return '';
        return (string) $info[0];
    }

    public function ping(): bool {
        try {
            $rows = $this->execute('SELECT 1 AS ok');
            return !empty($rows) && (int) ($rows[0]['ok'] ?? 0) === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function identifier(): string {
        return 'embedded:' . $this->db_path . ($this->use_extension ? ' (ext)' : ' (cli)');
    }

    /**
     * Local DuckDB supports the full DuckDB feature set, including the
     * VSS extension. MotherDuck overrides this for capabilities that
     * don't hold cloud-side.
     */
    public function supports_capability(string $capability): bool {
        switch ($capability) {
            case self::CAP_VSS_PERSISTENT_INDEX:
                return true;
            default:
                // Forward-compat: unknown tokens degrade to "not supported".
                return false;
        }
    }

    /**
     * Invokes the DuckDB CLI with -json output. Init SQL (if any) is prepended
     * to the script piped through stdin, because CLI sessions are stateless.
     *
     * Uses non-blocking pipes + stream_select with a deadline so that a hung
     * CLI (file lock, runaway query, broken pipe) doesn't freeze the PHP-FPM
     * worker indefinitely. The timeout is overridable via the
     * `mxchat_plus_duckdb_cli_timeout_seconds` filter (default 30s).
     *
     * @throws RuntimeException on timeout, non-zero exit, or stderr content.
     */
    private function execute_cli(string $sql): array {
        // Array form of proc_open (PHP 7.4+) — no shell interpolation, no escaping needed.
        $cmd = [$this->binary_path, '-json', '-bail', $this->db_path];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes, null, null);
        if (!is_resource($proc)) {
            throw new RuntimeException(
                __('Failed to launch the DuckDB CLI binary.', 'mxchat-plus')
            );
        }

        $script = '';
        foreach ($this->init_sql as $stmt) {
            $script .= rtrim($stmt, ";\n ") . ";\n";
        }
        $script .= rtrim($sql, ";\n ") . ";\n";

        fwrite($pipes[0], $script);
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $timeout = max(1, (int) apply_filters('mxchat_plus_duckdb_cli_timeout_seconds', 30));
        $deadline = microtime(true) + $timeout;
        $stdout = '';
        $stderr = '';
        $open = [$pipes[1], $pipes[2]];
        $timed_out = false;

        while (!empty($open)) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $timed_out = true;
                break;
            }
            $read = $open;
            $write = $except = null;
            $sec = (int) $remaining;
            $usec = (int) (($remaining - $sec) * 1_000_000);
            $ready = @stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false) break;        // signal interrupt
            if ($ready === 0)     continue;     // tick, recheck deadline
            foreach ($read as $stream) {
                $chunk = fread($stream, 8192);
                if ($chunk !== false && $chunk !== '') {
                    if ($stream === $pipes[1]) $stdout .= $chunk;
                    else                       $stderr .= $chunk;
                }
                if (feof($stream)) {
                    $open = array_filter($open, static fn($s) => $s !== $stream);
                }
            }
        }

        if ($timed_out) {
            foreach ($open as $s) { if (is_resource($s)) fclose($s); }
            proc_terminate($proc, 9);
            proc_close($proc);
            throw new RuntimeException(sprintf(
                /* translators: %d = timeout seconds */
                __('DuckDB CLI timed out after %d seconds.', 'mxchat-plus'),
                $timeout
            ));
        }

        foreach ([$pipes[1], $pipes[2]] as $s) {
            if (is_resource($s)) fclose($s);
        }
        $exit_code = proc_close($proc);

        if ($exit_code !== 0 || !empty($stderr)) {
            throw new RuntimeException(sprintf(
                /* translators: 1: exit code, 2: stderr content */
                __('DuckDB CLI failed (exit code %1$d): %2$s', 'mxchat-plus'),
                $exit_code,
                trim((string) $stderr) ?: __('no error output', 'mxchat-plus')
            ));
        }

        if ($stdout === '') {
            return []; // DDL / non-SELECT
        }

        return self::decode_cli_json($stdout);
    }

    /**
     * Parse the DuckDB CLI's `-json` stdout into an array of row objects.
     *
     * The common case is a single JSON array (one result-producing statement).
     * But the CLI is stateless, so we prepend init-SQL to every invocation
     * (ATTACH / INSTALL / LOAD for MotherDuck); if any of those ever emit
     * output, stdout becomes "[…]\n[…]" — concatenated result sets where the
     * statement we care about is the LAST one. Some builds/pipelines also emit
     * NDJSON (one object per line). The previous implementation did a single
     * `json_decode` and silently returned `[]` on anything but a clean single
     * value — turning a real result into "no rows" with no error.
     *
     * Resolution order, each strictly better than returning empty:
     *   1. Whole-string decode (fast path, normal output).
     *   2. Last top-level `[…]` array (concatenated result sets).
     *   3. NDJSON: decode each non-empty line, collect the objects.
     *   4. `[]` only when nothing parses.
     *
     * @return array<int,mixed> list of decoded row objects
     */
    private static function decode_cli_json(string $stdout): array {
        $stdout = trim($stdout);
        if ($stdout === '') {
            return [];
        }

        // 1. Fast path: a single well-formed JSON value.
        $decoded = json_decode($stdout, true);
        if (is_array($decoded)) {
            return self::normalize_decoded_json($decoded);
        }

        // 2. Concatenated result sets — the real query is the last array.
        $last = self::extract_last_json_array($stdout);
        if ($last !== null) {
            $decoded = json_decode($last, true);
            if (is_array($decoded)) {
                return self::normalize_decoded_json($decoded);
            }
        }

        // 3. NDJSON: one JSON value per line.
        $rows = [];
        foreach (preg_split('/\r?\n/', $stdout) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $obj = json_decode($line, true);
            if (is_array($obj)) {
                $rows = array_merge($rows, self::is_list_array($obj) ? $obj : [$obj]);
            }
        }
        return $rows;
    }

    /**
     * DuckDB `-json` returns a list of row-objects for a SELECT. A bare object
     * (single row, no wrapping list) is wrapped so callers always get a list
     * of rows; an empty array stays empty.
     *
     * @param array<array-key,mixed> $decoded
     * @return array<int,mixed>
     */
    private static function normalize_decoded_json(array $decoded): array {
        if ($decoded === []) {
            return [];
        }
        return self::is_list_array($decoded) ? $decoded : [$decoded];
    }

    /**
     * Sequential-0-indexed check, equivalent to PHP 8.1's array_is_list() but
     * available on our PHP 8.0 floor. An empty array counts as a list.
     *
     * @param array<array-key,mixed> $a
     */
    private static function is_list_array(array $a): bool {
        if ($a === []) {
            return true;
        }
        return array_keys($a) === range(0, count($a) - 1);
    }

    /**
     * Extract the last balanced top-level `[…]` array from a string by walking
     * backwards from the final `]`. Brackets inside string literals can throw
     * the depth count off, but a mis-extracted slice simply fails the
     * subsequent json_decode and the caller falls through to the NDJSON path —
     * so this stays a best-effort fallback, never a correctness dependency.
     */
    private static function extract_last_json_array(string $s): ?string {
        $end = strrpos($s, ']');
        if ($end === false) {
            return null;
        }
        $depth = 0;
        for ($i = $end; $i >= 0; $i--) {
            $ch = $s[$i];
            if ($ch === ']') {
                $depth++;
            } elseif ($ch === '[') {
                $depth--;
                if ($depth === 0) {
                    return substr($s, $i, $end - $i + 1);
                }
            }
        }
        return null;
    }

    /** Request-scoped memo for autodetect_binary() (sentinel = not yet probed). */
    private static ?string $autodetected_binary = null;

    private static function autodetect_binary(): string {
        // Memoize for the request: the filesystem doesn't change mid-request,
        // and a mirrored install constructs two backends (embedded + MotherDuck
        // wrapper), each of which would otherwise re-stat the candidate paths
        // and re-spawn `command -v`.
        if (self::$autodetected_binary !== null) {
            return self::$autodetected_binary;
        }
        $candidates = ['/usr/local/bin/duckdb', '/usr/bin/duckdb', '/opt/homebrew/bin/duckdb'];
        foreach ($candidates as $c) {
            if (is_executable($c)) return self::$autodetected_binary = $c;
        }
        if (function_exists('shell_exec')) {
            $which = trim((string) @shell_exec('command -v duckdb 2>/dev/null'));
            if ($which !== '' && is_executable($which)) return self::$autodetected_binary = $which;
        }
        return self::$autodetected_binary = '';
    }

    /**
     * Probe whether the given path is genuinely the DuckDB CLI. Runs a
     * `SELECT 'marker'` over the candidate binary in `-json` mode under a
     * 2-second cap and checks the marker round-tripped. The `-json` flag is
     * DuckDB-specific, so any unrelated binary (e.g. /bin/sh pasted by mistake)
     * fails fast — no false positives. Returns false on any error / timeout;
     * never throws. Used by the settings sanitiser to surface mis-pointed
     * paths before queries start failing cryptically at runtime.
     */
    public static function looks_like_duckdb_binary(string $path): bool {
        if ($path === '' || !is_executable($path)) return false;

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open([$path, '-json'], $descriptors, $pipes, null, null);
        if (!is_resource($proc)) return false;

        fwrite($pipes[0], "SELECT 'mxp_duckdb_probe' AS marker;\n");
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + 2.0;
        $stdout = '';
        while (true) {
            if (microtime(true) >= $deadline) {
                proc_terminate($proc, 9);
                @fclose($pipes[1]); @fclose($pipes[2]);
                proc_close($proc);
                return false;
            }
            $status = proc_get_status($proc);
            $chunk = stream_get_contents($pipes[1]);
            if ($chunk !== false) $stdout .= $chunk;
            if (!$status['running']) {
                $rest = stream_get_contents($pipes[1]);
                if ($rest !== false) $stdout .= $rest;
                break;
            }
            usleep(20_000);
        }
        @fclose($pipes[1]); @fclose($pipes[2]);
        proc_close($proc);

        return str_contains($stdout, 'mxp_duckdb_probe');
    }

    /**
     * Inline ?-parameters as DuckDB literals. Used only for the CLI path.
     * Handles scalars and arrays of floats (embedding vectors).
     *
     * @throws RuntimeException on non-numeric values inside a numeric array
     *         (would silently corrupt embedding vectors otherwise).
     */
    private static function inline_params(string $sql, array $params): string {
        if (empty($params)) return $sql;

        $i = 0;
        return preg_replace_callback('/\?/', function () use (&$i, $params) {
            if (!array_key_exists($i, $params)) {
                $i++;
                return 'NULL';
            }
            $val = $params[$i++];
            return self::to_literal($val);
        }, $sql);
    }

    private static function to_literal($val): string {
        if (is_null($val)) return 'NULL';
        if (is_bool($val)) return $val ? 'TRUE' : 'FALSE';
        if (is_int($val) || is_float($val)) return (string) $val;
        if (is_array($val)) {
            $parts = [];
            foreach ($val as $v) {
                if (is_int($v) || is_float($v)) {
                    $parts[] = (string) $v;
                } elseif (is_numeric($v)) {
                    $parts[] = (string) (float) $v;
                } else {
                    throw new RuntimeException(
                        __('Non-numeric value in array parameter: ', 'mxchat-plus')
                        . var_export($v, true)
                    );
                }
            }
            return '[' . implode(',', $parts) . ']';
        }
        return "'" . str_replace("'", "''", (string) $val) . "'";
    }
}
