<?php

namespace App\Support;

/**
 * Evaluates a signals/queries conformance result against the public
 * scenario manifest exposed by SignalQueryRuntimeContract.
 */
final class SignalQueryRuntimeResultGate
{
    public const SCHEMA = 'durable-workflow.v2.signal-query-runtime.result-gate';

    public const VERSION = 14;

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
        ],
    ];

    private const TRUTHY_REQUIRED_EVIDENCE = [
        'python_worker_query_task_routing',
        'cli_signal_and_query',
        'sdk_python_signal_and_query',
        'immediate_repeat_query_consistency',
        'php_worker_query_task_routing',
        'workflow_php_signal_and_query',
        'php_client_signal_and_query',
        'cross_language_query_consistency',
        'wire_envelope_compatibility',
        'comparison.run_status_matches_public_clients',
        'comparison.counter_state_matches_public_clients',
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
                'replay_timing_timestamps_are_ordered',
                'terminal_run_status_codes_and_reasons_are_typed',
                'each_non_pass_scenario_has_linked_findings',
                'omitted_required_scenarios_link_findings',
                'run_timestamps_outcome_and_finding_links_are_recorded',
                'overall_outcome_matches_gate_status',
                'published_artifact_versions_are_recorded_and_pinned',
                'scenario_artifact_versions_match_run_tuple',
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

        $scenarioArtifactFailures = self::scenarioArtifactVersionFailures($result, $scenarioResults, $contract);
        array_push($failures, ...$scenarioArtifactFailures);

        $sourceFailures = self::sourcePolicyFailures($result, $contract, $scenarioResults);
        array_push($failures, ...$sourceFailures);

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
     * @param array<string, array<string, mixed>> $scenarioResults
     * @param array<string, mixed> $contract
     *
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
     * @param array<string, mixed> $result
     * @param array<string, mixed> $scenarioResult
     *
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
     * @param array<mixed> $container
     *
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
            if (array_key_exists($key, $versions) && self::stringValue($versions[$key]) !== '') {
                return self::stringValue($versions[$key]);
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
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return array<int, array<string, mixed>>
     */
    private static function sourcePolicyFailures(array $result, array $contract, array $scenarioResults): array
    {
        $artifactPolicy = self::arrayValue($contract, 'artifact_policy') ?? [];
        $forbiddenSources = self::stringList($artifactPolicy['forbidden_sources'] ?? []);
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
        }

        return $failures;
    }

    /**
     * @param array<mixed> $container
     *
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
     * @param list<string> $forbiddenSources
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
                $expectedHistoryOrder = self::arrayValue($requirement, 'expected_history_signal_order');
                $historyOrder = self::evidenceValue($result, $scenarioResult, $scenarioId, 'history_signal_order');

                if (is_numeric($expectedTotal) && is_numeric($queriedTotal)
                    && (int) $queriedTotal !== (int) $expectedTotal) {
                    $failures[] = [
                        'code' => 'unexpected_ordered_signal_total',
                        'scenario_id' => $scenarioId,
                        'expected_total' => (int) $expectedTotal,
                        'actual_total' => (int) $queriedTotal,
                    ];
                }

                if ($expectedHistoryOrder !== null && $historyOrder !== null && $historyOrder !== $expectedHistoryOrder) {
                    $failures[] = [
                        'code' => 'unexpected_ordered_signal_history_order',
                        'scenario_id' => $scenarioId,
                        'expected_order' => $expectedHistoryOrder,
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
                        ['query_sent_at', '<', 'replay_completed_at'],
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
                );
            }

            if ($scenarioId === 'unknown_signal_and_query_errors') {
                array_push(
                    $failures,
                    ...self::statusCodeFailures($result, $scenarioResult, $scenarioId, [
                        'unknown_signal.status_code' => [404, 404],
                        'missing_workflow_signal.status_code' => [404, 404],
                        'missing_workflow_query.status_code' => [404, 404],
                        'query_not_found.status_code' => [404, 404],
                        'cli_unknown_signal_sample.status_code' => [404, 404],
                        'cli_unknown_query_sample.status_code' => [404, 404],
                        'cli_missing_workflow_signal_sample.status_code' => [404, 404],
                        'cli_missing_workflow_query_sample.status_code' => [404, 404],
                        'sdk_python_unknown_signal_sample.status_code' => [404, 404],
                        'sdk_python_unknown_query_sample.status_code' => [404, 404],
                    ]),
                    ...self::unknownHandlerReasonFailures($result, $scenarioResult, $scenarioId),
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
     * @param array<string, mixed> $result
     * @param array<string, mixed> $scenarioResult
     *
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
     * @param array<string, mixed> $result
     * @param array<string, mixed> $scenarioResult
     * @param list<array{0: string, 1: string, 2: string}> $orders
     *
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
     * @param array<string, mixed> $result
     * @param array<string, mixed> $scenarioResult
     * @param array<string, array{0: int, 1: int}> $ranges
     *
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
     * @param array<string, mixed> $result
     * @param array<string, mixed> $scenarioResult
     *
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
     * @param array<string, mixed> $result
     * @param array<string, mixed> $scenarioResult
     *
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
     * @param list<string> $missingScenarios
     * @param array<string, mixed> $result
     *
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
        return self::requiredEvidencePresent(
            $evidenceKey,
            self::evidenceValue($result, $scenarioResult, $scenarioId, $evidenceKey),
        );
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
     * @param array<string, mixed> $result
     *
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
     * @param array<string, mixed> $result
     * @param array<string, mixed> $scenarioResult
     *
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

    private static function pathFor(string $base, int|string $field): string
    {
        if (is_int($field)) {
            return $base . '[' . $field . ']';
        }

        if ($field === '') {
            return $base;
        }

        return $base . '.' . $field;
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
