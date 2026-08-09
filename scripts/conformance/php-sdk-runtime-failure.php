<?php

declare(strict_types=1);
use DurableWorkflow\Exception\ServerException;
use DurableWorkflow\Exception\TransportException;

const PHP_SDK_RUNTIME_FAILURE_ENVELOPE_MAX_BYTES = 2048;

function bounded_runtime_failure_text(mixed $value, array $secrets, int $limit = 256): string
{
    $text = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $value) ?? '';
    $text = preg_replace('/\s+/', ' ', $text) ?? '';
    foreach ($secrets as $secret) {
        if (is_string($secret) && $secret !== '') {
            $text = str_replace($secret, '[REDACTED]', $text);
        }
    }
    $text = preg_replace(
        '/((?:authorization|credential|password|passwd|secret|token|api[_-]?key)\s*[:=]\s*(?:bearer\s+)?)[^\s,;]+/i',
        '$1[REDACTED]',
        $text,
    ) ?? '';

    return substr(trim($text), 0, $limit);
}

function bounded_runtime_failure_value(mixed $value, array $secrets, int $depth = 0): mixed
{
    if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
        return $value;
    }
    if (is_string($value)) {
        return bounded_runtime_failure_text($value, $secrets);
    }
    if (! is_array($value)) {
        return bounded_runtime_failure_text($value, $secrets);
    }
    if ($depth >= 4) {
        return '[depth limit reached]';
    }

    $bounded = [];
    foreach (array_slice($value, 0, 12, true) as $key => $entry) {
        $safeKey = bounded_runtime_failure_text($key, $secrets, 128);
        $bounded[$safeKey] = preg_match(
            '/authorization|credential|password|passwd|secret|token|api[_-]?key/i',
            $safeKey,
        ) === 1
            ? '[REDACTED]'
            : bounded_runtime_failure_value($entry, $secrets, $depth + 1);
    }

    return $bounded;
}

function runtime_failure_json(mixed $value): ?string
{
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

    return is_string($encoded) ? $encoded : null;
}

function runtime_failure_json_bytes(mixed $value): int
{
    $encoded = runtime_failure_json($value);

    return $encoded === null ? PHP_INT_MAX : strlen($encoded);
}

/** @param array<string, mixed> $target */
function add_bounded_runtime_failure_text(
    array $target,
    string $key,
    string $value,
    array $secrets,
    int $maxBytes,
    int $textLimit,
): array {
    $value = bounded_runtime_failure_text($value, $secrets, $textLimit);
    while (true) {
        $candidate = $target;
        $candidate[$key] = $value;
        if (runtime_failure_json_bytes($candidate) <= $maxBytes) {
            return $candidate;
        }
        if ($value === '') {
            return $target;
        }

        $nextLength = intdiv(strlen($value), 2);
        $value = substr($value, 0, $nextLength);
    }
}

function bounded_runtime_failure_envelope(
    ?array $value,
    array $secrets,
    int $maxBytes = PHP_SDK_RUNTIME_FAILURE_ENVELOPE_MAX_BYTES,
): ?array {
    if ($value === null) {
        return null;
    }

    $bounded = bounded_runtime_failure_value($value, $secrets);
    if (! is_array($bounded)) {
        return null;
    }
    $serialized = runtime_failure_json($bounded);
    if ($serialized !== null && strlen($serialized) <= $maxBytes) {
        return $bounded;
    }

    $summary = ['_truncated' => true];
    foreach ([
        'error',
        'reason',
        'code',
        'status',
        'status_code',
        'retryable',
        'non_retryable',
        'operation',
        'http_method',
        'endpoint',
        'task_id',
        'workflow_task_id',
        'activity_task_id',
        'query_task_id',
        'workflow_id',
        'run_id',
        'message',
    ] as $key) {
        if (! array_key_exists($key, $bounded)) {
            continue;
        }

        $entry = $bounded[$key];
        if (is_int($entry) || is_float($entry) || is_bool($entry) || $entry === null) {
            $candidate = $summary;
            $candidate[$key] = $entry;
            if (runtime_failure_json_bytes($candidate) <= $maxBytes) {
                $summary = $candidate;
            }

            continue;
        }

        $entryText = is_string($entry) ? $entry : (runtime_failure_json($entry) ?? '');
        $summary = add_bounded_runtime_failure_text(
            $summary,
            $key,
            $entryText,
            $secrets,
            $maxBytes,
            192,
        );
    }
    $summary = add_bounded_runtime_failure_text(
        $summary,
        '_bounded_json_excerpt',
        $serialized ?? '',
        $secrets,
        $maxBytes,
        512,
    );

    return runtime_failure_json_bytes($summary) <= $maxBytes
        ? $summary
        : ['_truncated' => true];
}

