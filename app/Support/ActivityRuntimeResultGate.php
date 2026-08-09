<?php

namespace App\Support;

/**
 * Evaluates activity conformance results against the public activity contract
 * exposed by ActivityRuntimeContract.
 */
final class ActivityRuntimeResultGate
{
    public const SCHEMA = 'durable-workflow.v2.activity-runtime.result-gate';

    public const VERSION = 2;

    private const FORBIDDEN_ARTIFACT_SOURCE_TOKENS = [
        'local_product_source_checkout',
        'workspace_repo_as_artifact_under_test',
        'local_checkout_artifact',
        'local_checkout',
        'local_source_checkout',
        'source_checkout',
        'workspace_repo',
        'unverified_artifact_source',
    ];

    private const PUBLISHED_SERVER_IMAGE_REPOSITORIES = [
        'durableworkflow/server',
        'docker.io/durableworkflow/server',
        'index.docker.io/durableworkflow/server',
        'registry-1.docker.io/durableworkflow/server',
        'ghcr.io/durable-workflow/server',
    ];

    private const PUBLISHED_SERVER_CONTAINER_EXECUTION_SOURCE = 'published_server_container';

    private const FOCUSED_ACTIVITY_HOST_SCENARIO_MODES = [
        'workflow_embedded_activity_result' => 'workflow-embedded',
        'standalone_activity_result' => 'standalone',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'evaluates_result_schema' => ActivityRuntimeContract::RESULT_SCHEMA,
            'scenario_statuses_source' => 'activity_runtime_contract.scenario_statuses',
            'required_scenarios_source' => 'activity_runtime_contract.required_scenarios',
            'required_matrix_source' => 'activity_runtime_contract.required_matrix',
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
            'declared_outcomes_source' => 'activity_runtime_contract.coverage_gate.*_outcome',
            'non_pass_statuses' => [
                'fail',
                'unsupported',
                'not_covered',
                'runner_blocked',
            ],
            'pass_requires' => [
                'every_required_scenario_has_one_result',
                'every_result_uses_a_published_status',
                'workflow_embedded_and_standalone_activity_modes_are_reported',
                'required_php_and_python_activity_runtimes_are_reported',
                'durable_result_retry_timeout_php_sdk_heartbeat_renewal_failure_heartbeat_cancellation_idempotency_and_visibility_sections_are_reported',
                'each_pass_scenario_has_observed_outputs',
                'each_pass_scenario_has_scenario_specific_evidence',
                'published_artifact_install_evidence_reported',
                'published_activity_runtime_evidence_executes_the_pinned_server_artifact',
                'published_activity_host_evidence_reported_for_activity_result_cells',
                'each_non_pass_scenario_has_linked_findings',
                'run_timestamps_outcome_and_finding_links_are_recorded',
                'overall_outcome_matches_gate_status',
                'published_artifact_versions_are_recorded_and_pinned',
                'no_local_product_source_artifacts_are_reported',
                'published_artifact_install_sources_are_recorded_for_every_required_channel',
                'runner_blocked_false_for_product_evidence',
                'non_pass_cells_are_classified_by_root_cause',
            ],
            'artifact_version_policy' => [
                'rejects_placeholder_versions' => true,
                'required_artifacts' => [
                    'server',
                    'cli',
                    'sdk-php',
                    'sdk-python',
                    'workflow',
                    'waterline',
                ],
                'accepted_aliases' => [
                    'workflow' => ['workflow-php'],
                    'sdk-python' => ['python'],
                ],
            ],
            'classification_policy' => [
                'allowed_non_pass_classifications' => [
                    'product-gap',
                    'coverage-gap',
                    'runner-gap',
                    'stale-artifact',
                    'pipeline-churn',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>|null  $contract
     * @return array<string, mixed>
     */
    public static function evaluate(array $result, ?array $contract = null): array
    {
        $contract ??= ActivityRuntimeContract::manifest();

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
                if (! self::hasScenarioSpecificEvidence($scenarioResult)) {
                    $failures[] = [
                        'code' => 'missing_pass_scenario_specific_evidence',
                        'scenario_id' => $scenarioId,
                    ];
                }
                if ($scenarioId !== 'published_artifact_install_only') {
                    array_push(
                        $failures,
                        ...self::publishedServerExecutionFailures($scenarioId, $scenarioResult, $result),
                        ...self::activityHostEvidenceFailures($scenarioId, $scenarioResult, $result),
                        ...self::activityIsolationFailures($scenarioId, $scenarioResult),
                    );
                }
                if ($scenarioId === 'heartbeat_timeout_renewal_across_enforcement_passes') {
                    array_push(
                        $failures,
                        ...self::heartbeatTimeoutRenewalFailures($scenarioResult, $result),
                    );
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
                if (! self::hasAllowedClassification($scenarioResult, $result)) {
                    $failures[] = [
                        'code' => 'missing_or_invalid_non_pass_classification',
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
        array_push($failures, ...self::artifactVersionFailures($result));
        array_push($failures, ...self::sourcePolicyFailures($result, $contract));
        array_push($failures, ...self::matrixFailures($result, $contract));
        array_push($failures, ...self::requiredSectionFailures($result));

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
     * @param  array<string, mixed>  $result
     * @param  array<string, int>  $duplicateScenarioCounts
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
     * @param  array<string, mixed>  $scenarioResult
     * @return list<array<string, mixed>>
     */
    private static function activityIsolationFailures(string $scenarioId, array $scenarioResult): array
    {
        $outputs = self::arrayField($scenarioResult, [
            'observed_outputs',
            'observedOutputs',
            'activity_evidence',
            'activityEvidence',
            'evidence',
        ]) ?? [];

        if ($scenarioId === 'heartbeat_timeout_renewal_across_enforcement_passes') {
            $managedDeregistration = self::arrayField($outputs, [
                'managed_worker_deregistration',
                'managedWorkerDeregistration',
            ]) ?? [];
            $managedResponse = self::arrayField($managedDeregistration, ['response']) ?? [];
            $negative = self::arrayField($outputs, ['negative_control', 'negativeControl']) ?? [];
            $negativeDeregistration = self::arrayField($negative, [
                'worker_deregistration',
                'workerDeregistration',
            ]) ?? [];
            $workerId = self::stringValue($outputs['worker_id'] ?? $outputs['workerId'] ?? null);
            $negativeWorkerId = self::stringValue($negative['worker_id'] ?? $negative['workerId'] ?? null);
            $taskQueue = self::stringValue($outputs['task_queue'] ?? $outputs['taskQueue'] ?? null);
            $negativeTaskQueue = self::stringValue($negative['task_queue'] ?? $negative['taskQueue'] ?? null);
            if (($managedResponse['outcome'] ?? null) !== 'deregistered'
                || ($negativeDeregistration['outcome'] ?? null) !== 'deregistered'
                || $workerId === ''
                || $negativeWorkerId === ''
                || $workerId === $negativeWorkerId
                || $taskQueue === ''
                || $negativeTaskQueue === ''
                || $taskQueue === $negativeTaskQueue) {
                return [['code' => 'heartbeat_timeout_renewal_fresh_negative_worker_missing']];
            }
        }

        if ($scenarioId === 'idempotent_completion_handling') {
            $first = self::arrayField($outputs, ['first_completion_response', 'firstCompletionResponse']) ?? [];
            $duplicate = self::arrayField($outputs, ['duplicate_completion_response', 'duplicateCompletionResponse']) ?? [];
            $completedEvents = self::arrayField($outputs, [
                'activity_completed_history_events',
                'activityCompletedHistoryEvents',
            ]) ?? [];
            $completed = is_array($completedEvents[0] ?? null) ? $completedEvents[0] : [];
            $executionId = self::stringValue($outputs['activity_execution_id'] ?? null);
            if (($outputs['same_task_and_attempt_ids'] ?? null) !== true
                || ($outputs['recorded_once'] ?? null) !== true
                || ($outputs['activity_completed_history_count'] ?? null) !== 1
                || count($completedEvents) !== 1
                || ($first['recorded'] ?? null) !== true
                || ($duplicate['recorded'] ?? null) !== false
                || ($duplicate['reason'] ?? null) !== 'stale_attempt'
                || self::stringValue($first['task_id'] ?? null) === ''
                || ($first['task_id'] ?? null) !== ($duplicate['task_id'] ?? null)
                || self::stringValue($first['activity_attempt_id'] ?? null) === ''
                || ($first['activity_attempt_id'] ?? null) !== ($duplicate['activity_attempt_id'] ?? null)
                || $executionId === ''
                || ($completed['activity_execution_id'] ?? null) !== $executionId
                || ($completed['activity_attempt_id'] ?? null) !== ($first['activity_attempt_id'] ?? null)) {
                return [['code' => 'idempotent_completion_task_attempt_identity_invalid']];
            }
        }

        if ($scenarioId === 'php_python_activity_parity') {
            $handles = self::arrayField($outputs, ['handle_responses', 'handleResponses']) ?? [];
            foreach ([
                'php' => ['runtime' => 'workflow-php', 'handle' => 'workflow-php', 'result' => 'php_activity_result'],
                'python' => ['runtime' => 'sdk-python', 'handle' => 'sdk-python', 'result' => 'python_activity_result'],
            ] as $prefix => $identity) {
                $result = is_array($outputs[$identity['result']] ?? null) ? $outputs[$identity['result']] : [];
                $handle = is_array($handles[$identity['handle']] ?? null) ? $handles[$identity['handle']] : [];
                $activityId = self::stringValue($outputs[$prefix.'_activity_id'] ?? null);
                $runId = self::stringValue($outputs[$prefix.'_workflow_run_id'] ?? null);
                $executionId = self::stringValue($outputs[$prefix.'_activity_execution_id'] ?? null);
                if ($activityId === ''
                    || $runId === ''
                    || $executionId === ''
                    || ($result['runtime'] ?? null) !== $identity['runtime']
                    || ! str_starts_with(self::stringValue($result['input_marker'] ?? null), 'parity-result-')
                    || ($handle['activity_id'] ?? null) !== $activityId
                    || ($handle['workflow_run_id'] ?? null) !== $runId
                    || ($handle['activity_execution_id'] ?? null) !== $executionId) {
                    return [['code' => 'php_python_parity_'.$prefix.'_completion_identity_invalid']];
                }
            }
        }

        if ($scenarioId === 'operator_visible_activity_attempt_state') {
            $fixture = self::arrayField($outputs, [
                'stale_shared_queue_regression_fixture',
                'staleSharedQueueRegressionFixture',
            ]) ?? [];
            $retryAvailableAt = self::timestampField($fixture, ['retry_available_at', 'retryAvailableAt']);
            $backoffCrossedAt = self::timestampField($fixture, ['backoff_crossed_at', 'backoffCrossedAt']);
            $deadline = self::timestampField($fixture, [
                'timed_out_worker_visible_start_to_close_deadline',
                'timedOutWorkerVisibleStartToCloseDeadline',
            ]);
            $retryTaskQueue = self::stringValue($fixture['retry_task_queue'] ?? null);
            $timedOutTaskQueue = self::stringValue($fixture['timed_out_task_queue'] ?? null);
            $retryExecutionId = self::stringValue($fixture['retry_activity_execution_id'] ?? null);
            $timedOutExecutionId = self::stringValue($fixture['timed_out_activity_execution_id'] ?? null);
            if (($fixture['configured_backoff_seconds'] ?? null) !== 60
                || ($fixture['backoff_crossed_before_timed_out_poll'] ?? null) !== true
                || ($fixture['isolated_task_queues'] ?? null) !== true
                || $retryTaskQueue === ''
                || $timedOutTaskQueue === ''
                || $retryTaskQueue === $timedOutTaskQueue
                || $retryExecutionId === ''
                || $timedOutExecutionId === ''
                || $retryExecutionId === $timedOutExecutionId
                || $retryAvailableAt === null
                || $backoffCrossedAt === null
                || $backoffCrossedAt < $retryAvailableAt
                || $deadline === null) {
                return [['code' => 'operator_visibility_stale_queue_regression_fixture_invalid']];
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $scenarioResult
     */
    private static function hasObservedOutputs(array $scenarioResult): bool
    {
        foreach ([
            'observed_outputs',
            'observedOutputs',
            'activity_evidence',
            'activityEvidence',
            'history_events',
            'historyEvents',
            'operator_visibility',
            'operatorVisibility',
            'runtime_matrix',
            'runtimeMatrix',
        ] as $field) {
            $value = self::arrayValue($scenarioResult, $field);
            if ($value !== null && $value !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $scenarioResult
     */
    private static function hasScenarioSpecificEvidence(array $scenarioResult): bool
    {
        $evidence = self::arrayValue($scenarioResult, 'scenario_evidence')
            ?? self::arrayValue($scenarioResult, 'scenarioEvidence')
            ?? self::arrayValue($scenarioResult, 'observed_outputs')
            ?? self::arrayValue($scenarioResult, 'observedOutputs');

        return $evidence !== null && $evidence !== [];
    }

    /**
     * @param  array<string, mixed>  $scenarioResult
     * @param  array<string, mixed>  $result
     */
    private static function hasLinkedFindings(array $scenarioResult, array $result): bool
    {
        foreach (['linked_findings', 'linkedFindings', 'findings'] as $field) {
            $value = self::arrayValue($scenarioResult, $field);
            if ($value !== null && $value !== []) {
                return true;
            }
        }

        $scenarioId = self::stringValue($scenarioResult['scenario_id'] ?? null);
        if ($scenarioId === '') {
            return false;
        }

        $findingLinks = self::arrayValue($result, 'finding_links') ?? self::arrayValue($result, 'findingLinks') ?? [];
        if (isset($findingLinks[$scenarioId]) && is_array($findingLinks[$scenarioId]) && $findingLinks[$scenarioId] !== []) {
            return true;
        }

        foreach (self::arrayValue($result, 'findings') ?? [] as $finding) {
            if (! is_array($finding)) {
                continue;
            }
            if (self::stringValue($finding['scenario_id'] ?? $finding['scenarioId'] ?? null) === $scenarioId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $scenarioResult
     * @param  array<string, mixed>  $result
     */
    private static function hasAllowedClassification(array $scenarioResult, array $result): bool
    {
        $allowed = [
            'product-gap',
            'coverage-gap',
            'runner-gap',
            'stale-artifact',
            'pipeline-churn',
        ];

        $classification = self::stringValue(
            $scenarioResult['classification']
            ?? $scenarioResult['root_cause_classification']
            ?? $scenarioResult['rootCauseClassification']
            ?? null,
        );
        if (in_array($classification, $allowed, true)) {
            return true;
        }

        $scenarioId = self::stringValue($scenarioResult['scenario_id'] ?? null);
        foreach (self::arrayValue($result, 'findings') ?? [] as $finding) {
            if (! is_array($finding)) {
                continue;
            }
            if (self::stringValue($finding['scenario_id'] ?? $finding['scenarioId'] ?? null) !== $scenarioId) {
                continue;
            }

            $classification = self::stringValue(
                $finding['classification']
                ?? $finding['root_cause_classification']
                ?? $finding['rootCauseClassification']
                ?? null,
            );
            if (in_array($classification, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     * @return list<array<string, mixed>>
     */
    private static function runRecordFailures(array $result, array $contract): array
    {
        $required = self::stringList($contract['artifact_policy']['required_run_record_fields'] ?? []);
        $failures = [];

        foreach ($required as $field) {
            if (! self::hasRunRecordField($result, $field)) {
                $failures[] = [
                    'code' => 'missing_run_record_field',
                    'field' => $field,
                ];
            }
        }

        $runnerBlocked = self::runnerBlockedValue($result);
        if ($runnerBlocked !== false) {
            $failures[] = [
                'code' => 'runner_blocked_result_is_not_product_evidence',
                'field' => 'runner_blocked',
                'expected' => false,
                'actual' => $runnerBlocked,
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
            'artifact_versions' => self::arrayField($result, ['artifact_versions', 'artifactVersions']) !== null,
            'published_artifact_versions' => self::arrayField($result, [
                'published_artifact_versions',
                'publishedArtifactVersions',
            ]) !== null,
            'artifact_sources' => self::arrayField($result, ['artifact_sources', 'artifactSources']) !== null,
            'runner_blocked' => self::runnerBlockedValue($result) !== null,
            'scenario_results' => self::arrayField($result, ['scenario_results', 'scenarioResults']) !== null,
            'finding_links' => self::arrayField($result, ['finding_links', 'findingLinks']) !== null,
            'findings' => array_key_exists('findings', $result) && is_array($result['findings']),
            default => array_key_exists($field, $result),
        };
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

            return is_bool($result[$field]) ? $result[$field] : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private static function artifactVersionFailures(array $result): array
    {
        $versions = self::arrayValue($result, 'published_artifact_versions')
            ?? self::arrayValue($result, 'publishedArtifactVersions')
            ?? self::arrayValue($result, 'artifact_versions')
            ?? self::arrayValue($result, 'artifactVersions')
            ?? [];
        $failures = [];

        foreach (['server', 'cli', 'sdk-php', 'sdk-python', 'workflow', 'waterline'] as $artifact) {
            $value = self::artifactVersion($versions, $artifact);
            if ($value === '') {
                $failures[] = [
                    'code' => 'missing_artifact_version',
                    'artifact' => $artifact,
                ];

                continue;
            }

            if (! self::isExactVersion($value)) {
                $failures[] = [
                    'code' => 'invalid_artifact_version',
                    'artifact' => $artifact,
                    'version' => $value,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $versions
     */
    private static function artifactVersion(array $versions, string $artifact): string
    {
        $aliases = [
            'workflow' => ['workflow', 'workflow-php'],
            'sdk-php' => ['sdk-php', 'sdk_php', 'php'],
            'sdk-python' => ['sdk-python', 'sdk_python', 'python'],
        ];

        foreach ($aliases[$artifact] ?? [$artifact] as $key) {
            $value = self::stringValue($versions[$key] ?? null);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function isExactVersion(string $value): bool
    {
        if (preg_match('/(<[^>]+>|\$\{[^}]+}|{{[^}]+}}|(^|[^a-z0-9])latest([^a-z0-9]|$)|current|head|unresolved|placeholder)/i', $value)) {
            return false;
        }

        return (bool) preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $value);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     * @return list<array<string, mixed>>
     */
    private static function sourcePolicyFailures(array $result, array $contract): array
    {
        $failures = [];
        if (self::truthy($result['local_product_source_checkouts_used'] ?? null)) {
            $failures[] = [
                'code' => 'local_product_source_checkouts_used',
            ];
        }

        $sources = self::arrayField($result, ['artifact_sources', 'artifactSources']);
        if ($sources === null || $sources === []) {
            $failures[] = [
                'code' => 'missing_artifact_sources',
            ];

            return $failures;
        }

        $versions = self::arrayField($result, ['published_artifact_versions', 'publishedArtifactVersions'])
            ?? self::arrayField($result, ['artifact_versions', 'artifactVersions'])
            ?? [];
        $requiredArtifacts = array_keys($contract['artifact_policy']['install_channels'] ?? [
            'server' => true,
            'cli' => true,
            'workflow-php' => true,
            'sdk-python' => true,
            'waterline' => true,
        ]);

        foreach ($requiredArtifacts as $artifact) {
            $artifact = (string) $artifact;
            $source = self::artifactSource($sources, $artifact);
            $sourceText = self::stringValue($source);
            if (! self::sourceValueRecorded($source)) {
                $failures[] = [
                    'code' => 'missing_published_artifact_install_source',
                    'artifact' => $artifact,
                ];

                continue;
            }

            if (self::artifactSourceIsForbidden($sourceText)) {
                $failures[] = [
                    'code' => 'forbidden_artifact_source',
                    'artifact' => $artifact,
                    'source' => $sourceText,
                ];

                continue;
            }

            $version = self::artifactVersionForInstallChannel($versions, $artifact);
            if (! self::matchesPublishedArtifactSource($artifact, $version, $sourceText)) {
                $failures[] = [
                    'code' => 'unrecognized_published_artifact_install_source',
                    'artifact' => $artifact,
                    'source' => $sourceText,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $sources
     */
    private static function artifactSource(array $sources, string $artifact): mixed
    {
        $aliases = [
            'workflow-php' => ['workflow-php', 'workflow_php', 'workflow'],
            'sdk-php' => ['sdk-php', 'sdk_php', 'php'],
            'sdk-python' => ['sdk-python', 'sdk_python', 'python'],
            'waterline' => ['waterline', 'waterline-ui', 'waterline_ui'],
        ];

        foreach ($aliases[$artifact] ?? [$artifact] as $key) {
            if (array_key_exists($key, $sources)) {
                return $sources[$key];
            }
        }

        return null;
    }

    private static function sourceValueRecorded(mixed $value): bool
    {
        $source = strtolower(self::stringValue($value));

        return $source !== ''
            && ! in_array($source, ['not_exercised', 'missing', 'unknown', 'unresolved'], true);
    }

    private static function artifactSourceIsForbidden(string $source): bool
    {
        $normalized = strtolower(trim($source));
        $decoded = urldecode($normalized);

        foreach ([$normalized, $decoded] as $candidate) {
            foreach (self::FORBIDDEN_ARTIFACT_SOURCE_TOKENS as $token) {
                if (str_contains($candidate, strtolower($token))) {
                    return true;
                }
            }

            if (self::isLocalArtifactSourcePath($candidate)
                || preg_match('/(^|[\/:@=?&#._-])(latest|current|head)(?:$|[\/:@?&#._-])/', $candidate) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function isLocalArtifactSourcePath(string $source): bool
    {
        $path = str_replace('\\', '/', trim($source));

        return str_starts_with($path, 'file:')
            || preg_match('/^local(?::|\/|$)/', $path) === 1
            || preg_match('/^~(?:[^\/]*)?(?:\/|$)/', $path) === 1
            || preg_match('/^\$(?:home|userprofile)(?:\/|$)/', $path) === 1
            || preg_match('/^\$\{(?:home|userprofile)\}(?:\/|$)/', $path) === 1
            || preg_match('/^%(?:home|userprofile|homedrive|homepath)%/', $path) === 1
            || preg_match('/^\/[^\/]+/', $path) === 1
            || preg_match('/^[a-z]:\//', $path) === 1
            || preg_match('/^\.\.?(?:\/|$)/', $path) === 1
            || preg_match('/(^|[^a-z0-9])\/?workspace\/repos\//', $path) === 1
            || preg_match('/^repos\/(?:server|workflow|waterline|cli|cloud|sample-app|sdk-php|sdk-python|durable-workflow\.github\.io)(?:\/|$)/', $path) === 1;
    }

    /**
     * @param  array<string, mixed>  $versions
     */
    private static function artifactVersionForInstallChannel(array $versions, string $artifact): string
    {
        $aliases = [
            'workflow-php' => ['workflow-php', 'workflow_php', 'workflow'],
            'sdk-php' => ['sdk-php', 'sdk_php', 'php'],
            'sdk-python' => ['sdk-python', 'sdk_python', 'python'],
        ];

        foreach ($aliases[$artifact] ?? [$artifact] as $key) {
            $value = self::stringValue($versions[$key] ?? null);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function matchesPublishedArtifactSource(string $artifact, string $version, string $source): bool
    {
        if ($version === '') {
            return false;
        }

        return match ($artifact) {
            'server' => self::matchesServerArtifactSource($version, $source),
            'cli' => self::matchesCliArtifactSource($version, $source),
            'sdk-php' => self::matchesComposerArtifactSource('durable-workflow/sdk', $version, $source),
            'sdk-python' => self::matchesPythonArtifactSource($version, $source),
            'workflow-php' => self::matchesComposerArtifactSource('durable-workflow/workflow', $version, $source),
            'waterline' => self::matchesComposerArtifactSource('durable-workflow/waterline', $version, $source),
            default => false,
        };
    }

    private static function matchesServerArtifactSource(string $version, string $source): bool
    {
        $image = preg_replace('/^docker:\/\//i', '', trim($source));
        if ($image === null || $image === '') {
            return false;
        }

        $escapedVersion = preg_quote($version, '/');
        foreach (self::PUBLISHED_SERVER_IMAGE_REPOSITORIES as $repository) {
            $escapedRepository = preg_quote($repository, '/');

            if (strcasecmp($image, $repository.':'.$version) === 0
                || preg_match('/^'.$escapedRepository.'@sha256:[0-9a-f]{64}$/i', $image) === 1
                || preg_match('/^'.$escapedRepository.':'.$escapedVersion.'@sha256:[0-9a-f]{64}$/i', $image) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function matchesCliArtifactSource(string $version, string $source): bool
    {
        foreach ([
            'https://github.com/durable-workflow/cli/releases/download/'.$version.'/',
            'https://github.com/durable-workflow/cli/releases/download/v'.$version.'/',
        ] as $prefix) {
            if (str_starts_with($source, $prefix) && substr($source, strlen($prefix)) !== '') {
                return true;
            }
        }

        return $source === 'github://durable-workflow/cli@'.$version
            || $source === 'github://durable-workflow/cli@v'.$version;
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

    private static function matchesComposerArtifactSource(string $packageName, string $version, string $source): bool
    {
        return $source === 'packagist://'.$packageName.'@'.$version
            || $source === 'composer://'.$packageName.':'.$version
            || $source === 'https://repo.packagist.org/p2/'.$packageName.'.json#'.$version
            || $source === 'https://packagist.org/packages/'.$packageName.'#'.$version;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     * @return list<array<string, mixed>>
     */
    private static function matrixFailures(array $result, array $contract): array
    {
        $matrix = self::arrayValue($result, 'runtime_matrix') ?? self::arrayValue($result, 'runtimeMatrix') ?? [];
        $requiredMatrix = self::arrayValue($contract, 'required_matrix') ?? [];
        $failures = [];

        foreach (['workflow-embedded', 'standalone'] as $mode) {
            if (! in_array($mode, self::stringList($matrix['execution_modes'] ?? []), true)) {
                $failures[] = [
                    'code' => 'missing_execution_mode',
                    'mode' => $mode,
                ];
            }
        }

        foreach (self::stringList($requiredMatrix['runtimes'] ?? ['workflow-php', 'sdk-python']) as $runtime) {
            if (! in_array($runtime, self::stringList($matrix['runtimes'] ?? []), true)) {
                $failures[] = [
                    'code' => 'missing_activity_runtime',
                    'runtime' => $runtime,
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
        $sections = [
            'published_artifact_install',
            'runtime_matrix',
            'durable_result_recording',
            'retry_backoff',
            'timeout_behavior',
            'heartbeat_timeout_renewal',
            'typed_failure_propagation',
            'heartbeat_cancellation',
            'idempotent_completion',
            'operator_visibility',
        ];
        $failures = [];

        foreach ($sections as $section) {
            $value = self::arrayValue($result, $section);
            if ($value === null || $value === []) {
                $failures[] = [
                    'code' => 'missing_required_section',
                    'section' => $section,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $scenarioResult
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private static function heartbeatTimeoutRenewalFailures(array $scenarioResult, array $result): array
    {
        $outputs = self::arrayField($scenarioResult, [
            'observed_outputs',
            'observedOutputs',
            'activity_evidence',
            'activityEvidence',
            'evidence',
        ]) ?? [];
        $failures = [];
        $versions = self::arrayField($result, ['published_artifact_versions', 'publishedArtifactVersions'])
            ?? self::arrayField($result, ['artifact_versions', 'artifactVersions'])
            ?? [];

        $workerArtifact = self::arrayField($outputs, ['php_sdk_worker_artifact', 'phpSdkWorkerArtifact']);
        foreach (self::sdkPhpWorkerArtifactFailures($workerArtifact, $versions) as $failure) {
            $failures[] = $failure + [
                'scenario_id' => 'heartbeat_timeout_renewal_across_enforcement_passes',
                'field' => 'observed_outputs.php_sdk_worker_artifact',
            ];
        }

        $timeoutSeconds = $outputs['heartbeat_timeout_seconds'] ?? null;
        $cadenceSeconds = $outputs['heartbeat_cadence_seconds'] ?? null;
        $durationSeconds = $outputs['in_flight_duration_seconds'] ?? null;
        $initialDeadline = self::timestampField($outputs, [
            'initial_heartbeat_deadline_at',
            'initialHeartbeatDeadlineAt',
        ]);
        if (! is_int($timeoutSeconds) || $timeoutSeconds <= 0 || $timeoutSeconds > 10) {
            $failures[] = [
                'code' => 'heartbeat_timeout_renewal_invalid_short_timeout',
                'actual' => $timeoutSeconds,
            ];
        }
        if (! is_numeric($cadenceSeconds)
            || (float) $cadenceSeconds <= 0
            || ! is_int($timeoutSeconds)
            || (float) $cadenceSeconds > $timeoutSeconds / 2) {
            $failures[] = [
                'code' => 'heartbeat_timeout_renewal_cadence_not_materially_faster',
                'heartbeat_timeout_seconds' => $timeoutSeconds,
                'heartbeat_cadence_seconds' => $cadenceSeconds,
            ];
        }
        if (! is_numeric($durationSeconds)
            || ! is_int($timeoutSeconds)
            || (float) $durationSeconds <= $timeoutSeconds) {
            $failures[] = [
                'code' => 'heartbeat_timeout_renewal_activity_not_in_flight_beyond_timeout',
                'heartbeat_timeout_seconds' => $timeoutSeconds,
                'in_flight_duration_seconds' => $durationSeconds,
            ];
        }
        if ($initialDeadline === null) {
            $failures[] = [
                'code' => 'heartbeat_timeout_renewal_initial_deadline_missing',
            ];
        }

        $acknowledgements = self::arrayField($outputs, [
            'heartbeat_acknowledgements',
            'heartbeatAcknowledgements',
        ]) ?? [];
        if (count($acknowledgements) < 4) {
            $failures[] = [
                'code' => 'heartbeat_timeout_renewal_insufficient_acknowledgements',
                'count' => count($acknowledgements),
            ];
        }
        foreach ($acknowledgements as $index => $acknowledgement) {
            if (! is_array($acknowledgement)) {
                $failures[] = [
                    'code' => 'heartbeat_timeout_renewal_invalid_acknowledgement',
                    'index' => $index,
                ];

                continue;
            }
            $response = self::arrayField($acknowledgement, ['response', 'acknowledgement']) ?? [];
            $requestStartedAt = self::timestampField($acknowledgement, ['request_started_at', 'requestStartedAt']);
            $responseReceivedAt = self::timestampField($acknowledgement, ['response_received_at', 'responseReceivedAt']);
            $lastHeartbeatAt = self::timestampField($acknowledgement, ['last_heartbeat_at', 'lastHeartbeatAt']);
            $previousDeadline = self::timestampField($acknowledgement, ['previous_deadline_at', 'previousDeadlineAt']);
            $authoritativeDeadline = self::timestampField($acknowledgement, [
                'authoritative_deadline_at',
                'authoritativeDeadlineAt',
            ]);
            if (($response['heartbeat_recorded'] ?? null) !== true
                || ($response['can_continue'] ?? null) !== true
                || ($acknowledgement['deadline_advanced'] ?? null) !== true
                || $requestStartedAt === null
                || $responseReceivedAt === null
                || $lastHeartbeatAt === null
                || $previousDeadline === null
                || $authoritativeDeadline === null
                || $responseReceivedAt < $requestStartedAt
                || $authoritativeDeadline <= $previousDeadline
                || $authoritativeDeadline <= $lastHeartbeatAt) {
                $failures[] = [
                    'code' => 'heartbeat_timeout_renewal_acknowledgement_did_not_advance_deadline',
                    'index' => $index,
                ];
            }
            if ($index === 0 && $initialDeadline !== null && $previousDeadline !== $initialDeadline) {
                $failures[] = [
                    'code' => 'heartbeat_timeout_renewal_initial_deadline_not_authoritative',
                ];
            }
            if ($index > 0) {
                $previousAcknowledgement = is_array($acknowledgements[$index - 1] ?? null)
                    ? $acknowledgements[$index - 1]
                    : [];
                $previousHeartbeatAt = self::timestampField($previousAcknowledgement, [
                    'last_heartbeat_at',
                    'lastHeartbeatAt',
                ]);
                $previousAuthoritativeDeadline = self::timestampField($previousAcknowledgement, [
                    'authoritative_deadline_at',
                    'authoritativeDeadlineAt',
                ]);
                if ($previousHeartbeatAt === null
                    || $lastHeartbeatAt === null
                    || ! is_int($timeoutSeconds)
                    || $lastHeartbeatAt <= $previousHeartbeatAt
                    || $lastHeartbeatAt - $previousHeartbeatAt > $timeoutSeconds / 2
                    || $previousDeadline !== $previousAuthoritativeDeadline) {
                    $failures[] = [
                        'code' => 'heartbeat_timeout_renewal_observed_cadence_invalid',
                        'index' => $index,
                    ];
                }
            }
        }

        $enforcementPasses = self::arrayField($outputs, ['enforcement_passes', 'enforcementPasses']) ?? [];
        if (count($enforcementPasses) < 3) {
            $failures[] = [
                'code' => 'heartbeat_timeout_renewal_insufficient_enforcement_passes',
                'count' => count($enforcementPasses),
            ];
        }
        foreach ($enforcementPasses as $index => $pass) {
            $response = is_array($pass)
                ? (self::arrayField($pass, ['response', 'enforce_response', 'enforceResponse']) ?? [])
                : [];
            $observedAt = is_array($pass)
                ? self::timestampField($pass, ['observed_at', 'observedAt'])
                : null;
            $finishedAt = is_array($pass)
                ? self::timestampField($pass, ['finished_at', 'finishedAt'])
                : null;
            $authoritativeDeadline = is_array($pass)
                ? self::timestampField($pass, ['authoritative_deadline_at', 'authoritativeDeadlineAt'])
                : null;
            $results = is_array($response['results'] ?? null) ? $response['results'] : [];
            $firstResult = is_array($results[0] ?? null) ? $results[0] : [];
            if (($response['processed'] ?? null) !== 1
                || ($response['enforced'] ?? null) !== 0
                || ($response['failed'] ?? null) !== 0
                || ($response['skipped'] ?? null) !== 1
                || ($firstResult['outcome'] ?? null) !== 'skipped'
                || ($firstResult['reason'] ?? null) !== 'no_deadline_expired'
                || ($pass['activity_timed_out_history_count'] ?? null) !== 0
                || $observedAt === null
                || $finishedAt === null
                || $authoritativeDeadline === null
                || $finishedAt < $observedAt
                || $finishedAt >= $authoritativeDeadline) {
                $failures[] = [
                    'code' => 'heartbeat_timeout_renewal_enforcement_pass_did_not_honor_renewal',
                    'index' => $index,
                ];
            }
        }

        $completion = self::arrayField($outputs, ['completion_response', 'completionResponse']) ?? [];
        $terminalHistory = self::arrayField($outputs, ['terminal_history', 'terminalHistory']) ?? [];
        if (($completion['recorded'] ?? null) !== true
            || ($terminalHistory['activity_completed_count'] ?? null) !== 1
            || ($terminalHistory['activity_timed_out_count'] ?? null) !== 0
            || ($terminalHistory['activity_heartbeat_recorded_count'] ?? null) !== count($acknowledgements)
            || ($terminalHistory['completed_exactly_once'] ?? null) !== true
            || ($terminalHistory['history_without_contradiction'] ?? null) !== true) {
            $failures[] = [
                'code' => 'heartbeat_timeout_renewal_terminal_history_is_contradictory',
            ];
        }

        $negative = self::arrayField($outputs, ['negative_control', 'negativeControl']) ?? [];
        $typedTimeout = self::arrayField($negative, ['typed_timeout_payload', 'typedTimeoutPayload']) ?? [];
        $negativeEnforcement = self::arrayField($negative, ['enforcement_pass', 'enforcementPass']) ?? [];
        $lateHeartbeat = self::arrayField($negative, ['late_heartbeat_response', 'lateHeartbeatResponse']) ?? [];
        $lateCompletion = self::arrayField($negative, ['late_completion_conflict', 'lateCompletionConflict']) ?? [];
        $lateFailure = self::arrayField($negative, ['late_failure_conflict', 'lateFailureConflict']) ?? [];
        $negativeHistory = self::arrayField($negative, ['terminal_history', 'terminalHistory']) ?? [];
        $negativeDeadline = self::timestampField($negative, [
            'initial_heartbeat_deadline_at',
            'initialHeartbeatDeadlineAt',
        ]);
        $negativeEnforcedAt = self::timestampField($negative, ['enforcement_observed_at', 'enforcementObservedAt']);
        if (($negativeEnforcement['enforced'] ?? null) !== 1
            || ($typedTimeout['timeout_kind'] ?? null) !== 'heartbeat'
            || ($typedTimeout['failure_category'] ?? null) !== 'timeout'
            || ($lateHeartbeat['heartbeat_recorded'] ?? null) !== false
            || ($lateHeartbeat['can_continue'] ?? null) !== false
            || ($lateHeartbeat['reason'] ?? null) !== 'attempt_closed'
            || ($lateCompletion['http_status'] ?? null) !== 409
            || ($lateCompletion['reason'] ?? null) !== 'stale_attempt'
            || ($lateCompletion['recorded'] ?? null) !== false
            || ($lateFailure['http_status'] ?? null) !== 409
            || ($lateFailure['reason'] ?? null) !== 'stale_attempt'
            || ($lateFailure['recorded'] ?? null) !== false
            || ($negativeHistory['activity_timed_out_count'] ?? null) !== 1
            || ($negativeHistory['activity_completed_count'] ?? null) !== 0
            || ($negativeHistory['activity_failed_count'] ?? null) !== 0
            || $negativeDeadline === null
            || $negativeEnforcedAt === null
            || $negativeEnforcedAt <= $negativeDeadline) {
            $failures[] = [
                'code' => 'heartbeat_timeout_renewal_negative_control_invalid',
            ];
        }

        $cleanup = self::arrayField($outputs, ['isolated_cleanup', 'isolatedCleanup']) ?? [];
        if (($cleanup['isolated_database'] ?? null) !== true
            || ($cleanup['scratch_removed_on_exit'] ?? null) !== true
            || ($cleanup['published_server_container_removed'] ?? null) !== true) {
            $failures[] = [
                'code' => 'heartbeat_timeout_renewal_isolated_cleanup_missing',
            ];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>|null  $artifact
     * @param  array<string, mixed>  $versions
     * @return list<array<string, mixed>>
     */
    private static function sdkPhpWorkerArtifactFailures(?array $artifact, array $versions): array
    {
        if ($artifact === null || $artifact === []) {
            return [[
                'code' => 'sdk_php_activity_worker_artifact_missing',
            ]];
        }

        $expectedVersion = self::artifactVersionForInstallChannel($versions, 'sdk-php');
        $artifactName = self::stringField($artifact, ['artifact', 'name']);
        $package = self::stringField($artifact, ['package', 'package_name', 'packageName']);
        $version = self::stringField($artifact, ['version', 'package_version', 'packageVersion']);
        $source = self::stringField($artifact, ['source', 'artifact_source', 'artifactSource']);
        $status = strtolower(self::stringField($artifact, ['status', 'outcome']));
        $runtime = strtolower(self::stringField($artifact, ['runtime', 'language']));
        $executionSource = self::stringField($artifact, ['execution_source', 'executionSource']);
        $failures = [];

        if ($artifactName !== 'sdk-php' || $package !== 'durable-workflow/sdk') {
            $failures[] = ['code' => 'sdk_php_activity_worker_artifact_invalid_package'];
        }
        if ($version === '' || $version !== $expectedVersion || ! self::isExactVersion($version)) {
            $failures[] = [
                'code' => 'sdk_php_activity_worker_artifact_invalid_version',
                'version' => $version,
                'expected' => $expectedVersion,
            ];
        }
        if ($source === ''
            || self::artifactSourceIsForbidden($source)
            || ! self::matchesComposerArtifactSource('durable-workflow/sdk', $version, $source)) {
            $failures[] = ['code' => 'sdk_php_activity_worker_artifact_unrecognized_source'];
        }
        if ($status !== 'pass'
            || ! str_contains($runtime, 'php')
            || $executionSource !== self::PUBLISHED_SERVER_CONTAINER_EXECUTION_SOURCE
            || ! self::explicitFalseField($artifact, ['local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed'])
            || self::containsLocalSourceSignal($artifact)) {
            $failures[] = ['code' => 'sdk_php_activity_worker_artifact_execution_invalid'];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $scenarioResult
     * @return list<array<string, mixed>>
     */
    private static function activityHostEvidenceFailures(string $scenarioId, array $scenarioResult, array $result): array
    {
        $requiredMode = self::FOCUSED_ACTIVITY_HOST_SCENARIO_MODES[$scenarioId] ?? null;
        if ($requiredMode === null) {
            return [];
        }

        $outputs = self::arrayField($scenarioResult, [
            'observed_outputs',
            'observedOutputs',
            'activity_evidence',
            'activityEvidence',
            'evidence',
        ]) ?? [];
        $scenarioEvidence = self::arrayField($scenarioResult, [
            'scenario_evidence',
            'scenarioEvidence',
        ]) ?? [];
        $hostEvidence = self::activityHostEvidenceFrom($outputs)
            ?? self::activityHostEvidenceFrom($scenarioEvidence);

        if ($hostEvidence === null || $hostEvidence === []) {
            return [[
                'code' => 'activity_host_evidence_missing',
                'scenario_id' => $scenarioId,
                'field' => 'activity_host_evidence',
                'expected' => 'published_server_container_activity_cells',
                'actual' => $hostEvidence,
            ]];
        }

        $failures = [];
        $executionSource = self::stringField($hostEvidence, ['execution_source', 'executionSource']);
        if ($executionSource !== self::PUBLISHED_SERVER_CONTAINER_EXECUTION_SOURCE) {
            $failures[] = [
                'code' => 'activity_host_evidence_not_from_published_server_container',
                'scenario_id' => $scenarioId,
                'field' => 'activity_host_evidence.execution_source',
                'expected' => self::PUBLISHED_SERVER_CONTAINER_EXECUTION_SOURCE,
                'actual' => $executionSource,
            ];
        }

        if (self::containsLocalSourceSignal($hostEvidence)
            || self::truthyField($hostEvidence, ['local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed'])
            || self::truthyField($outputs, ['local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed'])
            || self::truthyField($scenarioEvidence, ['local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed'])) {
            $failures[] = [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'scenario_id' => $scenarioId,
                'field' => 'activity_host_evidence',
                'value' => 'local source checkout probe signal',
            ];
        }

        if (! self::explicitFalseField($hostEvidence, ['local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed'])) {
            $failures[] = [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'scenario_id' => $scenarioId,
                'field' => 'activity_host_evidence.local_product_source_checkouts_used',
                'value' => $hostEvidence['local_product_source_checkouts_used']
                    ?? $hostEvidence['localProductSourceCheckoutsUsed']
                    ?? null,
            ];
        }

        $activityCells = self::activityHostCells($hostEvidence);
        $versions = self::arrayField($result, ['published_artifact_versions', 'publishedArtifactVersions'])
            ?? self::arrayField($result, ['artifact_versions', 'artifactVersions'])
            ?? [];

        foreach (['workflow-php', 'sdk-python'] as $runtime) {
            $matchingCell = false;
            foreach ($activityCells as $cell) {
                $status = strtolower(self::stringField($cell, ['status', 'outcome', 'result']));
                if (self::stringField($cell, ['mode']) === $requiredMode
                    && self::stringField($cell, ['runtime']) === $runtime
                    && $status === 'pass'
                    && self::stringField($cell, ['execution_source', 'executionSource']) === self::PUBLISHED_SERVER_CONTAINER_EXECUTION_SOURCE
                    && ! self::containsLocalSourceSignal($cell)
                    && ! self::truthyField($cell, ['local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed'])
                    && (
                        $runtime !== 'sdk-python'
                        || self::sdkPythonActivityCellArtifactFailures($cell, $versions) === []
                    )) {
                    $matchingCell = true;
                    break;
                }
            }

            if (! $matchingCell) {
                $failures[] = [
                    'code' => 'activity_host_evidence_missing_activity_cell',
                    'scenario_id' => $scenarioId,
                    'field' => 'activity_host_evidence.activity_cells',
                    'mode' => $requiredMode,
                    'runtime' => $runtime,
                    'expected' => 'passing published_server_container activity host cell',
                ];
            }
        }
        foreach ($activityCells as $index => $cell) {
            $status = strtolower(self::stringField($cell, ['status', 'outcome', 'result']));
            if (self::stringField($cell, ['mode']) !== $requiredMode
                || self::stringField($cell, ['runtime']) !== 'sdk-python'
                || $status !== 'pass') {
                continue;
            }

            foreach (self::sdkPythonActivityCellArtifactFailures($cell, $versions) as $failure) {
                $failures[] = $failure + [
                    'scenario_id' => $scenarioId,
                    'field' => sprintf('activity_host_evidence.activity_cells.%d.worker_artifact', $index),
                ];
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $cell
     * @param  array<string, mixed>  $versions
     * @return list<array<string, mixed>>
     */
    private static function sdkPythonActivityCellArtifactFailures(array $cell, array $versions): array
    {
        $artifact = self::arrayField($cell, [
            'worker_artifact',
            'workerArtifact',
            'sdk_python_worker_artifact',
            'sdkPythonWorkerArtifact',
            'published_artifact_worker_execution',
            'publishedArtifactWorkerExecution',
        ]);
        if ($artifact === null || $artifact === []) {
            return [[
                'code' => 'sdk_python_activity_worker_artifact_missing',
                'expected' => 'published sdk-python package worker_artifact evidence',
            ]];
        }

        $failures = [];
        $expectedVersion = self::artifactVersionForInstallChannel($versions, 'sdk-python');
        $artifactName = self::stringField($artifact, ['artifact', 'name', 'package_artifact', 'packageArtifact']);
        $version = self::stringField($artifact, [
            'version',
            'package_version',
            'packageVersion',
            'sdk_version',
            'sdkVersion',
        ]);
        $source = self::stringField($artifact, [
            'source',
            'install_source',
            'installSource',
            'artifact_source',
            'artifactSource',
            'resolved_source',
            'resolvedSource',
        ]);
        $status = strtolower(self::stringField($artifact, ['status', 'result', 'outcome']));
        $executionSource = self::stringField($artifact, ['execution_source', 'executionSource']);
        $runtime = strtolower(implode(' ', array_filter([
            self::stringField($artifact, ['runtime']),
            self::stringField($artifact, ['language']),
            self::stringField($artifact, ['worker_runtime', 'workerRuntime']),
            self::stringField($artifact, ['sdk_runtime', 'sdkRuntime']),
        ])));

        if ($artifactName !== 'sdk-python') {
            $failures[] = [
                'code' => 'sdk_python_activity_worker_artifact_invalid_artifact',
                'artifact' => $artifactName,
                'expected' => 'sdk-python',
            ];
        }
        if ($status !== 'pass') {
            $failures[] = [
                'code' => 'sdk_python_activity_worker_artifact_not_pass',
                'status' => $status,
            ];
        }
        if ($version === '' || $version !== $expectedVersion || ! self::isExactVersion($version)) {
            $failures[] = [
                'code' => 'sdk_python_activity_worker_artifact_invalid_version',
                'version' => $version,
                'expected' => $expectedVersion,
            ];
        }
        if ($source === ''
            || self::artifactSourceIsForbidden($source)
            || ! self::matchesPythonArtifactSource($version, $source)) {
            $failures[] = [
                'code' => 'sdk_python_activity_worker_artifact_unrecognized_source',
                'source' => $source,
                'expected' => $expectedVersion === '' ? 'published PyPI artifact' : 'pypi://durable-workflow=='.$expectedVersion,
            ];
        }
        if ($executionSource !== self::PUBLISHED_SERVER_CONTAINER_EXECUTION_SOURCE) {
            $failures[] = [
                'code' => 'sdk_python_activity_worker_artifact_not_from_published_server_container',
                'execution_source' => $executionSource,
                'expected' => self::PUBLISHED_SERVER_CONTAINER_EXECUTION_SOURCE,
            ];
        }
        if (! str_contains($runtime, 'python')) {
            $failures[] = [
                'code' => 'sdk_python_activity_worker_artifact_not_python_runtime',
                'runtime' => $runtime,
            ];
        }
        if (self::containsLocalSourceSignal($artifact)
            || self::truthyField($artifact, ['local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed'])) {
            $failures[] = [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'value' => 'local source checkout probe signal',
            ];
        }
        if (! self::explicitFalseField($artifact, ['local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed'])) {
            $failures[] = [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'field' => 'activity_host_evidence.activity_cells.worker_artifact.local_product_source_checkouts_used',
                'value' => $artifact['local_product_source_checkouts_used']
                    ?? $artifact['localProductSourceCheckoutsUsed']
                    ?? null,
            ];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>|null
     */
    private static function activityHostEvidenceFrom(array $source): ?array
    {
        return self::arrayField($source, [
            'activity_host_evidence',
            'activityHostEvidence',
            'published_artifact_activity_host_evidence',
            'publishedArtifactActivityHostEvidence',
        ]);
    }

    /**
     * @param  array<string, mixed>  $hostEvidence
     * @return list<array<string, mixed>>
     */
    private static function activityHostCells(array $hostEvidence): array
    {
        $cells = self::arrayField($hostEvidence, ['activity_cells', 'activityCells']) ?? [];

        return array_values(array_filter(
            $cells,
            static fn (mixed $cell): bool => is_array($cell),
        ));
    }

    /**
     * @param  array<string, mixed>  $scenarioResult
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private static function publishedServerExecutionFailures(string $scenarioId, array $scenarioResult, array $result): array
    {
        $outputs = self::arrayField($scenarioResult, [
            'observed_outputs',
            'observedOutputs',
            'activity_evidence',
            'activityEvidence',
            'evidence',
        ]) ?? [];
        $scenarioEvidence = self::arrayField($scenarioResult, [
            'scenario_evidence',
            'scenarioEvidence',
        ]) ?? [];
        $execution = self::arrayField($outputs, self::publishedExecutionAliases())
            ?? self::arrayField($scenarioEvidence, self::publishedExecutionAliases())
            ?? self::arrayField($result, self::publishedExecutionAliases());

        if ($execution === null || $execution === []) {
            return [[
                'code' => 'published_artifact_worker_execution_missing',
                'scenario_id' => $scenarioId,
                'field' => 'published_artifact_worker_execution',
                'expected' => 'object_with_server_artifact_execution',
                'actual' => $execution,
            ]];
        }

        $failures = [];
        if (self::containsLocalSourceSignal($execution)
            || self::truthyField($outputs, ['local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed'])
            || self::truthyField($scenarioEvidence, ['local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed'])) {
            $failures[] = [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'scenario_id' => $scenarioId,
                'field' => 'published_artifact_worker_execution',
                'value' => 'local source checkout probe signal',
            ];
        }

        if (! self::explicitFalseField($execution, ['local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed'])) {
            $failures[] = [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'scenario_id' => $scenarioId,
                'field' => 'published_artifact_worker_execution.local_product_source_checkouts_used',
                'value' => $execution['local_product_source_checkouts_used']
                    ?? $execution['localProductSourceCheckoutsUsed']
                    ?? null,
            ];
        }

        if (! self::sourceIntegrityStatementPresent($execution)) {
            $failures[] = [
                'code' => 'missing_published_artifact_worker_execution_source_integrity_statement',
                'scenario_id' => $scenarioId,
                'field' => 'published_artifact_worker_execution.source_integrity_statement',
                'expected' => 'statement that local product checkouts, branch source, and local vendor trees were not used as pass evidence',
            ];
        }

        if (! self::executionClaimsContainer($execution)) {
            $failures[] = [
                'code' => 'published_artifact_worker_execution_not_containerized',
                'scenario_id' => $scenarioId,
                'field' => 'published_artifact_worker_execution.execution_environment',
                'expected' => 'pinned server container execution',
            ];
        }

        $versions = self::arrayField($result, ['published_artifact_versions', 'publishedArtifactVersions'])
            ?? self::arrayField($result, ['artifact_versions', 'artifactVersions'])
            ?? [];
        $sources = self::arrayField($result, ['artifact_sources', 'artifactSources']) ?? [];
        $serverVersion = self::artifactVersionForInstallChannel($versions, 'server');
        $pinnedServerSource = self::stringValue(self::artifactSource($sources, 'server'));
        $serverEntries = array_values(array_filter(
            self::publishedExecutionEntries($execution),
            static fn (array $entry): bool => self::canonicalExecutionArtifact(
                self::stringField($entry, ['artifact', 'name', 'id']) ?: 'server',
            ) === 'server',
        ));

        if ($serverEntries === []) {
            $failures[] = [
                'code' => 'missing_required_published_worker_execution_artifact',
                'scenario_id' => $scenarioId,
                'artifact' => 'server',
                'field' => 'published_artifact_worker_execution.artifacts',
            ];

            return $failures;
        }

        $validServerEntry = false;
        foreach ($serverEntries as $index => $entry) {
            $status = strtolower(self::stringField($entry, ['status', 'result', 'outcome']));
            $source = self::stringField($entry, [
                'source',
                'install_source',
                'installSource',
                'artifact_source',
                'artifactSource',
                'server_image',
                'serverImage',
                'image',
                'dw_server_image',
                'dwServerImage',
            ]);
            $version = self::stringField($entry, [
                'version',
                'artifact_version',
                'artifactVersion',
                'server_version',
                'serverVersion',
            ]);
            $fieldPrefix = sprintf('published_artifact_worker_execution.artifacts.%d', $index);

            if ($status !== 'pass') {
                $failures[] = [
                    'code' => 'published_artifact_worker_execution_not_pass',
                    'scenario_id' => $scenarioId,
                    'artifact' => 'server',
                    'status' => $status,
                    'field' => $fieldPrefix.'.status',
                ];
            }

            if ($version === '') {
                $failures[] = [
                    'code' => 'missing_published_artifact_worker_execution_version',
                    'scenario_id' => $scenarioId,
                    'artifact' => 'server',
                    'field' => $fieldPrefix.'.version',
                ];
            } elseif ($version !== $serverVersion || ! self::isExactVersion($version)) {
                $failures[] = [
                    'code' => 'invalid_published_artifact_worker_execution_version',
                    'scenario_id' => $scenarioId,
                    'artifact' => 'server',
                    'version' => $version,
                    'expected' => $serverVersion,
                    'field' => $fieldPrefix.'.version',
                ];
            }

            if ($source === '') {
                $failures[] = [
                    'code' => 'missing_published_artifact_worker_execution_source',
                    'scenario_id' => $scenarioId,
                    'artifact' => 'server',
                    'field' => $fieldPrefix.'.source',
                ];
            } elseif (self::artifactSourceIsForbidden($source)) {
                $failures[] = [
                    'code' => 'forbidden_published_artifact_worker_execution_source',
                    'scenario_id' => $scenarioId,
                    'artifact' => 'server',
                    'source' => $source,
                    'field' => $fieldPrefix.'.source',
                ];
            } elseif (! self::sourceMatchesPinnedServerArtifact($source, $serverVersion, $pinnedServerSource)) {
                $failures[] = [
                    'code' => 'unrecognized_published_artifact_worker_execution_source',
                    'scenario_id' => $scenarioId,
                    'artifact' => 'server',
                    'source' => $source,
                    'expected' => $pinnedServerSource,
                    'field' => $fieldPrefix.'.source',
                ];
            }

            if (self::truthyField($entry, ['local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed'])) {
                $failures[] = [
                    'code' => 'local_product_source_checkouts_used_must_be_false',
                    'scenario_id' => $scenarioId,
                    'artifact' => 'server',
                    'field' => $fieldPrefix.'.local_product_source_checkouts_used',
                    'value' => $entry['local_product_source_checkouts_used']
                        ?? $entry['localProductSourceCheckoutsUsed']
                        ?? null,
                ];
            }

            if ($status === 'pass'
                && $version === $serverVersion
                && self::isExactVersion($version)
                && $source !== ''
                && ! self::artifactSourceIsForbidden($source)
                && self::sourceMatchesPinnedServerArtifact($source, $serverVersion, $pinnedServerSource)
                && ! self::truthyField($entry, ['local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed'])) {
                $validServerEntry = true;
            }
        }

        if (! $validServerEntry) {
            $failures[] = [
                'code' => 'missing_required_published_worker_execution_artifact',
                'scenario_id' => $scenarioId,
                'artifact' => 'server',
                'field' => 'published_artifact_worker_execution.artifacts',
            ];
        }

        return $failures;
    }

    /**
     * @return list<string>
     */
    private static function publishedExecutionAliases(): array
    {
        return [
            'published_artifact_worker_execution',
            'publishedArtifactWorkerExecution',
            'published_server_artifact_execution',
            'publishedServerArtifactExecution',
            'published_artifact_execution',
            'publishedArtifactExecution',
            'published_server_image_activity_runtime_probe',
            'publishedServerImageActivityRuntimeProbe',
            'activity_runtime_probe',
            'activityRuntimeProbe',
        ];
    }

    private static function executionClaimsContainer(array $execution): bool
    {
        if (self::truthyField($execution, [
            'executed_in_pinned_server_artifact',
            'executedInPinnedServerArtifact',
            'executed_in_container',
            'executedInContainer',
            'containerized',
        ])) {
            return true;
        }

        $mode = strtolower(implode(' ', array_filter([
            self::stringField($execution, ['execution_environment', 'executionEnvironment']),
            self::stringField($execution, ['runtime_environment', 'runtimeEnvironment']),
            self::stringField($execution, ['worker_execution_mode', 'workerExecutionMode']),
        ])));

        return str_contains($mode, 'container')
            || str_contains($mode, 'docker')
            || self::stringField($execution, ['container_id', 'containerId']) !== '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function publishedExecutionEntries(array $execution): array
    {
        $entries = self::arrayField($execution, ['artifacts', 'workers', 'executions']);
        if ($entries !== null && $entries !== []) {
            return array_values(array_filter(
                $entries,
                static fn (mixed $entry): bool => is_array($entry),
            ));
        }

        foreach (['artifact', 'name', 'source', 'server_image', 'serverImage', 'image'] as $field) {
            if (array_key_exists($field, $execution)) {
                return [$execution];
            }
        }

        return [];
    }

    private static function canonicalExecutionArtifact(string $artifact): string
    {
        $normalized = strtolower(str_replace(['_', ' '], '-', trim($artifact)));

        return match ($normalized) {
            'durableworkflow/server', 'durable-workflow/server' => 'server',
            default => $normalized,
        };
    }

    private static function sourceMatchesPinnedServerArtifact(string $source, string $serverVersion, string $pinnedServerSource): bool
    {
        if ($serverVersion === '' || $pinnedServerSource === '') {
            return false;
        }

        $normalizedSource = self::normalizeDockerImage($source);
        $normalizedPinnedSource = self::normalizeDockerImage($pinnedServerSource);
        if ($normalizedSource === $normalizedPinnedSource) {
            return true;
        }

        if (str_contains($normalizedPinnedSource, '@sha256:')) {
            return false;
        }

        return self::matchesServerArtifactSource($serverVersion, $source);
    }

    private static function normalizeDockerImage(string $source): string
    {
        return strtolower((string) preg_replace('/^docker:\/\//i', '', trim($source)));
    }

    private static function containsLocalSourceSignal(mixed $value, int $depth = 0): bool
    {
        if ($depth > 8 || $value === null) {
            return false;
        }

        if (is_string($value)) {
            $normalized = strtolower(str_replace('\\', '/', $value));

            return str_contains($normalized, '/workspace/repos/')
                || str_contains($normalized, 'repo_root')
                || str_contains($normalized, '$repo_root')
                || str_contains($normalized, '${repo_root}')
                || str_contains($normalized, 'workspace_repo_as_artifact_under_test')
                || str_contains($normalized, 'local_product_source_checkout')
                || str_contains($normalized, 'local_checkout')
                || str_contains($normalized, 'local_source_checkout')
                || str_contains($normalized, 'source_checkout');
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (self::containsLocalSourceSignal($item, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    private static function sourceIntegrityStatementPresent(array $execution): bool
    {
        $statement = strtolower(self::stringField($execution, [
            'source_integrity_statement',
            'sourceIntegrityStatement',
            'no_local_source_statement',
            'noLocalSourceStatement',
        ]));

        return str_contains($statement, 'local product checkout')
            && str_contains($statement, 'branch source')
            && str_contains($statement, 'local vendor');
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private static function declaredOutcomeStatusFailures(array $result, string $evaluatedStatus): array
    {
        $declared = self::stringValue(
            $result['outcome']
            ?? $result['status']
            ?? $result['verdict']
            ?? null,
        );

        if ($declared === '') {
            return [[
                'code' => 'missing_declared_outcome',
            ]];
        }

        $declaredStatus = in_array($declared, ['pass', 'passed', 'success'], true)
            ? 'pass'
            : 'non_passing';

        if ($declaredStatus !== $evaluatedStatus) {
            return [[
                'code' => 'declared_outcome_mismatch',
                'declared_outcome' => $declared,
                'evaluated_status' => $evaluatedStatus,
            ]];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            $text = self::stringValue($item);
            if ($text !== '') {
                $result[] = $text;
            }
        }

        return $result;
    }

    private static function stringValue(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return trim((string) $value);
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $array
     * @param  list<string>  $keys
     */
    private static function stringField(array $array, array $keys): string
    {
        foreach ($keys as $key) {
            $value = self::stringValue($array[$key] ?? null);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $array
     * @param  list<string>  $keys
     */
    private static function timestampField(array $array, array $keys): ?float
    {
        $value = self::stringField($array, $keys);
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/', $value) !== 1) {
            return null;
        }

        try {
            return (float) (new \DateTimeImmutable($value))->format('U.u');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>|null
     */
    private static function arrayValue(array $array, string $key): ?array
    {
        $value = $array[$key] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $array
     * @param  list<string>  $keys
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

    private static function truthy(mixed $value): bool
    {
        if ($value === true || $value === 1) {
            return true;
        }

        return is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param  array<string, mixed>  $array
     * @param  list<string>  $keys
     */
    private static function truthyField(array $array, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $array) && self::truthy($array[$key])) {
                return true;
            }
        }

        return false;
    }

    private static function explicitFalse(mixed $value): bool
    {
        if ($value === false || $value === 0) {
            return true;
        }

        return is_string($value) && in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off'], true);
    }

    /**
     * @param  array<string, mixed>  $array
     * @param  list<string>  $keys
     */
    private static function explicitFalseField(array $array, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $array) && self::explicitFalse($array[$key])) {
                return true;
            }
        }

        return false;
    }
}
