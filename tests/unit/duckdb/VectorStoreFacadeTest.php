<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the public façade of Vector_Store: the write path (upsert, the
 * two delete variants), the read-side lookup helpers (count, list_ids,
 * fetch_by_ids), Parquet I/O, and the cache-flush coupling that makes
 * O(1) cache invalidation work after a mutation.
 *
 * The read pipeline (Vector_Store_Query::run) and schema lifecycle are
 * covered in their own tests; this file is about the surface every other
 * subsystem (REST proxy, sync, compactor, CLI) actually calls.
 */
final class VectorStoreFacadeTest extends TestCase {

    private $mock_conn;

    protected function setUp(): void {
        $GLOBALS['__test_options']    = [];
        $GLOBALS['__test_transients'] = [];

        MxChat_Test_Helpers::reset_schema_memoisation();
        MxChat_Test_Helpers::reset_vector_store_current();

        // Reset the Plugin stub's cache generation counter so each test
        // starts from a known baseline.
        MxChat_Plus_DuckDB_Cache::$cache_gen_map = [];
        MxChat_Plus_DuckDB_Cache::$flushed = [];

        $this->mock_conn = new MxChat_Test_RecordingConnection('mock:facade');
    }

    private function store(array $opts_override = [], int $dim = 3): MxChat_Plus_DuckDB_Vector_Store {
        $defaults = MxChat_Plus_DuckDB_Options::defaults();
        update_option('mxchat_plus_duckdb_options', array_merge($defaults, ['embedding_dim' => $dim], $opts_override));
        return new MxChat_Plus_DuckDB_Vector_Store($this->mock_conn);
    }

    // ─── upsert ───────────────────────────────────────────────────────────

    public function test_upsert_empty_array_is_a_noop_and_does_not_flush_cache(): void {
        $store = $this->store();
        $this->assertSame(0, $store->upsert([]));
        $this->assertEmpty(MxChat_Plus_DuckDB_Cache::$flushed,
            'no rows → no cache invalidation needed');
    }

    public function test_upsert_rejects_dim_mismatch_with_a_clear_message(): void {
        $store = $this->store([], 3);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/dimension mismatch/i');
        $store->upsert([
            ['vector_id' => 'v1', 'embedding' => [0.1, 0.2]], // 2-dim against a 3-dim store
        ]);
    }

    public function test_upsert_silently_skips_rows_without_vector_id_or_embedding(): void {
        $store = $this->store();
        $count = $store->upsert([
            ['vector_id' => '',  'embedding' => [0.1, 0.2, 0.3]], // empty id
            ['vector_id' => 'v', 'embedding' => null],            // null embedding
            ['vector_id' => 'v2'],                                // missing embedding key
        ]);
        $this->assertSame(0, $count, 'all three malformed rows should be skipped');
    }

    public function test_upsert_emits_one_batched_insert_or_replace_for_valid_rows(): void {
        $store = $this->store();
        $count = $store->upsert([
            ['vector_id' => 'v1', 'embedding' => [0.1, 0.2, 0.3], 'content' => 'foo', 'source_url' => 'a'],
            ['vector_id' => 'v2', 'embedding' => [0.4, 0.5, 0.6], 'content' => 'bar', 'source_url' => 'b'],
        ]);

        $this->assertSame(2, $count);
        $inserts = array_filter($this->mock_conn->log,
            fn($sql) => stripos($sql, 'INSERT OR REPLACE INTO "mxchat_vectors"') !== false);
        $this->assertCount(1, $inserts, 'a single batched INSERT, not one per row');
        $sql = array_values($inserts)[0];
        $this->assertStringContainsString("'v1'", $sql);
        $this->assertStringContainsString("'v2'", $sql);
        $this->assertStringContainsString("[0.1,0.2,0.3]", $sql);
        $this->assertStringContainsString("[0.4,0.5,0.6]", $sql);
    }

    public function test_upsert_bumps_cache_generation_after_successful_write(): void {
        $before = MxChat_Plus_DuckDB_Cache::cache_generation();
        $this->store()->upsert([
            ['vector_id' => 'v1', 'embedding' => [0.1, 0.2, 0.3]],
        ]);
        $this->assertGreaterThan($before, MxChat_Plus_DuckDB_Cache::cache_generation(),
            'a successful upsert must bump the cache generation so stale top-K go unreachable');
    }

