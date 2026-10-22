<?php

declare(strict_types=1);

function readEvidence(string $name): array
{
    return json_decode(trim(file_get_contents('/evidence/'.$name)), true, flags: JSON_THROW_ON_ERROR);
}
$result = readEvidence('loss2-result.log');
$runId = $result['run_id'];
$final = readEvidence('final.json');
$before = readEvidence('loss2-before-kill.json');
$after = readEvidence('loss2-after-kill.json');
$forRun = static fn (array $rows): array => array_values(array_filter($rows,
    static fn (array $row): bool => ($row['workflow_run_id'] ?? $row['run_id'] ?? null) === $runId));
$run = array_values(array_filter($final['runs'], static fn (array $row): bool => $row['id'] === $runId));
if ($result['result'] !== [9007199254740993, null, 'λ'] || $result['elapsed_seconds'] >= 30
    || count($run) !== 1 || $run[0]['status'] !== 'completed' || $run[0]['namespace'] !== 'lease-loss') {
    throw new RuntimeException('The bounded worker-loss result contract failed.');
}
$invocations = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
    file('/evidence/state-loss2/invocations.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
if (array_column($invocations, 'attempt') !== [1, 2]
    || readEvidence('loss2-helpers-before.json')['count'] !== 1
    || readEvidence('loss2-helpers-after.json')['count'] !== 0) {
    throw new RuntimeException('Owner/helper lifecycle or invocation identity failed.');
}
$taskId = $invocations[0]['task_id'];
$beforeTask = array_column($before['tasks'], null, 'id')[$taskId];
$afterTask = array_column($after['tasks'], null, 'id')[$taskId];
if ($beforeTask['lease_expires_at'] !== $afterTask['lease_expires_at']) {
    throw new RuntimeException('The killed owner retained lease-renewal authority.');
}
$attempts = $forRun($final['attempts']);
if (array_column($attempts, 'attempt_number') !== [1, 2]
    || array_column($attempts, 'status') !== ['expired', 'completed']
    || count(array_unique(array_column($attempts, 'activity_execution_id'))) !== 1) {
    throw new RuntimeException('Expiry/retry did not preserve the original activity.');
}
foreach ($attempts as $attempt) {
    if ($attempt['last_heartbeat_at'] !== null) {
        throw new RuntimeException('The control recorded an application heartbeat.');
    }
}
$events = $forRun($final['events']);
$counts = array_count_values(array_column($events, 'event_type'));
if (($counts['ActivityStarted'] ?? 0) !== 2 || ($counts['ActivityCompleted'] ?? 0) !== 1
    || ($counts['ActivityRetryScheduled'] ?? 0) !== 1 || ($counts['WorkflowCompleted'] ?? 0) !== 1
    || ($counts['ActivityHeartbeatRecorded'] ?? 0) !== 0 || $final['failed_jobs'] !== 0) {
    throw new RuntimeException('The durable retry outcomes or cardinality failed.');
}
$retry = $forRun(readEvidence('retries.json'));
if (count($retry) !== 1 || $retry[0]['payload']['failure_reason'] !== 'lease_expired'
    || $retry[0]['payload']['retry_of_task_id'] !== $invocations[0]['task_id']
    || $retry[0]['payload']['retry_task_id'] !== $invocations[1]['task_id']
    || $retry[0]['payload']['retry_policy']['max_attempts'] !== 2) {
    throw new RuntimeException('The retry lost the original task/policy binding.');
}
foreach ($forRun($final['tasks']) as $task) {
    if ($task['repair_count'] !== 0) {
        throw new RuntimeException('An unrelated task repair occurred.');
    }
}
$byId = array_column($events, null, 'id');
foreach ($forRun($before['events']) as $event) {
    if (($byId[$event['id']]['sha256'] ?? null) !== $event['sha256']) {
        throw new RuntimeException('Previously recorded history changed.');
    }
}
$ready = readEvidence('loss-readiness.json');
if ($ready['topology']['matching_role']['discovery_limits']['activity_task_lease_seconds'] !== 5) {
    throw new RuntimeException('The runtime did not use the configured five-second lease.');
}
echo json_encode(['status' => 'PASS', 'elapsed_seconds' => $result['elapsed_seconds'],
    'namespace' => $run[0]['namespace'], 'attempts' => [1, 2], 'expired_owner_helper_count' => 0,
    'failure_reason' => $retry[0]['payload']['failure_reason'], 'events' => $counts,
    'preserved_history_rows' => count($forRun($before['events'])), 'application_heartbeats' => 0], JSON_THROW_ON_ERROR)."\n";
