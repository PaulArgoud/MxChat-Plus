<?php
/**
 * Transcripts CSV export — the file's shape, each cell's content, and the
 * query that feeds it.
 *
 * handle_export() ends in `exit`, so it is never called here; everything it
 * delegates to is: the header, the row mapping, the byte-level writer (into
 * php://memory) and the query builder (against the $wpdb shim).
 */

use PHPUnit\Framework\TestCase;

final class TranscriptsCsvTest extends TestCase {

    /** @var mixed */
    private $previous_wpdb;

    protected function setUp(): void {
        $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
    }

    protected function tearDown(): void {
        if ($this->previous_wpdb === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->previous_wpdb;
        }
    }

    /** @param array<string,mixed> $fields */
    private static function row(array $fields): object {
        return (object) array_merge([
            'session_id'           => 'mxchat_chat_abc',
            'user_email'           => 'marie@example.test',
            'user_identifier'      => '203.0.113.7',
            'role'                 => 'user',
            'message'              => 'Bonjour',
            'timestamp'            => '2026-10-15 22:30:00',
            'user_name'            => 'Marie',
            'originating_page_url' => 'https://example.test/pricing/',
        ], $fields);
    }

    /** @return list<string> */
    private static function cells(object $row): array {
        return MxChat_Plus_Transcripts_Export::build_csv_row($row);
    }

    /** @param iterable<object> $rows */
    private static function csv(iterable $rows): string {
        $h = fopen('php://memory', 'w+');
        self::assertIsResource($h);
        MxChat_Plus_Transcripts_Export::write_csv($h, $rows);
        rewind($h);
        $out = (string) stream_get_contents($h);
        fclose($h);
        return $out;
    }

    /** @return list<list<string|null>> */
    private static function parse(string $csv): array {
        $h = fopen('php://memory', 'w+');
        self::assertIsResource($h);
        fwrite($h, $csv);
        rewind($h);
        // Skip the BOM the writer puts first.
        fread($h, 3);
        $rows = [];
        while (($r = fgetcsv($h, null, ',', '"', '')) !== false) {
            $rows[] = $r;
        }
        fclose($h);
        return $rows;
    }

    /** @return mixed */
    private static function query_rows(array $ids, ?array $range) {
        $m = new ReflectionMethod(MxChat_Plus_Transcripts_Export::class, 'query_rows');
        return $m->invoke(null, $ids, $range);
    }

    // ─── Header ──────────────────────────────────────────────────────────

    public function test_the_host_export_columns_come_first_in_the_host_order(): void {
        $header = MxChat_Plus_Transcripts_Export::csv_header();

        // Positions 0-5 are the host's own export (Session ID, Email, User
        // Identifier, Role, Message, Timestamp), so a sheet built on the
        // host's file reads ours by the same column numbers.
        $this->assertSame(
            ['Session ID', 'Email', 'User identifier', 'Role', 'Message', 'Timestamp (UTC)'],
            array_slice($header, 0, 6)
        );
    }

    public function test_the_extra_columns_are_appended_never_inserted(): void {
        $header = MxChat_Plus_Transcripts_Export::csv_header();

        $this->assertCount(8, $header);
        $this->assertSame('Name', $header[6]);
        $this->assertSame('Originating page', $header[7]);
    }

    public function test_the_timestamp_header_names_its_timezone(): void {
        // The host stores GMT and its panel shows site time; an unqualified
        // "Timestamp" reads as the latter.
        $this->assertContains('Timestamp (UTC)', MxChat_Plus_Transcripts_Export::csv_header());
        $this->assertNotContains('Timestamp', MxChat_Plus_Transcripts_Export::csv_header());
    }

    // ─── Row mapping ─────────────────────────────────────────────────────

    public function test_a_row_follows_the_header_order(): void {
        $this->assertSame(
            [
                'mxchat_chat_abc',
                'marie@example.test',
                '203.0.113.7',
                'user',
                'Bonjour',
                '2026-10-15 22:30:00',
                'Marie',
                'https://example.test/pricing/',
            ],
            self::cells(self::row([]))
        );
    }

    public function test_a_name_only_lead_keeps_its_one_identity(): void {
        // Since MxChat 3.2.22 the pre-chat form can take a name without an
        // email; without the Name column such a row named nobody.
        $cells = self::cells(self::row(['user_email' => '', 'user_name' => 'Marie']));

        $this->assertSame('', $cells[1]);
        $this->assertSame('Marie', $cells[6]);
    }

