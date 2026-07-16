<?php

declare(strict_types=1);

/**
 * @return array{
 *     workflow_type: string,
 *     queries: list<string>,
 *     query_contracts: list<array{name: string, parameters: list<array<string, mixed>>}>,
 *     signals: list<string>,
 *     signal_contracts: list<array{name: string, parameters: list<array<string, mixed>>}>,
 *     updates: list<string>,
 *     update_contracts: list<array{name: string, parameters: list<array<string, mixed>>}>
 * }
 */
function php_sdk_waiting_command_contract(): array
{
    return [
        'workflow_type' => 'php.sdk.waiting',
        'queries' => ['current'],
        'query_contracts' => [[
            'name' => 'current',
            'parameters' => [],
        ]],
        'signals' => ['increment'],
        'signal_contracts' => [[
            'name' => 'increment',
            'parameters' => [[
                'name' => 'amount',
                'position' => 0,
                'required' => true,
                'variadic' => false,
                'default_available' => false,
                'default' => null,
                'type' => 'int',
                'allows_null' => false,
            ]],
        ]],
        'updates' => ['set'],
        'update_contracts' => [[
            'name' => 'set',
            'parameters' => [[
                'name' => 'value',
                'position' => 0,
                'required' => true,
                'variadic' => false,
                'default_available' => false,
                'default' => null,
                'type' => 'int',
                'allows_null' => false,
            ]],
        ]],
    ];
}

/**
 * Normalize only JSON object member order. JSON array order and scalar types
 * remain part of the command contract.
 */
function php_sdk_normalize_json_object_order(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map('php_sdk_normalize_json_object_order', $value);
    }

    $normalized = [];
    foreach ($value as $key => $entry) {
        $normalized[$key] = php_sdk_normalize_json_object_order($entry);
    }
    ksort($normalized, SORT_STRING);

    return $normalized;
}

function php_sdk_json_semantically_equal(mixed $actual, mixed $expected): bool
{
    return php_sdk_normalize_json_object_order($actual)
        === php_sdk_normalize_json_object_order($expected);
}

/** @param  array<string, mixed>  $required */
function php_sdk_command_contract_matches(mixed $contract, array $required): bool
{
    if (! is_array($contract)) {
        return false;
    }

    return php_sdk_json_semantically_equal($contract['queries'] ?? null, $required['queries'])
        && php_sdk_json_semantically_equal(
            $contract['query_contracts'] ?? null,
            $required['query_contracts'],
        )
        && php_sdk_json_semantically_equal($contract['signals'] ?? null, $required['signals'])
        && php_sdk_json_semantically_equal(
            $contract['signal_contracts'] ?? null,
            $required['signal_contracts'],
        )
        && php_sdk_json_semantically_equal($contract['updates'] ?? null, $required['updates'])
        && php_sdk_json_semantically_equal(
            $contract['update_contracts'] ?? null,
            $required['update_contracts'],
        );
}

/** @param  array<string, mixed>  $required */
function php_sdk_started_payload_matches(mixed $payload, array $required): bool
{
    return is_array($payload)
        && php_sdk_json_semantically_equal($payload['declared_queries'] ?? null, $required['queries'])
        && php_sdk_json_semantically_equal(
            $payload['declared_query_contracts'] ?? null,
            $required['query_contracts'],
        )
        && php_sdk_json_semantically_equal($payload['declared_signals'] ?? null, $required['signals'])
        && php_sdk_json_semantically_equal(
            $payload['declared_signal_contracts'] ?? null,
            $required['signal_contracts'],
        )
        && php_sdk_json_semantically_equal($payload['declared_updates'] ?? null, $required['updates'])
        && php_sdk_json_semantically_equal(
            $payload['declared_update_contracts'] ?? null,
            $required['update_contracts'],
        );
}

/**
 * Validate the one immutable WorkflowStarted event returned by the history
 * request made immediately after start. This function intentionally does not
 * poll: a run that started without the complete contract must fail evidence
 * collection rather than appear to acquire declarations later.
 *
 * @param  array<string, mixed>  $history
 * @return array<string, mixed>
 */
function php_sdk_waiting_started_contract_evidence(
    array $history,
    string $workflowId,
    string $runId,
    string $startResponseObservedAt,
    float $startResponseObservedEpoch,
): array {
    $events = $history['events'] ?? $history['history'] ?? null;
    if (! is_array($events)) {
        throw new RuntimeException('Addressable workflow history did not contain an events list.');
    }

    $startedEvents = array_values(array_filter(
        $events,
        static fn (mixed $event): bool => is_array($event)
            && ($event['event_type'] ?? $event['type'] ?? null) === 'WorkflowStarted',
    ));
    if (count($startedEvents) !== 1) {
        throw new RuntimeException(sprintf(
            'Addressable workflow history must contain exactly one immutable WorkflowStarted event; observed %d.',
            count($startedEvents),
        ));
    }

    $started = $startedEvents[0];
    $payload = is_array($started['payload'] ?? null) ? $started['payload'] : [];
    $required = php_sdk_waiting_command_contract();
    $requiredPayload = [
        'declared_queries' => $required['queries'],
        'declared_query_contracts' => $required['query_contracts'],
        'declared_signals' => $required['signals'],
        'declared_signal_contracts' => $required['signal_contracts'],
        'declared_updates' => $required['updates'],
        'declared_update_contracts' => $required['update_contracts'],
    ];

    if (! php_sdk_started_payload_matches($payload, $required)) {
        foreach ($requiredPayload as $field => $expected) {
            if (! php_sdk_json_semantically_equal($payload[$field] ?? null, $expected)) {
                throw new RuntimeException(sprintf(
                    'Immutable WorkflowStarted field [%s] did not contain the complete normalized PHP handler contract.',
                    $field,
                ));
            }
        }
    }

    $observedEpoch = microtime(true);
    $encodedStarted = json_encode(
        $started,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );

    return [
        'workflow_id' => $workflowId,
        'run_id' => $runId,
        'command_contract_source' => 'durable_history',
        'history_reads' => 1,
        'start_response_observed_at' => $startResponseObservedAt,
        'workflow_started_snapshot_observed_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'snapshot_wait_after_start_ms' => max(
            0,
            (int) round(($observedEpoch - $startResponseObservedEpoch) * 1000),
        ),
        'workflow_started_event_fingerprint' => hash('sha256', $encodedStarted),
        'workflow_started_event' => $started,
        'required_workflow_command_contract' => $required,
        'validated_before_client_commands' => true,
    ];
}
