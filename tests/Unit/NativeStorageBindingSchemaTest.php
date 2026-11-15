<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/../../scripts/conformance/server-parity/storage-binding-schema.php';

class NativeStorageBindingSchemaTest extends TestCase
{
    public function test_only_exact_storage_additions_are_projected_from_captured_catalogs(): void
    {
        foreach (['mysql' => 16, 'mariadb' => 15, 'postgres' => 15] as $backend => $version) {
            $path = __DIR__.'/../../rust/migrations/'.$backend.'/';
            $old = json_decode(file_get_contents($path.sprintf('%04d_catalog.json', $version)), true, flags: JSON_THROW_ON_ERROR);
            $new = json_decode(file_get_contents($path.sprintf('%04d_catalog.json', $version + 1)), true, flags: JSON_THROW_ON_ERROR);
            $project = $backend === 'postgres' ? 'projectPostgresStorageBindings' : 'projectMysqlStorageBindings';
            $this->assertSame($this->canonical($old, $backend), $this->canonical($project($new), $backend));
            foreach (['runtime_external_payloads', 'workflow_namespaces'] as $table) {
                foreach (['type', 'default', 'nullability', 'missing', 'duplicate', 'identity'] as $mutation) {
                    $changed = $new;
                    $column = $table === 'workflow_namespaces' ? 'external_payload_storage_generation' : 'storage_binding';
                    $extra = null;
                    foreach ($changed as $index => &$row) {
                        if ($backend === 'postgres') {
                            if ($row[0] !== 'column' || $row[1] !== $table.'.'.$column) {
                                continue;
                            }
                            if ($mutation === 'missing') {
                                unset($changed[$index]);
                            } elseif ($mutation === 'duplicate') {
                                $extra = $row;
                            } else {
                                $key = ['type' => 'type', 'default' => 'default', 'nullability' => 'not_null', 'identity' => 'identity'][$mutation];
                                $row[2][$key] = ['type' => 'varchar', 'default' => '1', 'nullability' => ! $row[2]['not_null'], 'identity' => 'a'][$mutation];
                            }
                        } elseif ($row[0] === $table) {
                            preg_match('/^  `'.preg_quote($column, '/').'`[^\n]+\n/m', $row[1], $match);
                            $definition = $match[0];
                            $replacement = match ($mutation) {
                                'type' => str_replace([' text ', ' bigint'], [' blob ', ' int'], $definition),
                                'default' => str_replace(",\n", " DEFAULT 1,\n", $definition),
                                'nullability' => str_contains($definition, ' NOT NULL')
                                    ? str_replace(' NOT NULL', '', $definition)
                                    : str_replace(",\n", " NOT NULL,\n", $definition),
                                'missing' => '',
                                'duplicate' => $definition.$definition,
                                'identity' => str_replace('`'.$column.'`', '`foreign_column`', $definition),
                            };
                            $row[1] = str_replace($definition, $replacement, $row[1]);
                        }
                    }
                    unset($row);
                    if ($extra !== null) {
                        $changed[] = $extra;
                    }
                    $this->refuses($project, array_values($changed));
                }
            }
            $unrelated = $new;
            foreach ($unrelated as &$row) {
                if ($backend === 'postgres' && $row[0] === 'column' && $row[1] === 'workflow_instances.1') {
                    $row[2]['type'] = 'bytea';
                } elseif ($backend !== 'postgres' && $row[0] === 'workflow_instances') {
                    $row[1] = str_replace('varchar(191)', 'varchar(190)', $row[1]);
                }
            }
            unset($row);
            $this->assertNotSame($this->canonical($old, $backend), $this->canonical($project($unrelated), $backend));
        }
    }

    public function test_real_sqlite_alters_preserve_the_rest_and_refuse_changed_definitions(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE runtime_external_payloads (id TEXT, primary key (id)); CREATE TABLE workflow_namespaces (id INTEGER PRIMARY KEY)');
        $catalog = static fn (): array => $pdo->query("SELECT type,name,tbl_name,sql FROM sqlite_schema WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name")->fetchAll(PDO::FETCH_ASSOC);
        $old = $catalog();
        $pdo->exec('ALTER TABLE runtime_external_payloads ADD COLUMN storage_binding TEXT NULL; ALTER TABLE workflow_namespaces ADD COLUMN external_payload_storage_generation INTEGER NOT NULL DEFAULT 0');
        $new = $catalog();
        $this->assertSame($old, projectSqliteStorageBindings($new));
        foreach (['storage_binding TEXT NULL' => ['storage_binding BLOB NULL', 'storage_binding TEXT NOT NULL', 'storage_binding TEXT NULL DEFAULT 0', 'storage_binding TEXT NULL, storage_binding TEXT NULL'],
            'external_payload_storage_generation INTEGER NOT NULL DEFAULT 0' => ['external_payload_storage_generation TEXT NOT NULL DEFAULT 0', 'external_payload_storage_generation INTEGER DEFAULT 0', 'external_payload_storage_generation INTEGER NOT NULL DEFAULT 1']] as $column => $mutations) {
            foreach ($mutations as $mutation) {
                $changed = $new;
                foreach ($changed as &$row) {
                    $row['sql'] = str_replace($column, $mutation, $row['sql']);
                }
                unset($row);
                $this->refuses('projectSqliteStorageBindings', $changed);
            }
        }
        $this->refuses('projectSqliteStorageBindings', [$new[0]]);
        $this->refuses('projectSqliteStorageBindings', [...$new, $new[0]]);
    }

    private function refuses(string $project, array $rows): void
    {
        $message = null;
        try {
            $project($rows);
        } catch (RuntimeException $error) {
            $message = $error->getMessage();
        }
        $this->assertNotNull($message, 'Changed native storage metadata was hidden.');
        $this->assertStringStartsWith('Native storage binding', $message);
    }

    private function canonical(array $rows, string $backend): array
    {
        if ($backend === 'postgres') {
            foreach ($rows as &$row) {
                // Retained JSON snapshots can order object keys differently.
                // Preserve list order, all scalar types and every metadata value.
                $row[2] = $this->metadata($row[2]);
            }
            unset($row);
        } else {
            foreach ($rows as &$row) {
                // Same retained charset canonicalization as the native catalog;
                // the physical comparison itself still uses actual SHOW CREATE.
                $row[1] = preg_replace('/ CHARACTER SET ([A-Za-z0-9_]+)(?= COLLATE \1_)/', '', $row[1]);
            }
            unset($row);
        }

        return $rows;
    }

    private function metadata(array $value): array
    {
        foreach ($value as &$field) {
            if (is_array($field)) {
                $field = $this->metadata($field);
            }
        }
        unset($field);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
