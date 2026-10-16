<?php
/**
 * One-shot migration: Pinecone → DuckDB.
 *
 * Two kinds of Pinecone index can be read, the same two MxChat itself speaks
 * (MxChat 3.2.25):
 *
 *   classic vector index
 *     GET  https://{host}/vectors/list?namespace=&limit=&paginationToken=  → {vectors:[{id}], pagination:{next}}
 *     GET  https://{host}/vectors/fetch?ids=a&ids=b&namespace=             → {vectors:{id:{id, values, metadata}}}
 *     Both are GET endpoints with query parameters — the host's own notes
 *     (MxChat_Pinecone_Manager, "plan 793b82") say a POST is answered with
 *     an empty 200 or a non-200. `ids` is REPEATED, never `ids[0]=`.
 *
 *   document index (MxChat 3.2.22+, API version 2026-07)
 *     POST https://{host}/namespaces/{ns|__default__}/documents/list   {limit, pagination_token}
 *     POST https://{host}/namespaces/{ns|__default__}/documents/fetch  {ids, include_fields}
 *     Fields come back flat on each document (`_id`, `embedding`, text…).
 *
 * Namespaces are enumerated with POST /describe_index_stats (both kinds).
 *
 * The index type is given (`vector` / `document`) or detected (`auto`):
 * through MxChat's own helpers when they exist — is_document_host() for the
 * site's configured index, else find_index_by_host() + inspect_schema() —
 * and `vector` otherwise. The data calls themselves are made here rather
 * than through MxChat_Pinecone_Documents: its fetch() skips a failed batch
 * without reporting it (the ids would vanish from both tallies), and the
 * migrator must also work against hosts older than 3.2.22.
 *
 * Each Pinecone vector becomes a DuckDB row through
 * MxChat_Plus_DuckDB_Vector_Store::row_from_pinecone() — the same mapping
 * as the REST proxy, so title, timestamps and add-on keys survive. The row's
 * bot is --bot-id when given, else the vector's own metadata.bot_id (MxChat
 * writes it on every vector), else the namespace, else 'default': DuckDB
 * partitions retrieval on bot_id, and a Multi-Bot namespace need not be
 * named after its bot. A missing role is stored as 'public', the column
 * default — the read path never emits that as a claim (see
 * Vector_Store::resolve_host_roles()).
 *
 * No re-embedding happens — this is a pure vector copy. The migration is
 * resumable: the last pagination token is persisted per (host, index type,
 * namespace), and a saved cursor for a different source is discarded rather
 * than replayed against the wrong one.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Pinecone_Migrator {

    const STATE_OPTION = 'mxchat_plus_duckdb_pinecone_migration_state';

    /**
     * Durable record that vectors were imported from Pinecone. Unlike
     * STATE_OPTION (a resume cursor, deleted once the run completes) this
     * outlives the migration: the compactor consults it before pruning
     * orphans. See MxChat_Plus_DuckDB_Compactor::orphan_pruning_allowed().
     */
    const IMPORTED_MARKER_OPTION = 'mxchat_plus_duckdb_pinecone_imported';
    const LIST_PAGE_SIZE = 100;
    const FETCH_BATCH = 100;

    const INDEX_TYPE_AUTO     = 'auto';
    const INDEX_TYPE_VECTOR   = 'vector';
    const INDEX_TYPE_DOCUMENT = 'document';

    /**
     * Host literal: the document-index API version MxChat sends
     * (MxChat_Pinecone_Documents::API_VERSION, MxChat 3.2.25). Read from the
     * host class when it is loaded, so a host bump follows automatically.
     */
    const DOCUMENT_API_VERSION = '2026-07';

    /**
     * Fields asked of a document-index fetch — the host's RECORD_FIELDS
     * (MxChat_Pinecone_Documents, MxChat 3.2.25) plus the vector itself.
     */
    const DOCUMENT_FIELDS = [
        'text', 'source_url', 'title', 'type', 'bot_id', 'is_chunked', 'chunk_index',
        'total_chunks', 'parent_url_hash', 'role_restriction', 'created_at', 'last_updated',
        'embedding',
    ];

    /** Namespace path segment Pinecone uses for the default ('') namespace. */
    const DEFAULT_NAMESPACE_PATH = '__default__';

    private string $api_key;
    private string $host;
    private string $namespace;
    private string $index_type;
    private string $bot_id;

    /** @var callable(string, string, array<string, mixed>): mixed */
    private $http;

    /**
     * @param string        $namespace  Pinecone namespace; '' is the default one.
     * @param string        $index_type auto | vector | document.
     * @param string        $bot_id     Force every row onto this DuckDB bot ('' = per vector).
     * @param callable|null $http       fn(string $method, string $url, array $args): array|WP_Error —
     *                                  defaults to wp_remote_request(); injectable for tests.
     */
    public function __construct(string $api_key, string $host, string $namespace = '', string $index_type = self::INDEX_TYPE_AUTO, string $bot_id = '', ?callable $http = null) {
        if (trim($api_key) === '') {
            throw new RuntimeException(__('Missing Pinecone API key.', 'mxchat-plus'));
        }
        if (trim($host) === '') {
            throw new RuntimeException(__('Missing Pinecone host.', 'mxchat-plus'));
        }
        $index_type = strtolower(trim($index_type));
        if (!in_array($index_type, [self::INDEX_TYPE_AUTO, self::INDEX_TYPE_VECTOR, self::INDEX_TYPE_DOCUMENT], true)) {
            throw new RuntimeException(sprintf(
                /* translators: %s = the value given */
                __('Unknown Pinecone index type "%s". Use auto, vector or document.', 'mxchat-plus'),
                $index_type
            ));
        }
        $this->api_key    = $api_key;
        $this->host       = self::normalise_host($host);
        $this->namespace  = $namespace;
        $this->index_type = $index_type;
        $this->bot_id     = trim($bot_id);
        $this->http       = $http ?? static function (string $method, string $url, array $args) {
            $args['method'] = $method;
            return wp_remote_request($url, $args);
        };
    }

    /**
     * Copy the constructor's namespace. Returns a summary array. Caller should
     * expose progress via the optional callback.
     *
     * @param callable|null $progress fn(int $copied_so_far, ?int $total)
     * @return array{copied: int, failed: int, namespace: string, index_type: string}
     */
    public function run(?callable $progress = null): array {
        $store = new MxChat_Plus_DuckDB_Vector_Store();
        $store->ensure_schema();

        $r = $this->copy_namespace($store, $this->namespace, $progress, 0);
        delete_option(self::STATE_OPTION);
        $this->record_import($r['copied'], [$this->namespace]);

        return [
            'copied'     => $r['copied'],
            'failed'     => $r['failed'],
            'namespace'  => $this->namespace,
            'index_type' => $this->resolved_index_type(),
        ];
    }

    /**
     * Copy every namespace the index reports (describe_index_stats), the
     * default one first. A re-run after a failure skips the namespaces the
     * failed run had finished and resumes the one it was in.
     *
     * @param callable|null $progress fn(int $copied_so_far, ?int $total)
     * @return array{copied: int, failed: int, index_type: string, namespaces: array<string, array{copied: int, failed: int}>}
     */
    public function run_all_namespaces(?callable $progress = null): array {
        $store = new MxChat_Plus_DuckDB_Vector_Store();
        $store->ensure_schema();

        $namespaces = array_keys($this->list_namespaces());
        $state = $this->saved_state();
        $done = is_array($state['done_namespaces'] ?? null) ? array_values(array_map('strval', $state['done_namespaces'])) : [];

        $summary = [];
        $copied = 0;
        $failed = 0;
        foreach ($namespaces as $ns) {
            $ns = (string) $ns;
            if (in_array($ns, $done, true)) {
                continue;
            }
            $r = $this->copy_namespace($store, $ns, $progress, $copied, $done);
            $summary[$ns] = $r;
            $copied += $r['copied'];
            $failed += $r['failed'];
            $done[] = $ns;
            $this->save_state([
                'namespace'       => $ns,
                'next_token'      => '',
                'copied'          => 0,
                'failed'          => 0,
                'done_namespaces' => $done,
            ]);
        }

        delete_option(self::STATE_OPTION);
        $this->record_import($copied, array_keys($summary));

        return [
            'copied'     => $copied,
            'failed'     => $failed,
            'index_type' => $this->resolved_index_type(),
            'namespaces' => $summary,
        ];
    }

    /**
     * Namespaces of the index with their record counts, raw names ('' is the
     * default namespace), the default one first, then by name.
     *
     * @return array<string, int>
     */
    public function list_namespaces(): array {
        $headers = $this->resolved_index_type() === self::INDEX_TYPE_DOCUMENT ? $this->document_headers() : $this->classic_headers();
        $data = $this->request('POST', 'https://' . $this->host . '/describe_index_stats', [
            'headers' => $headers + ['Content-Type' => 'application/json'],
            'body'    => '{}',
            'timeout' => 15,
        ], '/describe_index_stats');

        $out = [];
        $namespaces = isset($data['namespaces']) && is_array($data['namespaces']) ? $data['namespaces'] : [];
        foreach ($namespaces as $name => $info) {
            $raw = (string) $name === self::DEFAULT_NAMESPACE_PATH ? '' : (string) $name;
            $out[$raw] = is_array($info) ? (int) ($info['vectorCount'] ?? $info['recordCount'] ?? 0) : 0;
        }
        uksort($out, static fn($a, $b) => ($a === '' ? -1 : ($b === '' ? 1 : strcmp((string) $a, (string) $b))));
        return $out;
    }

    /** The index type actually used: the given one, or what `auto` detected. */
    public function resolved_index_type(): string {
        if ($this->index_type === self::INDEX_TYPE_AUTO) {
            $this->index_type = $this->detect_index_type();
        }
        return $this->index_type;
    }

    /**
     * @param list<string> $done namespaces already finished (all-namespaces runs)
     * @return array{copied: int, failed: int}
     */
    private function copy_namespace(MxChat_Plus_DuckDB_Vector_Store $store, string $namespace, ?callable $progress, int $base_copied, array $done = []): array {
        $type  = $this->resolved_index_type();
        $state = $this->saved_state();
        $resume = $state !== null && (string) ($state['namespace'] ?? "\0") === $namespace;
        $token  = $resume ? (string) ($state['next_token'] ?? '') : '';
        $copied = $resume ? (int) ($state['copied'] ?? 0) : 0;
        $failed = $resume ? (int) ($state['failed'] ?? 0) : 0;

        while (true) {
            [$ids, $next] = $type === self::INDEX_TYPE_DOCUMENT
                ? $this->list_document_ids($namespace, $token)
                : $this->list_classic_ids($namespace, $token);

            foreach (array_chunk($ids, self::FETCH_BATCH) as $chunk) {
                $vectors = $type === self::INDEX_TYPE_DOCUMENT
                    ? $this->fetch_documents($namespace, $chunk)
                    : $this->fetch_classic($namespace, $chunk);

                $rows = [];
                foreach ($chunk as $id) {
                    $v = $vectors[$id] ?? null;
                    $row = is_array($v) ? $this->pinecone_to_row($id, $v, $namespace) : null;
                    if ($row !== null) {
                        $rows[] = $row;
                    } else {
                        // Listed but not returned by fetch, or without values.
                        $failed++;
                    }
                }
                if (!empty($rows)) {
                    try {
                        $copied += $store->upsert($rows);
                    } catch (\Throwable $e) {
                        $failed += count($rows);
                        error_log('[mxchat-plus] pinecone migrator upsert: ' . $e->getMessage());
                    }
                }
                if ($progress) $progress($base_copied + $copied, null);
            }

            // The page is fully written: the cursor may move past it.
            $token = $next;
            $this->save_state([
                'namespace'       => $namespace,
                'next_token'      => $token,
                'copied'          => $copied,
                'failed'          => $failed,
                'done_namespaces' => $done,
            ]);

            if ($token === '' || empty($ids)) break;
        }

        return ['copied' => $copied, 'failed' => $failed];
    }

    // ─── Classic vector index ───────────────────────────────────────────

    /**
     * @return array{0: list<string>, 1: string} [ids, next_pagination_token]
     */
    private function list_classic_ids(string $namespace, string $pagination_token): array {
        $params = ['limit' => self::LIST_PAGE_SIZE];
        if ($namespace !== '') {
            $params['namespace'] = $namespace;
        }
        if ($pagination_token !== '') {
            $params['paginationToken'] = $pagination_token;
        }
        $data = $this->request('GET', 'https://' . $this->host . '/vectors/list?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986), [
            'headers' => $this->classic_headers(),
            'timeout' => 30,
        ], '/vectors/list');

        $ids = [];
        foreach ((isset($data['vectors']) && is_array($data['vectors']) ? $data['vectors'] : []) as $v) {
            $id = is_array($v) ? (string) ($v['id'] ?? '') : '';
            if ($id !== '') $ids[] = $id;
        }
        return [$ids, (string) ($data['pagination']['next'] ?? '')];
    }

    /**
     * @param list<string> $ids at most FETCH_BATCH
     * @return array<string, array<string, mixed>> id → {values, metadata}
     */
    private function fetch_classic(string $namespace, array $ids): array {
        $data = $this->request('GET', self::classic_fetch_url($this->host, $namespace, $ids), [
            'headers' => $this->classic_headers(),
            'timeout' => 60,
        ], '/vectors/fetch');

        $out = [];
        foreach ((isset($data['vectors']) && is_array($data['vectors']) ? $data['vectors'] : []) as $id => $v) {
            if (is_array($v)) {
                $out[(string) ($v['id'] ?? $id)] = $v;
            }
        }
        return $out;
    }

    /**
     * GET /vectors/fetch URL with the ids REPEATED (`ids=a&ids=b`), as
     * Pinecone and MxChat_Pinecone_Documents::classic_fetch() (MxChat
     * 3.2.25) expect. http_build_query() would emit `ids[0]=a`, which
     * Pinecone does not read.
     *
     * @param list<string> $ids
     */
    public static function classic_fetch_url(string $host, string $namespace, array $ids): string {
        $query = [];
        foreach ($ids as $id) {
            $query[] = 'ids=' . rawurlencode((string) $id);
        }
        if ($namespace !== '') {
            $query[] = 'namespace=' . rawurlencode($namespace);
        }
        return 'https://' . $host . '/vectors/fetch?' . implode('&', $query);
    }

    // ─── Document index ─────────────────────────────────────────────────

    /**
     * @return array{0: list<string>, 1: string} [ids, next_pagination_token]
     */
    private function list_document_ids(string $namespace, string $pagination_token): array {
        $body = ['limit' => self::LIST_PAGE_SIZE];
        if ($pagination_token !== '') {
            // snake_case on the document API, unlike the classic paginationToken.
            $body['pagination_token'] = $pagination_token;
        }
        $data = $this->request('POST', $this->document_url($namespace, 'list'), [
            'headers' => $this->document_headers(),
            'body'    => (string) wp_json_encode($body),
            'timeout' => 30,
        ], '/documents/list');

        $ids = [];
        foreach ((isset($data['documents']) && is_array($data['documents']) ? $data['documents'] : []) as $doc) {
            $id = is_array($doc) ? (string) ($doc['_id'] ?? '') : '';
            if ($id !== '') $ids[] = $id;
        }
        return [$ids, (string) ($data['pagination']['next'] ?? '')];
    }

    /**
     * Fetch documents and reshape each into the classic {id, values,
     * metadata} so pinecone_to_row() reads both kinds alike: `_id` → id,
     * `embedding` → values, every other flat field → metadata.
     *
     * @param list<string> $ids
     * @return array<string, array<string, mixed>>
     */
    private function fetch_documents(string $namespace, array $ids): array {
        $data = $this->request('POST', $this->document_url($namespace, 'fetch'), [
            'headers' => $this->document_headers(),
            'body'    => (string) wp_json_encode(['ids' => array_values($ids), 'include_fields' => self::DOCUMENT_FIELDS]),
            'timeout' => 60,
        ], '/documents/fetch');

        $out = [];
        foreach ((isset($data['documents']) && is_array($data['documents']) ? $data['documents'] : []) as $key => $doc) {
            if (!is_array($doc)) continue;
            $out[(string) ($doc['_id'] ?? $key)] = self::document_to_vector($doc);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $doc
     * @return array{values: array<int|string, mixed>, metadata: array<string, mixed>}
     */
    public static function document_to_vector(array $doc): array {
        $values = isset($doc['embedding']) && is_array($doc['embedding']) ? $doc['embedding'] : [];
        $meta = [];
        foreach ($doc as $k => $v) {
            if ($k === '_id' || $k === '_score' || $k === 'embedding') continue;
            $meta[(string) $k] = $v;
        }
        return ['values' => $values, 'metadata' => $meta];
    }

    private function document_url(string $namespace, string $op): string {
        $segment = $namespace === '' ? self::DEFAULT_NAMESPACE_PATH : rawurlencode($namespace);
        return 'https://' . $this->host . '/namespaces/' . $segment . '/documents/' . $op;
    }

    // ─── Mapping ────────────────────────────────────────────────────────

    /**
     * One Pinecone vector → one upsert() row, or null when it carries no
     * values (cannot be imported without re-embedding).
     *
     * @param array<string, mixed> $v {values, metadata}
     * @return array<string, mixed>|null
     */
    private function pinecone_to_row(string $id, array $v, string $namespace): ?array {
        $values = isset($v['values']) && is_array($v['values']) ? $v['values'] : [];
        if (empty($values)) return null;
        $meta = isset($v['metadata']) && is_array($v['metadata']) ? $v['metadata'] : [];

        $bot = $this->bot_id;
        if ($bot === '') {
            $bot = isset($meta['bot_id']) && is_scalar($meta['bot_id']) ? trim((string) $meta['bot_id']) : '';
        }
        if ($bot === '') {
            $bot = $namespace !== '' ? $namespace : 'default';
        }

        $row = MxChat_Plus_DuckDB_Vector_Store::row_from_pinecone($id, $values, $meta, $bot);
        // Stored default unchanged ('public', the column default); the read
        // path treats it as "no role" and never emits it.
        $row['role_restriction'] = $row['role_restriction'] ?? 'public';
        return $row;
    }

    // ─── Detection ──────────────────────────────────────────────────────

    /**
     * `auto` → document or vector. Uses MxChat's own helpers when the host
     * has them (MxChat_Pinecone_Documents, MxChat 3.2.22+): first whether
     * this is the site's configured document index, then the index's schema
     * from the control plane. Falls back to `vector`, the only kind older
     * hosts know.
     */
    private function detect_index_type(): string {
        $docs = 'MxChat_Pinecone_Documents';
        if (!class_exists($docs)) {
            return self::INDEX_TYPE_VECTOR;
        }
        try {
            if (is_callable([$docs, 'is_document_host']) && call_user_func([$docs, 'is_document_host'], $this->host)) {
                return self::INDEX_TYPE_DOCUMENT;
            }
            if (is_callable([$docs, 'find_index_by_host']) && is_callable([$docs, 'inspect_schema'])) {
                $index = call_user_func([$docs, 'find_index_by_host'], $this->host, $this->api_key);
                if (is_array($index)) {
                    $schema = call_user_func([$docs, 'inspect_schema'], $index);
                    if (is_array($schema) && !empty($schema['is_document'])) {
                        return self::INDEX_TYPE_DOCUMENT;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[mxchat-plus] pinecone migrator: index type detection failed, assuming a vector index: ' . $e->getMessage());
        }
        return self::INDEX_TYPE_VECTOR;
    }

    // ─── State ──────────────────────────────────────────────────────────

    /**
     * The saved cursor, or null when there is none or it belongs to another
     * source (host or index type) — the two APIs use different token
     * formats, and a token is meaningless against another index.
     *
     * @return array<string, mixed>|null
     */
    private function saved_state(): ?array {
        $state = get_option(self::STATE_OPTION, []);
        if (!is_array($state) || $state === []) return null;
        if ((string) ($state['host'] ?? '') !== $this->host) return null;
        if ((string) ($state['index_type'] ?? self::INDEX_TYPE_VECTOR) !== $this->resolved_index_type()) return null;
        return $state;
    }

    /** @param array<string, mixed> $fields */
    private function save_state(array $fields): void {
        update_option(self::STATE_OPTION, array_merge($fields, [
            'host'       => $this->host,
            'index_type' => $this->resolved_index_type(),
            'updated_at' => time(),
        ]), false);
    }

    /**
     * Durable marker, deliberately NOT the resumable state above (which is
     * cleared on completion). The compactor reads it to stop treating
     * imported vectors as orphans: Pinecone's ids are preserved verbatim and
     * need not follow the md5(url)[_chunk_N] convention the sync writes, so
     * without this the nightly sweep would delete the very vectors this
     * migration copied in order to avoid re-embedding.
     *
     * @param list<string> $namespaces
     */
    private function record_import(int $copied, array $namespaces): void {
        if ($copied <= 0) return;
        $marker = get_option(self::IMPORTED_MARKER_OPTION, []);
        $previous = is_array($marker) ? (int) ($marker['copied'] ?? 0) : 0;
        update_option(self::IMPORTED_MARKER_OPTION, [
            'copied'      => $previous + $copied,
            'namespace'   => count($namespaces) === 1 ? (string) $namespaces[0] : implode(',', $namespaces),
            'host'        => $this->host,
            'index_type'  => $this->resolved_index_type(),
            'imported_at' => time(),
        ], false);
    }

    // ─── Transport ──────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function classic_headers(): array {
        return [
            'Api-Key' => $this->api_key,
            'Accept'  => 'application/json',
        ];
    }

    /** @return array<string, string> */
    private function document_headers(): array {
        $version = defined('MxChat_Pinecone_Documents::API_VERSION')
            ? (string) constant('MxChat_Pinecone_Documents::API_VERSION')
            : self::DOCUMENT_API_VERSION;
        return [
            'Api-Key'                => $this->api_key,
            'Accept'                 => 'application/json',
            'Content-Type'           => 'application/json',
            'X-Pinecone-Api-Version' => $version,
        ];
    }

    /**
     * One HTTP call; the decoded JSON object on a 2xx.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     * @throws RuntimeException on transport error, non-2xx, or a body that is
     *         not a JSON object.
     */
    private function request(string $method, string $url, array $args, string $label): array {
        $resp = ($this->http)($method, $url, $args);
        if (is_wp_error($resp)) {
            throw new RuntimeException('Pinecone HTTP error: ' . $resp->get_error_message());
        }
        $code = self::response_code($resp);
        $raw  = self::response_body($resp);
        if ($code < 200 || $code >= 300) {
            $hint = ($code === 400 || $code === 404) && $this->index_type !== self::INDEX_TYPE_DOCUMENT
                ? ' If this is a document index, pass --index-type=document.'
                : '';
            throw new RuntimeException(sprintf('Pinecone %s %s → HTTP %d: %s%s', $method, $label, $code, substr($raw, 0, 300), $hint));
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Pinecone %s %s returned a non-JSON response.', $method, $label));
        }
        return $decoded;
    }

    /** @param mixed $resp */
    private static function response_code($resp): int {
        if (function_exists('wp_remote_retrieve_response_code')) {
            return (int) wp_remote_retrieve_response_code($resp);
        }
        return is_array($resp) ? (int) ($resp['response']['code'] ?? 0) : 0;
    }

    /** @param mixed $resp */
    private static function response_body($resp): string {
        if (function_exists('wp_remote_retrieve_body')) {
            return (string) wp_remote_retrieve_body($resp);
        }
        return is_array($resp) ? (string) ($resp['body'] ?? '') : '';
    }

    private static function normalise_host(string $host): string {
        $host = preg_replace('#^https?://#', '', trim($host));
        $host = preg_replace('#/+$#', '', (string) $host);
        return (string) $host;
    }
}
