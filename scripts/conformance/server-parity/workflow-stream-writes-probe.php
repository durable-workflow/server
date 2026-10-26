<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as WorkflowStreamHttp;
use GuzzleHttp\HandlerStack;

// Durable workflow stream producer writes over raw HTTP: appends that open a
// stream, idempotent retries, the pending-items cap with its partial commit,
// reads and bounded waits, closes and errored closes, and every refusal the
// routes answer. Streams are server-owned rows; no worker is needed, so the
// run is simply started and left waiting.
function finishHttpWorkflowStreamWrites(Client $client, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['workflow_stream_writes'];
    $id = $workflowId.'-wsw';
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    // The SQLite reference shares its database with a one-second maintenance
    // loop; retry its retryable lock-pressure refusals, as the other probes do.
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new WorkflowStreamHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $raw = static function (string $method, string $path, ?array $body = null) use ($http, $token): array {
        $response = $http->request($method, $path, ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => 'default',
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ]), ...($body === null ? [] : ['json' => $body])]);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if (is_array($decoded)) {
            unset($decoded['protocol_version'], $decoded['server_capabilities'], $decoded['control_plane']);
        }

        return ['status' => $response->getStatusCode(), 'response' => $decoded];
    };
    $state = ['applicable' => true, 'workflow_id' => $id];
    $state['run_id'] = $client->startWorkflow($definition['workflow_type'], $id, $id.'-queue', [$fixture['input']])->selectedRunId;
    $run = "workflows/{$id}/runs/{$state['run_id']}";
    $stream = $definition['stream'];
    $other = $definition['other_stream'];
    $bad = rawurlencode($definition['bad_stream']);
    $items = static fn (string $name): string => "{$run}/streams/".rawurlencode($name).'/items';
    $append = static fn (string $name, array $body): array => $raw('POST', $items($name), $body);
    $read = static fn (string $name, string $query = ''): array => $raw('GET', $items($name).($query === '' ? '' : '?'.$query));
    $close = static fn (string $name, array $body = []): array => $raw('POST', "{$run}/streams/".rawurlencode($name).'/close', $body);
    $show = static fn (string $name): array => $raw('GET', "{$run}/streams/".rawurlencode($name));
    $batches = $definition['batches'];

    $state['list_empty'] = $raw('GET', "{$run}/streams");
    $state['show_missing'] = $show($stream);
    $state['items_missing'] = $read($stream);
    $state['close_missing'] = $close($stream);
    $state['append_first'] = $append($stream, ['items' => $batches['first']]);
    $state['append_retry'] = $append($stream, ['items' => $batches['retry']]);
    $state['show_after_appends'] = $show($stream);
    $state['items_window'] = $read($stream, 'from=1&max_items=1');
    $state['show_after_window'] = $show($stream);
    $state['items_all'] = $read($stream, 'from=0');
    $state['show_after_catch_up'] = $show($stream);
    $started = microtime(true);
    $state['items_wait_empty'] = $read($stream, 'from=3&wait_seconds=1');
    $state['items_wait_returned_after_wait'] = microtime(true) - $started >= 0.9;
    $state['append_full'] = $append($stream, ['items' => $batches['over_cap'], 'max_pending_items' => $definition['max_pending_items']]);
    $state['show_after_full'] = $show($stream);
    $state['items_after_full'] = $read($stream, 'from=3');
    $state['refusals'] = [
        'no_items' => $append($stream, []),
        'empty_items' => $append($stream, ['items' => []]),
        'too_many_items' => $append($stream, ['items' => array_fill(0, $definition['too_many_items'], ['payload' => 1])]),
        'item_not_object' => $append($stream, ['items' => ['text']]),
        'bad_codec' => $append($stream, ['items' => [['payload' => 1, 'payload_codec' => $definition['bad_codec']]]]),
        'long_idempotency_key' => $append($stream, ['items' => [['payload' => 1, 'idempotency_key' => str_repeat('k', $definition['long_key_length'])]]]),
        'zero_max_pending' => $append($stream, ['items' => [['payload' => 1]], 'max_pending_items' => 0]),
        'bad_stream_append' => $raw('POST', "{$run}/streams/{$bad}/items", ['items' => [['payload' => 1]]]),
        'unstored_reference' => $append($other, ['items' => [['payload_reference' => $definition['unstored_reference']]]]),
        'unknown_run_append' => $raw('POST', "workflows/{$id}/runs/{$state['run_id']}x/streams/{$stream}/items", ['items' => [['payload' => 1]]]),
        'unknown_workflow_append' => $raw('POST', "workflows/{$id}-missing/runs/{$state['run_id']}/streams/{$stream}/items", ['items' => [['payload' => 1]]]),
        'bad_stream_show' => $raw('GET', "{$run}/streams/{$bad}"),
        'bad_stream_items' => $raw('GET', "{$run}/streams/{$bad}/items"),
        'negative_from' => $read($stream, 'from=-1'),
        'too_many_read' => $read($stream, 'max_items='.$definition['too_many_read']),
        'long_wait' => $read($stream, 'wait_seconds='.$definition['too_long_wait']),
        'bad_stream_close' => $raw('POST', "{$run}/streams/{$bad}/close", []),
        'long_error_reason' => $close($stream, ['error_reason' => str_repeat('e', $definition['long_key_length'])]),
        'zero_retention' => $close($stream, ['retention_seconds' => 0]),
        'unknown_run_close' => $raw('POST', "workflows/{$id}/runs/{$state['run_id']}x/streams/{$stream}/close", []),
    ];
    $state['list_after_refusals'] = $raw('GET', "{$run}/streams");
    $state['close'] = $close($stream);
    $state['close_again'] = $close($stream, ['error_reason' => 'late error', 'retention_seconds' => 5]);
    $state['append_closed'] = $append($stream, ['items' => [['payload' => 1, 'idempotency_key' => 'k1']]]);
    $state['items_closed_tail'] = $read($stream, 'from=5');
    $state['items_closed_head'] = $read($stream, 'from=4');
    $state['append_other'] = $append($other, ['items' => $batches['other']]);
    $state['close_errored'] = $close($other, ['error_reason' => $definition['error_reason'], 'retention_seconds' => $definition['retention_seconds']]);
    $state['append_errored'] = $append($other, ['items' => [['payload' => 1]]]);
    $state['items_errored'] = $read($other, 'from=0&wait_seconds=1');
    $state['list_final'] = $raw('GET', "{$run}/streams");

    return $state;
}
