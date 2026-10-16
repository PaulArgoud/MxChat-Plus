<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the Pinecone-proxy authentication path
 * (MxChat_Plus_DuckDB_Pinecone_Proxy::check_token) and the per-namespace
 * rate-limit bucket added in v0.6.0.
 *
 * Why this test exists: check_token gates every REST endpoint the proxy
 * exposes. A regression here either locks legitimate callers out (chatbot
 * stops working) or — worse — lets cross-namespace access leak (one bot
 * reads another bot's vectors). Constant-time comparison and per-namespace
 * isolation are the load-bearing properties; we test both.
 *
 * Also locked here: check_token_any_namespace (describe_index_stats, whose
 * body MxChat sends as '{}'), and the loopback signature that exempts
 * MxChat's own calls from the external rate limit — bound to the method,
 * the path, the minute and the token, never a way around the token check.
 */
final class ProxyAuthTest extends TestCase {

    private MxChat_Plus_DuckDB_Pinecone_Proxy $proxy;

    protected function setUp(): void {
        $GLOBALS['__test_options']    = [];
        $GLOBALS['__test_transients'] = [];
        $GLOBALS['__test_filter_overrides'] = [];
        $this->proxy = MxChat_Plus_DuckDB_Pinecone_Proxy::instance();
    }

    protected function tearDown(): void {
        $GLOBALS['__test_filter_overrides'] = [];
    }

    private function makeRequest(?string $api_key, string $namespace = ''): WP_REST_Request {
        $req = new WP_REST_Request();
        if ($api_key !== null) {
            $req->set_header('api-key', $api_key);
        }
        if ($namespace !== '') {
            $req->set_json_params(['namespace' => $namespace]);
        }
        return $req;
    }

    private function callPrivateRateLimit(string $namespace): bool {
        $m = new ReflectionMethod(MxChat_Plus_DuckDB_Pinecone_Proxy::class, 'within_rate_limit');
        return (bool) $m->invoke($this->proxy, $namespace);
    }

    // ─── Token: malformed / missing ───────────────────────────────────────

    public function test_missing_api_key_header_is_rejected(): void {
        $req = new WP_REST_Request();
        $this->assertFalse($this->proxy->check_token($req));
    }

    public function test_empty_api_key_header_is_rejected(): void {
        $req = $this->makeRequest('');
        $this->assertFalse($this->proxy->check_token($req));
    }

    public function test_wrong_api_key_is_rejected(): void {
        update_option(MxChat_Plus_DuckDB_Pinecone_Proxy::PROXY_TOKEN_OPTION, 'real-token-abc');
        $req = $this->makeRequest('wrong-token-xyz');
        $this->assertFalse($this->proxy->check_token($req));
    }

    // ─── Token: legacy global ─────────────────────────────────────────────

    public function test_legacy_global_token_accepted_for_any_namespace(): void {
        // The legacy single token has wildcard scope — historical contract for
        // installs predating per-namespace tokens. Must keep working.
        update_option(MxChat_Plus_DuckDB_Pinecone_Proxy::PROXY_TOKEN_OPTION, 'global-token');

        foreach (['default', 'support_fr', 'sales_en', 'random_ns'] as $ns) {
            $req = $this->makeRequest('global-token', $ns);
            $this->assertTrue($this->proxy->check_token($req), "Legacy token must work for namespace '$ns'");
        }
    }

    // ─── Token: per-namespace ─────────────────────────────────────────────

    public function test_per_namespace_token_accepted_for_its_namespace(): void {
        update_option(MxChat_Plus_DuckDB_Pinecone_Proxy::PROXY_TOKEN_MAP_OPTION, [
            'support_fr' => 'tok_fr_secret',
            'sales_en'   => 'tok_en_secret',
        ]);

        $req = $this->makeRequest('tok_fr_secret', 'support_fr');
        $this->assertTrue($this->proxy->check_token($req));
    }

    public function test_per_namespace_token_REJECTED_for_other_namespace(): void {
        // The whole point of per-namespace tokens: a leak on one bot must not
        // grant access to another. If this assertion regresses, cross-tenant
        // isolation is broken.
        update_option(MxChat_Plus_DuckDB_Pinecone_Proxy::PROXY_TOKEN_MAP_OPTION, [
            'support_fr' => 'tok_fr_secret',
            'sales_en'   => 'tok_en_secret',
        ]);

        $req = $this->makeRequest('tok_fr_secret', 'sales_en');
        $this->assertFalse(
            $this->proxy->check_token($req),
            'tok_fr_secret must NOT authorise requests targeting sales_en'
        );
    }

    public function test_per_namespace_takes_precedence_over_legacy_global(): void {
        // When both maps exist, per-namespace wins for the matching ns and
        // legacy global still works as a wildcard fallback for any ns it
        // hasn't been explicitly overridden for.
        update_option(MxChat_Plus_DuckDB_Pinecone_Proxy::PROXY_TOKEN_OPTION, 'global-token');
        update_option(MxChat_Plus_DuckDB_Pinecone_Proxy::PROXY_TOKEN_MAP_OPTION, [
            'support_fr' => 'tok_fr',
        ]);

        // per-namespace token works for its ns
        $this->assertTrue($this->proxy->check_token($this->makeRequest('tok_fr', 'support_fr')));
        // legacy global still works for an unrelated ns
        $this->assertTrue($this->proxy->check_token($this->makeRequest('global-token', 'other_ns')));
        // per-namespace token does NOT leak to other ns
        $this->assertFalse($this->proxy->check_token($this->makeRequest('tok_fr', 'other_ns')));
    }

    public function test_namespace_can_come_from_query_string_too(): void {
        // mxchat may pass the namespace either in the JSON body or as a query
        // param (Pinecone wire protocol accepts both).
        update_option(MxChat_Plus_DuckDB_Pinecone_Proxy::PROXY_TOKEN_MAP_OPTION, [
            'sales_en' => 'tok_en',
        ]);

        $req = new WP_REST_Request();
        $req->set_header('api-key', 'tok_en');
        $req->set_query_params(['namespace' => 'sales_en']);
        $this->assertTrue($this->proxy->check_token($req));
    }

    // ─── Routes without a namespace (describe_index_stats) ────────────────

    public function test_any_namespace_check_accepts_a_bot_token_whatever_the_body_says(): void {
        // MxChat posts '{}' to describe_index_stats, which namespace_from_request()
        // reads as 'default': the per-namespace check would answer 401 to
        // every Multi-Bot bot, and the host would show a count of 0.
        update_option(MxChat_Plus_DuckDB_Pinecone_Proxy::PROXY_TOKEN_MAP_OPTION, ['sales' => 'tok_sales']);
        $req = $this->makeRequest('tok_sales');
        $req->set_json_params([]);

        $this->assertFalse($this->proxy->check_token($req), 'the namespaced check still refuses it');
        $this->assertTrue($this->proxy->check_token_any_namespace($req));
        $this->assertSame('sales', $req->get_param(MxChat_Plus_DuckDB_Pinecone_Proxy::AUTH_NS_PARAM),
            'the handler scopes its answer to the token');
    }

    public function test_any_namespace_check_still_requires_a_valid_token(): void {
        update_option(MxChat_Plus_DuckDB_Pinecone_Proxy::PROXY_TOKEN_OPTION, 'global-token');
        $this->assertFalse($this->proxy->check_token_any_namespace($this->makeRequest('nope')));
        $this->assertFalse($this->proxy->check_token_any_namespace($this->makeRequest(null)));

        $legacy = $this->makeRequest('global-token');
        $this->assertTrue($this->proxy->check_token_any_namespace($legacy));
        $this->assertSame('*', $legacy->get_param(MxChat_Plus_DuckDB_Pinecone_Proxy::AUTH_NS_PARAM));
    }

    public function test_namespace_zero_is_a_namespace_not_the_default(): void {
        update_option(MxChat_Plus_DuckDB_Pinecone_Proxy::PROXY_TOKEN_MAP_OPTION, ['0' => 'tok_zero']);
        $this->assertTrue($this->proxy->check_token($this->makeRequest('tok_zero', '0')));
    }

    // ─── MxChat's own loopback calls ──────────────────────────────────────

    private function loopbackRequest(string $token, string $method, string $path, ?string $signature, string $namespace = 'default'): WP_REST_Request {
        $req = new WP_REST_Request();
        $req->set_header('api-key', $token);
        $req->set_method($method);
        $req->set_route('/mxchat-plus/v1/pinecone-proxy' . $path);
        if ($method === 'GET') {
            $req->set_query_params(['namespace' => $namespace]);
        } else {
            $req->set_json_params(['namespace' => $namespace]);
        }
        if ($signature !== null) {
            $req->set_header(MxChat_Plus_DuckDB_Pinecone_Proxy::INTERNAL_HEADER, $signature);
        }
        return $req;
    }

    private static function sign(string $method, string $path, string $token, int $minutes_ago = 0): string {
        return MxChat_Plus_DuckDB_Pinecone_Proxy::loopback_signature(
            $method, $path, MxChat_Plus_DuckDB_Pinecone_Proxy::loopback_window() - $minutes_ago, $token
        );
    }

    public function test_signed_loopback_bulk_calls_are_not_rate_limited(): void {
        // REST DELETE /knowledge with 50 URLs makes ~150 list/delete calls in
        // seconds; a 429 there reports entries as not deleted and leaves chunks.
        update_option(MxChat_Plus_DuckDB_Pinecone_Proxy::PROXY_TOKEN_MAP_OPTION, ['default' => 'tok']);
        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_proxy_rate_limit_per_minute'] = 2;

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->proxy->check_token(
                $this->loopbackRequest('tok', 'GET', '/vectors/list', self::sign('GET', '/vectors/list', 'tok'))
            ), "signed call $i");
        }
        // …and they did not draw on the bucket external callers (and chat) share.
        $this->assertTrue($this->proxy->check_token($this->loopbackRequest('tok', 'GET', '/vectors/list', null)));
        $this->assertTrue($this->proxy->check_token($this->loopbackRequest('tok', 'GET', '/vectors/list', null)));
        $this->assertInstanceOf(WP_Error::class, $this->proxy->check_token($this->loopbackRequest('tok', 'GET', '/vectors/list', null)),
            'external callers keep the limit');
    }

    public function test_signed_loopback_queries_keep_a_bucket_of_their_own(): void {
        update_option(MxChat_Plus_DuckDB_Pinecone_Proxy::PROXY_TOKEN_MAP_OPTION, ['default' => 'tok']);
        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_proxy_internal_rate_limit_per_minute'] = 2;
        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_proxy_rate_limit_per_minute'] = 1;

        $q = fn() => $this->loopbackRequest('tok', 'POST', '/query', self::sign('POST', '/query', 'tok'));
        $this->assertTrue($this->proxy->check_token($q()));
        $this->assertTrue($this->proxy->check_token($q()));
        $res = $this->proxy->check_token($q());
        $this->assertInstanceOf(WP_Error::class, $res, '/query keeps a CPU guard even for the host');
        $this->assertSame(429, $res->get_error_data()['status'] ?? null);

        // The external bucket is untouched by those three calls.
        $this->assertTrue($this->proxy->check_token($this->loopbackRequest('tok', 'POST', '/query', null)));
    }

    public function test_previous_minute_signature_is_accepted_older_ones_are_not(): void {
        update_option(MxChat_Plus_DuckDB_Pinecone_Proxy::PROXY_TOKEN_MAP_OPTION, ['default' => 'tok']);
        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_proxy_rate_limit_per_minute'] = 1;

        // Burn the external bucket, so only a valid signature can pass.
        $this->assertTrue($this->proxy->check_token($this->loopbackRequest('tok', 'GET', '/vectors/list', null)));

        $this->assertTrue($this->proxy->check_token(
            $this->loopbackRequest('tok', 'GET', '/vectors/list', self::sign('GET', '/vectors/list', 'tok', 1))
        ), 'a call signed just before the minute turned');
        $this->assertInstanceOf(WP_Error::class, $this->proxy->check_token(
            $this->loopbackRequest('tok', 'GET', '/vectors/list', self::sign('GET', '/vectors/list', 'tok', 2))
        ));
    }

    public function test_a_signature_is_bound_to_method_path_and_token(): void {
        update_option(MxChat_Plus_DuckDB_Pinecone_Proxy::PROXY_TOKEN_MAP_OPTION, ['default' => 'tok', 'sales' => 'tok_sales']);
        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_proxy_rate_limit_per_minute'] = 1;
        $this->assertTrue($this->proxy->check_token($this->loopbackRequest('tok', 'POST', '/vectors/delete', null)));

        foreach ([
            'other path'   => self::sign('POST', '/vectors/upsert', 'tok'),
            'other method' => self::sign('GET', '/vectors/delete', 'tok'),
            'other token'  => self::sign('POST', '/vectors/delete', 'tok_sales'),
            'garbage'      => 'deadbeef',
        ] as $case => $sig) {
            $this->assertInstanceOf(WP_Error::class,
                $this->proxy->check_token($this->loopbackRequest('tok', 'POST', '/vectors/delete', $sig)), $case);
        }
    }

    public function test_a_signature_never_bypasses_the_token_or_namespace_checks(): void {
        update_option(MxChat_Plus_DuckDB_Pinecone_Proxy::PROXY_TOKEN_MAP_OPTION, ['sales' => 'tok_sales']);
        $req = $this->loopbackRequest('tok_sales', 'POST', '/query', self::sign('POST', '/query', 'tok_sales'), 'support');
        $this->assertFalse($this->proxy->check_token($req));
        $this->assertFalse($this->proxy->check_token(
            $this->loopbackRequest('unknown', 'POST', '/query', self::sign('POST', '/query', 'unknown'))
        ));
    }

    // ─── Rate limit ───────────────────────────────────────────────────────

    public function test_rate_limit_blocks_after_the_default_120_per_minute(): void {
        // Burn through the default ceiling of 120 requests on namespace "noisy",
        // verify the 121st is rejected, AND that another namespace keeps working
        // (per-namespace buckets are the v0.6.0 hardening contract).
        for ($i = 0; $i < 120; $i++) {
            $this->assertTrue($this->callPrivateRateLimit('noisy'), "request $i should pass");
        }
        $this->assertFalse($this->callPrivateRateLimit('noisy'), 'request 121 must be blocked');

        // Different namespace = different bucket.
        $this->assertTrue(
            $this->callPrivateRateLimit('quiet'),
            'other namespaces must NOT be throttled by another bucket'
        );
    }

    public function test_throttled_request_returns_429_error_not_401(): void {
        // A throttled-but-authenticated caller must get a 429 WP_Error, not a
        // bare false (which WP turns into a 401 indistinguishable from a bad
        // token). Drop the ceiling to 2 via the filter so we don't burn 120.
        update_option(MxChat_Plus_DuckDB_Pinecone_Proxy::PROXY_TOKEN_OPTION, 'tok');
        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_proxy_rate_limit_per_minute'] = 2;
        try {
            $this->assertTrue($this->proxy->check_token($this->makeRequest('tok', 'ns')));
            $this->assertTrue($this->proxy->check_token($this->makeRequest('tok', 'ns')));
            $res = $this->proxy->check_token($this->makeRequest('tok', 'ns'));
        } finally {
            $GLOBALS['__test_filter_overrides'] = [];
        }
        $this->assertInstanceOf(WP_Error::class, $res);
        $data = $res->get_error_data();
        $this->assertSame(429, is_array($data) ? ($data['status'] ?? null) : null);
        // A wrong token is still a plain false (→ 401), not a 429.
        $this->assertFalse($this->proxy->check_token($this->makeRequest('wrong-token', 'ns')));
    }

    public function test_rate_limit_can_be_disabled_with_filter_returning_zero(): void {
        // The shim's apply_filters() honours $GLOBALS['__test_filter_overrides'].
        $GLOBALS['__test_filter_overrides']['mxchat_plus_duckdb_proxy_rate_limit_per_minute'] = 0;
        for ($i = 0; $i < 200; $i++) {
            $this->assertTrue($this->callPrivateRateLimit('isolated_ns'), "request $i");
        }
        $this->assertSame([], array_filter(
            array_keys($GLOBALS['__test_transients']),
            fn($k) => strpos((string) $k, 'mxchat_plus_duckdb_rl_') === 0
        ), 'a disabled limiter keeps no counter');
    }

    public function test_unusual_namespace_names_are_hashed_into_a_safe_key(): void {
        // Namespaces matching [a-zA-Z0-9_-]{1,32} land verbatim in the transient
        // key; anything else gets md5-shortened so wp_options doesn't end up
        // with weird control chars or 4 KB keys.
        $weird = "héllo/world\n\t<x>";
        for ($i = 0; $i < 120; $i++) {
            $this->assertTrue($this->callPrivateRateLimit($weird));
        }
        $this->assertFalse(
            $this->callPrivateRateLimit($weird),
            'rate limit still works on namespaces with unusual characters'
        );

        // The key should NOT contain the literal weird characters.
        $found_safe_key = false;
        foreach (array_keys($GLOBALS['__test_transients']) as $key) {
            if (strpos($key, 'mxchat_plus_duckdb_rl_') === 0) {
                $this->assertDoesNotMatchRegularExpression(
                    '#[^a-zA-Z0-9_\-]#',
                    substr($key, strlen('mxchat_plus_duckdb_rl_')),
                    'transient key body must be safe ASCII'
                );
                $found_safe_key = true;
            }
        }
        $this->assertTrue($found_safe_key, 'a rate-limit transient should have been created');
    }
}
