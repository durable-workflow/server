<?php

namespace Tests\Unit;

use App\Support\PythonSdkParityContract;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;

class PythonSdkParityContractTest extends TestCase
{
    public function test_manifest_names_full_published_artifact_handoff(): void
    {
        $manifest = PythonSdkParityContract::manifest();

        $this->assertSame('durable-workflow.v2.python-sdk-parity.contract', $manifest['schema']);
        $this->assertSame(PythonSdkParityContract::VERSION, $manifest['version']);
        $this->assertSame(
            'durable-workflow.v2.python-sdk-parity.result',
            $manifest['result_schema'],
        );
        $this->assertSame(
            PlatformConformanceSuite::SCHEMA,
            $manifest['platform_conformance_suite_authority'],
        );
        $this->assertSame(
            'durable_workflow.python_conformance',
            $manifest['python_result_gate_authority']['module'],
        );
        $this->assertSame(
            'scripts/conformance/python-published-artifacts.sh',
            $manifest['host_runner_contract']['runner_path'],
        );
        $this->assertTrue($manifest['host_runner_contract']['must_execute_against_published_artifacts']);
        $this->assertTrue($manifest['host_runner_contract']['must_emit_complete_capability_table']);
        $this->assertTrue($manifest['host_runner_contract']['must_compose_with_installed_sdk_result_gate']);
        $this->assertContains(
            'scripts/conformance/python_external_payload_evidence.py',
            $manifest['host_runner_contract']['support_files'],
        );
        $this->assertSame(
            'non_passing',
            $manifest['coverage_gate']['smoke_subset_outcome'],
        );

        foreach (['server', 'cli', 'sdk-python', 'workflow', 'waterline'] as $artifact) {
            $this->assertArrayHasKey($artifact, $manifest['artifact_policy']['install_channels']);
        }
    }

    public function test_manifest_requires_the_expanded_python_parity_surface(): void
    {
        $manifest = PythonSdkParityContract::manifest();

        foreach ([
            'published_artifact_install_only',
            'official_cli_install_start_result_path',
            'cold_first_user_setup',
            'python_worker_registration',
            'activity_backed_workflow_execution',
            'workflow_result_surface',
            'worker_restart_activity_and_signal_state',
            'runtime_external_payload_round_trips',
            'protocol_trace_capture',
            'php_assumption_audit',
            'capability_table_complete',
        ] as $scenario) {
            $this->assertContains($scenario, $manifest['required_scenarios']);
        }

        foreach ([
            'official_cli_installed',
            'cli_starts_workflow',
            'cli_reads_workflow_result',
            'python_sdk_installed_from_pypi',
            'worker_restart_replays_activity_state',
            'worker_restart_replays_signal_state',
            'runtime_external_payload_inline_round_trip',
            'runtime_external_payload_externalized_round_trip',
            'runtime_external_payload_cross_language_round_trip',
            'runtime_external_payload_standalone_server',
            'runtime_external_payload_isolated_cloud',
            'runtime_external_payload_provider_setup_absent',
            'protocol_traces_recorded',
            'php_assumptions_absent',
        ] as $capability) {
            $this->assertContains($capability, $manifest['required_capabilities']);
        }

        $this->assertContains(
            'official-cli-install-start-result',
            $manifest['host_runner_contract']['required_execution_scopes'],
        );
        $this->assertContains(
            'control-plane-protocol-traces',
            $manifest['host_runner_contract']['required_execution_scopes'],
        );
        $this->assertContains(
            'runtime-mediated-external-payload-round-trips',
            $manifest['host_runner_contract']['required_execution_scopes'],
        );
        $this->assertSame(
            'DW_PYTHON_CONFORMANCE_CLOUD_EVIDENCE_JSON',
            $manifest['host_runner_contract']['runtime_external_payload_contract']['isolated_cloud_handoff']['environment_variable'],
        );
        $this->assertTrue(
            $manifest['host_runner_contract']['runtime_external_payload_contract']['isolated_cloud_handoff']['must_match_exact_artifact_tuple'],
        );
        $this->assertTrue(
            $manifest['host_runner_contract']['runtime_external_payload_contract']['isolated_cloud_handoff']['standalone_inference_forbidden'],
        );
        $this->assertSame(
            'conformance_runner_coverage_gap',
            $manifest['host_runner_contract']['routing_policy']['missing_required_scenario']['finding_type'],
        );
    }

    public function test_runner_script_composes_and_evaluates_with_installed_sdk_contract(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/conformance/python-published-artifacts.sh');

        $this->assertStringContainsString('durable-workflow-python-conformance --compose', $script);
        $this->assertStringContainsString('durable-workflow-python-conformance --evaluate', $script);
        $this->assertStringContainsString('workflow:start', $script);
        $this->assertStringContainsString('workflow:show-run', $script);
        $this->assertStringContainsString('protocol_traces', $script);
        $this->assertStringContainsString('php_assumption_audit', $script);
        $this->assertStringContainsString('local_product_source_checkouts_used', $script);
        $this->assertStringContainsString('runtime_external_payload_round_trips', $script);
        $this->assertStringContainsString('DW_PYTHON_CONFORMANCE_CLOUD_EVIDENCE_JSON', $script);
        $this->assertStringContainsString('"$run_root/cli/config"', $script);
        $this->assertStringContainsString('DW_CONFIG_HOME="$run_root/cli/config"', $script);
    }

