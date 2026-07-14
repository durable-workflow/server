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
                'no_compatible_worker_compatible_cohort_stopped',
                'no_compatible_worker_incompatible_cohort_polled',
                'no_compatible_worker_has_zero_incompatible_delivery',
                'no_compatible_worker_signal_is_explicit',
                'cross_language_php_python_counts_prove_zero_incompatible_delivery',
                'each_non_pass_scenario_has_linked_findings',
                'run_timestamps_outcome_and_finding_links_are_recorded',
                'overall_outcome_matches_gate_status',
                'published_artifact_versions_are_recorded_and_pinned',
                'published_artifact_install_evidence_reported',
                'published_artifact_worker_execution_reported_for_replay_adversarial_and_cross_language_cells',
                'no_compatible_worker_public_protocol_probe_or_worker_execution_reported',
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

    private static function isConcreteArtifactVersion(string $version): bool
    {
        return preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[.-][0-9A-Za-z.-]+)?$/', trim($version)) === 1;
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
            [
                'local_checkout_artifact',
                'local_checkout',
                'local_source_checkout',
                'workspace_repo',
            ],
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
                if (! self::isForbiddenArtifactSource($source, $forbiddenSources)) {
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
                if (self::hasEvidenceField($scenarioResult, $field, $scenarioId)) {
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

        array_push($failures, ...self::operatorRolloutVisibilityEvidenceFailures($scenarioResults));
        array_push($failures, ...self::drainResumeOperatorControlsEvidenceFailures($scenarioResults));
        array_push($failures, ...self::routingInvariantFailures($scenarioResults));
        array_push($failures, ...self::crossLanguageInvariantFailures($scenarioResults));
        array_push(
            $failures,
            ...self::publishedWorkerExecutionEvidenceFailures($scenarioResults, $result),
        );

        return $failures;
    }

    /**
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return array<int, array<string, mixed>>
     */
    private static function operatorRolloutVisibilityEvidenceFailures(array $scenarioResults): array
    {
        $evidence = self::passingScenarioEvidence($scenarioResults, 'operator_rollout_visibility');
        if ($evidence === null) {
            return [];
        }

        $waterline = self::fieldValue($evidence, [
            'waterline_operator_visibility',
            'waterlineOperatorVisibility',
        ]);
        if ($waterline === null) {
            return [];
        }

        if (! is_array($waterline)) {
            return [[
                'code' => 'invalid_waterline_operator_visibility_evidence',
                'scenario_id' => 'operator_rollout_visibility',
                'field' => 'waterline_operator_visibility',
                'expected' => 'object',
                'actual_type' => get_debug_type($waterline),
            ]];
        }

        if ($waterline === []) {
            return [];
        }

        if (self::isUnexercisedWaterlineVisibility($waterline)) {
            return [[
                'code' => 'waterline_operator_visibility_not_exercised',
                'scenario_id' => 'operator_rollout_visibility',
                'field' => 'waterline_operator_visibility',
                'status' => self::stringField($waterline, ['status', 'outcome', 'result']),
            ]];
        }

        if (! self::hasWaterlineVisibilitySignal($waterline)) {
            return [[
                'code' => 'missing_waterline_operator_visibility_signal',
                'scenario_id' => 'operator_rollout_visibility',
                'field' => 'waterline_operator_visibility',
            ]];
        }

        return [];
    }

    /**
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return array<int, array<string, mixed>>
     */
    private static function drainResumeOperatorControlsEvidenceFailures(array $scenarioResults): array
    {
        $evidence = self::passingScenarioEvidence($scenarioResults, 'drain_resume_operator_controls');
        if ($evidence === null) {
            return [];
        }

        $failures = [];
        $claimBlockedAliases = self::evidenceFieldAliases(
            'drain_resume_operator_controls',
            'draining_worker_claim_blocked',
        );
        if (! self::fieldExists($evidence, $claimBlockedAliases)) {
            $failures[] = [
                'code' => 'missing_draining_worker_claim_blocked_evidence',
                'scenario_id' => 'drain_resume_operator_controls',
                'field' => 'draining_worker_claim_blocked',
            ];
        } elseif (! self::truthyField($evidence, $claimBlockedAliases)) {
            $failures[] = [
                'code' => 'draining_worker_claim_not_blocked',
                'scenario_id' => 'drain_resume_operator_controls',
                'field' => 'draining_worker_claim_blocked',
                'expected' => true,
                'actual' => self::fieldValue($evidence, $claimBlockedAliases),
            ];
        }

        $claimCountAliases = self::evidenceFieldAliases(
            'drain_resume_operator_controls',
            'draining_worker_claim_count',
        );
        if (! self::fieldExists($evidence, $claimCountAliases)) {
            $failures[] = [
                'code' => 'missing_draining_worker_claim_count_evidence',
                'scenario_id' => 'drain_resume_operator_controls',
                'field' => 'draining_worker_claim_count',
            ];
        } else {
            self::requireZeroCount(
                $failures,
                $evidence,
                'drain_resume_operator_controls',
                'draining_worker_claim_count',
                $claimCountAliases,
            );
        }

        $pollAliases = self::evidenceFieldAliases(
            'drain_resume_operator_controls',
            'draining_worker_poll',
        );
        $poll = self::fieldValue($evidence, $pollAliases);
        if (! is_array($poll)) {
            $failures[] = [
                'code' => 'missing_draining_worker_poll_evidence',
                'scenario_id' => 'drain_resume_operator_controls',
                'field' => 'draining_worker_poll',
                'expected' => 'poll_response_object',
                'actual_type' => get_debug_type($poll),
            ];

            return $failures;
        }

        $pollStatus = self::stringField($poll, ['poll_status', 'pollStatus']);
        $reason = self::stringField($poll, ['reason']);
        $httpStatus = self::intField($poll, ['http_status', 'httpStatus', '__http_status']);
        if ($pollStatus !== 'draining' || $reason !== 'worker_draining' || $httpStatus !== 409) {
            $failures[] = [
                'code' => 'draining_worker_poll_not_blocked',
                'scenario_id' => 'drain_resume_operator_controls',
                'field' => 'draining_worker_poll',
                'expected' => [
                    'http_status' => 409,
                    'poll_status' => 'draining',
                    'reason' => 'worker_draining',
                ],
                'actual' => [
                    'http_status' => $httpStatus,
                    'poll_status' => $pollStatus,
                    'reason' => $reason,
                ],
            ];
        }

        if (! array_key_exists('task', $poll)) {
            $failures[] = [
                'code' => 'draining_worker_poll_missing_task_field',
                'scenario_id' => 'drain_resume_operator_controls',
                'field' => 'draining_worker_poll.task',
                'expected' => null,
            ];
        } elseif ($poll['task'] !== null) {
            $failures[] = [
                'code' => 'draining_worker_poll_claimed_task',
                'scenario_id' => 'drain_resume_operator_controls',
                'field' => 'draining_worker_poll.task',
                'expected' => null,
                'actual' => $poll['task'],
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $waterline
     */
    private static function isUnexercisedWaterlineVisibility(array $waterline): bool
    {
        $status = self::stringField($waterline, ['status', 'outcome', 'result']);
        if ($status === '') {
            return false;
        }

        $normalized = self::normalizeToken($status);
        foreach ([
            'notexercisedbyserverhandoff',
            'notexercised',
            'notcovered',
            'runnerblocked',
            'unsupported',
            'missing',
            'placeholder',
        ] as $placeholder) {
            if ($normalized === $placeholder || str_contains($normalized, $placeholder)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $waterline
     */
    private static function hasWaterlineVisibilitySignal(array $waterline): bool
    {
        return self::hasArrayField($waterline, [
            'worker_cohorts',
            'workerCohorts',
            'workflow_runs',
            'workflowRuns',
            'operator_surface_matrix',
            'operatorSurfaceMatrix',
            'api_captures',
            'apiCaptures',
            'worker_list',
            'workerList',
            'workflow_visibility',
            'workflowVisibility',
            'task_queue_build_ids',
            'taskQueueBuildIds',
        ])
            || self::hasScalarField($waterline, [
                'workflow_compatibility',
                'workflowCompatibility',
                'compatibility',
                'output_sample',
                'outputSample',
            ])
            || self::truthyField($waterline, [
                'visible',
                'operator_visible',
                'operatorVisible',
                'worker_view_visible',
                'workerViewVisible',
                'workflow_view_visible',
                'workflowViewVisible',
            ]);
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
        $artifactPolicy = self::arrayField($contract, ['artifact_policy']) ?? [];
        $forbiddenInstallSources = array_values(array_unique(array_merge(
            self::stringList($artifactPolicy['forbidden_sources'] ?? []),
            [
                'local_checkout_artifact',
                'local_checkout',
                'local_source_checkout',
                'workspace_repo',
                'not_exercised',
            ],
        )));
        $failures = [];

        foreach (array_keys($installChannels) as $artifact) {
            $source = self::artifactVersionValue($sources, (string) $artifact);
            if ($source === '') {
                $failures[] = [
                    'code' => 'missing_published_artifact_install_source',
                    'scenario_id' => 'published_artifact_install_only',
                    'artifact' => $artifact,
                ];

                continue;
            }

            if (self::isForbiddenArtifactSource($source, $forbiddenInstallSources)) {
                $failures[] = [
                    'code' => 'forbidden_published_artifact_install_source',
                    'scenario_id' => 'published_artifact_install_only',
                    'artifact' => $artifact,
                    'source' => $source,
                    'field' => 'artifact_sources',
                ];
            }
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

        $installEvidence = self::arrayField($outputs, [
            'artifact_install_evidence',
            'artifactInstallEvidence',
            'install_evidence',
            'installEvidence',
        ]);
        if ($installEvidence === null) {
            $failures[] = [
                'code' => 'missing_published_artifact_install_evidence',
                'scenario_id' => 'published_artifact_install_only',
                'field' => 'artifact_install_evidence',
            ];

            return $failures;
        }

        if (! self::hasExplicitFalseField($installEvidence, [
            'local_product_source_checkouts_used',
            'localProductSourceCheckoutsUsed',
        ])) {
            $failures[] = [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'scenario_id' => 'published_artifact_install_only',
                'field' => 'artifact_install_evidence.local_product_source_checkouts_used',
                'value' => $installEvidence['local_product_source_checkouts_used']
                    ?? $installEvidence['localProductSourceCheckoutsUsed']
                    ?? null,
            ];
        }

        $installArtifacts = self::arrayField($installEvidence, ['artifacts']) ?? [];
        foreach (array_keys($installChannels) as $artifact) {
            $entry = self::artifactInstallEntry($installArtifacts, (string) $artifact);
            if ($entry === null) {
                $failures[] = [
                    'code' => 'missing_published_artifact_install_evidence_artifact',
                    'scenario_id' => 'published_artifact_install_only',
                    'artifact' => $artifact,
                    'field' => 'artifact_install_evidence.artifacts',
                ];
                continue;
            }

            $status = strtolower(self::stringField($entry, ['status', 'result', 'outcome']));
            if ($status !== 'pass') {
                $failures[] = [
                    'code' => 'published_artifact_install_evidence_not_pass',
                    'scenario_id' => 'published_artifact_install_only',
                    'artifact' => $artifact,
                    'status' => $status,
                    'field' => 'artifact_install_evidence.artifacts.status',
                ];
            }

            $source = self::stringField($entry, ['source', 'install_source', 'installSource', 'artifact_source', 'artifactSource']);
            if ($source === '') {
                $failures[] = [
                    'code' => 'missing_published_artifact_install_evidence_source',
                    'scenario_id' => 'published_artifact_install_only',
                    'artifact' => $artifact,
                    'field' => 'artifact_install_evidence.artifacts.source',
                ];
            } elseif (self::isForbiddenArtifactSource($source, $forbiddenInstallSources)) {
                $failures[] = [
                    'code' => 'forbidden_published_artifact_install_evidence_source',
                    'scenario_id' => 'published_artifact_install_only',
                    'artifact' => $artifact,
                    'source' => $source,
                    'field' => 'artifact_install_evidence.artifacts.source',
                ];
            }

            if (self::truthyField($entry, [
                'local_product_source_checkouts_used',
                'localProductSourceCheckoutsUsed',
            ])) {
                $failures[] = [
                    'code' => 'local_product_source_checkouts_used_must_be_false',
                    'scenario_id' => 'published_artifact_install_only',
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
     * @param list<string> $forbiddenSources
     */
    private static function isForbiddenArtifactSource(string $source, array $forbiddenSources): bool
    {
        $source = strtolower(trim($source));
        if ($source === '') {
            return false;
        }

        $normalizedSource = self::normalizeToken($source);

        foreach ($forbiddenSources as $forbiddenSource) {
            $forbiddenSource = strtolower(trim($forbiddenSource));
            if ($forbiddenSource === '') {
                continue;
            }

            if ($source === $forbiddenSource || str_contains($source, $forbiddenSource)) {
                return true;
            }

            $normalizedForbidden = self::normalizeToken($forbiddenSource);
            if ($normalizedForbidden !== ''
                && ($normalizedSource === $normalizedForbidden || str_contains($normalizedSource, $normalizedForbidden))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $installArtifacts
     * @return array<string, mixed>|null
     */
    private static function artifactInstallEntry(array $installArtifacts, string $artifact): ?array
    {
        foreach ($installArtifacts as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $reported = self::stringField($entry, ['artifact', 'name', 'id']);
            if ($reported === $artifact) {
                return $entry;
            }
        }

        return null;
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
            $expectedReplayWorkerBuildId = self::stringField($cacheEviction, [
                'expected_replay_worker_build_id',
                'expectedReplayWorkerBuildId',
                'pinned_run_build_id',
                'pinnedRunBuildId',
            ]) ?: $pinnedBuildId;

            if (
                $expectedReplayWorkerBuildId !== ''
                && $replayWorkerBuildId !== ''
                && $replayWorkerBuildId !== $expectedReplayWorkerBuildId
            ) {
                $failures[] = [
                    'code' => 'replay_worker_build_id_mismatch',
                    'scenario_id' => 'replay_across_cache_eviction',
                    'field' => 'replay_worker_build_id',
                    'expected' => $expectedReplayWorkerBuildId,
                    'actual' => $replayWorkerBuildId,
                ];
            }
        }

        $noCompatible = self::passingScenarioEvidence($scenarioResults, 'no_compatible_worker_behavior');
        if ($noCompatible !== null) {
            self::requireZeroCount(
                $failures,
                $noCompatible,
                'no_compatible_worker_behavior',
                'incompatible_worker_task_count',
                self::evidenceFieldAliases(
                    'no_compatible_worker_behavior',
                    'incompatible_worker_task_count',
                ),
            );
            self::requirePositiveCount(
                $failures,
                $noCompatible,
                'no_compatible_worker_behavior',
                'incompatible_worker_poll_attempts',
                'incompatible_worker_poll_attempts_not_positive',
            );
            self::requireTruthyField(
                $failures,
                $noCompatible,
                'no_compatible_worker_behavior',
                'compatible_worker_deregistered',
            );

            $operatorSignal = self::stringField($noCompatible, [
                ...self::evidenceFieldAliases('no_compatible_worker_behavior', 'operator_visible_signal'),
            ]);
            if (! self::isExplicitNoCompatibleSignal($operatorSignal)) {
                $failures[] = [
                    'code' => 'no_compatible_worker_signal_not_explicit',
                    'scenario_id' => 'no_compatible_worker_behavior',
                    'field' => 'operator_visible_signal',
                    'expected' => [
                        'no_compatible_worker',
                        'compatibility_blocked',
                        'compatibility_unsupported',
                    ],
                    'actual' => $operatorSignal,
                ];
            }

            $pendingOrTypedError = self::stringField($noCompatible, [
                ...self::evidenceFieldAliases('no_compatible_worker_behavior', 'pending_or_typed_error'),
            ]);
            if (
                $pendingOrTypedError !== 'pending'
                && ! self::isExplicitNoCompatibleSignal($pendingOrTypedError)
            ) {
                $failures[] = [
                    'code' => 'no_compatible_worker_pending_or_typed_error_not_explicit',
                    'scenario_id' => 'no_compatible_worker_behavior',
                    'field' => 'pending_or_typed_error',
                    'expected' => [
                        'pending',
                        'no_compatible_worker',
                        'compatibility_blocked',
                        'compatibility_unsupported',
                    ],
                    'actual' => $pendingOrTypedError,
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
     * @param array<string, array<string, mixed>> $scenarioResults
     * @param array<string, mixed> $result
     *
     * @return array<int, array<string, mixed>>
     */
    private static function publishedWorkerExecutionEvidenceFailures(array $scenarioResults, array $result): array
    {
        $failures = [];
        $topLevelWorkerEvidence = self::arrayField($result, [
            'published_worker_execution_evidence',
            'publishedWorkerExecutionEvidence',
            'published_worker_evidence',
            'publishedWorkerEvidence',
        ]);
        $scenarios = [
            'replay_only_by_compatible_workers' => [
                'published_artifact_worker_execution',
                'divergent_workflow_execution_observed',
            ],
            'replay_across_cache_eviction' => [
                'published_artifact_worker_execution',
                'divergent_workflow_execution_observed',
            ],
            'cross_language_php_python_pinning' => [
                'published_artifact_worker_execution',
            ],
            'adversarial_no_version_bump' => [
                'published_artifact_worker_execution',
            ],
        ];

        foreach ($scenarios as $scenarioId => $requiredTrueFields) {
            $evidence = self::passingScenarioEvidence($scenarioResults, $scenarioId);
            if ($evidence === null) {
                continue;
            }

            if ($topLevelWorkerEvidence === null
                || ! self::hasExplicitFalseField($topLevelWorkerEvidence, [
                    'local_product_source_checkouts_used',
                    'localProductSourceCheckoutsUsed',
                ])
                || self::truthyField($topLevelWorkerEvidence, [
                    'supplied_shard_local_product_source_checkouts_used',
                    'suppliedShardLocalProductSourceCheckoutsUsed',
                ])) {
                $failures[] = [
                    'code' => 'local_product_source_checkouts_used_must_be_false',
                    'scenario_id' => $scenarioId,
                    'field' => 'published_worker_execution_evidence.local_product_source_checkouts_used',
                    'value' => $topLevelWorkerEvidence['local_product_source_checkouts_used']
                        ?? $topLevelWorkerEvidence['localProductSourceCheckoutsUsed']
                        ?? $topLevelWorkerEvidence['supplied_shard_local_product_source_checkouts_used']
                        ?? $topLevelWorkerEvidence['suppliedShardLocalProductSourceCheckoutsUsed']
                        ?? null,
                ];
            }

            foreach ($requiredTrueFields as $field) {
                if ($field === 'published_artifact_worker_execution') {
                    array_push(
                        $failures,
                        ...self::publishedWorkerExecutionFieldFailures(
                            $scenarioId,
                            $evidence,
                            $scenarioId === 'cross_language_php_python_pinning',
                        ),
                    );

                    continue;
                }

                $aliases = [$field, self::camelize($field)];
                if (self::fieldExists($evidence, $aliases) && self::truthyField($evidence, $aliases)) {
                    continue;
                }

                $failures[] = [
                    'code' => $field === 'divergent_workflow_execution_observed'
                        ? 'divergent_workflow_execution_not_observed'
                        : 'published_artifact_worker_execution_missing',
                    'scenario_id' => $scenarioId,
                    'field' => $field,
                    'expected' => true,
                    'actual' => $evidence[$field] ?? $evidence[self::camelize($field)] ?? null,
                ];
            }
        }

        $noCompatible = self::passingScenarioEvidence($scenarioResults, 'no_compatible_worker_behavior');
        if ($noCompatible !== null) {
            $execution = self::fieldValue(
                $noCompatible,
                self::evidenceFieldAliases('no_compatible_worker_behavior', 'published_artifact_worker_execution'),
            );

            if (is_array($execution)) {
                array_push(
                    $failures,
                    ...self::publishedWorkerExecutionFieldFailures(
                        'no_compatible_worker_behavior',
                        $noCompatible,
                        false,
                    ),
                );
            } else {
                array_push(
                    $failures,
                    ...self::publishedServerProtocolProbeFailures($noCompatible),
                );
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $evidence
     *
     * @return array<int, array<string, mixed>>
     */
    private static function publishedServerProtocolProbeFailures(array $evidence): array
    {
        $failures = [];

        if (! self::truthyField($evidence, ['published_server_protocol_probe', 'publishedServerProtocolProbe'])) {
            $failures[] = [
                'code' => 'no_compatible_worker_evidence_missing',
                'scenario_id' => 'no_compatible_worker_behavior',
                'field' => 'published_server_protocol_probe',
                'expected' => true,
                'actual' => $evidence['published_server_protocol_probe']
                    ?? $evidence['publishedServerProtocolProbe']
                    ?? null,
            ];
        }

        $workerExecutionMode = self::stringField($evidence, ['worker_execution_mode', 'workerExecutionMode']);
        if ($workerExecutionMode !== 'server_http_protocol_probe') {
            $failures[] = [
                'code' => 'no_compatible_worker_evidence_missing',
                'scenario_id' => 'no_compatible_worker_behavior',
                'field' => 'worker_execution_mode',
                'expected' => 'server_http_protocol_probe',
                'actual' => $workerExecutionMode,
            ];
        }

        if (! self::hasExplicitFalseField($evidence, [
            'local_product_source_checkouts_used',
            'localProductSourceCheckoutsUsed',
        ])) {
            $failures[] = [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'scenario_id' => 'no_compatible_worker_behavior',
                'field' => 'local_product_source_checkouts_used',
                'value' => $evidence['local_product_source_checkouts_used']
                    ?? $evidence['localProductSourceCheckoutsUsed']
                    ?? null,
            ];
        }

        $artifact = self::arrayField($evidence, ['published_server_artifact', 'publishedServerArtifact']);
        if ($artifact === null) {
            $failures[] = [
                'code' => 'published_server_protocol_probe_artifact_missing',
                'scenario_id' => 'no_compatible_worker_behavior',
                'field' => 'published_server_artifact',
            ];

            return $failures;
        }

        $reportedArtifact = self::stringField($artifact, ['artifact', 'name', 'id']);
        if ($reportedArtifact !== 'server') {
            $failures[] = [
                'code' => 'published_server_protocol_probe_artifact_mismatch',
                'scenario_id' => 'no_compatible_worker_behavior',
                'field' => 'published_server_artifact.artifact',
                'expected' => 'server',
                'actual' => $reportedArtifact,
            ];
        }

        $status = strtolower(self::stringField($artifact, ['status', 'result', 'outcome']));
        if ($status !== 'pass') {
            $failures[] = [
                'code' => 'published_server_protocol_probe_artifact_not_pass',
                'scenario_id' => 'no_compatible_worker_behavior',
                'field' => 'published_server_artifact.status',
                'expected' => 'pass',
                'actual' => $status,
            ];
        }

        $source = self::stringField($artifact, ['source', 'install_source', 'installSource', 'artifact_source', 'artifactSource']);
        if ($source === '') {
            $failures[] = [
                'code' => 'missing_published_server_protocol_probe_source',
                'scenario_id' => 'no_compatible_worker_behavior',
                'field' => 'published_server_artifact.source',
            ];
        } elseif (self::isForbiddenArtifactSource($source, [
            'local_product_source_checkout',
            'workspace_repo_as_artifact_under_test',
            'local_checkout_artifact',
            'local_checkout',
            'local_source_checkout',
            'workspace_repo',
            'not_exercised',
        ])) {
            $failures[] = [
                'code' => 'forbidden_published_server_protocol_probe_source',
                'scenario_id' => 'no_compatible_worker_behavior',
                'field' => 'published_server_artifact.source',
                'source' => $source,
            ];
        }

        $version = self::stringField($artifact, ['version', 'artifact_version', 'artifactVersion']);
        if ($version === '') {
            $failures[] = [
                'code' => 'missing_published_server_protocol_probe_version',
                'scenario_id' => 'no_compatible_worker_behavior',
                'field' => 'published_server_artifact.version',
            ];
        } elseif (! self::isConcreteArtifactVersion($version)) {
            $failures[] = [
                'code' => 'invalid_published_server_protocol_probe_version',
                'scenario_id' => 'no_compatible_worker_behavior',
                'field' => 'published_server_artifact.version',
                'version' => $version,
            ];
        } elseif (self::isPlaceholderVersion($version)) {
            $failures[] = [
                'code' => 'placeholder_published_server_protocol_probe_version',
                'scenario_id' => 'no_compatible_worker_behavior',
                'field' => 'published_server_artifact.version',
                'version' => $version,
            ];
        }

        if (self::truthyField($artifact, [
            'local_product_source_checkouts_used',
            'localProductSourceCheckoutsUsed',
        ])) {
            $failures[] = [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'scenario_id' => 'no_compatible_worker_behavior',
                'field' => 'published_server_artifact.local_product_source_checkouts_used',
                'value' => $artifact['local_product_source_checkouts_used']
                    ?? $artifact['localProductSourceCheckoutsUsed']
                    ?? null,
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $evidence
     *
     * @return array<int, array<string, mixed>>
     */
    private static function publishedWorkerExecutionFieldFailures(
        string $scenarioId,
        array $evidence,
        bool $requiresBothPhpAndPython,
    ): array {
        $failures = [];

        if (! self::hasExplicitFalseField($evidence, [
            'local_product_source_checkouts_used',
            'localProductSourceCheckoutsUsed',
        ])) {
            $failures[] = [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'scenario_id' => $scenarioId,
                'field' => 'local_product_source_checkouts_used',
                'value' => $evidence['local_product_source_checkouts_used']
                    ?? $evidence['localProductSourceCheckoutsUsed']
                    ?? null,
            ];
        }

        $execution = self::fieldValue($evidence, [
            ...self::evidenceFieldAliases($scenarioId, 'published_artifact_worker_execution'),
        ]);

        if (! is_array($execution)) {
            return [[
                'code' => 'published_artifact_worker_execution_missing',
                'scenario_id' => $scenarioId,
                'field' => 'published_artifact_worker_execution',
                'expected' => 'object_with_artifacts',
                'actual' => $execution,
            ]];
        }

        if (! self::hasExplicitFalseField($execution, [
            'local_product_source_checkouts_used',
            'localProductSourceCheckoutsUsed',
        ])) {
            $failures[] = [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'scenario_id' => $scenarioId,
                'field' => 'published_artifact_worker_execution.local_product_source_checkouts_used',
                'value' => $execution['local_product_source_checkouts_used']
                    ?? $execution['localProductSourceCheckoutsUsed']
                    ?? null,
            ];
        }

        $entries = self::publishedWorkerExecutionEntries($execution);
        if ($entries === []) {
            $failures[] = [
                'code' => 'published_artifact_worker_execution_missing_artifacts',
                'scenario_id' => $scenarioId,
                'field' => 'published_artifact_worker_execution.artifacts',
            ];

            return $failures;
        }

        $forbiddenSources = [
            'local_product_source_checkout',
            'workspace_repo_as_artifact_under_test',
            'local_checkout_artifact',
            'local_checkout',
            'local_source_checkout',
            'workspace_repo',
            'not_exercised',
        ];
        $validArtifacts = [];

        foreach ($entries as $index => $entry) {
            $artifact = self::canonicalWorkerArtifact(self::stringField($entry, ['artifact', 'name', 'id']));
            if (! in_array($artifact, ['sdk-python', 'sdk-php'], true)) {
                $failures[] = [
                    'code' => 'unsupported_published_worker_execution_artifact',
                    'scenario_id' => $scenarioId,
                    'field' => sprintf('published_artifact_worker_execution.artifacts.%d.artifact', $index),
                    'artifact' => self::stringField($entry, ['artifact', 'name', 'id']),
                ];

                continue;
            }

            $status = strtolower(self::stringField($entry, ['status', 'result', 'outcome']));
            if ($status !== 'pass') {
                $failures[] = [
                    'code' => 'published_artifact_worker_execution_not_pass',
                    'scenario_id' => $scenarioId,
                    'artifact' => $artifact,
                    'status' => $status,
                    'field' => sprintf('published_artifact_worker_execution.artifacts.%d.status', $index),
                ];
            }

            $source = self::stringField($entry, ['source', 'install_source', 'installSource', 'artifact_source', 'artifactSource']);
            if ($source === '') {
                $failures[] = [
                    'code' => 'missing_published_artifact_worker_execution_source',
                    'scenario_id' => $scenarioId,
                    'artifact' => $artifact,
                    'field' => sprintf('published_artifact_worker_execution.artifacts.%d.source', $index),
                ];
            } elseif (self::isForbiddenArtifactSource($source, $forbiddenSources)) {
                $failures[] = [
                    'code' => 'forbidden_published_artifact_worker_execution_source',
                    'scenario_id' => $scenarioId,
                    'artifact' => $artifact,
                    'source' => $source,
                    'field' => sprintf('published_artifact_worker_execution.artifacts.%d.source', $index),
                ];
            }

            $version = self::stringField($entry, ['version', 'artifact_version', 'artifactVersion']);
            if ($version === '') {
                $failures[] = [
                    'code' => 'missing_published_artifact_worker_execution_version',
                    'scenario_id' => $scenarioId,
                    'artifact' => $artifact,
                    'field' => sprintf('published_artifact_worker_execution.artifacts.%d.version', $index),
                ];
            } elseif (! self::isConcreteArtifactVersion($version)) {
                $failures[] = [
                    'code' => 'invalid_published_artifact_worker_execution_version',
                    'scenario_id' => $scenarioId,
                    'artifact' => $artifact,
                    'version' => $version,
                    'field' => sprintf('published_artifact_worker_execution.artifacts.%d.version', $index),
                ];
            } elseif (self::isPlaceholderVersion($version)) {
                $failures[] = [
                    'code' => 'placeholder_published_artifact_worker_execution_version',
                    'scenario_id' => $scenarioId,
                    'artifact' => $artifact,
                    'version' => $version,
                    'field' => sprintf('published_artifact_worker_execution.artifacts.%d.version', $index),
                ];
            }

            if (self::truthyField($entry, [
                'local_product_source_checkouts_used',
                'localProductSourceCheckoutsUsed',
            ])) {
                $failures[] = [
                    'code' => 'local_product_source_checkouts_used_must_be_false',
                    'scenario_id' => $scenarioId,
                    'artifact' => $artifact,
                    'field' => sprintf('published_artifact_worker_execution.artifacts.%d.local_product_source_checkouts_used', $index),
                    'value' => $entry['local_product_source_checkouts_used']
                        ?? $entry['localProductSourceCheckoutsUsed']
                        ?? null,
                ];
            }

            if (
                $status === 'pass'
                && $source !== ''
                && ! self::isForbiddenArtifactSource($source, $forbiddenSources)
                && $version !== ''
                && self::isConcreteArtifactVersion($version)
                && ! self::isPlaceholderVersion($version)
                && ! self::truthyField($entry, [
                    'local_product_source_checkouts_used',
                    'localProductSourceCheckoutsUsed',
                ])
            ) {
                $validArtifacts[$artifact] = true;
            }
        }

        $requiredArtifacts = $requiresBothPhpAndPython
            ? ['sdk-php', 'sdk-python']
            : ['sdk-php', 'sdk-python'];
        $missingArtifacts = $requiresBothPhpAndPython
            ? array_values(array_filter(
                $requiredArtifacts,
                static fn (string $artifact): bool => ! isset($validArtifacts[$artifact]),
            ))
            : (array_intersect(array_keys($validArtifacts), $requiredArtifacts) === [] ? ['sdk-php-or-sdk-python'] : []);

        foreach ($missingArtifacts as $artifact) {
            $failures[] = [
                'code' => 'missing_required_published_worker_execution_artifact',
                'scenario_id' => $scenarioId,
                'artifact' => $artifact,
                'field' => 'published_artifact_worker_execution.artifacts',
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $execution
     * @return list<array<string, mixed>>
     */
    private static function publishedWorkerExecutionEntries(array $execution): array
    {
        $entries = self::arrayField($execution, ['artifacts', 'workers', 'executions']);
        if ($entries !== null) {
            return array_values(array_filter(
                $entries,
                static fn (mixed $entry): bool => is_array($entry),
            ));
        }

        if (self::fieldExists($execution, ['artifact', 'name', 'source'])) {
            return [$execution];
        }

        return [];
    }

    private static function canonicalWorkerArtifact(string $artifact): string
    {
        return match (self::normalizeRuntimeSurface($artifact)) {
            'sdkpython' => 'sdk-python',
            'sdkphp' => 'sdk-php',
            default => strtolower(str_replace('_', '-', trim($artifact))),
        };
    }

    /**
     * @param array<int, array<string, mixed>> $failures
     * @param array<string, mixed> $evidence
     */
    private static function requireZeroCount(
        array &$failures,
        array $evidence,
        string $scenarioId,
        string $field,
        ?array $aliases = null,
    ): void
    {
        $aliases ??= [$field, self::camelize($field)];
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
        $aliases = self::evidenceFieldAliases($scenarioId, $field);
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
        $aliases = self::evidenceFieldAliases($scenarioId, $field);
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

    private static function isExplicitNoCompatibleSignal(string $signal): bool
    {
        $normalized = (string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($signal)));
        $normalized = trim($normalized, '_');

        if ($normalized === '') {
            return false;
        }

        foreach (['no_compatible_worker', 'compatibility_blocked', 'compatibility_unsupported'] as $token) {
            if (str_contains($normalized, $token)) {
                return true;
            }
        }

        return false;
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
    private static function hasEvidenceField(array $scenarioResult, string $field, string $scenarioId): bool
    {
        $aliases = self::evidenceFieldAliases($scenarioId, $field);
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
     * @return list<string>
     */
    private static function evidenceFieldAliases(string $scenarioId, string $field): array
    {
        $aliases = [$field, self::camelize($field)];

        if ($scenarioId === 'no_compatible_worker_behavior') {
            $aliases = match ($field) {
                'incompatible_worker_task_count' => [
                    ...$aliases,
                    'incompatible_task_count',
                    'incompatibleTaskCount',
                    'incompatible_delivery_count',
                    'incompatibleDeliveryCount',
                    'v2_worker_task_count_for_v1_run',
                    'v2WorkerTaskCountForV1Run',
                ],
                'incompatible_worker_poll_attempts' => [
                    ...$aliases,
                    'incompatible_poll_attempts',
                    'incompatiblePollAttempts',
                    'poll_attempts',
                    'pollAttempts',
                ],
                'compatible_worker_deregistered' => [
                    ...$aliases,
                    'compatible_worker_stopped',
                    'compatibleWorkerStopped',
                    'compatible_cohort_stopped',
                    'compatibleCohortStopped',
                ],
                'operator_visible_signal' => [
                    ...$aliases,
                    'public_diagnostic',
                    'publicDiagnostic',
                    'diagnostic',
                    'typed_error',
                    'typedError',
                    'poll_status',
                    'pollStatus',
                    'compatibility_status',
                    'compatibilityStatus',
                ],
                'pending_or_typed_error' => [
                    ...$aliases,
                    'pending_state',
                    'pendingState',
                    'typed_error',
                    'typedError',
                    'poll_status',
                    'pollStatus',
                    'compatibility_status',
                    'compatibilityStatus',
                ],
                'published_artifact_worker_execution' => [
                    ...$aliases,
                    'published_worker_execution',
                    'publishedWorkerExecution',
                    'published_artifact_execution',
                    'publishedArtifactExecution',
                ],
                default => $aliases,
            };
        }

        return array_values(array_unique($aliases));
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
            'php' => 'sdkphp',
            'phpworker' => 'sdkphp',
            'phpruntime' => 'sdkphp',
            'sdkphp' => 'sdkphp',
            'sdkphpworker' => 'sdkphp',
            'sdkphpruntime' => 'sdkphp',
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
            'phpclient' => 'sdkphp',
            'phpsdk' => 'sdkphp',
            'sdkphp' => 'sdkphp',
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
    private static function fieldValue(array $value, array $fields): mixed
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $value)) {
                return $value[$field];
            }
        }

        return null;
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
