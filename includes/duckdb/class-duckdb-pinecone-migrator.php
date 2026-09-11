<?php
/**
 * One-shot migration: Pinecone → DuckDB.
 *
 * Pinecone exposes:
 *   POST https://{host}/vectors/list   { namespace, paginationToken } → ids
 *   POST https://{host}/vectors/fetch  { ids[], namespace }           → vectors + metadata
 *
 * We paginate /list, batch ids into /fetch (100 at a time), translate each
 * Pinecone vector into the plugin's DuckDB row shape, and INSERT OR REPLACE.
 * No re-embedding happens — this is a pure vector copy.
 *
 * The migration is *resumable*: the last seen pagination token is persisted
 * to an option so a failure mid-run can be re-launched without re-fetching
 * everything.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Pinecone_Migrator {

    const STATE_OPTION = 'mxchat_plus_duckdb_pinecone_migration_state';
    const LIST_PAGE_SIZE = 100;
    const FETCH_BATCH = 100;

    private string $api_key;
    private string $host;
    private string $namespace;

    public function __construct(string $api_key, string $host, string $namespace = '') {
        if (trim($api_key) === '') {
            throw new RuntimeException(__('Missing Pinecone API key.', 'mxchat-plus'));
        }
        if (trim($host) === '') {
            throw new RuntimeException(__('Missing Pinecone host.', 'mxchat-plus'));
        }
        $this->api_key = $api_key;
        $this->host = self::normalise_host($host);
        $this->namespace = $namespace;
    }

    /**
     * Run a full migration. Returns a summary array. Caller should expose
     * progress via the optional callback.
     *
     * @param callable|null $progress fn(int $copied_so_far, ?int $total)
     */
    public function run(?callable $progress = null): array {
        $store = new MxChat_Plus_DuckDB_Vector_Store();
        $store->ensure_schema();

        $state = (array) get_option(self::STATE_OPTION, []);
        $pagination_token = (string) ($state['next_token'] ?? '');
        $copied = (int) ($state['copied'] ?? 0);
        $failed = (int) ($state['failed'] ?? 0);
        $bot_id = $this->namespace !== '' ? $this->namespace : 'default';

        while (true) {
            [$ids, $pagination_token] = $this->list_ids($pagination_token);
            if (empty($ids)) break;

            foreach (array_chunk($ids, self::FETCH_BATCH) as $chunk) {
                $vectors = $this->fetch_vectors($chunk);
                $rows = [];
                foreach ($vectors as $id => $v) {
                    $row = $this->pinecone_to_row($id, $v, $bot_id);
                    if ($row !== null) {
                        $rows[] = $row;
                    } else {
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

                update_option(self::STATE_OPTION, [
                    'next_token' => $pagination_token,
                    'copied'     => $copied,
                    'failed'     => $failed,
                    'host'       => $this->host,
                    'namespace'  => $this->namespace,
                    'updated_at' => time(),
                ], false);

                if ($progress) $progress($copied, null);
            }

            if ($pagination_token === '') break;
        }

        delete_option(self::STATE_OPTION);
        return [
            'copied'    => $copied,
            'failed'    => $failed,
            'namespace' => $this->namespace,
        ];
    }

    /**
     * @return array{0: string[], 1: string} [ids, next_pagination_token]
     */
    private function list_ids(string $pagination_token): array {
        $body = [
            'namespace' => $this->namespace,
            'limit'     => self::LIST_PAGE_SIZE,
        ];
        if ($pagination_token !== '') {
            $body['paginationToken'] = $pagination_token;
        }
        $resp = $this->call('/vectors/list', $body);

        $ids = [];
        if (isset($resp['vectors']) && is_array($resp['vectors'])) {
            foreach ($resp['vectors'] as $v) {
                $id = (string) ($v['id'] ?? '');
                if ($id !== '') $ids[] = $id;
            }
        }
        $next = '';
        if (isset($resp['pagination']['next'])) {
            $next = (string) $resp['pagination']['next'];
        }
        return [$ids, $next];
    }

    /**
     * @return array<string, array> map of id → vector payload
     */
    private function fetch_vectors(array $ids): array {
        $resp = $this->call('/vectors/fetch', [
            'ids'       => array_values($ids),
            'namespace' => $this->namespace,
        ]);
        $out = [];
        if (isset($resp['vectors']) && is_array($resp['vectors'])) {
            foreach ($resp['vectors'] as $id => $v) {
                $out[(string) $id] = is_array($v) ? $v : [];
            }
        }
        return $out;
    }

    private function pinecone_to_row(string $id, array $v, string $bot_id): ?array {
        $values = isset($v['values']) && is_array($v['values']) ? $v['values'] : [];
        if (empty($values)) return null;
        $meta = isset($v['metadata']) && is_array($v['metadata']) ? $v['metadata'] : [];

        return [
            'vector_id'        => $id,
            'bot_id'           => $bot_id,
            'embedding'        => $values,
            'content'          => (string) ($meta['text'] ?? $meta['content'] ?? ''),
            'source_url'       => (string) ($meta['source_url'] ?? $meta['url'] ?? ''),
            'role_restriction' => (string) ($meta['role_restriction'] ?? 'public'),
            'content_type'     => (string) ($meta['type'] ?? $meta['content_type'] ?? 'content'),
            'chunk_index'      => isset($meta['chunk_index']) ? (int) $meta['chunk_index'] : null,
            'total_chunks'     => isset($meta['total_chunks']) ? (int) $meta['total_chunks'] : null,
            'is_chunked'       => !empty($meta['is_chunked']),
        ];
    }

    /**
     * @throws RuntimeException on HTTP / decode error.
     */
    private function call(string $path, array $body): array {
        $url = 'https://' . $this->host . $path;
        $resp = wp_remote_post($url, [
            'headers' => [
                'Api-Key'      => $this->api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body'    => wp_json_encode($body),
            'timeout' => 30,
        ]);
        if (is_wp_error($resp)) {
            throw new RuntimeException('Pinecone HTTP error: ' . $resp->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($resp);
        $raw = wp_remote_retrieve_body($resp);
        if ((int) $code !== 200) {
            throw new RuntimeException(sprintf('Pinecone %s → HTTP %d: %s', $path, $code, substr((string) $raw, 0, 300)));
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Pinecone returned non-JSON response.');
        }
        return $decoded;
    }

    private static function normalise_host(string $host): string {
        $host = preg_replace('#^https?://#', '', trim($host));
        $host = preg_replace('#/+$#', '', (string) $host);
        return (string) $host;
    }
}