function runtime_failure_endpoint_class(string $endpoint): string
{
    $path = parse_url($endpoint, PHP_URL_PATH);
    $path = is_string($path) && $path !== '' ? $path : $endpoint;
    $normalized = strtolower(trim($path));

    $knownClass = match (true) {
        preg_match('#/(?:api/)?worker(?:-protocol)?(?:/|$)#', $normalized) === 1 => 'worker_protocol',
        preg_match('#/(?:api/)?workflows?(?:/|$)#', $normalized) === 1 => 'workflow_control',
        preg_match('#/(?:api/)?task-queues?(?:/|$)#', $normalized) === 1 => 'task_queue_diagnostics',
        preg_match('#/(?:api/)?workers?(?:/|$)#', $normalized) === 1 => 'worker_registration',
        default => null,
    };
    if ($knownClass !== null) {
        return $knownClass;
    }
    if (preg_match('#^/?(?:api/)?([^/*{?]+)#', $normalized, $matches) === 1) {
        return substr(str_replace('-', '_', $matches[1]), 0, 96);
    }

    return 'unknown';
}

function runtime_failure_public_field(array $details, array $fields): mixed
{
    foreach ($fields as $field) {
        if (array_key_exists($field, $details)) {
            return $details[$field];
        }
    }

    return null;
}

function set_runtime_failure_context(
    string $operation,
    string $httpMethod,
    string $endpoint,
    ?string $workflowId = null,
    ?string $runId = null,
): void {
    $GLOBALS['phpSdkRuntimeFailureContext'] = [
        'operation' => $operation,
        'http_method' => $httpMethod,
        'endpoint' => $endpoint,
        'workflow_id' => $workflowId,
        'run_id' => $runId,
    ];
}

function runtime_failure_http_status(Throwable $exception): ?int
{
    $current = $exception;
    while ($current !== null) {
        if ($current instanceof ServerException
            || $current instanceof TransportException) {
            $status = $current->status;
            if (is_int($status) && $status >= 400 && $status <= 599) {
                return $status;
            }
        }
        $current = $current->getPrevious();
    }

    return null;
}

function capture_expected_terminal_exception(callable $operation, string $expectedType): array
{
    try {
        $operation();

        return ['type' => null, 'message' => 'operation unexpectedly returned'];
    } catch (Throwable $exception) {
        if (runtime_failure_http_status($exception) !== null || ! is_a($exception, $expectedType)) {
            throw $exception;
        }

        return ['type' => $exception::class, 'message' => $exception->getMessage()];
    }
}

