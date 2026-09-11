<?php
/**
 * Read path for the vector store: top-K similarity search + hybrid BM25 +
 * dedup + custom reranker hook + result caching + slow-query log.
 *
 * Pure read-side — never mutates the table. The cache invalidation is owned
 * by the writer side (Vector_Store::upsert / delete_* calls
 * MxChat_Plus_DuckDB_Cache::flush_query_cache()).
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Vector_Store_Query {

    use MxChat_Plus_DuckDB_SQL_Helpers_Trait;

    /**
     * Single source of truth for the query-cache transient prefix.
     *
     * Referenced by cache_key() (the writer), by the compactor's stale-cache
     * sweep and by uninstall's wildcard DELETE — all three used to hard-code
     * the literal, and the two DELETE sites had drifted to a stale spelling,
     * so their sweep matched nothing. Change it here and nowhere else.
     */
    const CACHE_KEY_PREFIX = 'mxp_q_';

    /**
     * The same prefix, with its literal underscores escaped for use inside a
     * SQL `LIKE ... ESCAPE '\'` pattern (`_` is a single-char wildcard).
     */
    public static function cache_key_like_prefix(): string {
        return str_replace('_', '\_', self::CACHE_KEY_PREFIX);
    }

    public function __construct(
        protected MxChat_Plus_DuckDB_Connection $conn,
        protected string $table,
        protected int $dim,
        protected string $metric,
        protected string $storage
    ) {}

    /**
     * Top-K similarity search. Returns Pinecone-shaped matches:
     *   [{id, score, metadata: {text, source_url, role_restriction, type,
     *      chunk_index, total_chunks, is_chunked}}, ...]
     *
     * Pipeline (each step gated by options or filters):
     *   1. Result cache lookup (md5 of embedding + filter + top_k + bot_id)
     *   2. Vector search, optionally hybrid-merged with BM25
     *   3. Per-source dedup
     *   4. `mxchat_plus_duckdb_rerank_matches` filter
     *   5. Slow-query log + cache write
     *
     * @throws RuntimeException on dimension mismatch.
     */
    public function run(array $embedding, int $top_k, string $bot_id = 'default', array $filter = []): array {
        if (count($embedding) !== $this->dim) {
            throw new RuntimeException(sprintf(
                /* translators: 1: expected dim, 2: actual dim */
                __('Embedding dimension mismatch: expected %1$d, got %2$d. Update embedding_dim or re-sync.', 'mxchat-plus'),
                $this->dim,
                count($embedding)
            ));
        }

        // Per-bot retrieval config: hybrid / dedup / cache / slow-query settings
        // can be overridden per bot (storage-level settings stay global and are
        // already fixed on this instance). Falls back to the global config when
        // the bot has no override.
        $opts = MxChat_Plus_DuckDB_Options::get_for_bot($bot_id);
        // Per-bot generation: a write to another bot won't invalidate this
        // bot's cached top-Ks (the gen + a per-bot prefix are woven into the
        // key, so the compactor sweep can target each bot's stale generations).
        $gen = class_exists('MxChat_Plus_DuckDB_Cache') ? MxChat_Plus_DuckDB_Cache::cache_generation($bot_id) : 0;
        $cache_key = self::cache_key($embedding, $top_k, $bot_id, $filter, $gen);

        if (!empty($opts['query_cache_enabled'])) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                MxChat_Plus_DuckDB_Metrics::record('query_cache_hit');
                return $cached;
            }
        }

        $start = microtime(true);
        [$where_parts, $where_params] = $this->build_where($bot_id, $filter);

        $dedup_on = !empty($opts['dedup_per_source']);
        $query_text = (string) apply_filters('mxchat_plus_duckdb_query_text', '', $bot_id, $filter);

        if (!empty($opts['hybrid_enabled']) && $query_text !== '') {
            // Hybrid path: BM25 + vector merged in PHP, so dedup also stays in
            // PHP. Over-fetch ×3 so the post-merge dedup leaves enough rows
            // for top_k. (SQL-side dedup is reserved for the pure-vector path
            // where DuckDB can do it inside a single CTE without breaking
            // HNSW push-down.)
            $fetch_k = $dedup_on ? max($top_k * 3, $top_k + 10) : $top_k;
            $matches = $this->query_hybrid($embedding, $query_text, $fetch_k, $where_parts, $where_params, (float) $opts['hybrid_alpha']);
            if ($dedup_on) {
                $matches = self::dedup_per_source($matches, $top_k);
            }
        } else {
            // Pure vector path: push the dedup into DuckDB via a CTE +
            // ROW_NUMBER() OVER (PARTITION BY source_url ORDER BY score DESC).
            // The inner query keeps the HNSW-friendly
            // "ORDER BY <distance>(col, lit) LIMIT k" shape so VSS can still
            // use the index; the outer wrapper picks the top row per source_url
            // (and lets empty-URL rows through, mirroring the PHP semantics).
            $matches = $this->query_vector_only($embedding, $top_k, $where_parts, $where_params, $dedup_on);
        }

        $matches = apply_filters('mxchat_plus_duckdb_rerank_matches', $matches, $embedding, $bot_id, $filter, $query_text);

        $elapsed_ms = (int) ((microtime(true) - $start) * 1000);
        MxChat_Plus_DuckDB_Metrics::observe_latency($elapsed_ms);
        $threshold = (int) ($opts['slow_query_ms'] ?? 500);
        if ($threshold > 0 && $elapsed_ms >= $threshold) {
            error_log(sprintf(
                '[mxchat-plus] slow query %dms (bot=%s top_k=%d hybrid=%s dedup=%s)',
                $elapsed_ms, $bot_id, $top_k,
                !empty($opts['hybrid_enabled']) && $query_text !== '' ? '1' : '0',
                !empty($opts['dedup_per_source']) ? '1' : '0'
            ));
        }

        if (!empty($opts['query_cache_enabled']) && (int) $opts['query_cache_ttl'] > 0) {
            set_transient($cache_key, $matches, (int) $opts['query_cache_ttl']);
        }

        return $matches;
    }

    /**
     * Pure vector top-K. $where_parts is the pre-built array of SQL fragments
     * (AND-joined). When $dedup_on, the query is wrapped in a CTE so the
     * per-source_url deduplication happens inside DuckDB rather than in PHP.
     *
     * The inner sub-query preserves the
     * "ORDER BY <distance>(col, literal) LIMIT k" shape that the VSS planner
     * recognises for HNSW push-down — wrapping in the dedup CTE adds rows
     * past top_k (the over-fetch factor) so the outer dedup has enough
     * candidates to collapse same-URL chunks and still land top_k rows.
     */
    private function query_vector_only(array $embedding, int $top_k, array $where_parts, array $where_params, bool $dedup_on = false): array {
        $score_expr = $this->score_expression($embedding);
        $table_q    = $this->quote_ident($this->table);
        $where_sql  = implode(' AND ', $where_parts);

        if (!$dedup_on) {
            $sql = sprintf(
                'SELECT vector_id AS id,
                        %1$s AS score,
                        content AS text,
                        source_url,
                        role_restriction,
                        content_type AS type,
                        chunk_index,
                        total_chunks,
                        is_chunked
                 FROM %2$s
                 WHERE %3$s
                 ORDER BY score DESC
                 LIMIT %4$d',
                $score_expr, $table_q, $where_sql, $top_k
            );
            return self::rows_to_matches($this->conn->execute($sql, $where_params));
        }

        // Dedup-aware variant: over-fetch ×3 in the inner HNSW-friendly query,
        // partition by source_url in the outer CTE, keep rn=1 per partition
        // (plus all empty-URL rows since they shouldn't be deduped against
        // each other), then re-sort by score and apply the final LIMIT.
        $over_fetch = max($top_k * 3, $top_k + 10);
        $sql = sprintf(
            'WITH candidates AS (
                SELECT vector_id AS id,
                       %1$s AS score,
                       content AS text,
                       source_url,
                       role_restriction,
                       content_type AS type,
                       chunk_index,
                       total_chunks,
                       is_chunked
                FROM %2$s
                WHERE %3$s
                ORDER BY score DESC
                LIMIT %4$d
             ), ranked AS (
                SELECT *,
                       ROW_NUMBER() OVER (PARTITION BY source_url ORDER BY score DESC) AS rn
                FROM candidates
             )
             SELECT id, score, text, source_url, role_restriction, type,
                    chunk_index, total_chunks, is_chunked
             FROM ranked
             WHERE source_url = \'\' OR rn = 1
             ORDER BY score DESC
             LIMIT %5$d',
            $score_expr, $table_q, $where_sql, $over_fetch, $top_k
        );
        return self::rows_to_matches($this->conn->execute($sql, $where_params));
    }

    /**
     * Hybrid BM25 + vector via min-max-normalised score blending. Over-fetches
     * top_k * 4 from each leg so the merge has enough signal.
     *
     * The BM25 leg is skipped (vector-only fallback) when the FTS extension
     * is known unavailable for the current backend+table — either from a
     * persistent flag written by Vector_Store_Schema::migration_v3_fts_index()
     * or from a previous BM25 failure this request. Avoids one failing SQL
     * round-trip + an error_log entry per hybrid query on FTS-less builds.
     */
    private function query_hybrid(array $embedding, string $query_text, int $top_k, array $where_parts, array $where_params, float $alpha): array {
        $over = max($top_k * 4, 50);
        $where_sql = implode(' AND ', $where_parts);

        $vector_rows = $this->conn->execute(sprintf(
            'SELECT vector_id AS id, %1$s AS score, content AS text, source_url,
                    role_restriction, content_type AS type, chunk_index, total_chunks, is_chunked
             FROM %2$s WHERE %3$s ORDER BY score DESC LIMIT %4$d',
            $this->score_expression($embedding),
            $this->quote_ident($this->table),
            $where_sql,
            $over
        ), $where_params);

        if (!$this->fts_available_for_request()) {
            return self::rows_to_matches(array_slice($vector_rows, 0, $top_k));
        }

        // BM25 SQL: the user-controllable query_text moves from
        // literal_string-inlined to a bound ? parameter so the
        // prepared-statement path can take over on the native extension.
        // The same ? is used twice (SELECT projection + WHERE filter), so
        // we bind it twice — followed by the shared where_params.
        $bm25_rows = [];
        try {
            $sql_table_unquoted = preg_replace('/[^a-zA-Z0-9_]/', '', $this->table);
            $bm25_sql = sprintf(
                "SELECT vector_id AS id,
                        fts_main_%1\$s.match_bm25(vector_id, ?) AS score
                 FROM %2\$s
                 WHERE %3\$s
                   AND fts_main_%1\$s.match_bm25(vector_id, ?) IS NOT NULL
                 ORDER BY score DESC LIMIT %4\$d",
                $sql_table_unquoted,
                $this->quote_ident($this->table),
                $where_sql,
                $over
            );
            $bm25_params = array_merge([$query_text], $where_params, [$query_text]);
            // ⚠ argument order matches placeholder order: first ? = SELECT
            // projection, then where_params for the WHERE clause, then the
            // second ? for the AND ... IS NOT NULL clause.
            $bm25_rows = $this->conn->execute($bm25_sql, $bm25_params);
        } catch (\Throwable $e) {
            self::mark_fts_unavailable_for_request($this->conn->identifier(), $this->table);
            error_log('[mxchat-plus] BM25 path failed, caching FTS as unavailable for this request: ' . $e->getMessage());
            return self::rows_to_matches(array_slice($vector_rows, 0, $top_k));
        }

        $v_norm = self::normalize_scores($vector_rows);
        $b_norm = self::normalize_scores($bm25_rows);

        $blended = [];
        foreach ($v_norm as $id => $v) {
            $blended[$id] = ['v' => $v, 'b' => $b_norm[$id] ?? 0.0];
        }
        foreach ($b_norm as $id => $b) {
            if (!isset($blended[$id])) {
                $blended[$id] = ['v' => 0.0, 'b' => $b];
            }
        }

        $by_id = [];
        foreach ($vector_rows as $r) {
            $by_id[(string) ($r['id'] ?? '')] = $r;
        }

        $combined = [];
        foreach ($blended as $id => $sc) {
            $row = $by_id[$id] ?? null;
            if (!$row) continue; // BM25-only hits without metadata are skipped
            $row['score'] = $alpha * $sc['v'] + (1.0 - $alpha) * $sc['b'];
            $combined[] = $row;
        }

        usort($combined, fn($a, $b) => ($b['score'] <=> $a['score']));
        return self::rows_to_matches(array_slice($combined, 0, $top_k));
    }

    private function score_expression(array $embedding): string {
        $vec = $this->literal_int_or_float_array($embedding);
        $col_sql = $this->embedding_as_float_sql();
        switch ($this->metric) {
            case 'l2sq':
                return sprintf('(-1.0 * array_distance(%s, %s::FLOAT[%d]))', $col_sql, $vec, $this->dim);
            case 'ip':
                return sprintf('array_inner_product(%s, %s::FLOAT[%d])', $col_sql, $vec, $this->dim);
            case 'cosine':
            default:
                return sprintf('array_cosine_similarity(%s, %s::FLOAT[%d])', $col_sql, $vec, $this->dim);
        }
    }

    /**
     * Build the WHERE-clause pieces for the read path. Both the bot_id
     * literal and any compiled-filter values become bound `?` parameters
     * — the SQL string holds placeholders only, and the params array
     * carries the actual values in placeholder order.
     *
     * @return array{0: string[], 1: array<int,scalar|null>}
     */
    private function build_where(string $bot_id, array $filter): array {
        $parts  = ['bot_id = ?'];
        $params = [$bot_id];
        [$filter_parts, $filter_params] = self::compile_filter($filter, $this);
        foreach ($filter_parts as $p) $parts[] = $p;
        foreach ($filter_params as $v) $params[] = $v;
        return [$parts, $params];
    }

    /**
     * Compile a Pinecone-style filter dict into a (fragments, params) pair.
     * Fragments are SQL clauses with `?` placeholders; the corresponding
     * values are returned in the params array in the same left-to-right
     * order, ready to be appended to a connection->execute() call.
     *
     * Supported operators per field: $eq, $ne, $in, $nin, $gte, $gt, $lte, $lt.
     * Unknown fields/operators are silently dropped (Pinecone parity).
     *
     * @return array{0: string[], 1: array<int,scalar|null>}
     */
    public static function compile_filter(array $filter, MxChat_Plus_DuckDB_Vector_Store_Query $store): array {
        $default_fields = [
            'type'             => 'content_type',
            'content_type'     => 'content_type',
            'role_restriction' => 'role_restriction',
            'source_url'       => 'source_url',
            'chunk_index'      => 'chunk_index',
        ];

        /**
         * Filter: expose extra metadata columns to the Pinecone-style filter
         * compiler. Map is filter-field-name => physical column name. Useful
         * for add-ons that store custom columns alongside each vector (e.g.
         * via mxchat-basic's `mxchat_embedding_chunk_metadata`) and want to
         * constrain retrieval on them. Column names are validated against
         * /^[a-zA-Z0-9_]+$/ below before they touch SQL, so a malformed or
         * malicious entry is dropped rather than becoming an injection vector.
         *
         * @param array<string,string> $default_fields field-name => column-name
         */
        $allowed_fields = apply_filters('mxchat_plus_duckdb_filter_fields', $default_fields);
        if (!is_array($allowed_fields) || empty($allowed_fields)) {
            $allowed_fields = $default_fields;
        }

        $fragments = [];
        $params    = [];
        foreach ($filter as $field => $ops) {
            if (!isset($allowed_fields[$field]) || !is_array($ops)) {
                self::log_ignored_filter('field', (string) $field);
                continue;
            }
            $col = (string) $allowed_fields[$field];
            // Defense in depth: a filter callback returning an unsafe column
            // name must never reach the SQL string. The column goes in bare
            // (unquoted, as it always has), so it must match the identifier
            // safe-class — otherwise drop it (and log under WP_DEBUG).
            if ($col === '' || preg_match('/[^a-zA-Z0-9_]/', $col) === 1) {
                self::log_ignored_filter('unsafe-column', (string) $field . ' => ' . $col);
                continue;
            }
            foreach ($ops as $op => $val) {
                switch ($op) {
                    case '$eq':  $fragments[] = $col . ' = ?';  $params[] = $val; break;
                    case '$ne':  $fragments[] = $col . ' <> ?'; $params[] = $val; break;
                    case '$gt':  $fragments[] = $col . ' > ?';  $params[] = $val; break;
                    case '$gte': $fragments[] = $col . ' >= ?'; $params[] = $val; break;
                    case '$lt':  $fragments[] = $col . ' < ?';  $params[] = $val; break;
                    case '$lte': $fragments[] = $col . ' <= ?'; $params[] = $val; break;
                    case '$in':
                    case '$nin':
                        if (!is_array($val) || empty($val)) break;
                        $placeholders = implode(',', array_fill(0, count($val), '?'));
                        $fragments[] = $col . ($op === '$in' ? ' IN ' : ' NOT IN ') . '(' . $placeholders . ')';
                        foreach ($val as $v) $params[] = $v;
                        break;
                    default:
                        self::log_ignored_filter('operator', $field . '.' . (string) $op);
                }
            }
        }
        return [$fragments, $params];
    }

    /**
     * Per-request memo of the FTS extension's availability per (backend|table).
     * Three states: missing key = "not yet probed", true = "FTS works", false =
     * "FTS unavailable, skip BM25 attempts for the rest of the request". The
     * persistent decision lives in the meta table (written by Schema::migration_v3);
     * this static is a per-request hot cache + a place to record runtime failures.
     *
     * @var array<string,bool>
     */
    private static array $fts_status = [];

    private function fts_available_for_request(): bool {
        $key = $this->conn->identifier() . '|' . $this->table;
        if (array_key_exists($key, self::$fts_status)) {
            return self::$fts_status[$key];
        }

        // No request-scope decision yet — consult the persistent flag the
        // schema migration wrote. A missing flag (pre-v0.8.1 install at
        // schema v3) means "unknown, try it once" → return true; the catch
        // arm in query_hybrid will flip the static to false on failure.
        try {
            $rows = $this->conn->execute(sprintf(
                'SELECT value FROM %s WHERE key = %s',
                $this->quote_ident(MxChat_Plus_DuckDB_Vector_Store_Schema::META_TABLE),
                $this->literal_string(MxChat_Plus_DuckDB_Vector_Store_Schema::META_KEY_FTS_AVAILABLE)
            ));
            if (isset($rows[0]['value'])) {
                $available = ((string) $rows[0]['value']) === '1';
                self::$fts_status[$key] = $available;
                return $available;
            }
        } catch (\Throwable $e) {
            // Meta table doesn't exist yet — Schema hasn't been ensured.
            // Don't cache the "unknown" verdict; next call will re-probe.
        }
        return true;
    }

    public static function mark_fts_unavailable_for_request(string $conn_identifier, string $table): void {
        self::$fts_status[$conn_identifier . '|' . $table] = false;
    }

    /**
     * Test hook: drop the per-request FTS memo so a unit test can exercise
     * either the "known unavailable" or "probe and discover" branches in
     * isolation. Production code has no reason to call this.
     */
    public static function reset_fts_status_cache(): void {
        self::$fts_status = [];
    }

    /**
     * Visibility for ignored filter clauses. Silent in production (Pinecone
     * parity — unknown ops are dropped to avoid breaking existing call-sites
     * that pass loose filters), surfaced under WP_DEBUG so a typo in a custom
     * filter ({$equal} instead of {$eq}) doesn't leak unfiltered results
     * unnoticed. Deduplicated per request to avoid log spam on hot paths.
     *
     * @var array<string,bool>
     */
    private static array $logged_ignored = [];
    private static function log_ignored_filter(string $kind, string $name): void {
        if (!defined('WP_DEBUG') || !WP_DEBUG) return;
        $key = $kind . ':' . $name;
        if (isset(self::$logged_ignored[$key])) return;
        self::$logged_ignored[$key] = true;
        error_log('[mxchat-plus] ignored filter ' . $kind . ' "' . $name . '" — results returned without this constraint');
    }

    /**
     * Min-max normalise scores into [0,1]. Returns an id => normalized map.
     * Constant inputs (range = 0) collapse to 1.0 to avoid silent zeroing.
     */
    public static function normalize_scores(array $rows): array {
        if (empty($rows)) return [];
        $min = INF; $max = -INF;
        foreach ($rows as $r) {
            $s = (float) ($r['score'] ?? 0);
            if ($s < $min) $min = $s;
            if ($s > $max) $max = $s;
        }
        $range = $max - $min;
        $out = [];
        foreach ($rows as $r) {
            $s = (float) ($r['score'] ?? 0);
            $out[(string) ($r['id'] ?? '')] = $range > 0 ? ($s - $min) / $range : 1.0;
        }
        return $out;
    }

    /**
     * Collapse multiple chunks from the same source_url, keeping the highest-
     * scoring one. Empty-URL rows pass through unchanged.
     */
    public static function dedup_per_source(array $matches, int $top_k): array {
        $seen = [];
        $out = [];
        foreach ($matches as $m) {
            $url = (string) ($m['metadata']['source_url'] ?? '');
            if ($url === '') {
                $out[] = $m;
                continue;
            }
            if (!isset($seen[$url])) {
                $seen[$url] = true;
                $out[] = $m;
            }
        }
        return array_slice($out, 0, $top_k);
    }

    /**
     * Hashes the inputs that uniquely determine the top-K result. The embedding
     * is packed as 32-bit floats before hashing — orders of magnitude faster
     * than the old strval/implode path for 1536-dim vectors.
     *
     * Key shape: `mxp_q_<botkey>_<gen>_<hash>`. The per-bot `<gen>` lets a
     * write to one bot invalidate only that bot's keys in O(1) (bump the bot's
     * counter — no LIKE DELETE over wp_options). The `<botkey>` segment (an
     * ASCII-safe rendering of the bot id) lets the compactor sweep target a
     * single bot's stale generations precisely; the raw bot id is still folded
     * into the hash, so two bots that sanitise to the same botkey never collide.
     */
    public static function cache_key(array $embedding, int $top_k, string $bot_id, array $filter, int $gen = 0): string {
        $packed = @pack('g*', ...array_map('floatval', $embedding));
        $h = md5($packed . '|' . $top_k . '|' . $bot_id . '|' . wp_json_encode($filter));
        return self::CACHE_KEY_PREFIX . self::bot_key($bot_id) . '_' . ($gen > 0 ? $gen : 1) . '_' . $h;
    }

    /**
     * ASCII-safe, length-bounded rendering of a bot id for use as a cache-key
     * segment (and so the compactor sweep can build a LIKE pattern from it).
     * Unusual ids are md5-shortened; the `h` prefix keeps a hashed id from
     * ever colliding with a literal one.
     */
    public static function bot_key(string $bot_id): string {
        return preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $bot_id) === 1
            ? $bot_id
            : 'h' . substr(md5($bot_id), 0, 12);
    }

    private static function rows_to_matches(array $rows): array {
        $matches = [];
        foreach ($rows as $r) {
            $matches[] = [
                'id'       => (string) ($r['id'] ?? ''),
                'score'    => (float) ($r['score'] ?? 0),
                'metadata' => [
                    'text'             => (string) ($r['text'] ?? ''),
                    'source_url'       => (string) ($r['source_url'] ?? ''),
                    'role_restriction' => (string) ($r['role_restriction'] ?? 'public'),
                    'type'             => (string) ($r['type'] ?? 'content'),
                    'chunk_index'      => isset($r['chunk_index']) ? (int) $r['chunk_index'] : null,
                    'total_chunks'     => isset($r['total_chunks']) ? (int) $r['total_chunks'] : null,
                    'is_chunked'       => !empty($r['is_chunked']),
                ],
            ];
        }
        return $matches;
    }
}
