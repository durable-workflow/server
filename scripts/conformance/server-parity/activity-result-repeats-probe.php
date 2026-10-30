<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as ActivityResultRepeatsHttp;
use GuzzleHttp\HandlerStack;

// Repeats of the result that finished an activity attempt, over raw worker
// HTTP on protocol 1.20 in a fresh namespace (published Server
// ActivityTaskController, Workflow ActivityTaskBridge). Each case schedules
// one activity, finishes its attempt by completing or failing it, then sends
// the same result again, a different result, the other route, and the same
// result once more after the workflow consumed it. It records each answer
// without IDs or protocol fields, and the run's event types.
function finishHttpActivityResultRepeats(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['activity_result_repeats'];
    $namespace = strtolower($workflowId.'-arr');
    $admin->createNamespace($namespace, 'activity result repeats λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new ActivityResultRepeatsHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $headers = array_filter([
        'Authorization' => $token === null ? null : 'Bearer '.$token,
        'Accept' => 'application/json', 'X-Namespace' => $namespace,
        'X-Durable-Workflow-Control-Plane-Version' => '2', 'X-Durable-Workflow-Protocol-Version' => '1.20',
    ]);
    $raw = static function (string $method, string $path, ?array $body = null) use ($http, $headers): array {
        $response = $http->request($method, $path, ['headers' => $headers,
            ...($body === null ? [] : ['json' => $body === [] ? (object) [] : $body])]);

        return ['status' => $response->getStatusCode(),
            'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    };
    // IDs and protocol fields vary by run; the rest of each answer is compared.
    $answer = static function (array $answer, array $ids): array {
        $body = is_array($answer['response']) ? $answer['response'] : [];
        unset($body['server_capabilities'], $body['protocol_version']);
        foreach ($body as $key => $value) {
            if (is_string($value) && isset($ids[strtolower($value)])) {
                $body[$key] = $ids[strtolower($value)];
            }
        }
        ksort($body);

        return ['status' => $answer['status'], 'body' => $body];
    };
    $manifest = array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
        ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http']);
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $other = ['codec' => 'avro', 'blob' => $definition['other_blob']];
    $state = ['applicable' => true];
    foreach ($definition['cases'] as $case => $route) {
        $id = $workflowId.'-arr-'.$case;
        $queue = $id.'-queue';
        $worker = $id.'-worker';
        $registered = $raw('POST', 'worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
            'sdk_version' => 'raw-http', 'supported_workflow_types' => [$definition['workflow_type']],
            'supported_activity_types' => [$definition['activity_type']], 'capability_manifest' => $manifest]);
        $started = $raw('POST', 'workflows', ['workflow_id' => $id, 'workflow_type' => $definition['workflow_type'],
            'task_queue' => $queue, 'input' => $empty]);
        if ($registered['status'] !== 201 || $started['status'] !== 201) {
            throw new RuntimeException('Activity result repeats setup: '.json_encode([$registered, $started]));
        }
        $poll = static function (string $kind) use ($raw, $worker, $queue, $case): array {
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $task = $raw('POST', "worker/{$kind}-tasks/poll", ['worker_id' => $worker, 'task_queue' => $queue,
                    'timeout_seconds' => 1])['response']['task'] ?? null;
                if (is_array($task)) {
                    return $task;
                }
            }
            throw new RuntimeException("Activity result repeats {$case}: no {$kind} task");
        };
        $workflowTask = $poll('workflow');
        $scheduled = $raw('POST', 'worker/workflow-tasks/'.$workflowTask['task_id'].'/complete', [
            'lease_owner' => $workflowTask['lease_owner'], 'workflow_task_attempt' => $workflowTask['workflow_task_attempt'],
            'commands' => [['type' => 'schedule_activity', 'activity_type' => $definition['activity_type'], 'arguments' => $empty]]]);
        if ($scheduled['status'] !== 200) {
            throw new RuntimeException("Activity result repeats {$case} schedule: ".json_encode($scheduled));
        }
        $task = $poll('activity');
        $ids = [strtolower($task['task_id']) => '{task}', strtolower($task['activity_attempt_id']) => '{attempt}',
            strtolower($task['lease_owner']) => '{owner}'];
        $lease = ['activity_attempt_id' => $task['activity_attempt_id'], 'lease_owner' => $task['lease_owner']];
        $bodies = [
            'complete' => [...$lease, 'result' => $empty],
            'complete_other' => [...$lease, 'result' => $other],
            'fail' => [...$lease, 'failure' => ['message' => 'attempt failed', 'type' => 'Boom', 'non_retryable' => true]],
            'fail_other' => [...$lease, 'failure' => ['message' => 'another failure', 'type' => 'Other', 'non_retryable' => true]],
        ];
        $send = static fn (string $kind, array $body): array => $answer(
            $raw('POST', 'worker/activity-tasks/'.$task['task_id'].'/'.$kind, $body), $ids);
        $otherRoute = $route === 'complete' ? 'fail' : 'complete';
        $observed = [];
        $observed['first'] = $send($route, $bodies[$route]);
        // The workflow task the result created is a label too.
        $next = $observed['first']['body']['next_task_id'] ?? null;
        if (is_string($next)) {
            $ids[strtolower($next)] = '{next_task}';
            $observed['first']['body']['next_task_id'] = '{next_task}';
        }
        $observed['repeat_identical'] = $send($route, $bodies[$route]);
        $observed['repeat_different'] = $send($route, $bodies[$route.'_other']);
        $observed['repeat_other_route'] = $send($otherRoute, $bodies[$otherRoute]);
        $resumed = $poll('workflow');
        $closed = $raw('POST', 'worker/workflow-tasks/'.$resumed['task_id'].'/complete', [
            'lease_owner' => $resumed['lease_owner'], 'workflow_task_attempt' => $resumed['workflow_task_attempt'],
            'commands' => [['type' => 'complete_workflow', 'result' => $empty]]]);
        $observed['workflow_closed'] = $closed['status'];
        $observed['repeat_after_close'] = $send($route, $bodies[$route]);
        $events = $raw('GET', 'workflows/'.rawurlencode($id).'/runs/'.$started['response']['run_id'].'/history')['response']['events'] ?? [];
        $observed['events'] = array_map(static fn (array $event): string => $event['event_type'], $events);
        $state[$case] = $observed;
    }

    return $state;
}
