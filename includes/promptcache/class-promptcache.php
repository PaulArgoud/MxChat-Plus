<?php
/**
 * Prompt Cache module — cœur : injection Anthropic + mesure multi-provider.
 *
 * Deux greffons sur la pile HTTP de WordPress, aucun fichier du plugin hôte
 * (mxchat-basic) n'est modifié :
 *
 *   1. `http_request_args`  → INJECTION. Uniquement api.anthropic.com/v1/messages.
 *      Pose jusqu'à MXCHAT_PLUS_PC_MAX_BREAKPOINTS marqueurs cache_control
 *      (tools → system → avant-dernier user → dernier user) et ajoute le header
 *      beta requis par la TTL 1 h.
 *   2. `http_response`      → MESURE. Tous les providers reconnus (Anthropic,
 *      OpenAI/OpenRouter/xAI/DeepSeek, Gemini), dont le cache est automatique :
 *      on ne modifie rien, on lit juste les tokens cachés pour le tableau de bord.
 *
 * Filtres exposés aux intégrateurs :
 *   mxchat_plus_promptcache_should_inject      (bool,  $payload, $args, $url)
 *   mxchat_plus_promptcache_min_chars          (int,   $model, $payload)
 *   mxchat_plus_promptcache_min_messages       (int,   $payload)
 *   mxchat_plus_promptcache_ephemeral_control  (array) — cf. Models::ephemeral_control()
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_PromptCache {

    /** Accroche les deux greffons HTTP. Idempotent côté WordPress. */
    public static function register_hooks(): void {
        add_filter('http_request_args', [self::class, 'inject_cache_control'], 10, 2);
        add_filter('http_response', [self::class, 'record_metrics'], 10, 3);
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

        $mutated = false;
        // Compte les breakpoints déjà posés par l'hôte (MxChat 3.2.21+ marque
        // son bloc system) : le plafond de 4 d'Anthropic est global à la
        // requête, pas propre à ce plugin.
        $used    = MxChat_Plus_PromptCache_Models::count_existing_breakpoints($payload);
        $debug   = [
            'time'        => time(),
            'model'       => $model,
            'min_chars'   => $min_chars,
            'breakpoints' => [
                'tools'     => false,
                'system'    => false,
                'prev_user' => false,
                'last_user' => false,
            ],
        ];

        // --- 1) Tools : breakpoint sur le dernier outil si volumineux et non déjà caché.
        // Les tools sont rendus AVANT system dans le préfixe : c'est le bloc le plus stable.
        if (
            $used < MXCHAT_PLUS_PC_MAX_BREAKPOINTS
            && !empty($payload['tools'])
            && is_array($payload['tools'])
            && !MxChat_Plus_PromptCache_Models::blocks_have_cache_control($payload['tools'])
            && MxChat_Plus_PromptCache_Models::tools_size($payload['tools'], $min_chars) >= $min_chars
        ) {
            $last = count($payload['tools']) - 1;
            if (is_array($payload['tools'][$last])) {
                $payload['tools'][$last]['cache_control'] = MxChat_Plus_PromptCache_Models::ephemeral_control();
                $mutated = true;
                $used++;
                $debug['breakpoints']['tools'] = true;
            }
        }

        // --- 2) System : indépendant de l'historique (un breakpoint déjà présent
        //                 sur les messages ne doit PAS bloquer la mise en cache du system).
        if ($used < MXCHAT_PLUS_PC_MAX_BREAKPOINTS && !empty($payload['system'])) {
            $system_size = MxChat_Plus_PromptCache_Models::system_size($payload['system']);
            if ($system_size >= $min_chars) {
                if (is_string($payload['system'])) {
                    $payload['system'] = [[
                        'type'          => 'text',
                        'text'          => $payload['system'],
                        'cache_control' => MxChat_Plus_PromptCache_Models::ephemeral_control(),
                    ]];
                    $mutated = true;
                    $used++;
                    $debug['breakpoints']['system'] = true;
                } elseif (is_array($payload['system'])
                    && !MxChat_Plus_PromptCache_Models::blocks_have_cache_control($payload['system'])
                ) {
                    $last = count($payload['system']) - 1;
                    if (is_array($payload['system'][$last])) {
                        $payload['system'][$last]['cache_control'] = MxChat_Plus_PromptCache_Models::ephemeral_control();
                        $mutated = true;
                        $used++;
                        $debug['breakpoints']['system'] = true;
                    }
                }
            }
        }

        // --- 3) Historique : 2 breakpoints "rolling" sur les derniers messages user.
        // Le dernier user = écriture du cache. L'avant-dernier user = lecture au prochain tour
        // (il était le "dernier" lors de la requête précédente). Cette stratégie maintient
        // un hit rate stable même quand la conversation s'allonge.
        //
        // Seuil minimum de MXCHAT_PLUS_PC_MIN_MESSAGES messages : il faut au moins
        // (user, assistant, user) pour avoir un préfixe stable cachable + un point de lecture
        // potentiel au tour suivant.
        /**
         * Filtre le nombre minimum de messages avant d'activer le cache historique.
         * Défaut 3 (user, assistant, user) : évite la prime d'écriture sur les chats
         * one-shot jamais relus. Baisser à 1 pour cacher dès le 1er tour (sessions
         * longues), au prix d'écritures parfois inutiles.
         *
         * @param int   $min     Défaut MXCHAT_PLUS_PC_MIN_MESSAGES.
         * @param array $payload Payload décodé.
         */
        $min_messages = (int) apply_filters('mxchat_plus_promptcache_min_messages', MXCHAT_PLUS_PC_MIN_MESSAGES, $payload);

        if (
            $used < MXCHAT_PLUS_PC_MAX_BREAKPOINTS
            && !empty($payload['messages'])
            && is_array($payload['messages'])
            && count($payload['messages']) >= $min_messages
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
                $user_idx        = MxChat_Plus_PromptCache_Models::last_user_indexes($payload['messages'], 2);
                $slots_remaining = MXCHAT_PLUS_PC_MAX_BREAKPOINTS - $used;

                // Avant-dernier user d'abord (ordre logique du préfixe).
                if ($slots_remaining >= 2 && isset($user_idx[1])) {
                    $idx = $user_idx[1];
                    if (isset($payload['messages'][$idx]['content'])
                        && MxChat_Plus_PromptCache_Models::add_cache_control_to_content($payload['messages'][$idx]['content'])
                    ) {
                        $mutated = true;
                        $used++;
                        $debug['breakpoints']['prev_user'] = true;
                    }
                }
                // Dernier user (write breakpoint).
                if ($used < MXCHAT_PLUS_PC_MAX_BREAKPOINTS && isset($user_idx[0])) {
                    $idx = $user_idx[0];
                    if (isset($payload['messages'][$idx]['content'])
                        && MxChat_Plus_PromptCache_Models::add_cache_control_to_content($payload['messages'][$idx]['content'])
                    ) {
                        $mutated = true;
                        $used++;
                        $debug['breakpoints']['last_user'] = true;
                    }
                }
            }
        }

        MxChat_Plus_PromptCache_Stats::set_debug($debug);

        if (!$mutated) {
            return $args;
        }

        // Robustesse (a) : wp_json_encode() renvoie false si le payload contient de
        // l'UTF-8 invalide (données utilisateur mal encodées remontées par l'hôte).
        // Ré-encoder AVANT toute autre mutation garantit qu'on ne part jamais avec
        // un body vide ni un header beta orphelin : on rend $args tel quel.
        $encoded = wp_json_encode($payload);
        if (!is_string($encoded)) {
            return $args;
        }

        // Header beta requis pour la TTL 1 h. Si on ne peut pas l'ajouter sans
        // casser des headers existants, on renonce à TOUTE la mutation : envoyer
        // ttl=1h sans le header beta est une erreur API, pas une dégradation.
        if (MxChat_Plus_PromptCache_Models::using_extended_ttl()) {
            $headers = self::with_extended_ttl_header($args['headers'] ?? []);
            if ($headers === null) {
                return $args;
            }
            $args['headers'] = $headers;
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
     * Une chaîne est donc convertie ligne à ligne ; tout autre type fait renoncer.
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
     * @param mixed $args     Arguments de la requête (inutilisés, signature du filtre).
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

        $model          = $usage['model'];
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
