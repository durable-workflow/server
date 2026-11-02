<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as KeyOrderHttp;
use GuzzleHttp\HandlerStack;

// Key order in plain JSON input and in search attribute validation, over raw
// HTTP in a fresh namespace without registered search attributes (published
// Workflow PayloadEnvelopeResolver::resolve and Avro::toDatum, Server
// SearchAttributeValueValidator). The probe sends each start's input exactly
// as the fixture writes it: an object's values in document order, nested
// maps in document order, and an object with integer keys that is not a
// list. It records each start answer and the run's stored input, one signal's
// stored arguments, and the validation messages for several invalid search
// attributes on a start and on an upsert command.
function finishHttpKeyOrder(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['key_order'];
    $namespace = strtolower($workflowId.'-ko');
    $admin->createNamespace($namespace, 'key order λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    // The upsert case polls the published PHP SQLite reference while its
    // maintenance loop runs; its documented lock-pressure refusal is retried.
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new KeyOrderHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 15, 'handler' => $stack]);
    $headers = array_filter([
        'Authorization' => $token === null ? null : 'Bearer '.$token,
        'Accept' => 'application/json', 'X-Namespace' => $namespace,
        'X-Durable-Workflow-Control-Plane-Version' => '2', 'X-Durable-Workflow-Protocol-Version' => '1.20',
    ]);
    // The body is sent as the fixture's JSON text, so an object keeps its
    // key order and integer keys stay object keys.
    $raw = static function (string $method, string $path, ?string $body = null) use ($http, $headers): array {
        $response = $http->request($method, $path, ['headers' => $headers + ($body === null ? [] : ['Content-Type' => 'application/json']),
            ...($body === null ? [] : ['body' => $body])]);

        return ['status' => $response->getStatusCode(),
            'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    };
    $json = static fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $answer = static fn (array $answer): array => ['status' => $answer['status'],
        'reason' => $answer['response']['reason'] ?? null, 'outcome' => $answer['response']['outcome'] ?? null,
        'message' => $answer['response']['message'] ?? null, 'errors' => $answer['response']['errors'] ?? null];
    $state = ['applicable' => true];
    $runs = [];
    foreach ($definition['starts'] as $case => $shape) {
        $id = $workflowId.'-ko-'.$case;
        $body = '{"workflow_id":'.$json($id).',"workflow_type":'.$json($definition['workflow_type'])
            .',"task_queue":'.$json($id.'-queue').',"input":'.$shape['input']
            .(isset($shape['search_attributes']) ? ',"search_attributes":'.$shape['search_attributes'] : '').'}';
        $started = $raw('POST', 'workflows', $body);
        $observed = ['start' => $answer($started)];
        if ($started['status'] === 201) {
            $runs[$case] = [$id, $started['response']['run_id']];
            $described = $raw('GET', 'workflows/'.rawurlencode($id))['response'];
            $observed['input'] = $described['input'] ?? 'absent';
            $observed['input_blob'] = $described['input_envelope']['blob'] ?? 'absent';
        }
        $state['starts'][$case] = $observed;
    }

    $signal = $definition['signal'];
    [$id, $runId] = $runs[$signal['start']];
    $signalled = $raw('POST', 'workflows/'.rawurlencode($id).'/signal/'.rawurlencode($signal['name']),
        '{"input":'.$signal['input'].'}');
    $state['signal'] = ['answer' => $answer($signalled)];
    foreach ($raw('GET', 'workflows/'.rawurlencode($id).'/runs/'.$runId.'/history')['response']['events'] ?? [] as $event) {
        if (($event['event_type'] ?? null) === 'SignalReceived') {
            $state['signal']['arguments_blob'] = $event['payload']['arguments']['blob'] ?? 'absent';
        }
    }

    $upsert = $definition['upsert'];
    $id = $workflowId.'-ko-upsert';
    $queue = $id.'-queue';
    $worker = $id.'-worker';
    $manifest = array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
        ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http']);
    $registered = $raw('POST', 'worker/register', $json(['worker_id' => $worker, 'task_queue' => $queue,
        'runtime' => 'php', 'sdk_version' => 'raw-http', 'supported_workflow_types' => [$definition['workflow_type']],
        'supported_activity_types' => [], 'capabilities' => ['typed_search_attributes'],
        'capability_manifest' => $manifest]));
    if ($registered['status'] !== 201) {
        throw new RuntimeException('Key order registration: '.json_encode($registered));
    }
    $started = $raw('POST', 'workflows', $json(['workflow_id' => $id, 'workflow_type' => $definition['workflow_type'],
        'task_queue' => $queue, 'input' => ['upsert']]));
    if ($started['status'] !== 201) {
        throw new RuntimeException('Key order upsert start: '.json_encode($started));
    }
    $task = null;
    for ($attempt = 0; $attempt < 10 && ! is_array($task); $attempt++) {
        $task = $raw('POST', 'worker/workflow-tasks/poll', $json(['worker_id' => $worker, 'task_queue' => $queue,
            'timeout_seconds' => 1]))['response']['task'] ?? null;
    }
    if (! is_array($task)) {
        throw new RuntimeException('Key order: no workflow task');
    }
    $completed = $raw('POST', 'worker/workflow-tasks/'.$task['task_id'].'/complete',
        '{"lease_owner":'.$json($task['lease_owner']).',"workflow_task_attempt":'.$json($task['workflow_task_attempt'])
        .',"commands":[{"type":"upsert_search_attributes","attributes":'.$upsert['attributes'].'}]}');
    $state['upsert'] = ['status' => $completed['status'], 'reason' => $completed['response']['reason'] ?? null,
        'errors' => $completed['response']['errors'] ?? null];

    return $state;
}
