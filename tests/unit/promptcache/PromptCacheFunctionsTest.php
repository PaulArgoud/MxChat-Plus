<?php
/**
 * Tests unitaires des fonctions pures du module Prompt Cache.
 *
 * Portage des assertions du plugin autonome mxchat-promptcache
 * (tests/test-functions.php) vers PHPUnit. Les helpers globaux `check()` et
 * `ep_style()` de l'original ont disparu : noms trop génériques, sans garde
 * function_exists — ils entreraient en collision dans une suite partagée.
 *
 * But : verrouiller la table de seuils par modèle (qui dérive côté Anthropic)
 * et la logique de mesure/buckets, pour qu'une régression soit attrapée
 * automatiquement.
 */

use PHPUnit\Framework\TestCase;

// ── Auto-suffisance : le fichier fonctionne avec ou sans tests/bootstrap.php ──
if (!defined('ABSPATH'))         { define('ABSPATH', dirname(__DIR__, 2) . '/'); }
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value, ...$a) { return $value; } }
if (!function_exists('wp_parse_url'))  { function wp_parse_url($url) { return parse_url($url); } }
if (!function_exists('wp_json_encode')){ function wp_json_encode($data) { return json_encode($data); } }
if (!function_exists('__'))            { function __($text, $domain = null) { return $text; } }

if (!class_exists('MxChat_Plus_PromptCache_Models')) {
    $mxchat_plus_pc_dir = dirname(__DIR__, 3) . '/includes/promptcache/';
    require_once $mxchat_plus_pc_dir . 'promptcache-constants.php';
    require_once $mxchat_plus_pc_dir . 'class-promptcache-models.php';
    require_once $mxchat_plus_pc_dir . 'class-promptcache-stats.php';
}

final class PromptCacheFunctionsTest extends TestCase {

    // ──────────────────────────────────────────────────────────────────
    //  Seuils par modèle — GARDE-FOU D'ORDRE
    // ──────────────────────────────────────────────────────────────────

    /**
     * Verrouille la table des seuils, famille par famille, en valeurs
     * LITTÉRALES (et non via les constantes) : une régression sur l'ordre des
     * clauses de min_chars_for_model() *ou* sur la valeur d'une constante est
     * attrapée ici.
     *
     * Pièges d'ordre (le premier a déjà causé une régression, corrigée en 0.7.0) :
     *   - `mythos-preview` doit être testé AVANT `fable`/`mythos` ;
     *   - `haiku-5` et `haiku-4` doivent être testés AVANT `haiku` ;
     *   - `sonnet-5-5` doit être testé AVANT `sonnet`.
     *
     * @dataProvider modelThresholdProvider
     */
    public function test_min_chars_table_is_locked(string $model, int $expected): void {
        $this->assertSame(
            $expected,
            MxChat_Plus_PromptCache_Models::min_chars_for_model($model),
            sprintf('Seuil inattendu pour « %s » — ordre des clauses probablement cassé.', $model)
        );
    }

    /** @return array<string, array{0:string, 1:int}> */
    public static function modelThresholdProvider(): array {
        return [
            // famille                 modèle                         seuil attendu
            'fable-5.1'            => ['claude-fable-5-1',            2050],
            'fable-5'              => ['claude-fable-5',              2050],
            'mythos-5.1'           => ['claude-mythos-5-1',           2050],
            'mythos-5'             => ['claude-mythos-5',             2050],
            'mythos-preview'       => ['claude-mythos-preview',       8200],
            'opus-5.5'             => ['claude-opus-5-5',             2050],
            'opus-5'               => ['claude-opus-5',               2050],
            'opus-4.8'             => ['claude-opus-4-8',             4000],
            'opus-4.7'             => ['claude-opus-4-7',             8200],
            'opus-4.6'             => ['claude-opus-4-6',            16400],
            'opus-4 (daté)'        => ['claude-opus-4-20250514',      4000],
            'sonnet-5.5'           => ['claude-sonnet-5-5',           2050],
            'sonnet-5'             => ['claude-sonnet-5',             4000],
            'sonnet-4.6'           => ['claude-sonnet-4-6',           4000],
            'haiku-5.5'            => ['claude-haiku-5-5',            2050],
            'haiku-4.5'            => ['claude-haiku-4-5',           16400],
            'haiku-3.5'            => ['claude-3-5-haiku-20241022',   8200],
            'modèle inconnu'       => ['gpt-5.1',                    16400],
        ];
    }

