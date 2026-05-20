<?php

namespace App\Support;

/**
 * Evaluates search-attributes conformance results against the full parity
 * matrix exposed by SearchAttributeRuntimeContract.
 */
final class SearchAttributeRuntimeResultGate
{
    public const SCHEMA = 'durable-workflow.v2.search-attribute-runtime.result-gate';

    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'evaluates_result_schema' => SearchAttributeRuntimeContract::RESULT_SCHEMA,
            'scenario_statuses_source' => 'search_attribute_runtime_contract.scenario_statuses',
            'required_scenarios_source' => 'search_attribute_runtime_contract.required_scenarios',
            'required_matrix_source' => 'search_attribute_runtime_contract.required_matrix',
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
            'declared_outcomes_source' => 'search_attribute_runtime_contract.coverage_gate.*_outcome',
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
                'runtime_and_cross_language_cells_are_reported',
                'cli_waterline_codec_load_grammar_and_injection_sections_are_reported',
                'query_verdict_expected_and_actual_counts_match',
                'query_injection_required_rejection_probes_are_reported',
                'each_pass_scenario_has_observed_outputs',
                'each_pass_scenario_has_scenario_specific_evidence',
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
        $contract ??= SearchAttributeRuntimeContract::manifest();

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
                'reason' => 'Python/server smoke coverage is not a complete search-attributes conformance result.',
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
            'query_verdicts',
            'queryVerdicts',
            'latency_distribution',
            'latencyDistribution',
            'waterline_operator_visibility',
            'waterlineOperatorVisibility',
            'codec_round_trips',
            'codecRoundTrips',
            'adversarial_queries',
            'adversarialQueries',
            'load_profile',
            'loadProfile',
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

