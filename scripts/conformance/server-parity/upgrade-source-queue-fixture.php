<?php
declare(strict_types=1);

// Export only the isolated two-original source fixture after its sole API exits.
// This neither decodes nor executes a PHP object and grants no takeover authority.
$image = $argv[1] ?? '';
if (!preg_match('/\Aghcr\.io\/durable-workflow\/server@sha256:[a-f0-9]{64}\z/', $image)) {
    throw new RuntimeException('Expected the exact published source image');
}
$database = '/state/database.sqlite';
$before = hash_file('sha256', $database);
$pdo = new PDO('sqlite:'.$database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA query_only=ON');
if ($pdo->query('PRAGMA journal_mode')->fetchColumn() !== 'delete') {
    throw new RuntimeException('Expected the stopped DELETE-journal fixture');
}
$runs = $pdo->query('SELECT id,workflow_instance_id,workflow_class,workflow_type,namespace,queue,status FROM workflow_runs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$ids = array_column($runs, 'workflow_instance_id');
sort($ids);
if ($ids !== ['upgrade-source-activity', 'upgrade-source-timer']) {
    throw new RuntimeException('Refusing unrelated application data');
}
$jobs = $pdo->query('SELECT id,queue,payload,attempts,reserved_at,available_at,created_at FROM jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$tasks = $pdo->query('SELECT id,workflow_run_id,namespace,task_type,status,connection,queue,available_at,lease_owner,lease_expires_at,attempt_count FROM workflow_tasks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
if (count($jobs) !== 1 || count($tasks) !== 4) {
    throw new RuntimeException('Original pending source differs');
}
$pdo = null;
if (hash_file('sha256', $database) !== $before) {
    throw new RuntimeException('Source database changed');
}
echo json_encode([
    'scope' => 'genuine_published_php_pending_queue_and_canonical_tasks',
    'source_image' => $image,
    'database_sha256' => $before,
    'configuration' => ['connection' => 'database', 'default_connection' => 'database', 'default_queue' => 'default'],
    'jobs' => $jobs, 'tasks' => $tasks, 'runs' => $runs,
    'source_mutated' => false, 'php_objects_executed' => false, 'takeover_allowed' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
