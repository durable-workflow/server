<?php

namespace App\Support;

/**
 * Evaluates v1-to-v2 migration conformance results against the full upgrade
 * matrix exposed by MigrationRuntimeContract.
 */
final class MigrationRuntimeResultGate
{
    public const SCHEMA = 'durable-workflow.v2.migration-runtime.result-gate';

    public const VERSION = 2;

    private const FOCUSED_WORKER_PROJECTION_STALE_AFTER_SECONDS = 300;

    private const PLACEHOLDER_EVIDENCE_TOKENS = [
        'not_executed',
        'not_executed_by_public_guide_audit',
        'not_documented_by_public_guide_audit',
        'documented_but_not_executed',
        'blocked_before_execution_by_unexecutable_public_guide_commands',
        'not_exercised',
        'not_supplied',
        'not_available',
        'placeholder',
    ];

    private const EVIDENCE_METADATA_FIELDS = [
        'status',
        'kind',
        'source',
        'phase',
        'state_kind',
        'stateKind',
        'state_kinds',
        'stateKinds',
        'expected_state_kinds',
        'expectedStateKinds',
        'type',
        'name',
        'scenario',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'evaluates_result_schema' => MigrationRuntimeContract::RESULT_SCHEMA,
            'scenario_statuses_source' => 'migration_runtime_contract.scenario_statuses',
            'required_scenarios_source' => 'migration_runtime_contract.required_scenarios',
            'required_matrix_source' => 'migration_runtime_contract.required_matrix',
            'required_run_record_fields_source' => 'migration_runtime_contract.artifact_policy.required_run_record_fields',
            'artifact_versions_fields' => [
                'artifact_versions',
                'artifactVersions',
                'published_artifact_versions',
                'publishedArtifactVersions',
                'resolved_artifact_versions',
                'resolvedArtifactVersions',
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
            'declared_outcomes_source' => 'migration_runtime_contract.coverage_gate.*_outcome',
            'non_pass_statuses' => [
                'fail',
                'unsupported',
                'not_covered',
                'runner_blocked',
            ],
            'neutral_statuses' => [
                'not_applicable',
            ],
            'artifact_version_policy' => [
                'requires_recorded_and_pinned_versions' => true,
                'requires_v1_and_v2_artifact_versions' => true,
                'rejects_placeholder_versions' => true,
                'placeholder_version_examples' => [
                    'latest',
                    'current',
                    'head',
                    'unresolved',
                    'placeholder',
                    '<latest>',
                    '1.x',
                    '2.0.0-alpha.<latest>',
                    '${VERSION}',
                    '{{ version }}',
                ],
            ],
            'pass_requires' => [
                'every_required_scenario_has_one_result',
                'every_result_uses_a_published_status',
                'latest_supported_v1_state_is_seeded',
                'selected_v1_source_capabilities_are_inventoried_before_continuity',
                'public_migration_guide_steps_are_executed_verbatim',
                'completed_histories_in_flight_progress_retries_and_queue_state_are_preserved',
                'source_absent_control_plane_cells_are_capability_backed_not_applicable',
                'cli_and_waterline_operator_surfaces_cover_preupgrade_state',
                'new_v2_workflows_schedules_and_worker_registrations_are_verified_after_upgrade',
                'rollback_contract_or_documented_no_rollback_is_verified',
                'rollback_public_operator_signal_is_recorded',
                'cli_and_worker_skew_request_response_evidence_is_recorded',
                'version_skew_refuses_loudly_without_partial_mutation',
                'storage_connection_smoke_is_recorded_but_not_counted_as_complete',
                'each_pass_scenario_has_observed_outputs',
                'each_pass_scenario_has_scenario_specific_evidence',
                'each_non_pass_scenario_has_linked_findings',
                'run_timestamps_outcome_and_finding_links_are_recorded',
                'contract_required_run_record_fields_are_recorded',
                'overall_outcome_matches_gate_status',
                'published_artifact_versions_are_recorded_and_pinned',
                'resolved_artifact_versions_are_recorded_and_pinned',
                'v1_and_v2_artifact_versions_are_distinguished',
                'artifact_source_recorded_for_each_install_channel',
                'artifact_prerequisite_failures_are_linked_when_artifacts_are_missing',
                'no_local_product_source_artifacts_are_reported',
                'runner_blocked_false_for_product_evidence',
            ],
            'smoke_subset_outcome' => 'non_passing',
            'storage_connection_smoke_only_outcome' => 'non_passing',
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed>|null $contract
     *
     * @return array<string, mixed>
     */
    public static function evaluate(array $result, ?array $contract = null): array
    {
        $contract ??= MigrationRuntimeContract::manifest();

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
                'code' => 'duplicate_scenario_result',
                'scenario_id' => $scenarioId,
                'count' => $count,
            ];
        }

        foreach ($requiredScenarios as $scenarioId) {
            if (! array_key_exists($scenarioId, $scenarioResults)) {
                $missingScenarios[] = $scenarioId;
                $failures[] = [
                    'code' => 'missing_required_scenario',
                    'scenario_id' => $scenarioId,
                ];
                continue;
            }

            $scenarioResult = $scenarioResults[$scenarioId];
            $status = self::stringValue($scenarioResult['status'] ?? null);
            $scenarioStatuses[$scenarioId] = $status;

            if (! in_array($status, $allowedStatuses, true)) {
                $failures[] = [
                    'code' => 'invalid_scenario_status',
                    'scenario_id' => $scenarioId,
                    'status' => $status,
                    'allowed_statuses' => $allowedStatuses,
                ];
                continue;
            }

            if ($status === 'pass') {
                if (self::scenarioMustBeNotApplicable($scenarioId, $result, $contract)) {
                    $failures[] = [
                        'code' => 'source_absent_scenario_must_be_not_applicable',
                        'scenario_id' => $scenarioId,
                    ];
                }
                if (! self::hasObservedOutputs($scenarioResult)) {
                    $failures[] = [
                        'code' => 'missing_pass_observed_outputs',
                        'scenario_id' => $scenarioId,
                    ];
                }

                foreach (self::missingRequiredFields($scenarioId, $scenarioResult, $contract, $result) as $field) {
                    $failures[] = [
                        'code' => 'missing_scenario_required_field',
                        'scenario_id' => $scenarioId,
                        'field' => $field,
                    ];
                }
            } elseif ($status === 'not_applicable') {
                if (! self::validNotApplicableScenario($scenarioId, $scenarioResult, $result, $contract)) {
                    $nonPassScenarios[] = $scenarioId;
                    $failures[] = [
                        'code' => 'invalid_not_applicable_scenario',
                        'scenario_id' => $scenarioId,
                    ];
                }
            } else {
                $nonPassScenarios[] = $scenarioId;
                if (! self::hasLinkedFindings($scenarioResult, $result)) {
                    $failures[] = [
                        'code' => 'missing_non_pass_finding',
                        'scenario_id' => $scenarioId,
                        'status' => $status,
                    ];
                }
            }
        }

        $reportedScenarioIds = array_keys($scenarioResults);
        $unknownScenarios = array_values(array_diff($reportedScenarioIds, $requiredScenarios));
        foreach ($unknownScenarios as $scenarioId) {
            $scenarioResult = $scenarioResults[$scenarioId];
            $status = self::stringValue($scenarioResult['status'] ?? null);
            if ($status !== '' && ! in_array($status, $allowedStatuses, true)) {
                $failures[] = [
                    'code' => 'invalid_extra_scenario_status',
                    'scenario_id' => $scenarioId,
                    'status' => $status,
                    'allowed_statuses' => $allowedStatuses,
                ];
            }
        }

        array_push($failures, ...self::runRecordFailures($result, $contract));
        array_push($failures, ...self::artifactVersionFailures($result, $contract));
        array_push($failures, ...self::sourcePolicyFailures($result, $contract, $scenarioResults));
        array_push($failures, ...self::declaredOutcomeFailures($result));

        $smokeSubsetDetected = self::isSmokeSubset($scenarioResults, $requiredScenarios);
        if ($smokeSubsetDetected) {
            $failures[] = [
                'code' => 'storage_connection_smoke_cannot_pass',
                'reason' => 'Storage-connection migration smoke is useful evidence but is not a complete v1-to-v2 migration result.',
            ];
        }

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
            'smoke_subset_detected' => $smokeSubsetDetected,
            'gate_failures' => $failures,
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, int> $duplicateScenarioCounts
     *
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
     * @param array<string, mixed> $scenarioResult
     */
    private static function hasObservedOutputs(array $scenarioResult): bool
    {
        foreach (['observed_outputs', 'observedOutputs', 'evidence'] as $field) {
            if (isset($scenarioResult[$field]) && is_array($scenarioResult[$field]) && $scenarioResult[$field] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     */
    private static function scenarioMustBeNotApplicable(
        string $scenarioId,
        array $result,
        array $contract,
    ): bool {
        $scenarioCapabilities = $contract['source_capability_policy']['not_applicable_scenarios'] ?? [];
        if (is_array($scenarioCapabilities)) {
            $capability = self::stringValue($scenarioCapabilities[$scenarioId] ?? null);
            if ($capability !== '') {
                return self::sourceCapabilityStatus($result, $capability) === 'unsupported';
            }
        }

        if ($scenarioId !== 'version_skew_refusal') {
            return false;
        }

        $cells = $contract['required_matrix']['skew_cells'] ?? [];
        if (! is_array($cells) || $cells === []) {
            return false;
        }

        foreach ($cells as $cell) {
            if (! is_array($cell)) {
                return false;
            }
            $requirements = self::stringList($cell['requires_source_capabilities'] ?? []);
            if ($requirements === []) {
                return false;
            }
            $hasAbsentCapability = false;
            foreach ($requirements as $capability) {
                if (self::sourceCapabilityStatus($result, $capability) === 'unsupported') {
                    $hasAbsentCapability = true;
                    break;
                }
            }
            if (! $hasAbsentCapability) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     */
    private static function validNotApplicableScenario(
        string $scenarioId,
        array $scenarioResult,
        array $result,
        array $contract,
    ): bool {
        if (! self::scenarioMustBeNotApplicable($scenarioId, $result, $contract)) {
            return false;
        }

        $outputs = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']) ?? [];
        if ($scenarioId === 'version_skew_refusal') {
            $applicability = self::arrayField($outputs, ['applicability_evidence', 'applicabilityEvidence']) ?? [];
            $cells = $contract['required_matrix']['skew_cells'] ?? [];
            foreach (is_array($cells) ? $cells : [] as $cell) {
                if (! is_array($cell)) {
                    return false;
                }
                $cellId = self::skewCellId($cell);
                $actual = is_array($applicability[$cellId] ?? null) ? $applicability[$cellId] : [];
                $requirements = self::stringList($cell['requires_source_capabilities'] ?? []);
                $expectedReasons = [];
                foreach ($requirements as $capability) {
                    if (self::sourceCapabilityStatus($result, $capability) === 'unsupported') {
                        $reason = self::sourceCapabilityReason($result, $capability);
                        if ($reason !== '') {
                            $expectedReasons[] = $reason;
                        }
                    }
                }
                $actualReasons = self::stringList($actual['reason_codes'] ?? $actual['reasonCodes'] ?? []);
                sort($expectedReasons);
                sort($actualReasons);
                if (
                    self::stringValue($actual['status'] ?? null) !== 'not_applicable'
                    || $expectedReasons === []
                    || $actualReasons !== $expectedReasons
                    || ($actual['durable_state_mutation_attempted'] ?? null) !== false
                ) {
                    return false;
                }
            }

            return true;
        }

        $scenarioCapabilities = $contract['source_capability_policy']['not_applicable_scenarios'] ?? [];
        $capability = is_array($scenarioCapabilities)
            ? self::stringValue($scenarioCapabilities[$scenarioId] ?? null)
            : '';
        $applicability = self::arrayField($outputs, ['applicability']) ?? [];

        return $capability !== ''
            && self::stringValue($applicability['status'] ?? null) === 'not_applicable'
            && self::stringValue($applicability['source_capability'] ?? $applicability['sourceCapability'] ?? null) === $capability
            && self::stringValue($applicability['reason_code'] ?? $applicability['reasonCode'] ?? null) === self::sourceCapabilityReason($result, $capability)
            && ($applicability['durable_state_mutation_attempted'] ?? null) === false;
    }

    /**
     * @param array<string, mixed> $cell
     */
    private static function skewCellId(array $cell): string
    {
        $server = self::stringValue($cell['server'] ?? null);
        $worker = self::stringValue($cell['worker'] ?? null);
        if ($worker !== '') {
            return preg_replace('/^workflow-php-/', 'worker-', $worker).'-to-'.$server;
        }

        return self::stringValue($cell['client'] ?? null).'-to-'.$server;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $contract
     *
     * @return list<string>
     */
    private static function missingRequiredFields(
        string $scenarioId,
        array $scenarioResult,
        array $contract,
        array $result,
    ): array {
        $requirements = $contract['scenario_requirements'][$scenarioId]['required_fields'] ?? [];
        if (! is_array($requirements) || $requirements === []) {
            return [];
        }

        $observedOutputs = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']) ?? [];
        $missing = [];

        foreach (self::stringList($requirements) as $field) {
            if (! self::scenarioRequiredFieldApplies($scenarioId, $field, $scenarioResult, $observedOutputs)) {
                continue;
            }
            if (
                ! self::hasAnyEvidenceValue($scenarioResult, self::fieldAliases($field))
                && ! self::hasAnyEvidenceValue($observedOutputs, self::fieldAliases($field))
            ) {
                $missing[] = $field;
            }
        }

        array_push(
            $missing,
            ...self::missingScenarioSpecificRequiredFields(
                $scenarioId,
                $scenarioResult,
                $observedOutputs,
                $result,
                $contract,
            ),
        );

        return array_values(array_unique($missing));
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $observedOutputs
     */
    private static function scenarioRequiredFieldApplies(
        string $scenarioId,
        string $field,
        array $scenarioResult,
        array $observedOutputs,
    ): bool {
        if ($scenarioId !== 'queue_state_preserved' || $field !== 'postupgrade_queue_state') {
            return true;
        }

        $result = self::fieldValue($observedOutputs, 'dequeue_or_completion_result');
        if (self::isEmptyEvidence($result)) {
            $result = self::fieldValue($scenarioResult, 'dequeue_or_completion_result');
        }
        $disposition = self::queueDisposition($result);

        return ! in_array($disposition, ['completed', 'recovered', 'refused'], true);
    }

    private static function queueDisposition(mixed $value): string
    {
        if (! is_array($value)) {
            return '';
        }
        if (
            ($value['completed'] ?? null) === true
            || ($value['task_completed'] ?? $value['taskCompleted'] ?? null) === true
        ) {
            return 'completed';
        }
        if (($value['deliberately_recovered'] ?? $value['deliberatelyRecovered'] ?? null) === true) {
            return 'recovered';
        }
        if (($value['explicitly_refused'] ?? $value['explicitlyRefused'] ?? null) === true) {
            return 'refused';
        }

        $raw = self::stringValue(
            $value['disposition']
                ?? $value['availability_state']
                ?? $value['availabilityState']
                ?? $value['task_status']
                ?? $value['taskStatus']
                ?? $value['outcome']
                ?? $value['status']
                ?? null,
        );
        $token = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', trim($raw)));

        return match ($token) {
            'complete', 'completed', 'succeeded', 'executed' => 'completed',
            'deliberately_recovered' => 'recovered',
            'explicitly_refused', 'rejected' => 'refused',
            default => $token,
        };
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $observedOutputs
     *
     * @return list<string>
     */
    private static function missingScenarioSpecificRequiredFields(
        string $scenarioId,
        array $scenarioResult,
        array $observedOutputs,
        array $result,
        array $contract,
    ): array {
        return match ($scenarioId) {
            'latest_supported_v1_state_setup' => [
                ...self::missingEvidenceItemsForField($scenarioResult, $observedOutputs, 'seeded_workflows', [
                    'completed_workflow',
                    'running_workflow_waiting_on_signal',
                    'workflow_with_activity',
                    'workflow_mid_activity_retry',
                ]),
                ...self::missingEvidenceItemsForField($scenarioResult, $observedOutputs, 'seeded_schedules', [
                    'active_schedule',
                ]),
                ...self::missingEvidenceItemsForField($scenarioResult, $observedOutputs, 'seeded_worker_registrations', [
                    'registered_workers',
                ]),
                ...self::missingEvidenceItemsForField($scenarioResult, $observedOutputs, 'seeded_queue_state', [
                    'queued_task',
                ]),
                ...self::missingEvidenceItemsForField($scenarioResult, $observedOutputs, 'queryable_history', [
                    'queryable_history',
                ]),
            ],
            'documented_migration_steps_execute' => [
                ...self::missingArrayEvidenceFields(
                    $scenarioResult,
                    $observedOutputs,
                    [
                        'commands_executed',
                        'exit_codes',
                        'command_timings',
                    ],
                ),
                ...self::missingGuideCommandExecutabilityFields($scenarioResult, $observedOutputs),
            ],
            'rollback_contract_verified' => [
                ...self::missingArrayEvidenceFields(
                    $scenarioResult,
                    $observedOutputs,
                    [
                        'rollback_steps',
                    ],
                ),
                ...self::missingRollbackClassificationFields($scenarioResult, $observedOutputs),
            ],
            'cli_access_to_preupgrade_state' => self::missingEvidenceItemsForField(
                $scenarioResult,
                $observedOutputs,
                'typed_response_contracts',
                ['cli', 'operator_api'],
            ),
            'new_v2_schedule_after_upgrade' => self::missingEvidenceItemsForField(
                $scenarioResult,
                $observedOutputs,
                'typed_response_contracts',
                ['cli', 'operator_api', 'schedule'],
            ),
            'new_v2_worker_registration_after_upgrade' => [
                ...self::missingEvidenceItemsForField(
                    $scenarioResult,
                    $observedOutputs,
                    'typed_response_contracts',
                    ['cli', 'operator_api', 'worker_registration', 'worker_poll'],
                ),
                ...self::missingEvidenceItemsForField(
                    $scenarioResult,
                    $observedOutputs,
                    'task_queue_projection',
                    [
                        'worker_id',
                        'namespace',
                        'task_queue',
                        'status',
                        'last_heartbeat_at',
                        'task_slots',
                        'runtime',
                        'sdk_version',
                        'build_id',
                        'capabilities',
                    ],
                ),
                ...self::missingEvidenceItemsForField(
                    $scenarioResult,
                    $observedOutputs,
                    'cli_worker_projection',
                    [
                        'worker_id',
                        'namespace',
                        'task_queue',
                        'status',
                        'last_heartbeat_at',
                        'task_slots',
                        'runtime',
                        'sdk_version',
                        'build_id',
                        'capabilities',
                    ],
                ),
                ...self::missingEvidenceItemsForField(
                    $scenarioResult,
                    $observedOutputs,
                    'protocol_metadata',
                    ['registration', 'poll', 'operator_api', 'cli'],
                ),
                ...self::missingEvidenceItemsForField(
                    $scenarioResult,
                    $observedOutputs,
                    'freshness',
                    ['stale_after_seconds', 'operator_api', 'cli'],
                ),
                ...self::missingEvidenceItemsForField(
                    $scenarioResult,
                    $observedOutputs,
                    'polling_result',
                    ['request', 'response', 'exit_code', 'started_at', 'finished_at'],
                ),
                ...self::missingEvidenceItemsForField(
                    $scenarioResult,
                    $observedOutputs,
                    'request_response_evidence',
                    ['registration', 'operator_api', 'cli', 'poll'],
                ),
                ...self::missingEvidenceItemsForField(
                    $scenarioResult,
                    $observedOutputs,
                    'exit_codes',
                    ['registration', 'operator_api', 'cli', 'poll'],
                ),
                ...self::missingEvidenceItemsForField(
                    $scenarioResult,
                    $observedOutputs,
                    'timestamps',
                    ['registration', 'operator_api', 'cli', 'poll'],
                ),
                ...(
                    self::fieldValue($observedOutputs, 'unique_task_queue') === true
                    || self::fieldValue($scenarioResult, 'unique_task_queue') === true
                        ? []
                        : ['unique_task_queue.true']
                ),
                ...self::missingFocusedWorkerRegistrationSemanticFields(
                    $scenarioResult,
                    $observedOutputs,
                ),
            ],
            'version_skew_refusal' => [
                ...self::missingArrayEvidenceFields(
                    $scenarioResult,
                    $observedOutputs,
                    [
                        'skew_matrix',
                        'refusal_errors',
                        'request_response_evidence',
                        'no_partial_mutation_evidence',
                    ],
                ),
                ...self::missingEvidenceItemsForField($scenarioResult, $observedOutputs, 'cli_skew_observations', [
                    ...self::applicableSkewCellIds('client', $result, $contract),
                ]),
                ...self::missingEvidenceItemsForField($scenarioResult, $observedOutputs, 'worker_skew_observations', [
                    ...self::applicableSkewCellIds('worker', $result, $contract),
                ]),
                ...self::missingEvidenceItemsForField($scenarioResult, $observedOutputs, 'request_response_evidence', [
                    ...self::applicableSkewCellIds('client', $result, $contract),
                    ...self::applicableSkewCellIds('worker', $result, $contract),
                ]),
                ...self::missingSkewApplicabilityFields($scenarioResult, $observedOutputs, $result, $contract),
            ],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $observedOutputs
     *
     * @return list<string>
     */
    private static function missingFocusedWorkerRegistrationSemanticFields(
        array $scenarioResult,
        array $observedOutputs,
    ): array {
        $field = static function (string $name) use ($scenarioResult, $observedOutputs): mixed {
            $value = self::fieldValue($observedOutputs, $name);

            return self::isEmptyEvidence($value)
                ? self::fieldValue($scenarioResult, $name)
                : $value;
        };
        $apiProjection = $field('task_queue_projection');
        $cliProjection = $field('cli_worker_projection');
        $requestResponse = $field('request_response_evidence');
        $exitCodes = $field('exit_codes');
        $timestamps = $field('timestamps');
        $freshness = $field('freshness');
        $missing = [];

        $apiProjection = is_array($apiProjection) ? $apiProjection : [];
        $cliProjection = is_array($cliProjection) ? $cliProjection : [];
        $requestResponse = is_array($requestResponse) ? $requestResponse : [];
        $exitCodes = is_array($exitCodes) ? $exitCodes : [];
        $timestamps = is_array($timestamps) ? $timestamps : [];
        $freshness = is_array($freshness) ? $freshness : [];

        foreach (['registration', 'operator_api', 'cli', 'poll'] as $operation) {
            $observation = is_array($requestResponse[$operation] ?? null)
                ? $requestResponse[$operation]
                : [];
            if (
                ($observation['response_observed_from_command_stdout'] ?? null) !== true
                || ($observation['response_source'] ?? null) !== 'command_stdout_json'
            ) {
                $missing[] = "request_response_evidence.{$operation}.command_stdout_response";
            }
            if (! array_key_exists($operation, $exitCodes) || (int) $exitCodes[$operation] !== 0) {
                $missing[] = "exit_codes.{$operation}.zero";
            }
        }

        foreach (['registration', 'operator_api', 'poll'] as $operation) {
            $observation = is_array($requestResponse[$operation] ?? null)
                ? $requestResponse[$operation]
                : [];
            $httpStatus = filter_var($observation['http_status'] ?? null, FILTER_VALIDATE_INT);
            if ($httpStatus === false || $httpStatus < 200 || $httpStatus >= 300) {
                $missing[] = "request_response_evidence.{$operation}.http_status_2xx";
            }
        }

        $staleAfter = filter_var($freshness['stale_after_seconds'] ?? null, FILTER_VALIDATE_INT);
        if ($staleAfter === false || $staleAfter <= 0) {
            $missing[] = 'freshness.stale_after_seconds.positive';
        }
        if ($staleAfter !== self::FOCUSED_WORKER_PROJECTION_STALE_AFTER_SECONDS) {
            $missing[] = 'freshness.stale_after_seconds.300';
        }
        foreach (['operator_api' => $apiProjection, 'cli' => $cliProjection] as $surface => $projection) {
            $surfaceFreshness = is_array($freshness[$surface] ?? null) ? $freshness[$surface] : [];
            if (($surfaceFreshness['valid'] ?? null) !== true) {
                $missing[] = "freshness.{$surface}.valid";
            }
            if (strtolower(self::stringValue($projection['status'] ?? null)) !== 'active') {
                $missing[] = "{$surface}.status.active";
            }

            $heartbeatValue = self::stringValue($projection['last_heartbeat_at'] ?? null);
            $heartbeat = preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/',
                $heartbeatValue,
            ) === 1 ? strtotime($heartbeatValue) : false;
            $operationTimestamp = is_array($timestamps[$surface] ?? null) ? $timestamps[$surface] : [];
            $observedAtValue = self::stringValue($operationTimestamp['finished_at'] ?? null);
            $observedAt = preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/',
                $observedAtValue,
            ) === 1 ? strtotime($observedAtValue) : false;
            if ($heartbeat === false) {
                $missing[] = "{$surface}.last_heartbeat_at.valid";
            } elseif ($observedAt === false) {
                $missing[] = "timestamps.{$surface}.finished_at.valid";
            } elseif (
                $heartbeat > $observedAt
                || $observedAt - $heartbeat > self::FOCUSED_WORKER_PROJECTION_STALE_AFTER_SECONDS
            ) {
                $missing[] = "freshness.{$surface}.within_stale_window";
            }

            $taskSlots = is_array($projection['task_slots'] ?? null) ? $projection['task_slots'] : [];
            foreach ([
                'workflow_available',
                'activity_available',
                'session_available',
                'workflow_capacity',
                'activity_capacity',
                'session_capacity',
            ] as $slot) {
                if (! is_int($taskSlots[$slot] ?? null)) {
                    $missing[] = "{$surface}.task_slots.{$slot}";
                }
            }
        }

        foreach ([
            'worker_id',
            'namespace',
            'task_queue',
            'status',
            'last_heartbeat_at',
            'task_slots',
            'runtime',
            'sdk_version',
            'build_id',
            'capabilities',
        ] as $projectionField) {
            if (($apiProjection[$projectionField] ?? null) != ($cliProjection[$projectionField] ?? null)) {
                $missing[] = "typed_response_contracts.api_cli_projection_match.{$projectionField}";
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $observedOutputs
     *
     * @return list<string>
     */
    private static function missingRollbackClassificationFields(
        array $scenarioResult,
        array $observedOutputs,
    ): array {
        $value = self::fieldValue($observedOutputs, 'rollback_supported_state');
        if (self::isEmptyEvidence($value)) {
            $value = self::fieldValue($scenarioResult, 'rollback_supported_state');
        }

        if (self::hasEvidenceToken($value, ['supported', 'refused', 'irreversible', 'unsupported'])) {
            return [];
        }

        return ['rollback_supported_state.supported_refused_or_irreversible'];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return list<string>
     */
    private static function applicableSkewCellIds(string $kind, array $result, array $contract): array
    {
        $cells = $contract['required_matrix']['skew_cells'] ?? [];
        if (! is_array($cells)) {
            return [];
        }

        $ids = [];
        foreach ($cells as $cell) {
            if (! is_array($cell)) {
                continue;
            }
            $field = $kind === 'worker' ? 'worker' : 'client';
            if (self::stringValue($cell[$field] ?? null) === '') {
                continue;
            }
            $requirements = self::stringList($cell['requires_source_capabilities'] ?? []);
            $absent = array_filter(
                $requirements,
                static fn (string $capability): bool => self::sourceCapabilityStatus($result, $capability) === 'unsupported',
            );
            if ($absent === []) {
                $ids[] = self::skewCellId($cell);
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $observedOutputs
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return list<string>
     */
    private static function missingSkewApplicabilityFields(
        array $scenarioResult,
        array $observedOutputs,
        array $result,
        array $contract,
    ): array {
        $value = self::fieldValue($observedOutputs, 'applicability_evidence');
        if (self::isEmptyEvidence($value)) {
            $value = self::fieldValue($scenarioResult, 'applicability_evidence');
        }
        $actual = is_array($value) ? $value : [];
        $cells = $contract['required_matrix']['skew_cells'] ?? [];
        $missing = [];

        foreach (is_array($cells) ? $cells : [] as $cell) {
            if (! is_array($cell)) {
                continue;
            }
            $cellId = self::skewCellId($cell);
            $entry = is_array($actual[$cellId] ?? null) ? $actual[$cellId] : [];
            $requirements = self::stringList($cell['requires_source_capabilities'] ?? []);
            $expectedReasons = [];
            foreach ($requirements as $capability) {
                if (self::sourceCapabilityStatus($result, $capability) === 'unsupported') {
                    $reason = self::sourceCapabilityReason($result, $capability);
                    if ($reason !== '') {
                        $expectedReasons[] = $reason;
                    }
                }
            }
            $expectedStatus = $expectedReasons === [] ? 'applicable' : 'not_applicable';
            if (self::stringValue($entry['status'] ?? null) !== $expectedStatus) {
                $missing[] = 'applicability_evidence.'.$cellId.'.status_'.$expectedStatus;
                continue;
            }
            if ($expectedStatus === 'applicable') {
                continue;
            }

            $actualReasons = self::stringList($entry['reason_codes'] ?? $entry['reasonCodes'] ?? []);
            sort($actualReasons);
            sort($expectedReasons);
            if ($actualReasons !== $expectedReasons) {
                $missing[] = 'applicability_evidence.'.$cellId.'.stable_reason_codes';
            }
            if (($entry['durable_state_mutation_attempted'] ?? null) !== false) {
                $missing[] = 'applicability_evidence.'.$cellId.'.no_durable_state_mutation';
            }
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $observedOutputs
     *
     * @return list<string>
     */
    private static function missingGuideCommandExecutabilityFields(
        array $scenarioResult,
        array $observedOutputs,
    ): array {
        $value = self::fieldValue($observedOutputs, 'guide_command_executability');
        if (self::isEmptyEvidence($value)) {
            $value = self::fieldValue($scenarioResult, 'guide_command_executability');
        }

        if (self::isEmptyEvidence($value)) {
            return ['guide_command_executability'];
        }

        $evidence = is_array($value) ? $value : [];
        $status = strtolower(self::stringValue($evidence['status'] ?? null));
        $unexecutableCommands = $evidence['unexecutable_commands'] ?? $evidence['unexecutableCommands'] ?? [];
        $missing = [];

        if ($status !== 'pass') {
            $missing[] = 'guide_command_executability.status_pass';
        }

        if (is_array($unexecutableCommands) && $unexecutableCommands !== []) {
            $missing[] = 'guide_command_executability.unexecutable_commands_empty';
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $observedOutputs
     * @param list<string> $items
     *
     * @return list<string>
     */
    private static function missingEvidenceItemsForField(
        array $scenarioResult,
        array $observedOutputs,
        string $field,
        array $items,
    ): array {
        $value = self::fieldValue($observedOutputs, $field);
        if (self::isEmptyEvidence($value)) {
            $value = self::fieldValue($scenarioResult, $field);
        }

        $missing = [];
        foreach ($items as $item) {
            if (! self::hasEvidenceItem($value, $item)) {
                $missing[] = $field . '.' . $item;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $observedOutputs
     * @param list<string> $fields
     *
     * @return list<string>
     */
    private static function missingArrayEvidenceFields(
        array $scenarioResult,
        array $observedOutputs,
        array $fields,
    ): array {
        $missing = [];
        foreach ($fields as $field) {
            if (
                ! self::hasNonEmptyArrayEvidence($observedOutputs, $field)
                && ! self::hasNonEmptyArrayEvidence($scenarioResult, $field)
            ) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $result
     */
    private static function hasLinkedFindings(array $scenarioResult, array $result): bool
    {
        foreach (['linked_findings', 'linkedFindings', 'findings', 'finding_links', 'findingLinks'] as $field) {
            if (isset($scenarioResult[$field]) && self::nonEmptyFindingValue($scenarioResult[$field])) {
                return true;
            }
        }

        $scenarioId = self::stringValue($scenarioResult['scenario_id'] ?? null);
        foreach (['finding_links', 'findingLinks', 'linked_findings', 'linkedFindings', 'findings'] as $field) {
            $value = $result[$field] ?? null;
            if (is_array($value) && $scenarioId !== '' && isset($value[$scenarioId]) && self::nonEmptyFindingValue($value[$scenarioId])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     */
    private static function nonEmptyFindingValue(mixed $value): bool
    {
        if (is_string($value)) {
            return $value !== '';
        }

        return is_array($value) && $value !== [];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
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

        $runnerBlocked = self::runnerBlockedValue($result);
        if ($runnerBlocked !== null && $runnerBlocked !== false) {
            $failures[] = [
                'code' => 'runner_blocked_result_is_not_product_evidence',
            ];
        }

        array_push($failures, ...self::stateSnapshotFailures($result, $contract));
        array_push($failures, ...self::sourceCapabilityFailures($result, $contract));

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return list<array<string, mixed>>
     */
    private static function stateSnapshotFailures(array $result, array $contract): array
    {
        $failures = [];
        $requiredStateKinds = self::stringList($contract['required_matrix']['state_kinds'] ?? []);

        foreach (['preupgrade_state_snapshot', 'postupgrade_state_snapshot'] as $field) {
            $snapshot = self::fieldValue($result, $field);
            if (! is_array($snapshot) || self::isEmptyEvidence($snapshot)) {
                continue;
            }

            $stateKinds = self::observedStateKindsForSnapshot($snapshot, $requiredStateKinds);
            foreach ($requiredStateKinds as $stateKind) {
                if (
                    $field === 'preupgrade_state_snapshot'
                    && self::sourceStateKindNotApplicable($result, $contract, $stateKind)
                ) {
                    continue;
                }
                if (isset($stateKinds[$stateKind])) {
                    continue;
                }

                $failures[] = [
                    'code' => 'missing_run_record_state_kind',
                    'field' => $field,
                    'state_kind' => $stateKind,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return list<array<string, mixed>>
     */
    private static function sourceCapabilityFailures(array $result, array $contract): array
    {
        $failures = [];
        $inventory = self::sourceCapabilities($result);
        $policy = is_array($contract['source_capability_policy'] ?? null)
            ? $contract['source_capability_policy']
            : [];
        $definitions = is_array($policy['required_capabilities'] ?? null)
            ? $policy['required_capabilities']
            : [];

        if ($inventory === [] || self::stringValue($inventory['status'] ?? null) !== 'complete') {
            return [[
                'code' => 'missing_or_incomplete_source_capability_inventory',
            ]];
        }

        if (($inventory['inventoried_before_continuity'] ?? null) !== true) {
            $failures[] = [
                'code' => 'source_capabilities_not_inventoried_before_continuity',
            ];
        }
        foreach (['source_artifact', 'source_version', 'runtime_topology', 'inventory_source'] as $field) {
            if (self::stringValue($inventory[$field] ?? null) === '') {
                $failures[] = [
                    'code' => 'missing_source_capability_inventory_field',
                    'field' => $field,
                ];
            }
        }

        $capabilities = is_array($inventory['capabilities'] ?? null) ? $inventory['capabilities'] : [];
        foreach ($definitions as $capability => $definitionValue) {
            if (! is_string($capability)) {
                continue;
            }
            $entry = is_array($capabilities[$capability] ?? null) ? $capabilities[$capability] : [];
            $status = self::stringValue($entry['status'] ?? null);
            if (! in_array($status, ['supported', 'unsupported'], true)) {
                $failures[] = [
                    'code' => 'missing_source_capability_status',
                    'capability' => $capability,
                ];
                continue;
            }
            if (self::stringValue($entry['evidence_basis'] ?? $entry['evidenceBasis'] ?? null) === '') {
                $failures[] = [
                    'code' => 'missing_source_capability_evidence_basis',
                    'capability' => $capability,
                ];
            }

            $definition = is_array($definitionValue) ? $definitionValue : [];
            $expectedReason = self::stringValue($definition['absent_reason_code'] ?? null);
            if (
                $status === 'unsupported'
                && $expectedReason !== ''
                && self::stringValue($entry['reason_code'] ?? $entry['reasonCode'] ?? null) !== $expectedReason
            ) {
                $failures[] = [
                    'code' => 'invalid_source_capability_reason',
                    'capability' => $capability,
                    'expected_reason_code' => $expectedReason,
                ];
            }

            if (
                self::stringValue($definition['continuity'] ?? null) === 'required'
                && $status !== 'supported'
            ) {
                $failures[] = [
                    'code' => 'required_v1_durable_state_capability_unsupported',
                    'capability' => $capability,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     */
    private static function sourceStateKindNotApplicable(array $result, array $contract, string $stateKind): bool
    {
        $definitions = $contract['source_capability_policy']['required_capabilities'] ?? [];
        if (! is_array($definitions)) {
            return false;
        }

        foreach ($definitions as $capability => $definitionValue) {
            if (! is_string($capability) || ! is_array($definitionValue)) {
                continue;
            }
            if (
                self::stringValue($definitionValue['state_kind'] ?? null) === $stateKind
                && self::stringValue($definitionValue['continuity'] ?? null) === 'when_source_supported'
            ) {
                return self::sourceCapabilityStatus($result, $capability) === 'unsupported';
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private static function sourceCapabilities(array $result): array
    {
        return self::arrayField($result, [
            'source_capabilities',
            'sourceCapabilities',
            'v1_capabilities',
            'v1Capabilities',
        ]) ?? [];
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function sourceCapabilityStatus(array $result, string $capability): string
    {
        $inventory = self::sourceCapabilities($result);
        $capabilities = is_array($inventory['capabilities'] ?? null) ? $inventory['capabilities'] : [];
        $entry = is_array($capabilities[$capability] ?? null) ? $capabilities[$capability] : [];

        return self::stringValue($entry['status'] ?? null);
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function sourceCapabilityReason(array $result, string $capability): string
    {
        $inventory = self::sourceCapabilities($result);
        $capabilities = is_array($inventory['capabilities'] ?? null) ? $inventory['capabilities'] : [];
        $entry = is_array($capabilities[$capability] ?? null) ? $capabilities[$capability] : [];

        return self::stringValue($entry['reason_code'] ?? $entry['reasonCode'] ?? null);
    }

    /**
     * @param array<mixed> $snapshot
     *
     * @return array<string, true>
     */
    private static function observedStateKindsForSnapshot(array $snapshot, array $requiredStateKinds): array
    {
        $observed = [];
        $required = array_fill_keys($requiredStateKinds, true);

        self::collectObservedStateEntries($snapshot, $observed, $required);

        foreach ([
            'observed_states',
            'observedStates',
            'observed_state_entries',
            'observedStateEntries',
            'state_entries',
            'stateEntries',
            'states',
        ] as $field) {
            if (isset($snapshot[$field]) && is_array($snapshot[$field])) {
                self::collectObservedStateEntries($snapshot[$field], $observed, $required);
            }
        }

        return $observed;
    }

    /**
     * @param array<mixed> $entries
     * @param array<string, true> $observed
     * @param array<string, true> $required
     */
    private static function collectObservedStateEntries(array $entries, array &$observed, array $required): void
    {
        $isList = array_is_list($entries);

        foreach ($entries as $key => $entry) {
            if ($isList) {
                self::collectObservedStateEntryKind($entry, $observed, $required);

                continue;
            }

            if (
                is_string($key)
                && isset($required[$key])
                && self::hasObservedStateCellEvidence($entry)
            ) {
                $observed[$key] = true;
            }

            self::collectObservedStateEntryKind($entry, $observed, $required);
        }
    }

    /**
     * @param array<string, true> $observed
     * @param array<string, true> $required
     */
    private static function collectObservedStateEntryKind(mixed $entry, array &$observed, array $required): void
    {
        if (! is_array($entry)) {
            return;
        }

        foreach (['state_kind', 'stateKind', 'kind', 'type', 'name', 'scenario'] as $field) {
            $kind = self::stringValue($entry[$field] ?? null);
            if ($kind !== '' && isset($required[$kind]) && self::hasObservedStateCellEvidence($entry)) {
                $observed[$kind] = true;
            }
        }
    }

    /**
     * @param mixed $value
     */
    private static function hasObservedStateCellEvidence(mixed $value): bool
    {
        if (self::isEmptyEvidence($value)) {
            return false;
        }

        if (! is_array($value)) {
            return true;
        }

        foreach ($value as $key => $entry) {
            if (is_string($key) && in_array($key, self::stateCellMetadataFields(), true)) {
                continue;
            }

            if (! self::isEmptyEvidence($entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function stateCellMetadataFields(): array
    {
        return [
            'state_kind',
            'stateKind',
            'kind',
            'type',
            'name',
            'scenario',
            'status',
            'phase',
            'state_kinds',
            'stateKinds',
            'expected_state_kinds',
            'expectedStateKinds',
        ];
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function hasRunRecordField(array $result, string $field): bool
    {
        return match ($field) {
            'published_artifact_versions' => ! self::isEmptyEvidence(self::arrayField($result, [
                'published_artifact_versions',
                'publishedArtifactVersions',
            ])),
            'resolved_artifact_versions' => ! self::isEmptyEvidence(self::arrayField($result, [
                'resolved_artifact_versions',
                'resolvedArtifactVersions',
            ])),
            'started_at' => self::hasScalarField($result, ['started_at', 'startedAt']),
            'finished_at' => self::hasScalarField($result, ['finished_at', 'finishedAt']),
            'generated_at' => self::hasScalarField($result, ['generated_at', 'generatedAt']),
            'outcome' => self::declaredOutcomeTokens($result) !== [],
            'runner_blocked' => self::runnerBlockedValue($result) !== null,
            'scenario_results' => self::arrayField($result, ['scenario_results', 'scenarioResults']) !== null,
            'findings' => self::hasArrayKey($result, ['findings']),
            'finding_links' => self::hasArrayKey($result, ['finding_links', 'findingLinks']),
            default => ! self::isEmptyEvidence(self::fieldValue($result, $field)),
        };
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return list<array<string, mixed>>
     */
    private static function artifactVersionFailures(array $result, array $contract): array
    {
        $failures = [];
        $requiredArtifacts = self::stringList(array_keys($contract['artifact_policy']['install_channels'] ?? []));
        $aliases = $contract['artifact_policy']['release_artifact_aliases'] ?? [];
        $placeholders = self::stringList($contract['artifact_policy']['placeholder_version_examples'] ?? []);

        foreach ([
            'artifact_versions' => ['artifact_versions', 'artifactVersions'],
            'published_artifact_versions' => ['published_artifact_versions', 'publishedArtifactVersions'],
            'resolved_artifact_versions' => ['resolved_artifact_versions', 'resolvedArtifactVersions'],
        ] as $field => $fieldAliases) {
            $versions = self::arrayField($result, $fieldAliases);
            if ($versions === null) {
                continue;
            }

            foreach ($requiredArtifacts as $artifact) {
                $version = self::artifactVersionFor($versions, $artifact, is_array($aliases) ? $aliases : []);
                if ($version === '') {
                    $failures[] = [
                        'code' => 'missing_artifact_version',
                        'field' => $field,
                        'artifact' => $artifact,
                    ];
                    continue;
                }

                foreach ($placeholders as $placeholder) {
                    if ($placeholder !== '' && str_contains(strtolower($version), strtolower($placeholder))) {
                        $failures[] = [
                            'code' => 'placeholder_artifact_version',
                            'field' => $field,
                            'artifact' => $artifact,
                            'version' => $version,
                            'placeholder' => $placeholder,
                        ];
                        break;
                    }
                }
            }
        }

        return $failures;
    }

    /**
     * @param array<int|string, mixed> $versions
     * @param array<string, mixed> $aliases
     */
    private static function artifactVersionFor(array $versions, string $artifact, array $aliases): string
    {
        if (isset($versions[$artifact]) && (is_string($versions[$artifact]) || is_numeric($versions[$artifact]))) {
            $version = self::stringValue($versions[$artifact]);
            if ($version !== '') {
                return $version;
            }
        }

        foreach (self::stringList($aliases[$artifact] ?? []) as $alias) {
            if (isset($versions[$alias]) && (is_string($versions[$alias]) || is_numeric($versions[$alias]))) {
                $version = self::stringValue($versions[$alias]);
                if ($version !== '') {
                    return $version;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return list<array<string, mixed>>
     */
    private static function sourcePolicyFailures(array $result, array $contract, array $scenarioResults): array
    {
        $failures = [];
        $forbiddenSources = self::stringList($contract['artifact_policy']['forbidden_sources'] ?? []);
        $sourceSets = self::reportedArtifactSourceSets($result, $scenarioResults);
        $installSources = [];
        $localSourceFlags = self::localProductSourceFlagReports($result, $scenarioResults);

        if ($localSourceFlags === []) {
            $failures[] = [
                'code' => 'missing_local_product_source_policy',
            ];
        }

        foreach ($localSourceFlags as $flag) {
            if (! self::boolValue($flag['value'])) {
                continue;
            }

            $failure = [
                'code' => 'local_product_source_artifacts_reported',
                'field' => $flag['field'],
                'value' => $flag['value'],
            ];
            if ($flag['scenario_id'] !== null) {
                $failure['scenario_id'] = $flag['scenario_id'];
            }

            $failures[] = $failure;
        }

        if ($sourceSets === []) {
            $failures[] = [
                'code' => 'missing_artifact_sources',
            ];
        }

        foreach ($sourceSets as $sourceSet) {
            foreach ($sourceSet['sources'] as $artifact => $source) {
                if (
                    ($sourceSet['counts_for_required_sources'] ?? false)
                    && is_string($artifact)
                    && in_array($sourceSet['scenario_id'], [null, 'published_artifact_install_only'], true)
                ) {
                    if (self::sourceValueRecorded($source) || ! array_key_exists($artifact, $installSources)) {
                        $installSources[$artifact] = $source;
                    }
                }

                $sourceString = is_scalar($source) ? (string) $source : json_encode($source);
                $sourceString = is_string($sourceString) ? $sourceString : '';

                foreach ($forbiddenSources as $forbiddenSource) {
                    if ($forbiddenSource === '' || ! str_contains(strtolower($sourceString), strtolower($forbiddenSource))) {
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

        foreach (array_keys($contract['artifact_policy']['install_channels'] ?? []) as $artifact) {
            $artifact = (string) $artifact;
            if (self::sourceValueRecorded($installSources[$artifact] ?? null)) {
                continue;
            }

            $failures[] = [
                'code' => 'missing_published_artifact_install_source',
                'scenario_id' => 'published_artifact_install_only',
                'artifact' => $artifact,
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return list<array{field: string, scenario_id: string|null, value: mixed}>
     */
    private static function localProductSourceFlagReports(array $result, array $scenarioResults): array
    {
        $reports = [];

        self::appendLocalProductSourceFlagReports($reports, $result, null, '');

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
     * @param list<array{field: string, scenario_id: string|null, value: mixed}> $reports
     * @param array<string, mixed> $container
     */
    private static function appendLocalProductSourceFlagReports(
        array &$reports,
        array $container,
        ?string $scenarioId,
        string $fieldPrefix,
    ): void {
        foreach (['local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed'] as $field) {
            if (! array_key_exists($field, $container)) {
                continue;
            }

            $reports[] = [
                'field' => $fieldPrefix . $field,
                'scenario_id' => $scenarioId,
                'value' => $container[$field],
            ];
        }
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return list<array{sources: array<mixed>, field: string, scenario_id: string|null, counts_for_required_sources: bool}>
     */
    private static function reportedArtifactSourceSets(array $result, array $scenarioResults): array
    {
        $sourceSets = [];
        $containers = [
            [
                'container' => $result,
                'scenario_id' => null,
            ],
        ];

        foreach ($scenarioResults as $scenarioId => $scenarioResult) {
            $containers[] = [
                'container' => $scenarioResult,
                'scenario_id' => $scenarioId,
            ];
            $outputs = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']);
            if ($outputs !== null) {
                $containers[] = [
                    'container' => $outputs,
                    'scenario_id' => $scenarioId,
                ];
            }
        }

        foreach ($containers as $entry) {
            $container = $entry['container'];
            if (! is_array($container)) {
                continue;
            }

            foreach ([
                'artifact_sources' => true,
                'artifactSources' => true,
                'install_sources' => true,
                'installSources' => true,
                'source_paths' => false,
                'sourcePaths' => false,
            ] as $field => $countsForRequiredSources) {
                $sources = self::arrayField($container, [$field]);
                if ($sources === null) {
                    continue;
                }

                $sourceSets[] = [
                    'sources' => $sources,
                    'field' => $field,
                    'scenario_id' => is_string($entry['scenario_id']) ? $entry['scenario_id'] : null,
                    'counts_for_required_sources' => $countsForRequiredSources,
                ];
            }
        }

        return $sourceSets;
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

    /**
     * @param array<string, mixed> $result
     *
     * @return list<array<string, mixed>>
     */
    private static function declaredOutcomeFailures(array $result): array
    {
        $declared = [];
        foreach (['outcome', 'status', 'verdict'] as $field) {
            $value = self::stringValue($result[$field] ?? null);
            if ($value !== '') {
                $declared[$field] = $value;
            }
        }

        if ($declared === []) {
            return [[
                'code' => 'missing_declared_outcome',
            ]];
        }

        if (count(array_unique($declared)) > 1) {
            return [[
                'code' => 'conflicting_outcome_tokens',
                'declared_outcomes' => $declared,
            ]];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<array<string, mixed>>
     */
    private static function declaredOutcomeStatusFailures(array $result, string $evaluatedStatus): array
    {
        $declared = [];
        foreach (['outcome', 'status', 'verdict'] as $field) {
            $value = self::stringValue($result[$field] ?? null);
            if ($value !== '') {
                $declared[$field] = $value;
            }
        }

        if ($declared === []) {
            return [];
        }

        $declaredPasses = array_values(array_unique($declared)) === ['pass'];
        if ($evaluatedStatus === 'pass' && ! $declaredPasses) {
            return [[
                'code' => 'declared_outcome_status_mismatch',
                'declared_outcomes' => $declared,
                'evaluated_status' => $evaluatedStatus,
            ]];
        }

        if ($evaluatedStatus !== 'pass' && $declaredPasses) {
            return [[
                'code' => 'declared_outcome_status_mismatch',
                'declared_outcomes' => $declared,
                'evaluated_status' => $evaluatedStatus,
            ]];
        }

        return [];
    }

    /**
     * @param array<string, array<string, mixed>> $scenarioResults
     * @param list<string> $requiredScenarios
     */
    private static function isSmokeSubset(array $scenarioResults, array $requiredScenarios): bool
    {
        if ($scenarioResults === []) {
            return false;
        }

        $reported = array_keys($scenarioResults);
        $smokeOnlyIds = [
            'storage_connection_smoke',
            'migration_storage_connection_smoke',
            'published_artifact_install_only',
        ];

        if (array_diff($reported, $smokeOnlyIds) === []) {
            return true;
        }

        if (
            (isset($scenarioResults['storage_connection_smoke']) || isset($scenarioResults['migration_storage_connection_smoke']))
            && count(array_intersect($reported, $requiredScenarios)) < count($requiredScenarios)
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function runnerBlockedValue(array $result): ?bool
    {
        foreach (['runner_blocked', 'runnerBlocked'] as $field) {
            if (! array_key_exists($field, $result)) {
                continue;
            }

            return is_bool($result[$field]) ? $result[$field] : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, string>
     */
    private static function declaredOutcomeTokens(array $result): array
    {
        $declared = [];
        foreach (['outcome', 'status', 'verdict'] as $field) {
            $value = self::stringValue($result[$field] ?? null);
            if ($value !== '') {
                $declared[$field] = $value;
            }
        }

        return $declared;
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function fieldValue(array $result, string $field): mixed
    {
        foreach (self::fieldAliases($field) as $alias) {
            if (array_key_exists($alias, $result)) {
                return $result[$alias];
            }
        }

        return null;
    }

    private static function isEmptyEvidence(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '' || self::isPlaceholderEvidenceString($value);
        }

        if (! is_array($value)) {
            return false;
        }

        if ($value === []) {
            return true;
        }

        $status = strtolower(self::stringValue($value['status'] ?? null));
        if (
            in_array($status, ['not_covered', 'runner_blocked'], true)
            || self::boolValue($value['coverage_gap'] ?? false)
        ) {
            return true;
        }

        foreach ($value as $key => $entry) {
            if (is_string($key) && in_array($key, self::EVIDENCE_METADATA_FIELDS, true)) {
                continue;
            }

            if (! self::isEmptyEvidence($entry)) {
                return false;
            }
        }

        return true;
    }

    private static function isPlaceholderEvidenceString(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return true;
        }

        foreach (self::PLACEHOLDER_EVIDENCE_TOKENS as $token) {
            if ($normalized === $token || str_contains($normalized, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $result
     * @param list<string> $fields
     */
    private static function hasScalarField(array $result, array $fields): bool
    {
        foreach ($fields as $field) {
            if (self::stringValue($result[$field] ?? null) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $result
     * @param list<string> $fields
     */
    private static function hasArrayKey(array $result, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $result) && is_array($result[$field])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $array
     * @param list<string> $keys
     */
    private static function arrayField(array $array, array $keys): ?array
    {
        foreach ($keys as $key) {
            if (isset($array[$key]) && is_array($array[$key])) {
                return $array[$key];
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $entry): string => is_string($entry) || is_numeric($entry) ? (string) $entry : '',
                $value,
            ),
            static fn (string $entry): bool => $entry !== '',
        ));
    }

    /**
     * @param mixed $value
     */
    private static function stringValue(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }

    /**
     * @param mixed $value
     */
    private static function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes'], true);
        }

        return (bool) $value;
    }

    /**
     * @return list<string>
     */
    private static function fieldAliases(string $field): array
    {
        $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $field))));

        return array_values(array_unique([$field, $camel]));
    }

    /**
     * @param array<string, mixed> $array
     * @param list<string> $keys
     */
    private static function hasAnyEvidenceValue(array $array, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $array) && ! self::isEmptyEvidence($array[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $array
     */
    private static function hasNonEmptyArrayEvidence(array $array, string $field): bool
    {
        foreach (self::fieldAliases($field) as $alias) {
            if (
                array_key_exists($alias, $array)
                && is_array($array[$alias])
                && ! self::isEmptyEvidence($array[$alias])
            ) {
                return true;
            }
        }

        return false;
    }

    private static function hasEvidenceItem(mixed $value, string $item): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach (self::fieldAliases($item) as $alias) {
            if (array_key_exists($alias, $value) && ! self::isEmptyEvidence($value[$alias])) {
                return true;
            }
        }

        foreach (['state_kinds', 'stateKinds', 'kinds', 'items'] as $field) {
            if (isset($value[$field]) && self::hasEvidenceItem($value[$field], $item)) {
                return true;
            }
        }

        foreach ($value as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach (['id', 'kind', 'type', 'state_kind', 'stateKind', 'name', 'scenario'] as $field) {
                if (self::stringValue($entry[$field] ?? null) === $item && ! self::isEmptyEvidence($entry)) {
                    return true;
                }
            }

            if (self::hasEvidenceItem($entry, $item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $tokens
     */
    private static function hasEvidenceToken(mixed $value, array $tokens): bool
    {
        if (is_string($value) || is_numeric($value)) {
            $text = (string) $value;
            foreach ($tokens as $token) {
                if ($token !== '' && preg_match('/\b'.preg_quote($token, '/').'\b/i', $text) === 1) {
                    return true;
                }
            }

            return false;
        }

        if (! is_array($value) || self::isEmptyEvidence($value)) {
            return false;
        }

        foreach ($value as $key => $entry) {
            if (
                is_string($key)
                && ! self::isEmptyEvidence($entry)
                && self::hasEvidenceToken($key, $tokens)
            ) {
                return true;
            }

            if (self::hasEvidenceToken($entry, $tokens)) {
                return true;
            }
        }

        return false;
    }
}
