<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Transport\Psr18Transport;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require getenv('DW_PARITY_SDK_AUTOLOAD');
[$script, $url, $phase, $identityPath] = $argv;
$profile = json_decode(file_get_contents(__DIR__.'/../../../../tests/Fixtures/ActivityResultInspection/profile.json'), true, flags: JSON_THROW_ON_ERROR);
if (InstalledVersions::getPrettyVersion('durable-workflow/sdk') !== $profile['php_sdk']['version']
    || InstalledVersions::getReference('durable-workflow/sdk') !== $profile['php_sdk']['source']) {
    throw new RuntimeException('Normally installed SDK identity differs from the selected profile.');
}
$state = json_decode(file_get_contents($identityPath), true, flags: JSON_THROW_ON_ERROR);
$namespace = $state['namespace'];
$workflowId = $state['workflow_id'];
$expected = match ($state['case']) {
    'encoded-null' => null,
    'encoded-false' => false,
    'typed-map', 'retry-completed' => ['integer' => 9007199254740993, 'optional' => null, 'text' => '日本語 λ'],
    'external-result' => ['integer' => 9007199254740993, 'optional' => null, 'text' => '日本語 λ', 'large' => str_repeat('x', 2 * 1024 * 1024)],
    default => throw new RuntimeException('Unknown activity result fixture.'),
};
$worker = null;
$completions = 0;
$failures = 0;
$afterFailure = null;
$payloadGets = 0;
$stack = HandlerStack::create();
$stack->push(static function (callable $handler) use (&$worker, &$completions, &$failures, &$afterFailure, &$payloadGets): callable {
    return static function (RequestInterface $request, array $options) use ($handler, &$worker, &$completions, &$failures, &$afterFailure, &$payloadGets) {
        return $handler($request, $options)->then(static function (ResponseInterface $response) use ($request, &$worker, &$completions, &$failures, &$afterFailure, &$payloadGets): ResponseInterface {
            $path = $request->getUri()->getPath();
            if ($request->getMethod() === 'GET' && str_starts_with($path, '/api/external-payloads/v1/')) {
                $payloadGets++;
            }
            if ($response->getStatusCode() === 200 && preg_match('#^/api/worker/activity-tasks/[^/]+/complete$#', $path)) {
                $completions++;
                $worker?->requestShutdown();
            }
            if ($response->getStatusCode() === 200 && preg_match('#^/api/worker/activity-tasks/[^/]+/fail$#', $path)) {
                $failures++;
                $afterFailure?->__invoke();
            }

            return $response;
        });
    };
});
$http = new HttpClient(['base_uri' => rtrim($url, '/'), 'http_errors' => false, 'handler' => $stack,
    'connect_timeout' => 1, 'timeout' => 5]);
$client = new Client($url, namespace: $namespace, token: 'activity-result-fixture-token',
    transport: new Psr18Transport(client: $http));
$control = static function (string $method, string $path, array $body = []) use ($http, $namespace): array {
    $response = $http->request($method, $path, ['headers' => ['Authorization' => 'Bearer activity-result-fixture-token',
        'X-Namespace' => $namespace, 'X-Durable-Workflow-Control-Plane-Version' => '2'], 'json' => $body]);

    return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
};
$budget = static function () use (&$state): void {
    if (microtime(true) >= $state['original_deadline']) {
        throw new RuntimeException('Original activity-result observation budget expired.');
    }
};
$summary = static function (mixed $value) use ($client): array {
    $bytes = $client->payloadCodec()->encode($value);

    return ['encoded_bytes' => strlen($bytes), 'encoded_sha256' => hash('sha256', $bytes)];
};

