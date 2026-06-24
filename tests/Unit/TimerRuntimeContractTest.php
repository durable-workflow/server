<?php

namespace Tests\Unit;

use App\Support\TimerRuntimeContract;
use App\Support\TimerRuntimeResultGate;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;

final class TimerRuntimeContractTest extends TestCase
{
    public function test_manifest_publishes_timer_runtime_contract_and_runner_gap_status(): void
    {
        $manifest = TimerRuntimeContract::manifest();

        $this->assertSame('durable-workflow.v2.timer-runtime.contract', $manifest['schema']);
        $this->assertSame(TimerRuntimeContract::VERSION, $manifest['version']);
        $this->assertSame('durable-workflow.v2.timer-runtime.result', $manifest['result_schema']);
        $this->assertSame('timer_runtime_contract', $manifest['fixture_category']);
        $this->assertSame(PlatformConformanceSuite::SCHEMA, $manifest['platform_conformance_suite_authority']);
        $this->assertSame(
            'static/platform-conformance/timer-runtime-scenarios.json',
            $manifest['scenario_manifest']['source_path'],
        );
        $this->assertSame(
            [
                'concurrent_timers_distinct_deadlines',
                'cancellation_while_waiting',
                'operator_visible_timer_waiting_state',
            ],
            $manifest['required_scenarios'],
        );
        $this->assertSame(
            'runner_gap_until_timer_host_runner_exists',
            $manifest['host_runner_contract']['status'],
        );
        $this->assertFalse($manifest['host_runner_contract']['host_runner_implemented']);
        $this->assertSame('runner_blocked', $manifest['coverage_gate']['runner_blocked_outcome']);
    }

