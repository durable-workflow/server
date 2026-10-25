<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as WorkerPollFenceHttp;
use GuzzleHttp\HandlerStack;

// Worker poll fences (`WorkerPollFence`) over raw worker HTTP in a fresh
// namespace. A held workflow or activity poll belongs to the registration it
// started under. For each kind, one worker records: a held poll that work
// reaches while the registration is unchanged; a held poll across a worker
// heartbeat; a held poll across a same-ID re-registration with the same
// queue and types; and the poll the worker sends next. Held polls run in a
// child PHP process while this one changes the registration and makes work
// ready. Responses are recorded raw, without the protocol's capability map.
function finishHttpWorkerPollFence(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['worker_poll_fence'];
    $namespace = strtolower($workflowId.'-wpf');
    $admin->createNamespace($namespace, 'worker poll fence λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    // The SQLite reference shares its database with a one-second maintenance
    // loop; retry its retryable lock-pressure refusals, as the other probes do.
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new WorkerPollFenceHttp(['base_uri' => rtrim($url, '/'), 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $headers = array_filter([
        'Authorization' => $token === null ? null : 'Bearer '.$token,
        'Accept' => 'application/json', 'X-Namespace' => $namespace,
        'X-Durable-Workflow-Control-Plane-Version' => '2', 'X-Durable-Workflow-Protocol-Version' => '1.20',
    ]);
    $raw = static function (string $method, string $path, ?array $body = []) use ($http, $headers): array {
        $options = ['headers' => $headers];
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
            throw new RuntimeException('Worker poll fence '.$operation.': '.json_encode($recorded));
        }

        return $recorded['response'];
    };
    // A poll held in a child process. It retries only the documented
    // lock-pressure poll refusal, which leased nothing.
    $hold = static function (string $path, array $body) use ($url, $headers): array {
        $child = <<<'PHP'
[, $url, $path, $headers, $body, $retryRule] = $argv;
require $retryRule;
$lines = '';
foreach (json_decode($headers, true) as $name => $value) {
    $lines .= "{$name}: {$value}\r\n";
}
$context = stream_context_create(['http' => ['method' => 'POST', 'header' => $lines."Content-Type: application/json\r\n",
    'content' => $body, 'ignore_errors' => true, 'timeout' => 60]]);
$retries = [];
$waited = 0.0;
while (true) {
    $response = file_get_contents(rtrim($url, '/').$path, false, $context);
    preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $status);
    $status = (int) ($status[1] ?? 0);
    $decoded = json_decode((string) $response, true);
    $delay = lockPressureOperationRetryDelay('POST', $path, $status, $decoded, count($retries), $waited);
    if ($delay === null) {
        break;
    }
    $retries[] = $delay;
    usleep((int) round($delay * 1_000_000));
    $waited += $delay;
}
if ($retries !== []) {
    fwrite(STDERR, json_encode(['lock_pressure_poll_retries' => ['path' => $path, 'delays' => $retries]]).PHP_EOL);
}
echo json_encode(['status' => $status, 'response' => $decoded]);
PHP;
        $process = proc_open([PHP_BINARY, '-r', $child, '--', $url, $path, json_encode($headers, JSON_THROW_ON_ERROR),
            json_encode($body, JSON_THROW_ON_ERROR), __DIR__.'/lock-pressure-retry.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('The held poll could not start.');
        }
        // Let the request reach the server and begin waiting.
        usleep(700000);

        return [$process, $pipes];
    };
    $collect = static function (array $pending): array {
        [$process, $pipes] = $pending;
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        proc_close($process);
        $result = json_decode((string) $output, true);
        if (! is_array($result)) {
            throw new RuntimeException('The held poll failed: '.trim((string) $errors));
        }
        if (trim((string) $errors) !== '') {
            fwrite(STDERR, (string) $errors);
        }
        unset($result['response']['server_capabilities'], $result['response']['control_plane']);

        return $result;
    };
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    // Each case has its own worker and queue, so it sees only its own work.
    // PHP keeps registration times in whole seconds, so a registration
    // change is separated from the previous one by more than a second.
    $register = static function (string $case, ?array $process = null) use ($ok, $raw, $definition, $workflowId): array {
        $worker = ['worker_id' => $workflowId.'-wpf-'.$case, 'task_queue' => $workflowId.'-wpf-'.$case.'-queue'];
        // Query cases answer one query without parameters.
        $queries = str_starts_with($case, 'query-');
        $ok($raw('POST', '/api/worker/register', $worker + ['runtime' => 'php', 'sdk_version' => 'raw-http',
            'supported_workflow_types' => [$definition['workflow_type']], 'supported_activity_types' => [$definition['activity_type']],
            'capabilities' => $queries ? ['query_tasks'] : [],
            'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
                ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http'])]
            + ($queries ? ['workflow_command_contracts' => [$definition['workflow_type'] => ['signals' => [], 'updates' => [],
                'queries' => [$definition['query']], 'query_contracts' => [['name' => $definition['query'], 'parameters' => []]]]]] : [])
            + ($process === null ? [] : ['process_metrics' => $process])), 201, 'registration '.$case);
        usleep(1100000);

        return $worker;
    };
    $heartbeat = static fn (array $worker, ?array $process = null): int => $raw('POST', '/api/worker/heartbeat',
        ['worker_id' => $worker['worker_id']] + ($process === null ? [] : ['process_metrics' => $process]))['status'];
    $start = static fn (array $worker): array => $ok($raw('POST', '/api/workflows', ['workflow_id' => $worker['worker_id'].'-run',
        'workflow_type' => $definition['workflow_type'], 'task_queue' => $worker['task_queue'], 'input' => $empty]), 201, 'start');
    $workflowTask = static fn (array $worker): array => $raw('POST', '/api/worker/workflow-tasks/poll', $worker + ['timeout_seconds' => 0]);
    $activityTask = static fn (array $worker): array => $raw('POST', '/api/worker/activity-tasks/poll', $worker + ['timeout_seconds' => 0]);
    $heldWorkflow = static fn (array $worker): array => $hold('/api/worker/workflow-tasks/poll', $worker + ['timeout_seconds' => $definition['hold_seconds']]);
    $heldActivity = static fn (array $worker): array => $hold('/api/worker/activity-tasks/poll', $worker + ['timeout_seconds' => $definition['hold_seconds']]);
    // The case's run, its first workflow task polled now and completed by
    // scheduling one activity.
    $schedule = static function (array $worker) use ($start, $workflowTask, $raw, $ok, $definition, $empty): int {
        $start($worker);
        $task = $ok($workflowTask($worker), 200, 'workflow task')['task'] ?? [];

        return $raw('POST', '/api/worker/workflow-tasks/'.($task['task_id'] ?? 'missing').'/complete', [
            'lease_owner' => $task['lease_owner'] ?? null, 'workflow_task_attempt' => $task['workflow_task_attempt'] ?? null,
            'commands' => [['type' => 'schedule_activity', 'activity_type' => $definition['activity_type'], 'arguments' => $empty,
                'retry_policy' => ['max_attempts' => 1, 'backoff_seconds' => []], 'start_to_close_timeout' => 300]]])['status'];
    };
    // A poll's outcome: its status, its task's run and kind, and the attempt.
    $outcome = static fn (array $recorded, array $worker): array => ['status' => $recorded['status'],
        'poll_status' => $recorded['response']['poll_status'] ?? null,
        'own_run' => isset($recorded['response']['task']) ? ($recorded['response']['task']['workflow_id'] ?? null) === $worker['worker_id'].'-run' : null,
        'attempt' => $recorded['response']['task']['workflow_task_attempt'] ?? $recorded['response']['task']['attempt_number']
            ?? $recorded['response']['task']['query_task_attempt'] ?? null,
        'reason' => $recorded['response']['reason'] ?? null];

    $state = ['applicable' => true, 'namespace' => $namespace];

    // Workflow polls: unchanged, across a heartbeat, across a re-registration.
    $worker = $register('workflow-control');
    $held = $heldWorkflow($worker);
    $start($worker);
    $state['workflow_control'] = $outcome($collect($held), $worker);
    $worker = $register('workflow-heartbeat');
    $held = $heldWorkflow($worker);
    $state['workflow_heartbeat'] = $heartbeat($worker);
    $start($worker);
    $state['workflow_after_heartbeat'] = $outcome($collect($held), $worker);
    $state['workflow_next_after_heartbeat'] = $outcome($workflowTask($worker), $worker);
    $worker = $register('workflow-replaced');
    $held = $heldWorkflow($worker);
    $register('workflow-replaced');
    $start($worker);
    $state['workflow_after_replacement'] = $outcome($collect($held), $worker);
    $state['workflow_next_after_replacement'] = $outcome($workflowTask($worker), $worker);

    // A held workflow poll whose registration is removed while it waits.
    $worker = $register('workflow-removed');
    $held = $heldWorkflow($worker);
    $state['workflow_removed'] = $raw('DELETE', '/api/worker/registrations/'.$worker['worker_id'], null)['status'];
    $start($worker);
    $removed = $collect($held);
    $state['workflow_after_removal'] = $outcome($removed, $worker);

    // Activity polls, each reached by an activity scheduled while it waits.
    $worker = $register('activity-control');
    $held = $heldActivity($worker);
    $state['activity_control_scheduled'] = $schedule($worker);
    $state['activity_control'] = $outcome($collect($held), $worker);
    $worker = $register('activity-heartbeat');
    $held = $heldActivity($worker);
    $state['activity_heartbeat'] = $heartbeat($worker);
    $state['activity_heartbeat_scheduled'] = $schedule($worker);
    $state['activity_after_heartbeat'] = $outcome($collect($held), $worker);
    $state['activity_next_after_heartbeat'] = $outcome($activityTask($worker), $worker);
    $worker = $register('activity-replaced');
    $held = $heldActivity($worker);
    $register('activity-replaced');
    $state['activity_replaced_scheduled'] = $schedule($worker);
    $state['activity_after_replacement'] = $outcome($collect($held), $worker);
    $state['activity_next_after_replacement'] = $outcome($activityTask($worker), $worker);

    // A worker that reports its process: a heartbeat from the same process
    // keeps the held poll; a re-registration from a new process ends it.
    $process = static fn (string $started): array => ['host' => 'parity-host', 'process_id' => 4242, 'process_started_at' => $started];
    $worker = $register('process-heartbeat', $process('2026-10-24T00:00:00Z'));
    $held = $heldWorkflow($worker);
    $state['process_heartbeat'] = $heartbeat($worker, $process('2026-10-24T00:00:00Z'));
    $start($worker);
    $state['process_after_heartbeat'] = $outcome($collect($held), $worker);
    $worker = $register('process-replaced', $process('2026-10-24T00:00:00Z'));
    $held = $heldWorkflow($worker);
    $register('process-replaced', $process('2026-10-24T00:00:01Z'));
    $start($worker);
    $state['process_after_replacement'] = $outcome($collect($held), $worker);
    $state['process_next_after_replacement'] = $outcome($workflowTask($worker), $worker);

    // Query polls, each reached by a query sent while it waits to the
    // case's run, idle on a signal wait. The query is sent from a child
    // process and answered by the poll that leases it.
    $idle = static function (array $worker) use ($start, $workflowTask, $raw, $ok): string {
        $runId = $start($worker)['run_id'];
        $task = $ok($workflowTask($worker), 200, 'workflow task')['task'] ?? [];
        $raw('POST', '/api/worker/workflow-tasks/'.($task['task_id'] ?? 'missing').'/complete', [
            'lease_owner' => $task['lease_owner'] ?? null, 'workflow_task_attempt' => $task['workflow_task_attempt'] ?? null,
            'commands' => [['type' => 'open_signal_wait', 'signal_name' => 'finish']]]);

        return $runId;
    };
    $query = static fn (array $worker, string $runId): array => $hold('/api/workflows/'.$worker['worker_id'].'-run/runs/'.$runId
        .'/query/'.$definition['query'], ['input' => $empty]);
    $answer = static function (array $recorded) use ($raw, $empty): ?int {
        $task = $recorded['response']['task'] ?? null;

        return is_array($task) ? $raw('POST', '/api/worker/query-tasks/'.$task['query_task_id'].'/complete', [
            'lease_owner' => $task['lease_owner'], 'query_task_attempt' => $task['query_task_attempt'], 'result_envelope' => $empty])['status'] : null;
    };
    $queryTask = static fn (array $worker): array => $raw('POST', '/api/worker/query-tasks/poll', $worker + ['timeout_seconds' => 2]);
    $heldQuery = static fn (array $worker, ?string $requestId): array => $hold('/api/worker/query-tasks/poll',
        $worker + ['timeout_seconds' => $definition['hold_seconds']] + ($requestId === null ? [] : ['poll_request_id' => $requestId]));
    // Without a request ID a fenced query poll answers empty; with one, it
    // answers stale. Either way the next poll leases the query.
    foreach (['control' => null, 'heartbeat' => null, 'replaced' => null, 'heartbeat-id' => 'held-query', 'replaced-id' => 'held-query']
        as $case => $requestId) {
        $worker = $register('query-'.$case);
        $runId = $idle($worker);
        $held = $heldQuery($worker, $requestId);
        if (str_starts_with($case, 'heartbeat')) {
            $state['query_'.$case.'_heartbeat'] = $heartbeat($worker);
        } elseif (str_starts_with($case, 'replaced')) {
            $register('query-'.$case);
        }
        $client = $query($worker, $runId);
        $polled = $collect($held);
        $state['query_'.$case.'_held'] = $outcome($polled, $worker);
        if ($case !== 'control') {
            $polled = $queryTask($worker);
            $state['query_'.$case.'_next'] = $outcome($polled, $worker);
        }
        $state['query_'.$case.'_answered'] = $answer($polled);
        $state['query_'.$case.'_client'] = $collect($client)['status'];
    }

    // A workflow task leased before a same-ID re-registration.
    $worker = $register('recovered');
    $start($worker);
    $leased = $workflowTask($worker);
    $state['recovery_leased'] = $outcome($leased, $worker);
    $register('recovered');
    $state['recovery_after_registration'] = $outcome($workflowTask($worker), $worker);
    $task = $leased['response']['task'] ?? [];
    $old = $raw('POST', '/api/worker/workflow-tasks/'.($task['task_id'] ?? 'missing').'/complete', [
        'lease_owner' => $task['lease_owner'] ?? null, 'workflow_task_attempt' => $task['workflow_task_attempt'] ?? null,
        'commands' => [['type' => 'complete_workflow', 'result' => $empty]]]);
    $state['recovery_old_completion'] = ['status' => $old['status'], 'reason' => $old['response']['reason'] ?? null];

    return $state;
}
