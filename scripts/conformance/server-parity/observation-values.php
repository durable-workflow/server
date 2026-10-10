<?php

declare(strict_types=1);

use DurableWorkflow\Client;

// Describe already decoded values; the official Avro codec owns wire parsing.
// Decimal strings retain the complete int64 value across the JSON recorder.
function typedValue(mixed $value): array
{
    if (is_array($value)) {
        return ['type' => array_is_list($value) ? 'list' : 'map', 'value' => array_map(typedValue(...), $value)];
    }

    return match (get_debug_type($value)) {
        'int' => ['type' => 'int64', 'value' => (string) $value],
        'float' => ['type' => 'double', 'value' => $value],
        'bool' => ['type' => 'boolean', 'value' => $value],
        'string' => ['type' => 'string', 'value' => $value],
        'null' => ['type' => 'null', 'value' => null],
        default => throw new RuntimeException('No parity type recorder for '.get_debug_type($value)),
    };
}

function decodeHistory(array $events, callable $decode): array
{
    return array_map(static function (array $event) use ($decode): array {
        $payload = $event['payload'];
        $decoded = [];
        foreach (['output', 'result', 'arguments', 'value'] as $key) {
            if (array_key_exists($key, $payload)) {
                $decoded[$key] = $decode($payload[$key]);
            }
        }
        if (isset($payload['activity']['arguments'])) {
            $decoded['activity_arguments'] = $decode($payload['activity']['arguments']);
        }
        if ($event['event_type'] === 'CooperativeCancellationRequested') {
            $decoded['cancellation_command'] = $decode($payload['command']['payload']);
        }
        $event['decoded'] = $decoded;
        $event['typed_decoded'] = array_map(typedValue(...), $decoded);

        return $event;
    }, $events);
}

function httpHistory(Client $client, string $workflowId, string $runId): array
{
    $events = [];
    $tokens = [];
    $next = null;
    do {
        $page = $client->workflowHistory($workflowId, $runId, 2, $next);
        array_push($events, ...$page['events']);
        $next = $page['next_page_token'] ?? null;
        if ($next !== null && $next !== '') {
            if (isset($tokens[$next])) {
                throw new RuntimeException('History pagination repeated a token.');
            }
            $tokens[$next] = true;
        }
    } while ($next !== null && $next !== '');

    return $events;
}
