<?php

declare(strict_types=1);

$db = new PDO('sqlite:/app/database/database.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
]);
$db->exec('PRAGMA busy_timeout=1000');
$runs = $db->query('SELECT id, workflow_instance_id, namespace, status FROM workflow_runs ORDER BY created_at')
    ->fetchAll(PDO::FETCH_ASSOC);
$tasks = $db->query('SELECT id, workflow_run_id, task_type, status, attempt_count, repair_count, leased_at, lease_expires_at FROM workflow_tasks ORDER BY created_at')
    ->fetchAll(PDO::FETCH_ASSOC);
$events = [];
foreach ($db->query('SELECT * FROM workflow_history_events ORDER BY workflow_run_id, sequence')->fetchAll(PDO::FETCH_ASSOC) as $event) {
    $events[] = ['id' => $event['id'], 'run_id' => $event['workflow_run_id'],
        'sequence' => $event['sequence'], 'event_type' => $event['event_type'],
        'sha256' => hash('sha256', serialize($event))];
}
$activities = $db->query('SELECT id, workflow_run_id, status, attempt_count, current_attempt_id, started_at, closed_at, last_heartbeat_at FROM activity_executions ORDER BY created_at')
    ->fetchAll(PDO::FETCH_ASSOC);
$attempts = $db->query('SELECT id, workflow_run_id, activity_execution_id, attempt_number, status, lease_expires_at, last_heartbeat_at FROM activity_attempts ORDER BY created_at')
    ->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['at' => gmdate('c'), 'runs' => $runs, 'tasks' => $tasks,
    'events' => $events, 'activities' => $activities, 'attempts' => $attempts,
    'failed_jobs' => (int) $db->query('SELECT COUNT(*) FROM failed_jobs')->fetchColumn()], JSON_THROW_ON_ERROR)."\n";
