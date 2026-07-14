<?php

namespace App\Support;

/**
 * Evaluates search-attributes conformance results against the full parity
 * matrix exposed by SearchAttributeRuntimeContract.
 */
final class SearchAttributeRuntimeResultGate
{
    public const SCHEMA = 'durable-workflow.v2.search-attribute-runtime.result-gate';

    public const VERSION = 13;

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
                'codec_round_trips_include_encoded_payload_or_wire_value_context',
                'codec_round_trips_compare_written_or_wire_values_to_decoded_attributes',
                'query_verdict_exact_query_expected_and_actual_counts_match',
                'or_not_query_verdicts_include_public_surface_and_command_arguments',
                'query_injection_required_rejection_probes_status_and_response_are_reported',
                'waterline_operator_visibility_includes_operator_surface_matrix',
                'indexing_latency_p95_and_max_do_not_exceed_documented_bound',
                'load_latency_reported_for_equality_range_bool_and_keyword_list_filters',
                'latency_and_load_evidence_names_consistency_contract',
                'latency_and_load_evidence_records_public_observation_surfaces',
                'latency_and_load_evidence_records_run_id_and_observed_bounds',
                'each_pass_scenario_has_observed_outputs',
                'each_pass_scenario_has_scenario_specific_evidence',
                'each_non_pass_scenario_has_linked_findings',
                'run_timestamps_outcome_and_finding_links_are_recorded',
                'overall_outcome_matches_gate_status',
                'published_artifact_versions_are_recorded_and_pinned',
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
            'artifact_versions' => self::artifactVersions($result) !== [],
            'started_at' => self::hasScalarField($result, ['started_at', 'startedAt']),
            'finished_at' => self::hasScalarField($result, ['finished_at', 'finishedAt']),
            'generated_at' => self::hasScalarField($result, ['generated_at', 'generatedAt']),
            'outcome' => self::hasScalarField($result, ['outcome', 'status', 'verdict']),
            'runner_blocked' => self::runnerBlockedValue($result) !== null,
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
            'sdk-php' => ['sdk-php', 'sdk_php', 'php', 'php_worker'],
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
            'php_worker_start_and_upsert_visibility' => 'sdk-php',
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
                    $contract,
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
            array_push(
                $failures,
                ...self::queryVerdictFailures(
                    self::sectionValue($result, 'query_verdicts') ?? [],
                    $contract,
                ),
            );
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
                ...self::waterlineEvidenceFailures(
                    self::scenarioEvidence(
                        $result,
                        $scenarioResults['waterline_operator_visibility'],
                        'waterline_operator_visibility',
                    ),
                ),
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
    private static function cliSurfaceEvidenceFailures(array $section, array $contract): array
    {
        $failures = [];

        $requirements = self::arrayValue($contract['scenario_requirements'] ?? [], 'cli_query_and_error_surface') ?? [];
        $queries = self::arrayField(
            $section,
            ['workflow_list_queries', 'workflowListQueries', 'queries', 'workflow_list_query', 'workflowListQuery'],
        ) ?? [];
        foreach (self::arrayValue($requirements, 'required_queries') ?? [] as $queryClass => $query) {
            $entry = self::cliEntryForKey($queries, (string) $queryClass, (string) $query);
            if ($entry === null) {
                $failures[] = [
                    'code' => 'missing_cli_query_evidence',
                    'scenario_id' => 'cli_query_and_error_surface',
                    'query_class' => (string) $queryClass,
                    'query' => (string) $query,
                ];
                continue;
            }

            array_push(
                $failures,
                ...self::cliTranscriptFailures($entry, (string) $queryClass, 'query'),
                ...self::cliQueryCountFailures($entry, (string) $queryClass),
            );
        }

        $definitionCommands = self::arrayField(
            $section,
            ['search_attribute_commands', 'searchAttributeCommands', 'definition_commands', 'definitionCommands'],
        ) ?? [];
        foreach (self::stringList($requirements['required_definition_commands'] ?? []) as $operation) {
            $entry = self::cliEntryForKey($definitionCommands, $operation);
            if ($entry === null) {
                $legacyEntry = self::firstFieldValue(
                    $section,
                    ['search_attribute_'.$operation, 'searchAttribute'.ucfirst($operation)],
                );
                $entry = is_array($legacyEntry) ? $legacyEntry : null;
            }

            if ($entry === null) {
                $failures[] = [
                    'code' => 'missing_cli_definition_command_evidence',
                    'scenario_id' => 'cli_query_and_error_surface',
                    'operation' => $operation,
                ];
                continue;
            }

            array_push($failures, ...self::cliTranscriptFailures($entry, $operation, 'definition_command'));
        }

        $diagnostics = self::arrayField($section, ['diagnostics', 'typed_errors', 'typedErrors', 'errors']) ?? [];
        foreach (self::arrayValue($requirements, 'required_diagnostics') ?? [] as $diagnostic => $probe) {
            $entry = self::cliEntryForKey($diagnostics, (string) $diagnostic, (string) $probe);
            if ($entry === null) {
                $legacyEntry = self::firstFieldValue($section, [$diagnostic, self::camelize((string) $diagnostic)]);
                $entry = is_array($legacyEntry) && self::cliEntryMatchesProbe($legacyEntry, (string) $probe)
                    ? $legacyEntry
                    : null;
            }

            if ($entry === null) {
                $failures[] = [
                    'code' => 'missing_cli_diagnostic_evidence',
                    'scenario_id' => 'cli_query_and_error_surface',
                    'diagnostic' => (string) $diagnostic,
                    'probe' => (string) $probe,
                ];
                continue;
            }

            array_push(
                $failures,
                ...self::cliTranscriptFailures($entry, (string) $diagnostic, 'diagnostic'),
                ...self::cliDiagnosticFailures($entry, (string) $diagnostic),
            );
        }

        return $failures;
    }

