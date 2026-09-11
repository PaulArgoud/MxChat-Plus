<?php
/** @var array<string, mixed> $opts */
if (!defined('ABSPATH')) { exit; }
?>
<h2><?php esc_html_e('Retrieval quality', 'mxchat-plus'); ?></h2>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php esc_html_e('Hybrid BM25 + vector', 'mxchat-plus'); ?></th>
        <td>
            <label>
                <input type="checkbox" name="<?php echo esc_attr(MXCHAT_PLUS_DUCKDB_OPTION_KEY); ?>[hybrid_enabled]" value="1" <?php checked(!empty($opts['hybrid_enabled'])); ?>>
                <?php esc_html_e('Blend BM25 full-text scores with vector similarity (requires the DuckDB FTS extension and a populated `mxchat_plus_duckdb_query_text` filter).', 'mxchat-plus'); ?>
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="hybrid_alpha"><?php esc_html_e('Hybrid α (vector weight)', 'mxchat-plus'); ?></label></th>
        <td>
            <input type="number" id="hybrid_alpha" class="small-text"
                   name="<?php echo esc_attr(MXCHAT_PLUS_DUCKDB_OPTION_KEY); ?>[hybrid_alpha]"
                   min="0" max="1" step="0.05" value="<?php echo esc_attr((float) ($opts['hybrid_alpha'] ?? 0.7)); ?>">
            <p class="description"><?php esc_html_e('1.0 = pure vector, 0.0 = pure BM25. 0.7 is a sensible default for factual KBs.', 'mxchat-plus'); ?></p>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Dedup per source', 'mxchat-plus'); ?></th>
        <td>
            <label>
                <input type="checkbox" name="<?php echo esc_attr(MXCHAT_PLUS_DUCKDB_OPTION_KEY); ?>[dedup_per_source]" value="1" <?php checked(!empty($opts['dedup_per_source'])); ?>>
                <?php esc_html_e('Keep only the highest-scoring chunk per source_url in the top-K (avoids passing N near-duplicate chunks from the same article to the LLM).', 'mxchat-plus'); ?>
            </label>
        </td>
    </tr>
</table>
