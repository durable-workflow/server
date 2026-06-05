<?php

namespace App\Support;

/**
 * Evaluates v1-to-v2 migration conformance results against the full upgrade
 * matrix exposed by MigrationRuntimeContract.
 */
final class MigrationRuntimeResultGate
{
    public const SCHEMA = 'durable-workflow.v2.migration-runtime.result-gate';

    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'evaluates_result_schema' => MigrationRuntimeContract::RESULT_SCHEMA,
            'scenario_statuses_source' => 'migration_runtime_contract.scenario_statuses',
            'required_scenarios_source' => 'migration_runtime_contract.required_scenarios',
            'required_matrix_source' => 'migration_runtime_contract.required_matrix',
            'required_run_record_fields_source' => 'migration_runtime_contract.artifact_policy.required_run_record_fields',
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
            'declared_outcomes_source' => 'migration_runtime_contract.coverage_gate.*_outcome',
            'non_pass_statuses' => [
                'fail',
                'unsupported',
                'not_covered',
                'runner_blocked',
            ],
            'artifact_version_policy' => [
                'requires_recorded_and_pinned_versions' => true,
                'requires_v1_and_v2_artifact_versions' => true,
                'rejects_placeholder_versions' => true,
                'placeholder_version_examples' => [
                    'latest',
                    'current',
                    'head',
                    'unresolved',
                    'placeholder',
                    '<latest>',
                    '1.x',
                    '2.0.0-alpha.<latest>',
                    '${VERSION}',
                    '{{ version }}',
                ],
            ],
            'pass_requires' => [
                'every_required_scenario_has_one_result',
                'every_result_uses_a_published_status',
                'latest_supported_v1_state_is_seeded',
                'public_migration_guide_steps_are_executed_verbatim',
                'completed_histories_remain_readable_and_replay_safe',
                'in_flight_progress_retry_schedules_and_workers_are_preserved',
                'cli_and_waterline_operator_surfaces_cover_preupgrade_state',
                'new_v2_starts_are_verified_after_upgrade',
                'rollback_contract_or_documented_no_rollback_is_verified',
                'version_skew_refuses_loudly_without_partial_mutation',
                'storage_connection_smoke_is_recorded_but_not_counted_as_complete',
                'each_pass_scenario_has_observed_outputs',
                'each_pass_scenario_has_scenario_specific_evidence',
                'each_non_pass_scenario_has_linked_findings',
                'run_timestamps_outcome_and_finding_links_are_recorded',
                'contract_required_run_record_fields_are_recorded',
                'overall_outcome_matches_gate_status',
                'published_artifact_versions_are_recorded_and_pinned',
                'resolved_artifact_versions_are_recorded_and_pinned',
                'v1_and_v2_artifact_versions_are_distinguished',
                'artifact_source_recorded_for_each_install_channel',
                'artifact_prerequisite_failures_are_linked_when_artifacts_are_missing',
                'no_local_product_source_artifacts_are_reported',
                'runner_blocked_false_for_product_evidence',
            ],
            'smoke_subset_outcome' => 'non_passing',
            'storage_connection_smoke_only_outcome' => 'non_passing',
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
        $contract ??= MigrationRuntimeContract::manifest();

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

