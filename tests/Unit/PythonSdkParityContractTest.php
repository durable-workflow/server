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
    }

    public function test_runner_resolves_cli_from_github_release_asset(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/conformance/python-published-artifacts.sh');

        $this->assertStringContainsString('github_release_with_downloadable_asset("durable-workflow/cli", env("DW_CLI_VERSION"), "install.sh")', $script);
        $this->assertStringContainsString('https://api.github.com/repos/{repo}/releases/tags/{tag}', $script);
        $this->assertStringContainsString('asset_download_url(release, required_asset_name)', $script);
        $this->assertStringNotContainsString('f"v{override}"', $script);
    }
}
