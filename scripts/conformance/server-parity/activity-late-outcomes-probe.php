<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as ActivityLateOutcomesHttp;
use GuzzleHttp\HandlerStack;

// An activity outcome that arrives after the attempt's deadline, over raw
// worker HTTP on protocol 1.20 in a fresh namespace. Each case schedules one
// activity with the case's timeout, claims it, waits past the deadline and
// sends the case's outcome. Whether the timeout sweep has already closed the
// attempt is a race on both servers, so the probe records the refusal by
// class: the deadline's own timeout reason before the sweep, stale_attempt
// after it. It then waits for the sweep and records the activity's events
// and the timeout kind. The parent is completed afterwards.
function finishHttpActivityLateOutcomes(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['activity_late_outcomes'];
    $namespace = strtolower($workflowId.'-alo');
    $admin->createNamespace($namespace, 'activity late outcomes λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new ActivityLateOutcomesHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
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
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $state = ['applicable' => true];
    foreach ($definition['cases'] as $case => $plan) {
        $id = $workflowId.'-alo-'.$case;
        $queue = $id.'-queue';
        $worker = $id.'-worker';
        $registered = $raw('POST', 'worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
            'sdk_version' => 'raw-http', 'supported_workflow_types' => [$definition['workflow_type']],
            'supported_activity_types' => [$definition['activity_type']],
            'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
                ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http'])]);
        $started = $raw('POST', 'workflows', ['workflow_id' => $id, 'workflow_type' => $definition['workflow_type'],
            'task_queue' => $queue, 'input' => $empty]);
        if ($registered['status'] !== 201 || $started['status'] !== 201) {
            throw new RuntimeException('Activity late outcomes setup: '.json_encode([$registered, $started]));
        }
        $runId = $started['response']['run_id'];
        $poll = static function (string $kind) use ($raw, $worker, $queue, $case): array {
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $task = $raw('POST', "worker/{$kind}-tasks/poll", ['worker_id' => $worker, 'task_queue' => $queue,
                    'timeout_seconds' => 1])['response']['task'] ?? null;
                if (is_array($task)) {
                    return $task;
                }
            }
            throw new RuntimeException("Activity late outcomes {$case}: no {$kind} task");
        };
        $workflowTask = $poll('workflow');
        $scheduled = $raw('POST', 'worker/workflow-tasks/'.$workflowTask['task_id'].'/complete', [
            'lease_owner' => $workflowTask['lease_owner'], 'workflow_task_attempt' => $workflowTask['workflow_task_attempt'],
            'commands' => [['type' => 'schedule_activity', 'activity_type' => $definition['activity_type'],
                'arguments' => $empty, ...$plan['timeouts']]]]);
        if ($scheduled['status'] !== 200) {
            throw new RuntimeException("Activity late outcomes {$case} schedule: ".json_encode($scheduled));
        }
        $activity = $poll('activity');
        usleep((int) ($definition['late_by_seconds'] * 1_000_000));
        $body = ['lease_owner' => $activity['lease_owner'] ?? $worker, 'activity_attempt_id' => $activity['activity_attempt_id']];
        if ($plan['route'] === 'complete') {
            $body['result'] = $empty;
        } else {
            $body['failure'] = ['type' => 'RuntimeException', 'message' => 'late outcome', 'code' => 0];
        }
        $answer = $raw('POST', 'worker/activity-tasks/'.$activity['task_id'].'/'.$plan['route'], $body);
        $reason = $answer['response']['reason'] ?? null;
        $observed = ['answer' => ['status' => $answer['status'], 'recorded' => $answer['response']['recorded'] ?? null,
            'refusal' => match ($reason) {
                'stale_attempt' => 'after_timeout',
                $plan['timeout_reason'] => 'deadline_reason',
                default => $reason,
            }]];
        // The sweep closes the attempt; wait for it, bounded.
        $events = [];
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $events = $raw('GET', 'workflows/'.rawurlencode($id).'/runs/'.$runId.'/history')['response']['events'] ?? [];
            if (array_filter($events, static fn (array $event): bool => $event['event_type'] === 'ActivityTimedOut') !== []) {
                break;
            }
            usleep(500_000);
        }
        $activityEvents = array_values(array_filter($events, static fn (array $event): bool => str_starts_with($event['event_type'], 'Activity')));
        $observed['activity_events'] = array_map(static fn (array $event): string => $event['event_type'], $activityEvents);
        $timedOut = array_values(array_filter($activityEvents, static fn (array $event): bool => $event['event_type'] === 'ActivityTimedOut'))[0]['payload'] ?? [];
        $observed['timeout_kind'] = $timedOut['timeout_kind'] ?? null;
        // The parent resumes with the timeout; end it so nothing stays.
        $resumed = $poll('workflow');
        $closed = $raw('POST', 'worker/workflow-tasks/'.$resumed['task_id'].'/complete', ['lease_owner' => $resumed['lease_owner'],
            'workflow_task_attempt' => $resumed['workflow_task_attempt'], 'commands' => [['type' => 'complete_workflow', 'result' => $empty]]]);
        $observed['parent_closed'] = $closed['status'];
        $state[$case] = $observed;
    }

    return $state;
}
