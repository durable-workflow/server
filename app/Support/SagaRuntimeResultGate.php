<?php

namespace App\Support;

/**
 * Evaluates saga conformance results against the full compensation contract
 * exposed by SagaRuntimeContract.
 */
final class SagaRuntimeResultGate
{
    public const SCHEMA = 'durable-workflow.v2.saga-runtime.result-gate';

    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'evaluates_result_schema' => SagaRuntimeContract::RESULT_SCHEMA,
            'scenario_statuses_source' => 'saga_runtime_contract.scenario_statuses',
            'required_scenarios_source' => 'saga_runtime_contract.required_scenarios',
            'required_matrix_source' => 'saga_runtime_contract.required_matrix',
            'scenario_required_fields_source' => 'saga_runtime_contract.scenario_requirements.*.required_fields',
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
            'declared_outcomes_source' => 'saga_runtime_contract.coverage_gate.*_outcome plus pass/fail/error aliases and scenario non-pass statuses',
            'non_pass_statuses' => [
                'fail',
                'unsupported',
                'not_covered',
                'runner_blocked',
            ],
            'artifact_version_policy' => [
                'requires_recorded_and_pinned_versions' => true,
                'rejects_placeholder_versions' => true,
                'requires_complete_downloadable_release_assets' => true,
                'placeholder_version_examples' => [
                    'latest',
                    'current',
                    'head',
                    'unresolved',
                    'placeholder',
                    '<latest>',
                    '2.0.0-alpha.<latest>',
                    '${VERSION}',
                    '{{ version }}',
                ],
            ],
            'pass_requires' => [
                'every_required_scenario_has_one_result',
                'every_result_uses_a_published_status',
                'required_php_and_python_workflow_runtimes_are_reported',
                'required_php_and_python_activity_runtimes_are_reported',
                'cross_language_compensation_cells_are_reported',
                'reverse_compensation_retry_failure_restart_visibility_and_typed_error_sections_are_reported',
                'waterline_operator_visibility_evidence_is_reported',
                'each_pass_scenario_has_scenario_specific_evidence',
                'each_non_pass_scenario_has_linked_findings',
                'run_timestamps_outcome_and_findings_are_recorded',
                'runner_exit_status_zero_for_passing_record',
                'overall_outcome_matches_gate_status',
                'published_artifact_versions_are_recorded_and_pinned',
                'published_artifact_install_sources_are_complete',
                'local_product_source_checkouts_used_is_explicitly_false',
                'no_local_product_source_artifacts_are_reported',
                'runner_blocked_false_for_product_evidence',
            ],
            'smoke_subset_outcome' => 'non_passing',
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
        $contract ??= SagaRuntimeContract::manifest();

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
                foreach (self::requiredScenarioFields($contract, $scenarioId) as $field) {
                    if (! self::hasScenarioField($scenarioResult, $field)) {
                        $failures[] = [
                            'code' => 'missing_scenario_required_field',
                            'scenario_id' => $scenarioId,
                            'field' => $field,
                        ];
                    }
                }
            } else {
                $nonPassScenarios[] = $scenarioId;
                array_push(
                    $failures,
                    ...self::nonPassFindingFailures($scenarioResult, $result, $contract, $scenarioId, $status),
                );
            }
        }

        $reportedScenarioIds = array_keys($scenarioResults);
        $unknownScenarios = array_values(array_diff($reportedScenarioIds, $requiredScenarios));
        foreach ($unknownScenarios as $scenarioId) {
            $status = self::stringValue($scenarioResults[$scenarioId]['status'] ?? null);
            if (! in_array($status, $allowedStatuses, true)) {
                $failures[] = [
                    'code' => 'invalid_extra_scenario_status',
                    'scenario_id' => $scenarioId,
                    'status' => $status,
                    'allowed_statuses' => $allowedStatuses,
                ];
            }
        }

        array_push($failures, ...self::runRecordFailures($result, $contract));
        array_push($failures, ...self::declaredOutcomeFailures($result, $contract));
        array_push($failures, ...self::artifactVersionFailures($result, $contract));
        array_push($failures, ...self::sourcePolicyFailures($result, $contract, $scenarioResults));
        array_push($failures, ...self::matrixFailures($result));
        array_push($failures, ...self::requiredSectionFailures($result));
        array_push($failures, ...self::runnerExitStatusFailures($result));

        $smokeSubsetDetected = self::isSmokeSubset($scenarioStatuses, $contract);
        if ($smokeSubsetDetected) {
            $failures[] = [
                'code' => 'smoke_subset_cannot_pass',
                'reason' => 'A subset of saga scenarios is not a complete compensation conformance result.',
            ];
        }

        $evidencePasses = $failures === []
            && $missingScenarios === []
            && $nonPassScenarios === []
            && count($scenarioStatuses) >= count($requiredScenarios);
        $evaluatedStatus = $evidencePasses ? 'pass' : 'non_passing';

        array_push($failures, ...self::declaredOutcomeStatusFailures($result, $contract, $evaluatedStatus));

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
     * @param array<string, mixed> $contract
     *
     * @return list<string>
     */
    private static function requiredScenarioFields(array $contract, string $scenarioId): array
    {
        $requirements = $contract['scenario_requirements'][$scenarioId] ?? [];

        return self::stringList(is_array($requirements) ? ($requirements['required_fields'] ?? []) : []);
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

        return $failures;
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
            'findings' => self::hasFindingList($result),
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
        $installChannels = self::arrayField($contract['artifact_policy'] ?? [], ['install_channels']) ?? [];
        $requiredArtifacts = array_keys($installChannels);
        if ($requiredArtifacts === []) {
            $requiredArtifacts = ['server', 'cli', 'workflow-php', 'sdk-python', 'waterline'];
        }

        foreach ([
            'artifact_versions' => ['artifact_versions', 'artifactVersions'],
            'published_artifact_versions' => ['published_artifact_versions', 'publishedArtifactVersions'],
            'resolved_artifact_versions' => ['resolved_artifact_versions', 'resolvedArtifactVersions'],
        ] as $field => $aliases) {
            $versions = self::arrayField($result, $aliases);
            if ($versions === null) {
                continue;
            }

            foreach ($requiredArtifacts as $artifact) {
                $artifact = (string) $artifact;
                $version = self::artifactVersionValue($versions, $artifact);
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
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return list<array<string, mixed>>
     */
    private static function sourcePolicyFailures(array $result, array $contract, array $scenarioResults): array
    {
        $artifactPolicy = self::arrayField($contract, ['artifact_policy', 'artifactPolicy']) ?? [];
        $forbiddenSources = self::stringList(
            $artifactPolicy['forbidden_sources']
                ?? $artifactPolicy['forbiddenSources']
                ?? [
                    'local_product_source_checkout',
                    'workspace_repo_as_artifact_under_test',
                    'release_tag_without_required_assets',
                    'rolling_server_image_tag',
                ],
        );
        $install = $scenarioResults['published_artifact_install_only'] ?? [];
        $installOutputs = self::arrayField($install, ['observed_outputs', 'observedOutputs']) ?? [];

        $failures = [];
        foreach (self::localProductSourceCheckoutValues($result, $install, $installOutputs) as $flag) {
            if (($flag['value'] ?? null) !== true) {
                continue;
            }

            $failures[] = [
                'code' => 'local_product_source_checkout_used',
                'scenario_id' => 'published_artifact_install_only',
                'field' => $flag['field'],
            ];
        }

        $reportedSourceSets = [];
        foreach ([
            'artifact_sources',
            'artifactSources',
            'install_sources',
            'installSources',
        ] as $field) {
            $sources = self::arrayField($result, [$field]);
            if ($sources === null) {
                continue;
            }

            $reportedSourceSets[] = [
                'sources' => $sources,
                'field' => $field,
                'scenario_id' => null,
            ];
        }

        foreach ($scenarioResults as $scenarioId => $scenarioResult) {
            foreach ([
                $scenarioResult,
                self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']) ?? [],
            ] as $sourceContainer) {
                foreach ([
                    'artifact_sources',
                    'artifactSources',
                    'install_sources',
                    'installSources',
                ] as $field) {
                    $sources = self::arrayField($sourceContainer, [$field]);
                    if ($sources === null) {
                        continue;
                    }

                    $reportedSourceSets[] = [
                        'sources' => $sources,
                        'field' => $field,
                        'scenario_id' => $scenarioId,
                    ];
                }
            }
        }

        foreach ($reportedSourceSets as $sourceSet) {
            foreach ($sourceSet['sources'] as $artifact => $source) {
                $source = self::stringValue($source);
                if (! self::isForbiddenArtifactSource($source, $forbiddenSources)) {
                    continue;
                }

                $failure = [
                    'code' => 'forbidden_artifact_source',
                    'artifact' => $artifact,
                    'source' => $source,
                    'field' => $sourceSet['field'],
                ];
                if ($sourceSet['scenario_id'] !== null) {
                    $failure['scenario_id'] = $sourceSet['scenario_id'];
                }

                $failures[] = $failure;
            }
        }

        if (self::stringValue($install['status'] ?? null) === 'pass') {
            array_push(
                $failures,
                ...self::publishedArtifactEvidenceFailures($result, $contract, $install),
            );
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $scenarioResult
     *
     * @return list<array<string, mixed>>
     */
    private static function publishedArtifactEvidenceFailures(
        array $result,
        array $contract,
        array $scenarioResult,
    ): array {
        $outputs = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']) ?? [];
        $sources = [];

        foreach ([
            $result,
            $scenarioResult,
            $outputs,
        ] as $container) {
            foreach ([
                'artifact_sources',
                'artifactSources',
                'install_sources',
                'installSources',
            ] as $field) {
                $reportedSources = self::arrayField($container, [$field]);
                if ($reportedSources === null) {
                    continue;
                }

                $sources = array_replace($sources, $reportedSources);
            }
        }

        $installChannels = self::arrayField($contract['artifact_policy'] ?? [], ['install_channels']) ?? [];
        $requiredArtifacts = array_keys($installChannels);
        if ($requiredArtifacts === []) {
            $requiredArtifacts = ['server', 'cli', 'workflow-php', 'sdk-python', 'waterline'];
        }

        $failures = [];
        foreach ($requiredArtifacts as $artifact) {
            $artifact = (string) $artifact;
            if (self::artifactVersionValue($sources, $artifact) !== '') {
                continue;
            }

            $failures[] = [
                'code' => 'missing_published_artifact_install_source',
                'scenario_id' => 'published_artifact_install_only',
                'artifact' => $artifact,
            ];
        }

        if (! self::hasExplicitFalseLocalProductSourceFlag($result, $scenarioResult, $outputs)) {
            $failures[] = [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'scenario_id' => 'published_artifact_install_only',
                'field' => 'local_product_source_checkouts_used',
                'value' => self::firstLocalProductSourceFlagValue($result, $scenarioResult, $outputs),
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> ...$containers
     *
     * @return list<array{field: string, value: mixed}>
     */
    private static function localProductSourceCheckoutValues(array ...$containers): array
    {
        $values = [];
        foreach ($containers as $container) {
            foreach ([
                'local_product_source_checkouts_used',
                'localProductSourceCheckoutsUsed',
            ] as $field) {
                if (! array_key_exists($field, $container)) {
                    continue;
                }

                $values[] = [
                    'field' => $field,
                    'value' => $container[$field],
                ];
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> ...$containers
     */
    private static function hasExplicitFalseLocalProductSourceFlag(array ...$containers): bool
    {
        foreach (self::localProductSourceCheckoutValues(...$containers) as $flag) {
            if (($flag['value'] ?? null) === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> ...$containers
     */
    private static function firstLocalProductSourceFlagValue(array ...$containers): mixed
    {
        $flags = self::localProductSourceCheckoutValues(...$containers);

        return $flags[0]['value'] ?? null;
    }

    /**
     * @param list<string> $forbiddenSources
     */
    private static function isForbiddenArtifactSource(string $source, array $forbiddenSources): bool
    {
        $normalized = strtolower(trim($source));
        if ($normalized === '') {
            return false;
        }

        foreach ($forbiddenSources as $forbiddenSource) {
            $forbiddenSource = strtolower(trim($forbiddenSource));
            if ($forbiddenSource === '') {
                continue;
            }

            if ($normalized === $forbiddenSource || str_contains($normalized, $forbiddenSource)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<array<string, mixed>>
     */
    private static function matrixFailures(array $result): array
    {
        $failures = [];
        $matrix = self::arrayValue($result, 'runtime_matrix') ?? [];

        foreach ([
            'workflow_runtimes' => ['workflow-php', 'sdk-python'],
            'activity_runtimes' => ['workflow-php', 'sdk-python'],
        ] as $field => $requiredValues) {
            $reported = self::stringList(is_array($matrix) ? ($matrix[$field] ?? []) : []);
            foreach ($requiredValues as $requiredValue) {
                if (! in_array($requiredValue, $reported, true)) {
                    $failures[] = [
                        'code' => 'missing_runtime_matrix_value',
                        'field' => $field,
                        'value' => $requiredValue,
                    ];
                }
            }
        }

        $crossLanguageCells = self::stringList(is_array($matrix) ? ($matrix['cross_language_cells'] ?? []) : []);
        foreach (['php_workflow_python_compensation', 'python_workflow_php_compensation'] as $cell) {
            if (! in_array($cell, $crossLanguageCells, true)) {
                $failures[] = [
                    'code' => 'missing_cross_language_cell',
                    'cell' => $cell,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<array<string, mixed>>
     */
    private static function requiredSectionFailures(array $result): array
    {
        $failures = [];
        foreach ([
            'topology',
            'side_store_deltas',
            'history_dumps',
            'worker_restart_observations',
            'operator_visibility_snapshots',
            'cross_language_matrix',
            'typed_error_shapes',
        ] as $field) {
            if (self::isEmptyEvidence(self::fieldValue($result, $field))) {
                $failures[] = [
                    'code' => 'missing_required_section',
                    'field' => $field,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<string, string> $scenarioStatuses
     * @param array<string, mixed> $contract
     */
    private static function isSmokeSubset(array $scenarioStatuses, array $contract): bool
    {
        $requiredScenarios = self::stringList($contract['required_scenarios'] ?? []);

        return $scenarioStatuses !== []
            && count($scenarioStatuses) < count($requiredScenarios)
            && ! array_diff(array_keys($scenarioStatuses), $requiredScenarios);
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return list<array<string, mixed>>
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
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return list<array<string, mixed>>
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
            $conflictingOutcomes = array_intersect_key($declaredOutcomes, $declaredStatuses);
            $failure = [
                'code' => 'conflicting_outcome_tokens',
                'declared_outcomes' => $conflictingOutcomes,
                'declared_statuses' => $declaredStatuses,
            ];
            foreach (['outcome', 'status', 'verdict'] as $field) {
                if (array_key_exists($field, $conflictingOutcomes)) {
                    $failure[$field] = $conflictingOutcomes[$field];
                }
            }

            $failures[] = $failure;
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<array<string, mixed>>
     */
    private static function runnerExitStatusFailures(array $result): array
    {
        $declaresPassingOutcome = self::declaresPassingOutcome($result);

        foreach (['runner_exit_status', 'runnerExitStatus', 'exit_status', 'exitStatus', 'exit_code', 'exitCode'] as $field) {
            if (! array_key_exists($field, $result)) {
                continue;
            }

            $value = $result[$field];
            if (is_int($value)) {
                return $value === 0 ? [] : [[
                    'code' => 'runner_exit_status_nonzero',
                    'field' => $field,
                    'exit_status' => $value,
                ]];
            }

            if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
                $exitStatus = (int) trim($value);

                return $exitStatus === 0 ? [] : [[
                    'code' => 'runner_exit_status_nonzero',
                    'field' => $field,
                    'exit_status' => $exitStatus,
                ]];
            }

            return [[
                'code' => 'invalid_runner_exit_status',
                'field' => $field,
                'value' => $value,
            ]];
        }

        if ($declaresPassingOutcome) {
            return [[
                'code' => 'missing_runner_exit_status',
                'field' => 'runner_exit_status',
            ]];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function declaresPassingOutcome(array $result): bool
    {
        foreach (self::declaredOutcomeTokens($result) as $outcome) {
            if (self::declaredOutcomeStatus($outcome) === 'pass') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return list<array<string, mixed>>
     */
    private static function nonPassFindingFailures(
        array $scenarioResult,
        array $result,
        array $contract,
        string $scenarioId,
        string $status,
    ): array {
        $findings = self::nonPassFindings($scenarioResult, $result, $scenarioId);
        if ($findings === []) {
            return [[
                'code' => 'missing_non_pass_finding',
                'scenario_id' => $scenarioId,
                'status' => $status,
            ]];
        }

        $requiredFields = self::stringList($contract['finding_policy']['required_for_non_pass'] ?? []);
        if ($requiredFields === []) {
            $requiredFields = [
                'scenario_id',
                'owning_surface',
                'artifact_versions',
                'observed_behavior',
                'expected_behavior',
                'next_acceptance_criterion',
            ];
        }

        $failures = [];
        foreach ($findings as $index => $finding) {
            if (! is_array($finding)) {
                $failures[] = [
                    'code' => 'unstructured_non_pass_finding',
                    'scenario_id' => $scenarioId,
                    'status' => $status,
                    'finding_index' => $index,
                    'expected_fields' => $requiredFields,
                ];
                continue;
            }

            $missingFields = [];
            foreach ($requiredFields as $field) {
                if (! self::hasFindingField($finding, $field)) {
                    $missingFields[] = $field;
                }
            }

            $findingScenarioId = self::stringValue(self::findingFieldValue($finding, 'scenario_id'));
            if ($findingScenarioId !== '' && $findingScenarioId !== $scenarioId) {
                $failures[] = [
                    'code' => 'non_pass_finding_scenario_mismatch',
                    'scenario_id' => $scenarioId,
                    'finding_scenario_id' => $findingScenarioId,
                    'finding_index' => $index,
                ];
            }

            if ($missingFields !== []) {
                $failures[] = [
                    'code' => 'missing_non_pass_finding_field',
                    'scenario_id' => $scenarioId,
                    'status' => $status,
                    'finding_index' => $index,
                    'missing_fields' => $missingFields,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function hasFindingList(array $result): bool
    {
        foreach ([
            $result['findings'] ?? null,
            $result['linked_findings'] ?? null,
            $result['linkedFindings'] ?? null,
        ] as $value) {
            if (is_array($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $versions
     */
    private static function artifactVersionValue(array $versions, string $artifact): string
    {
        $aliases = [
            'workflow-php' => ['workflow-php', 'workflow_php', 'workflow'],
            'sdk-python' => ['sdk-python', 'sdk_python', 'python'],
            'waterline' => ['waterline', 'waterline-ui', 'waterline_ui'],
        ];

        foreach ($aliases[$artifact] ?? [$artifact] as $key) {
            $version = self::stringValue($versions[$key] ?? null);
            if (array_key_exists($key, $versions) && $version !== '') {
                return $version;
            }
        }

        return '';
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
     * @return array<string, mixed>
     */
    private static function artifactVersions(array $result): array
    {
        foreach ([
            'artifact_versions',
            'artifactVersions',
            'published_artifact_versions',
            'publishedArtifactVersions',
            'resolved_artifact_versions',
            'resolvedArtifactVersions',
        ] as $field) {
            $value = self::arrayValue($result, $field);
            if ($value !== null) {
                return $value;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, string>
     */
    private static function declaredOutcomeTokens(array $result): array
    {
        $declaredOutcomes = [];
        foreach (['outcome', 'status', 'verdict'] as $field) {
            $value = strtolower(trim(self::stringValue($result[$field] ?? null)));
            if ($value !== '') {
                $declaredOutcomes[$field] = $value;
            }
        }

        return $declaredOutcomes;
    }

    /**
     * @param array<string, mixed> $contract
     *
     * @return list<string>
     */
    private static function declaredOutcomes(array $contract): array
    {
        $outcomes = [
            'pass',
            'passed',
            'success',
            'full',
            'fail',
            'failed',
            'failure',
            'error',
            'non_passing',
        ];

        foreach (self::stringList($contract['scenario_statuses'] ?? []) as $status) {
            $outcomes[] = $status;
        }

        $coverageGate = self::arrayField($contract, ['coverage_gate']) ?? [];
        foreach ($coverageGate as $key => $value) {
            if (! is_string($key) || ! str_ends_with($key, '_outcome')) {
                continue;
            }

            $outcome = strtolower(trim(self::stringValue($value)));
            if ($outcome !== '') {
                $outcomes[] = $outcome;
            }
        }

        return array_values(array_unique($outcomes));
    }

    private static function declaredOutcomeStatus(string $outcome): string
    {
        return in_array($outcome, ['pass', 'passed', 'success', 'full'], true) ? 'pass' : 'non_passing';
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $result
     *
     * @return list<mixed>
     */
    private static function nonPassFindings(array $scenarioResult, array $result, string $scenarioId): array
    {
        $findings = [];
        foreach ([
            $scenarioResult['findings'] ?? null,
            $scenarioResult['linked_findings'] ?? null,
            $scenarioResult['linkedFindings'] ?? null,
        ] as $value) {
            if (is_array($value)) {
                array_push($findings, ...array_values($value));
            }
        }

        foreach ([
            $result['findings'] ?? null,
            $result['linked_findings'] ?? null,
            $result['linkedFindings'] ?? null,
        ] as $value) {
            if (! is_array($value)) {
                continue;
            }

            foreach ($value as $finding) {
                if (! is_array($finding)) {
                    continue;
                }

                if (self::stringValue(self::findingFieldValue($finding, 'scenario_id')) === $scenarioId) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $finding
     */
    private static function hasFindingField(array $finding, string $field): bool
    {
        $value = self::findingFieldValue($finding, $field);

        return ! self::isEmptyEvidence($value);
    }

    /**
     * @param array<string, mixed> $finding
     */
    private static function findingFieldValue(array $finding, string $field): mixed
    {
        $aliases = [
            'scenario_id' => ['scenario_id', 'scenarioId'],
            'owning_surface' => ['owning_surface', 'owningSurface', 'surface'],
            'artifact_versions' => ['artifact_versions', 'artifactVersions'],
            'observed_behavior' => ['observed_behavior', 'observedBehavior'],
            'expected_behavior' => ['expected_behavior', 'expectedBehavior'],
            'next_acceptance_criterion' => ['next_acceptance_criterion', 'nextAcceptanceCriterion'],
        ];

        foreach ($aliases[$field] ?? [$field] as $key) {
            if (array_key_exists($key, $finding)) {
                return $finding[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>|null
     */
    private static function arrayValue(array $value, string $field): ?array
    {
        $candidate = $value[$field] ?? null;

        return is_array($candidate) ? $candidate : null;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $fields
     *
     * @return array<int|string, mixed>|null
     */
    private static function arrayField(array $value, array $fields): ?array
    {
        foreach ($fields as $field) {
            $candidate = $value[$field] ?? null;
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function fieldValue(array $value, string $field): mixed
    {
        if (array_key_exists($field, $value)) {
            return $value[$field];
        }

        $observedOutputs = $value['observed_outputs'] ?? $value['observedOutputs'] ?? null;
        if (is_array($observedOutputs) && array_key_exists($field, $observedOutputs)) {
            return $observedOutputs[$field];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function hasScenarioField(array $value, string $field): bool
    {
        if (array_key_exists($field, $value)) {
            return $value[$field] !== null && $value[$field] !== '';
        }

        $observedOutputs = $value['observed_outputs'] ?? $value['observedOutputs'] ?? null;
        if (is_array($observedOutputs) && array_key_exists($field, $observedOutputs)) {
            return $observedOutputs[$field] !== null && $observedOutputs[$field] !== '';
        }

        return false;
    }

    private static function isEmptyEvidence(mixed $value): bool
    {
        return $value === null || $value === [] || $value === '';
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

    private static function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $fields
     */
    private static function hasScalarField(array $value, array $fields): bool
    {
        foreach ($fields as $field) {
            if (self::stringValue($value[$field] ?? null) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }
}
