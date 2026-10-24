<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as MessageStreamsHttp;
use GuzzleHttp\HandlerStack;

// Message streams over raw HTTP and the published SDK client: one workflow
// with a raw worker that waits on a condition as SDK receives do, appends
// and refused appends, diagnostics, refused and accepted completion cursors
// and waits, continue-as-new and appends to the closed instance. Worker
// answers drop their protocol advertisement; everything else is recorded raw.
function finishHttpMessageStreams(Client $client, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['message_streams'];
    $id = $workflowId.'-ms';
    $queue = $id.'-queue';
    $worker = $id.'-worker';
    $stream = $definition['stream'];
    $codec = $client->payloadCodec();
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    // The SQLite reference shares its database with a one-second maintenance
    // loop; retry its retryable lock-pressure refusals, as the other probes do.
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new MessageStreamsHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $raw = static function (string $method, string $path, ?array $body = null, ?string $worker = null) use ($http, $token): array {
        $response = $http->request($method, $path, ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => 'default',
            $worker !== null ? 'X-Durable-Workflow-Protocol-Version' : 'X-Durable-Workflow-Control-Plane-Version' => $worker ?? '2',
        ]), ...($body === null ? [] : ['json' => $body])]);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if (is_array($decoded)) {
            unset($decoded['protocol_version'], $decoded['server_capabilities']);
        }

        return ['status' => $response->getStatusCode(), 'response' => $decoded];
    };
    $append = static fn (string $streamName, array $body): array => $raw('POST',
        "workflows/{$id}/message-streams/".rawurlencode($streamName).'/messages', $body);
    $complete = static fn (array $task, array $body, string $protocol = '1.20'): array => $raw('POST',
        "worker/workflow-tasks/{$task['task_id']}/complete",
        ['lease_owner' => $task['lease_owner'] ?? $worker, 'workflow_task_attempt' => $task['workflow_task_attempt']] + $body, $protocol);
    $wait = static fn (int $cursor): array => ['type' => 'open_condition_wait',
        'condition_key' => "message-stream:{$stream}:{$cursor}",
        'condition_definition_fingerprint' => 'sha256:'.hash('sha256', $definition['condition_fingerprint_source'])];
    // A stream's deliveries and cursor moves in history, decoded.
    $streamEvents = static function (array $events) use ($codec, $id): array {
        $rows = [];
        foreach ($events as $event) {
            $payload = $event['payload'] ?? [];
            if (($event['event_type'] ?? null) === 'SignalReceived'
                && ($payload['signal_name'] ?? null) === '__durable_workflow_message_stream') {
                $deliveries = $codec->decodeEnvelope($payload['arguments']);
                foreach ($deliveries as &$delivery) {
                    if (is_array($delivery['payload_envelope'] ?? null)) {
                        $envelope = $delivery['payload_envelope'];
                        $delivery['payload_envelope'] = array_keys($envelope);
                        sort($delivery['payload_envelope']);
                        $delivery['payload'] = $codec->decodeEnvelope($envelope);
                    }
                }
                unset($delivery);
                $rows[] = ['event_type' => 'SignalReceived', 'deliveries' => $deliveries];
            } elseif (($event['event_type'] ?? null) === 'MessageCursorAdvanced') {
                $key = $payload['stream_key'] ?? null;
                $rows[] = ['event_type' => 'MessageCursorAdvanced',
                    'stream_key' => $key === "instance:{$id}" ? 'instance' : $key,
                    'previous_position' => $payload['previous_position'] ?? null,
                    'new_position' => $payload['new_position'] ?? null,
                    'task_status' => $payload['task']['status'] ?? null];
            }
        }

        return $rows;
    };
    $history = static function (string $runId) use ($raw, $id): array {
        $read = $raw('GET', "workflows/{$id}/runs/{$runId}/history");

        return $read['response']['events'] ?? [];
    };
    $poll = static function () use ($client, $worker, $queue, $streamEvents): array {
        $task = $client->pollWorkflowTask($worker, $queue, 0);
        if (! is_array($task)) {
            throw new RuntimeException('No workflow task was ready for the message stream run.');
        }

        return [$task, ['run_id' => $task['run_id'] ?? null, 'history' => $streamEvents($task['history_events'] ?? [])]];
    };
    $state = ['applicable' => true, 'workflow_id' => $id];
    $info = $raw('GET', 'cluster/info')['response'];
    $state['cluster_contract'] = $info['message_streams_contract'] ?? null;
    $state['worker_capability'] = $info['worker_protocol']['server_capabilities']['message_streams'] ?? null;
    $state['run_id'] = $client->startWorkflow($definition['workflow_type'], $id, $queue, [$fixture['input']])->selectedRunId;
    $state['list_before'] = $raw('GET', "workflows/{$id}/message-streams");
    $state['show_before'] = $raw('GET', "workflows/{$id}/message-streams/{$stream}");
    $state['show_bad_name'] = $raw('GET', "workflows/{$id}/message-streams/".rawurlencode($definition['bad_stream']));
    $state['list_unknown'] = $raw('GET', "workflows/{$id}-missing/message-streams");
    $client->registerWorker($worker, $queue, [$definition['workflow_type']], []);
    [$task, $state['poll1']] = $poll();
    $state['complete1_refusals'] = [
        'wait_ahead' => $complete($task, ['commands' => [$wait(0)], 'message_stream_waits' => [['stream_name' => $stream, 'after_position' => 1]]]),
        'cursor_ahead' => $complete($task, ['commands' => [$wait(0)], 'message_stream_cursors' => [['stream_name' => $stream, 'through_position' => 1]]]),
        'bad_name' => $complete($task, ['commands' => [$wait(0)], 'message_stream_waits' => [['stream_name' => 'bad name', 'after_position' => 0]]]),
        'repeated_wait' => $complete($task, ['commands' => [$wait(0)], 'message_stream_waits' => [
            ['stream_name' => $stream, 'after_position' => 0], ['stream_name' => $stream, 'after_position' => 0]]]),
        'old_protocol' => $complete($task, ['commands' => [$wait(0)], 'message_stream_waits' => [['stream_name' => $stream, 'after_position' => 0]]], '1.14'),
    ];
    $state['complete1'] = $complete($task, ['commands' => [$wait(0)], 'message_stream_waits' => [['stream_name' => $stream, 'after_position' => 0]]]);
    $state['list_waiting'] = $raw('GET', "workflows/{$id}/message-streams");
    $messages = $definition['messages'];
    $state['appends'] = [
        'm1' => $append($stream, ['message_id' => 'm1', 'input' => $messages['m1']]),
        'm1_duplicate' => $append($stream, ['message_id' => 'm1', 'input' => $messages['m1']]),
        'm1_conflict' => $append($stream, ['message_id' => 'm1', 'input' => $messages['conflict']]),
        // The published SDK client's own append.
        'm2_sdk' => ['status' => null, 'response' => $client->appendMessageStream($id, $stream, 'm2', $messages['m2'])],
        'no_id' => $append($stream, ['input' => $messages['m3']]),
        'bad_id' => $append($stream, ['message_id' => 'bad id!', 'input' => $messages['m3']]),
        'long_id' => $append($stream, ['message_id' => str_repeat('a', $definition['long_message_id_length']), 'input' => $messages['m3']]),
        'bad_input' => $append($stream, ['message_id' => 'm3', 'input' => 'text']),
        'bad_stream' => $append($definition['bad_stream'], ['message_id' => 'm3', 'input' => $messages['m3']]),
        'unknown_workflow' => $raw('POST', "workflows/{$id}-missing/message-streams/{$stream}/messages", ['message_id' => 'm1', 'input' => [1]]),
        'other_stream' => $append($definition['other_stream'], ['message_id' => 'a1']),
        'reserved_signal' => $raw('POST', "workflows/{$id}/signal/__durable_workflow_message_stream", ['input' => [1]]),
    ];
    $state['list_after_appends'] = $raw('GET', "workflows/{$id}/message-streams");
    $state['show_after_appends'] = $raw('GET', "workflows/{$id}/message-streams/{$stream}");
    [$task, $state['poll2']] = $poll();
    $state['complete2_refusals'] = [
        'beyond' => $complete($task, ['commands' => [$wait(1)], 'message_stream_cursors' => [['stream_name' => $stream, 'through_position' => 3]]]),
        'negative' => $complete($task, ['commands' => [$wait(1)], 'message_stream_cursors' => [['stream_name' => $stream, 'through_position' => -1]]]),
        'wait_behind' => $complete($task, ['commands' => [$wait(1)], 'message_stream_cursors' => [['stream_name' => $stream, 'through_position' => 1]],
            'message_stream_waits' => [['stream_name' => $stream, 'after_position' => 0]]]),
    ];
    $state['complete2'] = $complete($task, ['commands' => [$wait(1)], 'message_stream_cursors' => [['stream_name' => $stream, 'through_position' => 1]]]);
    $state['history_after2'] = $streamEvents($history($state['run_id']));
    $state['list_after2'] = $raw('GET', "workflows/{$id}/message-streams");
    $state['appends']['m3'] = $append($stream, ['message_id' => 'm3', 'input' => $messages['m3']]);
    [$task, $state['poll3']] = $poll();
    $state['complete3'] = $complete($task, ['commands' => [['type' => 'continue_as_new', 'arguments' => $codec->envelope([$fixture['input']])]],
        'message_stream_cursors' => [['stream_name' => $stream, 'through_position' => 2]]]);
    $state['list_after_continue'] = $raw('GET', "workflows/{$id}/message-streams");
    [$task, $state['poll4']] = $poll();
    $state['complete4'] = $complete($task, ['commands' => [['type' => 'complete_workflow', 'result' => $codec->envelope('done')]],
        'message_stream_cursors' => [['stream_name' => $stream, 'through_position' => 3]]]);
    $state['continued_run_id'] = $state['poll4']['run_id'];
    $state['list_final'] = $raw('GET', "workflows/{$id}/message-streams");
    $state['appends']['closed'] = $append($stream, ['message_id' => 'm4', 'input' => [1]]);
    $state['appends']['closed_duplicate'] = $append($stream, ['message_id' => 'm1', 'input' => $messages['m1']]);
    $eventTypes = static fn (array $events): array => array_map(static fn (array $event): string => $event['event_type'], $events);
    $firstRun = $history($state['run_id']);
    $continuedRun = $history($state['continued_run_id']);
    $state['first_run_events'] = $eventTypes($firstRun);
    $state['first_run_streams'] = $streamEvents($firstRun);
    $state['continued_run_events'] = $eventTypes($continuedRun);
    $state['continued_run_streams'] = $streamEvents($continuedRun);

    return $state;
}
