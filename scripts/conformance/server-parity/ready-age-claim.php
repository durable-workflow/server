<?php

declare(strict_types=1);

// The owning source job kills the native process between these two phases.
// Raw worker HTTP isolates this optional producer field from SDK observations.
function readyAgeRequest(string $url, string $path, array $body): array
{
    $headers = ['Content-Type: application/json', 'X-Namespace: default',
        'X-Durable-Workflow-Protocol-Version: 1.20',
        'Authorization: Bearer '.(getenv('DW_PARITY_TOKEN') ?: 'parity-test-token')];
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers),
        'content' => json_encode($body, JSON_THROW_ON_ERROR), 'timeout' => 5, 'ignore_errors' => true]]);
    $response = file_get_contents(rtrim($url, '/').$path, false, $context);
    $status = $http_response_header[0] ?? '';
    $GLOBALS['readyAgeLastHttpStatus'] = substr($status, 0, 64);
    if ($response === false || preg_match('/^HTTP\/\S+ 2[0-9]{2}\b/', $status) !== 1) {
        throw new RuntimeException('Ready-age source request refused: '.$path.' '.$status);
    }

    return json_decode($response, true, flags: JSON_THROW_ON_ERROR);
}

function readyAgeClaim(array $response, array $context = []): array
{
    $task = $response['task'] ?? null;
    if (($response['server_capabilities']['claimed_task_ready_age'] ?? null) !== true || ! is_array($task)
        || ! is_int($task['ready_age_ms'] ?? null) || $task['ready_age_ms'] < 0 || $task['ready_age_ms'] > 9007199254740991) {
        throw new RuntimeException('Native successful claim lacks its declared literal ready-age value. '
            .json_encode(readyAgeRefusalShape($response, $context), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
    $projection = array_intersect_key($task, array_flip(['task_id', 'run_id', 'workflow_task_attempt', 'activity_attempt_id', 'attempt_number', 'ready_age_ms']));
    if (! is_string($projection['task_id'] ?? null) || ! is_string($projection['run_id'] ?? null)) {
        throw new RuntimeException('Ready-age claim lost its original task/run identity.');
    }

    // JSONB may reorder object keys. Field names and literal values are exact.
    ksort($projection);

    return $projection;
}

// Bounded facts for a refused claim (Server #1096): statuses, key names,
// types and the two ready-age values only. No payload, token or other value.
function readyAgeRefusalShape(array $response, array $context): array
{
    $scalar = static fn (mixed $value): array => ['present' => $value !== null, 'type' => get_debug_type($value),
        'value' => is_bool($value) || is_int($value) ? $value : null];
    $keys = static fn (array $value): array => array_slice(array_map('strval', array_keys($value)), 0, 64);
    $capabilities = is_array($response['server_capabilities'] ?? null) ? $response['server_capabilities'] : [];
    $task = $response['task'] ?? null;
    $shape = [
        'http_status' => is_string($GLOBALS['readyAgeLastHttpStatus'] ?? null) ? $GLOBALS['readyAgeLastHttpStatus'] : null,
        'poll_status' => is_string($response['poll_status'] ?? null) ? substr($response['poll_status'], 0, 64) : null,
        'reason' => is_string($response['reason'] ?? null) ? substr($response['reason'], 0, 64) : null,
        'response_keys' => $keys($response),
        'task_type' => get_debug_type($task),
        'task_keys' => is_array($task) ? $keys($task) : null,
        'claimed_task_ready_age' => $scalar($capabilities['claimed_task_ready_age'] ?? null),
        'ready_age_ms' => $scalar(is_array($task) ? ($task['ready_age_ms'] ?? null) : null),
    ];
    if (isset($context['saved_task_id'])) {
        $shape['same_task_id'] = is_array($task) && ($task['task_id'] ?? null) === $context['saved_task_id'];
    }
    foreach (['phase', 'kind', 'seconds_since_prepare'] as $field) {
        if (array_key_exists($field, $context)) {
            $shape[$field] = $context[$field];
        }
    }

    return $shape;
}
