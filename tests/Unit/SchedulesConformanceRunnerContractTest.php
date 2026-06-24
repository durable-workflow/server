<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SchedulesRuntimeContract;
use PHPUnit\Framework\TestCase;

final class SchedulesConformanceRunnerContractTest extends TestCase
{
    public function test_python_lifecycle_shard_uses_payload_envelope_for_manual_completion(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $runner = (string) file_get_contents($repoRoot.'/scripts/conformance/schedules-published-artifacts.mjs');
        $lifecycleStart = strpos($runner, 'function schedulesPythonLifecycleScript()');
        $lifecycleEnd = strpos($runner, 'function schedulesPythonWorkerScript()', $lifecycleStart ?: 0);

        $this->assertIsInt($lifecycleStart);
        $this->assertIsInt($lifecycleEnd);
        $lifecycleShard = substr($runner, $lifecycleStart, $lifecycleEnd - $lifecycleStart);

        $this->assertStringContainsString(
            'from durable_workflow import Client, ScheduleAction, ScheduleSpec, serializer',
            $lifecycleShard,
        );
        $this->assertStringContainsString('"result": serializer.envelope({', $lifecycleShard);
        $this->assertStringNotContainsString('"result": json.dumps({', $lifecycleShard);
    }

    public function test_published_artifact_runner_requires_supplied_install_evidence_for_install_cell_pass(): void
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
            $this->assertNull($result['local_product_source_checkouts_used']);
            $this->assertSame('not_exercised', $result['artifact_sources']['server']);
            $this->assertSame('not_exercised', $result['artifact_sources']['cli']);
            $this->assertSame('not_exercised', $result['artifact_sources']['sdk-python']);
            $this->assertSame('not_exercised', $result['artifact_sources']['workflow-php']);
            $this->assertSame('not_exercised', $result['artifact_sources']['waterline']);
            $this->assertSame($result['artifact_sources'], $record['artifactSources']);

            $requiredScenarios = SchedulesRuntimeContract::manifest()['required_scenarios'];
            $this->assertSame($requiredScenarios, array_keys($result['scenario_results']));
            $this->assertSame('not_covered', $result['scenario_results']['published_artifact_install_only']['status']);
            $this->assertNotEmpty($result['scenario_results']['published_artifact_install_only']['linked_findings']);
            $this->assertFalse(
                $result['scenario_results']['published_artifact_install_only']['observed_outputs']['published_install_tuple_proven'],
            );
            $this->assertNull(
                $result['scenario_results']['published_artifact_install_only']['observed_outputs']['local_product_source_checkouts_used'],
            );
            $this->assertSame(
                ['server', 'cli', 'sdk-python', 'workflow-php', 'waterline'],
                array_column(
                    $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifacts'],
                    'artifact',
                ),
            );
            $this->assertSame(
                'not_covered',
                $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifacts'][0]['status'],
            );
            $this->assertSame(
                'not_exercised',
                $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_sources']['waterline'],
            );

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

