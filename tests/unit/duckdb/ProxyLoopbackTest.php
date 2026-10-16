<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the CALLING side of the proxy loopback — the hooks that run in the
 * request where MxChat calls "Pinecone" (a chat answer, an admin screen,
 * WP-CLI), not in the proxy's own REST request:
 *
 *   - sign_loopback_request (http_request_args): only a URL under this
 *     proxy is touched. It gets the signature header that check_token
 *     verifies, and a POST /query also gets the visitor's retrieval text.
 *     Any other URL — real Pinecone first of all — is returned unchanged,
 *     so the visitor's words never leave the site.
 *   - capture_*_retrieval_query (mxchat_retrieval_query) and
 *     note_query_body (mxchat_pinecone_query_body): return their input
 *     unchanged; they only record the text and the bot.
 *   - the wiring itself: what register_routes() hooks where (priority,
 *     accepted_args), and one chat turn driven through those registrations
 *     (the shim's opt-in dispatch, tests/shims/wp-functions.php) down to the
 *     BM25 SQL of a real Vector_Store_Query::run(). The other tests call the
 *     callbacks directly and would stay green if a registration went away.
 *
 * Host URLs follow MxChat 3.2.25: 'https://' . <host> . '/query', with
 * <host> = MxChat_Plus_DuckDB_Pinecone_Proxy::pinecone_host().
 */
final class ProxyLoopbackTest extends TestCase {

    private MxChat_Plus_DuckDB_Pinecone_Proxy $proxy;
    private string $base;
    /** @var mixed $wpdb set aside by the end-to-end tests (role lookup) */
    private $saved_wpdb = null;

    protected function setUp(): void {
        $GLOBALS['__test_options']    = [];
        $GLOBALS['__test_transients'] = [];
        $GLOBALS['__test_filter_overrides'] = [];
        $this->proxy = MxChat_Plus_DuckDB_Pinecone_Proxy::instance();
        $this->proxy->reset_state();
        $this->base = 'https://' . MxChat_Plus_DuckDB_Pinecone_Proxy::pinecone_host();
    }

    protected function tearDown(): void {
        unset($GLOBALS['__test_dispatch_filters'], $GLOBALS['__test_filter_calls']);
        $GLOBALS['__test_hooks'] = [];
        $GLOBALS['__test_filter_overrides'] = [];
        if ($this->saved_wpdb !== null) {
            $GLOBALS['wpdb'] = $this->saved_wpdb;
            $this->saved_wpdb = null;
        }
        MxChat_Test_Helpers::reset_vector_store_current();
        MxChat_Plus_DuckDB_Connection_Factory::reset_cache();
        MxChat_Plus_DuckDB_Vector_Store_Query::reset_fts_status_cache();
        $this->proxy->reset_state();
    }

    private static function queryArgs(array $body, string $token = 'tok'): array {
        return [
            'method'  => 'POST',
            'headers' => ['Api-Key' => $token, 'accept' => 'application/json', 'content-type' => 'application/json'],
            'body'    => (string) json_encode($body),
            'timeout' => 30,
        ];
    }

    /** What MxChat_Integrator does for one chat message, up to the HTTP call. */
    private function chatTurn(string $message, string $bot = 'default', $filtered = null): void {
        $q = $this->proxy->capture_original_retrieval_query($message, 'sess_1', $bot);
        $q = $filtered ?? $q;
        $this->assertSame($filtered ?? $message, $this->proxy->capture_retrieval_query($q, 'sess_1', $bot), 'returned unchanged');
        $body = ['vector' => [0.1, 0.2, 0.3], 'topK' => 50, 'includeMetadata' => true, 'includeValues' => true, 'namespace' => $bot];
        $this->assertSame($body, $this->proxy->note_query_body($body, $bot), 'returned unchanged');
    }

    // ─── URL matching ────────────────────────────────────────────────────

    public function test_proxy_path_from_url_matches_only_this_proxy(): void {
        $this->assertSame('/query', MxChat_Plus_DuckDB_Pinecone_Proxy::proxy_path_from_url($this->base . '/query'));
        $this->assertSame('/vectors/fetch', MxChat_Plus_DuckDB_Pinecone_Proxy::proxy_path_from_url($this->base . '/vectors/fetch?ids=a&ids=b'));
        $this->assertSame('/describe_index_stats', MxChat_Plus_DuckDB_Pinecone_Proxy::proxy_path_from_url($this->base . '/describe_index_stats'));

        foreach ([
            'https://my-index-abc123.svc.aped-4627-b74a.pinecone.io/query',
            'https://api.anthropic.com/v1/messages',
            $this->base . '-evil/query',
            'https://evil.test/?u=' . $this->base . '/query',
        ] as $url) {
            $this->assertNull(MxChat_Plus_DuckDB_Pinecone_Proxy::proxy_path_from_url($url), $url);
        }
        $this->assertTrue(MxChat_Plus_DuckDB_Pinecone_Proxy::is_proxy_url($this->base . '/vectors/list?limit=10'));
    }

    // ─── Signature ───────────────────────────────────────────────────────

    public function test_requests_to_the_proxy_are_signed_and_verify_on_the_other_side(): void {
        update_option(MxChat_Plus_DuckDB_Pinecone_Proxy::PROXY_TOKEN_MAP_OPTION, ['default' => 'tok']);
        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_proxy_rate_limit_per_minute'] = 1;

        $args = $this->proxy->sign_loopback_request(
            ['method' => 'GET', 'headers' => ['Api-Key' => 'tok', 'accept' => 'application/json'], 'timeout' => 30],
            $this->base . '/vectors/list?prefix=abc_chunk_&limit=100&namespace=default'
        );
        $sig = $args['headers'][MxChat_Plus_DuckDB_Pinecone_Proxy::INTERNAL_HEADER] ?? '';
        $this->assertNotSame('', $sig);
        $this->assertSame('tok', $args['headers']['Api-Key'], 'existing headers are kept');

        // The receiving side: well past the external cap of 1.
        for ($i = 0; $i < 3; $i++) {
            $req = new WP_REST_Request();
            $req->set_method('GET');
            $req->set_route('/mxchat-plus/v1/pinecone-proxy/vectors/list');
            $req->set_header('api-key', 'tok');
            $req->set_query_params(['namespace' => 'default']);
            $req->set_header(MxChat_Plus_DuckDB_Pinecone_Proxy::INTERNAL_HEADER, $sig);
            $this->assertTrue($this->proxy->check_token($req), "loopback call $i");
        }
    }

    public function test_other_urls_are_left_untouched(): void {
        $this->chatTurn('Do you stock 4471-AB-12?');
        $args = self::queryArgs(['vector' => [0.1], 'namespace' => 'default']);
        $out = $this->proxy->sign_loopback_request($args, 'https://my-index.svc.pinecone.io/query');
        $this->assertSame($args, $out, 'a real Pinecone index never sees our header nor the visitor text');
    }

    public function test_a_raw_header_string_is_left_unsigned(): void {
        $args = ['method' => 'GET', 'headers' => "Api-Key: tok\r\n"];
        $this->assertSame($args, $this->proxy->sign_loopback_request($args, $this->base . '/vectors/list'));
    }

    // ─── Retrieval text ──────────────────────────────────────────────────

    public function test_the_visitor_text_rides_along_with_the_proxy_query(): void {
        $this->chatTurn('Do you stock 4471-AB-12?');
        $out = $this->proxy->sign_loopback_request(self::queryArgs(['vector' => [0.1, 0.2, 0.3], 'topK' => 50, 'namespace' => 'default']), $this->base . '/query');

        $body = json_decode($out['body'], true);
        $this->assertSame('Do you stock 4471-AB-12?', $body[MxChat_Plus_DuckDB_Pinecone_Proxy::QUERY_TEXT_KEY]);
        $this->assertSame([0.1, 0.2, 0.3], $body['vector'], 'the rest of the body is unchanged');
        $this->assertSame(50, $body['topK']);
    }

    public function test_the_text_the_host_embeds_is_the_filtered_one(): void {
        // An integration rewrites the retrieval query (history-condensed).
        $this->chatTurn('and the blue one?', 'default', 'blue widget 4471-AB-12 price');
        $out = $this->proxy->sign_loopback_request(self::queryArgs(['vector' => [0.1], 'namespace' => 'default']), $this->base . '/query');
        $this->assertSame('blue widget 4471-AB-12 price', json_decode($out['body'], true)[MxChat_Plus_DuckDB_Pinecone_Proxy::QUERY_TEXT_KEY]);
    }

    public function test_an_unusable_filtered_value_falls_back_to_the_original_message(): void {
        // MxChat_Integrator does the same: a non-string or blank value → $message.
        foreach (['   ', null, ['x']] as $bad) {
            $this->proxy->reset_state();
            $this->proxy->capture_original_retrieval_query('original question', 's', 'default');
            $this->assertSame($bad, $this->proxy->capture_retrieval_query($bad, 's', 'default'));
            $this->proxy->note_query_body([], 'default');
            $out = $this->proxy->sign_loopback_request(self::queryArgs(['vector' => [0.1], 'namespace' => 'default']), $this->base . '/query');
            $this->assertSame('original question', json_decode($out['body'], true)[MxChat_Plus_DuckDB_Pinecone_Proxy::QUERY_TEXT_KEY]);
        }
    }

    public function test_text_of_another_bot_is_not_attached(): void {
        $this->chatTurn('sales question', 'sales');
        $this->proxy->note_query_body([], 'default');
        $out = $this->proxy->sign_loopback_request(self::queryArgs(['vector' => [0.1], 'namespace' => 'default']), $this->base . '/query');
        $this->assertArrayNotHasKey(MxChat_Plus_DuckDB_Pinecone_Proxy::QUERY_TEXT_KEY, json_decode($out['body'], true));
    }

    public function test_no_text_outside_a_chat_request(): void {
        // Admin listing sweeps also POST /query, with a synthetic vector and
        // no retrieval query in the request.
        $args = self::queryArgs(['vector' => [0.1], 'topK' => 5000, 'namespace' => 'default']);
        $out = $this->proxy->sign_loopback_request($args, $this->base . '/query');
        $this->assertSame($args['body'], $out['body']);
    }

    public function test_only_post_query_carries_the_text_and_a_body_already_carrying_one_is_kept(): void {
        $this->chatTurn('question');
        $list = $this->proxy->sign_loopback_request(['method' => 'GET', 'headers' => []], $this->base . '/vectors/list?limit=5');
        $this->assertArrayNotHasKey('body', $list);

        $own = self::queryArgs(['vector' => [0.1], MxChat_Plus_DuckDB_Pinecone_Proxy::QUERY_TEXT_KEY => 'set by an add-on']);
        $out = $this->proxy->sign_loopback_request($own, $this->base . '/query');
        $this->assertSame('set by an add-on', json_decode($out['body'], true)[MxChat_Plus_DuckDB_Pinecone_Proxy::QUERY_TEXT_KEY]);
    }

    public function test_the_captured_text_is_capped(): void {
        $this->chatTurn(str_repeat('a', 9000));
        $out = $this->proxy->sign_loopback_request(self::queryArgs(['vector' => [0.1], 'namespace' => 'default']), $this->base . '/query');
        $this->assertSame(MxChat_Plus_DuckDB_Pinecone_Proxy::QUERY_TEXT_MAX_CHARS, strlen(json_decode($out['body'], true)[MxChat_Plus_DuckDB_Pinecone_Proxy::QUERY_TEXT_KEY]));
    }

    // ─── Wiring, end to end ──────────────────────────────────────────────

    /** register_routes() on an empty hook table; returns what it recorded. */
    private function registerFresh(): array {
        $GLOBALS['__test_hooks'] = [];
        // The singleton registers its hooks once per process, and boot or
        // an earlier test may already have done it.
        $guard = new ReflectionProperty(MxChat_Plus_DuckDB_Pinecone_Proxy::class, 'hooks_registered');
        $guard->setValue($this->proxy, false);
        $this->proxy->register_routes();
        return $GLOBALS['__test_hooks'];
    }

    public function test_register_routes_hooks_every_callback_with_the_arguments_its_caller_passes(): void {
        $rows = [];
        foreach ($this->registerFresh() as $h) {
            $this->assertIsArray($h['callback']);
            $this->assertSame($this->proxy, $h['callback'][0]);
            $this->assertTrue(is_callable($h['callback']), $h['hook'] . ' → ' . $h['callback'][1]);
            $rows[] = [$h['hook'], $h['callback'][1], $h['priority'], $h['accepted_args']];
        }

        $this->assertEqualsCanonicalizing([
            ['rest_api_init', 'register_rest_routes', 10, 1],
            // MxChat_Integrator (MxChat 3.2.25) passes ($message, $session_id,
            // $bot_id): with fewer accepted_args every text lands under
            // 'default' and a Multi-Bot bot's /query goes out without it.
            ['mxchat_retrieval_query', 'capture_original_retrieval_query', PHP_INT_MIN, 3],
            ['mxchat_retrieval_query', 'capture_retrieval_query', PHP_INT_MAX, 3],
            ['mxchat_pinecone_query_body', 'note_query_body', PHP_INT_MAX, 2],
            // WP_Http::request() passes ($parsed_args, $url).
            ['http_request_args', 'sign_loopback_request', 99, 2],
            // Vector_Store_Query::run() and Options::get_for_bot().
            ['mxchat_plus_duckdb_query_text', 'default_query_text', PHP_INT_MIN, 3],
            ['mxchat_plus_duckdb_bot_config', 'follow_host_hybrid_toggle', PHP_INT_MIN, 2],
            ['update_option_' . MxChat_Plus_DuckDB_Pinecone_Proxy::HOST_HYBRID_TOGGLE_OPTION, 'flush_cache_on_host_hybrid_toggle', 10, 0],
            ['add_option_' . MxChat_Plus_DuckDB_Pinecone_Proxy::HOST_HYBRID_TOGGLE_OPTION, 'flush_cache_on_host_hybrid_toggle', 10, 0],
        ], $rows);

        // A second call adds only rest_api_init again, which WP_Hook
        // deduplicates (same callback, same priority); the loopback hooks
        // sit behind the proxy's own guard.
        $this->proxy->register_routes();
        $again = array_slice($GLOBALS['__test_hooks'], count($rows));
        $this->assertSame(['rest_api_init'], array_column($again, 'hook'), 'loopback hooks registered once, however often boot calls it');
    }

    /**
     * A store answering both hybrid legs; every statement is recorded with
     * its bound parameters.
     */
    private static function hybridConnection(): MxChat_Plus_DuckDB_Connection {
        return new class implements MxChat_Plus_DuckDB_Connection {
            /** @var list<array{0: string, 1: array}> */
            public array $calls = [];
            public function execute(string $sql, array $params = []): array {
                $this->calls[] = [$sql, $params];
                if (stripos($sql, 'SELECT value FROM "mxchat_plus_duckdb_schema_meta"') !== false) {
                    if (stripos($sql, "'schema_version'") !== false) {
                        return [['value' => (string) MxChat_Plus_DuckDB_Vector_Store_Schema::TARGET_SCHEMA_VERSION]];
                    }
                    return stripos($sql, "'fts_available'") !== false ? [['value' => '1']] : [];
                }
                if (stripos($sql, 'match_bm25') !== false) {
                    return [['id' => 'sku', 'score' => 7.5]];
                }
                if (stripos($sql, 'vector_id AS id') !== false && stripos($sql, 'ORDER BY') !== false) {
                    return [
                        ['id' => 'hours', 'score' => 0.62, 'text' => 'Opening hours', 'source_url' => 'https://s.test/hours'],
                        ['id' => 'sku',   'score' => 0.41, 'text' => 'Part 4471-AB-12 is in stock', 'source_url' => 'https://s.test/sku'],
                    ];
                }
                return [];
            }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'mock:proxy-e2e'; }
            public function supports_capability(string $cap): bool { return $cap === MxChat_Plus_DuckDB_Connection::CAP_VSS_PERSISTENT_INDEX; }

            /** @return list<array> bound parameters of every BM25 statement */
            public function bm25_params(): array {
                $out = [];
                foreach ($this->calls as [$sql, $params]) {
                    if (stripos($sql, 'match_bm25') !== false) $out[] = $params;
                }
                return $out;
            }
        };
    }

    /**
     * One chat message for $bot, as MxChat_Integrator sends it (MxChat
     * 3.2.25), through the hooks register_routes() recorded: the host's
     * filters and WordPress's http_request_args on the calling side; check_token
     * then handle_query on the receiving side, on a real store.
     *
     * @return array{0: WP_REST_Response, 1: object}
     */
    private function chatTurnThroughHooks(string $bot, string $message, array $options): array {
        update_option('mxchat_plus_duckdb_options', array_merge(MxChat_Plus_DuckDB_Options::defaults(), [
            'enabled' => true, 'embedding_dim' => 3, 'query_cache_enabled' => false, 'dedup_per_source' => false,
        ], $options));
        update_option(MxChat_Plus_DuckDB_Pinecone_Proxy::PROXY_TOKEN_MAP_OPTION, [$bot => 'tok_' . $bot]);
        $conn = self::hybridConnection();
        MxChat_Test_Helpers::reset_schema_memoisation();
        MxChat_Test_Helpers::reset_vector_store_current();
        MxChat_Test_Helpers::inject_mock_connection($conn);
        MxChat_Plus_DuckDB_Vector_Store_Query::reset_fts_status_cache();
        // The host roles lookup reads $GLOBALS['wpdb'], which another test
        // may have left behind; it is not what this test is about.
        if (array_key_exists('wpdb', $GLOBALS)) {
            $this->saved_wpdb = $GLOBALS['wpdb'];
            unset($GLOBALS['wpdb']);
        }

        $this->registerFresh();
        $GLOBALS['__test_dispatch_filters'] = true;
        $GLOBALS['__test_filter_calls'] = [];

        // ── Calling side: the chat request ──
        $retrieval = apply_filters('mxchat_retrieval_query', $message, 'sess_1', $bot);
        $this->assertSame($message, $retrieval, 'the host gets its query back unchanged');
        $body = apply_filters('mxchat_pinecone_query_body', [
            'vector' => [0.1, 0.2, 0.3], 'topK' => 50, 'includeMetadata' => true, 'includeValues' => true, 'namespace' => $bot,
        ], $bot);
        $url = 'https://' . MxChat_Plus_DuckDB_Pinecone_Proxy::pinecone_host() . '/query';
        // wp_remote_post(): WP_Http::post() sets the method, then
        // WP_Http::request() applies http_request_args.
        $args = apply_filters('http_request_args', [
            'method'  => 'POST',
            'headers' => ['Api-Key' => 'tok_' . $bot, 'accept' => 'application/json', 'content-type' => 'application/json'],
            'body'    => (string) wp_json_encode($body),
            'timeout' => 30,
        ], $url);

        // ── Receiving side: the proxy's REST request ──
        $make = function (bool $signed) use ($args, $url): WP_REST_Request {
            $req = new WP_REST_Request();
            $req->set_method($args['method']);
            $req->set_route('/' . MxChat_Plus_DuckDB_Pinecone_Proxy::REST_NS . '/' . MxChat_Plus_DuckDB_Pinecone_Proxy::REST_BASE
                . MxChat_Plus_DuckDB_Pinecone_Proxy::proxy_path_from_url($url));
            foreach ($args['headers'] as $name => $value) {
                if (!$signed && strcasecmp($name, MxChat_Plus_DuckDB_Pinecone_Proxy::INTERNAL_HEADER) === 0) continue;
                $req->set_header($name, (string) $value);
            }
            $req->set_json_params(json_decode($args['body'], true));
            return $req;
        };
        // The external bucket of this bot is already spent (this minute and
        // the next, so a minute boundary cannot reopen it): only the
        // signature added on http_request_args lets the call through.
        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_proxy_rate_limit_per_minute'] = 1;
        foreach ([time(), time() + 60] as $t) {
            $GLOBALS['__test_transients']['mxchat_plus_duckdb_rl_' . $bot . '_' . gmdate('YmdHi', $t)] = 1;
        }
        $this->assertInstanceOf(WP_Error::class, $this->proxy->check_token($make(false)), 'unsigned twin: throttled');
        $req = $make(true);
        $this->assertTrue($this->proxy->check_token($req), 'signed on http_request_args, verified by check_token');

        return [$this->proxy->handle_query($req), $conn];
    }

    /** Arguments of every apply_filters() call on $hook during the turn. */
    private static function filterCallArgs(string $hook): array {
        $out = [];
        foreach ((array) ($GLOBALS['__test_filter_calls'] ?? []) as $call) {
            if ($call['hook'] === $hook) $out[] = $call['args'];
        }
        return $out;
    }

    public function test_a_chat_turn_of_a_multibot_bot_reaches_bm25_through_the_registered_hooks(): void {
        // A non-default bot: a text filed under the wrong bot, or a store
        // asking for the wrong key, still looks fine for 'default'.
        $message = 'Do you stock 4471-AB-12?';
        [$resp, $conn] = $this->chatTurnThroughHooks('sales', $message, ['hybrid_enabled' => true]);

        $this->assertSame(200, $resp->status, (string) ($resp->data['error'] ?? ''));
        $this->assertSame([[$message, 'sales', $message]], $conn->bm25_params(),
            'the BM25 leg runs once, with the visitor text bound to both placeholders and the namespace as the bot');
        $this->assertContains('sku', array_column($resp->data['matches'], 'id'));

        // What run() asks for, and for which bot: the namespace.
        $this->assertSame([['sales', []]], self::filterCallArgs('mxchat_plus_duckdb_query_text'));
        $this->assertContains(['sales'], array_map(fn($a) => [$a[0]], self::filterCallArgs('mxchat_plus_duckdb_bot_config')));
    }

    public function test_the_host_hybrid_toggle_alone_does_not_turn_bm25_on(): void {
        update_option(MxChat_Plus_DuckDB_Pinecone_Proxy::HOST_HYBRID_TOGGLE_OPTION, 'on');
        [$resp, $conn] = $this->chatTurnThroughHooks('sales', 'blue widget', ['hybrid_enabled' => false]);
        $this->assertSame(200, $resp->status);
        $this->assertSame([], $conn->bm25_params(), 'not opted in: our hybrid_enabled stays the switch');
    }

    public function test_a_site_that_opted_in_follows_the_host_hybrid_toggle_through_the_bot_config_filter(): void {
        update_option(MxChat_Plus_DuckDB_Pinecone_Proxy::HOST_HYBRID_TOGGLE_OPTION, 'on');
        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_proxy_follow_host_hybrid_toggle'] = true;
        [$resp, $conn] = $this->chatTurnThroughHooks('sales', 'blue widget', ['hybrid_enabled' => false]);
        $this->assertSame(200, $resp->status);
        $this->assertSame([['blue widget', 'sales', 'blue widget']], $conn->bm25_params(),
            'follow_host_hybrid_toggle() turns hybrid on, through mxchat_plus_duckdb_bot_config, for this proxy query');
    }
}