    /** L'ordre des clauses, exprimé comme relation entre familles voisines. */
    public function test_prefix_families_are_not_conflated(): void {
        $m = MxChat_Plus_PromptCache_Models::class;
        // mythos-preview (2048) ne doit PAS retomber sur mythos (512).
        $this->assertNotSame(
            $m::min_chars_for_model('claude-mythos-5'),
            $m::min_chars_for_model('claude-mythos-preview'),
            'mythos-preview doit être testé avant mythos.'
        );
        // haiku-4.5 (4096) ne doit PAS retomber sur haiku 3.x (2048).
        $this->assertNotSame(
            $m::min_chars_for_model('claude-3-5-haiku-20241022'),
            $m::min_chars_for_model('claude-haiku-4-5'),
            'haiku-4 doit être testé avant haiku.'
        );
        // haiku-5.5 (512) ne doit PAS retomber sur haiku 3.x (2048).
        $this->assertNotSame(
            $m::min_chars_for_model('claude-3-5-haiku-20241022'),
            $m::min_chars_for_model('claude-haiku-5-5'),
            'haiku-5 doit être testé avant haiku.'
        );
        // sonnet-5.5 (512) ne doit PAS retomber sur Sonnet 5 (1024).
        $this->assertNotSame(
            $m::min_chars_for_model('claude-sonnet-5'),
            $m::min_chars_for_model('claude-sonnet-5-5'),
            'sonnet-5-5 doit être testé avant sonnet.'
        );
    }

    /** Table étendue (versions supplémentaires + entrées dégénérées). */
    public function test_min_chars_extended_table(): void {
        $thresholds = [
            'claude-opus-4-5'            => MXCHAT_PLUS_PC_MIN_CHARS_4096,
            'claude-opus-4-1'            => MXCHAT_PLUS_PC_MIN_CHARS_1024,
            'claude-opus-4-0'            => MXCHAT_PLUS_PC_MIN_CHARS_1024,
            'claude-opus-9-9'            => MXCHAT_PLUS_PC_MIN_CHARS_4096, // Opus inconnu
            'claude-sonnet-4-5'          => MXCHAT_PLUS_PC_MIN_CHARS_1024,
            'claude-3-7-sonnet-20250219' => MXCHAT_PLUS_PC_MIN_CHARS_1024,
            'CLAUDE-OPUS-4-8'            => MXCHAT_PLUS_PC_MIN_CHARS_1024, // insensible à la casse
            ''                           => MXCHAT_PLUS_PC_MIN_CHARS_DEFAULT,
        ];
        foreach ($thresholds as $model => $expected) {
            $this->assertSame(
                $expected,
                MxChat_Plus_PromptCache_Models::min_chars_for_model((string) $model),
                "min_chars({$model})"
            );
        }
        // Entrée non-string : défaut sûr, jamais d'erreur.
        $this->assertSame(
            MXCHAT_PLUS_PC_MIN_CHARS_DEFAULT,
            MxChat_Plus_PromptCache_Models::min_chars_for_model(null)
        );
    }

    // ──────────────────────────────────────────────────────────────────
    //  identify_endpoint
    // ──────────────────────────────────────────────────────────────────

