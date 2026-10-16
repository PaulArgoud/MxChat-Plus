<?php
/**
 * Read path for the vector store: top-K similarity search + hybrid BM25 +
 * dedup + custom reranker hook + result caching + slow-query log.
 *
 * Pure read-side — never mutates the table. The cache invalidation is owned
 * by the writer side (Vector_Store::upsert / delete_* calls
 * MxChat_Plus_DuckDB_Cache::flush_query_cache()).
 *
 * Output never claims role_restriction 'public': the key is present only for
 * a stored non-public role, and Vector_Store::query_pinecone_shape() resolves
 * the host's own roles table after the cache (see resolve_host_roles()).
 *
 * matches[].score is always the raw cosine similarity, whatever the metric
 * and with hybrid on: MxChat compares it with its similarity threshold and
 * its YouTube-card floor. The metric and the hybrid blend only rank.
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
     * Pinecone's own topK ceiling. The host sends up to 10000 for its admin
     * sweeps (knowledge-manager content selector, MxChat 3.2.25); anything
     * larger, zero or negative is clamped so LIMIT stays sane.
     */
    const MAX_TOP_K = 10000;

    /**
     * Default upper topK for the result cache (filter
     * `mxchat_plus_duckdb_query_cache_max_top_k`). The host clamps the chat
     * path to 1..1000, so every chat query stays cacheable, while the
     * random-vector admin sweeps (topK 5000/10000, never repeated) no longer
     * write multi-megabyte transients into wp_options.
     */
    const CACHE_MAX_TOP_K_DEFAULT = 1000;

    /** Nesting bound for $and/$or in compile_filter(). */
    const FILTER_MAX_DEPTH = 8;

    /**
     * Column list every search SELECT projects (after `id, score`). The
     * metadata columns feed metadata_from_row().
     */
    const MATCH_COLUMNS_SQL = 'content AS text, source_url, role_restriction, content_type AS type,
                        chunk_index, total_chunks, is_chunked, bot_id, title, parent_url_hash,
                        created_unix, last_updated_unix, extra_metadata';

    /** The same columns, by their projected names (outer SELECT of the dedup CTE). */
    const MATCH_COLUMN_NAMES_SQL = 'text, source_url, role_restriction, type, chunk_index, total_chunks,
                    is_chunked, bot_id, title, parent_url_hash, created_unix, last_updated_unix, extra_metadata';

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
     *   [{id, score, metadata: {text, source_url, type, chunk_index,
     *      total_chunks, is_chunked, bot_id, [title], [parent_url_hash],
     *      [created_at], [last_updated], [role_restriction], [add-on keys]}}, ...]
     * See metadata_from_row() for when the optional keys appear.
     *
     * Pipeline (each step gated by options or filters):
     *   1. Result cache lookup (md5 of embedding + filter + top_k + bot_id)
     *   2. Vector search, optionally hybrid-merged with BM25
     *   3. Per-source dedup
     *   4. `mxchat_plus_duckdb_rerank_matches` filter
     *   5. Slow-query log + cache write
     *
     * @throws RuntimeException on dimension mismatch.
     * @throws InvalidArgumentException on a malformed filter or an unsupported
     *         filter operator (Pinecone answers those with HTTP 400).
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
        $top_k = max(1, min(self::MAX_TOP_K, $top_k));

        // Huge sweeps are not cached: the host's random-vector admin sweeps
        // never repeat, so their transients would only bloat wp_options.
        $cache_max_top_k = (int) apply_filters('mxchat_plus_duckdb_query_cache_max_top_k', self::CACHE_MAX_TOP_K_DEFAULT, $bot_id);
        $cache_on = !empty($opts['query_cache_enabled']) && $top_k <= $cache_max_top_k;

        // Per-bot generation: a write to another bot won't invalidate this
        // bot's cached top-Ks (the gen + a per-bot prefix are woven into the
        // key, so the compactor sweep can target each bot's stale generations).
        $gen = class_exists('MxChat_Plus_DuckDB_Cache') ? MxChat_Plus_DuckDB_Cache::cache_generation($bot_id) : 0;
        $cache_key = self::cache_key($embedding, $top_k, $bot_id, $filter, $gen);

        if ($cache_on) {
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

        if ($cache_on && (int) $opts['query_cache_ttl'] > 0) {
            set_transient($cache_key, $matches, (int) $opts['query_cache_ttl']);
        }

        return $matches;
    }

    /**
     * Pure vector top-K. $where_parts is the pre-built array of SQL fragments
     * (AND-joined). When $dedup_on, the query is wrapped in a CTE so the
     * per-source_url deduplication happens inside DuckDB rather than in PHP.
     *
     * `score` is always the raw cosine similarity (see score_columns_sql());
     * rows are ORDERED by the configured metric's expression, unchanged, so
     * an l2sq/ip install keeps the ranking (and the query shape) it had. The
     * dedup wrapper adds rows past top_k (the over-fetch factor) so the outer
     * dedup has enough candidates to collapse same-URL chunks and still land
     * top_k rows.
     */
    private function query_vector_only(array $embedding, int $top_k, array $where_parts, array $where_params, bool $dedup_on = false): array {
        [$score_cols, $order_col] = $this->score_columns_sql($embedding);
        $table_q    = $this->quote_ident($this->table);
        $where_sql  = implode(' AND ', $where_parts);

        if (!$dedup_on) {
            $sql = sprintf(
                'SELECT vector_id AS id,
                        %1$s,
                        %5$s
                 FROM %2$s
                 WHERE %3$s
                 ORDER BY %6$s DESC
                 LIMIT %4$d',
                $score_cols, $table_q, $where_sql, $top_k, self::MATCH_COLUMNS_SQL, $order_col
            );
            return self::rows_to_matches($this->conn->execute($sql, $where_params));
        }

        // Dedup-aware variant: over-fetch ×3 in the inner query, partition by
        // source_url in the outer CTE, keep rn=1 per partition (plus all
        // empty-URL rows since they shouldn't be deduped against each other),
        // then re-sort and apply the final LIMIT.
        $over_fetch = max($top_k * 3, $top_k + 10);
        $sql = sprintf(
            'WITH candidates AS (
                SELECT vector_id AS id,
                       %1$s,
                       %6$s
                FROM %2$s
                WHERE %3$s
                ORDER BY %8$s DESC
                LIMIT %4$d
             ), ranked AS (
                SELECT *,
                       ROW_NUMBER() OVER (PARTITION BY source_url ORDER BY %8$s DESC) AS rn
                FROM candidates
             )
             SELECT id, score, %7$s
             FROM ranked
             WHERE source_url = \'\' OR rn = 1
             ORDER BY %8$s DESC
             LIMIT %5$d',
            $score_cols, $table_q, $where_sql, $over_fetch, $top_k,
            self::MATCH_COLUMNS_SQL, self::MATCH_COLUMN_NAMES_SQL, $order_col
        );
        return self::rows_to_matches($this->conn->execute($sql, $where_params));
    }

    /**
     * Hybrid BM25 + vector. Over-fetches top_k * 4 from each leg, blends the
     * min-max-normalised metric and BM25 scores on $alpha, and keeps the
     * top_k rows by that blend.
     *
     * The blend only CHOOSES AND ORDERS the rows: every returned match keeps
     * its raw cosine similarity as `score`. MxChat compares that score with
     * its similarity threshold and with the YouTube-card floor
     * (MxChat_Integrator::find_relevant_content_pinecone() and
     * MxChat_Utils::video_embed_threshold(), MxChat 3.2.25: "Pinecone's score
     * IS the cosine"). A normalised blend there turned an off-topic best row
     * (cosine 0.12) into ≥ alpha and pushed it into the context, and sank a
     * good row near the bottom of the over-fetch below the threshold. Since
     * the host re-sorts by score, hybrid in effect decides which rows make
     * the top_k cut, never whether the host keeps them.
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
        [$score_cols, $order_col] = $this->score_columns_sql($embedding);

        $vector_rows = $this->conn->execute(sprintf(
            'SELECT vector_id AS id, %1$s, %5$s
             FROM %2$s WHERE %3$s ORDER BY %6$s DESC LIMIT %4$d',
            $score_cols,
            $this->quote_ident($this->table),
            $where_sql,
            $over,
            self::MATCH_COLUMNS_SQL,
            $order_col
        ), $where_params);

        if (!$this->fts_available_for_request()) {
            return self::rows_to_matches(array_slice($vector_rows, 0, $top_k));
        }

        // BM25 SQL: the user-controllable query_text moves from
        // literal_string-inlined to a bound ? parameter so the
        // prepared-statement path can take over on the native extension.
        // The same ? is used twice (SELECT projection + WHERE filter), so
        // we bind it twice — followed by the shared where_params.
        //
        // The FTS document id is row_key (bot_id || chr(31) || vector_id,
        // schema v4): vector_id alone is no longer unique once two bots hold
        // the same md5(url) id, and match_bm25 looks a row up by that name.
        $bm25_rows = [];
        try {
            $sql_table_unquoted = preg_replace('/[^a-zA-Z0-9_]/', '', $this->table);
            $bm25_sql = sprintf(
                "SELECT vector_id AS id,
                        fts_main_%1\$s.match_bm25(row_key, ?) AS score
                 FROM %2\$s
                 WHERE %3\$s
                   AND fts_main_%1\$s.match_bm25(row_key, ?) IS NOT NULL
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

        return self::rows_to_matches(self::blend_hybrid($vector_rows, $bm25_rows, $alpha, $top_k));
    }

    /**
     * Pick and order the hybrid top_k. $vector_rows carry the raw cosine in
     * `score` and, for a non-cosine metric, the metric value in `rank_score`
     * (the vector leg is normalised on the metric it was ranked by);
     * $bm25_rows carry the BM25 score in `score`. The returned rows are the
     * $vector_rows themselves, untouched — `score` stays the cosine — sorted
     * by the blend. BM25-only hits are skipped: the vector leg fetched no
     * metadata for them.
     *
     * @param array<int, array<string, mixed>> $vector_rows
     * @param array<int, array<string, mixed>> $bm25_rows
     * @return list<array<string, mixed>>
     */
    public static function blend_hybrid(array $vector_rows, array $bm25_rows, float $alpha, int $top_k): array {
        $rank_rows = [];
        foreach ($vector_rows as $r) {
            $rank_rows[] = [
                'id'    => $r['id'] ?? '',
                'score' => array_key_exists('rank_score', $r) ? $r['rank_score'] : ($r['score'] ?? 0),
            ];
        }
        $v_norm = self::normalize_scores($rank_rows);
        $b_norm = self::normalize_scores($bm25_rows);

        $blend = [];
        $rows  = [];
        foreach ($vector_rows as $r) {
            $id = (string) ($r['id'] ?? '');
            if (isset($rows[$id])) continue;
            $rows[$id]  = $r;
            $blend[$id] = $alpha * ($v_norm[$id] ?? 0.0) + (1.0 - $alpha) * ($b_norm[$id] ?? 0.0);
        }
        // Stable: equal blends keep the vector leg's order.
        $ids = array_keys($rows);
        $pos = array_flip($ids);
        usort($ids, fn($a, $b) => ($blend[$b] <=> $blend[$a]) ?: ($pos[$a] <=> $pos[$b]));

        $out = [];
        foreach (array_slice($ids, 0, max(0, $top_k)) as $id) {
            $out[] = $rows[$id];
        }
        return $out;
    }

    /**
     * The projected score columns and the column to ORDER BY, for one query
     * embedding: `[sql, order_column]`.
     *
     * `score` is ALWAYS array_cosine_similarity — the only score MxChat's
     * threshold and video-card floor understand. For the cosine metric that
     * is also the ranking (ORDER BY score). For l2sq / ip the metric
     * expression is projected beside it as `rank_score` and the rows are
     * ordered by THAT, exactly as before this change (an l2sq index used to
     * return -distance as the score, which is ≤ 0 and failed every
     * threshold). The ORDER BY expressions are kept as they were: on the
     * DuckDB builds checked (0.10.3, 1.0.0, 1.5.6) none of the three is the
     * ascending-distance form the VSS planner turns into an HNSW scan, so
     * nothing is lost by computing the cosine alongside.
     *
     * @param array<int|string, mixed> $embedding
     * @return array{0: string, 1: string}
     */
    private function score_columns_sql(array $embedding): array {
        $cosine = $this->cosine_expression($embedding);
        if ($this->metric !== 'l2sq' && $this->metric !== 'ip') {
            return [$cosine . ' AS score', 'score'];
        }
        return [$cosine . ' AS score, ' . $this->score_expression($embedding) . ' AS rank_score', 'rank_score'];
    }

    /**
     * Raw cosine similarity of the embedding column to the query vector.
     *
     * @param array<int|string, mixed> $embedding
     */
    private function cosine_expression(array $embedding): string {
        return sprintf(
            'array_cosine_similarity(%s, %s::FLOAT[%d])',
            $this->embedding_as_float_sql(),
            $this->literal_int_or_float_array($embedding),
            $this->dim
        );
    }

    /** The configured metric as a higher-is-better ranking expression. */
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
                return $this->cosine_expression($embedding);
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
     * Fragments are SQL clauses with `?` placeholders, AND-ed by the caller;
     * the corresponding values are returned in the params array in the same
     * left-to-right order, ready to be appended to a connection->execute()
     * call. Column names come only from the whitelist below (validated
     * against /^[a-zA-Z0-9_]+$/); values never reach the SQL string.
     *
     * Grammar (Pinecone's):
     *   - `{"field": {"$op": value, …}, …}` — several fields / ops AND-ed;
     *   - `{"field": scalar}` — shorthand for `{"field": {"$eq": scalar}}`;
     *   - `{"$and": [filter, …]}` / `{"$or": [filter, …]}`, nestable (the
     *     host's own idiom, e.g. MxChat_Pinecone_Documents::merge_filters());
     *   - operators $eq, $ne, $gt, $gte, $lt, $lte (scalar), $in, $nin (list),
     *     $exists (bool).
     *
     * Unknown fields fail CLOSED rather than being dropped: a positive clause
     * ($eq/$gt/$gte/$lt/$lte/$in, $exists:true) on a field we do not store
     * becomes `FALSE` — Pinecone filters on any metadata key, and records
     * without the key fail such a clause — while a negative one ($ne, $nin,
     * $exists:false) holds for every row and adds no constraint. Dropping the
     * clause used to WIDEN the result set instead. Each unknown field is
     * logged under WP_DEBUG and counted in the `filter_unknown_field` metric.
     *
     * @return array{0: string[], 1: array<int,scalar|null>}
     * @throws InvalidArgumentException on an unsupported operator or a
     *         malformed filter — Pinecone answers both with HTTP 400.
     */
    public static function compile_filter(array $filter, MxChat_Plus_DuckDB_Vector_Store_Query $store): array {
        $params    = [];
        $fragments = self::compile_filter_group($filter, self::filter_fields(), $params, 0);
        return [$fragments, $params];
    }

    /**
     * Filter field name => physical column.
     *
     * @return array<string, string>
     */
    private static function filter_fields(): array {
        $default_fields = [
            'type'             => 'content_type',
            'content_type'     => 'content_type',
            'role_restriction' => 'role_restriction',
            'source_url'       => 'source_url',
            'chunk_index'      => 'chunk_index',
            'total_chunks'     => 'total_chunks',
            'is_chunked'       => 'is_chunked',
            'bot_id'           => 'bot_id',
            'title'            => 'title',
            'parent_url_hash'  => 'parent_url_hash',
            // MxChat's metadata names for the unix timestamps (schema v4).
            'created_at'       => 'created_unix',
            'last_updated'     => 'last_updated_unix',
        ];

        /**
         * Filter: expose extra metadata columns to the Pinecone-style filter
         * compiler. Map is filter-field-name => physical column name. Useful
         * for add-ons that store custom columns alongside each vector (e.g.
         * via mxchat-basic's `mxchat_embedding_chunk_metadata`) and want to
         * constrain retrieval on them. Column names are validated against
         * /^[a-zA-Z0-9_]+$/ before they touch SQL, so a malformed or
         * malicious entry is treated as an unknown field (fail closed) rather
         * than becoming an injection vector.
         *
         * @param array<string,string> $default_fields field-name => column-name
         */
        $allowed_fields = apply_filters('mxchat_plus_duckdb_filter_fields', $default_fields);
        if (!is_array($allowed_fields) || empty($allowed_fields)) {
            $allowed_fields = $default_fields;
        }
        return $allowed_fields;
    }

    /**
     * One AND-group: returns its fragments, appends its params in order.
     *
     * @param array<int|string, mixed> $filter
     * @param array<string, mixed>     $fields
     * @param array<int, scalar|null>  $params
     * @return list<string>
     */
    private static function compile_filter_group(array $filter, array $fields, array &$params, int $depth): array {
        if ($depth > self::FILTER_MAX_DEPTH) {
            throw new InvalidArgumentException('Filter is nested too deeply.');
        }
        $fragments = [];
        foreach ($filter as $key => $value) {
            $key = (string) $key;

            if ($key === '$and' || $key === '$or') {
                if (!is_array($value) || $value === [] || !array_is_list($value)) {
                    throw new InvalidArgumentException(sprintf('Filter operator %s expects a non-empty list of conditions.', $key));
                }
                $parts = [];
                foreach ($value as $sub) {
                    if (!is_array($sub)) {
                        throw new InvalidArgumentException(sprintf('Filter operator %s expects a list of objects.', $key));
                    }
                    $sub_fragments = self::compile_filter_group($sub, $fields, $params, $depth + 1);
                    $parts[] = $sub_fragments === [] ? 'TRUE' : '(' . implode(' AND ', $sub_fragments) . ')';
                }
                $fragments[] = '(' . implode($key === '$and' ? ' AND ' : ' OR ', $parts) . ')';
                continue;
            }
            if ($key === '' || $key[0] === '$') {
                throw new InvalidArgumentException(sprintf('Unsupported filter operator "%s".', $key));
            }

            // Shorthand {"field": scalar} ≡ {"field": {"$eq": scalar}}.
            $ops = is_array($value) ? $value : ['$eq' => $value];
            if ($ops === [] || array_is_list($ops)) {
                throw new InvalidArgumentException(sprintf('Filter on "%s" must be a value or an object of operators.', $key));
            }

            $col = self::filter_column($key, $fields);
            foreach ($ops as $op => $val) {
                $fragment = self::compile_filter_op($key, $col, (string) $op, $val, $params);
                if ($fragment !== null) {
                    $fragments[] = $fragment;
                }
            }
        }
        return $fragments;
    }

    /**
     * Physical column for a filter field, or null when the field is not
     * stored (or the mapping is unsafe).
     *
     * @param array<string, mixed> $fields
     */
    private static function filter_column(string $field, array $fields): ?string {
        if (!isset($fields[$field]) || !is_string($fields[$field])) {
            return null;
        }
        $col = $fields[$field];
        // Defense in depth: a filter callback returning an unsafe column
        // name must never reach the SQL string. The column goes in bare
        // (unquoted, as it always has), so it must match the identifier
        // safe-class.
        if ($col === '' || preg_match('/[^a-zA-Z0-9_]/', $col) === 1) {
            self::log_ignored_filter('unsafe-column', $field . ' => ' . $col);
            return null;
        }
        return $col;
    }

    /**
     * One operator on one field. Returns null for a clause that adds no
     * constraint.
     *
     * @param mixed                   $val
     * @param array<int, scalar|null> $params
     */
    private static function compile_filter_op(string $field, ?string $col, string $op, $val, array &$params): ?string {
        $comparisons = ['$eq' => '=', '$ne' => '<>', '$gt' => '>', '$gte' => '>=', '$lt' => '<', '$lte' => '<='];

        if (isset($comparisons[$op])) {
            if (!is_scalar($val)) {
                throw new InvalidArgumentException(sprintf('Filter %s on "%s" expects a string, number or boolean.', $op, $field));
            }
            if ($col === null) {
                return self::unknown_field_clause($field, $op !== '$ne');
            }
            $params[] = $val;
            return $col . ' ' . $comparisons[$op] . ' ?';
        }

        if ($op === '$in' || $op === '$nin') {
            if (!is_array($val) || !array_is_list($val)) {
                throw new InvalidArgumentException(sprintf('Filter %s on "%s" expects a list.', $op, $field));
            }
            foreach ($val as $v) {
                if (!is_scalar($v)) {
                    throw new InvalidArgumentException(sprintf('Filter %s on "%s" expects a list of strings, numbers or booleans.', $op, $field));
                }
            }
            if ($col === null) {
                return self::unknown_field_clause($field, $op === '$in');
            }
            if ($val === []) {
                // Nothing is in the empty set; everything is outside it.
                return $op === '$in' ? 'FALSE' : null;
            }
            foreach ($val as $v) $params[] = $v;
            return $col . ($op === '$in' ? ' IN ' : ' NOT IN ') . '(' . implode(',', array_fill(0, count($val), '?')) . ')';
        }

        if ($op === '$exists') {
            if (!is_bool($val)) {
                throw new InvalidArgumentException(sprintf('Filter $exists on "%s" expects true or false.', $field));
            }
            if ($col === null) {
                return self::unknown_field_clause($field, $val);
            }
            return $col . ($val ? ' IS NOT NULL' : ' IS NULL');
        }

        throw new InvalidArgumentException(sprintf('Unsupported filter operator "%s" on "%s".', $op, $field));
    }

    /**
     * A clause on a field we do not store: a positive one can match no row
     * (FALSE), a negative one holds for every row (no constraint).
     */
    private static function unknown_field_clause(string $field, bool $positive): ?string {
        self::log_ignored_filter('field', $field);
        if (class_exists('MxChat_Plus_DuckDB_Metrics')) {
            MxChat_Plus_DuckDB_Metrics::record('filter_unknown_field');
        }
        return $positive ? 'FALSE' : null;
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
     * Visibility for filter clauses on fields we do not store. Those fail
     * closed (see compile_filter()), so a typo in a field name now returns
     * nothing instead of unfiltered results — this log, under WP_DEBUG,
     * says why. Unknown operators are not logged: they throw. Deduplicated
     * per request to avoid log spam on hot paths.
     *
     * @var array<string,bool>
     */
    private static array $logged_ignored = [];
    private static function log_ignored_filter(string $kind, string $name): void {
        if (!defined('WP_DEBUG') || !WP_DEBUG) return;
        $key = $kind . ':' . $name;
        if (isset(self::$logged_ignored[$key])) return;
        self::$logged_ignored[$key] = true;
        error_log('[mxchat-plus] filter ' . $kind . ' "' . $name . '" is not a stored column — a positive clause on it matches nothing');
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
                'metadata' => self::metadata_from_row($r),
            ];
        }
        return $matches;
    }

    /**
     * Pinecone-shaped metadata for one stored row — the same keys MxChat
     * writes on its Pinecone upserts (MxChat_Utils::store_in_pinecone_only()
     * and the chunk writer, MxChat 3.2.25), so /query and /vectors/fetch
     * round-trip what the host sent. Shared by the query path and
     * Vector_Store::fetch_by_ids().
     *
     *   always:  text, source_url, type, chunk_index, total_chunks,
     *            is_chunked, bot_id (when known)
     *   when set: title, created_at / last_updated (unix ints, as sent by
     *            the host — never invented for rows that have none),
     *            parent_url_hash (stored, else md5(source_url) for a chunk:
     *            MxChat_Chunker computes it that way), add-on keys kept in
     *            extra_metadata (never overriding a known key)
     *   role_restriction: ONLY for a stored non-public role. 'public' is
     *            never emitted as a positive claim: MxChat's
     *            get_single_vector_role() consults its own roles table only
     *            when the metadata carries no role, exactly as with a real
     *            Pinecone index, whose vectors never carry one.
     *
     * @param array<string, mixed> $r row with the MATCH_COLUMNS_SQL aliases
     * @return array<string, mixed>
     */
    public static function metadata_from_row(array $r): array {
        $source_url = (string) ($r['source_url'] ?? '');
        $is_chunked = !empty($r['is_chunked']) && $r['is_chunked'] !== 'false';
        $meta = [
            'text'         => (string) ($r['text'] ?? ''),
            'source_url'   => $source_url,
            'type'         => (string) ($r['type'] ?? 'content'),
            'chunk_index'  => isset($r['chunk_index']) ? (int) $r['chunk_index'] : null,
            'total_chunks' => isset($r['total_chunks']) ? (int) $r['total_chunks'] : null,
            'is_chunked'   => $is_chunked,
        ];
        $bot = (string) ($r['bot_id'] ?? '');
        if ($bot !== '') {
            $meta['bot_id'] = $bot;
        }
        $title = (string) ($r['title'] ?? '');
        if ($title !== '') {
            $meta['title'] = $title;
        }
        $parent = (string) ($r['parent_url_hash'] ?? '');
        if ($parent === '' && $is_chunked && $source_url !== '') {
            $parent = md5($source_url);
        }
        if ($parent !== '') {
            $meta['parent_url_hash'] = $parent;
        }
        foreach (['created_unix' => 'created_at', 'last_updated_unix' => 'last_updated'] as $col => $key) {
            if (isset($r[$col]) && is_numeric($r[$col]) && (int) $r[$col] > 0) {
                $meta[$key] = (int) $r[$col];
            }
        }
        $role = trim((string) ($r['role_restriction'] ?? ''));
        if ($role !== '' && strtolower($role) !== 'public') {
            $meta['role_restriction'] = $role;
        }
        $extra = $r['extra_metadata'] ?? null;
        if (is_string($extra) && $extra !== '') {
            $decoded = json_decode($extra, true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    if (is_string($k) && !array_key_exists($k, $meta) && $k !== 'role_restriction') {
                        $meta[$k] = $v;
                    }
                }
            }
        }
        return $meta;
    }
}
