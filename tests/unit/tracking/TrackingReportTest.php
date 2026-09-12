<?php
/**
 * The server-side click report: internal/external classification, the table
 * probe, and the shape of the two aggregates the admin screen renders.
 *
 * The report reads MxChat's own `{prefix}mxchat_url_clicks` table, which
 * exists only if the host's activation routine ever ran — so the probe, and
 * the "table absent" branch behind it, are load-bearing rather than defensive
 * decoration. The rest is classification, which decides what a site owner is
 * told about where their visitors went; getting it wrong is not a crash but a
 * report that quietly lies.
 *
 * $wpdb double: this file rolls its own rather than using MxChat_Test_WPDB,
 * which has neither esc_like() nor get_row() — both of which this class calls.
 * The shims are shared and off-limits to this suite, and a subclass would bind
 * us to signatures that may change there; a standalone double cannot clash.
 * It can be dropped the day the shim grows those two methods.
 */

use PHPUnit\Framework\TestCase;

// The second argument MUST stay false. composer.json maps `tests/` into the
// autoload-dev classmap, so a CI-fresh `composer install` resolves
// MxChat_Plus_Tracking_Test_WPDB to *this* file. An autoloading class_exists()
// therefore re-includes the file while it is still being parsed: the nested
// pass declares TrackingReportTest, the outer pass then reaches line 97 and
// dies with "Cannot declare class TrackingReportTest". It passes locally only
// while vendor/composer/autoload_classmap.php predates this file — run
// `composer dump-autoload` and it fails here too.
if (!class_exists('MxChat_Plus_Tracking_Test_WPDB', false)) {

    /**
     * Records every SQL it is handed and answers with canned rows matched by
     * substring, the same contract as MxChat_Test_WPDB so the two read alike.
     */
    class MxChat_Plus_Tracking_Test_WPDB {

        public string $prefix = 'wp_';

        /** @var string[] every SQL passed through, in order */
        public array $log = [];

        /** @var array<string,mixed> pattern → rows, or a callable taking the SQL */
        public array $responses = [];

        const NOT_FOUND = '__mxp_tracking_not_found__';

        /** @param mixed $value */
        public function set_response(string $pattern, $value): void {
            $this->responses[$pattern] = $value;
        }

        /** @return mixed */
        private function find(string $sql) {
            foreach ($this->responses as $pattern => $value) {
                if (stripos($sql, $pattern) !== false) {
                    return is_callable($value) ? $value($sql) : $value;
                }
            }
            return self::NOT_FOUND;
        }

        /** @return mixed */
        public function get_var(string $sql) {
            $this->log[] = $sql;
            $r = $this->find($sql);
            return $r === self::NOT_FOUND ? null : $r;
        }

        /** @return mixed */
        public function get_row(string $sql, $output = null, $row_offset = 0) {
            $this->log[] = $sql;
            $r = $this->find($sql);
            return $r === self::NOT_FOUND ? null : $r;
        }

        /** @return mixed */
        public function get_results(string $sql, $output = null) {
            $this->log[] = $sql;
            $r = $this->find($sql);
            return $r === self::NOT_FOUND ? [] : $r;
        }

        /** Minimal sprintf-shaped prepare, mirroring MxChat_Test_WPDB's. */
        public function prepare(string $sql, ...$args): string {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }
            $i = 0;
            return (string) preg_replace_callback('/%[sdfiF]/', function ($m) use (&$i, $args) {
                $v = $args[$i++] ?? null;
                if (is_int($v) || is_float($v)) return (string) $v;
                if ($v === null)               return 'NULL';
                return "'" . str_replace("'", "''", (string) $v) . "'";
            }, $sql);
        }

        /** As WordPress does it: _ and % are LIKE wildcards, \ escapes them. */
        public function esc_like(string $text): string {
            return addcslashes($text, '_%\\');
        }
    }
}

final class TrackingReportTest extends TestCase {

    private MxChat_Plus_Tracking_Test_WPDB $wpdb;

    /** @var mixed */
    private $previous_wpdb;