    public function test_upsert_quotes_single_quotes_in_text_to_prevent_sql_injection(): void {
        $this->store()->upsert([
            ['vector_id' => "id'with'quotes", 'embedding' => [0.1, 0.2, 0.3],
             'content' => "It's a 'quoted' string"],
        ]);
        $sql = end($this->mock_conn->log);
        // SQL standard escapes ' as ''.
        $this->assertStringContainsString("'id''with''quotes'", $sql);
        $this->assertStringContainsString("'It''s a ''quoted'' string'", $sql);
    }

    public function test_upsert_chunk_size_can_be_overridden_via_filter(): void {
        // Default for embedded is 250; we cap at 2 via the filter to verify
        // chunking actually happens.
        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_upsert_chunk_size'] = 2;
        try {
            $rows = [];
            for ($i = 0; $i < 5; $i++) {
                $rows[] = ['vector_id' => 'v' . $i, 'embedding' => [0.1, 0.2, 0.3]];
            }
            $this->store()->upsert($rows);
        } finally {
            $GLOBALS['__test_filter_overrides'] = [];
        }
        $inserts = array_filter($this->mock_conn->log,
            fn($sql) => stripos($sql, 'INSERT OR REPLACE') !== false);
        $this->assertCount(3, $inserts,
            '5 rows at chunk_size=2 → ceil(5/2) = 3 batched INSERTs');
    }

    // ─── delete_by_ids ────────────────────────────────────────────────────

    public function test_delete_by_ids_empty_array_is_a_noop(): void {
        $this->assertSame(0, $this->store()->delete_by_ids([], 'default'));
        $this->assertEmpty($this->mock_conn->log);
        $this->assertEmpty(MxChat_Plus_DuckDB_Cache::$flushed);
    }

    public function test_delete_by_ids_emits_delete_and_bumps_cache(): void {
        // The flush is scoped to the targeted bot — only that bot's generation
        // moves, not the global/default one.
        $before_target = MxChat_Plus_DuckDB_Cache::cache_generation('support_fr');
        $before_other  = MxChat_Plus_DuckDB_Cache::cache_generation('default');
        $this->store()->delete_by_ids(['v1', 'v2', 'v3'], 'support_fr');
        $sql = end($this->mock_conn->log);
        $this->assertStringContainsString('DELETE FROM "mxchat_vectors"', $sql);
        $this->assertStringContainsString("bot_id = 'support_fr'", $sql);
        $this->assertStringContainsString("vector_id IN ('v1','v2','v3')", $sql);
        $this->assertGreaterThan($before_target, MxChat_Plus_DuckDB_Cache::cache_generation('support_fr'));
        $this->assertSame($before_other, MxChat_Plus_DuckDB_Cache::cache_generation('default'),
            'deleting from one bot must NOT bump another bot\'s generation');
    }

    public function test_delete_by_ids_chunks_large_id_lists(): void {
        // 250 ids must be split into batches of DELETE_ID_CHUNK (100), so the
        // SQL/HTTP body stays under MotherDuck's limit: 100 + 100 + 50 = 3 DELETEs.
        $ids = array_map(fn($i) => 'v' . $i, range(1, 250));
        $count = $this->store()->delete_by_ids($ids, 'default');
        $this->assertSame(250, $count);

        $deletes = array_values(array_filter(
            $this->mock_conn->log,
            fn($sql) => stripos($sql, 'DELETE FROM') !== false
        ));
        $this->assertCount(3, $deletes, 'must batch 250 ids into 3 DELETEs of <=100');
        // No single statement may exceed the chunk size.
        foreach ($deletes as $sql) {
            $this->assertLessThanOrEqual(
                MxChat_Plus_DuckDB_Vector_Store::DELETE_ID_CHUNK,
                substr_count($sql, "'v"),
                'each DELETE must carry at most DELETE_ID_CHUNK ids'
            );
        }
    }

    public function test_delete_by_ids_dedupes_ids(): void {
        $count = $this->store()->delete_by_ids(['v1', 'v1', 'v2'], 'default');
        $this->assertSame(2, $count, 'duplicate ids collapse to a unique count');
    }

    public function test_delete_by_source_url_scopes_to_bot_id(): void {
        $this->store()->delete_by_source_url('https://example.com/post-42', 'sales_en');
        $sql = end($this->mock_conn->log);
        $this->assertStringContainsString('DELETE FROM "mxchat_vectors"', $sql);
        $this->assertStringContainsString("bot_id = 'sales_en'", $sql);
        $this->assertStringContainsString("source_url = 'https://example.com/post-42'", $sql);
    }

