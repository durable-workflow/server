<?php

declare(strict_types=1);

/**
 * Decide whether a worker poll refused for backend lock pressure may be retried.
 *
 * Server documents this refusal as HTTP 503 with `reason: backend_lock_pressure`,
 * `task: null` and `retry_after_seconds`; no task was claimed, and the client is
 * told to retry the poll with backoff. Only that exact poll refusal is retried.
 * Every other path, status or body is returned to the caller unchanged, so lease,
 * fencing and history assertions still see every real outcome.
 *
 * Returns the delay in seconds before the next attempt, or null to rethrow.
 */
function lockPressurePollRetryDelay(
    string $method,
    string $uri,
    ?int $status,
    mixed $response,
    int $retriesSoFar,
    float $waitedSeconds,
    int $maxRetries = 15,
    float $maxWaitSeconds = 30.0,
): ?float {
    $path = parse_url($uri, PHP_URL_PATH);
    if ($method !== 'POST' || ! is_string($path) || ! str_ends_with($path, '/poll') || $status !== 503
        || ! is_array($response) || ($response['reason'] ?? null) !== 'backend_lock_pressure'
        || ! array_key_exists('task', $response) || $response['task'] !== null
        || $retriesSoFar >= $maxRetries) {
        return null;
    }
    $retryAfter = $response['retry_after_seconds'] ?? 1;
    if (! is_int($retryAfter) && ! is_float($retryAfter)) {
        return null;
    }
    $delay = min(max((float) $retryAfter, 0.1), 5.0);

    return $waitedSeconds + $delay <= $maxWaitSeconds ? $delay : null;
}

/**
 * Decide whether any other request refused for backend lock pressure may be
 * retried.
 *
 * Server returns this refusal as HTTP 503 with `reason: backend_lock_pressure`
 * and `retryable: true` only after its transaction failed with a concurrency
 * error, so nothing was committed. Worker mutations remain fenced by their
 * task and attempt identifiers (`outcome: deferred`), so repeating the request
 * cannot record a second outcome. Polls keep lockPressurePollRetryDelay's
 * stricter check that no task was handed out.
 *
 * Returns the delay in seconds before the next attempt, or null to return the
 * refusal to the caller unchanged.
 */
function lockPressureOperationRetryDelay(
    string $method,
    string $uri,
    ?int $status,
    mixed $response,
    int $retriesSoFar,
    float $waitedSeconds,
    int $maxRetries = 15,
    float $maxWaitSeconds = 30.0,
): ?float {
    $path = parse_url($uri, PHP_URL_PATH);
    if (! is_string($path) || str_ends_with($path, '/poll')) {
        return lockPressurePollRetryDelay($method, $uri, $status, $response, $retriesSoFar, $waitedSeconds,
            $maxRetries, $maxWaitSeconds);
    }
    if ($status !== 503 || ! is_array($response) || ($response['reason'] ?? null) !== 'backend_lock_pressure'
        || ($response['retryable'] ?? null) !== true || $retriesSoFar >= $maxRetries) {
        return null;
    }
    $retryAfter = $response['retry_after_seconds'] ?? 1;
    if (! is_int($retryAfter) && ! is_float($retryAfter)) {
        return null;
    }
    $delay = min(max((float) $retryAfter, 0.1), 5.0);

    return $waitedSeconds + $delay <= $maxWaitSeconds ? $delay : null;
}
