<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Transport\Psr18Transport;
use GuzzleHttp\Client as HttpClient;

require getenv('DW_PARITY_SDK_AUTOLOAD');
[$script, $url, $workflowId, $phase] = $argv;
$http = new HttpClient(['base_uri' => rtrim($url, '/'), 'http_errors' => false,
    'connect_timeout' => 1, 'timeout' => 5]);
$client = new Client($url, token: 'external-fixture-token', transport: new Psr18Transport(client: $http));
$control = static function (string $method, string $path, array $body = []) use ($http): array {
    $response = $http->request($method, $path, ['headers' => ['Authorization' => 'Bearer external-fixture-token',
        'X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2'], 'json' => $body]);

    return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
};
$expected = ['text' => str_repeat('x', 3 * 1024 * 1024), 'number' => 9007199254740993,
    'optional' => null, 'unicode' => 'rangeλ'];
$activityResult = str_starts_with($phase, 'activity-');
if ($phase === 'author' || $phase === 'activity-author') {
    // Explicit byte upload and the public task-completion API exercise the
    // installed SDK without advertising automatic upload for this partial backend.
    if ($control('GET', '/api/namespaces/default')[0] === 404
        && $control('POST', '/api/namespaces', ['name' => 'default'])[0] !== 201) {
        throw new RuntimeException('Namespace setup failed');
    }
    if ($control('PUT', '/api/namespaces/default/external-storage', ['driver' => 'local', 'enabled' => true,
        'threshold_bytes' => 16, 'config' => ['uri' => 'file:///state/objects']])[0] !== 200) {
        throw new RuntimeException('Local policy setup failed');
    }
    $started = microtime(true);
    [$status, $original] = $control('POST', '/api/workflows', ['workflow_id' => $workflowId,
        'workflow_type' => 'parity.v1.external_result', 'task_queue' => $workflowId,
        'input' => $client->payloadCodec()->envelope([]), 'run_timeout_seconds' => 120,
        'execution_timeout_seconds' => 600]);
    if ($status !== 201) {
        throw new RuntimeException('Original admission failed: '.$status);
    }
    $workerId = $workflowId.':author';
    $client->registerWorker($workerId, $workflowId, ['parity.v1.external_result'],
        $activityResult ? ['parity.v1.external_activity'] : [], capabilities: []);
    $task = $client->pollWorkflowTask($workerId, $workflowId, 0);
    if ($task === null || $task['run_id'] !== $original['run_id']) {
        throw new RuntimeException('Original task was not delivered');
    }
    if ($activityResult) {
        $client->completeWorkflowTask($task['task_id'], $task['lease_owner'], $task['workflow_task_attempt'], [[
            'type' => 'schedule_activity', 'activity_type' => 'parity.v1.external_activity',
            'arguments' => $client->payloadCodec()->envelope([]),
        ]]);
        $task = $client->pollActivityTask($workerId, $workflowId, 0);
        if ($task === null || $task['run_id'] !== $original['run_id']) {
            throw new RuntimeException('Original activity task was not delivered');
        }
    }
    $blob = $client->payloadCodec()->encode($expected);
    $upload = $http->post('/api/external-payloads/v1', ['headers' => ['Authorization' => 'Bearer external-fixture-token',
        'X-Namespace' => 'default', 'Content-Type' => 'application/octet-stream',
        'X-Durable-Workflow-Payload-Codec' => 'avro', 'X-Durable-Workflow-Payload-Size' => (string) strlen($blob),
        'X-Durable-Workflow-Payload-SHA256' => hash('sha256', $blob)], 'body' => $blob]);
    if ($upload->getStatusCode() !== 201) {
        throw new RuntimeException('Original result upload failed: '.$upload->getStatusCode());
    }
    $reference = json_decode((string) $upload->getBody(), true, flags: JSON_THROW_ON_ERROR)['reference'];
    if ($activityResult) {
        // The installed activity convenience API encodes application values.
        // Submit this explicit reference with the existing HTTP wire contract.
        $response = $http->post('/api/worker/activity-tasks/'.$task['task_id'].'/complete', [
            'headers' => ['Authorization' => 'Bearer external-fixture-token', 'X-Namespace' => 'default',
                'X-Durable-Workflow-Protocol-Version' => '1.20'],
            'json' => ['lease_owner' => $task['lease_owner'], 'activity_attempt_id' => $task['activity_attempt_id'],
                'result' => ['codec' => 'avro', 'external_payload' => $reference]],
        ]);
        $completion = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Explicit activity completion failed: '.$response->getStatusCode());
        }
    } else {
        $completion = $client->completeWorkflowTask($task['task_id'], $task['lease_owner'], $task['workflow_task_attempt'], [
            ['type' => 'complete_workflow', 'result' => ['codec' => 'avro', 'external_payload' => $reference]],
        ]);
    }
    if (($completion['recorded'] ?? null) !== true || microtime(true) >= $started + 30) {
        throw new RuntimeException('Original completion failed within its observation budget');
    }
    echo json_encode(['outcome' => 'pass', 'phase' => $phase, 'run_id' => $original['run_id'],
        'task_id' => $task['task_id'], 'reference' => $reference, 'started_at' => $started,
        'original_deadline' => $started + 30, 'encoded_bytes' => strlen($blob),
        'encoded_sha256' => hash('sha256', $blob), 'sdk_source' => InstalledVersions::getReference('durable-workflow/sdk')], JSON_THROW_ON_ERROR)."\n";
} elseif ($phase === 'activity-reader') {
    $author = json_decode(file_get_contents($argv[4]), true, flags: JSON_THROW_ON_ERROR);
    $task = $client->pollWorkflowTask($workflowId.':author', $workflowId, 0);
    if ($task === null || $task['run_id'] !== $author['run_id']) {
        throw new RuntimeException('Cold workflow reader did not receive its original run');
    }
    $completed = array_values(array_filter($task['history_events'],
        static fn (array $event): bool => $event['event_type'] === 'ActivityCompleted'));
    if (count($completed) !== 1 || $client->payloadCodec()->decodeEnvelope($completed[0]['payload']['result']) !== $expected) {
        throw new RuntimeException('Cold installed SDK did not hydrate the exact activity result');
    }
    $summary = ['text_bytes' => strlen($expected['text']), 'text_sha256' => hash('sha256', $expected['text']),
        'number' => $expected['number'], 'optional' => $expected['optional'], 'unicode' => $expected['unicode']];
    $completion = $client->completeWorkflowTask($task['task_id'], $task['lease_owner'], $task['workflow_task_attempt'], [
        ['type' => 'complete_workflow', 'result' => $client->payloadCodec()->envelope($summary)],
    ]);
    $path = '/api/workflows/'.$workflowId.'/runs/'.$author['run_id'];
    [$status, $before] = $control('GET', $path);
    [$historyStatus, $historyBefore] = $control('GET', $path.'/history');
    $actual = $client->describeWorkflow($workflowId, $author['run_id'])->output;
    [$afterStatus, $after] = $control('GET', $path);
    [$afterHistoryStatus, $historyAfter] = $control('GET', $path.'/history');
    if (($completion['recorded'] ?? null) !== true || $status !== 200 || $afterStatus !== 200
        || $historyStatus !== 200 || $afterHistoryStatus !== 200 || $before['status'] !== 'completed'
        || $actual !== $summary || $before !== $after || $historyBefore !== $historyAfter
        || microtime(true) >= $author['original_deadline']) {
        throw new RuntimeException('Original activity and cold workflow did not converge');
    }
    echo json_encode(['outcome' => 'pass', 'phase' => $phase, 'run_id' => $author['run_id'],
        'sdk_version' => InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
        'sdk_source' => InstalledVersions::getReference('durable-workflow/sdk'),
        'encoded_bytes' => $author['encoded_bytes'], 'encoded_sha256' => $author['encoded_sha256'],
        'result' => $summary, 'activity_completions' => count($completed), 'original_budget_seconds' => 30,
        'elapsed_seconds' => microtime(true) - $author['started_at'], 'history_unchanged' => true], JSON_THROW_ON_ERROR)."\n";
} elseif ($phase === 'reader') {
    $author = json_decode(file_get_contents($argv[4]), true, flags: JSON_THROW_ON_ERROR);
    $path = '/api/workflows/'.$workflowId.'/runs/'.$author['run_id'];
    [$status, $before] = $control('GET', $path);
    [$historyStatus, $historyBefore] = $control('GET', $path.'/history');
    $actual = $client->describeWorkflow($workflowId, $author['run_id'])->output;
    [$afterStatus, $after] = $control('GET', $path);
    [$afterHistoryStatus, $historyAfter] = $control('GET', $path.'/history');
    if ($status !== 200 || $afterStatus !== 200 || $historyStatus !== 200 || $afterHistoryStatus !== 200
        || $before['status'] !== 'completed' || $actual !== $expected || $before !== $after
        || $historyBefore !== $historyAfter || microtime(true) >= $author['original_deadline']) {
        throw new RuntimeException('Cold installed result read changed or failed the original');
    }
    echo json_encode(['outcome' => 'pass', 'phase' => $phase, 'run_id' => $author['run_id'],
        'sdk_version' => InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
        'sdk_source' => InstalledVersions::getReference('durable-workflow/sdk'),
        'encoded_bytes' => $author['encoded_bytes'], 'encoded_sha256' => $author['encoded_sha256'],
        'result_text_bytes' => strlen($actual['text']), 'result_text_sha256' => hash('sha256', $actual['text']),
        'number' => $actual['number'], 'optional' => $actual['optional'], 'unicode' => $actual['unicode'],
        'original_budget_seconds' => 30, 'elapsed_seconds' => microtime(true) - $author['started_at'],
        'describe_unchanged' => true, 'history_unchanged' => true], JSON_THROW_ON_ERROR)."\n";
} else {
    throw new RuntimeException('Unknown fixture phase');
}