    // ─── count / list_ids / fetch_by_ids ──────────────────────────────────

    public function test_count_returns_zero_when_table_is_empty(): void {
        $this->mock_conn->responses['SELECT COUNT(*)'] = [];
        $this->assertSame(0, $this->store()->count());
    }

    public function test_count_returns_value_for_bot_id_scope(): void {
        $this->mock_conn->responses['SELECT COUNT(*)'] = [['c' => 8421]];
        $count = $this->store()->count('support_fr');
        $this->assertSame(8421, $count);
        $sql = end($this->mock_conn->log);
        $this->assertStringContainsString("bot_id = 'support_fr'", $sql);
    }

    public function test_list_ids_returns_string_array_of_vector_ids(): void {
        $this->mock_conn->responses['SELECT vector_id FROM'] = [
            ['vector_id' => 'v_alpha'],
            ['vector_id' => 'v_beta'],
        ];
        $ids = $this->store()->list_ids('default', 50, 0);
        $this->assertSame(['v_alpha', 'v_beta'], $ids);
        $sql = end($this->mock_conn->log);
        $this->assertStringContainsString('LIMIT 50', $sql);
        $this->assertStringContainsString('OFFSET 0', $sql);
        // Ascending by id, like Pinecone's /vectors/list — stable for paging
        // (created_at tied every chunk of one upsert, which DuckDB stamps
        // with one transaction timestamp).
        $this->assertStringContainsString('ORDER BY vector_id LIMIT', $sql);
        // No prefix filter without an explicit prefix argument.
        $this->assertStringNotContainsString('LIKE', $sql);
    }

    public function test_list_ids_with_prefix_emits_escaped_like_clause(): void {
        // Vector IDs in chunked storage are formatted `{md5}_chunk_{N}`. The
        // underscore is a SQL LIKE wildcard, so we must escape it — otherwise
        // a prefix `abc_chunk_` would also match `abcXchunkX...`.
        $this->mock_conn->responses['SELECT vector_id FROM'] = [['vector_id' => 'h1_chunk_0']];
        $this->store()->list_ids('default', 100, 0, 'h1_chunk_');
        $sql = end($this->mock_conn->log);

        $this->assertStringContainsString("LIKE 'h1\\_chunk\\_%'", $sql,
            'literal underscores in the prefix must be escaped');
        $this->assertStringContainsString("ESCAPE '\\'", $sql,
            'ESCAPE clause must designate backslash as the escape character');
    }

    public function test_list_ids_prefix_escapes_percent_and_backslash_too(): void {
        $this->mock_conn->responses['SELECT vector_id FROM'] = [];
        // % is the other LIKE wildcard; \ must be escaped so the ESCAPE
        // sequence stays unambiguous.
        $this->store()->list_ids('default', 10, 0, '50%off_\\path');
        $sql = end($this->mock_conn->log);
        $this->assertStringContainsString("LIKE '50\\%off\\_\\\\path%'", $sql);
    }

    public function test_list_ids_empty_prefix_is_treated_as_no_filter(): void {
        $this->mock_conn->responses['SELECT vector_id FROM'] = [];
        $this->store()->list_ids('default', 10, 0, '');
        $sql = end($this->mock_conn->log);
        $this->assertStringNotContainsString('LIKE', $sql);
    }

    public function test_fetch_by_ids_returns_pinecone_shaped_metadata_map(): void {
        $this->mock_conn->responses['SELECT vector_id AS id'] = [
            ['id' => 'v1', 'text' => 'hello', 'source_url' => 'a', 'role_restriction' => 'public',
             'type' => 'post', 'chunk_index' => 2, 'total_chunks' => 5, 'is_chunked' => true],
        ];
        $out = $this->store()->fetch_by_ids(['v1', 'v2'], 'default');

        $this->assertArrayHasKey('v1', $out);
        $this->assertSame('v1', $out['v1']['id']);
        $this->assertSame('hello', $out['v1']['metadata']['text']);
        $this->assertSame('a', $out['v1']['metadata']['source_url']);
        $this->assertSame('post', $out['v1']['metadata']['type']);
        $this->assertSame(2, $out['v1']['metadata']['chunk_index']);
        $this->assertSame(5, $out['v1']['metadata']['total_chunks']);
        $this->assertTrue($out['v1']['metadata']['is_chunked']);
    }

