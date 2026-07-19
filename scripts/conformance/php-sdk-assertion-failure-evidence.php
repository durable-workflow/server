<?php

declare(strict_types=1);

/**
 * @param  array<string, mixed>  $baseline
 */
function php_sdk_update_assertion_domain(array $baseline): string
{
    $result = is_array($baseline['update']['result'] ?? null)
        ? $baseline['update']['result']
        : [];
    $workerResponses = is_array($baseline['worker_operation_responses'] ?? null)
        ? $baseline['worker_operation_responses']
        : [];
    $workerCallback = $workerResponses['workflow.update:set'] ?? null;
    $updateStatus = strtolower(trim((string) ($result['update_status'] ?? $result['status'] ?? '')));
    $accepted = $updateStatus === 'accepted' || ($result['accepted'] ?? null) === true;
    $waitingForDispatch = ($result['wait_timed_out'] ?? false) === true
        && array_key_exists('applied_at', $result)
        && $result['applied_at'] === null;

    if ($accepted && $waitingForDispatch && ! is_array($workerCallback)) {
        return 'server';
    }

    return 'sdk';
}

/**
 * @param  list<string>  $failedAssertions
 * @param  array<string, string>  $assertionDomains
 * @param  array<string, mixed>  $baseline
 * @param  array<string, mixed>  $activityCallbackCardinality
 * @return list<array<string, mixed>>
 */