                foreach (self::missingRequiredFields($scenarioId, $scenarioResult, $contract) as $field) {
                    $failures[] = [
                        'code' => 'missing_scenario_required_field',
                        'scenario_id' => $scenarioId,
                        'field' => $field,
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
            if ($status !== '' && ! in_array($status, $allowedStatuses, true)) {
                $failures[] = [
                    'code' => 'invalid_extra_scenario_status',
                    'scenario_id' => $scenarioId,
                    'status' => $status,
                    'allowed_statuses' => $allowedStatuses,
                ];
            }
        }

        array_push($failures, ...self::runRecordFailures($result, $contract));
        array_push($failures, ...self::artifactVersionFailures($result, $contract));
        array_push($failures, ...self::sourcePolicyFailures($result, $contract, $scenarioResults));
        array_push($failures, ...self::declaredOutcomeFailures($result));

        $smokeSubsetDetected = self::isSmokeSubset($scenarioResults, $requiredScenarios);
        if ($smokeSubsetDetected) {
            $failures[] = [
                'code' => 'storage_connection_smoke_cannot_pass',
                'reason' => 'Storage-connection migration smoke is useful evidence but is not a complete v1-to-v2 migration result.',
            ];
        }

        $evidencePasses = $failures === []
            && $missingScenarios === []
            && $nonPassScenarios === []
            && count($scenarioStatuses) >= count($requiredScenarios);
        $evaluatedStatus = $evidencePasses ? 'pass' : 'non_passing';

        array_push($failures, ...self::declaredOutcomeStatusFailures($result, $evaluatedStatus));

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
        foreach (['observed_outputs', 'observedOutputs', 'evidence'] as $field) {
            if (isset($scenarioResult[$field]) && is_array($scenarioResult[$field]) && $scenarioResult[$field] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $contract
     *
     * @return list<string>
     */
    private static function missingRequiredFields(string $scenarioId, array $scenarioResult, array $contract): array
    {
        $requirements = $contract['scenario_requirements'][$scenarioId]['required_fields'] ?? [];
        if (! is_array($requirements) || $requirements === []) {
            return [];
        }

        $observedOutputs = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']) ?? [];
        $missing = [];

        foreach (self::stringList($requirements) as $field) {
            if (
                ! self::hasAnyEvidenceValue($scenarioResult, self::fieldAliases($field))
                && ! self::hasAnyEvidenceValue($observedOutputs, self::fieldAliases($field))
            ) {
                $missing[] = $field;
            }
        }

        array_push(
            $missing,
            ...self::missingScenarioSpecificRequiredFields($scenarioId, $scenarioResult, $observedOutputs),
        );

        return array_values(array_unique($missing));
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $observedOutputs
     *
     * @return list<string>
     */
    private static function missingScenarioSpecificRequiredFields(
        string $scenarioId,
        array $scenarioResult,
        array $observedOutputs,
    ): array {
        return match ($scenarioId) {
            'latest_supported_v1_state_setup' => [
                ...self::missingEvidenceItemsForField($scenarioResult, $observedOutputs, 'seeded_workflows', [
                    'completed_workflow',
                    'running_workflow_waiting_on_signal',
                    'workflow_with_activity',
                    'workflow_mid_activity_retry',
                ]),
                ...self::missingEvidenceItemsForField($scenarioResult, $observedOutputs, 'seeded_schedules', [
                    'active_schedule',
                ]),
                ...self::missingEvidenceItemsForField($scenarioResult, $observedOutputs, 'seeded_worker_registrations', [
                    'registered_workers',
                ]),
                ...self::missingEvidenceItemsForField($scenarioResult, $observedOutputs, 'queryable_history', [
                    'queryable_history',
                ]),
            ],
            'documented_migration_steps_execute' => self::missingArrayEvidenceFields(
                $scenarioResult,
                $observedOutputs,
                [
                    'commands_executed',
                    'exit_codes',
                    'command_timings',
                ],
            ),
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $observedOutputs
     * @param list<string> $items
     *
     * @return list<string>
     */
    private static function missingEvidenceItemsForField(
        array $scenarioResult,
        array $observedOutputs,
        string $field,
        array $items,
    ): array {
        $value = self::fieldValue($observedOutputs, $field);
        if (self::isEmptyEvidence($value)) {
            $value = self::fieldValue($scenarioResult, $field);
        }

        $missing = [];
        foreach ($items as $item) {
            if (! self::hasEvidenceItem($value, $item)) {
                $missing[] = $field . '.' . $item;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $observedOutputs
     * @param list<string> $fields
     *
     * @return list<string>
     */
    private static function missingArrayEvidenceFields(
        array $scenarioResult,
        array $observedOutputs,
        array $fields,
    ): array {
        $missing = [];
        foreach ($fields as $field) {
            if (
                ! self::hasNonEmptyArrayEvidence($observedOutputs, $field)
                && ! self::hasNonEmptyArrayEvidence($scenarioResult, $field)
            ) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $result
     */
    private static function hasLinkedFindings(array $scenarioResult, array $result): bool
    {
        foreach (['linked_findings', 'linkedFindings', 'findings', 'finding_links', 'findingLinks'] as $field) {
            if (isset($scenarioResult[$field]) && self::nonEmptyFindingValue($scenarioResult[$field])) {
                return true;
            }
        }

        $scenarioId = self::stringValue($scenarioResult['scenario_id'] ?? null);
        foreach (['finding_links', 'findingLinks', 'linked_findings', 'linkedFindings', 'findings'] as $field) {
            $value = $result[$field] ?? null;
            if (is_array($value) && $scenarioId !== '' && isset($value[$scenarioId]) && self::nonEmptyFindingValue($value[$scenarioId])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     */
    private static function nonEmptyFindingValue(mixed $value): bool
    {
        if (is_string($value)) {
            return $value !== '';
        }

        return is_array($value) && $value !== [];
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

        array_push($failures, ...self::stateSnapshotFailures($result, $contract));

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return list<array<string, mixed>>
     */
    private static function stateSnapshotFailures(array $result, array $contract): array
    {
        $failures = [];
        $requiredStateKinds = self::stringList($contract['required_matrix']['state_kinds'] ?? []);

        foreach (['preupgrade_state_snapshot', 'postupgrade_state_snapshot'] as $field) {
            $snapshot = self::fieldValue($result, $field);
            if (! is_array($snapshot) || self::isEmptyEvidence($snapshot)) {
                continue;
            }

            $stateKinds = self::observedStateKindsForSnapshot($snapshot);
            foreach ($requiredStateKinds as $stateKind) {
                if (isset($stateKinds[$stateKind])) {
                    continue;
                }

                $failures[] = [
                    'code' => 'missing_run_record_state_kind',
                    'field' => $field,
                    'state_kind' => $stateKind,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<mixed> $snapshot
     *
     * @return array<string, true>
     */
    private static function observedStateKindsForSnapshot(array $snapshot): array
    {
        $observed = [];

        foreach (['state_kinds', 'stateKinds'] as $field) {
            if (isset($snapshot[$field]) && is_array($snapshot[$field])) {
                self::collectStateKindList($snapshot[$field], $observed);
            }
        }

        self::collectObservedStateEntries($snapshot, $observed);

        foreach ([
            'observed_states',
            'observedStates',
            'observed_state_entries',
            'observedStateEntries',
            'state_entries',
            'stateEntries',
            'states',
        ] as $field) {
            if (isset($snapshot[$field]) && is_array($snapshot[$field])) {
                self::collectObservedStateEntries($snapshot[$field], $observed);
            }
        }

        return $observed;
    }

    /**
     * @param array<mixed> $stateKinds
     * @param array<string, true> $observed
     */
    private static function collectStateKindList(array $stateKinds, array &$observed): void
    {
        $isList = array_is_list($stateKinds);

        foreach ($stateKinds as $key => $entry) {
            if ($isList) {
                self::collectObservedStateEntryKind($entry, $observed);

                continue;
            }

            if (is_string($key) && $key !== '' && ! self::isEmptyEvidence($entry)) {
                $observed[$key] = true;
            }

            self::collectObservedStateEntryKind($entry, $observed);
        }
    }

    /**
     * @param array<mixed> $entries
     * @param array<string, true> $observed
     */
    private static function collectObservedStateEntries(array $entries, array &$observed): void
    {
        $isList = array_is_list($entries);

        foreach ($entries as $key => $entry) {
            if ($isList) {
                self::collectObservedStateEntryKind($entry, $observed);

                continue;
            }

            if (is_string($key) && $key !== '' && ! self::isEmptyEvidence($entry)) {
                $observed[$key] = true;
            }

            self::collectObservedStateEntryKind($entry, $observed);
        }
    }

    /**
     * @param array<string, true> $observed
     */
    private static function collectObservedStateEntryKind(mixed $entry, array &$observed): void
    {
        $kind = self::stringValue($entry);
        if ($kind !== '') {
            $observed[$kind] = true;

            return;
        }

        if (! is_array($entry)) {
            return;
        }

        foreach (['state_kind', 'stateKind', 'kind', 'type', 'name', 'scenario'] as $field) {
            $kind = self::stringValue($entry[$field] ?? null);
            if ($kind !== '') {
                $observed[$kind] = true;
            }
        }
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
            'findings' => self::hasArrayKey($result, ['findings']),
            'finding_links' => self::hasArrayKey($result, ['finding_links', 'findingLinks']),
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
        $requiredArtifacts = self::stringList(array_keys($contract['artifact_policy']['install_channels'] ?? []));
        $aliases = $contract['artifact_policy']['release_artifact_aliases'] ?? [];
        $placeholders = self::stringList($contract['artifact_policy']['placeholder_version_examples'] ?? []);

        foreach ([
            'artifact_versions' => ['artifact_versions', 'artifactVersions'],
            'published_artifact_versions' => ['published_artifact_versions', 'publishedArtifactVersions'],
            'resolved_artifact_versions' => ['resolved_artifact_versions', 'resolvedArtifactVersions'],
        ] as $field => $fieldAliases) {
            $versions = self::arrayField($result, $fieldAliases);
            if ($versions === null) {
                continue;
            }

            foreach ($requiredArtifacts as $artifact) {
                $version = self::artifactVersionFor($versions, $artifact, is_array($aliases) ? $aliases : []);
                if ($version === '') {
                    $failures[] = [
                        'code' => 'missing_artifact_version',
                        'field' => $field,
                        'artifact' => $artifact,
                    ];
                    continue;
                }

                foreach ($placeholders as $placeholder) {
                    if ($placeholder !== '' && str_contains(strtolower($version), strtolower($placeholder))) {
                        $failures[] = [
                            'code' => 'placeholder_artifact_version',
                            'field' => $field,
                            'artifact' => $artifact,
                            'version' => $version,
                            'placeholder' => $placeholder,
                        ];
                        break;
                    }
                }
            }
        }

        return $failures;
    }

    /**
     * @param array<int|string, mixed> $versions
     * @param array<string, mixed> $aliases
     */
    private static function artifactVersionFor(array $versions, string $artifact, array $aliases): string
    {
        if (isset($versions[$artifact]) && (is_string($versions[$artifact]) || is_numeric($versions[$artifact]))) {
            $version = self::stringValue($versions[$artifact]);
            if ($version !== '') {
                return $version;
            }
        }

        foreach (self::stringList($aliases[$artifact] ?? []) as $alias) {
            if (isset($versions[$alias]) && (is_string($versions[$alias]) || is_numeric($versions[$alias]))) {
                $version = self::stringValue($versions[$alias]);
                if ($version !== '') {
                    return $version;
                }
            }
        }

        return '';
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
        $failures = [];
        $forbiddenSources = self::stringList($contract['artifact_policy']['forbidden_sources'] ?? []);
        $sourceSets = self::reportedArtifactSourceSets($result, $scenarioResults);
        $installSources = [];
        $localSourceFlags = self::localProductSourceFlagReports($result, $scenarioResults);

        if ($localSourceFlags === []) {
            $failures[] = [
                'code' => 'missing_local_product_source_policy',
            ];
        }

        foreach ($localSourceFlags as $flag) {
            if (! self::boolValue($flag['value'])) {
                continue;
            }

            $failure = [
                'code' => 'local_product_source_artifacts_reported',
                'field' => $flag['field'],
                'value' => $flag['value'],
            ];
            if ($flag['scenario_id'] !== null) {
                $failure['scenario_id'] = $flag['scenario_id'];
            }

            $failures[] = $failure;
        }

        if ($sourceSets === []) {
            $failures[] = [
                'code' => 'missing_artifact_sources',
            ];
        }

        foreach ($sourceSets as $sourceSet) {
            foreach ($sourceSet['sources'] as $artifact => $source) {
                if (
                    ($sourceSet['counts_for_required_sources'] ?? false)
                    && is_string($artifact)
                    && in_array($sourceSet['scenario_id'], [null, 'published_artifact_install_only'], true)
                ) {
                    if (self::sourceValueRecorded($source) || ! array_key_exists($artifact, $installSources)) {
                        $installSources[$artifact] = $source;
                    }
                }

                $sourceString = is_scalar($source) ? (string) $source : json_encode($source);
                $sourceString = is_string($sourceString) ? $sourceString : '';

                foreach ($forbiddenSources as $forbiddenSource) {
                    if ($forbiddenSource === '' || ! str_contains(strtolower($sourceString), strtolower($forbiddenSource))) {
                        continue;
                    }

                    $failure = [
                        'code' => 'forbidden_artifact_source',
                        'artifact' => is_string($artifact) ? $artifact : null,
                        'source' => $sourceString,
                        'field' => $sourceSet['field'],
                    ];
                    if ($sourceSet['scenario_id'] !== null) {
                        $failure['scenario_id'] = $sourceSet['scenario_id'];
                    }

                    $failures[] = $failure;
                    break;
                }
            }
        }

        foreach (array_keys($contract['artifact_policy']['install_channels'] ?? []) as $artifact) {
            $artifact = (string) $artifact;
            if (self::sourceValueRecorded($installSources[$artifact] ?? null)) {
                continue;
            }

            $failures[] = [
                'code' => 'missing_published_artifact_install_source',
                'scenario_id' => 'published_artifact_install_only',
                'artifact' => $artifact,
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return list<array{field: string, scenario_id: string|null, value: mixed}>
     */
    private static function localProductSourceFlagReports(array $result, array $scenarioResults): array
    {
        $reports = [];

        self::appendLocalProductSourceFlagReports($reports, $result, null, '');

        foreach ($scenarioResults as $scenarioId => $scenarioResult) {
            self::appendLocalProductSourceFlagReports($reports, $scenarioResult, $scenarioId, '');
            $outputs = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']);
            if ($outputs !== null) {
                self::appendLocalProductSourceFlagReports($reports, $outputs, $scenarioId, 'observed_outputs.');
            }
        }

        return $reports;
    }

    /**
     * @param list<array{field: string, scenario_id: string|null, value: mixed}> $reports
     * @param array<string, mixed> $container
     */
    private static function appendLocalProductSourceFlagReports(
        array &$reports,
        array $container,
        ?string $scenarioId,
        string $fieldPrefix,
    ): void {
        foreach (['local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed'] as $field) {
            if (! array_key_exists($field, $container)) {
                continue;
            }

            $reports[] = [
                'field' => $fieldPrefix . $field,
                'scenario_id' => $scenarioId,
                'value' => $container[$field],
            ];
        }
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return list<array{sources: array<mixed>, field: string, scenario_id: string|null, counts_for_required_sources: bool}>
     */
    private static function reportedArtifactSourceSets(array $result, array $scenarioResults): array
    {
        $sourceSets = [];
        $containers = [
            [
                'container' => $result,
                'scenario_id' => null,
            ],
        ];

        foreach ($scenarioResults as $scenarioId => $scenarioResult) {
            $containers[] = [
                'container' => $scenarioResult,
                'scenario_id' => $scenarioId,
            ];
            $outputs = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']);
            if ($outputs !== null) {
                $containers[] = [
                    'container' => $outputs,
                    'scenario_id' => $scenarioId,
                ];
            }
        }

        foreach ($containers as $entry) {
            $container = $entry['container'];
            if (! is_array($container)) {
                continue;
            }

            foreach ([
                'artifact_sources' => true,
                'artifactSources' => true,
                'install_sources' => true,
                'installSources' => true,
                'source_paths' => false,
                'sourcePaths' => false,
            ] as $field => $countsForRequiredSources) {
                $sources = self::arrayField($container, [$field]);
                if ($sources === null) {
                    continue;
                }

                $sourceSets[] = [
                    'sources' => $sources,
                    'field' => $field,
                    'scenario_id' => is_string($entry['scenario_id']) ? $entry['scenario_id'] : null,
                    'counts_for_required_sources' => $countsForRequiredSources,
                ];
            }
        }

        return $sourceSets;
    }

    private static function sourceValueRecorded(mixed $value): bool
    {
        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return false;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<array<string, mixed>>
     */
    private static function declaredOutcomeFailures(array $result): array
    {
        $declared = [];
        foreach (['outcome', 'status', 'verdict'] as $field) {
            $value = self::stringValue($result[$field] ?? null);
            if ($value !== '') {
                $declared[$field] = $value;
            }
        }

        if ($declared === []) {
            return [[
                'code' => 'missing_declared_outcome',
            ]];
        }

        if (count(array_unique($declared)) > 1) {
            return [[
                'code' => 'conflicting_outcome_tokens',
                'declared_outcomes' => $declared,
            ]];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<array<string, mixed>>
     */
    private static function declaredOutcomeStatusFailures(array $result, string $evaluatedStatus): array
    {
        $declared = [];
        foreach (['outcome', 'status', 'verdict'] as $field) {
            $value = self::stringValue($result[$field] ?? null);
            if ($value !== '') {
                $declared[$field] = $value;
            }
        }

        if ($declared === []) {
            return [];
        }

        $declaredPasses = array_values(array_unique($declared)) === ['pass'];
        if ($evaluatedStatus === 'pass' && ! $declaredPasses) {
            return [[
                'code' => 'declared_outcome_status_mismatch',
                'declared_outcomes' => $declared,
                'evaluated_status' => $evaluatedStatus,
            ]];
        }

        if ($evaluatedStatus !== 'pass' && $declaredPasses) {
            return [[
                'code' => 'declared_outcome_status_mismatch',
                'declared_outcomes' => $declared,
                'evaluated_status' => $evaluatedStatus,
            ]];
        }

        return [];
    }

    /**
     * @param array<string, array<string, mixed>> $scenarioResults
     * @param list<string> $requiredScenarios
     */
    private static function isSmokeSubset(array $scenarioResults, array $requiredScenarios): bool
    {
        if ($scenarioResults === []) {
            return false;
        }

        $reported = array_keys($scenarioResults);
        $smokeOnlyIds = [
            'storage_connection_smoke',
            'migration_storage_connection_smoke',
            'published_artifact_install_only',
        ];

        if (array_diff($reported, $smokeOnlyIds) === []) {
            return true;
        }

        if (
            (isset($scenarioResults['storage_connection_smoke']) || isset($scenarioResults['migration_storage_connection_smoke']))
            && count(array_intersect($reported, $requiredScenarios)) < count($requiredScenarios)
        ) {
            return true;
        }

        return false;
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
     * @return array<string, string>
     */
    private static function declaredOutcomeTokens(array $result): array
    {
        $declared = [];
        foreach (['outcome', 'status', 'verdict'] as $field) {
            $value = self::stringValue($result[$field] ?? null);
            if ($value !== '') {
                $declared[$field] = $value;
            }
        }

        return $declared;
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function fieldValue(array $result, string $field): mixed
    {
        foreach (self::fieldAliases($field) as $alias) {
            if (array_key_exists($alias, $result)) {
                return $result[$alias];
            }
        }

        return null;
    }

    private static function isEmptyEvidence(mixed $value): bool
    {
        if ($value === null || $value === [] || (is_string($value) && trim($value) === '')) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        $status = strtolower(self::stringValue($value['status'] ?? null));

        return in_array($status, ['not_covered', 'runner_blocked'], true)
            || self::boolValue($value['coverage_gap'] ?? false);
    }

    /**
     * @param array<string, mixed> $result
     * @param list<string> $fields
     */
    private static function hasScalarField(array $result, array $fields): bool
    {
        foreach ($fields as $field) {
            if (self::stringValue($result[$field] ?? null) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $result
     * @param list<string> $fields
     */
    private static function hasArrayKey(array $result, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $result) && is_array($result[$field])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $array
     * @param list<string> $keys
     */
    private static function arrayField(array $array, array $keys): ?array
    {
        foreach ($keys as $key) {
            if (isset($array[$key]) && is_array($array[$key])) {
                return $array[$key];
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $entry): string => is_string($entry) || is_numeric($entry) ? (string) $entry : '',
                $value,
            ),
            static fn (string $entry): bool => $entry !== '',
        ));
    }

    /**
     * @param mixed $value
     */
    private static function stringValue(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }

    /**
     * @param mixed $value
     */
    private static function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes'], true);
        }

        return (bool) $value;
    }

    /**
     * @return list<string>
     */
    private static function fieldAliases(string $field): array
    {
        $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $field))));

        return array_values(array_unique([$field, $camel]));
    }

    /**
     * @param array<string, mixed> $array
     * @param list<string> $keys
     */
    private static function hasAnyEvidenceValue(array $array, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $array) && ! self::isEmptyEvidence($array[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $array
     */
    private static function hasNonEmptyArrayEvidence(array $array, string $field): bool
    {
        foreach (self::fieldAliases($field) as $alias) {
            if (
                array_key_exists($alias, $array)
                && is_array($array[$alias])
                && ! self::isEmptyEvidence($array[$alias])
            ) {
                return true;
            }
        }

        return false;
    }

    private static function hasEvidenceItem(mixed $value, string $item): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach (self::fieldAliases($item) as $alias) {
            if (array_key_exists($alias, $value) && ! self::isEmptyEvidence($value[$alias])) {
                return true;
            }
        }

        foreach (['state_kinds', 'stateKinds', 'kinds', 'items'] as $field) {
            if (isset($value[$field]) && self::hasEvidenceItem($value[$field], $item)) {
                return true;
            }
        }

        foreach ($value as $entry) {
            if (self::stringValue($entry) === $item) {
                return true;
            }

            if (! is_array($entry)) {
                continue;
            }

            foreach (['id', 'kind', 'type', 'state_kind', 'stateKind', 'name', 'scenario'] as $field) {
                if (self::stringValue($entry[$field] ?? null) === $item && ! self::isEmptyEvidence($entry)) {
                    return true;
                }
            }

            if (self::hasEvidenceItem($entry, $item)) {
                return true;
            }
        }

        return false;
    }
}
