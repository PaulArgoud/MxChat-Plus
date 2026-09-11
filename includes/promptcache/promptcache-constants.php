<?php
/**
 * Prompt Cache module — constants.
 *
 * Minimums de préfixe cacheable, par PALIER de tokens (≈4 caractères/token).
 * Anthropic ignore SILENCIEUSEMENT cache_control si le préfixe est trop court :
 * aucune erreur, juste cache_creation_input_tokens=0. D'où l'importance des seuils.
 *
 * IMPORTANT : le minimum n'est PAS constant par famille — il varie par version
 * (ex. Opus 4.8 = 1024 mais Opus 4.6 = 4096 ; Sonnet 4.6 = 1024). Source : docs
 * Anthropic « prompt caching » (vérifié 2026-06-09). Les modèles récents (Opus
 * 4.7+, Fable, Mythos) utilisent un tokenizer plus dense → le seuil en caractères
 * est légèrement conservateur, ce qui est sans risque (un seuil trop bas est un
 * no-op silencieux côté Anthropic, jamais un surcoût).
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
    define('MXCHAT_PLUS_PC_MIN_CHARS_512', 2050);   // 512 tokens  : Fable 5, Mythos 5
}
if (!defined('MXCHAT_PLUS_PC_MIN_CHARS_1024')) {
    define('MXCHAT_PLUS_PC_MIN_CHARS_1024', 4000);  // 1024 tokens : Opus 4.8/4.1/4.0, Sonnet 4.6/4.5/4/3.x
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
    define('MXCHAT_PLUS_PC_MIN_MESSAGES', 3);
}