    public function test_fetch_by_ids_empty_input_is_a_noop(): void {
        $out = $this->store()->fetch_by_ids([], 'default');
        $this->assertSame([], $out);
        $this->assertEmpty($this->mock_conn->log);
    }

    // ─── Parquet I/O ──────────────────────────────────────────────────────

    public function test_export_parquet_emits_copy_to_with_zstd_compression(): void {
        $this->mock_conn->responses['SELECT COUNT(*)'] = [['c' => 100]];
        $n = $this->store()->export_parquet('/var/backups/dump.parquet');
        $this->assertSame(100, $n);
        $copy_sql = self::firstMatching($this->mock_conn->log, 'COPY');
        $this->assertNotNull($copy_sql);
        $this->assertStringContainsString("TO '/var/backups/dump.parquet'", $copy_sql);
        $this->assertStringContainsString('FORMAT PARQUET', $copy_sql);
        $this->assertStringContainsString('COMPRESSION ZSTD', $copy_sql);
    }

    public function test_export_parquet_escapes_single_quotes_in_path(): void {
        $this->mock_conn->responses['SELECT COUNT(*)'] = [['c' => 0]];
        $this->store()->export_parquet("/tmp/o'reilly.parquet");
        $copy_sql = self::firstMatching($this->mock_conn->log, 'COPY');
        // PHP "''" in the SQL is the SQL standard escape for a single quote.
        $this->assertStringContainsString("'/tmp/o''reilly.parquet'", $copy_sql);
    }

    public function test_import_parquet_copies_by_name_from_the_file(): void {
        // A backup exported before schema v4 has fewer columns than the v4
        // table: a positional `SELECT *` copy would fail or shift values.
        $this->mock_conn->responses['SELECT COUNT(*)'] = [['c' => 250]];
        $this->mock_conn->responses['DESCRIBE SELECT'] = array_map(fn($c) => ['column_name' => $c],
            ['vector_id', 'bot_id', 'embedding', 'content', 'source_url', 'role_restriction',
             'content_type', 'chunk_index', 'total_chunks', 'is_chunked', 'created_at']);
        $n = $this->store()->import_parquet('/tmp/restore.parquet');
        $this->assertSame(250, $n);

        $insert = self::firstMatching($this->mock_conn->log, 'INSERT OR REPLACE INTO "mxchat_vectors"');
        $this->assertNotNull($insert);
        $this->assertStringContainsString('(' . implode(', ', MxChat_Plus_DuckDB_Vector_Store_Schema::COLUMNS) . ')', $insert);
        $this->assertStringContainsString("FROM read_parquet('/tmp/restore.parquet')", $insert);
        $this->assertStringNotContainsString('SELECT * FROM', $insert);
        $this->assertStringContainsString('NULL, NULL, NULL, NULL, NULL', $insert,
            'v4 columns missing from the file are NULL');
        // Each statement reads the file itself: the CLI fallback runs every
        // statement in a fresh process, where a TEMP VIEW is already gone.
        $this->assertNull(self::firstMatching($this->mock_conn->log, 'TEMP VIEW'));
    }

    public function test_import_parquet_zero_rows_does_not_attempt_insert(): void {
        $this->mock_conn->responses['SELECT COUNT(*)'] = [['c' => 0]];
        $n = $this->store()->import_parquet('/tmp/empty.parquet');
        $this->assertSame(0, $n);
        $inserts = array_filter($this->mock_conn->log,
            fn($sql) => stripos($sql, 'INSERT OR REPLACE INTO "mxchat_vectors"') !== false);
        $this->assertEmpty($inserts, 'empty Parquet → no INSERT statement');
    }

    public function test_import_parquet_bumps_cache_generation(): void {
        $this->mock_conn->responses['SELECT COUNT(*)'] = [['c' => 5]];
        $this->mock_conn->responses['SELECT DISTINCT'] = [['bot_id' => 'sales']];
        $before = MxChat_Plus_DuckDB_Cache::cache_generation();
        $this->store()->import_parquet('/tmp/x.parquet');
        $this->assertGreaterThan($before, MxChat_Plus_DuckDB_Cache::cache_generation());
        $this->assertGreaterThan(1, MxChat_Plus_DuckDB_Cache::cache_generation('sales'),
            'every bot the file touched, tracked or not');
    }

    // ─── Composite key + metadata (schema v4) ─────────────────────────────

