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
                'normal_sleep_completion',
                'worker_restart_while_sleeping',
                'server_restart_while_sleeping',
                'replay_after_timer_fire',
                'concurrent_timers_distinct_deadlines',
                'cancellation_while_waiting',
                'operator_visible_timer_waiting_state',
            ],
            $manifest['required_scenarios'],
        );
        $this->assertSame(
            'published_handoff_non_passing_until_timer_scenarios_are_implemented',
            $manifest['host_runner_contract']['status'],
        );
        $this->assertTrue($manifest['host_runner_contract']['host_runner_implemented']);
        $this->assertSame(
            'scripts/conformance/timers-published-artifacts.sh',
            $manifest['host_runner_contract']['runner_path'],
        );
        $this->assertSame(
            'scripts/conformance/timers-published-artifacts.sh --result-dir <result-dir>',
            $manifest['host_runner_contract']['runner_command'],
        );
        $this->assertContains('timer-runtime-result.json', $manifest['host_runner_contract']['result_files']);
        $this->assertContains('timer-runtime-record.json', $manifest['host_runner_contract']['result_files']);
        $this->assertTrue($manifest['host_runner_contract']['must_execute_inside_pinned_published_server_image']);
        $this->assertTrue($manifest['host_runner_contract']['no_local_product_source_checkout_pass_evidence']);
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
        $this->assertSame($contract['coverage_gate'], $scenarioManifest['coverage_gate']);
        $this->assertSame(
            $contract['host_runner_contract']['routing_policy']['missing_host_runner'],
            $scenarioManifest['host_runner_contract']['routing_policy']['missing_host_runner'],
        );
        $this->assertSame(
            $contract['host_runner_contract']['runner_path'],
            $scenarioManifest['host_runner_contract']['runner_path'],
        );
        $this->assertSame(
            $contract['host_runner_contract']['result_files'],
            $scenarioManifest['host_runner_contract']['result_files'],
        );
    }

    public function test_published_artifact_handoff_emits_source_free_non_passing_timer_record(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir().'/dw-timers-conformance-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);

        try {
            $command = sprintf(
                'DW_SERVER_IMAGE=%s DW_SERVER_VERSION=%s DW_CLI_VERSION=%s DW_PYTHON_SDK_VERSION=%s DW_WORKFLOW_PHP_VERSION=%s DW_WATERLINE_VERSION=%s bash %s --result-dir %s 2>&1',
                escapeshellarg('durableworkflow/server:0.2.494'),
                escapeshellarg('0.2.494'),
                escapeshellarg('0.1.82'),
                escapeshellarg('0.4.90'),
                escapeshellarg('2.0.0-alpha.223'),
                escapeshellarg('2.0.0-alpha.111'),
                escapeshellarg($repoRoot.'/scripts/conformance/timers-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));
            $this->assertFileExists($resultDir.'/pins.json');
            $this->assertFileExists($resultDir.'/run-metadata.json');
            $this->assertFileExists($resultDir.'/timer-runtime-result.json');
            $this->assertFileExists($resultDir.'/timer-runtime-record.json');
            $this->assertFileExists($resultDir.'/timers-result.json');
            $this->assertFileExists($resultDir.'/timers-record.json');

            $result = json_decode(
                file_get_contents($resultDir.'/timer-runtime-result.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $record = json_decode(
                file_get_contents($resultDir.'/timer-runtime-record.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame(TimerRuntimeContract::RESULT_SCHEMA, $result['schema']);
            $this->assertSame('non_passing', $result['outcome']);
            $this->assertFalse($result['runner_blocked']);
            $this->assertSame('durableworkflow/server:0.2.494', $result['artifact_sources']['server']);
            $this->assertSame('durableworkflow/server:0.2.494', $result['artifact_images']['server']);
            $this->assertSame('0.1.82', $result['artifact_versions']['cli']);
            $this->assertSame('0.4.90', $result['artifact_versions']['sdk-python']);
            $this->assertSame('2.0.0-alpha.223', $result['artifact_versions']['workflow']);
            $this->assertSame('2.0.0-alpha.111', $result['artifact_versions']['waterline']);
            $this->assertTrue($result['no_local_product_source_checkout_pass_evidence']);
            $this->assertFalse($result['source_policy']['local_product_source_checkout_used_as_pass_evidence']);
            $this->assertSame(TimerRuntimeContract::manifest()['required_scenarios'], $result['unproven_timer_cells']);
            $this->assertSame(TimerRuntimeContract::manifest()['required_scenarios'], array_keys($result['scenario_results']));

            foreach (TimerRuntimeContract::manifest()['required_scenarios'] as $scenarioId) {
                $this->assertSame('not_covered', $result['scenario_results'][$scenarioId]['status']);
                $this->assertSame('coverage-gap', $result['scenario_results'][$scenarioId]['classification']);
                $this->assertNotEmpty($result['finding_links'][$scenarioId]);
            }

            $evaluation = TimerRuntimeResultGate::evaluate($result);
            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertSame([], $evaluation['gate_failures']);

            $this->assertSame('durable-workflow.v2.timer-runtime.published-artifacts', $record['schema']);
            $this->assertSame('non_passing', $record['outcome']);
            $this->assertFalse($record['runnerBlocked']);
            $this->assertSame('durableworkflow/server:0.2.494', $record['artifact_sources']['server']);
            $this->assertTrue($record['no_local_product_source_checkout_pass_evidence']);
            $this->assertSame($result, $record['result']);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_published_artifact_handoff_accepts_release_server_image_repositories(): void
    {
        $repoRoot = dirname(__DIR__, 2);

        foreach ([
            'registry-1.docker.io/durableworkflow/server:0.2.494',
            'ghcr.io/durable-workflow/server:0.2.494',
        ] as $serverImage) {
            $resultDir = sys_get_temp_dir().'/dw-timers-conformance-'.bin2hex(random_bytes(6));
            mkdir($resultDir, 0777, true);

            try {
                $command = sprintf(
                    'DW_SERVER_IMAGE=%s DW_SERVER_VERSION=%s DW_CLI_VERSION=%s DW_PYTHON_SDK_VERSION=%s DW_WORKFLOW_PHP_VERSION=%s DW_WATERLINE_VERSION=%s bash %s --result-dir %s 2>&1',
                    escapeshellarg($serverImage),
                    escapeshellarg('0.2.494'),
                    escapeshellarg('0.1.82'),
                    escapeshellarg('0.4.90'),
                    escapeshellarg('2.0.0-alpha.223'),
                    escapeshellarg('2.0.0-alpha.111'),
                    escapeshellarg($repoRoot.'/scripts/conformance/timers-published-artifacts.sh'),
                    escapeshellarg($resultDir),
                );

                $output = [];
                exec($command, $output, $exitCode);

                $this->assertSame(0, $exitCode, $serverImage."\n".implode("\n", $output));

                $result = json_decode(
                    file_get_contents($resultDir.'/timer-runtime-result.json') ?: '',
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );

                $this->assertSame('0.2.494', $result['artifact_versions']['server']);
                $this->assertSame($serverImage, $result['artifact_sources']['server']);
                $this->assertSame($serverImage, $result['artifact_images']['server']);
                $this->assertSame([], TimerRuntimeResultGate::evaluate($result)['gate_failures']);
            } finally {
                $this->removeDirectory($resultDir);
            }
        }
    }

    public function test_published_artifact_handoff_rejects_mismatched_or_non_exact_server_pins_before_record(): void
    {
        $repoRoot = dirname(__DIR__, 2);

        foreach ([
            'mismatched image tag' => [
                'image' => 'durableworkflow/server:0.2.493',
                'version' => '0.2.494',
                'message' => 'does not match DW_SERVER_IMAGE tag',
            ],
            'floating image tag' => [
                'image' => 'durableworkflow/server:latest',
                'version' => '0.2.494',
                'message' => 'DW_SERVER_IMAGE must use an exact patch semver tag or an image digest',
            ],
        ] as $case => $config) {
            $resultDir = sys_get_temp_dir().'/dw-timers-conformance-'.bin2hex(random_bytes(6));
            mkdir($resultDir, 0777, true);

            try {
                $command = sprintf(
                    'DW_SERVER_IMAGE=%s DW_SERVER_VERSION=%s DW_CLI_VERSION=%s DW_PYTHON_SDK_VERSION=%s DW_WORKFLOW_PHP_VERSION=%s DW_WATERLINE_VERSION=%s bash %s --result-dir %s 2>&1',
                    escapeshellarg($config['image']),
                    escapeshellarg($config['version']),
                    escapeshellarg('0.1.82'),
                    escapeshellarg('0.4.90'),
                    escapeshellarg('2.0.0-alpha.223'),
                    escapeshellarg('2.0.0-alpha.111'),
                    escapeshellarg($repoRoot.'/scripts/conformance/timers-published-artifacts.sh'),
                    escapeshellarg($resultDir),
                );

                $output = [];
                exec($command, $output, $exitCode);

                $joinedOutput = implode("\n", $output);
                $this->assertSame(2, $exitCode, $case."\n".$joinedOutput);
                $this->assertStringContainsString($config['message'], $joinedOutput, $case);
                $this->assertFileDoesNotExist($resultDir.'/timer-runtime-result.json', $case);
                $this->assertFileDoesNotExist($resultDir.'/timer-runtime-record.json', $case);
                $this->assertFileDoesNotExist($resultDir.'/timers-result.json', $case);
                $this->assertFileDoesNotExist($resultDir.'/timers-record.json', $case);
            } finally {
                $this->removeDirectory($resultDir);
            }
        }
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
        $this->assertContains('each_pass_scenario_reports_required_evidence', $resultGate['pass_requires']);
    }

    public function test_result_gate_accepts_complete_passing_timer_result(): void
    {
        $evaluation = TimerRuntimeResultGate::evaluate($this->completePassingTimerResult());

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_rejects_pass_results_without_contract_required_evidence(): void
    {
        foreach ([
            'normal_sleep_completion' => 'sleep_requested_at',
            'worker_restart_while_sleeping' => 'worker_restart_window',
            'server_restart_while_sleeping' => 'timer_state_recovered',
            'replay_after_timer_fire' => 'duplicate_timer_commands',
        ] as $scenarioId => $field) {
            $result = $this->completePassingTimerResult();
            unset($result['scenario_results'][$scenarioId]['observed_outputs'][$field]);

            $evaluation = TimerRuntimeResultGate::evaluate($result);
            $matchingFailures = array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_pass_required_evidence'
                    && ($failure['scenario_id'] ?? null) === $scenarioId
                    && ($failure['field'] ?? null) === $field,
            );

            $this->assertSame('non_passing', $evaluation['status'], $scenarioId);
            $this->assertNotEmpty($matchingFailures, $scenarioId);
        }
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
                'normal_sleep_completion' => [
                    'scenario_id' => 'normal_sleep_completion',
                    'status' => 'pass',
                    'observed_outputs' => [
                        'workflow_id' => 'timer-normal-sleep',
                        'sleep_requested_at' => '2026-06-24T10:00:00Z',
                        'wake_up_at' => '2026-06-24T10:00:10Z',
                        'completed_at' => '2026-06-24T10:00:11Z',
                        'workflow_result' => 'slept',
                    ],
                ],
                'worker_restart_while_sleeping' => [
                    'scenario_id' => 'worker_restart_while_sleeping',
                    'status' => 'pass',
                    'observed_outputs' => [
                        'workflow_id' => 'timer-worker-restart',
                        'sleep_started_at' => '2026-06-24T10:00:00Z',
                        'worker_restart_window' => [
                            'started_at' => '2026-06-24T10:00:02Z',
                            'finished_at' => '2026-06-24T10:00:04Z',
                        ],
                        'wake_up_at' => '2026-06-24T10:00:10Z',
                        'completed_at' => '2026-06-24T10:00:11Z',
                        'duplicate_resume_count' => 0,
                    ],
                ],
                'server_restart_while_sleeping' => [
                    'scenario_id' => 'server_restart_while_sleeping',
                    'status' => 'pass',
                    'observed_outputs' => [
                        'workflow_id' => 'timer-server-restart',
                        'sleep_started_at' => '2026-06-24T10:00:00Z',
                        'server_restart_window' => [
                            'started_at' => '2026-06-24T10:00:02Z',
                            'finished_at' => '2026-06-24T10:00:04Z',
                        ],
                        'wake_up_at' => '2026-06-24T10:00:10Z',
                        'completed_at' => '2026-06-24T10:00:11Z',
                        'timer_state_recovered' => true,
                    ],
                ],
                'replay_after_timer_fire' => [
                    'scenario_id' => 'replay_after_timer_fire',
                    'status' => 'pass',
                    'observed_outputs' => [
                        'workflow_id' => 'timer-replay-after-fire',
                        'timer_id' => 'timer-a',
                        'fired_at' => '2026-06-24T10:00:10Z',
                        'replay_started_at' => '2026-06-24T10:00:12Z',
                        'replayed_event_ids' => [1, 2, 3],
                        'duplicate_timer_commands' => 0,
                    ],
                ],
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
        $findings = [];
        $scenarioResults = [];
        $findingLinks = [];

        foreach (TimerRuntimeContract::manifest()['required_scenarios'] as $scenarioId) {
            $findingId = 'timer-'.str_replace('_', '-', $scenarioId).'-runner-gap';
            $findings[] = [
                'id' => $findingId,
                'finding_type' => 'conformance_runner_coverage_gap',
                'classification' => 'runner-gap',
                'scenario_id' => $scenarioId,
                'owning_surface' => 'conformance_harness',
                'summary' => "No timer host runner exists to prove $scenarioId against published artifacts.",
                'next_acceptance_criterion' => "Add a host runner shard that records timer runtime evidence for $scenarioId.",
            ];
            $scenarioResults[$scenarioId] = [
                'scenario_id' => $scenarioId,
                'status' => 'runner_blocked',
                'linked_findings' => [
                    ['finding_id' => $findingId, 'finding_type' => 'conformance_runner_coverage_gap'],
                ],
                'observed_outputs' => [
                    'blocked_reason' => 'timer_host_runner_missing',
                ],
            ];
            $findingLinks[$scenarioId] = [$findingId];
        }

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
            'scenario_results' => $scenarioResults,
            'findings' => $findings,
            'finding_links' => $findingLinks,
        ];
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.'/'.$entry;
            if (is_dir($child)) {
                $this->removeDirectory($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}
