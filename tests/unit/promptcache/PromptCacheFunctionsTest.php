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
     * Deux pièges d'ordre déjà rencontrés (régression corrigée en 0.7.0) :
     *   - `mythos-preview` doit être testé AVANT `fable`/`mythos` ;
     *   - `haiku-4` doit être testé AVANT `haiku`.
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
            'fable-5'              => ['claude-fable-5',              2050],
            'mythos-5'             => ['claude-mythos-5',             2050],
            'mythos-preview'       => ['claude-mythos-preview',       8200],
            'opus-4.8'             => ['claude-opus-4-8',             4000],
            'opus-4.7'             => ['claude-opus-4-7',             8200],
            'opus-4.6'             => ['claude-opus-4-6',            16400],
            'sonnet-4.6'           => ['claude-sonnet-4-6',           4000],
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
        ];
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
        $this->assertNotSame('', MxChat_Plus_PromptCache_Models::caching_hint([
            'requests' => 10, 'cache_creation_tokens' => 500, 'cache_read_tokens' => 10,
        ]));
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
