<?php
/**
 * Tests de MxChat_Plus_PromptCache::inject_cache_control() sur des payloads
 * construits EXACTEMENT comme MxChat les envoie (3.2.21 → 3.2.25) :
 *
 *   chemin non streamé  [note, historique…, u_N, bloc de contexte]
 *                       (mxchat_generate_response_claude, note de
 *                       mxchat_fetch_conversation_history_for_ai, bloc de
 *                       contexte de mxchat_handle_chat_request) ;
 *   boucle AI Tools     même forme + `tools` au saut 1, puis
 *                       [assistant(tool_use), user(tool_result)] aux sauts
 *                       suivants, et plus de `tools` au dernier saut
 *                       (mxchat_fc_loop_anthropic).
 *
 * Régressions verrouillées ici, toutes invisibles sans un vrai appel Anthropic :
 *   - l'hôte marque son system en TTL 5 min et le module ajoutait ensuite des
 *     breakpoints 1 h — un ordre qu'Anthropic interdit ;
 *   - la paire glissante tombait sur le bloc de contexte que MxChat ajoute à
 *     chaque tour et ne renvoie jamais : prime d'écriture 1 h payée à chaque
 *     tour pour une entrée jamais relue ;
 *   - un system qui embarque le bloc base de connaissances (`{context}`) ou la
 *     valeur de `{current_datetime}` change à chaque question / minute ;
 *   - un breakpoint de boucle posé sur un saut dont le suivant n'offre plus
 *     `tools`, ou change de `tool_choice` (disable_parallel_tool_use quand il
 *     reste un appel), payait une écriture jamais relue ;
 *   - un system volatile supprimait aussi les breakpoints de la boucle, alors
 *     que MxChat renvoie le même system à chaque saut d'un tour ;
 *   - le ré-encodage transformait `{}` en `[]` (outil sans argument) ;
 *   - un header beta impossible à ajouter annulait toute la mise en cache.
 */

use PHPUnit\Framework\TestCase;

final class PromptCacheInjectionTest extends TestCase {

    private const URL = 'https://api.anthropic.com/v1/messages';

    /** Phrase du prompt par défaut de MxChat (class-mxchat-admin.php) : NE DOIT PAS passer pour un bloc KB. */
    private const HOST_DEFAULT_PROMPT_WORDING =
        "- ONLY answer questions using information explicitly provided in OFFICIAL KNOWLEDGE DATABASE CONTENT sections marked with ===== delimiters\n";

    protected function setUp(): void {
        unset(
            $GLOBALS['__test_filter_overrides']['mxchat_plus_promptcache_ephemeral_control'],
            $GLOBALS['__test_filter_overrides']['mxchat_plus_promptcache_volatile_system'],
            $GLOBALS['__test_filter_overrides']['mxchat_plus_promptcache_min_messages'],
            $GLOBALS['__test_filter_overrides']['mxchat_plus_promptcache_send_extended_ttl_header'],
            $GLOBALS['__test_filter_overrides']['mxchat_function_call_max_depth'],
            $GLOBALS['__test_filter_overrides']['mxchat_function_call_max_per_turn'],
            $GLOBALS['__test_options']['date_format'],
            $GLOBALS['__test_options']['time_format']
        );
        unset($GLOBALS['__test_transients'][MXCHAT_PLUS_PC_DEBUG_KEY]);
        MxChat_Plus_PromptCache::forget_observed_datetimes();
    }

    protected function tearDown(): void {
        $this->setUp();
    }

    // ──────────────────────────────────────────────────────────────────
    //  Fabriques — formes réelles de l'hôte
    // ──────────────────────────────────────────────────────────────────

    /** System tel que mxchat_anthropic_system_blocks() le produit. */
    private static function host_system(string $text, bool $host_marks = true): array {
        $block = ['type' => 'text', 'text' => $text];
        if ($host_marks) {
            $block['cache_control'] = ['type' => 'ephemeral'];
        }
        return [$block];
    }

    /** Note d'en-tête de mxchat_fetch_conversation_history_for_ai(), convertie en user par le handler Claude. */
    private static function host_note(): array {
        return ['role' => 'user', 'content' => 'Preserved code blocks are marked with [CODE BLOCK PRESERVED]. '
            . 'Maintain formatting and syntax highlighting when referencing code.'];
    }

    /** Bloc de contexte par tour de mxchat_handle_chat_request() : ligne de date, question, KB. */
    private static function context_block(string $question): string {
        return "Current date and time: Friday, October 16, 2026, 3:04 PM (UTC). Anything dated before today is in the past.\n\n"
            . "User asked: '{$question}'\n\n"
            . "===== OFFICIAL KNOWLEDGE DATABASE CONTENT =====\n" . str_repeat('Horaires : 9h-18h. ', 50) . "\n"
            . "===== END OF OFFICIAL KNOWLEDGE DATABASE CONTENT =====\n\n";
    }

    /**
     * Messages du tour $turn : [note, (u_t, a_t)…, u_N, contexte_N].
     *
     * @return list<array<string,mixed>>
     */
    private static function chat(int $turn, ?string $context = null): array {
        $messages = [self::host_note()];
        for ($t = 1; $t < $turn; $t++) {
            $messages[] = ['role' => 'user', 'content' => "question {$t}"];
            $messages[] = ['role' => 'assistant', 'content' => "réponse {$t}"];
        }
        $messages[] = ['role' => 'user', 'content' => "question {$turn}"];
        $messages[] = ['role' => 'user', 'content' => $context ?? self::context_block("question {$turn}")];
        return $messages;
    }

    /** Payload du chemin non streamé (mxchat_generate_response_claude). */
    private static function plain_payload(int $turn, ?array $system = null, string $model = 'claude-opus-5'): array {
        return ['model' => $model, 'max_tokens' => 1000,
                'messages' => self::chat($turn), 'system' => $system ?? self::host_system('Tu es un assistant.')];
    }

    /** Outils tels que MxChat_Tool_Registry::to_anthropic_tools() les décrit (petits : sous le seuil). */
    private static function host_tools(): array {
        return [[
            'name'         => 'mxchat_search_kb',
            'description'  => 'Search the knowledge base.',
            'input_schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
        ]];
    }

