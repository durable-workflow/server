<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as DashboardTypesHttp;
use GuzzleHttp\HandlerStack;

// The bounded operator dashboard narrowed by workflow type, over raw HTTP, in
// a fresh namespace: a worker, three started runs of two workflow types and
// one failed workflow task. Each fixture case reads the filtered dashboard;
// each refusal sends one invalid list. The dashboards are reduced to the
// figures the contract checks, and the refusals are recorded raw.
function finishHttpOperatorDashboardTypes(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['operator_dashboard_types'];
    $namespace = strtolower($workflowId.'-dash');
    $queue = $namespace.'-queue';
    $worker = $namespace.'-worker';
    $admin->createNamespace($namespace, 'dashboard types λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    // The SQLite reference shares its database with a one-second maintenance
    // loop; retry its retryable lock-pressure refusals, as the other probes do.
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new DashboardTypesHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
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
    $summary = static function (array $read): array {
        $dashboard = $read['response']['dashboard'] ?? null;
        if (! is_array($dashboard)) {
            return $read;
        }
        $type = static fn (mixed $row): ?string => is_array($row) ? ($row['workflow_type'] ?? '') : null;
        $series = $dashboard['fleet_trends_series'] ?? [];

        return ['status' => $read['status'], 'namespace' => $read['response']['namespace'] ?? null,
            'keys' => array_keys($dashboard), 'operator_metric_keys' => array_keys($dashboard['operator_metrics'] ?? []),
            'flows' => $dashboard['flows'] ?? null, 'flows_past_hour' => $dashboard['flows_past_hour'] ?? null,
            'flows_per_minute' => $dashboard['flows_per_minute'] ?? null,
            'exceptions_past_hour' => $dashboard['exceptions_past_hour'] ?? null,
            'failed_flows_past_week' => $dashboard['failed_flows_past_week'] ?? null,
            'max_wait_time_workflow' => $type($dashboard['max_wait_time_workflow'] ?? null),
            'max_duration_workflow' => $type($dashboard['max_duration_workflow'] ?? null),
            'max_exceptions_workflow' => $type($dashboard['max_exceptions_workflow'] ?? null),
            'fleet_overview' => $dashboard['fleet_overview'] ?? null,
            'workflow_type_health' => array_map(static fn (array $row): array => [$row['workflow_type'] ?? null, $row['total_runs'] ?? null],
                $dashboard['workflow_type_health'] ?? []),
            'alerts' => $dashboard['needs_attention']['total_alerts'] ?? null,
            'trend_hours' => count($series['timestamps'] ?? []),
            'trend_runs' => array_sum($series['completed'] ?? []) + array_sum($series['failed'] ?? []),
            'workflow_scope' => $dashboard['workflow_scope'] ?? null,
            'operator_metrics_scope' => $dashboard['operator_metrics_scope'] ?? null];
    };
    $input = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $state = ['applicable' => true, 'namespace' => $namespace];
    $state['registered'] = $raw('POST', 'worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
        'sdk_version' => 'raw-http', 'supported_workflow_types' => $definition['workflow_types'], 'supported_activity_types' => [],
        'capabilities' => [], 'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
            ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http'])], true)['status'];
    $state['started'] = [];
    foreach ($definition['starts'] as $index => $type) {
        $state['started'][] = $raw('POST', 'workflows', ['workflow_id' => $namespace.'-'.$index,
            'workflow_type' => $type, 'task_queue' => $queue, 'input' => $input])['status'];
    }
    // A short long-poll waits out a claim the maintenance loop contends.
    $task = $raw('POST', 'worker/workflow-tasks/poll', ['worker_id' => $worker, 'task_queue' => $queue, 'timeout_seconds' => 5], true)['response']['task'] ?? [];
    $state['failed'] = $raw('POST', 'worker/workflow-tasks/'.($task['task_id'] ?? 'none').'/fail', [
        'lease_owner' => $task['lease_owner'] ?? null, 'workflow_task_attempt' => $task['workflow_task_attempt'] ?? null,
        'failure' => ['message' => 'dashboard fixture failure', 'type' => 'RuntimeException'],
    ], true)['status'];
    $state['unfiltered'] = $summary($raw('GET', 'system/operator-dashboard/bounded'));
    $filtered = static fn (?string $list): array => $raw('GET', 'system/operator-dashboard/bounded/workflow-types'
        .($list === null ? '' : '?workflow_types='.rawurlencode($list)));
    $state['cases'] = [];
    foreach ($definition['cases'] as $case => $expected) {
        $state['cases'][$case] = $summary($filtered(json_encode($expected['types'], JSON_THROW_ON_ERROR)));
    }
    $state['refusals'] = [];
    foreach ($definition['refusals'] as $case => $refusal) {
        $state['refusals'][$case] = $filtered($refusal['query']);
    }

    return $state;
}
