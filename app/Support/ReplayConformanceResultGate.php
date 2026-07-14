<?php

namespace App\Support;

/**
 * Evaluates deterministic replay conformance results against the scenario
 * matrix exposed by ReplayVerificationContract.
 */
final class ReplayConformanceResultGate
{
    public const SCHEMA = 'durable-workflow.v2.replay-conformance.result-gate';

    public const VERSION = 4;

    private const OUTCOME_FIELDS = [
        'outcome',
        'status',
        'verdict',
    ];

    private const PLACEHOLDER_VERSION_EXAMPLES = [
        'latest',
        'current',
        'head',
        'unresolved',
        'placeholder',
        '<latest>',
        '${VERSION}',
        '{{ version }}',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'evaluates_result_schema' => ReplayVerificationContract::REPLAY_CONFORMANCE_RESULT_SCHEMA,
            'scenario_statuses_source' => 'replay_verification_contract.replay_conformance.scenario_statuses',
            'required_scenarios_source' => 'replay_verification_contract.replay_conformance.required_scenarios',
            'required_matrix_source' => 'replay_verification_contract.replay_conformance.required_matrix',
            'required_run_record_fields_source' => 'replay_verification_contract.replay_conformance.artifact_policy.required_run_record_fields',
            'required_artifact_versions_source' => 'replay_verification_contract.replay_conformance.artifact_policy.required_artifact_versions',
            'artifact_version_policy' => [
                'requires_recorded_and_pinned_versions' => true,
                'rejects_placeholder_versions' => true,
                'placeholder_version_examples' => self::PLACEHOLDER_VERSION_EXAMPLES,
            ],
            'artifact_versions_fields' => [
                'artifact_versions',
                'artifactVersions',
                'published_artifact_versions',
                'publishedArtifactVersions',
            ],
            'declared_outcome_fields' => self::OUTCOME_FIELDS,
            'scenario_results_fields' => [
                'scenario_results',
                'scenarioResults',
            ],
            'declared_outcomes_source' => 'replay_verification_contract.replay_conformance.coverage_gate.*_outcome plus pass/fail aliases',
            'non_pass_statuses' => [
                'fail',
                'unsupported',
                'not_covered',
                'runner_blocked',
            ],
            'pass_requires' => [
                'every_required_scenario_has_one_result',
                'every_result_uses_a_published_status',
                'required_php_python_and_rust_runtimes_are_reported',
                'completed_history_families_are_reported_for_each_runtime',
                'worker_restart_replay_cells_are_reported_for_each_runtime',
                'adversarial_refusals_have_actionable_diagnostics',
                'adversarial_refusals_match_required_outcomes',
                'in_flight_signal_timing_is_reported_for_each_runtime',
                'in_flight_signal_timing_matches_required_outcome',
                'each_pass_scenario_has_replay_evidence',
                'each_non_pass_scenario_has_linked_findings',
                'run_record_metadata_is_complete',
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
        $contract = self::replayContract($contract ?? ReplayVerificationContract::manifest());

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
                if (! self::hasReplayEvidence($scenarioResult)) {
                    $failures[] = [
                        'code' => 'missing_pass_replay_evidence',
                        'scenario_id' => $scenarioId,
                    ];
                }

                array_push($failures, ...self::diagnosticFailures($scenarioId, $scenarioResult, $contract));
                array_push($failures, ...self::timingFailures($scenarioId, $scenarioResult, $contract));
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
        array_push($failures, ...self::runRecordFailures($result, $contract));
        array_push($failures, ...self::declaredOutcomeFailures($result, $contract));
        array_push($failures, ...self::runtimeMatrixFailures($result, $contract));
        array_push($failures, ...self::requiredSectionFailures($result, $scenarioResults));

        $smokeSubsetDetected = self::isSmokeSubset($scenarioStatuses, $contract);
        if ($smokeSubsetDetected) {
            $failures[] = [
                'code' => 'smoke_subset_cannot_pass',
                'reason' => 'Python replay smoke coverage is not a complete deterministic replay conformance result.',
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
     * @param array<string, mixed> $contract
     *
     * @return array<string, mixed>
     */
    private static function replayContract(array $contract): array
    {
        $nested = self::arrayValue($contract, 'replay_conformance');

        return $nested ?? $contract;
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
    private static function hasReplayEvidence(array $scenarioResult): bool
    {
        foreach ([
            'observed_outputs',
            'observedOutputs',
            'history_dumps',
            'historyDumps',
            'replay_diagnostics',
            'replayDiagnostics',
            'runtime_matrix',
            'runtimeMatrix',
            'replay_report',
            'replayReport',
            'verification_report',
            'verificationReport',
            'comparison',
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
        $versions = self::artifactVersions($result);

        $failures = [];
        $artifactPolicy = self::arrayValue($contract, 'artifact_policy') ?? [];
        $requiredArtifacts = self::stringList($artifactPolicy['required_artifact_versions'] ?? []);
        if ($requiredArtifacts === []) {
            $requiredArtifacts = array_keys(self::arrayValue($artifactPolicy, 'install_channels') ?? []);
        }

        foreach ($requiredArtifacts as $artifact) {
            $version = self::artifactVersionValue($versions, $artifact);
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
            'sdk-php' => ['sdk-php', 'sdk_php'],
            'workflow-php' => ['workflow-php', 'workflow_php', 'workflow'],
            'sdk-python' => ['sdk-python', 'sdk_python', 'python'],
            'waterline' => ['waterline', 'waterline-ui', 'waterline_ui'],
            'sdk-rust' => ['sdk-rust', 'sdk_rust', 'rust'],
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

        return preg_match(
            '/(^|[^a-z0-9])(latest|current|head|unresolved|placeholder)([^a-z0-9]|$)/',
            $normalized,
        ) === 1;
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
    private static function runRecordFailures(array $result, array $contract): array
    {
        $artifactPolicy = self::arrayValue($contract, 'artifact_policy') ?? [];
        $requiredFields = self::stringList($artifactPolicy['required_run_record_fields'] ?? []);
        $failures = [];

        foreach ($requiredFields as $field) {
            if (self::hasRunRecordField($result, $field)) {
                continue;
            }

            $failures[] = [
                'code' => 'missing_required_run_record_field',
                'field' => $field,
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
     * @return array<string, string>
     */
    private static function declaredOutcomeTokens(array $result): array
    {
        $declaredOutcomes = [];
        foreach (self::OUTCOME_FIELDS as $field) {
            $value = strtolower(self::stringValue($result[$field] ?? null));
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
            'ok',
            'fail',
            'failed',
            'error',
            'non_passing',
        ];

        $coverageGate = self::arrayValue($contract, 'coverage_gate') ?? [];
        foreach ($coverageGate as $key => $value) {
            if (! is_string($key) || ! str_ends_with($key, '_outcome')) {
                continue;
            }

            $outcome = strtolower(self::stringValue($value));
            if ($outcome !== '') {
                $outcomes[] = $outcome;
            }
        }

        return array_values(array_unique($outcomes));
    }

    private static function declaredOutcomeStatus(string $outcome): string
    {
        return in_array($outcome, ['pass', 'passed', 'ok'], true) ? 'pass' : 'non_passing';
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
            'outcome' => self::hasScalarField($result, self::OUTCOME_FIELDS),
            'scenario_results' => self::hasArrayField($result, ['scenario_results', 'scenarioResults'], true),
            'findings' => self::hasArrayField($result, ['findings']),
            'finding_links' => self::hasArrayField($result, ['finding_links', 'findingLinks']),
            default => self::hasScalarField($result, [$field, self::camelize($field)])
                || self::hasArrayField($result, [$field, self::camelize($field)]),
        };
    }

    /**
     * @param array<string, mixed> $result
     * @param list<string> $fields
     */
    private static function hasScalarField(array $result, array $fields): bool
    {
        foreach (array_unique($fields) as $key) {
            if (! array_key_exists($key, $result)) {
                continue;
            }

            if (self::stringValue($result[$key]) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $result
     * @param list<string> $fields
     */
    private static function hasArrayField(array $result, array $fields, bool $requireNonEmpty = false): bool
    {
        foreach (array_unique($fields) as $key) {
            if (! array_key_exists($key, $result) || ! is_array($result[$key])) {
                continue;
            }

            if (! $requireNonEmpty || $result[$key] !== []) {
                return true;
            }
        }

        return false;
    }

    private static function camelize(string $field): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $field))));
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return array<int, array<string, mixed>>
     */
    private static function runtimeMatrixFailures(array $result, array $contract): array
    {
        $matrix = self::arrayValue($result, 'runtime_matrix')
            ?? self::arrayValue($result, 'runtimeMatrix')
            ?? [];
        $failures = [];

        foreach (self::stringList($contract['required_runtimes'] ?? []) as $runtime) {
            if (! self::matrixHasRuntime($matrix, $runtime)) {
                $failures[] = [
                    'code' => 'missing_required_runtime',
                    'runtime' => $runtime,
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
     * @param array<string, mixed> $result
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return array<int, array<string, mixed>>
     */
    private static function requiredSectionFailures(array $result, array $scenarioResults): array
    {
        $sections = [
            'completed_history_replay' => [
                'python_completed_history_activity_replay',
                'python_completed_history_signal_update_replay',
                'python_completed_history_wait_condition_replay',
                'python_completed_history_version_marker_replay',
                'python_completed_history_saga_compensation_replay',
                'php_completed_history_activity_replay',
                'php_completed_history_signal_update_replay',
                'php_completed_history_wait_condition_replay',
                'php_completed_history_version_marker_replay',
                'php_completed_history_saga_compensation_replay',
                'rust_side_effect_replay_after_worker_restart',
            ],
            'worker_restart_replay' => [
                'python_worker_restart_completed_query',
                'python_worker_restart_activity_state',
                'python_worker_restart_signal_update_state',
                'python_worker_restart_wait_condition_state',
                'python_worker_restart_version_marker_state',
                'python_worker_restart_saga_compensation_state',
                'php_worker_restart_completed_query',
                'php_worker_restart_activity_state',
                'php_worker_restart_signal_update_state',
                'php_worker_restart_wait_condition_state',
                'php_worker_restart_version_marker_state',
                'php_worker_restart_saga_compensation_state',
                'rust_version_marker_replay_after_code_upgrade',
            ],
            'adversarial_replay' => [
                'python_code_divergence_refusal',
                'php_code_divergence_refusal',
                'server_history_mutation_refusal',
                'malformed_history_refusal',
            ],
            'in_flight_timing' => [
                'python_in_flight_signal_restart_timing',
                'php_in_flight_signal_restart_timing',
            ],
        ];

        $failures = [];
        foreach ($sections as $section => $scenarios) {
            if (self::arrayValue($result, $section) !== null) {
                continue;
            }

            $coveredByScenarioEvidence = true;
            foreach ($scenarios as $scenarioId) {
                if (! isset($scenarioResults[$scenarioId]) || ! self::hasReplayEvidence($scenarioResults[$scenarioId])) {
                    $coveredByScenarioEvidence = false;
                    break;
                }
            }

            if (! $coveredByScenarioEvidence) {
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
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $contract
     *
     * @return array<int, array<string, mixed>>
     */
    private static function diagnosticFailures(string $scenarioId, array $scenarioResult, array $contract): array
    {
        $diagnosticRequirements = self::arrayValue($contract, 'diagnostic_requirements') ?? [];
        $requirementKey = match ($scenarioId) {
            'python_code_divergence_refusal', 'php_code_divergence_refusal' => 'code_divergence_refusal',
            'server_history_mutation_refusal' => 'server_history_mutation_refusal',
            'malformed_history_refusal' => 'malformed_history_refusal',
            default => null,
        };

        if ($requirementKey === null) {
            return [];
        }

        $requirement = self::arrayValue($diagnosticRequirements, $requirementKey) ?? [];
        $requiredFields = self::stringList($requirement['required_fields'] ?? []);
        $missingFields = [];

        foreach ($requiredFields as $field) {
            if (! self::hasDiagnosticField($scenarioResult, $field)) {
                $missingFields[] = $field;
            }
        }

        $failures = [];
        if ($missingFields !== []) {
            $failures[] = [
                'code' => 'missing_actionable_refusal_diagnostic',
                'scenario_id' => $scenarioId,
                'requirement' => $requirementKey,
                'missing_fields' => $missingFields,
            ];
        }

        array_push($failures, ...self::requiredOutcomeFailures($scenarioId, $scenarioResult, $requirementKey, $requirement));

        return $failures;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $contract
     *
     * @return array<int, array<string, mixed>>
     */
    private static function timingFailures(string $scenarioId, array $scenarioResult, array $contract): array
    {
        if (! in_array($scenarioId, [
            'python_in_flight_signal_restart_timing',
            'php_in_flight_signal_restart_timing',
        ], true)) {
            return [];
        }

        $requiredFields = [
            'worker_restart_at',
            'signal_sent_at',
            'history_reloaded_at',
            'replayed_next_decision',
        ];
        $missingFields = [];
        foreach ($requiredFields as $field) {
            if (! self::hasDiagnosticField($scenarioResult, $field)) {
                $missingFields[] = $field;
            }
        }

        $failures = [];
        if ($missingFields !== []) {
            $failures[] = [
                'code' => 'missing_in_flight_timing_evidence',
                'scenario_id' => $scenarioId,
                'missing_fields' => $missingFields,
            ];
        }

        $diagnosticRequirements = self::arrayValue($contract, 'diagnostic_requirements') ?? [];
        $requirement = self::arrayValue($diagnosticRequirements, 'in_flight_signal_restart_timing') ?? [];
        if (is_array($requirement)) {
            array_push($failures, ...self::requiredOutcomeFailures(
                $scenarioId,
                $scenarioResult,
                'in_flight_signal_restart_timing',
                $requirement,
            ));
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $requirement
     *
     * @return array<int, array<string, mixed>>
     */
    private static function requiredOutcomeFailures(
        string $scenarioId,
        array $scenarioResult,
        string $requirementKey,
        array $requirement,
    ): array {
        $requiredOutcome = self::stringValue($requirement['required_outcome'] ?? null);
        if ($requiredOutcome === '') {
            return [];
        }

        $observedOutcome = self::scenarioOutcome($scenarioResult);
        if ($observedOutcome === $requiredOutcome) {
            return [];
        }

        return [[
            'code' => 'unexpected_required_outcome',
            'scenario_id' => $scenarioId,
            'requirement' => $requirementKey,
            'expected_outcome' => $requiredOutcome,
            'observed_outcome' => $observedOutcome,
        ]];
    }

    /**
     * @param array<string, mixed> $scenarioResult
     */
    private static function scenarioOutcome(array $scenarioResult): string
    {
        foreach ([
            'observed_outcome',
            'observedOutcome',
            'required_outcome',
            'requiredOutcome',
            'outcome',
            'verdict',
        ] as $field) {
            $value = self::stringValue($scenarioResult[$field] ?? null);
            if ($value !== '') {
                return $value;
            }
        }

        foreach ([
            'replay_diagnostics',
            'replayDiagnostics',
            'diagnostics',
            'observed_outputs',
            'observedOutputs',
            'comparison',
        ] as $field) {
            $value = self::arrayValue($scenarioResult, $field);
            if ($value === null) {
                continue;
            }

            foreach ([
                'observed_outcome',
                'observedOutcome',
                'required_outcome',
                'requiredOutcome',
                'outcome',
                'verdict',
            ] as $outcomeField) {
                $outcome = self::stringValue($value[$outcomeField] ?? null);
                if ($outcome !== '') {
                    return $outcome;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $scenarioResult
     */
    private static function hasDiagnosticField(array $scenarioResult, string $field): bool
    {
        $candidateRoots = [$scenarioResult];
        foreach ([
            'replay_diagnostics',
            'replayDiagnostics',
            'diagnostics',
            'observed_outputs',
            'observedOutputs',
            'comparison',
        ] as $fieldName) {
            $value = self::arrayValue($scenarioResult, $fieldName);
            if ($value !== null) {
                $candidateRoots[] = $value;
            }
        }

        foreach ($candidateRoots as $candidateRoot) {
            if (self::hasPath($candidateRoot, $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $value
     */
    private static function hasPath(array $value, string $path): bool
    {
        $segments = explode('.', $path);
        $cursor = $value;

        foreach ($segments as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return false;
            }

            $cursor = $cursor[$segment];
        }

        return self::stringValue($cursor) !== '' || (is_array($cursor) && $cursor !== []);
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

        $passedScenarios = array_keys(array_filter(
            $scenarioStatuses,
            static fn (string $status): bool => $status === 'pass',
        ));
        if ($passedScenarios === []) {
            return false;
        }

        $smokeScenarios = [
            'published_artifact_install_only',
            'python_completed_history_activity_replay',
            'python_completed_history_signal_update_replay',
            'python_completed_history_wait_condition_replay',
            'python_completed_history_version_marker_replay',
            'python_completed_history_saga_compensation_replay',
            'python_worker_restart_completed_query',
            'python_worker_restart_activity_state',
            'python_worker_restart_signal_update_state',
        ];

        return array_diff($passedScenarios, $smokeScenarios) === []
            && array_diff($requiredScenarios, $passedScenarios) !== [];
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