        foreach (['runtime_cells', 'cross_language_cells'] as $cellGroup) {
            foreach ($contractMatrix[$cellGroup] ?? [] as $requiredCell) {
                if (! is_array($requiredCell) || self::matrixHasCell($matrix, $cellGroup, $requiredCell)) {
                    continue;
                }

                $failures[] = [
                    'code' => 'missing_required_matrix_cell',
                    'cell_group' => $cellGroup,
                    'scenario' => $requiredCell['scenario'] ?? null,
                    'worker' => $requiredCell['worker'] ?? $requiredCell['writer'] ?? null,
                    'clients' => $requiredCell['clients'] ?? $requiredCell['readers'] ?? [],
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

            $reportedRuntime = self::runtimeField($reportedCell, ['worker', 'writer', 'runtime']);
            $requiredRuntime = self::stringValue($requiredCell['worker'] ?? $requiredCell['writer'] ?? null);
            if (! self::sameRuntime($reportedRuntime, $requiredRuntime)) {
                continue;
            }

            $reportedClients = self::stringList($reportedCell['clients'] ?? $reportedCell['readers'] ?? []);
            $requiredClients = self::stringList($requiredCell['clients'] ?? $requiredCell['readers'] ?? []);
            if (array_diff($requiredClients, $reportedClients) === []) {
                return true;
            }
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
            'topology' => [
                'schema_definition_and_reserved_name_refusal',
                'namespace_isolation',
            ],
            'query_verdicts' => [
                'equality_range_bool_query_behavior',
                'or_not_query_grammar',
                'keyword_list_membership',
            ],
            'type_safety_errors' => [
                'type_safety_wrong_literal',
                'undefined_key_rejection',
            ],
            'latency_distribution' => [
                'indexing_latency_distribution',
            ],
            'load_profile' => [
                'load_and_bounded_latency',
            ],
            'waterline_operator_visibility' => [
                'waterline_operator_visibility',
            ],
            'codec_round_trips' => [
                'python_to_php_codec_round_trip',
                'php_to_python_codec_round_trip',
            ],
            'adversarial_queries' => [
                'query_injection_hardening',
            ],
        ];

        $failures = [];
        foreach ($sections as $section => $scenarios) {
            $sectionValue = self::sectionValue($result, $section);
            if ($sectionValue !== null && $sectionValue !== []) {
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
                    $result,
                    $contract,
                    $scenarioResults['published_artifact_install_only'],
                ),
            );
        }

        if (self::isPassScenario($scenarioResults, 'schema_definition_and_reserved_name_refusal')) {
            array_push(
                $failures,
                ...self::schemaDefinitionEvidenceFailures(
                    $result,
                    $contract,
                    $scenarioResults['schema_definition_and_reserved_name_refusal'],
                ),
            );
        }

        foreach ([
            'python_worker_start_and_upsert_visibility' => 'sdk-python',
            'php_worker_start_and_upsert_visibility' => 'workflow-php',
        ] as $scenarioId => $runtime) {
            if (self::isPassScenario($scenarioResults, $scenarioId)) {
                array_push(
                    $failures,
                    ...self::workerVisibilityEvidenceFailures($scenarioId, $scenarioResults[$scenarioId], $runtime),
                );
            }
        }

        if (self::isPassScenario($scenarioResults, 'cli_query_and_error_surface')) {
            array_push(
                $failures,
                ...self::cliSurfaceEvidenceFailures(
                    self::scenarioEvidence($result, $scenarioResults['cli_query_and_error_surface'], 'cli_surface'),
                ),
            );
        }

        foreach ([
            'python_to_php_codec_round_trip' => 'python_to_php',
            'php_to_python_codec_round_trip' => 'php_to_python',
        ] as $scenarioId => $direction) {
            if (self::isPassScenario($scenarioResults, $scenarioId)) {
                array_push(
                    $failures,
                    ...self::codecRoundTripEvidenceFailures(
                        $result,
                        $contract,
                        $scenarioResults[$scenarioId],
                        $scenarioId,
                        $direction,
                    ),
                );
            }
        }

        if (self::isPassScenario($scenarioResults, 'indexing_latency_distribution')) {
            array_push(
                $failures,
                ...self::latencyEvidenceFailures(
                    self::sectionValue($result, 'latency_distribution') ?? [],
                    $contract,
                    'indexing_latency_distribution',
                ),
            );
        }

        if (self::isPassScenario($scenarioResults, 'load_and_bounded_latency')) {
            array_push(
                $failures,
                ...self::loadEvidenceFailures(
                    self::sectionValue($result, 'load_profile') ?? [],
                    $contract,
                ),
            );
        }

        if (self::isPassScenario($scenarioResults, 'equality_range_bool_query_behavior')
            || self::isPassScenario($scenarioResults, 'or_not_query_grammar')
            || self::isPassScenario($scenarioResults, 'keyword_list_membership')) {
            array_push($failures, ...self::queryVerdictFailures(self::sectionValue($result, 'query_verdicts') ?? []));
        }

        if (self::isPassScenario($scenarioResults, 'query_injection_hardening')) {
            array_push(
                $failures,
                ...self::adversarialEvidenceFailures(
                    self::sectionValue($result, 'adversarial_queries') ?? [],
                    $contract,
                ),
            );
        }

        if (self::isPassScenario($scenarioResults, 'waterline_operator_visibility')) {
            array_push(
                $failures,
                ...self::waterlineEvidenceFailures(self::sectionValue($result, 'waterline_operator_visibility') ?? []),
            );
        }

        if (self::isPassScenario($scenarioResults, 'type_safety_wrong_literal')
            || self::isPassScenario($scenarioResults, 'undefined_key_rejection')) {
            array_push(
                $failures,
                ...self::typeSafetyEvidenceFailures(
                    self::sectionValue($result, 'type_safety_errors') ?? [],
                    $scenarioResults,
                ),
            );
        }

        if (self::isPassScenario($scenarioResults, 'namespace_isolation')) {
            array_push(
                $failures,
                ...self::namespaceIsolationEvidenceFailures(
                    self::scenarioEvidence($result, $scenarioResults['namespace_isolation'], 'namespace_isolation'),
                ),
            );
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
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function publishedArtifactInstallEvidenceFailures(
        array $result,
        array $contract,
        array $scenarioResult,
    ): array {
        $outputs = self::scenarioOutputs($scenarioResult);
        $sources = self::arrayField($result, ['artifact_sources', 'artifactSources'])
            ?? self::arrayField($outputs, ['artifact_sources', 'artifactSources', 'install_sources', 'installSources'])
            ?? [];
        $artifactPolicy = self::arrayValue($contract, 'artifact_policy') ?? [];
        $installChannels = self::arrayValue($artifactPolicy, 'install_channels') ?? [];
        $forbiddenSources = self::stringList($artifactPolicy['forbidden_sources'] ?? []);
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

            if (self::isPlaceholderEvidence($source)) {
                $failures[] = [
                    'code' => 'placeholder_published_artifact_install_source',
                    'scenario_id' => 'published_artifact_install_only',
                    'artifact' => $artifact,
                    'source' => $source,
                ];
            }

            if (in_array($source, $forbiddenSources, true)) {
                $failures[] = [
                    'code' => 'forbidden_published_artifact_install_source',
                    'scenario_id' => 'published_artifact_install_only',
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
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function schemaDefinitionEvidenceFailures(
        array $result,
        array $contract,
        array $scenarioResult,
    ): array {
        $outputs = self::scenarioOutputs($scenarioResult);
        $topology = self::sectionValue($result, 'topology') ?? [];
        $definitions = self::arrayField(
            $outputs,
            ['schema_definitions', 'schemaDefinitions', 'schema_keys', 'schemaKeys'],
        ) ?? self::arrayField($topology, ['schema_definitions', 'schemaDefinitions', 'schema_keys', 'schemaKeys']) ?? [];
        $refusals = self::arrayField($outputs, ['reserved_name_refusals', 'reservedNameRefusals'])
            ?? self::arrayField($topology, ['reserved_name_refusals', 'reservedNameRefusals'])
            ?? [];
        $requirements = self::arrayValue($contract['scenario_requirements'] ?? [], 'schema_definition_and_reserved_name_refusal')
            ?? [];
        $failures = [];

        foreach (self::stringList($requirements['required_types'] ?? []) as $type) {
            if (! self::schemaDefinitionsIncludeType($definitions, $type)) {
                $failures[] = [
                    'code' => 'missing_schema_type_evidence',
                    'scenario_id' => 'schema_definition_and_reserved_name_refusal',
                    'type' => $type,
                ];
            }
        }

        foreach (self::stringList($requirements['reserved_name_refusals'] ?? []) as $name) {
            if (! self::reservedRefusalsIncludeName($refusals, $name)) {
                $failures[] = [
                    'code' => 'missing_reserved_name_refusal_evidence',
                    'scenario_id' => 'schema_definition_and_reserved_name_refusal',
                    'name' => $name,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function workerVisibilityEvidenceFailures(
        string $scenarioId,
        array $scenarioResult,
        string $expectedRuntime,
    ): array {
        $outputs = self::scenarioOutputs($scenarioResult);
        $failures = [];

        foreach ([
            'workflow_id' => ['workflow_id', 'workflowId'],
            'start_search_attributes' => ['start_search_attributes', 'startSearchAttributes'],
            'upserted_search_attributes' => ['upserted_search_attributes', 'upsertedSearchAttributes'],
        ] as $field => $aliases) {
            if (! self::hasNonEmptyField($outputs, $aliases)) {
                $failures[] = [
                    'code' => 'missing_worker_visibility_evidence',
                    'scenario_id' => $scenarioId,
                    'field' => $field,
                ];
            }
        }

        $runtime = self::runtimeField($outputs, ['worker', 'worker_runtime', 'workerRuntime', 'runtime']);
        if (! self::sameRuntime($runtime, $expectedRuntime)) {
            $failures[] = [
                'code' => 'missing_worker_visibility_evidence',
                'scenario_id' => $scenarioId,
                'field' => 'worker_runtime',
                'expected_runtime' => $expectedRuntime,
            ];
        }

        if (! self::hasTruthyField($outputs, ['visibility_query_match', 'visibilityQueryMatch'])) {
            $failures[] = [
                'code' => 'missing_worker_visibility_evidence',
                'scenario_id' => $scenarioId,
                'field' => 'visibility_query_match',
            ];
        }

        return $failures;
    }

    /**
     * @param array<mixed> $section
     *
     * @return array<int, array<string, mixed>>
     */
    private static function cliSurfaceEvidenceFailures(array $section): array
    {
        $failures = [];

        foreach ([
            'workflow_list_query' => ['workflow_list_query', 'workflowListQuery'],
            'search_attribute_list' => ['search_attribute_list', 'searchAttributeList'],
            'search_attribute_create' => ['search_attribute_create', 'searchAttributeCreate'],
            'search_attribute_delete' => ['search_attribute_delete', 'searchAttributeDelete'],
        ] as $field => $aliases) {
            if (! self::hasNonEmptyField($section, $aliases)) {
                $failures[] = [
                    'code' => 'missing_cli_surface_evidence',
                    'scenario_id' => 'cli_query_and_error_surface',
                    'field' => $field,
                ];
            }
        }

        if (! self::hasTruthyField($section, ['typed_error_observed', 'typedErrorObserved'])) {
            $failures[] = [
                'code' => 'missing_cli_surface_evidence',
                'scenario_id' => 'cli_query_and_error_surface',
                'field' => 'typed_error_observed',
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function codecRoundTripEvidenceFailures(
        array $result,
        array $contract,
        array $scenarioResult,
        string $scenarioId,
        string $direction,
    ): array {
        $section = self::sectionValue($result, 'codec_round_trips') ?? [];
        $outputs = self::scenarioOutputs($scenarioResult);
        $entry = self::arrayValue($section, $direction)
            ?? self::arrayValue($section, self::camelize($direction))
            ?? self::arrayValue($outputs, $direction)
            ?? self::arrayValue($outputs, self::camelize($direction))
            ?? $outputs;
        $failures = [];

        if (! self::hasNonEmptyField($entry, ['encoded_payload', 'encodedPayload', 'codec_payload', 'codecPayload'])) {
            $failures[] = [
                'code' => 'missing_codec_round_trip_field',
                'scenario_id' => $scenarioId,
                'field' => 'encoded_payload',
            ];
        }

        $decoded = self::arrayField($entry, ['decoded_attributes', 'decodedAttributes', 'attributes']);
        if ($decoded === null || $decoded === []) {
            $failures[] = [
                'code' => 'missing_codec_round_trip_field',
                'scenario_id' => $scenarioId,
                'field' => 'decoded_attributes',
            ];
        } else {
            foreach (self::schemaKeyNames($contract) as $attribute) {
                if (! array_key_exists($attribute, $decoded)) {
                    $failures[] = [
                        'code' => 'missing_codec_decoded_attribute',
                        'scenario_id' => $scenarioId,
                        'attribute' => $attribute,
                    ];
                }
            }
        }

        foreach (self::requiredReadersForScenario($contract, $scenarioId) as $reader) {
            if (! self::codecEntryHasReaderEvidence($entry, $reader)) {
                $failures[] = [
                    'code' => 'missing_codec_reader_evidence',
                    'scenario_id' => $scenarioId,
                    'reader' => $reader,
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
    private static function latencyEvidenceFailures(array $section, array $contract, string $scenarioId): array
    {
        $requirements = self::arrayValue($contract['scenario_requirements'] ?? [], $scenarioId) ?? [];
        $requiredSampleCount = (int) ($requirements['sample_count_minimum'] ?? 20);
        $requiredFields = self::stringList($requirements['required_distribution_fields'] ?? []);
        $failures = [];

        $sampleCount = self::intField($section, ['sample_count', 'sampleCount', 'samples']);
        if ($sampleCount < $requiredSampleCount) {
            $failures[] = [
                'code' => 'latency_sample_count_below_required',
                'scenario_id' => $scenarioId,
                'required' => $requiredSampleCount,
                'actual' => $sampleCount,
            ];
        }

        foreach ($requiredFields as $field) {
            if (! self::hasNumericField($section, [$field, self::camelize($field)])) {
                $failures[] = [
                    'code' => 'missing_latency_distribution_field',
                    'scenario_id' => $scenarioId,
                    'field' => $field,
                ];
            }
        }

        if (($requirements['documented_bound_required'] ?? false) === true
            && ! self::hasNumericField($section, ['documented_bound_ms', 'documentedBoundMs'])) {
            $failures[] = [
                'code' => 'missing_latency_documented_bound',
                'scenario_id' => $scenarioId,
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
    private static function loadEvidenceFailures(array $section, array $contract): array
    {
        $requirements = self::arrayValue($contract['scenario_requirements'] ?? [], 'load_and_bounded_latency') ?? [];
        $minimumWorkflowCount = (int) ($requirements['minimum_workflow_count'] ?? 1000);
        $failures = [];

        $workflowCount = self::intField($section, ['workflow_count', 'workflowCount', 'runs']);
        if ($workflowCount < $minimumWorkflowCount) {
            $failures[] = [
                'code' => 'load_workflow_count_below_required',
                'required' => $minimumWorkflowCount,
                'actual' => $workflowCount,
            ];
        }

        foreach (self::stringList($requirements['required_distribution_fields'] ?? []) as $field) {
            if (! self::hasNumericField($section, [$field, self::camelize($field)])) {
                $failures[] = [
                    'code' => 'missing_load_latency_field',
                    'field' => $field,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<mixed> $section
     *
     * @return array<int, array<string, mixed>>
     */
    private static function queryVerdictFailures(array $section): array
    {
        $queries = self::arrayValue($section, 'queries') ?? $section;
        $failures = [];

        foreach ([
            'equality',
            'range',
            'bool',
            'or',
            'not',
            'keyword_list',
        ] as $queryClass) {
            $verdict = self::arrayValue($queries, $queryClass) ?? [];
            if ($verdict === []) {
                $failures[] = [
                    'code' => 'missing_query_verdict',
                    'query_class' => $queryClass,
                ];
                continue;
            }

            foreach (['expected_count', 'actual_count'] as $field) {
                if (! self::hasNumericField($verdict, [$field, self::camelize($field)])) {
                    $failures[] = [
                        'code' => 'missing_query_count',
                        'query_class' => $queryClass,
                        'field' => $field,
                    ];
                }
            }

            $expectedCount = self::numericField($verdict, ['expected_count', 'expectedCount']);
            $actualCount = self::numericField($verdict, ['actual_count', 'actualCount']);
            if ($expectedCount !== null && $actualCount !== null && $expectedCount !== $actualCount) {
                $failures[] = [
                    'code' => 'query_count_mismatch',
                    'query_class' => $queryClass,
                    'expected_count' => $expectedCount,
                    'actual_count' => $actualCount,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<mixed> $section
     *
     * @return array<int, array<string, mixed>>
     */
    private static function adversarialEvidenceFailures(array $section, array $contract): array
    {
        $failures = [];
        if (! self::hasTruthyField($section, ['injection_rejected', 'injectionRejected'])) {
            $failures[] = [
                'code' => 'missing_injection_rejection_evidence',
            ];
        }

        $rejections = self::arrayField($section, ['rejections', 'rejected_inputs', 'rejectedInputs']) ?? [];
        if ($rejections === []) {
            $failures[] = [
                'code' => 'missing_injection_rejection_inputs',
            ];
        } else {
            foreach (self::stringList($rejections) as $rejection) {
                if (self::isPlaceholderEvidence($rejection)) {
                    $failures[] = [
                        'code' => 'placeholder_injection_rejection_input',
                        'input' => $rejection,
                    ];
                }
            }
        }

        $requirements = self::arrayValue($contract['scenario_requirements'] ?? [], 'query_injection_hardening') ?? [];
        foreach (self::stringList($requirements['required_rejections'] ?? []) as $probe) {
            if (self::injectionRejectionsCoverProbe($rejections, $probe)) {
                continue;
            }

            $failures[] = [
                'code' => 'missing_required_injection_rejection_probe',
                'probe' => $probe,
            ];
        }

        $partialExecution = self::boolField($section, ['partial_execution_observed', 'partialExecutionObserved']);
        if ($partialExecution === null) {
            $failures[] = [
                'code' => 'missing_partial_execution_evidence',
            ];
        } elseif ($partialExecution) {
            $failures[] = [
                'code' => 'query_injection_partially_executed',
            ];
        }

        return $failures;
    }

    /**
     * @param array<mixed> $section
     *
     * @return array<int, array<string, mixed>>
     */
    private static function waterlineEvidenceFailures(array $section): array
    {
        $failures = [];
        foreach ([
            'workflow_list_filter' => ['workflow_list_filter', 'workflowListFilter'],
            'selected_run_detail' => ['selected_run_detail', 'selectedRunDetail'],
            'saved_filter_state' => ['saved_filter_state', 'savedFilterState'],
        ] as $field => $aliases) {
            if (! self::hasTruthyField($section, $aliases)) {
                $failures[] = [
                    'code' => 'missing_waterline_operator_visibility_field',
                    'field' => $field,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<mixed> $section
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return array<int, array<string, mixed>>
     */
    private static function typeSafetyEvidenceFailures(array $section, array $scenarioResults): array
    {
        $failures = [];
        foreach ([
            'type_safety_wrong_literal' => [
                'field' => 'wrong_literal',
                'aliases' => ['wrong_literal', 'wrongLiteral'],
            ],
            'undefined_key_rejection' => [
                'field' => 'undefined_key',
                'aliases' => ['undefined_key', 'undefinedKey'],
            ],
        ] as $scenarioId => $config) {
            if (! self::isPassScenario($scenarioResults, $scenarioId)) {
                continue;
            }

            $outputs = self::scenarioOutputs($scenarioResults[$scenarioId]);
            $entry = self::firstFieldValue($section, $config['aliases'])
                ?? self::firstFieldValue($outputs, $config['aliases'])
                ?? self::firstFieldValue($outputs, ['typed_error', 'typedError', 'error', 'rejection'])
                ?? ($outputs !== [] ? $outputs : null);
            if ($entry === null || ! self::validTypedErrorEvidence($entry)) {
                $failures[] = [
                    'code' => 'missing_type_safety_error_evidence',
                    'scenario_id' => $scenarioId,
                    'field' => $config['field'],
                ];
                continue;
            }

            if (is_array($entry) && self::hasTruthyField($entry, ['accepted', 'coerced', 'coercion_observed', 'coercionObserved'])) {
                $failures[] = [
                    'code' => 'type_safety_probe_was_accepted',
                    'scenario_id' => $scenarioId,
                    'field' => $config['field'],
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<mixed> $section
     *
     * @return array<int, array<string, mixed>>
     */
    private static function namespaceIsolationEvidenceFailures(array $section): array
    {
        $failures = [];
        foreach ([
            'primary_namespace' => ['primary_namespace', 'primaryNamespace'],
            'peer_namespace' => ['peer_namespace', 'peerNamespace'],
        ] as $field => $aliases) {
            if (! self::hasNonEmptyField($section, $aliases)) {
                $failures[] = [
                    'code' => 'missing_namespace_isolation_field',
                    'scenario_id' => 'namespace_isolation',
                    'field' => $field,
                ];
            }
        }

        foreach ([
            'primary_query_count' => ['primary_query_count', 'primaryQueryCount'],
            'peer_query_count' => ['peer_query_count', 'peerQueryCount'],
        ] as $field => $aliases) {
            if (! self::hasNumericField($section, $aliases)) {
                $failures[] = [
                    'code' => 'missing_namespace_isolation_field',
                    'scenario_id' => 'namespace_isolation',
                    'field' => $field,
                ];
            }
        }

        $leakDetected = self::boolField($section, ['cross_namespace_leak_detected', 'crossNamespaceLeakDetected']);
        if ($leakDetected === null) {
            $failures[] = [
                'code' => 'missing_namespace_isolation_field',
                'scenario_id' => 'namespace_isolation',
                'field' => 'cross_namespace_leak_detected',
            ];
        } elseif ($leakDetected) {
            $failures[] = [
                'code' => 'namespace_isolation_leak_detected',
                'scenario_id' => 'namespace_isolation',
            ];
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
        if (count($scenarioStatuses) >= count($requiredScenarios)) {
            return false;
        }

        $coveredScenarios = array_keys(array_filter(
            $scenarioStatuses,
            static fn (string $status): bool => $status === 'pass',
        ));

        if ($coveredScenarios === []) {
            return false;
        }

        $fullParityScenarios = [
            'php_worker_start_and_upsert_visibility',
            'cli_query_and_error_surface',
            'waterline_operator_visibility',
            'python_to_php_codec_round_trip',
            'php_to_python_codec_round_trip',
            'or_not_query_grammar',
            'indexing_latency_distribution',
            'load_and_bounded_latency',
            'query_injection_hardening',
        ];

        return array_intersect($coveredScenarios, $fullParityScenarios) === [];
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function sectionValue(array $result, string $section): ?array
    {
        return self::arrayValue($result, $section)
            ?? self::arrayValue($result, self::camelize($section));
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
     * @param array<string, mixed> $result
     * @param array<string, mixed> $scenarioResult
     */
    private static function scenarioEvidence(array $result, array $scenarioResult, string $section): array
    {
        $sectionValue = self::sectionValue($result, $section);
        if ($sectionValue !== null && $sectionValue !== []) {
            return $sectionValue;
        }

        $outputs = self::scenarioOutputs($scenarioResult);
        $scenarioSection = self::arrayField($scenarioResult, [$section, self::camelize($section)])
            ?? self::arrayField($outputs, [$section, self::camelize($section)]);

        return $scenarioSection !== null && $scenarioSection !== [] ? $scenarioSection : $outputs;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<mixed>
     */
    private static function scenarioOutputs(array $scenarioResult): array
    {
        return self::arrayValue($scenarioResult, 'observed_outputs')
            ?? self::arrayValue($scenarioResult, 'observedOutputs')
            ?? [];
    }

    /**
     * @param array<mixed> $definitions
     */
    private static function schemaDefinitionsIncludeType(array $definitions, string $type): bool
    {
        foreach ($definitions as $key => $definition) {
            if (is_string($key) && self::stringValue($definition) === $type) {
                return true;
            }

            if (is_array($definition)) {
                $definitionType = self::stringValue($definition['type'] ?? $definition['value_type'] ?? null);
                if ($definitionType === $type) {
                    return true;
                }
            }

            if (self::stringValue($definition) === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $refusals
     */
    private static function reservedRefusalsIncludeName(array $refusals, string $name): bool
    {
        foreach ($refusals as $key => $refusal) {
            if (is_string($key) && $key === $name && self::nonEmptyValue($refusal)) {
                return true;
            }

            if (self::stringValue($refusal) === $name) {
                return true;
            }

            if (! is_array($refusal)) {
                continue;
            }

            $refusedName = self::firstStringField($refusal, ['name', 'key', 'reserved_name', 'reservedName']);
            if ($refusedName !== $name) {
                continue;
            }

            if (! self::hasTruthyField($refusal, ['accepted', 'acceptedReservedName'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $contract
     *
     * @return list<string>
     */
    private static function schemaKeyNames(array $contract): array
    {
        $schemaKeys = self::arrayValue($contract['topology'] ?? [], 'schema_keys') ?? [];
        $names = [];
        foreach ($schemaKeys as $key => $value) {
            $name = is_string($key) ? $key : self::stringValue($value);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $contract
     *
     * @return list<string>
     */
    private static function requiredReadersForScenario(array $contract, string $scenarioId): array
    {
        $cells = self::arrayValue($contract['required_matrix'] ?? [], 'cross_language_cells') ?? [];
        foreach ($cells as $cell) {
            if (! is_array($cell)) {
                continue;
            }

            if (self::stringValue($cell['scenario'] ?? null) === $scenarioId) {
                return self::stringList($cell['readers'] ?? []);
            }
        }

        return [];
    }

    /**
     * @param array<mixed> $entry
     */
    private static function codecEntryHasReaderEvidence(array $entry, string $reader): bool
    {
        if (in_array($reader, self::stringList($entry['readers'] ?? []), true)) {
            return true;
        }

        $verifications = self::arrayField($entry, ['reader_verifications', 'readerVerifications']) ?? [];
        foreach ([$reader, str_replace('-', '_', $reader)] as $key) {
            if (! array_key_exists($key, $verifications)) {
                continue;
            }

            $verification = $verifications[$key];
            if ($verification === true || $verification === 1 || $verification === '1' || $verification === 'true') {
                return true;
            }

            if (is_array($verification) && $verification !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $entry
     */
    private static function validTypedErrorEvidence(mixed $entry): bool
    {
        if (is_array($entry)) {
            return self::hasNonEmptyField($entry, ['error_code', 'errorCode', 'code'])
                && self::hasNonEmptyField($entry, ['message', 'error_message', 'errorMessage']);
        }

        $value = self::stringValue($entry);

        return $value !== '' && ! self::isPlaceholderEvidence($value);
    }

    private static function isPlaceholderEvidence(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return true;
        }

        if (preg_match('/<[^>]+>|\$\{[^}]+}|{{[^}]+}}/', $normalized) === 1) {
            return true;
        }

        return in_array($normalized, [
            '1',
            'true',
            'ok',
            'pass',
            'passed',
            'recorded',
            'placeholder',
            'todo',
            'tbd',
            'n/a',
            'none',
        ], true);
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $fields
     *
     * @return mixed
     */
    private static function firstFieldValue(array $value, array $fields): mixed
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $value)) {
                return $value[$field];
            }
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
     * @param array<mixed> $rejections
     */
    private static function injectionRejectionsCoverProbe(array $rejections, string $requiredProbe): bool
    {
        $requiredProbe = self::normalizeProbeLabel($requiredProbe);
        if ($requiredProbe === '') {
            return true;
        }

        foreach ($rejections as $key => $rejection) {
            if (is_string($key) && self::probeEvidenceMatches($key, $requiredProbe)) {
                return true;
            }

            if (self::probeEvidenceMatches(self::stringValue($rejection), $requiredProbe)) {
                return true;
            }

            if (! is_array($rejection)) {
                continue;
            }

            foreach ([
                'probe',
                'probe_name',
                'probeName',
                'case',
                'class',
                'kind',
                'input',
                'query',
                'rejected_input',
                'rejectedInput',
            ] as $field) {
                if (self::probeEvidenceMatches(self::stringValue($rejection[$field] ?? null), $requiredProbe)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function probeEvidenceMatches(string $evidence, string $requiredProbe): bool
    {
        $evidence = self::normalizeProbeLabel($evidence);
        if ($evidence === '') {
            return false;
        }

        if ($evidence === $requiredProbe || str_contains($evidence, $requiredProbe)) {
            return true;
        }

        return match ($requiredProbe) {
            'embedded sql comment' => str_contains($evidence, '--')
                || str_contains($evidence, '/*')
                || str_contains($evidence, '*/'),
            'shell metacharacters' => preg_match('/[;|&`]|\\$\\(/', $evidence) === 1,
            default => false,
        };
    }

    private static function normalizeProbeLabel(string $value): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim($value))) ?? '';
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
     */
    private static function boolField(array $value, array $fields): ?bool
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $value)) {
                continue;
            }

            $fieldValue = $value[$field];
            if ($fieldValue === true || $fieldValue === 1 || $fieldValue === '1' || $fieldValue === 'true') {
                return true;
            }
            if ($fieldValue === false || $fieldValue === 0 || $fieldValue === '0' || $fieldValue === 'false') {
                return false;
            }
        }

        return null;
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
     * @param list<string> $fields
     */
    private static function hasNumericField(array $value, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $value) && is_numeric($value[$field])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $fields
     */
    private static function numericField(array $value, array $fields): int|float|null
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $value) || ! is_numeric($value[$field])) {
                continue;
            }

            $number = $value[$field] + 0;

            return is_float($number) && floor($number) !== $number ? $number : (int) $number;
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

    private static function camelize(string $field): string
    {
        return preg_replace_callback(
            '/_([a-z])/',
            static fn (array $matches): string => strtoupper($matches[1]),
            $field,
        ) ?? $field;
    }
}
