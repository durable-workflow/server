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