    public function test_upsert_writes_the_v4_columns_and_the_row_key(): void {
        $this->store()->upsert([[
            'vector_id' => 'v1', 'bot_id' => 'sales', 'embedding' => [0.1, 0.2, 0.3],
            'title' => '  About   <b>Us</b> &amp; more ', 'parent_url_hash' => 'abc',
            'created_at' => 1700000000, 'last_updated' => '1700000500',
            'extra_metadata' => ['lang' => 'fr', 'tags' => ['a', 'b'], 'nested' => ['x' => 1]],
        ]]);
        $sql = self::firstMatching($this->mock_conn->log, 'INSERT OR REPLACE INTO "mxchat_vectors"');
        $this->assertStringContainsString('updated_at, title, parent_url_hash, created_unix, last_updated_unix, extra_metadata, row_key)', $sql);
        $this->assertStringContainsString("'About Us & more'", $sql, 'title normalised like MxChat_Utils::clean_title()');
        $this->assertStringContainsString("'abc', 1700000000, 1700000500", $sql);
        $this->assertStringContainsString('\'{"lang":"fr","tags":["a","b"]}\'', $sql, 'scalar / list values only');
        $this->assertStringContainsString("('sales') || chr(31) || ('v1')", $sql);
        $this->assertStringContainsString("'public'", $sql, 'a missing role keeps the stored default');
    }

    public function test_upsert_keeps_one_row_per_bot_and_id_within_a_batch(): void {
        // DuckDB refuses to upsert one key twice in a statement; the same id
        // under two bots is two rows (two Pinecone namespaces).
        $n = $this->store()->upsert([
            ['vector_id' => 'v1', 'bot_id' => 'a', 'embedding' => [0.1, 0.2, 0.3], 'content' => 'first'],
            ['vector_id' => 'v1', 'bot_id' => 'a', 'embedding' => [0.1, 0.2, 0.3], 'content' => 'second'],
            ['vector_id' => 'v1', 'bot_id' => 'b', 'embedding' => [0.1, 0.2, 0.3], 'content' => 'other bot'],
        ]);
        $this->assertSame(2, $n);
        $sql = self::firstMatching($this->mock_conn->log, 'INSERT OR REPLACE INTO "mxchat_vectors"');
        $this->assertStringNotContainsString("'first'", $sql, 'the last row for a key wins');
        $this->assertStringContainsString("'second'", $sql);
        $this->assertStringContainsString("'other bot'", $sql);
        $this->assertGreaterThan(1, MxChat_Plus_DuckDB_Cache::cache_generation('a'));
        $this->assertGreaterThan(1, MxChat_Plus_DuckDB_Cache::cache_generation('b'));
    }

    public function test_row_from_pinecone_maps_every_host_metadata_key(): void {
        // What MxChat_Utils::store_in_pinecone_only() / the chunk writer send
        // (MxChat 3.2.25), plus an add-on key and the AI-Engine aliases.
        $row = MxChat_Plus_DuckDB_Vector_Store::row_from_pinecone('h_chunk_1', [0.1, 0.2, 0.3], [
            'text' => 'body', 'source' => 'https://x/p', 'type' => 'post', 'part_index' => 1.0,
            'total_chunks' => 3, 'is_chunked' => 'false', 'title' => 'P', 'parent_url_hash' => 'h',
            'created_at' => 1700000000.0, 'last_updated' => 1700000100, 'bot_id' => 'evil',
            'my_key' => 'v', 'codes' => ['X'],
        ], 'sales');
        $this->assertSame('sales', $row['bot_id'], 'the caller decides the bot, never metadata.bot_id');
        $this->assertSame('body', $row['content']);
        $this->assertSame('https://x/p', $row['source_url']);
        $this->assertSame(1, $row['chunk_index']);
        $this->assertSame(3, $row['total_chunks']);
        $this->assertFalse($row['is_chunked'], "'false' is false");
        $this->assertSame('P', $row['title']);
        $this->assertSame(1700000000, $row['created_at']);
        $this->assertSame(1700000100, $row['last_updated']);
        $this->assertNull($row['role_restriction'], 'never an invented role');
        $this->assertSame(['source' => 'https://x/p', 'part_index' => 1.0, 'my_key' => 'v'], $row['extra_metadata']);
    }