    /**
     * Messages du saut $hop de la boucle AI Tools au tour $turn.
     *
     * @return list<array<string,mixed>>
     */
    private static function fc_messages(int $turn, int $hop): array {
        $messages = self::chat($turn);
        for ($h = 1; $h < $hop; $h++) {
            $messages[] = ['role' => 'assistant', 'content' => [
                ['type' => 'text', 'text' => 'Je vérifie.'],
                ['type' => 'tool_use', 'id' => "toolu_{$h}", 'name' => 'mxchat_search_kb', 'input' => ['query' => "q{$h}"]],
            ]];
            // tool_result d'abord, puis la ligne « appels restants » (plan 150b72 de l'hôte).
            $messages[] = ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => "toolu_{$h}", 'content' => "résultat {$h}"],
                ['type' => 'text', 'text' => 'You can make 2 more tool calls this turn.'],
            ]];
        }
        return $messages;
    }

    /** Payload d'un saut de mxchat_fc_loop_anthropic ; $offer_tools faux = dernier saut. */
    private static function fc_payload(int $turn, int $hop, bool $offer_tools = true, ?array $tools = null): array {
        $payload = ['model' => 'claude-opus-5', 'max_tokens' => 1024,
                    'messages' => self::fc_messages($turn, $hop), 'system' => self::host_system('Tu es un assistant.')];
        if ($offer_tools) {
            $payload['tools']       = $tools ?? self::host_tools();
            $payload['tool_choice'] = ['type' => 'auto'];
        }
        return $payload;
    }

    /** Bloc KB tel que MxChat l'assemble puis l'injecte à la place de `{context}`. */
    private static function kb_block(): string {
        return "===== OFFICIAL KNOWLEDGE DATABASE CONTENT =====\nHoraires : 9h-18h.\n"
            . "===== END OF OFFICIAL KNOWLEDGE DATABASE CONTENT =====";
    }

    /** @param array<string,mixed> $payload */
    private static function args(array $payload, mixed $headers = null): array {
        return [
            'method'  => 'POST',
            'headers' => $headers ?? ['x-api-key' => 'k', 'content-type' => 'application/json'],
            'body'    => (string) json_encode($payload),
        ];
    }

    /** @return array{0:array<string,mixed>, 1:array<string,mixed>} [args sortants, payload décodé] */
    private static function inject(array $args): array {
        $out = MxChat_Plus_PromptCache::inject_cache_control($args, self::URL);
        $payload = json_decode($out['body'], true);
        return [$out, is_array($payload) ? $payload : []];
    }

    /** @return array<string,mixed> */
    private static function debug(): array {
        return MxChat_Plus_PromptCache_Stats::get_debug() ?? [];
    }

    /** cache_control du dernier bloc du message $i, ou null. */
    private static function mark(array $payload, int $i): ?array {
        $content = $payload['messages'][$i]['content'] ?? null;
        if (!is_array($content) || $content === []) {
            return null;
        }
        $last = $content[count($content) - 1];
        return is_array($last) && isset($last['cache_control']) ? $last['cache_control'] : null;
    }

    /** Index des messages portant un breakpoint, dans l'ordre. @return list<int> */
    private static function marked_messages(array $payload): array {
        $out = [];
        foreach ($payload['messages'] ?? [] as $i => $msg) {
            foreach (is_array($msg['content'] ?? null) ? $msg['content'] : [] as $block) {
                if (is_array($block) && !empty($block['cache_control'])) {
                    $out[] = $i;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * TTL (en s) de chaque breakpoint, dans l'ordre de rendu Anthropic.
     *
     * @return list<int>
     */
    private static function ttls_in_render_order(array $payload): array {
        $ttls = [];
        foreach (['tools', 'system'] as $key) {
            foreach (is_array($payload[$key] ?? null) ? $payload[$key] : [] as $block) {
                if (is_array($block) && !empty($block['cache_control'])) {
                    $ttls[] = MxChat_Plus_PromptCache_Models::ttl_seconds($block['cache_control']);
                }
            }
        }
        foreach ($payload['messages'] ?? [] as $msg) {
            foreach (is_array($msg['content'] ?? null) ? $msg['content'] : [] as $block) {
                if (is_array($block) && !empty($block['cache_control'])) {
                    $ttls[] = MxChat_Plus_PromptCache_Models::ttl_seconds($block['cache_control']);
                }
            }
        }
        return $ttls;
    }

    private static function beta_header(array $args): string {
        $headers = is_array($args['headers'] ?? null) ? array_change_key_case($args['headers'], CASE_LOWER) : [];
        return is_string($headers['anthropic-beta'] ?? null) ? $headers['anthropic-beta'] : '';
    }

    private function assertTtlOrderIsValid(array $payload, string $context = ''): void {
        $ttls = self::ttls_in_render_order($payload);
        for ($i = 1; $i < count($ttls); $i++) {
            $this->assertLessThanOrEqual(
                $ttls[$i - 1],
                $ttls[$i],
                "Un breakpoint 1 h suit un breakpoint 5 min {$context} : " . json_encode($ttls)
            );
        }
        $this->assertLessThanOrEqual(MXCHAT_PLUS_PC_MAX_BREAKPOINTS, count($ttls), "Plus de 4 breakpoints {$context}");
    }

    // ──────────────────────────────────────────────────────────────────
    //  A) Ordre des TTL avec le marqueur 5 min de l'hôte
    // ──────────────────────────────────────────────────────────────────

    public function test_host_system_marker_is_aligned_before_one_hour_history_breakpoints(): void {
        // Tour 2 : [note, u1, a1, u2, contexte2].
        [$out, $sent] = self::inject(self::args(self::plain_payload(2)));

        $this->assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $sent['system'][0]['cache_control']);
        $this->assertSame('1h', self::mark($sent, 1)['ttl'] ?? null, 'u1 : relecture');
        $this->assertSame('1h', self::mark($sent, 3)['ttl'] ?? null, 'u2 : écriture');
        $this->assertSame([3600, 3600, 3600], self::ttls_in_render_order($sent));
        $this->assertStringContainsString(MXCHAT_PLUS_PC_EXTENDED_TTL_HEADER, self::beta_header($out));

        $debug = self::debug();
        $this->assertSame(1, $debug['ttl_upgraded'] ?? null);
        $this->assertFalse($debug['volatile_system'] ?? null);
        $this->assertSame('', $debug['volatile_reason'] ?? null);
        $this->assertSame(MxChat_Plus_PromptCache_Models::SHAPE_PLAIN, $debug['shape'] ?? null);
    }

    public function test_host_system_marker_is_aligned_from_the_first_turn(): void {
        // Le prompt système, commun à tous les visiteurs, doit durer 1 h dès le
        // premier message — sinon il expire entre deux visites espacées.
        $payload = self::plain_payload(1);

        [$out, $sent] = self::inject(self::args($payload));

        $this->assertSame('1h', $sent['system'][0]['cache_control']['ttl']);
        $this->assertSame($payload['messages'], $sent['messages'], 'tour 1 : aucun breakpoint d\'historique');
        $this->assertStringContainsString(MXCHAT_PLUS_PC_EXTENDED_TTL_HEADER, self::beta_header($out));
    }

    /**
     * Balayage : quelle que soit la forme (tours 1 à 4 du chemin simple, sauts
     * 1 à 3 et dernier saut de la boucle AI Tools, outils volumineux ou non),
     * le marquage de l'hôte et la TTL du module, la requête respecte l'ordre
     * des TTL et le plafond de 4 breakpoints.
     */
    public function test_ttl_order_and_breakpoint_cap_hold_across_host_payload_shapes(): void {
        $big_tools = [['name' => 'lookup', 'description' => str_repeat('d', 3000), 'input_schema' => ['type' => 'object']]];
        $shapes = [];
        foreach ([1, 2, 3, 4] as $turn) {
            $shapes["simple t{$turn}"] = self::plain_payload($turn, self::host_system(str_repeat('Règle stable. ', 400)));
        }
        foreach ([1, 3] as $turn) {
            foreach ([1, 2, 3] as $hop) {
                $shapes["fc t{$turn} saut {$hop}"]          = self::fc_payload($turn, $hop);
                $shapes["fc t{$turn} saut {$hop} gros outils"] = self::fc_payload($turn, $hop, true, $big_tools);
            }
            $shapes["fc t{$turn} dernier saut"] = self::fc_payload($turn, 3, false);
            foreach (self::volatile_systems() as $name => [$system]) {
                foreach ([1, 2, 3] as $hop) {
                    $volatile = self::fc_payload($turn, $hop, true, $big_tools);
                    $volatile['system'] = $system;
                    $shapes["fc t{$turn} saut {$hop} {$name}"] = $volatile;
                }
            }
        }

        foreach ([true, false] as $five_minutes) {
            foreach ([true, false] as $host_marks) {
                foreach ($shapes as $name => $payload) {
                    if ($five_minutes) {
                        $GLOBALS['__test_filter_overrides']['mxchat_plus_promptcache_ephemeral_control'] = ['type' => 'ephemeral'];
                    } else {
                        unset($GLOBALS['__test_filter_overrides']['mxchat_plus_promptcache_ephemeral_control']);
                    }
                    if (!$host_marks) {
                        unset($payload['system'][0]['cache_control']);
                    }
                    [, $sent] = self::inject(self::args($payload));
                    $this->assertTtlOrderIsValid($sent, sprintf('(%s, 5min=%d, hôte=%d)', $name, $five_minutes, $host_marks));
                }
            }
        }
    }

    public function test_five_minute_mode_leaves_the_host_marker_and_sends_no_beta_header(): void {
        $GLOBALS['__test_filter_overrides']['mxchat_plus_promptcache_ephemeral_control'] = ['type' => 'ephemeral'];

        [$out, $sent] = self::inject(self::args(self::plain_payload(2)));

        $this->assertSame(['type' => 'ephemeral'], $sent['system'][0]['cache_control']);
        $this->assertSame(['type' => 'ephemeral'], self::mark($sent, 3));
        $this->assertSame('', self::beta_header($out));
    }

    public function test_enforce_ttl_order_lengthens_only_what_precedes_a_one_hour_marker(): void {
        $payload = [
            'system'   => [['type' => 'text', 'text' => 's', 'cache_control' => ['type' => 'ephemeral']]],
            'messages' => [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'a', 'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h']]]],
                ['role' => 'assistant', 'content' => 'b'],
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'c', 'cache_control' => ['type' => 'ephemeral']]]],
            ],
        ];

        $this->assertSame(1, MxChat_Plus_PromptCache_Models::enforce_ttl_order($payload));
        // 5 min avant 1 h → allongé ; 5 min APRÈS le 1 h → autorisé, laissé tel quel.
        $this->assertSame([3600, 3600, 300], self::ttls_in_render_order($payload));
        $this->assertSame(0, MxChat_Plus_PromptCache_Models::enforce_ttl_order($payload), 'idempotent');
    }

    public function test_align_prefix_ttl_respects_the_system_exclusion_and_the_five_minute_mode(): void {
        $payload = [
            'tools'  => [['name' => 't', 'cache_control' => ['type' => 'ephemeral']]],
            'system' => [['type' => 'text', 'text' => 's', 'cache_control' => ['type' => 'ephemeral']]],
        ];
        $one_hour = ['type' => 'ephemeral', 'ttl' => '1h'];

        $copy = $payload;
        $this->assertSame(0, MxChat_Plus_PromptCache_Models::align_prefix_ttl($copy, ['type' => 'ephemeral'], true));
        $this->assertSame($payload, $copy, 'mode 5 min : rien à allonger');

        $this->assertSame(1, MxChat_Plus_PromptCache_Models::align_prefix_ttl($copy, $one_hour, false));
        $this->assertSame('1h', $copy['tools'][0]['cache_control']['ttl']);
        $this->assertArrayNotHasKey('ttl', $copy['system'][0]['cache_control'], 'system exclu');

        $this->assertSame(1, MxChat_Plus_PromptCache_Models::align_prefix_ttl($copy, $one_hour, true));
        $this->assertSame('1h', $copy['system'][0]['cache_control']['ttl']);
    }

    public function test_payload_uses_extended_ttl(): void {
        $m = MxChat_Plus_PromptCache_Models::class;
        $this->assertFalse($m::payload_uses_extended_ttl(['system' => 'x', 'messages' => self::chat(2)]));
        $this->assertFalse($m::payload_uses_extended_ttl(['system' => self::host_system('x')]));
        $this->assertTrue($m::payload_uses_extended_ttl(['messages' => [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'a', 'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h']]]],
        ]]));
        $this->assertFalse($m::payload_uses_extended_ttl('garbage'));
    }

    // ──────────────────────────────────────────────────────────────────
    //  B) Chemin simple : le bloc de contexte n'est jamais marqué
    // ──────────────────────────────────────────────────────────────────

    public function test_plain_path_never_marks_the_per_turn_context_block(): void {
        $payload = self::plain_payload(3); // [note, u1, a1, u2, a2, u3, contexte3]
        [, $sent] = self::inject(self::args($payload));

        $this->assertSame($payload['messages'][6], $sent['messages'][6], 'contexte : facturé en entrée simple, jamais écrit');
        $this->assertSame($payload['messages'][0], $sent['messages'][0], 'note de l\'hôte : jamais un breakpoint');
        $this->assertSame([3, 5], self::marked_messages($sent), 'u2 (relecture) et u3 (écriture)');

        $debug = self::debug();
        $this->assertTrue($debug['breakpoints']['prev_user']);
        $this->assertTrue($debug['breakpoints']['last_user']);
        $this->assertFalse($debug['breakpoints']['context_block']);
    }

    /**
     * La propriété qui fait gagner de l'argent : ce que le tour N écrit est
     * exactement ce que le tour N+1 relit (même message brut du visiteur, même
     * position). Avec l'ancien marquage, l'écriture tombait sur le contexte_N,
     * que le tour N+1 ne contient plus.
     */
    public function test_write_point_of_one_turn_is_the_read_point_of_the_next(): void {
        for ($turn = 2; $turn <= 5; $turn++) {
            [, $now]  = self::inject(self::args(self::plain_payload($turn)));
            [, $next] = self::inject(self::args(self::plain_payload($turn + 1)));

            $written = self::marked_messages($now);
            $read    = self::marked_messages($next);
            $write_i = end($written);
            $read_i  = $read[0];

            $this->assertSame($write_i, $read_i, "tour {$turn} : même position");
            // Le préfixe que compare Anthropic est le CONTENU, pas les marqueurs
            // (une chaîne et un bloc texte unique sont équivalents).
            $content = static fn(array $messages): array => array_map(
                static fn(array $m): array => [$m['role'], MxChat_Plus_PromptCache_Models::content_text($m['content'])],
                $messages
            );
            $this->assertSame(
                $content(array_slice($now['messages'], 0, $write_i + 1)),
                $content(array_slice($next['messages'], 0, $read_i + 1)),
                "tour {$turn} : préfixe identique jusqu'au point de relecture"
            );
            $this->assertStringStartsWith('question', $now['messages'][$write_i]['content'][0]['text'], 'message brut du visiteur');
        }
    }

    public function test_min_messages_ignores_the_host_note_and_the_context_block(): void {
        // Tour 1 = [note, u1, contexte] : 3 messages bruts, mais un seul du visiteur.
        $first = self::plain_payload(1);
        [, $sent] = self::inject(self::args($first));
        $this->assertSame([], self::marked_messages($sent), 'chat d\'un seul message : aucune écriture d\'historique');

        // Tour 2 = (u1, a1, u2) : le seuil de 3 est atteint.
        [, $sent] = self::inject(self::args(self::plain_payload(2)));
        $this->assertSame([1, 3], self::marked_messages($sent));

        // Filtre à 1 : cache dès le premier tour, sur u1 seul.
        $GLOBALS['__test_filter_overrides']['mxchat_plus_promptcache_min_messages'] = 1;
        [, $sent] = self::inject(self::args($first));
        $this->assertSame([1], self::marked_messages($sent));
        $this->assertSame($first['messages'][2], $sent['messages'][2]);
    }

    public function test_context_block_rewritten_by_a_filter_is_still_recognised_by_structure(): void {
        // mxchat_prepare_context peut réécrire le bloc : plus de littéral, mais
        // toujours deux user consécutifs en fin de requête.
        $payload = self::plain_payload(2);
        $payload['messages'][4]['content'] = 'Contexte réécrit par un add-on.';

        $layout = MxChat_Plus_PromptCache_Models::analyze_host_messages($payload['messages'], false);
        $this->assertSame(4, $layout['context']);

        [, $sent] = self::inject(self::args($payload));
        $this->assertSame([1, 3], self::marked_messages($sent));
    }

    public function test_context_block_is_recognised_by_its_literal_when_the_raw_message_was_dropped(): void {
        // Le filtre wp_kses de l'hôte écarte un u_N porteur de HTML : le
        // contexte suit alors directement une réponse de l'assistant.
        $messages = self::chat(3);
        array_splice($messages, 5, 1); // retire u3 → [note, u1, a1, u2, a2, contexte3]
        $payload = ['model' => 'claude-opus-5', 'messages' => $messages, 'system' => self::host_system('Tu es un assistant.')];

        $this->assertSame(5, MxChat_Plus_PromptCache_Models::analyze_host_messages($messages, false)['context']);

        [, $sent] = self::inject(self::args($payload));
        $this->assertSame([1, 3], self::marked_messages($sent), 'u1, u2 — jamais le contexte');
    }

    public function test_generic_payload_keeps_the_historical_rolling_pair(): void {
        // Appel sans note ni bloc de contexte (autre plugin, appel utilitaire).
        $messages = [
            ['role' => 'user', 'content' => 'a'],
            ['role' => 'assistant', 'content' => 'b'],
            ['role' => 'user', 'content' => 'c'],
        ];
        $payload = ['model' => 'claude-opus-5', 'system' => self::host_system('s'), 'messages' => $messages];

        [, $sent] = self::inject(self::args($payload));

        $this->assertSame(MxChat_Plus_PromptCache_Models::SHAPE_GENERIC, self::debug()['shape']);
        $this->assertSame([0, 2], self::marked_messages($sent));
    }

    public function test_analyze_host_messages_recognises_every_host_shape(): void {
        $m = MxChat_Plus_PromptCache_Models::class;

        $plain = $m::analyze_host_messages(self::chat(2), false);
        $this->assertSame(['shape' => $m::SHAPE_PLAIN, 'note' => 0, 'context' => 4], $plain);

        $this->assertSame($m::SHAPE_FC_FIRST_HOP, $m::analyze_host_messages(self::fc_messages(2, 1), true)['shape']);

        $loop = $m::analyze_host_messages(self::fc_messages(2, 2), true);
        $this->assertSame(['shape' => $m::SHAPE_FC_LOOP, 'note' => 0, 'context' => 4], $loop);

        $this->assertSame($m::SHAPE_FC_FINAL_HOP, $m::analyze_host_messages(self::fc_messages(2, 3), false)['shape']);

        $generic = $m::analyze_host_messages([['role' => 'user', 'content' => 'seul']], false);
        $this->assertSame(['shape' => $m::SHAPE_GENERIC, 'note' => null, 'context' => null], $generic);

        foreach ([null, [], 'garbage', ['a' => ['role' => 'user']]] as $degenerate) {
            $this->assertSame($m::SHAPE_GENERIC, $m::analyze_host_messages($degenerate, false)['shape']);
        }
        // La note seule n'est pas un bloc de contexte.
        $this->assertNull($m::analyze_host_messages([self::host_note()], false)['context']);
    }

    // ──────────────────────────────────────────────────────────────────
    //  C) Boucle AI Tools
    // ──────────────────────────────────────────────────────────────────

    public function test_fc_first_hop_marks_the_raw_message_at_module_ttl_and_the_context_block_at_five_minutes(): void {
        // Tour 2, saut 1 : [note, u1, a1, u2, contexte2] + tools (petits : pas de breakpoint tools).
        [, $sent] = self::inject(self::args(self::fc_payload(2, 1)));

        $this->assertSame(['type' => 'ephemeral', 'ttl' => '1h'], self::mark($sent, 3), 'u2 : relu au tour suivant');
        $this->assertSame(['type' => 'ephemeral'], self::mark($sent, 4), 'contexte : relu au saut 2, 5 min');
        $this->assertSame(['type' => 'ephemeral', 'ttl' => '1h'], self::mark($sent, 1), 'u1 : relecture exacte');
        $this->assertSame([3600, 3600, 3600, 300], self::ttls_in_render_order($sent));

        $debug = self::debug();
        $this->assertSame(MxChat_Plus_PromptCache_Models::SHAPE_FC_FIRST_HOP, $debug['shape']);
        $this->assertTrue($debug['breakpoints']['context_block']);
        $this->assertTrue($debug['breakpoints']['last_user']);
        $this->assertTrue($debug['breakpoints']['prev_user']);
    }

    public function test_fc_first_hop_with_large_tools_gives_the_slots_to_the_raw_message_and_the_context_block(): void {
        $tools = [['name' => 'lookup', 'description' => str_repeat('d', 3000), 'input_schema' => ['type' => 'object']]];
        [, $sent] = self::inject(self::args(self::fc_payload(2, 1, true, $tools)));

        $this->assertSame('1h', $sent['tools'][0]['cache_control']['ttl'] ?? null);
        $this->assertSame([3, 4], self::marked_messages($sent), 'plafond : u2 et contexte avant u1');
        $this->assertSame([3600, 3600, 3600, 300], self::ttls_in_render_order($sent));
    }

    public function test_fc_first_hop_of_the_first_turn_marks_only_the_context_block(): void {
        // Le seuil MIN_MESSAGES ne vise que les breakpoints inter-tours.
        [, $sent] = self::inject(self::args(self::fc_payload(1, 1)));
        $this->assertSame([2], self::marked_messages($sent));
        $this->assertSame(['type' => 'ephemeral'], self::mark($sent, 2));
    }

    public function test_fc_second_hop_reads_the_context_block_and_writes_the_tool_result_at_five_minutes(): void {
        [, $hop1] = self::inject(self::args(self::fc_payload(2, 1)));
        [, $hop2] = self::inject(self::args(self::fc_payload(2, 2)));

        // [note, u1, a1, u2, contexte2, assistant(tool_use), user(tool_result)]
        $this->assertSame([4, 6], self::marked_messages($hop2));
        $this->assertSame(['type' => 'ephemeral'], self::mark($hop2, 4));
        $this->assertSame(['type' => 'ephemeral'], self::mark($hop2, 6), 'dernier bloc du tour tool_result');
        $this->assertSame(
            $hop1['messages'][4],
            $hop2['messages'][4],
            'le saut 2 relit exactement l\'entrée écrite au saut 1'
        );
        $this->assertSame(MxChat_Plus_PromptCache_Models::SHAPE_FC_LOOP, self::debug()['shape']);
        $this->assertTtlOrderIsValid($hop2);
    }

    public function test_fc_third_hop_rolls_the_pair_over_the_tool_results(): void {
        [, $sent] = self::inject(self::args(self::fc_payload(2, 3)));
        // [... contexte2(4), a(5), tool_result1(6), a(7), tool_result2(8)]
        $this->assertSame([6, 8], self::marked_messages($sent));
    }

    public function test_fc_final_hop_marks_no_message(): void {
        // Plus de `tools` : préfixe différent de tout premier saut, une écriture
        // ici ne serait jamais relue.
        $payload = self::fc_payload(2, 3, false);
        [, $sent] = self::inject(self::args($payload));

        $this->assertSame($payload['messages'], $sent['messages']);
        $this->assertSame(MxChat_Plus_PromptCache_Models::SHAPE_FC_FINAL_HOP, self::debug()['shape']);
    }

    // ──────────────────────────────────────────────────────────────────
    //  C bis) Budget et profondeur de la boucle (tool_choice, plus de tools)
    // ──────────────────────────────────────────────────────────────────

    /** Payload du saut $hop quand il reste $remaining appels, `tool_choice` comme l'hôte (3.2.23+). */
    private static function fc_payload_with_budget(int $turn, int $hop, int $remaining): array {
        $payload = self::fc_payload($turn, $hop);
        if ($remaining === 1) {
            $payload['tool_choice']['disable_parallel_tool_use'] = true;
        }
        return $payload;
    }

    public function test_fc_first_hop_with_a_budget_of_two_does_not_mark_the_context_block(): void {
        // Saut 1 en {auto} ; après un appel il en reste 1, donc le saut 2 porte
        // disable_parallel_tool_use : nouveau tool_choice, cache des messages perdu.
        $GLOBALS['__test_filter_overrides']['mxchat_function_call_max_per_turn'] = 2;
        [, $sent] = self::inject(self::args(self::fc_payload_with_budget(2, 1, 2)));

        $this->assertSame([1, 3], self::marked_messages($sent), 'u1 et u2 restent, le contexte (4) non');
        $this->assertSame('1h', self::mark($sent, 3)['ttl'] ?? null);
        $debug = self::debug();
        $this->assertFalse($debug['breakpoints']['context_block']);
        $this->assertSame(['step' => 0, 'calls_made' => 0, 'remaining' => 2, 'write' => false, 'read' => true], $debug['loop']);
    }

    public function test_fc_first_hop_with_a_budget_of_one_keeps_only_the_cross_turn_marks(): void {
        // Budget 1 : le saut 1 porte déjà disable_parallel_tool_use et le saut 2
        // n'offrira plus `tools`. Chaque tour envoie le même tool_choice au saut
        // 1 : u_N / u_{N-1} restent relus d'un tour à l'autre.
        $GLOBALS['__test_filter_overrides']['mxchat_function_call_max_per_turn'] = 1;
        [, $sent] = self::inject(self::args(self::fc_payload_with_budget(2, 1, 1)));

        $this->assertSame([1, 3], self::marked_messages($sent));
        $this->assertSame('1h', self::mark($sent, 1)['ttl'] ?? null);
        $this->assertFalse(self::debug()['breakpoints']['context_block']);
    }

    public function test_fc_first_hop_with_a_depth_of_one_does_not_mark_the_context_block(): void {
        // Profondeur 1 : le saut 2 est le dernier, sans `tools`.
        $GLOBALS['__test_filter_overrides']['mxchat_function_call_max_depth'] = 1;
        [, $sent] = self::inject(self::args(self::fc_payload(2, 1)));
        $this->assertSame([1, 3], self::marked_messages($sent));
    }

    public function test_fc_first_hop_with_three_calls_left_still_marks_the_context_block(): void {
        $GLOBALS['__test_filter_overrides']['mxchat_function_call_max_per_turn'] = 3;
        [, $sent] = self::inject(self::args(self::fc_payload(2, 1)));
        $this->assertSame([1, 3, 4], self::marked_messages($sent));
        $this->assertSame(['type' => 'ephemeral'], self::mark($sent, 4));
    }

    public function test_fc_hop_carrying_disable_parallel_tool_use_marks_no_message(): void {
        // Budget 2, un appel fait : il en reste 1. Le saut 1 envoyait {auto},
        // celui-ci {auto, disable_parallel_tool_use} : rien à relire. Le suivant
        // n'offrira plus `tools` : rien à écrire.
        $GLOBALS['__test_filter_overrides']['mxchat_function_call_max_per_turn'] = 2;
        $payload = self::fc_payload_with_budget(2, 2, 1);
        [, $sent] = self::inject(self::args($payload));

        $this->assertSame([], self::marked_messages($sent));
        $this->assertSame(
            ['step' => 1, 'calls_made' => 1, 'remaining' => 1, 'write' => false, 'read' => false],
            self::debug()['loop']
        );
    }

    public function test_fc_hop_with_two_calls_left_reads_but_does_not_write(): void {
        // Budget 3, un appel fait : ce saut et le précédent envoient {auto}
        // (lecture du contexte), mais le saut suivant aura un seul appel restant.
        $GLOBALS['__test_filter_overrides']['mxchat_function_call_max_per_turn'] = 3;
        [, $sent] = self::inject(self::args(self::fc_payload_with_budget(2, 2, 2)));

        // [note, u1, a1, u2, contexte2(4), assistant(tool_use), user(tool_result)(6)]
        $this->assertSame([4], self::marked_messages($sent));
        $debug = self::debug();
        $this->assertTrue($debug['breakpoints']['prev_user']);
        $this->assertFalse($debug['breakpoints']['last_user']);
    }

    public function test_fc_hop_before_the_depth_limit_reads_but_does_not_write(): void {
        // Profondeur 2 : le saut 2 (step 1) offre `tools`, le saut 3 non.
        $GLOBALS['__test_filter_overrides']['mxchat_function_call_max_depth'] = 2;
        [, $sent] = self::inject(self::args(self::fc_payload(2, 2)));
        $this->assertSame([4], self::marked_messages($sent));
    }

    public function test_tool_loop_of_another_plugin_keeps_the_full_pair(): void {
        // Aucun bloc de contexte de MxChat : budget et profondeur de l'hôte ne
        // le concernent pas, même avec disable_parallel_tool_use.
        $GLOBALS['__test_filter_overrides']['mxchat_function_call_max_per_turn'] = 1;
        $payload = ['model' => 'claude-opus-5', 'max_tokens' => 1024,
            'tools'       => self::host_tools(),
            'tool_choice' => ['type' => 'auto', 'disable_parallel_tool_use' => true],
            'messages'    => [
                ['role' => 'user', 'content' => 'Quel temps fait-il ?'],
                ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'meteo', 'input' => ['ville' => 'Lyon']]]],
                ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => 'Soleil']]],
            ]];
        [, $sent] = self::inject(self::args($payload));

        $this->assertSame(MxChat_Plus_PromptCache_Models::SHAPE_FC_LOOP, self::debug()['shape']);
        $this->assertNull(self::debug()['loop']);
        $this->assertSame([0, 2], self::marked_messages($sent));
    }

    public function test_loop_hop_cacheability_counts_hops_and_executed_calls(): void {
        $m        = MxChat_Plus_PromptCache_Models::class;
        $profile  = ['depth' => 5, 'budget' => 3, 'budget_gate' => true, 'single_call_choice' => true];
        $messages = self::fc_messages(2, 2);
        // Un appel refusé faute de budget (is_error, 3.2.22+) n'est pas un appel fait.
        $messages[6]['content'][] = ['type' => 'tool_result', 'tool_use_id' => 'x', 'content' => 'budget', 'is_error' => true];

        $state = $m::loop_hop_cacheability($messages, ['type' => 'auto'], $profile);
        $this->assertSame(['step' => 1, 'calls_made' => 1, 'remaining' => 2, 'write' => false, 'read' => true], $state);

        $this->assertTrue($m::loop_hop_cacheability(self::fc_messages(2, 1), ['type' => 'auto'], $profile)['write'], 'saut 1, 3 appels restants');
        $this->assertSame(0, $m::loop_hop_cacheability(self::fc_messages(2, 1), null, $profile)['step']);
    }

    public function test_loop_hop_cacheability_follows_the_host_version(): void {
        $m    = MxChat_Plus_PromptCache_Models::class;
        $hop1 = self::fc_messages(2, 1);
        $dpu  = ['type' => 'auto', 'disable_parallel_tool_use' => true];

        // 3.2.22 : `tools` retirés au budget épuisé, mais jamais de
        // disable_parallel_tool_use : 2 appels restants suffisent.
        $v3222 = ['depth' => 5, 'budget' => 2, 'budget_gate' => true, 'single_call_choice' => false];
        $this->assertTrue($m::loop_hop_cacheability($hop1, ['type' => 'auto'], $v3222)['write']);
        $this->assertFalse($m::loop_hop_cacheability($hop1, ['type' => 'auto'], ['budget' => 1] + $v3222)['write']);

        // 3.2.21 : seule la profondeur retire `tools` ; un disable_parallel_tool_use
        // vient d'un tiers et ne dit rien du budget.
        $v3221 = ['depth' => 5, 'budget' => 1, 'budget_gate' => false, 'single_call_choice' => false];
        $this->assertSame(['write' => true, 'read' => true], array_intersect_key($m::loop_hop_cacheability($hop1, $dpu, $v3221), ['write' => 0, 'read' => 0]));
        $this->assertFalse($m::loop_hop_cacheability($hop1, null, ['depth' => 1] + $v3221)['write']);
    }

    public function test_loop_hop_cacheability_without_the_host_limits(): void {
        // Statiques de l'hôte absentes : seul disable_parallel_tool_use renseigne.
        $m       = MxChat_Plus_PromptCache_Models::class;
        $profile = ['depth' => null, 'budget' => null, 'budget_gate' => true, 'single_call_choice' => true];
        $hop2    = self::fc_messages(2, 2);

        $auto = $m::loop_hop_cacheability($hop2, ['type' => 'auto'], $profile);
        $this->assertSame([null, true, true], [$auto['remaining'], $auto['write'], $auto['read']]);

        $dpu = $m::loop_hop_cacheability($hop2, ['type' => 'auto', 'disable_parallel_tool_use' => true], $profile);
        $this->assertSame([false, false], [$dpu['write'], $dpu['read']]);

        // Budget connu, plusieurs appels restants : disable_parallel_tool_use a
        // été imposé par un tiers ; le saut précédent le portait sans doute aussi.
        $forced = $m::loop_hop_cacheability($hop2, ['type' => 'auto', 'disable_parallel_tool_use' => true], ['budget' => 8] + $profile);
        $this->assertSame([true, true], [$forced['write'], $forced['read']]);
    }

    public function test_history_breakpoint_plan_on_degenerate_layouts(): void {
        $m = MxChat_Plus_PromptCache_Models::class;
        // Contexte sans aucun message du visiteur avant lui : rien à marquer.
        $messages = [self::host_note(), ['role' => 'user', 'content' => self::context_block('x')]];
        $layout   = $m::analyze_host_messages($messages, false);
        $this->assertSame(1, $layout['context']);
        $this->assertSame([], $m::history_breakpoint_plan($messages, $layout, 1));

        $this->assertSame([], $m::history_breakpoint_plan(['a' => 1], ['shape' => $m::SHAPE_PLAIN, 'note' => null, 'context' => null], 1));
    }

    // ──────────────────────────────────────────────────────────────────
    //  D) System qui change à chaque requête
    // ──────────────────────────────────────────────────────────────────

    public function test_system_carrying_the_kb_block_is_left_uncached(): void {
        // {context} : MxChat injecte le KB dans le system et NE le marque PAS.
        $system  = "Tu es un assistant.\n" . self::kb_block() . "\nRéponds en français.";
        $args    = self::args(self::plain_payload(3, self::host_system($system, false)));

        $out = MxChat_Plus_PromptCache::inject_cache_control($args, self::URL);

        $this->assertSame($args, $out, 'ni system ni historique : la requête part telle quelle');
        $debug = self::debug();
        $this->assertTrue($debug['volatile_system'] ?? null);
        $this->assertSame(MxChat_Plus_PromptCache_Models::VOLATILE_KB_BLOCK, $debug['volatile_reason']);
        $this->assertSame(
            ['tools' => false, 'system' => false, 'prev_user' => false, 'last_user' => false, 'context_block' => false],
            $debug['breakpoints']
        );
    }

    public function test_no_relevant_content_header_also_marks_the_system_volatile(): void {
        $system = "Tu es un assistant.\n===== NO RELEVANT CONTENT FOUND IN KNOWLEDGE DATABASE =====\n";
        $this->assertTrue(MxChat_Plus_PromptCache_Models::system_has_per_query_content($system));
        $this->assertTrue(MxChat_Plus_PromptCache_Models::system_has_per_query_content(self::host_system($system, false)));
    }

    public function test_default_mxchat_prompt_wording_is_not_mistaken_for_the_kb_block(): void {
        // Le prompt par défaut de MxChat CITE la phrase sans les délimiteurs
        // d'en-tête : une détection naïve couperait le cache de tout le monde.
        $system = "You are a helpful assistant.\n" . self::HOST_DEFAULT_PROMPT_WORDING;
        $this->assertFalse(MxChat_Plus_PromptCache_Models::system_has_per_query_content($system));

        [, $sent] = self::inject(self::args(self::plain_payload(2, self::host_system($system))));
        $this->assertSame('1h', $sent['system'][0]['cache_control']['ttl']);
        $this->assertNotNull(self::mark($sent, 3));
    }

    public function test_system_has_per_query_content_on_degenerate_input(): void {
        $m = MxChat_Plus_PromptCache_Models::class;
        $this->assertFalse($m::system_has_per_query_content(null));
        $this->assertFalse($m::system_has_per_query_content(''));
        $this->assertFalse($m::system_has_per_query_content([['type' => 'text'], 'garbage', 42]));
    }

    public function test_volatile_system_still_caches_the_tools(): void {
        $tools   = [['name' => 'lookup', 'description' => str_repeat('d', 3000), 'input_schema' => ['type' => 'object']]];
        $payload = self::fc_payload(2, 1, true, $tools);
        $payload['system'] = self::host_system(self::kb_block(), false);

        [, $sent] = self::inject(self::args($payload));

        $this->assertNotEmpty($sent['tools'][0]['cache_control'] ?? null, 'les tools précèdent le system : toujours stables');
        $this->assertArrayNotHasKey('cache_control', $sent['system'][0]);
        $this->assertSame([4], self::marked_messages($sent), 'seul le bloc de contexte, relu au saut 2');
        $this->assertTtlOrderIsValid($sent);
    }

    /**
     * {context} et {current_datetime} : MxChat calcule le system une fois par
     * tour (mxchat_fc_attempt) et le renvoie tel quel à chaque saut. Les
     * breakpoints inter-tours tombent, ceux de la boucle restent.
     *
     * @return array<string, array{0:array<int,array<string,mixed>>, 1:string}>
     */
    public static function volatile_systems(): array {
        $value = MxChat_Plus_PromptCache_Models::host_datetime_placeholder(time());
        return [
            'kb block (host leaves it unmarked)' => [
                self::host_system("Tu es un assistant.\n" . self::kb_block(), false),
                MxChat_Plus_PromptCache_Models::VOLATILE_KB_BLOCK,
            ],
            'current_datetime (host 5 min marker)' => [
                self::host_system("Tu es un assistant. Nous sommes le {$value}."),
                MxChat_Plus_PromptCache_Models::VOLATILE_CURRENT_DATETIME,
            ],
        ];
    }

    /**
     * @dataProvider volatile_systems
     * @param array<int,array<string,mixed>> $system
     */
    public function test_volatile_system_keeps_the_context_block_mark_on_the_first_hop(array $system, string $reason): void {
        $payload = self::fc_payload(3, 1);
        $payload['system'] = $system;

        [, $sent] = self::inject(self::args($payload));

        // [note, u1, a1, u2, a2, u3, contexte3]
        $this->assertSame([6], self::marked_messages($sent), 'ni u3 ni u2 : leur préfixe change au tour suivant');
        $this->assertSame(['type' => 'ephemeral'], self::mark($sent, 6), 'TTL de boucle, sans `ttl`');
        $this->assertSame($system, $sent['system'], 'marqueur de l\'hôte inchangé (pas allongé à 1 h)');
        $this->assertTtlOrderIsValid($sent);

        $debug = self::debug();
        $this->assertSame($reason, $debug['volatile_reason']);
        $this->assertTrue($debug['breakpoints']['context_block']);
        $this->assertFalse($debug['breakpoints']['last_user']);
        $this->assertFalse($debug['breakpoints']['prev_user']);
        $this->assertSame(0, $debug['ttl_upgraded']);
    }

    /**
     * @dataProvider volatile_systems
     * @param array<int,array<string,mixed>> $system
     */
    public function test_volatile_system_keeps_the_in_loop_pair_on_later_hops(array $system, string $reason): void {
        $payload = self::fc_payload(2, 2);
        $payload['system'] = $system;

        [, $sent] = self::inject(self::args($payload));

        // [note, u1, a1, u2, contexte2, assistant(tool_use), user(tool_result)]
        $this->assertSame([4, 6], self::marked_messages($sent));
        $this->assertSame(['type' => 'ephemeral'], self::mark($sent, 4), 'lecture de l\'écriture du saut 1');
        $this->assertSame(['type' => 'ephemeral'], self::mark($sent, 6), 'écriture, relue au saut 3');
        $this->assertSame($system, $sent['system']);
        $this->assertTtlOrderIsValid($sent);
        $this->assertSame($reason, self::debug()['volatile_reason']);
    }

    /**
     * @dataProvider volatile_systems
     * @param array<int,array<string,mixed>> $system
     */
    public function test_volatile_system_still_marks_nothing_on_the_final_hop(array $system, string $reason): void {
        $payload = self::fc_payload(2, 3, false);
        $payload['system'] = $system;
        $args = self::args($payload);

        $this->assertSame($args, MxChat_Plus_PromptCache::inject_cache_control($args, self::URL));
        $this->assertSame($reason, self::debug()['volatile_reason']);
    }

    public function test_volatile_system_marks_no_history_on_the_plain_path(): void {
        // Pas de boucle : aucun breakpoint de boucle à garder.
        foreach (self::volatile_systems() as $name => [$system]) {
            $args = self::args(self::plain_payload(3, $system));
            $this->assertSame($args, MxChat_Plus_PromptCache::inject_cache_control($args, self::URL), $name);
        }
    }

    public function test_volatile_filter_overrides_the_detection_both_ways(): void {
        // Forcé à true : un system stable n'est plus marqué, l'historique non plus.
        $GLOBALS['__test_filter_overrides']['mxchat_plus_promptcache_volatile_system'] = true;
        $args = self::args(self::plain_payload(3, [['type' => 'text', 'text' => str_repeat('Règle stable. ', 400)]]));
        $this->assertSame($args, MxChat_Plus_PromptCache::inject_cache_control($args, self::URL));
        $this->assertSame(MxChat_Plus_PromptCache_Models::VOLATILE_FILTER, self::debug()['volatile_reason']);

        // Forcé à false : un intégrateur qui sait son KB stable récupère le cache.
        $GLOBALS['__test_filter_overrides']['mxchat_plus_promptcache_volatile_system'] = false;
        $kb = self::plain_payload(3, self::host_system(self::kb_block() . str_repeat(' x', 2000), false));
        [, $sent] = self::inject(self::args($kb));
        $this->assertNotEmpty($sent['system'][0]['cache_control'] ?? null);
        $this->assertNotNull(self::mark($sent, 5));
        $this->assertSame(MxChat_Plus_PromptCache_Models::VOLATILE_FILTER, self::debug()['volatile_reason']);
    }

    public function test_current_datetime_placeholder_value_makes_the_system_volatile(): void {
        // {current_datetime} (3.2.22) : le system change chaque minute. Le
        // marqueur 5 min de l'hôte couvre déjà toute sa vie utile.
        $value = MxChat_Plus_PromptCache_Models::host_datetime_placeholder(time());
        $this->assertNotSame('', $value);
        $args = self::args(self::plain_payload(3, self::host_system("Tu es un assistant. Nous sommes le {$value}.")));

        $out = MxChat_Plus_PromptCache::inject_cache_control($args, self::URL);

        $this->assertSame($args, $out, 'marqueur de l\'hôte laissé à 5 min, aucun historique, pas de header');
        $debug = self::debug();
        $this->assertTrue($debug['volatile_system']);
        $this->assertSame(MxChat_Plus_PromptCache_Models::VOLATILE_CURRENT_DATETIME, $debug['volatile_reason']);
        $this->assertFalse($debug['breakpoints']['prev_user']);
        $this->assertFalse($debug['breakpoints']['last_user']);
        $this->assertSame(0, $debug['ttl_upgraded']);
    }

    public function test_current_datetime_fallback_covers_two_minutes_and_no_more(): void {
        $m = MxChat_Plus_PromptCache_Models::class;
        // 90 s plus tôt : couvert par la valeur −60 s ou −120 s.
        $recent = $m::host_datetime_placeholder(time() - 90);
        $this->assertContains($recent, MxChat_Plus_PromptCache::current_datetime_candidates());
        $this->assertSame($m::VOLATILE_CURRENT_DATETIME, $m::system_volatility_reason("Il est {$recent}.", MxChat_Plus_PromptCache::current_datetime_candidates()));

        // Dix minutes plus tôt, sans observation : un texte figé, pas un placeholder.
        $old = $m::host_datetime_placeholder(time() - 600);
        $this->assertSame('', $m::system_volatility_reason("Écrit le {$old}.", MxChat_Plus_PromptCache::current_datetime_candidates()));
    }

    public function test_current_date_alone_stays_cacheable(): void {
        // {current_date} change une fois par jour : TTL 1 h et historique restent rentables.
        $date = wp_date('F j, Y', time(), wp_timezone());
        [, $sent] = self::inject(self::args(self::plain_payload(2, self::host_system("Nous sommes le {$date}."))));

        $this->assertSame('1h', $sent['system'][0]['cache_control']['ttl']);
        $this->assertFalse(self::debug()['volatile_system']);
        $this->assertNotNull(self::mark($sent, 3));
    }

    public function test_host_datetime_placeholder_follows_the_site_formats_and_their_fallbacks(): void {
        $m  = MxChat_Plus_PromptCache_Models::class;
        $ts = 1792162800; // 2026-10-16 15:00:00 UTC
        $this->assertSame('October 16, 2026 3:00 pm UTC', $m::host_datetime_placeholder($ts), 'replis F j, Y / g:i a');

        $GLOBALS['__test_options']['date_format'] = 'Y-m-d';
        $GLOBALS['__test_options']['time_format'] = 'H:i';
        $this->assertSame('2026-10-16 15:00 UTC', $m::host_datetime_placeholder($ts));

        $GLOBALS['__test_options']['time_format'] = '   '; // chaîne vide après trim → repli
        $this->assertSame('2026-10-16 3:00 pm UTC', $m::host_datetime_placeholder($ts));
    }

    public function test_observer_passes_the_instructions_through_and_remembers_the_value(): void {
        $GLOBALS['__test_options']['date_format'] = 'Y-m-d';
        $GLOBALS['__test_options']['time_format'] = 'H:i';
        $value        = MxChat_Plus_PromptCache_Models::host_datetime_placeholder(time());
        $instructions = "Tu es un assistant. Il est {$value}.";

        $this->assertSame($instructions, MxChat_Plus_PromptCache::observe_system_instructions($instructions));
        $this->assertSame(42, MxChat_Plus_PromptCache::observe_system_instructions(42), 'pass-through strict');

        // Les formats du site changent ensuite : le repli ne reconnaîtrait plus
        // la valeur, l'observation, si. C'est le cas de la boucle AI Tools, dont
        // les sauts tardifs suivent l'exécution des outils.
        $GLOBALS['__test_options']['time_format'] = 'g:i:s A';
        $this->assertContains($value, MxChat_Plus_PromptCache::current_datetime_candidates());

        $args = self::args(self::plain_payload(3, self::host_system($instructions)));
        $this->assertSame($args, MxChat_Plus_PromptCache::inject_cache_control($args, self::URL));
        $this->assertSame(MxChat_Plus_PromptCache_Models::VOLATILE_CURRENT_DATETIME, self::debug()['volatile_reason']);

        MxChat_Plus_PromptCache::forget_observed_datetimes();
        $this->assertNotContains($value, MxChat_Plus_PromptCache::current_datetime_candidates());
    }

    public function test_observer_ignores_instructions_without_the_current_value(): void {
        MxChat_Plus_PromptCache::observe_system_instructions('Tu es un assistant.');
        $candidates = MxChat_Plus_PromptCache::current_datetime_candidates();
        $this->assertLessThanOrEqual(3, count($candidates), 'seules les valeurs de repli');
    }

    // ──────────────────────────────────────────────────────────────────
    //  E) Ancien header beta de la TTL 1 h
    // ──────────────────────────────────────────────────────────────────

    public function test_unexpected_header_type_no_longer_cancels_the_body_mutation(): void {
        // La TTL 1 h n'exige plus le header : un type de headers inattendu (posé
        // par un filtre tiers) laisse les headers intacts, sans renoncer au cache.
        $out = MxChat_Plus_PromptCache::inject_cache_control(self::args(self::plain_payload(2), 42), self::URL);

        $this->assertSame(42, $out['headers']);
        $sent = json_decode($out['body'], true);
        $this->assertSame('1h', $sent['system'][0]['cache_control']['ttl']);
        $this->assertNotNull(self::mark($sent, 3));
    }

    public function test_raw_string_headers_are_kept_and_extended(): void {
        $raw = "x-api-key: k\r\nanthropic-version: 2023-06-01";
        [$out] = self::inject(self::args(self::plain_payload(2), $raw));

        $this->assertSame('k', $out['headers']['x-api-key']);
        $this->assertSame(MXCHAT_PLUS_PC_EXTENDED_TTL_HEADER, $out['headers']['anthropic-beta']);
    }

    public function test_existing_beta_header_is_appended_to_not_duplicated(): void {
        [$out] = self::inject(self::args(self::plain_payload(2), ['x-api-key' => 'k', 'Anthropic-Beta' => 'other-beta']));

        $this->assertSame('other-beta,' . MXCHAT_PLUS_PC_EXTENDED_TTL_HEADER, $out['headers']['Anthropic-Beta']);
        $this->assertArrayNotHasKey('anthropic-beta', $out['headers']);
    }

    public function test_extended_ttl_header_can_be_switched_off(): void {
        $GLOBALS['__test_filter_overrides']['mxchat_plus_promptcache_send_extended_ttl_header'] = false;
        [$out, $sent] = self::inject(self::args(self::plain_payload(2)));

        $this->assertSame('', self::beta_header($out));
        $this->assertSame('1h', $sent['system'][0]['cache_control']['ttl'], 'la TTL 1 h part sans header');
    }

    // ──────────────────────────────────────────────────────────────────
    //  F) Ré-encodage fidèle des objets JSON
    // ──────────────────────────────────────────────────────────────────

    public function test_empty_input_schema_properties_survive_the_round_trip(): void {
        // Outil AI Tools sans argument (fc_parameters d'un add-on).
        $tools = [[
            'name' => 'mxchat_ping', 'description' => str_repeat('Ping the shop. ', 200),
            'input_schema' => ['type' => 'object', 'properties' => new stdClass()],
        ]];
        $args = self::args(self::fc_payload(2, 1, true, $tools));
        $this->assertStringContainsString('"properties":{}', $args['body']);

        $out = MxChat_Plus_PromptCache::inject_cache_control($args, self::URL);

        $this->assertNotSame($args['body'], $out['body'], 'la requête a bien été réécrite');
        $this->assertStringContainsString('"properties":{}', $out['body']);
        $this->assertStringNotContainsString('"properties":[]', $out['body']);
        $this->assertGreaterThanOrEqual(1, self::debug()['json_objects_restored']);
    }

    public function test_tool_use_empty_input_survives_the_round_trip(): void {
        $payload = self::fc_payload(2, 2);
        $payload['messages'][5]['content'][1]['input'] = new stdClass();
        $args = self::args($payload);

        $out = MxChat_Plus_PromptCache::inject_cache_control($args, self::URL);

        $this->assertStringContainsString('"input":{}', $out['body']);
        $this->assertStringNotContainsString('"input":[]', $out['body']);
        $decoded = json_decode($out['body'], false);
        $this->assertInstanceOf(stdClass::class, $decoded->messages[5]->content[1]->input);
    }

    public function test_host_corrupted_empty_tool_use_input_is_repaired(): void {
        // mxchat_fc_post() décode la réponse en tableaux : `"input":{}` repart
        // en `"input":[]` au saut suivant, que l'API attend en objet.
        $payload = self::fc_payload(2, 2);
        $payload['messages'][5]['content'][1]['input'] = [];
        $args = self::args($payload);
        $this->assertStringContainsString('"input":[]', $args['body']);

        $out = MxChat_Plus_PromptCache::inject_cache_control($args, self::URL);

        $this->assertStringContainsString('"input":{}', $out['body']);
        $this->assertSame(1, self::debug()['tool_inputs_repaired']);
    }

    public function test_object_with_list_like_keys_survives_the_round_trip(): void {
        $payload = self::fc_payload(2, 2);
        $payload['messages'][5]['content'][1]['input'] = (object) ['0' => 'a', '1' => 'b'];
        $out = MxChat_Plus_PromptCache::inject_cache_control(self::args($payload), self::URL);

        $this->assertStringContainsString('"input":{"0":"a","1":"b"}', $out['body']);
    }

    public function test_json_object_paths_and_restore(): void {
        $m    = MxChat_Plus_PromptCache_Models::class;
        $json = '{"a":{},"b":[{"c":{"0":"x"}},{}],"d":{"e":1},"f":[]}';
        $paths = $m::json_object_paths(json_decode($json, false));
        $this->assertEqualsCanonicalizing([['a'], ['b', 0, 'c'], ['b', 1]], $paths);

        $payload = json_decode($json, true);
        // Une clé ajoutée par le module sur un objet vide ne doit pas être écrasée.
        $payload['b'][1]['cache_control'] = ['type' => 'ephemeral'];
        $this->assertSame(2, $m::restore_json_objects($payload, $paths));
        $this->assertSame(
            '{"a":{},"b":[{"c":{"0":"x"}},{"cache_control":{"type":"ephemeral"}}],"d":{"e":1},"f":[]}',
            json_encode($payload)
        );

        // Chemin disparu : ignoré sans erreur.
        $other = ['z' => 1];
        $this->assertSame(0, $m::restore_json_objects($other, [['a'], ['z', 'y']]));
    }

    // ──────────────────────────────────────────────────────────────────
    //  G) Seuils de la génération Claude 5, de bout en bout
    // ──────────────────────────────────────────────────────────────────

    public function test_opus_5_system_above_512_tokens_gets_a_breakpoint(): void {
        // ~3 000 caractères : au-dessus des 512 tokens d'Opus 5, très en dessous
        // des 16 400 caractères qu'il recevait quand la table ne le connaissait pas.
        $payload = ['model' => 'claude-opus-5', 'system' => str_repeat('Règle stable. ', 215),
                    'messages' => self::chat(1)];

        [, $sent] = self::inject(self::args($payload));

        $this->assertIsArray($sent['system']);
        $this->assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $sent['system'][0]['cache_control']);
    }

    public function test_short_system_still_gets_no_breakpoint(): void {
        $payload = ['model' => 'claude-opus-5', 'system' => 'Court.', 'messages' => self::chat(1)];
        $args    = self::args($payload);
        $this->assertSame($args, MxChat_Plus_PromptCache::inject_cache_control($args, self::URL));
    }

    // ──────────────────────────────────────────────────────────────────
    //  H) Sortie de `wp mxchat-plus promptcache debug`
    // ──────────────────────────────────────────────────────────────────

    public function test_cli_debug_prints_the_shape_the_volatile_reason_and_the_ttl_upgrades(): void {
        require_once MXCHAT_PLUS_DIR . 'includes/promptcache/class-promptcache-cli.php';
        $value = MxChat_Plus_PromptCache_Models::host_datetime_placeholder(time());
        MxChat_Plus_PromptCache::inject_cache_control(
            self::args(self::plain_payload(2, self::host_system("Il est {$value}."))),
            self::URL
        );

        WP_CLI::reset();
        (new MxChat_Plus_PromptCache_CLI())->debug();
        $log = implode("\n", WP_CLI::$log_buf);

        $this->assertStringContainsString('Payload shape      : plain', $log);
        $this->assertStringContainsString('Volatile system    : yes ({current_datetime} placeholder, changes every minute)', $log);
        $this->assertStringContainsString('TTLs lengthened    : 0', $log);
        $this->assertStringContainsString('  - context_block  : no', $log);

        WP_CLI::reset();
        MxChat_Plus_PromptCache::inject_cache_control(self::args(self::plain_payload(2)), self::URL);
        (new MxChat_Plus_PromptCache_CLI())->debug();
        $log = implode("\n", WP_CLI::$log_buf);
        $this->assertStringContainsString('Volatile system    : no', $log);
        $this->assertStringContainsString('TTLs lengthened    : 1', $log);
        $this->assertStringContainsString('  - last_user      : yes', $log);
        WP_CLI::reset();
    }

    // ──────────────────────────────────────────────────────────────────
    //  Constante d'opt-out publiée en 1.0.0 sous un autre nom
    // ──────────────────────────────────────────────────────────────────

    /**
     * Processus séparé : une constante définie ici le resterait pour toute la
     * suite et ferait basculer les autres tests en 5 min.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_documented_opt_out_constant_name_is_honoured(): void {
        define('MXCHAT_PLUS_PROMPTCACHE_EXTENDED_TTL', false);
        $this->assertSame(['type' => 'ephemeral'], MxChat_Plus_PromptCache_Models::ephemeral_control());
        $this->assertFalse(MxChat_Plus_PromptCache_Models::using_extended_ttl());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_canonical_opt_out_constant_name_is_honoured(): void {
        define('MXCHAT_PLUS_PC_EXTENDED_TTL', false);
        $this->assertSame(['type' => 'ephemeral'], MxChat_Plus_PromptCache_Models::ephemeral_control());
    }
}
