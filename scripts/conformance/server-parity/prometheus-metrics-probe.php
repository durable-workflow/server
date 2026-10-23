<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as PrometheusMetricsHttp;

// The Prometheus summary of a fresh namespace before and after a known
// sequence over raw HTTP: a worker with declared slots on the first queue; a
// run there that completes after one activity; a second run there that is
// terminated; and a run on a second queue with no worker. Responses are
// recorded raw.
function finishHttpPrometheusMetrics(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['prometheus_metrics'];
    $namespace = strtolower($workflowId.'-prometheus');
    $first = $namespace.'-q1';
    $second = $namespace.'-q2';
    $worker = $namespace.'-worker';
    $admin->createNamespace($namespace, 'prometheus metrics λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $http = new PrometheusMetricsHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30]);
    $raw = static function (string $method, string $path, ?array $body = null, bool $worker = false) use ($http, $token, $namespace): array {
        $response = $http->request($method, $path, ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $namespace,
            $worker ? 'X-Durable-Workflow-Protocol-Version' : 'X-Durable-Workflow-Control-Plane-Version' => $worker ? '1.20' : '2',
        ]), ...($body === null ? [] : ['json' => $body])]);

        return ['status' => $response->getStatusCode(),
            'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    };
    [$typeA, $typeB] = $definition['workflow_types'];
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $poll = ['worker_id' => $worker, 'task_queue' => $first, 'timeout_seconds' => 0];
    $state = ['applicable' => true, 'namespace' => $namespace, 'queues' => [$first, $second], 'statuses' => []];
    $step = static function (string $label, array $recorded) use (&$state): array {
        $state['statuses'][$label] = $recorded['status'];

        return $recorded['response'];
    };
    $state['empty'] = $raw('GET', 'system/prometheus-metrics');
    $step('register', $raw('POST', 'worker/register', ['worker_id' => $worker, 'task_queue' => $first, 'runtime' => 'php',
        'sdk_version' => 'raw-http', 'supported_workflow_types' => [$typeA, $typeB],
        'supported_activity_types' => [$definition['activity_type']], 'capabilities' => [],
        'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
            ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http']),
        'max_concurrent_workflow_tasks' => $definition['workflow_slots'],
        'max_concurrent_activity_tasks' => $definition['activity_slots']], true));
    $step('start completed', $raw('POST', 'workflows', ['workflow_id' => $namespace.'-completed',
        'workflow_type' => $typeA, 'task_queue' => $first, 'input' => $empty]));
    $task = $step('claim', $raw('POST', 'worker/workflow-tasks/poll', $poll, true))['task'] ?? [];
    $step('schedule activity', $raw('POST', 'worker/workflow-tasks/'.($task['task_id'] ?? 'none').'/complete', [
        'lease_owner' => $task['lease_owner'] ?? null, 'workflow_task_attempt' => $task['workflow_task_attempt'] ?? null,
        'commands' => [['type' => 'schedule_activity', 'activity_type' => $definition['activity_type'], 'arguments' => $empty,
            'retry_policy' => ['max_attempts' => 1, 'backoff_seconds' => []], 'start_to_close_timeout' => 300]]], true));
    $activity = $step('claim activity', $raw('POST', 'worker/activity-tasks/poll', $poll, true))['task'] ?? [];
    // Each run and activity lasts at least this long, so its duration is above
    // the lowest latency bounds.
    usleep($definition['minimum_duration_ms'] * 1000);
    $step('complete activity', $raw('POST', 'worker/activity-tasks/'.($activity['task_id'] ?? 'none').'/complete', [
        'activity_attempt_id' => $activity['activity_attempt_id'] ?? null, 'lease_owner' => $activity['lease_owner'] ?? null,
        'result' => $empty], true));
    $task = $step('claim resume', $raw('POST', 'worker/workflow-tasks/poll', $poll, true))['task'] ?? [];
    $step('complete run', $raw('POST', 'worker/workflow-tasks/'.($task['task_id'] ?? 'none').'/complete', [
        'lease_owner' => $task['lease_owner'] ?? null, 'workflow_task_attempt' => $task['workflow_task_attempt'] ?? null,
        'commands' => [['type' => 'complete_workflow', 'result' => $empty]]], true));
    $step('start terminated', $raw('POST', 'workflows', ['workflow_id' => $namespace.'-terminated',
        'workflow_type' => $typeB, 'task_queue' => $first, 'input' => $empty]));
    usleep($definition['minimum_duration_ms'] * 1000);
    $step('terminate', $raw('POST', 'workflows/'.$namespace.'-terminated/terminate', ['reason' => 'prometheus fixture']));
    $step('start waiting', $raw('POST', 'workflows', ['workflow_id' => $namespace.'-waiting',
        'workflow_type' => $typeA, 'task_queue' => $second, 'input' => $empty]));
    // Past the second of its creation, the waiting run's task is due on every
    // backend, including PHP's second-precision comparison on SQLite.
    usleep($definition['minimum_duration_ms'] * 1000);
    $state['read'] = $raw('GET', 'system/prometheus-metrics');

    return $state;
}
