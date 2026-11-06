<?php

declare(strict_types=1);

// Remove only the exact native additive column before comparing the remaining
// physical schema with frozen PHP. A changed type/default/nullability refuses.
function projectSqliteReadyOrigin(array $rows): array
{
    $found = 0;
    foreach ($rows as &$row) {
        if ($row['type'] === 'table' && $row['name'] === 'workflow_tasks') {
            $column = ', ready_origin_at DATETIME';
            if (substr_count($row['sql'], $column) !== 1) {
                throw new RuntimeException('Native ready origin definition mismatch.');
            }
            $row['sql'] = str_replace($column, '', $row['sql']);
            $found++;
        }
    }
    unset($row);
    if ($found !== 1) {
        throw new RuntimeException('Native task table missing.');
    }

    return $rows;
}

function projectMysqlReadyOrigin(array $rows): array
{
    $found = 0;
    foreach ($rows as &$row) {
        if ($row[0] === 'workflow_tasks') {
            $column = "  `ready_origin_at` datetime(6) DEFAULT NULL,\n";
            if (substr_count($row[1], $column) !== 1) {
                throw new RuntimeException('Native ready origin definition mismatch.');
            }
            $row[1] = str_replace($column, '', $row[1]);
            $found++;
        }
    }
    unset($row);
    if ($found !== 1) {
        throw new RuntimeException('Native task table missing.');
    }

    return $rows;
}

function projectPostgresReadyOrigin(array $rows): array
{
    $column = ['column', 'workflow_tasks.ready_origin_at', ['name' => 'ready_origin_at',
        'type' => 'timestamp(6) without time zone', 'default' => null, 'identity' => '',
        'not_null' => false, 'collation' => null, 'generated' => '']];
    $found = 0;
    $result = [];
    foreach ($rows as $row) {
        if ($row[0] === 'column' && str_starts_with($row[1], 'workflow_tasks.')
            && ($row[2]['name'] ?? null) === 'ready_origin_at') {
            if ($row !== $column) {
                throw new RuntimeException('Native ready origin definition mismatch.');
            }
            $found++;
        } else {
            $result[] = $row;
        }
    }
    if ($found !== 1) {
        throw new RuntimeException('Native ready origin column missing or duplicated.');
    }

    return $result;
}