    public function test_metadata_helpers_normalise_defensively(): void {
        $this->assertNull(MxChat_Plus_DuckDB_Vector_Store::normalize_title('   '));
        $this->assertNull(MxChat_Plus_DuckDB_Vector_Store::normalize_title(['x']));
        $this->assertSame(255, mb_strlen((string) MxChat_Plus_DuckDB_Vector_Store::normalize_title(str_repeat('é', 300))));
        $this->assertNull(MxChat_Plus_DuckDB_Vector_Store::normalize_unix(0));
        $this->assertNull(MxChat_Plus_DuckDB_Vector_Store::normalize_unix('soon'));
        $this->assertSame(1700000000, MxChat_Plus_DuckDB_Vector_Store::normalize_unix('2023-11-14T22:13:20Z'));
        $this->assertNull(MxChat_Plus_DuckDB_Vector_Store::encode_extra_metadata(['text' => 'known keys are columns']));
        $big = ['a' => str_repeat('x', MxChat_Plus_DuckDB_Vector_Store::EXTRA_METADATA_MAX_BYTES), 'b' => 'kept'];
        $this->assertSame('{"b":"kept"}', MxChat_Plus_DuckDB_Vector_Store::encode_extra_metadata($big), 'an oversized key is skipped, not truncated');
    }

    public function test_fetch_by_ids_emits_host_metadata_and_never_claims_public(): void {
        $this->mock_conn->responses['SELECT vector_id AS id'] = [
            ['id' => 'h_chunk_0', 'text' => 't', 'source_url' => 'https://x/p', 'role_restriction' => 'public',
             'type' => 'post', 'chunk_index' => 0, 'total_chunks' => 2, 'is_chunked' => true, 'bot_id' => 'sales',
             'title' => 'P', 'parent_url_hash' => null, 'created_unix' => 1700000000, 'last_updated_unix' => null,
             'extra_metadata' => '{"lang":"fr","text":"must not override"}', 'embedding_values' => [0.5, 0.25, 0]],
        ];
        $out = $this->store()->fetch_by_ids(['h_chunk_0'], 'sales', true);
        $meta = $out['h_chunk_0']['metadata'];
        $this->assertArrayNotHasKey('role_restriction', $meta, "'public' is never a positive claim");
        $this->assertSame('P', $meta['title']);
        $this->assertSame('sales', $meta['bot_id']);
        $this->assertSame(md5('https://x/p'), $meta['parent_url_hash'], 'derived for a chunk, as MxChat_Chunker does');
        $this->assertSame(1700000000, $meta['created_at']);
        $this->assertArrayNotHasKey('last_updated', $meta, 'no invented date');
        $this->assertSame('fr', $meta['lang']);
        $this->assertSame('t', $meta['text'], 'an add-on key never overrides a known one');
        $this->assertSame([0.5, 0.25, 0.0], $out['h_chunk_0']['values']);

        $sql = self::firstMatching($this->mock_conn->log, 'SELECT vector_id AS id');
        $this->assertStringContainsString("bot_id = 'sales' AND vector_id IN ('h_chunk_0')", $sql);
        $this->assertStringContainsString('AS embedding_values', $sql);
    }

    public function test_count_by_bot_omits_empty_namespaces(): void {
        $this->mock_conn->responses['GROUP BY bot_id'] = [['bot_id' => 'default', 'c' => 12], ['bot_id' => 'sales', 'c' => 0], ['bot_id' => '', 'c' => 3]];
        $this->assertSame(['default' => 12], $this->store()->count_by_bot());
        $sql = self::firstMatching($this->mock_conn->log, 'GROUP BY bot_id');
        $this->assertStringContainsString('SELECT bot_id, COUNT(*) AS c FROM "mxchat_vectors" GROUP BY bot_id ORDER BY bot_id LIMIT 1000', $sql);
        $this->assertSame(3, $this->store([], 3)->dimension());
    }

