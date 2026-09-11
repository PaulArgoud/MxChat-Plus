<?php

use PHPUnit\Framework\TestCase;

final class VectorIdTest extends TestCase {

    public function test_url_based_row_returns_md5_of_url(): void {
        $row = (object) ['id' => 42, 'source_url' => 'https://example.test/post/1'];
        $id = MxChat_Plus_DuckDB_Sync::vector_id_for_row($row);
        $this->assertSame(md5('https://example.test/post/1'), $id);
    }

    public function test_urlless_row_falls_back_to_kb_id(): void {
        $row = (object) ['id' => 42];
        $id = MxChat_Plus_DuckDB_Sync::vector_id_for_row($row);
        $this->assertSame('mxchat_kb_42', $id);
    }

    public function test_empty_url_falls_back_to_kb_id(): void {
        $row = (object) ['id' => 7, 'source_url' => ''];
        $id = MxChat_Plus_DuckDB_Sync::vector_id_for_row($row);
        $this->assertSame('mxchat_kb_7', $id);
    }

    public function test_id_is_deterministic_across_calls(): void {
        $row = (object) ['id' => 1, 'source_url' => 'https://x/y'];
        $this->assertSame(
            MxChat_Plus_DuckDB_Sync::vector_id_for_row($row),
            MxChat_Plus_DuckDB_Sync::vector_id_for_row($row)
        );
    }
}
