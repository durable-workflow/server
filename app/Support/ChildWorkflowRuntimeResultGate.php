<?php

namespace App\Support;

/**
 * Evaluates child-workflow conformance results against the public scenario
 * matrix exposed by ChildWorkflowRuntimeContract.
 */
final class ChildWorkflowRuntimeResultGate
{
    public const SCHEMA = 'durable-workflow.v2.child-workflow-runtime.result-gate';

    public const VERSION = 3;

    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'evaluates_result_schema' => ChildWorkflowRuntimeContract::RESULT_SCHEMA,
            'scenario_statuses_source' => 'child_workflow_runtime_contract.scenario_statuses',
            'required_scenarios_source' => 'child_workflow_runtime_contract.required_scenarios',
            'required_matrix_source' => 'child_workflow_runtime_contract.required_matrix',
            'artifact_versions_fields' => [
                'artifact_versions',
                'artifactVersions',
                'published_artifact_versions',
                'publishedArtifactVersions',
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
            'declared_outcomes_source' => 'child_workflow_runtime_contract.coverage_gate.*_outcome',
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
                'same_language_and_cross_language_parent_child_cells_are_reported',
                'failure_cancellation_replay_fan_out_and_namespace_sections_are_reported',
                'each_pass_scenario_has_observed_outputs',
                'each_pass_scenario_has_scenario_specific_evidence',
                'published_artifact_install_evidence_reported',
                'each_non_pass_scenario_has_linked_findings',
                'run_timestamps_outcome_and_finding_links_are_recorded',
                'overall_outcome_matches_gate_status',
                'published_artifact_versions_are_recorded_and_pinned',
                'no_local_product_source_artifacts_are_reported',
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
        $contract ??= ChildWorkflowRuntimeContract::manifest();

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

        array_push($failures, ...self::runRecordFailures($result, $contract));
        array_push($failures, ...self::declaredOutcomeFailures($result, $contract));
        array_push($failures, ...self::artifactVersionFailures($result, $contract));
        array_push($failures, ...self::sourcePolicyFailures($result, $contract));
        array_push($failures, ...self::matrixFailures($result, $contract));
        array_push($failures, ...self::requiredSectionFailures($result, $scenarioResults));
        array_push($failures, ...self::scenarioSpecificEvidenceFailures($result, $contract, $scenarioResults));

        $smokeSubsetDetected = self::isSmokeSubset($scenarioStatuses, $contract);
        if ($smokeSubsetDetected) {
            $failures[] = [
                'code' => 'smoke_subset_cannot_pass',
                'reason' => 'Python parent/child smoke coverage is not a complete child workflow conformance result.',
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
     * @param array<string, mixed> $scenarioResult
     */
    private static function hasObservedOutputs(array $scenarioResult): bool
    {
        foreach ([
            'observed_outputs',
            'observedOutputs',
            'runtime_matrix',
            'runtimeMatrix',
            'parent_history',
            'parentHistory',
            'child_history',
            'childHistory',
            'cancellation_evidence',
            'cancellationEvidence',
            'replay_report',
            'replayReport',
            'fan_out_timestamps',
            'fanOutTimestamps',
            'namespace_report',
            'namespaceReport',
        ] as $field) {
            $value = self::arrayValue($scenarioResult, $field);
            if ($value !== null && $value !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $result
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
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return array<int, array<string, mixed>>
     */
    private static function runRecordFailures(array $result, array $contract): array
    {
        $requiredFields = self::stringList($contract['artifact_policy']['required_run_record_fields'] ?? []);
        if ($requiredFields === []) {
            $requiredFields = [
                'artifact_versions',
                'started_at',
                'finished_at',
                'generated_at',
                'outcome',
                'scenario_results',
                'findings',
                'finding_links',
            ];
        }

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
     * @param array<string, mixed> $result
     */
    private static function hasRunRecordField(array $result, string $field): bool
    {
        return match ($field) {
            'artifact_versions' => self::artifactVersions($result) !== [],
            'started_at' => self::hasScalarField($result, ['started_at', 'startedAt']),
            'finished_at' => self::hasScalarField($result, ['finished_at', 'finishedAt']),
            'generated_at' => self::hasScalarField($result, ['generated_at', 'generatedAt']),
            'outcome' => self::hasScalarField($result, ['outcome', 'status', 'verdict']),
            'scenario_results' => self::hasArrayField($result, ['scenario_results', 'scenarioResults']),
            'findings' => self::hasArrayField($result, ['findings']),
            'finding_links' => self::hasArrayField($result, ['finding_links', 'findingLinks']),
            default => self::hasScalarField($result, [$field]) || self::hasArrayField($result, [$field]),
        };
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
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
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return array<int, array<string, mixed>>
     */
    private static function declaredOutcomeStatusFailures(array $result, array $contract, string $evaluatedStatus): array
    {
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
     * @param array<string, mixed> $contract
     *
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
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
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
     * @param array<string, mixed> $result
     *
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

    private static function isPlaceholderVersion(string $version): bool
    {
        $normalized = strtolower(trim($version));
        if ($normalized === '') {
            return false;
        }

        if (preg_match('/<[^>]+>|\$\{[^}]+}|{{[^}]+}}/', $normalized) === 1) {
            return true;
        }

        return preg_match('/(^|[^a-z0-9])latest([^a-z0-9]|$)/', $normalized) === 1
            || in_array($normalized, ['latest', 'current', 'head', 'unresolved', 'placeholder'], true);
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return array<int, array<string, mixed>>
     */
    private static function sourcePolicyFailures(array $result, array $contract): array
    {
        $artifactPolicy = self::arrayValue($contract, 'artifact_policy') ?? [];
        $forbiddenSources = self::stringList($artifactPolicy['forbidden_sources'] ?? []);
        $reportedSources = self::arrayValue($result, 'artifact_sources')
            ?? self::arrayValue($result, 'artifactSources')
            ?? [];

        $failures = [];
        foreach ($reportedSources as $artifact => $source) {
            $source = self::stringValue($source);
            if (in_array($source, $forbiddenSources, true)) {
                $failures[] = [
                    'code' => 'forbidden_artifact_source',
                    'artifact' => $artifact,
                    'source' => $source,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
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

        foreach (['same_language_cells', 'cross_language_cells', 'failure_round_trip_cells'] as $cellGroup) {
            foreach ($contractMatrix[$cellGroup] ?? [] as $requiredCell) {
                if (! is_array($requiredCell) || self::matrixHasCell($matrix, $cellGroup, $requiredCell)) {
                    continue;
                }

                $failures[] = [
                    'code' => 'missing_required_matrix_cell',
                    'cell_group' => $cellGroup,
                    'scenario' => $requiredCell['scenario'] ?? null,
                    'parent' => $requiredCell['parent'] ?? null,
                    'child' => $requiredCell['child'] ?? null,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<mixed> $matrix
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
     * @param array<mixed> $matrix
     * @param array<string, mixed> $requiredCell
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
                self::runtimeField($reportedCell, ['parent', 'parent_runtime', 'parentRuntime']),
                self::stringValue($requiredCell['parent'] ?? null),
            )) {
                continue;
            }

            if (! self::sameRuntime(
                self::runtimeField($reportedCell, ['child', 'child_runtime', 'childRuntime']),
                self::stringValue($requiredCell['child'] ?? null),
            )) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $cell
     * @param list<string> $fields
     */
    private static function runtimeField(array $cell, array $fields): string
    {
        foreach ($fields as $field) {
            $value = self::stringValue($cell[$field] ?? null);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return array<int, array<string, mixed>>
     */
    private static function requiredSectionFailures(array $result, array $scenarioResults): array
    {
        $sections = [
            'failure_round_trip' => [
                'child_failure_round_trip_matrix',
            ],
            'cancellation_propagation' => [
                'parent_cancellation_propagates_to_child',
                'direct_child_cancellation_observed_by_parent',
            ],
            'replay_restart' => [
                'worker_restart_replay_preserves_child_outcome',
            ],
            'fan_out' => [
                'concurrent_child_fan_out',
            ],
            'namespace_behavior' => [
                'child_workflow_namespace_contract',
            ],
        ];

        $failures = [];
        foreach ($sections as $section => $scenarios) {
            if (self::sectionValue($result, $section) !== null) {
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
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return array<int, array<string, mixed>>
     */
    private static function scenarioSpecificEvidenceFailures(
        array $result,
        array $contract,
        array $scenarioResults,
    ): array {
        $failures = [];

        if (self::isPassScenario($scenarioResults, 'published_artifact_install_only')) {
            array_push(
                $failures,
                ...self::publishedArtifactInstallEvidenceFailures(
                    self::sectionValue($result, 'published_artifact_install') ?? [],
                    $scenarioResults['published_artifact_install_only'],
                ),
            );
        }

        foreach ([
            'python_parent_python_child_baseline',
            'php_parent_php_child_baseline',
            'php_parent_python_child_cross_language',
            'python_parent_php_child_cross_language',
        ] as $scenarioId) {
            if (self::isPassScenario($scenarioResults, $scenarioId)) {
                array_push($failures, ...self::childResultEvidenceFailures($scenarioId, $scenarioResults[$scenarioId]));
            }
        }

        if (self::isPassScenario($scenarioResults, 'child_failure_round_trip_matrix')) {
            array_push(
                $failures,
                ...self::failureRoundTripEvidenceFailures(
                    self::sectionValue($result, 'failure_round_trip') ?? [],
                    $contract,
                ),
            );
        }

        if (self::isPassScenario($scenarioResults, 'parent_cancellation_propagates_to_child')
            || self::isPassScenario($scenarioResults, 'direct_child_cancellation_observed_by_parent')) {
            array_push(
                $failures,
                ...self::cancellationEvidenceFailures(self::sectionValue($result, 'cancellation_propagation') ?? []),
            );
        }

        if (self::isPassScenario($scenarioResults, 'worker_restart_replay_preserves_child_outcome')) {
            array_push(
                $failures,
                ...self::replayRestartEvidenceFailures(self::sectionValue($result, 'replay_restart') ?? []),
            );
        }

        if (self::isPassScenario($scenarioResults, 'concurrent_child_fan_out')) {
            array_push(
                $failures,
                ...self::fanOutEvidenceFailures(
                    self::sectionValue($result, 'fan_out') ?? [],
                    $contract,
                ),
            );
        }

        if (self::isPassScenario($scenarioResults, 'child_workflow_namespace_contract')) {
            array_push(
                $failures,
                ...self::namespaceEvidenceFailures(self::sectionValue($result, 'namespace_behavior') ?? []),
            );
        }

        return $failures;
    }

    /**
     * @param array<mixed> $section
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function publishedArtifactInstallEvidenceFailures(array $section, array $scenarioResult): array
    {
        $outputs = self::arrayValue($scenarioResult, 'observed_outputs')
            ?? self::arrayValue($scenarioResult, 'observedOutputs')
            ?? [];

        $failures = [];
        foreach ([
            'server_image' => ['server_image', 'serverImage'],
            'cli_release' => ['cli_release', 'cliRelease'],
            'workflow_php_package' => ['workflow_php_package', 'workflowPhpPackage', 'workflow_package'],
            'sdk_python_package' => ['sdk_python_package', 'sdkPythonPackage', 'python_package'],
            'waterline_artifact' => ['waterline_artifact', 'waterlineArtifact'],
        ] as $field => $aliases) {
            if (self::hasNonEmptyField($section, $aliases)
                || self::hasNonEmptyField($scenarioResult, $aliases)
                || self::hasNonEmptyField($outputs, $aliases)) {
                continue;
            }

            $failures[] = [
                'code' => 'missing_published_artifact_install_field',
                'scenario_id' => 'published_artifact_install_only',
                'field' => $field,
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, array<string, mixed>> $scenarioResults
     */
    private static function isPassScenario(array $scenarioResults, string $scenarioId): bool
    {
        return isset($scenarioResults[$scenarioId])
            && self::stringValue($scenarioResults[$scenarioId]['status'] ?? null) === 'pass';
    }

    /**
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function childResultEvidenceFailures(string $scenarioId, array $scenarioResult): array
    {
        $outputs = self::arrayValue($scenarioResult, 'observed_outputs')
            ?? self::arrayValue($scenarioResult, 'observedOutputs')
            ?? [];

        $failures = [];
        foreach ([
            'parent_workflow_id' => ['parent_workflow_id', 'parentWorkflowId'],
            'child_workflow_id' => ['child_workflow_id', 'childWorkflowId'],
            'parent_final_result' => ['parent_final_result', 'parentFinalResult', 'parent_result', 'parentResult'],
            'child_history_excerpt' => ['child_history_excerpt', 'childHistoryExcerpt'],
        ] as $field => $aliases) {
            if (! self::hasNonEmptyField($scenarioResult, $aliases) && ! self::hasNonEmptyField($outputs, $aliases)) {
                $failures[] = [
                    'code' => 'missing_child_result_evidence',
                    'scenario_id' => $scenarioId,
                    'field' => $field,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<mixed> $section
     * @param array<string, mixed> $contract
     *
     * @return array<int, array<string, mixed>>
     */
    private static function failureRoundTripEvidenceFailures(array $section, array $contract): array
    {
        $cells = self::cellList($section, [
            'cells',
            'failure_round_trip_cells',
            'failureRoundTripCells',
            'matrix',
        ]);
        $requiredCells = self::arrayValue($contract['required_matrix'] ?? [], 'failure_round_trip_cells') ?? [];
        $failures = [];

        foreach ($requiredCells as $requiredCell) {
            if (! is_array($requiredCell)) {
                continue;
            }

            $reportedCell = self::findCell($cells, $requiredCell);
            if ($reportedCell === null) {
                $failures[] = [
                    'code' => 'missing_failure_round_trip_evidence_cell',
                    'parent' => $requiredCell['parent'] ?? null,
                    'child' => $requiredCell['child'] ?? null,
                ];
                continue;
            }

            foreach ([
                'exception_class' => ['exception_class', 'exceptionClass', 'error_class', 'errorClass'],
                'message' => ['message', 'error_message', 'errorMessage'],
                'failure_kind' => ['failure_kind', 'failureKind', 'kind'],
            ] as $field => $aliases) {
                if (! self::hasNonEmptyField($reportedCell, $aliases)) {
                    $failures[] = [
                        'code' => 'missing_failure_round_trip_field',
                        'parent' => $requiredCell['parent'] ?? null,
                        'child' => $requiredCell['child'] ?? null,
                        'field' => $field,
                    ];
                }
            }
        }

        return $failures;
    }

    /**
     * @param array<mixed> $section
     *
     * @return array<int, array<string, mixed>>
     */
    private static function cancellationEvidenceFailures(array $section): array
    {
        $parentToChild = self::arrayValue($section, 'parent_to_child')
            ?? self::arrayValue($section, 'parentToChild')
            ?? [];
        $directChild = self::arrayValue($section, 'direct_child')
            ?? self::arrayValue($section, 'directChild')
            ?? [];
        $failures = [];

        foreach ([
            'cancel_issued_at' => ['cancel_issued_at', 'cancelIssuedAt'],
            'child_cancelled_at' => ['child_cancelled_at', 'childCancelledAt'],
        ] as $field => $aliases) {
            if (! self::hasNonEmptyField($parentToChild, $aliases)) {
                $failures[] = [
                    'code' => 'missing_parent_child_cancellation_field',
                    'field' => $field,
                ];
            }
        }

        if (! self::hasTruthyField(
            $parentToChild,
            ['worker_observed_typed_cancellation', 'workerObservedTypedCancellation'],
        )) {
            $failures[] = [
                'code' => 'missing_parent_child_cancellation_field',
                'field' => 'worker_observed_typed_cancellation',
            ];
        }

        foreach ([
            'child_cancel_issued_at' => ['child_cancel_issued_at', 'childCancelIssuedAt'],
            'parent_observed_at' => ['parent_observed_at', 'parentObservedAt'],
            'parent_failure_kind' => ['parent_failure_kind', 'parentFailureKind'],
        ] as $field => $aliases) {
            if (! self::hasNonEmptyField($directChild, $aliases)) {
                $failures[] = [
                    'code' => 'missing_direct_child_cancellation_field',
                    'field' => $field,
                ];
            }
        }

        $failureKind = strtolower(self::firstStringField($directChild, ['parent_failure_kind', 'parentFailureKind']));
        if ($failureKind !== '' && str_contains($failureKind, 'timeout')) {
            $failures[] = [
                'code' => 'direct_child_cancellation_reported_as_timeout',
                'parent_failure_kind' => $failureKind,
            ];
        }

        return $failures;
    }

    /**
     * @param array<mixed> $section
     *
     * @return array<int, array<string, mixed>>
     */
    private static function replayRestartEvidenceFailures(array $section): array
    {
        $failures = [];
        foreach ([
            'parent_worker_stopped_at' => ['parent_worker_stopped_at', 'parentWorkerStoppedAt'],
            'parent_worker_restarted_at' => ['parent_worker_restarted_at', 'parentWorkerRestartedAt'],
        ] as $field => $aliases) {
            if (! self::hasNonEmptyField($section, $aliases)) {
                $failures[] = [
                    'code' => 'missing_replay_restart_field',
                    'field' => $field,
                ];
            }
        }

        $original = self::arrayField($section, ['original_decision_sequence', 'originalDecisionSequence']);
        $replayed = self::arrayField($section, ['replayed_decision_sequence', 'replayedDecisionSequence']);
        if ($original === null || $original === []) {
            $failures[] = [
                'code' => 'missing_replay_restart_field',
                'field' => 'original_decision_sequence',
            ];
        }
        if ($replayed === null || $replayed === []) {
            $failures[] = [
                'code' => 'missing_replay_restart_field',
                'field' => 'replayed_decision_sequence',
            ];
        }
        if ($original !== null && $replayed !== null && $original !== $replayed) {
            $failures[] = [
                'code' => 'replay_decision_sequence_mismatch',
            ];
        }
        if (self::hasTruthyField($section, ['duplicate_child_scheduled', 'duplicateChildScheduled'])) {
            $failures[] = [
                'code' => 'replay_restart_scheduled_duplicate_child',
            ];
        }

        return $failures;
    }

    /**
     * @param array<mixed> $section
     * @param array<string, mixed> $contract
     *
     * @return array<int, array<string, mixed>>
     */
    private static function fanOutEvidenceFailures(array $section, array $contract): array
    {
        $requiredCount = (int) (
            $contract['scenario_requirements']['concurrent_child_fan_out']['required_child_count'] ?? 5
        );
        $childCount = self::intField($section, ['child_count', 'childCount']);
        $started = self::arrayField($section, ['child_started_at_values', 'childStartedAtValues']) ?? [];
        $completed = self::arrayField($section, ['child_completed_at_values', 'childCompletedAtValues']) ?? [];
        $failures = [];

        if ($childCount < $requiredCount) {
            $failures[] = [
                'code' => 'fan_out_child_count_below_required',
                'required' => $requiredCount,
                'actual' => $childCount,
            ];
        }
        foreach ([
            'child_started_at_values' => $started,
            'child_completed_at_values' => $completed,
        ] as $field => $values) {
            if (count($values) < $requiredCount) {
                $failures[] = [
                    'code' => 'fan_out_timestamp_count_below_required',
                    'field' => $field,
                    'required' => $requiredCount,
                    'actual' => count($values),
                ];
            }
        }
        if (! self::hasNonEmptyField($section, ['aggregate_result', 'aggregateResult'])) {
            $failures[] = [
                'code' => 'missing_fan_out_field',
                'field' => 'aggregate_result',
            ];
        }
        if (! self::hasTruthyField(
            $section,
            ['overlap_observed', 'overlapObserved', 'concurrency_observed', 'concurrencyObserved'],
        )) {
            $failures[] = [
                'code' => 'missing_fan_out_concurrency_evidence',
            ];
        }

        return $failures;
    }

    /**
     * @param array<mixed> $section
     *
     * @return array<int, array<string, mixed>>
     */
    private static function namespaceEvidenceFailures(array $section): array
    {
        $failures = [];
        foreach ([
            'parent_namespace' => ['parent_namespace', 'parentNamespace'],
            'child_namespace' => ['child_namespace', 'childNamespace'],
            'cross_namespace_verdict' => ['cross_namespace_verdict', 'crossNamespaceVerdict'],
        ] as $field => $aliases) {
            if (! self::hasNonEmptyField($section, $aliases)) {
                $failures[] = [
                    'code' => 'missing_namespace_behavior_field',
                    'field' => $field,
                ];
            }
        }

        $lineageLinks = self::arrayField($section, ['lineage_links', 'lineageLinks']);
        if ($lineageLinks === null || $lineageLinks === []) {
            $failures[] = [
                'code' => 'missing_namespace_behavior_field',
                'field' => 'lineage_links',
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function sectionValue(array $result, string $section): ?array
    {
        $camel = preg_replace_callback(
            '/_([a-z])/',
            static fn (array $matches): string => strtoupper($matches[1]),
            $section,
        );

        return self::arrayValue($result, $section)
            ?? ($camel !== null ? self::arrayValue($result, $camel) : null);
    }

    /**
     * @param array<string, string> $scenarioStatuses
     * @param array<string, mixed> $contract
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

        return $coveredScenarios === ['python_parent_python_child_baseline']
            || $coveredScenarios === ['published_artifact_install_only', 'python_parent_python_child_baseline'];
    }

    private static function sameRuntime(string $reported, string $required): bool
    {
        $aliases = [
            'workflow-php' => ['workflow-php', 'workflow_php', 'php', 'php_worker'],
            'sdk-python' => ['sdk-python', 'sdk_python', 'python', 'python_worker'],
        ];

        return in_array($reported, $aliases[$required] ?? [$required], true);
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $fields
     *
     * @return array<int, array<string, mixed>>
     */
    private static function cellList(array $value, array $fields): array
    {
        $cells = [];
        foreach ($fields as $field) {
            $fieldCells = self::arrayValue($value, $field);
            if ($fieldCells === null) {
                continue;
            }

            foreach ($fieldCells as $cell) {
                if (is_array($cell)) {
                    $cells[] = $cell;
                }
            }
        }

        return $cells;
    }

    /**
     * @param array<int, array<string, mixed>> $cells
     * @param array<string, mixed> $requiredCell
     *
     * @return array<string, mixed>|null
     */
    private static function findCell(array $cells, array $requiredCell): ?array
    {
        foreach ($cells as $cell) {
            if (self::stringValue($cell['scenario'] ?? $cell['scenario_id'] ?? null)
                !== self::stringValue($requiredCell['scenario'] ?? null)) {
                continue;
            }

            if (! self::sameRuntime(
                self::runtimeField($cell, ['parent', 'parent_runtime', 'parentRuntime']),
                self::stringValue($requiredCell['parent'] ?? null),
            )) {
                continue;
            }

            if (! self::sameRuntime(
                self::runtimeField($cell, ['child', 'child_runtime', 'childRuntime']),
                self::stringValue($requiredCell['child'] ?? null),
            )) {
                continue;
            }

            return $cell;
        }

        return null;
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $fields
     */
    private static function hasNonEmptyField(array $value, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $value) && self::nonEmptyValue($value[$field])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $fields
     */
    private static function hasTruthyField(array $value, array $fields): bool
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $value)) {
                continue;
            }

            $fieldValue = $value[$field];
            if ($fieldValue === true || $fieldValue === 1 || $fieldValue === '1' || $fieldValue === 'true') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $fields
     */
    private static function firstStringField(array $value, array $fields): string
    {
        foreach ($fields as $field) {
            $string = self::stringValue($value[$field] ?? null);
            if ($string !== '') {
                return $string;
            }
        }

        return '';
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $fields
     *
     * @return array<mixed>|null
     */
    private static function arrayField(array $value, array $fields): ?array
    {
        foreach ($fields as $field) {
            $fieldValue = self::arrayValue($value, $field);
            if ($fieldValue !== null) {
                return $fieldValue;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $fields
     */
    private static function intField(array $value, array $fields): int
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $value) || ! is_numeric($value[$field])) {
                continue;
            }

            return (int) $value[$field];
        }

        return 0;
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $fields
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
     * @param array<mixed> $value
     * @param list<string> $fields
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

    private static function nonEmptyValue(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }

        if (is_bool($value)) {
            return true;
        }

        return self::stringValue($value) !== '';
    }

    /**
     * @param mixed $value
     *
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

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>|null
     */
    private static function arrayValue(array $value, string $key): ?array
    {
        return isset($value[$key]) && is_array($value[$key]) ? $value[$key] : null;
    }
}