    public function test_columns_the_table_lacks_come_out_empty(): void {
        $row = (object) [
            'session_id'      => 's1',
            'user_email'      => null,
            'user_identifier' => 'guest',
            'role'            => 'bot',
            'message'         => 'Hi',
            'timestamp'       => '2026-01-01 10:00:00',
        ];
        $cells = self::cells($row);

        $this->assertCount(count(MxChat_Plus_Transcripts_Export::csv_header()), $cells);
        $this->assertSame('', $cells[1], 'a NULL email');
        $this->assertSame('', $cells[6], 'no user_name column');
        $this->assertSame('', $cells[7], 'no originating_page_url column');
    }

    /**
     * @dataProvider guardedColumns
     */
    public function test_every_visitor_influenced_cell_is_formula_guarded(string $field, int $index): void {
        $cells = self::cells(self::row([$field => '=HYPERLINK("http://evil.test","x")']));

        $this->assertSame("\t" . '=HYPERLINK("http://evil.test","x")', $cells[$index]);
    }

    /** @return array<string,array{0:string,1:int}> */
    public static function guardedColumns(): array {
        return [
            'session id' => ['session_id', 0],
            'email'      => ['user_email', 1],
            'identifier' => ['user_identifier', 2],
            'name'       => ['user_name', 6],
            'page url'   => ['originating_page_url', 7],
        ];
    }

    public function test_a_login_starting_with_a_dash_is_guarded(): void {
        $cells = self::cells(self::row(['user_identifier' => '-2+3']));
        $this->assertSame("\t-2+3", $cells[2]);
    }

    public function test_the_timestamp_is_written_as_stored(): void {
        $cells = self::cells(self::row(['timestamp' => '2026-10-15 22:30:00']));
        $this->assertSame('2026-10-15 22:30:00', $cells[5]);
    }

    // ─── Message decoding ────────────────────────────────────────────────

    public function test_a_visitor_message_loses_its_magic_quotes_and_entities(): void {
        // As stored: the host reads $_POST unslashed and runs wp_kses() over it.
        $stored = 'I\\\'m looking for \\"Tom &amp; Jerry\\"';
        $cells  = self::cells(self::row(['role' => 'user', 'message' => $stored]));

        $this->assertSame('I\'m looking for "Tom & Jerry"', $cells[4]);
    }

    /**
     * @dataProvider nonUserRoles
     */
    public function test_other_roles_are_exported_exactly_as_stored(string $role): void {
        // Never magic-quoted: stripping would destroy a real backslash, and
        // decoding would turn an escaped tag in an HTML reply into markup.
        $stored = 'Use C:\\path\\to\\file and &lt;b&gt; &amp; \\"quoted\\"';
        $cells  = self::cells(self::row(['role' => $role, 'message' => $stored]));

        $this->assertSame($stored, $cells[4]);
    }

    /** @return array<string,array{0:string}> */
    public static function nonUserRoles(): array {
        return [
            'bot'    => ['bot'],
            'agent'  => ['agent'],
            'system' => ['system'],
        ];
    }

    public function test_decoding_happens_before_the_formula_guard(): void {
        // `&#061;` is an encoded `=`: guarding first would let it through.
        $cells = self::cells(self::row(['role' => 'user', 'message' => '&#061;1+1']));
        $this->assertSame("\t=1+1", $cells[4]);

        $cells = self::cells(self::row(['role' => 'user', 'message' => '&#x40;SUM(A1)']));
        $this->assertSame("\t@SUM(A1)", $cells[4]);
    }

    // ─── Bytes on the wire ───────────────────────────────────────────────

    public function test_the_file_starts_with_a_bom_then_the_header(): void {
        $csv = self::csv([]);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertSame([MxChat_Plus_Transcripts_Export::csv_header()], self::parse($csv));
    }

    public function test_quotes_and_commas_round_trip_with_the_right_column_count(): void {
        $message = 'He said "hi, there"';
        $csv     = self::csv([self::row(['role' => 'bot', 'message' => $message])]);
        $lines   = explode("\n", rtrim(substr($csv, 3), "\n"));

        $this->assertCount(2, $lines);
        $cells = str_getcsv($lines[1], ',', '"', '');
        $this->assertCount(8, $cells);
        $this->assertSame($message, $cells[4]);
    }

    public function test_a_backslash_before_a_quote_does_not_break_the_row(): void {
        // A bot reply with a code sample: with fputcsv's default `\` escape the
        // `\"` is not doubled, and an RFC 4180 reader shifts every column after
        // it. With an empty escape every quote is doubled.
        $message = "printf(\"a\\\"b, c\");\nnext line";
        $csv     = self::csv([
            self::row(['role' => 'bot', 'message' => $message]),
            self::row(['session_id' => 'mxchat_chat_def', 'message' => 'second']),
        ]);

        $this->assertStringContainsString('"printf(""a\\""b, c"");', $csv);

        $rows = self::parse($csv);
        $this->assertCount(3, $rows, 'header + two messages, the newline inside a cell included');
        $this->assertCount(8, $rows[1]);
        $this->assertSame($message, $rows[1][4]);
        $this->assertSame('mxchat_chat_def', $rows[2][0]);
    }

