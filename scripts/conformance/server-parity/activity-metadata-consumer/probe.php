<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Transport\Psr18Transport;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;

require getenv('DW_PARITY_SDK_AUTOLOAD');
[$script, $url, $phase, $identityPath] = $argv;
$profile = json_decode(file_get_contents(__DIR__.'/../../../../tests/Fixtures/ActivityMetadataConsumer/profile.json'), true, flags: JSON_THROW_ON_ERROR);
if (InstalledVersions::getPrettyVersion('durable-workflow/sdk') !== $profile['php_sdk']['version']
    || InstalledVersions::getReference('durable-workflow/sdk') !== $profile['php_sdk']['source']) {
    throw new RuntimeException('Normally installed SDK identity differs from the selected profile.');
}
$state = json_decode(file_get_contents($identityPath), true, flags: JSON_THROW_ON_ERROR);
$payloadGets = 0;
$stack = HandlerStack::create();
$stack->push(static function (callable $handler) use (&$payloadGets): callable {
    return static function (RequestInterface $request, array $options) use ($handler, &$payloadGets) {
        if ($request->getMethod() === 'GET' && str_starts_with($request->getUri()->getPath(), '/api/external-payloads/v1/')) {
            $payloadGets++;
        }

        return $handler($request, $options);
    };
});
$http = new HttpClient(['base_uri' => rtrim($url, '/'), 'http_errors' => false, 'handler' => $stack,
    'connect_timeout' => 1, 'timeout' => 5]);
$client = new Client($url, namespace: $state['namespace'], token: 'activity-metadata-fixture-token',
    transport: new Psr18Transport(client: $http));
