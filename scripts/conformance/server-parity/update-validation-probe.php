<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Worker\CapabilityManifest;
use GuzzleHttp\Client as UpdateValidationHttp;
use GuzzleHttp\HandlerStack;

// Synchronous update validation over raw HTTP. Raw workers declare
// validators, since the published PHP SDK does not author them. An update
// that waits for its validator runs in a child PHP process while this one
// answers as the worker. Worker answers drop their protocol advertisement;
// everything else is recorded raw.
function finishHttpUpdateValidation(Client $client, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['update_validation'];
    $id = $workflowId.'-uv';
    $type = $definition['workflow_type'];
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $codec = $client->payloadCodec();
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new UpdateValidationHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $raw = static function (string $method, string $path, ?array $body = null, bool $worker = false) use ($http, $token): array {
        $response = $http->request($method, $path, ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => 'default',
            $worker ? 'X-Durable-Workflow-Protocol-Version' : 'X-Durable-Workflow-Control-Plane-Version' => $worker ? '1.20' : '2',
        ]), ...($body === null ? [] : ['json' => $body])]);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if (is_array($decoded)) {
            unset($decoded['protocol_version'], $decoded['server_capabilities']);
        }

        return ['status' => $response->getStatusCode(), 'response' => $decoded];
    };
    // A request that waits for a validator, sent from a child process. Like
    // the requests above, it retries only the documented lock-pressure
    // refusal, which committed nothing (lockPressureOperationRetryDelay);
    // `seconds` is the answered attempt's own wait, and retries are reported
    // on stderr.
    $background = static function (string $workflow, string $update, array $body) use ($url, $token): array {
        $child = <<<'PHP'
[, $url, $token, $path, $body, $retryRule] = $argv;
require $retryRule;
$headers = "Accept: application/json\r\nContent-Type: application/json\r\nX-Namespace: default\r\n"
    ."X-Durable-Workflow-Control-Plane-Version: 2\r\n".($token === '' ? '' : "Authorization: Bearer {$token}\r\n");
$context = stream_context_create(['http' => ['method' => 'POST', 'header' => $headers, 'content' => $body,
    'ignore_errors' => true, 'timeout' => 60]]);
$retries = [];
$waited = 0.0;
while (true) {
    $started = microtime(true);
    $response = file_get_contents(rtrim($url, '/').'/api/'.$path, false, $context);
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
    fwrite(STDERR, json_encode(['lock_pressure_update_retries' => ['path' => $path, 'delays' => $retries]]).PHP_EOL);
}
echo json_encode(['status' => $status, 'response' => $decoded, 'seconds' => microtime(true) - $started]);
PHP;
        $process = proc_open([PHP_BINARY, '-r', $child, '--', $url, $token ?? '', "workflows/{$workflow}/update/{$update}",
            json_encode($body, JSON_THROW_ON_ERROR), __DIR__.'/lock-pressure-retry.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('The waiting update request could not start.');
        }

        return [$process, $pipes];
    };
    $collect = static function (array $pending): array {
        [$process, $pipes] = $pending;
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        proc_close($process);
        $result = json_decode((string) $output, true);
        if (! is_array($result)) {
            throw new RuntimeException('The waiting update request failed: '.trim((string) $errors));
        }
        if (trim((string) $errors) !== '') {
            fwrite(STDERR, (string) $errors);
        }

        return $result;
    };
    $capabilities = static fn (bool $capable): array => array_values(array_filter(['query_tasks', 'workflow_updates',
        $capable ? 'update_validation_tasks' : null]));
    $contract = static function (?array $validators) use ($definition): array {
        $updates = $definition['updates'];
        $contract = ['signals' => [], 'queries' => [], 'updates' => $updates,
            'update_contracts' => array_map(static fn (string $name): array => ['name' => $name,
                'parameters' => [['name' => 'value', 'required' => true, 'type' => 'array']]], $updates)];

        return $validators === null ? $contract : $contract + ['update_validators' => $validators];
    };
    $register = static function (string $worker, string $queue, array $contract, bool $capable) use ($raw, $type, $capabilities): array {
        $result = $raw('POST', 'worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'python',
            'supported_workflow_types' => [$type], 'supported_activity_types' => [],
            'workflow_command_contracts' => [$type => $contract], 'capabilities' => $capabilities($capable),
            'capability_manifest' => CapabilityManifest::forCapabilities($capabilities($capable))], true);
        unset($result['response']['registration_token']);

        return $result;
    };
    $wait = ['type' => 'open_signal_wait', 'signal_name' => 'finish'];
    // A run waiting for a signal on `queue`, so updates find it idle.
    $waitingRun = static function (string $workflow, string $queue, string $worker) use ($client, $raw, $type, $wait): array {
        $run = $client->startWorkflow($type, $workflow, $queue, [['n' => 1]])->selectedRunId;
        $poll = $raw('POST', 'worker/workflow-tasks/poll', ['worker_id' => $worker, 'task_queue' => $queue, 'timeout_seconds' => 5], true);
        $task = $poll['response']['task'] ?? null;
        if (! is_array($task)) {
            throw new RuntimeException("No workflow task was ready for {$workflow}.");
        }
        $raw('POST', "worker/workflow-tasks/{$task['task_id']}/complete", ['lease_owner' => $task['lease_owner'],
            'workflow_task_attempt' => $task['workflow_task_attempt'], 'commands' => [$wait]], true);

        return ['run_id' => $run, 'task_kind' => $task['task_kind'] ?? null];
    };
    $update = static fn (string $workflow, string $name, array $body): array => $raw('POST', "workflows/{$workflow}/update/{$name}", $body);
    $input = static fn (int $value, ?string $requestId = null): array => ['input' => [['v' => $value]]]
        + ($requestId === null ? [] : ['request_id' => $requestId]);
    $pollValidation = static function (string $worker, string $queue) use ($raw): array {
        $poll = $raw('POST', 'worker/update-validation-tasks/poll', ['worker_id' => $worker, 'task_queue' => $queue,
            'timeout_seconds' => 5], true);

        return [$poll, $poll['response']['task'] ?? null];
    };
    $complete = static fn (string $task, string $verdict, array $body): array => $raw('POST',
        "worker/update-validation-tasks/{$task}/{$verdict}", $body, true);
    $fence = static fn (string $owner, int $attempt): array => ['lease_owner' => $owner, 'update_validation_attempt' => $attempt];
    // The fields of a delivered task that its reader relies on, with the
    // task's replay history reduced to its event types.
    $delivered = static function (?array $task) use ($codec): ?array {
        if ($task === null) {
            return null;
        }
        $keys = array_keys($task);
        sort($keys);

        return ['keys' => $keys, 'task_kind' => $task['task_kind'] ?? null, 'update_name' => $task['update_name'] ?? null,
            'arguments' => isset($task['update_arguments']) ? $codec->decodeEnvelope($task['update_arguments']) : null,
            'attempt' => $task['update_validation_attempt'] ?? null, 'lease_owner' => $task['lease_owner'] ?? null,
            'same_ids' => ($task['update_id'] ?? null) === ($task['update_validation_task_id'] ?? false),
            'workflow_id' => $task['workflow_id'] ?? null, 'workflow_type' => $task['workflow_type'] ?? null,
            'run_status' => $task['run_status'] ?? null, 'payload_codec' => $task['payload_codec'] ?? null,
            'history' => array_map(static fn (array $event): string => $event['event_type'], $task['history_events'] ?? []),
            'export_history' => array_map(static fn (array $event): ?string => $event['type'] ?? null,
                $task['history_export']['history_events'] ?? [])];
    };
    $history = static function (string $workflow, string $run) use ($raw): array {
        $events = $raw('GET', "workflows/{$workflow}/runs/{$run}/history")['response']['events'] ?? [];

        return array_map(static function (array $event): array {
            $keys = array_values(array_intersect(array_keys($event['payload'] ?? []),
                ['update_id', 'update_name', 'request_id', 'validation_errors', 'workflow_command_id']));
            sort($keys);

            return [$event['event_type'], $keys, $event['payload']['validation_errors'] ?? null];
        }, $events);
    };

    $state = ['applicable' => true, 'workflow_id' => $id];
    $protocol = $raw('GET', 'cluster/info')['response']['worker_protocol']['server_capabilities'] ?? [];
    $state['capability'] = [$protocol['update_validation_tasks'] ?? null, $protocol['synchronous_update_validation'] ?? null];
    $queue = "{$id}-q";
    $worker = "{$id}-validator";
    $validators = $definition['validators'];
    $registered = $register($worker, $queue, $contract($validators), true);
    $state['register'] = ['status' => $registered['status'], 'capabilities' => $registered['response']['capabilities'] ?? null];
    $main = $waitingRun($id, $queue, $worker);
    $state['first_task_kind'] = $main['task_kind'];

    // Refusals before validation.
    $state['no_request_id'] = $update($id, 'approve_me', $input(1));
    $state['invalid_arguments'] = $update($id, 'approve_me', ['input' => ['text'], 'request_id' => 'bad']);

    // A rejection through the multiplexed poll.
    $pending = $background($id, 'reject_me', $input(2, 'r2'));
    $poll = $raw('POST', 'worker/workflow-tasks/poll', ['worker_id' => $worker, 'task_queue' => $queue, 'timeout_seconds' => 5,
        'task_kinds' => ['workflow', 'update_validation']], true);
    $task = $poll['response']['task'] ?? null;
    $state['multiplexed_poll'] = ['status' => $poll['status'], 'poll_status' => $poll['response']['poll_status'] ?? null,
        'task' => $delivered($task)];
    $taskId = $task['update_validation_task_id'] ?? 'missing';
    $state['reject_without_failure'] = $complete($taskId, 'reject', $fence($worker, 1));
    $state['reject'] = $complete($taskId, 'reject', $fence($worker, 1) + ['failure' => ['message' => 'too small',
        'validation_errors' => ['v' => ['too small']]]]);
    $state['rejected_update'] = $collect($pending);
    $state['reject_again'] = $complete($taskId, 'reject', $fence($worker, 1) + ['failure' => ['message' => 'again']]);

    // An approval through the dedicated poll.
    $pending = $background($id, 'approve_me', $input(3, 'r1'));
    [$poll, $task] = $pollValidation($worker, $queue);
    $state['dedicated_poll'] = ['status' => $poll['status'], 'poll_status' => $poll['response']['poll_status'] ?? null,
        'task' => $delivered($task)];
    $taskId = $task['update_validation_task_id'] ?? 'missing';
    $state['approve_wrong_owner'] = $complete($taskId, 'approve', $fence("{$id}-someone", 1));
    $state['approve_wrong_attempt'] = $complete($taskId, 'approve', $fence($worker, 2));
    $state['approve'] = $complete($taskId, 'approve', $fence($worker, 1));
    $state['approved_update'] = $collect($pending);
    $state['approve_again'] = $complete($taskId, 'approve', $fence($worker, 1));
    $state['approve_unknown'] = $complete('01ZZZZZZZZZZZZZZZZZZZZZZZZ', 'approve', $fence($worker, 1));

    // Retries return recorded receipts; an update without a validator is
    // admitted as before.
    $state['retained_approval'] = $update($id, 'approve_me', $input(99, 'r1'));
    $state['retained_rejection'] = $update($id, 'reject_me', $input(2, 'r2'));
    $state['plain'] = $update($id, 'plain', $input(4, 'p1'));
    $state['history'] = $history($id, $main['run_id']);

    // Timeouts, run side by side on their own queues: nobody claims; the
    // claiming worker leaves; the claiming worker never answers.
    $runs = [];
    foreach (['idle', 'lost', 'busy'] as $name) {
        $register("{$id}-{$name}", "{$id}-q-{$name}", $contract($validators), true);
        $runs[$name] = $waitingRun("{$id}-{$name}", "{$id}-q-{$name}", "{$id}-{$name}");
    }
    $unclaimed = $background("{$id}-idle", 'approve_me', $input(5, 'r3'));
    $lost = $background("{$id}-lost", 'approve_me', $input(6, 'l1'));
    $busy = $background("{$id}-busy", 'approve_me', $input(7, 'b1'));
    [, $lostTask] = $pollValidation("{$id}-lost", "{$id}-q-lost");
    [, $busyTask] = $pollValidation("{$id}-busy", "{$id}-q-busy");
    $state['lost_deregistered'] = $raw('DELETE', "workers/{$id}-lost")['status'];
    $state['lost_approve'] = $complete($lostTask['update_validation_task_id'] ?? 'missing', 'approve', $fence("{$id}-lost", 1));
    $state['unclaimed'] = $collect($unclaimed);
    $state['worker_lost'] = $collect($lost);
    $state['execution_timeout'] = $collect($busy);
    $state['busy_approve_late'] = $complete($busyTask['update_validation_task_id'] ?? 'missing', 'approve', $fence("{$id}-busy", 1));
    $state['unclaimed_again'] = $update("{$id}-idle", 'approve_me', $input(5, 'r3'));
    $state['unclaimed_conflict'] = $update("{$id}-idle", 'approve_me', $input(8, 'r3'));
    $state['worker_unavailable'] = $update("{$id}-lost", 'approve_me', $input(9, 'u1'));

    // An expired lease is claimed again, with the completion wait after it.
    $slowQueue = "{$id}-q-slow";
    $slow = "{$id}-slow";
    $register($slow, $slowQueue, $contract($validators), true);
    $runs['slow'] = $waitingRun("{$id}-slow", $slowQueue, $slow);
    $pending = $background("{$id}-slow", 'approve_me', $input(10, 'r4') + ['wait_for' => 'completed', 'wait_timeout_seconds' => 2]);
    [, $first] = $pollValidation($slow, $slowQueue);
    $taskId = $first['update_validation_task_id'] ?? 'missing';
    sleep($definition['lease_wait_seconds']);
    $state['lease_expired'] = $complete($taskId, 'approve', $fence($slow, 1));
    [, $second] = $pollValidation($slow, $slowQueue);
    $state['reclaimed'] = ['same_task' => ($second['update_validation_task_id'] ?? null) === $taskId,
        'attempt' => $second['update_validation_attempt'] ?? null];
    $state['approve_old_attempt'] = $complete($taskId, 'approve', $fence($slow, 1));
    $state['approve_second'] = $complete($taskId, 'approve', $fence($slow, 2));
    $state['completed_wait'] = $collect($pending);

    // A validator failure that is not a rejection records nothing.
    $pending = $background("{$id}-slow", 'reject_me', $input(11, 'r5'));
    [, $task] = $pollValidation($slow, $slowQueue);
    $state['fail'] = $complete($task['update_validation_task_id'] ?? 'missing', 'reject', $fence($slow, 1)
        + ['failure' => ['message' => 'validator crashed', 'reason' => 'validator_error', 'type' => 'RuntimeError', 'stack_trace' => 'trace']]);
    $state['failed_update'] = $collect($pending);
    $state['failed_again'] = $update("{$id}-slow", 'reject_me', $input(11, 'r5'));

    // The multiplexed poll turns to the other kind after each lease.
    $state['plain_before_order'] = $update("{$id}-slow", 'plain', $input(12, 'p2'))['status'];
    $pending = $background("{$id}-slow", 'approve_me', $input(13, 'r6'));
    usleep(500000);
    $kinds = [];
    for ($poll = 0; $poll < 2; $poll++) {
        $task = $raw('POST', 'worker/workflow-tasks/poll', ['worker_id' => $slow, 'task_queue' => $slowQueue, 'timeout_seconds' => 2,
            'task_kinds' => ['update_validation', 'workflow']], true)['response']['task'] ?? null;
        $kinds[] = $task['task_kind'] ?? null;
        if (($task['task_kind'] ?? null) === 'update_validation') {
            $complete($task['update_validation_task_id'], 'approve', $fence($slow, $task['update_validation_attempt']));
        }
    }
    $state['multiplexed_order'] = $kinds;
    $state['ordered_update'] = $collect($pending)['status'];
    $state['slow_history'] = $history("{$id}-slow", $runs['slow']['run_id']);

    // Routing refusals on their own queues.
    $register("{$id}-silent", "{$id}-q-silent", $contract(null), true);
    $waitingRun("{$id}-silent", "{$id}-q-silent", "{$id}-silent");
    $state['contract_missing'] = $update("{$id}-silent", 'plain', $input(1));
    $register("{$id}-incapable", "{$id}-q-incapable", $contract($validators), false);
    $waitingRun("{$id}-incapable", "{$id}-q-incapable", "{$id}-incapable");
    $state['capability_unsupported'] = $update("{$id}-incapable", 'approve_me', $input(1, 'c1'));
    $state['poll_without_capability'] = $raw('POST', 'worker/update-validation-tasks/poll', ['worker_id' => "{$id}-incapable",
        'task_queue' => "{$id}-q-incapable", 'timeout_seconds' => 0], true);
    $state['multiplexed_without_capability'] = $raw('POST', 'worker/workflow-tasks/poll', ['worker_id' => "{$id}-incapable",
        'task_queue' => "{$id}-q-incapable", 'timeout_seconds' => 0, 'task_kinds' => ['update_validation']], true);
    $register("{$id}-changed", "{$id}-q-changed", $contract($validators), true);
    $waitingRun("{$id}-changed", "{$id}-q-changed", "{$id}-changed");
    $register("{$id}-changed", "{$id}-q-changed", $contract([]), true);
    $state['worker_incompatible'] = $update("{$id}-changed", 'approve_me', $input(1, 'i1'));
    $state['bad_task_kinds'] = $raw('POST', 'worker/workflow-tasks/poll', ['worker_id' => "{$id}-changed",
        'task_queue' => "{$id}-q-changed", 'timeout_seconds' => 0, 'task_kinds' => ['workflow', 'activity']], true);
    $state['unregistered_poll'] = $raw('POST', 'worker/update-validation-tasks/poll', ['worker_id' => "{$id}-nobody",
        'task_queue' => $queue, 'timeout_seconds' => 0], true);

    return $state;
}
