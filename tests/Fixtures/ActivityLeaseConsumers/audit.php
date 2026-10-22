<?php
declare(strict_types=1);
$path = '/data/database.sqlite';
$before = hash_file('sha256', $path);
$db = new PDO('sqlite:'.$path, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
]);
$db->exec('PRAGMA busy_timeout=1000');
$db->beginTransaction();
$tables = ['workflow_instances', 'workflow_runs', 'workflow_tasks',
    'activity_executions', 'activity_attempts', 'workflow_history_events', 'workflow_worker_registrations'];
$result = [];
foreach ($tables as $table) {
    $result[$table] = $db->query('SELECT * FROM '.$table.' ORDER BY id LIMIT 1001')->fetchAll(PDO::FETCH_ASSOC);
    if (count($result[$table]) > 1000) {
        throw new RuntimeException('The disposable consumer database exceeded its audit bound.');
    }
}
$result['failed_jobs'] = (int) $db->query('SELECT COUNT(*) FROM failed_jobs')->fetchColumn();
$db->commit();
unset($db);
$result['read_only_sha256_before'] = $before;
$result['read_only_sha256_after'] = hash_file('sha256', $path);
if (! in_array('--live', $argv, true)
    && $result['read_only_sha256_before'] !== $result['read_only_sha256_after']) {
    throw new RuntimeException('Read-only audit changed the source database.');
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
