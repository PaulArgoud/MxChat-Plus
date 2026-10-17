<?php
/**
 * Prompt Cache module — cœur : injection Anthropic + mesure multi-provider.
 *
 * Trois greffons, aucun fichier du plugin hôte (mxchat-basic) n'est modifié :
 *
 *   1. `http_request_args`  → INJECTION. Uniquement api.anthropic.com/v1/messages.
 *      Pose jusqu'à MXCHAT_PLUS_PC_MAX_BREAKPOINTS marqueurs cache_control
 *      (tools → system → historique, selon la forme du payload de MxChat :
 *      cf. MxChat_Plus_PromptCache_Models::history_breakpoint_plan()) et
 *      ajoute, par compatibilité, l'ancien header beta de la TTL 1 h — que
 *      l'API n'exige plus.
 *   2. `http_response`      → MESURE. Tous les providers reconnus (Anthropic,
 *      OpenAI Chat Completions et Responses, OpenRouter/xAI/DeepSeek, Gemini
 *      natif ou OpenAI-compatible, fournisseur personnalisé de MxChat), dont le
 *      cache est automatique : on ne modifie rien, on lit juste les tokens
 *      cachés pour le tableau de bord.
 *   3. `mxchat_system_instructions` (filtre de l'HÔTE, en observation pure :
 *      la valeur est rendue inchangée) → repère la valeur que MxChat substitue
 *      à `{current_datetime}`, pour traiter ce system comme volatile.
 *
 * Filtres exposés aux intégrateurs :
 *   mxchat_plus_promptcache_should_inject             (bool,  $payload, $args, $url)
 *   mxchat_plus_promptcache_min_chars                 (int,   $model, $payload)
 *   mxchat_plus_promptcache_min_messages              (int,   $payload)
 *   mxchat_plus_promptcache_volatile_system           (bool,  $payload)
 *   mxchat_plus_promptcache_send_extended_ttl_header  (bool,  $args)
 *   mxchat_plus_promptcache_ephemeral_control         (array) — cf. Models::ephemeral_control()
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_PromptCache {

    /**
     * Valeurs `{current_datetime}` vues dans le system de MxChat pendant cette
     * requête PHP (cf. observe_system_instructions()).
     *
     * @var list<string>
     */
    private static array $observed_datetimes = [];

    /** Accroche les greffons. Idempotent côté WordPress. */
    public static function register_hooks(): void {
        add_filter('http_request_args', [self::class, 'inject_cache_control'], 10, 2);
        add_filter('http_response', [self::class, 'record_metrics'], 10, 3);
        // Nom de filtre de l'HÔTE (MxChat_Integrator::get_system_instructions(),
        // 3.2.21+) : ne pas le préfixer. Priorité maximale pour voir la valeur
        // finale ; on observe sans jamais modifier.
        add_filter('mxchat_system_instructions', [self::class, 'observe_system_instructions'], PHP_INT_MAX, 1);
    }

    /** Strict host+path match : gate de l'INJECTION (Anthropic uniquement). */
    public static function is_anthropic_messages_url(mixed $url): bool {
        if (!is_string($url)) {
            return false;
        }
        $parsed = wp_parse_url($url);
        if (!is_array($parsed)) {
            return false;
        }
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '';
        return $host === 'api.anthropic.com' && $path === '/v1/messages';
    }

    // ──────────────────────────────────────────────────────────────────
    //  0) Observation du system de l'hôte ({current_datetime})
    // ──────────────────────────────────────────────────────────────────

    /**
     * Repère, dans les instructions que MxChat vient d'assembler, la valeur
     * qu'il a substituée à `{current_datetime}` (3.2.22+, MxChat_Integrator::
     * get_system_instructions() appelle ce filtre juste après la substitution).
     *
     * Pourquoi observer plutôt que relire le prompt du propriétaire : le bot
     * (Multi-Bot) n'est connu ni de l'URL ni du payload qu'intercepte
     * http_request_args, et un prompt global testé à tort marquerait volatile
     * un bot qui n'a pas le placeholder. Ici on voit la chaîne réellement
     * envoyée ; inject_cache_control() la cherchera dans le system sortant.
     * La boucle AI Tools calcule son system une seule fois, puis exécute des
     * outils avant les sauts suivants : retenir la valeur vue vaut mieux que
     * recalculer « maintenant » au moment de l'envoi.
     *
     * Pass-through strict : la valeur est toujours rendue telle quelle.
     */
    public static function observe_system_instructions(mixed $instructions): mixed {
        if (!is_string($instructions) || $instructions === '') {
            return $instructions;
        }
        $now = time();
        // Minute courante et précédente : la substitution a pu se faire juste
        // avant un changement de minute.
        foreach ([$now, $now - 60] as $timestamp) {
            $value = MxChat_Plus_PromptCache_Models::host_datetime_placeholder($timestamp);
            if ($value !== '' && str_contains($instructions, $value)) {
                if (!in_array($value, self::$observed_datetimes, true)) {
                    self::$observed_datetimes[] = $value;
                    if (count(self::$observed_datetimes) > MXCHAT_PLUS_PC_OBSERVED_DATETIMES_MAX) {
                        array_shift(self::$observed_datetimes);
                    }
                }
                break;
            }
        }
        return $instructions;
    }

    /**
     * Valeurs `{current_datetime}` à chercher dans un system sortant : celles
     * observées pendant la requête, plus celles de maintenant, −60 s et −120 s
     * quand l'observation n'a pas eu lieu (autre chemin d'appel, filtre retiré).
     * −120 s couvre la boucle AI Tools, dont les sauts suivent l'exécution des
     * outils et les retries de l'hôte.
     *
     * @return list<string>
     */
    public static function current_datetime_candidates(): array {
        $values = self::$observed_datetimes;
        $now    = time();
        foreach ([$now, $now - 60, $now - 120] as $timestamp) {
            $value = MxChat_Plus_PromptCache_Models::host_datetime_placeholder($timestamp);
            if ($value !== '') {
                $values[] = $value;
            }
        }
        return array_values(array_unique($values));
    }

    /** Oublie les valeurs observées (processus longs, tests). */
    public static function forget_observed_datetimes(): void {
        self::$observed_datetimes = [];
    }

    // ──────────────────────────────────────────────────────────────────
    //  1) Injection des breakpoints cache_control
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param mixed $args Arguments HTTP WordPress (array en pratique).
     * @param mixed $url  URL cible.
     * @return mixed Les $args, mutés ou non.
     */
    public static function inject_cache_control(mixed $args, mixed $url): mixed {
        if (!is_array($args) || !self::is_anthropic_messages_url($url)) {
            return $args;
        }

        // Bail-out précoce : seules les requêtes POST nous intéressent (OPTIONS preflight, etc.).
        $method = isset($args['method']) && is_string($args['method']) ? strtoupper($args['method']) : 'GET';
        if ($method !== 'POST') {
            return $args;
        }

        if (empty($args['body']) || !is_string($args['body'])) {
            return $args;
        }

        $payload = json_decode($args['body'], true);
        if (!is_array($payload)) {
            return $args;
        }

        /**
         * Permet de désactiver l'injection pour une requête spécifique.
         *
         * @param bool   $should  true par défaut.
         * @param array  $payload Payload décodé envoyé à Anthropic.
         * @param array  $args    Arguments HTTP WordPress.
         * @param string $url     URL cible.
         */
        if (!apply_filters('mxchat_plus_promptcache_should_inject', true, $payload, $args, $url)) {
            return $args;
        }

        $model     = isset($payload['model']) && is_string($payload['model']) ? $payload['model'] : '';
        $min_chars = MxChat_Plus_PromptCache_Models::min_chars_for_model($model);

        /**
         * Filtre le seuil minimum (en caractères) pour qu'un bloc soit marqué cacheable.
         *
         * @param int    $min_chars Seuil par défaut basé sur le modèle.
         * @param string $model     Nom du modèle Anthropic envoyé dans la requête.
         * @param array  $payload   Payload décodé.
         */
        $min_chars = (int) apply_filters('mxchat_plus_promptcache_min_chars', $min_chars, $model, $payload);

        // Pourquoi le system changerait-il à chaque requête ? Bloc base de
        // connaissances (jeton `{context}`) ou valeur `{current_datetime}`
        // (change chaque minute). `{current_date}` reste cachable.
        $volatile_reason = MxChat_Plus_PromptCache_Models::system_volatility_reason(
            $payload['system'] ?? null,
            self::current_datetime_candidates()
        );
        $detected = $volatile_reason !== '';

        /**
         * Le system porte-t-il du contenu recalculé à chaque requête ?
         *
         * Vrai par défaut quand MxChat y a injecté le bloc base de connaissances
         * (jeton `{context}`) ou la valeur de `{current_datetime}`. Le module ne
         * marque alors ni le system ni l'historique d'un tour à l'autre (u_N /
         * u_{N-1}), et n'allonge pas le marqueur 5 min que l'hôte a pu poser :
         * ce préfixe change d'un tour à l'autre, chaque écriture serait perdue.
         * Les tools, rendus AVANT le system, restent cachables, et les
         * breakpoints 5 min de la boucle AI Tools restent posés : MxChat calcule
         * le system une fois par tour et renvoie la même chaîne à chaque saut.
         * Seul moyen de couvrir une donnée volatile injectée par un shortcode ou
         * un filtre tiers.
         *
         * @param bool  $volatile Détection par défaut.
         * @param array $payload  Payload décodé.
         */
        $volatile_system = (bool) apply_filters('mxchat_plus_promptcache_volatile_system', $detected, $payload);
        if ($volatile_system !== $detected) {
            $volatile_reason = MxChat_Plus_PromptCache_Models::VOLATILE_FILTER;
        }

        $has_tools = !empty($payload['tools']) && is_array($payload['tools']);
        $layout    = MxChat_Plus_PromptCache_Models::analyze_host_messages($payload['messages'] ?? null, $has_tools);
        $control   = MxChat_Plus_PromptCache_Models::ephemeral_control();

        $mutated = false;
        // Compte les breakpoints déjà posés par l'hôte (MxChat 3.2.21+ marque
        // son bloc system) : le plafond de 4 d'Anthropic est global à la
        // requête, pas propre à ce plugin.
        $used    = MxChat_Plus_PromptCache_Models::count_existing_breakpoints($payload);
        $debug   = [
            'time'                  => time(),
            'model'                 => $model,
            'min_chars'             => $min_chars,
            'shape'                 => $layout['shape'],
            'volatile_system'       => $volatile_system,
            'volatile_reason'       => $volatile_reason,
            'ttl_upgraded'          => 0,
            'tool_inputs_repaired'  => 0,
            'json_objects_restored' => 0,
            // État du saut de la boucle AI Tools de MxChat (step, calls_made,
            // remaining, write, read), cf. Models::loop_hop_cacheability() ;
            // null hors de cette boucle.
            'loop'                  => null,
            // prev_user / last_user : paire glissante (lecture / écriture). Sur
            // le chemin MxChat, ce sont les messages bruts du visiteur u_{N-1} /
            // u_N, jamais le bloc de contexte ; dans la boucle AI Tools, le
            // contexte ou tool_result précédent / le dernier tool_result.
            // context_block : bloc de contexte du premier saut AI Tools (5 min).
            'breakpoints'           => [
                'tools'         => false,
                'system'        => false,
                'prev_user'     => false,
                'last_user'     => false,
                'context_block' => false,
            ],
        ];

        // --- 1) Tools : breakpoint sur le dernier outil si volumineux et non déjà caché.
        // Les tools sont rendus AVANT system dans le préfixe : c'est le bloc le plus
        // stable. Le marqueur system de l'hôte couvre déjà tools + system : le
        // doubler ne cacherait rien de plus et prendrait l'une des 4 places à un
        // breakpoint d'historique (boucle AI Tools). On ne marque donc les tools que
        // là où ce marqueur ne suffit pas : system non marqué par l'hôte ({context}),
        // ou volatile ({current_datetime}, filtre), dont le préfixe change sans eux.
        $host_marks_system = is_array($payload['system'] ?? null)
            && MxChat_Plus_PromptCache_Models::blocks_have_cache_control($payload['system']);
        if (
            $used < MXCHAT_PLUS_PC_MAX_BREAKPOINTS
            && $has_tools
            && (!$host_marks_system || $volatile_system)
            && !MxChat_Plus_PromptCache_Models::blocks_have_cache_control($payload['tools'])
            && MxChat_Plus_PromptCache_Models::tools_size($payload['tools'], $min_chars) >= $min_chars
        ) {
            $last = count($payload['tools']) - 1;
            if (isset($payload['tools'][$last]) && is_array($payload['tools'][$last])) {
                $payload['tools'][$last]['cache_control'] = $control;
                $mutated = true;
                $used++;
                $debug['breakpoints']['tools'] = true;
            }
        }

        // --- 2) System : indépendant de l'historique (un breakpoint déjà présent
        //                 sur les messages ne doit PAS bloquer la mise en cache du system).
        //                 Jamais sur un system volatile : MxChat s'en abstient
        //                 exprès pour `{context}`, et un system `{current_datetime}`
        //                 ne vit qu'une minute.
        if ($used < MXCHAT_PLUS_PC_MAX_BREAKPOINTS && !$volatile_system && !empty($payload['system'])) {
            $system_size = MxChat_Plus_PromptCache_Models::system_size($payload['system']);
            if ($system_size >= $min_chars) {
                if (is_string($payload['system'])) {
                    $payload['system'] = [[
                        'type'          => 'text',
                        'text'          => $payload['system'],
                        'cache_control' => $control,
                    ]];
                    $mutated = true;
                    $used++;
                    $debug['breakpoints']['system'] = true;
                } elseif (is_array($payload['system'])
                    && !MxChat_Plus_PromptCache_Models::blocks_have_cache_control($payload['system'])
                ) {
                    $last = count($payload['system']) - 1;
                    if (isset($payload['system'][$last]) && is_array($payload['system'][$last])) {
                        $payload['system'][$last]['cache_control'] = $control;
                        $mutated = true;
                        $used++;
                        $debug['breakpoints']['system'] = true;
                    }
                }
            }
        }

        // --- 3) Historique, selon la forme du payload (cf. Models::analyze_host_messages()
        // et Models::history_breakpoint_plan()).
        //
        // MxChat termine chaque requête de chat par un bloc de contexte (ligne de
        // date, "User asked: '…'", base de connaissances) qu'il ne renvoie JAMAIS
        // au tour suivant : il n'est pas enregistré dans la transcription
        // (MxChat_Integrator::mxchat_handle_chat_request(), MxChat 3.2.21 → 3.2.25).
        // Le marquer, comme le faisait la paire « deux derniers user », payait la
        // prime d'écriture 1 h sur des milliers de tokens à chaque tour, pour une
        // entrée jamais relue. La paire glissante va donc sur les messages bruts
        // du visiteur qui le précèdent : l'écriture d'un tour (u_N) est la lecture
        // du suivant.
        /**
         * Filtre le nombre minimum de messages avant d'activer le cache historique.
         * Défaut 3 (user, assistant, user), compté sans la note d'en-tête ni le
         * bloc de contexte de MxChat : évite la prime d'écriture sur les chats
         * one-shot jamais relus. Baisser à 1 pour cacher dès le 1er tour (sessions
         * longues), au prix d'écritures parfois inutiles. Ne concerne pas les
         * breakpoints posés dans la boucle AI Tools, qui servent le tour en cours.
         *
         * @param int   $min     Défaut MXCHAT_PLUS_PC_MIN_MESSAGES.
         * @param array $payload Payload décodé.
         */
        $min_messages = (int) apply_filters('mxchat_plus_promptcache_min_messages', MXCHAT_PLUS_PC_MIN_MESSAGES, $payload);

        // Un system volatile précède l'historique dans le préfixe : d'un tour à
        // l'autre, l'historique change donc lui aussi, et les breakpoints
        // inter-tours (u_N / u_{N-1}, TTL du module) sont retirés. Pas ceux de
        // la boucle AI Tools (5 min) : MxChat_Integrator::mxchat_fc_attempt()
        // (MxChat 3.2.25) calcule le system UNE fois par tour, et
        // mxchat_fc_loop_anthropic() renvoie la même chaîne à chaque saut, si
        // bien que le saut suivant relit ce que celui-ci écrit.
        if (
            $used < MXCHAT_PLUS_PC_MAX_BREAKPOINTS
            && !empty($payload['messages'])
            && is_array($payload['messages'])
        ) {
            $already_cached = false;
            foreach ($payload['messages'] as $msg) {
                if (is_array($msg) && isset($msg['content']) && is_array($msg['content'])) {
                    if (MxChat_Plus_PromptCache_Models::blocks_have_cache_control($msg['content'])) {
                        $already_cached = true;
                        break;
                    }
                }
            }

            if (!$already_cached) {
                // Boucle AI Tools de MxChat (bloc de contexte reconnu) : une
                // écriture n'est relue que si le saut suivant offre encore
                // `tools` avec le même `tool_choice`. Une boucle d'un autre
                // plugin garde la paire complète.
                $loop = null;
                if (($layout['shape'] === MxChat_Plus_PromptCache_Models::SHAPE_FC_FIRST_HOP
                        || $layout['shape'] === MxChat_Plus_PromptCache_Models::SHAPE_FC_LOOP)
                    && $layout['context'] !== null
                ) {
                    $loop = MxChat_Plus_PromptCache_Models::loop_hop_cacheability(
                        $payload['messages'],
                        $payload['tool_choice'] ?? null,
                        MxChat_Plus_Host::tool_loop_profile()
                    );
                    $debug['loop'] = $loop;
                }
                $loop_control = MxChat_Plus_PromptCache_Models::loop_control($control);
                $plan         = MxChat_Plus_PromptCache_Models::history_breakpoint_plan($payload['messages'], $layout, $min_messages, $loop);
                if ($volatile_system) {
                    $plan = array_values(array_filter($plan, static fn(array $mark): bool => $mark['loop']));
                }
                foreach ($plan as $mark) {
                    if ($used >= MXCHAT_PLUS_PC_MAX_BREAKPOINTS) {
                        break;
                    }
                    $idx = $mark['index'];
                    if (isset($payload['messages'][$idx]['content'])
                        && MxChat_Plus_PromptCache_Models::add_cache_control_to_content(
                            $payload['messages'][$idx]['content'],
                            $mark['loop'] ? $loop_control : $control
                        )
                    ) {
                        $mutated = true;
                        $used++;
                        $debug['breakpoints'][$mark['slot']] = true;
                    }
                }
            }
        }

        // --- 4) TTL. MxChat 3.2.21+ marque son system en 5 min ; nos breakpoints
        // inter-tours sont en 1 h par défaut. Anthropic exige qu'un breakpoint 1 h
        // ne suive jamais un breakpoint 5 min. On aligne d'abord les marqueurs de
        // l'hôte sur tools/system à la TTL du module (le prompt système, commun à
        // tous les visiteurs, est le préfixe qui gagne le plus à durer) — sauf sur
        // un system volatile, dont le marqueur 5 min couvre déjà toute la vie
        // utile —, puis on vérifie l'ordre sur toute la requête, marqueurs tiers
        // compris. Les breakpoints de boucle (5 min) viennent après les 1 h :
        // ordre valide, enforce_ttl_order() les laisse tels quels.
        $ttl_upgraded = MxChat_Plus_PromptCache_Models::align_prefix_ttl($payload, $control, !$volatile_system);
        $ttl_upgraded += MxChat_Plus_PromptCache_Models::enforce_ttl_order($payload);
        if ($ttl_upgraded > 0) {
            $mutated = true;
        }
        $debug['ttl_upgraded'] = $ttl_upgraded;

        // --- 5) tool_use.input arrivé en [] (décodage de l'hôte) → {}.
        $repaired = MxChat_Plus_PromptCache_Models::repair_tool_use_inputs($payload);
        if ($repaired > 0) {
            $mutated = true;
        }
        $debug['tool_inputs_repaired'] = $repaired;

        $extended = MxChat_Plus_PromptCache_Models::payload_uses_extended_ttl($payload);

        if ($mutated) {
            // Le décodage en tableaux a transformé les objets vides `{}` du body
            // en `[]` : on les remet en objets avant de ré-encoder, sans quoi un
            // outil AI Tools sans argument serait rejeté par Anthropic.
            $debug['json_objects_restored'] = MxChat_Plus_PromptCache_Models::restore_json_objects(
                $payload,
                MxChat_Plus_PromptCache_Models::json_object_paths(json_decode($args['body'], false))
            );
        }

        MxChat_Plus_PromptCache_Stats::set_debug($debug);

        if (!$mutated) {
            return $args;
        }

        // Robustesse (a) : wp_json_encode() renvoie false si le payload contient de
        // l'UTF-8 invalide (données utilisateur mal encodées remontées par l'hôte).
        // Ré-encoder AVANT toute autre mutation garantit qu'on ne part jamais avec
        // un body vide : on rend $args tel quel.
        $encoded = wp_json_encode($payload);
        if (!is_string($encoded)) {
            return $args;
        }

        // Ancien header beta de la TTL 1 h. Anthropic ne l'exige plus depuis le
        // 13 août 2025 : on l'envoie encore par compatibilité (comptes ou proxys
        // qui le demandaient), seulement quand il s'ajoute sans risque, et son
        // absence n'annule plus jamais la mutation du body. Décidé sur le payload
        // FINAL, pas sur la seule TTL du module : enforce_ttl_order() peut
        // produire du 1 h même en mode 5 min.
        /**
         * Envoyer l'ancien header `anthropic-beta: extended-cache-ttl-2025-04-11`
         * quand la requête porte un breakpoint 1 h ? Vrai par défaut.
         *
         * @param bool  $send true par défaut.
         * @param array $args Arguments HTTP WordPress.
         */
        if ($extended && (bool) apply_filters('mxchat_plus_promptcache_send_extended_ttl_header', true, $args)) {
            $headers = self::with_extended_ttl_header($args['headers'] ?? []);
            if ($headers !== null) {
                $args['headers'] = $headers;
            }
        }

        $args['body'] = $encoded;
        return $args;
    }

    /**
     * Ajoute MXCHAT_PLUS_PC_EXTENDED_TTL_HEADER à la liste des headers.
     *
     * Robustesse (b) : ne JAMAIS écraser une valeur non-tableau — WP_Http accepte
     * aussi un bloc de headers bruts sous forme de chaîne, et l'écraser détruirait
     * le `x-api-key` du plugin hôte (⇒ 401 sur tous les appels Anthropic).
     * Une chaîne est donc convertie ligne à ligne ; tout autre type fait renoncer
     * au header (pas au body : la TTL 1 h ne dépend plus du header).
     *
     * Robustesse (c) : les en-têtes HTTP sont insensibles à la casse. La lecture
     * passe par array_change_key_case() et la réécriture cible la clé réellement
     * présente, pour ne pas créer un doublon `Anthropic-Beta` + `anthropic-beta`.
     *
     * @param mixed $raw_headers Valeur courante de $args['headers'].
     * @return array<string,mixed>|null null = impossible d'ajouter sans risque.
     */
    private static function with_extended_ttl_header(mixed $raw_headers): ?array {
        if ($raw_headers === null || $raw_headers === '') {
            $headers = [];
        } elseif (is_array($raw_headers)) {
            $headers = $raw_headers;
        } elseif (is_string($raw_headers)) {
            // Bloc brut "Key: value\r\n…" → tableau associatif (clés minuscules,
            // forme canonique côté WP_Http). Aucune donnée n'est perdue.
            $headers = [];
            foreach (preg_split("/\r\n|\n|\r/", $raw_headers) ?: [] as $line) {
                if (!is_string($line) || !str_contains($line, ':')) {
                    continue;
                }
                [$key, $value] = explode(':', $line, 2);
                $key = strtolower(trim($key));
                if ($key !== '') {
                    $headers[$key] = trim($value);
                }
            }
        } else {
            return null; // type inattendu : on s'abstient plutôt que de détruire.
        }

        $lower        = array_change_key_case($headers, CASE_LOWER);
        $existing_raw = $lower['anthropic-beta'] ?? '';
        $existing     = match (true) {
            is_string($existing_raw) => $existing_raw,
            is_array($existing_raw)  => implode(',', array_filter($existing_raw, 'is_string')),
            default                  => '',
        };

        if (str_contains($existing, MXCHAT_PLUS_PC_EXTENDED_TTL_HEADER)) {
            return $headers;
        }

        // Réécrire sur la clé existante (quelle que soit sa casse) sinon en créer une.
        $target = 'anthropic-beta';
        foreach (array_keys($headers) as $key) {
            if (is_string($key) && strtolower($key) === 'anthropic-beta') {
                $target = $key;
                break;
            }
        }
        $headers[$target] = $existing === ''
            ? MXCHAT_PLUS_PC_EXTENDED_TTL_HEADER
            : $existing . ',' . MXCHAT_PLUS_PC_EXTENDED_TTL_HEADER;

        return $headers;
    }

    // ──────────────────────────────────────────────────────────────────
    //  2) Mesure : fenêtre glissante 24 h + cumulatif, par modèle
    // ──────────────────────────────────────────────────────────────────

    /**
     * Accumule les stats. Injection = Anthropic uniquement ; mesure = tous les
     * providers supportés (cache automatique inclus).
     *
     * @param mixed $response Réponse HTTP WP (array) ou WP_Error.
     * @param mixed $args     Arguments de la requête (body lu pour distinguer
     *                        l'appel file_search de la Responses API).
     * @param mixed $url      URL appelée.
     * @return mixed La réponse, inchangée.
     */
    public static function record_metrics(mixed $response, mixed $args, mixed $url): mixed {
        $endpoint = MxChat_Plus_PromptCache_Stats::identify_endpoint($url);
        if ($endpoint === null) {
            return $response;
        }
        if (is_wp_error($response)) {
            return $response;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return $response;
        }

        $usage = MxChat_Plus_PromptCache_Stats::parse_usage($endpoint, $data);
        if ($usage === null) {
            return $response;
        }

        $model          = $usage['model'] . MxChat_Plus_PromptCache_Stats::request_label($endpoint, $args);
        $cache_creation = $usage['cache_creation'];
        $cache_read     = $usage['cache_read'];
        $input          = $usage['input'];

        MxChat_Plus_PromptCache_Stats::record_sample($model, $input, $cache_creation, $cache_read);

        // --- Enrichit le debug de la dernière requête (best-effort, Anthropic uniquement :
        //     le transient debug n'est écrit que par l'injection, réservée à Anthropic).
        if (($endpoint['style'] ?? '') === 'anthropic') {
            $debug = MxChat_Plus_PromptCache_Stats::get_debug();
            if ($debug !== null) {
                $debug['response_model'] = $model;
                $debug['usage'] = [
                    'input_tokens'                => $input,
                    'cache_creation_input_tokens' => $cache_creation,
                    'cache_read_input_tokens'     => $cache_read,
                ];
                MxChat_Plus_PromptCache_Stats::set_debug($debug);
            }
        }

        return $response;
    }
}