    public function test_list_ids_page_is_a_keyset_with_an_opaque_cursor(): void {
        // limit + 1 rows come back → there is a next page.
        $this->mock_conn->responses['SELECT vector_id FROM'] = [
            ['vector_id' => 'h_chunk_0'], ['vector_id' => 'h_chunk_1'], ['vector_id' => 'h_chunk_10'],
        ];
        $page = $this->store()->list_ids_page('sales', 2, '', 'h_chunk_');
        $this->assertSame(['h_chunk_0', 'h_chunk_1'], $page['ids']);
        $this->assertNotSame('', $page['next']);
        $this->assertSame('h_chunk_1', MxChat_Plus_DuckDB_Vector_Store::decode_list_token($page['next']));
        $sql = end($this->mock_conn->log);
        $this->assertStringContainsString("bot_id = 'sales' AND vector_id LIKE 'h\\_chunk\\_%' ESCAPE '\\' ORDER BY vector_id LIMIT 3", $sql);

        // The next call resumes after the last id, never by offset.
        $this->mock_conn->responses['SELECT vector_id FROM'] = [['vector_id' => 'h_chunk_10']];
        $last = $this->store()->list_ids_page('sales', 2, $page['next'], 'h_chunk_');
        $this->assertSame(['h_chunk_10'], $last['ids']);
        $this->assertSame('', $last['next'], 'no token on the last page — MxChat loops until next is empty');
        $sql = end($this->mock_conn->log);
        $this->assertStringContainsString("AND vector_id > 'h_chunk_1'", $sql);
        $this->assertStringNotContainsString('OFFSET', $sql);
    }

    public function test_list_tokens_are_validated(): void {
        $this->assertNull(MxChat_Plus_DuckDB_Vector_Store::decode_list_token('garbage'));
        $this->assertNull(MxChat_Plus_DuckDB_Vector_Store::decode_list_token('mxp1.***'));
        $this->assertNull(MxChat_Plus_DuckDB_Vector_Store::decode_list_token('mxp1.'));
        $id = "o'id/+=_chunk_2";
        $this->assertSame($id, MxChat_Plus_DuckDB_Vector_Store::decode_list_token(MxChat_Plus_DuckDB_Vector_Store::encode_list_token($id)));

        // An undecodable token restarts from the beginning instead of failing.
        $this->mock_conn->responses['SELECT vector_id FROM'] = [];
        $this->store()->list_ids_page('default', 10, 'not-ours');
        $this->assertStringNotContainsString('vector_id >', end($this->mock_conn->log));
    }

    // ─── storage_estimate ────────────────────────────────────────────────

    public function test_storage_estimate_uses_4_bytes_per_value_for_float32(): void {
        $this->mock_conn->responses['SELECT COUNT(*)'] = [['c' => 1000]];
        $store = $this->store(['embedding_storage' => 'float32'], 1536);
        $est = $store->storage_estimate();
        // 1000 rows × (1536 floats × 4 bytes + ~200 metadata fudge).
        $expected = 1000 * (1536 * 4 + 200);
        $this->assertSame($expected, $est['bytes_estimate']);
        $this->assertSame('float32', $est['storage']);
        $this->assertSame(1536, $est['dim']);
    }

    public function test_storage_estimate_uses_1_byte_per_value_for_int8(): void {
        $this->mock_conn->responses['SELECT COUNT(*)'] = [['c' => 1000]];
        $store = $this->store(['embedding_storage' => 'int8'], 1536);
        $est = $store->storage_estimate();
        $expected = 1000 * (1536 * 1 + 200);
        $this->assertSame($expected, $est['bytes_estimate']);
        $this->assertSame('int8', $est['storage']);
    }

    // ─── current() singleton ──────────────────────────────────────────────

    public function test_current_caches_per_request_and_reset_clears(): void {
        update_option('mxchat_plus_duckdb_options', array_merge(
            MxChat_Plus_DuckDB_Options::defaults(),
            ['embedding_dim' => 1536]
        ));
        // Vector_Store::current() does `new self()` with no args — the
        // constructor falls back to Connection_Factory. Inject our mock so
        // no real backend is spawned.
        MxChat_Test_Helpers::inject_mock_connection($this->mock_conn);

        $a = MxChat_Plus_DuckDB_Vector_Store::current();
        $b = MxChat_Plus_DuckDB_Vector_Store::current();
        $this->assertSame($a, $b, 'current() must return the same instance within a request');
        MxChat_Plus_DuckDB_Vector_Store::reset_current();
        $c = MxChat_Plus_DuckDB_Vector_Store::current();
        $this->assertNotSame($a, $c, 'reset_current() must drop the singleton');
    }

    // ─── Mirror: schema applies to both sides ─────────────────────────────

