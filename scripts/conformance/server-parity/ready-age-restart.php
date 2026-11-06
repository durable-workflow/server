<?php

declare(strict_types=1);

// The owning source job kills the native process between these two phases.
// Raw worker HTTP isolates this optional producer field from SDK observations.
function readyAgeRequest(string $url, string $path, array $body): array
{
    $headers = ['Content-Type: application/json', 'X-Namespace: default',
        'X-Durable-Workflow-Protocol-Version: 1.20',
        'Authorization: Bearer '.(getenv('DW_PARITY_TOKEN') ?: 'parity-test-token')];
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers),
        'content' => json_encode($body, JSON_THROW_ON_ERROR), 'timeout' => 5, 'ignore_errors' => true]]);
    $response = file_get_contents(rtrim($url, '/').$path, false, $context);
    $status = $http_response_header[0] ?? '';
    if ($response === false || preg_match('/^HTTP\/\S+ 2[0-9]{2}\b/', $status) !== 1) {
        throw new RuntimeException('Ready-age source request refused: '.$path.' '.$status);
    }

    return json_decode($response, true, flags: JSON_THROW_ON_ERROR);
}

function readyAgeClaim(array $response): array
{
    $task = $response['task'] ?? null;
    if (($response['server_capabilities']['claimed_task_ready_age'] ?? null) !== true || ! is_array($task)
        || ! is_int($task['ready_age_ms'] ?? null) || $task['ready_age_ms'] < 0 || $task['ready_age_ms'] > 9007199254740991) {
        throw new RuntimeException('Native successful claim lacks its declared literal ready-age value.');
    }
    $projection = array_intersect_key($task, array_flip(['task_id', 'run_id', 'workflow_task_attempt', 'activity_attempt_id', 'attempt_number', 'ready_age_ms']));
    if (! is_string($projection['task_id'] ?? null) || ! is_string($projection['run_id'] ?? null)) {
        throw new RuntimeException('Ready-age claim lost its original task/run identity.');
    }

    return $projection;
}

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
        $claims[$kind] = ['request' => $request, 'claim' => readyAgeClaim($response)];
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
    if (readyAgeClaim($response) !== $saved['claim']) {
        throw new RuntimeException('Cold poll replay changed the original '.$kind.' claim or measurement.');
    }
}
echo json_encode(['outcome' => 'pass', 'claims' => ['workflow', 'activity'], 'source_only' => true], JSON_THROW_ON_ERROR)."\n";
