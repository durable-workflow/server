<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as CancellationAckHttp;
use GuzzleHttp\HandlerStack;

// Activity cancellation acknowledgement
// (POST /api/worker/activity-tasks/{task}/acknowledge-cancellation) over raw
// worker HTTP in a fresh namespace. Each run claims one activity on its own
// queue with a cooperative worker, then follows the cooperative path: request
// cancellation, deliver it to the activity from the workflow task, and
// acknowledge the stop from the activity worker. The main run is also refused
// for each ownership, protocol and validation fault, acknowledged, read back
// and acknowledged again. Separate runs cover a claim polled on protocol 1.19,
// an immediate cancel, a retried attempt, a run terminated after delivery and
// activity deliveries refused by the run's typed history.
// Responses are recorded raw, without the protocol's capability map beyond
// its acknowledgement flag.
function finishHttpActivityCancellationAcknowledgement(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['activity_cancellation_acknowledgement'];
    $namespace = strtolower($workflowId.'-cack');
    $other = $namespace.'-other';
    $admin->createNamespace($namespace, 'activity cancellation acknowledgement λ', 30);
    $admin->createNamespace($other, 'activity cancellation acknowledgement other λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    // The SQLite reference shares its database with a one-second maintenance
    // loop; retry its retryable lock-pressure refusals, as the other probes do.
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new CancellationAckHttp(['base_uri' => rtrim($url, '/'), 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $raw = static function (string $method, string $path, ?array $body = [], ?string $protocol = '1.20', ?string $space = null) use ($http, $token, $namespace): array {
        $options = ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $space ?? $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2', 'X-Durable-Workflow-Protocol-Version' => $protocol,
        ])];
        if ($body !== null) {
            $options['json'] = $body === [] ? (object) [] : $body;
        }
        $response = $http->request($method, $path, $options);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $capability = is_array($decoded) && array_key_exists('server_capabilities', $decoded)
            ? ($decoded['server_capabilities']['activity_cancellation_acknowledgement'] ?? null) : 'absent';
        unset($decoded['server_capabilities'], $decoded['control_plane']);

        return ['status' => $response->getStatusCode(), 'response' => $decoded, 'acknowledgement_capability' => $capability];
    };
    $ok = static function (array $recorded, int $status, string $operation): array {
        if ($recorded['status'] !== $status) {
            throw new RuntimeException('Activity cancellation acknowledgement '.$operation.': '.json_encode($recorded));
        }

        return $recorded['response'];
    };
    // Avro payloads precomputed by the published serializer: [] and 'done'.
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $done = ['codec' => 'avro', 'blob' => $definition['result_blob']];
    // Starts a run on its own queue with its own cooperative worker and claims
    // its single activity, on the given poll protocol.
    $claim = static function (string $suffix, array $retry = ['max_attempts' => 1, 'backoff_seconds' => []], string $activityProtocol = '1.20') use ($raw, $ok, $empty, $definition, $workflowId): array {
        $id = $workflowId.'-'.$suffix;
        $queue = $id.'-queue';
        $worker = $id.'-worker';
        $ok($raw('POST', '/api/worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
            'sdk_version' => 'raw-http', 'supported_workflow_types' => [$definition['workflow_type']],
            'supported_activity_types' => [$definition['activity_type']], 'capabilities' => ['cooperative_cancellation'],
            'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
                ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http'])]), 201, 'registration');
        $poll = ['worker_id' => $worker, 'task_queue' => $queue, 'timeout_seconds' => 0];
        $started = $ok($raw('POST', '/api/workflows', ['workflow_id' => $id, 'workflow_type' => $definition['workflow_type'],
            'task_queue' => $queue, 'input' => $empty]), 201, 'start');
        $task = $ok($raw('POST', '/api/worker/workflow-tasks/poll', $poll), 200, 'workflow claim')['task'];
        $ok($raw('POST', '/api/worker/workflow-tasks/'.$task['task_id'].'/complete', [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => [['type' => 'schedule_activity', 'activity_type' => $definition['activity_type'],
                'arguments' => $empty, 'retry_policy' => $retry, 'start_to_close_timeout' => 300]]]), 200, 'schedule activity');
        $activity = $ok($raw('POST', '/api/worker/activity-tasks/poll', $poll, $activityProtocol), 200, 'activity claim')['task'];

        return ['workflow_id' => $id, 'run_id' => $started['run_id'], 'queue' => $queue, 'worker' => $worker,
            'workflow_task_id' => $task['task_id'], 'task_id' => $activity['task_id'],
            'activity_attempt_id' => $activity['activity_attempt_id'],
            'activity_execution_id' => $activity['activity_execution_id'] ?? null, 'lease_owner' => $activity['lease_owner']];
    };
    $own = static fn (array $claimed): array => ['activity_attempt_id' => $claimed['activity_attempt_id'], 'lease_owner' => $claimed['lease_owner']];
    $ack = static fn (array $claimed, ?array $body, ?string $protocol = '1.20', ?string $space = null, ?string $task = null): array => $raw('POST',
        '/api/worker/activity-tasks/'.($task ?? $claimed['task_id']).'/acknowledge-cancellation', $body, $protocol, $space);
    $status = static fn (array $claimed): array => $raw('POST', '/api/worker/activity-tasks/'.$claimed['task_id'].'/status', $own($claimed));
    $history = static fn (array $claimed): array => $ok($raw('GET',
        '/api/workflows/'.$claimed['workflow_id'].'/runs/'.$claimed['run_id'].'/history', null), 200, 'history')['events'] ?? [];
    // Requests cooperative cancellation of the selected run.
    $request = static fn (array $claimed): array => $raw('POST',
        '/api/workflows/'.$claimed['workflow_id'].'/runs/'.$claimed['run_id'].'/request-cancellation',
        ['reason' => 'acknowledgement fixture λ', 'cleanup_timeout_seconds' => $definition['cleanup_timeout_seconds']]);
    // Polls the run's cancellation workflow task and delivers the request to
    // the activity, the first command of the run.
    $deliver = static function (array $claimed) use ($raw, $ok): array {
        $task = $ok($raw('POST', '/api/worker/workflow-tasks/poll',
            ['worker_id' => $claimed['worker'], 'task_queue' => $claimed['queue'], 'timeout_seconds' => 0]), 200, 'cancellation claim')['task'];
        $requestId = $task['cancellation_request']['request_id'] ?? null;

        return ['request_id' => $requestId, 'poll_cancellation_request' => $task['cancellation_request'] ?? null,
            'delivery' => $raw('POST', '/api/worker/workflow-tasks/'.$task['task_id'].'/deliver-cancellation', [
                'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
                'request_id' => $requestId, 'sequence' => 1, 'call_kind' => 'activity'])];
    };

    $state = ['applicable' => true, 'namespace' => $namespace];
    $cluster = $ok($raw('GET', '/api/cluster/info', null), 200, 'cluster info');
    $capabilities = $cluster['worker_protocol']['server_capabilities'] ?? [];
    $state['discovery'] = ['activity_cancellation_acknowledgement' => $capabilities['activity_cancellation_acknowledgement'] ?? 'absent',
        'cooperative_cancellation' => $capabilities['cooperative_cancellation'] ?? 'absent'];

    $main = $state['main'] = $claim('main');
    $state['before_request'] = $ack($main, $own($main) + ['request_id' => 'not-yet-requested']);
    $state['request'] = $request($main);
    $requestId = $state['request']['response']['cancellation_request']['request_id'] ?? null;
    $owned = $own($main) + ['request_id' => $requestId];
    $state['before_delivery'] = $ack($main, $owned);
    $state['status_before_delivery'] = $status($main);
    $delivered = $deliver($main);
    $state['poll_cancellation_request'] = $delivered['poll_cancellation_request'];
    $state['delivery'] = $delivered['delivery'];
    $state['heartbeat_after_delivery'] = $raw('POST', '/api/worker/activity-tasks/'.$main['task_id'].'/heartbeat', $own($main));
    $state['status_before_ack'] = $status($main);
    $state['history_before_ack'] = $history($main);
    $state['foreign_owner'] = $ack($main, ['lease_owner' => 'someone-else'] + $owned);
    $state['unknown_attempt'] = $ack($main, ['activity_attempt_id' => 'no-such-attempt'] + $owned);
    $state['unknown_task'] = $ack($main, $owned, '1.20', null, 'no-such-task');
    $state['task_mismatch'] = $ack($main, $owned, '1.20', null, $main['workflow_task_id']);
    $state['other_namespace'] = $ack($main, $owned, '1.20', $other);
    $state['protocol_1_19'] = $ack($main, $owned, '1.19');
    $state['protocol_missing'] = $ack($main, $owned, null);
    $state['protocol_1_21'] = $ack($main, $owned, '1.21');
    $state['missing_fields'] = $ack($main, []);
    $state['non_string_fields'] = $ack($main, ['activity_attempt_id' => 5, 'lease_owner' => ['x'], 'request_id' => true]);
    $state['long_request_id'] = $ack($main, $own($main) + ['request_id' => str_repeat('r', 256)]);
    $state['wrong_request_id'] = $ack($main, $own($main) + ['request_id' => 'other-request']);
    $state['history_after_refusals'] = $history($main);
    $state['acknowledged'] = $ack($main, $owned + ['message' => 'stopped']);
    $state['status_after_ack'] = $status($main);
    $state['duplicate'] = $ack($main, $owned);
    $state['completed_after_ack'] = $raw('POST', '/api/worker/activity-tasks/'.$main['task_id'].'/complete', $own($main) + ['result' => $done]);
    $state['history_after_ack'] = $history($main);

    // The activity was claimed on protocol 1.19, so its claim cannot
    // acknowledge even on a 1.20 request.
    $legacy = $state['legacy_claim'] = $claim('legacy', activityProtocol: '1.19');
    $state['legacy_request'] = $request($legacy)['status'];
    $legacyDelivered = $deliver($legacy);
    $state['legacy_delivery'] = $legacyDelivered['delivery'];
    $state['legacy_ack'] = $ack($legacy, $own($legacy) + ['request_id' => $legacyDelivered['request_id']]);

    // An immediate cancel records no cooperative request.
    $immediate = $state['immediate_claim'] = $claim('immediate');
    $state['immediate_cancel'] = $raw('POST', '/api/workflows/'.$immediate['workflow_id'].'/cancel', ['reason' => 'acknowledgement fixture']);
    $state['immediate_ack'] = $ack($immediate, $own($immediate)
        + ['request_id' => $state['immediate_cancel']['response']['command_id'] ?? 'no-command-id']);
    $state['immediate_status'] = $status($immediate);

    // A retried attempt: attempt 1 fails retryably and attempt 2 is claimed on
    // a new task before the request is delivered.
    $first = $state['stale_first'] = $claim('stale', ['max_attempts' => 2, 'backoff_seconds' => [0]]);
    $state['stale_fail'] = $raw('POST', '/api/worker/activity-tasks/'.$first['task_id'].'/fail', $own($first)
        + ['failure' => ['message' => 'retry me', 'type' => 'parity.retryable', 'non_retryable' => false]]);
    $retried = $ok($raw('POST', '/api/worker/activity-tasks/poll',
        ['worker_id' => $first['worker'], 'task_queue' => $first['queue'], 'timeout_seconds' => 0]), 200, 'retry claim')['task'];
    $second = $state['stale_second'] = ['task_id' => $retried['task_id'], 'activity_attempt_id' => $retried['activity_attempt_id'],
        'lease_owner' => $retried['lease_owner']] + $first;
    $state['stale_request'] = $request($first)['status'];
    $staleDelivered = $deliver($first);
    $state['stale_delivery'] = $staleDelivered['delivery'];
    $staleRequest = ['request_id' => $staleDelivered['request_id']];
    $state['stale_first_attempt_own_task'] = $ack($first, $own($first) + $staleRequest);
    $state['stale_first_attempt_new_task'] = $ack($first, $own($first) + $staleRequest, '1.20', null, $second['task_id']);
    $state['stale_second_attempt_old_task'] = $ack($second, $own($second) + $staleRequest, '1.20', null, $first['task_id']);
    $state['stale_second_attempt'] = $ack($second, $own($second) + $staleRequest);
    $state['stale_second_status'] = $status($second);
    $state['stale_history'] = $history($first);

    // Terminated after delivery, then acknowledged.
    $terminated = $state['terminated_claim'] = $claim('terminated');
    $state['terminated_request'] = $request($terminated)['status'];
    $terminatedDelivered = $deliver($terminated);
    $state['terminated_delivery'] = $terminatedDelivered['delivery'];
    $state['terminate'] = $raw('POST', '/api/workflows/'.$terminated['workflow_id'].'/terminate', ['reason' => 'acknowledgement fixture'])['status'];
    $state['terminated_ack'] = $ack($terminated, $own($terminated) + ['request_id' => $terminatedDelivered['request_id']]);
    $state['terminated_history'] = array_column($history($terminated), 'event_type');

    // An activity delivery is checked against typed history first: the
    // activity completed before the request, then a timer was started at
    // sequence 2. Delivering to the activity, or as an activity at the
    // timer, is refused and records nothing; the timer then takes it.
    $boundary = $state['boundary_claim'] = $claim('boundary');
    $boundaryPoll = ['worker_id' => $boundary['worker'], 'task_queue' => $boundary['queue'], 'timeout_seconds' => 0];
    $state['boundary_complete'] = $raw('POST', '/api/worker/activity-tasks/'.$boundary['task_id'].'/complete',
        $own($boundary) + ['result' => $done])['status'];
    $resumed = $ok($raw('POST', '/api/worker/workflow-tasks/poll', $boundaryPoll), 200, 'boundary resume')['task'];
    $state['boundary_timer'] = $raw('POST', '/api/worker/workflow-tasks/'.$resumed['task_id'].'/complete', [
        'lease_owner' => $resumed['lease_owner'], 'workflow_task_attempt' => $resumed['workflow_task_attempt'],
        'commands' => [['type' => 'start_timer', 'delay_seconds' => 3600]]])['status'];
    $state['boundary_request'] = $request($boundary)['status'];
    $cancelling = $ok($raw('POST', '/api/worker/workflow-tasks/poll', $boundaryPoll), 200, 'boundary cancellation claim')['task'];
    $state['boundary_task_id'] = $cancelling['task_id'];
    $deliverAt = static fn (int $sequence, string $kind): array => $raw('POST',
        '/api/worker/workflow-tasks/'.$cancelling['task_id'].'/deliver-cancellation', [
            'lease_owner' => $cancelling['lease_owner'], 'workflow_task_attempt' => $cancelling['workflow_task_attempt'],
            'request_id' => $cancelling['cancellation_request']['request_id'] ?? null, 'sequence' => $sequence,
            'call_kind' => $kind]);
    $state['boundary_history_before'] = $history($boundary);
    $state['boundary_resolved_activity'] = $deliverAt(1, 'activity');
    $state['boundary_timer_as_activity'] = $deliverAt(2, 'activity');
    $state['boundary_history_after_refusals'] = $history($boundary);
    $state['boundary_pending'] = $request($boundary);
    $state['boundary_delivery'] = $deliverAt(2, 'timer');
    $state['boundary_history_after_delivery'] = $history($boundary);

    return $state;
}
