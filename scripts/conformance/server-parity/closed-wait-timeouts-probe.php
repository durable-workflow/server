<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as ClosedWaitHttp;
use GuzzleHttp\HandlerStack;

// Freeze published history independently of native's physical timer cleanup.
function finishHttpClosedWaitTimeouts(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['closed_wait_timeouts'];
    $namespace = strtolower($workflowId.'-closed-waits');
    $admin->createNamespace($namespace, 'closed wait timeout histories', 30);
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new ClosedWaitHttp(['base_uri' => rtrim($url, '/'), 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $raw = static function (string $method, string $path, ?array $body = null) use ($http, $namespace): array {
        $options = ['headers' => array_filter([
            'Authorization' => getenv('DW_PARITY_TOKEN') ? 'Bearer '.getenv('DW_PARITY_TOKEN') : null,
            'Accept' => 'application/json', 'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2', 'X-Durable-Workflow-Protocol-Version' => '1.20',
        ])];
        if ($body !== null) {
            $options['json'] = $body === [] ? (object) [] : $body;
        }
        $response = $http->request($method, $path, $options);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException('Closed wait timeout '.$method.' '.$path.' HTTP '.$response->getStatusCode()
                .': '.($decoded['reason'] ?? $decoded['message'] ?? 'unknown'));
        }

        return $decoded;
    };
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $cases = [];
    foreach (['condition', 'signal'] as $kind) {
        foreach (['complete', 'fail', 'continue', 'cancel', 'terminate'] as $close) {
            $id = $workflowId.'-'.$kind.'-'.$close;
            $queue = $id.'-queue';
            $worker = $id.'-worker';
            $raw('POST', '/api/worker/register', [
                'worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
                'supported_workflow_types' => [$definition['workflow_type']], 'supported_activity_types' => [],
                'capabilities' => [], 'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
                    ['supported' => false, 'minimum_protocol_version' => '1.18']),
            ]);
            $run = $raw('POST', '/api/workflows', ['workflow_id' => $id,
                'workflow_type' => $definition['workflow_type'], 'task_queue' => $queue, 'input' => $empty]);
            $task = $raw('POST', '/api/worker/workflow-tasks/poll', [
                'worker_id' => $worker, 'task_queue' => $queue, 'timeout_seconds' => 5,
            ])['task'] ?? null;
            if (! is_array($task) || ($task['run_id'] ?? null) !== $run['run_id']) {
                throw new RuntimeException('Closed wait timeout expected an original run claim.');
            }
            $wait = $kind === 'condition'
                ? ['type' => 'open_condition_wait', 'condition_key' => 'ready',
                    'condition_wait_occurrence_id' => $id.':0', 'timeout_seconds' => $definition['timeout_seconds']]
                : ['type' => 'open_signal_wait', 'signal_name' => 'wake', 'timeout_seconds' => $definition['timeout_seconds']];
            $commands = [$wait];
            if ($close === 'complete') {
                $commands[] = ['type' => 'complete_workflow', 'result' => $empty];
            } elseif ($close === 'fail') {
                $commands[] = ['type' => 'fail_workflow', 'message' => 'close wait probe'];
            } elseif ($close === 'continue') {
                $commands[] = ['type' => 'continue_as_new'];
            }
            $raw('POST', '/api/worker/workflow-tasks/'.$task['task_id'].'/complete', [
                'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'], 'commands' => $commands,
            ]);
            if (in_array($close, ['cancel', 'terminate'], true)) {
                $raw('POST', '/api/workflows/'.$id.'/'.$close, ['reason' => 'close wait probe']);
            }
            $path = '/api/workflows/'.$id.'/runs/'.$run['run_id'];
            $described = $raw('GET', $path);
            $history = $raw('GET', $path.'/history');
            $cases[$kind.'_'.$close] = ['status' => $described['status'],
                'history' => array_column($history['events'] ?? [], 'event_type')];
        }
    }

    return ['applicable' => true, 'cases' => $cases];
}