function install_runtime_failure_handler(string $process, string $phase, array $secrets): void
{
    set_runtime_failure_context($process.'.'.$phase, 'UNKNOWN', 'unknown');
    set_exception_handler(static function (Throwable $exception) use ($process, $secrets): never {
        $status = null;
        $details = null;
        $reason = null;
        $transportFailure = false;
        $current = $exception;
        while ($current !== null) {
            if ($current instanceof ServerException) {
                $status = $current->status;
                $details = $current->details;
                $reason = $current->reason;
                break;
            }
            if ($current instanceof TransportException) {
                $transportFailure = true;
                if (is_int($current->status) && $current->status >= 400 && $current->status <= 599) {
                    $status = $current->status;
                    $details = $current->response;
                    break;
                }
            }
            $current = $current->getPrevious();
        }

        $httpFailure = is_int($status) && $status >= 400 && $status <= 599;
        $classification = $httpFailure ? 'server' : ($transportFailure ? 'runner' : 'sdk');
        $owningSurface = match ($classification) {
            'server' => 'server',
            'runner' => 'conformance_harness',
            default => 'sdk-php',
        };
        $context = is_array($GLOBALS['phpSdkRuntimeFailureContext'] ?? null)
            ? $GLOBALS['phpSdkRuntimeFailureContext']
            : [];
        $publicDetails = is_array($details) ? $details : [];
        if ($httpFailure && is_string($reason) && $reason !== '' && ! array_key_exists('reason', $publicDetails)) {
            $publicDetails['reason'] = $reason;
        }
        if ($httpFailure && ! array_key_exists('message', $publicDetails)) {
            $publicDetails['message'] = $exception->getMessage();
        }
        $envelope = bounded_runtime_failure_envelope($httpFailure ? $publicDetails : null, $secrets);
        $operation = bounded_runtime_failure_text(
            runtime_failure_public_field($publicDetails, ['operation'])
                ?? ($context['operation'] ?? 'unknown'),
            $secrets,
            160,
        );
        $httpMethod = bounded_runtime_failure_text(
            runtime_failure_public_field($publicDetails, ['http_method', 'method'])
                ?? ($context['http_method'] ?? ''),
            $secrets,
            16,
        );
        $endpoint = bounded_runtime_failure_text(
            runtime_failure_public_field($publicDetails, ['endpoint', 'path'])
                ?? ($context['endpoint'] ?? ''),
            $secrets,
            256,
        );
        $publicReason = runtime_failure_public_field($publicDetails, ['reason', 'error', 'code']);
        $taskId = runtime_failure_public_field(
            $publicDetails,
            ['task_id', 'workflow_task_id', 'activity_task_id', 'query_task_id'],
        );
        $retryable = runtime_failure_public_field($publicDetails, ['retryable']);
        $payload = [
            'classification' => $classification,
            'owning_surface' => $owningSurface,
            'process' => $process,
            'operation' => $operation,
            'http_method' => $httpMethod,
            'endpoint_class' => runtime_failure_endpoint_class($endpoint),
            'endpoint' => $endpoint,
            'status_code' => $httpFailure ? $status : null,
            'reason' => bounded_runtime_failure_text($publicReason ?? $reason ?? '', $secrets, 192) ?: null,
            'retryable' => is_bool($retryable) ? $retryable : null,
            'task_id' => bounded_runtime_failure_text($taskId ?? '', $secrets, 256) ?: null,
            'public_error_envelope' => $envelope,
            'workflow_id' => bounded_runtime_failure_text(
                $context['workflow_id']
                    ?? runtime_failure_public_field($publicDetails, ['workflow_id'])
                    ?? ($envelope['workflow_id'] ?? ''),
                $secrets,
                256,
            ) ?: null,
            'run_id' => bounded_runtime_failure_text(
                $context['run_id']
                    ?? runtime_failure_public_field($publicDetails, ['run_id'])
                    ?? ($envelope['run_id'] ?? ''),
                $secrets,
                256,
            ) ?: null,
            'exception_type' => $exception::class,
            'contract' => property_exists($exception, 'contract') && is_string($exception->contract)
                ? bounded_runtime_failure_text($exception->contract, $secrets, 256)
                : null,
            'message' => bounded_runtime_failure_text($exception->getMessage(), $secrets),
        ];

        // Apply the byte bound to the exact envelope that is finally serialized.
        $payload['public_error_envelope'] = bounded_runtime_failure_envelope(
            $payload['public_error_envelope'],
            $secrets,
        );
        $encoded = runtime_failure_json($payload);
        fwrite(STDERR, 'DW_PHP_SDK_RUNTIME_FAILURE='.($encoded ?? '{}')."\n");
        exit(1);
    });
}
