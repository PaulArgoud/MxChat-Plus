<?php
/**
 * Prompt Cache module — routine de nettoyage à la désinstallation.
 *
 * Supprime les données persistées par le module pour LE SITE COURANT :
 *   - option    mxchat_plus_promptcache_store       (buckets 24 h + cumulatif)
 *   - transient mxchat_plus_promptcache_last_debug  (dernière requête interceptée)
 *
 * Le plugin n'est pas chargé au moment de la désinstallation : on utilise les
 * chaînes littérales des clés, jamais les constantes du module.
 *
 * Ce fichier ne fait que DÉCLARER la routine. L'entrypoint (boucle multisite,
 * switch_to_blog/restore_current_blog) vit dans uninstall.php à la racine, car
 * WordPress ne lit qu'un seul uninstall.php par dossier de plugin.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Nettoyage par site. Appelé une fois en single-site, une fois par blog en multisite.
 */
function mxchat_plus_promptcache_uninstall_cleanup_site(): void {
    delete_option('mxchat_plus_promptcache_store');
    delete_transient('mxchat_plus_promptcache_last_debug');
}
