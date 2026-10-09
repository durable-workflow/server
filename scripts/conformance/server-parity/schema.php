<?php

declare(strict_types=1);

// Inspect two independent SQLite files without loading either runtime.
// The PHP file must come from the image pinned in php-baseline.json; this
// checks the real published catalog, not a second copy of the native DDL.
if ($argc !== 3) {
    throw new InvalidArgumentException('Usage: php schema.php PHP_SQLITE NATIVE_SQLITE');
}

function catalog(string $filename): array
{
    if (! is_file($filename)) {
        throw new RuntimeException('Schema comparison requires existing SQLite files.');
    }
    $path = realpath($filename);
    $originals = [];
    foreach ([$path, $path.'-wal', $path.'-journal'] as $file) {
        if (is_file($file)) {
            $originals[$file] = hash_file('sha256', $file);
        }
    }
    $uri = 'file:'.str_replace('%2F', '/', rawurlencode($path)).'?mode=ro';
    $pdo = new PDO('sqlite:'.$uri, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $rows = $pdo->query("SELECT type,name,tbl_name,sql FROM sqlite_schema WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY type,name")
        ->fetchAll(PDO::FETCH_ASSOC);
    $pdo = null;
    foreach ($originals as $file => $hash) {
        if (! is_file($file) || hash_file('sha256', $file) !== $hash) {
            throw new RuntimeException('Read-only catalog inspection changed database or journal bytes.');
        }
    }

    return $rows;
}

$php = catalog($argv[1]);
$native = catalog($argv[2]);
$nativeOnly = ['_sqlx_migrations', 'dw_server_schema', 'dw_task_completions', 'dw_poll_receipts', 'dw_workflow_tasks_poll', 'dw_query_cache'];
$native = array_values(array_filter($native, static fn (array $row): bool => ! in_array($row['name'], $nativeOnly, true)));
if ($php !== $native) {
    $byName = static fn (array $rows): array => array_column($rows, null, 'name');
    $phpByName = $byName($php);
    $nativeByName = $byName($native);
    $different = array_filter(array_unique([...array_keys($phpByName), ...array_keys($nativeByName)]),
        static fn (string $name): bool => ($phpByName[$name] ?? null) !== ($nativeByName[$name] ?? null));
    throw new RuntimeException('Published PHP/native catalog mismatch: '.implode(', ', $different));
}

$counts = array_count_values(array_column($php, 'type'));
echo json_encode(['outcome' => 'pass', 'tables' => $counts['table'] ?? 0, 'indexes' => $counts['index'] ?? 0,
    'database_and_existing_journals_unchanged' => true,
    'catalog_sha256' => hash('sha256', json_encode($php, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR)."\n";
