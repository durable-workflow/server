<?php

namespace App\Support;

/**
 * Evaluates heartbeat conformance results against the full worker
 * observability matrix exposed by HeartbeatRuntimeContract.
 */
final class HeartbeatRuntimeResultGate
{
    public const SCHEMA = 'durable-workflow.v2.heartbeat-runtime.result-gate';

    public const VERSION = 1;

    private const PUBLISHED_ARTIFACT_SOURCE_LABELS = [
        'server' => [
            'published_docker_image',
            'existing_published_server_url',
        ],
        'cli' => [
            'official_install_script',
            'published_cli_release',
            'github_release',
        ],
        'sdk-php' => [
            'composer_packagist',
            'composer_release',
            'packagist',
            'packagist_package',
            'published_packagist_release',
        ],
        'sdk-python' => [
            'pypi',
            'pypi_package',
            'pypi_release',
            'published_pypi_package',
            'published_pypi_release',
        ],
        'sdk-rust' => [
            'crates_io',
            'crates.io',
            'crates_release',
            'published_crates_release',
        ],
        'waterline' => [
            'published_waterline_artifact',
            'published_waterline_release',
            'composer_packagist',
            'composer_release',
            'packagist',
            'packagist_package',
            'published_packagist_release',
        ],
    ];

    private const CLI_RELEASE_ASSET_NAMES = [
        'dw.phar',
        'dw-linux-aarch64',
        'dw-linux-x86_64',
        'dw-macos-aarch64',
        'dw-windows-x86_64.exe',
        'dw.rb',
        'install.sh',
        'install.ps1',
        'verify-release.sh',
        'SHA256SUMS',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'evaluates_result_schema' => HeartbeatRuntimeContract::RESULT_SCHEMA,
            'scenario_statuses_source' => 'heartbeat_runtime_contract.scenario_statuses',
            'required_scenarios_source' => 'heartbeat_runtime_contract.required_scenarios',
            'required_matrix_source' => 'heartbeat_runtime_contract.required_matrix',
            'scenario_required_fields_source' => 'heartbeat_runtime_contract.scenario_requirements.*.required_fields',
            'artifact_versions_fields' => [
                'artifact_versions',
                'artifactVersions',
                'published_artifact_versions',
                'publishedArtifactVersions',
                'resolved_artifact_versions',
                'resolvedArtifactVersions',
            ],
            'required_artifact_versions_source' => 'heartbeat_runtime_contract.artifact_policy.install_channels',
            'artifact_version_policy' => [
                'requires_recorded_and_pinned_versions' => true,
                'rejects_placeholder_versions' => true,
                'requires_artifact_sources_for_each_required_artifact' => true,
                'requires_recognized_published_artifact_sources' => true,
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
            'declared_outcomes_source' => 'heartbeat_runtime_contract.coverage_gate.*_outcome plus pass/fail/error aliases and scenario non-pass statuses',
            'non_pass_statuses' => [
                'fail',
                'unsupported',
                'not_covered',
                'runner_blocked',
            ],
            'pass_requires' => [
                'every_required_scenario_has_one_result',
                'every_result_uses_a_published_status',
                'required_php_python_and_rust_workers_are_reported',
                'api_cli_and_waterline_operator_visibility_paths_are_reported',
                'cadence_stale_routing_restart_adversarial_and_namespace_sections_are_reported',
                'each_pass_scenario_has_scenario_specific_evidence',
                'each_non_pass_scenario_has_focused_linked_findings',
                'omitted_required_scenarios_link_findings',
                'run_timestamps_outcome_runner_blocked_and_findings_are_recorded',
                'overall_outcome_matches_gate_status',
                'published_artifact_versions_are_recorded_and_pinned',
                'artifact_sources_are_recorded_for_required_artifacts',
                'artifact_sources_are_recognized_published_channels',
                'local_product_source_checkouts_used_is_explicitly_false',
                'no_local_product_source_artifacts_are_reported',
                'sdk_heartbeat_loop_worker_execution_uses_published_artifacts',
                'sdk_heartbeat_loops_report_successive_timestamps',
                'cadence_drift_reports_numeric_intervals_within_tolerance',
                'stale_transition_matches_acknowledged_window',
                'stale_worker_routing_reports_zero_claims',
                'two_worker_stale_routing_records_before_and_after_observations',
                'stale_routing_records_conformance_run_id_timestamp_and_public_surfaces',
                'fresh_worker_remains_eligible_after_peer_stale',
                'waterline_visibility_reports_rendered_worker_status',
                'adversarial_heartbeat_rejections_are_4xx_typed_and_not_persisted',
                'cross_namespace_isolation_reports_zero_leaks',
                'runner_blocked_false_for_product_evidence',
                'smoke_only_results_remain_non_passing',
            ],
            'smoke_subset_outcome' => 'non_passing',
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>|null  $contract
     * @return array<string, mixed>
     */
    public static function evaluate(array $result, ?array $contract = null): array
    {
        $contract ??= HeartbeatRuntimeContract::manifest();

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

                if (! self::hasLinkedFindingsForScenario($result, $scenarioId)) {
                    $failures[] = [
                        'code' => 'missing_required_scenario_finding',
                        'scenario_id' => $scenarioId,
                    ];
                }

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
                foreach (self::requiredScenarioFields($contract, $scenarioId) as $field) {
                    if (! self::hasScenarioField($scenarioResult, $field)) {
                        $failures[] = [
                            'code' => 'missing_scenario_required_field',
                            'scenario_id' => $scenarioId,
                            'field' => $field,
                        ];
                    }
                }
            } else {
                $nonPassScenarios[] = $scenarioId;
                array_push(
                    $failures,
                    ...self::nonPassFindingFailures($scenarioResult, $result, $contract, $scenarioId, $status),
                );
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
        array_push($failures, ...self::semanticEvidenceFailures($scenarioResults));

        $smokeSubsetDetected = self::isSmokeSubset($scenarioStatuses, $contract);
        if ($smokeSubsetDetected) {
            $failures[] = [
                'code' => 'smoke_subset_cannot_pass',
                'reason' => 'Worker registration and API visibility smoke coverage is not a complete heartbeat conformance result.',
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
     * @param  array<string, mixed>  $result
     * @param  array<string, int>  $duplicateScenarioCounts
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
     * @param  array<string, mixed>  $contract
     * @return list<string>
     */
    private static function requiredScenarioFields(array $contract, string $scenarioId): array
    {
        $requirements = $contract['scenario_requirements'][$scenarioId] ?? [];

        return self::stringList(is_array($requirements) ? ($requirements['required_fields'] ?? []) : []);
    }

    /**
     * @param  array<string, mixed>  $scenarioResult
     */
    private static function hasScenarioField(array $scenarioResult, string $field): bool
    {
        if ($field === 'language_specific_field_diff') {
            $outputs = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']) ?? [];

            return array_key_exists($field, $scenarioResult)
                || array_key_exists(self::camelize($field), $scenarioResult)
                || array_key_exists($field, $outputs)
                || array_key_exists(self::camelize($field), $outputs);
        }

        if (self::hasPath($scenarioResult, $field)) {
            return true;
        }

        $outputs = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']) ?? [];

        return self::hasPath($outputs, $field);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
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

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private static function hasRunRecordField(array $result, string $field): bool
    {
        return match ($field) {
            'artifact_versions' => ! self::isEmptyEvidence(self::arrayField($result, [
                'artifact_versions',
                'artifactVersions',
                'published_artifact_versions',
                'publishedArtifactVersions',
                'resolved_artifact_versions',
                'resolvedArtifactVersions',
            ])),
            'started_at' => self::hasScalarField($result, ['started_at', 'startedAt']),
            'finished_at' => self::hasScalarField($result, ['finished_at', 'finishedAt']),
            'generated_at' => self::hasScalarField($result, ['generated_at', 'generatedAt']),
            'outcome' => self::declaredOutcomeTokens($result) !== [],
            'runner_blocked' => self::runnerBlockedValue($result) !== null,
            'scenario_results' => self::arrayField($result, ['scenario_results', 'scenarioResults']) !== null,
            'findings' => self::hasFindingList($result),
            'finding_links' => ! self::isEmptyEvidence(self::arrayField($result, [
                'finding_links',
                'findingLinks',
                'linked_findings',
                'linkedFindings',
            ])),
            default => ! self::isEmptyEvidence(self::fieldValue($result, $field)),
        };
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     * @return list<array<string, mixed>>
     */
    private static function artifactVersionFailures(array $result, array $contract): array
    {
        $failures = [];
        $requiredArtifacts = array_keys(self::arrayField($contract['artifact_policy'] ?? [], ['install_channels']) ?? []);

        foreach ([
            'artifact_versions' => ['artifact_versions', 'artifactVersions'],
            'published_artifact_versions' => ['published_artifact_versions', 'publishedArtifactVersions'],
            'resolved_artifact_versions' => ['resolved_artifact_versions', 'resolvedArtifactVersions'],
        ] as $field => $aliases) {
            $versions = self::arrayField($result, $aliases);
            if ($versions === null) {
                continue;
            }

            foreach ($requiredArtifacts as $artifact) {
                $artifact = (string) $artifact;
                $version = self::artifactVersionValue($versions, $artifact);
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
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     * @param  array<string, array<string, mixed>>  $scenarioResults
     * @return list<array<string, mixed>>
     */
    private static function sourcePolicyFailures(array $result, array $contract, array $scenarioResults): array
    {
        $artifactPolicy = self::arrayField($contract, ['artifact_policy', 'artifactPolicy']) ?? [];
        $forbiddenSources = array_values(array_unique(array_merge(
            self::stringList($artifactPolicy['forbidden_sources'] ?? $artifactPolicy['forbiddenSources'] ?? []),
            [
                'local_checkout',
                'local_checkout_artifact',
                'local_product_source_checkout',
                'local_source_checkout',
                'not_exercised',
                'workspace_repo',
                'workspace_repo_as_artifact_under_test',
            ],
        )));
        $install = $scenarioResults['published_artifact_install_only'] ?? [];
        $installOutputs = self::arrayField($install, ['observed_outputs', 'observedOutputs']) ?? [];
        $artifactVersions = self::artifactVersions($result);
        $requiredArtifacts = array_map(
            static fn (mixed $artifact): string => (string) $artifact,
            array_keys(self::arrayField($artifactPolicy, ['install_channels']) ?? []),
        );

        $failures = [];

        $sourcePolicyContainers = [
            [
                'container' => $result,
                'field_prefix' => '',
                'scenario_id' => null,
            ],
        ];

        foreach ($scenarioResults as $scenarioId => $scenarioResult) {
            $sourcePolicyContainers[] = [
                'container' => $scenarioResult,
                'field_prefix' => '',
                'scenario_id' => $scenarioId,
            ];

            $outputs = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']) ?? [];
            $sourcePolicyContainers[] = [
                'container' => $outputs,
                'field_prefix' => 'observed_outputs.',
                'scenario_id' => $scenarioId,
            ];
        }

        foreach ($sourcePolicyContainers as $sourcePolicyContainer) {
            foreach (self::localProductSourceCheckoutValues($sourcePolicyContainer['container']) as $flag) {
                if (($flag['value'] ?? null) === false) {
                    continue;
                }

                $failure = [
                    'code' => 'local_product_source_checkouts_used_must_be_false',
                    'field' => $sourcePolicyContainer['field_prefix'].$flag['field'],
                    'value' => $flag['value'] ?? null,
                ];
                if ($sourcePolicyContainer['scenario_id'] !== null) {
                    $failure['scenario_id'] = $sourcePolicyContainer['scenario_id'];
                }

                $failures[] = $failure;
            }
        }

        foreach (array_keys(self::sdkHeartbeatLoopArtifacts()) as $scenarioId) {
            $scenarioResult = $scenarioResults[$scenarioId] ?? [];
            if (self::stringValue($scenarioResult['status'] ?? null) !== 'pass') {
                continue;
            }

            $outputs = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']) ?? [];
            if (self::localProductSourceCheckoutValues($scenarioResult, $outputs) === []) {
                $failures[] = [
                    'code' => 'local_product_source_checkouts_used_must_be_false',
                    'scenario_id' => $scenarioId,
                    'field' => 'local_product_source_checkouts_used',
                    'value' => null,
                ];
            }

            array_push(
                $failures,
                ...self::publishedArtifactWorkerExecutionFailures(
                    $scenarioId,
                    $scenarioResult,
                    $outputs,
                    self::sdkHeartbeatLoopArtifacts()[$scenarioId],
                    $forbiddenSources,
                    $artifactVersions,
                ),
            );
        }

        $reportedSourceSets = [];
        foreach ($sourcePolicyContainers as $sourcePolicyContainer) {
            foreach ([
                'artifact_sources',
                'artifactSources',
                'install_sources',
                'installSources',
                'source_paths',
                'sourcePaths',
            ] as $field) {
                $sources = self::arrayField($sourcePolicyContainer['container'], [$field]);
                if ($sources !== null) {
                    $reportedSourceSets[] = [
                        'field' => $sourcePolicyContainer['field_prefix'].$field,
                        'sources' => $sources,
                        'scenario_id' => $sourcePolicyContainer['scenario_id'],
                    ];
                }
            }
        }

        foreach ($reportedSourceSets as $sourceSet) {
            foreach (self::sourceSetEntries($sourceSet['sources']) as $entry) {
                $source = $entry['source'];
                $artifact = self::canonicalArtifactName(self::stringValue($entry['artifact']));

                if ($source !== '' && self::isPlaceholderVersion($source)) {
                    $failure = [
                        'code' => 'placeholder_artifact_source',
                        'artifact' => $artifact !== '' ? $artifact : $entry['artifact'],
                        'source' => $source,
                        'field' => $sourceSet['field'],
                    ];
                    if ($sourceSet['scenario_id'] !== null) {
                        $failure['scenario_id'] = $sourceSet['scenario_id'];
                    }

                    $failures[] = $failure;
                }

                if (self::isForbiddenArtifactSource($source, $forbiddenSources)) {
                    $failure = [
                        'code' => 'forbidden_artifact_source',
                        'artifact' => $artifact !== '' ? $artifact : $entry['artifact'],
                        'source' => $source,
                        'field' => $sourceSet['field'],
                    ];
                    if ($sourceSet['scenario_id'] !== null) {
                        $failure['scenario_id'] = $sourceSet['scenario_id'];
                    }

                    $failures[] = $failure;
                }

                if ($source === ''
                    || ! in_array($artifact, $requiredArtifacts, true)
                    || ! self::isPublishedArtifactSourceField($sourceSet['field'])) {
                    continue;
                }

                $version = self::artifactVersionValue($artifactVersions, $artifact);
                if (self::matchesPublishedArtifactSource($artifact, $version, $source)) {
                    continue;
                }

                $failure = [
                    'code' => 'unverified_published_artifact_source',
                    'artifact' => $artifact,
                    'source' => $source,
                    'version' => $version !== '' ? $version : null,
                    'field' => $sourceSet['field'],
                ];
                if ($sourceSet['scenario_id'] !== null) {
                    $failure['scenario_id'] = $sourceSet['scenario_id'];
                }

                $failures[] = $failure;
            }
        }

        if (self::stringValue($install['status'] ?? null) !== 'pass') {
            return $failures;
        }

        $sources = [];
        foreach ([$result, $install, $installOutputs] as $container) {
            foreach ([
                'artifact_sources',
                'artifactSources',
                'install_sources',
                'installSources',
            ] as $field) {
                $sourceSet = self::arrayField($container, [$field]);
                if ($sourceSet !== null) {
                    $sources = array_replace($sources, $sourceSet);
                }
            }
        }

        foreach (array_keys(self::arrayField($artifactPolicy, ['install_channels']) ?? []) as $artifact) {
            $artifact = (string) $artifact;
            if (self::artifactVersionValue($sources, $artifact) !== '') {
                continue;
            }

            $failures[] = [
                'code' => 'missing_published_artifact_install_source',
                'scenario_id' => 'published_artifact_install_only',
                'artifact' => $artifact,
            ];
        }

        if (! self::hasExplicitFalseLocalProductSourceFlag($result, $install, $installOutputs)) {
            $failures[] = [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'scenario_id' => 'published_artifact_install_only',
                'field' => 'local_product_source_checkouts_used',
                'value' => self::firstLocalProductSourceFlagValue($result, $install, $installOutputs),
            ];
        }

        return $failures;
    }

    /**
     * @return array<string, string>
     */
    private static function sdkHeartbeatLoopArtifacts(): array
    {
        return [
            'php_sdk_heartbeat_loop' => 'sdk-php',
            'python_sdk_heartbeat_loop' => 'sdk-python',
            'rust_sdk_heartbeat_loop' => 'sdk-rust',
        ];
    }

    /**
     * @param  array<string, mixed>  $scenarioResult
     * @param  array<string, mixed>  $outputs
     * @param  list<string>  $forbiddenSources
     * @return list<array<string, mixed>>
     */
    private static function publishedArtifactWorkerExecutionFailures(
        string $scenarioId,
        array $scenarioResult,
        array $outputs,
        string $requiredArtifact,
        array $forbiddenSources,
        array $artifactVersions,
    ): array {
        $execution = self::arrayField($outputs, [
            'published_artifact_worker_execution',
            'publishedArtifactWorkerExecution',
            'published_worker_execution',
            'publishedWorkerExecution',
        ]);
        $actual = self::fieldValue($outputs, 'published_artifact_worker_execution');
        if ($execution === null && $actual === null) {
            $execution = self::arrayField($scenarioResult, [
                'published_artifact_worker_execution',
                'publishedArtifactWorkerExecution',
                'published_worker_execution',
                'publishedWorkerExecution',
            ]);
            $actual = self::fieldValue($scenarioResult, 'published_artifact_worker_execution');
        }

        if ($execution === null) {
            return [[
                'code' => 'published_artifact_worker_execution_missing',
                'scenario_id' => $scenarioId,
                'field' => 'published_artifact_worker_execution',
                'expected' => 'object_with_artifacts',
                'actual' => $actual,
            ]];
        }

        $failures = [];
        if (! self::hasExplicitFalseLocalProductSourceFlag($execution)) {
            $failures[] = [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'scenario_id' => $scenarioId,
                'field' => 'published_artifact_worker_execution.local_product_source_checkouts_used',
                'value' => self::firstLocalProductSourceFlagValue($execution),
            ];
        }

        $entries = self::publishedWorkerExecutionEntries($execution);
        if ($entries === []) {
            return [
                ...$failures,
                [
                    'code' => 'published_artifact_worker_execution_missing_artifacts',
                    'scenario_id' => $scenarioId,
                    'field' => 'published_artifact_worker_execution.artifacts',
                ],
            ];
        }

        $requiredArtifactObserved = false;
        foreach ($entries as $index => $entry) {
            $artifact = self::workerExecutionArtifact($entry, $requiredArtifact);
            $source = self::stringField($entry, [
                'source',
                'install_source',
                'installSource',
                'artifact_source',
                'artifactSource',
            ]);
            $version = self::stringField($entry, [
                'version',
                'artifact_version',
                'artifactVersion',
                'package_version',
                'packageVersion',
            ]);
            if ($version === '' && $artifact !== '') {
                $version = self::artifactVersionValue($artifactVersions, $artifact);
            }

            if ($source !== '' && self::isPlaceholderVersion($source)) {
                $failures[] = [
                    'code' => 'placeholder_published_artifact_worker_execution_source',
                    'scenario_id' => $scenarioId,
                    'artifact' => $artifact !== '' ? $artifact : null,
                    'source' => $source,
                    'field' => sprintf('published_artifact_worker_execution.artifacts.%d.source', $index),
                ];
            }

            if ($source !== '' && self::isForbiddenArtifactSource($source, $forbiddenSources)) {
                $failures[] = [
                    'code' => 'forbidden_published_artifact_worker_execution_source',
                    'scenario_id' => $scenarioId,
                    'artifact' => $artifact !== '' ? $artifact : null,
                    'source' => $source,
                    'field' => sprintf('published_artifact_worker_execution.artifacts.%d.source', $index),
                ];
            }

            if ($artifact !== '' && $version !== '' && self::isPlaceholderVersion($version)) {
                $failures[] = [
                    'code' => 'placeholder_published_artifact_worker_execution_version',
                    'scenario_id' => $scenarioId,
                    'artifact' => $artifact,
                    'version' => $version,
                    'field' => sprintf('published_artifact_worker_execution.artifacts.%d.version', $index),
                ];
            }

            if ($source !== ''
                && $artifact !== ''
                && ! self::matchesPublishedArtifactSource($artifact, $version, $source)) {
                $failures[] = [
                    'code' => 'unverified_published_artifact_worker_execution_source',
                    'scenario_id' => $scenarioId,
                    'artifact' => $artifact,
                    'source' => $source,
                    'version' => $version !== '' ? $version : null,
                    'field' => sprintf('published_artifact_worker_execution.artifacts.%d.source', $index),
                ];
            }

            foreach (self::localProductSourceCheckoutValues($entry) as $flag) {
                if (($flag['value'] ?? null) === false) {
                    continue;
                }

                $failures[] = [
                    'code' => 'local_product_source_checkouts_used_must_be_false',
                    'scenario_id' => $scenarioId,
                    'artifact' => $artifact !== '' ? $artifact : null,
                    'field' => sprintf(
                        'published_artifact_worker_execution.artifacts.%d.%s',
                        $index,
                        $flag['field'],
                    ),
                    'value' => $flag['value'] ?? null,
                ];
            }

            if ($artifact !== $requiredArtifact) {
                continue;
            }

            $requiredArtifactObserved = true;
            if ($source === '') {
                $failures[] = [
                    'code' => 'missing_published_artifact_worker_execution_source',
                    'scenario_id' => $scenarioId,
                    'artifact' => $requiredArtifact,
                    'field' => sprintf('published_artifact_worker_execution.artifacts.%d.source', $index),
                ];
            }

            if ($version === '') {
                $failures[] = [
                    'code' => 'missing_published_artifact_worker_execution_version',
                    'scenario_id' => $scenarioId,
                    'artifact' => $requiredArtifact,
                    'field' => sprintf('published_artifact_worker_execution.artifacts.%d.version', $index),
                ];
            }

            $status = strtolower(self::stringField($entry, ['status', 'result', 'outcome']));
            if ($status !== 'pass') {
                $failures[] = [
                    'code' => 'published_artifact_worker_execution_not_pass',
                    'scenario_id' => $scenarioId,
                    'artifact' => $requiredArtifact,
                    'status' => $status,
                    'field' => sprintf('published_artifact_worker_execution.artifacts.%d.status', $index),
                ];
            }
        }

        if (! $requiredArtifactObserved) {
            $failures[] = [
                'code' => 'missing_required_published_worker_execution_artifact',
                'scenario_id' => $scenarioId,
                'artifact' => $requiredArtifact,
                'field' => 'published_artifact_worker_execution.artifacts',
            ];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $execution
     * @return list<array<string, mixed>>
     */
    private static function publishedWorkerExecutionEntries(array $execution): array
    {
        $entries = [];
        $artifacts = self::arrayField($execution, [
            'artifacts',
            'workers',
            'worker_artifacts',
            'workerArtifacts',
        ]);

        if ($artifacts !== null) {
            foreach ($artifacts as $key => $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                if (is_string($key) && self::stringField($entry, ['artifact', 'name', 'id']) === '') {
                    $entry['artifact'] = $key;
                }

                $entries[] = $entry;
            }
        }

        if ($entries === []
            && (self::fieldValue($execution, 'artifact') !== null
                || self::fieldValue($execution, 'source') !== null
                || self::fieldValue($execution, 'artifact_source') !== null)
        ) {
            $entries[] = $execution;
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function workerExecutionArtifact(array $entry, string $requiredArtifact): string
    {
        $artifact = self::canonicalWorkerArtifact(self::stringField($entry, [
            'artifact',
            'name',
            'id',
            'package',
        ]));
        if (self::isKnownWorkerArtifact($artifact)) {
            return $artifact;
        }

        $runtime = self::canonicalWorkerArtifact(self::stringField($entry, [
            'runtime',
            'sdk',
            'language',
            'worker_runtime',
            'workerRuntime',
        ]));
        if (self::isKnownWorkerArtifact($runtime)) {
            return $runtime;
        }

        $source = strtolower(self::stringField($entry, [
            'source',
            'install_source',
            'installSource',
            'artifact_source',
            'artifactSource',
        ]));
        if ($artifact === 'durable-workflow') {
            if ($requiredArtifact === 'sdk-python' && (str_contains($source, 'pypi') || str_contains($source, 'python'))) {
                return 'sdk-python';
            }

            if ($requiredArtifact === 'sdk-rust' && (str_contains($source, 'crate') || str_contains($source, 'rust'))) {
                return 'sdk-rust';
            }
        }

        return $artifact;
    }

    private static function isKnownWorkerArtifact(string $artifact): bool
    {
        return in_array($artifact, ['sdk-php', 'sdk-python', 'sdk-rust'], true);
    }

    /**
     * @param  array<mixed>  $sources
     * @return list<array{artifact: mixed, source: string}>
     */
    private static function sourceSetEntries(array $sources): array
    {
        $entries = [];

        foreach ($sources as $artifact => $source) {
            if (is_array($source)) {
                $entries[] = [
                    'artifact' => is_string($artifact)
                        ? $artifact
                        : self::stringField($source, ['artifact', 'name', 'id']),
                    'source' => self::stringField($source, [
                        'source',
                        'install_source',
                        'installSource',
                        'artifact_source',
                        'artifactSource',
                        'path',
                        'value',
                    ]),
                ];

                continue;
            }

            $entries[] = [
                'artifact' => $artifact,
                'source' => self::stringValue($source),
            ];
        }

        return $entries;
    }

    private static function isPublishedArtifactSourceField(string $field): bool
    {
        return in_array(preg_replace('/^observed_outputs\./', '', $field), [
            'artifact_sources',
            'artifactSources',
            'install_sources',
            'installSources',
        ], true);
    }

    private static function matchesPublishedArtifactSource(string $artifact, string $version, string $source): bool
    {
        $artifact = self::canonicalArtifactName($artifact);
        $source = trim($source);

        if ($artifact === '' || $version === '' || $source === '') {
            return false;
        }

        if (self::isPlaceholderVersion($version) || self::isPlaceholderVersion($source)) {
            return false;
        }

        if (self::publishedSourceLabelAllowed($artifact, $source)) {
            return true;
        }

        return match ($artifact) {
            'server' => self::matchesServerArtifactSource($version, $source),
            'cli' => self::matchesCliArtifactSource($version, $source),
            'sdk-php' => self::matchesComposerArtifactSource('durable-workflow/sdk', $version, $source),
            'sdk-python' => self::matchesPythonArtifactSource($version, $source),
            'sdk-rust' => self::matchesRustArtifactSource($version, $source),
            'waterline' => self::matchesComposerArtifactSource('durable-workflow/waterline', $version, $source),
            default => false,
        };
    }

    private static function publishedSourceLabelAllowed(string $artifact, string $source): bool
    {
        $normalizedSource = self::normalizeToken($source);
        foreach (self::PUBLISHED_ARTIFACT_SOURCE_LABELS[$artifact] ?? [] as $label) {
            if (self::normalizeToken($label) === $normalizedSource) {
                return true;
            }
        }

        return false;
    }

    private static function matchesServerArtifactSource(string $version, string $source): bool
    {
        $escapedVersion = preg_quote($version, '/');

        return preg_match('/^docker:\/\/durableworkflow\/server@sha256:[0-9a-f]{64}$/i', $source) === 1
            || preg_match('/^durableworkflow\/server@sha256:[0-9a-f]{64}$/i', $source) === 1
            || preg_match('/^docker:\/\/durableworkflow\/server:'.$escapedVersion.'@sha256:[0-9a-f]{64}$/i', $source) === 1
            || preg_match('/^durableworkflow\/server:'.$escapedVersion.'@sha256:[0-9a-f]{64}$/i', $source) === 1
            || $source === 'docker://durableworkflow/server:'.$version
            || $source === 'durableworkflow/server:'.$version;
    }

    private static function matchesCliArtifactSource(string $version, string $source): bool
    {
        $prefix = 'https://github.com/durable-workflow/cli/releases/download/'.$version.'/';
        if (! str_starts_with($source, $prefix)) {
            return false;
        }

        return in_array(substr($source, strlen($prefix)), self::CLI_RELEASE_ASSET_NAMES, true);
    }

    private static function matchesComposerArtifactSource(string $packageName, string $version, string $source): bool
    {
        return $source === 'packagist://'.$packageName.'@'.$version
            || $source === 'composer://'.$packageName.':'.$version
            || $source === 'https://repo.packagist.org/p2/'.$packageName.'.json#'.$version;
    }

    private static function matchesPythonArtifactSource(string $version, string $source): bool
    {
        return $source === 'pypi://durable-workflow=='.$version
            || $source === 'https://pypi.org/project/durable-workflow/'.$version.'/'
            || (
                (str_starts_with($source, 'https://files.pythonhosted.org/') || str_starts_with($source, 'https://pypi.io/packages/'))
                && (str_contains($source, '/durable_workflow-'.$version) || str_contains($source, '/durable-workflow-'.$version))
            );
    }

    private static function matchesRustArtifactSource(string $version, string $source): bool
    {
        return $source === 'crates://durable-workflow@'.$version
            || $source === 'crates.io://durable-workflow@'.$version
            || $source === 'https://crates.io/crates/durable-workflow/'.$version
            || $source === 'https://crates.io/api/v1/crates/durable-workflow/'.$version.'/download';
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     * @return list<array<string, mixed>>
     */
    private static function matrixFailures(array $result, array $contract): array
    {
        $failures = [];
        $matrix = self::arrayField($result, ['runtime_matrix', 'runtimeMatrix']) ?? [];
        $requiredMatrix = self::arrayField($contract, ['required_matrix', 'requiredMatrix']) ?? [];

        foreach ([
            'runtimes' => ['runtimes', 'worker_runtimes', 'workerRuntimes'],
            'client_paths' => ['client_paths', 'clientPaths'],
            'operator_visibility_paths' => ['operator_visibility_paths', 'operatorVisibilityPaths'],
            'heartbeat_fields' => ['heartbeat_fields', 'heartbeatFields'],
        ] as $requiredField => $aliases) {
            $reported = self::stringList(self::arrayField($matrix, $aliases) ?? []);
            foreach (self::stringList($requiredMatrix[$requiredField] ?? []) as $requiredValue) {
                if (in_array($requiredValue, $reported, true)) {
                    continue;
                }

                $failures[] = [
                    'code' => 'missing_runtime_matrix_value',
                    'field' => $requiredField,
                    'value' => $requiredValue,
                ];
            }
        }

        foreach ([
            'routing_cells' => ['routing_cells', 'routingCells'],
            'adversarial_cells' => ['adversarial_cells', 'adversarialCells'],
        ] as $requiredField => $aliases) {
            $reported = self::arrayField($matrix, $aliases) ?? [];
            foreach (self::stringList($requiredMatrix[$requiredField] ?? []) as $requiredValue) {
                if (self::containsCell($reported, $requiredValue)) {
                    continue;
                }

                $failures[] = [
                    'code' => 'missing_runtime_matrix_cell',
                    'field' => $requiredField,
                    'cell' => $requiredValue,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private static function requiredSectionFailures(array $result): array
    {
        $failures = [];
        foreach ([
            'topology',
            'runtime_matrix',
            'cadence_drift_dataset',
            'worker_list_snapshots',
            'heartbeat_shape_diff',
            'stale_transition',
            'routing_exclusion',
            'operator_visibility',
            'adversarial_outcomes',
            'cross_namespace_isolation',
        ] as $field) {
            if (! self::isEmptyEvidence(self::fieldValue($result, $field))) {
                continue;
            }

            $failures[] = [
                'code' => 'missing_required_section',
                'field' => $field,
            ];
        }

        return $failures;
    }

    /**
     * @param  array<string, array<string, mixed>>  $scenarioResults
     * @return list<array<string, mixed>>
     */
    private static function semanticEvidenceFailures(array $scenarioResults): array
    {
        $failures = [];

        foreach (array_keys(self::sdkHeartbeatLoopArtifacts()) as $scenarioId) {
            if (! self::isPassScenario($scenarioResults, $scenarioId)) {
                continue;
            }

            array_push(
                $failures,
                ...self::sdkLoopSemanticFailures($scenarioId, self::scenarioOutputs($scenarioResults, $scenarioId)),
            );
        }

        if (self::isPassScenario($scenarioResults, 'heartbeat_wire_shape_uniformity')) {
            array_push(
                $failures,
                ...self::wireShapeSemanticFailures(
                    self::scenarioOutputs($scenarioResults, 'heartbeat_wire_shape_uniformity'),
                ),
            );
        }

        if (self::isPassScenario($scenarioResults, 'cadence_drift_window')) {
            array_push(
                $failures,
                ...self::cadenceSemanticFailures(self::scenarioOutputs($scenarioResults, 'cadence_drift_window')),
            );
        }

        if (self::isPassScenario($scenarioResults, 'stale_worker_transition_timing')) {
            array_push(
                $failures,
                ...self::staleTransitionSemanticFailures(
                    self::scenarioOutputs($scenarioResults, 'stale_worker_transition_timing'),
                ),
            );
        }

        if (self::isPassScenario($scenarioResults, 'stale_worker_routing_exclusion')) {
            array_push(
                $failures,
                ...self::staleRoutingSemanticFailures(
                    self::scenarioOutputs($scenarioResults, 'stale_worker_routing_exclusion'),
                ),
            );
        }

        if (self::isPassScenario($scenarioResults, 'waterline_worker_status_visibility')) {
            array_push(
                $failures,
                ...self::waterlineSemanticFailures(
                    self::scenarioOutputs($scenarioResults, 'waterline_worker_status_visibility'),
                ),
            );
        }

        foreach ([
            'malformed_heartbeat_rejection',
            'unregistered_heartbeat_rejection',
        ] as $scenarioId) {
            if (! self::isPassScenario($scenarioResults, $scenarioId)) {
                continue;
            }

            array_push(
                $failures,
                ...self::heartbeatRejectionSemanticFailures($scenarioId, self::scenarioOutputs($scenarioResults, $scenarioId)),
            );
        }

        if (self::isPassScenario($scenarioResults, 'cross_namespace_isolation')) {
            array_push(
                $failures,
                ...self::crossNamespaceSemanticFailures(
                    self::scenarioOutputs($scenarioResults, 'cross_namespace_isolation'),
                ),
            );
        }

        return $failures;
    }

    /**
     * @param  array<string, array<string, mixed>>  $scenarioResults
     */
    private static function isPassScenario(array $scenarioResults, string $scenarioId): bool
    {
        return self::stringValue($scenarioResults[$scenarioId]['status'] ?? null) === 'pass';
    }

    /**
     * @param  array<string, array<string, mixed>>  $scenarioResults
     * @return array<string, mixed>
     */
    private static function scenarioOutputs(array $scenarioResults, string $scenarioId): array
    {
        return self::arrayField($scenarioResults[$scenarioId] ?? [], ['observed_outputs', 'observedOutputs']) ?? [];
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @return list<array<string, mixed>>
     */
    private static function sdkLoopSemanticFailures(string $scenarioId, array $outputs): array
    {
        $failures = [];

        foreach (['runtime', 'worker_id'] as $field) {
            if (self::isConcreteEvidence(self::fieldValue($outputs, $field))) {
                continue;
            }

            $failures[] = [
                'code' => 'sdk_heartbeat_loop_missing_concrete_field',
                'scenario_id' => $scenarioId,
                'field' => $field,
            ];
        }

        foreach (['registered_types', 'task_slots', 'process_metrics'] as $field) {
            if (! self::isEmptyEvidence(self::arrayField($outputs, [$field]))) {
                continue;
            }

            $failures[] = [
                'code' => 'sdk_heartbeat_loop_missing_structured_field',
                'scenario_id' => $scenarioId,
                'field' => $field,
            ];
        }

        if (! self::hasSuccessiveTimestamps(self::fieldValue($outputs, 'heartbeat_timestamps'))) {
            $failures[] = [
                'code' => 'sdk_heartbeat_loop_missing_successive_timestamps',
                'scenario_id' => $scenarioId,
                'field' => 'heartbeat_timestamps',
            ];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @return list<array<string, mixed>>
     */
    private static function wireShapeSemanticFailures(array $outputs): array
    {
        $failures = [];

        foreach (['runtime_records', 'common_field_set', 'server_records'] as $field) {
            if (! self::isEmptyEvidence(self::arrayField($outputs, [$field]))) {
                continue;
            }

            $failures[] = [
                'code' => 'heartbeat_wire_shape_missing_structured_field',
                'scenario_id' => 'heartbeat_wire_shape_uniformity',
                'field' => $field,
            ];
        }

        $diff = self::fieldValue($outputs, 'language_specific_field_diff');
        if ($diff !== null && self::isEmptyEvidence($diff)) {
            return $failures;
        }

        if (is_array($diff) && self::onlyEmptyNestedValues($diff)) {
            return $failures;
        }

        $failures[] = [
            'code' => 'heartbeat_wire_shape_language_specific_diff_not_empty',
            'scenario_id' => 'heartbeat_wire_shape_uniformity',
            'field' => 'language_specific_field_diff',
            'value' => $diff,
        ];

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @return list<array<string, mixed>>
     */
    private static function cadenceSemanticFailures(array $outputs): array
    {
        $failures = [];
        $nominal = self::numberValue(self::fieldValue($outputs, 'nominal_interval_seconds'));
        $tolerance = self::numberValue(self::fieldValue($outputs, 'tolerance_percent'));
        $intervals = self::numericSamples(self::fieldValue($outputs, 'inter_arrival_seconds'));

        if ($nominal === null || $nominal <= 0) {
            $failures[] = [
                'code' => 'cadence_drift_window_invalid_nominal_interval',
                'scenario_id' => 'cadence_drift_window',
                'field' => 'nominal_interval_seconds',
            ];
        }

        if ($tolerance === null || $tolerance < 0) {
            $failures[] = [
                'code' => 'cadence_drift_window_invalid_tolerance',
                'scenario_id' => 'cadence_drift_window',
                'field' => 'tolerance_percent',
            ];
        }

        if ($intervals === []) {
            $failures[] = [
                'code' => 'cadence_drift_window_missing_numeric_intervals',
                'scenario_id' => 'cadence_drift_window',
                'field' => 'inter_arrival_seconds',
            ];
        }

        if ($nominal === null || $nominal <= 0 || $tolerance === null || $tolerance < 0 || $intervals === []) {
            return $failures;
        }

        foreach ($intervals as $interval) {
            $driftPercent = abs($interval - $nominal) / $nominal * 100;
            if ($driftPercent <= $tolerance) {
                continue;
            }

            $failures[] = [
                'code' => 'cadence_interval_exceeds_tolerance',
                'scenario_id' => 'cadence_drift_window',
                'interval_seconds' => $interval,
                'nominal_interval_seconds' => $nominal,
                'tolerance_percent' => $tolerance,
            ];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @return list<array<string, mixed>>
     */
    private static function staleTransitionSemanticFailures(array $outputs): array
    {
        $failures = [];
        $staleAfter = self::numberValue(self::fieldValue($outputs, 'stale_after_seconds'));
        $stopTimestamp = self::timestampValue(self::fieldValue($outputs, 'stop_timestamp'));
        $disappearedAt = self::timestampValue(self::fieldValue($outputs, 'disappeared_from_default_list_at'));
        $probeGrace = max(1.0, self::numberValue(self::fieldValue($outputs, 'probe_grace_seconds')) ?? 60.0);

        if ($staleAfter === null || $staleAfter <= 0) {
            $failures[] = [
                'code' => 'stale_transition_invalid_stale_after_seconds',
                'scenario_id' => 'stale_worker_transition_timing',
                'field' => 'stale_after_seconds',
            ];
        }

        if ($stopTimestamp === null || $disappearedAt === null) {
            $failures[] = [
                'code' => 'stale_transition_missing_parseable_timestamps',
                'scenario_id' => 'stale_worker_transition_timing',
            ];
        }

        if ($staleAfter === null || $staleAfter <= 0 || $stopTimestamp === null || $disappearedAt === null) {
            return $failures;
        }

        $observedSeconds = $disappearedAt - $stopTimestamp;
        if ($observedSeconds < 0 || $observedSeconds > ($staleAfter + $probeGrace)) {
            $failures[] = [
                'code' => 'stale_transition_exceeded_acknowledged_window',
                'scenario_id' => 'stale_worker_transition_timing',
                'stale_after_seconds' => $staleAfter,
                'probe_grace_seconds' => $probeGrace,
                'observed_seconds' => $observedSeconds,
            ];
        }

        if (self::isEmptyEvidence(self::arrayField($outputs, ['stale_list_entry']))) {
            $failures[] = [
                'code' => 'stale_transition_missing_stale_list_entry',
                'scenario_id' => 'stale_worker_transition_timing',
                'field' => 'stale_list_entry',
            ];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @return list<array<string, mixed>>
     */
    private static function staleRoutingSemanticFailures(array $outputs): array
    {
        $failures = [];
        $staleWorkerId = self::stringValue(self::fieldValue($outputs, 'stale_worker_id'));
        $freshWorkerId = self::stringValue(self::fieldValue($outputs, 'fresh_worker_id'));
        $threshold = self::numberValue(self::fieldValue($outputs, 'configured_stale_threshold_seconds'));
        $transitionTiming = self::arrayField($outputs, ['observed_stale_transition_timing']);
        $beforeObservations = self::arrayField($outputs, ['routing_observations_before_stale']);
        $afterObservations = self::arrayField($outputs, ['routing_observations_after_stale']);
        $freshEligibility = self::fieldValue($outputs, 'fresh_worker_eligibility_after_stale');
        $surfaces = self::publicSurfaceStrings(self::fieldValue($outputs, 'public_surfaces'));
        $runId = self::fieldValue($outputs, 'conformance_run_id');
        $timestamp = self::timestampValue(self::fieldValue($outputs, 'timestamp'));
        $claimCount = self::numberValue(self::fieldValue($outputs, 'stale_worker_claim_count'));

        if ($staleWorkerId === '' || $freshWorkerId === '') {
            $failures[] = [
                'code' => 'stale_worker_routing_missing_two_worker_ids',
                'scenario_id' => 'stale_worker_routing_exclusion',
                'stale_worker_id' => $staleWorkerId !== '' ? $staleWorkerId : null,
                'fresh_worker_id' => $freshWorkerId !== '' ? $freshWorkerId : null,
            ];
        } elseif ($staleWorkerId === $freshWorkerId) {
            $failures[] = [
                'code' => 'stale_worker_routing_worker_ids_not_distinct',
                'scenario_id' => 'stale_worker_routing_exclusion',
                'worker_id' => $staleWorkerId,
            ];
        }

        if ($threshold === null || $threshold <= 0) {
            $failures[] = [
                'code' => 'stale_worker_routing_invalid_configured_threshold',
                'scenario_id' => 'stale_worker_routing_exclusion',
                'field' => 'configured_stale_threshold_seconds',
                'value' => self::fieldValue($outputs, 'configured_stale_threshold_seconds'),
            ];
        }

        if (self::isEmptyEvidence($transitionTiming)) {
            $failures[] = [
                'code' => 'stale_worker_routing_missing_transition_timing',
                'scenario_id' => 'stale_worker_routing_exclusion',
                'field' => 'observed_stale_transition_timing',
            ];
        }

        if (self::isEmptyEvidence($beforeObservations)) {
            $failures[] = [
                'code' => 'stale_worker_routing_missing_before_observations',
                'scenario_id' => 'stale_worker_routing_exclusion',
                'field' => 'routing_observations_before_stale',
            ];
        }

        if (self::isEmptyEvidence($afterObservations)) {
            $failures[] = [
                'code' => 'stale_worker_routing_missing_after_observations',
                'scenario_id' => 'stale_worker_routing_exclusion',
                'field' => 'routing_observations_after_stale',
            ];
        }

        if ($staleWorkerId !== '' && $freshWorkerId !== '' && $staleWorkerId !== $freshWorkerId) {
            array_push(
                $failures,
                ...self::staleRoutingWorkerBindingFailures(
                    $beforeObservations,
                    $afterObservations,
                    $staleWorkerId,
                    $freshWorkerId,
                ),
            );
        }

        if (! self::freshWorkerEligibleEvidence($freshEligibility, $freshWorkerId)) {
            $failures[] = [
                'code' => 'fresh_worker_not_eligible_after_peer_stale',
                'scenario_id' => 'stale_worker_routing_exclusion',
                'field' => 'fresh_worker_eligibility_after_stale',
                'value' => $freshEligibility,
            ];
        }

        if (! self::hasWorkerStatusSurface($surfaces)) {
            $failures[] = [
                'code' => 'stale_worker_routing_missing_worker_status_surface',
                'scenario_id' => 'stale_worker_routing_exclusion',
                'field' => 'public_surfaces',
                'surfaces' => $surfaces,
            ];
        }

        if (! self::hasRoutingSurface($surfaces)) {
            $failures[] = [
                'code' => 'stale_worker_routing_missing_routing_surface',
                'scenario_id' => 'stale_worker_routing_exclusion',
                'field' => 'public_surfaces',
                'surfaces' => $surfaces,
            ];
        }

        if (! self::isConcreteEvidence($runId)) {
            $failures[] = [
                'code' => 'stale_worker_routing_missing_conformance_run_id',
                'scenario_id' => 'stale_worker_routing_exclusion',
                'field' => 'conformance_run_id',
            ];
        }

        if ($timestamp === null) {
            $failures[] = [
                'code' => 'stale_worker_routing_missing_parseable_timestamp',
                'scenario_id' => 'stale_worker_routing_exclusion',
                'field' => 'timestamp',
            ];
        }

        if ($claimCount !== 0.0) {
            $failures[] = [
                'code' => 'stale_worker_routing_claims_not_zero',
                'scenario_id' => 'stale_worker_routing_exclusion',
                'field' => 'stale_worker_claim_count',
                'value' => self::fieldValue($outputs, 'stale_worker_claim_count'),
            ];
        }

        return $failures;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function staleRoutingWorkerBindingFailures(
        ?array $beforeObservations,
        ?array $afterObservations,
        string $staleWorkerId,
        string $freshWorkerId,
    ): array {
        $failures = [];
        $beforePositiveWorkerIds = self::routingPositiveWorkerIds($beforeObservations);
        $beforeNegativeWorkerIds = self::routingNegativeWorkerIds($beforeObservations);
        $afterPositiveWorkerIds = self::routingPositiveWorkerIds($afterObservations);
        $afterNegativeWorkerIds = self::routingNegativeWorkerIds($afterObservations);

        if (! in_array($staleWorkerId, $beforePositiveWorkerIds, true)) {
            $failures[] = [
                'code' => 'stale_worker_routing_before_missing_stale_worker_evidence',
                'scenario_id' => 'stale_worker_routing_exclusion',
                'field' => 'routing_observations_before_stale',
                'worker_id' => $staleWorkerId,
                'observed_worker_ids' => $beforePositiveWorkerIds,
            ];
        }

        if (! in_array($freshWorkerId, $beforePositiveWorkerIds, true)) {
            $failures[] = [
                'code' => 'stale_worker_routing_before_missing_fresh_worker_evidence',
                'scenario_id' => 'stale_worker_routing_exclusion',
                'field' => 'routing_observations_before_stale',
                'worker_id' => $freshWorkerId,
                'observed_worker_ids' => $beforePositiveWorkerIds,
            ];
        }

        if (in_array($staleWorkerId, $beforeNegativeWorkerIds, true)) {
            $failures[] = [
                'code' => 'stale_worker_routing_before_stale_worker_already_excluded',
                'scenario_id' => 'stale_worker_routing_exclusion',
                'field' => 'routing_observations_before_stale',
                'worker_id' => $staleWorkerId,
                'observed_worker_ids' => $beforeNegativeWorkerIds,
            ];
        }

        if (! in_array($freshWorkerId, $afterPositiveWorkerIds, true)) {
            $failures[] = [
                'code' => 'stale_worker_routing_after_missing_fresh_worker_evidence',
                'scenario_id' => 'stale_worker_routing_exclusion',
                'field' => 'routing_observations_after_stale',
                'worker_id' => $freshWorkerId,
                'observed_worker_ids' => $afterPositiveWorkerIds,
            ];
        }

        if (! in_array($staleWorkerId, $afterNegativeWorkerIds, true)) {
            $failures[] = [
                'code' => 'stale_worker_routing_after_missing_stale_worker_exclusion',
                'scenario_id' => 'stale_worker_routing_exclusion',
                'field' => 'routing_observations_after_stale',
                'worker_id' => $staleWorkerId,
                'observed_worker_ids' => $afterNegativeWorkerIds,
            ];
        }

        if (in_array($staleWorkerId, $afterPositiveWorkerIds, true)) {
            $failures[] = [
                'code' => 'stale_worker_routing_after_stale_worker_still_eligible',
                'scenario_id' => 'stale_worker_routing_exclusion',
                'field' => 'routing_observations_after_stale',
                'worker_id' => $staleWorkerId,
                'observed_worker_ids' => $afterPositiveWorkerIds,
            ];
        }

        if (in_array($freshWorkerId, $afterNegativeWorkerIds, true)) {
            $failures[] = [
                'code' => 'stale_worker_routing_after_fresh_worker_excluded',
                'scenario_id' => 'stale_worker_routing_exclusion',
                'field' => 'routing_observations_after_stale',
                'worker_id' => $freshWorkerId,
                'observed_worker_ids' => $afterNegativeWorkerIds,
            ];
        }

        return $failures;
    }

    private static function freshWorkerEligibleEvidence(mixed $value, string $freshWorkerId): bool
    {
        if ($freshWorkerId === '' || ! is_array($value) || self::isEmptyEvidence($value)) {
            return false;
        }

        if (! in_array($freshWorkerId, self::evidenceWorkerIds($value), true)) {
            return false;
        }

        if (self::hasNegativeEligibilityEvidence($value)) {
            return false;
        }

        return self::hasPositiveEligibilityEvidence($value);
    }

    /**
     * @return list<string>
     */
    private static function routingPositiveWorkerIds(mixed $value): array
    {
        return self::routingWorkerIds($value, [
            'eligible_workers',
            'eligibleWorkers',
            'fresh_workers',
            'freshWorkers',
            'active_workers',
            'activeWorkers',
            'included_workers',
            'includedWorkers',
            'routable_workers',
            'routableWorkers',
            'admitted_workers',
            'admittedWorkers',
            'accepted_workers',
            'acceptedWorkers',
            'routed_workers',
            'routedWorkers',
            'claiming_workers',
            'claimingWorkers',
            'claimed_workers',
            'claimedWorkers',
            'lease_owners',
            'leaseOwners',
            'query_task_lease_owners',
            'queryTaskLeaseOwners',
            'workflow_task_lease_owners',
            'workflowTaskLeaseOwners',
            'worker_ids',
            'workerIds',
        ], true);
    }

    /**
     * @return list<string>
     */
    private static function routingNegativeWorkerIds(mixed $value): array
    {
        return self::routingWorkerIds($value, [
            'excluded_workers',
            'excludedWorkers',
            'stale_workers',
            'staleWorkers',
            'ineligible_workers',
            'ineligibleWorkers',
            'blocked_workers',
            'blockedWorkers',
            'rejected_workers',
            'rejectedWorkers',
            'denied_workers',
            'deniedWorkers',
            'skipped_workers',
            'skippedWorkers',
            'not_routed_workers',
            'notRoutedWorkers',
        ], false);
    }

    /**
     * @param  list<string>  $workerListFields
     * @return list<string>
     */
    private static function routingWorkerIds(mixed $value, array $workerListFields, bool $positive): array
    {
        if (! is_array($value)) {
            return [];
        }

        $workerIds = [];
        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, $workerListFields, true)) {
                array_push($workerIds, ...self::evidenceWorkerIds($item));

                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            array_push($workerIds, ...self::routingWorkerIds($item, $workerListFields, $positive));
        }

        if (! $isList) {
            $workerId = self::stringField($value, ['worker_id', 'workerId', 'worker']);
            if ($workerId !== '') {
                if ($positive && self::hasPositiveEligibilityEvidence($value) && ! self::hasNegativeEligibilityEvidence($value)) {
                    $workerIds[] = $workerId;
                }

                if (! $positive && self::hasNegativeEligibilityEvidence($value)) {
                    $workerIds[] = $workerId;
                }
            }
        }

        return array_values(array_unique($workerIds));
    }

    /**
     * @return list<string>
     */
    private static function evidenceWorkerIds(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            return [trim($value)];
        }

        if (! is_array($value)) {
            return [];
        }

        $workerIds = [];
        $isList = array_is_list($value);

        if (! $isList) {
            $workerId = self::stringField($value, [
                'worker_id',
                'workerId',
                'worker',
                'worker_name',
                'workerName',
            ]);
            if ($workerId !== '') {
                $workerIds[] = $workerId;
            }
        }

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, [
                'worker_id',
                'workerId',
                'worker',
                'worker_name',
                'workerName',
            ], true)) {
                continue;
            }

            if (is_int($key) && is_string($item) && trim($item) !== '') {
                $workerIds[] = trim($item);

                continue;
            }

            if (is_array($item)) {
                array_push($workerIds, ...self::evidenceWorkerIds($item));
            }
        }

        return array_values(array_unique($workerIds));
    }

    private static function hasPositiveEligibilityEvidence(array $value): bool
    {
        foreach ([
            'eligible',
            'is_eligible',
            'fresh_worker_eligible',
            'freshWorkerEligible',
            'routing_eligible',
            'routingEligible',
            'admitted',
            'accepted',
            'routable',
            'claimable',
            'claimed',
        ] as $field) {
            if (self::isPositiveEligibilityValue(self::fieldValue($value, $field))) {
                return true;
            }
        }

        foreach (['status', 'routing_status', 'routingStatus'] as $field) {
            if (self::isPositiveEligibilityValue(self::fieldValue($value, $field))) {
                return true;
            }
        }

        return false;
    }

    private static function hasNegativeEligibilityEvidence(array $value): bool
    {
        foreach ([
            'eligible',
            'is_eligible',
            'fresh_worker_eligible',
            'freshWorkerEligible',
            'routing_eligible',
            'routingEligible',
            'admitted',
            'accepted',
            'routable',
            'claimable',
            'claimed',
        ] as $field) {
            $candidate = self::fieldValue($value, $field);
            if ($candidate === false) {
                return true;
            }

            if (is_string($candidate) && self::isNegativeEligibilityValue($candidate)) {
                return true;
            }
        }

        foreach (['status', 'routing_status', 'routingStatus', 'reason', 'routing_reason', 'routingReason'] as $field) {
            if (self::isNegativeEligibilityValue(self::fieldValue($value, $field))) {
                return true;
            }
        }

        return false;
    }

    private static function isPositiveEligibilityValue(mixed $value): bool
    {
        if ($value === true) {
            return true;
        }

        if (! is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), [
            'active',
            'admitted',
            'eligible',
            'fresh',
            'routable',
            'true',
            'yes',
        ], true);
    }

    private static function isNegativeEligibilityValue(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), [
            'blocked',
            'denied',
            'excluded',
            'false',
            'ineligible',
            'not eligible',
            'not_eligible',
            'rejected',
            'stale',
            'stale_worker_registration',
            'unavailable',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @return list<array<string, mixed>>
     */
    private static function waterlineSemanticFailures(array $outputs): array
    {
        $failures = [];

        foreach ([
            'surface_snapshot',
            'stale_worker_render',
            'task_slots_render',
            'process_metrics_render',
        ] as $field) {
            if (self::isConcreteEvidence(self::fieldValue($outputs, $field))) {
                continue;
            }

            $failures[] = [
                'code' => 'waterline_worker_status_visibility_missing_render',
                'scenario_id' => 'waterline_worker_status_visibility',
                'field' => $field,
            ];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @return list<array<string, mixed>>
     */
    private static function heartbeatRejectionSemanticFailures(string $scenarioId, array $outputs): array
    {
        $failures = [];
        $status = self::statusCodeValue(self::fieldValue($outputs, 'status'));

        if ($status === null || $status < 400 || $status >= 500) {
            $failures[] = [
                'code' => 'heartbeat_rejection_status_not_4xx',
                'scenario_id' => $scenarioId,
                'field' => 'status',
                'value' => self::fieldValue($outputs, 'status'),
            ];
        }

        if (! self::isTypedErrorEvidence(self::fieldValue($outputs, 'typed_error'))) {
            $failures[] = [
                'code' => 'heartbeat_rejection_missing_typed_error',
                'scenario_id' => $scenarioId,
                'field' => 'typed_error',
            ];
        }

        if (self::fieldValue($outputs, 'persisted') !== false) {
            $failures[] = [
                'code' => 'heartbeat_rejection_persisted',
                'scenario_id' => $scenarioId,
                'field' => 'persisted',
                'value' => self::fieldValue($outputs, 'persisted'),
            ];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @return list<array<string, mixed>>
     */
    private static function crossNamespaceSemanticFailures(array $outputs): array
    {
        $failures = [];
        $leakCount = self::numberValue(self::fieldValue($outputs, 'leak_count'));

        if ($leakCount !== 0.0) {
            $failures[] = [
                'code' => 'cross_namespace_worker_leak_count_not_zero',
                'scenario_id' => 'cross_namespace_isolation',
                'field' => 'leak_count',
                'value' => self::fieldValue($outputs, 'leak_count'),
            ];
        }

        $workerListA = self::workerIds(self::fieldValue($outputs, 'worker_list_a'));
        $workerListB = self::workerIds(self::fieldValue($outputs, 'worker_list_b'));
        $overlap = array_values(array_intersect($workerListA, $workerListB));
        if ($overlap !== []) {
            $failures[] = [
                'code' => 'cross_namespace_worker_ids_overlap',
                'scenario_id' => 'cross_namespace_isolation',
                'worker_ids' => $overlap,
            ];
        }

        return $failures;
    }

    private static function isConcreteEvidence(mixed $value): bool
    {
        if (is_array($value)) {
            return ! self::isEmptyEvidence($value);
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return true;
        }

        if (! is_string($value)) {
            return false;
        }

        $normalized = strtolower(trim($value));

        return $normalized !== ''
            && ! in_array($normalized, [
                'observed',
                'present',
                'recorded',
                'placeholder',
                'todo',
                'n/a',
                'none',
            ], true);
    }

    private static function hasSuccessiveTimestamps(mixed $value): bool
    {
        if (! is_array($value) || count($value) < 2) {
            return false;
        }

        $timestamps = [];
        foreach ($value as $item) {
            $timestamp = self::timestampValue($item);
            if ($timestamp === null) {
                return false;
            }

            $timestamps[] = $timestamp;
        }

        for ($index = 1; $index < count($timestamps); $index++) {
            if ($timestamps[$index] <= $timestamps[$index - 1]) {
                return false;
            }
        }

        return true;
    }

    private static function timestampValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : (float) $timestamp;
    }

    /**
     * @return list<float>
     */
    private static function numericSamples(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $samples = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                array_push($samples, ...self::numericSamples($item));

                continue;
            }

            $number = self::numberValue($item);
            if ($number !== null) {
                $samples[] = $number;
            }
        }

        return $samples;
    }

    private static function numberValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    private static function statusCodeValue(mixed $value): ?int
    {
        if (is_array($value)) {
            foreach (['status_code', 'statusCode', 'status', 'code', 'http_status', 'httpStatus'] as $field) {
                $status = self::statusCodeValue($value[$field] ?? null);
                if ($status !== null) {
                    return $status;
                }
            }

            return null;
        }

        $number = self::numberValue($value);

        return $number === null ? null : (int) $number;
    }

    private static function isTypedErrorEvidence(mixed $value): bool
    {
        if (is_array($value)) {
            foreach (['type', 'error_type', 'errorType', 'code', 'reason'] as $field) {
                if (self::isConcreteEvidence($value[$field] ?? null)) {
                    return true;
                }
            }

            return false;
        }

        return self::isConcreteEvidence($value);
    }

    /**
     * @return list<string>
     */
    private static function workerIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $workerIds = [];
        foreach ($value as $key => $item) {
            if (is_string($item) && trim($item) !== '') {
                $workerIds[] = trim($item);

                continue;
            }

            if (is_array($item)) {
                $workerId = self::stringField($item, ['worker_id', 'workerId', 'id']);
                if ($workerId !== '') {
                    $workerIds[] = $workerId;
                }

                continue;
            }

            if (is_string($key) && trim($key) !== '') {
                $workerIds[] = trim($key);
            }
        }

        return array_values(array_unique($workerIds));
    }

    /**
     * @return list<string>
     */
    private static function publicSurfaceStrings(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            return [trim($value)];
        }

        if (! is_array($value)) {
            return [];
        }

        $surfaces = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $surfaces[] = trim($item);

                continue;
            }

            if (is_array($item)) {
                array_push($surfaces, ...self::publicSurfaceStrings($item));
            }
        }

        return array_values(array_unique($surfaces));
    }

    /**
     * @param  list<string>  $surfaces
     */
    private static function hasWorkerStatusSurface(array $surfaces): bool
    {
        foreach ($surfaces as $surface) {
            $normalized = strtolower($surface);
            if (str_contains($normalized, '/api/workers')
                || str_contains($normalized, 'worker:list')
                || str_contains($normalized, 'worker list')
                || str_contains($normalized, 'worker:describe')
                || str_contains($normalized, 'worker describe')
                || str_contains($normalized, 'worker status')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $surfaces
     */
    private static function hasRoutingSurface(array $surfaces): bool
    {
        foreach ($surfaces as $surface) {
            $normalized = strtolower($surface);
            if (str_contains($normalized, '/api/workflows')
                || str_contains($normalized, '/api/worker/query-tasks')
                || str_contains($normalized, 'workflow start')
                || str_contains($normalized, 'query task')
                || str_contains($normalized, 'query routing')
                || str_contains($normalized, 'task routing')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $value
     */
    private static function onlyEmptyNestedValues(array $value): bool
    {
        foreach ($value as $item) {
            if (is_array($item)) {
                if (! self::onlyEmptyNestedValues($item)) {
                    return false;
                }

                continue;
            }

            if (! in_array($item, [null, false, '', 0, '0'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $scenarioStatuses
     * @param  array<string, mixed>  $contract
     */
    private static function isSmokeSubset(array $scenarioStatuses, array $contract): bool
    {
        if ($scenarioStatuses === []) {
            return false;
        }

        $requiredScenarios = self::stringList($contract['required_scenarios'] ?? []);

        return count($scenarioStatuses) < count($requiredScenarios)
            && ! array_diff(array_keys($scenarioStatuses), $requiredScenarios);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     * @return list<array<string, mixed>>
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
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     * @return list<array<string, mixed>>
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
            $failures[] = [
                'code' => 'conflicting_outcome_tokens',
                'declared_outcomes' => array_intersect_key($declaredOutcomes, $declaredStatuses),
                'declared_statuses' => $declaredStatuses,
            ];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $scenarioResult
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     * @return list<array<string, mixed>>
     */
    private static function nonPassFindingFailures(
        array $scenarioResult,
        array $result,
        array $contract,
        string $scenarioId,
        string $status,
    ): array {
        $findings = self::nonPassFindings($scenarioResult, $result, $scenarioId);
        if ($findings === []) {
            return [[
                'code' => 'missing_non_pass_finding',
                'scenario_id' => $scenarioId,
                'status' => $status,
            ]];
        }

        $requiredFields = self::stringList($contract['finding_policy']['required_for_non_pass'] ?? []);
        $failures = [];
        foreach ($findings as $index => $finding) {
            if (! is_array($finding)) {
                $failures[] = [
                    'code' => 'unstructured_non_pass_finding',
                    'scenario_id' => $scenarioId,
                    'status' => $status,
                    'finding_index' => $index,
                    'expected_fields' => $requiredFields,
                ];

                continue;
            }

            $missingFields = [];
            foreach ($requiredFields as $field) {
                if (! self::hasFindingField($finding, $field)) {
                    $missingFields[] = $field;
                }
            }

            $findingScenarioId = self::stringValue(self::findingFieldValue($finding, 'scenario_id'));
            if ($findingScenarioId !== '' && $findingScenarioId !== $scenarioId) {
                $failures[] = [
                    'code' => 'non_pass_finding_scenario_mismatch',
                    'scenario_id' => $scenarioId,
                    'finding_scenario_id' => $findingScenarioId,
                    'finding_index' => $index,
                ];
            }

            if ($missingFields !== []) {
                $failures[] = [
                    'code' => 'missing_non_pass_finding_field',
                    'scenario_id' => $scenarioId,
                    'status' => $status,
                    'finding_index' => $index,
                    'missing_fields' => $missingFields,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $scenarioResult
     * @param  array<string, mixed>  $result
     * @return list<mixed>
     */
    private static function nonPassFindings(array $scenarioResult, array $result, string $scenarioId): array
    {
        $findings = [];

        foreach ([
            $scenarioResult['findings'] ?? null,
            $scenarioResult['linked_findings'] ?? null,
            $scenarioResult['linkedFindings'] ?? null,
        ] as $value) {
            if (is_array($value)) {
                array_push($findings, ...array_values($value));
            }
        }

        foreach ([
            self::arrayField($result, ['finding_links', 'findingLinks']) ?? [],
            self::arrayField($result, ['linked_findings', 'linkedFindings']) ?? [],
        ] as $links) {
            $linked = $links[$scenarioId] ?? null;
            if (is_array($linked)) {
                array_push($findings, ...array_values($linked));
            } elseif ($linked !== null) {
                $findings[] = $linked;
            }
        }

        foreach (self::arrayField($result, ['findings']) ?? [] as $finding) {
            if (is_array($finding) && self::stringValue(self::findingFieldValue($finding, 'scenario_id')) === $scenarioId) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private static function hasLinkedFindingsForScenario(array $result, string $scenarioId): bool
    {
        return self::nonPassFindings([], $result, $scenarioId) !== [];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private static function hasFindingList(array $result): bool
    {
        foreach ([
            $result['findings'] ?? null,
            $result['linked_findings'] ?? null,
            $result['linkedFindings'] ?? null,
        ] as $value) {
            if (is_array($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  ...$containers
     * @return list<array{field: string, value: mixed}>
     */
    private static function localProductSourceCheckoutValues(array ...$containers): array
    {
        $values = [];
        foreach ($containers as $container) {
            foreach ([
                'local_product_source_checkouts_used',
                'localProductSourceCheckoutsUsed',
            ] as $field) {
                if (array_key_exists($field, $container)) {
                    $values[] = ['field' => $field, 'value' => $container[$field]];
                }
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  ...$containers
     */
    private static function hasExplicitFalseLocalProductSourceFlag(array ...$containers): bool
    {
        foreach (self::localProductSourceCheckoutValues(...$containers) as $flag) {
            if (($flag['value'] ?? null) === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  ...$containers
     */
    private static function firstLocalProductSourceFlagValue(array ...$containers): mixed
    {
        $flags = self::localProductSourceCheckoutValues(...$containers);

        return $flags[0]['value'] ?? null;
    }

    /**
     * @param  list<string>  $forbiddenSources
     */
    private static function isForbiddenArtifactSource(string $source, array $forbiddenSources): bool
    {
        $normalized = strtolower(trim($source));
        if ($normalized === '') {
            return false;
        }

        foreach ($forbiddenSources as $forbiddenSource) {
            $forbiddenSource = strtolower(trim($forbiddenSource));
            if ($forbiddenSource !== '' && ($normalized === $forbiddenSource || str_contains($normalized, $forbiddenSource))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $versions
     */
    private static function artifactVersionValue(array $versions, string $artifact): string
    {
        $aliases = [
            'sdk-php' => ['sdk-php', 'sdk_php'],
            'sdk-python' => ['sdk-python', 'sdk_python', 'python'],
            'sdk-rust' => ['sdk-rust', 'sdk_rust', 'rust'],
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

    /**
     * @param  array<string, mixed>  $result
     * @return array<mixed>
     */
    private static function artifactVersions(array $result): array
    {
        $versions = [];
        foreach ([
            ['artifact_versions', 'artifactVersions'],
            ['published_artifact_versions', 'publishedArtifactVersions'],
            ['resolved_artifact_versions', 'resolvedArtifactVersions'],
        ] as $aliases) {
            $versionSet = self::arrayField($result, $aliases);
            if ($versionSet !== null) {
                $versions = array_replace($versions, $versionSet);
            }
        }

        return $versions;
    }

    private static function canonicalWorkerArtifact(string $artifact): string
    {
        $normalized = strtolower(str_replace(['_', ' '], '-', trim($artifact)));

        if ($normalized === 'php'
            || $normalized === 'sdk-php'
            || str_contains($normalized, 'durable-workflow/sdk')) {
            return 'sdk-php';
        }

        if ($normalized === 'python'
            || $normalized === 'sdk-python'
            || str_contains($normalized, 'sdk-python')) {
            return 'sdk-python';
        }

        if ($normalized === 'rust'
            || $normalized === 'sdk-rust'
            || str_contains($normalized, 'sdk-rust')) {
            return 'sdk-rust';
        }

        return $normalized;
    }

    private static function canonicalArtifactName(string $artifact): string
    {
        $normalized = strtolower(str_replace(['_', ' '], '-', trim($artifact)));

        if ($normalized === 'php'
            || $normalized === 'sdk-php'
            || str_contains($normalized, 'durable-workflow/sdk')) {
            return 'sdk-php';
        }

        if ($normalized === 'python'
            || $normalized === 'sdk-python'
            || str_contains($normalized, 'sdk-python')) {
            return 'sdk-python';
        }

        if ($normalized === 'rust'
            || $normalized === 'sdk-rust'
            || str_contains($normalized, 'sdk-rust')) {
            return 'sdk-rust';
        }

        if ($normalized === 'server' || str_contains($normalized, 'durableworkflow/server')) {
            return 'server';
        }

        if ($normalized === 'cli' || str_contains($normalized, 'durable-workflow/cli')) {
            return 'cli';
        }

        if ($normalized === 'waterline'
            || $normalized === 'waterline-ui'
            || str_contains($normalized, 'durable-workflow/waterline')) {
            return 'waterline';
        }

        return $normalized;
    }

    private static function isPlaceholderVersion(string $version): bool
    {
        $normalized = strtolower(trim($version));
        if ($normalized === '') {
            return true;
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
     * @param  array<mixed>  $values
     */
    private static function containsCell(array $values, string $expected): bool
    {
        foreach ($values as $value) {
            if (is_string($value) && $value === $expected) {
                return true;
            }

            if (! is_array($value)) {
                continue;
            }

            foreach (['id', 'cell', 'scenario', 'name'] as $field) {
                if (self::stringValue($value[$field] ?? null) === $expected) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, string>
     */
    private static function declaredOutcomeTokens(array $result): array
    {
        $tokens = [];
        foreach (['outcome', 'status', 'verdict'] as $field) {
            $value = self::stringValue($result[$field] ?? null);
            if ($value !== '') {
                $tokens[$field] = strtolower($value);
            }
        }

        return $tokens;
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return list<string>
     */
    private static function declaredOutcomes(array $contract): array
    {
        $outcomes = [
            'pass',
            'fail',
            'error',
            'non_passing',
        ];

        foreach (self::arrayField($contract, ['coverage_gate', 'coverageGate']) ?? [] as $key => $value) {
            if (! is_string($key) || ! str_ends_with($key, '_outcome')) {
                continue;
            }

            $value = self::stringValue($value);
            if ($value !== '') {
                $outcomes[] = strtolower($value);
            }
        }

        foreach (self::stringList($contract['scenario_statuses'] ?? []) as $status) {
            if ($status !== 'pass') {
                $outcomes[] = strtolower($status);
            }
        }

        return array_values(array_unique($outcomes));
    }

    private static function declaredOutcomeStatus(string $outcome): string
    {
        return in_array($outcome, ['pass', 'passing_product_behavior_with_separate_coverage_finding'], true)
            ? 'pass'
            : 'non_passing';
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private static function runnerBlockedValue(array $result): ?bool
    {
        foreach (['runner_blocked', 'runnerBlocked'] as $field) {
            if (! array_key_exists($field, $result)) {
                continue;
            }

            return filter_var($result[$field], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    private static function hasFindingField(array $finding, string $field): bool
    {
        return ! self::isEmptyEvidence(self::findingFieldValue($finding, $field));
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    private static function findingFieldValue(array $finding, string $field): mixed
    {
        if (array_key_exists($field, $finding)) {
            return $finding[$field];
        }

        $camel = self::camelize($field);

        return $finding[$camel] ?? null;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $aliases
     */
    private static function arrayField(array $value, array $aliases): ?array
    {
        foreach ($aliases as $alias) {
            $candidate = self::fieldValue($value, $alias);
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function fieldValue(array $value, string $field): mixed
    {
        if (array_key_exists($field, $value)) {
            return $value[$field];
        }

        $camel = self::camelize($field);
        if (array_key_exists($camel, $value)) {
            return $value[$camel];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function hasPath(array $value, string $path): bool
    {
        $current = $value;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($current)) {
                return false;
            }

            $next = self::fieldValue($current, $segment);
            if ($next === null && ! array_key_exists($segment, $current) && ! array_key_exists(self::camelize($segment), $current)) {
                return false;
            }

            $current = $next;
        }

        return ! self::isEmptyEvidence($current);
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $fields
     */
    private static function hasScalarField(array $value, array $fields): bool
    {
        foreach ($fields as $field) {
            $candidate = self::fieldValue($value, $field);
            if (is_scalar($candidate) && (string) $candidate !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    private static function stringValue(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $fields
     */
    private static function stringField(array $value, array $fields): string
    {
        foreach ($fields as $field) {
            $candidate = self::stringValue(self::fieldValue($value, $field));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

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

    private static function camelize(string $field): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $field))));
    }

    private static function normalizeToken(string $value): string
    {
        return strtolower(str_replace(['-', '_', ' '], '', trim($value)));
    }
}
