<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the orphan compactor — the daily cron job that prunes DuckDB
 * vectors whose MySQL KB row has been deleted out from under them.
 *
 * The compactor is destructive (DELETE) and runs unattended via cron;
 * the things that MUST hold:
 *   - it doesn't run when the plugin is disabled or when the last sync
 *     was very recent (a deletion may be a mid-sync transient);
 *   - it never deletes more than mxchat_plus_duckdb_compactor_max_deletes per
 *     run (default 5000) — guards a runaway delete on a misconfigured
 *     install from blowing through MotherDuck billing;
 *   - the MySQL KB scan is paginated so a 100k-row KB doesn't allocate
 *     ~20 MB of $wpdb row objects in one shot (v0.6.0 fix);
 *   - orphans get deleted, alive vectors stay.
 */
final class CompactorTest extends TestCase {

    private MxChat_Test_WPDB $wpdb;
    private $mock_conn;

    protected function setUp(): void {
        $GLOBALS['__test_options']    = [];
        $GLOBALS['__test_transients'] = [];
        // Per-bot cache generations live on the Plugin stub; reset so the
        // sweep tests start from a known map (and don't inherit a prior test's).
        MxChat_Plus_DuckDB_Cache::$cache_gen_map = [];

        $this->wpdb = new MxChat_Test_WPDB();
        $this->wpdb->prefix = 'wp_c' . bin2hex(random_bytes(3)) . '_';
        $GLOBALS['wpdb'] = $this->wpdb;

        MxChat_Test_Helpers::reset_schema_memoisation();

        // The compactor's prune_orphans paginates DuckDB vector_id pages —
        // we set $pages + $page_index on the recording connection to feed
        // the loop and break it.
        $this->mock_conn = new class('mock:compactor') extends MxChat_Test_RecordingConnection {
            public array $pages = [];
            public int $page_index = 0;
            public function execute(string $sql, array $params = []): array {
                $this->log[] = $sql;
                if (stripos($sql, 'schema_meta') !== false && stripos($sql, 'SELECT value') !== false) {
                    return [['value' => '3']];
                }
                if (stripos($sql, 'SELECT vector_id FROM') !== false) {
                    return $this->pages[$this->page_index++] ?? [];
                }
                return [];
            }
        };

        $defaults = MxChat_Plus_DuckDB_Options::defaults();
        update_option('mxchat_plus_duckdb_options', array_merge($defaults, [
            'enabled'       => true,
            'embedding_dim' => 3,
            'last_sync_at'  => time() - 7200, // 2h ago — past the 1h freshness floor
        ]));

        MxChat_Test_Helpers::inject_mock_connection($this->mock_conn);
    }

    private function compactor(): MxChat_Plus_DuckDB_Compactor {
        // Fresh instance — the singleton would hold the WP-cron state across
        // tests but a `new` works because register_hooks() isn't called here.
        return new MxChat_Plus_DuckDB_Compactor();
    }

    // ─── Skip paths ───────────────────────────────────────────────────────

    public function test_compactor_skips_when_plugin_disabled(): void {
        $defaults = MxChat_Plus_DuckDB_Options::defaults();
        update_option('mxchat_plus_duckdb_options', array_merge($defaults, ['enabled' => false]));

        $result = $this->compactor()->run();

        $this->assertFalse($result['ok']);
        $this->assertSame('plugin disabled', $result['reason']);
        $this->assertSame(0, $result['deleted']);
        $this->assertEmpty($this->mock_conn->log, 'no SQL must be issued when disabled');
    }

    public function test_compactor_skips_when_last_sync_too_recent(): void {
        // last_sync_at < MIN_SYNC_AGE_SECONDS (3600s) — sync may still be
        // mid-flight, the "deleted" rows might just be in-transit.
        $defaults = MxChat_Plus_DuckDB_Options::defaults();
        update_option('mxchat_plus_duckdb_options', array_merge($defaults, [
            'enabled'      => true,
            'last_sync_at' => time() - 60,
        ]));

        $result = $this->compactor()->run();
        $this->assertFalse($result['ok']);
        $this->assertSame('last sync too recent', $result['reason']);
        $this->assertEmpty($this->mock_conn->log);
    }

