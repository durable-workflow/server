<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as ContinueTransfersHttp;
use GuzzleHttp\HandlerStack;

// Continue-as-new over what a run leaves behind, over raw worker HTTP in a
// fresh namespace (Workflow DefaultWorkflowTaskBridge::applyContinueAsNew).
// Core continues the run whatever it has open and touches neither service
// calls nor query tasks.
//
// The service-call run is the caller of an accepted async call (caller
// attribution on execute). Its workflow task continues as new: the
// completion is accepted, the call keeps the predecessor as its caller and
// stays accepted, and the successor's first task is ready.
//
// The query run has a query admitted while its workflow task is leased; the
// query waits behind the lease (canClaimPendingTaskForPoller). The leased
// task then continues as new. The query task, still bound to the
// predecessor, is leased by the next query poll and answered, and the
// waiting client receives that answer.
//
// Run ids become labels by first appearance (`{run:N}`); instants are dropped.
function finishHttpContinueTransfers(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['continue_transfers'];
    $namespace = strtolower($workflowId.'-can');
    $admin->createNamespace($namespace, 'continue transfers λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    // The SQLite reference shares its database with a one-second maintenance
    // loop; retry its retryable lock-pressure refusals, as the other probes do.
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new ContinueTransfersHttp(['base_uri' => rtrim($url, '/'), 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $headers = array_filter([
        'Authorization' => $token === null ? null : 'Bearer '.$token,
        'Accept' => 'application/json', 'X-Namespace' => $namespace,
        'X-Durable-Workflow-Control-Plane-Version' => '2', 'X-Durable-Workflow-Protocol-Version' => '1.20',
    ]);
    $raw = static function (string $method, string $path, ?array $body = []) use ($http, $headers): array {
        $options = ['headers' => $headers];
        if ($body !== null) {
            $options['json'] = $body === [] ? (object) [] : $body;
        }
        $response = $http->request($method, $path, $options);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return ['status' => $response->getStatusCode(), 'response' => $decoded];
    };
    $ok = static function (array $recorded, int $status, string $operation): array {
        if ($recorded['status'] !== $status) {
            throw new RuntimeException('Continue transfers '.$operation.': '.json_encode($recorded));
        }

        return $recorded['response'];
    };
    // A request held in a child process, as the worker poll fence probe holds
    // one. It retries only the documented lock-pressure refusal.
    $hold = static function (string $path, array $body) use ($url, $headers): array {
        $child = <<<'PHP'
[, $url, $path, $headers, $body, $retryRule] = $argv;
require $retryRule;
$lines = '';
foreach (json_decode($headers, true) as $name => $value) {
    $lines .= "{$name}: {$value}\r\n";
}
$context = stream_context_create(['http' => ['method' => 'POST', 'header' => $lines."Content-Type: application/json\r\n",
    'content' => $body, 'ignore_errors' => true, 'timeout' => 60]]);
$retries = [];
$waited = 0.0;
while (true) {
    $response = file_get_contents(rtrim($url, '/').$path, false, $context);
    preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $status);
    $status = (int) ($status[1] ?? 0);
    $decoded = json_decode((string) $response, true);
    $delay = lockPressureOperationRetryDelay('POST', $path, $status, $decoded, count($retries), $waited);
    if ($delay === null) {
        break;
    }
    $retries[] = $delay;
    usleep((int) round($delay * 1_000_000));
    $waited += $delay;
}
echo json_encode(['status' => $status, 'response' => $decoded]);
PHP;
        $process = proc_open([PHP_BINARY, '-r', $child, '--', $url, $path, json_encode($headers, JSON_THROW_ON_ERROR),
            json_encode($body, JSON_THROW_ON_ERROR), __DIR__.'/lock-pressure-retry.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('The held request could not start.');
        }
        // Let the request reach the server and begin waiting.
        usleep(700000);

        return [$process, $pipes];
    };
    $collect = static function (array $pending): array {
        [$process, $pipes] = $pending;
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        proc_close($process);
        $result = json_decode((string) $output, true);
        if (! is_array($result)) {
            throw new RuntimeException('The held request failed: '.trim((string) $errors));
        }

        return $result;
    };
    $labels = [];
    $label = static function (?string $id) use (&$labels): ?string {
        if ($id === null) {
            return null;
        }
        $key = strtolower($id);
        if (! isset($labels[$key])) {
            $labels[$key] = '{run:'.(count($labels) + 1).'}';
        }

        return $labels[$key];
    };
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $type = $definition['workflow_type'];
    $query = $definition['query'];
    $start = static function (string $suffix) use ($raw, $ok, $type, $empty, $query, $workflowId): array {
        $id = $workflowId.'-'.$suffix;
        $queue = $id.'-queue';
        $worker = $id.'-worker';
        $ok($raw('POST', '/api/worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
            'sdk_version' => 'raw-http', 'supported_workflow_types' => [$type], 'supported_activity_types' => [],
            'capabilities' => ['query_tasks'], 'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions',
                'sticky_execution'], ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http']),
            'workflow_command_contracts' => [$type => ['signals' => [], 'updates' => [], 'queries' => [$query],
                'query_contracts' => [['name' => $query, 'parameters' => []]]]]]), 201, 'registration');
        $started = $ok($raw('POST', '/api/workflows', ['workflow_id' => $id, 'workflow_type' => $type,
            'task_queue' => $queue, 'input' => $empty]), 201, 'start');

        return ['workflow_id' => $id, 'run_id' => $started['run_id'], 'queue' => $queue, 'worker' => $worker];
    };
    $claim = static function (array $run) use ($raw, $ok): ?array {
        return $ok($raw('POST', '/api/worker/workflow-tasks/poll', ['worker_id' => $run['worker'],
            'task_queue' => $run['queue'], 'timeout_seconds' => 2]), 200, 'workflow claim')['task'] ?? null;
    };
    $continue = static function (array $task) use ($raw, $label): array {
        $recorded = $raw('POST', '/api/worker/workflow-tasks/'.$task['task_id'].'/complete', ['lease_owner' => $task['lease_owner'],
            'workflow_task_attempt' => $task['workflow_task_attempt'], 'commands' => [['type' => 'continue_as_new']]]);

        return ['status' => $recorded['status'], 'outcome' => $recorded['response']['outcome'] ?? null,
            'reason' => $recorded['response']['reason'] ?? null, 'run_status' => $recorded['response']['run_status'] ?? null,
            'created_tasks' => count($recorded['response']['created_task_ids'] ?? [])];
    };
    $runs = static function (array $run) use ($raw, $ok, $label): array {
        $listed = $ok($raw('GET', '/api/workflows/'.$run['workflow_id'].'/runs', null), 200, 'runs')['runs'] ?? [];

        return array_map(static fn (array $entry): array => ['run_id' => $label($entry['run_id'] ?? null),
            'run_number' => $entry['run_number'] ?? null, 'status' => $entry['status'] ?? null,
            'is_current_run' => $entry['is_current_run'] ?? null], $listed);
    };
    $successor = static function (array $run) use ($claim, $label, $raw): array {
        $task = $claim($run);
        $observed = ['run_id' => $label($task['run_id'] ?? null), 'workflow_task_attempt' => $task['workflow_task_attempt'] ?? null];
        $observed['terminated'] = $raw('POST', '/api/workflows/'.$run['workflow_id'].'/terminate',
            ['reason' => 'continue transfers fixture'])['status'];

        return $observed;
    };

    $state = ['applicable' => true];

    // An accepted async service call names the run as its caller.
    foreach ([['POST', '/api/service-endpoints', ['endpoint_name' => 'can']],
        ['POST', '/api/service-endpoints/can/services', ['service_name' => 'billing']],
        ['POST', '/api/service-endpoints/can/services/billing/operations', ['operation_name' => 'charge',
            'operation_mode' => 'async', 'handler_binding_kind' => 'activity_execution',
            'handler_target_reference' => 'charge-activity']]] as [$method, $path, $body]) {
        $ok($raw($method, $path, $body), 201, 'service catalog');
    }
    $calling = $start('call');
    $label($calling['run_id']);
    $task = $claim($calling);
    $executed = $raw('POST', '/api/service-endpoints/can/services/billing/operations/charge/execute', ['arguments' => $empty,
        'caller_workflow_instance_id' => $calling['workflow_id'], 'caller_workflow_run_id' => $calling['run_id']]);
    $call = $executed['response']['service_call_id'] ?? null;
    $describeCall = static function () use ($raw, $call, $label): array {
        $shown = $raw('GET', '/api/service-endpoints/can/services/billing/operations/charge/service-calls/'.$call, null);

        return ['status' => $shown['status'], 'call_status' => $shown['response']['status'] ?? null,
            'outcome' => $shown['response']['outcome'] ?? null,
            'caller_workflow_run_id' => $label($shown['response']['caller_workflow_run_id'] ?? null)];
    };
    $callState = ['executed' => ['status' => $executed['status'], 'accepted' => $executed['response']['accepted'] ?? null],
        'call_before' => $describeCall()];
    $callState['continued'] = $continue($task);
    $callState['runs'] = $runs($calling);
    $callState['call_after'] = $describeCall();
    $callState['successor'] = $successor($calling);
    $state['service_call'] = $callState;

    // A query admitted while the workflow task is leased.
    $querying = $start('query');
    $label($querying['run_id']);
    $task = $claim($querying);
    $client = $hold('/api/workflows/'.$querying['workflow_id'].'/query/'.$query, ['input' => $empty]);
    $queryState = ['continued' => $continue($task)];
    $polled = $raw('POST', '/api/worker/query-tasks/poll', ['worker_id' => $querying['worker'],
        'task_queue' => $querying['queue'], 'timeout_seconds' => 5]);
    $queryTask = $polled['response']['task'] ?? null;
    $queryState['query_task'] = ['status' => $polled['status'], 'leased' => is_array($queryTask),
        'run_id' => $label($queryTask['run_id'] ?? null), 'query_name' => $queryTask['query_name'] ?? null];
    $queryState['answered'] = is_array($queryTask) ? $raw('POST', '/api/worker/query-tasks/'.$queryTask['query_task_id'].'/complete', [
        'lease_owner' => $queryTask['lease_owner'], 'query_task_attempt' => $queryTask['query_task_attempt'],
        'result_envelope' => $empty])['status'] : null;
    $answer = $collect($client);
    $queryState['client'] = ['status' => $answer['status'], 'run_id' => $label($answer['response']['run_id'] ?? null),
        'result_envelope' => $answer['response']['result_envelope'] ?? null];
    $queryState['runs'] = $runs($querying);
    $queryState['successor'] = $successor($querying);
    $state['live_query'] = $queryState;

    return $state;
}