    protected function setUp(): void {
        $GLOBALS['__test_options'] = [];
        $GLOBALS['__test_home_url'] = 'https://example.test';

        // Put back whatever was there rather than dropping it: the suite is
        // one PHP process and file order is not guaranteed (--order-by).
        $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
        $this->wpdb          = new MxChat_Plus_Tracking_Test_WPDB();
        $GLOBALS['wpdb']     = $this->wpdb;

        // The probe is memoised for the request; each test starts unprobed.
        MxChat_Plus_Tracking_Report::reset_cache();
    }

    protected function tearDown(): void {
        // Neither the memoised probe nor the home host may survive into
        // another test file: both are process-wide state.
        MxChat_Plus_Tracking_Report::reset_cache();
        unset($GLOBALS['__test_home_url']);

        if ($this->previous_wpdb === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->previous_wpdb;
        }
    }

    /** Make the host table answer the probe. */
    private function withTable(): void {
        $this->wpdb->set_response('SHOW TABLES', 'wp_mxchat_url_clicks');
    }

    /** @param array<string,mixed> $fields */
    private static function row(array $fields): stdClass {
        return (object) $fields;
    }

    /** The SQL of the last statement the report issued. */
    private function lastSql(): string {
        $this->assertNotSame([], $this->wpdb->log, 'expected the report to query something');
        return (string) end($this->wpdb->log);
    }

    // ─── classify(): ours ─────────────────────────────────────────────────

