<?php
/**
 * INT8 quantization helpers (experimental).
 *
 * Cuts vector storage 4× by storing each component as a signed byte
 * (TINYINT, range [-128, 127]) instead of a 32-bit float. For embeddings
 * that are roughly L2-normalised to unit length (the default for OpenAI
 * ada-002, text-embedding-3-*, Voyage, BGE, etc.), a fixed scale of 127 is
 * a safe choice — recall loss on typical RAG workloads is < 1 %.
 *
 * Why a fixed scale rather than per-vector scale:
 *   - Per-vector scale costs an extra FLOAT column → halves the storage win.
 *   - Most production embedding models output unit-normalised vectors, so
 *     |v_i| ≤ 1 and the fixed scale never clips.
 *   - The user can always export to Parquet, requantize externally, and
 *     re-import if they need a different scheme.
 *
 * The scale of 127 (not 128) is deliberate: round-tripping the value 1.0
 * lands exactly on 127, and -1.0 lands on -127, leaving -128 unused. This
 * keeps quantize / dequantize numerically symmetric.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Plus_DuckDB_Quantization {

    const SCALE = 127;

    /**
     * @param float[] $vector
     * @return int[] components in [-128, 127]
     */
    public static function quantize_int8(array $vector): array {
        $out = [];
        foreach ($vector as $v) {
            if (!is_int($v) && !is_float($v) && !is_numeric($v)) {
                throw new RuntimeException(
                    __('Non-numeric value during INT8 quantization.', 'mxchat-plus')
                );
            }
            $f = (float) $v;
            $q = (int) round($f * self::SCALE);
            if ($q > 127) $q = 127;
            elseif ($q < -128) $q = -128;
            $out[] = $q;
        }
        return $out;
    }

    /**
     * @param int[] $quantized
     * @return float[] approximate floats in roughly [-1, 1]
     */
    public static function dequantize_int8(array $quantized): array {
        // Cast SCALE to float so PHP's `/` operator doesn't return an int when
        // the division happens to be exact (0/127, 127/127, -127/127). This
        // matters because downstream code and tests assertSame against floats.
        $scale = (float) self::SCALE;
        $out = [];
        foreach ($quantized as $q) {
            $out[] = (int) $q / $scale;
        }
        return $out;
    }

    /**
     * Returns the SQL fragment that converts the stored TINYINT[N] embedding
     * column into a FLOAT[N] suitable for `array_cosine_similarity`. Cached
     * here so both the score builder and the rerank paths use the same SQL.
     */
    public static function sql_dequantize_expression(int $dim): string {
        // list_transform handles the per-element divide; the explicit cast to
        // FLOAT[N] is what DuckDB needs to feed it into array_cosine_similarity.
        return sprintf(
            'CAST(list_transform(embedding, x -> CAST(x AS FLOAT) / %d.0) AS FLOAT[%d])',
            self::SCALE,
            $dim
        );
    }
}
