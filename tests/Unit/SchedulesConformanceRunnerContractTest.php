<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SchedulesRuntimeContract;
use PHPUnit\Framework\TestCase;

final class SchedulesConformanceRunnerContractTest extends TestCase
{
    public function test_published_artifact_runner_writes_focused_not_covered_findings(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the schedules runner result builder.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir().'/dw-schedules-runner-'.bin2hex(random_bytes(4));
        mkdir($resultDir);

        try {
            $process = proc_open(
                [$nodeBinary, $repoRoot.'/scripts/conformance/schedules-published-artifacts.mjs'],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                [
                    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                    'DW_SCHEDULES_RESULT_DIR' => $resultDir,
                    'DW_SCHEDULES_REPO_ROOT' => $repoRoot,
                    'DW_SERVER_VERSION' => '0.2.244',
                    'DW_CLI_VERSION' => '0.1.75',
                    'DW_PYTHON_SDK_VERSION' => '0.4.84',
                    'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.189',
                    'DW_WATERLINE_VERSION' => '2.0.0-alpha.77',
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, $stderr."\n".$stdout);

            $resultPath = $resultDir.'/schedules-runtime-result.json';
            $recordPath = $resultDir.'/schedules-runtime-record.json';
            $publishedArtifactsPath = $resultDir.'/published-artifacts.json';

            $this->assertFileExists($resultPath);
            $this->assertFileExists($recordPath);
            $this->assertFileExists($publishedArtifactsPath);

            $result = json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
            $record = json_decode((string) file_get_contents($recordPath), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(SchedulesRuntimeContract::RESULT_SCHEMA, $result['schema']);
            $this->assertSame('non_passing', $result['outcome']);
            $this->assertFalse($result['runner_blocked']);
            $this->assertSame('0.2.244', $result['artifact_versions']['server']);
            $this->assertSame('0.1.75', $record['artifactVersions']['cli']);

            $requiredScenarios = SchedulesRuntimeContract::manifest()['required_scenarios'];
            $this->assertSame($requiredScenarios, array_keys($result['scenario_results']));

            foreach ($requiredScenarios as $scenarioId) {
                $scenario = $result['scenario_results'][$scenarioId];
                $this->assertSame($scenarioId, $scenario['scenario_id']);
                $this->assertSame('not_covered', $scenario['status']);
                $this->assertNotEmpty($scenario['linked_findings']);
                $this->assertSame(
                    'conformance_runner_coverage_gap',
                    $scenario['linked_findings'][0]['finding_type'],
                );
                $this->assertSame($scenarioId, $scenario['linked_findings'][0]['scenario_id']);
                $this->assertNotEmpty($scenario['linked_findings'][0]['next_acceptance_criterion']);
            }

            $this->assertSame(
                'schedules-cron-cadence-coverage',
                $result['scenario_results']['cron_cadence']['linked_findings'][0]['finding_id'],
            );
            $this->assertSame(
                'cli',
                $result['scenario_results']['cli_schedule_surface']['linked_findings'][0]['owning_surface'],
            );
            $this->assertSame(
                'workflow-php',
                $result['scenario_results']['php_schedule_surface']['linked_findings'][0]['owning_surface'],
            );
            $this->assertSame(
                'server',
                $result['scenario_results']['invalid_cron_refusal']['linked_findings'][0]['owning_surface'],
            );
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_runner_promotes_supplied_python_smoke_evidence_without_passing_uncovered_cells(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the schedules runner result builder.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir().'/dw-schedules-runner-'.bin2hex(random_bytes(4));
        mkdir($resultDir);
        file_put_contents($resultDir.'/schedules-smoke-evidence.json', json_encode([
            'python_schedule_lifecycle_smoke' => [
                'passed' => true,
                'create' => true,
                'list' => true,
                'describe' => true,
                'pause' => true,
                'resume' => true,
                'trigger' => true,
                'delete' => true,
                'triggered_workflow_completed' => true,
                'invalid_cron_refused' => true,
                'invalid_cron_typed_error' => true,
                'invalid_cron_persisted' => false,
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $process = proc_open(
                [$nodeBinary, $repoRoot.'/scripts/conformance/schedules-published-artifacts.mjs'],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                [
                    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                    'DW_SCHEDULES_RESULT_DIR' => $resultDir,
                    'DW_SCHEDULES_REPO_ROOT' => $repoRoot,
                    'DW_SERVER_VERSION' => '0.2.244',
                    'DW_CLI_VERSION' => '0.1.75',
                    'DW_PYTHON_SDK_VERSION' => '0.4.84',
                    'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.189',
                    'DW_WATERLINE_VERSION' => '2.0.0-alpha.77',
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, $stderr."\n".$stdout);

            $result = json_decode(
                (string) file_get_contents($resultDir.'/schedules-runtime-result.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertSame('pass', $result['scenario_results']['python_sdk_schedule_surface']['status']);
            $this->assertSame('pass', $result['scenario_results']['invalid_cron_refusal']['status']);
            $this->assertSame('not_covered', $result['scenario_results']['cron_cadence']['status']);
            $this->assertSame('not_covered', $result['scenario_results']['php_created_python_workflow']['status']);
            $this->assertSame([], $result['scenario_results']['python_sdk_schedule_surface']['linked_findings']);
            $this->assertTrue(
                $result['scenario_results']['invalid_cron_refusal']['observed_outputs']['persisted'] === false,
            );
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_runner_preserves_supplied_cadence_pass_and_fail_cells(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the schedules runner result builder.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir().'/dw-schedules-runner-'.bin2hex(random_bytes(4));
        mkdir($resultDir);

        $cronObservation = [
            'schedule_id' => 'cadence-cron',
            'cron_expression' => '* * * * *',
            'actual_fire_timestamps' => [
                '2026-06-04T10:00:03Z',
                '2026-06-04T10:01:04Z',
                '2026-06-04T10:02:03Z',
                '2026-06-04T10:03:05Z',
            ],
            'nominal_fire_timestamps' => [
                '2026-06-04T10:00:00Z',
                '2026-06-04T10:01:00Z',
                '2026-06-04T10:02:00Z',
                '2026-06-04T10:03:00Z',
            ],
            'drift_ms' => [3000, 4000, 3000, 5000],
        ];

        $fixedRateObservation = [
            'schedule_id' => 'cadence-fixed',
            'interval' => 'PT30S',
            'actual_fire_timestamps' => [
                '2026-06-04T10:00:35Z',
                '2026-06-04T10:01:05Z',
            ],
            'nominal_fire_timestamps' => [
                '2026-06-04T10:00:30Z',
                '2026-06-04T10:01:00Z',
            ],
            'drift_ms' => [5000, 5000],
            'observed_fire_count' => 2,
        ];

        file_put_contents($resultDir.'/cadence-evidence.json', json_encode([
            'scenario_results' => [
                'cron_cadence' => [
                    'status' => 'pass',
                    'observed_outputs' => $cronObservation,
                    'linked_findings' => [],
                ],
                'fixed_rate_cadence' => [
                    'status' => 'fail',
                    'observed_outputs' => $fixedRateObservation,
                    'linked_findings' => [[
                        'finding_id' => 'schedules-fixed-rate-cadence-finding',
                        'scenario_id' => 'fixed_rate_cadence',
                        'finding_type' => 'schedule_cadence_contract_gap',
                        'owning_surface' => 'server',
                        'observed_behavior' => 'observed 2 fires; expected at least 8',
                        'expected_behavior' => 'PT30S fixed-rate schedule fires eight times.',
                        'next_acceptance_criterion' => 'observe at least eight PT30S fires',
                    ]],
                ],
            ],
            'cadence_observations' => [
                'cron' => $cronObservation,
                'fixed_rate' => $fixedRateObservation,
            ],
            'runtime_matrix' => [
                'schedule_types' => ['cron_expression', 'fixed_rate_interval'],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $process = proc_open(
                [$nodeBinary, $repoRoot.'/scripts/conformance/schedules-published-artifacts.mjs'],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                [
                    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                    'DW_SCHEDULES_RESULT_DIR' => $resultDir,
                    'DW_SCHEDULES_REPO_ROOT' => $repoRoot,
                    'DW_SCHEDULES_CADENCE_EVIDENCE' => $resultDir.'/cadence-evidence.json',
                    'DW_SERVER_VERSION' => '0.2.283',
                    'DW_CLI_VERSION' => '0.1.76',
                    'DW_PYTHON_SDK_VERSION' => '0.4.85',
                    'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.195',
                    'DW_WATERLINE_VERSION' => '2.0.0-alpha.81',
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, $stderr."\n".$stdout);

            $result = json_decode(
                (string) file_get_contents($resultDir.'/schedules-runtime-result.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('pass', $result['scenario_results']['cron_cadence']['status']);
            $this->assertSame('fail', $result['scenario_results']['fixed_rate_cadence']['status']);
            $this->assertSame(
                $cronObservation['nominal_fire_timestamps'],
                $result['scenario_results']['cron_cadence']['observed_outputs']['nominal_fire_timestamps'],
            );
            $this->assertSame(
                'schedule_cadence_contract_gap',
                $result['scenario_results']['fixed_rate_cadence']['linked_findings'][0]['finding_type'],
            );
            $this->assertSame(
                $fixedRateObservation['actual_fire_timestamps'],
                $result['cadence_observations']['fixed_rate']['actual_fire_timestamps'],
            );
            $this->assertSame('not_covered', $result['scenario_results']['restart_survival']['status']);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_runner_preserves_supplied_operator_controls_cells(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the schedules runner result builder.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir().'/dw-schedules-runner-'.bin2hex(random_bytes(4));
        mkdir($resultDir);

        $listDescribe = [
            'schedule_ids' => ['operator-cron', 'operator-fixed-rate'],
            'public_api_list_observed' => true,
            'public_api_describe_observed' => true,
            'cli_list_observed' => true,
            'sdk_list_observed' => true,
            'cron_or_interval_observed' => true,
            'last_fire_at_observed' => true,
            'next_fire_at_observed' => true,
            'pause_state_observed' => true,
            'verdict' => 'pass',
        ];
        $pauseResume = [
            'schedule_id' => 'operator-fixed-rate',
            'pause_window_seconds' => 125,
            'fires_during_pause_count' => 0,
            'resumed_after_pause' => true,
            'post_resume_normal_fire_observed' => true,
            'catchup_after_resume_count' => 0,
            'verdict' => 'pass',
        ];
        $delete = [
            'schedule_id' => 'operator-fixed-rate',
            'observation_window_seconds' => 65,
            'absent_from_list_after_delete' => true,
            'absent_from_describe_after_delete' => true,
            'fires_after_delete_count' => 0,
            'no_fires_after_delete' => true,
            'verdict' => 'pass',
        ];

        file_put_contents($resultDir.'/operator-controls-evidence.json', json_encode([
            'schema' => 'durable-workflow.v2.schedules-runtime.operator-controls-evidence',
            'scenario_results' => [
                'list_describe_visibility' => [
                    'scenario_id' => 'list_describe_visibility',
                    'status' => 'pass',
                    'observed_outputs' => $listDescribe,
                    'linked_findings' => [],
                ],
                'pause_resume_no_fire_window' => [
                    'scenario_id' => 'pause_resume_no_fire_window',
                    'status' => 'pass',
                    'observed_outputs' => $pauseResume,
                    'linked_findings' => [],
                ],
                'delete_stops_future_fires' => [
                    'scenario_id' => 'delete_stops_future_fires',
                    'status' => 'pass',
                    'observed_outputs' => $delete,
                    'linked_findings' => [],
                ],
            ],
            'operator_controls' => [
                'list_describe' => $listDescribe,
                'pause_resume' => $pauseResume,
                'delete' => $delete,
            ],
            'runtime_matrix' => [
                'client_paths' => ['server-http-api', 'cli', 'sdk-python'],
                'schedule_types' => ['cron_expression', 'fixed_rate_interval'],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $process = proc_open(
                [$nodeBinary, $repoRoot.'/scripts/conformance/schedules-published-artifacts.mjs'],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                [
                    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                    'DW_SCHEDULES_RESULT_DIR' => $resultDir,
                    'DW_SCHEDULES_REPO_ROOT' => $repoRoot,
                    'DW_SCHEDULES_OPERATOR_CONTROLS_EVIDENCE' => $resultDir.'/operator-controls-evidence.json',
                    'DW_SERVER_VERSION' => '0.2.305',
                    'DW_CLI_VERSION' => '0.1.77',
                    'DW_PYTHON_SDK_VERSION' => '0.4.85',
                    'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.197',
                    'DW_WATERLINE_VERSION' => '2.0.0-alpha.83',
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, $stderr."\n".$stdout);

            $result = json_decode(
                (string) file_get_contents($resultDir.'/schedules-runtime-result.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('pass', $result['scenario_results']['list_describe_visibility']['status']);
            $this->assertSame('pass', $result['scenario_results']['pause_resume_no_fire_window']['status']);
            $this->assertSame('pass', $result['scenario_results']['delete_stops_future_fires']['status']);
            $this->assertSame(
                0,
                $result['scenario_results']['pause_resume_no_fire_window']['observed_outputs']['fires_during_pause_count'],
            );
            $this->assertTrue(
                $result['operator_controls']['delete']['absent_from_list_after_delete'],
            );
            $this->assertSame('not_covered', $result['scenario_results']['missed_fire_policy']['status']);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_runner_promotes_supplied_cli_surface_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the schedules runner result builder.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir().'/dw-schedules-runner-'.bin2hex(random_bytes(4));
        mkdir($resultDir);

        $scheduleId = 'cli-surface-schedule';
        $commandOutputs = [
            'create' => $this->cliTranscript(
                ['dw', 'schedules', 'create', '--schedule-id='.$scheduleId, '--json'],
                ['schedule_id' => $scheduleId],
            ),
            'describe' => $this->cliTranscript(
                ['dw', 'schedules', 'describe', $scheduleId, '--json'],
                ['schedule_id' => $scheduleId, 'state' => ['paused' => true]],
            ),
            'list' => $this->cliTranscript(
                ['dw', 'schedules', 'list', '--json'],
                ['schedules' => [['schedule_id' => $scheduleId, 'paused' => true]]],
            ),
            'pause' => $this->cliTranscript(
                ['dw', 'schedules', 'pause', $scheduleId, '--json'],
                ['schedule_id' => $scheduleId],
            ),
            'resume' => $this->cliTranscript(
                ['dw', 'schedules', 'resume', $scheduleId, '--json'],
                ['schedule_id' => $scheduleId],
            ),
            'trigger' => $this->cliTranscript(
                ['dw', 'schedules', 'trigger', $scheduleId, '--json'],
                ['schedule_id' => $scheduleId, 'outcome' => 'started'],
            ),
            'delete' => $this->cliTranscript(
                ['dw', 'schedules', 'delete', $scheduleId, '--json'],
                ['schedule_id' => $scheduleId],
            ),
        ];
        $observedOutputs = [
            'create_or_observe' => true,
            'list_observed' => true,
            'describe_observed' => true,
            'control_observed' => true,
            'schedule_id' => $scheduleId,
            'command_outputs' => $commandOutputs,
            'failed_commands' => [],
            'unsupported_commands' => [],
        ];

        file_put_contents($resultDir.'/cli-evidence.json', json_encode([
            'schema' => 'durable-workflow.v2.schedules-runtime.cli-surface-evidence',
            'scenario_results' => [
                'cli_schedule_surface' => [
                    'scenario_id' => 'cli_schedule_surface',
                    'status' => 'pass',
                    'observed_outputs' => $observedOutputs,
                    'linked_findings' => [],
                ],
            ],
            'client_surfaces' => [
                'cli' => $observedOutputs,
            ],
            'runtime_matrix' => [
                'client_paths' => ['cli'],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $process = proc_open(
                [$nodeBinary, $repoRoot.'/scripts/conformance/schedules-published-artifacts.mjs'],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                [
                    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                    'DW_SCHEDULES_RESULT_DIR' => $resultDir,
                    'DW_SCHEDULES_REPO_ROOT' => $repoRoot,
                    'DW_SCHEDULES_CLI_EVIDENCE' => $resultDir.'/cli-evidence.json',
                    'DW_SERVER_VERSION' => '0.2.288',
                    'DW_CLI_VERSION' => '0.1.77',
                    'DW_PYTHON_SDK_VERSION' => '0.4.85',
                    'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.196',
                    'DW_WATERLINE_VERSION' => '2.0.0-alpha.82',
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, $stderr."\n".$stdout);

            $result = json_decode(
                (string) file_get_contents($resultDir.'/schedules-runtime-result.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $cliScenario = $result['scenario_results']['cli_schedule_surface'];
            $this->assertSame('pass', $cliScenario['status']);
            $this->assertTrue($cliScenario['observed_outputs']['create_or_observe']);
            $this->assertTrue($cliScenario['observed_outputs']['list_observed']);
            $this->assertTrue($cliScenario['observed_outputs']['control_observed']);
            $this->assertSame(0, $cliScenario['observed_outputs']['command_outputs']['delete']['exit_code']);
            $this->assertSame($scheduleId, $result['client_surfaces']['cli']['schedule_id']);
            $this->assertContains('cli', $result['runtime_matrix']['client_paths']);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    /**
     * @param list<string> $command
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function cliTranscript(array $command, array $payload): array
    {
        return [
            'command' => $command,
            'exit_code' => 0,
            'stdout' => json_encode($payload, JSON_THROW_ON_ERROR)."\n",
            'stderr' => '',
            'parsed_json' => $payload,
        ];
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($path);
    }
}
