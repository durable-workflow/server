<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class HeartbeatConformanceRunnerContractTest extends TestCase
{
    public function test_runner_executes_the_pinned_public_php_worker_loop(): void
    {
        $source = $this->runnerSource();

        foreach ([
            "'durable-workflow/workflow': WORKFLOW_VERSION",
            "'minimum-stability': 'dev'",
            'use Workflow\\\\V2\\\\Worker\\\\WorkerProtocolClient;',
            'use Workflow\\\\V2\\\\Worker\\\\StandaloneWorkflowWorker;',
            '$worker->tickWithHeartbeat(',
            '$worker->run(',
            "'workflow:start'",
            "'--wait'",
            "'worker:list'",
            "'worker:describe'",
            "'stale_worker_registration'",
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }

        $this->assertStringContainsString('sdk_emitted_heartbeat_timestamps', $source);
        $this->assertStringContainsString('sdk_heartbeat_acknowledgement_count >= 2', $source);
        $this->assertStringContainsString('cadence_observation_source', $source);
        $this->assertStringContainsString("'acknowledgement_logged_at'", $source);
        $this->assertStringContainsString('server_last_heartbeat_timestamps', $source);
        $this->assertStringContainsString('bounded_advertised_cadence', $source);
        $this->assertStringContainsString('work_processed_records', $source);
        $this->assertStringContainsString(
            'stale_worker_claim_count: Array.isArray(stalePoll.tasks) ? stalePoll.tasks.length : null',
            $source,
        );
        $this->assertStringContainsString('fresh_worker_eligibility_after_stale', $source);
    }

    public function test_heartbeat_cadence_timestamp_attribution_regressions(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise heartbeat cadence attribution.');
        }

        $process = proc_open(
            [
                $nodeBinary,
                '--test',
                __DIR__.'/HeartbeatCadenceObservationRegression.mjs',
            ],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2),
        );

        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, $stderr ?: $stdout);
    }

    public function test_runner_records_mergeable_focused_evidence_without_claiming_sibling_cells(): void
    {
        $source = $this->runnerSource();

        $this->assertStringContainsString(
            'schema: `durable-workflow.v2.heartbeat-runtime.${CELL}-sdk-loop-evidence`',
            $source,
        );
        $this->assertStringContainsString('scenario_id: SCENARIO_ID', $source);
        $this->assertStringContainsString('writeJson(EVIDENCE_FILE, evidence)', $source);
        $this->assertStringContainsString("const SCENARIO_ID = `\${CELL}_sdk_heartbeat_loop`;", $source);
        $this->assertStringContainsString(
            "? ['php_sdk_heartbeat_loop', 'python_sdk_heartbeat_loop', 'waterline_worker_status_visibility']",
            $source,
        );
        $this->assertStringContainsString('separate_uncovered_cells: SEPARATE_UNCOVERED_CELLS', $source);
        $this->assertStringContainsString('local_product_source_checkouts_used: false', $source);
        $this->assertStringNotContainsString('path repository', strtolower($source));
    }

    public function test_python_shard_uses_the_standard_pin_and_result_gate_artifact_source(): void
    {
        $source = $this->runnerSource();

        $this->assertStringContainsString(
            "const SDK_PYTHON_VERSION = env('DW_PYTHON_SDK_VERSION');",
            $source,
        );
        $this->assertStringContainsString(
            '`pypi://durable-workflow==${SDK_PYTHON_VERSION}`',
            $source,
        );
        $this->assertStringContainsString(
            "failures.push('DW_PYTHON_SDK_VERSION must be an exact release')",
            $source,
        );
        $this->assertStringNotContainsString('DW_SDK_PYTHON_VERSION', $source);
    }

    public function test_python_shard_emits_canonical_pin_source_and_truthful_focused_noncoverage(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Python heartbeat handoff.');
        }

        $runRoot = sys_get_temp_dir().'/dw-heartbeats-python-handoff-'.bin2hex(random_bytes(4));
        $binDir = $runRoot.'/bin';
        $resultDir = $runRoot.'/results';
        $dockerBinary = $binDir.'/docker';
        mkdir($binDir, 0777, true);
        mkdir($resultDir, 0777, true);
        file_put_contents($dockerBinary, <<<'SH'
#!/bin/sh
case "$*" in
  *"pull durableworkflow/server:"*) exit 64 ;;
