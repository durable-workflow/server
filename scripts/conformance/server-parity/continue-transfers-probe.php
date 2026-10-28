<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as ContinueTransfersHttp;
use GuzzleHttp\HandlerStack;

require_once __DIR__.'/held-request.php';

// Continue-as-new and close over what a run leaves behind, over raw worker
// HTTP in a fresh namespace (Workflow DefaultWorkflowTaskBridge::applyContinueAsNew,
// WorkflowStub::attemptTerminate, ParentClosePolicyEnforcer). Core continues
// or closes the run whatever it has open and touches neither service calls
// nor query tasks.
//
// The service-call run is the caller of an accepted async call (caller
// attribution on execute) and the linked run of another, through an
// activity_execution binding. Its workflow task continues as new: the
// completion is accepted, both calls stay accepted with the predecessor, and
// the successor's first task is ready.
//
// The query run has a query admitted while its workflow task is leased; the
// query waits behind the lease (canClaimPendingTaskForPoller). A query poll
// that ends held by the lease shows it was admitted. The leased task then
// continues as new. The query task, still bound to the predecessor, is
// leased by the next query poll and answered, and the waiting client
// receives that answer.
//
// The closed-query run is the same, but the run is terminated over HTTP
// instead of continuing.
//
// The policy run starts a child under the terminate parent-close policy. The
// child continues as new, and the parent is terminated over HTTP. The policy
// reaches the child's current run.
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
    // A request held in a child process (held-request.php). The probe checks
    // that the request arrived instead of waiting a fixed time.
    $hold = static fn (string $path, array $body): array => startHeldRequest($url, $headers, $path, $body, 0.0);
    $collect = static fn (array $pending): array => collectHeldRequest($pending);
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
    $continue = static function (array $task) use ($raw): array {
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

    // A query poll ends held by the run's leased workflow task once the
    // client's query is admitted against the run (performPoll's
    // workflow_task_leased); before that it ends empty. Bounded, so a lost
    // query fails the probe instead of hanging it.
    $admitted = static function (array $run) use ($raw): string {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $polled = $raw('POST', '/api/worker/query-tasks/poll', ['worker_id' => $run['worker'],
                'task_queue' => $run['queue'], 'timeout_seconds' => 1]);
            $status = $polled['response']['poll_status'] ?? null;
            if ($polled['status'] !== 200 || ($polled['response']['task'] ?? null) !== null
                || ! in_array($status, ['empty', 'workflow_task_leased'], true)) {
                throw new RuntimeException('Continue transfers admission: '.json_encode($polled));
            }
            if ($status === 'workflow_task_leased') {
                return $status;
            }
        }
        throw new RuntimeException('Continue transfers: the query was never admitted behind the leased task.');
    };
    $answerQuery = static function (array $run) use ($raw, $label, $empty): array {
        $polled = $raw('POST', '/api/worker/query-tasks/poll', ['worker_id' => $run['worker'],
            'task_queue' => $run['queue'], 'timeout_seconds' => 5]);
        $queryTask = $polled['response']['task'] ?? null;
        $observed = ['query_task' => ['status' => $polled['status'], 'leased' => is_array($queryTask),
            'run_id' => $label($queryTask['run_id'] ?? null), 'query_name' => $queryTask['query_name'] ?? null]];
        $observed['answered'] = is_array($queryTask) ? $raw('POST', '/api/worker/query-tasks/'.$queryTask['query_task_id'].'/complete', [
            'lease_owner' => $queryTask['lease_owner'], 'query_task_attempt' => $queryTask['query_task_attempt'],
            'result_envelope' => $empty])['status'] : null;

        return $observed;
    };
    $client = static function (array $pending) use ($collect, $label): array {
        $answer = $collect($pending);

        return ['status' => $answer['status'], 'run_id' => $label($answer['response']['run_id'] ?? null),
            'result_envelope' => $answer['response']['result_envelope'] ?? null];
    };
    $terminate = static fn (array $run): int => $raw('POST', '/api/workflows/'.$run['workflow_id'].'/terminate',
        ['reason' => 'continue transfers fixture'])['status'];

    $state = ['applicable' => true];

    // An accepted async service call names the run as its caller, and
    // another names it as its linked run through an activity_execution
    // binding.
    foreach ([['POST', '/api/service-endpoints', ['endpoint_name' => 'can']],
        ['POST', '/api/service-endpoints/can/services', ['service_name' => 'billing']],
        ['POST', '/api/service-endpoints/can/services/billing/operations', ['operation_name' => 'charge',
            'operation_mode' => 'async', 'handler_binding_kind' => 'activity_execution',
            'handler_target_reference' => 'charge-activity']]] as [$method, $path, $body]) {
        $ok($raw($method, $path, $body), 201, 'service catalog');
    }
    $calling = $start('call');
    $label($calling['run_id']);
    $ok($raw('POST', '/api/service-endpoints/can/services/billing/operations', ['operation_name' => 'linked',
        'operation_mode' => 'async', 'handler_binding_kind' => 'activity_execution', 'handler_binding' => [
            'activity_type' => 'charge-activity', 'owning_workflow_instance_id' => $calling['workflow_id'],
            'owning_workflow_run_id' => $calling['run_id']]]), 201, 'linked operation');
    $task = $claim($calling);
    $executed = $raw('POST', '/api/service-endpoints/can/services/billing/operations/charge/execute', ['arguments' => $empty,
        'caller_workflow_instance_id' => $calling['workflow_id'], 'caller_workflow_run_id' => $calling['run_id']]);
    $linkedExecuted = $raw('POST', '/api/service-endpoints/can/services/billing/operations/linked/execute', ['arguments' => $empty]);
    $describeCall = static function (string $operation, ?string $call) use ($raw, $label): array {
        $shown = $raw('GET', '/api/service-endpoints/can/services/billing/operations/'.$operation.'/service-calls/'.$call, null);

        return ['status' => $shown['status'], 'call_status' => $shown['response']['status'] ?? null,
            'outcome' => $shown['response']['outcome'] ?? null,
            'caller_workflow_run_id' => $label($shown['response']['caller_workflow_run_id'] ?? null),
            'resolved_binding_kind' => $shown['response']['resolved_binding_kind'] ?? null,
            'linked_workflow_run_id' => $label($shown['response']['linked_workflow_run_id'] ?? null)];
    };
    $call = $executed['response']['service_call_id'] ?? null;
    $linked = $linkedExecuted['response']['service_call_id'] ?? null;
    $callState = ['executed' => ['status' => $executed['status'], 'accepted' => $executed['response']['accepted'] ?? null],
        'linked_executed' => ['status' => $linkedExecuted['status'], 'accepted' => $linkedExecuted['response']['accepted'] ?? null],
        'call_before' => $describeCall('charge', $call), 'linked_before' => $describeCall('linked', $linked)];
    $callState['continued'] = $continue($task);
    $callState['runs'] = $runs($calling);
    $callState['call_after'] = $describeCall('charge', $call);
    $callState['linked_after'] = $describeCall('linked', $linked);
    $callState['successor'] = $successor($calling);
    $state['service_call'] = $callState;

    // A query admitted while the workflow task is leased; the task continues.
    $querying = $start('query');
    $label($querying['run_id']);
    $task = $claim($querying);
    $pending = $hold('/api/workflows/'.$querying['workflow_id'].'/query/'.$query, ['input' => $empty]);
    $queryState = ['admitted' => $admitted($querying)];
    $queryState['continued'] = $continue($task);
    $queryState += $answerQuery($querying);
    $queryState['client'] = $client($pending);
    $queryState['runs'] = $runs($querying);
    $queryState['successor'] = $successor($querying);
    $state['live_query'] = $queryState;

    // The same, but the run is terminated instead.
    $closing = $start('closed');
    $label($closing['run_id']);
    $claim($closing);
    $pending = $hold('/api/workflows/'.$closing['workflow_id'].'/query/'.$query, ['input' => $empty]);
    $closedState = ['admitted' => $admitted($closing), 'terminated' => $terminate($closing)];
    $closedState += $answerQuery($closing);
    $closedState['client'] = $client($pending);
    $closedState['runs'] = $runs($closing);
    $state['closed_query'] = $closedState;

    // A terminate parent-close policy reaches a child that continued as new.
    $parent = $start('policy');
    $label($parent['run_id']);
    $task = $claim($parent);
    $scheduled = $raw('POST', '/api/worker/workflow-tasks/'.$task['task_id'].'/complete', ['lease_owner' => $task['lease_owner'],
        'workflow_task_attempt' => $task['workflow_task_attempt'], 'commands' => [['type' => 'start_child_workflow',
            'workflow_type' => $type, 'arguments' => $empty, 'parent_close_policy' => 'terminate']]]);
    $policyState = ['scheduled' => ['status' => $scheduled['status'], 'outcome' => $scheduled['response']['outcome'] ?? null]];
    $childTask = $claim($parent);
    $child = ['workflow_id' => $childTask['workflow_id'] ?? null, 'run_id' => $childTask['run_id'] ?? null];
    $policyState['child_task'] = ['run_id' => $label($child['run_id']), 'child' => $child['workflow_id'] !== $parent['workflow_id']];
    $policyState['child_continued'] = $continue($childTask);
    $policyState['terminated'] = $terminate($parent);
    $events = $ok($raw('GET', '/api/workflows/'.$parent['workflow_id'].'/runs/'.$parent['run_id'].'/history', null),
        200, 'parent history')['events'] ?? [];
    $policyState['policy_events'] = array_values(array_map(static fn (array $event): array => [
        'event_type' => $event['event_type'], 'child_run_id' => $label($event['payload']['child_run_id'] ?? null),
        'policy' => $event['payload']['policy'] ?? null, 'error' => $event['payload']['error'] ?? null],
        array_filter($events, static fn (array $event): bool => str_starts_with((string) ($event['event_type'] ?? ''),
            'ParentClosePolicy'))));
    $policyState['child_runs'] = $runs($child);
    $state['policy_child'] = $policyState;

    return $state;
}
