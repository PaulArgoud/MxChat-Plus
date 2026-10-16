<?php
/**
 * Prompt Cache module — connaissances « modèle » et helpers de mesure.
 *
 * Tout ce qui décide *si* un bloc mérite un breakpoint de cache :
 *   - la table des seuils minimum par modèle (mouvante côté Anthropic) ;
 *   - la mesure en caractères (mb-safe) du system / des tools ;
 *   - la reconnaissance des formes de payload de MxChat (bloc de contexte par
 *     tour, sauts de la boucle AI Tools) et du system volatile ;
 *   - la fabrication de la structure cache_control (TTL 1 h vs 5 min) ;
 *   - le ré-encodage JSON qui préserve les objets vides.
 *
 * Classe 100 % statique et sans état : chaque méthode est pure (hors filtres WP),
 * ce qui la rend testable sans WordPress.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_PromptCache_Models {

    // Formes de `messages` reconnues par analyze_host_messages().
    /** Chemin non streamé de MxChat : [note, historique…, u_N, bloc de contexte], sans tools. */
    const SHAPE_PLAIN = 'plain';
    /** Premier saut de la boucle AI Tools : même forme que SHAPE_PLAIN, avec `tools`. */
    const SHAPE_FC_FIRST_HOP = 'fc_first_hop';
    /** Saut suivant de la boucle : tool_use / tool_result présents, `tools` encore offerts. */
    const SHAPE_FC_LOOP = 'fc_loop';
    /** Dernier saut de la boucle : tool_use / tool_result présents, plus de `tools`. */
    const SHAPE_FC_FINAL_HOP = 'fc_final_hop';
    /** Tout le reste (appels utilitaires, autres plugins, forme non reconnue). */
    const SHAPE_GENERIC = 'generic';

    // Raisons d'un system volatile (clé `volatile_reason` du debug).
    const VOLATILE_KB_BLOCK         = 'kb_block';
    const VOLATILE_CURRENT_DATETIME = 'current_datetime';
    const VOLATILE_FILTER           = 'filter';

    /**
     * Seuil minimum en caractères pour qu'Anthropic mette réellement en cache le préfixe.
     * Le minimum dépend du modèle ET de sa version (voir docs Anthropic « prompt caching »).
     *
     * ⚠ L'ORDRE des clauses est PORTEUR DE COMPORTEMENT et verrouillé par
     * PromptCacheFunctionsTest::test_min_chars_table_is_locked() :
     *   - `mythos-preview` doit être testé AVANT `fable`/`mythos` (préfixe commun) ;
     *   - `haiku-5` et `haiku-4` doivent être testés AVANT `haiku` (sinon
     *     Haiku 5.5 et Haiku 4.5 tomberaient sur les 2048 de Haiku 3.x) ;
     *   - `sonnet-5-5` doit être testé AVANT `sonnet` (Sonnet 5.5 = 512, mais
     *     Sonnet 5 = 1024 : les deux partagent le préfixe `sonnet-5`).
     * Une régression sur cet ordre est déjà survenue (corrigée en 0.7.0). Ne pas
     * réordonner, ne pas « factoriser » en table associative triée.
     *
     * Le minimum n'est PAS monotone d'une génération à l'autre : 512 sur la
     * génération 5, mais 4096 sur Opus 4.6 / Haiku 4.5. Un modèle absent de la
     * table retombe sur un seuil trop HAUT, et c'est la direction coûteuse :
     * un bloc qui aurait pu être caché ne reçoit jamais de breakpoint.
     *
     * @param mixed $model Nom de modèle tel qu'envoyé dans le payload.
     */
    public static function min_chars_for_model(mixed $model): int {
        if (!is_string($model) || $model === '') {
            return MXCHAT_PLUS_PC_MIN_CHARS_DEFAULT;
        }
        $m = strtolower($model);

        // Fable 5 / 5.1, Mythos 5 / 5.1 : 512. (Mythos Preview est distinct : 2048 → testé avant.)
        if (str_contains($m, 'mythos-preview')) {
            return MXCHAT_PLUS_PC_MIN_CHARS_2048;
        }
        if (str_contains($m, 'fable') || str_contains($m, 'mythos')) {
            return MXCHAT_PLUS_PC_MIN_CHARS_512;
        }

        // Opus : le minimum varie par version.
        if (str_contains($m, 'opus')) {
            if (str_contains($m, 'opus-5')) {
                return MXCHAT_PLUS_PC_MIN_CHARS_512;       // Opus 5 / 5.5 : 512
            }
            if (str_contains($m, 'opus-4-8')
                || str_contains($m, 'opus-4-1')
                || str_contains($m, 'opus-4-0')
                || str_contains($m, 'opus-4-2025')) {
                return MXCHAT_PLUS_PC_MIN_CHARS_1024;      // Opus 4.8 / 4.1 / 4 (claude-opus-4-20250514) : 1024
            }
            if (str_contains($m, 'opus-4-7')) {
                return MXCHAT_PLUS_PC_MIN_CHARS_2048;      // Opus 4.7 : 2048
            }
            return MXCHAT_PLUS_PC_MIN_CHARS_4096;          // Opus 4.6 / 4.5 (et Opus inconnu) : 4096
        }

        // Haiku.
        if (str_contains($m, 'haiku-5')) {
            return MXCHAT_PLUS_PC_MIN_CHARS_512;           // Haiku 5.5 : 512
        }
        if (str_contains($m, 'haiku-4')) {
            return MXCHAT_PLUS_PC_MIN_CHARS_4096;          // Haiku 4.5 : 4096
        }
        if (str_contains($m, 'haiku')) {
            return MXCHAT_PLUS_PC_MIN_CHARS_2048;          // Haiku 3.x (ex. claude-3-5-haiku) : 2048
        }

        // Sonnet.
        if (str_contains($m, 'sonnet-5-5')) {
            return MXCHAT_PLUS_PC_MIN_CHARS_512;           // Sonnet 5.5 : 512
        }
        if (str_contains($m, 'sonnet')) {
            return MXCHAT_PLUS_PC_MIN_CHARS_1024;          // Sonnet 5 / 4.6 / 4.5 / 4 / 3.x : 1024
        }

        return MXCHAT_PLUS_PC_MIN_CHARS_DEFAULT;
    }

    /**
     * True si le bloc system contient le bloc « base de connaissances » que
     * MxChat recalcule à CHAQUE question.
     *
     * Quand le prompt du propriétaire contient le jeton `{context}`, MxChat
     * (get_system_instructions()) y substitue le résultat RAG de la requête :
     * le system change alors à chaque message, et MxChat renonce exprès à le
     * marquer (mxchat_anthropic_system_blocks()). Un breakpoint posé là, ou
     * sur l'historique qui le suit, paierait la prime d'écriture sur un préfixe
     * jamais relu.
     *
     * On teste les EN-TÊTES DÉLIMITÉS, jamais la phrase seule : le prompt par
     * défaut de MxChat cite « OFFICIAL KNOWLEDGE DATABASE CONTENT sections
     * marked with ===== delimiters » en clair, et une recherche naïve
     * couperait le cache de toutes les installations. Ces littéraux
     * appartiennent à l'hôte (class-mxchat-integrator.php, assemblage du
     * $kb_block) : ne pas les renommer.
     */
    public static function system_has_per_query_content(mixed $system): bool {
        return self::system_contains_any($system, MXCHAT_PLUS_PC_HOST_KB_MARKERS);
    }

    /**
     * True si un bloc texte du system contient l'une des aiguilles.
     *
     * @param array<mixed> $needles Chaînes cherchées (les vides sont ignorées).
     */
    public static function system_contains_any(mixed $system, array $needles): bool {
        $texts = [];
        if (is_string($system)) {
            $texts[] = $system;
        } elseif (is_array($system)) {
            foreach ($system as $block) {
                if (is_array($block) && isset($block['text']) && is_string($block['text'])) {
                    $texts[] = $block['text'];
                }
            }
        }
        foreach ($texts as $text) {
            foreach ($needles as $needle) {
                if (is_string($needle) && $needle !== '' && str_contains($text, $needle)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Pourquoi le system change-t-il à chaque requête ? '' s'il est stable.
     *
     * - VOLATILE_KB_BLOCK : MxChat y a injecté le bloc base de connaissances
     *   (jeton `{context}`), cf. system_has_per_query_content().
     * - VOLATILE_CURRENT_DATETIME : le system contient la valeur que MxChat
     *   substitue à `{current_datetime}` (3.2.22+). Elle change CHAQUE MINUTE :
     *   une entrée de cache vit ~60 s, la TTL 5 min de l'hôte couvre déjà toute
     *   sa vie utile, et la passer à 1 h (×2 au lieu de ×1,25) puis empiler des
     *   breakpoints d'historique derrière ne paierait que des écritures.
     *   `{current_date}` (une fois par jour) n'est PAS concerné : la TTL 1 h et
     *   l'historique restent rentables sur une journée.
     *
     * @param array<mixed> $datetime_values Valeurs {current_datetime} candidates,
     *        cf. MxChat_Plus_PromptCache::current_datetime_candidates().
     */
    public static function system_volatility_reason(mixed $system, array $datetime_values = []): string {
        if (self::system_has_per_query_content($system)) {
            return self::VOLATILE_KB_BLOCK;
        }
        if ($datetime_values !== [] && self::system_contains_any($system, $datetime_values)) {
            return self::VOLATILE_CURRENT_DATETIME;
        }
        return '';
    }

    /**
     * Valeur que MxChat substitue à `{current_datetime}` pour l'instant $timestamp.
     *
     * Recopie MxChat_Integrator::mxchat_current_datetime_placeholder(true)
     * (MxChat 3.2.25), méthode privée donc injoignable d'ici : format de date
     * puis d'heure du site, replis 'F j, Y' / 'g:i a' quand l'option n'est pas
     * une chaîne non vide, suffixe ' T' (abréviation du fuseau), fuseau du site.
     * À garder aligné si l'hôte change sa formule — une divergence ne casse
     * rien, elle rend juste la détection muette (le repli est le comportement
     * d'avant cette détection).
     *
     * @return string '' quand WordPress ne fournit pas wp_date()/wp_timezone().
     */
    public static function host_datetime_placeholder(int $timestamp): string {
        if (!function_exists('wp_date') || !function_exists('wp_timezone')) {
            return '';
        }
        $date_format = get_option('date_format');
        if (!is_string($date_format) || trim($date_format) === '') {
            $date_format = 'F j, Y';
        }
        $time_format = get_option('time_format');
        if (!is_string($time_format) || trim($time_format) === '') {
            $time_format = 'g:i a';
        }
        $value = wp_date($date_format . ' ' . $time_format . ' T', $timestamp, wp_timezone());
        return is_string($value) ? trim($value) : '';
    }

    // ──────────────────────────────────────────────────────────────────
    //  Forme des messages envoyés par MxChat
    // ──────────────────────────────────────────────────────────────────

    /** Texte d'un content : la chaîne elle-même, ou la concaténation des blocs text. */
    public static function content_text(mixed $content): string {
        if (is_string($content)) {
            return $content;
        }
        $text = '';
        if (is_array($content)) {
            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'text' && isset($block['text']) && is_string($block['text'])) {
                    $text .= $block['text'];
                }
            }
        }
        return $text;
    }

    /** True si le content porte au moins un bloc tool_use ou tool_result. */
    public static function content_has_tool_blocks(mixed $content): bool {
        if (!is_array($content)) {
            return false;
        }
        foreach ($content as $block) {
            if (is_array($block) && in_array($block['type'] ?? null, ['tool_use', 'tool_result'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reconnaît la forme que MxChat donne à `messages`.
     *
     * Chemin Claude non streamé (MxChat_Integrator::mxchat_generate_response_claude)
     * et premier saut de la boucle AI Tools (mxchat_fc_loop_anthropic), MxChat
     * 3.2.21 → 3.2.25 :
     *
     *   [ note (role system → user), historique…, u_N, bloc de contexte ]
     *
     * - la note vient de mxchat_fetch_conversation_history_for_ai() ;
     * - u_N, le message brut du visiteur, est déjà dans l'historique : il est
     *   enregistré dans la transcription AVANT la lecture de l'historique ;
     * - le bloc de contexte (ligne de date, "User asked: '…'", base de
     *   connaissances, URLs, page) est ajouté en dernier user. Il n'est JAMAIS
     *   enregistré : le tour suivant renvoie u_N, pas ce bloc.
     *
     * Les sauts suivants de la boucle ajoutent [assistant(tool_use), user(tool_result)]
     * après le bloc de contexte ; le dernier saut n'offre plus `tools`
     * ($offer_tools faux quand la profondeur ou le budget est épuisé).
     *
     * Détection du bloc de contexte, sur le dernier message AVANT tout échange
     * d'outils : le littéral MXCHAT_PLUS_PC_HOST_CONTEXT_MARKER (str_contains,
     * la ligne de date le précède), ou à défaut deux messages user consécutifs
     * — le littéral seul rate un bloc réécrit par mxchat_prepare_context, la
     * structure seule rate le cas où le filtre wp_kses de l'hôte a retiré u_N.
     *
     * @return array{shape:string, note:int|null, context:int|null}
     */
    public static function analyze_host_messages(mixed $messages, bool $has_tools): array {
        $layout = ['shape' => self::SHAPE_GENERIC, 'note' => null, 'context' => null];
        if (!is_array($messages) || $messages === [] || !array_is_list($messages)) {
            return $layout;
        }
        $n = count($messages);

        $first = $messages[0];
        if (is_array($first)
            && ($first['role'] ?? null) === 'user'
            && str_contains(self::content_text($first['content'] ?? null), MXCHAT_PLUS_PC_HOST_HISTORY_NOTE)
        ) {
            $layout['note'] = 0;
        }

        $first_tool = null;
        foreach ($messages as $i => $msg) {
            if (is_array($msg) && self::content_has_tool_blocks($msg['content'] ?? null)) {
                $first_tool = $i;
                break;
            }
        }

        $tail = $first_tool === null ? $n - 1 : $first_tool - 1;
        if ($tail >= 0 && $tail !== $layout['note'] && is_array($messages[$tail]) && ($messages[$tail]['role'] ?? null) === 'user') {
            $marker       = str_contains(self::content_text($messages[$tail]['content'] ?? null), MXCHAT_PLUS_PC_HOST_CONTEXT_MARKER);
            $prev_is_user = $tail > 0 && is_array($messages[$tail - 1]) && ($messages[$tail - 1]['role'] ?? null) === 'user';
            if ($marker || $prev_is_user) {
                $layout['context'] = $tail;
            }
        }

        if ($first_tool !== null) {
            $layout['shape'] = $has_tools ? self::SHAPE_FC_LOOP : self::SHAPE_FC_FINAL_HOP;
        } elseif ($layout['context'] !== null) {
            $layout['shape'] = $has_tools ? self::SHAPE_FC_FIRST_HOP : self::SHAPE_PLAIN;
        }
        return $layout;
    }

    /**
     * Breakpoints d'historique à poser, par ordre de PRIORITÉ (le plafond de 4
     * peut couper la fin de liste). `loop` = TTL 5 min (cf. loop_control()),
     * sinon TTL du module.
     *
     * - SHAPE_PLAIN : jamais sur le bloc de contexte (jamais renvoyé). La paire
     *   glissante va sur les messages bruts du visiteur qui le précèdent :
     *   u_N (écriture, relue au tour suivant) puis u_{N-1} (relecture exacte de
     *   l'écriture du tour précédent, sans dépendre de la fenêtre de recherche
     *   d'Anthropic).
     * - SHAPE_FC_FIRST_HOP : u_N (TTL module, relu au tour suivant), puis le
     *   bloc de contexte en 5 min, puis u_{N-1}. Le bloc de contexte n'est
     *   relu que par un saut 2 qui offre encore `tools` avec le même
     *   `tool_choice` : il n'est marqué que si $loop['write'] (cf.
     *   loop_hop_cacheability()). Quand le modèle répond sans outil, la perte
     *   se limite à ×0,25 du bloc. u_N et u_{N-1} ne dépendent pas de $loop :
     *   le premier saut de chaque tour envoie le même `tool_choice`.
     *   Ordre de rendu system(1 h) → u_{N-1}(1 h) → u_N(1 h) → contexte(5 min) : valide.
     * - SHAPE_FC_LOOP : les deux derniers user, en 5 min : le dernier
     *   tool_result (écriture, si $loop['write']) et le contexte ou
     *   tool_result précédent (lecture de l'écriture du saut précédent, si
     *   $loop['read']).
     * - SHAPE_FC_FINAL_HOP : rien. Sans `tools`, son préfixe diffère de celui
     *   de tout premier saut : une écriture ici ne serait jamais relue.
     * - SHAPE_GENERIC : comportement historique (deux derniers user, TTL module).
     *
     * $min_messages ne s'applique qu'aux breakpoints inter-tours, compté sans
     * la note ni le bloc de contexte : (user, assistant, user) par défaut, donc
     * un chat d'un seul message ne paie aucune écriture d'historique. Les
     * breakpoints de boucle n'en dépendent pas : ils servent le tour en cours.
     *
     * @param array<mixed>                                        $messages
     * @param array{shape:string, note:int|null, context:int|null} $layout
     * @param array{write:bool, read:bool}|null                   $loop
     *        Cf. loop_hop_cacheability() ; null (requête qui n'est pas celle
     *        de la boucle de MxChat) = écriture et lecture posées.
     * @return list<array{index:int, slot:string, loop:bool}>
     */
    public static function history_breakpoint_plan(array $messages, array $layout, int $min_messages, ?array $loop = null): array {
        $shape = $layout['shape'];
        if ($shape === self::SHAPE_FC_FINAL_HOP || !array_is_list($messages)) {
            return [];
        }
        $note    = $layout['note'];
        $context = $layout['context'];
        $write   = $loop === null || $loop['write'];
        $read    = $loop === null || $loop['read'];
        $plan    = [];

        if ($shape === self::SHAPE_FC_LOOP) {
            $users = self::user_indexes($messages, count($messages), $note);
            $last  = array_pop($users);
            $prev  = array_pop($users);
            if ($write && $last !== null) {
                $plan[] = ['index' => $last, 'slot' => 'last_user', 'loop' => true];
            }
            if ($read && $prev !== null) {
                $plan[] = ['index' => $prev, 'slot' => 'prev_user', 'loop' => true];
            }
            return $plan;
        }

        $end        = ($shape === self::SHAPE_PLAIN || $shape === self::SHAPE_FC_FIRST_HOP) && $context !== null
            ? $context
            : count($messages);
        $counted    = $end - ($note !== null ? 1 : 0);
        $cross_turn = $counted >= $min_messages;
        $users      = self::user_indexes($messages, $end, $note);
        $last       = array_pop($users);
        $prev       = array_pop($users);

        if ($cross_turn && $last !== null) {
            $plan[] = ['index' => $last, 'slot' => 'last_user', 'loop' => false];
        }
        if ($shape === self::SHAPE_FC_FIRST_HOP && $context !== null && $write) {
            $plan[] = ['index' => $context, 'slot' => 'context_block', 'loop' => true];
        }
        if ($cross_turn && $prev !== null) {
            $plan[] = ['index' => $prev, 'slot' => 'prev_user', 'loop' => false];
        }
        return $plan;
    }

    /**
     * Sur un saut de la boucle AI Tools de MxChat, l'écriture posée ici
     * sera-t-elle relue au saut suivant, et l'écriture du saut précédent
     * est-elle relue ici ?
     *
     * Anthropic invalide le cache des `messages` quand `tool_choice` change
     * (celui de tools et system reste valide), et tout le cache quand `tools`
     * disparaît. Or MxChat_Integrator::mxchat_fc_loop_anthropic() (MxChat 3.2.25) :
     *   - n'offre plus `tools` au saut `step` quand `step == depth`, ni, depuis
     *     3.2.22, quand le budget d'appels du tour est épuisé ;
     *   - depuis 3.2.23, envoie `tool_choice.disable_parallel_tool_use` quand
     *     il ne reste qu'un appel ({type:auto} sinon).
     * Une écriture n'est donc relue que si le saut suivant offre encore
     * `tools` avec le même `{type:auto}` : step + 1 < depth, et après l'appel
     * le plus court possible (un outil), encore 2 appels restants (1 sur
     * 3.2.22, sans disable_parallel_tool_use ; aucune condition de budget
     * avant). Un appel en parallèle ou une réponse sans outil peuvent encore
     * la perdre : ça ne se prévoit pas, la perte reste ×0,25 du segment.
     *
     * La lecture : le saut précédent offrait `tools` (sans quoi il n'y aurait
     * pas de saut suivant), avec {type:auto} puisqu'un saut à un seul appel
     * restant n'est jamais suivi d'un saut qui offre `tools`, et il a bien
     * écrit (si ce saut-ci offre `tools` en {type:auto}, le précédent
     * remplissait la condition ci-dessus). Elle tient donc sauf si CE saut
     * porte disable_parallel_tool_use.
     *
     * L'état vient du payload : step = messages assistant porteurs de
     * tool_use (l'historique normalisé par l'hôte n'en contient jamais),
     * appels faits = blocs tool_result sans `is_error` (l'hôte marque ainsi
     * les appels refusés faute de budget). Profondeur et budget viennent des
     * statiques de l'hôte, cf. MxChat_Plus_Host::tool_loop_profile() ; quand
     * il ne les expose pas, seul disable_parallel_tool_use renseigne (il ne
     * reste alors qu'un appel). Un filtre `http_request_args` tiers, de
     * priorité inférieure à 10, qui imposerait disable_parallel_tool_use à
     * chaque saut ne fausse que les deux derniers appels du tour.
     *
     * @param array<mixed> $messages
     * @param array{depth:int|null, budget:int|null, budget_gate:bool, single_call_choice:bool} $profile
     * @return array{step:int, calls_made:int, remaining:int|null, write:bool, read:bool}
     */
    public static function loop_hop_cacheability(array $messages, mixed $tool_choice, array $profile): array {
        $step       = 0;
        $calls_made = 0;
        foreach ($messages as $msg) {
            $content = is_array($msg) ? ($msg['content'] ?? null) : null;
            if (!is_array($content) || !self::content_has_tool_blocks($content)) {
                continue;
            }
            if (($msg['role'] ?? null) === 'assistant') {
                $step++;
                continue;
            }
            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'tool_result' && ($block['is_error'] ?? false) !== true) {
                    $calls_made++;
                }
            }
        }

        // Un disable_parallel_tool_use envoyé à un hôte qui n'en pose pas vient
        // d'un tiers : il ne dit rien du budget.
        $single_call = $profile['single_call_choice']
            && is_array($tool_choice)
            && ($tool_choice['disable_parallel_tool_use'] ?? null) === true;
        $remaining = $profile['budget'] !== null ? $profile['budget'] - $calls_made : null;

        $write = $profile['depth'] === null || $step + 1 < $profile['depth'];
        if ($write && $profile['budget_gate']) {
            $write = $remaining !== null
                ? $remaining >= ($profile['single_call_choice'] ? 3 : 2)
                : !$single_call;
        }
        // Le budget connu tranche : disable_parallel_tool_use avec plusieurs
        // appels restants a été imposé par un tiers, sans doute à chaque saut.
        $read = !($single_call && ($remaining === null || $remaining <= 1));

        return [
            'step'       => $step,
            'calls_made' => $calls_made,
            'remaining'  => $remaining,
            'write'      => $write,
            'read'       => $read,
        ];
    }

    /**
     * Index des messages role=user dans [0, $end), note de l'hôte exclue, dans l'ordre.
     *
     * @param list<mixed> $messages
     * @return list<int>
     */
    private static function user_indexes(array $messages, int $end, ?int $skip): array {
        $out = [];
        for ($i = 0; $i < $end && $i < count($messages); $i++) {
            if ($i !== $skip && is_array($messages[$i]) && ($messages[$i]['role'] ?? null) === 'user') {
                $out[] = $i;
            }
        }
        return $out;
    }

    /** Durée de vie, en secondes, que Anthropic applique à une valeur cache_control. */
    public static function ttl_seconds(mixed $control): int {
        return is_array($control) && ($control['ttl'] ?? null) === '1h' ? 3600 : 300;
    }

    /**
     * Allonge à la TTL de $control les breakpoints déjà posés sur tools et
     * system, quand ils sont plus courts.
     *
     * MxChat 3.2.21 marque son system en TTL 5 min. Le module applique la TTL
     * 1 h par défaut : sans alignement, le préfixe le plus partagé (le prompt
     * système, commun à tous les visiteurs) expire entre deux visites sur un
     * site peu fréquenté, et la requête mélange les deux TTL dans un ordre
     * qu'Anthropic interdit (cf. enforce_ttl_order()).
     *
     * @param array<mixed> $payload        Payload décodé, modifié en place.
     * @param array<mixed> $control        Valeur cache_control du module.
     * @param bool         $include_system false quand le system porte du contenu par requête.
     * @return int Nombre de breakpoints allongés.
     */
    public static function align_prefix_ttl(array &$payload, array $control, bool $include_system): int {
        $target = self::ttl_seconds($control);
        if ($target <= 300) {
            return 0;
        }
        $keys = $include_system ? ['tools', 'system'] : ['tools'];
        $aligned = 0;
        foreach ($keys as $key) {
            if (!isset($payload[$key]) || !is_array($payload[$key])) {
                continue;
            }
            foreach ($payload[$key] as $i => $block) {
                if (is_array($block)
                    && !empty($block['cache_control'])
                    && is_array($block['cache_control'])
                    && self::ttl_seconds($block['cache_control']) < $target
                ) {
                    $payload[$key][$i]['cache_control']['ttl'] = '1h';
                    $aligned++;
                }
            }
        }
        return $aligned;
    }

    /**
     * Garantit l'ordre de TTL exigé par Anthropic : dans l'ordre de rendu
     * (tools → system → messages), un breakpoint 1 h ne peut pas suivre un
     * breakpoint 5 min. La documentation ne dit pas si l'API rejette la
     * requête ou ignore les marqueurs ; dans les deux cas la requête est hors
     * contrat, donc on ne l'envoie jamais ainsi.
     *
     * Correction minimale et toujours sûre : on ALLONGE les TTL trop courtes
     * situées avant un breakpoint 1 h (une TTL plus longue ne coûte qu'une
     * prime d'écriture, jamais une erreur). Couvre aussi les marqueurs posés
     * par l'hôte ou par un filtre tiers, pas seulement les nôtres.
     *
     * @param array<mixed> $payload Payload décodé, modifié en place.
     * @return int Nombre de breakpoints allongés.
     */
    public static function enforce_ttl_order(array &$payload): int {
        // Chemins de tous les blocs marqués, dans l'ordre de rendu.
        $paths = [];
        foreach (['tools', 'system'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                foreach ($payload[$key] as $i => $block) {
                    if (is_array($block) && !empty($block['cache_control']) && is_array($block['cache_control'])) {
                        $paths[] = [$key, $i, null];
                    }
                }
            }
        }
        if (isset($payload['messages']) && is_array($payload['messages'])) {
            foreach ($payload['messages'] as $m => $msg) {
                if (!is_array($msg) || !isset($msg['content']) || !is_array($msg['content'])) {
                    continue;
                }
                foreach ($msg['content'] as $j => $block) {
                    if (is_array($block) && !empty($block['cache_control']) && is_array($block['cache_control'])) {
                        $paths[] = ['messages', $m, $j];
                    }
                }
            }
        }

        // Parcours à rebours : tout marqueur plus court qu'un marqueur situé
        // APRÈS lui est allongé.
        $upgraded = 0;
        $max_after = 0;
        for ($p = count($paths) - 1; $p >= 0; $p--) {
            [$key, $i, $j] = $paths[$p];
            $control = $j === null
                ? $payload[$key][$i]['cache_control']
                : $payload[$key][$i]['content'][$j]['cache_control'];
            $ttl = self::ttl_seconds($control);
            if ($ttl < $max_after) {
                if ($j === null) {
                    $payload[$key][$i]['cache_control']['ttl'] = '1h';
                } else {
                    $payload[$key][$i]['content'][$j]['cache_control']['ttl'] = '1h';
                }
                $upgraded++;
                $ttl = $max_after;
            }
            $max_after = max($max_after, $ttl);
        }
        return $upgraded;
    }

    /**
     * True si au moins un breakpoint du payload utilise la TTL 1 h. Décide
     * seulement si l'ancien header beta est ajouté (par compatibilité) : il
     * n'est plus requis par Anthropic, cf. MXCHAT_PLUS_PC_EXTENDED_TTL_HEADER.
     */
    public static function payload_uses_extended_ttl(mixed $payload): bool {
        if (!is_array($payload)) {
            return false;
        }
        foreach (['tools', 'system'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                foreach ($payload[$key] as $block) {
                    if (is_array($block) && self::ttl_seconds($block['cache_control'] ?? null) === 3600) {
                        return true;
                    }
                }
            }
        }
        if (isset($payload['messages']) && is_array($payload['messages'])) {
            foreach ($payload['messages'] as $msg) {
                if (is_array($msg) && isset($msg['content']) && is_array($msg['content'])) {
                    foreach ($msg['content'] as $block) {
                        if (is_array($block) && self::ttl_seconds($block['cache_control'] ?? null) === 3600) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    /**
     * Longueur en CARACTÈRES (mb-safe), pas en octets.
     * Les seuils sont calibrés ~4 caractères/token ; en UTF-8 multioctet (arabe,
     * hébreu RTL, CJK…) strlen() compterait les octets et fausserait l'estimation.
     */
    public static function strlen(mixed $s): int {
        if (!is_string($s)) {
            return 0;
        }
        return function_exists('mb_strlen') ? mb_strlen($s) : \strlen($s);
    }

    /** Taille texte cumulée du bloc system (chaîne ou array de blocs). */
    public static function system_size(mixed $system): int {
        if (is_string($system)) {
            return self::strlen($system);
        }
        if (!is_array($system)) {
            return 0;
        }
        $total = 0;
        foreach ($system as $block) {
            if (is_array($block) && isset($block['text']) && is_string($block['text'])) {
                $total += self::strlen($block['text']);
            }
        }
        return $total;
    }

    /**
     * Taille cumulée des définitions tools (name + description + input_schema sérialisé).
     * Le paramètre $stop_at court-circuite la mesure dès que le seuil est atteint.
     */
    public static function tools_size(mixed $tools, int $stop_at = PHP_INT_MAX): int {
        if (!is_array($tools)) {
            return 0;
        }
        $total = 0;
        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            if (isset($tool['name']) && is_string($tool['name'])) {
                $total += self::strlen($tool['name']);
            }
            if (isset($tool['description']) && is_string($tool['description'])) {
                $total += self::strlen($tool['description']);
            }
            if (isset($tool['input_schema'])) {
                $encoded = wp_json_encode($tool['input_schema']);
                if (is_string($encoded)) {
                    $total += self::strlen($encoded);
                }
            }
            if ($total >= $stop_at) {
                return $total;
            }
        }
        return $total;
    }

    /** True si au moins un bloc porte déjà un cache_control. */
    public static function blocks_have_cache_control(mixed $blocks): bool {
        if (!is_array($blocks)) {
            return false;
        }
        foreach ($blocks as $block) {
            if (is_array($block) && !empty($block['cache_control'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Nombre de breakpoints `cache_control` DÉJÀ présents dans le payload,
     * tous emplacements confondus (tools, system, contenu des messages).
     *
     * Indispensable depuis MxChat 3.2.21, qui pose lui-même un breakpoint sur
     * son bloc system (MxChat_Integrator::mxchat_anthropic_system_blocks).
     * Anthropic en plafonne le nombre à 4 par requête et rejette la requête
     * au-delà : nos propres breakpoints doivent donc se compter EN PLUS de
     * ceux de l'hôte, et non repartir de zéro. Ne pas le faire fonctionne
     * aujourd'hui par coïncidence arithmétique (3 + 1 = 4) et casserait dès
     * que l'hôte en ajouterait un second.
     */
    public static function count_existing_breakpoints(mixed $payload): int {
        if (!is_array($payload)) {
            return 0;
        }

        $count = 0;

        foreach (['tools', 'system'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                foreach ($payload[$key] as $block) {
                    if (is_array($block) && !empty($block['cache_control'])) {
                        $count++;
                    }
                }
            }
        }

        if (isset($payload['messages']) && is_array($payload['messages'])) {
            foreach ($payload['messages'] as $msg) {
                if (is_array($msg) && isset($msg['content']) && is_array($msg['content'])) {
                    foreach ($msg['content'] as $block) {
                        if (is_array($block) && !empty($block['cache_control'])) {
                            $count++;
                        }
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Indexes des N derniers messages avec role=user (plus récent en premier).
     *
     * @return list<int>
     */
    public static function last_user_indexes(mixed $messages, int $limit = 2): array {
        $indexes = [];
        if (!is_array($messages)) {
            return $indexes;
        }
        for ($i = count($messages) - 1; $i >= 0 && count($indexes) < $limit; $i--) {
            if (isset($messages[$i]['role']) && $messages[$i]['role'] === 'user') {
                $indexes[] = $i;
            }
        }
        return $indexes;
    }

    /**
     * Ajoute cache_control au dernier bloc d'un content (string ou array). Renvoie true si muté.
     *
     * @param array<mixed>|null $control Valeur cache_control ; null = ephemeral_control().
     */
    public static function add_cache_control_to_content(mixed &$content, ?array $control = null): bool {
        $control ??= self::ephemeral_control();
        if (is_string($content)) {
            $content = [[
                'type'          => 'text',
                'text'          => $content,
                'cache_control' => $control,
            ]];
            return true;
        }
        if (is_array($content) && count($content) > 0 && array_is_list($content)) {
            $last = count($content) - 1;
            if (is_array($content[$last])) {
                $content[$last]['cache_control'] = $control;
                return true;
            }
        }
        return false;
    }

    /**
     * Valeur cache_control des breakpoints posés DANS la boucle function-calling
     * de MxChat : même type que le module, sans `ttl`, donc la TTL 5 min par
     * défaut d'Anthropic. Les sauts se suivent à quelques secondes ; la 1 h n'y
     * achèterait que la prime d'écriture ×2 au lieu de ×1,25.
     *
     * @param array<mixed> $control Valeur cache_control du module.
     * @return array{type:string}
     */
    public static function loop_control(array $control): array {
        return ['type' => isset($control['type']) && is_string($control['type']) ? $control['type'] : 'ephemeral'];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Ré-encodage JSON fidèle
    // ──────────────────────────────────────────────────────────────────

    /**
     * Chemins des objets JSON que json_decode(…, true) puis wp_json_encode()
     * transformeraient en tableau : objet vide `{}` (→ `[]`) ou objet à clés
     * "0", "1", … consécutives (→ liste).
     *
     * Le module décode le body en tableaux associatifs (tous les helpers et
     * leurs types PHPStan en dépendent), puis le ré-encode dès qu'il pose un
     * breakpoint. Sans cette restauration, un outil AI Tools sans argument
     * (`"properties":{}`, ou un tool_use `"input":{}`) partirait en `[]`,
     * qu'Anthropic refuse : la boucle AI Tools de MxChat se dégraderait
     * silencieusement à chaque tour. On décode donc une seconde fois en objets,
     * seulement quand le body va être réécrit, pour relever ces chemins.
     *
     * @param mixed $node Résultat de json_decode($body, false).
     * @return list<list<int|string>>
     */
    public static function json_object_paths(mixed $node): array {
        $found = [];
        self::collect_json_object_paths($node, [], $found);
        return $found;
    }

    /**
     * @param list<int|string>       $path
     * @param list<list<int|string>> $found
     */
    private static function collect_json_object_paths(mixed $node, array $path, array &$found): void {
        if ($node instanceof \stdClass) {
            // Le cast convertit les noms numériques en clés entières, comme le
            // fait json_decode(…, true) : c'est ce qui rend array_is_list() juste ici.
            $props = (array) $node;
            if ($props === [] || array_is_list($props)) {
                $found[] = $path;
            }
        } elseif (is_array($node)) {
            $props = $node;
        } else {
            return;
        }
        foreach ($props as $key => $child) {
            if (is_array($child) || $child instanceof \stdClass) {
                $child_path   = $path;
                $child_path[] = $key;
                self::collect_json_object_paths($child, $child_path, $found);
            }
        }
    }

    /**
     * Remet en objets, dans le payload décodé en tableaux, les chemins relevés
     * par json_object_paths(). Du plus profond au moins profond, pour que la
     * navigation ne traverse jamais un nœud déjà converti.
     *
     * Ne touche un chemin que s'il est encore un tableau vide ou une liste :
     * si le module y a ajouté une clé (cache_control), l'encodage produit déjà
     * un objet, et la conversion écraserait l'ajout. Les mutations du module
     * (ajout de clés, `ttl`, chaîne → tableau de blocs) ne déplacent aucun de
     * ces chemins.
     *
     * @param array<mixed>           $payload Modifié en place.
     * @param list<list<int|string>> $paths
     * @return int Nombre d'objets restaurés.
     */
    public static function restore_json_objects(array &$payload, array $paths): int {
        usort($paths, static fn(array $a, array $b): int => count($b) <=> count($a));
        $restored = 0;
        foreach ($paths as $path) {
            if ($path === []) {
                continue; // la racine d'un payload Anthropic n'est jamais vide
            }
            $node = &$payload;
            $found = true;
            foreach ($path as $key) {
                if (!is_array($node) || !array_key_exists($key, $node)) {
                    $found = false;
                    break;
                }
                $node = &$node[$key];
            }
            if ($found && is_array($node) && ($node === [] || array_is_list($node))) {
                $node = (object) $node;
                $restored++;
            }
            unset($node);
        }
        return $restored;
    }

    /**
     * Répare les blocs tool_use dont `input` est arrivé en tableau vide.
     *
     * MxChat_Integrator::mxchat_fc_post() (MxChat 3.2.21 → 3.2.25) décode la
     * réponse d'Anthropic en tableaux associatifs, et mxchat_fc_loop_anthropic()
     * renvoie tel quel le content de l'assistant au saut suivant : un appel
     * d'outil sans argument (`"input":{}`) repart donc en `"input":[]`. L'API
     * attend un objet à cet endroit ; aucun hook de l'hôte ne permet de corriger
     * ce décodage, d'où la réparation ici, dans le body sortant. Un tableau vide
     * n'est jamais un `input` valide : la conversion est sans risque.
     *
     * @param array<mixed> $payload Modifié en place.
     * @return int Nombre de blocs réparés.
     */
    public static function repair_tool_use_inputs(array &$payload): int {
        if (!isset($payload['messages']) || !is_array($payload['messages'])) {
            return 0;
        }
        $repaired = 0;
        foreach ($payload['messages'] as $m => $msg) {
            if (!is_array($msg) || !isset($msg['content']) || !is_array($msg['content'])) {
                continue;
            }
            foreach ($msg['content'] as $j => $block) {
                if (is_array($block)
                    && ($block['type'] ?? null) === 'tool_use'
                    && array_key_exists('input', $block)
                    && $block['input'] === []
                ) {
                    $payload['messages'][$m]['content'][$j]['input'] = new \stdClass();
                    $repaired++;
                }
            }
        }
        return $repaired;
    }

    /**
     * TTL 1 h par défaut, fallback 5 min si opt-out via constante. Filtrable.
     *
     * Économie : prime d'écriture ×2 (1 h) vs ×1,25 (5 min). La 1 h protège les
     * conversations avec pauses > 5 min (évite une réécriture complète de
     * l'historique au retour de l'utilisateur). Pour du chat très rapide
     * (tours < 5 min), la 5 min suffit et coûte moins cher :
     *   define('MXCHAT_PLUS_PC_EXTENDED_TTL', false);  // ou via le filtre ci-dessous.
     *
     * `MXCHAT_PLUS_PROMPTCACHE_EXTENDED_TTL` est accepté aussi : c'est le nom
     * que docs/promptcache/USAGE.md a publié en 1.0.0, alors que le code ne
     * lisait que `MXCHAT_PLUS_PC_EXTENDED_TTL`. Un opérateur qui a suivi la
     * doc croit être en 5 min ; le retirer le replongerait dans ce silence.
     *
     * @return array{type:string, ttl?:string}
     */
    public static function ephemeral_control(): array {
        $opted_out = (defined('MXCHAT_PLUS_PC_EXTENDED_TTL') && constant('MXCHAT_PLUS_PC_EXTENDED_TTL') === false)
            || (defined('MXCHAT_PLUS_PROMPTCACHE_EXTENDED_TTL') && constant('MXCHAT_PLUS_PROMPTCACHE_EXTENDED_TTL') === false);
        if ($opted_out) {
            $control = ['type' => 'ephemeral'];
        } else {
            $control = ['type' => 'ephemeral', 'ttl' => '1h'];
        }
        /**
         * Filtre la structure cache_control envoyée à Anthropic.
         *
         * @param array{type:string, ttl?:string} $control Tableau {type, ttl?}.
         */
        $filtered = apply_filters('mxchat_plus_promptcache_ephemeral_control', $control);

        // Un filtre tiers peut renvoyer n'importe quoi ; sans `type` la clé
        // cache_control est invalide côté Anthropic, donc on retombe sur la
        // valeur calculée plutôt que d'émettre une requête rejetée.
        if (!is_array($filtered) || !isset($filtered['type']) || !is_string($filtered['type'])) {
            return $control;
        }

        $out = ['type' => $filtered['type']];
        if (isset($filtered['ttl']) && is_string($filtered['ttl'])) {
            $out['ttl'] = $filtered['ttl'];
        }

        return $out;
    }

    /** True si la TTL retenue par le module est la TTL étendue 1 h. */
    public static function using_extended_ttl(): bool {
        $control = self::ephemeral_control();
        return isset($control['ttl']) && $control['ttl'] === '1h';
    }

    /**
     * Avertissement diagnostic : cache écrit mais peu/pas relu.
     *
     * Ne se déclenche que sur l'injection Anthropic (cache_creation > 0). Pour les
     * providers à cache automatique, cache_creation vaut toujours 0 → pas de bruit.
     * Symptôme classique : préfixe instable (placeholder `{current_datetime}`,
     * shortcode ou filtre `mxchat_system_instructions` qui injecte une donnée
     * par requête dans le system prompt), historique reformaté entre les tours,
     * ou simplement trop peu de trafic répété.
     *
     * Chaîne source en anglais (CLAUDE.md) ; la traduction française vit dans
     * languages/mxchat-plus-fr_FR.po.
     *
     * @param mixed $s Bucket de stats.
     * @return string Message traduit, ou '' si aucun avertissement.
     */
    public static function caching_hint(mixed $s): string {
        if (!is_array($s)) {
            return '';
        }
        $creation = (int) ($s['cache_creation_tokens'] ?? 0);
        $read     = (int) ($s['cache_read_tokens'] ?? 0);
        $requests = (int) ($s['requests'] ?? 0);
        if ($creation > 0 && $requests >= 5 && $read < $creation) {
            return __(
                '  ⚠ Cache writes exceed cache reads: the prompt prefix is probably unstable '
                . '(per-request data in the system prompt, such as the {current_datetime} placeholder, '
                . 'a shortcode or a filter; history rewritten between turns) or traffic is too low '
                . 'to pay back the cache. See "Cache writes > reads" in docs/promptcache/USAGE.md.',
                'mxchat-plus'
            );
        }
        return '';
    }
}
