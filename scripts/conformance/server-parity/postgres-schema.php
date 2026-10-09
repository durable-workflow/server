<?php

declare(strict_types=1);

// Inspect PostgreSQL directly, without starting Laravel or either engine.
// The first database in compare mode must come from the frozen PHP image.
if (! in_array($argc, [3, 4], true) || ! in_array($argv[1], ['catalog', 'snapshot', 'compare'], true)) {
    throw new InvalidArgumentException('Usage: postgres-schema.php catalog|snapshot DATABASE | compare PHP_DATABASE NATIVE_DATABASE');
}

function connection(string $database): PDO
{
    if (! preg_match('/^[a-zA-Z0-9_]{1,63}$/D', $database)) {
        throw new InvalidArgumentException('Use an isolated development database name.');
    }
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('DB_PORT') ?: 5432);
    $pdo = new PDO("pgsql:host={$host};port={$port};dbname={$database}", getenv('DB_USERNAME') ?: '', getenv('DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET search_path TO public; SET timezone TO 'UTC'; BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY");

    return $pdo;
}

function catalog(string $database): array
{
    $pdo = connection($database);
    $query = file_get_contents(__DIR__.'/../../../rust/src/runtime/postgres-catalog.sql');
    $rows = $pdo->query($query)->fetchAll(PDO::FETCH_NUM);
    foreach ($rows as &$row) {
        // PHP's 64-bit integers preserve sequence maxima. Do not round-trip
        // this catalog through JavaScript's double-valued JSON numbers.
        $row[2] = json_decode($row[2], true, 512, JSON_THROW_ON_ERROR);
    }
    unset($row);
    $pdo->exec('ROLLBACK');

    return $rows;
}

if ($argv[1] === 'snapshot') {
    $pdo = connection($argv[2]);
    $result = [];
    $relations = $pdo->query("SELECT c.relname::text,c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind IN ('r','p','S') ORDER BY c.relname::text")->fetchAll(PDO::FETCH_NUM);
    foreach ($relations as [$name, $kind]) {
        $identifier = 'public."'.str_replace('"', '""', $name).'"';
        $sql = $kind === 'S' ? "SELECT row_to_json(t)::text FROM (SELECT last_value,is_called FROM {$identifier}) t"
            : "SELECT row_to_json(t)::text FROM {$identifier} t ORDER BY row_to_json(t)::text COLLATE \"C\"";
        $statement = $pdo->query($sql);
        $hash = hash_init('sha256');
        $count = 0;
        while (($row = $statement->fetchColumn()) !== false) {
            hash_update($hash, $row."\n");
            $count++;
        }
        $result[$name] = ['rows' => $count, 'sha256' => hash_final($hash)];
    }
    $pdo->exec('ROLLBACK');
    // The isolated engines must be stopped before these snapshots. Sequence
    // counters are not MVCC data; this is a refusal receipt, not a backup tool.
    echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
    exit;
}

$php = catalog($argv[2]);
if ($argv[1] === 'catalog') {
    echo "[\n".implode(",\n", array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $php))."\n]\n";
    exit;
}
if ($argc !== 4) {
    throw new InvalidArgumentException('Compare requires two independent databases.');
}
$native = catalog($argv[3]);
$tables = ['_sqlx_migrations', 'dw_server_schema', 'dw_task_completions', 'dw_poll_receipts', 'dw_query_cache'];
$indexes = ['_sqlx_migrations_pkey', 'dw_task_completions_pkey', 'dw_poll_receipts_pkey', 'dw_workflow_tasks_poll', 'dw_query_cache_pkey'];
$native = array_values(array_filter($native, static function (array $row) use ($tables, $indexes): bool {
    if (in_array($row[0], ['column', 'constraint'], true)) {
        return ! in_array(explode('.', $row[1], 2)[0], $tables, true);
    }

    return ! in_array($row[1], [...$tables, ...$indexes], true);
}));
if ($php !== $native) {
    $byKey = static function (array $rows): array {
        $result = [];
        foreach ($rows as $row) {
            $result[$row[0].':'.$row[1]] = $row[2];
        }

        return $result;
    };
    $expected = $byKey($php);
    $actual = $byKey($native);
    $different = array_filter(array_unique([...array_keys($expected), ...array_keys($actual)]),
        static fn (string $key): bool => ($expected[$key] ?? null) !== ($actual[$key] ?? null));
    throw new RuntimeException('Published PHP/native PostgreSQL physical catalog mismatch: '.implode(', ', array_slice($different, 0, 10)));
}
$counts = array_count_values(array_column($php, 0));
echo json_encode(['outcome' => 'pass', 'tables' => ($counts['relation'] ?? 0) - ($counts['sequence'] ?? 0),
    'indexes' => $counts['index'] ?? 0, 'constraints' => $counts['constraint'] ?? 0,
    'sequences' => $counts['sequence'] ?? 0,
    'catalog_sha256' => hash('sha256', json_encode($php, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR)."\n";