    public function test_runtime_external_payload_evidence_regression_fixture(): void
    {
        $python = trim((string) shell_exec('command -v python3 2>/dev/null'));
        if ($python === '') {
            $this->markTestSkipped('python3 is required to exercise the external payload evidence fixture.');
        }

        $pipes = [];
        $process = proc_open(
            [
                $python,
                '-m',
                'unittest',
                'tests/Unit/Support/python_external_payload_evidence_test.py',
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

        $this->assertSame(0, $exitCode, $stdout.$stderr);
    }

    public function test_runner_uses_managed_stop_as_the_orderly_deregistration_boundary(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/conformance/python-published-artifacts.sh');
        $absenceContract = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/conformance/python_worker_stop_deregistration.py');

        $stop = strpos($script, 'await stop_worker(worker1, worker1_task)');
        $absence = strpos($script, 'initial_worker_absence = await verify_stopped_worker_absent(');
        $signal = strpos($script, 'await client.signal_workflow(workflow_id, "approve"');
        $replacement = strpos($script, 'terminal = await worker2.run_until(');

        $this->assertIsInt($stop);
        $this->assertIsInt($absence);
        $this->assertIsInt($signal);
        $this->assertIsInt($replacement);
        $this->assertLessThan($absence, $stop);
        $this->assertLessThan($signal, $absence);
        $this->assertLessThan($replacement, $signal);
        $this->assertStringNotContainsString('await client.deregister_worker(worker1_id)', $script);
        $this->assertStringContainsString('inventory = await client.list_workers()', $absenceContract);
        $this->assertStringContainsString('detail = await client.describe_worker(worker_id)', $absenceContract);
        $this->assertStringContainsString('status != 404', $absenceContract);
        $this->assertStringContainsString('{"worker_not_found", "not_found"}', $absenceContract);
        $this->assertStringContainsString('python-parity-phase-evidence.json', $script);
        $this->assertStringContainsString('worker-protocol-traces.json', $script);
    }

    public function test_managed_stop_absence_regression_fixture(): void
    {
        $python = trim((string) shell_exec('command -v python3 2>/dev/null'));
        if ($python === '') {
            $this->markTestSkipped('python3 is required to exercise the managed-stop absence fixture.');
        }

        $pipes = [];
        $process = proc_open(
            [
                $python,
                '-m',
                'unittest',
                'tests/Unit/Support/python_worker_stop_deregistration_test.py',
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

        $this->assertSame(0, $exitCode, $stdout.$stderr);
    }

    public function test_runner_bootstraps_the_shared_sqlite_database_queue_before_starting_server_processes(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/conformance/python-published-artifacts.sh');
        $bootstrapCommand = 'docker compose -p "$compose_project" -f "$run_root/compose.yml" run --rm server server-bootstrap';
        $startCommand = 'docker compose -p "$compose_project" -f "$run_root/compose.yml" up -d';

        foreach ([
            'DB_CONNECTION: sqlite',
            'DB_DATABASE: /app/database/database.sqlite',
            'QUEUE_CONNECTION: database',
            'server-queue-worker:',
            'command: ["php", "artisan", "queue:work"',
            'server-db:/app/database',
            'server-bootstrap.log',
        ] as $needle) {
            $this->assertStringContainsString($needle, $script);
        }

        $this->assertGreaterThanOrEqual(2, substr_count($script, 'server-db:/app/database'));

        $bootstrapPosition = strpos($script, $bootstrapCommand);
        $startPosition = strpos($script, $startCommand);

        $this->assertIsInt($bootstrapPosition);
        $this->assertIsInt($startPosition);
        $this->assertTrue(
            $bootstrapPosition < $startPosition,
            'The shared SQLite volume must be bootstrapped before the HTTP server and queue worker start.',
        );
    }

    public function test_runner_resolves_cli_from_github_release_asset(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/conformance/python-published-artifacts.sh');

        $this->assertStringContainsString('github_release_with_downloadable_asset("durable-workflow/cli", env("DW_CLI_VERSION"), "install.sh")', $script);
        $this->assertStringContainsString('https://api.github.com/repos/{repo}/releases/tags/{tag}', $script);
        $this->assertStringContainsString('asset_download_url(release, required_asset_name)', $script);
        $this->assertStringNotContainsString('f"v{override}"', $script);
    }

    public function test_runner_installs_pinned_composer_prereleases_under_explicit_policy(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/conformance/python-published-artifacts.sh');

        $this->assertStringContainsString('"minimum-stability": "alpha"', $script);
        $this->assertStringContainsString('"prefer-stable": true', $script);
        $this->assertStringContainsString(
            'write_prerelease_composer_manifest "$run_root/artifacts/workflow"',
            $script,
        );
        $this->assertStringContainsString(
            'write_prerelease_composer_manifest "$run_root/artifacts/waterline"',
            $script,
        );
        $composerRequire = <<<'SH'
                "durable-workflow/workflow:$workflow_version" \
                "durable-workflow/waterline:$waterline_version"
            SH;
        $this->assertStringContainsString($composerRequire, $script);
        $this->assertStringNotContainsString('"repositories"', $script);
    }
}
