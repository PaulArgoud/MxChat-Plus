<?php
/**
 * @var array<string, mixed> $opts
 * @var int    $detected_dim
 * @var string $active_embedding_model    model of the vectors MxChat already stored
 * @var string $selected_embedding_model  model MxChat's next embed will use
 * @var int    $selected_embedding_dim    its dimension, 0 when unknown
 * @var bool   $embedding_model_is_custom true when the selection is a Custom Provider
 */
if (!defined('ABSPATH')) { exit; }
?>
<h2><?php esc_html_e('Vector schema', 'mxchat-plus'); ?></h2>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><label for="embedding_dim"><?php esc_html_e('Embedding dimension', 'mxchat-plus'); ?></label></th>
        <td>
            <input type="number" id="embedding_dim" class="small-text"
                   name="<?php echo esc_attr(MXCHAT_PLUS_DUCKDB_OPTION_KEY); ?>[embedding_dim]"
                   min="1" max="4096" value="<?php echo esc_attr((int) ($opts['embedding_dim'] ?? 1536)); ?>">
            <p class="description">
                <?php
                printf(
                    /* translators: %d = detected dimension */
                    esc_html__('MxChat is currently using dimension %d. This must match the active embedding model.', 'mxchat-plus'),
                    (int) $detected_dim
                );
                ?>
            </p>
            <?php
            // The stamp describes the vectors already stored, the selection
            // the ones to come: both are shown, never swapped silently.
            $selected_model = isset($selected_embedding_model) ? (string) $selected_embedding_model : '';
            $active_model   = isset($active_embedding_model) ? (string) $active_embedding_model : '';
            if ($selected_model !== '' && $active_model !== '' && $selected_model !== $active_model): ?>
                <p class="description" style="margin-top:6px;color:#996800;">
                    <?php
                    if (!empty($selected_embedding_dim)) {
                        printf(
                            /* translators: 1: model MxChat will embed with next, 2: its dimension, 3: model of the stored vectors */
                            esc_html__('MxChat\'s embedding setting now selects %1$s (%2$d dimensions), but its stored vectors were made with %3$s. New content will be embedded with %1$s, so the dimension here must match it: wipe the table and re-sync after switching.', 'mxchat-plus'),
                            esc_html($selected_model),
                            (int) $selected_embedding_dim,
                            esc_html($active_model)
                        );
                    } else {
                        printf(
                            /* translators: 1: model MxChat will embed with next, 2: model of the stored vectors */
                            esc_html__('MxChat\'s embedding setting now selects %1$s, but its stored vectors were made with %2$s. Run Detect dimension to read the new model\'s size, then wipe the table and re-sync.', 'mxchat-plus'),
                            esc_html($selected_model),
                            esc_html($active_model)
                        );
                    }
                    ?>
                </p>
            <?php endif; ?>
            <?php if (!empty($embedding_model_is_custom)): ?>
                <p class="description" style="margin-top:6px;">
                    <strong><?php esc_html_e('Custom / Azure OpenAI embeddings detected.', 'mxchat-plus'); ?></strong>
                    <?php esc_html_e(
                        'MxChat is embedding through a custom provider, whose output dimension is not in any registry: until this plugin or MxChat has probed the endpoint, the number above is a placeholder. Probe your endpoint to fill in the real value, then Save.',
                        'mxchat-plus'
                    ); ?>
                    <br>
                    <button type="button" class="button button-secondary" id="mxchat-plus-detect-dim" style="margin-top:6px;">
                        <?php esc_html_e('Detect dimension', 'mxchat-plus'); ?>
                    </button>
                    <span id="mxchat-plus-detect-dim-status" style="margin-left:8px;"></span>
                </p>
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="distance_metric"><?php esc_html_e('Metric', 'mxchat-plus'); ?></label></th>
        <td>
            <select id="distance_metric" name="<?php echo esc_attr(MXCHAT_PLUS_DUCKDB_OPTION_KEY); ?>[distance_metric]">
                <option value="cosine" <?php selected($opts['distance_metric'] ?? '', 'cosine'); ?>>cosine</option>
                <option value="l2sq" <?php selected($opts['distance_metric'] ?? '', 'l2sq'); ?>>l2sq</option>
                <option value="ip" <?php selected($opts['distance_metric'] ?? '', 'ip'); ?>>inner product</option>
            </select>
            <p class="description"><?php esc_html_e('MxChat uses cosine similarity — keep cosine unless you have a specific reason.', 'mxchat-plus'); ?></p>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('HNSW index', 'mxchat-plus'); ?></th>
        <td>
            <label>
                <input type="checkbox" name="<?php echo esc_attr(MXCHAT_PLUS_DUCKDB_OPTION_KEY); ?>[hnsw_enabled]" value="1" <?php checked(!empty($opts['hnsw_enabled'])); ?>>
                <?php esc_html_e('Create an HNSW index over the embedding column (recommended for > 10k entries)', 'mxchat-plus'); ?>
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="top_k"><?php esc_html_e('Default top-K', 'mxchat-plus'); ?></label></th>
        <td>
            <input type="number" id="top_k" class="small-text"
                   name="<?php echo esc_attr(MXCHAT_PLUS_DUCKDB_OPTION_KEY); ?>[top_k]"
                   min="1" max="1000" value="<?php echo esc_attr((int) ($opts['top_k'] ?? 50)); ?>">
        </td>
    </tr>
</table>