if ($phase === 'author') {
    if ($control('GET', '/api/namespaces/'.$namespace)[0] === 404
        && $control('POST', '/api/namespaces', ['name' => $namespace])[0] !== 201) {
        throw new RuntimeException('Namespace setup failed.');
    }
    if ($state['case'] === 'external-result'
        && $control('PUT', '/api/namespaces/'.$namespace.'/external-storage', ['driver' => 'local', 'enabled' => true,
            'threshold_bytes' => 1048576, 'config' => ['uri' => 'file:///state/objects']])[0] !== 200) {
        throw new RuntimeException('External result policy setup failed.');
    }
    $state['authored_activity_options'] = $state['case'] === 'retry-completed'
        ? $profile['retry_activity_command_options'] : $profile['activity_command_options'];
    $state['started_at'] = microtime(true);
    $state['original_deadline'] = $state['started_at'] + $profile['original_observation_budget_seconds'];
    [$status, $original] = $control('POST', '/api/workflows', ['workflow_id' => $workflowId,
        'workflow_type' => 'parity.activity_result_inspection', 'task_queue' => $workflowId,
        'input' => $client->payloadCodec()->envelope([]), 'run_timeout_seconds' => 120,
        'execution_timeout_seconds' => 600]);
    if ($status !== 201) {
        throw new RuntimeException('Original workflow admission failed: '.$status);
    }
    $client->registerWorker($workflowId.':author', $workflowId, ['parity.activity_result_inspection'], []);
    $task = $client->pollWorkflowTask($workflowId.':author', $workflowId, 0);
    if ($task === null || $task['run_id'] !== $original['run_id']) {
        throw new RuntimeException('Original author task was not delivered.');
    }
    $receipt = $client->completeWorkflowTask($task['task_id'], $task['lease_owner'], $task['workflow_task_attempt'], [[
        'type' => 'schedule_activity', 'activity_type' => 'parity.activity_result_value',
        'arguments' => $client->payloadCodec()->envelope([]), ...$state['authored_activity_options'],
    ]]);
    if (($receipt['recorded'] ?? null) !== true) {
        throw new RuntimeException('Original activity schedule was not committed.');
    }
    $state['run_id'] = $original['run_id'];
    $history = $client->workflowHistory($workflowId, $state['run_id']);
    $events = $history['events'];
    $scheduled = array_values(array_filter($events, static fn (array $event): bool => $event['event_type'] === 'ActivityScheduled'));
    if (count($scheduled) !== 1) {
        throw new RuntimeException('Expected one original activity schedule.');
    }
    $state['activity_id'] = $scheduled[0]['payload']['activity_execution_id'];
    $state['scheduled_history_prefix'] = $events;
    $pending = $client->workflowActivityResult($workflowId, $state['run_id'], $state['activity_id']);
    if ($pending['state'] !== 'pending' || $pending['available'] !== false || array_key_exists('result_envelope', $pending)) {
        throw new RuntimeException('Pending activity was mistaken for an available null.');
    }
    $budget();
    file_put_contents($identityPath, json_encode($state, JSON_THROW_ON_ERROR));
    echo json_encode(['outcome' => 'pass', 'phase' => $phase, 'run_id' => $state['run_id'],
        'activity_id' => $state['activity_id'], 'pending' => true], JSON_THROW_ON_ERROR)."\n";
} elseif ($phase === 'activity') {
    $budget();
    $invocations = 0;
    $retry = $state['case'] === 'retry-completed';
    $retryPending = false;
    $attemptNumbers = [];
    $attemptIds = [];
    if ($retry) {
        $afterFailure = static function () use ($client, $workflowId, $state, $budget, &$retryPending): void {
            $budget();
            $pending = $client->workflowActivityResult($workflowId, $state['run_id'], $state['activity_id']);
            $history = $client->workflowHistory($workflowId, $state['run_id'])['events'];
            $counts = array_count_values(array_column($history, 'event_type'));
            if ($pending['state'] !== 'pending' || $pending['available'] !== false
                || $pending['reason'] !== 'activity_result_pending' || array_key_exists('result_envelope', $pending)
                || ($counts['ActivityRetryScheduled'] ?? 0) !== 1 || ($counts['ActivityFailed'] ?? 0) !== 0
                || ($counts['ActivityCompleted'] ?? 0) !== 0) {
                throw new RuntimeException('Committed retry observation disagrees: '.json_encode([
                    'state' => $pending['state'] ?? null, 'available' => $pending['available'] ?? null,
                    'reason' => $pending['reason'] ?? null, 'envelope_present' => array_key_exists('result_envelope', $pending),
                    'event_counts' => $counts,
                ], JSON_THROW_ON_ERROR));
            }
            $retryPending = true;
        };
    }
    if ($state['case'] === 'external-result') {
        // Explicit public-client completion isolates this read contract from
        // automatic Worker upload policy, which has its own qualification gate.
        $client->registerWorker($workflowId.':activity', $workflowId, [], ['parity.activity_result_value']);
        $task = $client->pollActivityTask($workflowId.':activity', $workflowId, 0);
        if ($task === null || $task['activity_execution_id'] !== $state['activity_id']) {
            throw new RuntimeException('Original external activity task was not delivered.');
        }
        $bytes = $client->payloadCodec()->encode($expected);
        $response = $http->post('/api/external-payloads/v1', ['headers' => ['Authorization' => 'Bearer activity-result-fixture-token',
            'X-Namespace' => $namespace, 'Content-Type' => 'application/octet-stream',
            'X-Durable-Workflow-Payload-Codec' => 'avro', 'X-Durable-Workflow-Payload-Size' => (string) strlen($bytes),
            'X-Durable-Workflow-Payload-SHA256' => hash('sha256', $bytes)], 'body' => $bytes]);
        if ($response->getStatusCode() !== 201) {
            throw new RuntimeException('Explicit result upload failed: '.$response->getStatusCode());
        }
        $reference = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['reference'];
        $response = $http->post('/api/worker/activity-tasks/'.$task['task_id'].'/complete', [
            'headers' => ['Authorization' => 'Bearer activity-result-fixture-token', 'X-Namespace' => $namespace,
                'X-Durable-Workflow-Protocol-Version' => '1.20'],
            'json' => ['lease_owner' => $task['lease_owner'], 'activity_attempt_id' => $task['activity_attempt_id'],
                'result' => ['codec' => 'avro', 'external_payload' => $reference]],
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Explicit original activity completion failed: '.$response->getStatusCode());
        }
    } else {
        $worker = new Worker($client, $workflowId, workerId: $workflowId.':activity', clock: static function () use ($budget): float {
            $budget();

            return microtime(true);
        });
        $worker->registerActivity('parity.activity_result_value', static function (ActivityContext $context) use ($expected, $retry, &$invocations, &$attemptNumbers, &$attemptIds): mixed {
            $invocations++;
            $attemptNumbers[] = $context->attemptNumber;
            $attemptIds[] = $context->activityAttemptId;
            if ($context->attemptNumber !== $invocations) {
                throw new RuntimeException('Original activity attempt order changed.');
            }
            if ($retry && $invocations === 1) {
                throw new RuntimeException('Authored first-attempt failure for result inspection.');
            }

            return $expected;
        });
        $worker->run(1);
        if ($invocations !== ($retry ? 2 : 1) || count(array_unique($attemptIds)) !== $invocations) {
            throw new RuntimeException('Actual callbacks or attempt identities changed.');
        }
    }
    $budget();
    if ($completions !== 1) {
        throw new RuntimeException('Expected one committed original activity completion.');
    }
    if ($failures !== ($retry ? 1 : 0) || ($retry && ! $retryPending)) {
        throw new RuntimeException('Expected the original committed retry failure and pending result observation.');
    }
    echo json_encode(['outcome' => 'pass', 'phase' => $phase, 'run_id' => $state['run_id'],
        'activity_invocations' => $invocations, 'activity_completions' => $completions,
        'failure_submissions' => $failures, 'retry_pending_observed' => $retryPending,
        'attempt_numbers' => $attemptNumbers, 'attempt_ids' => $attemptIds,
        ...$summary($expected)], JSON_THROW_ON_ERROR)."\n";
} elseif (in_array($phase, ['reader', 'cold-read'], true)) {
    $budget();
    $path = '/api/workflows/'.$workflowId.'/runs/'.$state['run_id'];
    [$historyStatus, $before] = $control('GET', $path.'/history');
    if (array_slice($before['events'] ?? [], 0, count($state['scheduled_history_prefix'])) !== $state['scheduled_history_prefix']) {
        throw new RuntimeException('The original scheduled history prefix changed.');
    }
    if ($state['case'] === 'retry-completed') {
        $counts = array_count_values(array_column($before['events'], 'event_type'));
        if (($counts['ActivityScheduled'] ?? 0) !== 1 || ($counts['ActivityStarted'] ?? 0) !== 2
            || ($counts['ActivityRetryScheduled'] ?? 0) !== 1 || ($counts['ActivityFailed'] ?? 0) !== 0
            || ($counts['ActivityCompleted'] ?? 0) !== 1) {
            throw new RuntimeException('Retry changed the original activity admission or terminal count.');
        }
    }
    [$rawStatus, $raw] = $control('GET', $path.'/activities/'.$state['activity_id'].'/result');
    if ($historyStatus !== 200 || $rawStatus !== 200 || $payloadGets !== 0) {
        throw new RuntimeException('Raw canonical observation unexpectedly fetched payload bytes.');
    }
    $fetchesBefore = $payloadGets;
    $result = $client->workflowActivityResult($workflowId, $state['run_id'], $state['activity_id']);
    if ($result['state'] !== 'available' || $result['available'] !== true || $result['reason'] !== null) {
        throw new RuntimeException('Targeted read did not return the retained canonical envelope.');
    }
    $decoded = $client->payloadCodec()->decodeEnvelope($result['result_envelope']);
    if ($decoded !== $expected) {
        throw new RuntimeException('Targeted result changed a typed value.');
    }
    $targetedFetches = $payloadGets - $fetchesBefore;
    if ($state['case'] === 'external-result' && ($targetedFetches !== 1 || ! isset($raw['result_envelope']['external_payload']))) {
        throw new RuntimeException('External result did not use one opaque verified payload GET.');
    }
    [$afterStatus, $after] = $control('GET', $path.'/history');
    if ($afterStatus !== 200 || $before !== $after) {
        throw new RuntimeException('Read changed the original history.');
    }
    if ($phase === 'reader') {
        $task = $client->pollWorkflowTask($workflowId.':author', $workflowId, 0);
        if ($task === null || $task['run_id'] !== $state['run_id']) {
            throw new RuntimeException('Original parent continuation was not delivered.');
        }
        $client->completeWorkflowTask($task['task_id'], $task['lease_owner'], $task['workflow_task_attempt'], [
            ['type' => 'complete_workflow', 'result' => $client->payloadCodec()->envelope('separate parent output')],
        ]);
    } elseif ($client->describeWorkflow($workflowId, $state['run_id'])->status !== 'completed') {
        throw new RuntimeException('Cold result reader did not observe a completed original parent.');
    }
    $budget();
    echo json_encode(['outcome' => 'pass', 'phase' => $phase, 'run_id' => $state['run_id'],
        'activity_id' => $state['activity_id'], 'history_sequence' => $result['history_sequence'],
        'history_unchanged' => true, 'targeted_payload_gets' => $targetedFetches,
        'sdk_version' => InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
        'sdk_source' => InstalledVersions::getReference('durable-workflow/sdk'),
        'elapsed_seconds' => microtime(true) - $state['started_at'], ...$summary($decoded)], JSON_THROW_ON_ERROR)."\n";
} else {
    throw new RuntimeException('Unknown activity-result fixture phase.');
}
