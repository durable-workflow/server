<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as QueryArgumentsHttp;
use GuzzleHttp\HandlerStack;

require_once __DIR__.'/held-request.php';

// Query arguments the server cannot decode, over raw HTTP in a fresh
// namespace (published Server WorkflowController::query and
// WorkflowQueryTaskBroker::query, validateContractedQueryArguments). The
// server decodes a query's arguments only to check them against a query
// contract the run's start recorded. A run whose worker declares no queries
// routes the query with its arguments as given: the probe holds the client
// query, leases the query task as the worker, records the arguments it
// carries and answers. A run whose worker declared the query refuses
// arguments that do not decode, or do not decode to a list, before routing.
function finishHttpQueryArgumentRouting(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['query_argument_routing'];
    $namespace = strtolower($workflowId.'-qa');
    $admin->createNamespace($namespace, 'query arguments λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    // The SQLite reference shares its database with a one-second maintenance
    // loop; retry its retryable lock-pressure refusals, as the other probes do.
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new QueryArgumentsHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $raw = static function (string $method, string $path, ?array $body = null) use ($http, $token, $namespace): array {
        $response = $http->request($method, $path, ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2', 'X-Durable-Workflow-Protocol-Version' => '1.20',
        ]), ...($body === null ? [] : ['json' => $body === [] ? (object) [] : $body])]);

        return ['status' => $response->getStatusCode(),
            'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    };
    $type = $definition['workflow_type'];
    $query = $definition['query'];
    $start = static function (string $suffix) use ($raw, $type, $workflowId, $definition): string {
        $id = $workflowId.'-qa-'.$suffix;
        $started = $raw('POST', 'workflows', ['workflow_id' => $id, 'workflow_type' => $type, 'task_queue' => $id.'-queue',
            'input' => ['codec' => 'avro', 'blob' => $definition['blobs']['empty']]]);
        if ($started['status'] !== 201) {
            throw new RuntimeException('Query arguments start: '.json_encode($started));
        }

        return $id;
    };
    $ask = static function (string $id, string $blob) use ($raw, $query): array {
        $answer = $raw('POST', 'workflows/'.rawurlencode($id).'/query/'.$query, ['input' => ['codec' => 'avro', 'blob' => $blob]]);

        return ['status' => $answer['status'], 'reason' => $answer['response']['reason'] ?? null,
            'message' => $answer['response']['message'] ?? null,
            'validation_errors' => $answer['response']['validation_errors'] ?? null];
    };

    $headers = array_filter([
        'Authorization' => $token === null ? null : 'Bearer '.$token,
        'Accept' => 'application/json', 'X-Namespace' => $namespace,
        'X-Durable-Workflow-Control-Plane-Version' => '2', 'X-Durable-Workflow-Protocol-Version' => '1.20',
    ]);
    $register = static function (string $id, bool $contract) use ($raw, $type, $query): string {
        $worker = $id.'-worker';
        $registration = ['worker_id' => $worker, 'task_queue' => $id.'-queue', 'runtime' => 'php',
            'sdk_version' => 'raw-http', 'supported_workflow_types' => [$type], 'supported_activity_types' => [],
            'capabilities' => ['query_tasks'], 'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions',
                'sticky_execution'], ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http'])];
        if ($contract) {
            $registration['workflow_command_contracts'] = [$type => ['signals' => [], 'updates' => [], 'queries' => [$query],
                'query_contracts' => [['name' => $query, 'parameters' => []]]]];
        }
        $registered = $raw('POST', 'worker/register', $registration);
        if ($registered['status'] !== 201) {
            throw new RuntimeException('Query arguments registration: '.json_encode($registered));
        }

        return $worker;
    };

    $state = ['applicable' => true];
    // A query-capable worker that declares no queries: the start records no
    // contract, so the server routes the query with its arguments as given.
    // The client waits while the worker leases the query task and answers.
    $uncontracted = $workflowId.'-qa-uncontracted';
    $worker = $register($uncontracted, false);
    $start('uncontracted');
    foreach (['undecodable', 'not_a_list'] as $blob) {
        $pending = startHeldRequest($url, $headers, '/api/workflows/'.rawurlencode($uncontracted).'/query/'.$query,
            ['input' => ['codec' => 'avro', 'blob' => $definition['blobs'][$blob]]], 0.0);
        $leased = null;
        for ($attempt = 0; $attempt < 10 && $leased === null; $attempt++) {
            $polled = $raw('POST', 'worker/query-tasks/poll', ['worker_id' => $worker, 'task_queue' => $uncontracted.'-queue',
                'timeout_seconds' => 2]);
            $leased = $polled['response']['task'] ?? null;
        }
        $observed = ['leased' => is_array($leased),
            'arguments' => is_array($leased) ? ($leased['arguments'] ?? $leased['query_arguments'] ?? 'absent') : null];
        if (is_array($leased)) {
            $observed['answered'] = $raw('POST', 'worker/query-tasks/'.$leased['query_task_id'].'/complete', [
                'lease_owner' => $leased['lease_owner'], 'query_task_attempt' => $leased['query_task_attempt'],
                'result_envelope' => ['codec' => 'avro', 'blob' => $definition['blobs']['empty']]])['status'];
        }
        $answer = collectHeldRequest($pending);
        $observed['client'] = ['status' => $answer['status'], 'reason' => $answer['response']['reason'] ?? null];
        $state['uncontracted'][$blob] = $observed;
    }
    // A worker that declares the query, so the start records its contract:
    // the server decodes the arguments to check them, before any routing.
    $contracted = $workflowId.'-qa-contracted';
    $register($contracted, true);
    $start('contracted');
    foreach (['undecodable', 'not_a_list'] as $blob) {
        $state['contracted'][$blob] = $ask($contracted, $definition['blobs'][$blob]);
    }

    return $state;
}