function php_sdk_assertion_failure_evidence(
    array $failedAssertions,
    array $assertionDomains,
    array $baseline,
    array $activityCallbackCardinality = [],
): array {
    $surfaceByDomain = [
        'sdk' => 'sdk-php',
        'server' => 'server',
        'package-publication' => 'sdk-php-release',
        'runner' => 'conformance_harness',
    ];
    $operationByAssertion = [
        'exact_sdk_version' => 'artifact.install:durable-workflow/sdk',
        'exact_server_version' => 'cluster.info',
        'sdk_dist_provenance' => 'artifact.provenance:durable-workflow/sdk',
        'official_apache_avro_dependency' => 'artifact.provenance:apache/avro',
        'source_free_composer_project' => 'composer.install',
        'distinct_client_worker_processes' => 'process.boundary:client-worker',
        'distinct_worker_restart_processes' => 'process.boundary:worker-restart',
        'worker_registration' => 'worker.registration',
        'worker_heartbeat' => 'worker.heartbeat',
        'worker_command_contract_readiness' => 'worker.registration.readiness',
        'workflow_started_command_contract' => 'workflow.history:started-contract',
        'start_result' => 'workflow.result:simple',
        'signal_query' => 'workflow.query:current',
        'signal_replay_visibility' => 'workflow.replay:signal-visibility',
        'signal_negative_contracts' => 'workflow.signal:negative-contracts',
        'update' => 'workflow.update:set',
        'cancellation' => 'workflow.result:cancelled',
        'termination' => 'workflow.result:terminated',
        'failure_envelope' => 'workflow.result:failed',
        'activity_callback_once_for_replay' => 'activity.callback:replay',
        'activity_callback_cardinality_by_phase' => 'activity.callback:phase-cardinality',
        'activity_heartbeat_callback' => 'activity.heartbeat',
        'namespace_lifecycle' => 'namespace.lifecycle',
        'namespace_selection' => 'namespace.selection',
        'search_attributes' => 'search-attribute.lifecycle',
        'schedule_lifecycle' => 'schedule.lifecycle',
        'replay_checkpoint' => 'workflow.replay:checkpoint',
        'durable_replay_history' => 'workflow.replay:history',
        'durable_replay_result' => 'workflow.replay:result',
        'local_product_source_checkouts_used_false' => 'artifact.source-policy',
    ];
    $signalQuery = is_array($baseline['signal_query'] ?? null) ? $baseline['signal_query'] : [];
    $workerResponses = is_array($baseline['worker_operation_responses'] ?? null)
        ? $baseline['worker_operation_responses']
        : [];
    $replayCallbackPhase = is_array($activityCallbackCardinality['phase_results']['durable_replay'] ?? null)
        ? $activityCallbackCardinality['phase_results']['durable_replay']
        : [];
    $replayHistoryCheckpoints = is_array($replayCallbackPhase['history_checkpoints'] ?? null)
        ? $replayCallbackPhase['history_checkpoints']
        : [];
    $expectedReplayHistory = [];
    $observedReplayHistory = [];
    foreach ($replayHistoryCheckpoints as $checkpoint => $history) {
        if (! is_array($history)) {
            continue;
        }
        $expectedReplayHistory[$checkpoint] = $history['expected_event_counts'] ?? [];
        $observedReplayHistory[$checkpoint] = $history['observed_event_counts'] ?? [];
    }
    $specialized = [
        'signal_query' => [[
            'operation' => 'workflow.query:current',
            'expected' => [
                'client_signal_commands' => ['signals_sent' => 2, 'accepted_inputs' => [3, 5]],
                'worker_callback_response' => ['inputs' => [3, 5], 'total' => 8],
                'sdk_decoded_response' => ['inputs' => [3, 5], 'total' => 8],
                'server_history_inputs' => [3, 5],
            ],
            'observed' => [
                'client_signal_commands' => [
                    'signals_sent' => $signalQuery['signals_sent'] ?? null,
                    'accepted_inputs' => $signalQuery['accepted_inputs'] ?? null,
                ],
                'worker_callback_response' => $workerResponses['workflow.query:current'] ?? null,
                'sdk_decoded_response' => $signalQuery['query_result'] ?? null,
                'server_history_inputs' => $signalQuery['history_inputs'] ?? null,
            ],
        ]],
        'signal_negative_contracts' => [
            [
                'operation' => 'workflow.signal:undeclared',
                'expected' => ['http_status' => 404, 'reason' => 'unknown_signal'],
                'observed' => $signalQuery['unknown_signal'] ?? null,
            ],
            [
                'operation' => 'workflow.signal:increment_invalid_arguments',
                'expected' => ['http_status' => 422, 'reason' => 'invalid_signal_arguments'],
                'observed' => $signalQuery['invalid_signal_arguments'] ?? null,
            ],
            [
                'operation' => 'workflow.history:addressable_signals',
                'expected' => ['accepted_signal_inputs' => [3, 5]],
                'observed' => ['accepted_signal_inputs' => $signalQuery['history_inputs'] ?? null],
            ],
        ],
        'update' => [[
            'operation' => 'workflow.update:set',
            'expected' => [
                'worker_callback_dispatched' => true,
                'worker_callback_response' => ['accepted' => true, 'value' => 13],
                'sdk_decoded_response' => ['accepted' => true, 'value' => 13],
            ],
            'observed' => [
                'worker_callback_dispatched' => is_array($workerResponses['workflow.update:set'] ?? null),
                'worker_callback_response' => $workerResponses['workflow.update:set'] ?? null,
                'sdk_decoded_response' => $baseline['update']['result'] ?? null,
            ],
        ]],
        'activity_callback_once_for_replay' => [[
            'operation' => 'activity.callback:replay',
            'expected' => [
                'callback_count' => $replayCallbackPhase['expected_callback_count'] ?? 1,
                'distinct_task_ids' => 1,
                'distinct_activity_attempt_ids' => 1,
                'history_event_counts' => $expectedReplayHistory,
            ],
            'observed' => [
                'callback_count' => $replayCallbackPhase['observed_callback_count'] ?? 0,
                'distinct_task_ids' => $replayCallbackPhase['distinct_task_ids'] ?? [],
                'distinct_activity_attempt_ids' => $replayCallbackPhase['distinct_activity_attempt_ids'] ?? [],
                'history_event_counts' => $observedReplayHistory,
            ],
        ]],
        'activity_callback_cardinality_by_phase' => [[
            'operation' => 'activity.callback:phase-cardinality',
            'expected' => [
                'callback_counts_by_phase' => $activityCallbackCardinality['expected_callback_counts_by_phase'] ?? [],
                'all_phases_passed' => true,
            ],
            'observed' => [
                'callback_counts_by_phase' => $activityCallbackCardinality['observed_callback_counts_by_phase'] ?? [],
                'phase_results' => $activityCallbackCardinality['phase_results'] ?? [],
                'all_phases_passed' => $activityCallbackCardinality['passed'] ?? false,
            ],
        ]],
    ];

    $evidence = [];
    foreach ($failedAssertions as $assertion) {
        $domain = $assertionDomains[$assertion] ?? 'sdk';
        $entries = $specialized[$assertion] ?? [[
            'operation' => $operationByAssertion[$assertion] ?? 'conformance.assertion:'.$assertion,
            'expected' => ['assertion_passed' => true],
            'observed' => ['assertion_passed' => false],
        ]];

        foreach ($entries as $entry) {
            $evidence[] = [
                'assertion' => $assertion,
                'operation' => $entry['operation'],
                'classification' => $domain,
                'owning_surface' => $surfaceByDomain[$domain] ?? 'sdk-php',
                'expected' => $entry['expected'],
                'observed' => $entry['observed'],
            ];
        }
    }

    return $evidence;
}
