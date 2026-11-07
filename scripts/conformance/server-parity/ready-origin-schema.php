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
    return projectMysqlAdditiveDefinition($rows, "  `ready_origin_at` datetime(6) DEFAULT NULL,\n",
        'Native ready origin definition mismatch.', 'Native task table missing.');
}

// The comparison keeps every original key. Remove only the exact native
// scoped-dispatch key, refusing a changed prefix, order or missing definition.
function projectMysqlScopedDispatch(array $rows): array
{
    return projectMysqlAdditiveDefinition($rows,
        ",\n  KEY `dw_workflow_tasks_scoped_dispatch` (`namespace`,`queue`,`task_type`(16),`status`(16),`priority`,`available_at`,`id`)",
        'Native scoped dispatch definition mismatch.', 'Native task table missing or duplicated.');
}

function projectMysqlAdditiveDefinition(array $rows, string $definition, string $mismatch, string $missing): array
{
    $found = 0;
    foreach ($rows as &$row) {
        if ($row[0] === 'workflow_tasks') {
            if (substr_count($row[1], $definition) !== 1) {
                throw new RuntimeException($mismatch);
            }
            $row[1] = str_replace($definition, '', $row[1]);
            $found++;
        }
    }
    unset($row);
    if ($found !== 1) {
        throw new RuntimeException($missing);
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

function projectPostgresScopedDispatch(array $rows): array
{
    $definition = ['index', 'dw_workflow_tasks_scoped_dispatch', ['ready' => true, 'valid' => true,
        'unique' => false, 'primary' => false,
        'definition' => 'CREATE INDEX dw_workflow_tasks_scoped_dispatch ON public.workflow_tasks USING btree (namespace COLLATE "C", queue COLLATE "C", task_type COLLATE "C", status COLLATE "C", priority, available_at, id)']];
    $found = 0;
    $result = [];
    foreach ($rows as $row) {
        if ($row[0] === 'index' && $row[1] === 'dw_workflow_tasks_scoped_dispatch') {
            if ($row !== $definition) {
                throw new RuntimeException('Native scoped dispatch definition mismatch.');
            }
            $found++;
        } else {
            $result[] = $row;
        }
    }
    if ($found !== 1) {
        throw new RuntimeException('Native scoped dispatch index missing or duplicated.');
    }

    return $result;
}
