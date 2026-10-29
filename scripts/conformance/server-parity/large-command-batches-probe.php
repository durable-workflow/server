<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as LargeCommandBatchesHttp;
use GuzzleHttp\HandlerStack;

// Workflow task completions with more than 100 commands, over raw worker
// HTTP on protocol 1.20 in a fresh namespace (published Server
// WorkerController::completeWorkflowTask, Workflow WorkflowCommandNormalizer
// and StructuralLimits::commandBatchSizeLimit). An external worker's
// completion has no command-count limit of its own; a parallel group's
// declared size is bounded by the command batch size (1,000 by default).
// Each case completes its run's first workflow task with one batch, then
// reads the run's history in pages and keeps event counts by type.
function finishHttpLargeCommandBatches(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['large_command_batches'];
    $namespace = strtolower($workflowId.'-lcb');
    $admin->createNamespace($namespace, 'large command batches λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new LargeCommandBatchesHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 60, 'handler' => $stack]);
    $headers = array_filter([
        'Authorization' => $token === null ? null : 'Bearer '.$token,
        'Accept' => 'application/json', 'X-Namespace' => $namespace,
        'X-Durable-Workflow-Control-Plane-Version' => '2', 'X-Durable-Workflow-Protocol-Version' => '1.20',
    ]);
    // A string body is sent as written, so a JSON number past PHP's integer
    // range reaches the server as the literal token.
    $raw = static function (string $method, string $path, array|string|null $body = null) use ($http, $headers): array {
        $response = $http->request($method, $path, ['headers' => $headers,
            ...(match (true) {
                $body === null => [],
                is_string($body) => ['body' => $body, 'headers' => [...$headers, 'Content-Type' => 'application/json']],
                default => ['json' => $body === [] ? (object) [] : $body],
            })]);

        return ['status' => $response->getStatusCode(),
            'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    };
    $type = $definition['workflow_type'];
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];

    $state = ['applicable' => true];
    foreach ($definition['cases'] as $case => $shape) {
        $id = $workflowId.'-lcb-'.$case;
        $queue = $id.'-queue';
        $worker = $id.'-worker';
        $registered = $raw('POST', 'worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
            'sdk_version' => 'raw-http', 'supported_workflow_types' => [$type],
            'supported_activity_types' => [$definition['activity_type']],
            'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
                ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http'])]);
        $started = $raw('POST', 'workflows', ['workflow_id' => $id, 'workflow_type' => $type, 'task_queue' => $queue,
            'input' => $empty]);
        if ($registered['status'] !== 201 || $started['status'] !== 201) {
            throw new RuntimeException('Large command batches setup: '.json_encode([$registered, $started]));
        }
        $task = $raw('POST', 'worker/workflow-tasks/poll', ['worker_id' => $worker, 'task_queue' => $queue,
            'timeout_seconds' => 5])['response']['task'] ?? null;
        if (! is_array($task)) {
            throw new RuntimeException('Large command batches: no workflow task for '.$case);
        }
        $commands = [];
        for ($index = 0; $index < $shape['commands']; $index++) {
            $commands[] = $shape['kind'] === 'timers'
                ? ['type' => 'start_timer', 'delay_seconds' => 3600]
                : ['type' => 'schedule_activity', 'activity_type' => $definition['activity_type'], 'arguments' => $empty];
        }
        // An all group of activities declares itself on every member, with
        // a one-group path. A group larger than the limit is declared by the
        // batch's members only.
        if (isset($shape['group_size'])) {
            foreach ($commands as $index => $command) {
                $entry = ['parallel_group_id' => 'parallel-activities:1:'.$shape['group_size'],
                    'parallel_group_kind' => 'activity', 'parallel_group_base_sequence' => 1,
                    'parallel_group_size' => $shape['group_size'], 'parallel_group_index' => $index];
                $commands[$index] += [...$entry, 'parallel_group_path' => [$entry]];
            }
        }
        // A case's `raw_commands` is the commands array as JSON text, spliced
        // into the body unchanged.
        $fence = ['lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt']];
        $completed = $raw('POST', 'worker/workflow-tasks/'.$task['task_id'].'/complete', isset($shape['raw_commands'])
            ? substr(json_encode($fence, JSON_THROW_ON_ERROR), 0, -1).',"commands":'.$shape['raw_commands'].'}'
            : [...$fence, 'commands' => $commands]);
        $observed = ['completed' => ['status' => $completed['status'], 'outcome' => $completed['response']['outcome'] ?? null,
            'reason' => $completed['response']['reason'] ?? null, 'run_status' => $completed['response']['run_status'] ?? null,
            'message' => $completed['response']['message'] ?? null,
            'errors' => $completed['response']['errors'] ?? null,
            'created_tasks' => count($completed['response']['created_task_ids'] ?? [])]];
        // The run as described after the batch: its waits and liveness.
        $described = $raw('GET', 'workflows/'.rawurlencode($id))['response'];
        $observed['described'] = array_intersect_key($described, array_flip($definition['described_fields']));
        $counts = [];
        $groups = [];
        $token = null;
        do {
            $page = $raw('GET', 'workflows/'.rawurlencode($id).'/runs/'.$started['response']['run_id'].'/history?page_size=1000'
                .($token === null ? '' : '&next_page_token='.rawurlencode($token)))['response'];
            foreach ($page['events'] ?? [] as $event) {
                $counts[$event['event_type']] = ($counts[$event['event_type']] ?? 0) + 1;
                if (is_string($event['payload']['parallel_group_id'] ?? null)) {
                    $groups[$event['payload']['parallel_group_id']] = ($groups[$event['payload']['parallel_group_id']] ?? 0) + 1;
                }
            }
            $token = $page['next_page_token'] ?? null;
        } while (is_string($token) && $token !== '');
        ksort($counts);
        $observed['events'] = $counts;
        $observed['parallel_groups'] = $groups;
        $state[$case] = $observed;
    }

    return $state;
}
