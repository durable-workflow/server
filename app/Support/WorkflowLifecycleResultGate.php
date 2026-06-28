<?php

namespace App\Support;

/**
 * Evaluates workflow lifecycle conformance results before pass evidence can be
 * counted by the coverage gate.
 */
final class WorkflowLifecycleResultGate
{
    public const SCHEMA = 'durable-workflow.v2.workflow-lifecycle.result-gate';

    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'evaluates_result_schema' => WorkflowLifecycleContract::RESULT_SCHEMA,
            'scenario_statuses_source' => 'workflow_lifecycle_contract.scenario_statuses',
            'required_scenarios_source' => 'workflow_lifecycle_contract.required_scenarios',
            'required_run_record_fields_source' => 'workflow_lifecycle_contract.artifact_policy.required_run_record_fields',
            'artifact_versions_fields' => [
                'artifact_versions',
                'artifactVersions',
                'published_artifact_versions',
                'publishedArtifactVersions',
            ],
            'artifact_sources_fields' => [
                'artifact_sources',
                'artifactSources',
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
            'lifecycle_cell_outcomes_fields' => [
                'lifecycle_cell_outcomes',
                'lifecycleCellOutcomes',
            ],
            'local_product_source_truthy_values' => [
                true,
                1,
                '1',
                'true',
                'yes',
                'on',
            ],
            'non_pass_statuses' => [
                'fail',
                'unsupported',
                'not_covered',
                'runner_blocked',
            ],
            'pass_requires' => [
                'every_required_lifecycle_cell_has_one_result',
                'every_result_uses_a_published_status',
                'run_timestamps_outcome_runner_blocked_and_findings_are_recorded',
                'artifact_sources_are_recorded_for_required_artifacts',
                'lifecycle_cell_outcomes_are_recorded_for_required_cells',
                'source_policy_is_recorded',
                'local_product_source_checkouts_used_is_explicitly_false',
                'local_product_source_truthy_values_are_refused_consistently',
                'no_local_product_source_artifacts_are_reported',
                'each_non_pass_cell_has_focused_findings',
                'overall_outcome_matches_gate_status',
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
        $contract ??= WorkflowLifecycleContract::manifest();

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
                'code' => 'duplicate_lifecycle_cell_result',
                'scenario_id' => $scenarioId,
                'count' => $count,
            ];
        }

        foreach ($requiredScenarios as $scenarioId) {
            if (! array_key_exists($scenarioId, $scenarioResults)) {
                $missingScenarios[] = $scenarioId;
                $failures[] = [
                    'code' => 'missing_required_lifecycle_cell',
                    'scenario_id' => $scenarioId,
                ];
                continue;
            }

            $scenarioResult = $scenarioResults[$scenarioId];
            $status = self::normalizedStatus($scenarioResult['status'] ?? null);
            $scenarioStatuses[$scenarioId] = $status;

            if (! in_array($status, $allowedStatuses, true)) {
                $failures[] = [
                    'code' => 'invalid_lifecycle_cell_status',
                    'scenario_id' => $scenarioId,
                    'status' => $status,
                    'allowed_statuses' => $allowedStatuses,
                ];
                continue;
            }

            $cellOutcome = self::lifecycleCellOutcomeStatus($result, $scenarioResult, $scenarioId);
            if ($cellOutcome === '') {
                $failures[] = [
                    'code' => 'missing_lifecycle_cell_outcome',
                    'scenario_id' => $scenarioId,
                ];
            } elseif ($cellOutcome !== $status) {
                $failures[] = [
                    'code' => 'contradictory_lifecycle_cell_outcome',
                    'scenario_id' => $scenarioId,
                    'status' => $status,
                    'lifecycle_cell_outcome' => $cellOutcome,
                ];
            }

            if ($status === 'pass') {
                if (! self::hasObservedOutputs($scenarioResult)) {
                    $failures[] = [
                        'code' => 'missing_pass_observed_outputs',
                        'scenario_id' => $scenarioId,
                    ];
                }

                foreach (self::requiredScenarioFields($contract, $scenarioId) as $field) {
                    if (! self::hasScenarioEvidenceField($result, $scenarioResult, $field, $scenarioId)) {
                        $failures[] = [
                            'code' => 'missing_lifecycle_cell_required_field',
                            'scenario_id' => $scenarioId,
                            'field' => $field,
                        ];
                    }
                }
            } else {
                $nonPassScenarios[] = $scenarioId;
                if (! self::hasFocusedFinding($result, $scenarioResult, $scenarioId)) {
                    $failures[] = [
                        'code' => 'missing_focused_finding_for_non_pass_cell',
                        'scenario_id' => $scenarioId,
                        'status' => $status,
                    ];
                }
            }
        }

