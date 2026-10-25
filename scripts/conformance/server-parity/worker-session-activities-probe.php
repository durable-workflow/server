<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as WorkerSessionActivityHttp;
use GuzzleHttp\HandlerStack;

// Session-bound activities over raw HTTP in a fresh namespace: the
// completion guards on schedule_activity with worker_session, lazy session
// creation on the first admitted claim, the poll pre-filter (protocol,
// queue, requirements) and the held session, the session renewed by the
// activity heartbeat and renew-lease, the compact form on status, the
// session's activity limit, an unclaimable queue, and the capability map.
// Session times are recorded as spans or presence flags.
function finishHttpWorkerSessionActivities(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['worker_session_activities'];
    $namespace = strtolower($workflowId.'-wsa');
    $admin->createNamespace($namespace, 'worker session activities λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new WorkerSessionActivityHttp(['base_uri' => rtrim($url, '/'), 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $capabilityMap = null;
    $raw = static function (string $method, string $path, ?array $body = null, ?string $protocol = '1.20', bool $control = false)
        use ($http, $token, $namespace, &$capabilityMap): array {
        $headers = array_filter(['Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $namespace,
            'X-Durable-Workflow-Protocol-Version' => $control ? null : $protocol,
            'X-Durable-Workflow-Control-Plane-Version' => $control ? '2' : null]);
        $response = $http->request($method, $path, ['headers' => $headers] + ($body === null ? [] : ['json' => $body]));
        $decoded = json_decode((string) $response->getBody(), true);
        if (is_array($decoded)) {
            $capabilityMap ??= $decoded['server_capabilities'] ?? null;
            unset($decoded['server_capabilities'], $decoded['control_plane'], $decoded['protocol_version']);
        }

        return ['status' => $response->getStatusCode(), 'response' => $decoded];
    };
    $at = static fn (?string $value): ?int => $value === null ? null : (new DateTimeImmutable($value))->getTimestamp();
    $present = static fn (mixed $value): string => $value === null ? 'null' : 'present';
    // The full snapshot with its times as spans and presence flags.
    $snapshot = static function (?array $session) use ($at, $present): ?array {
        if ($session === null) {
            return null;
        }
        $times = ['lease_expires_at', 'ttl_expires_at', 'closed_at', 'last_heartbeat_at', 'created_at', 'updated_at'];
        $plain = array_diff_key($session, array_flip($times));
        if (is_array($plain['holder'] ?? null)) {
            $plain['holder']['last_heartbeat_at'] = $present($plain['holder']['last_heartbeat_at']);
        }
        $heartbeat = $at($session['last_heartbeat_at'] ?? null);

        return $plain + ['lease_span' => isset($session['lease_expires_at']) && $heartbeat !== null ? $at($session['lease_expires_at']) - $heartbeat : null,
            'times' => array_map(static fn (string $key): string => $present($session[$key] ?? null), array_combine($times, $times))];
    };
    // The compact form a task or status carries: its times as presence flags.
    $compact = static function (?array $session) use ($present): ?array {
        if ($session === null) {
            return null;
        }
        foreach (['lease_expires_at', 'ttl_expires_at'] as $key) {
            if (array_key_exists($key, $session)) {
                $session[$key] = $present($session[$key]);
            }
        }

        return $session;
    };
    $ok = static function (array $recorded, int $status, string $operation): array {
        if ($recorded['status'] !== $status) {
            throw new RuntimeException('Worker session activities '.$operation.': '.json_encode($recorded));
        }

        return $recorded['response'];
    };
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $queue = $workflowId.'-wsa-queue';
    $other = $workflowId.'-wsa-other-queue';
    $manifest = static fn (bool $sessions): array => ['local_activities' => ['supported' => false, 'minimum_protocol_version' => '1.18'],
        'sticky_execution' => ['supported' => false, 'minimum_protocol_version' => '1.18'],
        'worker_sessions' => ['supported' => $sessions, 'minimum_protocol_version' => '1.18']];
    $register = static function (string $worker, string $onQueue, array $capabilities) use ($raw, $ok, $definition, $manifest): void {
        $ok($raw('POST', '/api/worker/register', ['worker_id' => $worker, 'task_queue' => $onQueue, 'runtime' => 'php',
            'sdk_version' => 'raw-http', 'supported_workflow_types' => [$definition['workflow_type']],
            'supported_activity_types' => [$definition['activity_type']], 'capabilities' => $capabilities,
            'capability_manifest' => $manifest(in_array('worker_sessions', $capabilities, true))]), 201, 'registration '.$worker);
    };
    [$author, $plain, $gpu, $gpu2, $far] = array_map(static fn (string $name): string => $workflowId.'-wsa-'.$name, ['author', 'plain', 'gpu', 'gpu2', 'far']);
    $register($author, $queue, ['worker_sessions']);
    $register($plain, $queue, []);
    $register($gpu, $queue, ['worker_sessions', 'gpu']);
    $register($gpu2, $queue, ['worker_sessions', 'gpu']);
    $register($far, $other, ['worker_sessions', 'gpu']);
    $start = static fn (string $suffix): array => $ok($raw('POST', '/api/workflows', ['workflow_id' => $workflowId.'-wsa-'.$suffix,
        'workflow_type' => $definition['workflow_type'], 'task_queue' => $queue, 'input' => $empty], null, true), 201, 'start '.$suffix);
    $workflowTask = static fn (string $worker): array => $raw('POST', '/api/worker/workflow-tasks/poll',
        ['worker_id' => $worker, 'task_queue' => $queue, 'timeout_seconds' => 0])['response']['task'] ?? [];
    $complete = static fn (array $task, array $commands, string $protocol = '1.20'): array => $raw('POST',
        '/api/worker/workflow-tasks/'.($task['task_id'] ?? 'missing').'/complete',
        ['lease_owner' => $task['lease_owner'] ?? null, 'workflow_task_attempt' => $task['workflow_task_attempt'] ?? null, 'commands' => $commands], $protocol);
    $activity = static fn (array $session, array $extra = []): array => ['type' => 'schedule_activity', 'activity_type' => $definition['activity_type'],
        'arguments' => $empty, 'retry_policy' => ['max_attempts' => 1, 'backoff_seconds' => []], 'start_to_close_timeout' => 300,
        'worker_session' => $session] + $extra;
    $refusal = static fn (array $recorded): array => ['status' => $recorded['status'],
        'reason' => $recorded['response']['reason'] ?? null, 'message' => $recorded['response']['message'] ?? $recorded['response']['error'] ?? null];
    $poll = static fn (string $worker, string $onQueue, string $protocol = '1.20'): array => $raw('POST', '/api/worker/activity-tasks/poll',
        ['worker_id' => $worker, 'task_queue' => $onQueue, 'timeout_seconds' => 0], $protocol);
    $leased = static fn (array $recorded): array => ['status' => $recorded['status'], 'poll_status' => $recorded['response']['poll_status'] ?? null,
        'worker_session' => $compact($recorded['response']['task']['worker_session'] ?? null),
        'has_session_key' => is_array($recorded['response']['task'] ?? null) && array_key_exists('worker_session', $recorded['response']['task'])];
    $own = static fn (array $task): array => ['activity_attempt_id' => $task['activity_attempt_id'] ?? null, 'lease_owner' => $task['lease_owner'] ?? null];
    $show = static fn (string $session): array => $raw('GET', '/api/worker-sessions/'.rawurlencode($session), null, null, true);
    $state = ['applicable' => true, 'namespace' => $namespace];

    // 17. Completion guards on one workflow task, then an accepted command.
    $start('guards');
    $task = $workflowTask($author);
    $lazy = $workflowId.'-wsa-lazy';
    $session = ['session_id' => $lazy, 'requirements' => ['gpu'], 'lease_seconds' => 90];
    $state['complete_protocol_1_7'] = $refusal($complete($task, [$activity($session)], '1.7'));
    $state['complete_protocol_1_17'] = $refusal($complete($task, [$activity($session)], '1.17'));
    $state['complete_on_timer'] = $refusal($complete($task, [['type' => 'start_timer', 'delay_seconds' => 60, 'worker_session' => $session]]));
    $state['complete_without_session_id'] = $refusal($complete($task, [$activity(['queue' => $queue])]));
    $state['complete_accepted'] = $complete($task, [$activity($session)])['status'];
    $start('incapable');
    $incapable = $workflowTask($plain);
    $state['complete_incapable'] = $refusal($complete($incapable, [$activity($session)]));

    // 18. The activity path: lazily created on the first admitted claim.
    $state['show_before_claim'] = $refusal($show($lazy));
    $state['poll_without_requirement'] = $leased($poll($plain, $queue));
    $state['poll_protocol_1_7'] = $leased($poll($gpu, $queue, '1.7'));
    $claimed = $poll($gpu, $queue);
    $state['poll_capable'] = $leased($claimed);
    $claim = $claimed['response']['task'] ?? [];
    $shown = $show($lazy);
    $state['show_after_claim'] = ['status' => $shown['status'], 'session' => $snapshot($shown['response']['session'] ?? null)];
    $state['poll_other_capable'] = $leased($poll($gpu2, $queue));
    $beat = $raw('POST', '/api/worker/activity-tasks/'.($claim['task_id'] ?? 'missing').'/heartbeat', $own($claim) + ['message' => 'half']);
    $state['activity_heartbeat'] = ['status' => $beat['status'], 'heartbeat_recorded' => $beat['response']['heartbeat_recorded'] ?? null,
        'worker_session' => $snapshot($beat['response']['worker_session'] ?? null)];
    $status = $raw('POST', '/api/worker/activity-tasks/'.($claim['task_id'] ?? 'missing').'/status', $own($claim));
    $state['activity_status'] = ['status' => $status['status'], 'can_continue' => $status['response']['can_continue'] ?? null,
        'reason' => $status['response']['reason'] ?? null, 'worker_session' => $compact($status['response']['worker_session'] ?? null)];
    $renewed = $raw('POST', '/api/worker/activity-tasks/'.($claim['task_id'] ?? 'missing').'/renew-lease', $own($claim));
    $state['activity_renew'] = ['status' => $renewed['status'], 'lease_renewed' => $renewed['response']['lease_renewed'] ?? null,
        'worker_session' => $snapshot($renewed['response']['worker_session'] ?? null)];
    $state['session_closed'] = $raw('DELETE', '/api/worker/sessions/'.rawurlencode($lazy), ['worker_id' => $gpu])['status'];
    $beat = $raw('POST', '/api/worker/activity-tasks/'.($claim['task_id'] ?? 'missing').'/heartbeat', $own($claim));
    $state['heartbeat_after_close'] = ['status' => $beat['status'], 'reason' => $beat['response']['reason'] ?? null,
        'can_continue' => $beat['response']['can_continue'] ?? null, 'heartbeat_recorded' => $beat['response']['heartbeat_recorded'] ?? null,
        'worker_session_renewal' => $beat['response']['worker_session_renewal'] ?? null,
        'worker_session_status' => $beat['response']['worker_session']['status'] ?? null];
    $status = $raw('POST', '/api/worker/activity-tasks/'.($claim['task_id'] ?? 'missing').'/status', $own($claim));
    $state['status_after_close'] = ['status' => $status['status'], 'can_continue' => $status['response']['can_continue'] ?? null,
        'reason' => $status['response']['reason'] ?? null];
    $state['complete_after_close'] = $raw('POST', '/api/worker/activity-tasks/'.($claim['task_id'] ?? 'missing').'/complete',
        $own($claim) + ['result' => $empty])['status'];

    // 19. The session's activity limit: two activities on one session.
    foreach ([1 => 'one', 2 => 'two'] as $limit => $name) {
        $start('limit-'.$name);
        $task = $workflowTask($author);
        $shared = ['session_id' => $workflowId.'-wsa-shared-'.$name, 'requirements' => ['gpu'], 'max_concurrent_activities' => $limit];
        $complete($task, [$activity($shared), $activity($shared)]);
        $first = $poll($gpu2, $queue);
        $second = $poll($gpu2, $queue);
        $state['limit_'.$name] = [$leased($first)['poll_status'], $leased($second)['poll_status']];
        foreach ([$first, $second] as $polled) {
            $leasedTask = $polled['response']['task'] ?? null;
            if (is_array($leasedTask)) {
                $raw('POST', '/api/worker/activity-tasks/'.$leasedTask['task_id'].'/complete', $own($leasedTask) + ['result' => $empty]);
            }
        }
    }

    // 20. A command queue other than the session's: nobody can claim it.
    $start('queue');
    $task = $workflowTask($author);
    $complete($task, [$activity(['session_id' => $workflowId.'-wsa-elsewhere', 'queue' => $queue], ['queue' => $other])]);
    $state['queue_mismatch_polls'] = [$leased($poll($gpu, $queue))['poll_status'], $leased($poll($far, $other))['poll_status']];

    // 21. Operator metrics count the namespace's sessions.
    $metrics = $raw('GET', '/api/system/operator-metrics', null, null, true);
    $state['operator_metrics'] = ['status' => $metrics['status'],
        'worker_sessions' => $metrics['response']['operator_metrics']['worker_sessions'] ?? null];

    // The capability map, once.
    $state['capabilities'] = ['worker_sessions' => $capabilityMap['worker_sessions'] ?? null,
        'worker_session_verbs' => $capabilityMap['worker_session_verbs'] ?? null];

    return $state;
}
