<?php

declare(strict_types=1);

// The frozen PHP engine has no lease loss columns. Check the definitions
// native added before comparing the rest of the published catalog exactly.
function projectSqliteTaskAttention(array $rows): array
{
    $columns = ', lost_lease_count INTEGER NOT NULL DEFAULT 0, last_lease_lost_at DATETIME,';
    $found = 0;
    foreach ($rows as &$row) {
        if ($row['type'] !== 'table' || $row['name'] !== 'workflow_tasks') {
            continue;
        }
        if (substr_count($row['sql'], $columns) !== 1) {
            throw new RuntimeException('Native SQLite lease loss column definitions mismatch.');
        }
        $row['sql'] = str_replace($columns, ',', $row['sql']);
        $found++;
    }
    unset($row);
    if ($found !== 1) {
        throw new RuntimeException('Native task table missing or duplicated.');
    }

    return $rows;
}

function projectMysqlTaskAttention(array $rows): array
{
    $definition = '/^  `lost_lease_count` bigint(?: NOT NULL DEFAULT \'0\'|\(20\) NOT NULL DEFAULT 0),\n'
        .'  `last_lease_lost_at` datetime\(6\) DEFAULT NULL,\n/m';
    $found = 0;
    foreach ($rows as &$row) {
        if ($row[0] !== 'workflow_tasks') {
            continue;
        }
        $row[1] = preg_replace($definition, '', $row[1], -1, $removed);
        if ($removed !== 1) {
            throw new RuntimeException('Native MySQL/MariaDB lease loss column definitions mismatch.');
        }
        $found++;
    }
    unset($row);
    if ($found !== 1) {
        throw new RuntimeException('Native task table missing or duplicated.');
    }

    return $rows;
}

function projectPostgresTaskAttention(array $rows): array
{
    $expected = [
        'workflow_tasks.last_lease_lost_at' => ['name' => 'last_lease_lost_at', 'type' => 'timestamp(6) without time zone',
            'default' => null, 'identity' => '', 'not_null' => false, 'collation' => null, 'generated' => ''],
        'workflow_tasks.lost_lease_count' => ['name' => 'lost_lease_count', 'type' => 'bigint',
            'default' => '0', 'identity' => '', 'not_null' => true, 'collation' => null, 'generated' => ''],
    ];
    $result = [];
    foreach ($rows as $row) {
        if ($row[0] !== 'column' || ! isset($expected[$row[1]])) {
            $result[] = $row;
            continue;
        }
        if ($row[2] !== $expected[$row[1]]) {
            throw new RuntimeException('Native PostgreSQL lease loss column definitions mismatch.');
        }
        unset($expected[$row[1]]);
    }
    if ($expected !== []) {
        throw new RuntimeException('Native PostgreSQL lease loss columns missing.');
    }

    return $result;
}
