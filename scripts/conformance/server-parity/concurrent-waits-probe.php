<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as ConcurrentWaitsHttp;
use GuzzleHttp\HandlerStack;

// Several waits open at once, over raw worker HTTP in a fresh namespace. Each
// run has its own queue and worker.
//
// The mixed run opens, in one completion, a signal wait for `first`, a signal
// wait for `second` with a timeout and a condition wait with a timeout. A
// `second` signal reserves its own wait; the resumed task applies it, cancels
// its timeout, satisfies the open condition wait and schedules a timer. A
// `first` signal then resumes its own wait at its own sequence and the run
// completes. The same-name run opens two waits for `dup`: the first `dup`
// signal reserves the later wait, the second is buffered behind it, and the
// resumed task applies the first at the later wait's sequence.
//
// Identities become labels by first appearance (`{wait:N}`, `{signal:N}`,
// `{timer:N}`, `{task:N}`, buffered ids `{buffered:N}`); instants are dropped.
function finishHttpConcurrentWaits(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['concurrent_waits'];
    $namespace = strtolower($workflowId.'-waits');
    $admin->createNamespace($namespace, 'concurrent waits λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    // The SQLite reference shares its database with a one-second maintenance
    // loop; retry its retryable lock-pressure refusals, as the other probes do.
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new ConcurrentWaitsHttp(['base_uri' => rtrim($url, '/'), 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $raw = static function (string $method, string $path, ?array $body = []) use ($http, $token, $namespace): array {
        $options = ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2', 'X-Durable-Workflow-Protocol-Version' => '1.20',
        ])];
        if ($body !== null) {
            $options['json'] = $body === [] ? (object) [] : $body;
        }
        $response = $http->request($method, $path, $options);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return ['status' => $response->getStatusCode(), 'response' => $decoded];
    };
    $ok = static function (array $recorded, int $status, string $operation): array {
        if ($recorded['status'] !== $status) {
            throw new RuntimeException('Concurrent waits '.$operation.': '.json_encode($recorded));
        }

        return $recorded['response'];
    };
    $labels = [];
    $label = static function (?string $id, string $kind) use (&$labels): ?string {
        if ($id === null) {
            return null;
        }
        $key = strtolower($id);
        if (str_starts_with($key, 'signal-command:')) {
            $kind = 'buffered';
        }
        if (! isset($labels[$key])) {
            $count = count(array_filter($labels, static fn (string $value): bool => str_starts_with($value, '{'.$kind.':')));
            $labels[$key] = '{'.$kind.':'.($count + 1).'}';
        }

        return $labels[$key];
    };
    $idKinds = ['signal_wait_id' => 'wait', 'condition_wait_id' => 'wait', 'timer_id' => 'timer', 'signal_id' => 'signal',
        'workflow_signal_id' => 'signal'];
    $kept = ['signal_name', 'condition_key', 'sequence', 'timeout_seconds', 'delay_seconds', 'timer_kind'];
    $event = static function (array $event) use ($label, $idKinds, $kept): array {
        $payload = $event['payload'] ?? [];
        $fields = [];
        foreach ($kept as $field) {
            if (array_key_exists($field, $payload)) {
                $fields[$field] = $payload[$field];
            }
        }
        foreach ($idKinds as $field => $kind) {
            if (isset($payload[$field]) && is_string($payload[$field])) {
                $fields[$field] = $label($payload[$field], $kind);
            }
        }
        ksort($fields);

        return [$event['event_type'], $fields];
    };
    // Each read returns the events recorded since the run's previous read, in
    // history order, so no step depends on how a server numbers its events.
    $seen = [];
    $history = static function (array $run) use ($raw, $ok, $event, &$seen): array {
        $events = $ok($raw('GET', '/api/workflows/'.$run['workflow_id'].'/runs/'.$run['run_id'].'/history', null), 200, 'history')['events'] ?? [];
        $from = $seen[$run['run_id']] ?? 2;
        $seen[$run['run_id']] = count($events);

        return array_map($event, array_slice($events, $from));
    };
    $describe = static function (array $run) use ($raw, $ok): array {
        $described = $ok($raw('GET', '/api/workflows/'.$run['workflow_id'].'/runs/'.$run['run_id'], null), 200, 'describe');

        return ['status' => $described['status'] ?? null, 'wait_kind' => $described['wait_kind'] ?? null,
            'wait_reason' => $described['wait_reason'] ?? null];
    };
    $waits = static function (array $run) use ($raw, $ok, $label): array {
        $observed = $ok($raw('GET', '/api/workflows/'.$run['workflow_id'].'/runs/'.$run['run_id'].'/observation', null), 200, 'observation');

        return array_map(static fn (array $wait): array => ['id' => $label($wait['id'] ?? null, 'wait'),
            'kind' => $wait['kind'] ?? null, 'state' => $wait['state'] ?? null, 'reason' => $wait['reason'] ?? null,
            'target_name' => $wait['target_name'] ?? null, 'task_status' => $wait['task_status'] ?? null],
            $observed['current_waits']['waits'] ?? []);
    };
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $type = $definition['workflow_type'];
    $start = static function (string $suffix) use ($raw, $ok, $type, $empty, $workflowId): array {
        $id = $workflowId.'-'.$suffix;
        $queue = $id.'-queue';
        $worker = $id.'-worker';
        $ok($raw('POST', '/api/worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
            'sdk_version' => 'raw-http', 'supported_workflow_types' => [$type], 'supported_activity_types' => [],
            'capabilities' => [], 'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
                ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http'])]), 201, 'registration');
        $started = $ok($raw('POST', '/api/workflows', ['workflow_id' => $id, 'workflow_type' => $type,
            'task_queue' => $queue, 'input' => $empty]), 201, 'start');

        return ['workflow_id' => $id, 'run_id' => $started['run_id'], 'queue' => $queue, 'worker' => $worker];
    };
    $claim = static function (array $run) use ($raw, $ok): ?array {
        return $ok($raw('POST', '/api/worker/workflow-tasks/poll', ['worker_id' => $run['worker'],
            'task_queue' => $run['queue'], 'timeout_seconds' => 2]), 200, 'workflow claim')['task'] ?? null;
    };
    $resume = static function (?array $task) use ($label): ?array {
        if ($task === null) {
            return null;
        }

        return ['resume_source_kind' => $task['resume_source_kind'] ?? null, 'workflow_wait_kind' => $task['workflow_wait_kind'] ?? null,
            'signal_name' => $task['signal_name'] ?? null, 'signal_wait_id' => $label($task['signal_wait_id'] ?? null, 'wait'),
            'workflow_signal_id' => $label($task['workflow_signal_id'] ?? null, 'signal'),
            'condition_wait_id' => $label($task['condition_wait_id'] ?? null, 'wait')];
    };
    $complete = static function (array $task, array $commands) use ($raw): array {
        $recorded = $raw('POST', '/api/worker/workflow-tasks/'.$task['task_id'].'/complete', ['lease_owner' => $task['lease_owner'],
            'workflow_task_attempt' => $task['workflow_task_attempt'], 'commands' => $commands]);

        return ['status' => $recorded['status'], 'outcome' => $recorded['response']['outcome'] ?? null,
            'reason' => $recorded['response']['reason'] ?? null, 'run_status' => $recorded['response']['run_status'] ?? null,
            'created_tasks' => count($recorded['response']['created_task_ids'] ?? [])];
    };
    $signal = static function (array $run, string $name) use ($raw, $empty): array {
        $recorded = $raw('POST', '/api/workflows/'.$run['workflow_id'].'/signal/'.$name, ['arguments' => $empty]);

        return ['status' => $recorded['status'], 'outcome' => $recorded['response']['outcome'] ?? null,
            'reason' => $recorded['response']['reason'] ?? null];
    };
    $timer = ['type' => 'start_timer', 'delay_seconds' => $definition['timer_seconds']];

    $state = ['applicable' => true];
    // Mixed waits: two signal waits and a condition wait in one completion.
    $mixed = $start('mixed');
    $task = $claim($mixed);
    $mixedState['opened'] = $complete($task, [
        ['type' => 'open_signal_wait', 'signal_name' => 'first'],
        ['type' => 'open_signal_wait', 'signal_name' => 'second', 'timeout_seconds' => $definition['timeout_seconds']],
        ['type' => 'open_condition_wait', 'condition_key' => 'ready', 'timeout_seconds' => $definition['timeout_seconds']],
    ]);
    $mixedState['opened_history'] = $history($mixed);
    $mixedState['opened_describe'] = $describe($mixed);
    $mixedState['opened_waits'] = $waits($mixed);
    $mixedState['signal_second'] = $signal($mixed, 'second');
    $mixedState['second_received'] = $history($mixed);
    $task = $claim($mixed);
    $mixedState['second_resume'] = $resume($task);
    $mixedState['second_completed'] = $complete($task, [$timer]);
    $mixedState['second_history'] = $history($mixed);
    $mixedState['second_describe'] = $describe($mixed);
    $mixedState['second_waits'] = $waits($mixed);
    $mixedState['signal_first'] = $signal($mixed, 'first');
    $task = $claim($mixed);
    $mixedState['first_resume'] = $resume($task);
    $mixedState['first_completed'] = $complete($task, [['type' => 'complete_workflow', 'result' => $empty]]);
    $mixedState['first_history'] = $history($mixed);
    $mixedState['first_describe'] = $describe($mixed);
    $state['mixed'] = $mixedState;

    // Two waits for one signal name.
    $same = $start('same');
    $task = $claim($same);
    $sameState['opened'] = $complete($task, [['type' => 'open_signal_wait', 'signal_name' => 'dup'],
        ['type' => 'open_signal_wait', 'signal_name' => 'dup']]);
    $sameState['opened_history'] = $history($same);
    $sameState['signal_one'] = $signal($same, 'dup');
    $sameState['signal_two'] = $signal($same, 'dup');
    $sameState['received'] = $history($same);
    $task = $claim($same);
    $sameState['resume'] = $resume($task);
    $sameState['completed'] = $complete($task, [$timer]);
    $sameState['applied_history'] = $history($same);
    $sameState['applied_waits'] = $waits($same);
    $sameState['terminated'] = $raw('POST', '/api/workflows/'.$same['workflow_id'].'/terminate', ['reason' => 'concurrent waits fixture'])['status'];
    $state['same_name'] = $sameState;

    return $state;
}