    /**
     * @param array<mixed> $entries
     */
    private static function cliEntryForKey(array $entries, string $key, ?string $probe = null): ?array
    {
        foreach ([$key, self::camelize($key)] as $entryKey) {
            $entry = self::arrayValue($entries, $entryKey);
            if ($entry !== null && $entry !== [] && self::cliEntryMatchesProbe($entry, $probe)) {
                return $entry;
            }
        }

        $wanted = self::normalizeEvidenceKey($key);
        foreach ($entries as $entryKey => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (is_string($entryKey)
                && self::normalizeEvidenceKey($entryKey) === $wanted
                && self::cliEntryMatchesProbe($entry, $probe)) {
                return $entry;
            }

            foreach ([
                'query_class',
                'queryClass',
                'class',
                'kind',
                'operation',
                'name',
                'diagnostic',
                'case',
            ] as $field) {
                if (self::normalizeEvidenceKey(self::stringValue($entry[$field] ?? null)) === $wanted
                    && self::cliEntryMatchesProbe($entry, $probe)) {
                    return $entry;
                }
            }

            if ($probe !== null && is_int($entryKey) && self::cliEntryMatchesProbe($entry, $probe)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $entry
     */
    private static function cliEntryMatchesProbe(array $entry, ?string $probe): bool
    {
        if ($probe === null) {
            return true;
        }

        $wantedProbe = self::normalizeExactProbeEvidence($probe);

        return $wantedProbe === '' || self::cliEntryContainsExactProbe($entry, $wantedProbe);
    }

    /**
     * @param array<mixed> $entry
     */
    private static function cliEntryContainsExactProbe(array $entry, string $wantedProbe): bool
    {
        foreach (['query', 'input', 'probe', 'rejected_input', 'rejectedInput'] as $field) {
            $value = $entry[$field] ?? null;
            if (is_array($value)) {
                foreach ($value as $item) {
                    if (self::exactProbeEvidenceMatches(self::stringValue($item), $wantedProbe)) {
                        return true;
                    }
                }

                continue;
            }

            if (self::exactProbeEvidenceMatches(self::stringValue($value), $wantedProbe)) {
                return true;
            }
        }

        foreach (['arguments', 'args', 'argv'] as $field) {
            $arguments = $entry[$field] ?? null;
            if (is_array($arguments)) {
                foreach ($arguments as $argument) {
                    if (self::exactProbeEvidenceMatches(self::stringValue($argument), $wantedProbe)) {
                        return true;
                    }
                }

                continue;
            }

            if (self::exactProbeEvidenceMatches(self::stringValue($arguments), $wantedProbe)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $entry
     *
     * @return array<int, array<string, mixed>>
     */
    private static function cliTranscriptFailures(array $entry, string $entryId, string $entryType): array
    {
        $failures = [];

        foreach ([
            'command' => ['command'],
            'arguments' => ['arguments', 'args', 'argv'],
        ] as $field => $aliases) {
            if (! self::hasNonEmptyField($entry, $aliases)) {
                $failures[] = [
                    'code' => 'missing_cli_transcript_field',
                    'scenario_id' => 'cli_query_and_error_surface',
                    'entry_type' => $entryType,
                    'entry_id' => $entryId,
                    'field' => $field,
                ];
            }
        }

        foreach ([
            'stdout' => ['stdout'],
            'stderr' => ['stderr'],
        ] as $field => $aliases) {
            if (! self::hasAnyField($entry, $aliases)) {
                $failures[] = [
                    'code' => 'missing_cli_transcript_field',
                    'scenario_id' => 'cli_query_and_error_surface',
                    'entry_type' => $entryType,
                    'entry_id' => $entryId,
                    'field' => $field,
                ];
            }
        }

        if (! self::hasNumericField($entry, ['exit_code', 'exitCode', 'exit_status', 'exitStatus'])) {
            $failures[] = [
                'code' => 'missing_cli_transcript_field',
                'scenario_id' => 'cli_query_and_error_surface',
                'entry_type' => $entryType,
                'entry_id' => $entryId,
                'field' => 'exit_code',
            ];
        }

        return $failures;
    }

    /**
     * @param array<mixed> $entry
     *
     * @return array<int, array<string, mixed>>
     */
    private static function cliQueryCountFailures(array $entry, string $queryClass): array
    {
        $failures = [];

        foreach (['expected_count', 'actual_count'] as $field) {
            if (! self::hasNumericField($entry, [$field, self::camelize($field)])) {
                $failures[] = [
                    'code' => 'missing_cli_query_count',
                    'scenario_id' => 'cli_query_and_error_surface',
                    'query_class' => $queryClass,
                    'field' => $field,
                ];
            }
        }

        $expectedCount = self::numericField($entry, ['expected_count', 'expectedCount']);
        $actualCount = self::numericField($entry, ['actual_count', 'actualCount']);
        if ($expectedCount !== null && $actualCount !== null && $expectedCount !== $actualCount) {
            $failures[] = [
                'code' => 'cli_query_count_mismatch',
                'scenario_id' => 'cli_query_and_error_surface',
                'query_class' => $queryClass,
                'expected_count' => $expectedCount,
                'actual_count' => $actualCount,
            ];
        }

        return $failures;
    }

    /**
     * @param array<mixed> $entry
     *
     * @return array<int, array<string, mixed>>
     */
    private static function cliDiagnosticFailures(array $entry, string $diagnostic): array
    {
        $failures = [];

        foreach ([
            'error_code' => ['error_code', 'errorCode', 'code', 'type', 'reason', 'rejection_reason', 'rejectionReason'],
            'message' => ['message', 'error', 'error_message', 'errorMessage'],
        ] as $field => $aliases) {
            if (! self::hasNonEmptyField($entry, $aliases)) {
                $failures[] = [
                    'code' => 'missing_cli_diagnostic_field',
                    'scenario_id' => 'cli_query_and_error_surface',
                    'diagnostic' => $diagnostic,
                    'field' => $field,
                ];
            }
        }

        $exitCode = self::numericField($entry, ['exit_code', 'exitCode', 'exit_status', 'exitStatus']);
        if ($exitCode === 0 || $exitCode === 0.0) {
            $failures[] = [
                'code' => 'cli_diagnostic_command_succeeded',
                'scenario_id' => 'cli_query_and_error_surface',
                'diagnostic' => $diagnostic,
            ];
        }

        $failureKind = strtolower(self::firstStringField(
            $entry,
            ['failure_kind', 'failureKind', 'error_kind', 'errorKind', 'type', 'reason', 'error_code', 'errorCode'],
        ));
        if (str_contains($failureKind, 'transport') || str_contains($failureKind, 'network')) {
            $failures[] = [
                'code' => 'cli_diagnostic_collapsed_to_transport_failure',
                'scenario_id' => 'cli_query_and_error_surface',
                'diagnostic' => $diagnostic,
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

        if (! self::hasCodecPayloadContext($entry)) {
            $failures[] = [
                'code' => 'missing_codec_round_trip_field',
                'scenario_id' => $scenarioId,
                'field' => 'encoded_payload_or_wire_value_context',
            ];
        }

        $decoded = self::arrayField($entry, ['decoded_attributes', 'decodedAttributes', 'attributes']);
        $expected = self::codecExpectedAttributes($entry);
        if ($decoded === null || $decoded === []) {
            $failures[] = [
                'code' => 'missing_codec_round_trip_field',
                'scenario_id' => $scenarioId,
                'field' => 'decoded_attributes',
            ];
        } else {
            foreach (self::schemaKeyTypes($contract) as $attribute => $type) {
                if (! array_key_exists($attribute, $decoded)) {
                    $failures[] = [
                        'code' => 'missing_codec_decoded_attribute',
                        'scenario_id' => $scenarioId,
                        'attribute' => $attribute,
                    ];
                    continue;
                }

                if (! self::decodedAttributeMatchesType($decoded[$attribute], $type)) {
                    $failures[] = [
                        'code' => 'codec_decoded_attribute_type_mismatch',
                        'scenario_id' => $scenarioId,
                        'attribute' => $attribute,
                        'expected_type' => $type,
                    ];
                }

                if (! array_key_exists($attribute, $expected)) {
                    $failures[] = [
                        'code' => 'missing_codec_expected_attribute',
                        'scenario_id' => $scenarioId,
                        'attribute' => $attribute,
                    ];

                    continue;
                }

                if (! self::codecAttributeValuesMatch($decoded[$attribute], $expected[$attribute], $type)) {
                    $failures[] = [
                        'code' => 'codec_decoded_attribute_value_mismatch',
                        'scenario_id' => $scenarioId,
                        'attribute' => $attribute,
                        'expected_type' => $type,
                        'expected_value' => $expected[$attribute],
                        'actual_value' => $decoded[$attribute],
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

        $documentedBoundMs = self::numericField($section, ['documented_bound_ms', 'documentedBoundMs']);
        if ($documentedBoundMs !== null) {
            foreach (self::stringList($requirements['documented_bound_compared_fields'] ?? []) as $field) {
                $latencyMs = self::numericField($section, [$field, self::camelize($field)]);
                if ($latencyMs !== null && $latencyMs > $documentedBoundMs) {
                    $failures[] = [
                        'code' => 'latency_distribution_exceeds_documented_bound',
                        'scenario_id' => $scenarioId,
                        'field' => $field,
                        'actual_ms' => $latencyMs,
                        'documented_bound_ms' => $documentedBoundMs,
                    ];
                }
            }
        }

        array_push(
            $failures,
            ...self::latencyAndLoadContractEvidenceFailures(
                $section,
                $requirements,
                $scenarioId,
                'latency',
            ),
        );

        return $failures;
    }

    /**
     * @param array<mixed> $entry
     */
    private static function hasCodecPayloadContext(array $entry): bool
    {
        return self::hasNonEmptyField($entry, [
            'encoded_payload',
            'encodedPayload',
            'codec_payload',
            'codecPayload',
            'wire_value_context',
            'wireValueContext',
            'wire_values',
            'wireValues',
            'wire_value',
            'wireValue',
            'wire_context',
            'wireContext',
        ]);
    }

    /**
     * @param array<mixed> $entry
     *
     * @return array<string, mixed>
     */
    private static function codecExpectedAttributes(array $entry): array
    {
        $attributes = self::arrayField($entry, [
            'written_attributes',
            'writtenAttributes',
            'expected_attributes',
            'expectedAttributes',
            'source_attributes',
            'sourceAttributes',
            'writer_attributes',
            'writerAttributes',
            'input_attributes',
            'inputAttributes',
        ]);

        if ($attributes !== null && $attributes !== []) {
            return $attributes;
        }

        $wireContext = self::arrayField($entry, [
            'wire_value_context',
            'wireValueContext',
            'wire_context',
            'wireContext',
        ]) ?? [];

        $wireValues = self::arrayField($wireContext, ['wire_values', 'wireValues', 'attributes'])
            ?? self::arrayField($entry, ['wire_values', 'wireValues']);

        return $wireValues === null ? [] : self::codecAttributesFromWireValues($wireValues);
    }

    /**
     * @param array<mixed> $wireValues
     *
     * @return array<string, mixed>
     */
    private static function codecAttributesFromWireValues(array $wireValues): array
    {
        $attributes = [];

        foreach ($wireValues as $attribute => $wireValue) {
            if (! is_string($attribute) || $attribute === '') {
                continue;
            }

            if (! is_array($wireValue)) {
                $attributes[$attribute] = $wireValue;

                continue;
            }

            foreach ([
                'value_string',
                'value_keyword',
                'value_int',
                'value_double',
                'value_float',
                'value_bool',
                'value_datetime',
                'value_keyword_list',
            ] as $field) {
                if (array_key_exists($field, $wireValue)) {
                    $attributes[$attribute] = $wireValue[$field];

                    break;
                }
            }
        }

        return $attributes;
    }

    private static function codecAttributeValuesMatch(mixed $actual, mixed $expected, string $type): bool
    {
        return match ($type) {
            'string', 'keyword' => is_string($actual) && is_string($expected) && $actual === $expected,
            'int' => is_int($actual) && is_int($expected) && $actual === $expected,
            'double' => is_float($actual)
                && (is_float($expected) || is_int($expected))
                && abs($actual - (float) $expected) < 0.000000001,
            'bool' => is_bool($actual) && is_bool($expected) && $actual === $expected,
            'datetime' => self::codecDateTimesMatch($actual, $expected),
            'keyword_list' => self::codecKeywordListsMatch($actual, $expected),
            default => true,
        };
    }

    private static function codecDateTimesMatch(mixed $actual, mixed $expected): bool
    {
        if (! is_string($actual) || ! is_string($expected) || $actual === '' || $expected === '') {
            return false;
        }

        $actualTime = self::parseCodecDateTime($actual);
        $expectedTime = self::parseCodecDateTime($expected);

        if ($actualTime === null || $expectedTime === null) {
            return false;
        }

        $utc = new \DateTimeZone('UTC');

        return $actualTime->setTimezone($utc)->format('U.u') === $expectedTime->setTimezone($utc)->format('U.u');
    }

    private static function parseCodecDateTime(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function codecKeywordListsMatch(mixed $actual, mixed $expected): bool
    {
        return self::isStringList($actual)
            && self::isStringList($expected)
            && array_values($actual) === array_values($expected);
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

        $queryLatencies = self::loadQueryLatencyProfiles($section);
        foreach (self::stringList($requirements['required_query_latency_classes'] ?? []) as $queryClass) {
            $profile = self::arrayValue($queryLatencies, $queryClass) ?? [];
            if ($profile === []) {
                $failures[] = [
                    'code' => 'missing_load_query_latency_class',
                    'query_class' => $queryClass,
                ];
                continue;
            }

            foreach (self::stringList($requirements['required_query_latency_fields'] ?? []) as $field) {
                if (! self::hasNumericField($profile, [$field, self::camelize($field)])) {
                    $failures[] = [
                        'code' => 'missing_load_query_latency_field',
                        'query_class' => $queryClass,
                        'field' => $field,
                    ];
                }
            }
        }

        array_push(
            $failures,
            ...self::latencyAndLoadContractEvidenceFailures(
                $section,
                $requirements,
                'load_and_bounded_latency',
                'load',
            ),
        );

        return $failures;
    }

    /**
     * @param array<mixed> $section
     * @param array<mixed> $requirements
     *
     * @return array<int, array<string, mixed>>
     */
    private static function latencyAndLoadContractEvidenceFailures(
        array $section,
        array $requirements,
        string $scenarioId,
        string $codePrefix,
    ): array {
        $failures = [];
        $requiredFields = self::stringList($requirements['required_evidence_fields'] ?? []);

        if (in_array('consistency_contract', $requiredFields, true)
            && ! self::hasNonPlaceholderField($section, [
                'consistency_contract',
                'consistencyContract',
                'user_visible_consistency_contract',
                'userVisibleConsistencyContract',
            ])) {
            $failures[] = [
                'code' => 'missing_'.$codePrefix.'_consistency_contract',
                'scenario_id' => $scenarioId,
            ];
        }

        if (in_array('observed_bounds', $requiredFields, true)) {
            $observedBounds = self::arrayField($section, ['observed_bounds', 'observedBounds']);
            if ($observedBounds === null || $observedBounds === []) {
                $failures[] = [
                    'code' => 'missing_'.$codePrefix.'_observed_bounds',
                    'scenario_id' => $scenarioId,
                ];
            } else {
                foreach (self::stringList($requirements['required_observed_bound_fields'] ?? []) as $field) {
                    if (! self::hasNumericField($observedBounds, [$field, self::camelize($field)])) {
                        $failures[] = [
                            'code' => 'missing_'.$codePrefix.'_observed_bound_field',
                            'scenario_id' => $scenarioId,
                            'field' => $field,
                        ];
                    }
                }
            }
        }

        if (in_array('public_observation_surfaces', $requiredFields, true)) {
            $surfaces = self::stringArrayField($section, [
                'public_observation_surfaces',
                'publicObservationSurfaces',
                'observation_surfaces',
                'observationSurfaces',
            ]) ?? [];
            $surfaces = array_values(array_filter(
                $surfaces,
                static fn (string $surface): bool => ! self::isPlaceholderEvidence($surface),
            ));

            if ($surfaces === []) {
                $failures[] = [
                    'code' => 'missing_'.$codePrefix.'_public_observation_surfaces',
                    'scenario_id' => $scenarioId,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<mixed> $section
     *
     * @return array<mixed>
     */
    private static function loadQueryLatencyProfiles(array $section): array
    {
        foreach ([
            'query_latencies',
            'queryLatencies',
            'query_latency_by_filter',
            'queryLatencyByFilter',
            'filter_latencies',
            'filterLatencies',
            'queries',
        ] as $field) {
            $profiles = self::arrayValue($section, $field);
            if ($profiles !== null) {
                return $profiles;
            }
        }

        return $section;
    }

    /**
     * @param array<mixed> $section
     *
     * @return array<int, array<string, mixed>>
     */
    private static function queryVerdictFailures(array $section, array $contract): array
    {
        $queries = self::arrayValue($section, 'queries') ?? $section;
        $requirements = self::arrayValue(
            self::arrayValue($contract['scenario_requirements'] ?? [], 'cli_query_and_error_surface') ?? [],
            'required_queries',
        ) ?? [
            'equality' => 'customer_id = "cust-7"',
            'range' => 'order_total_cents > 5000 AND order_total_cents <= 10000',
            'bool' => 'is_vip = true',
            'or' => 'customer_id = "cust-2" OR customer_id = "cust-8"',
            'not' => 'priority_tier IN ("gold","platinum") AND NOT is_vip',
            'keyword_list' => 'tags = "urgent"',
        ];
        $failures = [];

        foreach ($requirements as $queryClass => $requiredQuery) {
            $queryClass = (string) $queryClass;
            $requiredQuery = (string) $requiredQuery;
            $verdict = self::arrayValue($queries, $queryClass) ?? [];
            if ($verdict === []) {
                $failures[] = [
                    'code' => 'missing_query_verdict',
                    'query_class' => $queryClass,
                ];
                continue;
            }

            $queryText = self::queryVerdictText($verdict);
            if ($queryText === '') {
                $failures[] = [
                    'code' => 'missing_query_verdict_query',
                    'query_class' => $queryClass,
                    'query' => $requiredQuery,
                ];
            } elseif (! self::exactProbeEvidenceMatches($queryText, $requiredQuery)) {
                $failures[] = [
                    'code' => 'query_verdict_query_mismatch',
                    'query_class' => $queryClass,
                    'expected_query' => $requiredQuery,
                    'actual_query' => $queryText,
                ];
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

            if (in_array($queryClass, ['or', 'not'], true)) {
                array_push($failures, ...self::queryPublicSurfaceFailures($verdict, $queryClass));
            }
        }

        return $failures;
    }

    /**
     * @param array<mixed> $verdict
     *
     * @return array<int, array<string, mixed>>
     */
    private static function queryPublicSurfaceFailures(array $verdict, string $queryClass): array
    {
        $failures = [];

        if (! self::hasNonPlaceholderField($verdict, [
            'public_surface',
            'publicSurface',
            'surface',
            'observed_surface',
            'observedSurface',
        ])) {
            $failures[] = [
                'code' => 'missing_query_public_surface',
                'query_class' => $queryClass,
                'field' => 'public_surface',
            ];
        }

        if (! self::hasNonEmptyField($verdict, ['arguments', 'args', 'argv'])) {
            $failures[] = [
                'code' => 'missing_query_public_surface',
                'query_class' => $queryClass,
                'field' => 'arguments',
            ];
        }

        return $failures;
    }

    /**
     * @param array<mixed> $verdict
     */
    private static function queryVerdictText(array $verdict): string
    {
        foreach (['query', 'query_string', 'queryString', 'input', 'probe'] as $field) {
            $value = self::stringValue($verdict[$field] ?? null);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
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
            $rejection = self::injectionRejectionForProbe($rejections, $probe);
            if ($rejection === null) {
                $failures[] = [
                    'code' => 'missing_required_injection_rejection_probe',
                    'probe' => $probe,
                ];

                continue;
            }

            array_push(
                $failures,
                ...self::injectionRejectionDiagnosticFailures($rejection, $probe, $requirements),
            );
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
     * @param array<mixed> $rejections
     * @return array<mixed>|null
     */
    private static function injectionRejectionForProbe(array $rejections, string $requiredProbe): ?array
    {
        foreach ($rejections as $key => $rejection) {
            $keyMatches = is_string($key) && self::probeEvidenceMatches($key, $requiredProbe);
            if (is_array($rejection)) {
                if ($keyMatches) {
                    return $rejection;
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
                        return $rejection;
                    }
                }

                continue;
            }

            if ($keyMatches || self::probeEvidenceMatches(self::stringValue($rejection), $requiredProbe)) {
                return ['rejected_input' => is_string($key) ? $key : self::stringValue($rejection)];
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $rejection
     * @param array<mixed> $requirements
     * @return array<int, array<string, mixed>>
     */
    private static function injectionRejectionDiagnosticFailures(
        array $rejection,
        string $probe,
        array $requirements,
    ): array {
        $failures = [];
        $requiredFields = self::stringList($requirements['required_rejection_fields'] ?? [
            'status_code',
            'response_body',
        ]);

        if (in_array('status_code', $requiredFields, true)) {
            $statusCode = self::numericField($rejection, [
                'status_code',
                'statusCode',
                'http_status',
                'httpStatus',
                'response_status',
                'responseStatus',
            ]);
            if ($statusCode === null) {
                $failures[] = [
                    'code' => 'missing_injection_rejection_field',
                    'probe' => $probe,
                    'field' => 'status_code',
                ];
            } elseif ($statusCode >= 200 && $statusCode < 300) {
                $failures[] = [
                    'code' => 'injection_rejection_status_succeeded',
                    'probe' => $probe,
                    'status_code' => $statusCode,
                ];
            }
        }

        if (in_array('response_body', $requiredFields, true)
            && ! self::hasNonPlaceholderField($rejection, [
                'response_body',
                'responseBody',
                'body',
                'response',
                'error_body',
                'errorBody',
            ])) {
            $failures[] = [
                'code' => 'missing_injection_rejection_field',
                'probe' => $probe,
                'field' => 'response_body',
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
        $section = self::waterlineEvidenceSection($section);
        $failures = [];

        $workflowList = self::arrayField($section, ['workflow_list_filter', 'workflowListFilter']);
        if ($workflowList === null) {
            self::addMissingWaterlineFields($failures, [
                'workflow_list_filter.expected_count',
                'workflow_list_filter.actual_count',
            ]);
        } else {
            $expectedCount = self::numericField($workflowList, ['expected_count', 'expectedCount']);
            $actualCount = self::numericField($workflowList, ['actual_count', 'actualCount']);

            if ($expectedCount === null) {
                self::addMissingWaterlineFields($failures, ['workflow_list_filter.expected_count']);
            }
            if ($actualCount === null) {
                self::addMissingWaterlineFields($failures, ['workflow_list_filter.actual_count']);
            }
            if ($expectedCount !== null && $expectedCount <= 0) {
                $failures[] = [
                    'code' => 'waterline_workflow_list_filter_empty',
                    'field' => 'workflow_list_filter.expected_count',
                    'expected_count' => $expectedCount,
                ];
            }
            if ($expectedCount !== null && $actualCount !== null && (float) $expectedCount !== (float) $actualCount) {
                $failures[] = [
                    'code' => 'waterline_workflow_list_filter_count_mismatch',
                    'field' => 'workflow_list_filter',
                    'expected_count' => $expectedCount,
                    'actual_count' => $actualCount,
                ];
            }

            $expectedRunIds = self::stringArrayField($workflowList, ['expected_run_ids', 'expectedRunIds']);
            $actualRunIds = self::stringArrayField($workflowList, ['actual_run_ids', 'actualRunIds']);
            if ($expectedRunIds !== null && $actualRunIds !== null && ! self::sameStringSet($expectedRunIds, $actualRunIds)) {
                $failures[] = [
                    'code' => 'waterline_workflow_list_filter_run_id_mismatch',
                    'field' => 'workflow_list_filter.actual_run_ids',
                    'expected_run_ids' => $expectedRunIds,
                    'actual_run_ids' => $actualRunIds,
                ];
            }
        }

        $selectedRun = self::arrayField($section, ['selected_run_detail', 'selectedRunDetail']);
        if ($selectedRun === null) {
            self::addMissingWaterlineFields($failures, [
                'selected_run_detail.expected_search_attributes',
                'selected_run_detail.actual_search_attributes',
            ]);
        } else {
            $expectedAttributes = self::arrayField($selectedRun, ['expected_search_attributes', 'expectedSearchAttributes']);
            $actualAttributes = self::arrayField($selectedRun, ['actual_search_attributes', 'actualSearchAttributes']);

            if ($expectedAttributes === null || $expectedAttributes === []) {
                self::addMissingWaterlineFields($failures, ['selected_run_detail.expected_search_attributes']);
            }
            if ($actualAttributes === null || $actualAttributes === []) {
                self::addMissingWaterlineFields($failures, ['selected_run_detail.actual_search_attributes']);
            }
            if ($expectedAttributes !== null && $expectedAttributes !== [] && $actualAttributes !== null) {
                foreach ($expectedAttributes as $attribute => $expectedValue) {
                    if (! is_string($attribute) || ! array_key_exists($attribute, $actualAttributes)) {
                        $failures[] = [
                            'code' => 'missing_waterline_selected_run_attribute',
                            'field' => 'selected_run_detail.actual_search_attributes',
                            'attribute' => $attribute,
                        ];
                        continue;
                    }

                    if (! self::waterlineValuesMatch($actualAttributes[$attribute], $expectedValue)) {
                        $failures[] = [
                            'code' => 'waterline_selected_run_attribute_mismatch',
                            'field' => 'selected_run_detail.actual_search_attributes',
                            'attribute' => $attribute,
                        ];
                    }
                }
            }

            $visible = self::boolField($selectedRun, ['expected_attributes_visible', 'expectedAttributesVisible']);
            if ($visible === false) {
                $failures[] = [
                    'code' => 'waterline_selected_run_attributes_not_visible',
                    'field' => 'selected_run_detail.expected_attributes_visible',
                ];
            }
        }

        $savedFilter = self::arrayField($section, ['saved_filter_state', 'savedFilterState']);
        if ($savedFilter === null) {
            self::addMissingWaterlineFields($failures, [
                'saved_filter_state.stored_filters',
                'saved_filter_state.retrieved_filters',
            ]);
        } else {
            $storedFilters = self::arrayField($savedFilter, ['stored_filters', 'storedFilters']);
            $retrievedFilters = self::arrayField($savedFilter, ['retrieved_filters', 'retrievedFilters']);

            if ($storedFilters === null || $storedFilters === []) {
                self::addMissingWaterlineFields($failures, ['saved_filter_state.stored_filters']);
            }
            if ($retrievedFilters === null || $retrievedFilters === []) {
                self::addMissingWaterlineFields($failures, ['saved_filter_state.retrieved_filters']);
            }
            if ($storedFilters !== null && $storedFilters !== [] && $retrievedFilters !== null
                && ! self::waterlineValuesMatch($retrievedFilters, $storedFilters)) {
                $failures[] = [
                    'code' => 'waterline_saved_filter_round_trip_mismatch',
                    'field' => 'saved_filter_state.retrieved_filters',
                ];
            }

            foreach ([
                'filter_preserved_on_retrieval' => ['filter_preserved_on_retrieval', 'filterPreservedOnRetrieval'],
                'filter_preserved_on_list_retrieval' => ['filter_preserved_on_list_retrieval', 'filterPreservedOnListRetrieval'],
                'applied_filter_matched' => ['applied_filter_matched', 'appliedFilterMatched'],
            ] as $field => $aliases) {
                $value = self::boolField($savedFilter, $aliases);
                if ($value === false) {
                    $failures[] = [
                        'code' => 'waterline_saved_filter_round_trip_mismatch',
                        'field' => 'saved_filter_state.'.$field,
                    ];
                }
            }
        }

        $namespaceIsolation = self::arrayField($section, ['namespace_isolation', 'namespaceIsolation']);
        if ($namespaceIsolation === null) {
            self::addMissingWaterlineFields($failures, [
                'namespace_isolation.tenant_a_filter_actual_run_ids',
                'namespace_isolation.tenant_b_filter_actual_run_ids',
            ]);
        } else {
            $tenantAActual = self::stringArrayField(
                $namespaceIsolation,
                ['tenant_a_filter_actual_run_ids', 'tenantAFilterActualRunIds'],
            );
            $tenantBActual = self::stringArrayField(
                $namespaceIsolation,
                ['tenant_b_filter_actual_run_ids', 'tenantBFilterActualRunIds'],
            );

            if ($tenantAActual === null) {
                self::addMissingWaterlineFields($failures, ['namespace_isolation.tenant_a_filter_actual_run_ids']);
            } elseif ($tenantAActual === []) {
                $failures[] = [
                    'code' => 'waterline_namespace_isolation_empty_run_ids',
                    'field' => 'namespace_isolation.tenant_a_filter_actual_run_ids',
                ];
            }

            if ($tenantBActual === null) {
                self::addMissingWaterlineFields($failures, ['namespace_isolation.tenant_b_filter_actual_run_ids']);
            } elseif ($tenantBActual === []) {
                $failures[] = [
                    'code' => 'waterline_namespace_isolation_empty_run_ids',
                    'field' => 'namespace_isolation.tenant_b_filter_actual_run_ids',
                ];
            }

            if ($tenantAActual !== null
                && $tenantBActual !== null
                && array_intersect($tenantAActual, $tenantBActual) !== []) {
                $failures[] = [
                    'code' => 'waterline_namespace_isolation_run_id_overlap',
                    'field' => 'namespace_isolation',
                    'tenant_a_filter_actual_run_ids' => $tenantAActual,
                    'tenant_b_filter_actual_run_ids' => $tenantBActual,
                ];
            }

            foreach ([
                'tenant_a_filter_expected_run_ids' => [
                    'expected' => ['tenant_a_filter_expected_run_ids', 'tenantAFilterExpectedRunIds'],
                    'actual' => ['tenant_a_filter_actual_run_ids', 'tenantAFilterActualRunIds'],
                ],
                'tenant_b_filter_expected_run_ids' => [
                    'expected' => ['tenant_b_filter_expected_run_ids', 'tenantBFilterExpectedRunIds'],
                    'actual' => ['tenant_b_filter_actual_run_ids', 'tenantBFilterActualRunIds'],
                ],
            ] as $field => $aliases) {
                $expectedIds = self::stringArrayField($namespaceIsolation, $aliases['expected']);
                $actualIds = self::stringArrayField($namespaceIsolation, $aliases['actual']);
                if ($expectedIds !== null && $actualIds !== null && ! self::sameStringSet($expectedIds, $actualIds)) {
                    $failures[] = [
                        'code' => 'waterline_namespace_isolation_run_id_mismatch',
                        'field' => 'namespace_isolation.'.str_replace('_expected_', '_actual_', $field),
                        'expected_run_ids' => $expectedIds,
                        'actual_run_ids' => $actualIds,
                    ];
                }
            }

            foreach ([
                'tenant_a_excludes_tenant_b' => ['tenant_a_excludes_tenant_b', 'tenantAExcludesTenantB'],
                'tenant_b_excludes_tenant_a' => ['tenant_b_excludes_tenant_a', 'tenantBExcludesTenantA'],
                'tenant_b_filter_matched' => ['tenant_b_filter_matched', 'tenantBFilterMatched'],
            ] as $field => $aliases) {
                $value = self::boolField($namespaceIsolation, $aliases);
                if ($value === false) {
                    $failures[] = [
                        'code' => 'waterline_namespace_isolation_failed',
                        'field' => 'namespace_isolation.'.$field,
                    ];
                }
            }
        }

        $captures = self::arrayField($section, ['api_captures', 'apiCaptures']);
        if ($captures === null || $captures === []) {
            self::addMissingWaterlineFields($failures, ['api_captures']);
        } else {
            foreach ([
                'workflow_list_customer_filter',
                'workflow_list_keyword_list_filter',
                'selected_run_detail',
                'saved_view_show',
                'saved_view_list',
                'saved_view_applied_workflow_list',
                'foreign_namespace_workflow_list',
            ] as $capture) {
                $captureEvidence = self::arrayField($captures, [$capture, self::camelize($capture)]);
                if ($captureEvidence === null) {
                    $failures[] = [
                        'code' => 'missing_waterline_api_capture',
                        'field' => 'api_captures.'.$capture,
                    ];
                    continue;
                }

                $status = self::numericField($captureEvidence, ['status', 'status_code', 'statusCode']);
                if ($status === null) {
                    self::addMissingWaterlineFields($failures, ['api_captures.'.$capture.'.status']);
                } elseif ((int) $status !== 200) {
                    $failures[] = [
                        'code' => 'waterline_api_capture_status_mismatch',
                        'field' => 'api_captures.'.$capture.'.status',
                        'status' => $status,
                    ];
                }
            }
        }

        $surfaceMatrix = self::arrayField($section, ['operator_surface_matrix', 'operatorSurfaceMatrix']);
        if ($surfaceMatrix === null) {
            $failures[] = [
                'code' => 'missing_waterline_operator_surface_matrix',
                'field' => 'operator_surface_matrix',
            ];
        } else {
            foreach ([
                'workflow_list_search_attribute_filter',
                'keyword_list_search_attribute_filter',
                'selected_run_search_attributes',
                'saved_filter_round_trip',
                'namespace_scoped_visibility',
            ] as $surface) {
                if (self::boolField($surfaceMatrix, [$surface, self::camelize($surface)]) !== true) {
                    $failures[] = [
                        'code' => 'waterline_operator_surface_not_proved',
                        'field' => 'operator_surface_matrix.'.$surface,
                    ];
                }
            }
        }

        return $failures;
    }

    /**
     * @param array<mixed> $section
     *
     * @return array<mixed>
     */
    private static function waterlineEvidenceSection(array $section): array
    {
        foreach ([
            'waterline_operator_visibility',
            'waterlineOperatorVisibility',
            'waterline_search_attribute_visibility',
            'waterlineSearchAttributeVisibility',
            'observed_outputs',
            'observedOutputs',
        ] as $field) {
            $nested = self::arrayValue($section, $field);
            if ($nested !== null && $nested !== []) {
                return self::waterlineEvidenceSection($nested);
            }
        }

        return $section;
    }

    /**
     * @param array<int, array<string, mixed>> $failures
     * @param list<string> $fields
     */
    private static function addMissingWaterlineFields(array &$failures, array $fields): void
    {
        foreach ($fields as $field) {
            $failures[] = [
                'code' => 'missing_waterline_operator_visibility_field',
                'field' => $field,
            ];
        }
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
            'sdk-php' => ['sdk-php', 'sdk_php', 'php', 'php_worker'],
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
     * @return array<string, string>
     */
    private static function schemaKeyTypes(array $contract): array
    {
        $schemaKeys = self::arrayValue($contract['topology'] ?? [], 'schema_keys') ?? [];
        $types = [];
        foreach ($schemaKeys as $key => $definition) {
            $name = is_string($key) ? $key : '';
            $type = '';

            if (is_array($definition)) {
                $name = $name !== '' ? $name : self::firstStringField($definition, ['name', 'key']);
                $type = self::firstStringField($definition, ['type', 'value_type', 'valueType']);
            } elseif (is_string($key)) {
                $type = self::stringValue($definition);
            } else {
                $name = self::stringValue($definition);
            }

            $type = self::normalizeSearchAttributeType($type);
            if ($name !== '' && $type !== '') {
                $types[$name] = $type;
            }
        }

        return $types;
    }

    private static function normalizeSearchAttributeType(string $type): string
    {
        $normalized = strtolower(str_replace('-', '_', trim($type)));

        return match ($normalized) {
            'integer' => 'int',
            'boolean' => 'bool',
            'float' => 'double',
            'keywordlist', 'keyword_list' => 'keyword_list',
            default => $normalized,
        };
    }

    private static function decodedAttributeMatchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string', 'keyword', 'datetime' => is_string($value) && $value !== '',
            'int' => is_int($value),
            'double' => is_float($value),
            'bool' => is_bool($value),
            'keyword_list' => self::isStringList($value),
            default => true,
        };
    }

    private static function isStringList(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        $expectedKey = 0;
        foreach ($value as $key => $item) {
            if ($key !== $expectedKey || ! is_string($item) || $item === '') {
                return false;
            }
            $expectedKey++;
        }

        return $expectedKey > 0;
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
     * @param array<mixed> $value
     * @param list<string> $fields
     */
    private static function hasAnyField(array $value, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $fields
     */
    private static function hasNonPlaceholderField(array $value, array $fields): bool
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $value) || ! self::nonEmptyValue($value[$field])) {
                continue;
            }

            $fieldValue = $value[$field];
            if (is_string($fieldValue) && self::isPlaceholderEvidence($fieldValue)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private static function probeEvidenceMatches(string $evidence, string $requiredProbe): bool
    {
        $evidence = self::normalizeProbeLabel($evidence);
        $requiredProbe = self::normalizeProbeLabel($requiredProbe);
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

    private static function exactProbeEvidenceMatches(string $evidence, string $requiredProbe): bool
    {
        $evidence = self::normalizeExactProbeEvidence($evidence);
        $requiredProbe = self::normalizeExactProbeEvidence($requiredProbe);

        return $evidence !== '' && $evidence === $requiredProbe;
    }

    private static function normalizeExactProbeEvidence(string $value): string
    {
        return preg_replace('/\s+/', ' ', trim($value)) ?? '';
    }

    private static function normalizeProbeLabel(string $value): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim($value))) ?? '';
    }

    private static function normalizeEvidenceKey(string $value): string
    {
        return str_replace('-', '_', self::normalizeProbeLabel($value));
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
     *
     * @return list<string>|null
     */
    private static function stringArrayField(array $value, array $fields): ?array
    {
        $fieldValue = self::arrayField($value, $fields);
        if ($fieldValue === null) {
            return null;
        }

        return self::stringList($fieldValue);
    }

    /**
     * @param list<string> $expected
     * @param list<string> $actual
     */
    private static function sameStringSet(array $expected, array $actual): bool
    {
        sort($expected);
        sort($actual);

        return $expected === $actual;
    }

    private static function waterlineValuesMatch(mixed $actual, mixed $expected): bool
    {
        return self::normalizeComparableValue($actual) === self::normalizeComparableValue($expected);
    }

    private static function normalizeComparableValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = self::normalizeComparableValue($item);
        }

        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
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
