<?php

declare(strict_types=1);

// Raw worker HTTP and the claim predicate live in ready-age-claim.php.
require_once __DIR__.'/ready-age-claim.php';

if ($argc !== 4 || ! in_array($argv[1], ['prepare', 'finish'], true)) {
    throw new InvalidArgumentException('Usage: ready-age-restart.php prepare|finish URL RECEIPT');
}
[$script, $phase, $url, $path] = $argv;
if ($phase === 'prepare') {
    require __DIR__.'/vendor/autoload.php';
    $client = new \DurableWorkflow\Client($url, namespace: 'default',
        token: getenv('DW_PARITY_TOKEN') ?: null, workerProtocolVersion: '1.20');
    $claims = [];
    foreach (['workflow', 'activity'] as $kind) {
        $queue = 'ready-age-restart-'.$kind;
        $worker = $queue.'-worker';
        // Reuse published admission/Avro authoring, including its required
        // affinity manifest. The measurement comparison uses raw poll bytes.
        $client->registerWorker($worker, $queue, ['ready-age-restart'], ['ready-age-restart']);
        $client->startWorkflow('ready-age-restart', $queue, $queue, ['source restart']);
        $request = ['worker_id' => $worker, 'task_queue' => $queue, 'timeout_seconds' => 0, 'poll_request_id' => $queue];
        $workflowRequest = $kind === 'activity' ? [...$request, 'poll_request_id' => $queue.'-author'] : $request;
        $response = readyAgeRequest($url, '/api/worker/workflow-tasks/poll', $workflowRequest);
        if ($kind === 'activity') {
            $task = $response['task'];
            readyAgeRequest($url, '/api/worker/workflow-tasks/'.$task['task_id'].'/complete', [
                'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
                'commands' => [['type' => 'schedule_activity', 'activity_type' => 'ready-age-restart',
                    'arguments' => $client->payloadCodec()->envelope(['source restart'])]]]);
            $response = readyAgeRequest($url, '/api/worker/activity-tasks/poll', $request);
        }
        $claims[$kind] = ['request' => $request, 'claim' => readyAgeClaim($response, ['phase' => 'prepare', 'kind' => $kind])];
    }
    file_put_contents($path, json_encode(['prepared_at' => microtime(true), 'claims' => $claims], JSON_THROW_ON_ERROR)."\n");
    echo json_encode(['outcome' => 'prepared', 'claims' => array_keys($claims)], JSON_THROW_ON_ERROR)."\n";
    exit;
}
$receipt = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
if (microtime(true) - $receipt['prepared_at'] < 1) {
    throw new RuntimeException('The recovered read did not cross an elapsed process boundary.');
}
foreach ($receipt['claims'] as $kind => $saved) {
    $response = readyAgeRequest($url, '/api/worker/'.$kind.'-tasks/poll', $saved['request']);
    $actual = readyAgeClaim($response, ['phase' => 'finish', 'kind' => $kind,
        'seconds_since_prepare' => round(microtime(true) - $receipt['prepared_at'], 3),
        'saved_task_id' => $saved['claim']['task_id'] ?? null]);
    if ($actual !== $saved['claim']) {
        $changed = array_values(array_filter(array_unique([...array_keys($actual), ...array_keys($saved['claim'])]),
            static fn (string $key): bool => ! array_key_exists($key, $actual) || ! array_key_exists($key, $saved['claim']) || $actual[$key] !== $saved['claim'][$key]));
        throw new RuntimeException('Cold poll replay changed original '.$kind.' fields: '.implode(',', $changed));
    }
}
echo json_encode(['outcome' => 'pass', 'claims' => ['workflow', 'activity'], 'source_only' => true], JSON_THROW_ON_ERROR)."\n";
