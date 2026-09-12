<?php
/**
 * Server-side report over MxChat's own link-click log.
 *
 * MxChat records every click on an absolute link inside a bot answer into
 * `{prefix}mxchat_url_clicks` (the AJAX writer lives at
 * mxchat-basic/includes/class-mxchat-integrator.php:14752), then surfaces the
 * table as nothing more than a DISTINCT list of URLs on a single
 * conversation's screen: no counts, no aggregation, and not a single click
 * column in its CSV export or its REST API. The data is collected and left
 * unusable.
 *
 * This class aggregates it — clicks and distinct sessions per URL, split
 * internal vs external, over an optional date range — on screen and as CSV.
 *
 * Reading the host's table directly is the same coupling the transcripts
 * module already accepts: it is the host's public storage contract (its own
 * reader and its own privacy sweep read the very same columns), and no hook
 * exists that would hand us the rows.
 *
 * Two columns are deliberately never read, here or in the export: `user_ip`
 * and `user_agent`. The host anonymises the IP and empties the agent 30 days
 * after the click and deletes the row after a year
 * (MxChat_Privacy::sweep_url_clicks, mxchat-basic/includes/class-mxchat-privacy.php:490).
 * Putting either into a report — worse, into a downloaded CSV that nothing
 * ever sweeps — would copy identifiers straight back out of the retention
 * window the host built for them.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_Tracking_Report {

    const AJAX_ACTION  = 'mxchat_plus_tracking_export';
    const NONCE_ACTION = 'mxchat_plus_tracking_export';

    /** Host table, unprefixed. A host symbol — never rename it. */
    const HOST_TABLE = 'mxchat_url_clicks';

    const SCOPE_INTERNAL = 'internal';
    const SCOPE_EXTERNAL = 'external';

    /** Ceiling on an on-screen report, so a caller cannot ask for the world. */
    const MAX_LIMIT = 500;

    /** Ceiling on one CSV. Higher than the screen, still bounded memory. */
    const EXPORT_MAX_ROWS = 5000;

    /** Distinct URLs pulled per pass when totalling internal vs external. */
    const CLASSIFY_PAGE = 500;

    /** Hard stop on that paging, so a pathological site cannot loop forever. */
    const MAX_CLASSIFY_URLS = 50000;

    /**
     * Sentinels used when no date range is given. They stand in for the full
     * DATETIME domain so every query keeps one shape and always carries
     * placeholders: wpdb::prepare() raises a _doing_it_wrong notice for a
     * query containing none, so a dropped predicate would mean a second,
     * unprepared code path per query.
     */
    const RANGE_MIN = '1000-01-01 00:00:00';
    const RANGE_MAX = '9999-12-31 23:59:59';

    /** Memoised result of the table probe. Null until probed. */
    private static ?bool $table_exists = null;

    /**
     * The export handler is the only hook this class owns. Registering the
     * same static callback twice is a no-op in WordPress, so it makes no
     * difference whether the module bootstrap calls this or adds the action
     * itself.
     */
    public static function register_hooks(): void {
        add_action('wp_ajax_' . self::AJAX_ACTION, [self::class, 'handle_export']);
    }

    /**
     * MxChat creates this table only in its activation routine
     * (mxchat_create_url_clicks_table, mxchat-basic/mxchat-basic.php:594), so
     * on a site where the host was installed but never activated — or where
     * dbDelta failed — it is simply absent and every query against it is a
     * fatal. Every host reader guards it exactly this way; so do we.
     */
    public static function table_exists(): bool {
        if (self::$table_exists !== null) {
            return self::$table_exists;
        }

        global $wpdb;
        $table = self::table();

        // esc_like matters here: the default `wp_` prefix contains an
        // underscore, which LIKE reads as a single-character wildcard — an
        // unescaped probe would also match `wpXmxchat_url_clicks`.
        $found = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
        );

        self::$table_exists = ($found === $table);

        return self::$table_exists;
    }

    /**
     * Forget the table probe. Its answer can only change when MxChat's
     * activation routine runs, which is normally a different request — the
     * exception being an admin activating MxChat from the plugins screen
     * after we have already probed. Also what the test suite uses to move
     * between a site that has the table and one that does not.
     */
    public static function reset_cache(): void {
        self::$table_exists = null;
    }

    /**
     * The most-clicked URLs, busiest first.
     *
     * @param int         $limit Clamped to 1..MAX_LIMIT.
     * @param string|null $from  Inclusive lower bound, `YYYY-MM-DD`.
     * @param string|null $to    Inclusive upper bound, `YYYY-MM-DD`.
     *
     * @return list<array{url:string,clicks:int,sessions:int,scope:'internal'|'external',last_click:string}>
     */
    public static function top_links(int $limit = 50, ?string $from = null, ?string $to = null): array {
        return self::aggregate(min(self::MAX_LIMIT, max(1, $limit)), $from, $to);
    }

    /**
     * Headline numbers for the same range.
     *
     * The shape is stable whether or not the host table exists, so a caller
     * can render the screen without a special case; use table_exists() to
     * tell "no clicks yet" from "MxChat never ran".
     *
     * @return array{clicks:int,sessions:int,urls:int,internal:int,external:int}
     */
    public static function totals(?string $from = null, ?string $to = null): array {
        $totals = ['clicks' => 0, 'sessions' => 0, 'urls' => 0, 'internal' => 0, 'external' => 0];

        if (!self::table_exists()) {
            return $totals;
        }

        global $wpdb;
        $table = self::table();
        $range = self::range_bounds($from, $to);

        $row = $wpdb->get_row($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix.
            "SELECT COUNT(*) AS clicks,
                    COUNT(DISTINCT session_id) AS sessions,
                    COUNT(DISTINCT clicked_url) AS urls
             FROM {$table}
             WHERE click_timestamp >= %s AND click_timestamp <= %s",
            $range['from'],
            $range['to']
        ));

        if ($row === null) {
            return $totals;
        }

        $totals['clicks']   = (int) $row->clicks;
        $totals['sessions'] = (int) $row->sessions;
        $totals['urls']     = (int) $row->urls;

        // Internal vs external is decided in PHP, never in SQL: the site's own
        // host is a runtime value (home_url() is filterable, and multisite
        // changes it per blog), and a LIKE over a TEXT column would have to
        // guess at schemes, `www.` and ports. Cheaper to classify the grouped
        // rows, of which there are far fewer than clicks.
        $offset = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix.
                "SELECT clicked_url, COUNT(*) AS clicks
                 FROM {$table}
                 WHERE click_timestamp >= %s AND click_timestamp <= %s
                 GROUP BY clicked_url
                 ORDER BY clicked_url ASC
                 LIMIT %d OFFSET %d",
                $range['from'],
                $range['to'],
                self::CLASSIFY_PAGE,
                $offset
            ));

            $rows = is_array($rows) ? $rows : [];

            foreach ($rows as $grouped) {
                $clicks = (int) $grouped->clicks;
                if (self::classify((string) $grouped->clicked_url) === self::SCOPE_INTERNAL) {
                    $totals['internal'] += $clicks;
                } else {
                    $totals['external'] += $clicks;
                }
            }

            $offset += self::CLASSIFY_PAGE;
        } while (count($rows) === self::CLASSIFY_PAGE && $offset < self::MAX_CLASSIFY_URLS);

        return $totals;
    }

    /**
     * Is this URL one of ours?
     *
     * Strict host equality after normalisation: a subdomain is a different
     * site and counts as external. A URL with no host at all — a relative
     * path, an anchor, anything parse_url() refuses — is ours by definition,
     * since a browser would have resolved it against this site.
     *
     * When home_url() yields no host (a broken or stubbed install) nothing can
     * be proven internal, so everything reads as external rather than having
     * the report quietly claim every outbound click as its own.
     *
     * @return 'internal'|'external'
     */
    public static function classify(string $url): string {
        $host = parse_url(trim($url), PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return self::SCOPE_INTERNAL;
        }

        $home = self::home_host();

        if ($home === '') {
            return self::SCOPE_EXTERNAL;
        }

        return self::normalise_host($host) === $home
            ? self::SCOPE_INTERNAL
            : self::SCOPE_EXTERNAL;
    }

    /**
     * Streams the report as CSV. Mirrors the transcripts export — same nonce
     * field name, same UTF-8 BOM — so both files behave identically in Excel.
     */
    public static function handle_export(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to export link clicks.', 'mxchat-plus'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_ACTION, 'security');

        if (!self::table_exists()) {
            wp_die(
                esc_html__('MxChat has never recorded a link click on this site: its click table does not exist.', 'mxchat-plus'),
                '',
                ['response' => 404]
            );
        }

        $rows = self::aggregate(
            self::EXPORT_MAX_ROWS,
            self::request_param('date_from'),
            self::request_param('date_to')
        );

        if ($rows === []) {
            wp_die(esc_html__('No link clicks found for that period.', 'mxchat-plus'), '', ['response' => 404]);
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="mxchat-link-clicks-' . gmdate('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        if ($output === false) {
            wp_die(esc_html__('Could not open the output stream for the export.', 'mxchat-plus'), '', ['response' => 500]);
        }

        // UTF-8 BOM: without it Excel mis-reads accented characters.
        fwrite($output, "\xEF\xBB\xBF");

        // The escape argument is passed explicitly: PHP 8.4 deprecates leaving
        // it out, and '' is both the RFC 4180 behaviour and the value PHP 9
        // will default to, so the file reads the same on every supported PHP.
        fputcsv($output, [
            __('URL', 'mxchat-plus'),
            __('Scope', 'mxchat-plus'),
            __('Clicks', 'mxchat-plus'),
            __('Sessions', 'mxchat-plus'),
            // The host stamps click_timestamp with current_time('mysql', 1),
            // so every timestamp in this file is UTC, not site time.
            __('Last click (UTC)', 'mxchat-plus'),
        ], ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($output, [
                self::neutralise_formula($row['url']),
                $row['scope'] === self::SCOPE_INTERNAL
                    ? __('Internal', 'mxchat-plus')
                    : __('External', 'mxchat-plus'),
                $row['clicks'],
                $row['sessions'],
                $row['last_click'],
            ], ',', '"', '');
        }

        fclose($output);
        exit;
    }

    /**
     * The one grouped query behind both the screen and the CSV; they differ
     * only in how many rows they are willing to carry.
     *
     * @return list<array{url:string,clicks:int,sessions:int,scope:'internal'|'external',last_click:string}>
     */
    private static function aggregate(int $limit, ?string $from, ?string $to): array {
        if (!self::table_exists()) {
            return [];
        }

        global $wpdb;
        $table = self::table();
        $range = self::range_bounds($from, $to);

        $rows = $wpdb->get_results($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix.
            "SELECT clicked_url,
                    COUNT(*) AS clicks,
                    COUNT(DISTINCT session_id) AS sessions,
                    MAX(click_timestamp) AS last_click
             FROM {$table}
             WHERE click_timestamp >= %s AND click_timestamp <= %s
             GROUP BY clicked_url
             ORDER BY clicks DESC, last_click DESC
             LIMIT %d",
            $range['from'],
            $range['to'],
            max(1, $limit)
        ));

        if (!is_array($rows)) {
            return [];
        }

        $report = [];
        foreach ($rows as $row) {
            $url = (string) $row->clicked_url;
            $report[] = [
                'url'        => $url,
                'clicks'     => (int) $row->clicks,
                'sessions'   => (int) $row->sessions,
                'scope'      => self::classify($url),
                'last_click' => (string) $row->last_click,
            ];
        }

        return $report;
    }

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::HOST_TABLE;
    }

    /**
     * Turn an optional `YYYY-MM-DD` pair into inclusive SQL bounds, falling
     * back to the full DATETIME domain.
     *
     * The widening of the end bound to 23:59:59 — and the swap of a reversed
     * pair — is the transcripts module's logic, called rather than copied so
     * the two exports can never drift apart on what "the 4th to the 4th"
     * means. Both bounds or neither: a lone bound is unusable to that
     * sanitiser and degrades to no filtering at all.
     *
     * @return array{from:string,to:string}
     */
    private static function range_bounds(?string $from, ?string $to): array {
        $full = ['from' => self::RANGE_MIN, 'to' => self::RANGE_MAX];

        if ($from === null || $to === null) {
            return $full;
        }

        // The transcripts class is always autoloadable, but this module can be
        // enabled on its own — never let a missing sibling fatal the report.
        if (!class_exists('MxChat_Plus_Transcripts_Export')) {
            return $full;
        }

        $range = MxChat_Plus_Transcripts_Export::sanitize_date_range($from, $to);

        return $range ?? $full;
    }

    /**
     * The site's own host, normalised, or '' when it cannot be determined.
     *
     * Split out from classify() so the classification itself stays pure PHP
     * over its argument, testable without a WordPress runtime.
     */
    private static function home_host(): string {
        $host = parse_url(home_url('/'), PHP_URL_HOST);

        return is_string($host) ? self::normalise_host($host) : '';
    }

    /**
     * Lower-cased, with the trailing root dot and a leading `www.` removed:
     * a link to www.example.com on a site served from example.com is the same
     * site by any reading a report reader would recognise.
     */
    private static function normalise_host(string $host): string {
        $host = strtolower(rtrim(trim($host), '.'));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * check_admin_referer() accepts its nonce from $_REQUEST, so the export
     * works both as a posted form and as a plain link; the date parameters
     * follow the same rule rather than pinning one transport.
     */
    private static function request_param(string $key): string {
        if (!isset($_REQUEST[$key]) || !is_scalar($_REQUEST[$key])) {
            return '';
        }

        return sanitize_text_field(wp_unslash((string) $_REQUEST[$key]));
    }

    /**
     * CSV injection guard, same as the transcripts export. A clicked URL is
     * visitor-influenced text — the host stores whatever the page linked to —
     * and a cell opening with =, +, - or @ is executed as a formula by Excel
     * and Sheets. A tab prefix keeps it readable and strips that meaning.
     */
    private static function neutralise_formula(?string $value): string {
        $value = (string) $value;
        if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
            return "\t" . $value;
        }
        return $value;
    }
}
