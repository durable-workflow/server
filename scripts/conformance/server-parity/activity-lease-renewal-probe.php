<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as LeaseRenewalHttp;

// Runtime activity lease renewal (POST /api/worker/activity-tasks/{task}/renew-lease)
// over raw worker HTTP in a fresh namespace. Three runs each claim one
// activity on their own queue: the first is renewed live, refused for each
// ownership fault, heartbeated, completed and renewed again; the second is
// cancelled and the third terminated before renewal. Responses are recorded
// raw, without the protocol's capability map beyond its renewal flag.
function finishHttpActivityLeaseRenewal(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['activity_lease_renewal'];
    $namespace = strtolower($workflowId.'-lease');
    $admin->createNamespace($namespace, 'activity lease renewal λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $http = new LeaseRenewalHttp(['base_uri' => rtrim($url, '/'), 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30]);
    $raw = static function (string $method, string $path, ?array $body = [], string $protocol = '1.20') use ($http, $token, $namespace): array {
        $options = ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2', 'X-Durable-Workflow-Protocol-Version' => $protocol,
        ])];
        if ($body !== null) {
            $options['json'] = $body === [] ? (object) [] : $body;
        }
        $response = $http->request($method, $path, $options);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $capability = is_array($decoded) && array_key_exists('server_capabilities', $decoded)
            ? ($decoded['server_capabilities']['activity_lease_renewal'] ?? null) : 'absent';
        unset($decoded['server_capabilities'], $decoded['control_plane']);

        return ['status' => $response->getStatusCode(), 'response' => $decoded, 'lease_renewal_capability' => $capability];
    };
    $ok = static function (array $recorded, int $status, string $operation): array {
        if ($recorded['status'] !== $status) {
            throw new RuntimeException('Activity lease renewal '.$operation.': '.json_encode($recorded));
        }

        return $recorded['response'];
    };
    // Avro payloads precomputed by the published serializer: [] and 'done'.
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $done = ['codec' => 'avro', 'blob' => $definition['result_blob']];
    // Starts a run on its own queue with its own worker and claims its single
    // activity.
    $claim = static function (string $suffix) use ($raw, $ok, $empty, $definition, $workflowId): array {
        $id = $workflowId.'-'.$suffix;
        $queue = $id.'-queue';
        $worker = $id.'-worker';
        $ok($raw('POST', '/api/worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
            'sdk_version' => 'raw-http', 'supported_workflow_types' => [$definition['workflow_type']],
            'supported_activity_types' => [$definition['activity_type']], 'capabilities' => [],
            'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
                ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http'])]), 201, 'registration');
        $poll = ['worker_id' => $worker, 'task_queue' => $queue, 'timeout_seconds' => 0];
        $started = $ok($raw('POST', '/api/workflows', ['workflow_id' => $id, 'workflow_type' => $definition['workflow_type'],
            'task_queue' => $queue, 'input' => $empty]), 201, 'start');
        $task = $ok($raw('POST', '/api/worker/workflow-tasks/poll', $poll), 200, 'workflow claim')['task'];
        $ok($raw('POST', '/api/worker/workflow-tasks/'.$task['task_id'].'/complete', [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => [['type' => 'schedule_activity', 'activity_type' => $definition['activity_type'],
                'arguments' => $empty, 'retry_policy' => ['max_attempts' => 1, 'backoff_seconds' => []],
                'heartbeat_timeout' => $definition['heartbeat_timeout'], 'start_to_close_timeout' => 300]]]), 200, 'schedule activity');
        $activity = $ok($raw('POST', '/api/worker/activity-tasks/poll', $poll), 200, 'activity claim')['task'];

        return ['workflow_id' => $id, 'run_id' => $started['run_id'], 'workflow_task_id' => $task['task_id'],
            'task_id' => $activity['task_id'], 'activity_attempt_id' => $activity['activity_attempt_id'],
            'activity_execution_id' => $activity['activity_execution_id'] ?? null, 'lease_owner' => $activity['lease_owner']];
    };
    $own = static fn (array $claimed): array => ['activity_attempt_id' => $claimed['activity_attempt_id'], 'lease_owner' => $claimed['lease_owner']];
    $renew = static fn (array $claimed, ?array $body = null, string $protocol = '1.20'): array => $raw('POST',
        '/api/worker/activity-tasks/'.$claimed['task_id'].'/renew-lease', $body ?? $own($claimed), $protocol);
    $status = static fn (array $claimed): array => $raw('POST', '/api/worker/activity-tasks/'.$claimed['task_id'].'/status', $own($claimed));
    $history = static fn (array $claimed): array => array_column($ok($raw('GET',
        '/api/workflows/'.$claimed['workflow_id'].'/runs/'.$claimed['run_id'].'/history', null), 200, 'history')['events'] ?? [], 'event_type');

    $state = ['applicable' => true, 'namespace' => $namespace];
    $live = $state['live'] = $claim('live');
    $state['status_before'] = $status($live);
    $state['history_before'] = $history($live);
    usleep(20000);
    $state['renewed'] = $renew($live);
    $state['status_after'] = $status($live);
    $state['history_after'] = $history($live);
    $state['renewed_on_protocol_1_19'] = $renew($live, null, '1.19');
    $state['extra_field'] = $renew($live, $own($live) + ['message' => 'progress']);
    $state['foreign_owner'] = $renew($live, ['activity_attempt_id' => $live['activity_attempt_id'], 'lease_owner' => 'someone-else']);
    $state['unknown_attempt'] = $renew($live, ['activity_attempt_id' => 'no-such-attempt', 'lease_owner' => $live['lease_owner']]);
    $state['unknown_task'] = $raw('POST', '/api/worker/activity-tasks/no-such-task/renew-lease', $own($live));
    $state['task_mismatch'] = $raw('POST', '/api/worker/activity-tasks/'.$live['workflow_task_id'].'/renew-lease', $own($live));
    $state['missing_fields'] = $renew($live, []);
    $state['non_string_fields'] = $renew($live, ['activity_attempt_id' => 5, 'lease_owner' => ['x']]);
    $state['heartbeat'] = $raw('POST', '/api/worker/activity-tasks/'.$live['task_id'].'/heartbeat', $own($live) + ['message' => 'half']);
    $state['renewed_after_heartbeat'] = $renew($live);
    $state['completed'] = $raw('POST', '/api/worker/activity-tasks/'.$live['task_id'].'/complete', $own($live) + ['result' => $done]);
    $state['renewed_after_completion'] = $renew($live);

    $cancelled = $state['cancelled_claim'] = $claim('cancelled');
    $state['cancel'] = $raw('POST', '/api/workflows/'.$cancelled['workflow_id'].'/cancel', ['reason' => 'lease renewal fixture'])['status'];
    $state['renewed_after_cancel'] = $renew($cancelled);
    $terminated = $state['terminated_claim'] = $claim('terminated');
    $state['terminate'] = $raw('POST', '/api/workflows/'.$terminated['workflow_id'].'/terminate', ['reason' => 'lease renewal fixture'])['status'];
    $state['renewed_after_terminate'] = $renew($terminated);

    return $state;
}
