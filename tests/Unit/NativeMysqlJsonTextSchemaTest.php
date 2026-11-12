<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/../../scripts/conformance/server-parity/ready-origin-schema.php';

class NativeMysqlJsonTextSchemaTest extends TestCase
{
    public function test_only_the_exact_native_json_text_columns_and_checks_map_back(): void
    {
        $path = __DIR__.'/../../rust/migrations/mysql/';
        $old = json_decode(file_get_contents($path.'0012_catalog.json'), true, flags: JSON_THROW_ON_ERROR);
        $new = json_decode(file_get_contents($path.'0013_catalog.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($old, projectMysqlJsonText($new, $old));
        // Live SHOW CREATE names the character set where the table default
        // collation differs from the column's.
        $raw = array_map(static fn (array $row): array => str_ends_with($row[1], 'COLLATE=utf8mb4_bin') ? $row : [$row[0],
            str_replace('longtext COLLATE utf8mb4_bin', 'longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin', $row[1])], $new);
        $this->assertSame($old, projectMysqlJsonText($raw, $old));
        // MariaDB's PHP layout has no json column: nothing changes.
        $maria = json_decode(file_get_contents(__DIR__.'/../../rust/migrations/mariadb/0012_catalog.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($maria, projectMysqlJsonText($maria, $maria));
        foreach ([
            'collation' => ['`retry_policy` longtext COLLATE utf8mb4_bin,', '`retry_policy` longtext COLLATE utf8mb4_unicode_ci,'],
            'missing check' => [",\n  CONSTRAINT `dw_json_text_da40cddf645f92a0f5d3` CHECK (json_valid(`retry_policy`))", ''],
            'renamed check' => ['dw_json_text_da40cddf645f92a0f5d3', 'dw_json_text_000000000000000000000'],
        ] as $label => [$from, $to]) {
            $changed = array_map(static fn (array $row): array => $row[0] === 'activity_executions'
                ? [$row[0], str_replace($from, $to, $row[1])] : $row, $new);
            try {
                projectMysqlJsonText($changed, $old);
                $this->fail("Changed native JSON text definition was hidden: {$label}.");
            } catch (RuntimeException $error) {
                $this->assertSame('Native JSON text definition mismatch: activity_executions.retry_policy', $error->getMessage());
            }
        }
    }
}