    public function test_a_slashed_visitor_quote_round_trips_as_typed(): void {
        $csv  = self::csv([self::row(['role' => 'user', 'message' => 'say \\"hi, there\\"'])]);
        $rows = self::parse($csv);

        $this->assertCount(8, $rows[1]);
        $this->assertSame('say "hi, there"', $rows[1][4]);
    }

    // ─── SELECT list ─────────────────────────────────────────────────────

    public function test_a_table_without_the_optional_columns_gets_the_host_six(): void {
        $this->assertSame(
            'session_id, user_email, user_identifier, role, message, timestamp',
            MxChat_Plus_Transcripts_Export::select_columns(['id', 'session_id', 'role', 'message'])
        );
    }

    public function test_optional_columns_are_selected_when_present_in_a_fixed_order(): void {
        $this->assertSame(
            'session_id, user_email, user_identifier, role, message, timestamp, user_name, originating_page_url',
            MxChat_Plus_Transcripts_Export::select_columns(['ORIGINATING_PAGE_URL', 'user_name', 'rag_context'])
        );
    }

    public function test_unknown_columns_never_reach_the_select_list(): void {
        $sql = MxChat_Plus_Transcripts_Export::select_columns(['user_name', 'x; DROP TABLE wp_users', 'rag_context']);

        $this->assertStringNotContainsString('DROP', $sql);
        $this->assertStringNotContainsString('rag_context', $sql);
    }

    // ─── Query ───────────────────────────────────────────────────────────

    public function test_a_selection_query_binds_every_id_and_breaks_ties_on_id(): void {
        $wpdb = new MxChat_Test_WPDB();
        $wpdb->set_response('SHOW COLUMNS', ['id', 'session_id', 'user_name']);
        $wpdb->set_response('WHERE session_id IN', [self::row([])]);
        $GLOBALS['wpdb'] = $wpdb;

        $rows = self::query_rows(['a', "b'c"], null);

        $this->assertCount(1, $rows);
        $sql = (string) end($wpdb->log);
        $this->assertStringContainsString("IN ('a','b''c')", $sql);
        $this->assertStringContainsString('ORDER BY session_id, timestamp ASC, id ASC', $sql);
        $this->assertStringContainsString('timestamp, user_name FROM wp_mxchat_chat_transcripts', $sql);
        $this->assertStringNotContainsString('originating_page_url', $sql);
    }

    public function test_a_range_query_uses_the_bounds_it_is_given(): void {
        $wpdb = new MxChat_Test_WPDB();
        $wpdb->set_response('WHERE timestamp >=', [self::row([])]);
        $GLOBALS['wpdb'] = $wpdb;

        self::query_rows([], ['from' => '2026-10-15 22:00:00', 'to' => '2026-10-16 21:59:59']);

        $sql = (string) end($wpdb->log);
        $this->assertStringContainsString("timestamp >= '2026-10-15 22:00:00' AND timestamp <= '2026-10-16 21:59:59'", $sql);
        $this->assertStringContainsString('ORDER BY session_id, timestamp ASC, id ASC', $sql);
    }

    public function test_a_failed_column_probe_still_exports_the_host_six(): void {
        // The probe answering nothing must cost the optional columns only —
        // naming a column the table lacks would fail the whole SELECT.
        $wpdb = new MxChat_Test_WPDB();
        $wpdb->set_response('SHOW COLUMNS', null);
        $GLOBALS['wpdb'] = $wpdb;

        self::query_rows(['a'], null);

        $sql = (string) end($wpdb->log);
        $this->assertStringContainsString('SELECT session_id, user_email, user_identifier, role, message, timestamp FROM', $sql);
    }

    public function test_no_selection_and_no_range_never_queries_the_table(): void {
        $wpdb = new MxChat_Test_WPDB();
        $GLOBALS['wpdb'] = $wpdb;

        $this->assertSame([], self::query_rows([], null));
        $this->assertSame([], $wpdb->log, 'an empty request must never dump the table');
    }

    public function test_a_query_error_reads_as_no_rows(): void {
        $wpdb = new MxChat_Test_WPDB();
        $wpdb->set_response('WHERE session_id IN', null);
        $GLOBALS['wpdb'] = $wpdb;

        $this->assertSame([], self::query_rows(['a'], null));
    }
}
