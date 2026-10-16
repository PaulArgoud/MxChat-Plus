<?php
/**
 * Prompt Cache module — constants.
 *
 * Minimums de préfixe cacheable, par PALIER de tokens (≈4 caractères/token).
 * Anthropic ignore SILENCIEUSEMENT cache_control si le préfixe est trop court :
 * aucune erreur, juste cache_creation_input_tokens=0. D'où l'importance des seuils.
 *
 * IMPORTANT : le minimum n'est PAS constant par famille — il varie par version
 * (ex. Opus 5 = 512, Opus 4.8 = 1024 mais Opus 4.6 = 4096 ; Sonnet 5.5 = 512 mais
 * Sonnet 5 = 1024). Source : docs Anthropic « prompt caching » (vérifié
 * 2026-10-16). Les modèles récents (Opus 4.7+, génération 5) utilisent un
 * tokenizer plus dense → le seuil en caractères est légèrement conservateur.
 * Un seuil trop BAS est sans risque (no-op silencieux côté Anthropic) ; un seuil
 * trop HAUT, lui, prive le bloc de cache sans aucun signal.
 *
 * Pourquoi define() et non const : le module doit pouvoir être chargé
 * conditionnellement (`if ($enabled) { require …; }`). PHP interdit `const` dans
 * un bloc conditionnel ; les gardes `defined()` rendent aussi le fichier
 * idempotent (double require, suite de tests, wp-config qui pré-définit une clé).
 */

if (!defined('ABSPATH')) {
    exit;
}

// ── Seuils par palier de tokens ─────────────────────────────────────────
if (!defined('MXCHAT_PLUS_PC_MIN_CHARS_512')) {
    define('MXCHAT_PLUS_PC_MIN_CHARS_512', 2050);   // 512 tokens  : Fable 5/5.1, Mythos 5/5.1, Opus 5/5.5, Sonnet 5.5, Haiku 5.5
}
if (!defined('MXCHAT_PLUS_PC_MIN_CHARS_1024')) {
    define('MXCHAT_PLUS_PC_MIN_CHARS_1024', 4000);  // 1024 tokens : Opus 4.8/4.1/4, Sonnet 5/4.6/4.5/4/3.x
}
if (!defined('MXCHAT_PLUS_PC_MIN_CHARS_2048')) {
    define('MXCHAT_PLUS_PC_MIN_CHARS_2048', 8200);  // 2048 tokens : Opus 4.7, Mythos Preview, Haiku 3.x
}
if (!defined('MXCHAT_PLUS_PC_MIN_CHARS_4096')) {
    define('MXCHAT_PLUS_PC_MIN_CHARS_4096', 16400); // 4096 tokens : Opus 4.6/4.5, Haiku 4.5
}
// NE PAS fusionner avec _4096 malgré la valeur identique : ce sont deux notions
// distinctes (palier 4096 tokens vs défaut conservateur pour modèle inconnu).
// Si Anthropic change l'un des deux, l'autre ne doit pas suivre mécaniquement.
if (!defined('MXCHAT_PLUS_PC_MIN_CHARS_DEFAULT')) {
    define('MXCHAT_PLUS_PC_MIN_CHARS_DEFAULT', 16400); // défaut sûr (modèle inconnu)
}

