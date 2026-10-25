<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as WorkflowPollRequestHttp;
use GuzzleHttp\HandlerStack;

// Workflow-task poll request idempotency (`poll_request_id` on
// POST /api/worker/workflow-tasks/poll) over raw worker HTTP in a fresh
// namespace and queue. One worker records, in order: an empty poll and its
// replay after work arrived; a fresh request id's lease and its replay; the
// replay after a workflow-task heartbeat renewed the lease; a padded copy of
// the id; the same id after its task completed while another was ready, and
// its replay; and the same id after that task completed with nothing ready.
// Responses are recorded raw, without the protocol's capability map.
function finishHttpWorkflowPollRequest(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['workflow_poll_request'];
    $namespace = strtolower($workflowId.'-wpr');
    $admin->createNamespace($namespace, 'workflow poll request λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    // The SQLite reference shares its database with a one-second maintenance
    // loop; retry its retryable lock-pressure refusals, as the other probes do.
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new WorkflowPollRequestHttp(['base_uri' => rtrim($url, '/'), 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $raw = static function (string $method, string $path, ?array $body = []) use ($http, $token, $namespace): array {
        $options = ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2', 'X-Durable-Workflow-Protocol-Version' => '1.20',
        ])];
        if ($body !== null) {
            $options['json'] = $body === [] ? (object) [] : $body;
        }
        $response = $http->request($method, $path, $options);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        unset($decoded['server_capabilities'], $decoded['control_plane']);

        return ['status' => $response->getStatusCode(), 'response' => $decoded];
    };
    $ok = static function (array $recorded, int $status, string $operation): array {
        if ($recorded['status'] !== $status) {
            throw new RuntimeException('Workflow poll request '.$operation.': '.json_encode($recorded));
        }

        return $recorded['response'];
    };
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $queue = $workflowId.'-wpr-queue';
    $worker = $workflowId.'-wpr-worker';
    $ok($raw('POST', '/api/worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
        'sdk_version' => 'raw-http', 'supported_workflow_types' => [$definition['workflow_type']],
        'supported_activity_types' => [], 'capabilities' => [],
        'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
            ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http'])]), 201, 'registration');
    $poll = static fn (string $requestId): array => $raw('POST', '/api/worker/workflow-tasks/poll',
        ['worker_id' => $worker, 'task_queue' => $queue, 'timeout_seconds' => 0, 'poll_request_id' => $requestId]);
    $start = static fn (string $suffix): array => $ok($raw('POST', '/api/workflows', ['workflow_id' => $workflowId.'-wpr-'.$suffix,
        'workflow_type' => $definition['workflow_type'], 'task_queue' => $queue, 'input' => $empty]), 201, 'start '.$suffix);
    $complete = static fn (array $task): array => $raw('POST', '/api/worker/workflow-tasks/'.$task['task_id'].'/complete', [
        'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
        'commands' => [['type' => 'complete_workflow', 'result' => $empty]]]);
    // The identity and lease of a delivered task, or its absence.
    $delivery = static fn (array $recorded): array => ['status' => $recorded['status'],
        'poll_status' => $recorded['response']['poll_status'] ?? null,
        'task_id' => $recorded['response']['task']['task_id'] ?? null,
        'workflow_id' => $recorded['response']['task']['workflow_id'] ?? null,
        'attempt' => $recorded['response']['task']['workflow_task_attempt'] ?? null,
        'lease_owner' => $recorded['response']['task']['lease_owner'] ?? null,
        'lease_expires_at' => $recorded['response']['task']['lease_expires_at'] ?? null,
        'reason' => $recorded['response']['reason'] ?? null];

    $state = ['applicable' => true, 'namespace' => $namespace, 'queue' => $queue, 'worker' => $worker];
    $requestId = $definition['request_id'];
    $state['empty'] = $delivery($poll($definition['empty_request_id']));
    $first = $start('first');
    $state['empty_replay'] = $delivery($poll($definition['empty_request_id']));
    $leased = $poll($requestId);
    $state['leased'] = $delivery($leased);
    $state['leased_replay'] = $delivery($poll($requestId));
    usleep(1100000);
    // A server that leased nothing is recorded as it answered; the contract
    // reports the difference.
    $task = $leased['response']['task'] ?? null;
    $heartbeat = is_array($task) ? $raw('POST', '/api/worker/workflow-tasks/'.$task['task_id'].'/heartbeat',
        ['lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt']]) : ['status' => null, 'response' => []];
    $state['heartbeat'] = ['status' => $heartbeat['status'], 'lease_expires_at' => $heartbeat['response']['lease_expires_at'] ?? null];
    $state['heartbeat_replay'] = $delivery($poll($requestId));
    $state['padded_replay'] = $delivery($poll('  '.$requestId.'  '));
    $start('second');
    $state['first_completed'] = is_array($task) ? $complete($task)['status'] : null;
    $next = $poll($requestId);
    $state['after_completion'] = $delivery($next);
    $state['after_completion_replay'] = $delivery($poll($requestId));
    $state['second_completed'] = isset($next['response']['task']) ? $complete($next['response']['task'])['status'] : null;
    $state['after_drain'] = $delivery($poll($requestId));
    $state['first_workflow_id'] = $first['workflow_id'];

    return $state;
}