    /** @dataProvider internalUrls */
    public function test_urls_on_this_site_are_internal(string $url, string $why): void {
        $this->assertSame(MxChat_Plus_Tracking_Report::SCOPE_INTERNAL, MxChat_Plus_Tracking_Report::classify($url), $why);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function internalUrls(): array {
        return [
            'same host'          => ['https://example.test/contact', 'the site itself'],
            'same host http'     => ['http://example.test/contact', 'scheme is not part of identity here'],
            'www variant'        => ['https://www.example.test/contact', 'www. is stripped from both sides'],
            'uppercase host'     => ['https://EXAMPLE.TEST/contact', 'hosts are case-insensitive'],
            'trailing root dot'  => ['https://example.test./contact', 'the DNS root dot names the same host'],
            'protocol relative'  => ['//example.test/contact', 'a browser resolves this to the current scheme'],
            'relative path'      => ['/contact', 'no host: the browser resolved it against this site'],
            'bare anchor'        => ['#top', 'same page'],
            'query only'         => ['?utm_source=chat', 'same page'],
            'surrounding space'  => ['  https://example.test/contact  ', 'the host trims before parsing'],
            'unparseable'        => ['http:///no-host', 'parse_url refuses it; there is no host to call foreign'],
            'not a url'          => ['not a url at all', 'nothing to resolve against but this site'],
            'empty'              => ['', 'nothing at all'],
        ];
    }

    /**
     * A mailto: (or tel:) link has no host, so it lands in the internal
     * bucket. Documenting it rather than fixing it: the alternative is a third
     * scope the screen has no column for, and MxChat's own click handler only
     * binds absolute http(s) links (mxchat-basic/js/chat-script.js:2188), so
     * such a row can only arrive from a hand-written POST.
     */
    public function test_a_hostless_scheme_counts_as_internal(): void {
        $this->assertSame(
            MxChat_Plus_Tracking_Report::SCOPE_INTERNAL,
            MxChat_Plus_Tracking_Report::classify('mailto:hello@example.test')
        );
    }

    /**
     * PHP_URL_HOST excludes the port, so the same host on another port is the
     * same site to this report. That is the intent for the usual case (a
     * staging install served on :8080), and the port is not something the
     * screen shows anyway.
     */
    public function test_a_different_port_on_the_same_host_is_still_internal(): void {
        $this->assertSame(
            MxChat_Plus_Tracking_Report::SCOPE_INTERNAL,
            MxChat_Plus_Tracking_Report::classify('https://example.test:8080/contact')
        );
    }

    public function test_home_url_written_with_www_still_matches_the_bare_host(): void {
        $GLOBALS['__test_home_url'] = 'https://www.example.test';

        $this->assertSame(
            MxChat_Plus_Tracking_Report::SCOPE_INTERNAL,
            MxChat_Plus_Tracking_Report::classify('https://example.test/contact')
        );
        $this->assertSame(
            MxChat_Plus_Tracking_Report::SCOPE_INTERNAL,
            MxChat_Plus_Tracking_Report::classify('https://www.example.test/contact')
        );
    }

    public function test_classification_follows_a_moved_home_url(): void {
        // home_url() is filterable and differs per blog on multisite, so it is
        // read per call rather than cached.
        $this->assertSame(
            MxChat_Plus_Tracking_Report::SCOPE_EXTERNAL,
            MxChat_Plus_Tracking_Report::classify('https://other.test/page')
        );

        $GLOBALS['__test_home_url'] = 'https://other.test';

        $this->assertSame(
            MxChat_Plus_Tracking_Report::SCOPE_INTERNAL,
            MxChat_Plus_Tracking_Report::classify('https://other.test/page')
        );
    }

    // ─── classify(): not ours ─────────────────────────────────────────────

    /** @dataProvider externalUrls */
    public function test_urls_elsewhere_are_external(string $url, string $why): void {
        $this->assertSame(MxChat_Plus_Tracking_Report::SCOPE_EXTERNAL, MxChat_Plus_Tracking_Report::classify($url), $why);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function externalUrls(): array {
        return [
            'another site'      => ['https://other.test/page', 'plainly outbound'],
            'subdomain'         => ['https://blog.example.test/page', 'a different site, however related'],
            'www of subdomain'  => ['https://www.blog.example.test/page', 'still a different site'],
            'suffix lookalike'  => ['https://example.test.evil.test/page', 'the match is on the whole host, not a prefix'],
            'prefix lookalike'  => ['https://notexample.test/page', 'nor a suffix'],
            'protocol relative' => ['//other.test/page', 'no scheme, but a host that is not ours'],
        ];
    }

    /**
     * A stubbed or broken install where home_url() yields no host: nothing can
     * be *proven* internal, so the report must not claim outbound clicks as
     * its own. A URL with no host of its own is still internal — a browser had
     * nowhere else to resolve it.
     */
    public function test_an_unusable_home_url_makes_everything_with_a_host_external(): void {
        $GLOBALS['__test_home_url'] = 'not-a-url';

        $this->assertSame(
            MxChat_Plus_Tracking_Report::SCOPE_EXTERNAL,
            MxChat_Plus_Tracking_Report::classify('https://example.test/contact')
        );
        $this->assertSame(
            MxChat_Plus_Tracking_Report::SCOPE_INTERNAL,
            MxChat_Plus_Tracking_Report::classify('/contact')
        );
    }

    public function test_classify_only_ever_returns_the_two_scope_constants(): void {
        // The screen and the CSV both branch on these literals.
        $this->assertSame('internal', MxChat_Plus_Tracking_Report::SCOPE_INTERNAL);
        $this->assertSame('external', MxChat_Plus_Tracking_Report::SCOPE_EXTERNAL);

        foreach (['https://example.test/a', 'https://other.test/a', '/a', '', 'mailto:a@b.test'] as $url) {
            $this->assertContains(
                MxChat_Plus_Tracking_Report::classify($url),
                [MxChat_Plus_Tracking_Report::SCOPE_INTERNAL, MxChat_Plus_Tracking_Report::SCOPE_EXTERNAL]
            );
        }
    }

    // ─── table_exists() ───────────────────────────────────────────────────

    public function test_table_is_found_when_the_probe_returns_its_name(): void {
        $this->withTable();

        $this->assertTrue(MxChat_Plus_Tracking_Report::table_exists());
    }

    public function test_table_is_absent_when_the_probe_returns_nothing(): void {
        // MxChat installed but never activated: no table, and every query
        // against it would be fatal.
        $this->assertFalse(MxChat_Plus_Tracking_Report::table_exists());
    }

    public function test_a_probe_that_matches_another_table_is_not_accepted(): void {
        // The comparison is an identity check on the name, not a truthiness
        // test on whatever LIKE happened to return.
        $this->wpdb->set_response('SHOW TABLES', 'wp_mxchat_url_clicks_backup');

        $this->assertFalse(MxChat_Plus_Tracking_Report::table_exists());
    }

    public function test_the_probe_escapes_the_underscores_in_the_table_name(): void {
        $this->withTable();
        MxChat_Plus_Tracking_Report::table_exists();

        // Unescaped, each _ is a single-character LIKE wildcard and the probe
        // would also match wpXmxchatYurlZclicks.
        $this->assertStringContainsString('wp\\_mxchat\\_url\\_clicks', $this->lastSql());
    }

    public function test_the_probe_is_memoised_for_the_request(): void {
        $this->withTable();

        MxChat_Plus_Tracking_Report::table_exists();
        $after_first = count($this->wpdb->log);
        MxChat_Plus_Tracking_Report::table_exists();

        $this->assertSame($after_first, count($this->wpdb->log), 'the probe must not run twice');
    }

    public function test_reset_cache_forces_a_fresh_probe(): void {
        $this->assertFalse(MxChat_Plus_Tracking_Report::table_exists());

        // What an admin activating MxChat mid-request looks like.
        $this->withTable();
        MxChat_Plus_Tracking_Report::reset_cache();

        $this->assertTrue(MxChat_Plus_Tracking_Report::table_exists());
    }

    // ─── top_links() ──────────────────────────────────────────────────────

    public function test_top_links_is_empty_and_silent_without_the_host_table(): void {
        $this->assertSame([], MxChat_Plus_Tracking_Report::top_links());
        // Only the probe ran: no SELECT was issued against a table that would
        // have thrown.
        $this->assertCount(1, $this->wpdb->log);
    }

    public function test_top_links_shapes_and_types_every_row(): void {
        $this->withTable();
        $this->wpdb->set_response('ORDER BY clicks DESC', [
            self::row(['clicked_url' => 'https://example.test/pricing', 'clicks' => '9', 'sessions' => '4', 'last_click' => '2026-02-03 10:11:12']),
            self::row(['clicked_url' => 'https://other.test/docs',     'clicks' => '2', 'sessions' => '2', 'last_click' => '2026-02-01 08:00:00']),
        ]);

        $rows = MxChat_Plus_Tracking_Report::top_links();

        // The exact keys the admin view and the CSV both read.
        $this->assertSame(['url', 'clicks', 'sessions', 'scope', 'last_click'], array_keys($rows[0]));
        $this->assertSame([
            'url'        => 'https://example.test/pricing',
            'clicks'     => 9,
            'sessions'   => 4,
            'scope'      => 'internal',
            'last_click' => '2026-02-03 10:11:12',
        ], $rows[0]);
        // MySQL hands back counts as strings; the report casts them so the
        // screen can format them as numbers.
        $this->assertSame(2, $rows[1]['clicks']);
        $this->assertSame('external', $rows[1]['scope']);
    }

    public function test_top_links_survives_a_failed_query(): void {
        $this->withTable();
        // wpdb returns null, not an array, when the query errors.
        $this->wpdb->set_response('ORDER BY clicks DESC', null);

        $this->assertSame([], MxChat_Plus_Tracking_Report::top_links());
    }

    public function test_top_links_clamps_the_row_limit(): void {
        $this->withTable();

        MxChat_Plus_Tracking_Report::top_links(10000);
        $this->assertStringContainsString('LIMIT ' . MxChat_Plus_Tracking_Report::MAX_LIMIT, $this->lastSql());

        // A caller cannot ask for the world, nor for nothing at all: LIMIT 0
        // would return an empty report that looks like "no clicks".
        MxChat_Plus_Tracking_Report::top_links(0);
        $this->assertStringContainsString('LIMIT 1', $this->lastSql());

        MxChat_Plus_Tracking_Report::top_links(-5);
        $this->assertStringContainsString('LIMIT 1', $this->lastSql());

        MxChat_Plus_Tracking_Report::top_links(25);
        $this->assertStringContainsString('LIMIT 25', $this->lastSql());
    }

    public function test_an_unbounded_report_still_queries_with_placeholders(): void {
        $this->withTable();

        MxChat_Plus_Tracking_Report::top_links();

        // No range means the full DATETIME domain, not a dropped predicate:
        // wpdb::prepare() raises _doing_it_wrong for a query with no
        // placeholder, so the alternative is a second, unprepared code path.
        $sql = $this->lastSql();
        $this->assertStringContainsString(MxChat_Plus_Tracking_Report::RANGE_MIN, $sql);
        $this->assertStringContainsString(MxChat_Plus_Tracking_Report::RANGE_MAX, $sql);
    }

    public function test_a_date_range_becomes_inclusive_sql_bounds(): void {
        $this->withTable();

        MxChat_Plus_Tracking_Report::top_links(50, '2026-01-05', '2026-01-31');

        $sql = $this->lastSql();
        $this->assertStringContainsString('2026-01-05 00:00:00', $sql);
        // Widened to the end of the day: compared against midnight, a
        // DATETIME column would silently drop the final day's clicks.
        $this->assertStringContainsString('2026-01-31 23:59:59', $sql);
    }

    public function test_a_reversed_date_range_is_swapped_rather_than_returning_nothing(): void {
        $this->withTable();

        MxChat_Plus_Tracking_Report::top_links(50, '2026-05-20', '2026-05-01');

        $sql = $this->lastSql();
        $this->assertStringContainsString('2026-05-01 00:00:00', $sql);
        $this->assertStringContainsString('2026-05-20 23:59:59', $sql);
    }

    /**
     * @dataProvider unusableRanges
     * @param string|null $from
     * @param string|null $to
     */
    public function test_an_unusable_range_degrades_to_the_whole_table($from, $to): void {
        $this->withTable();

        MxChat_Plus_Tracking_Report::top_links(50, $from, $to);

        // Never an empty result dressed up as "no clicks in that period".
        $this->assertStringContainsString(MxChat_Plus_Tracking_Report::RANGE_MIN, $this->lastSql());
    }

    /** @return array<string,array{0:?string,1:?string}> */
    public static function unusableRanges(): array {
        return [
            'both null'     => [null, null],
            'lone start'    => ['2026-01-01', null],
            'lone end'      => [null, '2026-01-01'],
            'empty strings' => ['', ''],
            'not dates'     => ['yesterday', 'today'],
            'impossible day' => ['2026-02-30', '2026-03-01'],
            'injection'     => ["2026-01-01' OR '1'='1", '2026-01-31'],
        ];
    }

    // ─── totals() ─────────────────────────────────────────────────────────

    public function test_totals_is_zero_filled_without_the_host_table(): void {
        // A stable shape, so the screen renders with no special case; the
        // caller uses table_exists() to tell this from "no clicks yet".
        $this->assertSame(
            ['clicks' => 0, 'sessions' => 0, 'urls' => 0, 'internal' => 0, 'external' => 0],
            MxChat_Plus_Tracking_Report::totals()
        );
    }

    public function test_totals_is_zero_filled_when_the_count_query_fails(): void {
        $this->withTable();
        $this->wpdb->set_response('AS urls', null);

        $this->assertSame(
            ['clicks' => 0, 'sessions' => 0, 'urls' => 0, 'internal' => 0, 'external' => 0],
            MxChat_Plus_Tracking_Report::totals()
        );
    }

    public function test_totals_counts_clicks_sessions_and_distinct_urls(): void {
        $this->withTable();
        $this->wpdb->set_response('AS urls', self::row(['clicks' => '12', 'sessions' => '5', 'urls' => '3']));
        $this->wpdb->set_response('ORDER BY clicked_url ASC', [
            self::row(['clicked_url' => 'https://example.test/a', 'clicks' => '7']),
            self::row(['clicked_url' => 'https://other.test/b',   'clicks' => '4']),
            self::row(['clicked_url' => '/c',                     'clicks' => '1']),
        ]);

        $totals = MxChat_Plus_Tracking_Report::totals();

        $this->assertSame(
            ['clicks', 'sessions', 'urls', 'internal', 'external'],
            array_keys($totals)
        );
        $this->assertSame(12, $totals['clicks']);
        $this->assertSame(5, $totals['sessions']);
        $this->assertSame(3, $totals['urls']);
        // 7 on our own page + 1 on a relative path.
        $this->assertSame(8, $totals['internal']);
        $this->assertSame(4, $totals['external']);
        // The split is over clicks, not over URLs, so it must reconcile with
        // the headline figure — an admin will do that subtraction by eye.
        $this->assertSame($totals['clicks'], $totals['internal'] + $totals['external']);
    }

    public function test_totals_pages_through_the_grouped_urls(): void {
        $this->withTable();
        $this->wpdb->set_response('AS urls', self::row([
            'clicks'   => (string) (MxChat_Plus_Tracking_Report::CLASSIFY_PAGE + 2),
            'sessions' => '1',
            'urls'     => (string) (MxChat_Plus_Tracking_Report::CLASSIFY_PAGE + 2),
        ]));

        // A full page of internal URLs, then a short page of two external
        // ones: a classifier that stopped after the first page would report
        // every outbound click as internal.
        $first = [];
        for ($i = 0; $i < MxChat_Plus_Tracking_Report::CLASSIFY_PAGE; $i++) {
            $first[] = self::row(['clicked_url' => 'https://example.test/p' . $i, 'clicks' => '1']);
        }
        $second = [
            self::row(['clicked_url' => 'https://other.test/x', 'clicks' => '1']),
            self::row(['clicked_url' => 'https://other.test/y', 'clicks' => '1']),
        ];

        $this->wpdb->set_response('ORDER BY clicked_url ASC', function (string $sql) use ($first, $second) {
            return stripos($sql, 'OFFSET 0') !== false ? $first : $second;
        });

        $totals = MxChat_Plus_Tracking_Report::totals();

        $this->assertSame(MxChat_Plus_Tracking_Report::CLASSIFY_PAGE, $totals['internal']);
        $this->assertSame(2, $totals['external']);

        $offsets = array_filter($this->wpdb->log, static function (string $sql): bool {
            return stripos($sql, 'ORDER BY clicked_url ASC') !== false;
        });
        $this->assertCount(2, $offsets, 'a full page must be followed by another request');
        $this->assertStringContainsString(
            'OFFSET ' . MxChat_Plus_Tracking_Report::CLASSIFY_PAGE,
            implode("\n", $offsets)
        );
    }

    public function test_totals_stops_paging_on_a_short_page(): void {
        $this->withTable();
        $this->wpdb->set_response('AS urls', self::row(['clicks' => '1', 'sessions' => '1', 'urls' => '1']));
        $this->wpdb->set_response('ORDER BY clicked_url ASC', [
            self::row(['clicked_url' => 'https://example.test/a', 'clicks' => '1']),
        ]);

        MxChat_Plus_Tracking_Report::totals();

        $pages = array_filter($this->wpdb->log, static function (string $sql): bool {
            return stripos($sql, 'ORDER BY clicked_url ASC') !== false;
        });
        $this->assertCount(1, $pages);
    }

    // ─── Wiring ───────────────────────────────────────────────────────────

    public function test_the_host_table_name_is_the_hosts_own(): void {
        // A host symbol. Renaming it points the report at a table nobody
        // writes to, and the screen simply reports zero clicks forever.
        $this->assertSame('mxchat_url_clicks', MxChat_Plus_Tracking_Report::HOST_TABLE);
    }

    public function test_the_export_action_is_namespaced_to_this_plugin(): void {
        $this->assertSame('mxchat_plus_tracking_export', MxChat_Plus_Tracking_Report::AJAX_ACTION);
        $this->assertStringStartsWith('mxchat_plus_', MxChat_Plus_Tracking_Report::NONCE_ACTION);
    }

    public function test_the_csv_carries_more_rows_than_the_screen(): void {
        $this->assertGreaterThan(
            MxChat_Plus_Tracking_Report::MAX_LIMIT,
            MxChat_Plus_Tracking_Report::EXPORT_MAX_ROWS
        );
    }
}