    public function test_public_scenario_manifest_matches_timer_contract(): void
    {
        $contract = TimerRuntimeContract::manifest();
        $scenarioManifest = json_decode(
            file_get_contents(dirname(__DIR__, 2).'/static/platform-conformance/timer-runtime-scenarios.json') ?: '',
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame($contract['scenario_manifest']['schema'], $scenarioManifest['schema']);
        $this->assertSame($contract['scenario_manifest']['category'], $scenarioManifest['category']);
        $this->assertSame($contract['scenario_manifest']['suite_schema'], $scenarioManifest['suite_schema']);
        $this->assertSame($contract['scenario_manifest']['suite_version'], $scenarioManifest['suite_version']);
        $this->assertSame($contract['result_schema'], $scenarioManifest['result_schema']);
        $this->assertSame($contract['result_version'], $scenarioManifest['result_version']);
        $this->assertSame($contract['scenario_statuses'], $scenarioManifest['result_statuses']);
        $this->assertSame($contract['required_scenarios'], $scenarioManifest['required_scenarios']);
        $this->assertSame($contract['required_scenarios'], array_column($scenarioManifest['scenarios'], 'id'));
        $this->assertSame($contract['scenario_requirements'], $scenarioManifest['scenario_requirements']);
        $this->assertSame($contract['timer_semantics'], $scenarioManifest['timer_semantics']);
        $this->assertSame(
            $contract['host_runner_contract']['routing_policy']['missing_host_runner'],
            $scenarioManifest['host_runner_contract']['routing_policy']['missing_host_runner'],
        );
    }

    public function test_manifest_publishes_an_enforceable_timer_result_gate(): void
    {
        $resultGate = TimerRuntimeContract::manifest()['result_gate'];

        $this->assertSame(TimerRuntimeResultGate::SCHEMA, $resultGate['schema']);
        $this->assertSame(TimerRuntimeResultGate::VERSION, $resultGate['version']);
        $this->assertSame(TimerRuntimeContract::RESULT_SCHEMA, $resultGate['evaluates_result_schema']);
        $this->assertContains('scenario_results', $resultGate['scenario_results_fields']);
        $this->assertContains('artifactVersions', $resultGate['artifact_versions_fields']);
        $this->assertContains(
            'concurrent_timer_resume_order_matches_wake_up_times',
            $resultGate['pass_requires'],
        );
        $this->assertContains('concurrent_timer_fires_are_not_early', $resultGate['pass_requires']);
        $this->assertContains('concurrent_timer_fires_are_not_duplicated', $resultGate['pass_requires']);
        $this->assertContains('cancellation_occurs_before_recorded_wake_up', $resultGate['pass_requires']);
        $this->assertContains('cancelled_timer_does_not_fire_after_cancel', $resultGate['pass_requires']);
        $this->assertContains('operator_waiting_state_uses_recognized_public_surface', $resultGate['pass_requires']);
    }

    public function test_result_gate_rejects_concurrent_timer_resume_order_that_does_not_match_wake_up_times(): void
    {
        $result = $this->completePassingTimerResult();
        $result['scenario_results']['concurrent_timers_distinct_deadlines']['observed_outputs']['observed_resume_order'] = [
            'timer-b',
            'timer-a',
            'timer-c',
        ];

        $evaluation = TimerRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('observed_resume_order_mismatch', array_column($evaluation['gate_failures'], 'code'));
    }

    public function test_result_gate_rejects_concurrent_timer_early_fire(): void
    {
        $result = $this->completePassingTimerResult();
        $result['scenario_results']['concurrent_timers_distinct_deadlines']['observed_outputs']['fired_at_times']['timer-b'] =
            '2026-06-24T10:00:04Z';

        $evaluation = TimerRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('timer_fired_before_wake_up', array_column($evaluation['gate_failures'], 'code'));
    }

    public function test_result_gate_rejects_concurrent_timer_duplicate_fire(): void
    {
        $result = $this->completePassingTimerResult();
        $result['scenario_results']['concurrent_timers_distinct_deadlines']['observed_outputs']['fired_at_times'] = [
            ['timer_id' => 'timer-a', 'fired_at' => '2026-06-24T10:00:01Z'],
            ['timer_id' => 'timer-b', 'fired_at' => '2026-06-24T10:00:05Z'],
            ['timer_id' => 'timer-b', 'fired_at' => '2026-06-24T10:00:06Z'],
            ['timer_id' => 'timer-c', 'fired_at' => '2026-06-24T10:00:09Z'],
        ];

        $evaluation = TimerRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('duplicate_timer_fire', array_column($evaluation['gate_failures'], 'code'));
    }

    public function test_result_gate_rejects_cancellation_after_recorded_wake_up(): void
    {
        $result = $this->completePassingTimerResult();
        $result['scenario_results']['cancellation_while_waiting']['observed_outputs']['cancellation_requested_at'] =
            '2026-06-24T10:00:30Z';

        $evaluation = TimerRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('cancellation_not_before_wake_up', array_column($evaluation['gate_failures'], 'code'));
    }

    public function test_result_gate_rejects_cancelled_timer_that_fired_after_cancel(): void
    {
        $result = $this->completePassingTimerResult();
        $result['scenario_results']['cancellation_while_waiting']['observed_outputs']['fired_after_cancel'] = true;

        $evaluation = TimerRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('timer_fired_after_cancel', array_column($evaluation['gate_failures'], 'code'));
    }

    public function test_result_gate_rejects_cancellation_with_non_terminal_workflow_status(): void
    {
        $result = $this->completePassingTimerResult();
        $result['scenario_results']['cancellation_while_waiting']['observed_outputs']['workflow_status'] = 'waiting';

        $evaluation = TimerRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('invalid_cancellation_workflow_status', array_column($evaluation['gate_failures'], 'code'));
    }

    public function test_result_gate_rejects_operator_visibility_without_explicit_waiting_status(): void
    {
        $result = $this->completePassingTimerResult();
        $result['scenario_results']['operator_visible_timer_waiting_state']['observed_outputs']['status'] = 'running';

        $evaluation = TimerRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('invalid_timer_waiting_status', array_column($evaluation['gate_failures'], 'code'));
    }

    public function test_result_gate_rejects_operator_visibility_from_unrecognized_surface(): void
    {
        $result = $this->completePassingTimerResult();
        $result['scenario_results']['operator_visible_timer_waiting_state']['observed_outputs']['surface'] = 'private_db';

        $evaluation = TimerRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'unrecognized_timer_waiting_observation_surface',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_scenario_finding_without_top_level_parity(): void
    {
        $result = $this->completeRunnerBlockedTimerResult();
        unset($result['findings'][1]);
        unset($result['finding_links']['cancellation_while_waiting']);

        $evaluation = TimerRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('missing_top_level_finding', $failureCodes);
        $this->assertContains('missing_top_level_finding_link', $failureCodes);
    }

    public function test_result_gate_rejects_top_level_finding_links_without_top_level_findings(): void
    {
        $result = $this->completeRunnerBlockedTimerResult();
        $result['findings'] = [];
        foreach (array_keys($result['scenario_results']) as $scenarioId) {
            unset($result['scenario_results'][$scenarioId]['linked_findings']);
        }

        $evaluation = TimerRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('missing_top_level_finding', $failureCodes);
        $this->assertNotContains('missing_non_pass_finding', $failureCodes);
    }

    public function test_result_gate_accepts_complete_non_passing_runner_blocked_timer_handoff(): void
    {
        $evaluation = TimerRuntimeResultGate::evaluate($this->completeRunnerBlockedTimerResult());

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertSame(TimerRuntimeContract::manifest()['required_scenarios'], $evaluation['reported_scenarios']);
        $this->assertSame(TimerRuntimeContract::manifest()['required_scenarios'], $evaluation['non_pass_scenarios']);
        $this->assertSame([], $evaluation['missing_scenarios']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    /**
     * @return array<string, mixed>
     */
    private function completePassingTimerResult(): array
    {
        return [
            'schema' => TimerRuntimeContract::RESULT_SCHEMA,
            'version' => TimerRuntimeContract::RESULT_VERSION,
            'started_at' => '2026-06-24T10:00:00Z',
            'finished_at' => '2026-06-24T10:02:00Z',
            'generated_at' => '2026-06-24T10:02:01Z',
            'outcome' => 'pass',
            'runner_blocked' => false,
            'artifact_versions' => [
                'server' => '0.2.492',
                'cli' => '0.1.82',
                'workflow' => '2.0.0-alpha.223',
                'sdk-python' => '0.4.90',
                'waterline' => '2.0.0-alpha.111',
            ],
            'scenario_results' => [
                'concurrent_timers_distinct_deadlines' => [
                    'scenario_id' => 'concurrent_timers_distinct_deadlines',
                    'status' => 'pass',
                    'observed_outputs' => [
                        'wake_up_times' => [
                            'timer-a' => '2026-06-24T10:00:01Z',
                            'timer-b' => '2026-06-24T10:00:05Z',
                            'timer-c' => '2026-06-24T10:00:09Z',
                        ],
                        'observed_resume_order' => [
                            'timer-a',
                            'timer-b',
                            'timer-c',
                        ],
                        'fired_at_times' => [
                            'timer-a' => '2026-06-24T10:00:01Z',
                            'timer-b' => '2026-06-24T10:00:05Z',
                            'timer-c' => '2026-06-24T10:00:09Z',
                        ],
                    ],
                ],
                'cancellation_while_waiting' => [
                    'scenario_id' => 'cancellation_while_waiting',
                    'status' => 'pass',
                    'observed_outputs' => [
                        'cancellation_requested_at' => '2026-06-24T10:00:10Z',
                        'wake_up_at' => '2026-06-24T10:00:20Z',
                        'fired_after_cancel' => false,
                        'workflow_status' => 'cancelled',
                    ],
                ],
                'operator_visible_timer_waiting_state' => [
                    'scenario_id' => 'operator_visible_timer_waiting_state',
                    'status' => 'pass',
                    'observed_outputs' => [
                        'status' => 'timer_waiting',
                        'surface' => 'public_api',
                    ],
                ],
            ],
            'findings' => [],
            'finding_links' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function completeRunnerBlockedTimerResult(): array
    {
        $findings = [
            [
                'id' => 'timer-concurrent-runner-gap',
                'finding_type' => 'conformance_runner_coverage_gap',
                'classification' => 'runner-gap',
                'scenario_id' => 'concurrent_timers_distinct_deadlines',
                'owning_surface' => 'conformance_harness',
                'summary' => 'No timer host runner exists to prove concurrent timer ordering against published artifacts.',
                'next_acceptance_criterion' => 'Add a host runner shard that records wake_up_times, observed_resume_order, and fired_at_times.',
            ],
            [
                'id' => 'timer-cancellation-runner-gap',
                'finding_type' => 'conformance_runner_coverage_gap',
                'classification' => 'runner-gap',
                'scenario_id' => 'cancellation_while_waiting',
                'owning_surface' => 'conformance_harness',
                'summary' => 'No timer host runner exists to prove cancellation while waiting against published artifacts.',
                'next_acceptance_criterion' => 'Add a host runner shard that cancels before wake-up and records terminal workflow status.',
            ],
            [
                'id' => 'timer-operator-visibility-runner-gap',
                'finding_type' => 'conformance_runner_coverage_gap',
                'classification' => 'runner-gap',
                'scenario_id' => 'operator_visible_timer_waiting_state',
                'owning_surface' => 'conformance_harness',
                'summary' => 'No timer host runner exists to prove waiting-state visibility through public operator surfaces.',
                'next_acceptance_criterion' => 'Add a host runner shard that observes timer waiting state through CLI, Waterline, or public API.',
            ],
        ];

        return [
            'schema' => TimerRuntimeContract::RESULT_SCHEMA,
            'version' => TimerRuntimeContract::RESULT_VERSION,
            'started_at' => '2026-06-24T10:00:00Z',
            'finished_at' => '2026-06-24T10:00:01Z',
            'generated_at' => '2026-06-24T10:00:02Z',
            'outcome' => 'runner_blocked',
            'runner_blocked' => true,
            'artifact_versions' => [
                'server' => '0.2.492',
                'cli' => '0.1.82',
                'workflow' => '2.0.0-alpha.223',
                'sdk-python' => '0.4.90',
                'waterline' => '2.0.0-alpha.111',
            ],
            'scenario_results' => [
                'concurrent_timers_distinct_deadlines' => [
                    'scenario_id' => 'concurrent_timers_distinct_deadlines',
                    'status' => 'runner_blocked',
                    'linked_findings' => [
                        ['finding_id' => 'timer-concurrent-runner-gap', 'finding_type' => 'conformance_runner_coverage_gap'],
                    ],
                    'observed_outputs' => [
                        'blocked_reason' => 'timer_host_runner_missing',
                    ],
                ],
                'cancellation_while_waiting' => [
                    'scenario_id' => 'cancellation_while_waiting',
                    'status' => 'runner_blocked',
                    'linked_findings' => [
                        ['finding_id' => 'timer-cancellation-runner-gap', 'finding_type' => 'conformance_runner_coverage_gap'],
                    ],
                    'observed_outputs' => [
                        'blocked_reason' => 'timer_host_runner_missing',
                    ],
                ],
                'operator_visible_timer_waiting_state' => [
                    'scenario_id' => 'operator_visible_timer_waiting_state',
                    'status' => 'runner_blocked',
                    'linked_findings' => [
                        ['finding_id' => 'timer-operator-visibility-runner-gap', 'finding_type' => 'conformance_runner_coverage_gap'],
                    ],
                    'observed_outputs' => [
                        'blocked_reason' => 'timer_host_runner_missing',
                    ],
                ],
            ],
            'findings' => $findings,
            'finding_links' => [
                'concurrent_timers_distinct_deadlines' => ['timer-concurrent-runner-gap'],
                'cancellation_while_waiting' => ['timer-cancellation-runner-gap'],
                'operator_visible_timer_waiting_state' => ['timer-operator-visibility-runner-gap'],
            ],
        ];
    }
}
