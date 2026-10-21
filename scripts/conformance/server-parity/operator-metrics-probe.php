<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as OperatorMetricsHttp;

// Operator metrics and health of a fresh namespace at each stage of one run:
// before any work, waiting for a worker, past the redispatch window, with an
// activity scheduled, running and retrying, and after completion. Each stage
// keeps the counts and check statuses; timestamps and ages are left out.
function finishHttpOperatorMetrics(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['operator_metrics'];
    $namespace = strtolower($workflowId.'-metrics');
    $admin->createNamespace($namespace, 'operator metrics', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $client = parityClient($url, namespace: $namespace, token: $token);
    $http = new OperatorMetricsHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30]);
    $get = static function (string $path) use ($http, $token, $namespace): array {
        $response = $http->get($path, ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ])]);

        return ['status' => $response->getStatusCode(),
            'body' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    };
    $numbers = static fn (array $section): array => array_filter($section,
        static fn ($value, string $key): bool => is_int($value) && ! str_contains($key, '_ms'), ARRAY_FILTER_USE_BOTH);
    $present = static fn (array $section): array => array_map(static fn ($value): bool => $value !== null,
        array_filter($section, static fn (string $key): bool => str_starts_with($key, 'oldest_'), ARRAY_FILTER_USE_KEY));
    $stage = static function () use ($get, $numbers, $present, $namespace): array {
        $metrics = $get('system/operator-metrics');
        $health = $get('system/health');
        $document = $metrics['body']['operator_metrics'] ?? [];
        $sections = [];
        foreach (['runs', 'tasks', 'activities', 'backlog', 'starts', 'history', 'schedules', 'command_contracts'] as $name) {
            $sections[$name] = ['counts' => $numbers($document[$name] ?? []), 'timestamps' => $present($document[$name] ?? [])];
        }
        $checks = [];
        foreach ($health['body']['health']['checks'] ?? [] as $check) {
            $checks[] = ['name' => $check['name'], 'status' => $check['status'], 'category' => $check['category']];
        }

        return [
            'metrics_status' => $metrics['status'],
            'metrics_namespace' => $metrics['body']['namespace'] ?? null,
            'metrics_keys' => array_keys($metrics['body'] ?? []),
            'sections' => $sections,
            'active_workers' => $document['workers']['active_workers'] ?? null,
            'worker_fleet' => array_map(static fn (array $entry): array => ['worker_id' => $entry['worker_id'] ?? null,
                'namespace' => $entry['namespace'] ?? null, 'queue' => $entry['queue'] ?? null],
                $document['workers']['fleet'] ?? []),
            'health_status' => $health['status'],
            'health_namespace' => $health['body']['namespace'] ?? null,
            'retention' => [$health['body']['retention_mode'] ?? null, $health['body']['retention_days'] ?? null],
            'health' => ['status' => $health['body']['health']['status'] ?? null, 'healthy' => $health['body']['health']['healthy'] ?? null,
                'categories' => $health['body']['health']['categories'] ?? null],
            'checks' => $checks,
            'expected_namespace' => $namespace,
        ];
    };
    $queue = $workflowId.'-metrics';
    $worker = $workflowId.'-metrics-worker';
    $input = $fixture['input'];
    $stages = ['empty' => $stage()];
    $client->startWorkflow($definition['workflow_type'], $workflowId.'-metrics-run', $queue, [$input]);
    $stages['waiting'] = $stage();
    // Past PHP's three-second redispatch window, the unclaimed task is overdue.
    usleep((int) ($definition['redispatch_wait_seconds'] * 1_000_000));
    $stages['waiting_after_redispatch'] = $stage();
    $client->registerWorker($worker, $queue, [$definition['workflow_type']], [$definition['activity_type']]);
    $task = $client->pollWorkflowTask($worker, $queue, 5);
    $client->completeWorkflowTask($task['task_id'], $worker, $task['workflow_task_attempt'], [[
        'type' => 'schedule_activity', 'activity_type' => $definition['activity_type'],
        'arguments' => $client->payloadCodec()->envelope([$input]),
        'retry_policy' => ['max_attempts' => 2, 'backoff_seconds' => [$definition['retry_backoff_seconds']]],
    ]]);
    $stages['activity_scheduled'] = $stage();
    $activity = $client->pollActivityTask($worker, $queue, 5);
    $stages['activity_running'] = $stage();
    $client->failActivityTask($activity['task_id'], $activity['activity_attempt_id'], $worker, 'first attempt fails');
    $stages['activity_retrying'] = $stage();
    usleep((int) (($definition['retry_backoff_seconds'] + 1) * 1_000_000));
    $activity = $client->pollActivityTask($worker, $queue, 5);
    $client->completeActivityTask($activity['task_id'], $activity['activity_attempt_id'], $worker, $input);
    $task = $client->pollWorkflowTask($worker, $queue, 5);
    $client->completeWorkflowTask($task['task_id'], $worker, $task['workflow_task_attempt'],
        [['type' => 'complete_workflow', 'result' => $client->payloadCodec()->envelope($input)]]);
    $stages['completed'] = $stage();

    return ['applicable' => true, 'namespace' => $namespace, 'stages' => $stages];
}
