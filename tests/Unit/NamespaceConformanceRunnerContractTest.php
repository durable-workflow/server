<?php

namespace Tests\Unit;

use App\Support\NamespaceRuntimeContract;
use App\Support\NamespaceRuntimeResultGate;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;

class NamespaceConformanceRunnerContractTest extends TestCase
{
    public function test_mutating_bind_mount_containers_use_the_invoking_user(): void
    {
        $source = $this->read('scripts/conformance/namespaces-published-artifacts.sh');

        $this->assertStringContainsString('for command_name in docker curl id;', $source);
        $this->assertStringContainsString('host_uid_gid="$(id -u):$(id -g)"', $source);
        $this->assertSame(6, substr_count($source, '--user "$host_uid_gid"'));
    }

    public function test_runner_handoff_names_full_namespace_surface(): void
    {
        $source = $this->read('scripts/conformance/namespaces-published-artifacts.sh');

        $this->assertStringContainsString(
            'Usage: namespaces-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]',
            $source,
        );
        $this->assertStringContainsString('durable-workflow.v2.namespace-runtime.result', $source);
        $this->assertStringContainsString('php-sdk-published-artifacts', $source);
        $this->assertStringContainsString('waterline:namespace-conformance', $source);
        $this->assertStringContainsString('DW_NAMESPACES_SDK_PHP_RESULT  Optional pre-generated JSON report', $source);
        $this->assertStringContainsString('If unset, the runner installs the published PHP SDK artifact and runs this shard.', $source);
        $this->assertStringContainsString('If unset, the runner installs the published Waterline artifact and runs this shard.', $source);
        $this->assertStringContainsString('load_required_shard', $source);
        $this->assertStringContainsString('sdk_php_shard_execution', $source);
        $this->assertStringContainsString('required {scope} report was not supplied', $source);
        $this->assertStringContainsString('validate_shard_report', $source);
        $this->assertStringContainsString('artifact_version_mismatches', $source);
        $this->assertStringContainsString('SDK_PHP_REQUIRED_SCENARIOS = [', $source);
        $this->assertStringContainsString('"namespace_create_update_describe_and_list",', $source);
        $this->assertStringContainsString('"sdk_namespace_selection_parity",', $source);
        $this->assertStringContainsString('"php_worker_task_queue_namespace_isolation",', $source);

        foreach ([
            'published_artifact_install_only',
            'namespace_create_update_describe_and_list',
            'workflow_cross_namespace_visibility_isolation',
            'workflow_cross_namespace_mutation_isolation',
            'php_worker_task_queue_namespace_isolation',
            'cli_namespace_context_and_default_scope',
            'sdk_namespace_selection_parity',
            'search_attribute_schema_and_value_query_isolation',
            'schedule_namespace_isolation',
            'namespace_lifecycle_cleanup_and_recreate',
            'waterline_operator_namespace_visibility',
            'nexus_explicit_cross_namespace_invocation',
            'reserved_namespace_name_refusal',
            'result_record_and_product_finding_routing',
        ] as $scenario) {
            $this->assertStringContainsString($scenario, $source);
        }

        foreach ([
            'namespace_lifecycle_cleanup',
            'nexus_cross_namespace',
            'cli_namespace_behavior',
            'sdk_namespace_selection',
            'php_worker_behavior',
            'waterline_operator_visibility',
            'search_attribute_value_query_isolation',
        ] as $section) {
            $this->assertStringContainsString($section, $source);
        }

        foreach ([
            'parse_cli_json',
            'cli_namespace_resource_json_is',
            'cli_namespace_list_contains_resource',
            '"namespace:create"',
            '"namespace:describe"',
            '"namespace:update"',
            '"namespace:list"',
            '"namespace:delete"',
            '"workflow:list"',
            '"schedule:list"',
            '"search-attribute:list"',
            '"namespace_crud":',
            '"workflow_list": cli_probe(cli_workflow_explicit_json)',
            '"schedule_list": cli_probe(cli_schedule_explicit_json)',
            '"search_attribute_list": {',
            '"tenant_a": cli_probe(cli_search_attribute_tenant_a_json)',
            '"tenant_b": cli_probe(cli_search_attribute_tenant_b_json)',
            '"expected_namespace": "default"',
            '"tenant_resources_checked":',
        ] as $cliEvidenceNeedle) {
            $this->assertStringContainsString($cliEvidenceNeedle, $source);
        }
    }

