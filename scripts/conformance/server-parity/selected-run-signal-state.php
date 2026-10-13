<?php

declare(strict_types=1);

// Fixture-only, read-only inspection. Never inspect credentials or register a
// product endpoint. Every row is anchored to these two original fixture runs.
function selectedRunSignalStoredState(array $workflowIds, array $runIds): array
{
    if (count($workflowIds) !== 2 || count($runIds) !== 2
        || count(array_unique($workflowIds)) !== 2 || count(array_unique($runIds)) !== 2) {
        throw new InvalidArgumentException('State inspection requires the two distinct original runs.');
    }
    $kind = getenv('DW_PARITY_STATE_CONNECTION') ?: getenv('DB_CONNECTION');
    $database = getenv('DW_PARITY_STATE_DATABASE') ?: getenv('DB_DATABASE');
    if (! is_string($database) || $database === '') {
        throw new RuntimeException('Selected-run fixture needs its explicit read-only database observer.');
    }
    $dsn = match ($kind) {
        'sqlite' => 'sqlite:file:'.$database.'?mode=ro',
        'mysql', 'pgsql' => $kind.':host='.getenv('DB_HOST').';port='.(getenv('DB_PORT') ?: ($kind === 'mysql' ? '3306' : '5432')).';dbname='.$database,
        default => throw new InvalidArgumentException('Unsupported fixture state connection.'),
    };
    $pdo = new PDO($dsn, getenv('DB_USERNAME') ?: null, getenv('DB_PASSWORD') ?: null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($kind === 'sqlite') {
        $pdo->exec('PRAGMA query_only=ON');
        $pdo->beginTransaction();
        $names = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'workflow_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($kind === 'mysql') {
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION READ ONLY');
        $statement = $pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema=? AND table_name LIKE 'workflow_%' ORDER BY table_name");
        $statement->execute([$database]);
        $names = $statement->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $pdo->exec('BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $names = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_name LIKE 'workflow_%' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
    }
    $tables = [];
    try {
        foreach ($names as $table) {
            if (! preg_match('/^workflow_[a-z_]+$/D', $table)) {
                throw new RuntimeException('Unexpected workflow table identifier.');
            }
            if ($kind === 'sqlite') {
                $columns = array_column($pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC), 'name');
            } else {
                $statement = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=? AND table_name=? ORDER BY ordinal_position');
                $statement->execute([$kind === 'mysql' ? $database : 'public', $table]);
                $columns = $statement->fetchAll(PDO::FETCH_COLUMN);
            }
            $conditions = $bindings = [];
            foreach ($columns as $column) {
                $ids = match ($column) {
                    'workflow_run_id', 'parent_workflow_run_id', 'target_workflow_run_id' => $runIds,
                    'workflow_instance_id', 'parent_workflow_instance_id', 'target_workflow_instance_id' => $workflowIds,
                    'id' => match ($table) { 'workflow_runs' => $runIds, 'workflow_instances' => $workflowIds, default => null },
                    default => null,
                };
                if ($ids !== null) {
                    $conditions[] = $column.' IN (?,?)';
                    array_push($bindings, ...$ids);
                }
            }
            if ($conditions === []) {
                continue;
            }
            $statement = $pdo->prepare('SELECT * FROM '.$table.' WHERE '.implode(' OR ', $conditions).' ORDER BY '.(in_array('id', $columns, true) ? 'id' : $columns[0]));
            $statement->execute($bindings);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                foreach ($row as &$value) {
                    if (is_resource($value)) { $value = stream_get_contents($value); }
                    if (is_string($value) && preg_match('//u', $value) !== 1) {
                        $value = ['raw_base64' => base64_encode($value), 'sha256' => hash('sha256', $value)];
                    }
                }
                unset($value);
            }
            unset($row);
            $tables[$table] = ['columns' => $columns, 'rows' => $rows];
        }
        return ['schema' => 'durable-workflow.selected-run-signal-state/v1',
            'read_only' => true, 'workflow_ids' => $workflowIds, 'run_ids' => $runIds, 'tables' => $tables];
    } finally {
        $pdo->exec('ROLLBACK');
    }
}
