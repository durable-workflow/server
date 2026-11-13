<?php

declare(strict_types=1);

// Check the complete native-only column before comparing the remaining
// physical schema with frozen PHP. Do not hide other schema changes.
function projectSqliteDispatchSummary(array $rows): array
{
    $found = 0;
    foreach ($rows as &$row) {
        if ($row['type'] !== 'table' || $row['name'] !== 'workflow_schedules') {
            continue;
        }
        $definition = ', dispatch_summary TEXT NULL,';
        if (substr_count($row['sql'], $definition) !== 1 || substr_count($row['sql'], ', dispatch_summary ') !== 1) {
            throw new RuntimeException('Native dispatch summary definition mismatch.');
        }
        $row['sql'] = str_replace($definition, ',', $row['sql']);
        $found++;
    }
    unset($row);
    if ($found !== 1) {
        throw new RuntimeException('Native schedule table missing or duplicated.');
    }

    return $rows;
}

function projectMysqlDispatchSummary(array $rows): array
{
    $found = 0;
    foreach ($rows as &$row) {
        if ($row[0] !== 'workflow_schedules') {
            continue;
        }
        $definitions = ["  `dispatch_summary` text COLLATE utf8mb4_unicode_ci,\n", "  `dispatch_summary` text DEFAULT NULL,\n"];
        $matches = array_filter($definitions, static fn (string $definition): bool => substr_count($row[1], $definition) === 1);
        if (count($matches) !== 1 || substr_count($row[1], '  `dispatch_summary`') !== 1) {
            throw new RuntimeException('Native dispatch summary definition mismatch.');
        }
        $row[1] = str_replace(array_values($matches)[0], '', $row[1]);
        $found++;
    }
    unset($row);
    if ($found !== 1) {
        throw new RuntimeException('Native schedule table missing or duplicated.');
    }

    return $rows;
}

function projectPostgresDispatchSummary(array $rows): array
{
    $expected = ['column', 'workflow_schedules.31', ['name' => 'dispatch_summary', 'type' => 'text',
        'default' => null, 'identity' => '', 'not_null' => false, 'collation' => 'default', 'generated' => '']];
    $found = 0;
    $result = [];
    foreach ($rows as $row) {
        if ($row[0] === 'column' && str_starts_with($row[1], 'workflow_schedules.')
            && ($row[2]['name'] ?? null) === 'dispatch_summary') {
            if ($row !== $expected) {
                throw new RuntimeException('Native dispatch summary definition mismatch.');
            }
            $found++;
        } else {
            $result[] = $row;
        }
    }
    if ($found !== 1) {
        throw new RuntimeException('Native dispatch summary column missing or duplicated.');
    }

    return $result;
}