    public function test_runner_validates_attached_shard_scope_and_artifact_versions_before_import(): void
    {
        $source = $this->read('scripts/conformance/namespaces-published-artifacts.sh');

        $this->assertStringContainsString(
            'REQUIRED_SHARD_ARTIFACTS = ["server", "cli", "workflow-php", "sdk-php", "sdk-python", "waterline"]',
            $source,
        );
        $this->assertStringContainsString('def validate_shard_report(', $source);
        $this->assertStringContainsString('coverage_scope != scope', $source);
        $this->assertStringContainsString('shard_artifact_versions(payload)', $source);
        $this->assertStringContainsString(
            'report artifact_versions did not match resolved pins',
            $source,
        );
        $this->assertStringContainsString(
            'run {command} with the artifact-version values from pins.json',
            $source,
        );
        $this->assertStringContainsString(
            'waterline-operator-namespace-shard',
            $source,
            'Waterline shard reports must be scope-validated before their scenario row is imported.',
        );
        $this->assertStringContainsString(
            '"php-sdk-published-artifacts",',
            $source,
        );
        $this->assertStringContainsString(
            '"waterline:namespace-conformance",',
            $source,
        );
    }

    public function test_runner_executes_published_waterline_namespace_shard_when_not_pre_supplied(): void
    {
        $source = $this->read('scripts/conformance/namespaces-published-artifacts.sh');

        $this->assertStringContainsString('waterline_result_path="${DW_NAMESPACES_WATERLINE_RESULT:-}"', $source);
        $this->assertStringContainsString('waterline_result_path="$result_dir/waterline-namespace-result.json"', $source);
        $this->assertStringContainsString('composer create-project laravel/laravel . --no-interaction --no-progress', $source);
        $this->assertStringContainsString('"durable-workflow/waterline:${waterline_version}@beta"', $source);
        $this->assertStringContainsString('"durable-workflow/workflow:${workflow_php_version}@beta"', $source);
        $this->assertStringContainsString('"durable-workflow/sdk:${sdk_php_version}@beta"', $source);
        $this->assertStringContainsString('composer:2 php artisan migrate --force', $source);
        $this->assertStringContainsString('composer:2 php artisan waterline:namespace-conformance', $source);
        $this->assertStringContainsString('--output /result/waterline-namespace-result.json', $source);
        $this->assertStringContainsString('DW_NAMESPACES_WATERLINE_RESULT="$waterline_result_path"', $source);
        $this->assertStringContainsString('"required": True', $source);
        $this->assertStringContainsString('"required_scenarios": ["waterline_operator_namespace_visibility"]', $source);
        $this->assertStringContainsString('"scenario_statuses": {"waterline_operator_namespace_visibility": scenario_status}', $source);
        $this->assertStringContainsString('"status": "executed" if waterline_execution["status"] == "executed"', $source);
        $this->assertStringContainsString('"waterline_shard_execution": waterline_execution', $source);
        $this->assertStringContainsString('write_waterline_setup_failure', $source);
        $this->assertStringContainsString('Waterline namespace shard could not run in the published-artifact harness', $source);
    }

