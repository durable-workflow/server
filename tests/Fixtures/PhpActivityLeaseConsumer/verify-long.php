<?php

declare(strict_types=1);

$prefix = json_decode(file_get_contents('/evidence/long-prefix.json'), true, flags: JSON_THROW_ON_ERROR);
$final = json_decode(file_get_contents('/evidence/long-final.json'), true, flags: JSON_THROW_ON_ERROR);
$result = json_decode(trim(file_get_contents('/evidence/long-result.log')), true, flags: JSON_THROW_ON_ERROR);
$invocations = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
    file('/evidence/state-long/invocations.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
if ($result['result'] !== [9007199254740993, null, 'λ'] || $result['elapsed_seconds'] < 360
    || count($invocations) !== 1 || $invocations[0]['attempt'] !== 1) {
    throw new RuntimeException('The real-duration activity/result contract failed.');
}
if (count($final['runs']) !== 1 || $final['runs'][0]['status'] !== 'completed'
    || $final['runs'][0]['namespace'] !== 'default' || count($final['tasks']) !== 3) {
    throw new RuntimeException('The original workflow or task set did not complete.');
}
foreach ($final['tasks'] as $task) {
    if ($task['status'] !== 'completed' || $task['attempt_count'] !== 1 || $task['repair_count'] !== 0) {
        throw new RuntimeException('An original task retried or required repair.');
    }
}
foreach (['activities', 'attempts'] as $key) {
    if (count($final[$key]) !== 1 || $final[$key][0]['status'] !== 'completed'
        || $final[$key][0]['last_heartbeat_at'] !== null) {
        throw new RuntimeException('Activity cardinality, completion or heartbeat contract failed.');
    }
}
$counts = array_count_values(array_column($final['events'], 'event_type'));
foreach (['ActivityStarted', 'ActivityCompleted', 'WorkflowCompleted'] as $name) {
    if (($counts[$name] ?? 0) !== 1) {
        throw new RuntimeException('Unexpected event cardinality: '.$name);
    }
}
if (($counts['ActivityHeartbeatRecorded'] ?? 0) !== 0 || $final['failed_jobs'] !== 0) {
    throw new RuntimeException('Runtime maintenance produced progress events or failed jobs.');
}
$finalById = array_column($final['events'], null, 'id');
foreach ($prefix['events'] as $event) {
    if (($finalById[$event['id']]['sha256'] ?? null) !== $event['sha256']) {
        throw new RuntimeException('Previously recorded history bytes changed.');
    }
}
echo json_encode(['status' => 'PASS', 'elapsed_seconds' => $result['elapsed_seconds'],
    'tasks' => count($final['tasks']), 'events' => $counts,
    'preserved_history_rows' => count($prefix['events']), 'invocations' => count($invocations),
    'application_heartbeats' => 0, 'repairs' => 0], JSON_THROW_ON_ERROR)."\n";
