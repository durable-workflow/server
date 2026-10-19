<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Transport\Psr18Transport;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;

// This is an installed SDK consumer. It never loads Server models or edits a database.
require getenv('DW_PARITY_SDK_AUTOLOAD');
[$script, $url, $phase, $identityPath] = $argv;
$profile = json_decode(file_get_contents(__DIR__.'/../../../tests/Fixtures/ActivityMetadataProjection/consumer.json'), true, flags: JSON_THROW_ON_ERROR);
if (InstalledVersions::getPrettyVersion('durable-workflow/sdk') !== $profile['php_sdk']['version']
    || InstalledVersions::getReference('durable-workflow/sdk') !== $profile['php_sdk']['source']) {
    throw new RuntimeException('Installed SDK identity differs from the consumer profile.');
}
$state = json_decode(file_get_contents($identityPath), true, flags: JSON_THROW_ON_ERROR);
$workflowId = $state['workflow_id'];
$namespace = $state['namespace'];
$payloadGets = 0;
$stack = HandlerStack::create();
$stack->push(static function (callable $handler) use (&$payloadGets): callable {
    return static function (RequestInterface $request, array $options) use ($handler, &$payloadGets) {
        if ($request->getMethod() === 'GET' && str_starts_with($request->getUri()->getPath(), '/api/external-payloads/')) {
            $payloadGets++;
        }

        return $handler($request, $options);
    };
});
$http = new HttpClient(['base_uri' => rtrim($url, '/'), 'handler' => $stack,
    'http_errors' => false, 'connect_timeout' => 1, 'timeout' => 5]);
$client = new Client($url, namespace: $namespace, token: 'metadata-fixture-token',
    transport: new Psr18Transport(client: $http));
$require = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$budget = static function () use (&$state, $require): void {
    $require(microtime(true) < $state['original_deadline'], 'Original observation budget expired.');
};
$details = ['boolean' => false, 'int64' => 9007199254740993, 'text' => '日本語λ', 'z' => null];
$value = ['integer' => 9007199254740993, 'optional' => null, 'text' => '日本語 λ'];
$snapshots = [];
$capture = static function (string $label, string $status, int $attempts) use (
    $client, $workflowId, &$state, &$snapshots, &$payloadGets, $require, $budget,
): array {
    $budget();
    $before = $client->workflowHistory($workflowId, $state['run_id']);
    $list = $client->workflowActivities($workflowId, $state['run_id']);
    $after = $client->workflowHistory($workflowId, $state['run_id']);
    $require($before === $after, $label.': metadata read changed history.');
    $require($payloadGets === 0, $label.': metadata read fetched payload bytes.');
    $require($list['workflow_id'] === $workflowId && $list['run_id'] === $state['run_id'], $label.': identity changed.');
    $require(count($list['activities']) === 1, $label.': expected one original activity.');
    $activity = $list['activities'][0];
    $require($activity['id'] === $state['activity_id'] && $activity['status'] === $status
        && $activity['attempt_count'] === $attempts && count($activity['attempts']) === $attempts,
        $label.': activity state or attempt count differs.');
    $require($activity['history_authority'] === 'typed_history' && $activity['diagnostic_only'] === false,
        $label.': genuine history was not authoritative.');
    foreach ([$activity, ...$activity['attempts']] as $metadata) {
        foreach (['arguments', 'result', 'exception'] as $field) {
            $require(! array_key_exists($field, $metadata), $label.': metadata includes '.$field.'.');
        }
    }
    foreach ($activity['attempts'] as $index => $attempt) {
        $claim = $state['claims'][$index];
        $require($attempt['id'] === $claim['activity_attempt_id']
            && $attempt['task_id'] === $claim['task_id'] && $attempt['lease_owner'] === $claim['lease_owner']
            && $attempt['attempt_number'] === $index + 1, $label.': attempt does not match its original claim.');
        if ($attempt['status'] !== 'running') {
            $require($attempt['lease_expires_at'] === null && $attempt['can_continue'] === false
                && $attempt['stop_reason'] === 'attempt_closed', $label.': terminal attempt retained authority.');
        }
    }
    $snapshots[$label] = $list;
    $budget();

    return $activity;
};

