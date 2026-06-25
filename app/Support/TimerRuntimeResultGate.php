<?php

namespace App\Support;

/**
 * Evaluates timer/sleep conformance results against TimerRuntimeContract.
 */
final class TimerRuntimeResultGate
{
    public const SCHEMA = 'durable-workflow.v2.timer-runtime.result-gate';

    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'evaluates_result_schema' => TimerRuntimeContract::RESULT_SCHEMA,
            'scenario_statuses_source' => 'timer_runtime_contract.scenario_statuses',
            'required_scenarios_source' => 'timer_runtime_contract.required_scenarios',
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
            'declared_outcomes_source' => 'timer_runtime_contract.coverage_gate.*_outcome plus scenario non-pass statuses',
            'non_pass_statuses' => [
                'fail',
                'unsupported',
                'not_covered',
                'runner_blocked',
            ],
            'pass_requires' => [
                'every_required_scenario_has_one_result',
                'every_result_uses_a_published_status',
                'each_pass_scenario_has_observed_outputs',
                'each_pass_scenario_reports_required_evidence',
                'each_non_pass_scenario_has_linked_findings',
                'coverage_gap_scenario_findings_are_top_level_and_linked',
                'run_timestamps_outcome_runner_blocked_and_finding_links_are_recorded',
                'overall_outcome_matches_gate_status',
                'published_artifact_versions_are_recorded_and_pinned',
                'no_local_product_source_artifacts_are_reported',
                'normal_sleep_completion_completes_at_or_after_wake_up',
                'replay_after_timer_fire_starts_at_or_after_fire',
                'replay_after_timer_fire_replays_recorded_events',
                'replay_after_timer_fire_does_not_schedule_duplicate_timer_commands',
                'concurrent_timer_resume_order_matches_wake_up_times',
                'concurrent_timer_fires_are_not_early',
                'concurrent_timer_fires_are_not_duplicated',
                'concurrent_timer_fire_counts_cover_declared_timer_ids',
                'concurrent_timer_fire_counts_are_exactly_one',
                'cancellation_occurs_before_recorded_wake_up',
                'cancelled_timer_does_not_fire_after_cancel',
                'cancellation_terminal_status_is_documented',
                'operator_waiting_state_uses_explicit_waiting_status',
                'operator_waiting_state_uses_recognized_public_surface',
                'worker_restart_occurs_before_recorded_wake_up',
                'worker_restart_completion_occurs_at_or_after_wake_up',
                'worker_restart_timer_fires_exactly_once',
                'worker_restart_duplicate_resume_count_is_zero',
                'server_restart_occurs_before_recorded_wake_up',
                'server_restart_completion_occurs_at_or_after_wake_up',
                'server_restart_timer_state_recovered',
                'server_restart_timer_fires_exactly_once',
                'server_restart_duplicate_resume_count_is_zero',
            ],
            'semantic_evidence_policy' => [
                'normal_sleep_completion' => [
                    'sleep_requested_at',
                    'wake_up_at',
                    'completed_at',
                    'completed_at_must_be_greater_than_or_equal_to_wake_up_at',
                    'early_resume_observed_false_when_reported',
                ],
                'worker_restart_while_sleeping' => [
                    'sleep_started_at',
                    'worker_restart_window',
                    'wake_up_at',
                    'completed_at_must_be_greater_than_or_equal_to_wake_up_at',
                    'timer_fire_count_exactly_one',
                    'duplicate_resume_count_zero',
                ],
                'server_restart_while_sleeping' => [
                    'sleep_started_at',
                    'server_restart_window',
                    'wake_up_at',
                    'completed_at_must_be_greater_than_or_equal_to_wake_up_at',
                    'timer_state_recovered_true',
                    'timer_fire_count_exactly_one',
                    'duplicate_resume_count_zero',
                ],
                'replay_after_timer_fire' => [
                    'fired_at',
                    'replay_started_at_must_be_greater_than_or_equal_to_fired_at',
                    'replayed_event_ids_non_empty',
                    'replayed_event_types_include_timer_fired',
                    'duplicate_timer_commands_zero',
                ],
                'concurrent_timers_distinct_deadlines' => [
                    'wake_up_times',
                    'observed_resume_order',
                    'fired_at_times',
                    'fire_counts',
                ],
                'cancellation_while_waiting' => [
                    'cancellation_requested_at_before_wake_up_at',
                    'fired_after_cancel_false',
                    'workflow_status_in_allowed_terminal_states',
                ],
                'operator_visible_timer_waiting_state' => [
                    'explicit_waiting_or_timer_waiting_status',
                    'recognized_public_surface_cli_waterline_or_public_api',
                ],
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
        $contract ??= TimerRuntimeContract::manifest();

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

                array_push(
                    $failures,
                    ...self::requiredEvidenceFailures($scenarioId, $scenarioResult, $contract),
                );
                array_push(
                    $failures,
                    ...self::scenarioSemanticFailures($scenarioId, $scenarioResult, $contract),
                );
            } else {
                $nonPassScenarios[] = $scenarioId;
                array_push(
                    $failures,
                    ...self::nonPassFindingFailures($scenarioResult, $result, $scenarioId, $status),
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
        array_push($failures, ...self::sourcePolicyFailures($result, $contract));

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
        foreach (['observed_outputs', 'observedOutputs', 'timer_observations', 'timerObservations'] as $field) {
            $value = self::arrayField($scenarioResult, [$field]);
            if ($value !== null && $value !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $contract
     *
     * @return list<array<string, mixed>>
     */
    private static function requiredEvidenceFailures(
        string $scenarioId,
        array $scenarioResult,
        array $contract,
    ): array {
        $requiredEvidence = self::stringList($contract['scenario_requirements'][$scenarioId]['evidence'] ?? []);
        if ($requiredEvidence === []) {
            return [];
        }

        $evidence = self::scenarioEvidence($scenarioResult);
        $failures = [];
        foreach ($requiredEvidence as $field) {
            if (array_key_exists($field, $evidence) && self::hasEvidenceValue($evidence[$field])) {
                continue;
            }

            $failures[] = [
                'code' => 'missing_pass_required_evidence',
                'scenario_id' => $scenarioId,
                'field' => $field,
            ];
        }

        return $failures;
    }

    private static function hasEvidenceValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return true;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $result
     *
     * @return list<array<string, mixed>>
     */
    private static function nonPassFindingFailures(
        array $scenarioResult,
        array $result,
        string $scenarioId,
        string $status,
    ): array {
        $failures = [];
        $refs = self::linkedFindingRefs($scenarioResult);
        $topLevelFindingIds = self::topLevelFindingIds($result);
        $topLevelScenarioLinkIds = self::topLevelScenarioLinkIds($result, $scenarioId);
        $linkedFindingIds = $topLevelScenarioLinkIds;

        foreach ($refs as $ref) {
            $findingId = self::findingId($ref);
            if ($findingId === '') {
                continue;
            }

            $linkedFindingIds[] = $findingId;
        }

        $linkedFindingIds = array_values(array_unique($linkedFindingIds));

        if ($linkedFindingIds === []) {
            $failures[] = [
                'code' => 'missing_non_pass_finding',
                'scenario_id' => $scenarioId,
                'status' => $status,
            ];
        }

        foreach ($linkedFindingIds as $findingId) {
            if (! in_array($findingId, $topLevelFindingIds, true)) {
                $failures[] = [
                    'code' => 'missing_top_level_finding',
                    'scenario_id' => $scenarioId,
                    'finding_id' => $findingId,
                ];
            }

            if (! in_array($findingId, $topLevelScenarioLinkIds, true)) {
                $failures[] = [
                    'code' => 'missing_top_level_finding_link',
                    'scenario_id' => $scenarioId,
                    'finding_id' => $findingId,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $contract
     *
     * @return list<array<string, mixed>>
     */
    private static function scenarioSemanticFailures(
        string $scenarioId,
        array $scenarioResult,
        array $contract,
    ): array {
        return match ($scenarioId) {
            'normal_sleep_completion' => self::normalSleepCompletionFailures($scenarioResult),
            'worker_restart_while_sleeping' => self::workerRestartWhileSleepingFailures($scenarioResult),
            'server_restart_while_sleeping' => self::serverRestartWhileSleepingFailures($scenarioResult),
            'replay_after_timer_fire' => self::replayAfterTimerFireFailures($scenarioResult),
            'concurrent_timers_distinct_deadlines' => self::concurrentTimerFailures($scenarioResult),
            'cancellation_while_waiting' => self::cancellationWhileWaitingFailures($scenarioResult, $contract),
            'operator_visible_timer_waiting_state' => self::operatorVisibleWaitingFailures($scenarioResult, $contract),
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $scenarioResult
     *
     * @return list<array<string, mixed>>
     */
    private static function normalSleepCompletionFailures(array $scenarioResult): array
    {
        $evidence = self::scenarioEvidence($scenarioResult);
        $scenarioId = 'normal_sleep_completion';
        $sleepRequestedAt = self::stringField($evidence, [
            'sleep_requested_at',
            'sleepRequestedAt',
            'timer_scheduled_at',
            'timerScheduledAt',
        ]);
        $wakeUpAt = self::stringField($evidence, [
            'wake_up_at',
            'wakeUpAt',
            'wake_up_time',
            'wakeUpTime',
            'recorded_wake_up_at',
            'recordedWakeUpAt',
            'timer_wake_up_at',
            'timerWakeUpAt',
        ]);
        $completedAt = self::stringField($evidence, [
            'completed_at',
            'completedAt',
            'workflow_completed_at',
            'workflowCompletedAt',
            'run_closed_at',
            'runClosedAt',
        ]);
        $earlyResumeObserved = self::fieldValue($evidence, [
            'early_resume_observed',
            'earlyResumeObserved',
            'resumed_before_wake_up',
            'resumedBeforeWakeUp',
        ]);

        $failures = [];
        $sleepRequestedEpoch = self::timestampEpoch($sleepRequestedAt);
        $wakeEpoch = self::timestampEpoch($wakeUpAt);
        $completedEpoch = self::timestampEpoch($completedAt);

        if ($sleepRequestedEpoch === null) {
            $failures[] = [
                'code' => 'missing_or_invalid_sleep_requested_time',
                'scenario_id' => $scenarioId,
                'sleep_requested_at' => $sleepRequestedAt,
            ];
        }

        if ($wakeEpoch === null) {
            $failures[] = [
                'code' => 'missing_or_invalid_wake_up_time',
                'scenario_id' => $scenarioId,
                'wake_up_at' => $wakeUpAt,
            ];
        }

        if ($completedEpoch === null) {
            $failures[] = [
                'code' => 'missing_or_invalid_completed_time',
                'scenario_id' => $scenarioId,
                'completed_at' => $completedAt,
            ];
        }

        if ($sleepRequestedEpoch !== null && $wakeEpoch !== null && $wakeEpoch < $sleepRequestedEpoch) {
            $failures[] = [
                'code' => 'wake_up_before_sleep_requested',
                'scenario_id' => $scenarioId,
                'sleep_requested_at' => $sleepRequestedAt,
                'wake_up_at' => $wakeUpAt,
            ];
        }

        if ($wakeEpoch !== null && $completedEpoch !== null && $completedEpoch < $wakeEpoch) {
            $failures[] = [
                'code' => 'normal_sleep_completed_before_wake_up',
                'scenario_id' => $scenarioId,
                'wake_up_at' => $wakeUpAt,
                'completed_at' => $completedAt,
            ];
        }

        if ($earlyResumeObserved === true) {
            $failures[] = [
                'code' => 'normal_sleep_early_resume_observed',
                'scenario_id' => $scenarioId,
                'early_resume_observed' => true,
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     *
     * @return list<array<string, mixed>>
     */
    private static function replayAfterTimerFireFailures(array $scenarioResult): array
    {
        $evidence = self::scenarioEvidence($scenarioResult);
        $scenarioId = 'replay_after_timer_fire';
        $firedAt = self::stringField($evidence, [
            'fired_at',
            'firedAt',
            'timer_fired_at',
            'timerFiredAt',
        ]);
        $replayStartedAt = self::stringField($evidence, [
            'replay_started_at',
            'replayStartedAt',
            'replayed_at',
            'replayedAt',
        ]);
        $replayedEventIds = self::arrayField($evidence, [
            'replayed_event_ids',
            'replayedEventIds',
            'history_event_ids',
            'historyEventIds',
        ]) ?? [];
        $replayedEventTypes = self::stringList(self::arrayField($evidence, [
            'replayed_event_types',
            'replayedEventTypes',
            'history_event_types',
            'historyEventTypes',
        ]) ?? []);
        $duplicateTimerCommands = self::integerField($evidence, [
            'duplicate_timer_commands',
            'duplicateTimerCommands',
            'duplicate_timer_command_count',
            'duplicateTimerCommandCount',
            'start_timer_command_count_after_replay',
            'startTimerCommandCountAfterReplay',
        ]);

        $failures = [];
        $firedEpoch = self::timestampEpoch($firedAt);
        $replayStartedEpoch = self::timestampEpoch($replayStartedAt);

        if ($firedEpoch === null) {
            $failures[] = [
                'code' => 'missing_or_invalid_timer_fired_time',
                'scenario_id' => $scenarioId,
                'fired_at' => $firedAt,
            ];
        }

        if ($replayStartedEpoch === null) {
            $failures[] = [
                'code' => 'missing_or_invalid_replay_started_time',
                'scenario_id' => $scenarioId,
                'replay_started_at' => $replayStartedAt,
            ];
        }

        if ($firedEpoch !== null && $replayStartedEpoch !== null && $replayStartedEpoch < $firedEpoch) {
            $failures[] = [
                'code' => 'timer_replay_started_before_fire',
                'scenario_id' => $scenarioId,
                'fired_at' => $firedAt,
                'replay_started_at' => $replayStartedAt,
            ];
        }

        if ($replayedEventIds === []) {
            $failures[] = [
                'code' => 'missing_replayed_event_ids',
                'scenario_id' => $scenarioId,
            ];
        }

        if (! in_array('TimerFired', $replayedEventTypes, true)) {
            $failures[] = [
                'code' => 'replayed_history_missing_timer_fired',
                'scenario_id' => $scenarioId,
                'replayed_event_types' => $replayedEventTypes,
            ];
        }

        if ($duplicateTimerCommands !== 0) {
            $failures[] = [
                'code' => 'duplicate_timer_commands_after_replay',
                'scenario_id' => $scenarioId,
                'expected_count' => 0,
                'actual_count' => $duplicateTimerCommands,
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     *
     * @return list<array<string, mixed>>
     */
    private static function workerRestartWhileSleepingFailures(array $scenarioResult): array
    {
        $evidence = self::scenarioEvidence($scenarioResult);
        $scenarioId = 'worker_restart_while_sleeping';
        $sleepStartedAt = self::stringField($evidence, [
            'sleep_started_at',
            'sleepStartedAt',
            'sleep_requested_at',
            'sleepRequestedAt',
            'timer_scheduled_at',
            'timerScheduledAt',
        ]);
        $wakeUpAt = self::stringField($evidence, [
            'wake_up_at',
            'wakeUpAt',
            'wake_up_time',
            'wakeUpTime',
            'recorded_wake_up_at',
            'recordedWakeUpAt',
            'timer_wake_up_at',
            'timerWakeUpAt',
        ]);
        $completedAt = self::stringField($evidence, [
            'completed_at',
            'completedAt',
            'workflow_completed_at',
            'workflowCompletedAt',
            'run_closed_at',
            'runClosedAt',
        ]);
        $workerRestartWindow = self::arrayField($evidence, [
            'worker_restart_window',
            'workerRestartWindow',
            'restart_window',
            'restartWindow',
        ]) ?? [];
        $restartStartedAt = self::stringField($workerRestartWindow, [
            'started_at',
            'startedAt',
            'restart_started_at',
            'restartStartedAt',
        ]);
        $restartFinishedAt = self::stringField($workerRestartWindow, [
            'finished_at',
            'finishedAt',
            'restart_finished_at',
            'restartFinishedAt',
        ]);
        $duplicateResumeCount = self::integerField($evidence, [
            'duplicate_resume_count',
            'duplicateResumeCount',
            'duplicate_resumes',
            'duplicateResumes',
        ]);
        $timerFireCount = self::integerField($evidence, [
            'timer_fire_count',
            'timerFireCount',
            'fire_count',
            'fireCount',
            'timer_fired_count',
            'timerFiredCount',
        ]);
        $earlyResumeObserved = self::fieldValue($evidence, [
            'early_resume_observed',
            'earlyResumeObserved',
            'resumed_before_wake_up',
            'resumedBeforeWakeUp',
        ]);

        $failures = [];
        $sleepStartedEpoch = self::timestampEpoch($sleepStartedAt);
        $wakeEpoch = self::timestampEpoch($wakeUpAt);
        $completedEpoch = self::timestampEpoch($completedAt);
        $restartStartedEpoch = self::timestampEpoch($restartStartedAt);
        $restartFinishedEpoch = self::timestampEpoch($restartFinishedAt);

        if ($sleepStartedEpoch === null) {
            $failures[] = [
                'code' => 'missing_or_invalid_sleep_started_time',
                'scenario_id' => $scenarioId,
                'sleep_started_at' => $sleepStartedAt,
            ];
        }

        if ($wakeEpoch === null) {
            $failures[] = [
                'code' => 'missing_or_invalid_wake_up_time',
                'scenario_id' => $scenarioId,
                'wake_up_at' => $wakeUpAt,
            ];
        }

        if ($completedEpoch === null) {
            $failures[] = [
                'code' => 'missing_or_invalid_completed_time',
                'scenario_id' => $scenarioId,
                'completed_at' => $completedAt,
            ];
        }

        if ($restartStartedEpoch === null || $restartFinishedEpoch === null) {
            $failures[] = [
                'code' => 'missing_or_invalid_worker_restart_window',
                'scenario_id' => $scenarioId,
                'worker_restart_window' => $workerRestartWindow,
            ];
        }

        if (
            $sleepStartedEpoch !== null
            && $restartStartedEpoch !== null
            && $restartStartedEpoch < $sleepStartedEpoch
        ) {
            $failures[] = [
                'code' => 'worker_restart_before_sleep_started',
                'scenario_id' => $scenarioId,
                'sleep_started_at' => $sleepStartedAt,
                'restart_started_at' => $restartStartedAt,
            ];
        }

        if (
            $restartStartedEpoch !== null
            && $restartFinishedEpoch !== null
            && $restartFinishedEpoch < $restartStartedEpoch
        ) {
            $failures[] = [
                'code' => 'worker_restart_window_reversed',
                'scenario_id' => $scenarioId,
                'restart_started_at' => $restartStartedAt,
                'restart_finished_at' => $restartFinishedAt,
            ];
        }

        if ($restartFinishedEpoch !== null && $wakeEpoch !== null && $restartFinishedEpoch >= $wakeEpoch) {
            $failures[] = [
                'code' => 'worker_restart_not_before_wake_up',
                'scenario_id' => $scenarioId,
                'restart_finished_at' => $restartFinishedAt,
                'wake_up_at' => $wakeUpAt,
            ];
        }

        if ($wakeEpoch !== null && $completedEpoch !== null && $completedEpoch < $wakeEpoch) {
            $failures[] = [
                'code' => 'worker_restart_completed_before_wake_up',
                'scenario_id' => $scenarioId,
                'wake_up_at' => $wakeUpAt,
                'completed_at' => $completedAt,
            ];
        }

        if ($timerFireCount !== 1) {
            $failures[] = [
                'code' => 'worker_restart_timer_fire_count_mismatch',
                'scenario_id' => $scenarioId,
                'expected_count' => 1,
                'actual_count' => $timerFireCount,
            ];
        }

        if ($duplicateResumeCount !== 0) {
            $failures[] = [
                'code' => 'worker_restart_duplicate_resume_count_mismatch',
                'scenario_id' => $scenarioId,
                'expected_count' => 0,
                'actual_count' => $duplicateResumeCount,
            ];
        }

        if ($earlyResumeObserved === true) {
            $failures[] = [
                'code' => 'worker_restart_early_resume_observed',
                'scenario_id' => $scenarioId,
                'early_resume_observed' => true,
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     *
     * @return list<array<string, mixed>>
     */
    private static function serverRestartWhileSleepingFailures(array $scenarioResult): array
    {
        $evidence = self::scenarioEvidence($scenarioResult);
        $scenarioId = 'server_restart_while_sleeping';
        $sleepStartedAt = self::stringField($evidence, [
            'sleep_started_at',
            'sleepStartedAt',
            'sleep_requested_at',
            'sleepRequestedAt',
            'timer_scheduled_at',
            'timerScheduledAt',
        ]);
        $wakeUpAt = self::stringField($evidence, [
            'wake_up_at',
            'wakeUpAt',
            'wake_up_time',
            'wakeUpTime',
            'recorded_wake_up_at',
            'recordedWakeUpAt',
            'timer_wake_up_at',
            'timerWakeUpAt',
        ]);
        $completedAt = self::stringField($evidence, [
            'completed_at',
            'completedAt',
            'workflow_completed_at',
            'workflowCompletedAt',
            'run_closed_at',
            'runClosedAt',
        ]);
        $serverRestartWindow = self::arrayField($evidence, [
            'server_restart_window',
            'serverRestartWindow',
            'restart_window',
            'restartWindow',
        ]) ?? [];
        $restartStartedAt = self::stringField($serverRestartWindow, [
            'started_at',
            'startedAt',
            'restart_started_at',
            'restartStartedAt',
        ]);
        $restartFinishedAt = self::stringField($serverRestartWindow, [
            'finished_at',
            'finishedAt',
            'restart_finished_at',
            'restartFinishedAt',
        ]);
        $timerStateRecovered = self::fieldValue($evidence, [
            'timer_state_recovered',
            'timerStateRecovered',
            'waiting_timer_state_recovered',
            'waitingTimerStateRecovered',
        ]);
        $duplicateResumeCount = self::integerField($evidence, [
            'duplicate_resume_count',
            'duplicateResumeCount',
            'duplicate_resumes',
            'duplicateResumes',
        ]);
        $timerFireCount = self::integerField($evidence, [
            'timer_fire_count',
            'timerFireCount',
            'fire_count',
            'fireCount',
            'timer_fired_count',
            'timerFiredCount',
        ]);
        $earlyResumeObserved = self::fieldValue($evidence, [
            'early_resume_observed',
            'earlyResumeObserved',
            'resumed_before_wake_up',
            'resumedBeforeWakeUp',
        ]);

        $failures = [];
        $sleepStartedEpoch = self::timestampEpoch($sleepStartedAt);
        $wakeEpoch = self::timestampEpoch($wakeUpAt);
        $completedEpoch = self::timestampEpoch($completedAt);
        $restartStartedEpoch = self::timestampEpoch($restartStartedAt);
        $restartFinishedEpoch = self::timestampEpoch($restartFinishedAt);

        if ($sleepStartedEpoch === null) {
            $failures[] = [
                'code' => 'missing_or_invalid_sleep_started_time',
                'scenario_id' => $scenarioId,
                'sleep_started_at' => $sleepStartedAt,
            ];
        }

        if ($wakeEpoch === null) {
            $failures[] = [
                'code' => 'missing_or_invalid_wake_up_time',
                'scenario_id' => $scenarioId,
                'wake_up_at' => $wakeUpAt,
            ];
        }

        if ($completedEpoch === null) {
            $failures[] = [
                'code' => 'missing_or_invalid_completed_time',
                'scenario_id' => $scenarioId,
                'completed_at' => $completedAt,
            ];
        }

        if ($restartStartedEpoch === null || $restartFinishedEpoch === null) {
            $failures[] = [
                'code' => 'missing_or_invalid_server_restart_window',
                'scenario_id' => $scenarioId,
                'server_restart_window' => $serverRestartWindow,
            ];
        }

        if (
            $sleepStartedEpoch !== null
            && $restartStartedEpoch !== null
            && $restartStartedEpoch < $sleepStartedEpoch
        ) {
            $failures[] = [
                'code' => 'server_restart_before_sleep_started',
                'scenario_id' => $scenarioId,
                'sleep_started_at' => $sleepStartedAt,
                'restart_started_at' => $restartStartedAt,
            ];
        }

        if (
            $restartStartedEpoch !== null
            && $restartFinishedEpoch !== null
            && $restartFinishedEpoch < $restartStartedEpoch
        ) {
            $failures[] = [
                'code' => 'server_restart_window_reversed',
                'scenario_id' => $scenarioId,
                'restart_started_at' => $restartStartedAt,
                'restart_finished_at' => $restartFinishedAt,
            ];
        }

        if ($restartFinishedEpoch !== null && $wakeEpoch !== null && $restartFinishedEpoch >= $wakeEpoch) {
            $failures[] = [
                'code' => 'server_restart_not_before_wake_up',
                'scenario_id' => $scenarioId,
                'restart_finished_at' => $restartFinishedAt,
                'wake_up_at' => $wakeUpAt,
            ];
        }

        if ($wakeEpoch !== null && $completedEpoch !== null && $completedEpoch < $wakeEpoch) {
            $failures[] = [
                'code' => 'server_restart_completed_before_wake_up',
                'scenario_id' => $scenarioId,
                'wake_up_at' => $wakeUpAt,
                'completed_at' => $completedAt,
            ];
        }

        if (! self::booleanTrue($timerStateRecovered)) {
            $failures[] = [
                'code' => 'server_restart_timer_state_not_recovered',
                'scenario_id' => $scenarioId,
                'timer_state_recovered' => $timerStateRecovered,
            ];
        }

        if ($timerFireCount !== 1) {
            $failures[] = [
                'code' => 'server_restart_timer_fire_count_mismatch',
                'scenario_id' => $scenarioId,
                'expected_count' => 1,
                'actual_count' => $timerFireCount,
            ];
        }

        if ($duplicateResumeCount !== 0) {
            $failures[] = [
                'code' => 'server_restart_duplicate_resume_count_mismatch',
                'scenario_id' => $scenarioId,
                'expected_count' => 0,
                'actual_count' => $duplicateResumeCount,
            ];
        }

        if ($earlyResumeObserved === true) {
            $failures[] = [
                'code' => 'server_restart_early_resume_observed',
                'scenario_id' => $scenarioId,
                'early_resume_observed' => true,
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     *
     * @return list<array<string, mixed>>
     */
    private static function concurrentTimerFailures(array $scenarioResult): array
    {
        $evidence = self::scenarioEvidence($scenarioResult);
        $wakeUpTimes = self::timerTimestampMap($evidence, [
            'wake_up_times',
            'wakeUpTimes',
            'wake_times',
            'wakeTimes',
            'deadlines',
            'timer_deadlines',
            'timerDeadlines',
        ]);
        $fires = self::timerTimestampMapWithCounts($evidence, [
            'fired_at_times',
            'firedAtTimes',
            'fire_times',
            'fireTimes',
            'actual_fire_times',
            'actualFireTimes',
            'timer_fires',
            'timerFires',
        ]);
        $fireCounts = self::timerCountMap($evidence, [
            'fire_counts',
            'fireCounts',
            'timer_fire_counts',
            'timerFireCounts',
            'per_timer_fire_counts',
            'perTimerFireCounts',
            'per_timer_fire_count_map',
            'perTimerFireCountMap',
        ]);
        $resumeOrder = self::timerIdList($evidence, [
            'observed_resume_order',
            'observedResumeOrder',
            'resume_order',
            'resumeOrder',
            'resumed_timer_ids',
            'resumedTimerIds',
        ]);

        $failures = [];
        if (count($wakeUpTimes) < 2) {
            $failures[] = [
                'code' => 'missing_wake_up_times',
                'scenario_id' => 'concurrent_timers_distinct_deadlines',
            ];
        }

        if ($resumeOrder === []) {
            $failures[] = [
                'code' => 'missing_observed_resume_order',
                'scenario_id' => 'concurrent_timers_distinct_deadlines',
            ];
        }

        if ($fires['times'] === []) {
            $failures[] = [
                'code' => 'missing_timer_fire_times',
                'scenario_id' => 'concurrent_timers_distinct_deadlines',
            ];
        }

        if ($fireCounts === []) {
            $failures[] = [
                'code' => 'missing_timer_fire_counts',
                'scenario_id' => 'concurrent_timers_distinct_deadlines',
            ];
        }

        foreach (self::duplicateValues($resumeOrder) as $timerId) {
            $failures[] = [
                'code' => 'duplicate_timer_resume',
                'scenario_id' => 'concurrent_timers_distinct_deadlines',
                'timer_id' => $timerId,
            ];
        }

        foreach ($fires['counts'] as $timerId => $count) {
            if ($count <= 1) {
                continue;
            }

            $failures[] = [
                'code' => 'duplicate_timer_fire',
                'scenario_id' => 'concurrent_timers_distinct_deadlines',
                'timer_id' => $timerId,
                'count' => $count,
            ];
        }

        $wakeEpochs = [];
        foreach ($wakeUpTimes as $timerId => $wakeUpAt) {
            $epoch = self::timestampEpoch($wakeUpAt);
            if ($epoch === null) {
                $failures[] = [
                    'code' => 'invalid_wake_up_time',
                    'scenario_id' => 'concurrent_timers_distinct_deadlines',
                    'timer_id' => $timerId,
                    'wake_up_at' => $wakeUpAt,
                ];
                continue;
            }

            $wakeEpochs[$timerId] = $epoch;
        }

        if (count(array_unique($wakeEpochs)) !== count($wakeEpochs)) {
            $failures[] = [
                'code' => 'wake_up_times_not_distinct',
                'scenario_id' => 'concurrent_timers_distinct_deadlines',
            ];
        }

        if ($wakeEpochs !== [] && $resumeOrder !== []) {
            $expectedOrder = array_keys($wakeEpochs);
            usort(
                $expectedOrder,
                static fn (string $left, string $right): int => $wakeEpochs[$left] <=> $wakeEpochs[$right],
            );

            if ($resumeOrder !== $expectedOrder) {
                $failures[] = [
                    'code' => 'observed_resume_order_mismatch',
                    'scenario_id' => 'concurrent_timers_distinct_deadlines',
                    'expected_order' => $expectedOrder,
                    'observed_order' => $resumeOrder,
                ];
            }
        }

        foreach ($wakeUpTimes as $timerId => $_wakeUpAt) {
            if (! array_key_exists($timerId, $fires['times'])) {
                $failures[] = [
                    'code' => 'missing_timer_fire_record',
                    'scenario_id' => 'concurrent_timers_distinct_deadlines',
                    'timer_id' => $timerId,
                ];
            }

            if (! array_key_exists($timerId, $fireCounts)) {
                $failures[] = [
                    'code' => 'missing_timer_fire_count',
                    'scenario_id' => 'concurrent_timers_distinct_deadlines',
                    'timer_id' => $timerId,
                ];
                continue;
            }

            if ($fireCounts[$timerId] !== 1) {
                $failures[] = [
                    'code' => 'timer_fire_count_mismatch',
                    'scenario_id' => 'concurrent_timers_distinct_deadlines',
                    'timer_id' => $timerId,
                    'expected_count' => 1,
                    'actual_count' => $fireCounts[$timerId],
                ];
            }
        }

        foreach (array_keys($fireCounts) as $timerId) {
            if (array_key_exists($timerId, $wakeUpTimes)) {
                continue;
            }

            $failures[] = [
                'code' => 'unknown_timer_fire_count',
                'scenario_id' => 'concurrent_timers_distinct_deadlines',
                'timer_id' => $timerId,
            ];
        }

        foreach ($fires['times'] as $timerId => $firedAt) {
            if (! array_key_exists($timerId, $wakeUpTimes)) {
                $failures[] = [
                    'code' => 'unknown_timer_fire',
                    'scenario_id' => 'concurrent_timers_distinct_deadlines',
                    'timer_id' => $timerId,
                ];
                continue;
            }

            $fireEpoch = self::timestampEpoch($firedAt);
            $wakeEpoch = $wakeEpochs[$timerId] ?? null;
            if ($fireEpoch === null) {
                $failures[] = [
                    'code' => 'invalid_timer_fire_time',
                    'scenario_id' => 'concurrent_timers_distinct_deadlines',
                    'timer_id' => $timerId,
                    'fired_at' => $firedAt,
                ];
                continue;
            }

            if ($wakeEpoch !== null && $fireEpoch < $wakeEpoch) {
                $failures[] = [
                    'code' => 'timer_fired_before_wake_up',
                    'scenario_id' => 'concurrent_timers_distinct_deadlines',
                    'timer_id' => $timerId,
                    'wake_up_at' => $wakeUpTimes[$timerId],
                    'fired_at' => $firedAt,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $contract
     *
     * @return list<array<string, mixed>>
     */
    private static function cancellationWhileWaitingFailures(array $scenarioResult, array $contract): array
    {
        $evidence = self::scenarioEvidence($scenarioResult);
        $scenarioId = 'cancellation_while_waiting';
        $cancellationAt = self::stringField($evidence, [
            'cancellation_requested_at',
            'cancellationRequestedAt',
            'cancel_requested_at',
            'cancelRequestedAt',
            'cancelled_at',
            'cancelledAt',
            'canceled_at',
            'canceledAt',
            'cancellation_at',
            'cancellationAt',
        ]);
        $wakeUpAt = self::stringField($evidence, [
            'wake_up_at',
            'wakeUpAt',
            'wake_up_time',
            'wakeUpTime',
            'recorded_wake_up_at',
            'recordedWakeUpAt',
            'timer_wake_up_at',
            'timerWakeUpAt',
        ]);
        $firedAfterCancel = self::fieldValue($evidence, [
            'fired_after_cancel',
            'firedAfterCancel',
            'timer_fired_after_cancel',
            'timerFiredAfterCancel',
        ]);
        $workflowStatus = self::normalizeToken(self::stringField($evidence, [
            'workflow_status',
            'workflowStatus',
            'run_status',
            'runStatus',
            'terminal_status',
            'terminalStatus',
        ]));
        $allowedStatuses = self::stringList(
            $contract['scenario_requirements'][$scenarioId]['allowed_terminal_workflow_statuses'] ?? [],
        );

        $failures = [];
        $cancellationEpoch = self::timestampEpoch($cancellationAt);
        $wakeEpoch = self::timestampEpoch($wakeUpAt);

        if ($cancellationEpoch === null) {
            $failures[] = [
                'code' => 'missing_or_invalid_cancellation_time',
                'scenario_id' => $scenarioId,
                'cancellation_requested_at' => $cancellationAt,
            ];
        }

        if ($wakeEpoch === null) {
            $failures[] = [
                'code' => 'missing_or_invalid_wake_up_time',
                'scenario_id' => $scenarioId,
                'wake_up_at' => $wakeUpAt,
            ];
        }

        if ($cancellationEpoch !== null && $wakeEpoch !== null && $cancellationEpoch >= $wakeEpoch) {
            $failures[] = [
                'code' => 'cancellation_not_before_wake_up',
                'scenario_id' => $scenarioId,
                'cancellation_requested_at' => $cancellationAt,
                'wake_up_at' => $wakeUpAt,
            ];
        }

        if ($firedAfterCancel !== false) {
            $failures[] = [
                'code' => 'timer_fired_after_cancel',
                'scenario_id' => $scenarioId,
                'fired_after_cancel' => $firedAfterCancel,
            ];
        }

        if (! in_array($workflowStatus, $allowedStatuses, true)) {
            $failures[] = [
                'code' => 'invalid_cancellation_workflow_status',
                'scenario_id' => $scenarioId,
                'workflow_status' => $workflowStatus,
                'allowed_statuses' => $allowedStatuses,
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $contract
     *
     * @return list<array<string, mixed>>
     */
    private static function operatorVisibleWaitingFailures(array $scenarioResult, array $contract): array
    {
        $evidence = self::scenarioEvidence($scenarioResult);
        $scenarioId = 'operator_visible_timer_waiting_state';
        $observedStatus = self::normalizeToken(self::stringField($evidence, [
            'status',
            'workflow_status',
            'workflowStatus',
            'run_status',
            'runStatus',
            'visible_status',
            'visibleStatus',
            'timer_wait_status',
            'timerWaitStatus',
        ]));
        $allowedStatuses = self::stringList(
            $contract['scenario_requirements'][$scenarioId]['explicit_waiting_statuses'] ?? [],
        );
        $normalizedAllowedStatuses = array_map([self::class, 'normalizeToken'], $allowedStatuses);
        $surfaces = self::surfaceValues($evidence);

        $failures = [];
        if (! in_array($observedStatus, $normalizedAllowedStatuses, true)) {
            $failures[] = [
                'code' => 'invalid_timer_waiting_status',
                'scenario_id' => $scenarioId,
                'status' => $observedStatus,
                'allowed_statuses' => $allowedStatuses,
            ];
        }

        if (! self::hasRecognizedPublicSurface($surfaces)) {
            $failures[] = [
                'code' => 'unrecognized_timer_waiting_observation_surface',
                'scenario_id' => $scenarioId,
                'surfaces' => $surfaces,
                'recognized_public_surfaces' => ['cli', 'waterline', 'public_api'],
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return list<array<string, mixed>>
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
            'runner_blocked' => array_key_exists('runner_blocked', $result) || array_key_exists('runnerBlocked', $result),
            'scenario_results' => self::hasArrayField($result, ['scenario_results', 'scenarioResults']),
            'findings' => self::hasArrayField($result, ['findings']),
            'finding_links' => self::hasArrayField($result, ['finding_links', 'findingLinks']),
            default => self::hasScalarField($result, [$field]) || self::hasArrayField($result, [$field]),
        };
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
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
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return list<array<string, mixed>>
     */
    private static function declaredOutcomeStatusFailures(array $result, array $contract, string $evaluatedStatus): array
    {
        $declaredOutcomes = self::declaredOutcomeTokens($result);
        if ($declaredOutcomes === []) {
            return [];
        }

        $allowedOutcomes = self::declaredOutcomes($contract);
        $failures = [];
        foreach ($declaredOutcomes as $field => $outcome) {
            if (! in_array($outcome, $allowedOutcomes, true)) {
                continue;
            }

            $declaredStatus = $outcome === 'pass' ? 'pass' : 'non_passing';
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
        foreach (self::stringList($contract['scenario_statuses'] ?? []) as $status) {
            if ($status !== 'pass') {
                $outcomes[] = $status;
            }
        }

        foreach (self::arrayField($contract, ['coverage_gate']) ?? [] as $key => $value) {
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
     * @return list<array<string, mixed>>
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
     * @return list<array<string, mixed>>
     */
    private static function sourcePolicyFailures(array $result, array $contract): array
    {
        $artifactPolicy = self::arrayField($contract, ['artifact_policy']) ?? [];
        $forbiddenSources = self::stringList($artifactPolicy['forbidden_sources'] ?? []);
        $reportedSources = self::arrayField($result, ['artifact_sources', 'artifactSources']) ?? [];
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
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<string, mixed>
     */
    private static function scenarioEvidence(array $scenarioResult): array
    {
        $observedOutputs = self::arrayField($scenarioResult, [
            'observed_outputs',
            'observedOutputs',
            'timer_observations',
            'timerObservations',
        ]) ?? [];

        return array_merge($scenarioResult, $observedOutputs);
    }

    /**
     * @param array<string, mixed> $evidence
     * @param list<string> $fields
     *
     * @return array<string, string>
     */
    private static function timerTimestampMap(array $evidence, array $fields): array
    {
        return self::timerTimestampMapWithCounts($evidence, $fields)['times'];
    }

    /**
     * @param array<string, mixed> $evidence
     * @param list<string> $fields
     *
     * @return array{times: array<string, string>, counts: array<string, int>}
     */
    private static function timerTimestampMapWithCounts(array $evidence, array $fields): array
    {
        $raw = self::arrayField($evidence, $fields) ?? [];
        $times = [];
        $counts = [];

        foreach ($raw as $key => $value) {
            if (is_array($value)) {
                $timerId = self::timerId($value);
                if ($timerId === '' && is_string($key)) {
                    $timerId = $key;
                }
                $timestamp = self::timestampString($value);
            } else {
                $timerId = is_string($key) ? $key : '';
                $timestamp = self::stringValue($value);
            }

            if ($timerId === '' || $timestamp === '') {
                continue;
            }

            $times[$timerId] ??= $timestamp;
            $counts[$timerId] = ($counts[$timerId] ?? 0) + 1;
        }

        return [
            'times' => $times,
            'counts' => $counts,
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     * @param list<string> $fields
     *
     * @return list<string>
     */
    private static function timerIdList(array $evidence, array $fields): array
    {
        $raw = self::arrayField($evidence, $fields) ?? [];
        $ids = [];

        foreach ($raw as $value) {
            $timerId = is_array($value) ? self::timerId($value) : self::stringValue($value);
            if ($timerId !== '') {
                $ids[] = $timerId;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $evidence
     * @param list<string> $fields
     *
     * @return array<string, int>
     */
    private static function timerCountMap(array $evidence, array $fields): array
    {
        $raw = self::arrayField($evidence, $fields) ?? [];
        $counts = [];

        foreach ($raw as $key => $value) {
            if (is_array($value)) {
                $timerId = self::timerId($value);
                if ($timerId === '' && is_string($key)) {
                    $timerId = $key;
                }
                $count = self::integerField($value, [
                    'fire_count',
                    'fireCount',
                    'count',
                    'fires',
                    'observed_fire_count',
                    'observedFireCount',
                ]);
            } else {
                $timerId = is_string($key) ? $key : '';
                $count = self::integerValue($value);
            }

            if ($timerId === '' || $count === null) {
                continue;
            }

            $counts[$timerId] = ($counts[$timerId] ?? 0) + $count;
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function timerId(array $value): string
    {
        return self::stringField($value, ['timer_id', 'timerId', 'id', 'name']);
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function timestampString(array $value): string
    {
        return self::stringField($value, [
            'wake_up_at',
            'wakeUpAt',
            'wake_up_time',
            'wakeUpTime',
            'deadline',
            'fired_at',
            'firedAt',
            'fire_at',
            'fireAt',
            'timestamp',
            'time',
            'at',
            'scheduled_for',
            'scheduledFor',
        ]);
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $fields
     */
    private static function integerField(array $value, array $fields): ?int
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $value)) {
                continue;
            }

            $integerValue = self::integerValue($value[$field]);
            if ($integerValue !== null) {
                return $integerValue;
            }
        }

        return null;
    }

    private static function integerValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    private static function booleanTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
        }

        return false;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function duplicateValues(array $values): array
    {
        $seen = [];
        $duplicates = [];
        foreach ($values as $value) {
            if (isset($seen[$value])) {
                $duplicates[$value] = $value;
            }
            $seen[$value] = true;
        }

        return array_values($duplicates);
    }

    private static function timestampEpoch(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $epoch = strtotime($value);

        return $epoch === false ? null : $epoch;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $fields
     */
    private static function stringField(array $value, array $fields): string
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $value)) {
                continue;
            }

            $stringValue = self::stringValue($value[$field]);
            if ($stringValue !== '') {
                return $stringValue;
            }
        }

        return '';
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
     *
     * @return list<string>
     */
    private static function surfaceValues(array $value): array
    {
        $surfaces = [];
        foreach ([
            'surface',
            'public_surface',
            'publicSurface',
            'observed_surface',
            'observedSurface',
            'operator_surface',
            'operatorSurface',
            'observation_surface',
            'observationSurface',
        ] as $field) {
            $surface = self::stringValue($value[$field] ?? null);
            if ($surface !== '') {
                $surfaces[] = $surface;
            }
        }

        foreach (['surfaces', 'public_surfaces', 'publicSurfaces'] as $field) {
            foreach (self::stringList($value[$field] ?? []) as $surface) {
                $surfaces[] = $surface;
            }
        }

        return array_values(array_unique($surfaces));
    }

    /**
     * @param list<string> $surfaces
     */
    private static function hasRecognizedPublicSurface(array $surfaces): bool
    {
        foreach ($surfaces as $surface) {
            $normalized = self::normalizeToken($surface);
            if (in_array($normalized, [
                'cli',
                'dw'.'_cli',
                'durable_workflow_cli',
                'waterline',
                'waterline_ui',
                'waterline_dashboard',
                'public_api',
                'api',
                'rest_api',
                'http_api',
                'control_plane_api',
            ], true)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeToken(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace(['-', ' ', ':', '/'], '_', $normalized);

        return preg_replace('/_+/', '_', $normalized) ?? $normalized;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     *
     * @return list<array<string, mixed>>
     */
    private static function linkedFindingRefs(array $scenarioResult): array
    {
        $refs = [];
        foreach (['linked_findings', 'linkedFindings', 'finding_links', 'findingLinks'] as $field) {
            $value = self::arrayField($scenarioResult, [$field]);
            if ($value === null) {
                continue;
            }

            foreach ($value as $key => $entry) {
                if (is_array($entry)) {
                    if (is_string($key) && self::findingId($entry) === '') {
                        $entry['finding_id'] = $key;
                    }
                    $refs[] = $entry;
                } elseif (is_string($entry)) {
                    $refs[] = ['finding_id' => $entry];
                } elseif (is_string($key)) {
                    $refs[] = ['finding_id' => $key];
                }
            }
        }

        return $refs;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<string>
     */
    private static function topLevelFindingIds(array $result): array
    {
        $ids = [];
        $findings = self::arrayField($result, ['findings']) ?? [];
        foreach ($findings as $key => $finding) {
            if (is_string($key)) {
                $ids[] = $key;
            }

            if (is_array($finding)) {
                $id = self::findingId($finding);
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<string>
     */
    private static function topLevelScenarioLinkIds(array $result, string $scenarioId): array
    {
        $ids = [];
        $links = self::arrayField($result, ['finding_links', 'findingLinks']) ?? [];

        if (array_key_exists($scenarioId, $links)) {
            foreach (self::arrayWrap($links[$scenarioId]) as $entry) {
                $id = is_array($entry) ? self::findingId($entry) : self::stringValue($entry);
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }

        foreach ($links as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $linkedScenario = self::stringValue($entry['scenario_id'] ?? $entry['scenario'] ?? null);
            if ($linkedScenario !== $scenarioId) {
                continue;
            }

            $id = self::findingId($entry);
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function topLevelLinksScenario(array $result, string $scenarioId): bool
    {
        return self::topLevelScenarioLinkIds($result, $scenarioId) !== [];
    }

    /**
     * @param array<string, mixed> $finding
     */
    private static function findingId(array $finding): string
    {
        return self::stringField($finding, ['finding_id', 'findingId', 'id', 'url', 'href']);
    }

    /**
     * @param mixed $value
     *
     * @return list<mixed>
     */
    private static function arrayWrap(mixed $value): array
    {
        if (is_array($value)) {
            return array_is_list($value) ? $value : [$value];
        }

        return [$value];
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

        $strings = [];
        foreach ($value as $item) {
            if (is_string($item) || is_numeric($item)) {
                $strings[] = (string) $item;
            }
        }

        return $strings;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $fields
     *
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

    /**
     * @param array<string, mixed> $value
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
     * @param array<string, mixed> $value
     * @param list<string> $fields
     */
    private static function hasScalarField(array $value, array $fields): bool
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $value)) {
                continue;
            }

            if (! is_array($value[$field]) && self::stringValue($value[$field]) !== '') {
                return true;
            }
        }

        return false;
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
}