    public function test_runner_executes_published_sdk_php_namespace_shard_when_not_pre_supplied(): void
    {
        $source = $this->read('scripts/conformance/namespaces-published-artifacts.sh');
        $reportSource = $this->read('scripts/conformance/php-sdk-namespace-shard-report.py');

        $this->assertStringContainsString('sdk_php_result_path="${DW_NAMESPACES_SDK_PHP_RESULT:-}"', $source);
        $this->assertStringContainsString('sdk_php_result_path="$result_dir/sdk-php-namespace-result.json"', $source);
        $this->assertStringContainsString('--network "container:${server_container_id}"', $source);
        $this->assertStringContainsString('-e DW_PHP_SDK_VERSION="$sdk_php_version"', $source);
        $this->assertStringContainsString('-e DW_PHP_SDK_CONFORMANCE_SERVER_URL="http://127.0.0.1:8080"', $source);
        $this->assertStringContainsString('-e DW_PHP_SDK_CONFORMANCE_WORKER_TOKEN=worker-token', $source);
        $this->assertStringContainsString('scripts/conformance/php-sdk-published-artifacts.sh --scope namespace --result-dir /result', $source);
        $this->assertStringContainsString('"namespace_selection"', $reportSource);
        $this->assertStringContainsString('php-sdk-namespace-shard-report.py', $source);
        $this->assertStringContainsString('DW_NAMESPACES_SDK_PHP_RESULT="$sdk_php_result_path"', $source);
        $this->assertStringContainsString('write_sdk_php_setup_failure', $source);
        $this->assertStringContainsString('PHP SDK namespace shard could not run in the published-artifact harness', $source);
    }

    public function test_php_namespace_shard_preserves_completed_rows_after_an_unrelated_failure(): void
    {
        $report = $this->renderPhpNamespaceShardReport(
            [
                'runner_blocked' => false,
                'outcome' => 'fail',
                'findings' => [[
                    'owning_surface' => 'server',
                    'failure_stage' => 'signal_query',
                    'summary' => 'An unrelated signal/query operation failed after namespace evidence was complete.',
                ]],
            ],
            [
                'namespace_lifecycle' => true,
                'namespace_selection' => true,
                'worker_namespace_registration' => true,
                'namespace_worker_execution' => true,
                'distinct_client_worker_processes' => true,
            ],
        );

        $this->assertSame('pass', $report['outcome']);
        $this->assertSame([], $report['findings']);
        $this->assertSame(
            ['pass', 'pass', 'pass'],
            array_column($report['scenario_results'], 'status'),
        );
    }

    public function test_php_namespace_shard_keeps_specific_server_diagnostics(): void
    {
        $summary = 'The released PHP SDK probe received a server HTTP failure during namespace_client.';
        $report = $this->renderPhpNamespaceShardReport(
            [
                'runner_blocked' => false,
                'outcome' => 'fail',
                'findings' => [[
                    'owning_surface' => 'server',
                    'classification' => 'server',
                    'failure_stage' => 'namespace_client',
                    'summary' => $summary,
                    'diagnostic' => [
                        'path' => 'php-sdk-client-namespace.diagnostic.log',
                        'excerpt' => 'HTTP/1.1 500 Internal Server Error',
                    ],
                ]],
            ],
            [
                'namespace_lifecycle' => false,
                'namespace_selection' => true,
                'worker_namespace_registration' => true,
                'namespace_worker_execution' => true,
                'distinct_client_worker_processes' => true,
            ],
        );

        $rows = array_column($report['scenario_results'], null, 'scenario_id');
        $this->assertSame('fail', $rows['namespace_create_update_describe_and_list']['status']);
        $this->assertSame('pass', $rows['sdk_namespace_selection_parity']['status']);
        $this->assertSame('server', $rows['namespace_create_update_describe_and_list']['linked_findings'][0]['owning_surface']);
        $this->assertSame($summary, $rows['namespace_create_update_describe_and_list']['linked_findings'][0]['observed_behavior']);
        $this->assertSame(
            'HTTP/1.1 500 Internal Server Error',
            $rows['namespace_create_update_describe_and_list']['linked_findings'][0]['diagnostic']['excerpt'],
        );
        $this->assertSame(
            'sdk-php-namespace-probe/php-sdk-client-namespace.diagnostic.log',
            $rows['namespace_create_update_describe_and_list']['linked_findings'][0]['diagnostic']['path'],
        );
    }