    // ─── Happy path ───────────────────────────────────────────────────────

    public function test_compactor_deletes_only_orphan_vector_ids(): void {
        // Alive set (per MySQL): vectors keyed off two URLs + one id-only row.
        $alive_url_a = (object) ['id' => 1, 'source_url' => 'https://example.com/a'];
        $alive_url_b = (object) ['id' => 2, 'source_url' => 'https://example.com/b'];
        $alive_id   = (object) ['id' => 3, 'source_url' => ''];
        // Paginated response: first page returns the alive rows, second page
        // is empty so load_alive_ids() terminates its pagination loop.
        $alive_rows = [$alive_url_a, $alive_url_b, $alive_id];
        $this->wpdb->set_response('SELECT id, url AS source_url', function ($sql) use ($alive_rows) {
            return (stripos($sql, 'OFFSET 0') !== false) ? $alive_rows : [];
        });

        $alive_ids = [
            MxChat_Plus_DuckDB_Sync::vector_id_for_row($alive_url_a),
            MxChat_Plus_DuckDB_Sync::vector_id_for_row($alive_url_b),
            MxChat_Plus_DuckDB_Sync::vector_id_for_row($alive_id),
        ];

        // DuckDB page: 2 alive + 2 orphan + end-of-pages.
        $this->mock_conn->pages = [
            [
                ['vector_id' => $alive_ids[0]],
                ['vector_id' => $alive_ids[1]],
                ['vector_id' => 'orphan_one'],
                ['vector_id' => 'orphan_two'],
            ],
            [], // empty page → loop terminates
        ];

        $result = $this->compactor()->run();

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['deleted'], 'both orphans must be deleted, neither alive');

