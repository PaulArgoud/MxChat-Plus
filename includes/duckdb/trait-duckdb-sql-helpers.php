<?php
/**
 * Shared SQL primitives used by Vector_Store, Vector_Store_Schema and
 * Vector_Store_Query. Kept as a trait so each host class can use them on
 * its own promoted constructor properties without indirection.
 *
 * The trait expects the host to expose the following protected properties:
 *   - string  $table      DuckDB table name (sanitised on options save)
 *   - int     $dim        Embedding dimension
 *   - string  $storage    'float32' | 'int8'
 */

if (!defined('ABSPATH')) {
    exit;
}

trait MxChat_Plus_DuckDB_SQL_Helpers_Trait {

    /**
     * Double-quote a DuckDB identifier (table/column name).
     *
     * Rejects identifiers containing characters outside `[a-zA-Z0-9_]` rather
     * than silently stripping them: a typo like `"my-table"` previously became
     * `"mytable"` and queries would hit a different (possibly non-existent)
     * table without any error visible to the caller. The options sanitiser
     * already enforces the same character class on `table_name` and
     * `motherduck_database` at save time, so this throw should never fire in
     * production — it exists to catch programmer error (someone calling
     * quote_ident() with a user-supplied string that bypassed the sanitiser).
     *
     * Empty identifiers are also rejected for the same reason: an empty
     * quoted string `""` is a SQL syntax error in some contexts and a
     * collation pitfall in others.
     *
     * @throws InvalidArgumentException
     */
    protected function quote_ident(string $ident): string {
        if ($ident === '' || preg_match('/[^a-zA-Z0-9_]/', $ident) === 1) {
            throw new InvalidArgumentException(sprintf(
                /* translators: %s = the offending identifier */
                __('Refusing to quote unsafe identifier "%s". Identifiers must match /^[a-zA-Z0-9_]+$/. Did the value bypass the options sanitiser?', 'mxchat-plus'),
                $ident
            ));
        }
        return '"' . $ident . '"';
    }

    protected function literal_string(string $val): string {
        return "'" . str_replace("'", "''", $val) . "'";
    }

    /**
     * Pinecone-filter literal: wraps scalars for `WHERE col = ?`-style fragments.
     */
    public function literal_for($val): string {
        if (is_int($val) || is_float($val)) return (string) $val;
        if (is_bool($val)) return $val ? 'TRUE' : 'FALSE';
        if (is_null($val)) return 'NULL';
        return $this->literal_string((string) $val);
    }

    protected function embedding_column_type(): string {
        return $this->storage === 'int8'
            ? sprintf('TINYINT[%d]', $this->dim)
            : sprintf('FLOAT[%d]', $this->dim);
    }

    /**
     * SQL expression that yields a FLOAT[N] usable by VSS — identity for
     * float32 storage, list_transform-based dequantize for int8.
     */
    protected function embedding_as_float_sql(): string {
        return $this->storage === 'int8'
            ? MxChat_Plus_DuckDB_Quantization::sql_dequantize_expression($this->dim)
            : 'embedding';
    }

    /**
     * Integer-or-float array literal used by upsert. Both paths throw on
     * non-numeric components — silent zeroing would corrupt embeddings.
     *
     * @throws RuntimeException
     */
    protected function literal_int_or_float_array(array $arr): string {
        $parts = [];
        foreach ($arr as $i => $v) {
            if (is_int($v) || is_float($v)) {
                $parts[] = (string) $v;
            } elseif (is_numeric($v)) {
                $parts[] = (string) (float) $v;
            } else {
                throw new RuntimeException(sprintf(
                    /* translators: 1: index, 2: PHP type */
                    __('Non-numeric embedding component at index %1$d (type %2$s). Refusing to write a corrupted vector.', 'mxchat-plus'),
                    $i,
                    gettype($v)
                ));
            }
        }
        return '[' . implode(',', $parts) . ']';
    }

    protected function literal_float_array(array $arr): string {
        return $this->literal_int_or_float_array($arr);
    }
}
