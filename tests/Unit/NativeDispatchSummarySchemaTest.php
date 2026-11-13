<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/../../scripts/conformance/server-parity/dispatch-summary-schema.php';

class NativeDispatchSummarySchemaTest extends TestCase
{
    public function test_only_the_complete_native_column_is_projected_from_retained_catalogs(): void
    {
        foreach (['mysql' => 15, 'mariadb' => 14, 'postgres' => 14] as $backend => $version) {
            $path = __DIR__.'/../../rust/migrations/'.$backend.'/';
            $old = json_decode(file_get_contents($path.sprintf('%04d_catalog.json', $version)), true, flags: JSON_THROW_ON_ERROR);
            $new = json_decode(file_get_contents($path.sprintf('%04d_catalog.json', $version + 1)), true, flags: JSON_THROW_ON_ERROR);
            $project = $backend === 'postgres' ? 'projectPostgresDispatchSummary' : 'projectMysqlDispatchSummary';
            $this->assertSame($old, $project($new));
            foreach (['type', 'default', 'missing', 'duplicate', 'other'] as $mutation) {
                $changed = $new;
                $duplicate = null;
                foreach ($changed as $index => &$row) {
                    if ($backend === 'postgres') {
                        if ($row[0] !== 'column' || ($row[2]['name'] ?? null) !== 'dispatch_summary') {
                            continue;
                        }
                        if ($mutation === 'missing') {
                            unset($changed[$index]);
                        } elseif ($mutation === 'duplicate') {
                            $duplicate = $row;
                        } else {
                            $field = ['type' => 'type', 'default' => 'default', 'other' => 'not_null'][$mutation];
                            $row[2][$field] = ['type' => 'varchar', 'default' => '0', 'other' => true][$mutation];
                        }
                    } elseif ($row[0] === 'workflow_schedules') {
                        $definition = $backend === 'mysql'
                            ? "  `dispatch_summary` text COLLATE utf8mb4_unicode_ci,\n"
                            : "  `dispatch_summary` text DEFAULT NULL,\n";
                        $replacement = match ($mutation) {
                            'type' => str_replace(' text ', ' varchar(64) ', $definition),
                            'default' => str_replace(",\n", " DEFAULT 0,\n", $definition),
                            'missing' => '',
                            'duplicate' => $definition.$definition,
                            'other' => str_replace('`dispatch_summary`', '`other`', $definition),
                        };
                        $row[1] = str_replace($definition, $replacement, $row[1]);
                    }
                }
                unset($row);
                if ($duplicate !== null) {
                    $changed[] = $duplicate;
                }
                try {
                    $project(array_values($changed));
                    $this->fail('Changed native dispatch column was hidden.');
                } catch (RuntimeException $error) {
                    $this->assertStringStartsWith('Native dispatch summary', $error->getMessage());
                }
            }
        }
    }

    public function test_sqlite_projection_preserves_every_other_definition(): void
    {
        $base = [['type' => 'table', 'name' => 'workflow_schedules', 'tbl_name' => 'workflow_schedules',
            'sql' => 'CREATE TABLE workflow_schedules (id TEXT, primary key (id))']];
        $new = $base;
        $new[0]['sql'] = str_replace(', primary key', ', dispatch_summary TEXT NULL, primary key', $base[0]['sql']);
        $this->assertSame($base, projectSqliteDispatchSummary($new));
        foreach (['TEXT NULL DEFAULT 0', 'TEXT NOT NULL', 'BLOB NULL', 'TEXT NULL, dispatch_summary TEXT NULL'] as $definition) {
            $changed = $new;
            $changed[0]['sql'] = str_replace('TEXT NULL', $definition, $new[0]['sql']);
            try {
                projectSqliteDispatchSummary($changed);
                $this->fail('Changed SQLite dispatch column was hidden.');
            } catch (RuntimeException $error) {
                $this->assertSame('Native dispatch summary definition mismatch.', $error->getMessage());
            }
        }
        $new[0]['sql'] = str_replace('id TEXT', 'id BLOB', $new[0]['sql']);
        $this->assertNotSame($base, projectSqliteDispatchSummary($new), 'Unrelated schema corruption remains visible.');
    }
}
