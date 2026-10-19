<?php

declare(strict_types=1);

// Explicit mutations of the disposable source, never part of upgrade-inspect.
[$script, $mode] = $argv;
$backend = getenv('DB_CONNECTION');
$database = getenv('DB_DATABASE');
if ($backend === 'sqlite') {
    $dsn = 'sqlite:'.$database;
} else {
    if (! preg_match('/^[a-zA-Z0-9_]{1,63}$/D', $database)) {
        throw new RuntimeException('An isolated fixture database is required');
    }
    $dsn = $backend.':host='.getenv('DB_HOST').';dbname='.$database;
    if ($backend === 'mysql') {
        $dsn .= ';charset=utf8mb4';
    }
}
$pdo = new PDO($dsn, getenv('DB_USERNAME') ?: null, getenv('DB_PASSWORD') ?: null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$statements = match ($mode) {
    'roles' => $backend === 'mysql' ? [
        "CREATE USER 'upgrade_source_reader'@'%' IDENTIFIED BY 'parity-disposable-password'",
        'GRANT SELECT ON '.$database.'.* TO \'upgrade_source_reader\'@\'%\'',
        "CREATE USER 'upgrade_source_denied'@'%' IDENTIFIED BY 'parity-disposable-password'",
    ] : [
        "CREATE ROLE upgrade_source_reader LOGIN PASSWORD 'parity-disposable-password'",
        'GRANT CONNECT ON DATABASE '.$database.' TO upgrade_source_reader',
        'GRANT USAGE ON SCHEMA public TO upgrade_source_reader',
        'GRANT SELECT ON ALL TABLES IN SCHEMA public TO upgrade_source_reader',
        "CREATE ROLE upgrade_source_denied LOGIN PASSWORD 'parity-disposable-password'",
    ],
    'unknown' => ['ALTER TABLE jobs ADD COLUMN upgrade_source_probe INTEGER NULL'],
    'marker' => ['CREATE TABLE dw_server_schema (unqualified_marker INTEGER NULL)'],
    'rls' => ['ALTER TABLE workflow_runs ENABLE ROW LEVEL SECURITY'],
    'rls-reset' => ['ALTER TABLE workflow_runs DISABLE ROW LEVEL SECURITY'],
    default => throw new RuntimeException('Unknown source control'),
};
foreach ($statements as $sql) {
    $pdo->exec($sql);
}
echo json_encode(['outcome' => 'pass', 'control' => $mode], JSON_THROW_ON_ERROR)."\n";
