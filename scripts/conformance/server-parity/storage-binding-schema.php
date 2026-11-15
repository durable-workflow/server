<?php

declare(strict_types=1);

// Validate the complete native additions before comparing all remaining
// columns, constraints and indexes with the frozen PHP schema.
function projectSqliteStorageBindings(array $rows): array
{
    $definitions = [
        'runtime_external_payloads' => [', storage_binding TEXT NULL,', ', storage_binding ', ','],
        'workflow_namespaces' => [', external_payload_storage_generation INTEGER NOT NULL DEFAULT 0)',
            ', external_payload_storage_generation ', ')'],
    ];
    $found = [];
    foreach ($rows as &$row) {
        if ($row['type'] !== 'table' || ! isset($definitions[$row['name']])) {
            continue;
        }
        [$definition, $column, $replacement] = $definitions[$row['name']];
        if (substr_count($row['sql'], $definition) !== 1 || substr_count($row['sql'], $column) !== 1) {
            throw new RuntimeException('Native storage binding definition mismatch.');
        }
        $row['sql'] = str_replace($definition, $replacement, $row['sql']);
        $found[] = $row['name'];
    }
    unset($row);
    sort($found, SORT_STRING);
    if ($found !== array_keys($definitions)) {
        throw new RuntimeException('Native storage binding tables missing or duplicated.');
    }

    return $rows;
}

function projectMysqlStorageBindings(array $rows): array
{
    $definitions = [
        'runtime_external_payloads' => ['storage_binding', [
            "  `storage_binding` text COLLATE utf8mb4_unicode_ci,\n",
            "  `storage_binding` text DEFAULT NULL,\n",
        ]],
        'workflow_namespaces' => ['external_payload_storage_generation', [
            "  `external_payload_storage_generation` bigint NOT NULL DEFAULT '0',\n",
            "  `external_payload_storage_generation` bigint(20) NOT NULL DEFAULT 0,\n",
        ]],
    ];
    $found = [];
    foreach ($rows as &$row) {
        if (! isset($definitions[$row[0]])) {
            continue;
        }
        [$column, $allowed] = $definitions[$row[0]];
        $matches = array_values(array_filter($allowed, static fn (string $definition): bool =>
            substr_count($row[1], $definition) === 1));
        if (count($matches) !== 1 || substr_count($row[1], '  `'.$column.'`') !== 1) {
            throw new RuntimeException('Native storage binding definition mismatch.');
        }
        $row[1] = str_replace($matches[0], '', $row[1]);
        $found[] = $row[0];
    }
    unset($row);
    sort($found, SORT_STRING);
    if ($found !== array_keys($definitions)) {
        throw new RuntimeException('Native storage binding tables missing or duplicated.');
    }

    return $rows;
}

function projectPostgresStorageBindings(array $rows): array
{
    $expected = [
        'runtime_external_payloads.storage_binding' => ['name' => 'storage_binding', 'type' => 'text',
            'default' => null, 'identity' => '', 'not_null' => false, 'collation' => 'default', 'generated' => ''],
        'workflow_namespaces.external_payload_storage_generation' => ['name' => 'external_payload_storage_generation',
            'type' => 'bigint', 'default' => '0', 'identity' => '', 'not_null' => true,
            'collation' => null, 'generated' => ''],
    ];
    $found = [];
    $result = [];
    foreach ($rows as $row) {
        if ($row[0] !== 'column' || ! isset($expected[$row[1]])) {
            $result[] = $row;
            continue;
        }
        if ($row[2] !== $expected[$row[1]]) {
            throw new RuntimeException('Native storage binding definition mismatch.');
        }
        $found[] = $row[1];
    }
    sort($found, SORT_STRING);
    if ($found !== array_keys($expected)) {
        throw new RuntimeException('Native storage binding columns missing or duplicated.');
    }

    return $result;
}
