<?php

namespace App\Support;

/**
 * Evaluates worker-versioning conformance results against the full safe-deploy
 * matrix exposed by WorkerVersioningRuntimeContract.
 */
final class WorkerVersioningRuntimeResultGate
{
    public const SCHEMA = 'durable-workflow.v2.worker-versioning-runtime.result-gate';

    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'evaluates_result_schema' => WorkerVersioningRuntimeContract::RESULT_SCHEMA,
            'scenario_statuses_source' => 'worker_versioning_runtime_contract.scenario_statuses',
            'required_scenarios_source' => 'worker_versioning_runtime_contract.required_scenarios',
            'required_matrix_source' => 'worker_versioning_runtime_contract.required_matrix',
            'scenario_required_fields_source' => 'worker_versioning_runtime_contract.scenario_requirements.*.required_fields',
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
            'declared_outcomes_source' => 'worker_versioning_runtime_contract.coverage_gate.*_outcome',
            'non_pass_statuses' => [
                'fail',
                'unsupported',
                'not_covered',
                'runner_blocked',
            ],
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
            'pass_requires' => [
                'every_required_scenario_has_one_result',
                'every_result_uses_a_published_status',
                'required_php_and_python_workers_are_reported',
                'required_cli_python_php_and_waterline_surfaces_are_reported',
                'pin_replay_promotion_no_compatible_and_history_sections_are_reported',
                'cross_language_and_adversarial_sections_are_reported',
                'each_pass_scenario_has_observed_outputs',
                'each_pass_scenario_has_scenario_specific_evidence',
                'compatible_replay_counts_prove_zero_incompatible_delivery',
                'cross_language_php_python_counts_prove_zero_incompatible_delivery',
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
        $contract ??= WorkerVersioningRuntimeContract::manifest();

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
        array_push($failures, ...self::matrixFailures($result, $contract));
        array_push($failures, ...self::requiredSectionFailures($result));
        array_push($failures, ...self::scenarioSpecificEvidenceFailures($result, $contract, $scenarioResults));

        $smokeSubsetDetected = self::isSmokeSubset($scenarioStatuses, $contract);
        if ($smokeSubsetDetected) {
            $failures[] = [
                'code' => 'smoke_subset_cannot_pass',
                'reason' => 'Worker registration and rollout smoke coverage is not a complete worker-versioning conformance result.',
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
     * @param array<string, mixed> $scenarioResult
     */
    private static function hasObservedOutputs(array $scenarioResult): bool
    {
        foreach ([
            'observed_outputs',
            'observedOutputs',
            'versioning_observations',
            'versioningObservations',
            'history_version_pins',
            'historyVersionPins',
            'operator_controls',
            'operatorControls',
            'mixed_version_polling',
            'mixedVersionPolling',
            'no_compatible_worker',
            'noCompatibleWorker',
            'cross_language_matrix',
            'crossLanguageMatrix',
            'adversarial_outcomes',
            'adversarialOutcomes',
            'waterline_operator_visibility',
            'waterlineOperatorVisibility',
        ] as $field) {
            $value = self::arrayField($scenarioResult, [$field]);
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
            $value = self::arrayField($scenarioResult, [$field]);
            if ($value !== null && $value !== []) {
                return true;
            }
        }

        $scenarioId = self::stringValue($scenarioResult['scenario_id'] ?? null);
        foreach (['finding_links', 'findingLinks', 'findings'] as $field) {
            $links = self::arrayField($result, [$field]);
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
        $allowedOutcomes = self::declaredOutcomes($contract);
        $failures = [];

        foreach (self::declaredOutcomeTokens($result) as $field => $outcome) {
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
        $allowedOutcomes = self::declaredOutcomes($contract);
        $failures = [];
        $declaredStatuses = [];

        foreach (self::declaredOutcomeTokens($result) as $field => $outcome) {
            if (! in_array($outcome, $allowedOutcomes, true)) {
                continue;
            }

            $declaredStatus = $outcome === 'pass' ? 'pass' : 'non_passing';
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
            $failures[] = [
                'code' => 'conflicting_outcome_tokens',
                'declared_statuses' => $declaredStatuses,
            ];
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
        $coverageGate = self::arrayField($contract, ['coverage_gate']) ?? [];
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

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return array<int, array<string, mixed>>
     */
    private static function artifactVersionFailures(array $result, array $contract): array
    {
        $versions = self::artifactVersions($result);
        $installChannels = self::arrayField($contract['artifact_policy'] ?? [], ['install_channels']) ?? [];
        $failures = [];

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
        return self::arrayField($result, [
            'artifact_versions',
            'artifactVersions',
            'published_artifact_versions',
            'publishedArtifactVersions',
        ]) ?? [];
    }

    /**
     * @param array<mixed> $versions
     */
    private static function artifactVersionValue(array $versions, string $artifact): string
    {
        $aliases = [
            $artifact,
            str_replace('-', '_', $artifact),
            str_replace('_', '-', $artifact),
        ];

        if ($artifact === 'workflow-php') {
            $aliases[] = 'workflow';
            $aliases[] = 'php';
        }

        if ($artifact === 'sdk-python') {
            $aliases[] = 'python';
            $aliases[] = 'durable-workflow';
        }

        foreach (array_unique($aliases) as $alias) {
            $value = self::stringValue($versions[$alias] ?? null);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function isPlaceholderVersion(string $version): bool
    {
        $normalized = strtolower(trim($version));

        if ($normalized === '') {
            return true;
        }

        foreach (['latest', 'current', 'head', 'unresolved', 'placeholder', '<latest>', '${version}', '{{ version }}'] as $placeholder) {
            if (str_contains($normalized, $placeholder)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return array<int, array<string, mixed>>
     */
    private static function sourcePolicyFailures(array $result, array $contract, array $scenarioResults): array
    {
        $artifactPolicy = self::arrayField($contract, ['artifact_policy']) ?? [];
        $forbiddenSources = array_values(array_unique(array_merge(
            self::stringList($artifactPolicy['forbidden_sources'] ?? []),
            ['local_checkout_artifact'],
        )));
        $reportedSourceSets = [];
        foreach (['artifact_sources', 'artifactSources', 'source_paths', 'sourcePaths'] as $field) {
            $topLevelSources = self::arrayField($result, [$field]);
            if ($topLevelSources === null) {
                continue;
            }

            $reportedSourceSets[] = [
                'sources' => $topLevelSources,
                'field' => $field,
                'scenario_id' => null,
            ];
        }

        foreach ($scenarioResults as $scenarioId => $scenarioResult) {
            $outputs = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']) ?? [];
            foreach (['artifact_sources', 'artifactSources', 'source_paths', 'sourcePaths'] as $field) {
                $scenarioSources = self::arrayField($outputs, [$field]);
                if ($scenarioSources === null) {
                    continue;
                }

                $reportedSourceSets[] = [
                    'sources' => $scenarioSources,
                    'field' => $field,
                    'scenario_id' => $scenarioId,
                ];
            }
        }

        $failures = [];

        foreach ($reportedSourceSets as $sourceSet) {
            foreach ($sourceSet['sources'] as $artifact => $source) {
                $source = self::stringValue($source);
                if (! in_array($source, $forbiddenSources, true)) {
                    continue;
                }

                $failure = [
                    'code' => 'forbidden_artifact_source',
                    'artifact' => is_string($artifact) ? $artifact : null,
                    'source' => $source,
                    'field' => $sourceSet['field'],
                ];
                if ($sourceSet['scenario_id'] !== null) {
                    $failure['scenario_id'] = $sourceSet['scenario_id'];
                }

                $failures[] = $failure;
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
        $matrix = self::arrayField($contract, ['required_matrix']) ?? [];
        $reportedRuntimeMatrix = self::arrayField($result, ['runtime_matrix', 'runtimeMatrix']) ?? [];
        $failures = [];

        foreach (self::stringList($matrix['runtimes'] ?? []) as $runtime) {
            if (! self::matrixRuntimeListContains($reportedRuntimeMatrix, ['runtimes', 'worker_runtimes', 'workerRuntimes'], $runtime)) {
                $failures[] = [
                    'code' => 'missing_required_runtime',
                    'runtime' => $runtime,
                ];
            }
        }

        foreach (self::stringList($matrix['client_paths'] ?? []) as $clientPath) {
            if (! self::matrixClientListContains($reportedRuntimeMatrix, ['client_paths', 'clientPaths', 'clients'], $clientPath)) {
                $failures[] = [
                    'code' => 'missing_required_client_path',
                    'client_path' => $clientPath,
                ];
            }
        }

        foreach (self::stringList($matrix['operator_visibility_paths'] ?? []) as $visibilityPath) {
            if (! self::matrixTokenListContains($reportedRuntimeMatrix, ['operator_visibility_paths', 'operatorVisibilityPaths', 'operator_surfaces', 'operatorSurfaces'], $visibilityPath)) {
                $failures[] = [
                    'code' => 'missing_operator_visibility_path',
                    'operator_visibility_path' => $visibilityPath,
                ];
            }
        }

        foreach (self::stringList($matrix['worker_cohorts'] ?? []) as $cohort) {
            if (! self::matrixTokenListContains($reportedRuntimeMatrix, ['worker_cohorts', 'workerCohorts', 'cohorts'], $cohort)) {
                $failures[] = [
                    'code' => 'missing_required_worker_cohort',
                    'worker_cohort' => $cohort,
                ];
            }
        }

        foreach (($matrix['cross_language_cells'] ?? []) as $requiredCell) {
            if (! is_array($requiredCell) || self::matrixHasCrossLanguageCell($reportedRuntimeMatrix, $requiredCell)) {
                continue;
            }

            $failures[] = [
                'code' => 'missing_required_cross_language_cell',
                'scenario' => $requiredCell['scenario'] ?? null,
                'started_by' => $requiredCell['started_by'] ?? null,
                'incompatible_worker' => $requiredCell['incompatible_worker'] ?? null,
            ];
        }

        return $failures;
    }

    /**
     * @param array<mixed> $matrix
     * @param list<string> $fields
     */
    private static function matrixRuntimeListContains(array $matrix, array $fields, string $expected): bool
    {
        foreach ($fields as $field) {
            foreach (self::stringList($matrix[$field] ?? []) as $reported) {
                if (self::sameRuntimeSurface($reported, $expected)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $matrix
     * @param list<string> $fields
     */
    private static function matrixClientListContains(array $matrix, array $fields, string $expected): bool
    {
        foreach ($fields as $field) {
            foreach (self::stringList($matrix[$field] ?? []) as $reported) {
                if (self::sameClientSurface($reported, $expected)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $matrix
     * @param list<string> $fields
     */
    private static function matrixTokenListContains(array $matrix, array $fields, string $expected): bool
    {
        foreach ($fields as $field) {
            foreach (self::stringList($matrix[$field] ?? []) as $reported) {
                if (self::sameNormalizedToken($reported, $expected)) {
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
    private static function matrixHasCrossLanguageCell(array $matrix, array $requiredCell): bool
    {
        $reportedCells = [];
        foreach (['cross_language_cells', 'crossLanguageCells', 'cells', 'runtime_cells', 'runtimeCells'] as $field) {
            $value = self::arrayField($matrix, [$field]);
            if ($value !== null) {
                $reportedCells = array_merge($reportedCells, $value);
            }
        }

        foreach ($reportedCells as $cell) {
            if (! is_array($cell)) {
                continue;
            }

            if (self::stringField($cell, ['scenario', 'scenario_id', 'scenarioId'])
                !== self::stringValue($requiredCell['scenario'] ?? null)) {
                continue;
            }

            if (! self::sameRuntimeSurface(
                self::stringField($cell, ['started_by', 'startedBy', 'starter', 'workflow_runtime', 'workflowRuntime']),
                self::stringValue($requiredCell['started_by'] ?? null),
            )) {
                continue;
            }

            if (! self::sameRuntimeSurface(
                self::stringField($cell, ['incompatible_worker', 'incompatibleWorker', 'worker', 'runtime']),
                self::stringValue($requiredCell['incompatible_worker'] ?? null),
            )) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<int, array<string, mixed>>
     */
    private static function requiredSectionFailures(array $result): array
    {
        $requiredSections = [
            'versioning_observations',
            'history_version_pins',
            'operator_controls',
            'mixed_version_polling',
            'no_compatible_worker',
            'cross_language_matrix',
            'adversarial_outcomes',
        ];
        $failures = [];

        foreach ($requiredSections as $section) {
            if (self::hasRunRecordField($result, $section)) {
                continue;
            }

            $failures[] = [
                'code' => 'missing_required_evidence_section',
                'section' => $section,
            ];
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
    private static function scenarioSpecificEvidenceFailures(array $result, array $contract, array $scenarioResults): array
    {
        $requirements = self::scenarioRequiredFields($contract);
        $failures = [];

        foreach ($requirements as $scenarioId => $fields) {
            $scenarioResult = $scenarioResults[$scenarioId] ?? null;
            if (! is_array($scenarioResult) || self::stringValue($scenarioResult['status'] ?? null) !== 'pass') {
                continue;
            }

            foreach ($fields as $field) {
                if (self::hasEvidenceField($scenarioResult, $field)) {
                    continue;
                }

                $failures[] = [
                    'code' => 'missing_scenario_required_field',
                    'scenario_id' => $scenarioId,
                    'field' => $field,
                ];
            }
        }

        $publishedArtifactResult = $scenarioResults['published_artifact_install_only'] ?? null;
        if (is_array($publishedArtifactResult)
            && self::stringValue($publishedArtifactResult['status'] ?? null) === 'pass') {
            array_push($failures, ...self::publishedArtifactEvidenceFailures($result, $contract, $publishedArtifactResult));
        }

        array_push($failures, ...self::routingInvariantFailures($scenarioResults));
        array_push($failures, ...self::crossLanguageInvariantFailures($scenarioResults));

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function publishedArtifactEvidenceFailures(array $result, array $contract, array $scenarioResult): array
    {
        $outputs = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']) ?? [];
        $topLevelSources = self::arrayField($result, ['artifact_sources', 'artifactSources']) ?? [];
        $scenarioSources = self::arrayField($outputs, ['artifact_sources', 'artifactSources', 'install_sources', 'installSources']) ?? [];
        $sources = array_replace($topLevelSources, $scenarioSources);
        $installChannels = self::arrayField($contract['artifact_policy'] ?? [], ['install_channels']) ?? [];
        $failures = [];

        foreach (array_keys($installChannels) as $artifact) {
            $source = self::artifactVersionValue($sources, (string) $artifact);
            if ($source !== '') {
                continue;
            }

            $failures[] = [
                'code' => 'missing_published_artifact_install_source',
                'scenario_id' => 'published_artifact_install_only',
                'artifact' => $artifact,
            ];
        }

        if (! self::hasExplicitFalseField($outputs, [
            'local_product_source_checkouts_used',
            'localProductSourceCheckoutsUsed',
        ])) {
            $failures[] = [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'scenario_id' => 'published_artifact_install_only',
                'field' => 'local_product_source_checkouts_used',
                'value' => $outputs['local_product_source_checkouts_used']
                    ?? $outputs['localProductSourceCheckoutsUsed']
                    ?? null,
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return array<int, array<string, mixed>>
     */
    private static function routingInvariantFailures(array $scenarioResults): array
    {
        $failures = [];
        $pinOnStart = self::passingScenarioEvidence($scenarioResults, 'pin_on_start');
        $pinnedBuildId = $pinOnStart === null
            ? ''
            : self::stringField($pinOnStart, ['run_compatibility', 'runCompatibility']);

        $compatibleReplay = self::passingScenarioEvidence($scenarioResults, 'replay_only_by_compatible_workers');
        if ($compatibleReplay !== null) {
            self::requirePositiveCount(
                $failures,
                $compatibleReplay,
                'replay_only_by_compatible_workers',
                'v1_worker_task_count',
                'compatible_worker_task_count_not_positive',
            );
            self::requireZeroCount(
                $failures,
                $compatibleReplay,
                'replay_only_by_compatible_workers',
                'v2_worker_task_count_for_v1_run',
            );
        }

        $cacheEviction = self::passingScenarioEvidence($scenarioResults, 'replay_across_cache_eviction');
        if ($cacheEviction !== null) {
            self::requireTruthyField(
                $failures,
                $cacheEviction,
                'replay_across_cache_eviction',
                'cache_eviction_observed',
            );
            self::requireZeroCount(
                $failures,
                $cacheEviction,
                'replay_across_cache_eviction',
                'incompatible_delivery_count',
            );

            $replayWorkerBuildId = self::stringField($cacheEviction, [
                'replay_worker_build_id',
                'replayWorkerBuildId',
            ]);
            if (
                $pinnedBuildId !== ''
                && $replayWorkerBuildId !== ''
                && $replayWorkerBuildId !== $pinnedBuildId
            ) {
                $failures[] = [
                    'code' => 'replay_worker_build_id_mismatch',
                    'scenario_id' => 'replay_across_cache_eviction',
                    'field' => 'replay_worker_build_id',
                    'expected' => $pinnedBuildId,
                    'actual' => $replayWorkerBuildId,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<string, array<string, mixed>> $scenarioResults
     * @return array<string, mixed>|null
     */
    private static function passingScenarioEvidence(array $scenarioResults, string $scenarioId): ?array
    {
        $scenarioResult = $scenarioResults[$scenarioId] ?? null;
        if (! is_array($scenarioResult) || self::stringValue($scenarioResult['status'] ?? null) !== 'pass') {
            return null;
        }

        $observedOutputs = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']) ?? [];

        return array_replace($scenarioResult, $observedOutputs);
    }

    /**
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return array<int, array<string, mixed>>
     */
    private static function crossLanguageInvariantFailures(array $scenarioResults): array
    {
        $evidence = self::passingScenarioEvidence($scenarioResults, 'cross_language_php_python_pinning');

        if ($evidence === null) {
            return [];
        }

        $failures = [];

        self::requireZeroCount(
            $failures,
            $evidence,
            'cross_language_php_python_pinning',
            'php_v1_to_python_v2_incompatible_delivery_count',
        );

        self::requireZeroCount(
            $failures,
            $evidence,
            'cross_language_php_python_pinning',
            'python_v1_to_php_v2_incompatible_delivery_count',
        );

        return $failures;
    }

    /**
     * @param array<int, array<string, mixed>> $failures
     * @param array<string, mixed> $evidence
     */
    private static function requireZeroCount(array &$failures, array $evidence, string $scenarioId, string $field): void
    {
        $aliases = [$field, self::camelize($field)];
        if (! self::fieldExists($evidence, $aliases)) {
            return;
        }

        $count = self::intField($evidence, $aliases);
        if ($count === null) {
            $failures[] = [
                'code' => 'invalid_numeric_evidence_field',
                'scenario_id' => $scenarioId,
                'field' => $field,
                'expected' => 'integer_zero',
                'actual' => $evidence[$field] ?? $evidence[self::camelize($field)] ?? null,
            ];

            return;
        }

        if ($count !== 0) {
            $failures[] = [
                'code' => 'incompatible_delivery_count_nonzero',
                'scenario_id' => $scenarioId,
                'field' => $field,
                'expected' => 0,
                'actual' => $count,
            ];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $failures
     * @param array<string, mixed> $evidence
     */
    private static function requirePositiveCount(
        array &$failures,
        array $evidence,
        string $scenarioId,
        string $field,
        string $code,
    ): void {
        $aliases = [$field, self::camelize($field)];
        if (! self::fieldExists($evidence, $aliases)) {
            return;
        }

        $count = self::intField($evidence, $aliases);
        if ($count === null) {
            $failures[] = [
                'code' => 'invalid_numeric_evidence_field',
                'scenario_id' => $scenarioId,
                'field' => $field,
                'expected' => 'positive_integer',
                'actual' => $evidence[$field] ?? $evidence[self::camelize($field)] ?? null,
            ];

            return;
        }

        if ($count < 1) {
            $failures[] = [
                'code' => $code,
                'scenario_id' => $scenarioId,
                'field' => $field,
                'expected' => '>=1',
                'actual' => $count,
            ];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $failures
     * @param array<string, mixed> $evidence
     */
    private static function requireTruthyField(array &$failures, array $evidence, string $scenarioId, string $field): void
    {
        $aliases = [$field, self::camelize($field)];
        if (! self::fieldExists($evidence, $aliases) || self::truthyField($evidence, $aliases)) {
            return;
        }

        $failures[] = [
            'code' => 'scenario_field_must_be_true',
            'scenario_id' => $scenarioId,
            'field' => $field,
            'expected' => true,
            'actual' => $evidence[$field] ?? $evidence[self::camelize($field)] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $contract
     *
     * @return array<string, list<string>>
     */
    private static function scenarioRequiredFields(array $contract): array
    {
        $requirements = self::arrayField($contract, ['scenario_requirements', 'scenarioRequirements']) ?? [];
        $fieldsByScenario = [];

        foreach ($requirements as $scenarioId => $requirement) {
            if (! is_string($scenarioId) || ! is_array($requirement)) {
                continue;
            }

            $requiredFields = $requirement['required_fields'] ?? $requirement['requiredFields'] ?? [];
            if (! is_array($requiredFields)) {
                continue;
            }

            $fields = self::stringList($requiredFields);
            if ($fields !== []) {
                $fieldsByScenario[$scenarioId] = $fields;
            }
        }

        return $fieldsByScenario;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     */
    private static function hasEvidenceField(array $scenarioResult, string $field): bool
    {
        $aliases = [$field, self::camelize($field)];
        $observedOutputs = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']);

        foreach ([$observedOutputs, $scenarioResult] as $evidence) {
            if (! is_array($evidence)) {
                continue;
            }

            if (self::hasScalarField($evidence, $aliases) || self::hasArrayField($evidence, $aliases)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $scenarioStatuses
     * @param array<string, mixed> $contract
     */
    private static function isSmokeSubset(array $scenarioStatuses, array $contract): bool
    {
        if ($scenarioStatuses === []) {
            return false;
        }

        $requiredScenarios = self::stringList($contract['required_scenarios'] ?? []);
        if (count($scenarioStatuses) >= count($requiredScenarios)) {
            return false;
        }

        $smokeScenarioIds = [
            'published_artifact_install_only',
            'worker_registration_build_ids',
            'operator_rollout_visibility',
            'drain_resume_operator_controls',
        ];

        return array_diff(array_keys($scenarioStatuses), $smokeScenarioIds) === [];
    }

    private static function sameRuntimeSurface(string $reported, string $expected): bool
    {
        return self::normalizeRuntimeSurface($reported) === self::normalizeRuntimeSurface($expected);
    }

    private static function sameClientSurface(string $reported, string $expected): bool
    {
        return self::normalizeClientSurface($reported) === self::normalizeClientSurface($expected);
    }

    private static function sameNormalizedToken(string $reported, string $expected): bool
    {
        return self::normalizeToken($reported) === self::normalizeToken($expected);
    }

    private static function normalizeRuntimeSurface(string $value): string
    {
        $normalized = self::normalizeToken($value);
        $aliases = [
            'php' => 'workflowphp',
            'phpworker' => 'workflowphp',
            'phpruntime' => 'workflowphp',
            'workflow' => 'workflowphp',
            'workflowphp' => 'workflowphp',
            'workflowphpworker' => 'workflowphp',
            'workflowphpruntime' => 'workflowphp',
            'python' => 'sdkpython',
            'pythonworker' => 'sdkpython',
            'pythonruntime' => 'sdkpython',
            'pythonsdk' => 'sdkpython',
            'sdkpython' => 'sdkpython',
        ];

        return $aliases[$normalized] ?? $normalized;
    }

    private static function normalizeClientSurface(string $value): string
    {
        $normalized = self::normalizeToken($value);
        $aliases = [
            'dw' => 'cli',
            'durableworkflowcli' => 'cli',
            'python' => 'sdkpython',
            'pythonclient' => 'sdkpython',
            'pythonsdk' => 'sdkpython',
            'sdkpython' => 'sdkpython',
            'phpclient' => 'workflowphpsdk',
            'phpsdk' => 'workflowphpsdk',
            'workflowphpclient' => 'workflowphpsdk',
            'workflowphpsdk' => 'workflowphpsdk',
        ];

        return $aliases[$normalized] ?? $normalized;
    }

    private static function normalizeToken(string $value): string
    {
        return str_replace(['_', '-', ' '], '', strtolower($value));
    }

    /**
     * @param array<mixed> $value
     * @return list<string>
     */
    private static function stringList(array $value): array
    {
        $strings = [];
        foreach ($value as $item) {
            $string = self::stringValue($item);
            if ($string !== '') {
                $strings[] = $string;
            }
        }

        return array_values(array_unique($strings));
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $fields
     */
    private static function hasScalarField(array $value, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $value) && ! is_array($value[$field]) && self::stringValue($value[$field]) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $fields
     */
    private static function hasArrayField(array $value, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $value) && is_array($value[$field]) && $value[$field] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $fields
     */
    private static function hasExplicitFalseField(array $value, array $fields): bool
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $value)) {
                continue;
            }

            $fieldValue = $value[$field];
            if ($fieldValue === false || $fieldValue === 0 || $fieldValue === '0') {
                return true;
            }

            if (is_string($fieldValue) && strtolower(trim($fieldValue)) === 'false') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $fields
     */
    private static function fieldExists(array $value, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $fields
     */
    private static function intField(array $value, array $fields): ?int
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $value)) {
                continue;
            }

            $fieldValue = $value[$field];
            if (is_int($fieldValue)) {
                return $fieldValue;
            }

            if (is_float($fieldValue) && floor($fieldValue) === $fieldValue) {
                return (int) $fieldValue;
            }

            if (is_string($fieldValue) && preg_match('/^-?\d+$/', trim($fieldValue)) === 1) {
                return (int) trim($fieldValue);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $fields
     */
    private static function truthyField(array $value, array $fields): bool
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $value)) {
                continue;
            }

            $fieldValue = $value[$field];
            if ($fieldValue === true || $fieldValue === 1 || $fieldValue === '1') {
                return true;
            }

            if (is_string($fieldValue) && strtolower(trim($fieldValue)) === 'true') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $fields
     */
    private static function stringField(array $value, array $fields): string
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
     * @param array<string, mixed> $value
     * @param list<string> $fields
     * @return array<mixed>|null
     */
    private static function arrayField(array $value, array $fields): ?array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $value) && is_array($value[$field])) {
                return $value[$field];
            }
        }

        return null;
    }

    private static function stringValue(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return '';
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
