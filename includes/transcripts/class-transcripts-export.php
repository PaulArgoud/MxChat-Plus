<?php
/**
 * CSV export of the transcripts selected on MxChat's transcripts screen.
 *
 * MxChat ships `wp_ajax_mxchat_export_transcripts`, but it dumps the whole
 * table unconditionally — there is no way to export just the conversations an
 * admin ticked. This module adds that, and does it without touching the host:
 *
 *  - the button is injected client-side next to the host's own "Delete
 *    Selected" control, because the host's markup offers no action or filter
 *    hook to render into;
 *  - the selection is read back from the DOM, because the host keeps its
 *    `selectedSessions` Set private to its own closure;
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
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::AJAX_ACTION,
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'i18n'    => [
                'buttonTitle'   => __('Export as CSV (selection, or by date range)', 'mxchat-plus'),
                'rangeTitle'    => __('Export by date range', 'mxchat-plus'),
                'rangeIntro'    => __('No conversation is selected. Choose a period — every conversation between these two dates will be exported.', 'mxchat-plus'),
                'rangeFrom'     => __('Start date', 'mxchat-plus'),
                'rangeTo'       => __('End date', 'mxchat-plus'),
                'rangeRequired' => __('Please provide both a start and an end date.', 'mxchat-plus'),
                'rangeOrder'    => __('The start date must be before the end date.', 'mxchat-plus'),
                'cancel'        => __('Cancel', 'mxchat-plus'),
                'exportBtn'     => __('Export CSV', 'mxchat-plus'),
            ],
        ]);
    }

    /**
     * Streams the CSV. Deliberately mirrors the column set and the UTF-8 BOM of
     * the host's own export, so both files open identically in Excel.
     */
    public function handle_export(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to export transcripts.', 'mxchat-plus'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_ACTION, 'security');

        $session_ids = self::sanitize_session_ids(
            isset($_POST['session_ids']) ? wp_unslash($_POST['session_ids']) : []
        );

        global $wpdb;
        $table = $wpdb->prefix . 'mxchat_chat_transcripts';
        $columns = 'session_id, user_email, user_identifier, role, message, timestamp';

        if ($session_ids !== []) {
            // Placeholders are generated from the COUNT, never from user input,
            // and every value is bound — session ids come from the browser.
            $placeholders = implode(',', array_fill(0, count($session_ids), '%s'));
            $sql = $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table from $wpdb->prefix, $placeholders from a count.
                "SELECT {$columns} FROM {$table}
                 WHERE session_id IN ({$placeholders})
                 ORDER BY session_id, timestamp ASC",
                ...$session_ids
            );
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

            $sql = $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix.
                "SELECT {$columns} FROM {$table}
                 WHERE timestamp >= %s AND timestamp <= %s
                 ORDER BY session_id, timestamp ASC",
                $range['from'],
                $range['to']
            );
            $empty_message = __('No conversations found in that date range.', 'mxchat-plus');
        }

        $rows = $wpdb->get_results($sql);

        if (empty($rows)) {
            wp_die(esc_html($empty_message), '', ['response' => 404]);
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="mxchat-transcripts-' . gmdate('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        if ($output === false) {
            wp_die(esc_html__('Could not open the output stream for the export.', 'mxchat-plus'), '', ['response' => 500]);
        }

        // UTF-8 BOM: without it Excel mis-reads accented characters.
        fwrite($output, "\xEF\xBB\xBF");

        fputcsv($output, [
            __('Session ID', 'mxchat-plus'),
            __('Email', 'mxchat-plus'),
            __('User identifier', 'mxchat-plus'),
            __('Role', 'mxchat-plus'),
            __('Message', 'mxchat-plus'),
            __('Timestamp', 'mxchat-plus'),
        ]);

        foreach ($rows as $row) {
            fputcsv($output, [
                $row->session_id,
                $row->user_email,
                $row->user_identifier,
                $row->role,
                self::neutralise_formula($row->message),
                $row->timestamp,
            ]);
        }

        fclose($output);
        exit;
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

        $ids = array_keys($seen);
        // Bound the export rather than reject it: a "select all" on a large
        // install should still produce a usable file.
        return count($ids) > self::MAX_SESSIONS
            ? array_slice($ids, 0, self::MAX_SESSIONS)
            : $ids;
    }

    /**
     * Turn two `YYYY-MM-DD` strings from a date input into inclusive SQL
     * bounds, or null when the pair is unusable.
     *
     * The end bound is stretched to 23:59:59 so that picking the same day for
     * both actually returns that day: the column is a TIMESTAMP, and a bare
     * date compares as midnight, which would match nothing.
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