        $reportedScenarioIds = array_keys($scenarioResults);
        $unknownScenarios = array_values(array_diff($reportedScenarioIds, $requiredScenarios));
        foreach ($unknownScenarios as $scenarioId) {
            $status = self::normalizedStatus($scenarioResults[$scenarioId]['status'] ?? null);
            if (! in_array($status, $allowedStatuses, true)) {
                $failures[] = [
                    'code' => 'invalid_extra_lifecycle_cell_status',
                    'scenario_id' => $scenarioId,
                    'status' => $status,
                    'allowed_statuses' => $allowedStatuses,
                ];
            }
        }

        array_push($failures, ...self::runRecordFailures($result, $contract));
        array_push($failures, ...self::declaredOutcomeFailures($result));
        array_push($failures, ...self::artifactVersionFailures($result, $contract));
        array_push($failures, ...self::sourcePolicyFailures($result, $contract, $scenarioResults));

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
     * @param array<string, mixed> $contract
     *
     * @return list<string>
     */
    private static function requiredScenarioFields(array $contract, string $scenarioId): array
    {
        $requirements = $contract['scenario_requirements'][$scenarioId] ?? [];

        return self::stringList(is_array($requirements) ? ($requirements['required_fields'] ?? []) : []);
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $scenarioResult
     */
    private static function hasScenarioEvidenceField(
        array $result,
        array $scenarioResult,
        string $field,
        string $scenarioId,
    ): bool {
        $outputs = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']) ?? [];

        return match ($field) {
            'observed_outputs' => ! self::isEmptyEvidence($outputs),
            'lifecycle_cell_outcome' => self::lifecycleCellOutcomeStatus($result, $scenarioResult, $scenarioId) !== '',
            'artifact_sources' => ! self::isEmptyEvidence(
                self::arrayField($scenarioResult, ['artifact_sources', 'artifactSources'])
                    ?? self::arrayField($outputs, ['artifact_sources', 'artifactSources'])
                    ?? self::arrayField($result, ['artifact_sources', 'artifactSources']),
            ),
            'local_product_source_checkouts_used' => self::hasExplicitFalseLocalProductSourceFlag(
                $scenarioResult,
                $outputs,
                $result,
            ),
            'source_policy' => ! self::isEmptyEvidence(
                self::arrayField($scenarioResult, ['source_policy', 'sourcePolicy'])
                    ?? self::arrayField($outputs, ['source_policy', 'sourcePolicy'])
                    ?? self::arrayField($result, ['source_policy', 'sourcePolicy']),
            ),
            default => ! self::isEmptyEvidence(
                self::fieldValue($scenarioResult, $field)
                    ?? self::fieldValue($outputs, $field)
                    ?? self::fieldValue($result, $field),
            ),
        };
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

        $runnerBlocked = self::firstFieldValue($result, ['runner_blocked', 'runnerBlocked']);
        if ($runnerBlocked !== null && ! self::explicitFalse($runnerBlocked)) {
            $failures[] = [
                'code' => 'runner_blocked_result_is_not_product_evidence',
                'value' => $runnerBlocked,
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
            'artifact_versions' => ! self::isEmptyEvidence(self::arrayField($result, [
                'artifact_versions',
                'artifactVersions',
            ])),
            'published_artifact_versions' => ! self::isEmptyEvidence(self::arrayField($result, [
                'published_artifact_versions',
                'publishedArtifactVersions',
            ])),
            'artifact_sources' => ! self::isEmptyEvidence(self::arrayField($result, [
                'artifact_sources',
                'artifactSources',
            ])),
            'started_at' => self::hasScalarField($result, ['started_at', 'startedAt']),
            'finished_at' => self::hasScalarField($result, ['finished_at', 'finishedAt']),
            'generated_at' => self::hasScalarField($result, ['generated_at', 'generatedAt']),
            'outcome' => self::declaredOutcomeTokens($result) !== [],
            'runner_blocked' => array_key_exists('runner_blocked', $result) || array_key_exists('runnerBlocked', $result),
            'scenario_results' => self::arrayField($result, ['scenario_results', 'scenarioResults']) !== null,
            'lifecycle_cell_outcomes' => ! self::isEmptyEvidence(self::arrayField($result, [
                'lifecycle_cell_outcomes',
                'lifecycleCellOutcomes',
            ])),
            'findings' => array_key_exists('findings', $result) && is_array($result['findings']),
            'local_product_source_checkouts_used' => self::hasAnyField($result, self::localProductSourceFlagFields()),
            'source_policy' => ! self::isEmptyEvidence(self::arrayField($result, ['source_policy', 'sourcePolicy'])),
            default => ! self::isEmptyEvidence(self::fieldValue($result, $field)),
        };
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<array<string, mixed>>
     */
    private static function declaredOutcomeFailures(array $result): array
    {
        $tokens = self::declaredOutcomeTokens($result);
        if ($tokens === []) {
            return [[
                'code' => 'missing_declared_outcome',
            ]];
        }

        $normalized = array_values(array_unique(array_map(
            static fn (string $token): string => self::outcomeStatus($token),
            array_values($tokens),
        )));

        if (count($normalized) <= 1) {
            return [];
        }

        return [[
            'code' => 'contradictory_declared_outcome',
            'declared_outcomes' => $tokens,
        ]];
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<array<string, mixed>>
     */
    private static function declaredOutcomeStatusFailures(array $result, string $evaluatedStatus): array
    {
        $tokens = self::declaredOutcomeTokens($result);
        if ($tokens === []) {
            return [];
        }

        $declaredStatus = self::outcomeStatus(reset($tokens));
        if ($declaredStatus === $evaluatedStatus) {
            return [];
        }

        return [[
            'code' => 'declared_outcome_mismatch',
            'declared_outcome' => reset($tokens),
            'evaluated_status' => $evaluatedStatus,
        ]];
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, string>
     */
    private static function declaredOutcomeTokens(array $result): array
    {
        $tokens = [];
        foreach (['outcome', 'status', 'verdict'] as $field) {
            $value = self::stringValue($result[$field] ?? null);
            if ($value !== '') {
                $tokens[$field] = self::normalizedStatus($value);
            }
        }

        return $tokens;
    }

    private static function outcomeStatus(string $value): string
    {
        return match (self::normalizedStatus($value)) {
            'pass', 'passed', 'success', 'succeeded' => 'pass',
            default => 'non_passing',
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
        $requiredArtifacts = self::requiredArtifacts($contract);

        foreach ([
            'artifact_versions' => ['artifact_versions', 'artifactVersions'],
            'published_artifact_versions' => ['published_artifact_versions', 'publishedArtifactVersions'],
        ] as $field => $aliases) {
            $versions = self::arrayField($result, $aliases);
            if ($versions === null) {
                continue;
            }

            foreach ($requiredArtifacts as $artifact) {
                $version = self::artifactValue($versions, $artifact, $contract);
                if ($version === '') {
                    $failures[] = [
                        'code' => 'missing_artifact_version',
                        'field' => $field,
                        'artifact' => $artifact,
                    ];
                    continue;
                }

                if (self::isPlaceholderVersion($version)) {
                    $failures[] = [
                        'code' => 'placeholder_artifact_version',
                        'field' => $field,
                        'artifact' => $artifact,
                        'version' => $version,
                    ];
                }
            }
        }

        return $failures;
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
        $sourcePolicy = self::arrayField($result, ['source_policy', 'sourcePolicy']);

        if ($sourcePolicy === null || $sourcePolicy === []) {
            $failures[] = [
                'code' => 'missing_source_policy',
            ];
        } else {
            if (self::truthyField($sourcePolicy, self::localProductSourcePolicyTruthFields())) {
                $failures[] = [
                    'code' => 'local_product_source_checkout_used',
                    'field' => 'source_policy',
                    'value' => self::firstFieldValue($sourcePolicy, self::localProductSourcePolicyTruthFields()),
                ];
            }

            if (self::truthyField($sourcePolicy, [
                'allows_local_product_source_checkout_pass_evidence',
                'allowsLocalProductSourceCheckoutPassEvidence',
                'local_product_source_checkout_allowed_as_pass_evidence',
                'localProductSourceCheckoutAllowedAsPassEvidence',
            ])) {
                $failures[] = [
                    'code' => 'source_policy_allows_local_product_source_pass_evidence',
                ];
            }

            if (! self::truthyField($sourcePolicy, [
                'published_artifacts_only',
                'publishedArtifactsOnly',
                'published_artifact_evidence_only',
                'publishedArtifactEvidenceOnly',
            ])) {
                $failures[] = [
                    'code' => 'source_policy_must_require_published_artifacts',
                    'value' => self::firstFieldValue($sourcePolicy, [
                        'published_artifacts_only',
                        'publishedArtifactsOnly',
                        'published_artifact_evidence_only',
                        'publishedArtifactEvidenceOnly',
                    ]),
                ];
            }

            if (! self::hasExplicitFalseLocalProductSourceFlag($sourcePolicy)) {
                $failures[] = [
                    'code' => 'local_product_source_checkouts_used_must_be_false',
                    'field' => 'source_policy.local_product_source_checkouts_used',
                    'value' => self::firstFieldValue($sourcePolicy, self::localProductSourceFlagFields()),
                ];
            }
        }

        foreach (self::localProductSourceFlagReports($result, $scenarioResults) as $flag) {
            if (! self::truthy($flag['value'])) {
                continue;
            }

            $failure = [
                'code' => 'local_product_source_checkout_used',
                'field' => $flag['field'],
                'value' => $flag['value'],
            ];
            if ($flag['scenario_id'] !== null) {
                $failure['scenario_id'] = $flag['scenario_id'];
            }

            $failures[] = $failure;
        }

        if (! self::hasExplicitFalseLocalProductSourceFlag($result)) {
            $failures[] = [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'field' => 'local_product_source_checkouts_used',
                'value' => self::firstFieldValue($result, self::localProductSourceFlagFields()),
            ];
        }

        $sourceSets = self::reportedArtifactSourceSets($result, $scenarioResults);
        if ($sourceSets === []) {
            $failures[] = [
                'code' => 'missing_artifact_sources',
            ];

            return $failures;
        }

        $requiredArtifacts = self::requiredArtifacts($contract);
        $installSources = [];
        foreach ($sourceSets as $sourceSet) {
            if (($sourceSet['counts_for_required_sources'] ?? false) !== true) {
                continue;
            }

            foreach ($sourceSet['sources'] as $artifact => $source) {
                if (! is_string($artifact)) {
                    continue;
                }

                if (self::sourceValueRecorded($source) || ! array_key_exists($artifact, $installSources)) {
                    $installSources[$artifact] = $source;
                }
            }
        }

        foreach ($requiredArtifacts as $artifact) {
            if (self::sourceValueRecorded($installSources[$artifact] ?? null)) {
                continue;
            }

            $failures[] = [
                'code' => 'missing_artifact_source',
                'artifact' => $artifact,
            ];
        }

        $forbiddenSources = self::stringList($contract['artifact_policy']['forbidden_sources'] ?? []);
        foreach ($sourceSets as $sourceSet) {
            foreach ($sourceSet['sources'] as $artifact => $source) {
                $sourceString = self::sourceString($source);
                foreach ($forbiddenSources as $forbiddenSource) {
                    $forbiddenSource = strtolower(trim($forbiddenSource));
                    if ($forbiddenSource === '' || ! str_contains(strtolower($sourceString), $forbiddenSource)) {
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

        $sourcePolicy = self::arrayField($result, ['source_policy', 'sourcePolicy']);
        if ($sourcePolicy !== null) {
            self::appendLocalProductSourceFlagReports($reports, $sourcePolicy, null, 'source_policy.');
        }

        $cellOutcomes = self::arrayField($result, ['lifecycle_cell_outcomes', 'lifecycleCellOutcomes']) ?? [];
        foreach ($cellOutcomes as $scenarioId => $cellOutcome) {
            if (! is_array($cellOutcome)) {
                continue;
            }

            self::appendLocalProductSourceFlagReports(
                $reports,
                $cellOutcome,
                is_string($scenarioId) ? $scenarioId : null,
                'lifecycle_cell_outcomes.',
            );
        }

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
        foreach (self::localProductSourceFlagFields() as $field) {
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
                'field_prefix' => '',
                'scenario_id' => null,
            ],
        ];

        $sourcePolicy = self::arrayField($result, ['source_policy', 'sourcePolicy']);
        if ($sourcePolicy !== null) {
            $containers[] = [
                'container' => $sourcePolicy,
                'field_prefix' => 'source_policy.',
                'scenario_id' => null,
            ];
        }

        foreach ($scenarioResults as $scenarioId => $scenarioResult) {
            $containers[] = [
                'container' => $scenarioResult,
                'field_prefix' => '',
                'scenario_id' => $scenarioId,
            ];

            $outputs = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']);
            if ($outputs !== null) {
                $containers[] = [
                    'container' => $outputs,
                    'field_prefix' => 'observed_outputs.',
                    'scenario_id' => $scenarioId,
                ];
            }
        }

        foreach ($containers as $entry) {
            foreach ([
                'artifact_sources' => true,
                'artifactSources' => true,
                'install_sources' => true,
                'installSources' => true,
                'source_paths' => false,
                'sourcePaths' => false,
            ] as $field => $countsForRequiredSources) {
                $sources = self::arrayField($entry['container'], [$field]);
                if ($sources === null) {
                    continue;
                }

                $sourceSets[] = [
                    'sources' => $sources,
                    'field' => $entry['field_prefix'] . $field,
                    'scenario_id' => is_string($entry['scenario_id']) ? $entry['scenario_id'] : null,
                    'counts_for_required_sources' => $countsForRequiredSources,
                ];
            }
        }

        return $sourceSets;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $scenarioResult
     */
    private static function lifecycleCellOutcomeStatus(array $result, array $scenarioResult, string $scenarioId): string
    {
        foreach ([
            self::fieldValue($scenarioResult, 'lifecycle_cell_outcome'),
            self::fieldValue($scenarioResult, 'cell_outcome'),
            self::fieldValue($scenarioResult, 'outcome'),
            self::fieldValue($scenarioResult, 'result'),
        ] as $value) {
            $status = self::cellOutcomeStatus($value);
            if ($status !== '') {
                return $status;
            }
        }

        $cellOutcomes = self::arrayField($result, ['lifecycle_cell_outcomes', 'lifecycleCellOutcomes']) ?? [];
        if (array_key_exists($scenarioId, $cellOutcomes)) {
            return self::cellOutcomeStatus($cellOutcomes[$scenarioId]);
        }

        return '';
    }

    private static function cellOutcomeStatus(mixed $value): string
    {
        if (is_array($value)) {
            foreach (['status', 'outcome', 'result', 'verdict'] as $field) {
                $status = self::normalizedStatus($value[$field] ?? null);
                if ($status !== '') {
                    return $status;
                }
            }

            return '';
        }

        return self::normalizedStatus($value);
    }

    /**
     * @param array<string, mixed> $scenarioResult
     */
    private static function hasObservedOutputs(array $scenarioResult): bool
    {
        return ! self::isEmptyEvidence(self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']));
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $scenarioResult
     */
    private static function hasFocusedFinding(array $result, array $scenarioResult, string $scenarioId): bool
    {
        foreach ([
            $scenarioResult['linked_findings'] ?? null,
            $scenarioResult['linkedFindings'] ?? null,
            $scenarioResult['finding_links'] ?? null,
            $scenarioResult['findingLinks'] ?? null,
            $scenarioResult['findings'] ?? null,
        ] as $value) {
            if (! self::isEmptyEvidence($value)) {
                return true;
            }
        }

        foreach (['findings', 'linked_findings', 'linkedFindings', 'finding_links', 'findingLinks'] as $field) {
            $findings = self::arrayField($result, [$field]);
            if ($findings === null) {
                continue;
            }

            if (array_key_exists($scenarioId, $findings) && ! self::isEmptyEvidence($findings[$scenarioId])) {
                return true;
            }

            foreach ($findings as $finding) {
                if (! is_array($finding)) {
                    continue;
                }

                if (self::stringValue($finding['scenario_id'] ?? $finding['scenarioId'] ?? null) === $scenarioId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $contract
     *
     * @return list<string>
     */
    private static function requiredArtifacts(array $contract): array
    {
        $artifacts = array_keys($contract['artifact_policy']['install_channels'] ?? []);
        if ($artifacts === []) {
            return ['server', 'cli', 'workflow-php', 'sdk-python', 'waterline'];
        }

        return array_values(array_map(static fn (mixed $artifact): string => (string) $artifact, $artifacts));
    }

    /**
     * @param array<mixed> $values
     * @param array<string, mixed> $contract
     */
    private static function artifactValue(array $values, string $artifact, array $contract): string
    {
        foreach (self::artifactAliases($artifact, $contract) as $alias) {
            $value = self::stringValue($values[$alias] ?? null);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $contract
     *
     * @return list<string>
     */
    private static function artifactAliases(string $artifact, array $contract): array
    {
        $aliases = [$artifact];
        foreach (self::stringList($contract['artifact_policy']['release_artifact_aliases'][$artifact] ?? []) as $alias) {
            $aliases[] = $alias;
        }

        if ($artifact === 'workflow-php') {
            $aliases[] = 'workflow';
        }
        if ($artifact === 'sdk-python') {
            $aliases[] = 'python';
        }

        return array_values(array_unique($aliases));
    }

    private static function isPlaceholderVersion(string $value): bool
    {
        return preg_match('/(<[^>]+>|\$\{[^}]+}|{{[^}]+}}|(^|[^a-z0-9])latest([^a-z0-9]|$)|current|head|unresolved|placeholder)/i', $value) === 1;
    }

    /**
     * @param array<string, mixed> ...$containers
     */
    private static function hasExplicitFalseLocalProductSourceFlag(array ...$containers): bool
    {
        foreach ($containers as $container) {
            foreach (self::localProductSourceFlagFields() as $field) {
                if (array_key_exists($field, $container) && self::explicitFalse($container[$field])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function localProductSourceFlagFields(): array
    {
        return [
            'local_product_source_checkouts_used',
            'localProductSourceCheckoutsUsed',
            'local_product_source_checkout_used',
            'localProductSourceCheckoutUsed',
            'used_local_product_source_checkout',
            'usedLocalProductSourceCheckout',
            'local_source_checkout',
            'localSourceCheckout',
        ];
    }

    /**
     * @return list<string>
     */
    private static function localProductSourcePolicyTruthFields(): array
    {
        return [
            ...self::localProductSourceFlagFields(),
            'local_product_source_checkout_used_as_pass_evidence',
            'localProductSourceCheckoutUsedAsPassEvidence',
            'local_product_source_checkouts_used_as_pass_evidence',
            'localProductSourceCheckoutsUsedAsPassEvidence',
        ];
    }

    /**
     * @param array<string, mixed> $array
     * @param list<string> $fields
     */
    private static function truthyField(array $array, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $array) && self::truthy($array[$field])) {
                return true;
            }
        }

        return false;
    }

    private static function truthy(mixed $value): bool
    {
        if ($value === true || $value === 1) {
            return true;
        }

        if (is_float($value) && $value == 1.0) {
            return true;
        }

        return is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function explicitFalse(mixed $value): bool
    {
        if ($value === false || $value === 0) {
            return true;
        }

        if (is_float($value) && $value == 0.0) {
            return true;
        }

        return is_string($value) && in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off'], true);
    }

    /**
     * @param array<string, mixed> $array
     * @param list<string> $keys
     */
    private static function hasScalarField(array $array, array $keys): bool
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $array)) {
                continue;
            }

            $value = $array[$key];
            if ((is_string($value) || is_numeric($value) || is_bool($value)) && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $array
     * @param list<string> $keys
     *
     * @return array<string, mixed>|null
     */
    private static function arrayField(array $array, array $keys): ?array
    {
        foreach ($keys as $key) {
            $value = $array[$key] ?? null;
            if (is_array($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $array
     * @param list<string> $keys
     */
    private static function hasAnyField(array $array, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $array
     * @param list<string> $keys
     */
    private static function firstFieldValue(array $array, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                return $array[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $array
     */
    private static function fieldValue(array $array, string $field): mixed
    {
        foreach ([$field, self::camelize($field)] as $key) {
            if (array_key_exists($key, $array)) {
                return $array[$key];
            }
        }

        return null;
    }

    private static function camelize(string $field): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $field))));
    }

    private static function stringValue(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }

    private static function normalizedStatus(mixed $value): string
    {
        return strtolower(str_replace('-', '_', self::stringValue($value)));
    }

    /**
     * @param mixed $value
     */
    private static function isEmptyEvidence(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
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

    private static function sourceString(mixed $value): string
    {
        if (is_string($value) || is_numeric($value) || is_bool($value)) {
            return trim((string) $value);
        }

        if (is_array($value)) {
            $encoded = json_encode($value);

            return is_string($encoded) ? $encoded : '';
        }

        return '';
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
                static fn (mixed $item): string => is_string($item) || is_numeric($item) ? trim((string) $item) : '',
                $value,
            ),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