        $log = implode("\n", $this->mock_conn->log);
        $this->assertStringContainsString("'orphan_one'", $log);
        $this->assertStringContainsString("'orphan_two'", $log);
        $this->assertStringNotContainsString("'" . $alive_ids[0] . "'", $log,
            'alive vector_id must NOT appear in any DELETE');
    }

    /**
     * Regression guard (silent data loss): mxchat-basic stores chunked
     * documents in the KB as a single row per chunk, prefixed with
     * `{"document_type":"chunked",...}\n---\n<text>`, and the sync pipeline
     * derives the vector_id from that prefix — `md5(url) . '_chunk_' . N`
     * (MxChat_Plus_DuckDB_Mysql_Sync::vector_id_for_row + parse_chunk_prefix).
     *
     * The compactor's alive-set must be built with the SAME chunk logic. When
     * it isn't (it used to select only `id, url` and call vector_id_for_row()
     * without chunk metadata), every alive id degrades to bare md5(url), NO
     * chunk row matches, and the nightly job deletes the whole chunked KB —
     * up to max_deletes (5000) vectors per night.
     */
    public function test_chunked_rows_are_alive_and_never_pruned(): void {
        $url = 'https://example.com/guide';
        // Byte-for-byte what MxChat_Chunker::format_chunk_for_storage() writes.
        $stored = json_encode([
            'document_type'   => 'chunked',
            'chunk_index'     => 2,
            'total_chunks'    => 5,
            'source_url'      => $url,
            'parent_url_hash' => md5($url),
        ]) . "\n---\nChunk two body text.";

        // The KB SELECT must expose the chunk-metadata prefix alongside id/url.
        $kb_row = (object) ['id' => 42, 'source_url' => $url, 'chunk_prefix' => $stored];
        $this->wpdb->set_response('SELECT id, url AS source_url', function ($sql) use ($kb_row) {
            return (stripos($sql, 'OFFSET 0') !== false) ? [$kb_row] : [];
        });

        // The id the write path actually put in DuckDB for that row.
        $chunk_id = MxChat_Plus_DuckDB_Sync::vector_id_for_row(
            $kb_row,
            MxChat_Plus_DuckDB_Mysql_Sync::parse_chunk_prefix($stored)
        );
        $this->assertStringEndsWith('_chunk_2', $chunk_id, 'sanity: sync writes a chunk-suffixed id');

        $this->mock_conn->pages = [
            [
                ['vector_id' => $chunk_id],
                ['vector_id' => 'orphan_gone'],
            ],
            [],
        ];

        $result = $this->compactor()->run();

        $this->assertTrue($result['ok']);
        $log = implode("\n", $this->mock_conn->log);
        $this->assertStringNotContainsString("'" . $chunk_id . "'", $log,
            'a live chunk vector must NEVER appear in a compactor DELETE');
        $this->assertSame(1, $result['deleted'], 'only the real orphan may be deleted');
        $this->assertStringContainsString("'orphan_gone'", $log);

        // And the KB scan must actually ask MySQL for the chunk metadata —
        // without it the alive set silently degrades to md5(url) again.
        $kb_select = '';
        foreach ($this->wpdb->log as $sql) {
            if (stripos($sql, 'SELECT id, url AS source_url') !== false) { $kb_select = $sql; break; }
        }
        $this->assertStringContainsString('article_content', $kb_select,
            'the alive-set SELECT must carry the chunked-content prefix');
    }

    public function test_max_deletes_cap_is_respected(): void {
        // Provide 250 orphans across 3 pages but cap to 100 per run.
        // The compactor chunks DELETE by 100 ids; we expect exactly one
        // chunk to fire.
        $orphans = [];
        for ($i = 0; $i < 250; $i++) {
            $orphans[] = ['vector_id' => 'orphan_' . $i];
        }
        $this->mock_conn->pages = [array_slice($orphans, 0, 1000), []];
        // No alive vectors; paginated empty response so load_alive_ids
        // terminates immediately.
        $this->wpdb->set_response('SELECT id, url AS source_url', function () { return []; });

        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_compactor_max_deletes'] = 100;
        try {
            $result = $this->compactor()->run();
        } finally {
            $GLOBALS['__test_filter_overrides'] = [];
        }

        $this->assertSame(100, $result['deleted'], 'max_deletes filter must cap the delete count');
    }

    // ─── KB pagination (v0.6.0 memory fix) ────────────────────────────────

    public function test_kb_is_paginated_so_huge_kbs_dont_load_all_in_one_shot(): void {
        // Paginated empty response so load_alive_ids() terminates on first page.
        $this->wpdb->set_response('SELECT id, url AS source_url', function () { return []; });
        $this->mock_conn->pages = [[]];

        $this->compactor()->run();

        $kb_select = '';
        foreach ($this->wpdb->log as $sql) {
            if (stripos($sql, 'SELECT id, url AS source_url') !== false) {
                $kb_select = $sql;
                break;
            }
        }
        $this->assertNotEmpty($kb_select, 'compactor must hit the KB to build the alive set');
        // The query must include a LIMIT clause (paginated) — the v0.6.0
        // fix was specifically to avoid a single get_results() for 100k rows.
        $this->assertMatchesRegularExpression('/LIMIT \d+/i', $kb_select,
            'KB SELECT must be paginated (LIMIT clause)');
        $this->assertMatchesRegularExpression('/OFFSET \d+/i', $kb_select);
    }

    public function test_throws_when_kb_table_is_unreadable(): void {
        // get_results returns null instead of an array — the original code
        // checks for $rows === null and throws "MySQL KB table unreadable".
        $this->wpdb->set_response('SELECT id, url AS source_url', null);
        $this->mock_conn->pages = [[]];

        $result = $this->compactor()->run();
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('unreadable', $result['reason']);
    }

    // ─── Stale query-cache transient sweep (v0.12.0) ──────────────────────

    public function test_sweeps_stale_generation_per_bot(): void {
        // Two bots at different generations; the sweep targets each bot's
        // stale generations precisely (keeps the current gen per bot).
        MxChat_Plus_DuckDB_Cache::$cache_gen_map = ['default' => 7, 'support_fr' => 2];
        $this->wpdb->set_response('SELECT id, url AS source_url', function () { return []; });
        $this->mock_conn->pages = [[]];

        $this->compactor()->run();

        $deletes = array_values(array_filter(
            $this->wpdb->log,
            fn($sql) => stripos($sql, 'DELETE FROM') !== false && stripos($sql, '_transient') !== false
        ));
        $this->assertNotEmpty($deletes, 'a per-bot transient-sweep DELETE must be issued');
        $blob = implode("\n", $deletes);
        // The prefix is NOT re-spelled here: it must come from the class that
        // writes the keys, or the sweep silently stops matching anything
        // (which is exactly what a stale hard-coded 'mxd_q_' literal did).
        $q = MxChat_Plus_DuckDB_Vector_Store_Query::cache_key_like_prefix();
        $this->assertSame('mxp\_q\_', $q, 'sweep must target the real cache-key prefix');
        // default bot: delete all its keys EXCEPT generation 7.
        $this->assertStringContainsString("LIKE '\\_transient\\_{$q}default\\_%'", $blob);
        $this->assertStringContainsString("NOT LIKE '\\_transient\\_{$q}default\\_7\\_%'", $blob);
        // support_fr bot (note its underscore is LIKE-escaped) at gen 2.
        $this->assertStringContainsString("LIKE '\\_transient\\_{$q}support\\_fr\\_%'", $blob);
        $this->assertStringContainsString("NOT LIKE '\\_transient\\_{$q}support\\_fr\\_2\\_%'", $blob);
        // Timeout rows are swept too.
        $this->assertStringContainsString("'\\_transient\\_timeout\\_{$q}default\\_%'", $blob);
    }

    public function test_cache_sweep_noop_when_no_bot_was_ever_bumped(): void {
        MxChat_Plus_DuckDB_Cache::$cache_gen_map = []; // nothing bumped → nothing superseded
        $this->wpdb->set_response('SELECT id, url AS source_url', function () { return []; });
        $this->mock_conn->pages = [[]];

        $result = $this->compactor()->run();
        $this->assertSame(0, $result['cache_swept']);
        $transient_deletes = array_filter(
            $this->wpdb->log,
            fn($sql) => stripos($sql, 'DELETE FROM') !== false && stripos($sql, '_transient') !== false
        );
        $this->assertEmpty($transient_deletes, 'no sweep DELETE when the gen map is empty');
    }

    public function test_cache_sweep_runs_even_when_last_sync_too_recent(): void {
        // The freshness guard must NOT gate the transient sweep — busy installs
        // hit "last sync too recent" daily and still need orphan cache GC.
        MxChat_Plus_DuckDB_Cache::$cache_gen_map = ['default' => 3];
        $defaults = MxChat_Plus_DuckDB_Options::defaults();
        update_option('mxchat_plus_duckdb_options', array_merge($defaults, [
            'enabled'      => true,
            'last_sync_at' => time() - 60, // well within the 1h floor
        ]));

        $result = $this->compactor()->run();

        $this->assertFalse($result['ok']);
        $this->assertSame('last sync too recent', $result['reason']);
        $this->assertArrayHasKey('cache_swept', $result);
        // The DuckDB backend must stay untouched (the early-return path)…
        $this->assertEmpty($this->mock_conn->log, 'no DuckDB SQL on the freshness-skip path');
        // …but the MySQL transient sweep must have fired.
        $swept_fired = (bool) array_filter(
            $this->wpdb->log,
            fn($sql) => stripos($sql, 'DELETE FROM') !== false
                && stripos($sql, MxChat_Plus_DuckDB_Vector_Store_Query::cache_key_like_prefix()) !== false
        );
        $this->assertTrue($swept_fired, 'transient sweep must run before the freshness guard');
    }

    // ─── Regressions fixed in 1.0.0 ──────────────────────────────────────

    /**
     * The scan DELETEs from the table it is paging through. With LIMIT/OFFSET
     * every delete shifts the remaining rows left while the offset kept
     * advancing by a full page, so up to one page of vectors was skipped after
     * each drain and one sweep could never compact everything. A keyset cursor
     * on the ordering column is immune.
     */
    public function test_orphan_scan_pages_by_keyset_not_offset(): void {
        $this->wpdb->set_response('SELECT id, url AS source_url', function () { return []; });

        // A FULL first page (page_size = 1000) is required to make the scan
        // ask for a second one: a short page means "end of table".
        $first = [];
        for ($i = 0; $i < 1000; $i++) {
            $first[] = ['vector_id' => sprintf('orphan-%04d', $i)];
        }
        $this->mock_conn->pages = [$first, [['vector_id' => 'orphan-1000']], []];

        $this->compactor()->run();

        $scans = array_values(array_filter(
            $this->mock_conn->log,
            fn($sql) => stripos($sql, 'SELECT vector_id FROM') !== false
        ));
        $this->assertNotEmpty($scans);

        foreach ($scans as $sql) {
            $this->assertStringNotContainsStringIgnoringCase(
                'OFFSET',
                $sql,
                'OFFSET paging skips rows while the same scan deletes from the table'
            );
        }
        // The follow-up page must resume from the last id actually seen — not
        // from a positional offset that the interleaved DELETEs have shifted.
        $this->assertArrayHasKey(1, $scans, 'a full page must be followed by another scan');
        $this->assertStringContainsStringIgnoringCase('WHERE vector_id >', $scans[1]);
        $this->assertStringContainsString("'orphan-0999'", $scans[1]);
    }

    /**
     * max_deletes is advertised as a hard ceiling (it exists to bound
     * MotherDuck billing). Draining fixed 100-id chunks while only checking
     * the budget beforehand overshot it by up to 99 rows on the last chunk.
     */
    public function test_delete_count_never_exceeds_max_deletes(): void {
        $this->wpdb->set_response('SELECT id, url AS source_url', function () { return []; });

        // 250 orphans, ceiling at 150 → the boundary falls mid-chunk.
        $page = [];
        for ($i = 0; $i < 250; $i++) {
            $page[] = ['vector_id' => sprintf('orphan-%03d', $i)];
        }
        $this->mock_conn->pages = [$page, []];

        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_compactor_max_deletes'] = 150;
        try {
            $result = $this->compactor()->run();
        } finally {
            unset($GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_compactor_max_deletes']);
        }

        $this->assertSame(150, $result['deleted'], 'the cap must be exact, not approximate');

        $ids_deleted = 0;
        foreach ($this->mock_conn->log as $sql) {
            if (stripos($sql, 'DELETE FROM') !== false && stripos($sql, 'vector_id IN') !== false) {
                $ids_deleted += substr_count($sql, "'orphan-");
            }
        }
        $this->assertSame(150, $ids_deleted, 'no id may be deleted beyond the ceiling');
    }

    /**
     * Vectors imported straight from Pinecone keep Pinecone's own ids, which
     * need not follow the md5(url)[_chunk_N] convention the sync writes — so
     * they look like orphans here. Pruning them would delete the very vectors
     * the migration copied to avoid re-embedding, the night after the import.
     */
    public function test_orphan_pruning_is_disabled_after_a_pinecone_import(): void {
        update_option(MxChat_Plus_DuckDB_Pinecone_Migrator::IMPORTED_MARKER_OPTION, [
            'copied'      => 1200,
            'namespace'   => 'default',
            'imported_at' => time() - 3600,
        ]);

        $this->wpdb->set_response('SELECT id, url AS source_url', function () { return []; });
        $this->mock_conn->pages = [[['vector_id' => 'pinecone-xyz']], []];

        $result = $this->compactor()->run();

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['deleted']);
        $deletes = array_filter(
            $this->mock_conn->log,
            fn($sql) => stripos($sql, 'DELETE FROM') !== false && stripos($sql, 'vector_id IN') !== false
        );
        $this->assertEmpty($deletes, 'imported vectors must not be pruned');
    }

    public function test_a_site_can_re_enable_pruning_after_an_import(): void {
        update_option(MxChat_Plus_DuckDB_Pinecone_Migrator::IMPORTED_MARKER_OPTION, ['copied' => 10]);
        $this->assertFalse(MxChat_Plus_DuckDB_Compactor::orphan_pruning_allowed());

        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_compactor_prune_orphans'] = true;
        try {
            $this->assertTrue(MxChat_Plus_DuckDB_Compactor::orphan_pruning_allowed());
        } finally {
            unset($GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_compactor_prune_orphans']);
        }
    }

    public function test_pruning_is_allowed_when_nothing_was_imported(): void {
        $this->assertTrue(MxChat_Plus_DuckDB_Compactor::orphan_pruning_allowed());
        update_option(MxChat_Plus_DuckDB_Pinecone_Migrator::IMPORTED_MARKER_OPTION, ['copied' => 0]);
        $this->assertTrue(MxChat_Plus_DuckDB_Compactor::orphan_pruning_allowed());
    }
}
