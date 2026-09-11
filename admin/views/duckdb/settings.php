<?php
/**
 * Settings page shell. Each <h2> section lives in its own partial under
 * partials/ so contributors can edit one section without scrolling past
 * 350+ lines of unrelated form HTML.
 *
 * Variables in scope (set by class-duckdb-admin.php):
 *   $opts          (array)   plugin options
 *   $proxy_token   (string)  Pinecone-proxy auth token (read-only)
 *   $proxy_host    (string)  Pinecone-proxy host fragment
 *   $detected_dim  (int)     detected embedding dimension from mxchat
 *   $active_embedding_model     (string) model mxchat is embedding with
 *   $embedding_model_is_custom  (bool)   true for a Custom/Azure provider
 *   $known_bots                 (string[]) bot ids for the per-bot overrides UI
 */

if (!defined('ABSPATH')) {
    exit;
}

$partials = MXCHAT_PLUS_DIR . 'admin/views/duckdb/partials/';
?>
<div class="wrap">
    <h1><?php esc_html_e('MxChat — DuckDB / MotherDuck', 'mxchat-plus'); ?></h1>

    <p class="description">
        <?php esc_html_e(
            'Replaces Pinecone with DuckDB (embedded) or MotherDuck (cloud) as the vector store. The plugin registers itself with MxChat as an alternative Pinecone backend.',
            'mxchat-plus'
        ); ?>
    </p>

    <?php if (!empty($opts['last_error'])): ?>
        <div class="notice notice-warning">
            <p><strong><?php esc_html_e('Last error:', 'mxchat-plus'); ?></strong>
                <code><?php echo esc_html($opts['last_error']); ?></code></p>
        </div>
    <?php endif; ?>

    <?php
    $has_pecl = extension_loaded('duckdb');
    $is_motherduck = ($opts['mode'] ?? '') === 'motherduck';
    if (!$has_pecl): ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e('Performance:', 'mxchat-plus'); ?></strong>
                <?php esc_html_e(
                    'the PHP duckdb extension is not loaded. The plugin falls back to the duckdb CLI binary, which adds 50–200 ms of process-spawn latency per query.',
                    'mxchat-plus'
                ); ?>
                <?php if ($is_motherduck): ?>
                    <br>
                    <strong style="color:#a00;"><?php esc_html_e('MotherDuck + CLI mode:', 'mxchat-plus'); ?></strong>
                    <?php esc_html_e(
                        'each query re-runs the MotherDuck ATTACH (network handshake + token validation). Expect 1–3 s of overhead per call. For production, install the PECL duckdb extension.',
                        'mxchat-plus'
                    ); ?>
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <form method="post" action="options.php">
        <?php settings_fields(MxChat_Plus_DuckDB_Admin::MENU_SLUG); ?>

        <?php
        include $partials . 'section-activation.php';
        include $partials . 'section-motherduck.php';
        include $partials . 'section-embedded.php';
        include $partials . 'section-vector-schema.php';
        include $partials . 'section-retrieval-quality.php';
        include $partials . 'section-bot-overrides.php';
        include $partials . 'section-performance.php';
        ?>

        <?php submit_button(__('Save', 'mxchat-plus')); ?>
    </form>

    <hr>

    <?php include $partials . 'section-diagnostics.php'; ?>
</div>