// ── Divers ──────────────────────────────────────────────────────────────
if (!defined('MXCHAT_PLUS_PC_EXTENDED_TTL_HEADER')) {
    // Ancien header beta de la TTL 1 h. Anthropic n'en a plus besoin depuis le
    // 13 août 2025 (la TTL 1 h est passée en disponibilité générale) : il est
    // encore envoyé, par compatibilité avec les comptes et proxys qui l'exigeaient,
    // mais son absence n'empêche plus jamais la mutation (cf.
    // MxChat_Plus_PromptCache::inject_cache_control()).
    define('MXCHAT_PLUS_PC_EXTENDED_TTL_HEADER', 'extended-cache-ttl-2025-04-11');
}
if (!defined('MXCHAT_PLUS_PC_STORE_KEY')) {
    // Option unique, non-autoloadée : { buckets:{h=>bucket}, total:{…, since} }.
    define('MXCHAT_PLUS_PC_STORE_KEY', 'mxchat_plus_promptcache_store');
}
if (!defined('MXCHAT_PLUS_PC_DEBUG_KEY')) {
    // Transient 1 h : détail de la dernière requête interceptée.
    define('MXCHAT_PLUS_PC_DEBUG_KEY', 'mxchat_plus_promptcache_last_debug');
}
if (!defined('MXCHAT_PLUS_PC_MAX_BREAKPOINTS')) {
    define('MXCHAT_PLUS_PC_MAX_BREAKPOINTS', 4);
}
if (!defined('MXCHAT_PLUS_PC_MIN_MESSAGES')) {
    // (user, assistant, user) min — défaut filtrable via mxchat_plus_promptcache_min_messages.
    // Compté SANS la note d'en-tête de l'hôte ni son bloc de contexte final
    // (cf. MXCHAT_PLUS_PC_HOST_HISTORY_NOTE / MXCHAT_PLUS_PC_HOST_CONTEXT_MARKER) :
    // sinon le premier tour [note, u1, contexte] atteint déjà 3 et le seuil ne
    // protège plus aucun chat d'un seul message.
    define('MXCHAT_PLUS_PC_MIN_MESSAGES', 3);
}
if (!defined('MXCHAT_PLUS_PC_HOST_CONTEXT_MARKER')) {
    // Littéral de l'HÔTE, non traduit : ouverture du bloc de contexte que
    // MxChat_Integrator::mxchat_handle_chat_request() (MxChat 3.2.21 → 3.2.25)
    // assemble à chaque tour (`"User asked: '{$message}'"` + base de
    // connaissances + URLs approuvées + page) puis ajoute en DERNIER message
    // user. Ce bloc n'est jamais enregistré dans la transcription : le tour
    // suivant ne le renvoie pas, et un breakpoint posé dessus paie une prime
    // d'écriture jamais relue.
    //
    // Cherché avec str_contains(), jamais en préfixe : depuis 3.2.22 la ligne
    // de date filtrable (mxchat_current_datetime_line) le précède, et le filtre
    // mxchat_prepare_context peut réécrire le bloc. Ne pas renommer, ne pas
    // traduire. Cf. MxChat_Plus_PromptCache_Models::analyze_host_messages().
    define('MXCHAT_PLUS_PC_HOST_CONTEXT_MARKER', "User asked: '");
}
if (!defined('MXCHAT_PLUS_PC_HOST_HISTORY_NOTE')) {
    // Littéral de l'HÔTE : note que mxchat_fetch_conversation_history_for_ai()
    // (MxChat 3.2.25) place en tête de l'historique avec role=system, et que
    // les handlers Claude convertissent en role=user. Identique à chaque tour,
    // elle n'est pas un message du visiteur : ni compté dans
    // MXCHAT_PLUS_PC_MIN_MESSAGES, ni choisi comme breakpoint d'historique.
    define('MXCHAT_PLUS_PC_HOST_HISTORY_NOTE', 'Preserved code blocks are marked with [CODE BLOCK PRESERVED].');
}
if (!defined('MXCHAT_PLUS_PC_OBSERVED_DATETIMES_MAX')) {
    // Valeurs {current_datetime} retenues par requête PHP (cf.
    // MxChat_Plus_PromptCache::observe_system_instructions()). Borne un
    // processus long (WP-CLI, worker) qui verrait passer beaucoup de minutes.
    define('MXCHAT_PLUS_PC_OBSERVED_DATETIMES_MAX', 8);
}
if (!defined('MXCHAT_PLUS_PC_HOST_KB_MARKERS')) {
    // En-têtes du bloc « base de connaissances » que MxChat assemble à chaque
    // question (class-mxchat-integrator.php, $kb_block). Littéraux de l'HÔTE :
    // les recopier tels quels, délimiteurs compris — cf.
    // MxChat_Plus_PromptCache_Models::system_has_per_query_content().
    define('MXCHAT_PLUS_PC_HOST_KB_MARKERS', [
        '===== OFFICIAL KNOWLEDGE DATABASE CONTENT =====',
        '===== NO RELEVANT CONTENT FOUND IN KNOWLEDGE DATABASE =====',
    ]);
}