    public function test_published_artifact_runner_passes_install_cell_with_supplied_install_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the schedules runner result builder.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir().'/dw-schedules-runner-'.bin2hex(random_bytes(4));
        mkdir($resultDir);
        $installEvidencePath = $resultDir.'/schedules-artifact-install-evidence.json';
        file_put_contents($installEvidencePath, json_encode([
            'schema' => 'durable-workflow.v2.schedules-runtime.artifact-install-evidence',
            'local_product_source_checkouts_used' => false,
            'artifacts' => [
                ['artifact' => 'server', 'version' => '0.2.244', 'source' => 'docker://durableworkflow/server:0.2.244', 'status' => 'pass'],
                ['artifact' => 'cli', 'version' => '0.1.75', 'source' => 'https://github.com/durable-workflow/cli/releases/download/0.1.75/dw.phar', 'status' => 'pass'],
                ['artifact' => 'sdk-python', 'version' => '0.4.84', 'source' => 'pypi://durable-workflow==0.4.84', 'status' => 'pass'],
                ['artifact' => 'workflow-php', 'version' => '2.0.0-alpha.189', 'source' => 'packagist://durable-workflow/workflow@2.0.0-alpha.189', 'status' => 'pass'],
                ['artifact' => 'waterline', 'version' => '2.0.0-alpha.77', 'source' => 'packagist://durable-workflow/waterline@2.0.0-alpha.77', 'status' => 'pass'],
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
                    'DW_SCHEDULES_ARTIFACT_INSTALL_EVIDENCE' => $installEvidencePath,
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
            $record = json_decode(
                (string) file_get_contents($resultDir.'/schedules-runtime-record.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $installScenario = $result['scenario_results']['published_artifact_install_only'];
            $this->assertSame('pass', $installScenario['status']);
            $this->assertSame([], $installScenario['linked_findings']);
            $this->assertTrue($installScenario['observed_outputs']['published_install_tuple_proven']);
            $this->assertTrue($installScenario['observed_outputs']['supplied_install_evidence']);
            $this->assertFalse($installScenario['observed_outputs']['local_product_source_checkouts_used']);
            $this->assertSame(
                ['pass', 'pass', 'pass', 'pass', 'pass'],
                array_column($installScenario['observed_outputs']['artifacts'], 'status'),
            );
            $this->assertSame('docker://durableworkflow/server:0.2.244', $result['artifact_sources']['server']);
            $this->assertSame(
                'packagist://durable-workflow/workflow@2.0.0-alpha.189',
                $record['artifactSources']['workflow-php'],
            );
            $this->assertSame(
                $installScenario['observed_outputs'],
                $result['artifact_install_evidence'],
            );
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_runner_reads_default_install_evidence_and_rejects_non_passing_entries(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the schedules runner result builder.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir().'/dw-schedules-runner-'.bin2hex(random_bytes(4));
        mkdir($resultDir);
        $installEvidencePath = $resultDir.'/artifact-install-evidence.json';
        file_put_contents($installEvidencePath, json_encode([
            'schema' => 'durable-workflow.v2.schedules-runtime.artifact-install-evidence',
            'local_product_source_checkouts_used' => false,
            'artifacts' => [
                ['artifact' => 'server', 'version' => '0.2.323', 'source' => 'docker://durableworkflow/server:0.2.323', 'status' => 'pass'],
                ['artifact' => 'cli', 'version' => '0.1.77', 'source' => 'https://github.com/durable-workflow/cli/releases/download/0.1.77/dw.phar', 'status' => 'fail'],
                ['artifact' => 'sdk-python', 'version' => '0.4.85', 'source' => 'pypi://durable-workflow==0.4.85', 'status' => 'pass'],
                ['artifact' => 'workflow-php', 'version' => '2.0.0-alpha.197', 'source' => 'packagist://durable-workflow/workflow@2.0.0-alpha.197', 'status' => 'pass'],
                ['artifact' => 'waterline', 'version' => '2.0.0-alpha.83', 'source' => 'packagist://durable-workflow/waterline@2.0.0-alpha.83', 'status' => 'pass'],
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
                    'DW_SERVER_VERSION' => '0.2.323',
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
            $record = json_decode(
                (string) file_get_contents($resultDir.'/schedules-runtime-record.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $publishedArtifacts = json_decode(
                (string) file_get_contents($resultDir.'/published-artifacts.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $installScenario = $result['scenario_results']['published_artifact_install_only'];
            $this->assertSame('not_covered', $installScenario['status']);
            $this->assertTrue($result['artifact_install_evidence']['supplied_install_evidence']);
            $this->assertSame($installEvidencePath, $result['artifact_install_evidence']['supplied_install_evidence_path']);
            $this->assertSame('fail', $result['artifact_install_evidence']['non_passing_artifacts']['cli']);
            $this->assertSame($result['artifact_install_evidence'], $record['artifactInstallEvidence']);
            $this->assertSame($result['artifact_install_evidence'], $publishedArtifacts['artifact_install_evidence']);
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
                'invalid_cron_public_persistence_checked' => true,
                'invalid_cron_public_persistence' => [
                    'public_list_checked' => true,
                    'list_contains_invalid_schedule' => false,
                    'public_describe_checked' => true,
                    'describe_found' => false,
                    'describe_status' => 404,
                ],
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
                $result['scenario_results']['python_sdk_schedule_surface']['observed_outputs']['manual_trigger_observed'],
            );
            $this->assertTrue(
                $result['scenario_results']['python_sdk_schedule_surface']['observed_outputs']['triggered_workflow_completion_observed'],
            );
            $this->assertTrue(
                $result['scenario_results']['invalid_cron_refusal']['observed_outputs']['persisted'] === false,
            );
            $this->assertTrue(
                $result['scenario_results']['invalid_cron_refusal']['observed_outputs']['public_persistence_checked'],
            );
            $this->assertFalse(
                $result['scenario_results']['invalid_cron_refusal']['observed_outputs']['list_contains_invalid_schedule'],
            );
            $this->assertSame(
                404,
                $result['scenario_results']['invalid_cron_refusal']['observed_outputs']['describe_status'],
            );
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_runner_rejects_partial_python_smoke_without_completion_and_public_persistence_proof(): void
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
                'triggered_workflow_completed' => false,
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

            $this->assertSame('not_covered', $result['scenario_results']['python_sdk_schedule_surface']['status']);
            $this->assertSame('not_covered', $result['scenario_results']['invalid_cron_refusal']['status']);
            $this->assertNotEmpty($result['scenario_results']['python_sdk_schedule_surface']['linked_findings']);
            $this->assertNotEmpty($result['scenario_results']['invalid_cron_refusal']['linked_findings']);
            $this->assertSame(
                'not_covered',
                $result['scenario_results']['python_sdk_schedule_surface']['observed_outputs']['coverage_status'],
            );
            $this->assertSame(
                'not_covered',
                $result['scenario_results']['invalid_cron_refusal']['observed_outputs']['coverage_status'],
            );
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_runner_promotes_published_artifact_install_evidence_when_sources_are_recorded(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the schedules runner result builder.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir().'/dw-schedules-runner-'.bin2hex(random_bytes(4));
        mkdir($resultDir);
        $installEvidencePath = $resultDir.'/schedules-artifact-install-evidence.json';
        file_put_contents($installEvidencePath, json_encode([
            'schema' => 'durable-workflow.v2.schedules-runtime.artifact-install-evidence',
            'local_product_source_checkouts_used' => false,
            'artifacts' => [
                ['artifact' => 'server', 'version' => '0.2.307', 'source' => 'docker://durableworkflow/server:0.2.307', 'status' => 'pass'],
                ['artifact' => 'cli', 'version' => '0.1.77', 'source' => 'https://github.com/durable-workflow/cli/releases/download/0.1.77/dw.phar', 'status' => 'pass'],
                ['artifact' => 'sdk-python', 'version' => '0.4.85', 'source' => 'pypi://durable-workflow==0.4.85', 'status' => 'pass'],
                ['artifact' => 'workflow-php', 'version' => '2.0.0-alpha.197', 'source' => 'packagist://durable-workflow/workflow@2.0.0-alpha.197', 'status' => 'pass'],
                ['artifact' => 'waterline', 'version' => '2.0.0-alpha.83', 'source' => 'packagist://durable-workflow/waterline@2.0.0-alpha.83', 'status' => 'pass'],
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
                    'DW_SCHEDULES_ARTIFACT_INSTALL_EVIDENCE' => $installEvidencePath,
                    'DW_SERVER_VERSION' => '0.2.307',
                    'DW_CLI_VERSION' => '0.1.77',
                    'DW_PYTHON_SDK_VERSION' => '0.4.85',
                    'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.197',
                    'DW_WATERLINE_VERSION' => '2.0.0-alpha.83',
                    'DW_SERVER_ARTIFACT_SOURCE' => 'docker://durableworkflow/server:0.2.307',
                    'DW_CLI_ARTIFACT_SOURCE' => 'https://github.com/durable-workflow/cli/releases/download/0.1.77/dw.phar',
                    'DW_PYTHON_SDK_ARTIFACT_SOURCE' => 'pypi://durable-workflow==0.4.85',
                    'DW_WORKFLOW_PHP_ARTIFACT_SOURCE' => 'packagist://durable-workflow/workflow@2.0.0-alpha.197',
                    'DW_WATERLINE_ARTIFACT_SOURCE' => 'packagist://durable-workflow/waterline@2.0.0-alpha.83',
                    'DW_SCHEDULES_LOCAL_PRODUCT_SOURCE_CHECKOUTS_USED' => 'false',
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

            $scenario = $result['scenario_results']['published_artifact_install_only'];
            $this->assertSame('pass', $scenario['status']);
            $this->assertSame([], $scenario['linked_findings']);
            $this->assertFalse($result['local_product_source_checkouts_used']);
            $this->assertSame('0.2.307', $scenario['observed_outputs']['artifacts']['server']['version']);
            $this->assertSame(
                'https://github.com/durable-workflow/cli/releases/download/0.1.77/dw.phar',
                $scenario['observed_outputs']['artifacts']['cli']['source'],
            );
            $this->assertSame(
                'packagist://durable-workflow/workflow@2.0.0-alpha.197',
                $scenario['observed_outputs']['artifacts']['workflow-php']['source'],
            );
            $this->assertSame(
                'packagist://durable-workflow/waterline@2.0.0-alpha.83',
                $scenario['observed_outputs']['artifacts']['waterline']['source'],
            );
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_runner_derives_install_evidence_from_source_free_shard_and_published_sources(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the schedules runner result builder.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir().'/dw-schedules-runner-'.bin2hex(random_bytes(4));
        mkdir($resultDir);
        file_put_contents($resultDir.'/schedules-smoke-evidence.json', json_encode([
            'schema' => 'durable-workflow.v2.schedules-runtime.cadence-evidence',
            'local_product_source_checkouts_used' => false,
            'cadence_observations' => [
                'cron' => [
                    'schedule_id' => 'cadence-cron',
                    'actual_fire_timestamps' => [
                        '2026-06-04T10:00:03Z',
                        '2026-06-04T10:01:03Z',
                        '2026-06-04T10:02:03Z',
                        '2026-06-04T10:03:03Z',
                    ],
                    'nominal_fire_timestamps' => [
                        '2026-06-04T10:00:00Z',
                        '2026-06-04T10:01:00Z',
                        '2026-06-04T10:02:00Z',
                        '2026-06-04T10:03:00Z',
                    ],
                    'drift_ms' => [3000, 3000, 3000, 3000],
                ],
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
                    'DW_SERVER_VERSION' => '0.2.312',
                    'DW_CLI_VERSION' => '0.1.77',
                    'DW_PYTHON_SDK_VERSION' => '0.4.85',
                    'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.197',
                    'DW_WATERLINE_VERSION' => '2.0.0-alpha.83',
                    'DW_SERVER_ARTIFACT_SOURCE' => 'published_docker_image',
                    'DW_CLI_ARTIFACT_SOURCE' => 'official_install_script',
                    'DW_PYTHON_SDK_ARTIFACT_SOURCE' => 'pypi',
                    'DW_WORKFLOW_PHP_ARTIFACT_SOURCE' => 'composer_packagist',
                    'DW_WATERLINE_ARTIFACT_SOURCE' => 'published_waterline_artifact',
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
            $publishedArtifacts = json_decode(
                (string) file_get_contents($resultDir.'/published-artifacts.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $scenario = $result['scenario_results']['published_artifact_install_only'];
            $installEvidence = $result['artifact_install_evidence'];
            $scenarioInstallEvidence = $scenario['observed_outputs']['artifact_install_evidence'];

            $this->assertSame('pass', $scenario['status']);
            $this->assertSame([], $scenario['linked_findings']);
            $this->assertFalse($result['local_product_source_checkouts_used']);
            $this->assertFalse($installEvidence['supplied_install_evidence']);
            $this->assertTrue($installEvidence['derived_install_evidence']);
            $this->assertFalse($scenarioInstallEvidence['supplied_install_evidence']);
            $this->assertTrue($scenarioInstallEvidence['derived_install_evidence']);
            $this->assertSame(
                ['pass', 'pass', 'pass', 'pass', 'pass'],
                array_column($installEvidence['artifacts'], 'status'),
            );
            $this->assertFalse($publishedArtifacts['local_product_source_checkouts_used']);
            $this->assertSame(
                'docker://durableworkflow/server:0.2.312',
                $publishedArtifacts['artifact_install_evidence']['artifact_sources']['server'],
            );
            $this->assertSame(
                'https://github.com/durable-workflow/cli/releases/download/0.1.77/install.sh',
                $publishedArtifacts['artifact_install_evidence']['artifact_sources']['cli'],
            );
            $this->assertSame(
                'pypi://durable-workflow==0.4.85',
                $publishedArtifacts['artifact_install_evidence']['artifact_sources']['sdk-python'],
            );
            $this->assertSame(
                'packagist://durable-workflow/workflow@2.0.0-alpha.197',
                $publishedArtifacts['artifact_install_evidence']['artifact_sources']['workflow-php'],
            );
            $this->assertSame(
                'packagist://durable-workflow/waterline@2.0.0-alpha.83',
                $publishedArtifacts['artifact_install_evidence']['artifact_sources']['waterline'],
            );
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_runner_requires_explicit_no_local_source_evidence_before_install_promotion(): void
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
                    'DW_SERVER_VERSION' => '0.2.307',
                    'DW_CLI_VERSION' => '0.1.77',
                    'DW_PYTHON_SDK_VERSION' => '0.4.85',
                    'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.197',
                    'DW_WATERLINE_VERSION' => '2.0.0-alpha.83',
                    'DW_SERVER_ARTIFACT_SOURCE' => 'published_docker_image',
                    'DW_CLI_ARTIFACT_SOURCE' => 'official_install_script',
                    'DW_PYTHON_SDK_ARTIFACT_SOURCE' => 'pypi',
                    'DW_WORKFLOW_PHP_ARTIFACT_SOURCE' => 'composer_packagist',
                    'DW_WATERLINE_ARTIFACT_SOURCE' => 'published_waterline_artifact',
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

            $scenario = $result['scenario_results']['published_artifact_install_only'];
            $this->assertSame('not_covered', $scenario['status']);
            $this->assertNull($result['local_product_source_checkouts_used']);
            $this->assertNotEmpty($scenario['linked_findings']);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_runner_uses_source_free_shard_evidence_for_install_local_source_policy(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the schedules runner result builder.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir().'/dw-schedules-runner-'.bin2hex(random_bytes(4));
        mkdir($resultDir);
        file_put_contents($resultDir.'/schedules-smoke-evidence.json', json_encode([
            'schema' => 'durable-workflow.v2.schedules-runtime.cadence-evidence',
            'local_product_source_checkouts_used' => false,
            'scenario_results' => [
                'cron_cadence' => [
                    'status' => 'fail',
                    'observed_outputs' => [
                        'failure_reason' => 'published server did not become ready',
                        'observed_fire_count' => 0,
                    ],
                    'linked_findings' => [],
                ],
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
                    'DW_SERVER_VERSION' => '0.2.332',
                    'DW_CLI_VERSION' => '0.1.77',
                    'DW_PYTHON_SDK_VERSION' => '0.4.85',
                    'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.198',
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
            $installEvidence = $result['scenario_results']['published_artifact_install_only']['observed_outputs'];

            $this->assertSame('not_covered', $result['scenario_results']['published_artifact_install_only']['status']);
            $this->assertFalse($result['local_product_source_checkouts_used']);
            $this->assertFalse($installEvidence['local_product_source_checkouts_used']);
            $this->assertTrue($installEvidence['local_product_source_checkouts_explicitly_false']);
            $this->assertNotContains(
                'artifact_install_evidence.local_product_source_checkouts_used=false missing',
                $installEvidence['policy_failures'],
            );
            $this->assertContains('server.artifact_install_evidence.source missing', $installEvidence['policy_failures']);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_runner_derives_install_evidence_from_published_sources_and_explicit_source_free_env(): void
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
                    'DW_SERVER_IMAGE' => 'ghcr.io/durable-workflow/server:0.2.323',
                    'DW_SERVER_VERSION' => '0.2.323',
                    'DW_CLI_VERSION' => '0.1.77',
                    'DW_PYTHON_SDK_VERSION' => '0.4.85',
                    'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.197',
                    'DW_WATERLINE_VERSION' => '2.0.0-alpha.83',
                    'DW_SERVER_ARTIFACT_SOURCE' => 'published_docker_image',
                    'DW_CLI_ARTIFACT_SOURCE' => 'official_install_script',
                    'DW_PYTHON_SDK_ARTIFACT_SOURCE' => 'pypi',
                    'DW_WORKFLOW_PHP_ARTIFACT_SOURCE' => 'composer_packagist',
                    'DW_WATERLINE_ARTIFACT_SOURCE' => 'published_waterline_artifact',
                    'DW_SCHEDULES_LOCAL_PRODUCT_SOURCE_CHECKOUTS_USED' => 'false',
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
            $publishedArtifacts = json_decode(
                (string) file_get_contents($resultDir.'/published-artifacts.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $scenario = $result['scenario_results']['published_artifact_install_only'];
            $installEvidence = $result['artifact_install_evidence'];
            $scenarioInstallEvidence = $scenario['observed_outputs']['artifact_install_evidence'];

            $this->assertSame('pass', $scenario['status']);
            $this->assertSame([], $scenario['linked_findings']);
            $this->assertFalse($installEvidence['supplied_install_evidence']);
            $this->assertTrue($installEvidence['derived_install_evidence']);
            $this->assertFalse($scenarioInstallEvidence['supplied_install_evidence']);
            $this->assertTrue($scenarioInstallEvidence['derived_install_evidence']);
            $this->assertFalse($installEvidence['local_product_source_checkouts_used']);
            $this->assertSame(
                ['pass', 'pass', 'pass', 'pass', 'pass'],
                array_column($installEvidence['artifacts'], 'status'),
            );
            $this->assertSame(
                ['pass', 'pass', 'pass', 'pass', 'pass'],
                array_column($scenarioInstallEvidence['artifacts'], 'status'),
            );
            $this->assertFalse($publishedArtifacts['local_product_source_checkouts_used']);
            $this->assertSame(
                'docker://ghcr.io/durable-workflow/server:0.2.323',
                $result['artifact_sources']['server'],
            );
            $this->assertSame(
                'docker://ghcr.io/durable-workflow/server:0.2.323',
                $scenario['observed_outputs']['artifact_sources']['server'],
            );
            $this->assertSame(
                'docker://ghcr.io/durable-workflow/server:0.2.323',
                $publishedArtifacts['artifact_install_evidence']['artifact_sources']['server'],
            );
            $this->assertSame(
                'https://github.com/durable-workflow/cli/releases/download/0.1.77/install.sh',
                $publishedArtifacts['artifact_install_evidence']['artifact_sources']['cli'],
            );
            $this->assertSame(
                'pypi://durable-workflow==0.4.85',
                $publishedArtifacts['artifact_install_evidence']['artifact_sources']['sdk-python'],
            );
            $this->assertSame(
                'packagist://durable-workflow/workflow@2.0.0-alpha.197',
                $publishedArtifacts['artifact_install_evidence']['artifact_sources']['workflow-php'],
            );
            $this->assertSame(
                'packagist://durable-workflow/waterline@2.0.0-alpha.83',
                $publishedArtifacts['artifact_install_evidence']['artifact_sources']['waterline'],
            );
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_cadence_shard_records_readiness_candidate_failure(): void
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
                    'DW_SCHEDULES_RUN_CADENCE_SHARD' => '1',
                    'DW_SCHEDULES_SERVER_URL' => 'http://127.0.0.1:1',
                    'DW_SCHEDULES_SERVER_READY_TIMEOUT_SECONDS' => '1',
                    'DW_SERVER_VERSION' => '0.2.343',
                    'DW_CLI_VERSION' => '0.1.77',
                    'DW_PYTHON_SDK_VERSION' => '0.4.85',
                    'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.200',
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

            $cronOutputs = $result['scenario_results']['cron_cadence']['observed_outputs'];
            $fixedOutputs = $result['scenario_results']['fixed_rate_cadence']['observed_outputs'];

            $this->assertSame('fail', $result['scenario_results']['cron_cadence']['status']);
            $this->assertSame('fail', $result['scenario_results']['fixed_rate_cadence']['status']);
            $this->assertStringContainsString(
                'published server did not become ready; tried http://127.0.0.1:1/api/ready',
                $cronOutputs['failure_reason'],
            );
            $this->assertSame($cronOutputs['failure_reason'], $fixedOutputs['failure_reason']);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_runner_rejects_unallowlisted_install_source_labels(): void
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
                    'DW_SERVER_VERSION' => '0.2.307',
                    'DW_CLI_VERSION' => '0.1.77',
                    'DW_PYTHON_SDK_VERSION' => '0.4.85',
                    'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.197',
                    'DW_WATERLINE_VERSION' => '2.0.0-alpha.83',
                    'DW_SERVER_ARTIFACT_SOURCE' => 'local_checkout/banana',
                    'DW_CLI_ARTIFACT_SOURCE' => 'official_install_script',
                    'DW_PYTHON_SDK_ARTIFACT_SOURCE' => 'pypi',
                    'DW_WORKFLOW_PHP_ARTIFACT_SOURCE' => 'composer_packagist',
                    'DW_WATERLINE_ARTIFACT_SOURCE' => 'published_waterline_artifact',
                    'DW_SCHEDULES_LOCAL_PRODUCT_SOURCE_CHECKOUTS_USED' => 'false',
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

            $scenario = $result['scenario_results']['published_artifact_install_only'];
            $this->assertSame('not_covered', $scenario['status']);
            $this->assertFalse($result['local_product_source_checkouts_used']);
            $this->assertNotEmpty($scenario['linked_findings']);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_runner_revalidates_supplied_install_pass_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the schedules runner result builder.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir().'/dw-schedules-runner-'.bin2hex(random_bytes(4));
        mkdir($resultDir);
        file_put_contents($resultDir.'/schedules-smoke-evidence.json', json_encode([
            'scenario_results' => [
                'published_artifact_install_only' => [
                    'status' => 'pass',
                    'observed_outputs' => [
                        'artifact_sources' => [
                            'server' => 'local_checkout/banana',
                            'cli' => 'official_install_script',
                            'sdk-python' => 'pypi',
                            'workflow' => 'composer_packagist',
                            'waterline' => 'published_waterline_artifact',
                        ],
                        'local_product_source_checkouts_used' => false,
                    ],
                    'linked_findings' => [],
                ],
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
                    'DW_SERVER_VERSION' => '0.2.307',
                    'DW_CLI_VERSION' => '0.1.77',
                    'DW_PYTHON_SDK_VERSION' => '0.4.85',
                    'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.197',
                    'DW_WATERLINE_VERSION' => '2.0.0-alpha.83',
                    'DW_SERVER_ARTIFACT_SOURCE' => 'published_docker_image',
                    'DW_CLI_ARTIFACT_SOURCE' => 'official_install_script',
                    'DW_PYTHON_SDK_ARTIFACT_SOURCE' => 'pypi',
                    'DW_WORKFLOW_PHP_ARTIFACT_SOURCE' => 'composer_packagist',
                    'DW_WATERLINE_ARTIFACT_SOURCE' => 'published_waterline_artifact',
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

            $scenario = $result['scenario_results']['published_artifact_install_only'];
            $this->assertSame('not_covered', $scenario['status']);
            $this->assertNotEmpty($scenario['observed_outputs']['published_artifact_policy_failures']);
            $this->assertStringContainsString(
                'server.artifact_sources',
                implode('; ', $scenario['observed_outputs']['published_artifact_policy_failures']),
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

    public function test_runner_preserves_supplied_missed_fire_and_restart_cells(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the schedules runner result builder.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir().'/dw-schedules-runner-'.bin2hex(random_bytes(4));
        mkdir($resultDir);

        $missedFire = [
            'schedule_id' => 'missed-fire-schedule',
            'documented_policy' => 'fire_once_on_resume_then_skip_remaining_missed',
            'observed_policy' => 'fire_once_on_resume_then_skip_remaining_missed',
            'catchup_fire_count' => 1,
            'post_resume_normal_fire_observed' => true,
            'scheduler_stopped_at' => '2026-06-04T10:00:00Z',
            'scheduler_resume_requested_at' => '2026-06-04T10:02:10Z',
            'catchup_fires' => [[
                'recorded_at' => '2026-06-04T10:02:12Z',
                'occurrence_time' => '2026-06-04T10:01:00Z',
            ]],
            'normal_fires_after_resume' => [[
                'recorded_at' => '2026-06-04T10:03:02Z',
                'occurrence_time' => '2026-06-04T10:03:00Z',
            ]],
            'verdict' => 'pass',
        ];
        $restart = [
            'schedule_id' => 'restart-survival-schedule',
            'schedule_listed_before_restart' => true,
            'schedule_listed_after_restart' => true,
            'fired_after_restart' => true,
            'fire_within_restart_deadline' => true,
            'restart_deadline_seconds' => 90,
            'server_restart_requested_at' => '2026-06-04T11:00:00Z',
            'server_restart_ready_at' => '2026-06-04T11:00:12Z',
            'first_fire_after_restart' => [
                'recorded_at' => '2026-06-04T11:01:02Z',
                'occurrence_time' => '2026-06-04T11:01:00Z',
            ],
            'verdict' => 'pass',
        ];

        file_put_contents($resultDir.'/missed-restart-evidence.json', json_encode([
            'schema' => 'durable-workflow.v2.schedules-runtime.missed-restart-evidence',
            'scenario_results' => [
                'missed_fire_policy' => [
                    'scenario_id' => 'missed_fire_policy',
                    'status' => 'pass',
                    'observed_outputs' => $missedFire,
                    'linked_findings' => [],
                ],
                'restart_survival' => [
                    'scenario_id' => 'restart_survival',
                    'status' => 'pass',
                    'observed_outputs' => $restart,
                    'linked_findings' => [],
                ],
            ],
            'missed_fire_policy' => $missedFire,
            'restart_survival' => $restart,
            'runtime_matrix' => [
                'client_paths' => ['server-http-api'],
                'runtimes' => ['server-scheduler'],
                'schedule_types' => ['cron_expression'],
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
                    'DW_SCHEDULES_MISSED_RESTART_EVIDENCE' => $resultDir.'/missed-restart-evidence.json',
                    'DW_SERVER_VERSION' => '0.2.307',
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

            $this->assertSame('pass', $result['scenario_results']['missed_fire_policy']['status']);
            $this->assertSame('pass', $result['scenario_results']['restart_survival']['status']);
            $this->assertSame(
                'fire_once_on_resume_then_skip_remaining_missed',
                $result['scenario_results']['missed_fire_policy']['observed_outputs']['observed_policy'],
            );
            $this->assertSame(
                1,
                $result['scenario_results']['missed_fire_policy']['observed_outputs']['catchup_fire_count'],
            );
            $this->assertTrue(
                $result['scenario_results']['missed_fire_policy']['observed_outputs']['post_resume_normal_fire_observed'],
            );
            $this->assertTrue(
                $result['scenario_results']['restart_survival']['observed_outputs']['schedule_listed_after_restart'],
            );
            $this->assertTrue(
                $result['scenario_results']['restart_survival']['observed_outputs']['fired_after_restart'],
            );
            $this->assertSame(
                $missedFire['catchup_fires'],
                $result['missed_fire_policy']['catchup_fires'],
            );
            $this->assertSame(
                $restart['first_fire_after_restart'],
                $result['restart_survival']['first_fire_after_restart'],
            );
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

    public function test_runner_promotes_supplied_php_schedule_surface_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the schedules runner result builder.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir().'/dw-schedules-runner-'.bin2hex(random_bytes(4));
        mkdir($resultDir);

        $scheduleId = 'php-surface-schedule';
        $state = [
            'schedule_id' => $scheduleId,
            'cadence' => '*/5 * * * *',
            'pause_state' => 'active',
            'last_fire_at' => '2026-06-03T00:00:00Z',
            'next_fire_at' => '2026-06-03T00:05:00Z',
        ];
        $observedOutputs = [
            'schedule_id' => $scheduleId,
            'create_or_observe' => true,
            'list_or_describe' => true,
            'control_observed' => true,
            'claimed_controls' => [
                'pause' => true,
                'resume' => true,
                'trigger' => true,
                'delete' => true,
            ],
            'unsupported_controls' => [],
            'control_behavior' => [
                'passed' => true,
                'pause' => ['ok' => true, 'state_after_pause' => array_merge($state, ['pause_state' => 'paused'])],
                'resume' => ['ok' => true, 'state_after_resume' => $state],
                'trigger' => ['ok' => true, 'schedule_id' => $scheduleId],
                'delete' => ['ok' => true, 'absent_from_php_list' => true],
            ],
            'state_comparison' => [
                'fields_compared' => ['schedule_id', 'cadence', 'pause_state', 'last_fire_at', 'next_fire_at'],
                'php' => ['describe' => $state, 'list' => $state],
                'server' => ['describe' => $state, 'list' => $state],
                'cli' => ['describe' => $state, 'list' => $state],
                'server_compared' => true,
                'cli_compared' => true,
                'comparisons' => [
                    [
                        'php_surface' => 'php_describe',
                        'target_surface' => 'server_describe',
                        'field' => 'schedule_id',
                        'php_value' => $scheduleId,
                        'target_value' => $scheduleId,
                        'matches' => true,
                    ],
                    [
                        'php_surface' => 'php_describe',
                        'target_surface' => 'cli_describe',
                        'field' => 'next_fire_at',
                        'php_value' => '2026-06-03T00:05:00Z',
                        'target_value' => '2026-06-03T00:05:00Z',
                        'matches' => true,
                    ],
                ],
                'failures' => [],
            ],
            'php_report' => [
                'create_or_observe' => ['ok' => true, 'response' => ['schedule_id' => $scheduleId]],
                'list_or_describe' => [
                    'list' => ['ok' => true, 'response' => ['schedules' => [['schedule_id' => $scheduleId]]]],
                    'describe' => ['ok' => true, 'response' => ['schedule_id' => $scheduleId]],
                ],
            ],
            'failures' => [],
        ];

        file_put_contents($resultDir.'/php-surface-evidence.json', json_encode([
            'schema' => 'durable-workflow.v2.schedules-runtime.php-surface-evidence',
            'scenario_results' => [
                'php_schedule_surface' => [
                    'scenario_id' => 'php_schedule_surface',
                    'status' => 'pass',
                    'observed_outputs' => $observedOutputs,
                    'linked_findings' => [],
                ],
            ],
            'client_surfaces' => [
                'workflow-php-sdk' => [
                    'create_or_observe' => true,
                    'list_or_describe' => true,
                    'control_observed' => true,
                    'state_compared_with_server' => true,
                    'state_compared_with_cli' => true,
                    'schedule_id' => $scheduleId,
                ],
            ],
            'runtime_matrix' => [
                'runtimes' => ['workflow-php'],
                'client_paths' => ['workflow-php-sdk', 'server-http-api', 'cli'],
                'schedule_types' => ['cron_expression'],
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
                    'DW_SCHEDULES_PHP_SURFACE_EVIDENCE' => $resultDir.'/php-surface-evidence.json',
                    'DW_SERVER_VERSION' => '0.2.410',
                    'DW_CLI_VERSION' => '0.1.80',
                    'DW_PYTHON_SDK_VERSION' => '0.4.88',
                    'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.204',
                    'DW_WATERLINE_VERSION' => '2.0.0-alpha.96',
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

            $scenario = $result['scenario_results']['php_schedule_surface'];
            $this->assertSame('pass', $scenario['status']);
            $this->assertSame([], $scenario['linked_findings']);
            $this->assertTrue($scenario['observed_outputs']['create_or_observe']);
            $this->assertTrue($scenario['observed_outputs']['list_or_describe']);
            $this->assertTrue($scenario['observed_outputs']['control_observed']);
            $this->assertSame([], $scenario['observed_outputs']['unsupported_controls']);
            $this->assertSame(
                ['schedule_id', 'cadence', 'pause_state', 'last_fire_at', 'next_fire_at'],
                $scenario['observed_outputs']['state_comparison']['fields_compared'],
            );
            $this->assertTrue($scenario['observed_outputs']['state_comparison']['server_compared']);
            $this->assertTrue($scenario['observed_outputs']['state_comparison']['cli_compared']);
            $this->assertSame(
                $scheduleId,
                $result['client_surfaces']['workflow-php-sdk']['schedule_id'],
            );
            $this->assertContains('workflow-php-sdk', $result['runtime_matrix']['client_paths']);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_runner_promotes_supplied_cross_language_schedule_cells(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the schedules runner result builder.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir().'/dw-schedules-runner-'.bin2hex(random_bytes(4));
        mkdir($resultDir);

        $pythonCreatedPhp = [
            'scenario' => 'python_created_php_workflow',
            'schedule_creator' => 'sdk-python',
            'workflow_runtime' => 'workflow-php',
            'schedule_id' => 'python-created-php-schedule',
            'schedule_visible_in_cli' => true,
            'workflow_completed' => true,
            'workflow_id' => 'python-created-php-workflow',
            'run_id' => 'run-php',
        ];
        $phpCreatedPython = [
            'scenario' => 'php_created_python_workflow',
            'schedule_creator' => 'workflow-php-sdk',
            'workflow_runtime' => 'sdk-python',
            'schedule_id' => 'php-created-python-schedule',
            'schedule_visible_in_cli' => true,
            'workflow_completed' => true,
            'workflow_id' => 'php-created-python-workflow',
            'run_id' => 'run-python',
        ];

        file_put_contents($resultDir.'/cross-language-evidence.json', json_encode([
            'schema' => 'durable-workflow.v2.schedules-runtime.cross-language-evidence',
            'scenario_results' => [
                'python_created_php_workflow' => [
                    'scenario_id' => 'python_created_php_workflow',
                    'status' => 'pass',
                    'observed_outputs' => $pythonCreatedPhp,
                    'linked_findings' => [],
                ],
                'php_created_python_workflow' => [
                    'scenario_id' => 'php_created_python_workflow',
                    'status' => 'pass',
                    'observed_outputs' => $phpCreatedPython,
                    'linked_findings' => [],
                ],
            ],
            'cross_language_matrix' => [
                'cross_language_cells' => [$pythonCreatedPhp, $phpCreatedPython],
            ],
            'runtime_matrix' => [
                'runtimes' => ['workflow-php', 'sdk-python'],
                'client_paths' => ['cli', 'sdk-python', 'workflow-php-sdk'],
                'schedule_types' => ['fixed_rate_interval'],
                'cross_language_cells' => [
                    [
                        'scenario' => 'python_created_php_workflow',
                        'schedule_creator' => 'sdk-python',
                        'workflow_runtime' => 'workflow-php',
                    ],
                    [
                        'scenario' => 'php_created_python_workflow',
                        'schedule_creator' => 'workflow-php-sdk',
                        'workflow_runtime' => 'sdk-python',
                    ],
                ],
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
                    'DW_SCHEDULES_CROSS_LANGUAGE_EVIDENCE' => $resultDir.'/cross-language-evidence.json',
                    'DW_SERVER_VERSION' => '0.2.312',
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

            $this->assertSame('pass', $result['scenario_results']['python_created_php_workflow']['status']);
            $this->assertSame('pass', $result['scenario_results']['php_created_python_workflow']['status']);
            $this->assertTrue(
                $result['scenario_results']['python_created_php_workflow']['observed_outputs']['schedule_visible_in_cli'],
            );
            $this->assertTrue(
                $result['scenario_results']['php_created_python_workflow']['observed_outputs']['workflow_completed'],
            );
            $this->assertSame(
                [$pythonCreatedPhp, $phpCreatedPython],
                $result['cross_language_matrix']['cross_language_cells'],
            );
            $this->assertContains('workflow-php-sdk', $result['runtime_matrix']['client_paths']);
            $this->assertContains('sdk-python', $result['runtime_matrix']['runtimes']);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_runner_marks_row_runner_blocked_when_cross_language_cells_are_runner_blocked(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the schedules runner result builder.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir().'/dw-schedules-runner-'.bin2hex(random_bytes(4));
        mkdir($resultDir);

        $blockedReason = 'published Python schedules worker action poll_complete failed; see schedules-cross-language-python-worker-poll_complete.log; scheduler evaluation reported eligible_count=0 and fired_count=0';
        $pythonFinding = [
            'finding_id' => 'schedules-python-created-php-workflow-runner-blocked',
            'scenario_id' => 'python_created_php_workflow',
            'finding_type' => 'conformance_runner_blocked',
            'owning_surface' => 'conformance_harness',
            'execution_scope' => 'cross-language-schedule-worker-shard',
            'observed_behavior' => $blockedReason,
            'expected_behavior' => 'The schedules conformance host can execute Python-created/PHP-worker schedule dispatch.',
            'next_acceptance_criterion' => 'restore the missing host capability and rerun schedules conformance',
        ];
        $phpFinding = [
            'finding_id' => 'schedules-php-created-python-workflow-runner-blocked',
            'scenario_id' => 'php_created_python_workflow',
            'finding_type' => 'conformance_runner_blocked',
            'owning_surface' => 'conformance_harness',
            'execution_scope' => 'cross-language-schedule-worker-shard',
            'observed_behavior' => $blockedReason,
            'expected_behavior' => 'The schedules conformance host can execute PHP-created/Python-worker schedule dispatch.',
            'next_acceptance_criterion' => 'restore the missing host capability and rerun schedules conformance',
        ];

        file_put_contents($resultDir.'/cross-language-evidence.json', json_encode([
            'schema' => 'durable-workflow.v2.schedules-runtime.cross-language-evidence',
            'scenario_results' => [
                'python_created_php_workflow' => [
                    'scenario_id' => 'python_created_php_workflow',
                    'status' => 'runner_blocked',
                    'observed_outputs' => [
                        'scenario' => 'python_created_php_workflow',
                        'blocked_reason' => $blockedReason,
                        'schedule_creator' => 'sdk-python',
                        'workflow_runtime' => 'workflow-php',
                        'schedule_visible_in_cli' => false,
                        'workflow_completed' => false,
                    ],
                    'linked_findings' => [$pythonFinding],
                ],
                'php_created_python_workflow' => [
                    'scenario_id' => 'php_created_python_workflow',
                    'status' => 'runner_blocked',
                    'observed_outputs' => [
                        'scenario' => 'php_created_python_workflow',
                        'blocked_reason' => $blockedReason,
                        'schedule_creator' => 'workflow-php-sdk',
                        'workflow_runtime' => 'sdk-python',
                        'schedule_visible_in_cli' => false,
                        'workflow_completed' => false,
                    ],
                    'linked_findings' => [$phpFinding],
                ],
            ],
            'findings' => [$pythonFinding, $phpFinding],
            'runtime_matrix' => [
                'runtimes' => ['workflow-php', 'sdk-python'],
                'client_paths' => ['cli', 'sdk-python', 'workflow-php-sdk'],
                'schedule_types' => ['fixed_rate_interval'],
                'cross_language_cells' => [
                    [
                        'scenario' => 'python_created_php_workflow',
                        'schedule_creator' => 'sdk-python',
                        'workflow_runtime' => 'workflow-php',
                    ],
                    [
                        'scenario' => 'php_created_python_workflow',
                        'schedule_creator' => 'workflow-php-sdk',
                        'workflow_runtime' => 'sdk-python',
                    ],
                ],
            ],
            'cross_language_matrix' => [
                'cross_language_cells' => [
                    [
                        'scenario' => 'python_created_php_workflow',
                        'schedule_creator' => 'sdk-python',
                        'workflow_runtime' => 'workflow-php',
                        'schedule_visible_in_cli' => false,
                        'workflow_completed' => false,
                        'blocked_reason' => $blockedReason,
                    ],
                    [
                        'scenario' => 'php_created_python_workflow',
                        'schedule_creator' => 'workflow-php-sdk',
                        'workflow_runtime' => 'sdk-python',
                        'schedule_visible_in_cli' => false,
                        'workflow_completed' => false,
                        'blocked_reason' => $blockedReason,
                    ],
                ],
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
                    'DW_SCHEDULES_CROSS_LANGUAGE_EVIDENCE' => $resultDir.'/cross-language-evidence.json',
                    'DW_SERVER_VERSION' => '0.2.491',
                    'DW_CLI_VERSION' => '0.1.82',
                    'DW_PYTHON_SDK_VERSION' => '0.4.90',
                    'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.223',
                    'DW_WATERLINE_VERSION' => '2.0.0-alpha.111',
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
            $record = json_decode(
                (string) file_get_contents($resultDir.'/schedules-runtime-record.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing_runner_blocked', $result['outcome']);
            $this->assertTrue($result['runner_blocked']);
            $this->assertSame('non_passing_runner_blocked', $record['outcome']);
            $this->assertTrue($record['runnerBlocked']);
            $this->assertSame('runner_blocked', $result['scenario_results']['python_created_php_workflow']['status']);
            $this->assertSame('runner_blocked', $result['scenario_results']['php_created_python_workflow']['status']);
            $this->assertSame(
                'conformance_runner_blocked',
                $result['scenario_results']['python_created_php_workflow']['linked_findings'][0]['finding_type'],
            );
            $this->assertStringContainsString(
                'eligible_count=0 and fired_count=0',
                $result['scenario_results']['php_created_python_workflow']['observed_outputs']['blocked_reason'],
            );
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_runner_promotes_supplied_adversarial_nonexistent_workflow_type_cell(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the schedules runner result builder.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir().'/dw-schedules-runner-'.bin2hex(random_bytes(4));
        mkdir($resultDir);

        $observedOutcome = [
            'scenario_id' => 'nonexistent_workflow_type_outcome',
            'behavior' => 'accepted_pending_worker',
            'allowed_behaviors' => ['refused_at_create', 'fails_at_fire_time', 'accepted_pending_worker'],
            'schedule_id' => 'missing-type-schedule',
            'workflow_type' => 'schedules.nonexistent.workflow',
            'task_queue' => 'schedules-unregistered',
            'operator_visible_signal' => [
                'surface' => 'GET /api/workflows/missing-type-workflow/runs/run-1',
                'workflow_status' => 'running',
                'task_queue' => 'schedules-unregistered',
                'no_worker_registered_by_probe' => true,
            ],
        ];

        file_put_contents($resultDir.'/adversarial-evidence.json', json_encode([
            'schema' => 'durable-workflow.v2.schedules-runtime.adversarial-evidence',
            'scenario_results' => [
                'nonexistent_workflow_type_outcome' => [
                    'scenario_id' => 'nonexistent_workflow_type_outcome',
                    'status' => 'pass',
                    'observed_outputs' => $observedOutcome,
                    'linked_findings' => [],
                ],
            ],
            'adversarial_outcomes' => [
                'nonexistent_workflow_type_outcome' => $observedOutcome,
            ],
            'runtime_matrix' => [
                'runtimes' => ['server-scheduler'],
                'client_paths' => ['server-http-api'],
                'schedule_types' => ['fixed_rate_interval'],
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
                    'DW_SCHEDULES_ADVERSARIAL_EVIDENCE' => $resultDir.'/adversarial-evidence.json',
                    'DW_SERVER_VERSION' => '0.2.348',
                    'DW_CLI_VERSION' => '0.1.77',
                    'DW_PYTHON_SDK_VERSION' => '0.4.85',
                    'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.200',
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

            $scenario = $result['scenario_results']['nonexistent_workflow_type_outcome'];
            $this->assertSame('pass', $scenario['status']);
            $this->assertSame('accepted_pending_worker', $scenario['observed_outputs']['behavior']);
            $this->assertTrue($scenario['observed_outputs']['operator_visible_signal']['no_worker_registered_by_probe']);
            $this->assertSame(
                $observedOutcome,
                $result['adversarial_outcomes']['nonexistent_workflow_type_outcome'],
            );
            $this->assertContains('server-http-api', $result['runtime_matrix']['client_paths']);
            $this->assertContains('server-scheduler', $result['runtime_matrix']['runtimes']);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_runner_uses_bounded_concurrency_for_long_published_artifact_shards(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $source = (string) file_get_contents($repoRoot.'/scripts/conformance/schedules-published-artifacts.mjs');
        $shell = (string) file_get_contents($repoRoot.'/scripts/conformance/schedules-published-artifacts.sh');

        $this->assertStringContainsString('async function runEvidenceShardTasks', $source);
        $this->assertStringContainsString('Promise.all(Array.from({ length: workerCount }, runWorker))', $source);
        $this->assertStringContainsString('DW_SCHEDULES_SHARD_CONCURRENCY', $source);
        $this->assertStringContainsString('fixedServerPort > 0', $source);
        $this->assertStringContainsString('publishedCliInstallPromise', $source);
        $this->assertStringContainsString('async function installPublishedCliArtifact', $source);
        $this->assertStringContainsString('maybeRunPythonLifecycleShard', $source);
        $this->assertStringContainsString('DW_SCHEDULES_RUN_PYTHON_LIFECYCLE_SHARD', $source);
        $this->assertStringContainsString('DW_SCHEDULES_PYTHON_LIFECYCLE_EVIDENCE', $source);
        $this->assertStringContainsString('maybeRunPhpSurfaceShard', $source);
        $this->assertStringContainsString('DW_SCHEDULES_RUN_PHP_SURFACE_SHARD', $source);
        $this->assertStringContainsString('DW_SCHEDULES_PHP_SURFACE_EVIDENCE', $source);
        $this->assertStringContainsString('schedules_php_surface.php', $source);
        $this->assertStringContainsString('DW_SCHEDULES_SHARD_CONCURRENCY', $shell);
        $this->assertStringContainsString('DW_SCHEDULES_RUN_PYTHON_LIFECYCLE_SHARD', $shell);
        $this->assertStringContainsString('DW_SCHEDULES_PYTHON_LIFECYCLE_EVIDENCE', $shell);
        $this->assertStringContainsString('DW_SCHEDULES_RUN_PHP_SURFACE_SHARD', $shell);
        $this->assertStringContainsString('DW_SCHEDULES_PHP_SURFACE_EVIDENCE', $shell);
    }

    public function test_runner_uses_published_compose_dependency_graph_for_schedule_shards(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $source = (string) file_get_contents($repoRoot.'/scripts/conformance/schedules-published-artifacts.mjs');

        $this->assertStringContainsString('async function startPublishedComposeServices', $source);
        $this->assertStringContainsString("'up', '-d', '--wait', '--wait-timeout'", $source);
        $this->assertStringNotContainsString("'up', '-d', ...services", $source);
        $this->assertStringNotContainsString("'run', '--rm', 'bootstrap'", $source);
        $this->assertStringNotContainsString("'--no-deps'", $source);
        $this->assertGreaterThanOrEqual(
            3,
            substr_count($source, "markArtifactSource(artifactSources, 'server'"),
            'Each Docker-backed schedules shard should record the published server image source.',
        );
    }

    public function test_runner_readiness_timeout_reports_candidate_probe_observations(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $source = (string) file_get_contents($repoRoot.'/scripts/conformance/schedules-published-artifacts.mjs');

        $this->assertStringContainsString('async function waitForReachableServerUrl', $source);
        $this->assertStringContainsString('const observations = new Map();', $source);
        $this->assertStringContainsString('const observation = await probeServerReady(candidate);', $source);
        $this->assertStringContainsString('observations.set(candidate, observation.detail);', $source);
        $this->assertStringContainsString('${candidate}/api/ready => ${observations.get(candidate)', $source);
        $this->assertStringContainsString('published server did not become ready; tried ${details}', $source);
        $this->assertStringContainsString('function networkErrorDetail', $source);
        $this->assertStringContainsString('compactLogText(text)', $source);
        $this->assertStringContainsString('<empty response body>', $source);
        $this->assertStringContainsString('compose diagnostics:', $source);
        $this->assertStringContainsString('tailLogSnippet', $source);
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
