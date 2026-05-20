<?php

namespace App\Support;

/**
 * Evaluates a signals/queries conformance result against the public
 * scenario manifest exposed by SignalQueryRuntimeContract.
 */
final class SignalQueryRuntimeResultGate
{
    public const SCHEMA = 'durable-workflow.v2.signal-query-runtime.result-gate';

    public const VERSION = 3;

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
                'each_non_pass_scenario_has_linked_findings',
                'run_timestamps_outcome_and_finding_links_are_recorded',
                'overall_outcome_matches_gate_status',
                'published_artifact_versions_are_recorded',
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

        $sourceFailures = self::sourcePolicyFailures($result, $contract);
        array_push($failures, ...$sourceFailures);

        $matrixFailures = self::matrixFailures($result, $contract);
        array_push($failures, ...$matrixFailures);

        $sectionFailures = self::requiredSectionFailures($result, $scenarioResults);
        array_push($failures, ...$sectionFailures);

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
     * @param array<string, mixed> $result
     *
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
        foreach (['observed_outputs', 'observedOutputs', 'runtime_matrix', 'runtimeMatrix'] as $field) {
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
            'outcome' => self::hasScalarField($result, ['outcome', 'status', 'verdict']),
            'scenario_results' => self::hasArrayField($result, ['scenario_results', 'scenarioResults']),
            'findings' => self::hasArrayField($result, ['findings']),
            'finding_links' => self::hasArrayField($result, ['finding_links', 'findingLinks']),
            default => self::hasScalarField($result, [$field, self::camelize($field)])
                || self::hasArrayField($result, [$field, self::camelize($field)]),
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
    private static function declaredOutcomeStatusFailures(
        array $result,
        array $contract,
        string $evaluatedStatus,
    ): array
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
            if (! self::hasArtifactVersion($versions, (string) $artifact)) {
                $failures[] = [
                    'code' => 'missing_artifact_version',
                    'artifact' => $artifact,
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
    private static function hasArtifactVersion(array $versions, string $artifact): bool
    {
        $aliases = [
            'workflow-php' => ['workflow-php', 'workflow_php', 'workflow'],
            'sdk-python' => ['sdk-python', 'sdk_python', 'python'],
        ];

        foreach ($aliases[$artifact] ?? [$artifact] as $key) {
            if (array_key_exists($key, $versions) && self::stringValue($versions[$key]) !== '') {
                return true;
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
     * @param array<string, mixed> $result
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return array<int, array<string, mixed>>
     */
    private static function requiredSectionFailures(array $result, array $scenarioResults): array
    {
        $sections = [
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
            ],
        ];

        $failures = [];
        foreach ($sections as $section => $scenarios) {
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
     * @param array<string, mixed> $result
     * @param array<string, array<string, mixed>> $scenarioResults
     * @param array<string, mixed> $contract
     *
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
                $expectedTotal = $requirement['expected_total_for_1_through_10'] ?? null;
                $queriedTotal = self::evidenceValue($result, $scenarioResult, $scenarioId, 'queried_total');

                if (is_numeric($expectedTotal) && is_numeric($queriedTotal)
                    && (int) $queriedTotal !== (int) $expectedTotal) {
                    $failures[] = [
                        'code' => 'unexpected_ordered_signal_total',
                        'scenario_id' => $scenarioId,
                        'expected_total' => (int) $expectedTotal,
                        'actual_total' => (int) $queriedTotal,
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
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $requirement
     *
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
     * @param array<string, mixed> $result
     * @param array<string, mixed> $scenarioResult
     */
    private static function hasEvidence(
        array $result,
        array $scenarioResult,
        string $scenarioId,
        string $evidenceKey,
    ): bool {
        return self::evidenceValue($result, $scenarioResult, $scenarioId, $evidenceKey) !== null;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $scenarioResult
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
     * @param array<string, mixed> $result
     * @param array<string, mixed> $scenarioResult
     *
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

        foreach ([
            'replay_timing',
            'terminal_run_behavior',
            'adversarial_errors',
            'waterline_observer_comparison',
        ] as $field) {
            $section = self::arrayValue($result, $field);
            if ($section === null) {
                continue;
            }

            array_push($containers, ...self::scenarioSectionContainers($section, $scenarioId));
        }

        return $containers;
    }

    /**
     * @param array<mixed> $section
     *
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
     * @param array<mixed> $value
     * @param list<string> $path
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
     * @param array<mixed> $value
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

        return $coveredScenarios === ['python_worker_cli_and_sdk_baseline']
            || $coveredScenarios === ['published_artifact_install_only', 'python_worker_cli_and_sdk_baseline'];
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

    /**
     * @param array<mixed> $value
     *
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