    public function test_runner_reports_suite_version_from_namespace_scenario_manifest(): void
    {
        $source = $this->read('scripts/conformance/namespaces-published-artifacts.sh');
        $manifest = json_decode(
            $this->read('static/platform-conformance/namespace-runtime-scenarios.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertStringContainsString(
            'namespace_scenario_manifest="${DW_NAMESPACES_SCENARIO_MANIFEST:-$repo_root/static/platform-conformance/namespace-runtime-scenarios.json}"',
            $source,
            'the runner must use the advertised namespace scenario manifest as its suite-version source',
        );
        $this->assertSame(
            PlatformConformanceSuite::VERSION,
            $manifest['suite_version'],
            'the shipped namespace runner handoff must stay aligned with the installed platform conformance suite version',
        );
        $this->assertStringContainsString(
            'namespace_suite_version="${DW_NAMESPACES_SUITE_VERSION:-$(read_namespace_suite_version)}"',
            $source,
            'the runner must resolve suite_version from the manifest unless the host overrides it',
        );
        $this->assertStringNotContainsString(
            'namespace_suite_version="${DW_NAMESPACES_SUITE_VERSION:-15}"',
            $source,
            'the namespace runner must not hardcode a suite version that can drift from the public manifest',
        );
    }

    public function test_runner_requires_all_sdk_php_namespace_shard_rows_before_passing_php_backed_cells(): void
    {
        $source = $this->read('scripts/conformance/namespaces-published-artifacts.sh');

        $this->assertStringContainsString('def load_sdk_php_shard(', $source);
        $this->assertStringContainsString('for scenario_id in SDK_PHP_REQUIRED_SCENARIOS:', $source);
        $this->assertStringContainsString('sdk_php_items.get("namespace_create_update_describe_and_list")', $source);
        $this->assertStringContainsString('sdk_php_items.get("sdk_namespace_selection_parity")', $source);
        $this->assertStringContainsString('sdk_php_items.get("php_worker_task_queue_namespace_isolation")', $source);
        $this->assertStringContainsString('"sdk_php_namespace_crud": scenario_observed_outputs(crud_php_item)', $source);
        $this->assertStringContainsString('"php_client_namespace": sdk_php_outputs.get("php_client_namespace")', $source);
        $this->assertStringContainsString('"covered_scenarios": sorted(items.keys())', $source);
        $this->assertStringNotContainsString('"php_client_namespace": "php-sdk-published-artifacts"', $source);
    }

    public function test_runner_routes_missing_namespace_shards_as_focused_unsupported_surface_findings(): void
    {
        $source = $this->read('scripts/conformance/namespaces-published-artifacts.sh');

        $this->assertStringContainsString('finding_type: str | None = None', $source);
        $this->assertStringContainsString('scenario_status: str | None = None', $source);
        $this->assertStringContainsString('"finding_type": "unsupported_public_surface"', $source);
        $this->assertStringContainsString('"scenario_status": "unsupported"', $source);
        $this->assertStringContainsString(
            'the PHP SDK namespace mirror cell remains focused unsupported evidence',
            $source,
        );
        $this->assertStringContainsString('return status if status in ALLOWED_SCENARIO_STATUSES else "not_covered"', $source);
        $this->assertStringContainsString('finding_type="unsupported_public_surface"', $source);
        $this->assertStringContainsString('scenario_status="unsupported"', $source);
    }

    public function test_runner_exercises_cross_namespace_schedule_mutation_not_only_describe(): void
    {
        $source = $this->read('scripts/conformance/namespaces-published-artifacts.sh');

        $this->assertStringContainsString('sched_b_pause_a = request(', $source);
        $this->assertStringContainsString('"POST"', $source);
        $this->assertStringContainsString('f"/schedules/{sched_a_id}/pause"', $source);
        $this->assertStringContainsString('"cross_namespace_schedule_mutation_denied": sched_b_pause_a', $source);
        $this->assertStringContainsString('"cross_namespace_schedule_describe_denied": sched_b_describe_a', $source);
        $this->assertStringNotContainsString('"cross_namespace_schedule_mutation_denied": sched_b_describe_a', $source);
    }

    public function test_runner_records_namespace_deletion_cleanup_surfaces_before_and_after_recreate(): void
    {
        $source = $this->read('scripts/conformance/namespaces-published-artifacts.sh');

        foreach ([
            'cleanup_pre_delete_resources = {',
            'cleanup_retained_resources = {',
            'post_delete_refusals = {',
            '"operator_surface_cleanup": {',
            'deleted_counts = delete_response.get("deleted") if isinstance(delete_response, dict) else {}',
            '"deleted_counts": deleted_counts',
            '"pre_delete_resources": cleanup_pre_delete_resources',
            '"retained_resources": {',
            '"workflow_cleanup": {',
            '"schedule_cleanup": {',
            '"search_attribute_cleanup": {',
            '"worker_registration_cleanup": {',
            '"after_delete_refused": post_delete_workflow_list_refused',
            '"after_delete_refused": post_delete_schedule_list_refused',
            '"after_delete_refused": post_delete_search_attributes_refused',
            '"after_delete_refused": post_delete_workers_refused',
            '"deleted_namespace_absent_from_list": NAMESPACES["a"] not in post_delete_list_names',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
    }

    public function test_runner_records_non_source_published_artifact_policy(): void
    {
        $source = $this->read('scripts/conformance/namespaces-published-artifacts.sh');

        $this->assertStringContainsString('local_product_source_checkouts_used', $source);
        $this->assertStringContainsString('runnerBlocked', $source);
        $this->assertStringContainsString('artifactVersions', $source);
        $this->assertStringContainsString('durableworkflow/server', $source);
        $this->assertStringContainsString('durable-workflow/cli', $source);
        $this->assertStringContainsString('durable-workflow==', $source);
        $this->assertStringContainsString('durable-workflow/sdk', $source);
        $this->assertStringContainsString('durable-workflow/workflow', $source);
        $this->assertStringContainsString('durable-workflow/waterline', $source);
        $this->assertStringContainsString('"status": sdk_php_execution["status"]', $source);
        $this->assertStringContainsString('"namespace_shard_execution": sdk_php_execution', $source);
    }

    public function test_runner_completed_non_pass_output_uses_contract_outcome_token(): void
    {
        $source = $this->read('scripts/conformance/namespaces-published-artifacts.sh');

        $this->assertStringContainsString(
            'outcome = "pass" if all(item["status"] == "pass" for item in ordered_results) else "non_passing"',
            $source,
            'completed namespace runs with missing shards or unsupported surfaces must emit a result-gate outcome token',
        );
        $this->assertStringNotContainsString(
            'outcome = "pass" if all(item["status"] == "pass" for item in ordered_results) else "fail"',
            $source,
            'fail is not an advertised namespace coverage_gate outcome',
        );
    }

    public function test_completed_non_pass_runner_output_is_gate_conformant(): void
    {
        $scenarioResults = [];
        foreach (NamespaceRuntimeContract::manifest()['required_scenarios'] as $scenarioId) {
            $scenarioResults[$scenarioId] = [
                'scenario_id' => $scenarioId,
                'status' => 'pass',
                'observed_outputs' => ['scenario' => $scenarioId],
            ];
        }

        $scenarioResults['waterline_operator_namespace_visibility'] = [
            'scenario_id' => 'waterline_operator_namespace_visibility',
            'status' => 'unsupported',
            'observed_outputs' => [
                'shard_command' => 'waterline:namespace-conformance',
            ],
            'linked_findings' => [
                [
                    'scenario_id' => 'waterline_operator_namespace_visibility',
                    'owning_surface' => 'waterline',
                    'observed_behavior' => 'Waterline namespace shard was not supplied to this runner invocation',
                    'expected_behavior' => 'Waterline namespace shard runs against the published artifact tuple',
                    'next_acceptance_criterion' => 'run the published Waterline namespace shard and attach its report',
                    'priority' => 'P1',
                ],
            ],
        ];

        $result = [
            'schema' => NamespaceRuntimeContract::RESULT_SCHEMA,
            'schema_version' => NamespaceRuntimeContract::RESULT_VERSION,
            'category' => 'namespace_runtime_contract',
            'outcome' => 'non_passing',
            'runner_blocked' => false,
            'started_at' => '2026-06-01T09:00:00Z',
            'finished_at' => '2026-06-01T09:05:00Z',
            'generated_at' => '2026-06-01T09:05:00Z',
            'artifact_versions' => [
                'server' => '0.2.208',
                'cli' => '0.1.71',
                'workflow' => '2.0.0-alpha.187',
                'workflow-php' => '2.0.0-alpha.187',
                'sdk-php' => '0.1.1',
                'sdk-python' => '0.4.83',
                'waterline' => '2.0.0-alpha.69',
            ],
            'namespace_topology' => [
                'namespaces' => ['tenant-a', 'tenant-b', 'shared'],
            ],
            'runtime_matrix' => [
                'runtimes' => ['sdk-php', 'sdk-python'],
                'client_paths' => ['cli', 'sdk-python', 'sdk-php'],
                'observer_paths' => ['waterline-list', 'waterline-detail', 'waterline-operator-api'],
            ],
            'scenario_results' => $scenarioResults,
            'findings' => $scenarioResults['waterline_operator_namespace_visibility']['linked_findings'],
            'finding_links' => [
                'waterline_operator_namespace_visibility' => $scenarioResults['waterline_operator_namespace_visibility']['linked_findings'],
            ],
        ];

        $evaluation = NamespaceRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotContains('invalid_declared_outcome', $failureCodes);
        $this->assertNotContains('declared_outcome_status_mismatch', $failureCodes);
    }

    public function test_runner_reconciles_verified_pass_record_before_exiting(): void
    {
        $source = $this->read('scripts/conformance/namespaces-published-artifacts.sh');

        $this->assertStringContainsString('recorded_pass_status="$(', $source);
        $this->assertStringContainsString('record.get("outcome") == "pass"', $source);
        $this->assertStringContainsString('result.get("outcome") == "pass"', $source);
        $this->assertStringContainsString('scenario_statuses.get(scenario_id) == "pass"', $source);
        $this->assertStringContainsString('artifacts_match', $source);
        $this->assertStringContainsString('findings_empty', $source);
        $this->assertStringContainsString('orchestrate_status=0', $source);
    }

    public function test_runner_blocked_output_uses_gate_conformant_declared_outcome(): void
    {
        if (! is_file('/bin/bash')) {
            $this->markTestSkipped('bash is required to exercise the namespace runner handoff.');
        }

        $python = trim((string) shell_exec('command -v python3 2>/dev/null'));
        if ($python === '') {
            $this->markTestSkipped('python3 is required to exercise blocked namespace runner output.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $scriptPath = $repoRoot.'/scripts/conformance/namespaces-published-artifacts.sh';
        $tempRoot = sys_get_temp_dir().'/dw-namespaces-blocked-'.bin2hex(random_bytes(6));
        $binDir = $tempRoot.'/bin';
        $resultDir = $tempRoot.'/result';
        $runRoot = $tempRoot.'/run';

        try {
            mkdir($binDir, 0777, true);
            mkdir($resultDir, 0777, true);

            foreach (['date', 'dirname', 'head', 'mkdir', 'sed'] as $command) {
                $this->linkSystemCommand($binDir, $command);
            }
            symlink($python, $binDir.'/python3');

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
                    'DW_NAMESPACES_RUN_ROOT' => $runRoot,
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(
                1,
                $exitCode,
                ($stdout === false ? '' : $stdout).($stderr === false ? '' : $stderr),
            );

            $this->assertFileExists($resultDir.'/namespaces-result.json');
            $this->assertFileExists($resultDir.'/namespaces-record.json');

            $result = json_decode(
                (string) file_get_contents($resultDir.'/namespaces-result.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $record = json_decode(
                (string) file_get_contents($resultDir.'/namespaces-record.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing_runner_blocked', $result['outcome']);
            $this->assertTrue($result['runner_blocked']);
            $this->assertSame('non_passing_runner_blocked', $record['outcome']);
            $this->assertTrue($record['runnerBlocked']);

            $evaluation = NamespaceRuntimeResultGate::evaluate($result);
            $this->assertNotContains('invalid_declared_outcome', array_column($evaluation['gate_failures'], 'code'));
        } finally {
            $this->removeTree($tempRoot);
        }
    }

    /**
     * @param  array<string, mixed>  $probe
     * @param  array<string, bool>  $assertions
     * @return array<string, mixed>
     */
    private function renderPhpNamespaceShardReport(array $probe, array $assertions): array
    {
        $python = trim((string) shell_exec('command -v python3 2>/dev/null'));
        if ($python === '') {
            $this->markTestSkipped('python3 is required to exercise the PHP namespace shard report.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $script = $repoRoot.'/scripts/conformance/php-sdk-namespace-shard-report.py';
        $tempRoot = sys_get_temp_dir().'/dw-php-namespace-report-'.bin2hex(random_bytes(6));
        mkdir($tempRoot, 0777, true);
        $pinsPath = $tempRoot.'/pins.json';
        $probePath = $tempRoot.'/probe.json';
        $sidecarPath = $tempRoot.'/sidecar.json';
        $outputPath = $tempRoot.'/result.json';
        $versions = [
            'server' => '0.2.653',
            'cli' => '0.1.1',
            'workflow' => '2.0.0-alpha.1',
            'workflow-php' => '2.0.0-alpha.1',
            'sdk-php' => '0.1.2',
            'sdk-python' => '0.4.1',
            'waterline' => '2.0.0-alpha.1',
        ];
        $artifactSources = [];
        foreach ($versions as $name => $version) {
            $artifactSources[$name] = "artifact://{$name}@{$version}";
        }
        $pins = $versions + ['artifact_sources' => $artifactSources];
        $sidecar = [
            'scenario_results' => [
                'php_sdk_lifecycle_surface' => [
                    'observed_outputs' => [
                        'artifact_version' => '0.1.2',
                        'artifact_source' => 'packagist://durable-workflow/sdk@0.1.2',
                        'client_processes' => [['process_id' => 100]],
                        'worker_processes' => [['process_id' => 200, 'namespace' => 'default']],
                        'namespace_evidence' => ['created_namespace' => 'php-sdk-test'],
                        'scenario_assertions' => $assertions,
                    ],
                ],
            ],
        ];

        try {
            file_put_contents($pinsPath, json_encode($pins, JSON_THROW_ON_ERROR));
            file_put_contents($probePath, json_encode($probe, JSON_THROW_ON_ERROR));
            file_put_contents($sidecarPath, json_encode($sidecar, JSON_THROW_ON_ERROR));
            $process = proc_open(
                [
                    $python,
                    $script,
                    $pinsPath,
                    $probePath,
                    $sidecarPath,
                    $outputPath,
                    '2026-07-14T10:00:00Z',
                    (string) PlatformConformanceSuite::VERSION,
                ],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
            );
            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            $this->assertSame(0, $exitCode, (string) $stdout.(string) $stderr);

            return json_decode((string) file_get_contents($outputPath), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            $this->removeTree($tempRoot);
        }
    }

    private function read(string $path): string
    {
        $absolute = dirname(__DIR__, 2).'/'.$path;
        $this->assertFileExists($absolute);

        return (string) file_get_contents($absolute);
    }

    private function linkSystemCommand(string $binDir, string $command): void
    {
        $target = trim((string) shell_exec('command -v '.escapeshellarg($command).' 2>/dev/null'));
        if ($target !== '' && ! is_file($target)) {
            $target = '';
        }
        foreach (['/usr/bin/'.$command, '/bin/'.$command] as $candidate) {
            if ($target === '' && is_file($candidate)) {
                $target = $candidate;
            }
        }
        $this->assertNotSame('', $target, "{$command} must be available to exercise the runner");

        symlink($target, $binDir.'/'.$command);
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        $this->assertNotFalse($items);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path.'/'.$item;
            if (is_dir($child) && ! is_link($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}
