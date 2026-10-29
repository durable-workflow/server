<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Worker\CapabilityManifest;
use GuzzleHttp\Client as ManyUpdatesHttp;
use GuzzleHttp\HandlerStack;

// A run's description after more than 64 updates, over raw worker HTTP on
// protocol 1.20 in a fresh namespace (published Server
// WorkflowController::show, Workflow RunWaitView and
// StructuralLimits::pendingUpdateCountLimit). Published PHP bounds only
// pending updates, not how many a run takes over its life. A run waiting
// for a signal takes each update, accepted, through its own workflow task;
// the probe records the run's description before, after the completed
// updates, and with one more update left accepted, and the history counts.
function finishHttpManyUpdates(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['many_updates'];
    $namespace = strtolower($workflowId.'-mu');
    $admin->createNamespace($namespace, 'many updates λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new ManyUpdatesHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 60, 'handler' => $stack]);
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
    $type = $definition['workflow_type'];
    $update = $definition['update'];
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $id = $workflowId.'-mu';
    $queue = $id.'-queue';
    $worker = $id.'-worker';
    $capabilities = ['query_tasks', 'workflow_updates'];
    $registered = $raw('POST', 'worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
        'sdk_version' => 'raw-http', 'supported_workflow_types' => [$type], 'supported_activity_types' => [],
        'workflow_command_contracts' => [$type => ['signals' => [$definition['signal']], 'queries' => [], 'updates' => [$update], 'update_validators' => [],
            'update_contracts' => [['name' => $update, 'parameters' => [['name' => 'value', 'required' => true, 'type' => 'array']]]]]],
        'capabilities' => $capabilities, 'capability_manifest' => CapabilityManifest::forCapabilities($capabilities)]);
    $started = $raw('POST', 'workflows', ['workflow_id' => $id, 'workflow_type' => $type, 'task_queue' => $queue,
        'input' => $empty]);
    if ($registered['status'] !== 201 || $started['status'] !== 201) {
        throw new RuntimeException('Many updates setup: '.json_encode([$registered, $started]));
    }
    $poll = static function (mixed $context = null) use ($raw, $worker, $queue): array {
        $task = $raw('POST', 'worker/workflow-tasks/poll', ['worker_id' => $worker, 'task_queue' => $queue,
            'timeout_seconds' => 5])['response']['task'] ?? null;
        if (! is_array($task)) {
            throw new RuntimeException('Many updates: no workflow task was ready after '.json_encode($context));
        }

        return $task;
    };
    $complete = static fn (array $task, array $commands): array => $raw('POST',
        'worker/workflow-tasks/'.$task['task_id'].'/complete', ['lease_owner' => $task['lease_owner'],
            'workflow_task_attempt' => $task['workflow_task_attempt'], 'commands' => $commands]);
    $describe = static fn (): array => array_intersect_key($raw('GET', 'workflows/'.rawurlencode($id))['response'],
        array_flip($definition['described_fields']));
    // On MySQL-family databases published PHP's update admission can deadlock
    // against its own maintenance pass and answer an uncaught 500. The request
    // id makes the same submission idempotent, so it is sent again.
    $submit = static function (int $index) use ($raw, $id, $update): array {
        for ($attempt = 1; ; $attempt++) {
            $answer = $raw('POST', 'workflows/'.rawurlencode($id).'/update/'.$update,
                ['input' => [['v' => $index]], 'request_id' => 'many-'.$index, 'wait_for' => 'accepted']);
            if ($answer['status'] !== 500 || $attempt === 3) {
                return $answer;
            }
            usleep(200000 * $attempt);
        }
    };

    $opened = $complete($poll(), [['type' => 'open_signal_wait', 'signal_name' => $definition['signal']]]);
    $state = ['applicable' => true, 'opened' => $opened['status'], 'before' => $describe()];
    $answers = [];
    $lastBeat = microtime(true);
    for ($index = 0; $index < $definition['completed_updates']; $index++) {
        // The worker stays active across the loop, which can outlast its
        // staleness window; a heartbeat every 20 seconds stays well inside it
        // however slow each round trip is.
        if (microtime(true) - $lastBeat >= 20) {
            $beat = $raw('POST', 'worker/heartbeat', ['worker_id' => $worker]);
            if ($beat['status'] !== 200) {
                throw new RuntimeException('Many updates heartbeat: '.json_encode($beat));
            }
            $lastBeat = microtime(true);
        }
        $accepted = $submit($index);
        $task = $poll([$index, $accepted]);
        $completed = $complete($task, [['type' => 'complete_update', 'update_id' => $accepted['response']['update_id'] ?? null,
            'result' => $empty]]);
        $answer = implode(' ', [$accepted['status'], $accepted['response']['update_status'] ?? 'none',
            ($task['workflow_update_id'] ?? null) === ($accepted['response']['update_id'] ?? false) ? 'own_task' : 'other_task',
            $completed['status'], $completed['response']['run_status'] ?? 'none']);
        $answers[$answer] = ($answers[$answer] ?? 0) + 1;
    }
    $state['updates'] = $answers;
    $state['after'] = $describe();
    $pending = $submit($definition['completed_updates']);
    $state['pending'] = ['status' => $pending['status'], 'update_status' => $pending['response']['update_status'] ?? null,
        'described' => $describe()];
    $counts = [];
    $page = $raw('GET', 'workflows/'.rawurlencode($id).'/runs/'.$started['response']['run_id'].'/history?page_size=1000')['response'];
    foreach ($page['events'] ?? [] as $event) {
        $counts[$event['event_type']] = ($counts[$event['event_type']] ?? 0) + 1;
    }
    ksort($counts);
    $state['events'] = $counts;
    // The accepted update's workflow task is never polled; end the run so it
    // does not stay for the maintenance passes of later fixtures.
    $terminated = $raw('POST', 'workflows/'.rawurlencode($id).'/terminate', ['reason' => 'many updates recorded']);
    if (! in_array($terminated['status'], [200, 202], true)) {
        throw new RuntimeException('Many updates cleanup: '.json_encode($terminated));
    }

    return $state;
}
