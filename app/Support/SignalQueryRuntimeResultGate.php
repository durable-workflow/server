<?php

namespace App\Support;

/**
 * Evaluates a signals/queries conformance result against the public
 * scenario manifest exposed by SignalQueryRuntimeContract.
 */
final class SignalQueryRuntimeResultGate
{
    public const SCHEMA = 'durable-workflow.v2.signal-query-runtime.result-gate';

    public const VERSION = 30;

    private const EVIDENCE_SECTION_SCENARIOS = [
        'replay_timing' => [
            'signal_during_replay',
            'query_during_replay',
        ],
        'terminal_run_behavior' => [
            'completed_run_signal_and_query',
        ],
        'adversarial_errors' => [
            'unknown_signal_and_query_errors',
            'malformed_signal_and_query_payloads',
        ],
        'waterline_observer_comparison' => [
            'waterline_operator_visibility',
            'waterline_service_operator_visibility',
        ],
    ];

    private const TRUTHY_REQUIRED_EVIDENCE = [
        'python_worker_query_task_routing',
        'cli_signal_and_query',
        'sdk_python_signal_and_query',
        'immediate_repeat_query_consistency',
        'php_worker_query_task_routing',
        'sdk_php_signal_and_query',
        'php_client_signal_and_query',
        'cross_language_query_consistency',
        'wire_envelope_compatibility',
        'comparison.run_status_matches_public_clients',
        'comparison.run_identity_matches_public_clients',
        'comparison.counter_state_matches_public_clients',
        'comparison.service_mode_uses_public_php_sdk',
        'api_captures.running_runs.selected_run_present',
        'prefix_consistent_query_results',
        'query_result_rollback_free',
        'repeat_query_consistency',
        'successful_queries_appended_no_history',
        'successful_queries_emitted_no_workflow_commands',
        'failed_queries_appended_no_history',
        'failed_queries_emitted_no_workflow_commands',
        'failed_query_did_not_change_later_answer',
        'successful_and_failed_queries_appended_no_history',
        'successful_and_failed_queries_emitted_no_workflow_commands',
        'rejected_signal_audit_rows_match_expected',
        'rejected_requests_and_recovery_appended_no_history',
        'rejected_requests_created_no_executable_or_ready_work',
        'rejected_requests_mutated_no_workflow_state',
        'cold_restart.durable_history_restored',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'evaluates_result_schema' => SignalQueryRuntimeContract::RESULT_SCHEMA,
            'scenario_statuses_source' => 'signal_query_runtime_contract.scenario_statuses',
            'required_scenarios_source' => 'signal_query_runtime_contract.required_scenarios',
            'required_matrix_source' => 'signal_query_runtime_contract.required_matrix',
            'artifact_versions_fields' => [
                'artifact_versions',
                'artifactVersions',
                'published_artifact_versions',
                'publishedArtifactVersions',
            ],
            'required_artifact_versions_source' => 'signal_query_runtime_contract.artifact_policy.install_channels',
            'required_artifact_sources_source' => 'signal_query_runtime_contract.artifact_policy.expected_sources',
            'artifact_version_policy' => [
                'requires_recorded_and_pinned_versions' => true,
                'rejects_placeholder_versions' => true,
                'placeholder_version_examples' => [
                    'latest',
                    'current',
                    'head',
                    'unresolved',
                    'placeholder',
                    '<latest>',
                    '${VERSION}',
                    '{{ version }}',
                ],
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
            'declared_outcomes_source' => 'signal_query_runtime_contract.coverage_gate.*_outcome',
            'non_pass_statuses' => [
                'fail',
                'unsupported',
                'not_covered',
                'runner_blocked',
            ],
            'pass_requires' => [
                'every_required_scenario_has_one_result',
                'every_result_uses_a_published_status',
                'required_php_and_python_workers_are_reported',
                'same_language_and_cross_language_cells_are_reported',
                'replay_terminal_adversarial_and_waterline_sections_are_reported',
                'each_pass_scenario_has_observed_outputs',
                'each_pass_scenario_includes_required_evidence',
                'published_artifact_install_only_includes_per_artifact_install_proof',
                'python_worker_baseline_identifies_a_published_python_sdk_worker',
                'python_worker_baseline_route_evidence_status_is_pass_or_completed',
                'rust_cells_identify_the_exact_crates_io_sdk_and_registry_checksum',
                'rust_worker_registration_reports_the_resolved_sdk_version',
                'rust_avro_cell_executes_a_valid_cross_language_round_trip',
                'rust_query_immutability_baseline_precedes_the_first_successful_query',
                'rust_snapshot_and_replayed_instance_state_models_are_distinct',
                'php_worker_baseline_identifies_a_published_sdk_php_worker',
                'php_worker_baseline_version_matches_run_tuple',
                'php_worker_baseline_route_evidence_status_is_pass_or_completed',
                'replay_timing_timestamps_are_ordered',
                'terminal_run_status_codes_and_reasons_are_typed',
                'terminal_run_result_and_history_are_unchanged_after_operations',
                'each_non_pass_scenario_has_linked_findings',
                'omitted_required_scenarios_link_findings',
                'run_timestamps_outcome_and_finding_links_are_recorded',
                'overall_outcome_matches_gate_status',
                'published_artifact_versions_are_recorded_and_pinned',
                'required_distribution_identities_are_recorded',
                'published_artifact_sources_match_expected_channels',
                'scenario_artifact_versions_match_run_tuple',
                'no_local_product_source_artifacts_are_reported',
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
        $contract ??= SignalQueryRuntimeContract::manifest();

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
                if (! self::hasObservedOutputs($scenarioResult)) {
                    $failures[] = [
                        'code' => 'missing_pass_observed_outputs',
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
            if (! in_array($status, $allowedStatuses, true)) {
                $failures[] = [
                    'code' => 'invalid_extra_scenario_status',
                    'scenario_id' => $scenarioId,
                    'status' => $status,
                    'allowed_statuses' => $allowedStatuses,
                ];
            }
        }

        $runRecordFailures = self::runRecordFailures($result, $contract);
        array_push($failures, ...$runRecordFailures);

        $declaredOutcomeFailures = self::declaredOutcomeFailures($result, $contract);
        array_push($failures, ...$declaredOutcomeFailures);

        $artifactFailures = self::artifactVersionFailures($result, $contract);
        array_push($failures, ...$artifactFailures);

        $distributionIdentityFailures = self::distributionIdentityFailures($result, $contract);
        array_push($failures, ...$distributionIdentityFailures);

        $scenarioArtifactFailures = self::scenarioArtifactVersionFailures($result, $scenarioResults, $contract);
        array_push($failures, ...$scenarioArtifactFailures);

        $sourceFailures = self::sourcePolicyFailures($result, $contract, $scenarioResults);
        array_push($failures, ...$sourceFailures);

        $installEvidenceFailures = self::publishedArtifactInstallEvidenceFailures($result, $contract, $scenarioResults);
        array_push($failures, ...$installEvidenceFailures);

        $matrixFailures = self::matrixFailures($result, $contract);
        array_push($failures, ...$matrixFailures);

        $sectionFailures = self::requiredSectionFailures($result, $scenarioResults);
        array_push($failures, ...$sectionFailures);

        array_push($failures, ...self::missingScenarioFindingFailures($missingScenarios, $result));

        $evidenceFailures = self::scenarioEvidenceFailures($result, $scenarioResults, $contract);
        array_push($failures, ...$evidenceFailures);

        $smokeSubsetDetected = self::isSmokeSubset($scenarioStatuses, $contract);
        if ($smokeSubsetDetected) {
            $failures[] = [
                'code' => 'smoke_subset_cannot_pass',
                'reason' => 'Python smoke coverage is not a complete signals/queries conformance result.',
            ];
        }

        $evidencePasses = $failures === []
            && $missingScenarios === []
            && $nonPassScenarios === []
            && count($scenarioStatuses) >= count($requiredScenarios);
        $evaluatedStatus = $evidencePasses ? 'pass' : 'non_passing';

        $declaredOutcomeStatusFailures = self::declaredOutcomeStatusFailures($result, $contract, $evaluatedStatus);
        array_push($failures, ...$declaredOutcomeStatusFailures);

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
     * @param  array<string, mixed>  $result
     * @param  array<string, int>  $duplicateScenarioCounts
     * @return array<string, array<string, mixed>>
     */
    private static function scenarioResultsById(array $result, array &$duplicateScenarioCounts): array
    {
        $raw = self::arrayValue($result, 'scenario_results')
            ?? self::arrayValue($result, 'scenarioResults')
            ?? [];

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
     * @param  array<string, mixed>  $scenarioResult
     */
    private static function hasObservedOutputs(array $scenarioResult): bool
    {
        foreach (['observed_outputs', 'observedOutputs', 'runtime_matrix', 'runtimeMatrix'] as $field) {
            $value = self::arrayValue($scenarioResult, $field);
            if ($value !== null && $value !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $scenarioResult
     * @param  array<string, mixed>  $result
     */
    private static function hasLinkedFindings(array $scenarioResult, array $result): bool
    {
        foreach (['linked_findings', 'linkedFindings', 'finding_links', 'findingLinks'] as $field) {
            $value = self::arrayValue($scenarioResult, $field);
            if ($value !== null && $value !== []) {
                return true;
            }
        }

        $scenarioId = self::stringValue($scenarioResult['scenario_id'] ?? null);
        foreach (['finding_links', 'findingLinks', 'findings'] as $field) {
            $links = self::arrayValue($result, $field);
            if ($links === null) {
                continue;
            }

            if (array_key_exists($scenarioId, $links) && $links[$scenarioId] !== []) {
                return true;
            }

            foreach ($links as $link) {
                if (! is_array($link)) {
                    continue;
                }

                $linkedScenario = self::stringValue($link['scenario_id'] ?? $link['scenario'] ?? null);
                if ($linkedScenario === $scenarioId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     * @return array<int, array<string, mixed>>
     */
    private static function runRecordFailures(array $result, array $contract): array
    {
        $requiredFields = self::stringList($contract['artifact_policy']['required_run_record_fields'] ?? []);
        $failures = [];
        foreach ($requiredFields as $field) {
            if (self::hasRunRecordField($result, $field)) {
                continue;
            }

            $failures[] = [
                'code' => 'missing_run_record_field',
                'field' => $field,
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
            'artifact_versions' => self::artifactVersions($result) !== [],
            'started_at' => self::hasScalarField($result, ['started_at', 'startedAt']),
            'finished_at' => self::hasScalarField($result, ['finished_at', 'finishedAt']),
            'outcome' => self::hasScalarField($result, ['outcome', 'status', 'verdict']),
            'scenario_results' => self::hasArrayField($result, ['scenario_results', 'scenarioResults']),
            'findings' => self::hasArrayField($result, ['findings']),
            'finding_links' => self::hasArrayField($result, ['finding_links', 'findingLinks']),
            default => self::hasScalarField($result, [$field, self::camelize($field)])
                || self::hasArrayField($result, [$field, self::camelize($field)]),
        };
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     * @return array<int, array<string, mixed>>
     */
    private static function declaredOutcomeFailures(array $result, array $contract): array
    {
        $declaredOutcomes = self::declaredOutcomeTokens($result);
        if ($declaredOutcomes === []) {
            return [];
        }

        $allowedOutcomes = self::declaredOutcomes($contract);
        $failures = [];
        foreach ($declaredOutcomes as $field => $outcome) {
            if (in_array($outcome, $allowedOutcomes, true)) {
                continue;
            }

            $failures[] = [
                'code' => 'invalid_declared_outcome',
                'field' => $field,
                'outcome' => $outcome,
                'allowed_outcomes' => $allowedOutcomes,
            ];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     * @param  array<string, mixed>  $scenarioResult
     * @return array<int, array<string, mixed>>
     */
    private static function phpWorkerBaselineFailures(
        array $result,
        array $contract,
        array $scenarioResult,
        string $scenarioId,
    ): array {
        $failures = [];
        $runtime = self::stringValue(
            self::evidenceValue($result, $scenarioResult, $scenarioId, 'worker_runtime'),
        );
        if (! self::sameRuntime($runtime, 'sdk-php')) {
            $failures[] = [
                'code' => 'php_worker_baseline_runtime_not_sdk_php',
                'scenario_id' => $scenarioId,
                'field' => 'worker_runtime',
                'runtime' => $runtime,
            ];
        }

        $source = self::stringValue(
            self::evidenceValue($result, $scenarioResult, $scenarioId, 'sdk_php_artifact_source'),
        );
        if (! self::publishedSdkPhpSource($source, self::expectedArtifactSources($contract))) {
            $failures[] = [
                'code' => 'php_worker_baseline_source_not_published_sdk_php',
                'scenario_id' => $scenarioId,
                'field' => 'sdk_php_artifact_source',
                'source' => $source,
            ];
        }

        $version = self::stringValue(
            self::evidenceValue($result, $scenarioResult, $scenarioId, 'sdk_php_sdk_version'),
        );
        $expectedVersion = self::artifactVersionValue(self::artifactVersions($result), 'sdk-php');
        if ($version === '' || self::isPlaceholderVersion($version)) {
            $failures[] = [
                'code' => 'php_worker_baseline_missing_sdk_version',
                'scenario_id' => $scenarioId,
                'field' => 'sdk_php_sdk_version',
                'version' => $version,
            ];
        } elseif ($expectedVersion !== '' && $version !== $expectedVersion) {
            $failures[] = [
                'code' => 'php_worker_baseline_sdk_version_mismatch',
                'scenario_id' => $scenarioId,
                'field' => 'sdk_php_sdk_version',
                'version' => $version,
                'expected_version' => $expectedVersion,
            ];
        }

        array_push(
            $failures,
            ...self::routedCurrentQueryTaskFailures(
                $result,
                $scenarioResult,
                $scenarioId,
                expectedRuntime: 'sdk-php',
                failureCode: 'php_worker_baseline_current_query_not_routed',
                mismatchCode: 'php_worker_baseline_current_query_route_mismatch',
            ),
        );

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     * @return array<int, array<string, mixed>>
     */
    private static function declaredOutcomeStatusFailures(
        array $result,
        array $contract,
        string $evaluatedStatus,
    ): array {
        $declaredOutcomes = self::declaredOutcomeTokens($result);
        if ($declaredOutcomes === []) {
            return [];
        }

        $allowedOutcomes = self::declaredOutcomes($contract);
        $failures = [];
        $declaredStatuses = [];
        foreach ($declaredOutcomes as $field => $outcome) {
            if (! in_array($outcome, $allowedOutcomes, true)) {
                continue;
            }

            $declaredStatus = self::declaredOutcomeStatus($outcome);
            $declaredStatuses[$field] = $declaredStatus;
            if ($declaredStatus === $evaluatedStatus) {
                continue;
            }

            $failures[] = [
                'code' => 'declared_outcome_status_mismatch',
                'field' => $field,
                'outcome' => $outcome,
                'declared_status' => $declaredStatus,
                'evaluated_status' => $evaluatedStatus,
            ];
        }

        if (count(array_unique($declaredStatuses)) > 1) {
            $failure = [
                'code' => 'conflicting_outcome_tokens',
                'declared_outcomes' => array_intersect_key($declaredOutcomes, $declaredStatuses),
                'declared_statuses' => $declaredStatuses,
            ];
            foreach (['outcome', 'status', 'verdict'] as $field) {
                if (array_key_exists($field, $declaredOutcomes)) {
                    $failure[$field] = $declaredOutcomes[$field];
                }
            }

            $failures[] = $failure;
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, string>
     */
    private static function declaredOutcomeTokens(array $result): array
    {
        $declaredOutcomes = [];
        foreach (['outcome', 'status', 'verdict'] as $field) {
            $value = self::stringValue($result[$field] ?? null);
            if ($value !== '') {
                $declaredOutcomes[$field] = $value;
            }
        }

        return $declaredOutcomes;
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return list<string>
     */
    private static function declaredOutcomes(array $contract): array
    {
        $outcomes = ['pass'];
        $coverageGate = self::arrayValue($contract, 'coverage_gate') ?? [];
        foreach ($coverageGate as $key => $value) {
            if (! is_string($key) || ! str_ends_with($key, '_outcome')) {
                continue;
            }

            $outcome = self::stringValue($value);
            if ($outcome !== '') {
                $outcomes[] = $outcome;
            }
        }

        return array_values(array_unique($outcomes));
    }

    private static function declaredOutcomeStatus(string $outcome): string
    {
        return $outcome === 'pass' ? 'pass' : 'non_passing';
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     * @return array<int, array<string, mixed>>
     */
    private static function artifactVersionFailures(array $result, array $contract): array
    {
        $versions = self::artifactVersions($result);

        $failures = [];
        $installChannels = self::arrayValue($contract['artifact_policy'] ?? [], 'install_channels') ?? [];
        foreach (array_keys($installChannels) as $artifact) {
            $version = self::artifactVersionValue($versions, (string) $artifact);
            if ($version === '') {
                $failures[] = [
                    'code' => 'missing_artifact_version',
                    'artifact' => $artifact,
                ];

                continue;
            }

            if (self::isPlaceholderVersion($version)) {
                $failures[] = [
                    'code' => 'placeholder_artifact_version',
                    'artifact' => $artifact,
                    'version' => $version,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     * @return array<int, array<string, mixed>>
     */
    private static function distributionIdentityFailures(array $result, array $contract): array
    {
        $artifactPolicy = self::arrayValue($contract, 'artifact_policy') ?? [];
        $required = self::arrayValue($artifactPolicy, 'required_distribution_identities') ?? [];
        if ($required === []) {
            return [];
        }

        $identities = self::arrayValue($result, 'executed_distribution_identities')
            ?? self::arrayValue($result, 'executedDistributionIdentities')
            ?? [];
        $versions = self::artifactVersions($result);
        $failures = [];

        foreach ($required as $distribution => $definition) {
            if (! is_string($distribution) || ! is_array($definition)) {
                continue;
            }

            $identity = self::arrayValue($identities, $distribution);
            if ($identity === null) {
                $failures[] = [
                    'code' => 'missing_executed_distribution_identity',
                    'distribution' => $distribution,
                ];

                continue;
            }

            $versionComponent = self::stringValue($definition['version_component'] ?? null);
            $kind = self::stringValue($definition['kind'] ?? null);
            $package = self::stringValue($definition['package'] ?? null);
            $version = self::artifactVersionValue($versions, $versionComponent);
            $locatorVersion = self::distributionLocatorVersion($versionComponent, $version);
            $expectedLocator = $kind.':'.$package.'@'.$locatorVersion;
            $observedKind = self::stringValue($identity['kind'] ?? null);
            $observedLocator = self::stringValue($identity['locator'] ?? null);

            if ($observedKind !== $kind || $observedLocator !== $expectedLocator) {
                $failures[] = [
                    'code' => 'executed_distribution_locator_mismatch',
                    'distribution' => $distribution,
                    'expected_kind' => $kind,
                    'actual_kind' => $observedKind,
                    'expected_locator' => $expectedLocator,
                    'actual_locator' => $observedLocator,
                ];
            }

            $artifacts = self::arrayValue($identity, 'artifacts');
            if ($artifacts === null || $artifacts === []) {
                $failures[] = [
                    'code' => 'missing_executed_distribution_artifact',
                    'distribution' => $distribution,
                ];

                continue;
            }

            foreach ($artifacts as $artifact) {
                $name = is_array($artifact) ? self::stringValue($artifact['name'] ?? null) : '';
                $sha256 = is_array($artifact) ? self::stringValue($artifact['sha256'] ?? null) : '';
                if ($name !== '' && preg_match('/^[0-9a-f]{64}$/', $sha256) === 1) {
                    continue;
                }

                $failures[] = [
                    'code' => 'invalid_executed_distribution_artifact',
                    'distribution' => $distribution,
                    'artifact_name' => $name,
                ];
            }
        }

        return $failures;
    }

    private static function distributionLocatorVersion(string $component, string $version): string
    {
        if ($component !== 'sdk-python') {
            return $version;
        }

        if (preg_match(
            '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)-(alpha|beta|rc)\.(0|[1-9]\d*)$/i',
            $version,
            $matches,
        ) !== 1) {
            return $version;
        }

        $phase = match (strtolower($matches[4])) {
            'alpha' => 'a',
            'beta' => 'b',
            default => 'rc',
        };

        return $matches[1].'.'.$matches[2].'.'.$matches[3].$phase.$matches[5];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, array<string, mixed>>  $scenarioResults
     * @param  array<string, mixed>  $contract
     * @return array<int, array<string, mixed>>
     */
    private static function scenarioArtifactVersionFailures(
        array $result,
        array $scenarioResults,
        array $contract,
    ): array {
        $runVersions = self::artifactVersions($result);
        if ($runVersions === []) {
            return [];
        }

        $installChannels = self::arrayValue($contract['artifact_policy'] ?? [], 'install_channels') ?? [];
        $failures = [];
        foreach (self::sectionPolicyContainers($result) as $container) {
            foreach (self::artifactVersionSets($container['value'], $container['path'], false) as $versionSet) {
                foreach (array_keys($installChannels) as $artifact) {
                    $expected = self::artifactVersionValue($runVersions, (string) $artifact);
                    $actual = self::artifactVersionValue($versionSet['versions'], (string) $artifact);
                    if ($expected === '' || $actual === '' || $actual === $expected) {
                        continue;
                    }

                    $failures[] = [
                        'code' => 'scenario_artifact_version_mismatch',
                        'artifact' => $artifact,
                        'expected_version' => $expected,
                        'actual_version' => $actual,
                        'field' => $versionSet['field'],
                        'path' => $versionSet['path'],
                    ];
                }
            }
        }

        foreach ($scenarioResults as $scenarioId => $scenarioResult) {
            if (self::stringValue($scenarioResult['status'] ?? null) !== 'pass') {
                continue;
            }

            foreach (self::scenarioArtifactVersionContainers($result, $scenarioResult, $scenarioId) as $versionSet) {
                $versions = $versionSet['versions'];
                foreach (array_keys($installChannels) as $artifact) {
                    $expected = self::artifactVersionValue($runVersions, (string) $artifact);
                    $actual = self::artifactVersionValue($versions, (string) $artifact);
                    if ($expected === '' || $actual === '' || $actual === $expected) {
                        continue;
                    }

                    $failures[] = [
                        'code' => 'scenario_artifact_version_mismatch',
                        'scenario_id' => $scenarioId,
                        'artifact' => $artifact,
                        'expected_version' => $expected,
                        'actual_version' => $actual,
                        'field' => $versionSet['field'],
                        'path' => $versionSet['path'],
                    ];
                }
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $scenarioResult
     * @return array<int, array{versions: array<mixed>, field: string, path: string}>
     */
    private static function scenarioArtifactVersionContainers(
        array $result,
        array $scenarioResult,
        string $scenarioId,
    ): array {
        $versionSets = [];
        foreach (self::scenarioPolicyContainers($result, $scenarioResult, $scenarioId) as $container) {
            array_push(
                $versionSets,
                ...self::artifactVersionSets(
                    $container['value'],
                    $container['path'],
                    $container['recursive'],
                ),
            );
        }

        return $versionSets;
    }

    /**
     * @param  array<mixed>  $container
     * @return array<int, array{versions: array<mixed>, field: string, path: string}>
     */
    private static function artifactVersionSets(array $container, string $path, bool $recursive): array
    {
        $versionSets = [];
        foreach ([
            'artifact_versions',
            'artifactVersions',
            'published_artifact_versions',
            'publishedArtifactVersions',
        ] as $field) {
            $versions = self::arrayValue($container, $field);
            if (! is_array($versions)) {
                continue;
            }

            $versionSets[] = [
                'versions' => $versions,
                'field' => $field,
                'path' => self::pathFor($path, $field),
            ];
        }

        if (! $recursive) {
            return $versionSets;
        }

        foreach ($container as $field => $value) {
            if (! is_array($value)) {
                continue;
            }

            array_push(
                $versionSets,
                ...self::artifactVersionSets($value, self::pathFor($path, $field), true),
            );
        }

        return $versionSets;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<mixed>
     */
    private static function artifactVersions(array $result): array
    {
        return self::arrayValue($result, 'artifact_versions')
            ?? self::arrayValue($result, 'artifactVersions')
            ?? self::arrayValue($result, 'published_artifact_versions')
            ?? self::arrayValue($result, 'publishedArtifactVersions')
            ?? [];
    }

    /**
     * @param  array<mixed>  $versions
     */
    private static function artifactVersionValue(array $versions, string $artifact): string
    {
        $aliases = [
            'sdk-php' => ['sdk-php', 'sdk_php'],
            'sdk-python' => ['sdk-python', 'sdk_python', 'python'],
            'sdk-rust' => ['sdk-rust', 'sdk_rust', 'rust'],
            'waterline' => ['waterline', 'waterline-ui', 'waterline_ui'],
        ];

        foreach ($aliases[$artifact] ?? [$artifact] as $key) {
            if (array_key_exists($key, $versions) && self::stringValue($versions[$key]) !== '') {
                return self::stringValue($versions[$key]);
            }
        }

        return '';
    }

    /**
     * @param  array<mixed>  $sources
     */
    private static function artifactSourceValue(array $sources, string $artifact): string
    {
        $aliases = [
            'sdk-php' => ['sdk-php', 'sdk_php'],
            'sdk-python' => ['sdk-python', 'sdk_python', 'python'],
            'sdk-rust' => ['sdk-rust', 'sdk_rust', 'rust'],
            'waterline' => ['waterline', 'waterline-ui', 'waterline_ui'],
        ];

        foreach ($aliases[$artifact] ?? [$artifact] as $key) {
            if (array_key_exists($key, $sources) && self::stringValue($sources[$key]) !== '') {
                return self::stringValue($sources[$key]);
            }
        }

        return '';
    }

    private static function isPlaceholderVersion(string $version): bool
    {
        $normalized = strtolower(trim($version));
        if ($normalized === '') {
            return false;
        }

        if (preg_match('/<[^>]+>|\$\{[^}]+}|{{[^}]+}}/', $normalized) === 1) {
            return true;
        }

        return preg_match(
            '/(^|[^a-z0-9])(latest|current|head|unresolved|placeholder)([^a-z0-9]|$)/',
            $normalized,
        ) === 1;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     * @param  array<string, array<string, mixed>>  $scenarioResults
     * @return array<int, array<string, mixed>>
     */
    private static function sourcePolicyFailures(array $result, array $contract, array $scenarioResults): array
    {
        $artifactPolicy = self::arrayValue($contract, 'artifact_policy') ?? [];
        $forbiddenSources = self::stringList($artifactPolicy['forbidden_sources'] ?? []);
        $expectedSources = self::expectedArtifactSources($contract);
        $reportedSourceSets = [];
        foreach (['artifact_sources', 'artifactSources'] as $field) {
            $reportedSources = self::arrayValue($result, $field);
            if ($reportedSources === null) {
                continue;
            }

            $reportedSourceSets[] = [
                'sources' => $reportedSources,
                'field' => $field,
                'path' => self::pathFor('$', $field),
                'scenario_id' => null,
            ];
        }

        foreach (self::sectionPolicyContainers($result) as $container) {
            foreach (self::artifactSourceSets($container['value'], $container['path'], false) as $sourceSet) {
                $sourceSet['scenario_id'] = null;
                $reportedSourceSets[] = $sourceSet;
            }
        }

        foreach ($scenarioResults as $scenarioId => $scenarioResult) {
            foreach (self::scenarioPolicyContainers($result, $scenarioResult, $scenarioId) as $container) {
                foreach (self::artifactSourceSets(
                    $container['value'],
                    $container['path'],
                    $container['recursive'],
                ) as $sourceSet) {
                    $sourceSet['scenario_id'] = $scenarioId;
                    $sourceSet['scenario_status'] = self::stringValue($scenarioResult['status'] ?? null);
                    $reportedSourceSets[] = $sourceSet;
                }
            }
        }

        $failures = [];

        foreach ($reportedSourceSets as $sourceSet) {
            foreach ($sourceSet['sources'] as $artifact => $source) {
                $source = self::stringValue($source);
                if (! self::isForbiddenArtifactSource($source, $forbiddenSources)) {
                    continue;
                }

                $failure = [
                    'code' => 'forbidden_artifact_source',
                    'artifact' => is_string($artifact) ? $artifact : null,
                    'source' => $source,
                    'field' => $sourceSet['field'],
                    'path' => $sourceSet['path'],
                ];
                if ($sourceSet['scenario_id'] !== null) {
                    $failure['scenario_id'] = $sourceSet['scenario_id'];
                }

                $failures[] = $failure;
            }

            if (! self::sourceSetRequiresExpectedPublishedSources($sourceSet)) {
                continue;
            }

            array_push(
                $failures,
                ...self::expectedPublishedArtifactSourceFailures($sourceSet, $expectedSources),
            );
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return array<string, string>
     */
    private static function expectedArtifactSources(array $contract): array
    {
        $artifactPolicy = self::arrayValue($contract, 'artifact_policy') ?? [];
        $expectedSources = self::arrayValue($artifactPolicy, 'expected_sources') ?? [];
        $fallback = [
            'server' => 'published_docker_image',
            'cli' => 'published_cli_release',
            'sdk-php' => 'published_composer_package',
            'sdk-python' => 'published_pypi_package',
            'sdk-rust' => 'published_crates_io_package',
            'waterline' => 'published_waterline_artifact',
        ];

        if ($expectedSources === []) {
            return $fallback;
        }

        $normalized = [];
        foreach ($fallback as $artifact => $fallbackSource) {
            $source = self::artifactSourceValue($expectedSources, $artifact);
            $normalized[$artifact] = $source === '' ? $fallbackSource : $source;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $sourceSet
     */
    private static function sourceSetRequiresExpectedPublishedSources(array $sourceSet): bool
    {
        return (
            ($sourceSet['scenario_id'] ?? null) === 'published_artifact_install_only'
            && ($sourceSet['scenario_status'] ?? null) === 'pass'
        )
            || in_array($sourceSet['path'] ?? null, ['$.artifact_sources', '$.artifactSources'], true);
    }

    /**
     * @param  array{sources: array<mixed>, field: string, path: string, scenario_id?: string|null}  $sourceSet
     * @param  array<string, string>  $expectedSources
     * @return array<int, array<string, mixed>>
     */
    private static function expectedPublishedArtifactSourceFailures(array $sourceSet, array $expectedSources): array
    {
        $failures = [];
        foreach ($expectedSources as $artifact => $expectedSource) {
            $actualSource = self::artifactSourceValue($sourceSet['sources'], $artifact);
            if ($actualSource === '') {
                $failure = [
                    'code' => 'missing_expected_published_artifact_source',
                    'artifact' => $artifact,
                    'expected_source' => $expectedSource,
                    'field' => $sourceSet['field'],
                    'path' => $sourceSet['path'],
                ];
            } elseif (self::publishedSourceMatchesArtifact($actualSource, $artifact, $expectedSources)) {
                continue;
            } else {
                $failure = [
                    'code' => 'unexpected_published_artifact_source',
                    'artifact' => $artifact,
                    'expected_source' => $expectedSource,
                    'actual_source' => $actualSource,
                    'field' => $sourceSet['field'],
                    'path' => $sourceSet['path'],
                ];
            }

            if (($sourceSet['scenario_id'] ?? null) !== null) {
                $failure['scenario_id'] = $sourceSet['scenario_id'];
            }

            $failures[] = $failure;
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     * @param  array<string, array<string, mixed>>  $scenarioResults
     * @return array<int, array<string, mixed>>
     */
    private static function publishedArtifactInstallEvidenceFailures(
        array $result,
        array $contract,
        array $scenarioResults,
    ): array {
        $scenarioId = 'published_artifact_install_only';
        $scenarioResult = $scenarioResults[$scenarioId] ?? null;
        if (! is_array($scenarioResult) || self::stringValue($scenarioResult['status'] ?? null) !== 'pass') {
            return [];
        }

        $artifactPolicy = self::arrayValue($contract, 'artifact_policy') ?? [];
        $installChannels = self::arrayValue($artifactPolicy, 'install_channels') ?? [];
        $installProofArtifacts = self::installProofArtifacts($artifactPolicy, $installChannels);
        $forbiddenSources = self::stringList($artifactPolicy['forbidden_sources'] ?? []);
        $expectedSources = self::expectedArtifactSources($contract);
        $versions = self::artifactVersions($result);
        $installEvidence = self::arrayEvidenceValue(
            $result,
            $scenarioResult,
            $scenarioId,
            'artifact_install_evidence',
            'artifactInstallEvidence',
            'install_evidence',
            'installEvidence',
        );

        if ($installEvidence === null) {
            return [[
                'code' => 'missing_published_artifact_install_evidence',
                'scenario_id' => $scenarioId,
                'field' => 'artifact_install_evidence',
            ]];
        }

        $failures = [];
        if (! self::explicitFalseField($installEvidence, 'local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed')) {
            $failures[] = [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'scenario_id' => $scenarioId,
                'field' => 'artifact_install_evidence.local_product_source_checkouts_used',
                'value' => $installEvidence['local_product_source_checkouts_used']
                    ?? $installEvidence['localProductSourceCheckoutsUsed']
                    ?? null,
            ];
        }

        foreach ($installProofArtifacts as $artifact) {
            $entry = self::artifactInstallEvidenceEntry($installEvidence, $artifact);
            if ($entry === null) {
                $failures[] = [
                    'code' => 'missing_published_artifact_install_evidence_artifact',
                    'scenario_id' => $scenarioId,
                    'artifact' => $artifact,
                    'field' => 'artifact_install_evidence.artifacts',
                ];

                continue;
            }

            $status = strtolower(self::firstString($entry, 'status', 'result', 'outcome'));
            if ($status !== 'pass') {
                $failures[] = [
                    'code' => 'published_artifact_install_evidence_not_pass',
                    'scenario_id' => $scenarioId,
                    'artifact' => $artifact,
                    'status' => $status,
                    'field' => 'artifact_install_evidence.artifacts.status',
                ];
            }

            $version = self::firstString(
                $entry,
                'version',
                'resolved_version',
                'resolvedVersion',
                'artifact_version',
                'artifactVersion',
            );
            if ($version === '') {
                $failures[] = [
                    'code' => 'missing_published_artifact_install_evidence_version',
                    'scenario_id' => $scenarioId,
                    'artifact' => $artifact,
                    'field' => 'artifact_install_evidence.artifacts.version',
                ];
            } elseif (self::isPlaceholderVersion($version)) {
                $failures[] = [
                    'code' => 'placeholder_published_artifact_install_evidence_version',
                    'scenario_id' => $scenarioId,
                    'artifact' => $artifact,
                    'version' => $version,
                    'field' => 'artifact_install_evidence.artifacts.version',
                ];
            } else {
                $expectedVersion = self::artifactVersionValue($versions, $artifact);
                if ($expectedVersion !== '' && $version !== $expectedVersion) {
                    $failures[] = [
                        'code' => 'published_artifact_install_evidence_version_mismatch',
                        'scenario_id' => $scenarioId,
                        'artifact' => $artifact,
                        'version' => $version,
                        'expected_version' => $expectedVersion,
                        'field' => 'artifact_install_evidence.artifacts.version',
                    ];
                }
            }

            $source = self::firstString(
                $entry,
                'source',
                'install_source',
                'installSource',
                'artifact_source',
                'artifactSource',
            );
            if ($source === '') {
                $failures[] = [
                    'code' => 'missing_published_artifact_install_evidence_source',
                    'scenario_id' => $scenarioId,
                    'artifact' => $artifact,
                    'field' => 'artifact_install_evidence.artifacts.source',
                ];
            } elseif (self::isForbiddenArtifactSource($source, $forbiddenSources)) {
                $failures[] = [
                    'code' => 'forbidden_published_artifact_install_evidence_source',
                    'scenario_id' => $scenarioId,
                    'artifact' => $artifact,
                    'source' => $source,
                    'field' => 'artifact_install_evidence.artifacts.source',
                ];
            } elseif (! self::publishedSourceMatchesArtifact($source, $artifact, $expectedSources)) {
                $failures[] = [
                    'code' => 'invalid_published_artifact_install_evidence_source',
                    'scenario_id' => $scenarioId,
                    'artifact' => $artifact,
                    'expected_source' => $expectedSources[$artifact] ?? null,
                    'source' => $source,
                    'field' => 'artifact_install_evidence.artifacts.source',
                ];
            }

            if (self::truthyField($entry, 'local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed')) {
                $failures[] = [
                    'code' => 'local_product_source_checkouts_used_must_be_false',
                    'scenario_id' => $scenarioId,
                    'artifact' => $artifact,
                    'field' => 'artifact_install_evidence.artifacts.local_product_source_checkouts_used',
                    'value' => $entry['local_product_source_checkouts_used']
                        ?? $entry['localProductSourceCheckoutsUsed']
                        ?? null,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $artifactPolicy
     * @param  array<mixed>  $installChannels
     * @return list<string>
     */
    private static function installProofArtifacts(array $artifactPolicy, array $installChannels): array
    {
        $proofArtifacts = self::stringList($artifactPolicy['install_proof_artifacts'] ?? []);
        if ($proofArtifacts !== []) {
            return $proofArtifacts;
        }

        return array_values(array_map('strval', array_keys($installChannels)));
    }

    /**
     * @param  array<string, string>  $expectedSources
     */
    private static function publishedSourceMatchesArtifact(
        string $source,
        string $artifact,
        array $expectedSources,
    ): bool {
        return trim($source) === ($expectedSources[$artifact] ?? '');
    }

    /**
     * @param  array<string, string>  $expectedSources
     */
    private static function publishedPythonSdkSource(string $source, array $expectedSources): bool
    {
        return self::publishedSourceMatchesArtifact($source, 'sdk-python', $expectedSources);
    }

    /**
     * @param  array<string, string>  $expectedSources
     */
    private static function publishedSdkPhpSource(string $source, array $expectedSources): bool
    {
        return self::publishedSourceMatchesArtifact($source, 'sdk-php', $expectedSources);
    }

    /**
     * @param  array<mixed>  $container
     * @return array<int, array{sources: array<mixed>, field: string, path: string}>
     */
    private static function artifactSourceSets(array $container, string $path, bool $recursive): array
    {
        $sourceSets = [];
        foreach (['artifact_sources', 'artifactSources'] as $field) {
            $sources = self::arrayValue($container, $field);
            if (! is_array($sources)) {
                continue;
            }

            $sourceSets[] = [
                'sources' => $sources,
                'field' => $field,
                'path' => self::pathFor($path, $field),
            ];
        }

        if (! $recursive) {
            return $sourceSets;
        }

        foreach ($container as $field => $value) {
            if (! is_array($value)) {
                continue;
            }

            array_push(
                $sourceSets,
                ...self::artifactSourceSets($value, self::pathFor($path, $field), true),
            );
        }

        return $sourceSets;
    }

    /**
     * @param  list<string>  $forbiddenSources
     */
    private static function isForbiddenArtifactSource(string $source, array $forbiddenSources): bool
    {
        $source = strtolower(trim($source));
        if ($source === '') {
            return false;
        }

        foreach ($forbiddenSources as $forbiddenSource) {
            $forbiddenSource = strtolower(trim($forbiddenSource));
            if ($forbiddenSource === '') {
                continue;
            }

            if ($source === $forbiddenSource || str_contains($source, $forbiddenSource)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     * @return array<int, array<string, mixed>>
     */
    private static function matrixFailures(array $result, array $contract): array
    {
        $matrix = self::arrayValue($result, 'runtime_matrix')
            ?? self::arrayValue($result, 'runtimeMatrix')
            ?? [];
        $contractMatrix = self::arrayValue($contract, 'required_matrix') ?? [];
        $failures = [];

        foreach (self::stringList($contractMatrix['runtimes'] ?? []) as $runtime) {
            if (! self::matrixHasRuntime($matrix, $runtime)) {
                $failures[] = [
                    'code' => 'missing_required_runtime',
                    'runtime' => $runtime,
                ];
            }
        }

        foreach (['same_language_cells', 'cross_language_cells'] as $cellGroup) {
            foreach ($contractMatrix[$cellGroup] ?? [] as $requiredCell) {
                if (! is_array($requiredCell) || self::matrixHasCell($matrix, $cellGroup, $requiredCell)) {
                    continue;
                }

                $failures[] = [
                    'code' => 'missing_required_matrix_cell',
                    'cell_group' => $cellGroup,
                    'scenario' => $requiredCell['scenario'] ?? null,
                    'worker' => $requiredCell['worker'] ?? null,
                    'clients' => $requiredCell['clients'] ?? [],
                ];
            }
        }

        return $failures;
    }

    /**
     * @param  array<mixed>  $matrix
     */
    private static function matrixHasRuntime(array $matrix, string $runtime): bool
    {
        foreach (['runtimes', 'workers', 'worker_runtimes', 'workerRuntimes'] as $field) {
            foreach (self::stringList($matrix[$field] ?? []) as $reportedRuntime) {
                if (self::sameRuntime($reportedRuntime, $runtime)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $matrix
     * @param  array<string, mixed>  $requiredCell
     */
    private static function matrixHasCell(array $matrix, string $cellGroup, array $requiredCell): bool
    {
        $reportedCells = [];
        foreach ([$cellGroup, 'cells', 'runtime_cells', 'runtimeCells'] as $field) {
            $value = self::arrayValue($matrix, $field);
            if ($value !== null) {
                $reportedCells = array_merge($reportedCells, $value);
            }
        }

        foreach ($reportedCells as $reportedCell) {
            if (! is_array($reportedCell)) {
                continue;
            }

            if (self::stringValue($reportedCell['scenario'] ?? $reportedCell['scenario_id'] ?? null)
                !== self::stringValue($requiredCell['scenario'] ?? null)) {
                continue;
            }

            if (! self::sameRuntime(
                self::stringValue($reportedCell['worker'] ?? $reportedCell['runtime'] ?? null),
                self::stringValue($requiredCell['worker'] ?? null),
            )) {
                continue;
            }

            $reportedClients = self::stringList($reportedCell['clients'] ?? $reportedCell['client_paths'] ?? []);
            $requiredClients = self::stringList($requiredCell['clients'] ?? []);
            if (array_diff($requiredClients, $reportedClients) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, array<string, mixed>>  $scenarioResults
     * @return array<int, array<string, mixed>>
     */
    private static function requiredSectionFailures(array $result, array $scenarioResults): array
    {
        $failures = [];
        foreach (self::EVIDENCE_SECTION_SCENARIOS as $section => $scenarios) {
            if (self::arrayValue($result, $section) !== null) {
                continue;
            }

            $coveredByScenarioOutputs = true;
            foreach ($scenarios as $scenarioId) {
                if (! isset($scenarioResults[$scenarioId]) || ! self::hasObservedOutputs($scenarioResults[$scenarioId])) {
                    $coveredByScenarioOutputs = false;
                    break;
                }
            }

            if (! $coveredByScenarioOutputs) {
                $failures[] = [
                    'code' => 'missing_required_evidence_section',
                    'section' => $section,
                    'scenarios' => $scenarios,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, array<string, mixed>>  $scenarioResults
     * @param  array<string, mixed>  $contract
     * @return array<int, array<string, mixed>>
     */
    private static function scenarioEvidenceFailures(
        array $result,
        array $scenarioResults,
        array $contract,
    ): array {
        $requirements = self::arrayValue($contract, 'scenario_requirements') ?? [];
        $failures = [];

        foreach ($requirements as $scenarioId => $requirement) {
            if (! is_string($scenarioId) || ! is_array($requirement)) {
                continue;
            }

            $scenarioResult = $scenarioResults[$scenarioId] ?? null;
            if (! is_array($scenarioResult) || self::stringValue($scenarioResult['status'] ?? null) !== 'pass') {
                continue;
            }

            foreach (self::requiredEvidenceKeys($requirement) as $evidenceKey) {
                if (self::hasEvidence($result, $scenarioResult, $scenarioId, $evidenceKey)) {
                    continue;
                }

                $failures[] = [
                    'code' => 'missing_required_pass_evidence',
                    'scenario_id' => $scenarioId,
                    'evidence_key' => $evidenceKey,
                ];
            }

            if ($scenarioId === 'ordered_signal_delivery') {
                $expectedRapidInputs = self::arrayValue($requirement, 'expected_rapid_increment_inputs')
                    ?? range(1, 10);
                $rapidInputsValue = self::evidenceValue(
                    $result,
                    $scenarioResult,
                    $scenarioId,
                    'rapid_increment_inputs',
                );
                $rapidInputs = self::integerList($rapidInputsValue);
                $acceptedInputsValue = self::evidenceValue(
                    $result,
                    $scenarioResult,
                    $scenarioId,
                    'accepted_signal_inputs',
                );
                $acceptedInputs = self::integerList($acceptedInputsValue);
                $acceptedSignalTotal = self::integerValue(self::evidenceValue(
                    $result,
                    $scenarioResult,
                    $scenarioId,
                    'accepted_signal_total',
                ));
                $queriedTotal = self::integerValue(self::evidenceValue(
                    $result,
                    $scenarioResult,
                    $scenarioId,
                    'queried_total',
                ));
                $historyOrderValue = self::evidenceValue(
                    $result,
                    $scenarioResult,
                    $scenarioId,
                    'history_signal_order',
                );
                $historyOrder = self::integerList($historyOrderValue);

                foreach ([
                    'rapid_increment_inputs' => [$rapidInputsValue, $rapidInputs],
                    'accepted_signal_inputs' => [$acceptedInputsValue, $acceptedInputs],
                    'history_signal_order' => [$historyOrderValue, $historyOrder],
                ] as $evidenceKey => [$rawValue, $sequence]) {
                    if ($rawValue === null || $sequence !== null) {
                        continue;
                    }

                    $failures[] = [
                        'code' => 'invalid_ordered_signal_sequence_evidence',
                        'scenario_id' => $scenarioId,
                        'evidence_key' => $evidenceKey,
                        'expected_shape' => 'list<int>',
                        'actual_value' => $rawValue,
                    ];
                }

                $referenceInputs = $acceptedInputsValue === null ? $rapidInputs : $acceptedInputs;

                if ($rapidInputs !== null && $rapidInputs !== $expectedRapidInputs) {
                    $failures[] = [
                        'code' => 'unexpected_ordered_signal_inputs',
                        'scenario_id' => $scenarioId,
                        'expected_inputs' => $expectedRapidInputs,
                        'actual_inputs' => $rapidInputs,
                    ];
                }

                if ($acceptedInputs !== null && $rapidInputs !== null && $acceptedInputs !== $rapidInputs) {
                    $failures[] = [
                        'code' => 'unexpected_ordered_signal_acceptance',
                        'scenario_id' => $scenarioId,
                        'expected_inputs' => $rapidInputs,
                        'actual_inputs' => $acceptedInputs,
                    ];
                }

                if ($referenceInputs !== null
                    && $acceptedSignalTotal !== null
                    && $acceptedSignalTotal !== array_sum($referenceInputs)) {
                    $failures[] = [
                        'code' => 'unexpected_ordered_signal_accepted_total',
                        'scenario_id' => $scenarioId,
                        'expected_total' => array_sum($referenceInputs),
                        'actual_total' => $acceptedSignalTotal,
                    ];
                }

                if ($referenceInputs !== null
                    && $queriedTotal !== null
                    && $queriedTotal !== array_sum($referenceInputs)) {
                    $failures[] = [
                        'code' => 'unexpected_ordered_signal_total',
                        'scenario_id' => $scenarioId,
                        'expected_total' => array_sum($referenceInputs),
                        'actual_total' => $queriedTotal,
                    ];
                }

                if ($referenceInputs !== null && $historyOrder !== null && $historyOrder !== $referenceInputs) {
                    $failures[] = [
                        'code' => 'unexpected_ordered_signal_history_order',
                        'scenario_id' => $scenarioId,
                        'expected_order' => $referenceInputs,
                        'actual_order' => $historyOrder,
                    ];
                }
            }

            if ($scenarioId === 'query_during_replay') {
                $queryAnswer = self::evidenceValue($result, $scenarioResult, $scenarioId, 'query_answer');
                $expectedAnswer = self::evidenceValue($result, $scenarioResult, $scenarioId, 'expected_answer');

                if ($queryAnswer !== null && $expectedAnswer !== null && $queryAnswer !== $expectedAnswer) {
                    $failures[] = [
                        'code' => 'unexpected_replay_query_answer',
                        'scenario_id' => $scenarioId,
                        'expected_answer' => $expectedAnswer,
                        'actual_answer' => $queryAnswer,
                    ];
                }
            }

            if ($scenarioId === 'python_worker_cli_and_sdk_baseline') {
                array_push(
                    $failures,
                    ...self::pythonWorkerBaselineFailures($result, $contract, $scenarioResult, $scenarioId),
                );
            }

            if ($scenarioId === 'php_worker_cli_and_sdk_baseline') {
                array_push(
                    $failures,
                    ...self::phpWorkerBaselineFailures($result, $contract, $scenarioResult, $scenarioId),
                );
            }

            if (in_array($scenarioId, [
                'rust_worker_rust_php_python_clients',
                'python_worker_rust_client',
                'php_worker_rust_client',
                'rust_query_error_and_immutability',
                'rust_replayed_instance_state_query_after_cold_restart',
            ], true)) {
                array_push(
                    $failures,
                    ...self::rustScenarioFailures($result, $contract, $scenarioResult, $scenarioId),
                );
            }

            if ($scenarioId === 'signal_during_replay') {
                array_push(
                    $failures,
                    ...self::timestampOrderFailures($result, $scenarioResult, $scenarioId, [
                        ['worker_restart_at', '<=', 'signal_sent_at'],
                        ['signal_sent_at', '<', 'replay_completed_at'],
                        ['replay_completed_at', '<=', 'signal_applied_at'],
                    ], 'invalid_signal_replay_timing_order'),
                );
                array_push(
                    $failures,
                    ...self::statusCodeFailures($result, $scenarioResult, $scenarioId, [
                        'signal_status_code' => [200, 299],
                    ]),
                );
            }

            if ($scenarioId === 'query_during_replay') {
                array_push(
                    $failures,
                    ...self::timestampOrderFailures($result, $scenarioResult, $scenarioId, [
                        ['worker_restart_at', '<=', 'query_sent_at'],
                        ['query_sent_at', '<=', 'query_poll_started_at'],
                        ['query_poll_started_at', '<', 'replay_completed_at'],
                        ['replay_completed_at', '<=', 'query_handler_invoked_at'],
                        ['query_handler_invoked_at', '<=', 'query_completed_at'],
                    ], 'invalid_query_replay_timing_order'),
                );
                array_push(
                    $failures,
                    ...self::statusCodeFailures($result, $scenarioResult, $scenarioId, [
                        'query_status_code' => [200, 299],
                    ]),
                );
            }

            if ($scenarioId === 'completed_run_signal_and_query') {
                array_push(
                    $failures,
                    ...self::statusCodeFailures($result, $scenarioResult, $scenarioId, [
                        'signal_error.status_code' => [400, 499],
                        'query_result_or_error.status_code' => [200, 499],
                    ]),
                    ...self::terminalRunReasonFailures($result, $scenarioResult, $scenarioId),
                    ...self::terminalRunImmutabilityFailures($result, $scenarioResult, $scenarioId),
                );
            }

            if ($scenarioId === 'unknown_signal_and_query_errors') {
                $optionalStatusCodeRanges = [];
                foreach ([
                    'cli_unknown_signal_sample.status_code' => [404, 404],
                    'cli_unknown_query_sample.status_code' => [404, 404],
                    'cli_missing_workflow_signal_sample.status_code' => [404, 404],
                    'cli_missing_workflow_query_sample.status_code' => [404, 404],
                    'sdk_python_unknown_signal_sample.status_code' => [404, 404],
                    'sdk_python_unknown_query_sample.status_code' => [404, 404],
                ] as $evidenceKey => $range) {
                    $sampleKey = explode('.', $evidenceKey, 2)[0];
                    if (self::evidenceValue($result, $scenarioResult, $scenarioId, $sampleKey) !== null) {
                        $optionalStatusCodeRanges[$evidenceKey] = $range;
                    }
                }

                array_push(
                    $failures,
                    ...self::statusCodeFailures($result, $scenarioResult, $scenarioId, [
                        'unknown_signal.status_code' => [404, 404],
                        'missing_workflow_signal.status_code' => [404, 404],
                        'missing_workflow_query.status_code' => [404, 404],
                        'query_not_found.status_code' => [404, 404],
                        'known_query_after_unknown_errors.status_code' => [200, 299],
                    ]),
                    ...self::statusCodeFailures($result, $scenarioResult, $scenarioId, $optionalStatusCodeRanges),
                    ...self::unknownHandlerReasonFailures($result, $scenarioResult, $scenarioId),
                    ...self::unknownHandlerAuditFailures($result, $scenarioResult, $scenarioId),
                );
            }

            if ($scenarioId === 'malformed_signal_and_query_payloads') {
                array_push(
                    $failures,
                    ...self::statusCodeFailures($result, $scenarioResult, $scenarioId, [
                        'invalid_signal_arguments.status_code' => [422, 422],
                        'invalid_query_arguments.status_code' => [422, 422],
                    ]),
                    ...self::malformedPayloadReasonFailures($result, $scenarioResult, $scenarioId),
                );
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     * @param  array<string, mixed>  $scenarioResult
     * @return array<int, array<string, mixed>>
     */
    private static function rustScenarioFailures(
        array $result,
        array $contract,
        array $scenarioResult,
        string $scenarioId,
    ): array {
        $failures = [];
        $versions = self::artifactVersions($result);
        $expectedVersion = self::artifactVersionValue($versions, 'sdk-rust');
        $reportedVersion = self::stringValue(
            self::evidenceValue($result, $scenarioResult, $scenarioId, 'rust_sdk_version'),
        );
        if ($expectedVersion === '' || $reportedVersion !== $expectedVersion) {
            $failures[] = [
                'code' => 'rust_sdk_version_mismatch',
                'scenario_id' => $scenarioId,
                'expected_version' => $expectedVersion,
                'actual_version' => $reportedVersion,
            ];
        }

        $resolvedVersion = self::stringValue(
            self::evidenceValue($result, $scenarioResult, $scenarioId, 'rust_crate_provenance.resolved_version'),
        );
        $source = self::stringValue(
            self::evidenceValue($result, $scenarioResult, $scenarioId, 'rust_crate_provenance.source'),
        );
        $checksum = self::stringValue(
            self::evidenceValue($result, $scenarioResult, $scenarioId, 'rust_crate_provenance.checksum'),
        );
        if ($resolvedVersion !== $expectedVersion
            || ! self::isOfficialCratesIoRegistrySource($source)
            || preg_match('/^[0-9a-f]{64}$/', $checksum) !== 1) {
            $failures[] = [
                'code' => 'rust_crate_registry_provenance_mismatch',
                'scenario_id' => $scenarioId,
                'expected_version' => $expectedVersion,
                'resolved_version' => $resolvedVersion,
                'source' => $source,
                'checksum' => $checksum,
            ];
        }

        if ($scenarioId === 'rust_worker_rust_php_python_clients') {
            $registrationVersion = self::stringValue(
                self::evidenceValue($result, $scenarioResult, $scenarioId, 'rust_worker_registration.sdk_version'),
            );
            if ($expectedVersion === '' || ! str_ends_with($registrationVersion, '/'.$expectedVersion)) {
                $failures[] = [
                    'code' => 'rust_worker_registration_sdk_version_mismatch',
                    'scenario_id' => $scenarioId,
                    'expected_version' => $expectedVersion,
                    'registration_sdk_version' => $registrationVersion,
                ];
            }

            $avroSource = self::stringValue(
                self::evidenceValue($result, $scenarioResult, $scenarioId, 'apache_avro_provenance.source'),
            );
            $avroChecksum = self::stringValue(
                self::evidenceValue($result, $scenarioResult, $scenarioId, 'apache_avro_provenance.checksum'),
            );
            $payloadCodec = self::stringValue(
                self::evidenceValue($result, $scenarioResult, $scenarioId, 'valid_avro_signal_and_query.payload_codec'),
            );
            $defaultCodec = self::stringValue(
                self::evidenceValue($result, $scenarioResult, $scenarioId, 'valid_avro_signal_and_query.default_codec'),
            );
            if (! self::isOfficialCratesIoRegistrySource($avroSource)
                || preg_match('/^[0-9a-f]{64}$/', $avroChecksum) !== 1
                || $defaultCodec !== 'avro'
                || $payloadCodec !== 'avro') {
                $failures[] = [
                    'code' => 'rust_valid_avro_round_trip_not_proved',
                    'scenario_id' => $scenarioId,
                    'apache_avro_source' => $avroSource,
                    'apache_avro_checksum' => $avroChecksum,
                    'default_codec' => $defaultCodec,
                    'payload_codec' => $payloadCodec,
                ];
            }
        }

        if (in_array($scenarioId, ['python_worker_rust_client', 'php_worker_rust_client'], true)) {
            $defaultCodec = self::stringValue(
                self::evidenceValue($result, $scenarioResult, $scenarioId, 'default_codec'),
            );
            $payloadCodec = self::stringValue(
                self::evidenceValue($result, $scenarioResult, $scenarioId, 'payload_codec'),
            );
            if ($defaultCodec !== 'avro' || $payloadCodec !== 'avro') {
                $failures[] = [
                    'code' => 'rust_valid_avro_round_trip_not_proved',
                    'scenario_id' => $scenarioId,
                    'default_codec' => $defaultCodec,
                    'payload_codec' => $payloadCodec,
                ];
            }
        }

        $expectedModel = match ($scenarioId) {
            'rust_worker_rust_php_python_clients',
            'rust_query_error_and_immutability' => 'snapshot_derived_transport_state',
            'rust_replayed_instance_state_query_after_cold_restart' => 'replayed_workflow_instance_state',
            default => null,
        };
        if ($expectedModel !== null) {
            $model = self::stringValue(
                self::evidenceValue($result, $scenarioResult, $scenarioId, 'query_state_model'),
            );
            if ($model !== $expectedModel) {
                $failures[] = [
                    'code' => 'rust_query_state_model_mismatch',
                    'scenario_id' => $scenarioId,
                    'expected_model' => $expectedModel,
                    'actual_model' => $model,
                ];
            }
        }

        if ($scenarioId === 'rust_query_error_and_immutability') {
            $requirements = self::arrayValue($contract, 'scenario_requirements') ?? [];
            $rustRequirements = self::arrayValue($requirements, $scenarioId) ?? [];
            $allowedReasonContract = self::arrayValue($rustRequirements, 'allowed_reasons') ?? [];
            $documentedReasons = [
                'unknown_query.reason' => 'unknown_query',
                'malformed_query_payload.reason' => 'malformed_query_payload',
                'unavailable_query_handler.reason' => 'unavailable_query_handler',
                'incompatible_query_protocol.reason' => 'incompatible_query_protocol',
                'missing_workflow.reason' => 'missing_workflow',
                'terminal_signal.reason' => 'terminal_signal',
                'terminal_signal.rejection_reason' => 'terminal_signal',
            ];
            foreach ($documentedReasons as $path => $outcome) {
                $expectedReasons = self::stringList($allowedReasonContract[$outcome] ?? []);
                $actualReason = self::stringValue(
                    self::evidenceValue($result, $scenarioResult, $scenarioId, $path),
                );
                if (! in_array($actualReason, $expectedReasons, true)) {
                    $failures[] = [
                        'code' => 'rust_stable_outcome_reason_mismatch',
                        'scenario_id' => $scenarioId,
                        'field' => $path,
                        'expected_reasons' => $expectedReasons,
                        'actual_reason' => $actualReason,
                    ];
                }
            }

            $beforeHistory = self::integerValue(self::evidenceValue(
                $result,
                $scenarioResult,
                $scenarioId,
                'history_and_commands_before_first_successful_query.history_event_count',
            ));
            $afterSuccessHistory = self::integerValue(self::evidenceValue(
                $result,
                $scenarioResult,
                $scenarioId,
                'history_and_commands_after_successful_queries.history_event_count',
            ));
            $afterFailureHistory = self::integerValue(self::evidenceValue(
                $result,
                $scenarioResult,
                $scenarioId,
                'history_and_commands_after_failure_queries.history_event_count',
            ));
            $beforeCommands = self::integerValue(self::evidenceValue(
                $result,
                $scenarioResult,
                $scenarioId,
                'history_and_commands_before_first_successful_query.workflow_command_count',
            ));
            $afterSuccessCommands = self::integerValue(self::evidenceValue(
                $result,
                $scenarioResult,
                $scenarioId,
                'history_and_commands_after_successful_queries.workflow_command_count',
            ));
            $afterFailureCommands = self::integerValue(self::evidenceValue(
                $result,
                $scenarioResult,
                $scenarioId,
                'history_and_commands_after_failure_queries.workflow_command_count',
            ));
            if ($beforeHistory === null
                || $beforeCommands === null
                || $beforeHistory !== $afterSuccessHistory
                || $beforeHistory !== $afterFailureHistory
                || $beforeCommands !== $afterSuccessCommands
                || $beforeCommands !== $afterFailureCommands) {
                $failures[] = [
                    'code' => 'rust_query_history_or_commands_changed',
                    'scenario_id' => $scenarioId,
                    'history_counts' => [$beforeHistory, $afterSuccessHistory, $afterFailureHistory],
                    'workflow_command_counts' => [$beforeCommands, $afterSuccessCommands, $afterFailureCommands],
                ];
            }

            $beforeAnswer = self::evidenceValue(
                $result,
                $scenarioResult,
                $scenarioId,
                'answer_before_failures',
            );
            $afterAnswer = self::evidenceValue(
                $result,
                $scenarioResult,
                $scenarioId,
                'answer_after_failures',
            );
            if ($beforeAnswer !== $afterAnswer) {
                $failures[] = [
                    'code' => 'rust_failed_query_changed_later_answer',
                    'scenario_id' => $scenarioId,
                    'answer_before_failures' => $beforeAnswer,
                    'answer_after_failures' => $afterAnswer,
                ];
            }
        }

        if ($scenarioId === 'rust_replayed_instance_state_query_after_cold_restart') {
            $requirements = self::arrayValue($contract, 'scenario_requirements') ?? [];
            $rustRequirements = self::arrayValue($requirements, $scenarioId) ?? [];
            $failedQueryReasons = self::stringList($rustRequirements['failed_query_allowed_reasons'] ?? []);
            $initialProcess = self::stringValue(
                self::evidenceValue($result, $scenarioResult, $scenarioId, 'initial_worker_process_id'),
            );
            $freshProcess = self::stringValue(
                self::evidenceValue($result, $scenarioResult, $scenarioId, 'cold_restart.fresh_worker_process_id'),
            );
            if ($initialProcess === '' || $freshProcess === '' || $initialProcess === $freshProcess) {
                $failures[] = [
                    'code' => 'rust_replay_cold_restart_not_proved',
                    'scenario_id' => $scenarioId,
                    'initial_worker_process_id' => $initialProcess,
                    'fresh_worker_process_id' => $freshProcess,
                ];
            }

            foreach (['running', 'cold_restarted', 'completed'] as $checkpoint) {
                $beforeHistory = self::integerValue(self::evidenceValue(
                    $result,
                    $scenarioResult,
                    $scenarioId,
                    "immutability_checkpoints.$checkpoint.before_first_successful_query.history_event_count",
                ));
                $afterHistory = self::integerValue(self::evidenceValue(
                    $result,
                    $scenarioResult,
                    $scenarioId,
                    "immutability_checkpoints.$checkpoint.after_successful_and_failed_queries.history_event_count",
                ));
                $beforeCommands = self::integerValue(self::evidenceValue(
                    $result,
                    $scenarioResult,
                    $scenarioId,
                    "immutability_checkpoints.$checkpoint.before_first_successful_query.workflow_command_count",
                ));
                $afterCommands = self::integerValue(self::evidenceValue(
                    $result,
                    $scenarioResult,
                    $scenarioId,
                    "immutability_checkpoints.$checkpoint.after_successful_and_failed_queries.workflow_command_count",
                ));
                if ($beforeHistory === null
                    || $beforeCommands === null
                    || $beforeHistory !== $afterHistory
                    || $beforeCommands !== $afterCommands) {
                    $failures[] = [
                        'code' => 'rust_replay_query_history_or_commands_changed',
                        'scenario_id' => $scenarioId,
                        'checkpoint' => $checkpoint,
                        'before_history_event_count' => $beforeHistory,
                        'after_history_event_count' => $afterHistory,
                        'before_workflow_command_count' => $beforeCommands,
                        'after_workflow_command_count' => $afterCommands,
                    ];
                }

                $failedReason = self::stringValue(self::evidenceValue(
                    $result,
                    $scenarioResult,
                    $scenarioId,
                    "immutability_checkpoints.$checkpoint.failed_query.reason",
                ));
                if (! in_array($failedReason, $failedQueryReasons, true)) {
                    $failures[] = [
                        'code' => 'rust_replay_failed_query_reason_mismatch',
                        'scenario_id' => $scenarioId,
                        'checkpoint' => $checkpoint,
                        'expected_reasons' => $failedQueryReasons,
                        'actual_reason' => $failedReason,
                    ];
                }

                $beforeAnswer = self::evidenceValue(
                    $result,
                    $scenarioResult,
                    $scenarioId,
                    "immutability_checkpoints.$checkpoint.answer_before_failed_query",
                );
                $afterAnswer = self::evidenceValue(
                    $result,
                    $scenarioResult,
                    $scenarioId,
                    "immutability_checkpoints.$checkpoint.answer_after_failed_query",
                );
                if ($beforeAnswer !== $afterAnswer) {
                    $failures[] = [
                        'code' => 'rust_replay_failed_query_changed_later_answer',
                        'scenario_id' => $scenarioId,
                        'checkpoint' => $checkpoint,
                        'answer_before_failed_query' => $beforeAnswer,
                        'answer_after_failed_query' => $afterAnswer,
                    ];
                }
            }
        }

        return $failures;
    }

    private static function isOfficialCratesIoRegistrySource(string $source): bool
    {
        return in_array($source, [
            'registry+https://github.com/rust-lang/crates.io-index',
            'registry+https://index.crates.io',
            'registry+https://index.crates.io/',
            'registry+sparse+https://index.crates.io',
            'registry+sparse+https://index.crates.io/',
            'sparse+https://index.crates.io',
            'sparse+https://index.crates.io/',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     * @param  array<string, mixed>  $scenarioResult
     * @return array<int, array<string, mixed>>
     */
    private static function pythonWorkerBaselineFailures(
        array $result,
        array $contract,
        array $scenarioResult,
        string $scenarioId,
    ): array {
        $failures = [];
        $runtime = self::stringValue(
            self::evidenceValue($result, $scenarioResult, $scenarioId, 'worker_runtime'),
        );
        if (! self::sameRuntime($runtime, 'sdk-python')) {
            $failures[] = [
                'code' => 'python_worker_baseline_runtime_not_sdk_python',
                'scenario_id' => $scenarioId,
                'field' => 'worker_runtime',
                'runtime' => $runtime,
            ];
        }

        $source = self::stringValue(
            self::evidenceValue($result, $scenarioResult, $scenarioId, 'python_worker_artifact_source'),
        );
        if (! self::publishedPythonSdkSource($source, self::expectedArtifactSources($contract))) {
            $failures[] = [
                'code' => 'python_worker_baseline_source_not_published_sdk',
                'scenario_id' => $scenarioId,
                'field' => 'python_worker_artifact_source',
                'source' => $source,
            ];
        }

        $version = self::stringValue(
            self::evidenceValue($result, $scenarioResult, $scenarioId, 'python_worker_sdk_version'),
        );
        $expectedVersion = self::artifactVersionValue(self::artifactVersions($result), 'sdk-python');
        if ($version === '' || self::isPlaceholderVersion($version)) {
            $failures[] = [
                'code' => 'python_worker_baseline_missing_sdk_version',
                'scenario_id' => $scenarioId,
                'field' => 'python_worker_sdk_version',
                'version' => $version,
            ];
        } elseif ($expectedVersion !== '' && ! self::samePythonRelease($expectedVersion, $version)) {
            $failures[] = [
                'code' => 'python_worker_baseline_sdk_version_mismatch',
                'scenario_id' => $scenarioId,
                'field' => 'python_worker_sdk_version',
                'version' => $version,
                'expected_version' => $expectedVersion,
            ];
        }

        array_push(
            $failures,
            ...self::routedCurrentQueryTaskFailures(
                $result,
                $scenarioResult,
                $scenarioId,
                expectedRuntime: 'sdk-python',
                failureCode: 'python_worker_baseline_current_query_not_routed',
                mismatchCode: 'python_worker_baseline_current_query_route_mismatch',
            ),
        );

        $directRoute = self::directObservedOutput(
            $scenarioResult,
            'routed_current_query_task',
            'routedCurrentQueryTask',
        );
        $routeSdkVersion = self::stringValue(
            is_array($directRoute) ? ($directRoute['worker_sdk_version'] ?? null) : null,
        );
        if (
            $routeSdkVersion === ''
            || ($expectedVersion !== '' && ! self::samePythonRelease($expectedVersion, $routeSdkVersion))
        ) {
            $failures[] = [
                'code' => 'python_worker_baseline_routed_query_sdk_version_mismatch',
                'scenario_id' => $scenarioId,
                'field' => 'routed_current_query_task.worker_sdk_version',
                'version' => $routeSdkVersion,
                'expected_version' => $expectedVersion,
            ];
        }

        $readiness = self::arrayEvidenceValue(
            $result,
            $scenarioResult,
            $scenarioId,
            'readiness_boundary',
        );
        if ($readiness === null || ($readiness['status'] ?? null) !== 'pass') {
            $failures[] = [
                'code' => 'python_worker_baseline_readiness_boundary_missing',
                'scenario_id' => $scenarioId,
                'field' => 'readiness_boundary',
            ];
        } else {
            foreach ([
                'registered_query_task_capability',
                'initial_state_restored',
                'query_handler_ready',
                'restart_state_restored',
            ] as $field) {
                if (($readiness[$field] ?? null) !== true) {
                    $failures[] = [
                        'code' => 'python_worker_baseline_readiness_boundary_incomplete',
                        'scenario_id' => $scenarioId,
                        'field' => 'readiness_boundary.'.$field,
                    ];
                }
            }
            foreach ([
                'worker_id',
                'restart_worker_id',
                'task_queue',
                'run_id',
                'installed_package_version',
                'installed_package_version_verified_at',
                'worker_started_at',
                'worker_registered_at',
                'initial_state_restored_at',
                'query_handler_ready_at',
                'restart_worker_registered_at',
                'evidence_captured_at',
            ] as $field) {
                if (self::stringValue($readiness[$field] ?? null) === '') {
                    $failures[] = [
                        'code' => 'python_worker_baseline_readiness_boundary_incomplete',
                        'scenario_id' => $scenarioId,
                        'field' => 'readiness_boundary.'.$field,
                    ];
                }
            }
            if (! self::timestampSequenceIsOrdered($readiness, [
                'installed_package_version_verified_at',
                'worker_started_at',
                'worker_registered_at',
                'initial_state_restored_at',
                'query_handler_ready_at',
                'restart_worker_registered_at',
                'evidence_captured_at',
            ])) {
                $failures[] = [
                    'code' => 'python_worker_baseline_readiness_timestamps_invalid',
                    'scenario_id' => $scenarioId,
                    'field' => 'readiness_boundary',
                ];
            }
            $observedWorkerId = self::stringValue(self::directObservedOutput(
                $scenarioResult,
                'worker_id',
            ));
            $observedTaskQueue = self::stringValue(self::directObservedOutput(
                $scenarioResult,
                'task_queue',
            ));
            $observedRunId = self::stringValue(self::directObservedOutput(
                $scenarioResult,
                'run_id',
            ));
            $readinessInstalledVersion = self::stringValue(
                $readiness['installed_package_version'] ?? null,
            );
            if (
                self::stringValue($readiness['worker_id'] ?? null) !== $observedWorkerId
                || self::stringValue($readiness['task_queue'] ?? null) !== $observedTaskQueue
                || self::stringValue($readiness['run_id'] ?? null) !== $observedRunId
                || (
                    $expectedVersion !== ''
                    && ! self::samePythonRelease($expectedVersion, $readinessInstalledVersion)
                )
            ) {
                $failures[] = [
                    'code' => 'python_worker_baseline_readiness_identity_mismatch',
                    'scenario_id' => $scenarioId,
                    'field' => 'readiness_boundary',
                ];
            }
        }

        $restart = self::arrayEvidenceValue(
            $result,
            $scenarioResult,
            $scenarioId,
            'controlled_restart',
        );
        if ($restart === null || ($restart['status'] ?? null) !== 'pass') {
            $failures[] = [
                'code' => 'python_worker_baseline_controlled_restart_missing',
                'scenario_id' => $scenarioId,
                'field' => 'controlled_restart',
            ];
        } else {
            $previousWorkerId = self::stringValue($restart['previous_worker_id'] ?? null);
            $restartWorkerId = self::stringValue($restart['worker_id'] ?? null);
            $restartRoute = is_array($restart['routed_current_query_task'] ?? null)
                ? $restart['routed_current_query_task']
                : null;
            $restartRouteSdkVersion = self::stringValue(
                is_array($restartRoute) ? ($restartRoute['worker_sdk_version'] ?? null) : null,
            );
            $restartRegistration = is_array($restart['worker_registration'] ?? null)
                ? $restart['worker_registration']
                : null;
            $observedWorkerId = self::stringValue(self::directObservedOutput(
                $scenarioResult,
                'worker_id',
            ));
            $observedTaskQueue = self::stringValue(self::directObservedOutput(
                $scenarioResult,
                'task_queue',
            ));
            $observedRunId = self::stringValue(self::directObservedOutput(
                $scenarioResult,
                'run_id',
            ));
            if (
                $previousWorkerId === ''
                || $restartWorkerId === ''
                || $previousWorkerId === $restartWorkerId
                || $previousWorkerId !== $observedWorkerId
                || self::stringValue($restart['task_queue'] ?? null) !== $observedTaskQueue
                || self::stringValue($restart['run_id'] ?? null) !== $observedRunId
                || ($restart['repeat_query_consistency'] ?? null) !== true
                || ($restart['expected_replayed_state'] ?? null) !== ($restart['query_result'] ?? null)
                || ($restart['query_result'] ?? null) !== ($restart['repeat_query_result'] ?? null)
                || ! is_array($restartRoute)
                || ! is_array($restartRegistration)
                || self::stringValue($restartRegistration['worker_id'] ?? null) !== $restartWorkerId
                || self::stringValue($restartRegistration['task_queue'] ?? null) !== $observedTaskQueue
                || ! is_array($restartRegistration['capabilities'] ?? null)
                || ! in_array('query_tasks', $restartRegistration['capabilities'], true)
                || self::stringValue($restartRoute['worker_id'] ?? null) !== $restartWorkerId
                || self::stringValue($restartRoute['query_name'] ?? null) !== 'current'
                || ! in_array(self::stringValue($restartRoute['status'] ?? null), ['pass', 'completed'], true)
                || (
                    $expectedVersion !== ''
                    && ! self::samePythonRelease($expectedVersion, $restartRouteSdkVersion)
                )
                || ! self::timestampSequenceIsOrdered($restart, [
                    'worker_stopped_at',
                    'worker_restart_at',
                    'worker_registered_at',
                    'query_sent_at',
                    'query_completed_at',
                    'repeat_query_completed_at',
                ])
            ) {
                $failures[] = [
                    'code' => 'python_worker_baseline_controlled_restart_mismatch',
                    'scenario_id' => $scenarioId,
                    'field' => 'controlled_restart',
                ];
            }
        }

        return $failures;
    }

    private static function samePythonRelease(string $expected, string $observed): bool
    {
        $expectedIdentity = self::pythonReleaseIdentity($expected);

        return $expectedIdentity !== null && $expectedIdentity === self::pythonReleaseIdentity($observed);
    }

    private static function pythonReleaseIdentity(string $version): ?string
    {
        $version = trim($version);
        if (preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/', $version) === 1) {
            return $version;
        }

        if (preg_match(
            '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)-(alpha|beta|rc)\.(0|[1-9]\d*)$/i',
            $version,
            $matches,
        ) === 1) {
            $phase = match (strtolower($matches[4])) {
                'alpha' => 'a',
                'beta' => 'b',
                'rc' => 'rc',
            };

            return $matches[1].'.'.$matches[2].'.'.$matches[3].$phase.$matches[5];
        }

        if (preg_match(
            '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(a|b|rc)(0|[1-9]\d*)$/i',
            $version,
            $matches,
        ) === 1) {
            return $matches[1].'.'.$matches[2].'.'.$matches[3].strtolower($matches[4]).$matches[5];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @param  list<string>  $fields
     */
    private static function timestampSequenceIsOrdered(array $evidence, array $fields): bool
    {
        $previous = null;
        foreach ($fields as $field) {
            $value = self::stringValue($evidence[$field] ?? null);
            if ($value === '') {
                return false;
            }

            try {
                $timestamp = new \DateTimeImmutable($value);
            } catch (\Exception) {
                return false;
            }

            if ($previous !== null && $timestamp < $previous) {
                return false;
            }
            $previous = $timestamp;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $scenarioResult
     * @return array<int, array<string, mixed>>
     */
    private static function routedCurrentQueryTaskFailures(
        array $result,
        array $scenarioResult,
        string $scenarioId,
        string $expectedRuntime,
        string $failureCode,
        string $mismatchCode,
    ): array {
        $routeValue = self::directObservedOutput(
            $scenarioResult,
            'routed_current_query_task',
            'routedCurrentQueryTask',
        );
        $route = is_array($routeValue) ? $routeValue : null;
        if ($route === null) {
            return [
                [
                    'code' => $failureCode,
                    'scenario_id' => $scenarioId,
                    'field' => 'routed_current_query_task',
                    'reason' => 'missing_route_metadata',
                ],
            ];
        }

        $failures = [];
        $status = strtolower(trim(self::stringValue($route['status'] ?? null)));
        if (! in_array($status, ['pass', 'completed'], true)) {
            $failures[] = [
                'code' => $failureCode,
                'scenario_id' => $scenarioId,
                'field' => 'routed_current_query_task.status',
                'expected' => ['pass', 'completed'],
                'actual' => $status,
            ];
        }

        foreach ([
            'query_name' => 'current',
            'worker_runtime' => $expectedRuntime,
            'public_query_surface' => 'cli',
            'server_route' => 'worker_query_task_poll',
            'completion_route' => 'worker_query_task_complete',
        ] as $field => $expected) {
            $actual = self::stringValue($route[$field] ?? null);
            if ($actual === $expected) {
                continue;
            }

            $failures[] = [
                'code' => $failureCode,
                'scenario_id' => $scenarioId,
                'field' => 'routed_current_query_task.'.$field,
                'expected' => $expected,
                'actual' => $actual,
            ];
        }

        foreach ([
            'query_task_id',
            'workflow_id',
            'run_id',
            'workflow_type',
            'task_queue',
            'worker_id',
            'lease_owner',
        ] as $field) {
            if (self::stringValue($route[$field] ?? null) !== '') {
                continue;
            }

            $failures[] = [
                'code' => $failureCode,
                'scenario_id' => $scenarioId,
                'field' => 'routed_current_query_task.'.$field,
                'reason' => 'missing_public_task_metadata',
            ];
        }

        $attempt = self::integerValue($route['query_task_attempt'] ?? null);
        if ($attempt === null || $attempt < 1) {
            $failures[] = [
                'code' => $failureCode,
                'scenario_id' => $scenarioId,
                'field' => 'routed_current_query_task.query_task_attempt',
                'reason' => 'missing_public_task_attempt',
            ];
        }

        foreach ([
            'workflow_id',
            'run_id',
            'task_queue',
            'worker_id',
        ] as $field) {
            $expected = self::stringValue(self::evidenceValue($result, $scenarioResult, $scenarioId, $field));
            if ($expected === '') {
                continue;
            }

            $actual = self::stringValue($route[$field] ?? null);
            if ($actual === $expected) {
                continue;
            }

            $failures[] = [
                'code' => $mismatchCode,
                'scenario_id' => $scenarioId,
                'field' => 'routed_current_query_task.'.$field,
                'expected' => $expected,
                'actual' => $actual,
            ];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $scenarioResult
     * @return array<int, array<string, mixed>>
     */
    private static function unknownHandlerReasonFailures(
        array $result,
        array $scenarioResult,
        string $scenarioId,
    ): array {
        $failures = [];

        foreach ([
            'unknown_signal.reason' => ['unknown_signal'],
            'missing_workflow_signal.reason' => ['instance_not_found'],
            'missing_workflow_query.reason' => ['instance_not_found'],
            'query_not_found.reason' => ['query_not_found', 'rejected_unknown_query'],
            'rejected_unknown_query.reason' => ['query_not_found', 'rejected_unknown_query'],
            'cli_unknown_signal_sample.reason' => ['unknown_signal'],
            'cli_unknown_query_sample.reason' => ['query_not_found', 'rejected_unknown_query'],
            'cli_missing_workflow_signal_sample.reason' => ['instance_not_found'],
            'cli_missing_workflow_query_sample.reason' => ['instance_not_found'],
            'sdk_python_unknown_signal_sample.reason' => ['unknown_signal'],
            'sdk_python_unknown_query_sample.reason' => ['query_not_found', 'rejected_unknown_query'],
            'sdk_python_missing_workflow_signal_sample.reason' => ['instance_not_found'],
            'sdk_python_missing_workflow_query_sample.reason' => ['instance_not_found'],
        ] as $evidenceKey => $expectedReasons) {
            if (str_contains($evidenceKey, '_sample.')) {
                $sampleKey = explode('.', $evidenceKey, 2)[0];
                if (self::evidenceValue($result, $scenarioResult, $scenarioId, $sampleKey) === null) {
                    continue;
                }
            }

            $actualReason = self::stringValue(
                self::evidenceValue($result, $scenarioResult, $scenarioId, $evidenceKey),
            );

            if (in_array($actualReason, $expectedReasons, true)) {
                continue;
            }

            $failures[] = [
                'code' => 'unexpected_unknown_handler_reason',
                'scenario_id' => $scenarioId,
                'evidence_key' => $evidenceKey,
                'expected_reasons' => $expectedReasons,
                'actual_reason' => $actualReason,
            ];
        }

        foreach ([
            'sdk_python_unknown_signal_sample.exception' => 'SignalFailed',
            'sdk_python_unknown_query_sample.exception' => 'QueryFailed',
            'sdk_python_missing_workflow_signal_sample.exception' => 'WorkflowNotFound',
            'sdk_python_missing_workflow_query_sample.exception' => 'WorkflowNotFound',
        ] as $evidenceKey => $expectedException) {
            $sampleKey = explode('.', $evidenceKey, 2)[0];
            if (self::evidenceValue($result, $scenarioResult, $scenarioId, $sampleKey) === null) {
                continue;
            }

            $actualException = self::stringValue(
                self::evidenceValue($result, $scenarioResult, $scenarioId, $evidenceKey),
            );

            if ($actualException === $expectedException) {
                continue;
            }

            $failures[] = [
                'code' => 'unexpected_unknown_handler_sdk_exception',
                'scenario_id' => $scenarioId,
                'evidence_key' => $evidenceKey,
                'expected_exception' => $expectedException,
                'actual_exception' => $actualException,
            ];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $scenarioResult
     * @return array<int, array<string, mixed>>
     */
    private static function unknownHandlerAuditFailures(
        array $result,
        array $scenarioResult,
        string $scenarioId,
    ): array {
        $failures = [];
        $audit = self::evidenceValue($result, $scenarioResult, $scenarioId, 'rejected_signal_audit_rows');
        $runId = self::stringValue(
            self::evidenceValue($result, $scenarioResult, $scenarioId, 'run_id'),
        );
        $expectedRows = is_array($audit) && is_array($audit['expected_rows'] ?? null)
            ? $audit['expected_rows']
            : [];
        $observedRows = is_array($audit) && is_array($audit['observed_rows'] ?? null)
            ? $audit['observed_rows']
            : [];
        $allowedTargets = [
            'missing|unknown_signal|rejected_unknown_signal',
            'increment|invalid_signal_arguments|rejected_invalid_arguments',
        ];
        $rowsAreExactRejectedAudits = $runId !== ''
            && $expectedRows !== []
            && $expectedRows === $observedRows
            && ($audit['exact_match'] ?? null) === true
            && self::integerValue($audit['executable_or_ready_command_count'] ?? null) === 0;
        $unknownSignalRowObserved = false;

        foreach ($expectedRows as $row) {
            if (! is_array($row)) {
                $rowsAreExactRejectedAudits = false;

                continue;
            }

            $reason = self::stringValue($row['reason'] ?? null);
            $target = implode('|', [
                self::stringValue($row['target_name'] ?? null),
                $reason,
                self::stringValue($row['outcome'] ?? null),
            ]);
            $unknownSignalRowObserved = $unknownSignalRowObserved || $reason === 'unknown_signal';
            $rowsAreExactRejectedAudits = $rowsAreExactRejectedAudits
                && ($row['type'] ?? null) === 'signal'
                && ($row['target_scope'] ?? null) === 'instance'
                && ($row['requested_run_id'] ?? null) === null
                && ($row['resolved_run_id'] ?? null) === $runId
                && in_array($target, $allowedTargets, true)
                && ($row['status'] ?? null) === 'rejected'
                && ($row['rejection_reason'] ?? null) === $reason
                && ($row['accepted_at'] ?? null) === null
                && ($row['applied_at'] ?? null) === null
                && ($row['rejected_at_recorded'] ?? null) === true;
        }

        if (! $rowsAreExactRejectedAudits || ! $unknownSignalRowObserved) {
            $failures[] = [
                'code' => 'unexpected_rejected_signal_audit_rows',
                'scenario_id' => $scenarioId,
                'field' => 'rejected_signal_audit_rows',
                'expected' => 'only exact rejected signal audit rows for the selected run',
                'actual' => $audit,
            ];
        }

        $before = self::evidenceValue(
            $result,
            $scenarioResult,
            $scenarioId,
            'history_and_commands_before_rejected_requests',
        );
        $afterRejected = self::evidenceValue(
            $result,
            $scenarioResult,
            $scenarioId,
            'history_and_commands_after_rejected_requests',
        );
        $afterRecovery = self::evidenceValue(
            $result,
            $scenarioResult,
            $scenarioId,
            'history_and_commands_after_recovery_query',
        );
        $afterAll = self::evidenceValue(
            $result,
            $scenarioResult,
            $scenarioId,
            'history_and_commands_after_all_requests',
        );
        $taskSetsUnchanged = is_array($before)
            && is_array($afterRejected)
            && is_array($afterRecovery)
            && is_array($afterAll)
            && self::integerValue($before['ready_or_leased_workflow_task_count'] ?? null)
                === self::integerValue($afterRejected['ready_or_leased_workflow_task_count'] ?? null)
            && self::stringValue($before['ready_or_leased_workflow_task_set_sha256'] ?? null) !== ''
            && self::stringValue($before['ready_or_leased_workflow_task_set_sha256'] ?? null)
                === self::stringValue($afterRejected['ready_or_leased_workflow_task_set_sha256'] ?? null)
            && self::integerValue($afterRejected['ready_or_leased_workflow_task_count'] ?? null)
                === self::integerValue($afterRecovery['ready_or_leased_workflow_task_count'] ?? null)
            && self::stringValue($afterRejected['ready_or_leased_workflow_task_set_sha256'] ?? null)
                === self::stringValue($afterRecovery['ready_or_leased_workflow_task_set_sha256'] ?? null)
            && self::integerValue($afterRecovery['ready_or_leased_workflow_task_count'] ?? null)
                === self::integerValue($afterAll['ready_or_leased_workflow_task_count'] ?? null)
            && self::stringValue($afterRecovery['ready_or_leased_workflow_task_set_sha256'] ?? null) !== ''
            && self::stringValue($afterRecovery['ready_or_leased_workflow_task_set_sha256'] ?? null)
                === self::stringValue($afterAll['ready_or_leased_workflow_task_set_sha256'] ?? null);
        if (! $taskSetsUnchanged) {
            $failures[] = [
                'code' => 'rejected_signal_created_ready_or_leased_workflow_task',
                'scenario_id' => $scenarioId,
                'field' => 'ready_or_leased_workflow_tasks',
            ];
        }

        $handlerInvocationCount = self::integerValue(
            self::evidenceValue(
                $result,
                $scenarioResult,
                $scenarioId,
                'rejected_signal_handler_invocation_count',
            ),
        );
        if ($handlerInvocationCount !== 0) {
            $failures[] = [
                'code' => 'rejected_signal_invoked_handler',
                'scenario_id' => $scenarioId,
                'field' => 'rejected_signal_handler_invocation_count',
                'expected' => 0,
                'actual' => $handlerInvocationCount,
            ];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $scenarioResult
     * @param  list<array{0: string, 1: string, 2: string}>  $orders
     * @return array<int, array<string, mixed>>
     */
    private static function timestampOrderFailures(
        array $result,
        array $scenarioResult,
        string $scenarioId,
        array $orders,
        string $code,
    ): array {
        $failures = [];

        foreach ($orders as [$leftKey, $operator, $rightKey]) {
            $left = self::timestampMicroseconds(
                self::evidenceValue($result, $scenarioResult, $scenarioId, $leftKey),
            );
            $right = self::timestampMicroseconds(
                self::evidenceValue($result, $scenarioResult, $scenarioId, $rightKey),
            );

            if ($left === null || $right === null) {
                $failures[] = [
                    'code' => 'invalid_replay_timing_timestamp',
                    'scenario_id' => $scenarioId,
                    'left_key' => $leftKey,
                    'right_key' => $rightKey,
                ];

                continue;
            }

            $passes = $operator === '<'
                ? $left < $right
                : $left <= $right;

            if ($passes) {
                continue;
            }

            $failures[] = [
                'code' => $code,
                'scenario_id' => $scenarioId,
                'left_key' => $leftKey,
                'operator' => $operator,
                'right_key' => $rightKey,
                'left_value' => self::evidenceValue($result, $scenarioResult, $scenarioId, $leftKey),
                'right_value' => self::evidenceValue($result, $scenarioResult, $scenarioId, $rightKey),
            ];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $scenarioResult
     * @param  array<string, array{0: int, 1: int}>  $ranges
     * @return array<int, array<string, mixed>>
     */
    private static function statusCodeFailures(
        array $result,
        array $scenarioResult,
        string $scenarioId,
        array $ranges,
    ): array {
        $failures = [];

        foreach ($ranges as $evidenceKey => [$minimum, $maximum]) {
            $value = self::evidenceValue($result, $scenarioResult, $scenarioId, $evidenceKey);
            $status = self::integerValue($value);

            if ($status !== null && $status >= $minimum && $status <= $maximum) {
                continue;
            }

            $failures[] = [
                'code' => 'unexpected_status_code',
                'scenario_id' => $scenarioId,
                'evidence_key' => $evidenceKey,
                'expected_minimum' => $minimum,
                'expected_maximum' => $maximum,
                'actual_status_code' => $value,
            ];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $scenarioResult
     * @return array<int, array<string, mixed>>
     */
    private static function terminalRunReasonFailures(
        array $result,
        array $scenarioResult,
        string $scenarioId,
    ): array {
        $failures = [];
        $signalReason = self::stringValue(self::evidenceValue($result, $scenarioResult, $scenarioId, 'signal_error.reason'));
        $signalRejectionReason = self::stringValue(
            self::evidenceValue($result, $scenarioResult, $scenarioId, 'signal_error.rejection_reason'),
        );

        if ($signalReason !== 'run_not_active' || $signalRejectionReason !== 'run_not_active') {
            $failures[] = [
                'code' => 'unexpected_terminal_signal_reason',
                'scenario_id' => $scenarioId,
                'expected_reason' => 'run_not_active',
                'actual_reason' => $signalReason,
                'actual_rejection_reason' => $signalRejectionReason,
            ];
        }

        $queryStatus = self::integerValue(
            self::evidenceValue($result, $scenarioResult, $scenarioId, 'query_result_or_error.status_code'),
        );
        $queryReason = self::stringValue(
            self::evidenceValue($result, $scenarioResult, $scenarioId, 'query_result_or_error.reason'),
        );

        if ($queryStatus !== null && $queryStatus >= 400 && $queryReason === '') {
            $failures[] = [
                'code' => 'missing_terminal_query_reason',
                'scenario_id' => $scenarioId,
                'status_code' => $queryStatus,
            ];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $scenarioResult
     * @return array<int, array<string, mixed>>
     */
    private static function terminalRunImmutabilityFailures(
        array $result,
        array $scenarioResult,
        string $scenarioId,
    ): array {
        $failures = [];

        foreach ([
            'terminal_result_changed_after_operations' => 'terminal_result_changed_after_operations',
            'terminal_history_changed_after_operations' => 'terminal_history_changed_after_operations',
        ] as $evidenceKey => $code) {
            $actualValues = [];
            foreach (self::evidenceContainers($result, $scenarioResult, $scenarioId) as $container) {
                $actual = str_contains($evidenceKey, '.')
                    ? self::pathValue($container, explode('.', $evidenceKey))
                    : self::recursiveKeyValue($container, $evidenceKey);
                if (self::evidencePresent($actual)) {
                    $actualValues[] = $actual;
                }
            }

            $unexpectedValues = array_values(array_filter(
                $actualValues,
                static fn (mixed $actual): bool => $actual !== false,
            ));
            if ($unexpectedValues === []) {
                continue;
            }

            $failures[] = [
                'code' => $code,
                'scenario_id' => $scenarioId,
                'evidence_key' => $evidenceKey,
                'expected_value' => false,
                'actual_values' => $actualValues,
            ];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $scenarioResult
     * @return array<int, array<string, mixed>>
     */
    private static function malformedPayloadReasonFailures(
        array $result,
        array $scenarioResult,
        string $scenarioId,
    ): array {
        $failures = [];

        foreach ([
            'invalid_signal_arguments.reason' => 'invalid_signal_arguments',
            'invalid_query_arguments.reason' => 'invalid_query_arguments',
        ] as $evidenceKey => $expectedReason) {
            $actualReason = self::stringValue(
                self::evidenceValue($result, $scenarioResult, $scenarioId, $evidenceKey),
            );

            if ($actualReason === $expectedReason) {
                continue;
            }

            $failures[] = [
                'code' => 'unexpected_malformed_payload_reason',
                'scenario_id' => $scenarioId,
                'evidence_key' => $evidenceKey,
                'expected_reason' => $expectedReason,
                'actual_reason' => $actualReason,
            ];
        }

        foreach ([
            'signal_handler_invocation_count_after_invalid_payload',
            'query_state_mutation_count_after_invalid_payload',
        ] as $evidenceKey) {
            $actualCount = self::integerValue(
                self::evidenceValue($result, $scenarioResult, $scenarioId, $evidenceKey),
            );

            if ($actualCount === 0) {
                continue;
            }

            $failures[] = [
                'code' => 'malformed_payload_side_effect_observed',
                'scenario_id' => $scenarioId,
                'evidence_key' => $evidenceKey,
                'expected_count' => 0,
                'actual_count' => $actualCount,
            ];
        }

        return $failures;
    }

    /**
     * @param  list<string>  $missingScenarios
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    private static function missingScenarioFindingFailures(array $missingScenarios, array $result): array
    {
        $failures = [];

        foreach ($missingScenarios as $scenarioId) {
            if (self::hasLinkedFindings(['scenario_id' => $scenarioId], $result)) {
                continue;
            }

            $failures[] = [
                'code' => 'missing_required_scenario_finding',
                'scenario_id' => $scenarioId,
            ];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $requirement
     * @return list<string>
     */
    private static function requiredEvidenceKeys(array $requirement): array
    {
        return array_values(array_unique(array_merge(
            self::stringList($requirement['evidence'] ?? []),
            self::stringList($requirement['required_errors'] ?? []),
            self::stringList($requirement['required_surfaces'] ?? []),
        )));
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $scenarioResult
     */
    private static function hasEvidence(
        array $result,
        array $scenarioResult,
        string $scenarioId,
        string $evidenceKey,
    ): bool {
        if (
            $evidenceKey === 'routed_current_query_task'
            && in_array($scenarioId, [
                'python_worker_cli_and_sdk_baseline',
                'php_worker_cli_and_sdk_baseline',
            ], true)
        ) {
            return self::requiredEvidencePresent(
                $evidenceKey,
                self::directObservedOutput($scenarioResult, $evidenceKey, 'routedCurrentQueryTask'),
            );
        }

        return self::requiredEvidencePresent(
            $evidenceKey,
            self::evidenceValue($result, $scenarioResult, $scenarioId, $evidenceKey),
        );
    }

    /**
     * @param  array<string, mixed>  $scenarioResult
     */
    private static function directObservedOutput(
        array $scenarioResult,
        string ...$fields,
    ): mixed {
        $observed = self::arrayValue($scenarioResult, 'observed_outputs')
            ?? self::arrayValue($scenarioResult, 'observedOutputs')
            ?? self::arrayValue($scenarioResult, 'runtime_matrix')
            ?? self::arrayValue($scenarioResult, 'runtimeMatrix');
        if ($observed === null) {
            return null;
        }

        foreach ($fields as $field) {
            if (array_key_exists($field, $observed)) {
                return $observed[$field];
            }
        }

        return null;
    }

    private static function requiredEvidencePresent(string $evidenceKey, mixed $value): bool
    {
        if (in_array($evidenceKey, self::TRUTHY_REQUIRED_EVIDENCE, true)) {
            if ($value === true) {
                return true;
            }

            return is_string($value) && in_array(
                strtolower(trim($value)),
                ['true', 'pass', 'passed', 'ok', 'yes'],
                true,
            );
        }

        return self::evidencePresent($value);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $scenarioResult
     */
    private static function evidenceValue(
        array $result,
        array $scenarioResult,
        string $scenarioId,
        string $evidenceKey,
    ): mixed {
        foreach (self::evidenceContainers($result, $scenarioResult, $scenarioId) as $container) {
            $value = str_contains($evidenceKey, '.')
                ? self::pathValue($container, explode('.', $evidenceKey))
                : self::recursiveKeyValue($container, $evidenceKey);

            if (self::evidencePresent($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $scenarioResult
     * @return array<int, array<mixed>>
     */
    private static function evidenceContainers(array $result, array $scenarioResult, string $scenarioId): array
    {
        $containers = [$scenarioResult];

        foreach (['observed_outputs', 'observedOutputs', 'runtime_matrix', 'runtimeMatrix'] as $field) {
            $value = self::arrayValue($scenarioResult, $field);
            if ($value !== null) {
                $containers[] = $value;
            }
        }

        foreach (array_keys(self::EVIDENCE_SECTION_SCENARIOS) as $field) {
            $section = self::arrayValue($result, $field);
            if ($section === null) {
                continue;
            }

            array_push($containers, ...self::scenarioSectionContainers($section, $scenarioId));
        }

        return $containers;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<int, array{value: array<mixed>, path: string}>
     */
    private static function sectionPolicyContainers(array $result): array
    {
        $containers = [];
        foreach (array_keys(self::EVIDENCE_SECTION_SCENARIOS) as $section) {
            $value = self::arrayValue($result, $section);
            if (is_array($value)) {
                $containers[] = [
                    'value' => $value,
                    'path' => self::pathFor('$', $section),
                ];
            }
        }

        return $containers;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $scenarioResult
     * @return array<int, array{value: array<mixed>, path: string, recursive: bool}>
     */
    private static function scenarioPolicyContainers(array $result, array $scenarioResult, string $scenarioId): array
    {
        $containers = [[
            'value' => $scenarioResult,
            'path' => self::pathFor('$.scenario_results', $scenarioId),
            'recursive' => true,
        ]];

        foreach (self::sectionFieldsForScenario($scenarioId) as $sectionField) {
            $section = self::arrayValue($result, $sectionField);
            if (! is_array($section)) {
                continue;
            }

            foreach (self::scenarioSectionContainers($section, $scenarioId) as $container) {
                $containers[] = [
                    'value' => $container,
                    'path' => self::pathFor(self::pathFor('$', $sectionField), $scenarioId),
                    'recursive' => true,
                ];
            }
        }

        return $containers;
    }

    /**
     * @return list<string>
     */
    private static function sectionFieldsForScenario(string $scenarioId): array
    {
        $fields = [];
        foreach (self::EVIDENCE_SECTION_SCENARIOS as $section => $scenarios) {
            if (in_array($scenarioId, $scenarios, true)) {
                $fields[] = $section;
            }
        }

        return $fields;
    }

    /**
     * @param  array<mixed>  $section
     * @return array<int, array<mixed>>
     */
    private static function scenarioSectionContainers(array $section, string $scenarioId): array
    {
        $containers = [];
        $keyedValue = self::arrayValue($section, $scenarioId);
        if ($keyedValue !== null) {
            $containers[] = $keyedValue;
        }

        foreach ($section as $value) {
            if (! is_array($value)) {
                continue;
            }

            $valueScenarioId = self::stringValue(
                $value['scenario_id'] ?? $value['scenario'] ?? $value['id'] ?? null,
            );
            if ($valueScenarioId === $scenarioId) {
                $containers[] = $value;
            }
        }

        return $containers;
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $path
     */
    private static function pathValue(array $value, array $path): mixed
    {
        $current = $value;
        foreach ($path as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param  array<mixed>  $value
     */
    private static function recursiveKeyValue(array $value, string $key): mixed
    {
        if (array_key_exists($key, $value)) {
            return $value[$key];
        }

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $found = self::recursiveKeyValue($item, $key);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private static function evidencePresent(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $scenarioResult
     */
    private static function arrayEvidenceValue(
        array $result,
        array $scenarioResult,
        string $scenarioId,
        string ...$fields,
    ): ?array {
        foreach ($fields as $field) {
            $value = self::evidenceValue($result, $scenarioResult, $scenarioId, $field);
            if (is_array($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $installEvidence
     * @return array<string, mixed>|null
     */
    private static function artifactInstallEvidenceEntry(array $installEvidence, string $artifact): ?array
    {
        $artifacts = self::arrayValue($installEvidence, 'artifacts');
        if ($artifacts !== null) {
            foreach ($artifacts as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $entryArtifact = self::stringValue(
                    $entry['artifact'] ?? $entry['name'] ?? $entry['id'] ?? $entry['package'] ?? null,
                );
                if ($entryArtifact === $artifact) {
                    return $entry;
                }
            }
        }

        $direct = self::arrayValue($installEvidence, $artifact);

        return $direct === null ? null : $direct;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function firstString(array $value, string ...$fields): string
    {
        foreach ($fields as $field) {
            $candidate = self::stringValue($value[$field] ?? null);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function explicitFalseField(array $value, string ...$fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $value) && $value[$field] === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function truthyField(array $value, string ...$fields): bool
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $value)) {
                continue;
            }

            $fieldValue = $value[$field];
            if ($fieldValue === true) {
                return true;
            }

            if (is_string($fieldValue) && in_array(strtolower(trim($fieldValue)), ['1', 'true', 'yes'], true)) {
                return true;
            }
        }

        return false;
    }

    private static function pathFor(string $base, int|string $field): string
    {
        if (is_int($field)) {
            return $base.'['.$field.']';
        }

        if ($field === '') {
            return $base;
        }

        return $base.'.'.$field;
    }

    /**
     * @param  array<string, string>  $scenarioStatuses
     * @param  array<string, mixed>  $contract
     */
    private static function isSmokeSubset(array $scenarioStatuses, array $contract): bool
    {
        $requiredScenarios = self::stringList($contract['required_scenarios'] ?? []);
        if (count($scenarioStatuses) >= count($requiredScenarios)) {
            return false;
        }

        $coveredScenarios = array_keys(array_filter(
            $scenarioStatuses,
            static fn (string $status): bool => $status === 'pass',
        ));

        return $coveredScenarios === ['python_worker_cli_and_sdk_baseline']
            || $coveredScenarios === ['published_artifact_install_only', 'python_worker_cli_and_sdk_baseline'];
    }

    private static function sameRuntime(string $reported, string $required): bool
    {
        $reported = strtolower(trim($reported));
        $aliases = [
            'sdk-php' => ['sdk-php', 'sdk_php', 'php', 'php_worker'],
            'sdk-python' => ['sdk-python', 'sdk_python', 'python', 'python_worker'],
            'sdk-rust' => ['sdk-rust', 'sdk_rust', 'rust', 'rust_worker'],
        ];

        return in_array($reported, $aliases[$required] ?? [$required], true);
    }

    /**
     * @return array<int, string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $item): string => self::stringValue($item),
                $value,
            ),
            static fn (string $item): bool => $item !== '',
        ));
    }

    private static function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function integerValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    /**
     * @return list<int>|null
     */
    private static function integerList(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $integers = [];
        foreach ($value as $item) {
            if (! is_int($item)) {
                return null;
            }

            $integers[] = $item;
        }

        return $integers;
    }

    private static function timestampMicroseconds(mixed $value): ?int
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            $timestamp = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }

        return ((int) $timestamp->format('U') * 1_000_000) + (int) $timestamp->format('u');
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $fields
     */
    private static function hasScalarField(array $value, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $value) && self::stringValue($value[$field]) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $fields
     */
    private static function hasArrayField(array $value, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $value) && is_array($value[$field])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>|null
     */
    private static function arrayValue(array $value, string $key): ?array
    {
        return isset($value[$key]) && is_array($value[$key]) ? $value[$key] : null;
    }

    private static function camelize(string $field): string
    {
        return preg_replace_callback(
            '/_([a-z])/',
            static fn (array $matches): string => strtoupper($matches[1]),
            $field,
        ) ?? $field;
    }
}