if ($phase === 'execute') {
    $headers = ['Authorization' => 'Bearer metadata-fixture-token', 'X-Namespace' => $namespace,
        'X-Durable-Workflow-Control-Plane-Version' => '2'];
    $response = $http->get('/api/namespaces/'.$namespace, ['headers' => $headers]);
    if ($response->getStatusCode() === 404) {
        $response = $http->post('/api/namespaces', ['headers' => $headers, 'json' => ['name' => $namespace]]);
        $require($response->getStatusCode() === 201, 'Namespace creation failed.');
    } else {
        $require($response->getStatusCode() === 200, 'Namespace lookup failed.');
    }
    $state['started_at'] = microtime(true);
    $state['original_deadline'] = $state['started_at'] + $profile['original_observation_budget_seconds'];
    $workerId = $workflowId.':worker';
    $client->registerWorker($workerId, $workflowId, ['parity.metadata'], ['parity.metadata_value']);
    $original = $client->startWorkflow('parity.metadata', $workflowId, $workflowId, [],
        executionTimeoutSeconds: 600, runTimeoutSeconds: 120);
    $state['run_id'] = $original->selectedRunId;
    $task = $client->pollWorkflowTask($workerId, $workflowId, 0);
    $require($task !== null && $task['run_id'] === $state['run_id'], 'Original workflow claim missing.');
    $receipt = $client->completeWorkflowTask($task['task_id'], $task['lease_owner'], $task['workflow_task_attempt'], [[
        'type' => 'schedule_activity', 'activity_type' => 'parity.metadata_value',
        'arguments' => $client->payloadCodec()->envelope([$value]), ...$profile['activity_command_options'],
    ]]);
    $require(($receipt['recorded'] ?? null) === true, 'Original schedule was not committed.');
    $events = $client->workflowHistory($workflowId, $state['run_id'])['events'];
    $scheduled = array_values(array_filter($events, static fn (array $event): bool => $event['event_type'] === 'ActivityScheduled'));
    $require(count($scheduled) === 1, 'Expected one original schedule event.');
    $state['activity_id'] = $scheduled[0]['payload']['activity_execution_id'];
    $state['claims'] = [];
    $capture('pending', 'pending', 0);
    $first = $client->pollActivityTask($workerId, $workflowId, 0);
    $require($first !== null && $first['activity_execution_id'] === $state['activity_id'], 'Original activity claim missing.');
    $require($client->payloadCodec()->decodeEnvelope($first['arguments']) === [$value], 'Original activity arguments changed.');
    $state['claims'][] = $first;
    $capture('running', 'running', 1);
    $client->heartbeatActivityTask($first['task_id'], $first['activity_attempt_id'], $first['lease_owner'], $details);
    $beat = $capture('heartbeat', 'running', 1);
    $require($beat['last_heartbeat_progress']['details'] === $details, 'Heartbeat details lost type or value.');
    $client->failActivityTask($first['task_id'], $first['activity_attempt_id'], $first['lease_owner'],
        'metadata retry λ', nonRetryable: false);
    $capture('retry', 'pending', 1);
    $second = $client->pollActivityTask($workerId, $workflowId, 0);
    $require($second !== null && $second['activity_execution_id'] === $state['activity_id']
        && $second['activity_attempt_id'] !== $first['activity_attempt_id'] && $second['attempt_number'] === 2,
        'Retry did not preserve activity identity with a distinct second attempt.');
    $state['claims'][] = $second;
    $capture('resumed', 'running', 2);
    $client->completeActivityTask($second['task_id'], $second['activity_attempt_id'], $second['lease_owner'], $value);
    $capture('completed', 'completed', 2);
    $result = $client->workflowActivityResult($workflowId, $state['run_id'], $state['activity_id']);
    $require($result['state'] === 'available' && $client->payloadCodec()->decodeEnvelope($result['result_envelope']) === $value,
        'Genuine activity completion did not retain its typed result.');
    $parent = $client->pollWorkflowTask($workerId, $workflowId, 0);
    $require($parent !== null && $parent['run_id'] === $state['run_id'], 'Original parent continuation missing.');
    $client->completeWorkflowTask($parent['task_id'], $parent['lease_owner'], $parent['workflow_task_attempt'], [[
        'type' => 'complete_workflow', 'result' => $client->payloadCodec()->envelope('separate parent output'),
    ]]);
    $capture('closed', 'completed', 2);
    $counts = array_count_values(array_column($client->workflowHistory($workflowId, $state['run_id'])['events'], 'event_type'));
    foreach (['ActivityScheduled' => 1, 'ActivityStarted' => 2, 'ActivityHeartbeatRecorded' => 1,
        'ActivityRetryScheduled' => 1, 'ActivityCompleted' => 1, 'ActivityFailed' => 0, 'WorkflowCompleted' => 1] as $type => $count) {
        $require(($counts[$type] ?? 0) === $count, 'Unexpected durable event count: '.$type);
    }
    $state['snapshots'] = $snapshots;
    $state['history_counts'] = $counts;
    $budget();
    file_put_contents($identityPath, json_encode($state, JSON_THROW_ON_ERROR));
} elseif ($phase === 'cold-read') {
    $budget();
    $require($client->describeWorkflow($workflowId, $state['run_id'])->status === 'completed', 'Original parent is not completed.');
    $capture('cold', 'completed', 2);
    $require($snapshots['cold'] === $state['snapshots']['closed'], 'Fresh client changed retained metadata.');
    $state['snapshots']['cold'] = $snapshots['cold'];
    file_put_contents($identityPath, json_encode($state, JSON_THROW_ON_ERROR));
} else {
    throw new RuntimeException('Unknown consumer phase.');
}
echo json_encode(['outcome' => 'pass', 'phase' => $phase, 'namespace' => $namespace,
    'workflow_id' => $workflowId, 'run_id' => $state['run_id'], 'activity_id' => $state['activity_id'],
    'snapshots' => array_keys($snapshots), 'history_unchanged_by_reads' => true, 'metadata_payload_gets' => $payloadGets,
    'sdk_version' => InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
    'sdk_source' => InstalledVersions::getReference('durable-workflow/sdk'),
    'elapsed_seconds' => microtime(true) - $state['started_at']], JSON_THROW_ON_ERROR)."\n";
