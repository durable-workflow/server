<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as QueryContractArgumentsHttp;
use GuzzleHttp\HandlerStack;

require_once __DIR__.'/held-request.php';

// Query arguments checked against a durable query contract, over raw HTTP
// on protocol 1.20 in a fresh namespace (published Server
// WorkflowQueryTaskBroker::validateContractedQueryArguments with Workflow
// WorkflowQueryContract::validatedArgumentsForRun). The worker that starts
// the run declares three queries: `state(string $prefix)`, `greet(string
// $prefix = 'dflt')`, and `plain` with no parameter contract. Each case sends
// one query with a fixed Avro blob. A query the contract accepts is routed:
// the probe holds the client query, leases the task as the worker and
// records the arguments blob the worker receives. A refused query records
// the server's answer.
function finishHttpQueryContractArguments(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['query_contract_arguments'];
    $namespace = strtolower($workflowId.'-qca');
    $admin->createNamespace($namespace, 'query contract arguments λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new QueryContractArgumentsHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $headers = array_filter([
        'Authorization' => $token === null ? null : 'Bearer '.$token,
        'Accept' => 'application/json', 'X-Namespace' => $namespace,
        'X-Durable-Workflow-Control-Plane-Version' => '2', 'X-Durable-Workflow-Protocol-Version' => '1.20',
    ]);
    $raw = static function (string $method, string $path, ?array $body = null) use ($http, $headers): array {
        $response = $http->request($method, $path, ['headers' => $headers,
            ...($body === null ? [] : ['json' => $body === [] ? (object) [] : $body])]);

        return ['status' => $response->getStatusCode(),
            'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    };
    $type = $definition['workflow_type'];
    $id = $workflowId.'-qca';
    $queue = $id.'-queue';
    $worker = $id.'-worker';
    // The fixture is loaded into PHP arrays, so a default written as a JSON
    // object with keys 0..n-1 would be sent as a list. These queries'
    // defaults are sent as JSON objects, as a worker registering raw JSON
    // would; the exact bytes sent are recorded.
    $contracts = array_map(static fn (array $contract): array => in_array($contract['name'], $definition['object_defaults'], true)
        ? [...$contract, 'parameters' => array_map(static fn (array $parameter): array => array_key_exists('default', $parameter)
            ? [...$parameter, 'default' => (object) $parameter['default']] : $parameter, $contract['parameters'])]
        : $contract, $definition['query_contracts']);
    $sentDefaults = [];
    foreach ($contracts as $contract) {
        if (in_array($contract['name'], $definition['object_defaults'], true)) {
            $sentDefaults[$contract['name']] = json_encode($contract['parameters'][0]['default'], JSON_THROW_ON_ERROR);
        }
    }
    $registered = $raw('POST', 'worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
        'sdk_version' => 'raw-http', 'supported_workflow_types' => [$type], 'supported_activity_types' => [],
        'capabilities' => ['query_tasks'], 'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions',
            'sticky_execution'], ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http']),
        'workflow_command_contracts' => [$type => ['signals' => [], 'updates' => [],
            'queries' => $definition['queries'], 'query_contracts' => $contracts]]]);
    if ($registered['status'] !== 201) {
        throw new RuntimeException('Query contract arguments registration: '.json_encode($registered));
    }
    $started = $raw('POST', 'workflows', ['workflow_id' => $id, 'workflow_type' => $type, 'task_queue' => $queue,
        'input' => ['codec' => 'avro', 'blob' => $definition['blobs']['empty']]]);
    if ($started['status'] !== 201) {
        throw new RuntimeException('Query contract arguments start: '.json_encode($started));
    }

    $state = ['applicable' => true, 'sent_defaults' => $sentDefaults];
    foreach ($definition['cases'] as $case => [$query, $blob, $routed]) {
        $path = '/api/workflows/'.rawurlencode($id).'/query/'.$query;
        $body = ['input' => ['codec' => 'avro', 'blob' => $definition['blobs'][$blob]]];
        if (! $routed) {
            $answer = $raw('POST', ltrim(substr($path, strlen('/api/')), '/'), $body);
            $state[$case] = ['status' => $answer['status'], 'reason' => $answer['response']['reason'] ?? null,
                'message' => $answer['response']['message'] ?? null,
                'validation_errors' => $answer['response']['validation_errors'] ?? null];

            continue;
        }
        $pending = startHeldRequest($url, $headers, $path, $body, 0.0);
        $leased = null;
        for ($attempt = 0; $attempt < 10 && $leased === null; $attempt++) {
            $polled = $raw('POST', 'worker/query-tasks/poll', ['worker_id' => $worker, 'task_queue' => $queue,
                'timeout_seconds' => 2]);
            $leased = $polled['response']['task'] ?? null;
        }
        $observed = ['leased' => is_array($leased), 'query_arguments' => is_array($leased)
            ? ($leased['query_arguments'] ?? 'absent') : null];
        if (is_array($leased)) {
            $observed['answered'] = $raw('POST', 'worker/query-tasks/'.$leased['query_task_id'].'/complete', [
                'lease_owner' => $leased['lease_owner'], 'query_task_attempt' => $leased['query_task_attempt'],
                'result_envelope' => ['codec' => 'avro', 'blob' => $definition['blobs']['empty']]])['status'];
        }
        $answer = collectHeldRequest($pending);
        $observed['client'] = ['status' => $answer['status'], 'reason' => $answer['response']['reason'] ?? null];
        $state[$case] = $observed;
    }

    return $state;
}