esac
exit 0
SH);
        chmod($dockerBinary, 0755);

        try {
            $process = proc_open(
                [
                    '/bin/bash',
                    dirname(__DIR__, 2).'/scripts/conformance/heartbeats-python-published-artifacts.sh',
                    '--result-dir',
                    $resultDir,
                ],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                dirname(__DIR__, 2),
                [
                    'PATH' => $binDir.':'.(getenv('PATH') ?: '/usr/bin:/bin'),
                    'DW_SERVER_VERSION' => '0.2.623',
                    'DW_CLI_VERSION' => '0.1.86',
                    'DW_PYTHON_SDK_VERSION' => '0.4.98',
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(1, $exitCode, $stderr ?: $stdout);
            $evidence = json_decode(
                (string) file_get_contents($resultDir.'/python-sdk-heartbeat-loop-evidence.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $pins = json_decode(
                (string) file_get_contents($resultDir.'/pins.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('0.4.98', $evidence['artifact_versions']['sdk-python']);
            $this->assertSame(
                'pypi://durable-workflow==0.4.98',
                $evidence['artifact_sources']['sdk-python'],
            );
            $this->assertSame(
                $evidence['artifact_sources']['sdk-python'],
                $pins['artifact_sources']['sdk-python'],
            );
            $this->assertSame([
                'php_sdk_heartbeat_loop',
                'rust_sdk_heartbeat_loop',
                'waterline_worker_status_visibility',
            ], $evidence['separate_uncovered_cells']);
        } finally {
            $this->removeDirectory($runRoot);
        }
    }

    public function test_python_shard_uses_the_public_worker_heartbeat_loop_and_authoritative_surfaces(): void
    {
        $source = $this->runnerSource();

        foreach ([
            'class EvidenceClient(Client):',
            'acknowledgement = await super().register_worker(**kwargs)',
            'acknowledgement = await super().heartbeat_worker(**kwargs)',
            'worker = Worker(',
            'worker_task = asyncio.create_task(worker.run())',
            'poll = await client.poll_workflow_task_response(',
            "install_mode: 'pip --target'",
            '`durable-workflow==${SDK_PYTHON_VERSION}`',
            'evidence.python_package_install?.installed_version === SDK_PYTHON_VERSION',
            'at_least_two_sdk_heartbeats',
            'advertised_cadence_bounded',
            'heartbeat_freshness_visible',
            'api_cli_worker_state_consistent',
            'stale_sdk_poll_refused',
            'fresh_worker_remains_eligible',
            "'worker:list'",
            "'worker:describe'",
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }

        $this->assertStringNotContainsString('DW_HEARTBEATS_PLAN', $source);
        $this->assertStringNotContainsString('fixture_response', $source);
    }

    public function test_rust_shard_installs_and_executes_the_exact_public_crate(): void
    {
        $source = $this->runnerSource();

        foreach ([
            "const SDK_RUST_VERSION = env('DW_RUST_SDK_VERSION');",
            '`crates.io://durable-workflow@${SDK_RUST_VERSION}`',
            'durable-workflow = "=${SDK_RUST_VERSION}"',
            "'cargo', 'metadata', '--locked', '--format-version=1'",
            "installedPackage.source ?? '').startsWith('registry+')",
            "installedPackage.repository !== 'https://github.com/durable-workflow/sdk-rust'",
            'registry_checksum_sha256: registryChecksum',
            "'cargo', 'build', '--release', '--locked'",
            '.on_worker_heartbeat(|observation|',
            '.poll_workflow_task_response(&arguments[4], &arguments[3]',
            "'/app/target/release/heartbeat-worker'",
            "'/app/target/release/stale-poll'",
            "evidence.rust_package_install?.installed_version === SDK_RUST_VERSION",
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }

        $this->assertStringNotContainsString('DW_HEARTBEATS_PLAN', $source);
        $this->assertStringNotContainsString('fixture_response', $source);
    }

    public function test_runner_registers_idempotent_cleanup_before_resources_can_partially_start(): void
    {
        $source = $this->runnerSource();
        $composeCleanup = strpos($source, 'cleanupCommands.push(() => cleanupComposeProject(project, composeArgs, composeEnv));');
        $composeUp = strpos($source, "run('docker', [...composeArgs, 'up', '-d', '--wait', 'server']");
        $workerFunction = strpos($source, 'function startWorker(workerId)');
        $workerTracking = strpos($source, 'workerContainers.add(containerName);', $workerFunction);
        $workerRun = strpos($source, "const result = run('docker', [", $workerFunction);

        $this->assertIsInt($composeCleanup);
        $this->assertIsInt($composeUp);
        $this->assertLessThan($composeUp, $composeCleanup);
        $this->assertIsInt($workerTracking);
        $this->assertIsInt($workerRun);
        $this->assertLessThan($workerRun, $workerTracking);
        $this->assertStringContainsString("run('docker', ['rm', '-f', containerName], { timeout: 30_000 });", $source);
        $this->assertStringContainsString("run('docker', [...composeArgs, 'down', '-v'], { env: composeEnv, timeout: 120_000 });", $source);
        $this->assertStringContainsString("'volume', 'ls'", $source);
        $this->assertStringContainsString('recordCleanupFailure(cleanupFailures);', $source);
        $this->assertSame(1, substr_count($source, 'writeResultFiles(completedContext);'));
        $this->assertLessThan(
            strpos($source, 'writeResultFiles(completedContext);'),
            strpos($source, 'for (const containerName of workerContainers)'),
        );
    }

    public function test_all_php_project_mounts_use_the_invoking_host_uid_and_gid(): void
    {
        $source = $this->runnerSource();

        $this->assertStringContainsString("const CONTAINER_USER = `\${HOST_UID}:\${HOST_GID}`;", $source);
        $this->assertGreaterThanOrEqual(4, substr_count($source, "'-v', `\${PROJECT_DIR}:/app`"));
        $this->assertGreaterThanOrEqual(4, substr_count($source, "'--user', CONTAINER_USER"));
    }

    public function test_host_control_address_is_configurable_without_changing_the_worker_container_gateway(): void
    {
        $source = $this->runnerSource();

        $this->assertStringContainsString(
            "const SERVER_HOST = env('DW_HEARTBEATS_SERVER_HOST') || '127.0.0.1';",
            $source,
        );
        $this->assertStringContainsString('serverBaseUrl = `http://${SERVER_HOST}:${port}`;', $source);
        $this->assertStringContainsString("new URL('/api/ready', serverBaseUrl)", $source);
        $this->assertStringContainsString('DURABLE_WORKFLOW_SERVER_URL: serverBaseUrl', $source);
        $this->assertStringContainsString("parsed.hostname = 'host.docker.internal';", $source);
        $this->assertSame(2, substr_count($source, 'workerBaseUrl(serverBaseUrl)'));
        $this->assertStringNotContainsString(
            "if (['127.0.0.1', 'localhost'].includes(parsed.hostname))",
            $source,
        );

        $shell = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/conformance/heartbeats-published-artifacts.sh');
        $this->assertStringContainsString('DW_HEARTBEATS_SERVER_HOST', $shell);
    }

    public function test_runner_resolves_artifacts_only_from_exact_public_release_sources(): void
    {
        $source = $this->runnerSource();
        $inspectEvidenceSource = (string) file_get_contents(
            dirname(__DIR__, 2).'/scripts/conformance/heartbeat-container-inspect-evidence.mjs',
        );

        $this->assertStringContainsString("run('docker', ['pull', SERVER_IMAGE]", $source);
        $this->assertStringContainsString("run('docker', ['image', 'inspect', SERVER_IMAGE]", $source);
        $this->assertStringContainsString('digest-pinned server image does not match public version tag', $source);
        $this->assertStringContainsString('SERVER_CONTAINER_IMAGE_INSPECT_FORMAT', $source);
        $this->assertStringContainsString('captureTransform: safeContainerInspectCommandRecord', $source);
        $this->assertStringContainsString('container?.Image !== image.Id || container?.Config?.Image !== SERVER_IMAGE', $source);
        $this->assertStringNotContainsString("['container', 'inspect', containerId]", $source);
        $this->assertStringContainsString(
            '{"Image":{{json .Image}},"Config":{"Image":{{json .Config.Image}}}}',
            $inspectEvidenceSource,
        );
        $this->assertStringNotContainsString('.Config.Env', $inspectEvidenceSource);
        $this->assertStringContainsString('parseCliVersionOutput(versionOutput)', $source);
        $this->assertStringNotContainsString('includes(CLI_VERSION)', $source);
        $this->assertStringNotContainsString('DW_HEARTBEATS_SERVER_URL', $source);
        $this->assertStringNotContainsString('DW_HEARTBEATS_CLI_BIN', $source);
        $this->assertStringNotContainsString('DW_HEARTBEATS_CLI_SOURCE_URL', $source);
    }

    public function test_container_inspect_evidence_omits_supplied_secret_sentinels(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the heartbeat inspect-evidence sanitizer.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $suffix = bin2hex(random_bytes(4));
        $artifact = $repoRoot.'/storage/framework/heartbeat-inspect-evidence-'.$suffix.'.json';
        $authSentinel = 'heartbeat-auth-sentinel-'.$suffix;
        $databaseSentinel = 'heartbeat-database-sentinel-'.$suffix;

        try {
            $script = <<<'JS'
import fs from 'node:fs';
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(process.argv[2]).href;
const { safeContainerInspectCommandRecord } = await import(moduleUrl);
const imageId = `sha256:${'a'.repeat(64)}`;
const record = {
  command: ['docker', 'container', 'inspect', process.env.DW_AUTH_TOKEN],
  status: 0,
  signal: null,
  stdout: JSON.stringify([{
    Id: 'b'.repeat(64),
    Image: imageId,
    Config: {
      Image: 'durableworkflow/server:0.2.622',
      Env: [
        `DW_AUTH_TOKEN=${process.env.DW_AUTH_TOKEN}`,
        `DB_PASSWORD=${process.env.DW_DATABASE_PASSWORD}`,
      ],
    },
  }]),
  stderr: `diagnostic ${process.env.DW_DATABASE_PASSWORD}`,
};

fs.writeFileSync(process.argv[3], JSON.stringify(safeContainerInspectCommandRecord(record)));
JS;

            $process = proc_open(
                [
                    $nodeBinary,
                    '--input-type=module',
                    '-e',
                    $script,
                    'heartbeat-inspect-evidence-test',
                    $repoRoot.'/scripts/conformance/heartbeat-container-inspect-evidence.mjs',
                    $artifact,
                ],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                [
                    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                    'DW_AUTH_TOKEN' => $authSentinel,
                    'DW_DATABASE_PASSWORD' => $databaseSentinel,
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, $stderr ?: $stdout);
            $contents = (string) file_get_contents($artifact);
            $this->assertStringNotContainsString($authSentinel, $contents);
            $this->assertStringNotContainsString($databaseSentinel, $contents);

            $evidence = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            $this->assertTrue($evidence['raw_output_omitted']);
            $this->assertSame('sha256:'.str_repeat('a', 64), $evidence['inspection']['Image']);
            $this->assertSame(
                'durableworkflow/server:0.2.622',
                $evidence['inspection']['Config']['Image'],
            );
            $this->assertArrayNotHasKey('command', $evidence);
            $this->assertArrayNotHasKey('stdout', $evidence);
            $this->assertArrayNotHasKey('stderr', $evidence);
            $this->assertArrayNotHasKey('Env', $evidence['inspection']['Config']);
        } finally {
            if (is_file($artifact)) {
                unlink($artifact);
            }
        }
    }

    public function test_shell_handoff_requires_exact_release_pins_and_names_all_outputs(): void
    {
        $shell = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/conformance/heartbeats-published-artifacts.sh');

        $this->assertStringContainsString('DW_SERVER_VERSION', $shell);
        $this->assertStringContainsString('DW_CLI_VERSION', $shell);
        $this->assertStringContainsString('DW_WORKFLOW_PHP_VERSION', $shell);
        $this->assertStringContainsString('pins.json', $shell);
        $this->assertStringContainsString('run-metadata.json', $shell);
        $this->assertStringContainsString('php-sdk-heartbeat-loop-evidence.json', $shell);
        $this->assertStringContainsString('heartbeat-cadence-dataset.json', $shell);
        $this->assertStringContainsString('heartbeat-request-response-captures.json', $shell);
        $this->assertStringNotContainsString('DW_HEARTBEATS_SERVER_URL', $shell);
        $this->assertStringNotContainsString('DW_HEARTBEATS_CLI_BIN', $shell);
        $this->assertStringNotContainsString('DW_HEARTBEATS_CLI_SOURCE_URL', $shell);
    }

    public function test_python_shell_handoff_is_a_separate_focused_cell(): void
    {
        $shell = (string) file_get_contents(
            dirname(__DIR__, 2).'/scripts/conformance/heartbeats-python-published-artifacts.sh',
        );

        $this->assertStringContainsString('DW_HEARTBEATS_CELL=python', $shell);
        $this->assertStringContainsString('DW_SERVER_VERSION', $shell);
        $this->assertStringContainsString('DW_CLI_VERSION', $shell);
        $this->assertStringContainsString('DW_PYTHON_SDK_VERSION', $shell);
        $this->assertStringNotContainsString('DW_SDK_PYTHON_VERSION', $shell);
        $this->assertStringContainsString('python-sdk-heartbeat-loop-evidence.json', $shell);
        $this->assertStringNotContainsString('DW_WORKFLOW_PHP_VERSION', $shell);
        $this->assertStringNotContainsString('composer', strtolower($shell));
        $this->assertStringNotContainsString('waterline', strtolower($shell));
    }

    public function test_rust_shell_handoff_is_a_separate_focused_cell(): void
    {
        $shell = (string) file_get_contents(
            dirname(__DIR__, 2).'/scripts/conformance/heartbeats-rust-published-artifacts.sh',
        );

        $this->assertStringContainsString('DW_HEARTBEATS_CELL=rust', $shell);
        $this->assertStringContainsString('DW_SERVER_VERSION', $shell);
        $this->assertStringContainsString('DW_CLI_VERSION', $shell);
        $this->assertStringContainsString('DW_RUST_SDK_VERSION', $shell);
        $this->assertStringContainsString('rust-sdk-heartbeat-loop-evidence.json', $shell);
        $this->assertStringNotContainsString('DW_WORKFLOW_PHP_VERSION', $shell);
        $this->assertStringNotContainsString('python', strtolower($shell));
        $this->assertStringNotContainsString('waterline', strtolower($shell));
    }

    private function runnerSource(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2).'/scripts/conformance/heartbeats-published-artifacts.mjs',
        );
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

        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($path);
    }
}
