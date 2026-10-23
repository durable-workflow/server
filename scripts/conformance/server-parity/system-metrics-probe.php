<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as SystemMetricsHttp;
use GuzzleHttp\HandlerStack;

// The namespace metrics of a fresh namespace after a known sequence over raw
// HTTP: a worker, two started runs of two workflow types, one failed workflow
// task, and one schedule. Responses are recorded raw.
function finishHttpSystemMetrics(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['system_metrics'];
    $namespace = strtolower($workflowId.'-metrics');
    $queue = $namespace.'-queue';
    $worker = $namespace.'-worker';
    $admin->createNamespace($namespace, 'system metrics λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    // The SQLite reference shares its database with a one-second maintenance
    // loop; retry its retryable lock-pressure refusals, as the other probes do.
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new SystemMetricsHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $raw = static function (string $method, string $path, ?array $body = null, bool $worker = false) use ($http, $token, $namespace): array {
        $response = $http->request($method, $path, ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $namespace,
            $worker ? 'X-Durable-Workflow-Protocol-Version' : 'X-Durable-Workflow-Control-Plane-Version' => $worker ? '1.20' : '2',
        ]), ...($body === null ? [] : ['json' => $body])]);

        return ['status' => $response->getStatusCode(),
            'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    };
    $input = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $state = ['applicable' => true, 'namespace' => $namespace];
    $state['empty'] = $raw('GET', 'system/metrics');
    $state['registered'] = $raw('POST', 'worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
        'sdk_version' => 'raw-http', 'supported_workflow_types' => $definition['workflow_types'], 'supported_activity_types' => [],
        'capabilities' => [], 'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
            ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http'])], true)['status'];
    $state['started'] = [];
    foreach ($definition['workflow_types'] as $index => $type) {
        $state['started'][] = $raw('POST', 'workflows', ['workflow_id' => $namespace.'-'.$index,
            'workflow_type' => $type, 'task_queue' => $queue, 'input' => $input])['status'];
    }
    // A short long-poll waits out a claim the maintenance loop contends.
    $task = $raw('POST', 'worker/workflow-tasks/poll', ['worker_id' => $worker, 'task_queue' => $queue, 'timeout_seconds' => 5], true)['response']['task'] ?? [];
    $state['claimed_type'] = $task['workflow_type'] ?? null;
    $state['failed'] = $raw('POST', 'worker/workflow-tasks/'.($task['task_id'] ?? 'none').'/fail', [
        'lease_owner' => $task['lease_owner'] ?? null, 'workflow_task_attempt' => $task['workflow_task_attempt'] ?? null,
        'failure' => ['message' => 'metrics fixture failure', 'type' => 'RuntimeException'],
    ], true)['status'];
    $state['scheduled'] = $raw('POST', 'schedules', ['schedule_id' => $namespace.'-schedule', 'spec' => ['intervals' => [['every' => 'P1D']]],
        'action' => ['workflow_type' => $definition['workflow_types'][0], 'task_queue' => $queue, 'input' => $input]])['status'];
    $state['read'] = $raw('GET', 'system/metrics');
    $state['posted'] = $raw('POST', 'system/metrics');

    return $state;
}
