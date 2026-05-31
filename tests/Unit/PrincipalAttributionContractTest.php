<?php

namespace Tests\Unit;

use App\Support\PrincipalAttributionContract;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;

class PrincipalAttributionContractTest extends TestCase
{
    public function test_manifest_names_published_artifact_runner_handoff(): void
    {
        $manifest = PrincipalAttributionContract::manifest();

        $this->assertSame('durable-workflow.v2.principal-attribution.contract', $manifest['schema']);
        $this->assertSame(1, PrincipalAttributionContract::VERSION);
        $this->assertSame(PrincipalAttributionContract::VERSION, $manifest['version']);
        $this->assertSame(
            'durable-workflow.v2.principal-attribution-conformance.result',
            $manifest['result_schema'],
        );
        $this->assertSame('principal_attribution_contract', $manifest['fixture_category']);
        $this->assertSame(
            PlatformConformanceSuite::SCHEMA,
            $manifest['platform_conformance_suite_authority'],
        );
        $this->assertSame(
            PlatformConformanceSuite::VERSION,
            $manifest['scenario_manifest']['suite_version'],
        );
        $this->assertSame(
            'scripts/conformance/principal-attribution-published-artifacts.sh',
            $manifest['host_runner_contract']['runner_path'],
        );
        $this->assertTrue($manifest['host_runner_contract']['must_execute_against_published_artifacts']);
        $this->assertTrue($manifest['host_runner_contract']['must_record_runner_blocked_false_for_product_evidence']);
        $this->assertTrue($manifest['host_runner_contract']['must_attempt_spoofing_payloads_and_headers']);
        $this->assertContains(
            'runner_blocked_false_for_product_evidence',
            $manifest['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertSame(
            ['WorkflowCompleted', 'WorkflowFailed'],
            $manifest['worker_terminal_event_policy']['events'],
        );
        $this->assertSame(
            ['type' => 'auth:token', 'id' => 'worker:principal-attribution'],
            $manifest['worker_terminal_event_policy']['expected_authenticated_worker_principal'],
        );
        $this->assertSame([], $manifest['worker_terminal_event_policy']['documented_system_principals']);

        foreach (['server', 'cli', 'workflow-php', 'sdk-python', 'waterline'] as $artifact) {
            $this->assertArrayHasKey($artifact, $manifest['artifact_policy']['install_channels']);
        }
    }

    public function test_manifest_names_required_audit_scenarios(): void
    {
        $manifest = PrincipalAttributionContract::manifest();

        foreach ([
            'published_artifact_install_only',
            'named_token_actor_matrix',
            'start_signal_cancel_spoofing',
            'query_attribution',
            'completion_failure_attribution',
            'server_originated_events',
            'anonymous_attribution',
            'python_sdk_visibility',
            'php_client_visibility',
            'cli_operator_visibility',
            'waterline_operator_visibility',
        ] as $scenario) {
            $this->assertContains($scenario, $manifest['required_scenarios']);
            $this->assertArrayHasKey($scenario, $manifest['scenario_requirements']);
        }

        $this->assertSame(
            $manifest['required_scenarios'],
            array_keys($manifest['scenario_requirements']),
            'every required principal-attribution scenario must declare evidence fields',
        );

        $this->assertContains(
            'expected_worker_principal',
            $manifest['scenario_requirements']['completion_failure_attribution']['required_fields'],
        );
        $this->assertContains(
            'documented_system_principals',
            $manifest['scenario_requirements']['completion_failure_attribution']['required_fields'],
        );
    }

    public function test_scenario_manifest_source_path_matches_contract(): void
    {
        $manifest = PrincipalAttributionContract::manifest();
        $scenarioManifestPath = dirname(__DIR__, 2).'/'.$manifest['scenario_manifest']['source_path'];

        $this->assertFileExists($scenarioManifestPath);

        $scenarioManifest = json_decode(
            (string) file_get_contents($scenarioManifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame($manifest['scenario_manifest']['schema'], $scenarioManifest['schema']);
        $this->assertSame($manifest['scenario_manifest']['category'], $scenarioManifest['category']);
        $this->assertSame($manifest['scenario_manifest']['suite_schema'], $scenarioManifest['suite_schema']);
        $this->assertSame($manifest['scenario_manifest']['suite_version'], $scenarioManifest['suite_version']);
        $this->assertSame($manifest['scenario_statuses'], $scenarioManifest['result_statuses']);
        $this->assertSame($manifest['required_scenarios'], array_column($scenarioManifest['scenarios'], 'id'));
        $this->assertSame(
            array_keys($manifest['scenario_requirements']),
            array_keys($scenarioManifest['scenario_requirements']),
        );
        $this->assertSame(
            $manifest['worker_terminal_event_policy'],
            $scenarioManifest['worker_terminal_event_policy'],
        );
    }

    public function test_published_artifact_runner_fails_closed_for_required_evidence(): void
    {
        $manifest = PrincipalAttributionContract::manifest();
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/'.$manifest['host_runner_contract']['runner_path']);

        $this->assertStringContainsString('artifact-install-evidence.json', $script);
        $this->assertStringContainsString('install_status_and_findings', $script);
        $this->assertStringContainsString('scenario(install_status, "published_artifact_install_only"', $script);
        $this->assertStringNotContainsString('scenario("pass", "published_artifact_install_only"', $script);

        $this->assertStringContainsString('recorded_query_principal = principal_from_query_observation(query_observation)', $script);
        $this->assertStringContainsString('principal_id(recorded_query_principal) != "bob"', $script);
        $this->assertStringContainsString('recorded_principal=recorded_query_principal', $script);
        $this->assertStringNotContainsString('recorded_principal=None', $script);
        $this->assertStringContainsString('def missing_required_principal(scenario_id: str, reason: str)', $script);
        $this->assertStringContainsString('"classification": "coverage_gap_not_observed"', $script);
        $this->assertStringContainsString('"required_named_principal": True', $script);
        $this->assertStringContainsString('python_sdk_gap = "Python SDK client operation was not exercised by this runner revision"', $script);
        $this->assertStringContainsString(
            'recorded_principal=missing_required_principal("python_sdk_visibility", python_sdk_gap)',
            $script,
        );
        $this->assertStringContainsString('php_client_gap = "PHP client operation was not exercised by this runner revision"', $script);
        $this->assertStringContainsString(
            'recorded_principal=missing_required_principal("php_client_visibility", php_client_gap)',
            $script,
        );

        $this->assertStringContainsString('"--output=json"', $script);
        $this->assertStringContainsString('--output=json', $script);
        $this->assertStringNotContainsString('"--json"', $script);
        $this->assertStringNotContainsString(' --json', $script);

        $this->assertStringContainsString('expected_worker_principal = {"id": "worker:principal-attribution", "type": "auth:token"}', $script);
        $this->assertStringContainsString('documented_system_principals: list[dict[str, str]] = []', $script);
        $this->assertStringContainsString('principal_matches(completion_event_principal, expected_worker_principal)', $script);
        $this->assertStringContainsString('principal_matches(failure_event_principal, expected_worker_principal)', $script);
        $this->assertStringContainsString('worker_principal=expected_worker_principal', $script);
        $this->assertStringContainsString('documented_system_principals=documented_system_principals', $script);
        $this->assertStringContainsString('"claims":{}', $script);
        $this->assertStringContainsString('COMPLETE_TASK_QUEUE = f"{TASK_QUEUE_BASE}-complete"', $script);
        $this->assertStringContainsString('FAIL_TASK_QUEUE = f"{TASK_QUEUE_BASE}-fail"', $script);
        $this->assertStringContainsString('register_worker(COMPLETE_WORKER_ID, COMPLETE_TASK_QUEUE)', $script);
        $this->assertStringContainsString('poll_workflow_task(COMPLETE_WORKER_ID, COMPLETE_TASK_QUEUE, expected_workflow_id=complete_id)', $script);
        $this->assertStringContainsString('poll_workflow_task(FAIL_WORKER_ID, FAIL_TASK_QUEUE, expected_workflow_id=fail_id)', $script);
        $this->assertStringNotContainsString(
            'completion_failure_status = "pass" if isinstance(completion_event_principal, dict) and isinstance(failure_event_principal, dict) else "fail"',
            $script,
        );
    }

    public function test_published_artifact_runner_reports_current_suite_version_from_scenario_manifest(): void
    {
        $manifest = PrincipalAttributionContract::manifest();
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/'.$manifest['host_runner_contract']['runner_path']);

        $this->assertStringContainsString('principal_scenario_manifest=', $script);
        $this->assertStringContainsString('principal_suite_version="$(read_principal_suite_version)"', $script);
        $this->assertStringContainsString('"suite_version": $principal_suite_version', $script);
        $this->assertStringContainsString('"suite_version": SUITE_VERSION', $script);
        $this->assertStringContainsString('PRINCIPAL_ATTRIBUTION_SUITE_VERSION="$principal_suite_version"', $script);
        $this->assertStringNotContainsString('"suite_version": 12', $script);
    }

    public function test_published_artifact_runner_blocked_result_preserves_required_result_shape(): void
    {
        if (! is_file('/bin/bash')) {
            $this->markTestSkipped('bash is required to exercise the conformance runner handoff.');
        }

        $manifest = PrincipalAttributionContract::manifest();
        $repoRoot = dirname(__DIR__, 2);
        $scriptPath = $repoRoot.'/'.$manifest['host_runner_contract']['runner_path'];
        $scenarioManifestPath = $repoRoot.'/'.$manifest['scenario_manifest']['source_path'];
        $scenarioManifest = json_decode(
            (string) file_get_contents($scenarioManifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $tempRoot = sys_get_temp_dir().'/dw-principal-attribution-blocked-'.bin2hex(random_bytes(6));
        $binDir = $tempRoot.'/bin';
        $resultDir = $tempRoot.'/result';
        $runRoot = $tempRoot.'/run';

        try {
            mkdir($binDir, 0777, true);
            mkdir($resultDir, 0777, true);

            foreach (['basename', 'cat', 'date', 'dirname', 'head', 'mkdir', 'pwd', 'sed', 'tr'] as $command) {
                $this->linkSystemCommand($binDir, $command);
            }

            $process = proc_open(
                ['/bin/bash', $scriptPath, '--result-dir', $resultDir],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                [
                    'PATH' => $binDir,
                    'DW_PRINCIPAL_ATTRIBUTION_RUN_ROOT' => $runRoot,
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(1, $exitCode, $stdout.$stderr);

            $resultPath = $resultDir.'/principal-attribution-result.json';
            $this->assertFileExists($resultPath);

            $result = json_decode(
                (string) file_get_contents($resultPath),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('error', $result['outcome']);
            $this->assertTrue($result['runner_blocked']);

            foreach ($scenarioManifest['required_top_level_fields'] as $requiredField) {
                $this->assertArrayHasKey($requiredField, $result);
            }

            $scenarioResults = array_column($result['scenario_results'], null, 'scenario_id');
            foreach ($scenarioManifest['scenario_requirements'] as $scenarioId => $requirements) {
                $this->assertArrayHasKey($scenarioId, $scenarioResults);
                $this->assertSame('runner_blocked', $scenarioResults[$scenarioId]['status']);

                foreach ($requirements['required_fields'] as $requiredField) {
                    $this->assertArrayHasKey($requiredField, $scenarioResults[$scenarioId]);
                }
            }

            $this->assertFalse($scenarioResults['published_artifact_install_only']['local_product_source_checkouts_used']);
            $this->assertSame([], $result['history_dumps']);
            $this->assertSame([], $result['spoofing_attempts']['payload_values']);
            $this->assertFalse($result['spoofing_attempts']['executed']);
            $this->assertSame('runner_blocked', $result['anonymous_observations']['status']);
        } finally {
            $this->removeTree($tempRoot);
        }
    }

    public function test_published_artifact_runner_non_pass_findings_include_versioned_routing_fields(): void
    {
        $manifest = PrincipalAttributionContract::manifest();
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/'.$manifest['host_runner_contract']['runner_path']);

        $this->assertStringContainsString(
            'def current_artifact_versions()',
            $script,
            'principal-attribution findings must resolve the published artifact tuple under test',
        );
        $this->assertStringContainsString(
            '"scenario_id": scenario_id',
            $script,
            'principal-attribution findings must preserve the scenario identity',
        );
        $this->assertStringContainsString(
            '"owning_surface": surface',
            $script,
            'principal-attribution findings must route to the owning public surface',
        );
        $this->assertStringContainsString(
            '"artifact_versions": current_artifact_versions()',
            $script,
            'principal-attribution findings must carry the published artifact tuple under test',
        );
        $this->assertStringContainsString(
            '"observed_behavior": observed',
            $script,
            'principal-attribution findings must describe the observed behavior',
        );
        $this->assertStringContainsString(
            '"next_acceptance_criterion": next_acceptance',
            $script,
            'principal-attribution findings must name the next criterion for turning the scenario green',
        );
    }

    private function linkSystemCommand(string $binDir, string $command): void
    {
        foreach (['/usr/bin', '/bin', '/usr/local/bin'] as $prefix) {
            $candidate = $prefix.'/'.$command;
            if (is_file($candidate) && is_executable($candidate)) {
                symlink($candidate, $binDir.'/'.$command);

                return;
            }
        }

        $this->markTestSkipped("required command {$command} is not available.");
    }

    private function removeTree(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
