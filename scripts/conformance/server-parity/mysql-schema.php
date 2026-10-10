<?php

declare(strict_types=1);

// Physical fixture inspection only. This is not a database backup procedure.
if (! in_array($argc, [3, 4], true) || ! in_array($argv[1], ['dump', 'catalog', 'snapshot', 'compare'], true)) {
    throw new InvalidArgumentException('Usage: mysql-schema.php dump|catalog|snapshot DATABASE | compare PHP_DATABASE NATIVE_DATABASE');
}

function connection(string $database): PDO
{
    if (! preg_match('/^[a-zA-Z0-9_]{1,63}$/D', $database)) {
        throw new InvalidArgumentException('Use an isolated development database name.');
    }
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('DB_PORT') ?: 3306);
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", getenv('DB_USERNAME') ?: '', getenv('DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET time_zone='+00:00'");
    $pdo->exec('SET sql_quote_show_create=1');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION READ ONLY');

    return $pdo;
}

function identifier(string $name): string
{
    return '`'.str_replace('`', '``', $name).'`';
}

function definition(string $sql): string
{
    // The live next-ID counter is data, not a physical table definition.
    // Preserve column AUTO_INCREMENT flags and every other byte, including
    // comments/defaults. Only remove its numeric table option before COMMENT.
    $footer = strrpos($sql, ') ENGINE=');
    if ($footer === false) {
        return $sql;
    }
    $start = strpos($sql, ' AUTO_INCREMENT=', $footer);
    $comment = strpos($sql, ' COMMENT=', $footer);
    if ($start === false || ($comment !== false && $start > $comment)) {
        return $sql;
    }
    $end = $start + strlen(' AUTO_INCREMENT=');
    while ($end < strlen($sql) && ctype_digit($sql[$end])) {
        $end++;
    }

    return substr($sql, 0, $start).substr($sql, $end);
}

function catalog(PDO $pdo): array
{
    $tables = $pdo->query("SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_NUM);
    $result = [];
    foreach ($tables as [$name, $type]) {
        if ($type !== 'BASE TABLE') {
            throw new RuntimeException('Fixture contains an unsupported view.');
        }
        $row = $pdo->query('SHOW CREATE TABLE '.identifier($name))->fetch(PDO::FETCH_NUM);
        $result[] = [$name, definition($row[1])];
    }
    foreach (['ROUTINES' => 'ROUTINE_SCHEMA', 'TRIGGERS' => 'TRIGGER_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA'] as $table => $column) {
        if ((int) $pdo->query("SELECT COUNT(*) FROM information_schema.{$table} WHERE {$column}=DATABASE()")->fetchColumn() !== 0) {
            throw new RuntimeException('Fixture contains unsupported stored code.');
        }
    }

    return $result;
}

$pdo = connection($argv[2]);
$php = catalog($pdo);
if ($argv[1] === 'dump') {
    echo "-- Frozen published PHP physical schema; no rows or live next-ID counters.\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n";
    foreach ($php as [$name, $sql]) {
        echo str_replace('CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $sql).";\n";
    }
    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    $pdo->exec('ROLLBACK');
    exit;
}
if ($argv[1] === 'catalog') {
    echo json_encode($php, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    $pdo->exec('ROLLBACK');
    exit;
}
if ($argv[1] === 'snapshot') {
    $result = [];
    $counters = $pdo->query('SELECT TABLE_NAME,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($php as [$name]) {
        $rows = $pdo->query('SELECT * FROM '.identifier($name))->fetchAll(PDO::FETCH_ASSOC);
        $rows = array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows);
        sort($rows, SORT_STRING);
        $result[$name] = ['rows' => count($rows), 'sha256' => hash('sha256', implode("\n", $rows)), 'next_id' => $counters[$name]];
    }
    $pdo->exec('ROLLBACK');
    // All write-capable fixture roles must be stopped before both snapshots.
    echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
    exit;
}
if ($argc !== 4) {
    throw new InvalidArgumentException('Compare requires two independent databases.');
}
$pdo->exec('ROLLBACK');
$pdo = connection($argv[3]);
$native = array_values(array_filter(catalog($pdo), static fn (array $row): bool => ! in_array($row[0], ['dw_server_schema', '_sqlx_migrations', 'dw_task_completions', 'dw_poll_receipts', 'dw_query_cache', 'dw_worker_registration_incarnations'], true)));
$pdo->exec('ROLLBACK');
// Native polling uses an additional index on an existing table. Retain all
// original column, index and constraint definitions in the comparison.
$native = array_map(static function (array $row): array {
    $row[1] = preg_replace('/,\n  KEY `dw_workflow_tasks_poll`[^\n]+/', '', $row[1]);

    return $row;
}, $native);
if ($php !== $native) {
    $expected = array_column($php, 1, 0);
    $actual = array_column($native, 1, 0);
    $different = array_filter(array_unique([...array_keys($expected), ...array_keys($actual)]), static fn (string $name): bool => ($expected[$name] ?? null) !== ($actual[$name] ?? null));
    throw new RuntimeException('Published PHP/native physical catalog mismatch: '.implode(', ', array_slice($different, 0, 10)));
}
echo json_encode(['outcome' => 'pass', 'tables' => count($php), 'catalog_sha256' => hash('sha256', json_encode($php, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR)."\n";
