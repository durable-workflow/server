<?php
declare(strict_types=1);

require '/app/vendor/autoload.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

use Workflow\Serializers\Avro;

$prefix = $argv[1] ?? '';
if (!in_array($prefix, ['representation-source', 'upgrade-source'], true)) {
    throw new RuntimeException('Expected the isolated source-author fixture prefix');
}
$database = '/state/database.sqlite';
$before = hash_file('sha256', $database);
$pdo = new PDO('sqlite:'.$database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA query_only=ON');
if ($pdo->query('PRAGMA journal_mode')->fetchColumn() !== 'delete') {
    throw new RuntimeException('Audit requires the already stopped DELETE-journal fixture');
}
$runs = $pdo->query('SELECT * FROM workflow_runs ORDER BY id LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
$workflowIds = array_column($runs, 'workflow_instance_id');
sort($workflowIds);
if ($workflowIds !== [$prefix.'-activity', $prefix.'-lease', $prefix.'-signal', $prefix.'-timer']) {
    throw new RuntimeException('This reader accepts only the four isolated source-author originals');
}
$report = ['scope' => 'published_php_stored_representation_observation', 'runs' => [],
    'table_rows' => [], 'tasks' => [], 'activities' => [], 'history' => [], 'queue_jobs' => [], 'cache_files' => []];
$expected = [[9007199254740993, null, 'upgradeλ']];
foreach ($runs as $run) {
    if ($run['payload_codec'] !== 'avro' || Avro::unserialize($run['arguments']) !== $expected) {
        throw new RuntimeException('Original stored Avro values differ');
    }
    $report['runs'][] = ['workflow_id' => $run['workflow_instance_id'], 'run_id' => $run['id'],
        'workflow_class' => $run['workflow_class'], 'workflow_type' => $run['workflow_type'],
        'status' => $run['status'], 'codec' => $run['payload_codec'],
        'arguments_sha256' => hash('sha256', $run['arguments']), 'typed_arguments_match' => true,
        'started_at' => $run['started_at'], 'run_deadline_at' => $run['run_deadline_at'],
        'execution_deadline_at' => $run['execution_deadline_at']];
}
foreach ($pdo->query("SELECT name FROM sqlite_schema WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
    $name = $table['name'];
    if (!preg_match('/\A[a-zA-Z0-9_]+\z/', $name)) throw new RuntimeException('Unexpected table name');
    $report['table_rows'][$name] = (int)$pdo->query('SELECT COUNT(*) FROM "'.$name.'"')->fetchColumn();
}
foreach ($pdo->query('SELECT * FROM workflow_tasks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $task) {
    $payload = $task['payload'] === null ? null : json_decode($task['payload'], true, flags: JSON_THROW_ON_ERROR);
    $report['tasks'][] = ['id' => $task['id'], 'run_id' => $task['workflow_run_id'], 'kind' => $task['task_type'],
        'status' => $task['status'], 'attempt_count' => $task['attempt_count'], 'payload_keys' => array_keys($payload ?? []),
        'payload_sha256' => $task['payload'] === null ? null : hash('sha256', $task['payload']),
        'lease_expires_at' => $task['lease_expires_at'], 'available_at' => $task['available_at']];
}
foreach ($pdo->query('SELECT * FROM activity_executions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $activity) {
    if ($activity['payload_codec'] !== 'avro' || Avro::unserialize($activity['arguments']) !== $expected) {
        throw new RuntimeException('Original activity Avro values differ');
    }
    $report['activities'][] = ['id' => $activity['id'], 'run_id' => $activity['workflow_run_id'],
        'activity_class' => $activity['activity_class'], 'activity_type' => $activity['activity_type'],
        'codec' => $activity['payload_codec'], 'typed_arguments_match' => true,
        'arguments_sha256' => hash('sha256', $activity['arguments']), 'status' => $activity['status'],
        'attempt_count' => $activity['attempt_count'], 'retry_policy' => json_decode($activity['retry_policy'], true, flags: JSON_THROW_ON_ERROR)];
}
foreach ($pdo->query('SELECT * FROM workflow_history_events ORDER BY workflow_run_id, sequence')->fetchAll(PDO::FETCH_ASSOC) as $event) {
    $payload = json_decode($event['payload'], true, flags: JSON_THROW_ON_ERROR);
    $report['history'][] = ['run_id' => $event['workflow_run_id'], 'sequence' => $event['sequence'],
        'event_type' => $event['event_type'], 'payload_keys' => array_keys($payload),
        'payload_sha256' => hash('sha256', $event['payload']), 'created_at' => $event['created_at']];
}
foreach ($pdo->query('SELECT id,payload FROM jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $job = json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR);
    $command = $job['data']['command'] ?? null;
    if (!is_string($command)) throw new RuntimeException('Queue command representation is unknown');
    preg_match_all('/(?:O|C):[0-9]+:"([^"\x00]+)"/', $command, $classes);
    // This is an observation of the fixture's known public string property,
    // never an authorizing PHP-object decoder or a queue conversion routine.
    preg_match('/s:6:"taskId";s:([0-9]+):"([A-Za-z0-9_-]+)";/', $command, $taskId);
    if (!isset($taskId[2]) || strlen($taskId[2]) !== (int)$taskId[1]) {
        throw new RuntimeException('Known timer job task identity is absent');
    }
    $canonical = array_values(array_filter($report['tasks'], static fn(array $task): bool => $task['id'] === $taskId[2]));
    if (count($canonical) !== 1 || $canonical[0]['kind'] !== 'timer') {
        throw new RuntimeException('Known timer job does not bind its canonical original task');
    }
    $report['queue_jobs'][] = ['id' => $row['id'], 'display_name' => $job['displayName'] ?? null,
        'handler' => $job['job'] ?? null, 'command_kind' => preg_match('/\A[OC]:[0-9]+:/', $command) ? 'php_serialized_object' : 'unknown',
        'classes' => array_values(array_unique($classes[1])), 'command_bytes' => strlen($command),
        'command_sha256' => hash('sha256', $command), 'task_id' => $taskId[2], 'canonical_timer_task_match' => true];
}
$cache = '/state/storage/framework/cache/data';
$report['cache_observation'] = is_dir($cache) ? 'mounted_file_store_only' : 'not_mounted';
$files = is_dir($cache)
    ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache, FilesystemIterator::SKIP_DOTS)) : [];
foreach ($files as $file) {
    if (!$file->isFile()) continue;
    $bytes = file_get_contents($file->getPathname());
    preg_match('/\A[0-9]{10}([aObidsNC]):/', $bytes, $kind);
    preg_match_all('/(?:O|C):[0-9]+:"([^"\x00]+)"/', $bytes, $classes);
    $inner = null;
    $innerValid = false;
    if (preg_match('/\A[0-9]{10}s:([0-9]+):"/', $bytes, $stringHeader)
        && substr($bytes, strlen($stringHeader[0]) + (int)$stringHeader[1]) === '";') {
        $string = substr($bytes, strlen($stringHeader[0]), (int)$stringHeader[1]);
        $inner = json_decode($string, true);
        $innerValid = json_last_error() === JSON_ERROR_NONE;
    }
    $report['cache_files'][] = ['size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
        'expiry_prefixed_serialized_kind' => $kind[1] ?? null, 'classes' => array_values(array_unique($classes[1])),
        'inner_json_valid' => $innerValid, 'inner_json_kind' => $innerValid ? get_debug_type($inner) : 'opaque_string',
        'inner_json_keys' => is_array($inner) ? array_slice(array_keys($inner), 0, 20) : []];
}
$serializer = (new ReflectionClass(Avro::class))->getFileName();
$report['serializer_sha256'] = hash_file('sha256', $serializer);
$pdo = null;
$report['database_sha256_before'] = $before;
$report['database_sha256_after'] = hash_file('sha256', $database);
if ($report['database_sha256_after'] !== $before) throw new RuntimeException('Source database changed');
$report += ['outcome' => 'pass', 'source_mutated' => false, 'php_objects_executed' => false,
    'backup_verified' => false, 'stored_representation_compatibility_verified' => false, 'takeover_allowed' => false];
echo json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
