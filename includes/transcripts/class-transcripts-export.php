<?php
/**
 * CSV export of the transcripts selected on MxChat's transcripts screen.
 *
 * MxChat ships `wp_ajax_mxchat_export_transcripts`, but it dumps the whole
 * table unconditionally — there is no way to export just the conversations an
 * admin ticked, and the "Export" button inside an open conversation calls the
 * same whole-table dump. This module adds both, without touching the host:
 *
 *  - the buttons are injected client-side, one next to the host's "Delete
 *    Selected" control and one next to the open conversation's own "Export",
 *    because the host's markup offers no action or filter hook to render into;
 *  - the selection is mirrored in the browser from the checkboxes' `change`
 *    events, because the host keeps its `selectedSessions` Set private to its
 *    own closure — and that Set outlives the 50 rows on screen, so the
 *    checkboxes alone only show the current page's share of it;
 *  - the open conversation is learnt from the host's own AJAX traffic, for the
 *    same reason (`currentSessionId` lives in that closure too);
 *  - the export itself is a handler of ours, on our own nonce.
 *
 * Reading the host's table directly is the one coupling we accept: it is the
 * only way to honour the request, and the schema is the host's public storage
 * contract (it is what its own export reads).
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_Transcripts_Export {

    private static ?self $instance = null;

    const AJAX_ACTION  = 'mxchat_plus_export_transcripts';
    const NONCE_ACTION = 'mxchat_plus_export_transcripts';

    /** The host's transcripts screen, as seen in the page query arg. */
    const HOST_PAGE = 'mxchat-transcripts';

    /** Hard ceiling on one export, so a "select all" cannot exhaust memory. */
    const MAX_SESSIONS = 2000;

    /** The host's transcripts table, without the prefix. Host literal (MxChat schema). */
    const HOST_TABLE = 'mxchat_chat_transcripts';

    /**
     * Columns every MxChat version has — the same set, in the same order, as
     * the host's own export (MxChat_Admin::export_chat_transcripts, MxChat
     * 3.2.25). Host literals: these are column names of the host's table.
     */
    const BASE_COLUMNS = ['session_id', 'user_email', 'user_identifier', 'role', 'message', 'timestamp'];

    /**
     * Columns added to the table by host upgrade routines, selected only when
     * the table actually has them. The host itself probes
     * `originating_page_url` with SHOW COLUMNS before every read
     * (MxChat_Admin::mxchat_fetch_conversation, MxChat 3.2.25): a SELECT that
     * names a missing column fails as a whole, and the export would then
     * answer "No messages found" for everything. Host literals.
     */
    const OPTIONAL_COLUMNS = ['user_name', 'originating_page_url'];

    /** The `role` value the host stores for a visitor's message. Host literal. */
    const ROLE_USER = 'user';

    /**
     * The latest value MySQL's DATETIME syntax can spell, and the bound a
     * far-future end date was sent as before ranges were converted to GMT.
     */
    const GMT_MAX = '9999-12-31 23:59:59';

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function register_hooks(): void {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handle_export']);
    }

    /**
     * The host's screen is registered as a submenu page, so its hook suffix
     * varies with the parent slug. Matching on the `page` query arg is both
     * simpler and stable across host renames.
     */
    public function enqueue_assets(string $hook): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== self::HOST_PAGE) {
            return;
        }

        wp_enqueue_script(
            'mxchat-plus-transcripts-export',
            MXCHAT_PLUS_URL . 'assets/js/admin-transcripts-export.js',
            ['jquery'],
            MXCHAT_PLUS_VERSION,
            true
        );

        wp_localize_script('mxchat-plus-transcripts-export', 'mxchatPlusTranscriptsExport', [
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'action'      => self::AJAX_ACTION,
            'nonce'       => wp_create_nonce(self::NONCE_ACTION),
            'maxSessions' => self::MAX_SESSIONS,
            'i18n'        => [
                'buttonTitle'        => __('Export as CSV (selection, or by date range)', 'mxchat-plus'),
                'rangeTitle'         => __('Export by date range', 'mxchat-plus'),
                'rangeIntro'         => __('No conversation is selected. Choose a period — every conversation between these two dates will be exported.', 'mxchat-plus'),
                'rangeFrom'          => __('Start date', 'mxchat-plus'),
                'rangeTo'            => __('End date', 'mxchat-plus'),
                'rangeRequired'      => __('Please provide both a start and an end date.', 'mxchat-plus'),
                'rangeOrder'         => __('The start date must be before the end date.', 'mxchat-plus'),
                'cancel'             => __('Cancel', 'mxchat-plus'),
                'exportBtn'          => __('Export CSV', 'mxchat-plus'),
                'exportCurrent'      => __('Export this conversation (CSV)', 'mxchat-plus'),
                /* translators: 1: number of conversations MxChat's toolbar shows as selected, 2: number of conversations this export will contain. */
                'selectionMismatch'  => __('MxChat shows %1$d selected conversations, but this export will contain %2$d. Export anyway?', 'mxchat-plus'),
                /* translators: %d: number of conversations MxChat's toolbar shows as selected. */
                'selectionUntracked' => __('MxChat shows %d selected conversations, but this export lost track of them. To export them, reload the page and select them again. Export by date range instead?', 'mxchat-plus'),
                /* translators: 1: maximum number of conversations in one export, 2: number of conversations selected. */
                'selectionCapped'    => __('Only the first %1$d of the %2$d selected conversations will be exported. Continue?', 'mxchat-plus'),
            ],
        ]);
    }

    /**
     * Streams the CSV: the host export's six columns in the host's order, the
     * same UTF-8 BOM, then two columns the host leaves out (see csv_header()).
     */
    public function handle_export(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to export transcripts.', 'mxchat-plus'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_ACTION, 'security');

        // The current script posts one JSON field; the `session_ids[]` array is
        // still read for a screen whose script was cached before that change.
        // See decode_session_ids_json() for why.
        if (isset($_POST['session_ids_json'])) {
            $raw_ids = self::decode_session_ids_json(wp_unslash($_POST['session_ids_json']));
        } else {
            $raw_ids = isset($_POST['session_ids']) ? wp_unslash($_POST['session_ids']) : [];
        }
        $session_ids = self::sanitize_session_ids($raw_ids);

        $gmt_range = null;
        if ($session_ids !== []) {
            $empty_message = __('No messages found for the selected conversations.', 'mxchat-plus');
        } else {
            // No selection: fall back to a date range, which the browser asked
            // the user for. Both bounds are required — an unbounded export here
            // would silently dump the entire table.
            $range = self::sanitize_date_range(
                isset($_POST['date_from']) ? wp_unslash($_POST['date_from']) : '',
                isset($_POST['date_to']) ? wp_unslash($_POST['date_to']) : ''
            );

            if ($range === null) {
                wp_die(
                    esc_html__('Select at least one conversation, or provide a valid start and end date.', 'mxchat-plus'),
                    '',
                    ['response' => 400]
                );
            }

            // The dates were picked as site-local days; the column is GMT.
            $gmt_range     = self::local_range_to_gmt($range);
            $empty_message = __('No conversations found in that date range.', 'mxchat-plus');
        }

        $rows = self::query_rows($session_ids, $gmt_range);

        if ($rows === []) {
            wp_die(esc_html($empty_message), '', ['response' => 404]);
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="mxchat-transcripts-' . gmdate('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        if ($output === false) {
            wp_die(esc_html__('Could not open the output stream for the export.', 'mxchat-plus'), '', ['response' => 500]);
        }

        self::write_csv($output, $rows);

        fclose($output);
        exit;
    }

    /**
     * Run the export query: the given sessions, or every message inside a GMT
     * range. Neither means nothing — never the whole table.
     *
     * Ordered by session, then timestamp, then row id. The id tie-breaker is a
     * deliberate difference from the host's CSV (whose ORDER BY stops at the
     * timestamp): the host writes rows back to back within one second — a bot
     * answer and its HTML card, or rows an add-on appends through
     * MxChat_Utils::save_session_message() — and MySQL does not keep equal
     * keys in any guaranteed order. The id is the order the host itself
     * replays a conversation in (MxChat_Utils::get_session_history, MxChat
     * 3.2.25), and its privacy exporter breaks ties the same way.
     *
     * @param list<string>                      $session_ids
     * @param array{from:string,to:string}|null $gmt_range
     * @return list<object>
     */
    private static function query_rows(array $session_ids, ?array $gmt_range): array {
        if ($session_ids === [] && $gmt_range === null) {
            return [];
        }

        global $wpdb;
        $table   = $wpdb->prefix . self::HOST_TABLE;
        $columns = self::select_columns(self::table_columns($table));

        if ($session_ids !== []) {
            // Placeholders are generated from the COUNT, never from user input,
            // and every value is bound — session ids come from the browser.
            // $columns is built from the class constants above, never from input.
            $placeholders = implode(',', array_fill(0, count($session_ids), '%s'));
            $sql = $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table from $wpdb->prefix, $columns from constants, $placeholders from a count.
                "SELECT {$columns} FROM {$table}
                 WHERE session_id IN ({$placeholders})
                 ORDER BY session_id, timestamp ASC, id ASC",
                ...$session_ids
            );
        } else {
            $sql = $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table from $wpdb->prefix, $columns from constants.
                "SELECT {$columns} FROM {$table}
                 WHERE timestamp >= %s AND timestamp <= %s
                 ORDER BY session_id, timestamp ASC, id ASC",
                $gmt_range['from'],
                $gmt_range['to']
            );
        }

        $rows = $wpdb->get_results($sql);
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_object'));
    }

    /**
     * Lower-cased column names of the host's table, or an empty list when the
     * probe fails — which only drops the optional columns, never the export.
     *
     * @return list<string>
     */
    private static function table_columns(string $table): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table from $wpdb->prefix and a constant.
        $fields = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        if (!is_array($fields)) {
            return [];
        }

        $names = [];
        foreach ($fields as $field) {
            if (is_scalar($field)) {
                $names[] = strtolower((string) $field);
            }
        }
        return $names;
    }

    /**
     * The SELECT list: the host's six columns, plus each optional column the
     * table has. Built from the class constants only.
     *
     * @param list<string> $present Column names the table has.
     */
    public static function select_columns(array $present): string {
        $present = array_map('strtolower', $present);
        $columns = self::BASE_COLUMNS;
        foreach (self::OPTIONAL_COLUMNS as $optional) {
            if (in_array($optional, $present, true)) {
                $columns[] = $optional;
            }
        }
        return implode(', ', $columns);
    }

    /**
     * The header row.
     *
     * The first six columns mirror the host's export. The two after them carry
     * what the host's export leaves out: the visitor's name (since 3.2.22 a
     * lead can be captured with a name and no email, and the transcripts list
     * then shows only that name) and the page the chat started on (the host
     * shows it in the details drawer). They are appended, never inserted, so a
     * spreadsheet import that reads columns by position keeps working.
     *
     * "UTC" is spelled out because the host stores every timestamp in GMT
     * (current_time('mysql', 1)) while its panel shows them in site time.
     *
     * @return list<string>
     */
    public static function csv_header(): array {
        return [
            __('Session ID', 'mxchat-plus'),
            __('Email', 'mxchat-plus'),
            __('User identifier', 'mxchat-plus'),
            __('Role', 'mxchat-plus'),
            __('Message', 'mxchat-plus'),
            __('Timestamp (UTC)', 'mxchat-plus'),
            __('Name', 'mxchat-plus'),
            __('Originating page', 'mxchat-plus'),
        ];
    }

    /**
     * One message row, in csv_header() order. A column the table lacks comes
     * out empty, so every file has the same shape whatever the host version.
     *
     * Every visitor-influenced cell goes through the formula guard: not only
     * the message, but the email (is_email() accepts a local part starting
     * with `=`, `+` or `-`), the identifier (a user_login can start with `-`
     * or `@`), the name, the page URL and even the session id, which older
     * hosts accepted from the browser with little more than
     * sanitize_text_field().
     *
     * @return list<string>
     */
    public static function build_csv_row(object $row): array {
        $vars = get_object_vars($row);
        $get  = static function (string $name) use ($vars): string {
            $value = $vars[$name] ?? null;
            return is_scalar($value) ? (string) $value : '';
        };

        $role = $get('role');

        return [
            self::neutralise_formula($get('session_id')),
            self::neutralise_formula($get('user_email')),
            self::neutralise_formula($get('user_identifier')),
            self::neutralise_formula($role),
            // Decode first, guard second: decoding `&#061;` yields a leading `=`.
            self::neutralise_formula(self::export_message($get('message'), $role)),
            $get('timestamp'),
            self::neutralise_formula($get('user_name')),
            self::neutralise_formula($get('originating_page_url')),
        ];
    }

    /**
     * Write the whole file — BOM, header, rows — to an open stream. Split out
     * of handle_export() so the bytes can be tested without a request.
     *
     * @param resource       $handle
     * @param iterable<object> $rows
     */
    public static function write_csv($handle, iterable $rows): void {
        // UTF-8 BOM: without it Excel mis-reads accented characters.
        fwrite($handle, "\xEF\xBB\xBF");

        self::put_row($handle, self::csv_header());
        foreach ($rows as $row) {
            self::put_row($handle, self::build_csv_row($row));
        }
    }

    /**
     * RFC 4180 quoting. With PHP's default escape character (`\`), fputcsv
     * leaves a `\"` sequence undoubled — and visitor messages are stored with
     * exactly that sequence (see export_message()) — so a reader that follows
     * RFC 4180, Excel and Python's csv among them, shifts the row's columns
     * or splits it at the next newline. An empty escape character makes every
     * `"` doubled, whatever precedes it.
     *
     * @param resource     $handle
     * @param list<string> $fields
     */
    private static function put_row($handle, array $fields): void {
        fputcsv($handle, $fields, ',', '"', '');
    }

    /**
     * The message as the host's panel shows it, rather than as it is stored.
     *
     * A visitor's message reaches the database slashed and entity-encoded:
     * the host reads `$_POST['message']` without wp_unslash(), so WordPress's
     * magic quotes stay in (`I\'m`), and runs it through wp_kses(), which
     * encodes a bare `&` as `&amp;` (MxChat_Integrator::mxchat_handle_chat_request,
     * MxChat 3.2.25). Its panel undoes the slashes with stripslashes() and the
     * entities by rendering HTML; this does both, for `user` rows only.
     *
     * Bot, agent and system rows are left exactly as stored: they are never
     * magic-quoted, so stripping would destroy real backslashes (code samples,
     * Windows paths), and they can legitimately carry HTML. The host's panel
     * strips every row; that is a bug of its own, not one to copy.
     *
     * Known limitation: since 3.2.22 an add-on can write a `user` row through
     * MxChat_Utils::save_session_message(), unslashed, and nothing in the row
     * says which path wrote it. Such a row loses a genuine backslash here —
     * exactly as it does in the host's panel.
     */
    private static function export_message(string $message, string $role): string {
        if ($role !== self::ROLE_USER) {
            return $message;
        }
        return html_entity_decode(stripslashes($message), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Normalise whatever the browser posted into a clean, deduped, bounded list
     * of session ids.
     *
     * @param mixed $raw
     * @return list<string>
     */
    public static function sanitize_session_ids($raw): array {
        if (!is_array($raw)) {
            $raw = [$raw];
        }

        $seen = [];
        foreach ($raw as $id) {
            if (!is_scalar($id)) {
                continue;
            }
            $id = sanitize_text_field((string) $id);
            if ($id !== '') {
                // Key-based dedupe preserves first-seen order.
                $seen[$id] = true;
            }
        }

        // Cast back: PHP turns a numeric-string key such as "123" into an int.
        $ids = array_map('strval', array_keys($seen));
        // Bound the export rather than reject it: a "select all" on a large
        // install should still produce a usable file.
        return count($ids) > self::MAX_SESSIONS
            ? array_slice($ids, 0, self::MAX_SESSIONS)
            : $ids;
    }

    /**
     * Decode the `session_ids_json` field into a raw list for
     * sanitize_session_ids(), or an empty list when it is not a JSON array.
     *
     * The browser sends the selection as ONE field rather than one
     * `session_ids[]` input per id: the selection is now kept across pages, so
     * it can run into the thousands, and PHP silently drops every input past
     * `max_input_vars` (1000 by default) — the export would lose conversations
     * with no error anywhere.
     *
     * @param mixed $json Unslashed field value.
     * @return array<mixed>
     */
    public static function decode_session_ids_json($json): array {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * Turn two `YYYY-MM-DD` strings from a date input into inclusive bounds,
     * or null when the pair is unusable. A pure validator: the bounds it
     * returns are in the same (site-local) time as the dates it was given —
     * see local_range_to_gmt() for the conversion to the column's GMT.
     *
     * The end bound is stretched to 23:59:59 so that picking the same day for
     * both actually returns that day: the column is a TIMESTAMP, and a bare
     * date compares as midnight, which would match nothing. local_range_to_gmt()
     * reads that 23:59:59 as "end of the day", not as a clock time.
     *
     * Reversed bounds are swapped rather than rejected — the intent is
     * unambiguous, and failing on it would only be pedantry.
     *
     * @return array{from:string,to:string}|null
     */
    public static function sanitize_date_range(mixed $from, mixed $to): ?array {
        $from = is_scalar($from) ? trim((string) $from) : '';
        $to   = is_scalar($to) ? trim((string) $to) : '';

        if (!self::is_valid_date($from) || !self::is_valid_date($to)) {
            return null;
        }

        if (strcmp($from, $to) > 0) {
            [$from, $to] = [$to, $from];
        }

        return [
            'from' => $from . ' 00:00:00',
            'to'   => $to . ' 23:59:59',
        ];
    }

    /**
     * Convert a range from sanitize_date_range() — site-local calendar days —
     * into the GMT bounds MxChat's columns are stored in.
     *
     * The host writes every `timestamp` (and every `click_timestamp` of its
     * clicks table) with current_time('mysql', 1), i.e. GMT, and its panel
     * shows them back in site time through wp_date(). The date picker speaks
     * the panel's language, so "the 16th" means the 16th from local midnight
     * to local 23:59:59. Comparing those strings with the column directly
     * would shift both edges by the site's UTC offset.
     *
     * The site's timezone (wp_timezone(), through get_gmt_from_date()) is
     * used, not the browser's: that is what the panel displays in. Each bound
     * gets the UTC offset in force at that instant, and the end of a day is
     * taken from the start of the next one (see local_end_of_day_to_gmt()),
     * so consecutive days stay contiguous across a clock change, even one
     * that falls at local midnight.
     *
     * Public so the tracking report converts its own ranges the same way.
     * Only pass it a range sanitize_date_range() accepted — never a sentinel
     * such as the tracking report's full-domain bounds.
     *
     * @param array{from:string,to:string} $range
     * @return array{from:string,to:string}
     */
    public static function local_range_to_gmt(array $range): array {
        return [
            'from' => self::local_to_gmt($range['from']),
            'to'   => self::local_end_of_day_to_gmt($range['to']),
        ];
    }

    /**
     * The inclusive GMT end of a site-local day: one second before the NEXT
     * local midnight, rather than local 23:59:59 converted on its own.
     *
     * The two differ when a clock change sits at local midnight, because PHP
     * resolves a local time that happens twice to its first occurrence, and
     * one that never happens to an hour later:
     *
     *  - clocks back from 00:00 to 23:00 (America/Santiago, early April): the
     *    23:00-23:59 hour happens twice, "23:59:59" ends the day at the first
     *    pass, and the repeated hour belonged to no single-day range at all;
     *  - clocks forward from 23:00 to 00:00 (America/Nuuk, late March):
     *    "23:59:59" does not exist, lands an hour into the next day, and that
     *    hour was exported with both days.
     *
     * Taken from the next day's start, day N always ends one second before
     * day N+1 begins, whatever the zone does.
     *
     * Only the end sanitize_date_range() produces (`YYYY-MM-DD 23:59:59`) is
     * treated this way; any other value is converted as given.
     */
    private static function local_end_of_day_to_gmt(string $local_end): string {
        if (preg_match('/^(\d{4}-\d{2}-\d{2}) 23:59:59$/', $local_end, $m) !== 1) {
            return self::local_to_gmt($local_end);
        }

        // Calendar arithmetic only, done in UTC, where every day is 24 hours.
        $utc = new DateTimeZone('UTC');
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $m[1], $utc);
        if ($day === false) {
            return self::local_to_gmt($local_end);
        }
        $next = $day->add(new DateInterval('P1D'));
        if ((int) $next->format('Y') > 9999) {
            // 9999-12-31 has no next midnight the column syntax can spell.
            // Nothing is stored that late; converting 23:59:59 itself is
            // exact enough, and local_to_gmt() clamps the result.
            return self::local_to_gmt($local_end);
        }

        $next_start = self::local_to_gmt($next->format('Y-m-d') . ' 00:00:00');
        $end = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $next_start, $utc);
        if ($end === false) {
            return self::local_to_gmt($local_end);
        }
        return $end->sub(new DateInterval('PT1S'))->format('Y-m-d H:i:s');
    }

    private static function local_to_gmt(string $local): string {
        // Core since WordPress 1.2, timezone-aware since 5.3 — always present
        // on the 6.0+ this plugin requires. The guard only keeps a stripped
        // runtime from fataling; the fallback is the pre-fix behaviour.
        if (!function_exists('get_gmt_from_date')) {
            return $local;
        }
        $gmt = get_gmt_from_date($local, 'Y-m-d H:i:s');
        if ($gmt === '') {
            return $local;
        }

        // West of Greenwich, the last local hours of 9999-12-31 fall in year
        // 10000 in GMT. That five-digit year is outside MySQL's DATETIME
        // syntax, and however the server reads it (a zero date, or a string
        // in which '2026-…' <= '10000-…' is false) it matches no row, so a
        // "until 9999-12-31" export came back empty. Clamp to the latest value
        // the column can hold, which is the bound sent before this conversion
        // existed. The year is read as a number on purpose: compared as
        // strings, '10000-…' sorts BEFORE '9999-…' and a min() would keep it.
        if (preg_match('/^(\d+)-/', $gmt, $year) === 1 && strlen($year[1]) > 4) {
            return self::GMT_MAX;
        }
        return $gmt;
    }

    /**
     * Strict `YYYY-MM-DD`, and a real calendar date: checkdate() rejects
     * 2026-02-30, which the regex alone would accept.
     */
    private static function is_valid_date(string $value): bool {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * CSV injection guard. A chat message is visitor-supplied text; one opening
     * with =, +, - or @ is executed as a formula when the file is opened in
     * Excel or Sheets. Prefixing a tab keeps the text readable while stripping
     * its formula meaning.
     */
    private static function neutralise_formula(?string $value): string {
        $value = (string) $value;
        if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
            return "\t" . $value;
        }
        return $value;
    }
}
