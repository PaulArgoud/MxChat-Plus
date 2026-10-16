<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the post-cache role resolution of Vector_Store::query_pinecone_shape()
 * (#42 / #58).
 *
 * MxChat's get_single_vector_role() (MxChat_Integrator, MxChat 3.2.25)
 * trusts metadata.role_restriction when it is non-empty and only otherwise
 * reads its own {prefix}mxchat_pinecone_roles table. The proxy used to emit
 * 'public' on every match, so a page restricted in MxChat was served to
 * every visitor. And the host's role writers key that table by md5(url)
 * alone while chunk matches are md5(url)_chunk_N.
 *
 * What must hold:
 *   - 'public' (or empty) is never emitted: the key is omitted so the host
 *     still runs its own lookup;
 *   - a row for the exact id wins, then a row for the base id, then a
 *     non-public role stored on the DuckDB row;
 *   - it runs AFTER the query cache, so a cached top-K still gets the role
 *     the admin set a second ago.
 */
final class VectorStoreRolesTest extends TestCase {

    private MxChat_Test_WPDB $wpdb;
    private MxChat_Test_RecordingConnection $conn;

    protected function setUp(): void {
        $GLOBALS['__test_options']    = [];
        $GLOBALS['__test_transients'] = [];
        $GLOBALS['__test_filter_overrides'] = [];
        MxChat_Test_Helpers::reset_schema_memoisation();
        MxChat_Plus_DuckDB_Cache::$cache_gen_map = [];
        MxChat_Plus_DuckDB_Vector_Store::reset_host_roles_cache();

        $this->wpdb = new MxChat_Test_WPDB();
        $this->wpdb->set_response('SHOW TABLES LIKE', 'wp_mxchat_pinecone_roles');
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->conn = new MxChat_Test_RecordingConnection('mock:roles');
        update_option('mxchat_plus_duckdb_options', array_merge(MxChat_Plus_DuckDB_Options::defaults(), [
            'embedding_dim'       => 3,
            'query_cache_enabled' => true,
            'query_cache_ttl'     => 300,
            'dedup_per_source'    => false,
        ]));
    }

    protected function tearDown(): void {
        unset($GLOBALS['wpdb']);
        MxChat_Plus_DuckDB_Vector_Store::reset_host_roles_cache();
    }

    private function store(): MxChat_Plus_DuckDB_Vector_Store {
        return new MxChat_Plus_DuckDB_Vector_Store($this->conn);
    }

    /** @param array<int, array<string, string>> $rows */
    private function rolesTable(array $rows): void {
        $this->wpdb->set_response('FROM wp_mxchat_pinecone_roles WHERE vector_id IN', array_map(fn($r) => (object) $r, $rows));
    }

    private static function match(string $id, ?string $stored_role = 'public'): array {
        $meta = ['text' => 't', 'source_url' => 'https://x/' . $id];
        if ($stored_role !== null) $meta['role_restriction'] = $stored_role;
        return ['id' => $id, 'score' => 0.9, 'metadata' => $meta];
    }

    private static function roleOf(array $match): ?string {
        return $match['metadata']['role_restriction'] ?? null;
    }

    public function test_public_is_never_emitted_as_a_claim(): void {
        $this->rolesTable([]);
        $out = $this->store()->resolve_host_roles([self::match('a', 'public'), self::match('b', ''), self::match('c', null)]);
        foreach ($out as $m) {
            $this->assertArrayNotHasKey('role_restriction', $m['metadata'],
                'the host only reads its roles table when the key is empty or absent');
        }
    }

    public function test_a_stored_non_public_role_is_kept(): void {
        // Rows the MySQL sync copied carry the WordPress-mode role.
        $this->rolesTable([]);
        $out = $this->store()->resolve_host_roles([self::match('a', 'subscriber')]);
        $this->assertSame('subscriber', self::roleOf($out[0]));
    }

    public function test_the_base_id_row_restricts_a_chunk_match(): void {
        // apply_role_restriction_to_post() and the tag rules write md5(url)
        // only; the match is md5(url)_chunk_3. Real Pinecone leaks here —
        // this is deliberately stricter.
        $base = md5('https://x/members');
        $this->rolesTable([['vector_id' => $base, 'bot_id' => 'default', 'role_restriction' => 'administrator']]);
        $out = $this->store()->resolve_host_roles([self::match($base . '_chunk_3')]);
        $this->assertSame('administrator', self::roleOf($out[0]));

        $sql = '';
        foreach ($this->wpdb->log as $q) if (stripos($q, 'WHERE vector_id IN') !== false) $sql = $q;
        $this->assertStringContainsString("'{$base}_chunk_3'", $sql);
        $this->assertStringContainsString("'{$base}'", $sql, 'the base id is looked up too');
        $this->assertStringNotContainsString('bot_id =', $sql,
            'no bot filter: no host writer sets bot_id, and the host lookup ignores it');
    }

    public function test_the_exact_id_row_wins_over_the_base_id_and_the_stored_role(): void {
        $this->rolesTable([
            ['vector_id' => 'h', 'bot_id' => 'default', 'role_restriction' => 'administrator'],
            ['vector_id' => 'h_chunk_0', 'bot_id' => 'default', 'role_restriction' => 'editor'],
        ]);
        $out = $this->store()->resolve_host_roles([self::match('h_chunk_0', 'subscriber')]);
        $this->assertSame('editor', self::roleOf($out[0]));
    }

    public function test_an_exact_public_row_lifts_a_base_restriction(): void {
        // The KB dropdown on one chunk row writes that chunk id: the admin
        // made THIS chunk public. Omit the key; the host reads 'public' too.
        $this->rolesTable([
            ['vector_id' => 'h', 'bot_id' => 'default', 'role_restriction' => 'administrator'],
            ['vector_id' => 'h_chunk_0', 'bot_id' => 'default', 'role_restriction' => 'public'],
        ]);
        $out = $this->store()->resolve_host_roles([self::match('h_chunk_0')]);
        $this->assertNull(self::roleOf($out[0]));
    }

    public function test_a_row_for_this_bot_beats_default_and_other_bots(): void {
        $this->rolesTable([
            ['vector_id' => 'v', 'bot_id' => 'other', 'role_restriction' => 'author'],
            ['vector_id' => 'v', 'bot_id' => 'default', 'role_restriction' => 'subscriber'],
        ]);
        $this->assertSame('subscriber', self::roleOf($this->store()->resolve_host_roles([self::match('v')], 'sales')[0]));

        $this->rolesTable([
            ['vector_id' => 'v', 'bot_id' => 'default', 'role_restriction' => 'subscriber'],
            ['vector_id' => 'v', 'bot_id' => 'sales', 'role_restriction' => 'editor'],
        ]);
        $this->assertSame('editor', self::roleOf($this->store()->resolve_host_roles([self::match('v')], 'sales')[0]));
    }

    public function test_a_roles_table_without_bot_id_still_works(): void {
        // The bot_id column only exists once the host's
        // mxchat_migrate_pinecone_roles_add_bot_id() has run.
        $this->rolesTable([['vector_id' => 'v', 'role_restriction' => 'subscriber']]);
        $this->assertSame('subscriber', self::roleOf($this->store()->resolve_host_roles([self::match('v')])[0]));
    }

    public function test_no_roles_table_only_strips_public(): void {
        $this->wpdb->set_response('SHOW TABLES LIKE', null);
        $out = $this->store()->resolve_host_roles([self::match('a'), self::match('b', 'editor')]);
        $this->assertNull(self::roleOf($out[0]));
        $this->assertSame('editor', self::roleOf($out[1]));
        $lookups = array_filter($this->wpdb->log, fn($q) => stripos($q, 'WHERE vector_id IN') !== false);
        $this->assertEmpty($lookups);
    }

    public function test_lookups_are_batched_and_skipped_for_admin_sweeps(): void {
        $this->rolesTable([]);
        $matches = [];
        for ($i = 0; $i < 150; $i++) $matches[] = self::match('h' . $i . '_chunk_0');
        $this->store()->resolve_host_roles($matches);
        $lookups = array_values(array_filter($this->wpdb->log, fn($q) => stripos($q, 'WHERE vector_id IN') !== false));
        $this->assertCount(2, $lookups, '150 chunk ids + their 150 base ids = 300 ids → 2 batches of at most ROLE_LOOKUP_CHUNK');
        foreach ($lookups as $q) {
            $this->assertLessThanOrEqual(MxChat_Plus_DuckDB_Vector_Store::ROLE_LOOKUP_CHUNK, substr_count($q, "'h"));
        }
    }

    public function test_more_than_the_resolve_ceiling_skips_the_table(): void {
        $this->rolesTable([['vector_id' => 'id0', 'bot_id' => 'default', 'role_restriction' => 'editor']]);
        $matches = [];
        for ($i = 0; $i <= MxChat_Plus_DuckDB_Vector_Store::ROLE_RESOLVE_MAX_MATCHES; $i++) $matches[] = self::match('id' . $i);
        $out = $this->store()->resolve_host_roles($matches);
        $this->assertNull(self::roleOf($out[0]),
            "the host's admin sweeps (topK 5000/10000) re-resolve roles themselves; only 'public' is stripped");
        $lookups = array_filter($this->wpdb->log, fn($q) => stripos($q, 'WHERE vector_id IN') !== false);
        $this->assertEmpty($lookups);
    }

    public function test_a_cached_top_k_still_gets_the_current_role(): void {
        // Role writes never bump our cache generation, and transients cached
        // before this release carried role_restriction 'public'. Resolving
        // after run() means neither can freeze a role.
        $this->conn->responses['array_cosine_similarity'] = [
            ['id' => 'p', 'score' => 0.9, 'text' => 't', 'source_url' => 'https://x/p', 'role_restriction' => 'public',
             'type' => 'post', 'chunk_index' => null, 'total_chunks' => null, 'is_chunked' => false, 'bot_id' => 'default'],
        ];
        $this->rolesTable([]);
        $first = $this->store()->query_pinecone_shape([0.1, 0.2, 0.3], 5, 'default');
        $this->assertNull(self::roleOf($first[0]));
        $cached = array_filter(array_keys($GLOBALS['__test_transients']), fn($k) => str_starts_with((string) $k, 'mxp_q_'));
        $this->assertNotEmpty($cached, 'the first query was cached');

        // The admin restricts the page; the next query is a cache hit.
        $selects_before = count(array_filter($this->conn->log, fn($q) => stripos($q, 'array_cosine_similarity') !== false));
        $this->rolesTable([['vector_id' => 'p', 'bot_id' => 'default', 'role_restriction' => 'administrator']]);
        $second = $this->store()->query_pinecone_shape([0.1, 0.2, 0.3], 5, 'default');
        $selects_after = count(array_filter($this->conn->log, fn($q) => stripos($q, 'array_cosine_similarity') !== false));

        $this->assertSame($selects_before, $selects_after, 'served from the cache');
        $this->assertSame('administrator', self::roleOf($second[0]));
    }
}
