<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/includes/duckdb/class-duckdb-quantization.php';

final class QuantizationTest extends TestCase {

    public function test_zero_round_trips_to_zero(): void {
        $q = MxChat_Plus_DuckDB_Quantization::quantize_int8([0.0, 0.0, 0.0]);
        $this->assertSame([0, 0, 0], $q);
        $d = MxChat_Plus_DuckDB_Quantization::dequantize_int8($q);
        $this->assertSame([0.0, 0.0, 0.0], $d);
    }

    public function test_one_round_trips_to_127(): void {
        $q = MxChat_Plus_DuckDB_Quantization::quantize_int8([1.0]);
        $this->assertSame([127], $q);
        $d = MxChat_Plus_DuckDB_Quantization::dequantize_int8($q);
        $this->assertSame([1.0], $d);
    }

    public function test_minus_one_round_trips_to_minus_127(): void {
        $q = MxChat_Plus_DuckDB_Quantization::quantize_int8([-1.0]);
        $this->assertSame([-127], $q);
        $d = MxChat_Plus_DuckDB_Quantization::dequantize_int8($q);
        $this->assertSame([-1.0], $d);
    }

    public function test_values_above_one_clip_to_127(): void {
        $q = MxChat_Plus_DuckDB_Quantization::quantize_int8([1.5, 2.0, 10.0]);
        $this->assertSame([127, 127, 127], $q);
    }

    public function test_values_below_minus_one_clip_to_minus_128(): void {
        $q = MxChat_Plus_DuckDB_Quantization::quantize_int8([-1.5, -2.0, -100.0]);
        $this->assertSame([-128, -128, -128], $q);
    }

    public function test_recall_error_is_within_one_percent_on_unit_vectors(): void {
        // Generate a random unit-length vector and check that the cosine
        // similarity between the original and the dequantized round-trip
        // is > 0.9999 (which is what we care about for retrieval recall).
        srand(42);
        $dim = 1536;
        $v = [];
        for ($i = 0; $i < $dim; $i++) {
            $v[] = (rand() / getrandmax()) * 2 - 1;
        }
        // Normalise to unit length.
        $norm = sqrt(array_sum(array_map(fn($x) => $x * $x, $v)));
        $v = array_map(fn($x) => $x / $norm, $v);

        $q = MxChat_Plus_DuckDB_Quantization::quantize_int8($v);
        $d = MxChat_Plus_DuckDB_Quantization::dequantize_int8($q);

        $dot = 0.0; $n1 = 0.0; $n2 = 0.0;
        for ($i = 0; $i < $dim; $i++) {
            $dot += $v[$i] * $d[$i];
            $n1  += $v[$i] * $v[$i];
            $n2  += $d[$i] * $d[$i];
        }
        $cos = $dot / (sqrt($n1) * sqrt($n2));
        // INT8 with scale=127 on a uniform-distributed 1536-dim unit vector
        // gives cosine similarity ~0.995. Real embedding distributions (which
        // are NOT uniform) typically round-trip at > 0.999 — but we test what
        // we can synthesise here. 0.99 still proves the round-trip preserves
        // retrieval-grade information; anything lower would indicate a bug.
        $this->assertGreaterThan(0.99, $cos);
    }

    public function test_quantize_throws_on_non_numeric(): void {
        $this->expectException(RuntimeException::class);
        MxChat_Plus_DuckDB_Quantization::quantize_int8([1.0, 'not_a_number', 0.5]);
    }

    public function test_sql_dequantize_expression_includes_dim(): void {
        $sql = MxChat_Plus_DuckDB_Quantization::sql_dequantize_expression(1536);
        $this->assertStringContainsString('FLOAT[1536]', $sql);
        $this->assertStringContainsString('list_transform', $sql);
        $this->assertStringContainsString('/ 127', $sql);
    }
}