    public function test_ensure_schema_under_mirror_runs_migrations_on_both_sides(): void {
        // Construct a real Mirrored_Connection wrapping two recording
        // mocks. Each side answers supports_capability() differently:
        // primary refuses VSS (the MotherDuck contract), local accepts.
        // Vector_Store::ensure_schema() must walk the migrations on
        // BOTH so the CREATE INDEX … USING HNSW lands on local while
        // primary's HNSW DDL is skipped (correctly) instead of throwing.
        $primary = new class implements MxChat_Plus_DuckDB_Connection {
            public array $log = [];
            public function execute(string $sql, array $params = []): array {
                $this->log[] = $sql;
                if (stripos($sql, 'SELECT value FROM') !== false) return [];
                return [];
            }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'mock:mirror-primary (motherduck)'; }
            public function supports_capability(string $cap): bool { return false; /* MotherDuck refuses VSS */ }
        };
        $local = new class implements MxChat_Plus_DuckDB_Connection {
            public array $log = [];
            public function execute(string $sql, array $params = []): array {
                $this->log[] = $sql;
                if (stripos($sql, 'SELECT value FROM') !== false) return [];
                return [];
            }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'mock:mirror-local (embedded)'; }
            public function supports_capability(string $cap): bool { return $cap === MxChat_Plus_DuckDB_Connection::CAP_VSS_PERSISTENT_INDEX; }
        };
        $mirror = new MxChat_Plus_DuckDB_Mirrored_Connection($primary, $local);

        // Wipe Schema's per-request memoisation so both ensure_schema()
        // calls actually run their DDL.
        $r = new ReflectionProperty(MxChat_Plus_DuckDB_Vector_Store_Schema::class, 'ensured');
        $r->setValue(null, []);

        update_option('mxchat_plus_duckdb_options', array_merge(
            MxChat_Plus_DuckDB_Options::defaults(),
            ['hnsw_enabled' => true]
        ));
        $store = new MxChat_Plus_DuckDB_Vector_Store($mirror);
        $store->ensure_schema();

        $primary_log = implode("\n", $primary->log);
        $local_log   = implode("\n", $local->log);

        // Both sides see the base table CREATE.
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS "mxchat_vectors"', $primary_log,
            'primary must get the base table DDL — schema_meta lives there too');
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS "mxchat_vectors"', $local_log,
            'local must get the same base table DDL — INSERTs target it');

        // HNSW DDL fires on local only, primary stays clean.
        $this->assertStringNotContainsString('USING HNSW', $primary_log,
            'primary (MotherDuck) refuses VSS via capability — HNSW DDL must not fire');
        $this->assertStringContainsString('USING HNSW', $local_log,
            'local supports VSS via capability — HNSW must be created on the read side');
    }

    public function test_hnsw_and_fts_available_read_from_local_when_mirrored(): void {
        // The read path runs against local under mirror. Vector_Store's
        // hnsw_available() / fts_available() accessors are read by the
        // admin UI + /health endpoint; they must reflect the LOCAL
        // verdict (true for HNSW) rather than primary's (false on MD).
        $primary = new class implements MxChat_Plus_DuckDB_Connection {
            public function execute(string $sql, array $params = []): array {
                // primary reports hnsw_available='0' (MotherDuck refused).
                if (stripos($sql, "key = 'hnsw_available'") !== false) return [['value' => '0']];
                if (stripos($sql, "key = 'fts_available'")  !== false) return [['value' => '0']];
                return [];
            }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'mock:mirror-primary'; }
            public function supports_capability(string $cap): bool { return false; }
        };
        $local = new class implements MxChat_Plus_DuckDB_Connection {
            public function execute(string $sql, array $params = []): array {
                // local reports hnsw_available='1' + fts_available='1'.
                if (stripos($sql, "key = 'hnsw_available'") !== false) return [['value' => '1']];
                if (stripos($sql, "key = 'fts_available'")  !== false) return [['value' => '1']];
                return [];
            }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'mock:mirror-local'; }
            public function supports_capability(string $cap): bool { return $cap === MxChat_Plus_DuckDB_Connection::CAP_VSS_PERSISTENT_INDEX; }
        };
        $mirror = new MxChat_Plus_DuckDB_Mirrored_Connection($primary, $local);
        $store = new MxChat_Plus_DuckDB_Vector_Store($mirror);

        $this->assertTrue($store->hnsw_available(),
            'mirror reads HNSW availability from local — that is where the index lives');
        $this->assertTrue($store->fts_available(),
            'mirror reads FTS availability from local — that is where BM25 runs');
    }

    // ─── helper ───────────────────────────────────────────────────────────

    private static function firstMatching(array $log, string $needle): ?string {
        foreach ($log as $sql) {
            if (stripos($sql, $needle) !== false) return $sql;
        }
        return null;
    }
}