    /** @dataProvider endpointProvider */
    public function test_identify_endpoint(string $url, ?string $expected_style): void {
        $endpoint = MxChat_Plus_PromptCache_Stats::identify_endpoint($url);
        $style    = is_array($endpoint) ? ($endpoint['style'] ?? null) : null;
        $this->assertSame($expected_style, $style);
    }

    /** @return array<string, array{0:string, 1:?string}> */
    public static function endpointProvider(): array {
        return [
            'anthropic'         => ['https://api.anthropic.com/v1/messages', 'anthropic'],
            'openai'            => ['https://api.openai.com/v1/chat/completions', 'openai'],
            'openrouter'        => ['https://openrouter.ai/api/v1/chat/completions', 'openai'],
            'xai'               => ['https://api.x.ai/v1/chat/completions', 'openai'],
            'deepseek'          => ['https://api.deepseek.com/chat/completions', 'openai'],
            'deepseek v1'       => ['https://api.deepseek.com/v1/chat/completions', 'openai'],
            'gemini'            => ['https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent', 'gemini'],
            'hôte inconnu'      => ['https://example.com/x', null],
            'embeddings ignoré' => ['https://api.openai.com/v1/embeddings', null],
            'gemini streamé'    => ['https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:streamGenerateContent', null],
            // Responses API (web search non streamé, Vector Store file_search).
            'openai responses'  => ['https://api.openai.com/v1/responses', 'openai_responses'],
            // Compatibilité OpenAI de Google, via le fournisseur personnalisé de MxChat.
            'gemini compat'     => ['https://generativelanguage.googleapis.com/v1beta/openai/chat/completions', 'openai'],
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Endpoints ajoutés pour la mesure (Responses API, Gemini compat, custom)
    // ──────────────────────────────────────────────────────────────────

    public function test_gemini_compat_endpoint_is_measured_only_when_it_reports_cached_tokens(): void {
        $endpoint = MxChat_Plus_PromptCache_Stats::identify_endpoint('https://generativelanguage.googleapis.com/v1beta/openai/chat/completions');
        $this->assertSame('gemini', $endpoint['provider']);
        $this->assertTrue($endpoint['strict_usage']);

        $this->assertNull(
            MxChat_Plus_PromptCache_Stats::parse_usage($endpoint, ['model' => 'gemini-3.8-flash', 'usage' => ['prompt_tokens' => 100]]),
            'pas de cached_tokens publié : aucune mesure plutôt qu\'un faux 0 %'
        );
        $usage = MxChat_Plus_PromptCache_Stats::parse_usage($endpoint, ['model' => 'gemini-3.8-flash', 'usage' => [
            'prompt_tokens' => 100, 'prompt_tokens_details' => ['cached_tokens' => 0],
        ]]);
        $this->assertSame(100, $usage['input']);
        $this->assertSame(0, $usage['cache_read']);
    }

    /** @dataProvider customProviderProvider */
    public function test_custom_provider_url_is_recognised_in_both_host_forms(string $base, string $url, bool $expected): void {
        $GLOBALS['__test_options']['mxchat_options'] = ['custom_provider_base_url' => $base];
        try {
            $endpoint = MxChat_Plus_PromptCache_Stats::identify_endpoint($url);
            $this->assertSame($expected, $endpoint !== null && $endpoint['provider'] === 'custom', "{$base} / {$url}");
            if ($expected) {
                $this->assertSame('openai', $endpoint['style']);
                $this->assertTrue($endpoint['strict_usage']);
            }
        } finally {
            unset($GLOBALS['__test_options']['mxchat_options']);
        }
    }

    /** @return array<string, array{0:string, 1:string, 2:bool}> */
    public static function customProviderProvider(): array {
        return [
            // mxchat_resolve_custom_provider() : base + '/chat/completions' (+ ?api-version Azure).
            'chat'                  => ['https://llm.example.com/v1', 'https://llm.example.com/v1/chat/completions', true],
            'barre finale'          => ['https://llm.example.com/v1/', 'https://llm.example.com/v1/chat/completions', true],
            'azure api-version'     => ['https://x.openai.azure.com/openai/deployments/d', 'https://x.openai.azure.com/openai/deployments/d/chat/completions?api-version=2024-10-21', true],
            'port local'            => ['http://localhost:11434/v1', 'http://localhost:11434/v1/chat/completions', true],
            // mxchat_fc_resolve_provider() : base gardée telle quelle si elle contient déjà chat/completions.
            'base complète (AI Tools)' => ['https://llm.example.com/v1/chat/completions', 'https://llm.example.com/v1/chat/completions', true],
            'autre chemin'          => ['https://llm.example.com/v1', 'https://llm.example.com/v1/embeddings', false],
            'autre hôte'            => ['https://llm.example.com/v1', 'https://other.example.com/v1/chat/completions', false],
            'autre port'            => ['http://localhost:11434/v1', 'http://localhost:1234/v1/chat/completions', false],
            'autre schéma'          => ['https://llm.example.com/v1', 'http://llm.example.com/v1/chat/completions', false],
            'base vide'             => ['', 'https://llm.example.com/chat/completions', false],
        ];
    }

    public function test_custom_provider_lookup_survives_a_missing_or_odd_option(): void {
        foreach ([null, 'garbage', ['custom_provider_base_url' => 42], ['custom_provider_base_url' => 'not a url']] as $option) {
            $GLOBALS['__test_options']['mxchat_options'] = $option;
            $this->assertNull(MxChat_Plus_PromptCache_Stats::identify_endpoint('https://llm.example.com/v1/chat/completions'));
        }
        unset($GLOBALS['__test_options']['mxchat_options']);
    }

    public function test_custom_provider_without_cached_tokens_records_nothing(): void {
        // Ollama, LM Studio, vLLM… : usage sans prompt_tokens_details.
        $endpoint = ['provider' => 'custom', 'style' => 'openai', 'strict_usage' => true];
        $this->assertNull(MxChat_Plus_PromptCache_Stats::parse_usage($endpoint, ['model' => 'llama3', 'usage' => ['prompt_tokens' => 50]]));
        // Sans strict_usage (OpenAI, OpenRouter…), l'absence vaut 0 comme avant.
        $usage = MxChat_Plus_PromptCache_Stats::parse_usage(['style' => 'openai'], ['model' => 'gpt-5.1', 'usage' => ['prompt_tokens' => 50]]);
        $this->assertSame(50, $usage['input']);
    }

    public function test_parse_usage_openai_responses_style(): void {
        $usage = MxChat_Plus_PromptCache_Stats::parse_usage(
            ['style' => 'openai_responses'],
            ['model' => 'gpt-5.1', 'usage' => [
                'input_tokens'         => 1200,
                'input_tokens_details' => ['cached_tokens' => 1000],
                'output_tokens'        => 80,
            ]]
        );
        $this->assertSame('gpt-5.1', $usage['model']);
        $this->assertSame(200, $usage['input']);
        $this->assertSame(1000, $usage['cache_read']);
        $this->assertSame(0, $usage['cache_creation']);

        // L'ancien style 'openai' lisait prompt_tokens : 0 token enregistré.
        $this->assertSame(0, MxChat_Plus_PromptCache_Stats::parse_usage(['style' => 'openai'], ['usage' => ['input_tokens' => 1200]])['input']);
        $this->assertNull(MxChat_Plus_PromptCache_Stats::parse_usage(['style' => 'openai_responses'], ['model' => 'x']));
    }

    public function test_request_label_separates_the_vector_store_file_search_call(): void {
        $endpoint = ['provider' => 'openai', 'style' => 'openai_responses'];
        // find_relevant_content_openai_vectorstore() : outil file_search, pas d'instructions.
        $search = ['body' => json_encode(['model' => 'gpt-5.1', 'input' => 'q',
            'tools' => [['type' => 'file_search', 'vector_store_ids' => ['vs_1']]]])];
        // Réponse web search : clé instructions.
        $answer = ['body' => json_encode(['model' => 'gpt-5.1', 'input' => [], 'instructions' => 'sys',
            'tools' => [['type' => 'web_search']]])];

        $this->assertSame(' (file_search)', MxChat_Plus_PromptCache_Stats::request_label($endpoint, $search));
        $this->assertSame('', MxChat_Plus_PromptCache_Stats::request_label($endpoint, $answer));
        $this->assertSame('', MxChat_Plus_PromptCache_Stats::request_label(['style' => 'openai'], $search), 'Responses API uniquement');
        $this->assertSame('', MxChat_Plus_PromptCache_Stats::request_label($endpoint, null));
        $this->assertSame('', MxChat_Plus_PromptCache_Stats::request_label($endpoint, ['body' => 'not json']));
    }

    public function test_record_metrics_files_the_vector_store_search_under_its_own_label(): void {
        unset($GLOBALS['__test_options'][MXCHAT_PLUS_PC_STORE_KEY]);
        $response = ['body' => json_encode(['model' => 'gpt-5.1', 'usage' => [
            'input_tokens' => 300, 'input_tokens_details' => ['cached_tokens' => 100],
        ]])];
        $args = ['body' => json_encode(['model' => 'gpt-5.1', 'input' => 'q', 'tools' => [['type' => 'file_search']]])];

        MxChat_Plus_PromptCache::record_metrics($response, $args, 'https://api.openai.com/v1/responses');

        $store = MxChat_Plus_PromptCache_Stats::get_store();
        $this->assertSame(100, $store['total']['per_model']['gpt-5.1 (file_search)']['cache_read_tokens'] ?? null);
        $this->assertSame(200, $store['total']['input_tokens']);
        unset($GLOBALS['__test_options'][MXCHAT_PLUS_PC_STORE_KEY]);
    }

    public function test_identify_endpoint_extracts_gemini_model(): void {
        $endpoint = MxChat_Plus_PromptCache_Stats::identify_endpoint(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent'
        );
        $this->assertSame('gemini-2.5-flash', $endpoint['model']);
    }

    public function test_identify_endpoint_rejects_non_string(): void {
        $this->assertNull(MxChat_Plus_PromptCache_Stats::identify_endpoint(null));
    }

    // ──────────────────────────────────────────────────────────────────
    //  parse_usage
    // ──────────────────────────────────────────────────────────────────

    public function test_parse_usage_anthropic(): void {
        $usage = MxChat_Plus_PromptCache_Stats::parse_usage(
            ['style' => 'anthropic'],
            ['model' => 'claude-opus-4-8', 'usage' => [
                'input_tokens'                => 10,
                'cache_creation_input_tokens' => 20,
                'cache_read_input_tokens'     => 30,
            ]]
        );
        $this->assertSame('claude-opus-4-8', $usage['model']);
        $this->assertSame(10, $usage['input']);
        $this->assertSame(20, $usage['cache_creation']);
        $this->assertSame(30, $usage['cache_read']);
    }

    public function test_parse_usage_openai_deducts_cached_from_input(): void {
        $usage = MxChat_Plus_PromptCache_Stats::parse_usage(
            ['style' => 'openai'],
            ['model' => 'gpt-5.1', 'usage' => [
                'prompt_tokens'         => 100,
                'prompt_tokens_details' => ['cached_tokens' => 40],
            ]]
        );
        $this->assertSame(40, $usage['cache_read']);
        $this->assertSame(60, $usage['input']); // reste non-caché
        $this->assertSame(0, $usage['cache_creation']); // cache automatique : jamais d'écriture facturée
    }

    public function test_parse_usage_gemini(): void {
        $usage = MxChat_Plus_PromptCache_Stats::parse_usage(
            ['style' => 'gemini', 'model' => 'gemini-2.5-flash'],
            ['usageMetadata' => ['promptTokenCount' => 200, 'cachedContentTokenCount' => 50]]
        );
        $this->assertSame(50, $usage['cache_read']);
        $this->assertSame(150, $usage['input']);
        $this->assertSame('gemini-2.5-flash', $usage['model']); // repli sur le modèle de l'URL
    }

    public function test_parse_usage_returns_null_when_usage_missing(): void {
        $this->assertNull(MxChat_Plus_PromptCache_Stats::parse_usage(['style' => 'anthropic'], ['model' => 'x']));
        $this->assertNull(MxChat_Plus_PromptCache_Stats::parse_usage(['style' => 'inconnu'], ['usage' => []]));
        $this->assertNull(MxChat_Plus_PromptCache_Stats::parse_usage(null, null));
    }

    // ──────────────────────────────────────────────────────────────────
    //  Buckets + fenêtre glissante
    // ──────────────────────────────────────────────────────────────────

    /** @return array{buckets: array<int, array<string, mixed>>} */
    private function make_store(): array {
        $H     = (int) floor(time() / HOUR_IN_SECONDS);
        $store = ['buckets' => []];

        $store['buckets'][$H - 30] = MxChat_Plus_PromptCache_Stats::empty_bucket(); // hors fenêtre
        MxChat_Plus_PromptCache_Stats::bucket_add($store['buckets'][$H - 30], 'claude-opus-4-8', 999, 0, 0);

        $store['buckets'][$H - 1] = MxChat_Plus_PromptCache_Stats::empty_bucket();
        MxChat_Plus_PromptCache_Stats::bucket_add($store['buckets'][$H - 1], 'claude-opus-4-8', 100, 50, 800);
        MxChat_Plus_PromptCache_Stats::bucket_add($store['buckets'][$H - 1], 'claude-opus-4-8', 100, 0, 900);

        $store['buckets'][$H] = MxChat_Plus_PromptCache_Stats::empty_bucket();
        MxChat_Plus_PromptCache_Stats::bucket_add($store['buckets'][$H], 'gpt-5.1', 200, 0, 300);

        return $store;
    }

    public function test_empty_bucket_shape(): void {
        $bucket = MxChat_Plus_PromptCache_Stats::empty_bucket();
        $this->assertSame(0, $bucket['requests']);
        $this->assertSame(0, $bucket['cache_creation_tokens']);
        $this->assertSame(0, $bucket['cache_read_tokens']);
        $this->assertSame(0, $bucket['input_tokens']);
        $this->assertSame([], $bucket['per_model']);
    }

    public function test_aggregate_window_excludes_out_of_window_buckets(): void {
        $window = MxChat_Plus_PromptCache_Stats::aggregate_window($this->make_store());
        $this->assertSame(3, $window['requests']); // le bucket H-30 est exclu
        $this->assertSame(2000, $window['cache_read_tokens']);
        $this->assertSame(50, $window['cache_creation_tokens']);
        $this->assertSame(400, $window['input_tokens']);
    }

    public function test_aggregate_window_per_model(): void {
        $window = MxChat_Plus_PromptCache_Stats::aggregate_window($this->make_store());
        $this->assertSame(2, $window['per_model']['claude-opus-4-8']['requests']);
        $this->assertSame(300, $window['per_model']['gpt-5.1']['cache_read_tokens']);
        $this->assertArrayHasKey('window_start', $window);
    }

    public function test_aggregate_window_on_garbage_input(): void {
        $window = MxChat_Plus_PromptCache_Stats::aggregate_window(null);
        $this->assertSame(0, $window['requests']);
        $this->assertArrayNotHasKey('window_start', $window);
    }

    // ──────────────────────────────────────────────────────────────────
    //  normalize_store
    // ──────────────────────────────────────────────────────────────────

    public function test_normalize_store_from_null(): void {
        $store = MxChat_Plus_PromptCache_Stats::normalize_store(null);
        $this->assertIsArray($store['buckets']);
        $this->assertArrayHasKey('since', $store['total']);
        $this->assertSame(0, $store['total']['requests']);
    }

    public function test_normalize_store_preserves_existing_total(): void {
        $existing = ['total' => ['requests' => 7, 'since' => 123]];
        $store    = MxChat_Plus_PromptCache_Stats::normalize_store($existing);
        $this->assertSame(7, $store['total']['requests']);
        $this->assertSame(123, $store['total']['since']);
        $this->assertSame([], $store['buckets']);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Helpers de mesure (Models)
    // ──────────────────────────────────────────────────────────────────

    public function test_strlen_is_multibyte_safe(): void {
        $this->assertSame(3, MxChat_Plus_PromptCache_Models::strlen('日本語'));
        $this->assertSame(0, MxChat_Plus_PromptCache_Models::strlen(null));
    }

    public function test_system_size_handles_string_and_blocks(): void {
        $this->assertSame(5, MxChat_Plus_PromptCache_Models::system_size('abcde'));
        $this->assertSame(5, MxChat_Plus_PromptCache_Models::system_size([
            ['type' => 'text', 'text' => 'abc'],
            ['type' => 'text', 'text' => 'de'],
            ['type' => 'image'], // pas de texte : ignoré
        ]));
        $this->assertSame(0, MxChat_Plus_PromptCache_Models::system_size(null));
    }

    public function test_tools_size_short_circuits_at_stop_at(): void {
        $tools = [
            ['name' => 'aaaaa', 'description' => 'bbbbb'],
            ['name' => 'ccccc', 'description' => 'ddddd'],
        ];
        $this->assertSame(20, MxChat_Plus_PromptCache_Models::tools_size($tools));
        // Le court-circuit renvoie dès le premier outil atteignant le seuil.
        $this->assertSame(10, MxChat_Plus_PromptCache_Models::tools_size($tools, 10));
    }

    public function test_blocks_have_cache_control(): void {
        $this->assertFalse(MxChat_Plus_PromptCache_Models::blocks_have_cache_control([['text' => 'x']]));
        $this->assertTrue(MxChat_Plus_PromptCache_Models::blocks_have_cache_control([
            ['text' => 'x'],
            ['text' => 'y', 'cache_control' => ['type' => 'ephemeral']],
        ]));
        $this->assertFalse(MxChat_Plus_PromptCache_Models::blocks_have_cache_control('nope'));
    }

    public function test_last_user_indexes_returns_most_recent_first(): void {
        $messages = [
            ['role' => 'user', 'content' => 'a'],
            ['role' => 'assistant', 'content' => 'b'],
            ['role' => 'user', 'content' => 'c'],
            ['role' => 'assistant', 'content' => 'd'],
            ['role' => 'user', 'content' => 'e'],
        ];
        $this->assertSame([4, 2], MxChat_Plus_PromptCache_Models::last_user_indexes($messages, 2));
        $this->assertSame([4], MxChat_Plus_PromptCache_Models::last_user_indexes($messages, 1));
        $this->assertSame([], MxChat_Plus_PromptCache_Models::last_user_indexes('nope'));
    }

    public function test_add_cache_control_to_content_promotes_string(): void {
        $content = 'bonjour';
        $this->assertTrue(MxChat_Plus_PromptCache_Models::add_cache_control_to_content($content));
        $this->assertSame('text', $content[0]['type']);
        $this->assertSame('bonjour', $content[0]['text']);
        $this->assertSame('ephemeral', $content[0]['cache_control']['type']);
    }

    public function test_add_cache_control_to_content_accepts_an_explicit_control(): void {
        $content = 'bonjour';
        $loop    = MxChat_Plus_PromptCache_Models::loop_control(['type' => 'ephemeral', 'ttl' => '1h']);
        $this->assertSame(['type' => 'ephemeral'], $loop, 'boucle AI Tools : TTL 5 min par défaut');
        $this->assertTrue(MxChat_Plus_PromptCache_Models::add_cache_control_to_content($content, $loop));
        $this->assertSame(['type' => 'ephemeral'], $content[0]['cache_control']);

        $this->assertSame(['type' => 'ephemeral'], MxChat_Plus_PromptCache_Models::loop_control([]));
        $not_a_list = ['a' => ['type' => 'text', 'text' => 'x']];
        $this->assertFalse(MxChat_Plus_PromptCache_Models::add_cache_control_to_content($not_a_list));
    }

    public function test_content_helpers(): void {
        $m = MxChat_Plus_PromptCache_Models::class;
        $this->assertSame('ab', $m::content_text([['type' => 'text', 'text' => 'a'], ['type' => 'tool_result', 'content' => 'z'], ['type' => 'text', 'text' => 'b']]));
        $this->assertSame('x', $m::content_text('x'));
        $this->assertSame('', $m::content_text(null));

        $this->assertTrue($m::content_has_tool_blocks([['type' => 'text'], ['type' => 'tool_result']]));
        $this->assertTrue($m::content_has_tool_blocks([['type' => 'tool_use']]));
        $this->assertFalse($m::content_has_tool_blocks([['type' => 'text']]));
        $this->assertFalse($m::content_has_tool_blocks('tool_use'));
    }

    public function test_add_cache_control_to_content_marks_last_block(): void {
        $content = [['type' => 'text', 'text' => 'a'], ['type' => 'text', 'text' => 'b']];
        $this->assertTrue(MxChat_Plus_PromptCache_Models::add_cache_control_to_content($content));
        $this->assertArrayNotHasKey('cache_control', $content[0]);
        $this->assertArrayHasKey('cache_control', $content[1]);

        $empty = [];
        $this->assertFalse(MxChat_Plus_PromptCache_Models::add_cache_control_to_content($empty));
    }

    public function test_ephemeral_control_defaults_to_one_hour_ttl(): void {
        $control = MxChat_Plus_PromptCache_Models::ephemeral_control();
        $this->assertSame('ephemeral', $control['type']);
        $this->assertSame('1h', $control['ttl']);
        $this->assertTrue(MxChat_Plus_PromptCache_Models::using_extended_ttl());
    }

    public function test_caching_hint_only_fires_on_unread_writes(): void {
        // Écritures > lectures sur un volume significatif : avertissement.
        $hint = MxChat_Plus_PromptCache_Models::caching_hint([
            'requests' => 10, 'cache_creation_tokens' => 500, 'cache_read_tokens' => 10,
        ]);
        $this->assertNotSame('', $hint);
        // Chaîne source anglaise (CLAUDE.md), qui nomme la cause introduite en 3.2.22.
        $this->assertStringContainsString('Cache writes exceed cache reads', $hint);
        $this->assertStringContainsString('{current_datetime}', $hint);
        // Cache relu : silence.
        $this->assertSame('', MxChat_Plus_PromptCache_Models::caching_hint([
            'requests' => 10, 'cache_creation_tokens' => 500, 'cache_read_tokens' => 5000,
        ]));
        // Trop peu de trafic pour conclure : silence.
        $this->assertSame('', MxChat_Plus_PromptCache_Models::caching_hint([
            'requests' => 2, 'cache_creation_tokens' => 500, 'cache_read_tokens' => 0,
        ]));
        // Provider à cache automatique (creation = 0) : silence.
        $this->assertSame('', MxChat_Plus_PromptCache_Models::caching_hint([
            'requests' => 50, 'cache_creation_tokens' => 0, 'cache_read_tokens' => 0,
        ]));
        $this->assertSame('', MxChat_Plus_PromptCache_Models::caching_hint(null));
    }
}