$control = static function (string $method, string $path, array $body = []) use ($http, &$state): array {
    $response = $http->request($method, $path, ['headers' => ['Authorization' => 'Bearer activity-metadata-fixture-token',
        'X-Namespace' => $state['namespace'], 'X-Durable-Workflow-Control-Plane-Version' => '2'], 'json' => $body]);

    return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
};
$check = static function (bool $valid, string $message): void {
    if (! $valid) {
        throw new RuntimeException($message);
    }
};
$budget = static function () use (&$state, $check): void {
    $check(microtime(true) < $state['original_deadline'], 'Original metadata observation budget expired.');
};
$metadata = static function (string $label, string $status, int $attempts) use ($client, &$state, &$payloadGets, $check, $budget): array {
    $budget();
    $gets = $payloadGets;
    $response = $client->workflowActivities($state['workflow_id'], $state['run_id']);
    $check($payloadGets === $gets, 'Metadata listing fetched an external payload.');
    $activities = $response['activities'];
    $check(count($activities) === 1, 'Expected one original activity.');
    $activity = $activities[0];
    $check($activity['id'] === $state['activity_id'] && $activity['status'] === $status
        && $activity['attempt_count'] === $attempts, 'Metadata identity, status or attempt count differs: '.json_encode($activity));
    foreach (['arguments', 'result', 'exception', 'result_envelope'] as $field) {
        $check(! array_key_exists($field, $activity), 'Metadata exposed '.$field);
    }
    $state['snapshots'][$label] = $response;
    file_put_contents($state['identity_path'], json_encode($state, JSON_THROW_ON_ERROR));

    return $activity;
};
$expected = ['integer' => 9007199254740993, 'optional' => null, 'text' => '日本語 λ'];
if ($state['case'] === 'external-result') {
    $expected['large'] = str_repeat('x', 2 * 1024 * 1024);
}
$workerId = $state['workflow_id'].':worker';
if ($phase === 'author') {
    $namespace = $state['namespace'];
    if ($control('GET', '/api/namespaces/'.$namespace)[0] === 404) {
        $check($control('POST', '/api/namespaces', ['name' => $namespace])[0] === 201, 'Namespace setup failed.');
    }
    if ($state['case'] === 'external-result') {
        $check($control('PUT', '/api/namespaces/'.$namespace.'/external-storage', ['driver' => 'local', 'enabled' => true,
            'threshold_bytes' => 1048576, 'config' => ['uri' => 'file:///state/objects']])[0] === 200, 'External storage setup failed.');
    }
    $state['original_deadline'] = microtime(true) + $profile['original_observation_budget_seconds'];
    [$status, $original] = $control('POST', '/api/workflows', ['workflow_id' => $state['workflow_id'],
        'workflow_type' => 'parity.activity_metadata', 'task_queue' => $state['workflow_id'],
        'input' => $client->payloadCodec()->envelope([]), 'run_timeout_seconds' => 120, 'execution_timeout_seconds' => 600]);
    $check($status === 201, 'Original workflow admission failed: '.$status);
    $state['run_id'] = $original['run_id'];
    $client->registerWorker($workerId, $state['workflow_id'], ['parity.activity_metadata'], ['parity.metadata_value']);
    $task = $client->pollWorkflowTask($workerId, $state['workflow_id'], 0);
    $check($task !== null && $task['run_id'] === $state['run_id'], 'Original author task missing.');
    $receipt = $client->completeWorkflowTask($task['task_id'], $task['lease_owner'], $task['workflow_task_attempt'], [[
        'type' => 'schedule_activity', 'activity_type' => 'parity.metadata_value',
        'arguments' => $client->payloadCodec()->envelope([9007199254740993, null, '日本語 λ']),
        ...$profile['activity_command_options'],
        'retry_policy' => ['max_attempts' => $state['case'] === 'retry-completed' ? 2 : 1, 'backoff_seconds' => []],
    ]]);
    $check(($receipt['recorded'] ?? null) === true, 'Original activity schedule missing.');
    $events = $client->workflowHistory($state['workflow_id'], $state['run_id'])['events'];
    $scheduled = array_values(array_filter($events, static fn (array $event): bool => $event['event_type'] === 'ActivityScheduled'));
    $check(count($scheduled) === 1, 'Expected one original activity schedule.');
    $state['activity_id'] = $scheduled[0]['payload']['activity_execution_id'];
    $state['prefix'] = $events;
    $metadata('pending', 'pending', 0);
} elseif ($phase === 'activity') {
    $budget();
    $attempts = $state['case'] === 'retry-completed' ? 2 : 1;
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $task = $client->pollActivityTask($workerId, $state['workflow_id'], 0);
        $check($task !== null && $task['activity_execution_id'] === $state['activity_id'], 'Original activity task missing.');
        $state['attempt_ids'][] = $task['activity_attempt_id'];
        $state['task_ids'][] = $task['task_id'];
        $metadata('running-'.$attempt, 'running', $attempt);
        $beat = $client->heartbeatActivityTask($task['task_id'], $task['activity_attempt_id'], $task['lease_owner'],
            ['integer' => 9007199254740993, 'optional' => null, 'text' => '日本語 λ']);
        $check(($beat['can_continue'] ?? null) === true, 'Heartbeat lost original authority.');
        $observed = $metadata('heartbeat-'.$attempt, 'running', $attempt);
        $check($observed['last_heartbeat_progress']['details']['integer'] === 9007199254740993
            && $observed['last_heartbeat_progress']['details']['optional'] === null
            && $observed['last_heartbeat_progress']['details']['text'] === '日本語 λ', 'Heartbeat details changed.');
        if ($attempt < $attempts) {
            $client->failActivityTask($task['task_id'], $task['activity_attempt_id'], $task['lease_owner'], 'Authored metadata retry λ');
            $retry = $metadata('retry-pending', 'pending', 1);
            $check($retry['attempts'][0]['status'] === 'failed' && $retry['attempts'][0]['lease_expires_at'] === null,
                'Closed first attempt metadata differs.');

            continue;
        }
        if ($state['case'] === 'external-result') {
            $bytes = $client->payloadCodec()->encode($expected);
            $response = $http->post('/api/external-payloads/v1', ['headers' => ['Authorization' => 'Bearer activity-metadata-fixture-token',
                'X-Namespace' => $state['namespace'], 'Content-Type' => 'application/octet-stream',
                'X-Durable-Workflow-Payload-Codec' => 'avro', 'X-Durable-Workflow-Payload-Size' => (string) strlen($bytes),
                'X-Durable-Workflow-Payload-SHA256' => hash('sha256', $bytes)], 'body' => $bytes]);
            $check($response->getStatusCode() === 201, 'Explicit external upload failed.');
            $state['external_reference'] = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['reference'];
            $response = $http->post('/api/worker/activity-tasks/'.$task['task_id'].'/complete', ['headers' => [
                'Authorization' => 'Bearer activity-metadata-fixture-token', 'X-Namespace' => $state['namespace'],
                'X-Durable-Workflow-Protocol-Version' => '1.20'], 'json' => ['lease_owner' => $task['lease_owner'],
                    'activity_attempt_id' => $task['activity_attempt_id'], 'result' => ['codec' => 'avro', 'external_payload' => $state['external_reference']]]]);
            $check($response->getStatusCode() === 200, 'Explicit external completion failed.');
        } else {
            $client->completeActivityTask($task['task_id'], $task['activity_attempt_id'], $task['lease_owner'], $expected);
        }
    }
    $check(count(array_unique($state['attempt_ids'])) === $attempts, 'Attempt identity reused.');
    $metadata('completed-active-parent', 'completed', $attempts);
} elseif ($phase === 'unavailable') {
    $before = $state['snapshots']['completed-active-parent'];
    $metadata('external-object-unavailable', 'completed', 1);
    $check($state['snapshots']['external-object-unavailable'] === $before, 'Unavailable object changed metadata.');
} elseif ($phase === 'reader') {
    $budget();
    $before = $client->workflowHistory($state['workflow_id'], $state['run_id'])['events'];
    $result = $client->workflowActivityResult($state['workflow_id'], $state['run_id'], $state['activity_id']);
    $check($client->payloadCodec()->decodeEnvelope($result['result_envelope']) === $expected, 'Original typed result differs.');
    $check($client->workflowHistory($state['workflow_id'], $state['run_id'])['events'] === $before, 'Result read mutated history.');
    $task = $client->pollWorkflowTask($workerId, $state['workflow_id'], 0);
    $check($task !== null && $task['run_id'] === $state['run_id'], 'Original parent continuation missing.');
    $client->completeWorkflowTask($task['task_id'], $task['lease_owner'], $task['workflow_task_attempt'], [[
        'type' => 'complete_workflow', 'result' => $client->payloadCodec()->envelope('metadata parent complete'),
    ]]);
    $metadata('completed-closed-parent', 'completed', count($state['attempt_ids']));
    $state['final_history'] = $client->workflowHistory($state['workflow_id'], $state['run_id'])['events'];
    $check(array_slice($state['final_history'], 0, count($state['prefix'])) === $state['prefix'], 'Original prefix changed.');
    $counts = array_count_values(array_column($state['final_history'], 'event_type'));
    $check(($counts['ActivityCompleted'] ?? 0) === 1 && ($counts['WorkflowCompleted'] ?? 0) === 1
        && ($counts['ActivityRetryScheduled'] ?? 0) === ($state['case'] === 'retry-completed' ? 1 : 0), 'Original event counts differ.');
} elseif ($phase === 'cold-read') {
    $before = $state['snapshots']['completed-closed-parent'];
    $metadata('cold-read', 'completed', count($state['attempt_ids']));
    $check($state['snapshots']['cold-read'] === $before, 'Cold metadata changed.');
    $check($client->workflowHistory($state['workflow_id'], $state['run_id'])['events'] === $state['final_history'], 'Cold read mutated history.');
} else {
    throw new RuntimeException('Unknown metadata phase.');
}
$budget();
file_put_contents($identityPath, json_encode($state, JSON_THROW_ON_ERROR));
echo json_encode(['outcome' => 'pass', 'phase' => $phase, 'run_id' => $state['run_id'],
    'metadata_snapshots' => count($state['snapshots']), 'payload_gets' => $payloadGets], JSON_THROW_ON_ERROR)."\n";
