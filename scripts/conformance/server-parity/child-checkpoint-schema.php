<?php

declare(strict_types=1);

// Verify the native checkpoint objects before comparing the unchanged PHP layout.
function projectSqliteChildCheckpoints(array $rows): array
{
    $migration = file_get_contents(__DIR__.'/../../../rust/migrations/sqlite/0019_child_summary_checkpoints.sql');
    $statements = preg_split('/(?=^(?:CREATE|UPDATE) )/m', trim($migration), -1, PREG_SPLIT_NO_EMPTY);
    $expected = [];
    foreach ($statements as $statement) {
        if (! preg_match('/\ACREATE (?:TABLE|INDEX|TRIGGER) ([a-z_]+)\b/', $statement, $name)) {
            continue;
        }
        $expected[$name[1]] = substr(trim($statement), 0, -1);
    }
    $result = [];
    foreach ($rows as $row) {
        $name = $row['name'];
        if (! isset($expected[$name])) {
            $result[] = $row;
            continue;
        }
        if ($row['sql'] !== $expected[$name]) {
            throw new RuntimeException('Native SQLite child checkpoint definition mismatch: '.$name);
        }
        unset($expected[$name]);
    }
    if ($expected !== []) {
        throw new RuntimeException('Native SQLite child checkpoint objects missing: '.implode(', ', array_keys($expected)));
    }

    return $result;
}

function verifyMysqlChildCheckpointCode(PDO $pdo): void
{
    $routineBody = trim(file_get_contents(__DIR__.'/../../../rust/migrations/mysql/0023_child_summary_catalog_lookup.sql'));
    $routines = $pdo->query("SELECT ROUTINE_NAME, ROUTINE_TYPE, SECURITY_TYPE, SQL_DATA_ACCESS, DTD_IDENTIFIER, IS_DETERMINISTIC,
        SHA2(TRIM(TRAILING ';' FROM TRIM(ROUTINE_DEFINITION)),256), BINARY DEFINER=BINARY CURRENT_USER()
        FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() LIMIT 2")->fetchAll(PDO::FETCH_NUM);
    $expected = ['dw_child_summary_catalog', 'FUNCTION', 'DEFINER', 'READS SQL DATA', 'longtext', 'NO', hash('sha256', $routineBody)];
    if (count($routines) !== 1 || array_slice($routines[0], 0, 7) !== $expected || (int) $routines[0][7] !== 1) {
        throw new RuntimeException('Native MySQL/MariaDB child checkpoint routine mismatch.');
    }
    $view = $pdo->query("SELECT SECURITY_TYPE, CHECK_OPTION, VIEW_DEFINITION, BINARY DEFINER=BINARY CURRENT_USER()
        FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dw_child_summary_catalog'")->fetch(PDO::FETCH_NUM);
    if ($view === false || array_slice($view, 0, 3) !== ['DEFINER', 'NONE', 'select `dw_child_summary_catalog`() AS `catalog`']
        || (int) $view[3] !== 1) {
        throw new RuntimeException('Native MySQL/MariaDB child checkpoint view mismatch.');
    }

    $tables = [
        'history' => ['workflow_history_events', 'workflow_run_id'],
        'wait' => ['workflow_run_waits', 'workflow_run_id'],
        'call' => ['workflow_child_calls', 'parent_workflow_run_id'],
        'link' => ['workflow_links', 'parent_workflow_run_id'],
        'task' => ['workflow_tasks', 'workflow_run_id'],
        'failure' => ['workflow_failures', 'workflow_run_id'],
        'signal' => ['workflow_signal_records', 'workflow_run_id'],
        'current' => ['workflow_instances', 'current_run_id'],
        'summary' => ['workflow_run_summaries', 'id'],
    ];
    $expected = [];
    foreach ($tables as $prefix => [$table, $column]) {
        foreach (['INSERT' => "= NEW.{$column}", 'UPDATE' => "IN (OLD.{$column}, NEW.{$column})", 'DELETE' => "= OLD.{$column}"] as $event => $condition) {
            $name = 'dw_child_summary_'.$prefix.'_'.strtolower($event);
            $action = 'UPDATE dw_child_summary_checkpoints SET generation = generation + 1 WHERE run_id '.$condition;
            $expected[$name] = [$table, $event, 'AFTER', 'ROW', $action];
        }
    }
    $triggers = $pdo->query('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_ORIENTATION, ACTION_STATEMENT,
        ACTION_ORDER, BINARY DEFINER=BINARY CURRENT_USER() FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=DATABASE() LIMIT 28')->fetchAll(PDO::FETCH_NUM);
    foreach ($triggers as [$name, $table, $event, $timing, $orientation, $action, $position, $owner]) {
        $action = preg_replace('/;$/D', '', trim($action));
        if (($expected[$name] ?? null) !== [$table, $event, $timing, $orientation, $action] || (int) $position !== 1 || (int) $owner !== 1) {
            throw new RuntimeException('Native MySQL/MariaDB child checkpoint trigger mismatch: '.$name);
        }
        unset($expected[$name]);
    }
    if ($expected !== []) {
        throw new RuntimeException('Native MySQL/MariaDB child checkpoint triggers missing: '.implode(', ', array_keys($expected)));
    }
}

function projectMysqlChildCheckpoints(array $rows, bool $mariadb): array
{
    $file = $mariadb ? 'mariadb/0020_catalog.json' : 'mysql/0021_catalog.json';
    $expected = array_column(json_decode(file_get_contents(__DIR__.'/../../../rust/migrations/'.$file), true, 512, JSON_THROW_ON_ERROR), 1, 0);
    $checkpoint = array_search('dw_child_summary_checkpoints', array_column($rows, 0), true);
    $calls = array_search('workflow_child_calls', array_column($rows, 0), true);
    $index = ",\n  KEY `dw_child_calls_run_status_sequence` (`parent_workflow_run_id`,`status`,`sequence`)";
    if ($checkpoint === false || $rows[$checkpoint][1] !== $expected['dw_child_summary_checkpoints']
        || $calls === false || substr_count($rows[$calls][1], $index.",\n") !== 1) {
        throw new RuntimeException('Native MySQL/MariaDB child checkpoint table or index mismatch.');
    }
    unset($rows[$checkpoint]);
    $rows[$calls][1] = str_replace($index, '', $rows[$calls][1]);

    return array_values($rows);
}

function projectPostgresChildCheckpoints(array $rows): array
{
    $key = static fn (array $row): string => $row[0].':'.$row[1];
    $previous = json_decode(file_get_contents(__DIR__.'/../../../rust/migrations/postgres/0019_catalog.json'), true, 512, JSON_THROW_ON_ERROR);
    $current = json_decode(file_get_contents(__DIR__.'/../../../rust/migrations/postgres/0020_catalog.json'), true, 512, JSON_THROW_ON_ERROR);
    $expected = array_diff_key(array_combine(array_map($key, $current), $current), array_flip(array_map($key, $previous)));
    $result = [];
    foreach ($rows as $row) {
        $name = $key($row);
        if (! isset($expected[$name])) {
            $result[] = $row;
            continue;
        }
        if ($row !== $expected[$name]) {
            throw new RuntimeException('Native PostgreSQL child checkpoint definition mismatch: '.$name);
        }
        unset($expected[$name]);
    }
    if ($expected !== []) {
        throw new RuntimeException('Native PostgreSQL child checkpoint objects missing: '.implode(', ', array_keys($expected)));
    }

    return $result;
}
