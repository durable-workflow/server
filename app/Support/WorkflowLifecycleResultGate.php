<?php

namespace App\Support;

/**
 * Evaluates workflow lifecycle conformance results before pass evidence can be
 * counted by the coverage gate.
 */
final class WorkflowLifecycleResultGate
{
    public const SCHEMA = 'durable-workflow.v2.workflow-lifecycle.result-gate';

    public const VERSION = 2;

    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'evaluates_result_schema' => WorkflowLifecycleContract::RESULT_SCHEMA,
            'scenario_statuses_source' => 'workflow_lifecycle_contract.scenario_statuses',
            'required_scenarios_source' => 'workflow_lifecycle_contract.required_scenarios',
            'required_run_record_fields_source' => 'workflow_lifecycle_contract.artifact_policy.required_run_record_fields',
            'artifact_versions_fields' => [
                'artifact_versions',
                'artifactVersions',
                'published_artifact_versions',
                'publishedArtifactVersions',
            ],
            'artifact_sources_fields' => [
                'artifact_sources',
                'artifactSources',
            ],
            'declared_outcome_fields' => [
                'outcome',
                'status',
                'verdict',
            ],
            'scenario_results_fields' => [
                'scenario_results',
                'scenarioResults',
            ],
            'lifecycle_cell_outcomes_fields' => [
                'lifecycle_cell_outcomes',
                'lifecycleCellOutcomes',
            ],
            'local_product_source_truthy_values' => [
                true,
                1,
                '1',
                'true',
                'yes',
                'on',
            ],
            'non_pass_statuses' => [
                'fail',
                'unsupported',
                'not_covered',
                'runner_blocked',
            ],
            'pass_requires' => [
                'every_required_lifecycle_cell_has_one_result',
                'every_result_uses_a_published_status',
                'run_timestamps_outcome_runner_blocked_and_findings_are_recorded',
                'artifact_sources_are_recorded_for_required_artifacts',
                'lifecycle_cell_outcomes_are_recorded_for_required_cells',
                'source_policy_is_recorded',
                'local_product_source_checkouts_used_is_explicitly_false',
                'local_product_source_truthy_values_are_refused_consistently',
                'no_local_product_source_artifacts_are_reported',
                'each_pass_scenario_proves_published_artifact_cell_execution',
                'each_pass_scenario_reports_required_evidence',
                'continue_as_new_chain_reports_distinct_run_ids_and_one_workflow_id',
                'continue_as_new_history_links_predecessor_and_successor_runs',
                'continue_as_new_does_not_duplicate_side_effects',
                'cancellation_reaches_documented_terminal_state_and_typed_errors',
                'termination_reaches_documented_terminal_state_and_typed_errors',
                'duplicate_start_policy_is_enforced_or_refused_clearly',
                'workflow_timeout_records_operator_visible_timing_and_terminal_state',
                'workflow_retry_backoff_is_proven_or_unsupported_retry_refuses_clearly',
                'php_python_and_rust_sdk_cells_pass_or_emit_documented_typed_errors',
                'rust_sdk_shard_uses_exact_crates_io_and_matching_server_artifacts',
                'rust_sdk_timed_out_is_server_terminal_not_client_wait_timeout',
                'rust_sdk_replacement_worker_starts_before_cancelled_activity_settles',
                'rust_sdk_continue_as_new_redelivery_preserves_predecessor_decisions_across_process_replacement',
                'rust_sdk_machine_readable_outcomes_are_semantically_validated',
                'cli_api_history_and_waterline_surfaces_are_operator_diagnostic_enough',
                'non_passing_lifecycle_shards_retain_bounded_portable_diagnostics',
                'each_unsupported_scenario_reports_documented_typed_refusal',
                'each_non_pass_cell_has_focused_findings',
                'overall_outcome_matches_gate_status',
            ],
            'smoke_subset_outcome' => 'non_passing',
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>|null  $contract
     * @return array<string, mixed>
     */
    public static function evaluate(array $result, ?array $contract = null): array
    {
        $contract ??= WorkflowLifecycleContract::manifest();

        $failures = [];
        $requiredScenarios = self::stringList($contract['required_scenarios'] ?? []);
        $allowedStatuses = self::stringList($contract['scenario_statuses'] ?? []);
        $duplicateScenarioCounts = [];
        $scenarioResults = self::scenarioResultsById($result, $duplicateScenarioCounts);
        $scenarioStatuses = [];
        $missingScenarios = [];
        $nonPassScenarios = [];

        foreach ($duplicateScenarioCounts as $scenarioId => $count) {
            $failures[] = [
                'code' => 'duplicate_lifecycle_cell_result',
                'scenario_id' => $scenarioId,
                'count' => $count,
            ];
        }

        foreach ($requiredScenarios as $scenarioId) {
            if (! array_key_exists($scenarioId, $scenarioResults)) {
                $missingScenarios[] = $scenarioId;
                $failures[] = [
                    'code' => 'missing_required_lifecycle_cell',
                    'scenario_id' => $scenarioId,
                ];

                continue;
            }

            $scenarioResult = $scenarioResults[$scenarioId];
            $status = self::normalizedStatus($scenarioResult['status'] ?? null);
            $scenarioStatuses[$scenarioId] = $status;

            if (! in_array($status, $allowedStatuses, true)) {
                $failures[] = [
                    'code' => 'invalid_lifecycle_cell_status',
                    'scenario_id' => $scenarioId,
                    'status' => $status,
                    'allowed_statuses' => $allowedStatuses,
                ];

                continue;
            }

            $cellOutcome = self::lifecycleCellOutcomeStatus($result, $scenarioResult, $scenarioId);
            if ($cellOutcome === '') {
                $failures[] = [
                    'code' => 'missing_lifecycle_cell_outcome',
                    'scenario_id' => $scenarioId,
                ];
            } elseif ($cellOutcome !== $status) {
                $failures[] = [
                    'code' => 'contradictory_lifecycle_cell_outcome',
                    'scenario_id' => $scenarioId,
                    'status' => $status,
                    'lifecycle_cell_outcome' => $cellOutcome,
                ];
            }

            if ($status === 'pass') {
                if (! self::hasObservedOutputs($scenarioResult)) {
                    $failures[] = [
                        'code' => 'missing_pass_observed_outputs',
                        'scenario_id' => $scenarioId,
                    ];
                }

                if (! self::hasPublishedArtifactCellExecution($scenarioResult)) {
                    $failures[] = [
                        'code' => 'missing_published_artifact_cell_execution',
                        'scenario_id' => $scenarioId,
                    ];
                }

                foreach (self::requiredScenarioFields($contract, $scenarioId) as $field) {
                    if (! self::hasScenarioEvidenceField($result, $scenarioResult, $field, $scenarioId)) {
                        $failures[] = [
                            'code' => 'missing_lifecycle_cell_required_field',
                            'scenario_id' => $scenarioId,
                            'field' => $field,
                        ];
                    }
                }

                foreach (self::missingScenarioEvidence($contract, $scenarioId, $scenarioResult) as $field) {
                    $failures[] = [
                        'code' => 'missing_scenario_required_field',
                        'scenario_id' => $scenarioId,
                        'field' => $field,
                    ];
                }

                foreach (self::semanticScenarioFailures($scenarioId, $scenarioResult, $result) as $failure) {
                    $failures[] = [
                        'code' => $failure['code'],
                        'scenario_id' => $scenarioId,
                        'reason' => $failure['reason'],
                    ];
                }
            } elseif ($status === 'unsupported') {
                $nonPassScenarios[] = $scenarioId;
                if (! self::hasDocumentedTypedRefusal($scenarioResult)) {
                    $failures[] = [
                        'code' => 'missing_unsupported_typed_refusal',
                        'scenario_id' => $scenarioId,
                    ];
                }

                if (! self::hasFocusedFinding($result, $scenarioResult, $scenarioId)) {
                    $failures[] = [
                        'code' => 'missing_focused_finding_for_non_pass_cell',
                        'scenario_id' => $scenarioId,
                        'status' => $status,
                    ];
                }
            } else {
                $nonPassScenarios[] = $scenarioId;
                if (! self::hasFocusedFinding($result, $scenarioResult, $scenarioId)) {
                    $failures[] = [
                        'code' => 'missing_focused_finding_for_non_pass_cell',
                        'scenario_id' => $scenarioId,
                        'status' => $status,
                    ];
                }
            }

            if ($status !== 'pass') {
                array_push(
                    $failures,
                    ...self::lifecycleShardDiagnosticFailures($contract, $scenarioId, $scenarioResult),
                );
            }
        }

        $reportedScenarioIds = array_keys($scenarioResults);
        $unknownScenarios = array_values(array_diff($reportedScenarioIds, $requiredScenarios));
        foreach ($unknownScenarios as $scenarioId) {
            $status = self::normalizedStatus($scenarioResults[$scenarioId]['status'] ?? null);
            if (! in_array($status, $allowedStatuses, true)) {
                $failures[] = [
                    'code' => 'invalid_extra_lifecycle_cell_status',
                    'scenario_id' => $scenarioId,
                    'status' => $status,
                    'allowed_statuses' => $allowedStatuses,
                ];
            }
        }

        array_push($failures, ...self::runRecordFailures($result, $contract));
        array_push($failures, ...self::declaredOutcomeFailures($result));
        array_push($failures, ...self::artifactVersionFailures($result, $contract));
        array_push($failures, ...self::sourcePolicyFailures($result, $contract, $scenarioResults));

        $evidencePasses = $failures === []
            && $missingScenarios === []
            && $nonPassScenarios === []
            && count($scenarioStatuses) >= count($requiredScenarios);
        $evaluatedStatus = $evidencePasses ? 'pass' : 'non_passing';

        array_push($failures, ...self::declaredOutcomeStatusFailures($result, $evaluatedStatus));

        $passes = $evaluatedStatus === 'pass' && $failures === [];

        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'status' => $passes ? 'pass' : 'non_passing',
            'required_scenarios' => $requiredScenarios,
            'reported_scenarios' => $reportedScenarioIds,
            'missing_scenarios' => $missingScenarios,
            'non_pass_scenarios' => $nonPassScenarios,
            'unknown_scenarios' => $unknownScenarios,
            'duplicate_scenarios' => $duplicateScenarioCounts,
            'scenario_statuses' => $scenarioStatuses,
            'gate_failures' => $failures,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, int>  $duplicateScenarioCounts
     * @return array<string, array<string, mixed>>
     */
    private static function scenarioResultsById(array $result, array &$duplicateScenarioCounts): array
    {
        $raw = self::arrayField($result, ['scenario_results', 'scenarioResults']) ?? [];
        $results = [];
        $seen = [];

        foreach ($raw as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $scenarioId = is_string($key) ? $key : self::stringValue($value['scenario_id'] ?? $value['id'] ?? null);
            if ($scenarioId === '') {
                continue;
            }

            if (isset($seen[$scenarioId])) {
                $duplicateScenarioCounts[$scenarioId] = ($duplicateScenarioCounts[$scenarioId] ?? 1) + 1;
            } else {
                $seen[$scenarioId] = true;
            }

            $value['scenario_id'] = $scenarioId;
            $results[$scenarioId] = $value;
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $contract
     * @param  array<string, mixed>  $scenarioResult
     * @return list<array<string, mixed>>
     */
    private static function lifecycleShardDiagnosticFailures(
        array $contract,
        string $scenarioId,
        array $scenarioResult,
    ): array {
        $diagnosticContract = $contract['host_runner_contract']['lifecycle_shard_diagnostics'] ?? [];
        if (! is_array($diagnosticContract)
            || ! in_array($scenarioId, self::stringList($diagnosticContract['non_pass_shards'] ?? []), true)) {
            return [];
        }

        $outputs = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']) ?? [];
        $diagnostic = self::arrayField($outputs, ['shard_diagnostic', 'shardDiagnostic']);
        if ($diagnostic === null) {
            return [[
                'code' => 'missing_lifecycle_shard_diagnostic',
                'scenario_id' => $scenarioId,
            ]];
        }

        $failures = [];
        if (self::stringValue($diagnostic['schema'] ?? null)
            !== self::stringValue($diagnosticContract['schema'] ?? null)) {
            $failures[] = [
                'code' => 'invalid_lifecycle_shard_diagnostic_schema',
                'scenario_id' => $scenarioId,
            ];
        }
        if (self::stringValue($diagnostic['retention'] ?? null) !== 'inline_result_and_record') {
            $failures[] = [
                'code' => 'non_portable_lifecycle_shard_diagnostic',
                'scenario_id' => $scenarioId,
            ];
        }
        foreach (self::stringList($diagnosticContract['required_fields'] ?? []) as $field) {
            if (! self::isEmptyEvidence(self::fieldValue($diagnostic, $field))) {
                continue;
            }
            $failures[] = [
                'code' => 'incomplete_lifecycle_shard_diagnostic',
                'scenario_id' => $scenarioId,
                'field' => $field,
            ];
        }
        if (array_key_exists('path', $diagnostic)
            || self::arrayHasKeyRecursively($diagnostic, 'workspace_path')
            || self::arrayHasKeyRecursively($diagnostic, 'diagnostic_file')) {
            $failures[] = [
                'code' => 'workspace_path_used_as_lifecycle_shard_diagnostic',
                'scenario_id' => $scenarioId,
            ];
        }
        if (self::stringValue($diagnostic['failure_stage'] ?? null) === 'runtime_assertions'
            && ($diagnosticContract['assertion_expected_and_observed_per_failed_operation_retained'] ?? false) === true) {
            $assertionFailures = self::arrayField($diagnostic, ['assertion_failures', 'assertionFailures']) ?? [];
            $operations = is_array($assertionFailures['operations'] ?? null)
                ? array_values($assertionFailures['operations'])
                : [];
            if ($operations === []) {
                $failures[] = [
                    'code' => 'missing_lifecycle_assertion_failure_evidence',
                    'scenario_id' => $scenarioId,
                ];
            }
            foreach ($operations as $index => $operation) {
                if (! is_array($operation)) {
                    $failures[] = [
                        'code' => 'incomplete_lifecycle_assertion_failure_evidence',
                        'scenario_id' => $scenarioId,
                        'operation_index' => $index,
                    ];

                    continue;
                }
                foreach (['assertion', 'operation', 'owning_surface', 'expected'] as $field) {
                    if (! self::isEmptyEvidence($operation[$field] ?? null)) {
                        continue;
                    }
                    $failures[] = [
                        'code' => 'incomplete_lifecycle_assertion_failure_evidence',
                        'scenario_id' => $scenarioId,
                        'operation_index' => $index,
                        'field' => $field,
                    ];
                }
                if (! array_key_exists('observed', $operation)) {
                    $failures[] = [
                        'code' => 'incomplete_lifecycle_assertion_failure_evidence',
                        'scenario_id' => $scenarioId,
                        'operation_index' => $index,
                        'field' => 'observed',
                    ];
                }
            }
            if (($assertionFailures['count'] ?? null) !== count($operations)) {
                $failures[] = [
                    'code' => 'invalid_lifecycle_assertion_failure_count',
                    'scenario_id' => $scenarioId,
                ];
            }
        }

        $diagnosticCompanion = self::arrayField($diagnostic, ['companion_failure', 'companionFailure']) ?? [];
        $failureKind = self::stringValue(
            $outputs['failure_kind']
                ?? $outputs['failureKind']
                ?? $diagnosticCompanion['failure_kind']
                ?? $diagnosticCompanion['failureKind']
                ?? null,
        );
        $runtimeFailure = self::arrayField($outputs, ['runtime_failure_evidence', 'runtimeFailureEvidence']) ?? [];
        $runtimeText = strtolower((string) json_encode(
            [
                $runtimeFailure['exception_type'] ?? null,
                $runtimeFailure['message'] ?? null,
                $runtimeFailure['public_error_envelope'] ?? null,
            ],
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
        $requiresCompanion = $scenarioId === 'php_sdk_lifecycle_surface'
            && ($diagnosticContract['client_timeout_and_unavailable_worker_companion_retained'] ?? false) === true
            && (in_array($failureKind, ['client_timeout', 'worker_unavailable'], true)
                || preg_match('/workflowtimedout|worker.{0,24}unavailable|no[_ -](?:active|compatible)[_ -]workers?/', $runtimeText) === 1);
        if ($requiresCompanion) {
            $companion = self::arrayField($diagnostic, ['companion_failure', 'companionFailure']);
            if ($companion === null) {
                $failures[] = [
                    'code' => 'missing_lifecycle_timeout_companion_evidence',
                    'scenario_id' => $scenarioId,
                ];
            } else {
                if (self::stringValue($companion['schema'] ?? null)
                    !== self::stringValue($diagnosticContract['companion_diagnostic_schema'] ?? null)) {
                    $failures[] = [
                        'code' => 'invalid_lifecycle_timeout_companion_schema',
                        'scenario_id' => $scenarioId,
                    ];
                }
                foreach (['classification_basis', 'owning_surface'] as $field) {
                    if (! self::isEmptyEvidence($companion[$field] ?? null)) {
                        continue;
                    }
                    $failures[] = [
                        'code' => 'incomplete_lifecycle_timeout_companion_evidence',
                        'scenario_id' => $scenarioId,
                        'field' => $field,
                    ];
                }
                if (($companion['retained_after_cleanup'] ?? null) !== true) {
                    $failures[] = [
                        'code' => 'non_portable_lifecycle_timeout_companion_evidence',
                        'scenario_id' => $scenarioId,
                    ];
                }
                $worker = self::arrayField($companion, ['worker']) ?? [];
                $processState = self::arrayField($worker, ['process_state', 'processState']) ?? [];
                $structuredStderr = self::arrayField($worker, ['structured_stderr', 'structuredStderr']) ?? [];
                foreach (['state', 'alive', 'exit_code'] as $field) {
                    if (array_key_exists($field, $processState)) {
                        continue;
                    }
                    $failures[] = [
                        'code' => 'incomplete_lifecycle_timeout_worker_state',
                        'scenario_id' => $scenarioId,
                        'field' => $field,
                    ];
                }
                if (self::isEmptyEvidence($structuredStderr['excerpt'] ?? null)) {
                    $failures[] = [
                        'code' => 'missing_lifecycle_timeout_worker_stderr',
                        'scenario_id' => $scenarioId,
                    ];
                }
                if (! array_key_exists('last_protocol_failure', $worker)
                    && ! array_key_exists('lastProtocolFailure', $worker)) {
                    $failures[] = [
                        'code' => 'missing_lifecycle_timeout_worker_protocol_state',
                        'scenario_id' => $scenarioId,
                    ];
                }
                if (($diagnosticContract['worker_runtime_exception_separate_from_protocol_failure'] ?? false) === true
                    && ! array_key_exists('last_runtime_exception', $worker)
                    && ! array_key_exists('lastRuntimeException', $worker)) {
                    $failures[] = [
                        'code' => 'missing_lifecycle_timeout_worker_runtime_exception_state',
                        'scenario_id' => $scenarioId,
                    ];
                }

                $server = self::arrayField($companion, ['server']) ?? [];
                $health = self::arrayField($server, ['health']) ?? [];
                $healthStatus = $health['http_status'] ?? $health['httpStatus'] ?? null;
                if (! array_key_exists('http_status', $health) && ! array_key_exists('httpStatus', $health)) {
                    $failures[] = [
                        'code' => 'missing_lifecycle_timeout_server_health',
                        'scenario_id' => $scenarioId,
                    ];
                }
                if (($processState['alive'] ?? null) === true
                    && is_int($healthStatus)
                    && $healthStatus >= 200
                    && $healthStatus < 300) {
                    foreach (['run_state', 'history'] as $field) {
                        $probe = self::arrayField($server, [$field]) ?? [];
                        $status = $probe['http_status'] ?? $probe['httpStatus'] ?? null;
                        if (is_int($status) && $status >= 200 && $status < 300
                            && ! self::isEmptyEvidence($probe['payload'] ?? null)) {
                            continue;
                        }
                        $failures[] = [
                            'code' => 'incomplete_lifecycle_timeout_server_run_state',
                            'scenario_id' => $scenarioId,
                            'field' => $field,
                        ];
                    }
                    $taskQueue = self::arrayField($server, ['task_queue', 'taskQueue']) ?? [];
                    $taskQueueStatus = $taskQueue['http_status'] ?? $taskQueue['httpStatus'] ?? null;
                    $taskQueuePayload = self::arrayField($taskQueue, ['payload']) ?? [];
                    if (! is_int($taskQueueStatus)
                        || $taskQueueStatus < 200
                        || $taskQueueStatus >= 300) {
                        $failures[] = [
                            'code' => 'incomplete_lifecycle_timeout_server_task_queue',
                            'scenario_id' => $scenarioId,
                            'field' => 'http_status',
                        ];
                    }
                    foreach (['name', 'stats', 'pollers', 'current_leases', 'admission'] as $field) {
                        if (array_key_exists($field, $taskQueuePayload)) {
                            continue;
                        }
                        $failures[] = [
                            'code' => 'incomplete_lifecycle_timeout_server_task_queue',
                            'scenario_id' => $scenarioId,
                            'field' => $field,
                        ];
                    }
                }
                $protocolFailure = self::arrayField($worker, ['last_protocol_failure', 'lastProtocolFailure']);
                $runtimeException = self::arrayField($worker, ['last_runtime_exception', 'lastRuntimeException']);
                if (($diagnosticContract['worker_runtime_exception_separate_from_protocol_failure'] ?? false) === true
                    && $runtimeException !== null) {
                    if ($protocolFailure !== null) {
                        $failures[] = [
                            'code' => 'conflicting_lifecycle_worker_failure_kinds',
                            'scenario_id' => $scenarioId,
                        ];
                    }
                    $requiredRuntimeFields = $diagnosticContract['worker_runtime_exception_required_fields'] ?? [];
                    foreach (is_array($requiredRuntimeFields) ? $requiredRuntimeFields : [] as $field) {
                        if (is_string($field) && ! self::isEmptyEvidence($runtimeException[$field] ?? null)) {
                            continue;
                        }
                        $failures[] = [
                            'code' => 'incomplete_lifecycle_worker_runtime_exception',
                            'scenario_id' => $scenarioId,
                            'field' => $field,
                        ];
                    }
                    $runtimeStatus = $runtimeException['status_code'] ?? $runtimeException['statusCode'] ?? null;
                    if (is_int($runtimeStatus) && $runtimeStatus >= 400 && $runtimeStatus <= 599) {
                        $failures[] = [
                            'code' => 'http_failure_stored_as_lifecycle_worker_runtime_exception',
                            'scenario_id' => $scenarioId,
                        ];
                    }
                }
                if (($diagnosticContract['structured_worker_protocol_failure_required'] ?? false) === true
                    && $protocolFailure !== null) {
                    $requiredProtocolFields = $diagnosticContract['worker_protocol_failure_required_fields'] ?? [];
                    foreach (is_array($requiredProtocolFields) ? $requiredProtocolFields : [] as $field) {
                        if (is_string($field) && array_key_exists($field, $protocolFailure)) {
                            continue;
                        }
                        $failures[] = [
                            'code' => 'incomplete_lifecycle_worker_protocol_failure',
                            'scenario_id' => $scenarioId,
                            'field' => $field,
                        ];
                    }
                    foreach (['operation', 'http_method', 'endpoint_class'] as $field) {
                        if (! self::isEmptyEvidence($protocolFailure[$field] ?? null)
                            && ($field !== 'endpoint_class'
                                || self::stringValue($protocolFailure[$field] ?? null) !== 'unknown')) {
                            continue;
                        }
                        $failures[] = [
                            'code' => 'incomplete_lifecycle_worker_protocol_failure',
                            'scenario_id' => $scenarioId,
                            'field' => $field,
                        ];
                    }
                    $protocolStatus = $protocolFailure['status_code'] ?? $protocolFailure['statusCode'] ?? null;
                    if (! is_int($protocolStatus) || $protocolStatus < 400 || $protocolStatus > 599) {
                        $failures[] = [
                            'code' => 'incomplete_lifecycle_worker_protocol_failure',
                            'scenario_id' => $scenarioId,
                            'field' => 'status_code',
                        ];
                    }
                    $publicResponse = self::arrayField(
                        $protocolFailure,
                        ['public_error_envelope', 'publicErrorEnvelope'],
                    ) ?? [];
                    $publicFields = array_diff(
                        array_keys($publicResponse),
                        ['_truncated', '_bounded_json_excerpt', 'bounded_json_excerpt'],
                    );
                    if ($publicFields === []) {
                        $failures[] = [
                            'code' => 'opaque_lifecycle_worker_protocol_response',
                            'scenario_id' => $scenarioId,
                        ];
                    }
                    if (array_key_exists('bounded_json_excerpt', $protocolFailure)
                        || array_key_exists('_bounded_json_excerpt', $protocolFailure)) {
                        $failures[] = [
                            'code' => 'opaque_lifecycle_worker_protocol_failure',
                            'scenario_id' => $scenarioId,
                        ];
                    }
                    $diagnosticHttp = self::arrayField($diagnostic, ['http']) ?? [];
                    if (is_int($protocolStatus)
                        && ($diagnosticHttp['status'] ?? null) !== $protocolStatus) {
                        $failures[] = [
                            'code' => 'lifecycle_worker_protocol_status_not_promoted',
                            'scenario_id' => $scenarioId,
                        ];
                    }
                    $genericReason = str_replace(
                        ' ',
                        '_',
                        self::normalizedStatus(
                            $protocolFailure['reason']
                                ?? $publicResponse['reason']
                                ?? $publicResponse['error']
                                ?? $publicResponse['code']
                                ?? null,
                        ),
                    );
                    $genericServerResponse = is_int($protocolStatus)
                        && $protocolStatus >= 500
                        && ($genericReason === ''
                            || in_array($genericReason, [
                                'error',
                                'server_error',
                                'internal_error',
                                'internal_server_error',
                                'request_failed',
                                'unknown_error',
                            ], true));
                    if (($diagnosticContract['generic_protocol_failure_server_error_record_required'] ?? false) === true
                        && $genericServerResponse) {
                        $errorRecord = self::arrayField($server, ['error_record', 'errorRecord']) ?? [];
                        if (($server['error_record_required'] ?? $server['errorRecordRequired'] ?? null) !== true) {
                            $failures[] = [
                                'code' => 'missing_lifecycle_server_error_record_requirement',
                                'scenario_id' => $scenarioId,
                            ];
                        }
                        foreach (['source', 'matched_by', 'level', 'excerpt'] as $field) {
                            if (! self::isEmptyEvidence($errorRecord[$field] ?? null)) {
                                continue;
                            }
                            $failures[] = [
                                'code' => 'missing_lifecycle_server_error_record',
                                'scenario_id' => $scenarioId,
                                'field' => $field,
                            ];
                        }
                        if (($diagnosticContract['generic_protocol_failure_correlated_error_severity_required'] ?? false) === true) {
                            $errorLevel = strtoupper(self::stringValue($errorRecord['level'] ?? null));
                            if (! in_array($errorLevel, ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true)) {
                                $failures[] = [
                                    'code' => 'invalid_lifecycle_server_error_record_severity',
                                    'scenario_id' => $scenarioId,
                                    'level' => $errorLevel,
                                ];
                            }
                            $matchedBy = self::stringValue($errorRecord['matched_by'] ?? $errorRecord['matchedBy'] ?? null);
                            if (! str_contains($matchedBy, 'error_severity')) {
                                $failures[] = [
                                    'code' => 'invalid_lifecycle_server_error_record_match',
                                    'scenario_id' => $scenarioId,
                                    'matched_by' => $matchedBy,
                                ];
                            }
                            $protocolIdentifiers = array_values(array_filter([
                                self::stringValue($protocolFailure['task_id'] ?? null),
                                self::stringValue($protocolFailure['workflow_id'] ?? null),
                                self::stringValue($protocolFailure['run_id'] ?? null),
                            ], static fn (string $value): bool => $value !== ''));
                            $recordIdentifiers = array_values(array_filter([
                                self::stringValue($errorRecord['task_id'] ?? null),
                                self::stringValue($errorRecord['workflow_id'] ?? null),
                                self::stringValue($errorRecord['run_id'] ?? null),
                            ], static fn (string $value): bool => $value !== ''));
                            $identifierCorrelated = array_intersect($protocolIdentifiers, $recordIdentifiers) !== [];
                            $statusCorrelated = $protocolIdentifiers === []
                                && is_int($errorRecord['status_code'] ?? null)
                                && ($errorRecord['status_code'] ?? null) === $protocolStatus;
                            if (! $identifierCorrelated && ! $statusCorrelated) {
                                $failures[] = [
                                    'code' => 'uncorrelated_lifecycle_server_error_record',
                                    'scenario_id' => $scenarioId,
                                ];
                            }
                        }
                    }
                }
                if (($processState['alive'] ?? null) === false
                    && self::stringValue($protocolFailure['classification'] ?? null) === 'server') {
                    $processLog = self::arrayField($server, ['process_log', 'processLog']) ?? [];
                    if (self::isEmptyEvidence($processLog['excerpt'] ?? null)) {
                        $failures[] = [
                            'code' => 'missing_lifecycle_timeout_server_process_log',
                            'scenario_id' => $scenarioId,
                        ];
                    }
                }
                if (($diagnosticContract['companion_evidence_led_ownership_required'] ?? false) === true
                    && self::stringValue($diagnostic['owning_surface'] ?? null)
                        !== self::stringValue($companion['owning_surface'] ?? null)) {
                    $failures[] = [
                        'code' => 'lifecycle_timeout_ownership_ignores_companion_evidence',
                        'scenario_id' => $scenarioId,
                    ];
                }
                if (($diagnosticContract['companion_evidence_led_ownership_required'] ?? false) === true
                    && ($processState['alive'] ?? null) === true
                    && $protocolFailure === null) {
                    $runTerminal = self::companionRunTerminal($server);
                    $expectedSurface = $runTerminal === true
                        ? 'sdk-php'
                        : ($runTerminal === false ? 'server' : null);
                    if ($expectedSurface !== null
                        && self::stringValue($companion['owning_surface'] ?? null) !== $expectedSurface) {
                        $failures[] = [
                            'code' => 'lifecycle_timeout_ownership_ignores_terminal_run_state',
                            'scenario_id' => $scenarioId,
                            'run_terminal' => $runTerminal,
                            'expected_owning_surface' => $expectedSurface,
                        ];
                    }
                }
                $encodedCompanion = json_encode(
                    $companion,
                    JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                );
                $companionMaxBytes = (int) ($diagnosticContract['companion_diagnostic_max_bytes'] ?? 0);
                if (! is_string($encodedCompanion)
                    || $companionMaxBytes <= 0
                    || strlen($encodedCompanion) > $companionMaxBytes) {
                    $failures[] = [
                        'code' => 'oversized_lifecycle_timeout_companion_evidence',
                        'scenario_id' => $scenarioId,
                        'max_bytes' => $companionMaxBytes,
                    ];
                }
            }
        }

        $encoded = json_encode(
            $diagnostic,
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        $maxBytes = (int) ($diagnosticContract['max_bytes_per_shard'] ?? 0);
        if (! is_string($encoded) || $maxBytes <= 0 || strlen($encoded) > $maxBytes) {
            $failures[] = [
                'code' => 'oversized_lifecycle_shard_diagnostic',
                'scenario_id' => $scenarioId,
                'max_bytes' => $maxBytes,
                'observed_bytes' => is_string($encoded) ? strlen($encoded) : null,
            ];
        }

        return $failures;
    }

    /** @param array<string, mixed> $server */
    private static function companionRunTerminal(array $server): ?bool
    {
        $runProbe = self::arrayField($server, ['run_state', 'runState']) ?? [];
        $runStatus = $runProbe['http_status'] ?? $runProbe['httpStatus'] ?? null;
        if (! is_int($runStatus) || $runStatus < 200 || $runStatus >= 300) {
            return null;
        }

        $run = self::arrayField($runProbe, ['payload']) ?? [];
        if (($run['is_terminal'] ?? $run['isTerminal'] ?? null) === true) {
            return true;
        }
        $status = self::normalizedStatus($run['status'] ?? null);
        if (in_array($status, ['cancelled', 'completed', 'failed', 'terminated', 'timed_out'], true)) {
            return true;
        }

        $historyProbe = self::arrayField($server, ['history']) ?? [];
        $historyStatus = $historyProbe['http_status'] ?? $historyProbe['httpStatus'] ?? null;
        $history = self::arrayField($historyProbe, ['payload']) ?? [];
        if (is_int($historyStatus) && $historyStatus >= 200 && $historyStatus < 300) {
            $eventTypes = is_array($history['last_event_types'] ?? null)
                ? $history['last_event_types']
                : [];
            foreach (is_array($history['last_events'] ?? null) ? $history['last_events'] : [] as $event) {
                if (is_array($event)) {
                    $eventTypes[] = $event['event_type'] ?? $event['type'] ?? null;
                }
            }
            foreach ($eventTypes as $eventType) {
                if (in_array(self::stringValue($eventType), [
                    'WorkflowCancelled',
                    'WorkflowCompleted',
                    'WorkflowContinuedAsNew',
                    'WorkflowFailed',
                    'WorkflowTerminated',
                    'WorkflowTimedOut',
                ], true)) {
                    return true;
                }
            }
        }

        if (($run['is_terminal'] ?? $run['isTerminal'] ?? null) === false
            || in_array($status, ['pending', 'running', 'waiting'], true)) {
            return false;
        }

        return null;
    }

    /** @param array<string, mixed> $value */
    private static function arrayHasKeyRecursively(array $value, string $expected): bool
    {
        foreach ($value as $key => $entry) {
            if (is_string($key) && strtolower($key) === strtolower($expected)) {
                return true;
            }
            if (is_array($entry) && self::arrayHasKeyRecursively($entry, $expected)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return list<string>
     */
    private static function requiredScenarioFields(array $contract, string $scenarioId): array
    {
        $requirements = $contract['scenario_requirements'][$scenarioId] ?? [];

        return self::stringList(is_array($requirements) ? ($requirements['required_fields'] ?? []) : []);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $scenarioResult
     */
    private static function hasScenarioEvidenceField(
        array $result,
        array $scenarioResult,
        string $field,
        string $scenarioId,
    ): bool {
        $outputs = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']) ?? [];

        return match ($field) {
            'observed_outputs' => ! self::isEmptyEvidence($outputs),
            'lifecycle_cell_outcome' => self::lifecycleCellOutcomeStatus($result, $scenarioResult, $scenarioId) !== '',
            'artifact_sources' => ! self::isEmptyEvidence(
                self::arrayField($scenarioResult, ['artifact_sources', 'artifactSources'])
                    ?? self::arrayField($outputs, ['artifact_sources', 'artifactSources'])
                    ?? self::arrayField($result, ['artifact_sources', 'artifactSources']),
            ),
            'local_product_source_checkouts_used' => self::hasExplicitFalseLocalProductSourceFlag(
                $scenarioResult,
                $outputs,
                $result,
            ),
            'source_policy' => ! self::isEmptyEvidence(
                self::arrayField($scenarioResult, ['source_policy', 'sourcePolicy'])
                    ?? self::arrayField($outputs, ['source_policy', 'sourcePolicy'])
                    ?? self::arrayField($result, ['source_policy', 'sourcePolicy']),
            ),
            default => ! self::isEmptyEvidence(
                self::fieldValue($scenarioResult, $field)
                    ?? self::fieldValue($outputs, $field)
                    ?? self::fieldValue($result, $field),
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     * @return list<array<string, mixed>>
     */
    private static function runRecordFailures(array $result, array $contract): array
    {
        $failures = [];

        foreach (self::stringList($contract['artifact_policy']['required_run_record_fields'] ?? []) as $field) {
            if (self::hasRunRecordField($result, $field)) {
                continue;
            }

            $failures[] = [
                'code' => 'missing_run_record_field',
                'field' => $field,
            ];
        }

        $runnerBlocked = self::firstFieldValue($result, ['runner_blocked', 'runnerBlocked']);
        if ($runnerBlocked !== null && ! self::explicitFalse($runnerBlocked)) {
            $failures[] = [
                'code' => 'runner_blocked_result_is_not_product_evidence',
                'value' => $runnerBlocked,
            ];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private static function hasRunRecordField(array $result, string $field): bool
    {
        return match ($field) {
            'artifact_versions' => ! self::isEmptyEvidence(self::arrayField($result, [
                'artifact_versions',
                'artifactVersions',
            ])),
            'published_artifact_versions' => ! self::isEmptyEvidence(self::arrayField($result, [
                'published_artifact_versions',
                'publishedArtifactVersions',
            ])),
            'artifact_sources' => ! self::isEmptyEvidence(self::arrayField($result, [
                'artifact_sources',
                'artifactSources',
            ])),
            'started_at' => self::hasScalarField($result, ['started_at', 'startedAt']),
            'finished_at' => self::hasScalarField($result, ['finished_at', 'finishedAt']),
            'generated_at' => self::hasScalarField($result, ['generated_at', 'generatedAt']),
            'outcome' => self::declaredOutcomeTokens($result) !== [],
            'runner_blocked' => array_key_exists('runner_blocked', $result) || array_key_exists('runnerBlocked', $result),
            'scenario_results' => self::arrayField($result, ['scenario_results', 'scenarioResults']) !== null,
            'lifecycle_cell_outcomes' => ! self::isEmptyEvidence(self::arrayField($result, [
                'lifecycle_cell_outcomes',
                'lifecycleCellOutcomes',
            ])),
            'findings' => array_key_exists('findings', $result) && is_array($result['findings']),
            'local_product_source_checkouts_used' => self::hasAnyField($result, self::localProductSourceFlagFields()),
            'source_policy' => ! self::isEmptyEvidence(self::arrayField($result, ['source_policy', 'sourcePolicy'])),
            default => ! self::isEmptyEvidence(self::fieldValue($result, $field)),
        };
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private static function declaredOutcomeFailures(array $result): array
    {
        $tokens = self::declaredOutcomeTokens($result);
        if ($tokens === []) {
            return [[
                'code' => 'missing_declared_outcome',
            ]];
        }

        $normalized = array_values(array_unique(array_map(
            static fn (string $token): string => self::outcomeStatus($token),
            array_values($tokens),
        )));

        if (count($normalized) <= 1) {
            return [];
        }

        return [[
            'code' => 'contradictory_declared_outcome',
            'declared_outcomes' => $tokens,
        ]];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private static function declaredOutcomeStatusFailures(array $result, string $evaluatedStatus): array
    {
        $tokens = self::declaredOutcomeTokens($result);
        if ($tokens === []) {
            return [];
        }

        $declaredStatus = self::outcomeStatus(reset($tokens));
        if ($declaredStatus === $evaluatedStatus) {
            return [];
        }

        return [[
            'code' => 'declared_outcome_mismatch',
            'declared_outcome' => reset($tokens),
            'evaluated_status' => $evaluatedStatus,
        ]];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, string>
     */
    private static function declaredOutcomeTokens(array $result): array
    {
        $tokens = [];
        foreach (['outcome', 'status', 'verdict'] as $field) {
            $value = self::stringValue($result[$field] ?? null);
            if ($value !== '') {
                $tokens[$field] = self::normalizedStatus($value);
            }
        }

        return $tokens;
    }

    private static function outcomeStatus(string $value): string
    {
        return match (self::normalizedStatus($value)) {
            'pass', 'passed', 'success', 'succeeded' => 'pass',
            default => 'non_passing',
        };
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     * @return list<array<string, mixed>>
     */
    private static function artifactVersionFailures(array $result, array $contract): array
    {
        $failures = [];
        $requiredArtifacts = self::requiredArtifacts($contract);

        foreach ([
            'artifact_versions' => ['artifact_versions', 'artifactVersions'],
            'published_artifact_versions' => ['published_artifact_versions', 'publishedArtifactVersions'],
        ] as $field => $aliases) {
            $versions = self::arrayField($result, $aliases);
            if ($versions === null) {
                continue;
            }

            foreach ($requiredArtifacts as $artifact) {
                $version = self::artifactValue($versions, $artifact, $contract);
                if ($version === '') {
                    $failures[] = [
                        'code' => 'missing_artifact_version',
                        'field' => $field,
                        'artifact' => $artifact,
                    ];

                    continue;
                }

                if (self::isPlaceholderVersion($version)) {
                    $failures[] = [
                        'code' => 'placeholder_artifact_version',
                        'field' => $field,
                        'artifact' => $artifact,
                        'version' => $version,
                    ];
                }
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     * @param  array<string, array<string, mixed>>  $scenarioResults
     * @return list<array<string, mixed>>
     */
    private static function sourcePolicyFailures(array $result, array $contract, array $scenarioResults): array
    {
        $failures = [];
        $sourcePolicy = self::arrayField($result, ['source_policy', 'sourcePolicy']);

        if ($sourcePolicy === null || $sourcePolicy === []) {
            $failures[] = [
                'code' => 'missing_source_policy',
            ];
        } else {
            if (self::truthyField($sourcePolicy, self::localProductSourcePolicyTruthFields())) {
                $failures[] = [
                    'code' => 'local_product_source_checkout_used',
                    'field' => 'source_policy',
                    'value' => self::firstFieldValue($sourcePolicy, self::localProductSourcePolicyTruthFields()),
                ];
            }

            if (self::truthyField($sourcePolicy, [
                'allows_local_product_source_checkout_pass_evidence',
                'allowsLocalProductSourceCheckoutPassEvidence',
                'local_product_source_checkout_allowed_as_pass_evidence',
                'localProductSourceCheckoutAllowedAsPassEvidence',
            ])) {
                $failures[] = [
                    'code' => 'source_policy_allows_local_product_source_pass_evidence',
                ];
            }

            $publishedArtifactOnlyFields = [
                'published_artifacts_only',
                'publishedArtifactsOnly',
                'published_artifact_evidence_only',
                'publishedArtifactEvidenceOnly',
            ];
            if (! self::allReportedFieldsTruthy($sourcePolicy, $publishedArtifactOnlyFields)) {
                $failures[] = [
                    'code' => 'source_policy_must_require_published_artifacts',
                    'value' => self::firstFieldValue($sourcePolicy, $publishedArtifactOnlyFields),
                ];
            }

            if (! self::hasExplicitFalseLocalProductSourceFlag($sourcePolicy)) {
                $failures[] = [
                    'code' => 'local_product_source_checkouts_used_must_be_false',
                    'field' => 'source_policy.local_product_source_checkouts_used',
                    'value' => self::firstFieldValue($sourcePolicy, self::localProductSourceFlagFields()),
                ];
            }
        }

        foreach (self::localProductSourceFlagReports($result, $scenarioResults) as $flag) {
            if (! self::truthy($flag['value'])) {
                continue;
            }

            $failure = [
                'code' => 'local_product_source_checkout_used',
                'field' => $flag['field'],
                'value' => $flag['value'],
            ];
            if ($flag['scenario_id'] !== null) {
                $failure['scenario_id'] = $flag['scenario_id'];
            }

            $failures[] = $failure;
        }

        if (! self::hasExplicitFalseLocalProductSourceFlag($result)) {
            $failures[] = [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'field' => 'local_product_source_checkouts_used',
                'value' => self::firstFieldValue($result, self::localProductSourceFlagFields()),
            ];
        }

        $sourceSets = self::reportedArtifactSourceSets($result, $scenarioResults);
        if ($sourceSets === []) {
            $failures[] = [
                'code' => 'missing_artifact_sources',
            ];

            return $failures;
        }

        $requiredArtifacts = self::requiredArtifacts($contract);
        $installSources = [];
        foreach ($sourceSets as $sourceSet) {
            if (($sourceSet['counts_for_required_sources'] ?? false) !== true) {
                continue;
            }

            foreach ($sourceSet['sources'] as $artifact => $source) {
                if (! is_string($artifact)) {
                    continue;
                }

                if (self::sourceValueRecorded($source) || ! array_key_exists($artifact, $installSources)) {
                    $installSources[$artifact] = $source;
                }
            }
        }

        foreach ($requiredArtifacts as $artifact) {
            if (self::sourceValueRecorded($installSources[$artifact] ?? null)) {
                continue;
            }

            $failures[] = [
                'code' => 'missing_artifact_source',
                'artifact' => $artifact,
            ];
        }

        $forbiddenSources = self::stringList($contract['artifact_policy']['forbidden_sources'] ?? []);
        foreach ($sourceSets as $sourceSet) {
            foreach ($sourceSet['sources'] as $artifact => $source) {
                $sourceString = self::sourceString($source);
                foreach ($forbiddenSources as $forbiddenSource) {
                    $forbiddenSource = strtolower(trim($forbiddenSource));
                    if ($forbiddenSource === '' || ! str_contains(strtolower($sourceString), $forbiddenSource)) {
                        continue;
                    }

                    $failure = [
                        'code' => 'forbidden_artifact_source',
                        'artifact' => is_string($artifact) ? $artifact : null,
                        'source' => $sourceString,
                        'field' => $sourceSet['field'],
                    ];
                    if ($sourceSet['scenario_id'] !== null) {
                        $failure['scenario_id'] = $sourceSet['scenario_id'];
                    }

                    $failures[] = $failure;
                    break;
                }
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, array<string, mixed>>  $scenarioResults
     * @return list<array{field: string, scenario_id: string|null, value: mixed}>
     */
    private static function localProductSourceFlagReports(array $result, array $scenarioResults): array
    {
        $reports = [];
        self::appendLocalProductSourceFlagReports($reports, $result, null, '');

        $sourcePolicy = self::arrayField($result, ['source_policy', 'sourcePolicy']);
        if ($sourcePolicy !== null) {
            self::appendLocalProductSourceFlagReports($reports, $sourcePolicy, null, 'source_policy.');
        }

        $cellOutcomes = self::arrayField($result, ['lifecycle_cell_outcomes', 'lifecycleCellOutcomes']) ?? [];
        foreach ($cellOutcomes as $scenarioId => $cellOutcome) {
            if (! is_array($cellOutcome)) {
                continue;
            }

            self::appendLocalProductSourceFlagReports(
                $reports,
                $cellOutcome,
                is_string($scenarioId) ? $scenarioId : null,
                'lifecycle_cell_outcomes.',
            );
        }

        foreach ($scenarioResults as $scenarioId => $scenarioResult) {
            self::appendLocalProductSourceFlagReports($reports, $scenarioResult, $scenarioId, '');
            $outputs = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']);
            if ($outputs !== null) {
                self::appendLocalProductSourceFlagReports($reports, $outputs, $scenarioId, 'observed_outputs.');
            }
        }

        return $reports;
    }

    /**
     * @param  list<array{field: string, scenario_id: string|null, value: mixed}>  $reports
     * @param  array<string, mixed>  $container
     */
    private static function appendLocalProductSourceFlagReports(
        array &$reports,
        array $container,
        ?string $scenarioId,
        string $fieldPrefix,
    ): void {
        foreach (self::localProductSourceFlagFields() as $field) {
            if (! array_key_exists($field, $container)) {
                continue;
            }

            $reports[] = [
                'field' => $fieldPrefix.$field,
                'scenario_id' => $scenarioId,
                'value' => $container[$field],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, array<string, mixed>>  $scenarioResults
     * @return list<array{sources: array<mixed>, field: string, scenario_id: string|null, counts_for_required_sources: bool}>
     */
    private static function reportedArtifactSourceSets(array $result, array $scenarioResults): array
    {
        $sourceSets = [];
        $containers = [
            [
                'container' => $result,
                'field_prefix' => '',
                'scenario_id' => null,
            ],
        ];

        $sourcePolicy = self::arrayField($result, ['source_policy', 'sourcePolicy']);
        if ($sourcePolicy !== null) {
            $containers[] = [
                'container' => $sourcePolicy,
                'field_prefix' => 'source_policy.',
                'scenario_id' => null,
            ];
        }

        foreach ($scenarioResults as $scenarioId => $scenarioResult) {
            $containers[] = [
                'container' => $scenarioResult,
                'field_prefix' => '',
                'scenario_id' => $scenarioId,
            ];

            $outputs = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']);
            if ($outputs !== null) {
                $containers[] = [
                    'container' => $outputs,
                    'field_prefix' => 'observed_outputs.',
                    'scenario_id' => $scenarioId,
                ];
            }
        }

        foreach ($containers as $entry) {
            foreach ([
                'artifact_sources' => true,
                'artifactSources' => true,
                'install_sources' => true,
                'installSources' => true,
                'source_paths' => false,
                'sourcePaths' => false,
            ] as $field => $countsForRequiredSources) {
                $sources = self::arrayField($entry['container'], [$field]);
                if ($sources === null) {
                    continue;
                }

                $sourceSets[] = [
                    'sources' => $sources,
                    'field' => $entry['field_prefix'].$field,
                    'scenario_id' => is_string($entry['scenario_id']) ? $entry['scenario_id'] : null,
                    'counts_for_required_sources' => $countsForRequiredSources,
                ];
            }
        }

        return $sourceSets;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $scenarioResult
     */
    private static function lifecycleCellOutcomeStatus(array $result, array $scenarioResult, string $scenarioId): string
    {
        foreach ([
            self::fieldValue($scenarioResult, 'lifecycle_cell_outcome'),
            self::fieldValue($scenarioResult, 'cell_outcome'),
            self::fieldValue($scenarioResult, 'outcome'),
            self::fieldValue($scenarioResult, 'result'),
        ] as $value) {
            $status = self::cellOutcomeStatus($value);
            if ($status !== '') {
                return $status;
            }
        }

        $cellOutcomes = self::arrayField($result, ['lifecycle_cell_outcomes', 'lifecycleCellOutcomes']) ?? [];
        if (array_key_exists($scenarioId, $cellOutcomes)) {
            return self::cellOutcomeStatus($cellOutcomes[$scenarioId]);
        }

        return '';
    }

    private static function cellOutcomeStatus(mixed $value): string
    {
        if (is_array($value)) {
            foreach (['status', 'outcome', 'result', 'verdict'] as $field) {
                $status = self::normalizedStatus($value[$field] ?? null);
                if ($status !== '') {
                    return $status;
                }
            }

            return '';
        }

        return self::normalizedStatus($value);
    }

    /**
     * @param  array<string, mixed>  $scenarioResult
     */
    private static function hasObservedOutputs(array $scenarioResult): bool
    {
        return ! self::isEmptyEvidence(self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']));
    }

    /**
     * @param  array<string, mixed>  $scenarioResult
     */
    private static function hasPublishedArtifactCellExecution(array $scenarioResult): bool
    {
        $outputs = self::observedOutputs($scenarioResult);

        return self::truthy($scenarioResult['published_artifact_cell_executed'] ?? null)
            || self::truthy($scenarioResult['publishedArtifactCellExecuted'] ?? null)
            || self::truthy($outputs['published_artifact_cell_executed'] ?? null)
            || self::truthy($outputs['publishedArtifactCellExecuted'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $scenarioResult
     */
    private static function hasDocumentedTypedRefusal(array $scenarioResult): bool
    {
        $outputs = self::observedOutputs($scenarioResult);
        $typedRefusal = self::arrayField($outputs, ['typed_refusal', 'typedRefusal']) ?? [];

        $typedError = self::stringValue($typedRefusal['typed_error'] ?? null)
            ?: self::stringValue($typedRefusal['typedError'] ?? null)
            ?: self::stringValue($typedRefusal['error_type'] ?? null)
            ?: self::stringValue($typedRefusal['errorType'] ?? null)
            ?: self::stringValue($typedRefusal['refusal_code'] ?? null)
            ?: self::stringValue($typedRefusal['refusalCode'] ?? null)
            ?: self::stringValue($outputs['typed_error'] ?? null)
            ?: self::stringValue($outputs['error_type'] ?? null)
            ?: self::stringValue($outputs['refusal_code'] ?? null)
            ?: self::stringValue($outputs['backoff_observation_or_error_type'] ?? null)
            ?: self::stringValue($scenarioResult['typed_error'] ?? null)
            ?: self::stringValue($scenarioResult['error_type'] ?? null);

        $reason = self::stringValue($typedRefusal['refusal_reason'] ?? null)
            ?: self::stringValue($typedRefusal['refusalReason'] ?? null)
            ?: self::stringValue($typedRefusal['reason'] ?? null)
            ?: self::stringValue($outputs['refusal_reason'] ?? null)
            ?: self::stringValue($outputs['reason'] ?? null)
            ?: self::stringValue($scenarioResult['refusal_reason'] ?? null)
            ?: self::stringValue($scenarioResult['reason'] ?? null);

        $documented = self::truthy($typedRefusal['documented'] ?? null)
            || self::truthy($typedRefusal['docs_match'] ?? null)
            || self::truthy($typedRefusal['docsMatch'] ?? null)
            || self::truthy($outputs['documented'] ?? null)
            || self::truthy($outputs['documented_refusal'] ?? null)
            || self::truthy($outputs['docs_match'] ?? null)
            || self::truthy($outputs['docsMatch'] ?? null)
            || self::truthy($scenarioResult['documented'] ?? null)
            || self::truthy($scenarioResult['docs_match'] ?? null)
            || self::truthy($scenarioResult['docsMatch'] ?? null);

        return $typedError !== '' && $reason !== '' && $documented;
    }

    /**
     * @param  array<string, mixed>  $contract
     * @param  array<string, mixed>  $scenarioResult
     * @return list<string>
     */
    private static function missingScenarioEvidence(array $contract, string $scenarioId, array $scenarioResult): array
    {
        $outputs = self::observedOutputs($scenarioResult);
        $missing = [];

        foreach (self::scenarioEvidenceFields($contract, $scenarioId) as $field) {
            if (! array_key_exists($field, $outputs)
                || self::requiredEvidenceValueMissing($scenarioId, $field, $outputs[$field])
            ) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return list<string>
     */
    private static function scenarioEvidenceFields(array $contract, string $scenarioId): array
    {
        $requirements = $contract['scenario_requirements'][$scenarioId] ?? [];
        if (! is_array($requirements)) {
            return [];
        }

        return self::stringList($requirements['evidence'] ?? $requirements['required_evidence'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $scenarioResult
     * @return list<array{code: string, reason: string}>
     */
    private static function semanticScenarioFailures(string $scenarioId, array $scenarioResult, array $result): array
    {
        $outputs = self::observedOutputs($scenarioResult);

        return match ($scenarioId) {
            'continue_as_new_run_chain_visibility' => self::validateContinueAsNewRunChain($outputs),
            'continue_as_new_identity_and_history_continuity' => self::validateContinueAsNewHistory($outputs),
            'continue_as_new_duplicate_side_effect_prevention' => self::validateContinueAsNewSideEffects($outputs),
            'cancellation_public_surface_terminal_state' => self::validateTerminalLifecycleSurface(
                $outputs,
                'cancelled',
                ['cancel'],
                'cancellation_terminal_status_invalid',
                'cancellation_typed_errors_missing',
            ),
            'termination_public_surface_terminal_state' => self::validateTerminalLifecycleSurface(
                $outputs,
                'terminated',
                ['terminat'],
                'termination_terminal_status_invalid',
                'termination_typed_errors_missing',
            ),
            'workflow_id_reuse_duplicate_start_policy' => self::validateDuplicateStartPolicy($outputs),
            'workflow_timeout_terminal_state' => self::validateWorkflowTimeout($outputs),
            'workflow_retry_backoff_or_refusal' => self::validateWorkflowRetry($outputs),
            'php_sdk_lifecycle_surface' => self::validateSdkLifecycleSurface($outputs, ['sdk-php']),
            'python_sdk_lifecycle_surface' => self::validateSdkLifecycleSurface($outputs, ['python']),
            'rust_sdk_lifecycle_surface' => array_merge(
                self::validateSdkLifecycleSurface($outputs, ['rust']),
                self::validateRustSdkLifecycleSurface(
                    $outputs,
                    self::artifactValue(
                        self::arrayField($result, ['artifact_versions', 'artifactVersions']) ?? [],
                        'sdk-rust',
                        WorkflowLifecycleContract::manifest(),
                    ),
                    self::artifactValue(
                        self::arrayField($result, ['artifact_versions', 'artifactVersions']) ?? [],
                        'server',
                        WorkflowLifecycleContract::manifest(),
                    ),
                ),
            ),
            'operator_diagnostics_surfaces' => self::validateOperatorDiagnostics($outputs),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $scenarioResult
     * @return array<string, mixed>
     */
    private static function observedOutputs(array $scenarioResult): array
    {
        return self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']) ?? [];
    }

    /**
     * @param  list<array{code: string, reason: string}>  $failures
     * @return list<array{code: string, reason: string}>
     */
    private static function addSemanticFailure(array $failures, string $code, string $reason): array
    {
        $failures[] = [
            'code' => $code,
            'reason' => $reason,
        ];

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @return list<array{code: string, reason: string}>
     */
    private static function validateContinueAsNewRunChain(array $outputs): array
    {
        $failures = [];
        $workflowId = self::stringValue($outputs['workflow_id'] ?? null);
        $initialRunId = self::stringValue($outputs['initial_run_id'] ?? null);
        $continuedRunId = self::stringValue($outputs['continued_run_id'] ?? null);
        $currentRunId = self::stringValue($outputs['current_run_id'] ?? null);
        $runCount = self::numberValue($outputs['run_count'] ?? null);
        $runNumbers = is_array($outputs['run_numbers'] ?? null)
            ? array_map(fn (mixed $value): ?float => self::numberValue($value), $outputs['run_numbers'])
            : [];

        if ($workflowId === '') {
            $failures = self::addSemanticFailure(
                $failures,
                'continue_as_new_workflow_id_missing',
                'continue-as-new chain must report one logical workflow_id',
            );
        }
        if ($initialRunId === '' || $continuedRunId === '' || $initialRunId === $continuedRunId) {
            $failures = self::addSemanticFailure(
                $failures,
                'continue_as_new_run_ids_not_distinct',
                'continue-as-new chain must report distinct initial and continued run IDs',
            );
        }
        if ($currentRunId !== $continuedRunId) {
            $failures = self::addSemanticFailure(
                $failures,
                'continue_as_new_current_run_not_successor',
                'continue-as-new current_run_id must point at the continued successor run',
            );
        }
        if ($runCount === null || $runCount < 2) {
            $failures = self::addSemanticFailure(
                $failures,
                'continue_as_new_run_count_invalid',
                'continue-as-new run_count must be at least 2',
            );
        }
        if (count($runNumbers) < 2 || in_array(null, $runNumbers, true)) {
            $failures = self::addSemanticFailure(
                $failures,
                'continue_as_new_run_numbers_invalid',
                'continue-as-new run_numbers must list at least two numeric runs',
            );
        } else {
            for ($index = 1; $index < count($runNumbers); $index++) {
                if ($runNumbers[$index] <= $runNumbers[$index - 1]) {
                    $failures = self::addSemanticFailure(
                        $failures,
                        'continue_as_new_run_numbers_not_monotonic',
                        'continue-as-new run_numbers must be strictly increasing',
                    );
                    break;
                }
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @return list<array{code: string, reason: string}>
     */
    private static function validateContinueAsNewHistory(array $outputs): array
    {
        $failures = [];
        $events = $outputs['history_events'] ?? null;
        $predecessor = self::stringValue($outputs['predecessor_closed_event'] ?? null);
        $successor = self::stringValue($outputs['successor_started_event'] ?? null);

        if (! self::isNonEmptyList($events)) {
            $failures = self::addSemanticFailure(
                $failures,
                'continue_as_new_history_events_missing',
                'continue-as-new history must include public history events',
            );
        } else {
            if ($predecessor === '' || ! self::listContainsValue($events, $predecessor)) {
                $failures = self::addSemanticFailure(
                    $failures,
                    'continue_as_new_predecessor_history_missing',
                    'continue-as-new history must include the predecessor closed event',
                );
            }
            if ($successor === '' || ! self::listContainsValue($events, $successor)) {
                $failures = self::addSemanticFailure(
                    $failures,
                    'continue_as_new_successor_history_missing',
                    'continue-as-new history must include the successor started event',
                );
            }
        }
        if (! self::isNonEmptyList($outputs['history_api_links'] ?? null)) {
            $failures = self::addSemanticFailure(
                $failures,
                'continue_as_new_history_links_missing',
                'continue-as-new history must include operator-visible API links',
            );
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @return list<array{code: string, reason: string}>
     */
    private static function validateContinueAsNewSideEffects(array $outputs): array
    {
        $failures = [];
        $expected = self::numberValue($outputs['expected_count'] ?? null);
        $observed = self::numberValue($outputs['observed_count'] ?? null);

        if ($expected === null || $expected < 1) {
            $failures = self::addSemanticFailure(
                $failures,
                'side_effect_expected_count_invalid',
                'side-effect evidence must report a positive expected_count',
            );
        }
        if ($observed === null || $observed < 0) {
            $failures = self::addSemanticFailure(
                $failures,
                'side_effect_observed_count_invalid',
                'side-effect evidence must report a non-negative observed_count',
            );
        }
        if ($expected !== null && $observed !== null && $observed !== $expected) {
            $failures = self::addSemanticFailure(
                $failures,
                'duplicate_side_effect_count_mismatch',
                'continue-as-new side-effect observed_count must equal expected_count',
            );
        }
        if (self::stringValue($outputs['side_effect_key'] ?? null) === '') {
            $failures = self::addSemanticFailure(
                $failures,
                'side_effect_key_missing',
                'side-effect evidence must name the protected side_effect_key',
            );
        }
        if (self::stringValue($outputs['replay_or_restart_window'] ?? null) === '') {
            $failures = self::addSemanticFailure(
                $failures,
                'side_effect_window_missing',
                'side-effect evidence must name the replay or restart window exercised',
            );
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @param  list<string>  $errorFragments
     * @return list<array{code: string, reason: string}>
     */
    private static function validateTerminalLifecycleSurface(
        array $outputs,
        string $terminalStatus,
        array $errorFragments,
        string $terminalFailureCode,
        string $errorFailureCode,
    ): array {
        $failures = [];
        if (self::normalizedText($outputs['terminal_status'] ?? null) !== $terminalStatus) {
            $failures = self::addSemanticFailure(
                $failures,
                $terminalFailureCode,
                'terminal_status must be '.$terminalStatus,
            );
        }
        if (! self::textIncludesAny($outputs['worker_error_type'] ?? null, $errorFragments)) {
            $failures = self::addSemanticFailure(
                $failures,
                $errorFailureCode,
                'worker_error_type must be a typed '.$terminalStatus.' error',
            );
        }
        if (! self::textIncludesAny($outputs['caller_error_type'] ?? null, $errorFragments)) {
            $failures = self::addSemanticFailure(
                $failures,
                $errorFailureCode,
                'caller_error_type must be a typed '.$terminalStatus.' error',
            );
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @return list<array{code: string, reason: string}>
     */
    private static function validateDuplicateStartPolicy(array $outputs): array
    {
        $failures = [];
        $duplicateOutcome = self::normalizedText($outputs['duplicate_start_outcome'] ?? null);
        $firstRunId = self::stringValue($outputs['first_run_id'] ?? null);
        $runCountAfterDuplicate = self::numberValue($outputs['run_count_after_duplicate'] ?? null);
        $runIdsAfterDuplicate = self::stringList($outputs['run_ids_after_duplicate'] ?? null);

        if (in_array($duplicateOutcome, ['accepted', 'started', 'created', 'completed', 'succeeded', 'success', 'ok'], true)) {
            $failures = self::addSemanticFailure(
                $failures,
                'duplicate_start_accepted',
                'duplicate workflow-id start must not be accepted as a new run',
            );
        }
        if (! self::textIncludesAny($duplicateOutcome, ['refus', 'reject', 'fail', 'conflict', 'error', 'existing', 'duplicate'])) {
            $failures = self::addSemanticFailure(
                $failures,
                'duplicate_start_policy_not_proven',
                'duplicate workflow-id start must prove enforcement or a typed refusal',
            );
        }
        if (self::stringValue($outputs['http_status_or_error_type'] ?? null) === '') {
            $failures = self::addSemanticFailure(
                $failures,
                'duplicate_start_error_type_missing',
                'duplicate workflow-id start must report an HTTP status or typed error',
            );
        }
        if ($firstRunId === '') {
            $failures = self::addSemanticFailure(
                $failures,
                'duplicate_start_first_run_id_missing',
                'duplicate workflow-id start must report the first run id',
            );
        }
        if ($runCountAfterDuplicate !== 1.0) {
            $failures = self::addSemanticFailure(
                $failures,
                'duplicate_start_run_count_changed',
                'duplicate workflow-id fail policy must leave exactly one run after the duplicate request',
            );
        }
        if (count($runIdsAfterDuplicate) !== 1 || ($firstRunId !== '' && $runIdsAfterDuplicate[0] !== $firstRunId)) {
            $failures = self::addSemanticFailure(
                $failures,
                'duplicate_start_first_run_not_preserved',
                'duplicate workflow-id fail policy must preserve only the first run id',
            );
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @return list<array{code: string, reason: string}>
     */
    private static function validateWorkflowTimeout(array $outputs): array
    {
        $failures = [];
        if (self::normalizedText($outputs['terminal_status'] ?? null) !== 'timed_out') {
            $failures = self::addSemanticFailure(
                $failures,
                'workflow_timeout_terminal_status_invalid',
                'workflow timeout terminal_status must be timed_out',
            );
        }

        $deadlineAt = self::timestampMs($outputs['deadline_at'] ?? null);
        $observedTerminalAt = self::timestampMs($outputs['observed_terminal_at'] ?? null);
        if ($deadlineAt === null || $observedTerminalAt === null) {
            $failures = self::addSemanticFailure(
                $failures,
                'workflow_timeout_timestamp_invalid',
                'workflow timeout evidence must report parseable deadline and terminal timestamps',
            );
        } elseif ($observedTerminalAt < $deadlineAt) {
            $failures = self::addSemanticFailure(
                $failures,
                'workflow_timeout_terminal_before_deadline',
                'workflow timeout terminal observation must not be earlier than the deadline',
            );
        }
        if (! self::isNonEmptyCollection($outputs['operator_visible_timing'] ?? null)) {
            $failures = self::addSemanticFailure(
                $failures,
                'workflow_timeout_operator_timing_missing',
                'workflow timeout must include operator-visible timing evidence',
            );
        }
        $refusals = is_array($outputs['unsupported_timeout_shape_refusals'] ?? null)
            ? array_values($outputs['unsupported_timeout_shape_refusals'])
            : [];
        if ($refusals === []) {
            $failures = self::addSemanticFailure(
                $failures,
                'workflow_timeout_refusals_missing',
                'workflow timeout evidence must include typed refusals for unsupported timeout shapes',
            );
        } else {
            foreach ($refusals as $refusal) {
                if (! is_array($refusal)) {
                    $failures = self::addSemanticFailure(
                        $failures,
                        'workflow_timeout_refusal_invalid',
                        'workflow timeout unsupported shapes must be documented typed refusals',
                    );
                    break;
                }

                $status = self::numberValue($refusal['http_status'] ?? null);
                $typedError = self::stringValue($refusal['typed_error'] ?? $refusal['error_type'] ?? $refusal['refusal_code'] ?? null);
                $reason = self::stringValue($refusal['refusal_reason'] ?? $refusal['reason'] ?? $refusal['message'] ?? null);
                if ($status === null || $status < 400 || $typedError === '' || $reason === '' || ! self::truthy($refusal['documented'] ?? null)) {
                    $failures = self::addSemanticFailure(
                        $failures,
                        'workflow_timeout_refusal_invalid',
                        'workflow timeout unsupported shapes must be documented typed refusals',
                    );
                    break;
                }
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @return list<array{code: string, reason: string}>
     */
    private static function validateWorkflowRetry(array $outputs): array
    {
        $failures = [];
        if (! self::truthy($outputs['docs_match'] ?? null)) {
            $failures = self::addSemanticFailure(
                $failures,
                'workflow_retry_docs_mismatch',
                'workflow retry/backoff evidence must match public docs',
            );
        }

        $attemptCount = self::numberValue($outputs['attempt_count_or_refusal_reason'] ?? null);
        if ($attemptCount !== null) {
            if ($attemptCount < 2) {
                $failures = self::addSemanticFailure(
                    $failures,
                    'workflow_retry_attempt_count_invalid',
                    'workflow retry evidence must show at least two attempts',
                );
            }
            if (self::stringValue($outputs['backoff_observation_or_error_type'] ?? null) === '') {
                $failures = self::addSemanticFailure(
                    $failures,
                    'workflow_retry_backoff_not_proven',
                    'workflow retry evidence must report backoff observation',
                );
            }

            return $failures;
        }

        if (self::hasDocumentedTypedRefusal(['observed_outputs' => $outputs])) {
            return $failures;
        }

        $failures = self::addSemanticFailure(
            $failures,
            'workflow_retry_backoff_not_proven',
            'workflow retry pass evidence must prove retry attempts or a documented typed refusal',
        );

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @param  list<string>  $expectedSdkFragments
     * @return list<array{code: string, reason: string}>
     */
    private static function validateSdkLifecycleSurface(array $outputs, array $expectedSdkFragments): array
    {
        $failures = [];
        if (! self::textIncludesAny($outputs['sdk'] ?? null, $expectedSdkFragments)) {
            $failures = self::addSemanticFailure(
                $failures,
                'sdk_lifecycle_surface_mismatch',
                'SDK lifecycle surface evidence must identify the expected SDK',
            );
        }
        if (! self::isNonEmptyList($outputs['covered_cells'] ?? null) && ! self::isNonEmptyList($outputs['unsupported_cells'] ?? null)) {
            $failures = self::addSemanticFailure(
                $failures,
                'sdk_lifecycle_surface_empty',
                'SDK lifecycle surface must cover cells or report unsupported cells',
            );
        }
        if (self::isNonEmptyList($outputs['unsupported_cells'] ?? null) && ! self::isNonEmptyList($outputs['typed_errors'] ?? null)) {
            $failures = self::addSemanticFailure(
                $failures,
                'sdk_lifecycle_typed_errors_missing',
                'SDK unsupported lifecycle cells must include typed errors',
            );
        }
        if (self::stringValue($outputs['artifact_version'] ?? null) === '') {
            $failures = self::addSemanticFailure(
                $failures,
                'sdk_lifecycle_artifact_version_missing',
                'SDK lifecycle surface must report the published artifact version',
            );
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @return list<array{code: string, reason: string}>
     */
    private static function validateRustSdkLifecycleSurface(
        array $outputs,
        string $expectedVersion,
        string $expectedServerVersion,
    ): array {
        $failures = [];
        if ($expectedVersion === '' || ($outputs['artifact_version'] ?? null) !== $expectedVersion) {
            $failures = self::addSemanticFailure(
                $failures,
                'rust_sdk_artifact_version_mismatch',
                'Rust SDK lifecycle evidence version must match the pinned artifact tuple',
            );
        }
        if ($expectedServerVersion === '' || ($outputs['server_version'] ?? null) !== $expectedServerVersion) {
            $failures = self::addSemanticFailure(
                $failures,
                'rust_sdk_server_version_mismatch',
                'Rust SDK lifecycle evidence server version must match the pinned artifact tuple',
            );
        }
        $requiredCells = [
            'instance_cancel',
            'instance_terminate',
            'selected_run_guard',
            'stale_run_rejection',
            'typed_failed',
            'typed_cancelled',
            'typed_terminated',
            'typed_timed_out',
            'cancellation_heartbeat',
            'late_activity_completion_refused',
            'worker_restart_during_cancellation',
            'continue_as_new_replay_boundary',
        ];
        $covered = self::stringList($outputs['covered_cells'] ?? []);
        foreach ($requiredCells as $cell) {
            if (! in_array($cell, $covered, true)) {
                $failures = self::addSemanticFailure(
                    $failures,
                    'rust_sdk_required_cell_missing',
                    'Rust SDK lifecycle evidence is missing required cell '.$cell,
                );
            }
        }

        $scenarioOutcomes = self::arrayField($outputs, ['scenario_outcomes']) ?? [];
        foreach ($requiredCells as $cell) {
            $outcome = $scenarioOutcomes[$cell] ?? null;
            if (! is_array($outcome) || self::normalizedStatus($outcome['status'] ?? null) !== 'pass') {
                $failures = self::addSemanticFailure(
                    $failures,
                    'rust_sdk_scenario_outcome_missing',
                    'Rust SDK lifecycle evidence must report a passing outcome for '.$cell,
                );
            }
        }

        $exactOutcome = static function (string $cell, string $field, string $expected) use (&$failures, $scenarioOutcomes): void {
            if (self::normalizedText($scenarioOutcomes[$cell][$field] ?? null) !== self::normalizedText($expected)) {
                $failures = self::addSemanticFailure(
                    $failures,
                    'rust_sdk_scenario_semantics_invalid',
                    sprintf('Rust SDK lifecycle evidence %s.%s must be %s', $cell, $field, $expected),
                );
            }
        };
        $exactOutcome('instance_cancel', 'command_status', 'accepted');
        $exactOutcome('instance_cancel', 'target_scope', 'instance');
        $exactOutcome('instance_cancel', 'typed_outcome', 'WorkflowCancelled');
        $exactOutcome('instance_terminate', 'command_status', 'accepted');
        $exactOutcome('instance_terminate', 'target_scope', 'instance');
        $exactOutcome('instance_terminate', 'typed_outcome', 'WorkflowTerminated');
        $exactOutcome('selected_run_guard', 'command_status', 'accepted');
        $exactOutcome('selected_run_guard', 'target_scope', 'run');
        if (self::stringValue($scenarioOutcomes['selected_run_guard']['workflow_id'] ?? null) === ''
            || self::stringValue($scenarioOutcomes['selected_run_guard']['run_id'] ?? null) === '') {
            $failures = self::addSemanticFailure(
                $failures,
                'rust_sdk_selected_run_identity_missing',
                'Rust selected-run evidence must retain workflow and selected run identity',
            );
        }
        $exactOutcome('stale_run_rejection', 'typed_error', 'WorkflowCommandRejected');
        $exactOutcome('stale_run_rejection', 'reason', 'historical_run_command_rejected');
        $exactOutcome('stale_run_rejection', 'target_scope', 'run');
        if (self::numberValue($scenarioOutcomes['stale_run_rejection']['http_status'] ?? null) !== 409.0) {
            $failures = self::addSemanticFailure(
                $failures,
                'rust_sdk_stale_run_status_invalid',
                'Rust stale-run rejection evidence must report HTTP 409',
            );
        }
        $staleOutcome = is_array($scenarioOutcomes['stale_run_rejection'] ?? null)
            ? $scenarioOutcomes['stale_run_rejection']
            : [];
        $staleWorkflowId = self::stringValue($staleOutcome['workflow_id'] ?? null);
        $staleRunId = self::stringValue($staleOutcome['run_id'] ?? null);
        $priorRunId = self::stringValue($staleOutcome['prior_run_id'] ?? null);
        $successorRunId = self::stringValue($staleOutcome['successor_run_id'] ?? null);
        $successorWorkflowId = self::stringValue($staleOutcome['successor_workflow_id'] ?? null);
        if ($staleWorkflowId === ''
            || $staleRunId === ''
            || $priorRunId === ''
            || $successorRunId === ''
            || $successorWorkflowId !== $staleWorkflowId
            || $staleRunId !== $priorRunId
            || $successorRunId === $priorRunId) {
            $failures = self::addSemanticFailure(
                $failures,
                'rust_sdk_historical_run_boundary_not_proven',
                'Rust stale-run evidence must identify a distinct successor current run for the same workflow and retain the rejected prior run identity',
            );
        }
        $exactOutcome('typed_failed', 'typed_outcome', 'WorkflowFailed');
        $exactOutcome('typed_cancelled', 'typed_outcome', 'WorkflowCancelled');
        $exactOutcome('typed_terminated', 'typed_outcome', 'WorkflowTerminated');
        $exactOutcome('typed_timed_out', 'typed_outcome', 'WorkflowTimedOut');
        $exactOutcome('typed_timed_out', 'reason', 'run_timeout');
        $exactOutcome('typed_timed_out', 'observation_source', 'WorkflowHandle::result');
        $exactOutcome('typed_timed_out', 'server_closed_reason', 'timed_out');
        if (! self::truthy($scenarioOutcomes['typed_timed_out']['server_terminal'] ?? null)
            || self::normalizedText($scenarioOutcomes['typed_timed_out']['failure_category'] ?? null) === 'client_timeout') {
            $failures = self::addSemanticFailure(
                $failures,
                'rust_sdk_server_terminal_timeout_not_proven',
                'Rust typed timeout evidence must come from WorkflowHandle::result observing a server-terminal timeout',
            );
        }
        if (! self::truthy($scenarioOutcomes['cancellation_heartbeat']['cancel_requested'] ?? null)
            || ! self::truthy($scenarioOutcomes['cancellation_heartbeat']['should_stop'] ?? null)
            || self::normalizedText($scenarioOutcomes['cancellation_heartbeat']['run_closed_reason'] ?? null) !== 'cancelled') {
            $failures = self::addSemanticFailure(
                $failures,
                'rust_sdk_cancellation_heartbeat_not_proven',
                'Rust cancellation heartbeat evidence must show cancellation and a stop response',
            );
        }
        $exactOutcome('late_activity_completion_refused', 'typed_error', 'ActivityTaskRejected');
        if (self::numberValue($scenarioOutcomes['late_activity_completion_refused']['http_status'] ?? null) !== 409.0
            || self::normalizedText($scenarioOutcomes['late_activity_completion_refused']['reason'] ?? null) !== 'run_cancelled') {
            $failures = self::addSemanticFailure(
                $failures,
                'rust_sdk_late_completion_refusal_invalid',
                'Rust late activity completion must report the stable 409 run_cancelled refusal',
            );
        }
        $exactOutcome('worker_restart_during_cancellation', 'restart_phase', 'cancellation_pending');
        $restartOutcome = is_array($scenarioOutcomes['worker_restart_during_cancellation'] ?? null)
            ? $scenarioOutcomes['worker_restart_during_cancellation']
            : [];
        $replacementPollStartedAt = self::numberValue($restartOutcome['replacement_poll_started_elapsed_ns'] ?? null);
        $settlementReleasedAt = self::numberValue($restartOutcome['settlement_released_elapsed_ns'] ?? null);
        $originalSettlementObservedAt = self::numberValue($restartOutcome['original_settlement_observed_elapsed_ns'] ?? null);
        $observedOrdering = $replacementPollStartedAt !== null
            && $settlementReleasedAt !== null
            && $originalSettlementObservedAt !== null
            && $replacementPollStartedAt < $settlementReleasedAt
            && $settlementReleasedAt <= $originalSettlementObservedAt;
        if (! self::truthy($restartOutcome['replacement_registered'] ?? null)
            || ! self::truthy($restartOutcome['replacement_poll_start_observed'] ?? null)
            || ! self::truthy($restartOutcome['original_activity_unsettled_when_replacement_poll_started'] ?? null)
            || ! self::truthy($restartOutcome['replacement_started_before_original_settled'] ?? null)
            || ! self::truthy($restartOutcome['settlement_released_after_replacement_started'] ?? null)
            || ! self::truthy($restartOutcome['original_settled_after_restart'] ?? null)
            || ! $observedOrdering) {
            $failures = self::addSemanticFailure(
                $failures,
                'rust_sdk_restart_boundary_not_proven',
                'Rust worker restart evidence must observe the replacement poll before releasing original activity settlement',
            );
        }
        $continueOutcome = is_array($scenarioOutcomes['continue_as_new_replay_boundary'] ?? null)
            ? $scenarioOutcomes['continue_as_new_replay_boundary']
            : [];
        $workflowId = self::stringValue($continueOutcome['workflow_id'] ?? null);
        $predecessorRunId = self::stringValue($continueOutcome['predecessor_run_id'] ?? null);
        $successorRunId = self::stringValue($continueOutcome['successor_run_id'] ?? null);
        $runChain = self::arrayField($continueOutcome, ['run_chain']) ?? [];
        $runs = self::arrayField($runChain, ['runs']) ?? [];
        $runIds = array_values(array_filter(array_map(
            static fn (mixed $run): string => is_array($run)
                ? self::stringValue($run['run_id'] ?? null)
                : '',
            $runs,
        ), static fn (string $runId): bool => $runId !== ''));
        $runNumbers = array_map(
            static fn (mixed $run): ?float => is_array($run)
                ? self::numberValue($run['run_number'] ?? null)
                : null,
            $runs,
        );
        if ($workflowId === ''
            || $predecessorRunId === ''
            || $successorRunId === ''
            || $predecessorRunId === $successorRunId
            || self::stringValue($continueOutcome['current_run_id'] ?? null) !== $successorRunId
            || self::stringValue($continueOutcome['selected_historical_run_id'] ?? null) !== $predecessorRunId
            || self::normalizedText($continueOutcome['selected_historical_closed_reason'] ?? null) !== 'continued'
            || self::stringValue($runChain['workflow_id'] ?? null) !== $workflowId
            || self::numberValue($runChain['run_count'] ?? null) !== 2.0
            || $runIds !== [$predecessorRunId, $successorRunId]
            || $runNumbers !== [1.0, 2.0]
            || self::numberValue($continueOutcome['successor_count'] ?? null) !== 1.0) {
            $failures = self::addSemanticFailure(
                $failures,
                'rust_sdk_continue_as_new_run_identity_invalid',
                'Rust continue-as-new evidence must retain one workflow identity, exactly two distinct ordered runs, historical selection, and current successor routing',
            );
        }

        $predecessorProcess = self::arrayField($continueOutcome, ['predecessor_worker_process']) ?? [];
        $successorProcess = self::arrayField($continueOutcome, ['successor_worker_process']) ?? [];
        $predecessorCompletion = self::arrayField($predecessorProcess, ['completion']) ?? [];
        $successorCompletion = self::arrayField($successorProcess, ['completion']) ?? [];
        if (self::numberValue($predecessorProcess['process_id'] ?? null) === null
            || self::numberValue($successorProcess['process_id'] ?? null) === null
            || self::numberValue($predecessorProcess['process_id'] ?? null)
                === self::numberValue($successorProcess['process_id'] ?? null)
            || self::stringValue($predecessorProcess['worker_id'] ?? null) === ''
            || self::stringValue($successorProcess['worker_id'] ?? null) === ''
            || self::stringValue($predecessorProcess['worker_id'] ?? null)
                === self::stringValue($successorProcess['worker_id'] ?? null)
            || self::numberValue($predecessorProcess['handled_tasks'] ?? null) !== 1.0
            || self::numberValue($successorProcess['handled_tasks'] ?? null) !== 1.0) {
            $failures = self::addSemanticFailure(
                $failures,
                'rust_sdk_continue_as_new_process_replacement_invalid',
                'Rust continue-as-new evidence must execute predecessor and successor tasks in distinct worker processes and worker identities',
            );
        }
        if (self::numberValue($predecessorCompletion['completion_delivery_count'] ?? null) !== 2.0
            || self::numberValue($predecessorCompletion['first_response_status'] ?? null) !== 200.0
            || ! self::truthy($predecessorCompletion['first_response']['recorded'] ?? null)
            || self::numberValue($predecessorCompletion['retry_response_status'] ?? null) !== 409.0
            || self::stringValue($predecessorCompletion['retry_response']['reason'] ?? null) === ''
            || self::stringList($predecessorCompletion['command_types'] ?? [])
                !== ['record_side_effect', 'record_version_marker', 'continue_as_new']
            || ! self::isNonEmptyList($predecessorCompletion['commands'] ?? null)) {
            $failures = self::addSemanticFailure(
                $failures,
                'rust_sdk_continue_as_new_completion_redelivery_invalid',
                'Rust continue-as-new evidence must retry the exact committed predecessor completion and retain its rejected redelivery response',
            );
        }
        if (self::numberValue($successorCompletion['completion_delivery_count'] ?? null) !== 1.0
            || self::numberValue($successorCompletion['first_response_status'] ?? null) !== 200.0
            || ! self::truthy($successorCompletion['first_response']['recorded'] ?? null)
            || self::stringList($successorCompletion['command_types'] ?? [])
                !== ['record_side_effect', 'record_version_marker', 'complete_workflow']
            || ! self::isNonEmptyList($successorCompletion['commands'] ?? null)) {
            $failures = self::addSemanticFailure(
                $failures,
                'rust_sdk_continue_as_new_successor_commands_invalid',
                'Rust continue-as-new successor must record its own new-run side effect and version marker before final completion',
            );
        }

        $predecessorHistory = self::arrayField($continueOutcome, ['predecessor_history']) ?? [];
        $successorHistory = self::arrayField($continueOutcome, ['successor_history']) ?? [];
        $historyCount = static function (array $history, string $eventType): int {
            $events = is_array($history['events'] ?? null) ? $history['events'] : [];

            return count(array_filter(
                $events,
                static fn (mixed $event): bool => is_array($event)
                    && ($event['event_type'] ?? null) === $eventType,
            ));
        };
        $predecessorCounts = self::arrayField($continueOutcome, ['predecessor_history_event_counts']) ?? [];
        $successorCounts = self::arrayField($continueOutcome, ['successor_history_event_counts']) ?? [];
        if (self::stringValue($predecessorHistory['workflow_id'] ?? null) !== $workflowId
            || self::stringValue($predecessorHistory['run_id'] ?? null) !== $predecessorRunId
            || self::stringValue($successorHistory['workflow_id'] ?? null) !== $workflowId
            || self::stringValue($successorHistory['run_id'] ?? null) !== $successorRunId
            || $historyCount($predecessorHistory, 'SideEffectRecorded') !== 1
            || $historyCount($predecessorHistory, 'VersionMarkerRecorded') !== 1
            || $historyCount($predecessorHistory, 'WorkflowContinuedAsNew') !== 1
            || $historyCount($successorHistory, 'SideEffectRecorded') !== 1
            || $historyCount($successorHistory, 'VersionMarkerRecorded') !== 1
            || $historyCount($successorHistory, 'WorkflowContinuedAsNew') !== 0
            || self::numberValue($predecessorCounts['SideEffectRecorded'] ?? null) !== 1.0
            || self::numberValue($predecessorCounts['VersionMarkerRecorded'] ?? null) !== 1.0
            || self::numberValue($predecessorCounts['WorkflowContinuedAsNew'] ?? null) !== 1.0
            || self::numberValue($successorCounts['SideEffectRecorded'] ?? null) !== 1.0
            || self::numberValue($successorCounts['VersionMarkerRecorded'] ?? null) !== 1.0
            || self::numberValue($successorCounts['WorkflowContinuedAsNew'] ?? null) !== 0.0) {
            $failures = self::addSemanticFailure(
                $failures,
                'rust_sdk_continue_as_new_history_decisions_invalid',
                'Rust continue-as-new histories must keep predecessor decisions immutable and count successor decisions only in the new run',
            );
        }
        $predecessorLink = self::arrayField($continueOutcome, ['predecessor_transition_link']) ?? [];
        $successorLink = self::arrayField($continueOutcome, ['successor_transition_link']) ?? [];
        if (self::stringValue($predecessorLink['continued_to_run_id'] ?? null) !== $successorRunId
            || self::stringValue($successorLink['continued_from_run_id'] ?? null) !== $predecessorRunId) {
            $failures = self::addSemanticFailure(
                $failures,
                'rust_sdk_continue_as_new_history_links_invalid',
                'Rust continue-as-new histories must link predecessor and successor run identities in both directions',
            );
        }
        $finalResult = self::arrayField($continueOutcome, ['final_result']) ?? [];
        if (self::numberValue($predecessorProcess['callback_calls'] ?? null) !== 1.0
            || self::numberValue($successorProcess['callback_calls'] ?? null) !== 1.0
            || ! self::truthy($continueOutcome['predecessor_decisions_immutable'] ?? null)
            || ! self::truthy($continueOutcome['successor_decisions_are_new_run_decisions'] ?? null)
            || self::normalizedText($continueOutcome['final_result_observation_source'] ?? null) !== 'workflowhandle::result'
            || self::normalizedText($continueOutcome['current_run_observation_source'] ?? null) !== 'workflowhandle::describe'
            || self::normalizedText($continueOutcome['selected_run_observation_source'] ?? null) !== 'workflowhandle::describe_selected_run'
            || self::normalizedText($finalResult['status'] ?? null) !== 'completed'
            || self::stringValue($finalResult['workflow_id'] ?? null) !== $workflowId
            || self::stringValue($finalResult['run_id'] ?? null) !== $successorRunId
            || self::numberValue($finalResult['successor_version'] ?? null) !== 3.0) {
            $failures = self::addSemanticFailure(
                $failures,
                'rust_sdk_continue_as_new_callback_or_routing_invalid',
                'Rust continue-as-new evidence must invoke each run callback once and route current, selected historical, and final result reads through the chain',
            );
        }
        $provenance = self::arrayField($outputs, ['install_provenance']) ?? [];
        if (($provenance['package'] ?? null) !== 'durable-workflow'
            || ($provenance['requested_version'] ?? null) !== ($outputs['artifact_version'] ?? null)
            || ($provenance['installed_version'] ?? null) !== ($outputs['artifact_version'] ?? null)
            || ! str_contains(strtolower(self::stringValue($provenance['registry_source'] ?? null)), 'crates.io')
            || preg_match('/^[0-9a-f]{64}$/', self::stringValue($provenance['registry_checksum_sha256'] ?? null)) !== 1) {
            $failures = self::addSemanticFailure(
                $failures,
                'rust_sdk_install_provenance_invalid',
                'Rust SDK lifecycle evidence must identify the exact crates.io package and registry checksum',
            );
        }

        $payload = self::arrayField($outputs, ['payload_contract']) ?? [];
        if (($payload['codec'] ?? null) !== 'avro'
            || ($payload['envelope_contract'] ?? null) !== 'durable-workflow-published-envelope'
            || ($payload['apache_avro_package'] ?? null) !== 'apache-avro'
            || ! self::truthy($payload['official_crates_io_provenance'] ?? null)
            || ! str_contains(strtolower(self::stringValue($payload['apache_avro_registry_source'] ?? null)), 'crates.io')
            || preg_match('/^[0-9a-f]{64}$/', self::stringValue($payload['apache_avro_registry_checksum_sha256'] ?? null)) !== 1) {
            $failures = self::addSemanticFailure(
                $failures,
                'rust_sdk_payload_contract_invalid',
                'Rust SDK lifecycle evidence must use the published Avro envelope and official apache-avro crate',
            );
        }

        $stableReasons = self::stringList($outputs['stable_reasons'] ?? []);
        foreach (['run_cancelled', 'run_terminated', 'historical_run_command_rejected', 'run_timeout', 'workflow_task_completion_redelivery_rejected'] as $reason) {
            if (! in_array($reason, $stableReasons, true)) {
                $failures = self::addSemanticFailure(
                    $failures,
                    'rust_sdk_stable_reason_missing',
                    'Rust lifecycle evidence stable_reasons must include '.$reason,
                );
            }
        }
        $identities = self::arrayField($outputs, ['workflow_identities']) ?? [];
        foreach (['instance_cancel', 'instance_terminate', 'selected_run_guard', 'typed_failed', 'typed_timed_out', 'continue_as_new_replay_boundary_predecessor', 'continue_as_new_replay_boundary_successor'] as $scenario) {
            $matching = array_values(array_filter(
                $identities,
                static fn (mixed $identity): bool => is_array($identity)
                    && self::normalizedText($identity['scenario'] ?? null) === $scenario
                    && self::stringValue($identity['workflow_id'] ?? null) !== ''
                    && self::stringValue($identity['run_id'] ?? null) !== '',
            ));
            if ($matching === []) {
                $failures = self::addSemanticFailure(
                    $failures,
                    'rust_sdk_workflow_identity_missing',
                    'Rust lifecycle evidence must retain workflow and run identity for '.$scenario,
                );
            }
        }
        $topology = self::arrayField($outputs, ['executor_topology']) ?? [];
        if (($outputs['rust_shard_contract_version'] ?? null) !== 3
            || ($outputs['shard_runner'] ?? null) !== 'published-rust-sdk-lifecycle-surface-probe'
            || self::numberValue($outputs['shard_exit_status'] ?? null) !== 0.0) {
            $failures = self::addSemanticFailure(
                $failures,
                'rust_sdk_shard_execution_invalid',
                'Rust lifecycle evidence must identify the successful versioned shard executor',
            );
        }
        if (($topology['server_http_process'] ?? null) !== 'exact_published_image'
            || ($topology['scheduler_process'] ?? null) !== 'exact_published_image'
            || ($topology['rust_executor'] ?? null) !== 'host_rust_container'
            || ! self::truthy($topology['rust_executor_outside_server_image'] ?? null)) {
            $failures = self::addSemanticFailure(
                $failures,
                'rust_sdk_executor_topology_invalid',
                'Rust lifecycle evidence must prove exact-image HTTP and scheduler processes plus the external Rust executor',
            );
        }
        $clusterInfo = self::arrayField($outputs, ['server_cluster_info']) ?? [];
        $clusterJson = json_encode($clusterInfo);
        if (! is_string($clusterJson) || ! str_contains($clusterJson, $expectedServerVersion)) {
            $failures = self::addSemanticFailure(
                $failures,
                'rust_sdk_server_identity_not_observed',
                'Rust lifecycle evidence must retain cluster-info observation of the pinned server version',
            );
        }

        if (! self::isNonEmptyList($outputs['workflow_identities'] ?? null)
            || ! self::isNonEmptyList($outputs['stable_reasons'] ?? null)
            || ! is_array($outputs['scenario_outcomes'] ?? null)
            || $outputs['scenario_outcomes'] === []) {
            $failures = self::addSemanticFailure(
                $failures,
                'rust_sdk_machine_readable_evidence_missing',
                'Rust SDK lifecycle evidence must record identities, per-scenario outcomes, and stable reasons',
            );
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @return list<array{code: string, reason: string}>
     */
    private static function validateOperatorDiagnostics(array $outputs): array
    {
        $failures = [];
        foreach (['cli_fields', 'api_fields', 'history_fields', 'waterline_fields'] as $field) {
            if (! self::isNonEmptyList($outputs[$field] ?? null)) {
                $failures = self::addSemanticFailure(
                    $failures,
                    'operator_diagnostic_surface_missing',
                    'operator diagnostics must include '.$field,
                );
            }
        }
        if (! self::isNonEmptyCollection($outputs['diagnostic_transition_matrix'] ?? null)) {
            $failures = self::addSemanticFailure(
                $failures,
                'operator_diagnostic_transition_matrix_missing',
                'operator diagnostics must include a transition matrix',
            );
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $scenarioResult
     */
    private static function hasFocusedFinding(array $result, array $scenarioResult, string $scenarioId): bool
    {
        foreach ([
            $scenarioResult['linked_findings'] ?? null,
            $scenarioResult['linkedFindings'] ?? null,
            $scenarioResult['finding_links'] ?? null,
            $scenarioResult['findingLinks'] ?? null,
            $scenarioResult['findings'] ?? null,
        ] as $value) {
            if (! self::isEmptyEvidence($value)) {
                return true;
            }
        }

        foreach (['findings', 'linked_findings', 'linkedFindings', 'finding_links', 'findingLinks'] as $field) {
            $findings = self::arrayField($result, [$field]);
            if ($findings === null) {
                continue;
            }

            if (array_key_exists($scenarioId, $findings) && ! self::isEmptyEvidence($findings[$scenarioId])) {
                return true;
            }

            foreach ($findings as $finding) {
                if (! is_array($finding)) {
                    continue;
                }

                if (self::stringValue($finding['scenario_id'] ?? $finding['scenarioId'] ?? null) === $scenarioId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return list<string>
     */
    private static function requiredArtifacts(array $contract): array
    {
        $artifacts = array_keys($contract['artifact_policy']['install_channels'] ?? []);
        if ($artifacts === []) {
            return ['server', 'cli', 'workflow-php', 'sdk-php', 'sdk-python', 'sdk-rust', 'waterline'];
        }

        return array_values(array_map(static fn (mixed $artifact): string => (string) $artifact, $artifacts));
    }

    /**
     * @param  array<mixed>  $values
     * @param  array<string, mixed>  $contract
     */
    private static function artifactValue(array $values, string $artifact, array $contract): string
    {
        foreach (self::artifactAliases($artifact, $contract) as $alias) {
            $value = self::stringValue($values[$alias] ?? null);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return list<string>
     */
    private static function artifactAliases(string $artifact, array $contract): array
    {
        $aliases = [$artifact];
        foreach (self::stringList($contract['artifact_policy']['release_artifact_aliases'][$artifact] ?? []) as $alias) {
            $aliases[] = $alias;
        }

        if ($artifact === 'workflow-php') {
            $aliases[] = 'workflow';
        }
        if ($artifact === 'sdk-python') {
            $aliases[] = 'python';
        }

        return array_values(array_unique($aliases));
    }

    private static function isPlaceholderVersion(string $value): bool
    {
        return preg_match('/(<[^>]+>|\$\{[^}]+}|{{[^}]+}}|(^|[^a-z0-9])latest([^a-z0-9]|$)|current|head|unresolved|placeholder)/i', $value) === 1;
    }

    /**
     * @param  array<string, mixed>  ...$containers
     */
    private static function hasExplicitFalseLocalProductSourceFlag(array ...$containers): bool
    {
        foreach ($containers as $container) {
            foreach (self::localProductSourceFlagFields() as $field) {
                if (array_key_exists($field, $container) && self::explicitFalse($container[$field])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function localProductSourceFlagFields(): array
    {
        return [
            'local_product_source_checkouts_used',
            'localProductSourceCheckoutsUsed',
            'local_product_source_checkout_used',
            'localProductSourceCheckoutUsed',
            'used_local_product_source_checkout',
            'usedLocalProductSourceCheckout',
            'local_source_checkout',
            'localSourceCheckout',
        ];
    }

    /**
     * @return list<string>
     */
    private static function localProductSourcePolicyTruthFields(): array
    {
        return [
            ...self::localProductSourceFlagFields(),
            'local_product_source_checkout_used_as_pass_evidence',
            'localProductSourceCheckoutUsedAsPassEvidence',
            'local_product_source_checkouts_used_as_pass_evidence',
            'localProductSourceCheckoutsUsedAsPassEvidence',
        ];
    }

    /**
     * @param  array<string, mixed>  $array
     * @param  list<string>  $fields
     */
    private static function truthyField(array $array, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $array) && self::truthy($array[$field])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $array
     * @param  list<string>  $fields
     */
    private static function allReportedFieldsTruthy(array $array, array $fields): bool
    {
        $reported = false;

        foreach ($fields as $field) {
            if (! array_key_exists($field, $array)) {
                continue;
            }

            $reported = true;
            if (! self::truthy($array[$field])) {
                return false;
            }
        }

        return $reported;
    }

    private static function truthy(mixed $value): bool
    {
        if ($value === true || $value === 1) {
            return true;
        }

        if (is_float($value) && $value == 1.0) {
            return true;
        }

        return is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function explicitFalse(mixed $value): bool
    {
        if ($value === false || $value === 0) {
            return true;
        }

        if (is_float($value) && $value == 0.0) {
            return true;
        }

        return is_string($value) && in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off'], true);
    }

    /**
     * @param  array<string, mixed>  $array
     * @param  list<string>  $keys
     */
    private static function hasScalarField(array $array, array $keys): bool
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $array)) {
                continue;
            }

            $value = $array[$key];
            if ((is_string($value) || is_numeric($value) || is_bool($value)) && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $array
     * @param  list<string>  $keys
     * @return array<string, mixed>|null
     */
    private static function arrayField(array $array, array $keys): ?array
    {
        foreach ($keys as $key) {
            $value = $array[$key] ?? null;
            if (is_array($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $array
     * @param  list<string>  $keys
     */
    private static function hasAnyField(array $array, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $array
     * @param  list<string>  $keys
     */
    private static function firstFieldValue(array $array, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                return $array[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $array
     */
    private static function fieldValue(array $array, string $field): mixed
    {
        foreach ([$field, self::camelize($field)] as $key) {
            if (array_key_exists($key, $array)) {
                return $array[$key];
            }
        }

        return null;
    }

    private static function camelize(string $field): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $field))));
    }

    private static function stringValue(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }

    private static function normalizedStatus(mixed $value): string
    {
        return strtolower(str_replace('-', '_', self::stringValue($value)));
    }

    private static function isEmptyEvidence(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    private static function requiredEvidenceValueMissing(string $scenarioId, string $field, mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [] && ! self::requiredFieldAllowsEmptyList($scenarioId, $field);
        }

        return false;
    }

    private static function requiredFieldAllowsEmptyList(string $scenarioId, string $field): bool
    {
        return in_array($scenarioId, ['php_sdk_lifecycle_surface', 'python_sdk_lifecycle_surface', 'rust_sdk_lifecycle_surface'], true)
            && in_array($field, ['unsupported_cells', 'typed_errors'], true);
    }

    private static function normalizedText(mixed $value): string
    {
        return str_replace(['-', ' '], '_', strtolower(self::stringValue($value)));
    }

    /**
     * @param  list<string>  $fragments
     */
    private static function textIncludesAny(mixed $value, array $fragments): bool
    {
        $text = self::normalizedText($value);
        if ($text === '') {
            return false;
        }

        foreach ($fragments as $fragment) {
            if (str_contains($text, self::normalizedText($fragment))) {
                return true;
            }
        }

        return false;
    }

    private static function numberValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }

        if (is_string($value) && trim($value) !== '' && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    private static function timestampMs(mixed $value): ?int
    {
        $timestamp = self::stringValue($value);
        if ($timestamp === '') {
            return null;
        }

        $parsed = strtotime($timestamp);

        return $parsed === false ? null : $parsed * 1000;
    }

    /**
     * @param  list<mixed>  $values
     */
    private static function listContainsValue(array $values, string $expected): bool
    {
        $normalizedExpected = self::normalizedText($expected);
        foreach ($values as $value) {
            if (self::normalizedText($value) === $normalizedExpected) {
                return true;
            }
        }

        return false;
    }

    private static function isNonEmptyCollection(mixed $value): bool
    {
        return is_array($value) && $value !== [];
    }

    private static function isNonEmptyList(mixed $value): bool
    {
        if (! is_array($value) || $value === []) {
            return false;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    private static function sourceValueRecorded(mixed $value): bool
    {
        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return false;
    }

    private static function sourceString(mixed $value): string
    {
        if (is_string($value) || is_numeric($value) || is_bool($value)) {
            return trim((string) $value);
        }

        if (is_array($value)) {
            $encoded = json_encode($value);

            return is_string($encoded) ? $encoded : '';
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $item): string => is_string($item) || is_numeric($item) ? trim((string) $item) : '',
                $value,
            ),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
