<?php

namespace App\Support;

/**
 * Evaluates child-workflow conformance results against the public scenario
 * matrix exposed by ChildWorkflowRuntimeContract.
 */
final class ChildWorkflowRuntimeResultGate
{
    public const SCHEMA = 'durable-workflow.v2.child-workflow-runtime.result-gate';

    public const VERSION = 1;

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
            ],
            'scenario_results_fields' => [
                'scenario_results',
                'scenarioResults',
            ],
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
                'each_non_pass_scenario_has_linked_findings',
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

        array_push($failures, ...self::artifactVersionFailures($result, $contract));
        array_push($failures, ...self::sourcePolicyFailures($result, $contract));
        array_push($failures, ...self::matrixFailures($result, $contract));
        array_push($failures, ...self::requiredSectionFailures($result, $scenarioResults));

        $smokeSubsetDetected = self::isSmokeSubset($scenarioStatuses, $contract);
        if ($smokeSubsetDetected) {
            $failures[] = [
                'code' => 'smoke_subset_cannot_pass',
                'reason' => 'Python parent/child smoke coverage is not a complete child workflow conformance result.',
            ];
        }

        $passes = $failures === []
            && $missingScenarios === []
            && $nonPassScenarios === []
            && count($scenarioStatuses) >= count($requiredScenarios);

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
    private static function artifactVersionFailures(array $result, array $contract): array
    {
        $versions = self::arrayValue($result, 'artifact_versions')
            ?? self::arrayValue($result, 'artifactVersions')
            ?? [];

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
