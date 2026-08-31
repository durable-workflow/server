<?php

namespace Tests\Unit;

use App\Support\ChildWorkflowRuntimeContract;
use App\Support\ChildWorkflowRuntimeResultGate;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;

class ChildWorkflowRuntimeContractTest extends TestCase
{
    public function test_manifest_requires_published_artifacts_and_run_record_fields(): void
    {
        $manifest = ChildWorkflowRuntimeContract::manifest();

        $this->assertSame('durable-workflow.v2.child-workflow-runtime.contract', $manifest['schema']);
        $this->assertSame(ChildWorkflowRuntimeContract::VERSION, $manifest['version']);
        $this->assertSame('durable-workflow.v2.child-workflow-runtime.result', $manifest['result_schema']);
        $this->assertSame('child_workflow_runtime_contract', $manifest['fixture_category']);
        $this->assertSame(
            PlatformConformanceSuite::SCHEMA,
            $manifest['platform_conformance_suite_authority'],
        );
        $this->assertSame(
            'durable-workflow.v2.platform-conformance.runtime-scenarios',
            $manifest['scenario_manifest']['schema'],
        );
        $this->assertSame(
            'child_workflow_runtime_contract',
            $manifest['scenario_manifest']['category'],
        );
        $this->assertSame(
            'https://durable-workflow.github.io/platform-conformance/child-workflow-runtime-scenarios.json',
            $manifest['scenario_manifest']['public_path'],
        );
        $this->assertSame(
            'static/platform-conformance/child-workflow-runtime-scenarios.json',
            $manifest['scenario_manifest']['source_path'],
        );

        foreach (['server', 'cli', 'workflow-php', 'sdk-python', 'sdk-rust', 'waterline'] as $artifact) {
            $this->assertArrayHasKey($artifact, $manifest['artifact_policy']['install_channels']);
        }

        $this->assertContains(
            'local_product_source_checkout',
            $manifest['artifact_policy']['forbidden_sources'],
        );

        foreach ([
            'artifact_versions',
            'started_at',
            'finished_at',
            'generated_at',
            'outcome',
            'scenario_results',
            'findings',
            'finding_links',
        ] as $field) {
            $this->assertContains($field, $manifest['artifact_policy']['required_run_record_fields']);
        }
    }

    public function test_public_scenario_manifest_matches_required_child_workflow_matrix(): void
    {
        $manifestPath = dirname(__DIR__, 2) . '/static/platform-conformance/child-workflow-runtime-scenarios.json';
        $scenarioManifest = json_decode(
            file_get_contents($manifestPath) ?: '',
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $contract = ChildWorkflowRuntimeContract::manifest();

        $this->assertSame('child_workflow_runtime_contract', $scenarioManifest['category']);
        $this->assertSame($contract['result_schema'], $scenarioManifest['result_schema']);
        $this->assertSame($contract['scenario_statuses'], $scenarioManifest['result_statuses']);
        $this->assertSame(
            $contract['required_scenarios'],
            array_column($scenarioManifest['scenarios'], 'id'),
        );
        $this->assertSame(
            $contract['required_matrix'],
            $scenarioManifest['required_matrix'],
        );
        $this->assertContains(
            'workflow-php',
            $scenarioManifest['artifact_policy']['required_artifacts'],
        );
        $this->assertContains(
            'sdk-python',
            $scenarioManifest['artifact_policy']['required_artifacts'],
        );
        $this->assertContains(
            'sdk-rust',
            $scenarioManifest['artifact_policy']['required_artifacts'],
        );
        $this->assertContains(
            'direct_child_cancellation_observed_by_parent',
            array_column($scenarioManifest['scenarios'], 'id'),
        );
        $this->assertContains(
            'worker_restart_replay_preserves_child_outcome',
            array_column($scenarioManifest['scenarios'], 'id'),
        );
        $this->assertContains(
            'concurrent_child_fan_out',
            array_column($scenarioManifest['scenarios'], 'id'),
        );
        $this->assertContains(
            'child_workflow_namespace_contract',
            array_column($scenarioManifest['scenarios'], 'id'),
        );
    }

    public function test_manifest_names_full_parent_child_runtime_matrix(): void
    {
        $manifest = ChildWorkflowRuntimeContract::manifest();
        $matrix = $manifest['required_matrix'];

        $this->assertSame(['workflow-php', 'sdk-python'], $matrix['runtimes']);
        $this->assertContains(
            [
                'parent' => 'sdk-python',
                'child' => 'sdk-python',
                'scenario' => 'python_parent_python_child_baseline',
            ],
            $matrix['same_language_cells'],
        );
        $this->assertContains(
            [
                'parent' => 'workflow-php',
                'child' => 'sdk-python',
                'scenario' => 'php_parent_python_child_cross_language',
            ],
            $matrix['cross_language_cells'],
        );
        $this->assertContains(
            [
                'parent' => 'sdk-python',
                'child' => 'workflow-php',
                'scenario' => 'python_parent_php_child_cross_language',
            ],
            $matrix['cross_language_cells'],
        );
        $this->assertCount(4, $matrix['failure_round_trip_cells']);
    }

    public function test_manifest_keeps_smoke_only_coverage_non_passing(): void
    {
        $manifest = ChildWorkflowRuntimeContract::manifest();
        $gate = $manifest['coverage_gate'];

        $this->assertContains('not_covered', $manifest['scenario_statuses']);
        $this->assertSame('non_passing', $gate['uncovered_required_scenario_outcome']);
        $this->assertSame('non_passing', $gate['smoke_subset_outcome']);

        foreach ([
            'all_required_scenarios_reported',
            'all_required_runtimes_present',
            'same_language_cells_reported',
            'cross_language_cells_reported',
            'failure_round_trip_cells_reported',
            'parent_cancellation_reported',
            'direct_child_cancellation_reported',
            'replay_restart_reported',
            'fan_out_concurrency_reported',
            'namespace_behavior_reported',
            'declared_outcome_matches_evaluated_status',
            'published_artifact_install_evidence_reported',
            'omitted_required_scenarios_link_findings',
            'findings_linked_for_non_pass_scenarios',
        ] as $requirement) {
            $this->assertContains($requirement, $gate['passing_outcome_requires']);
        }

        $expectedScenarios = [
            'published_artifact_install_only',
            'python_parent_python_child_baseline',
            'php_parent_php_child_baseline',
            'php_parent_python_child_cross_language',
            'python_parent_php_child_cross_language',
            'child_failure_round_trip_matrix',
            'parent_cancellation_propagates_to_child',
            'direct_child_cancellation_observed_by_parent',
            'worker_restart_replay_preserves_child_outcome',
            'concurrent_child_fan_out',
            'child_workflow_namespace_contract',
        ];

        foreach ($expectedScenarios as $scenario) {
            $this->assertContains($scenario, $manifest['required_scenarios']);
        }

        $this->assertSame($expectedScenarios, $manifest['required_scenarios']);
    }

    public function test_manifest_requires_actionable_diagnostics_for_cancellation_replay_fan_out_and_namespace_cases(): void
    {
        $requirements = ChildWorkflowRuntimeContract::manifest()['scenario_requirements'];

        $this->assertSame(
            'all_artifacts_resolved_from_published_channels',
            $requirements['published_artifact_install_only']['required_behavior'],
        );
        foreach ([
            'server_image',
            'cli_release',
            'workflow_php_package',
            'sdk_python_package',
            'sdk_rust_package',
            'waterline_artifact',
        ] as $field) {
            $this->assertContains($field, $requirements['published_artifact_install_only']['evidence']);
        }

        $this->assertSame(
            'child_reaches_cancelled_after_parent_cancel',
            $requirements['parent_cancellation_propagates_to_child']['required_behavior'],
        );
        foreach ([
            'typed_cancellation_observed',
            'child_cancellation_history_evidence',
            'parent_close_policy_evidence',
        ] as $field) {
            $this->assertContains(
                $field,
                $requirements['parent_cancellation_propagates_to_child']['evidence'],
            );
        }
        $this->assertSame(
            'parent_observes_typed_child_cancellation_not_timeout',
            $requirements['direct_child_cancellation_observed_by_parent']['required_behavior'],
        );
        $this->assertSame(
            'parent_decision_sequence_matches_after_restart',
            $requirements['worker_restart_replay_preserves_child_outcome']['required_behavior'],
        );
        $this->assertSame(5, $requirements['concurrent_child_fan_out']['required_child_count']);
        $this->assertContains(
            'cross_namespace_verdict',
            $requirements['child_workflow_namespace_contract']['evidence'],
        );

        $findingPolicy = ChildWorkflowRuntimeContract::manifest()['finding_policy'];
        $this->assertSame('link_root_cause_finding_against_server', $findingPolicy['child_result_not_observed']);
        $this->assertSame('link_root_cause_finding_against_server', $findingPolicy['cancellation_leak']);
        $this->assertSame(
            'link_root_cause_finding_against_docs_or_server_owner',
            $findingPolicy['namespace_contract_gap'],
        );
        $this->assertSame(
            'link_root_cause_finding_against_conformance_harness',
            $findingPolicy['conformance_runner_coverage_gap'],
        );
    }

    public function test_manifest_publishes_host_runner_contract_for_full_child_workflow_coverage(): void
    {
        $hostRunner = ChildWorkflowRuntimeContract::manifest()['host_runner_contract'];

        $this->assertSame('required_for_passing_child_workflows_conformance', $hostRunner['status']);
        $this->assertSame(ChildWorkflowRuntimeContract::RESULT_SCHEMA, $hostRunner['result_schema']);
        $this->assertSame(
            'scripts/conformance/child-workflows-published-artifacts.sh',
            $hostRunner['published_artifact_runner'],
        );
        $this->assertTrue($hostRunner['must_probe_runtime_published_surfaces']);
        $this->assertTrue($hostRunner['must_emit_result_for_every_required_scenario']);
        $this->assertSame('non_passing', $hostRunner['smoke_summary_only_outcome']);
        $this->assertSame('not_covered', $hostRunner['unexecuted_required_scenario_status']);
        $this->assertSame('conformance_runner_coverage_gap', $hostRunner['coverage_gap_finding_type']);
        $this->assertSame('conformance_harness', $hostRunner['coverage_gap_owner']);

        foreach ([
            'published-artifact-install',
            'workflow-php-parent-child-shard',
            'sdk-python-parent-child-shard',
            'cross-language-parent-child-shard',
            'failure-round-trip-shard',
            'cancellation-propagation-shard',
            'replay-restart-shard',
            'fan-out-concurrency-shard',
            'namespace-behavior-shard',
        ] as $scope) {
            $this->assertContains($scope, $hostRunner['required_execution_scopes']);
            $this->assertContains($scope, $hostRunner['merge_policy']['input_scopes']);
        }

        $this->assertSame(
            ['PhpParent', 'PhpChild'],
            $hostRunner['runtime_shards']['workflow-php']['must_register_workflows'],
        );
        $this->assertSame(
            ['PythonParent', 'PythonChild'],
            $hostRunner['runtime_shards']['sdk-python']['must_register_workflows'],
        );
        $this->assertSame(
            'child_workflow_runtime_contract.required_scenarios',
            $hostRunner['merge_policy']['requires_required_scenarios'],
        );
        foreach (['workflow-php', 'sdk-python'] as $runtime) {
            $this->assertContains($runtime, $hostRunner['merge_policy']['requires_required_runtimes']);
        }
        foreach ([
            'published_artifact_install',
            'runtime_matrix',
            'failure_round_trip',
            'cancellation_propagation',
            'replay_restart',
            'fan_out',
            'namespace_behavior',
        ] as $section) {
            $this->assertContains($section, $hostRunner['merge_policy']['requires_sections']);
        }

        $this->assertSame(
            [
                'scenario_status' => 'not_covered',
                'finding_type' => 'conformance_runner_coverage_gap',
                'owner' => 'conformance_harness',
            ],
            $hostRunner['routing_policy']['missing_required_scenario'],
        );
    }

    public function test_published_artifact_runner_routes_every_unexecuted_child_workflow_cell(): void
    {
        $source = $this->read('scripts/conformance/child-workflows-published-artifacts.sh');

        $this->assertStringContainsString(
            'Usage: child-workflows-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]',
            $source,
            'host runners must be able to pass the same result-dir and keep-run-root flags used by the other conformance scripts',
        );
        $this->assertStringContainsString('child-workflows-result.json', $source);
        $this->assertStringContainsString('child-workflows-record.json', $source);
        $this->assertStringContainsString('DW_CHILD_WORKFLOWS_SCENARIO_MANIFEST', $source);
        $this->assertStringContainsString('DW_CHILD_WORKFLOWS_ARTIFACT_INSTALL_EVIDENCE', $source);
        $this->assertStringContainsString('DW_CHILD_WORKFLOWS_TYPED_FAILURE_EVIDENCE', $source);
        $this->assertStringContainsString('DW_CHILD_WORKFLOWS_FULL_MATRIX_EVIDENCE', $source);
        $this->assertStringContainsString('DW_CHILD_WORKFLOWS_SKIP_FOCUSED_TYPED_FAILURE_PROBE', $source);
        $this->assertStringContainsString('DW_CHILD_WORKFLOWS_PYTHON_BIN', $source);
        $this->assertStringContainsString('focused-typed-failure-server-evidence.json', $source);
        $this->assertStringContainsString('published durable-workflow Python SDK replay surface', $source);
        $this->assertStringContainsString('child-workflows-install-probe.py', $source);
        $this->assertStringContainsString('child-workflows-runtime-probe.php', $source);
        $this->assertStringContainsString('unset DW_CHILD_WORKFLOWS_FULL_MATRIX_EVIDENCE', $source);
        $this->assertStringContainsString('unset DW_CHILD_WORKFLOWS_ARTIFACT_INSTALL_EVIDENCE', $source);

        $installProbe = $this->read('scripts/conformance/child-workflows-install-probe.py');
        $runtimeProbe = $this->read('scripts/conformance/child-workflows-runtime-probe.php');
        $pythonRuntime = $this->read('scripts/conformance/child-workflows-python-runtime.py');
        $this->assertStringContainsString('command', $installProbe);
        $this->assertStringContainsString('output_sample', $installProbe);
        $this->assertStringContainsString('WorkflowFiberRunner::forClass(', $runtimeProbe);
        $this->assertStringContainsString('cw_failure_cell(', $runtimeProbe);
        $this->assertStringContainsString(
            "'capability_manifest' => cw_capability_manifest(),",
            $runtimeProbe,
        );
        $this->assertStringContainsString(
            'WorkerProtocol::PORTABLE_WORKER_AFFINITY_CAPABILITIES',
            $runtimeProbe,
        );
        $this->assertStringContainsString(
            '$parentRuntimeResult = $parentResume[\'observation\'][\'runtime_result\'] ?? null;',
            $runtimeProbe,
        );
        $this->assertStringContainsString('$parentFailureKind !== \'child_workflow\'', $runtimeProbe);
        $this->assertStringContainsString(
            '$childFailureCategory = (string) ($payload[\'failure_category\'] ?? \'\');',
            $runtimeProbe,
        );
        $this->assertStringContainsString('$childFailureCategory === \'\'', $runtimeProbe);
        $this->assertStringContainsString('\'failure_kind\' => $parentFailureKind', $runtimeProbe);
        $this->assertStringContainsString('\'child_failure_category\' => $childFailureCategory', $runtimeProbe);
        $this->assertStringNotContainsString(
            '\'failure_kind\' => (string) ($payload[\'failure_category\'] ?? \'child_workflow\')',
            $runtimeProbe,
        );
        $this->assertStringContainsString('cw_parent_cancellation(', $runtimeProbe);
        $this->assertStringContainsString('cw_direct_child_cancellation(', $runtimeProbe);
        $this->assertStringContainsString('cw_parent_close_policy(', $runtimeProbe);
        $this->assertStringContainsString('cw_replay_restart(', $runtimeProbe);
        $this->assertStringContainsString('cw_fan_out(', $runtimeProbe);
        $this->assertStringContainsString('commands_to_server_commands(', $pythonRuntime);
        $directCancellationBody = substr(
            $runtimeProbe,
            strpos($runtimeProbe, 'function cw_direct_child_cancellation(): array'),
            strpos($runtimeProbe, 'function cw_parent_close_policy(): array')
                - strpos($runtimeProbe, 'function cw_direct_child_cancellation(): array'),
        );
        $parentCancellationBody = substr(
            $runtimeProbe,
            strpos($runtimeProbe, 'function cw_parent_cancellation(): array'),
            strpos($runtimeProbe, 'function cw_direct_child_cancellation(): array')
                - strpos($runtimeProbe, 'function cw_parent_cancellation(): array'),
        );
        $postCancelParentCancellationBody = substr(
            $parentCancellationBody,
            strpos($parentCancellationBody, "'/cancel'"),
        );
        $this->assertStringNotContainsString('cw_poll(', $postCancelParentCancellationBody);
        $this->assertStringNotContainsString('cw_python_step(', $postCancelParentCancellationBody);
        $this->assertStringContainsString("'ParentClosePolicyApplied'", $postCancelParentCancellationBody);
        $this->assertStringContainsString("'WorkflowCancelledException'", $postCancelParentCancellationBody);
        $fanOutBody = substr(
            $runtimeProbe,
            strpos($runtimeProbe, 'function cw_fan_out(): array'),
            strpos($runtimeProbe, "\ntry {") - strpos($runtimeProbe, 'function cw_fan_out(): array'),
        );
        $this->assertStringNotContainsString('$aggregate', $directCancellationBody);
        $this->assertStringNotContainsString('$startedEpochs', $directCancellationBody);
        $this->assertStringContainsString("['failure_kind'] ?? null) !== 'cancelled'", $directCancellationBody);
        $this->assertStringContainsString(
            '($runtimeResult[\'child_run_id\'] ?? null) !== $childRunId',
            $directCancellationBody,
        );
        $this->assertStringContainsString('$aggregate', $fanOutBody);
        $this->assertStringContainsString('$overlapObserved', $fanOutBody);
        $this->assertStringContainsString('runtime_relationship_failures(', $source);
        $this->assertStringContainsString('full_matrix_runtime_relationship_failures(', $source);

        foreach ([
            'DW_SERVER_VERSION',
            'DW_CLI_VERSION',
            'DW_PYTHON_SDK_VERSION',
            'DW_RUST_SDK_VERSION',
            'DW_WORKFLOW_PHP_VERSION',
            'DW_WATERLINE_VERSION',
        ] as $envName) {
            $this->assertStringContainsString($envName, $source);
        }

        foreach (ChildWorkflowRuntimeContract::manifest()['required_scenarios'] as $scenarioId) {
            $this->assertStringContainsString(
                $scenarioId,
                $source,
                "the published-artifact runner must know how to route scenario $scenarioId",
            );
        }

        foreach ([
            'not_covered',
            'conformance_runner_coverage_gap',
            'user_visible_reproduction_steps',
            'extend the host runner to execute this scenario against published artifacts',
            'artifact_install_evidence',
            'artifact_install_evidence missing',
            'typed_failure_evidence requires passing published artifact install evidence',
            'typed failure evidence did not include required failure round-trip cells',
            'full_matrix_evidence requires passing published artifact install evidence',
            'full_matrix_evidence.local_product_source_checkouts_used=false missing',
            'install_evidence_pass',
            'not_exercised',
            'FORBIDDEN_INSTALL_SOURCE_TOKENS',
            'repo_root" != "/app"',
            'local_product_source_checkouts_used": False',
        ] as $token) {
            $this->assertStringContainsString($token, $source);
        }

        $this->assertStringContainsString(
            '"outcome": "pass" if pass_result else ("error" if runner_blocked else "fail")',
            $source,
            'coverage gaps must record a non-runner-blocked fail; only missing host prerequisites may become runner-blocked',
        );
    }

    public function test_published_artifact_resolvers_handle_tag_conventions_and_transient_registries(): void
    {
        if (trim((string) shell_exec('command -v python3 2>/dev/null')) === '') {
            $this->markTestSkipped('python3 is required to exercise the child-workflow artifact resolvers.');
        }

        $test = dirname(__DIR__, 2) . '/tests/Unit/Support/child_workflows_artifact_resolver_test.py';
        exec('python3 ' . escapeshellarg($test) . ' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    public function test_published_artifact_runner_does_not_pass_install_cell_without_install_evidence(): void
    {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === ''
            || trim((string) shell_exec('command -v python3 2>/dev/null')) === '') {
            $this->markTestSkipped('bash and python3 are required to exercise the child-workflows runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = $repoRoot . '/storage/framework/child-workflows-' . bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($resultDir, 0777, true));

        try {
            $env = [
                'DW_SERVER_VERSION' => '9.9.9',
                'DW_CLI_VERSION' => '9.9.9',
                'DW_PYTHON_SDK_VERSION' => '9.9.9',
                'DW_RUST_SDK_VERSION' => '9.9.9',
                'DW_WORKFLOW_PHP_VERSION' => '9.9.9',
                'DW_WATERLINE_VERSION' => '9.9.9',
            ];
            $envPrefix = implode(' ', array_map(
                static fn (string $name, string $value): string => $name . '=' . escapeshellarg($value),
                array_keys($env),
                array_values($env),
            ));
            $command = sprintf(
                '%s bash %s --result-dir %s >/dev/null 2>&1',
                $envPrefix,
                escapeshellarg($repoRoot . '/scripts/conformance/child-workflows-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $exitCode);
            $this->assertSame(1, $exitCode);

            $result = json_decode(
                file_get_contents($resultDir . '/child-workflows-result.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $installScenario = null;
            foreach ($result['scenario_results'] ?? [] as $scenario) {
                if (($scenario['scenario_id'] ?? null) === 'published_artifact_install_only') {
                    $installScenario = $scenario;
                    break;
                }
            }

            $this->assertIsArray($installScenario);
            $this->assertSame('not_covered', $installScenario['status']);
            $this->assertContains(
                'artifact_install_evidence missing',
                $installScenario['observed_outputs']['artifact_install_failures'] ?? [],
            );
        } finally {
            foreach (glob($resultDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($resultDir)) {
                rmdir($resultDir);
            }
        }
    }

    public function test_published_artifact_runner_rejects_caller_authored_typed_failure_evidence(): void
    {
        $run = $this->runChildWorkflowRunnerWithEvidence(
            $this->childWorkflowRunnerBaseEnv(),
            [
                'DW_CHILD_WORKFLOWS_TYPED_FAILURE_EVIDENCE' => [
                    'name' => 'typed-failure-evidence.json',
                    'content' => $this->childWorkflowTypedFailureEvidence(),
                ],
            ],
        );
        $result = $run['result'];
        $scenario = $this->scenarioResult($result, 'child_failure_round_trip_matrix');

        $this->assertSame(1, $run['exitCode']);
        $this->assertSame('not_covered', $scenario['status']);
        $this->assertSame('not_covered', $result['failure_round_trip']['status'] ?? null);
        $this->assertNotContains('pass', array_column($result['failure_round_trip']['cells'] ?? [], 'status'));
        $this->assertSame('', $result['runtime_evidence_source'] ?? null);
        $this->assertTrue($result['local_product_source_checkouts_used'] ?? false);
    }

    public function test_published_artifact_runner_keeps_partial_typed_failure_evidence_non_passing(): void
    {
        $run = $this->runChildWorkflowRunnerWithEvidence(
            $this->childWorkflowRunnerBaseEnv(),
            [
                'DW_CHILD_WORKFLOWS_ARTIFACT_INSTALL_EVIDENCE' => [
                    'name' => 'artifact-install-evidence.json',
                    'content' => $this->childWorkflowArtifactInstallEvidence(),
                ],
                'DW_CHILD_WORKFLOWS_TYPED_FAILURE_EVIDENCE' => [
                    'name' => 'typed-failure-evidence.json',
                    'content' => $this->childWorkflowTypedFailureEvidence([
                        $this->childWorkflowTypedFailureCell('sdk-python', 'sdk-python'),
                    ]),
                ],
            ],
        );
        $result = $run['result'];
        $scenario = $this->scenarioResult($result, 'child_failure_round_trip_matrix');
        $cellStatuses = $this->failureRoundTripStatusByCell($result);

        $this->assertSame(1, $run['exitCode']);
        $this->assertSame('not_covered', $scenario['status']);
        $this->assertSame('not_covered', $result['failure_round_trip']['status'] ?? null);
        $this->assertSame('not_covered', $cellStatuses['sdk-python->sdk-python'] ?? null);
        $this->assertSame('not_covered', $cellStatuses['workflow-php->workflow-php'] ?? null);
        $this->assertSame('not_covered', $cellStatuses['workflow-php->sdk-python'] ?? null);
        $this->assertSame('not_covered', $cellStatuses['sdk-python->workflow-php'] ?? null);
        $this->assertSame('', $result['runtime_evidence_source'] ?? null);
    }

    public function test_published_artifact_runner_rejects_default_path_caller_evidence(): void
    {
        $run = $this->runChildWorkflowRunnerWithEvidence(
            $this->childWorkflowRunnerBaseEnv(),
            [
                'DW_CHILD_WORKFLOWS_ARTIFACT_INSTALL_EVIDENCE' => [
                    'name' => 'artifact-install-evidence.json',
                    'content' => $this->childWorkflowArtifactInstallEvidence(),
                ],
                'typed_failure_default' => [
                    'name' => 'typed-failure-evidence.json',
                    'content' => $this->childWorkflowTypedFailureEvidence([
                        $this->childWorkflowTypedFailureCell('sdk-python', 'sdk-python'),
                    ]),
                ],
            ],
        );
        $result = $run['result'];
        $scenario = $this->scenarioResult($result, 'child_failure_round_trip_matrix');
        $cellStatuses = $this->failureRoundTripStatusByCell($result);

        $this->assertSame(1, $run['exitCode']);
        $this->assertSame('not_covered', $scenario['status']);
        $this->assertSame('not_covered', $cellStatuses['sdk-python->sdk-python'] ?? null);
        $this->assertArrayNotHasKey('typed_failure_evidence_path', $result['failure_round_trip']);
    }

    public function test_published_artifact_runner_rejects_complete_caller_typed_failure_matrix(): void
    {
        $run = $this->runChildWorkflowRunnerWithEvidence(
            $this->childWorkflowRunnerBaseEnv(),
            [
                'DW_CHILD_WORKFLOWS_ARTIFACT_INSTALL_EVIDENCE' => [
                    'name' => 'artifact-install-evidence.json',
                    'content' => $this->childWorkflowArtifactInstallEvidence(),
                ],
                'DW_CHILD_WORKFLOWS_TYPED_FAILURE_EVIDENCE' => [
                    'name' => 'typed-failure-evidence.json',
                    'content' => $this->childWorkflowTypedFailureEvidence(),
                ],
            ],
        );
        $result = $run['result'];
        $scenario = $this->scenarioResult($result, 'child_failure_round_trip_matrix');

        $this->assertSame(1, $run['exitCode']);
        $this->assertSame('not_covered', $scenario['status']);
        $this->assertSame('not_covered', $result['failure_round_trip']['status'] ?? null);
        $this->assertNotContains('pass', array_column($result['failure_round_trip']['cells'] ?? [], 'status'));
        $this->assertSame(
            'non_passing',
            $result['outcome'] ?? null,
            'passing the focused typed-failure matrix must not reopen the broader child-workflows matrix',
        );
    }

    public function test_published_artifact_runner_rejects_complete_caller_authored_full_matrix_evidence(): void
    {
        $run = $this->runChildWorkflowRunnerWithEvidence(
            $this->childWorkflowRunnerBaseEnv(),
            [
                'DW_CHILD_WORKFLOWS_ARTIFACT_INSTALL_EVIDENCE' => [
                    'name' => 'artifact-install-evidence.json',
                    'content' => $this->childWorkflowArtifactInstallEvidence(),
                ],
                'DW_CHILD_WORKFLOWS_FULL_MATRIX_EVIDENCE' => [
                    'name' => 'full-matrix-evidence.json',
                    'content' => $this->childWorkflowFullMatrixEvidence(),
                ],
            ],
        );

        $result = $run['result'];
        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);

        $this->assertSame(1, $run['exitCode']);
        $this->assertSame('non_passing', $result['outcome'] ?? null);
        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertSame('not_covered', $this->scenarioResult($result, 'php_parent_python_child_cross_language')['status']);
        $this->assertSame('not_covered', $result['failure_round_trip']['status'] ?? null);
        $this->assertSame('', $result['runtime_evidence_source'] ?? null);
    }

    public function test_published_artifact_runner_does_not_fail_pass_record_when_external_run_root_cannot_be_removed(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $runRoot = $repoRoot . '/storage/framework/child-workflows-run-root-' . bin2hex(random_bytes(4));
        $fakeBin = $repoRoot . '/storage/framework/child-workflows-bin-' . bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($runRoot, 0777, true));
        $this->assertTrue(mkdir($fakeBin, 0777, true));

        try {
            $realRm = trim((string) shell_exec('command -v rm 2>/dev/null')) ?: '/bin/rm';
            file_put_contents($fakeBin . '/rm', str_replace('__REAL_RM__', escapeshellarg($realRm), <<<'SH'
#!/usr/bin/env bash
set -eu
for arg in "$@"; do
  if [[ "$arg" == "${DW_CHILD_WORKFLOWS_PROTECTED_RUN_ROOT:?}" ]]; then
    printf 'fake rm cleanup failure: %s\n' "$*" >&2
    exit 1
  fi
done
exec __REAL_RM__ "$@"
SH));
            chmod($fakeBin . '/rm', 0755);

            $run = $this->runChildWorkflowRunnerWithEvidence(
                array_merge($this->childWorkflowRunnerBaseEnv(), [
                    'PATH' => $fakeBin . PATH_SEPARATOR . (string) getenv('PATH'),
                    'DW_CHILD_WORKFLOWS_RUN_ROOT' => $runRoot,
                    'DW_CHILD_WORKFLOWS_PROTECTED_RUN_ROOT' => $runRoot,
                    'DW_CHILD_WORKFLOWS_SKIP_FOCUSED_TYPED_FAILURE_PROBE' => '1',
                ]),
                [
                    'DW_CHILD_WORKFLOWS_ARTIFACT_INSTALL_EVIDENCE' => [
                        'name' => 'artifact-install-evidence.json',
                        'content' => $this->childWorkflowArtifactInstallEvidence(),
                    ],
                    'DW_CHILD_WORKFLOWS_FULL_MATRIX_EVIDENCE' => [
                        'name' => 'full-matrix-evidence.json',
                        'content' => $this->childWorkflowFullMatrixEvidence(),
                    ],
                ],
            );

            $this->assertSame(1, $run['exitCode']);
            $this->assertSame('non_passing', $run['result']['outcome'] ?? null);
            $this->assertDirectoryExists($runRoot);
        } finally {
            foreach (glob($fakeBin . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($fakeBin)) {
                rmdir($fakeBin);
            }
            if (is_dir($runRoot)) {
                rmdir($runRoot);
            }
        }
    }

    public function test_manifest_publishes_an_enforceable_result_gate(): void
    {
        $resultGate = ChildWorkflowRuntimeContract::manifest()['result_gate'];

        $this->assertSame(ChildWorkflowRuntimeResultGate::SCHEMA, $resultGate['schema']);
        $this->assertSame(ChildWorkflowRuntimeResultGate::VERSION, $resultGate['version']);
        $this->assertSame(
            ChildWorkflowRuntimeContract::RESULT_SCHEMA,
            $resultGate['evaluates_result_schema'],
        );
        $this->assertContains('scenario_results', $resultGate['scenario_results_fields']);
        $this->assertContains('artifactVersions', $resultGate['artifact_versions_fields']);
        $this->assertContains('published_artifact_versions', $resultGate['artifact_versions_fields']);
        $this->assertSame(['outcome', 'status', 'verdict'], $resultGate['declared_outcome_fields']);
        $this->assertSame(
            'child_workflow_runtime_contract.coverage_gate.*_outcome',
            $resultGate['declared_outcomes_source'],
        );
        $this->assertContains('every_required_scenario_has_one_result', $resultGate['pass_requires']);
        $this->assertContains(
            'same_language_and_cross_language_parent_child_cells_are_reported',
            $resultGate['pass_requires'],
        );
        $this->assertContains('each_pass_scenario_has_scenario_specific_evidence', $resultGate['pass_requires']);
        $this->assertContains('published_artifact_install_evidence_reported', $resultGate['pass_requires']);
        $this->assertContains('omitted_required_scenarios_link_findings', $resultGate['pass_requires']);
        $this->assertContains(
            'run_timestamps_outcome_and_finding_links_are_recorded',
            $resultGate['pass_requires'],
        );
        $this->assertContains('overall_outcome_matches_gate_status', $resultGate['pass_requires']);
        $this->assertContains('each_non_pass_scenario_has_linked_findings', $resultGate['pass_requires']);
        $this->assertContains('published_artifact_versions_are_recorded_and_pinned', $resultGate['pass_requires']);
        $this->assertSame('non_passing', $resultGate['smoke_subset_outcome']);
    }

    public function test_result_gate_rejects_python_smoke_subset_even_when_the_smoke_passes(): void
    {
        $evaluation = ChildWorkflowRuntimeResultGate::evaluate([
            'schema' => ChildWorkflowRuntimeContract::RESULT_SCHEMA,
            'artifactVersions' => [
                'server' => '0.2.144',
                'cli' => '0.1.45',
                'sdk-python' => '0.4.60',
                'workflow' => '2.0.0-alpha.164',
                'waterline' => '2.0.0-alpha.54',
            ],
            'runtime_matrix' => [
                'runtimes' => ['sdk-python'],
                'same_language_cells' => [
                    [
                        'scenario' => 'python_parent_python_child_baseline',
                        'parent' => 'sdk-python',
                        'child' => 'sdk-python',
                    ],
                ],
            ],
            'scenario_results' => [
                [
                    'scenario_id' => 'python_parent_python_child_baseline',
                    'status' => 'pass',
                    'observed_outputs' => [
                        'parent_result' => 'python-parent:python-child:ok',
                    ],
                ],
            ],
        ]);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertTrue($evaluation['smoke_subset_detected']);
        $this->assertContains('php_parent_php_child_baseline', $evaluation['missing_scenarios']);
        $this->assertContains(
            'smoke_subset_cannot_pass',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_findings_for_non_pass_scenarios(): void
    {
        $result = $this->completeChildWorkflowResult();
        $result['scenario_results']['parent_cancellation_propagates_to_child']['status'] = 'fail';
        unset($result['scenario_results']['parent_cancellation_propagates_to_child']['linked_findings']);

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('parent_cancellation_propagates_to_child', $evaluation['non_pass_scenarios']);
        $this->assertContains(
            'missing_non_pass_finding',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_findings_for_omitted_required_scenarios(): void
    {
        $result = $this->completeChildWorkflowResult();
        unset($result['scenario_results']['php_parent_python_child_cross_language']);

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);
        $missingScenarioFindingFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_required_scenario_finding',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('php_parent_python_child_cross_language', $evaluation['missing_scenarios']);
        $this->assertCount(1, $missingScenarioFindingFailures);
        $this->assertSame(
            'php_parent_python_child_cross_language',
            $missingScenarioFindingFailures[0]['scenario_id'],
        );

        $result['finding_links'] = [
            'php_parent_python_child_cross_language' => [
                'https://tracker.example/findings/php-parent-python-child-cross-language',
            ],
        ];

        $evaluationWithFinding = ChildWorkflowRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluationWithFinding['status']);
        $this->assertNotContains(
            'missing_required_scenario_finding',
            array_column($evaluationWithFinding['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_duplicate_scenario_results(): void
    {
        $result = $this->completeChildWorkflowResult();
        $result['scenario_results'] = array_values($result['scenario_results']);
        $result['scenario_results'][] = [
            'scenario_id' => 'python_parent_python_child_baseline',
            'status' => 'pass',
            'observed_outputs' => [
                'parent_result' => 'duplicate',
            ],
        ];

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);
        $duplicateFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'duplicate_scenario_result',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertCount(1, $duplicateFailures);
        $this->assertSame('python_parent_python_child_baseline', $duplicateFailures[0]['scenario_id']);
        $this->assertSame(2, $duplicateFailures[0]['count']);
    }

    public function test_result_gate_requires_run_metadata_for_a_passing_result(): void
    {
        $result = $this->completeChildWorkflowResult();
        unset($result['started_at'], $result['finished_at'], $result['generated_at'], $result['outcome']);

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);
        $missingFields = $this->missingRunRecordFields($evaluation);

        $this->assertSame('non_passing', $evaluation['status']);
        foreach (['started_at', 'finished_at', 'generated_at', 'outcome'] as $field) {
            $this->assertContains($field, $missingFields);
        }
    }

    public function test_result_gate_rejects_results_without_published_runtime_probe_provenance(): void
    {
        $result = $this->completeChildWorkflowResult();
        unset($result['runtime_evidence_source'], $result['local_product_source_checkouts_used']);

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);
        $codes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('invalid_runtime_evidence_source', $codes);
        $this->assertContains('local_product_source_checkouts_used_must_be_false', $codes);
    }

    public function test_result_gate_rejects_synthetic_runtime_identities_even_with_probe_label(): void
    {
        $result = $this->completeChildWorkflowResult();
        $outputs = &$result['scenario_results']['python_parent_python_child_baseline']['observed_outputs'];
        $outputs['parent_workflow_id'] = 'parent-fixture';
        $outputs['parent_history']['workflow_id'] = 'parent-fixture';
        $outputs['runtime_observations'][0]['workflow_id'] = 'parent-fixture';

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('synthetic_runtime_identity', array_column($evaluation['gate_failures'], 'code'));
    }

    public function test_result_gate_rejects_contradictory_history_and_runtime_run_identities(): void
    {
        $result = $this->completeChildWorkflowResult();
        $outputs = &$result['scenario_results']['php_parent_python_child_cross_language']['observed_outputs'];
        $outputs['child_history']['run_id'] = '01CONTRADICTORYRUNIDENTITY0';
        $outputs['runtime_observations'][1]['task_queue'] = 'contradictory-queue';

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);
        $codes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('runtime_history_identity_mismatch', $codes);
        $this->assertContains('incomplete_leased_runtime_observation', $codes);
    }

    public function test_result_gate_rejects_structurally_incomplete_event_and_lease_evidence(): void
    {
        $result = $this->completeChildWorkflowResult();
        $outputs = &$result['scenario_results']['python_parent_php_child_cross_language']['observed_outputs'];
        unset(
            $outputs['parent_history']['events'][0]['payload']['child_workflow_run_id'],
            $outputs['runtime_observations'][0]['lease_owner'],
        );
        $outputs['observed_at'] = '2026-05-20T06:00:00Z';

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);
        $codes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('parent_history_child_run_mismatch', $codes);
        $this->assertContains('incomplete_leased_runtime_observation', $codes);
        $this->assertContains('runtime_timestamp_not_from_history', $codes);
    }

    public function test_result_gate_rejects_install_rows_without_command_and_output_provenance(): void
    {
        $result = $this->completeChildWorkflowResult();
        unset(
            $result['artifact_install_evidence']['artifacts'][0]['commands'],
            $result['artifact_install_evidence']['artifacts'][0]['output_sample'],
            $result['published_artifact_install']['artifact_install_evidence']['artifacts'][0]['commands'],
            $result['published_artifact_install']['artifact_install_evidence']['artifacts'][0]['output_sample'],
            $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_install_evidence']['artifacts'][0]['commands'],
            $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_install_evidence']['artifacts'][0]['output_sample']
        );

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);
        $codes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('missing_published_artifact_install_command_provenance', $codes);
        $this->assertContains('missing_published_artifact_install_output_provenance', $codes);
    }

    public function test_result_gate_requires_started_at_when_generated_at_is_present(): void
    {
        $result = $this->completeChildWorkflowResult();
        unset($result['started_at']);

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('started_at', $this->missingRunRecordFields($evaluation));
    }

    public function test_result_gate_requires_finished_at_when_generated_at_is_present(): void
    {
        $result = $this->completeChildWorkflowResult();
        unset($result['finished_at']);

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('finished_at', $this->missingRunRecordFields($evaluation));
    }

    public function test_result_gate_requires_generated_at_when_start_and_finish_are_present(): void
    {
        $result = $this->completeChildWorkflowResult();
        unset($result['generated_at']);

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('generated_at', $this->missingRunRecordFields($evaluation));
    }

    public function test_result_gate_rejects_placeholder_artifact_versions_embedded_in_install_channel_strings(): void
    {
        $result = $this->completeChildWorkflowResult();
        $result['artifactVersions'] = [
            'server' => 'durableworkflow/server:<latest>',
            'cli' => 'latest',
            'sdk-python' => 'durable-workflow==<latest>',
            'workflow' => '2.0.0-alpha.<latest>',
            'waterline' => '2.0.0-alpha.54',
        ];

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);
        $placeholderFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'placeholder_artifact_version',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertSame(
            ['server', 'cli', 'workflow-php', 'sdk-python'],
            array_column($placeholderFailures, 'artifact'),
        );
    }

    public function test_result_gate_accepts_contract_declared_non_passing_outcomes(): void
    {
        $coverageGate = ChildWorkflowRuntimeContract::manifest()['coverage_gate'];
        $acceptedOutcomes = [
            $coverageGate['uncovered_required_scenario_outcome'],
            $coverageGate['smoke_subset_outcome'],
            $coverageGate['unsupported_public_surface_outcome'],
            $coverageGate['runner_blocked_outcome'],
        ];

        foreach (array_unique($acceptedOutcomes) as $outcome) {
            $result = $this->completeChildWorkflowResult();
            $result['outcome'] = $outcome;
            $result['scenario_results']['child_workflow_namespace_contract']['status'] =
                $outcome === $coverageGate['runner_blocked_outcome'] ? 'runner_blocked' : 'unsupported';
            $result['scenario_results']['child_workflow_namespace_contract']['linked_findings'] = [
                'https://tracker.example/findings/child-workflow-namespace-contract',
            ];

            $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);

            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertNotContains(
                'invalid_declared_outcome',
                array_column($evaluation['gate_failures'], 'code'),
                'Outcome ' . $outcome . ' must remain valid because coverage_gate advertises it.',
            );
        }
    }

    public function test_result_gate_rejects_unknown_declared_outcome(): void
    {
        $result = $this->completeChildWorkflowResult();
        $result['outcome'] = 'smoke_pass';

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'invalid_declared_outcome',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_complete_pass_with_non_passing_declared_outcome(): void
    {
        $result = $this->completeChildWorkflowResult();
        $result['outcome'] = 'non_passing';

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);
        $mismatchFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'declared_outcome_status_mismatch',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertCount(1, $mismatchFailures);
        $this->assertSame('non_passing', $mismatchFailures[0]['outcome']);
        $this->assertSame('non_passing', $mismatchFailures[0]['declared_status']);
        $this->assertSame('pass', $mismatchFailures[0]['evaluated_status']);
    }

    public function test_result_gate_uses_non_empty_verdict_when_outcome_is_empty(): void
    {
        $result = $this->completeChildWorkflowResult();
        $result['outcome'] = '';
        $result['verdict'] = 'non_passing';

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);
        $mismatchFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'declared_outcome_status_mismatch',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertCount(1, $mismatchFailures);
        $this->assertSame('non_passing', $mismatchFailures[0]['outcome']);
        $this->assertSame('non_passing', $mismatchFailures[0]['declared_status']);
        $this->assertSame('pass', $mismatchFailures[0]['evaluated_status']);
    }

    public function test_result_gate_rejects_non_passing_evidence_with_pass_declared_outcome(): void
    {
        $result = $this->completeChildWorkflowResult();
        $result['scenario_results']['parent_cancellation_propagates_to_child']['status'] = 'fail';
        $result['scenario_results']['parent_cancellation_propagates_to_child']['linked_findings'] = [
            'https://tracker.example/findings/parent-cancellation-propagation',
        ];

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);
        $mismatchFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'declared_outcome_status_mismatch',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertCount(1, $mismatchFailures);
        $this->assertSame('pass', $mismatchFailures[0]['outcome']);
        $this->assertSame('pass', $mismatchFailures[0]['declared_status']);
        $this->assertSame('non_passing', $mismatchFailures[0]['evaluated_status']);
    }

    public function test_result_gate_requires_scenario_specific_runtime_evidence(): void
    {
        $result = $this->completeChildWorkflowResult();
        $result['failure_round_trip'] = [
            'typed_failures' => true,
        ];
        $result['cancellation_propagation'] = [
            'parent_to_child' => ['cancelled' => true],
            'direct_child' => ['observed_by_parent' => true],
        ];
        $result['replay_restart'] = [
            'decision_sequence_matches' => true,
        ];
        $result['fan_out'] = [
            'child_count' => 5,
            'overlap_observed' => true,
        ];
        $result['namespace_behavior'] = [
            'same_namespace_lineage' => true,
            'cross_namespace_verdict' => 'documented',
        ];

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('missing_failure_round_trip_evidence_cell', $failureCodes);
        $this->assertContains('missing_parent_child_cancellation_field', $failureCodes);
        $this->assertContains('missing_direct_child_cancellation_field', $failureCodes);
        $this->assertContains('missing_replay_restart_field', $failureCodes);
        $this->assertContains('fan_out_timestamp_count_below_required', $failureCodes);
        $this->assertContains('missing_namespace_behavior_field', $failureCodes);
    }

    public function test_result_gate_requires_published_artifact_install_section_fields(): void
    {
        $result = $this->completeChildWorkflowResult();
        unset($result['published_artifact_install']);

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);
        $installFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_published_artifact_install_field',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertSame([
            'server_image',
            'cli_release',
            'workflow_php_package',
            'sdk_python_package',
            'sdk_rust_package',
            'waterline_artifact',
        ], array_column($installFailures, 'field'));
    }

    public function test_result_gate_requires_published_artifact_install_evidence(): void
    {
        $result = $this->completeChildWorkflowResult();
        unset(
            $result['artifact_install_evidence'],
            $result['published_artifact_install']['artifact_install_evidence'],
            $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_install_evidence']
        );

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'missing_published_artifact_install_evidence',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_non_passing_published_artifact_install_evidence(): void
    {
        $result = $this->completeChildWorkflowResult();
        $result['artifact_install_evidence']['artifacts'][1]['status'] = 'not_covered';

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);
        $installFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'published_artifact_install_evidence_not_pass',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertCount(1, $installFailures);
        $this->assertSame('cli', $installFailures[0]['artifact']);
    }

    public function test_result_gate_rejects_generic_published_artifact_install_sources(): void
    {
        $result = $this->completeChildWorkflowResult();
        $result['artifact_install_evidence']['artifacts'][0]['source'] = 'docker';

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);
        $sourceFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'invalid_published_artifact_install_evidence_source',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertCount(1, $sourceFailures);
        $this->assertSame('server', $sourceFailures[0]['artifact']);
    }

    public function test_result_gate_accepts_a_complete_passing_matrix(): void
    {
        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($this->completeChildWorkflowResult());

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['missing_scenarios']);
        $this->assertSame([], $evaluation['non_pass_scenarios']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_rejects_parent_cancellation_without_matching_terminal_history_and_policy(): void
    {
        $result = $this->completeChildWorkflowResult();
        $parentCancellation = &$result['cancellation_propagation']['parent_to_child'];
        $parentCancellation['worker_observed_typed_cancellation'] = true;
        unset(
            $parentCancellation['child_cancellation_history_evidence'],
            $parentCancellation['parent_close_policy_evidence']
        );

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('parent_cancellation_typed_child_history_mismatch', $failureCodes);
        $this->assertContains('parent_cancellation_parent_close_policy_mismatch', $failureCodes);
    }

    public function test_result_gate_rejects_parent_cancellation_policy_for_a_different_child_run(): void
    {
        $result = $this->completeChildWorkflowResult();
        $result['cancellation_propagation']['parent_to_child']['parent_close_policy_evidence']['payload']['child_run_id'] =
            '4A852DDE50B15BA85B88EE73BF';

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'parent_cancellation_parent_close_policy_mismatch',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_all_pass_checks_when_status_alias_is_non_passing(): void
    {
        $result = $this->completeChildWorkflowResult();
        unset($result['outcome']);
        $result['status'] = 'non_passing';

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);
        $mismatchFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'declared_outcome_status_mismatch',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertCount(1, $mismatchFailures);
        $this->assertSame('status', $mismatchFailures[0]['field']);
        $this->assertSame('non_passing', $mismatchFailures[0]['outcome']);
        $this->assertSame('non_passing', $mismatchFailures[0]['declared_status']);
        $this->assertSame('pass', $mismatchFailures[0]['evaluated_status']);
    }

    public function test_result_gate_rejects_conflicting_outcome_and_verdict_aliases(): void
    {
        $result = $this->completeChildWorkflowResult();
        $result['outcome'] = 'non_passing';
        $result['verdict'] = 'pass';

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('declared_outcome_status_mismatch', $failureCodes);
        $this->assertContains('conflicting_outcome_tokens', $failureCodes);
    }

    public function test_result_gate_rejects_conflicting_status_and_verdict_aliases_when_outcome_is_empty(): void
    {
        $result = $this->completeChildWorkflowResult();
        unset($result['outcome']);
        $result['status'] = 'non_passing';
        $result['verdict'] = 'pass';

        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');
        $aliasFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'conflicting_outcome_tokens',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('declared_outcome_status_mismatch', $failureCodes);
        $this->assertContains('conflicting_outcome_tokens', $failureCodes);
        $this->assertCount(1, $aliasFailures);
        $this->assertSame([
            'status' => 'non_passing',
            'verdict' => 'pass',
        ], $aliasFailures[0]['declared_outcomes']);
    }

    /**
     * @param array<string, mixed> $evaluation
     *
     * @return list<string>
     */
    private function missingRunRecordFields(array $evaluation): array
    {
        $fields = [];
        foreach ($evaluation['gate_failures'] ?? [] as $failure) {
            if (! is_array($failure) || ($failure['code'] ?? null) !== 'missing_run_record_field') {
                continue;
            }

            $field = $failure['field'] ?? null;
            if (is_string($field)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeEvidence(
        string $label,
        string $timestamp,
        string $parentEvent = 'ChildRunCompleted',
        string $childEvent = 'WorkflowCompleted',
    ): array {
        $hash = strtoupper(hash('sha256', $label));
        $parentWorkflowId = 'cw-' . strtolower(substr($hash, 0, 16));
        $childWorkflowId = 'cw-' . strtolower(substr($hash, 16, 16));
        $parentRunId = substr($hash, 0, 26);
        $childRunId = substr($hash, 26, 26);
        $taskQueue = 'cw-queue-' . strtolower(substr($hash, 52, 8));

        return [
            'parent_workflow_id' => $parentWorkflowId,
            'parent_run_id' => $parentRunId,
            'child_workflow_id' => $childWorkflowId,
            'child_run_id' => $childRunId,
            'task_queue' => $taskQueue,
            'observed_at' => $timestamp,
            'parent_history' => [
                'workflow_id' => $parentWorkflowId,
                'run_id' => $parentRunId,
                'events' => [[
                    'event_type' => $parentEvent,
                    'timestamp' => $timestamp,
                    'payload' => ['child_workflow_run_id' => $childRunId],
                ]],
            ],
            'child_history' => [
                'workflow_id' => $childWorkflowId,
                'run_id' => $childRunId,
                'events' => [[
                    'event_type' => $childEvent,
                    'timestamp' => $timestamp,
                    'payload' => [],
                ]],
            ],
            'runtime_observations' => [
                [
                    'runtime' => 'sdk-python',
                    'task_id' => substr($hash, 0, 24),
                    'lease_owner' => 'worker-' . strtolower(substr($hash, 0, 8)),
                    'task_queue' => $taskQueue,
                    'workflow_id' => $parentWorkflowId,
                    'run_id' => $parentRunId,
                ],
                [
                    'runtime' => 'workflow-php',
                    'task_id' => substr($hash, 8, 24),
                    'lease_owner' => 'worker-' . strtolower(substr($hash, 8, 8)),
                    'task_queue' => $taskQueue,
                    'workflow_id' => $childWorkflowId,
                    'run_id' => $childRunId,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function fanOutRuntimeEvidence(): array
    {
        $parent = $this->runtimeEvidence('fan-out-parent', '2026-05-20T05:04:01.000Z');
        $taskQueue = $parent['task_queue'];
        $identities = [];
        $histories = [];
        $started = [];
        $completed = [];
        $parentEvents = [];
        $observations = [$parent['runtime_observations'][0]];

        for ($index = 0; $index < 5; ++$index) {
            $start = sprintf('2026-05-20T05:04:00.%03dZ', $index * 10);
            $finish = sprintf('2026-05-20T05:04:01.%03dZ', $index * 10);
            $child = $this->runtimeEvidence('fan-out-child-' . $index, $finish);
            $child['child_history']['events'] = [
                ['event_type' => 'WorkflowStarted', 'timestamp' => $start, 'payload' => []],
                ['event_type' => 'WorkflowCompleted', 'timestamp' => $finish, 'payload' => []],
            ];
            $identities[] = [
                'workflow_id' => $child['child_workflow_id'],
                'run_id' => $child['child_run_id'],
            ];
            $histories[] = $child['child_history'];
            $started[] = $start;
            $completed[] = $finish;
            $parentEvents[] = [
                'event_type' => 'ChildRunCompleted',
                'timestamp' => $finish,
                'payload' => ['child_workflow_run_id' => $child['child_run_id']],
            ];
            $observation = $child['runtime_observations'][1];
            $observation['task_queue'] = $taskQueue;
            $observations[] = $observation;
        }

        return [
            'child_count' => 5,
            'child_started_at_values' => $started,
            'child_completed_at_values' => $completed,
            'aggregate_result' => ['child_count' => 5, 'values' => ['0', '1', '2', '3', '4']],
            'overlap_observed' => true,
            'parent_workflow_id' => $parent['parent_workflow_id'],
            'parent_run_id' => $parent['parent_run_id'],
            'task_queue' => $taskQueue,
            'parent_history' => [
                'workflow_id' => $parent['parent_workflow_id'],
                'run_id' => $parent['parent_run_id'],
                'events' => $parentEvents,
            ],
            'child_run_identities' => $identities,
            'child_histories' => $histories,
            'runtime_observations' => $observations,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function cancellationRuntimeObservations(string $label, string $timestamp): array
    {
        $evidence = $this->runtimeEvidence($label, $timestamp, 'ChildRunCancelled', 'WorkflowCancelled');
        $evidence['runtime_observations'][0]['runtime_result'] = [
            'failure_kind' => 'cancelled',
            'exception_type' => 'ChildWorkflowCancelled',
            'exception_class' => 'durable_workflow.errors.ChildWorkflowCancelled',
            'child_run_id' => $evidence['child_run_id'],
        ];

        return $evidence['runtime_observations'];
    }

    /** @return array<string, mixed> */
    private function parentCancellationRuntimeEvidence(): array
    {
        $evidence = $this->runtimeEvidence(
            'parent-cancellation',
            '2026-05-20T05:01:03Z',
            'ParentClosePolicyApplied',
            'WorkflowCancelled',
        );
        $exceptionClass = 'Workflow\\V2\\Exceptions\\WorkflowCancelledException';
        $message = 'Parent workflow closed (cancelled); parent-close policy: request_cancel.';
        $evidence['parent_history']['events'][0]['payload'] = [
            'child_run_id' => $evidence['child_run_id'],
            'policy' => 'request_cancel',
            'reason' => $message,
        ];
        $evidence['child_history']['events'][0]['payload'] = [
            'failure_category' => 'cancelled',
            'exception_class' => $exceptionClass,
            'message' => $message,
        ];
        return $evidence + [
            'worker_observed_typed_cancellation' => false,
            'typed_cancellation_observed' => true,
            'typed_cancellation_evidence_source' => 'terminal_child_history_and_parent_close_policy',
            'child_failure_kind' => 'cancelled',
            'child_exception_type' => 'WorkflowCancelledException',
            'child_exception_class' => $exceptionClass,
            'child_message' => $message,
            'child_cancellation_history_evidence' => $evidence['child_history']['events'][0],
            'parent_close_policy_evidence' => $evidence['parent_history']['events'][0],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function completeChildWorkflowResult(): array
    {
        $scenarioResults = [];
        foreach (ChildWorkflowRuntimeContract::manifest()['required_scenarios'] as $scenario) {
            $scenarioResults[$scenario] = [
                'scenario_id' => $scenario,
                'status' => 'pass',
                'observed_outputs' => [
                    'recorded' => true,
                    ...$this->runtimeEvidence($scenario, '2026-05-20T05:00:30Z'),
                    'parent_final_result' => 'parent-result-' . $scenario,
                    'child_history_excerpt' => ['ChildWorkflowScheduled', 'ChildRunCompleted'],
                ],
            ];
        }
        $artifactInstallEvidence = [
            'schema' => 'durable-workflow.v2.child-workflow-runtime.artifact-install-evidence',
            'generated_at' => '2026-05-20T05:00:30Z',
            'local_product_source_checkouts_used' => false,
            'artifacts' => [
                [
                    'artifact' => 'server',
                    'version' => '0.2.144',
                    'source' => 'docker://durableworkflow/server:0.2.144',
                    'status' => 'pass',
                    'commands' => [['argv' => ['docker', 'pull', 'durableworkflow/server:0.2.144']]],
                    'output_sample' => 'pulled durableworkflow/server:0.2.144',
                ],
                [
                    'artifact' => 'cli',
                    'version' => '0.1.45',
                    'source' => 'https://github.com/durable-workflow/cli/releases/download/v0.1.45/dw-linux-amd64',
                    'status' => 'pass',
                    'commands' => [['argv' => ['sh', 'install.sh']]],
                    'output_sample' => 'dw 0.1.45',
                ],
                [
                    'artifact' => 'workflow-php',
                    'version' => '2.0.0-alpha.164',
                    'source' => 'packagist:durable-workflow/workflow:2.0.0-alpha.164',
                    'status' => 'pass',
                    'commands' => [['argv' => ['composer', 'require', 'durable-workflow/workflow:2.0.0-alpha.164']]],
                    'output_sample' => 'installed durable-workflow/workflow 2.0.0-alpha.164',
                ],
                [
                    'artifact' => 'sdk-python',
                    'version' => '0.4.60',
                    'source' => 'pypi:durable-workflow==0.4.60',
                    'status' => 'pass',
                    'commands' => [['argv' => ['pip', 'install', 'durable-workflow==0.4.60']]],
                    'output_sample' => 'installed durable-workflow 0.4.60',
                ],
                [
                    'artifact' => 'sdk-rust',
                    'version' => '0.1.5',
                    'source' => 'https://crates.io/crates/durable-workflow/0.1.5',
                    'status' => 'pass',
                    'commands' => [['argv' => ['GET', 'https://crates.io/api/v1/crates/durable-workflow/0.1.5']]],
                    'output_sample' => 'resolved durable-workflow 0.1.5',
                ],
                [
                    'artifact' => 'waterline',
                    'version' => '2.0.0-alpha.54',
                    'source' => 'packagist:durable-workflow/waterline:2.0.0-alpha.54',
                    'status' => 'pass',
                    'commands' => [['argv' => ['composer', 'require', 'durable-workflow/waterline:2.0.0-alpha.54']]],
                    'output_sample' => 'resolved durable-workflow/waterline 2.0.0-alpha.54',
                ],
            ],
        ];
        $scenarioResults['published_artifact_install_only']['observed_outputs']['artifact_install_evidence'] =
            $artifactInstallEvidence;

        return [
            'schema' => ChildWorkflowRuntimeContract::RESULT_SCHEMA,
            'runtime_evidence_source' => 'published_server_image_runtime_probe',
            'local_product_source_checkouts_used' => false,
            'started_at' => '2026-05-20T05:00:00Z',
            'finished_at' => '2026-05-20T05:05:00Z',
            'generated_at' => '2026-05-20T05:05:00Z',
            'outcome' => 'pass',
            'artifactVersions' => [
                'server' => '0.2.144',
                'cli' => '0.1.45',
                'sdk-python' => '0.4.60',
                'sdk-rust' => '0.1.5',
                'workflow' => '2.0.0-alpha.164',
                'waterline' => '2.0.0-alpha.54',
            ],
            'artifact_sources' => [
                'server' => 'docker://durableworkflow/server:0.2.144',
                'cli' => 'https://github.com/durable-workflow/cli/releases/download/v0.1.45/dw-linux-amd64',
                'sdk-python' => 'pypi:durable-workflow==0.4.60',
                'sdk-rust' => 'https://crates.io/crates/durable-workflow/0.1.5',
                'workflow' => 'packagist:durable-workflow/workflow:2.0.0-alpha.164',
                'workflow-php' => 'packagist:durable-workflow/workflow:2.0.0-alpha.164',
                'waterline' => 'packagist:durable-workflow/waterline:2.0.0-alpha.54',
            ],
            'artifact_install_evidence' => $artifactInstallEvidence,
            'published_artifact_install' => [
                'server_image' => 'durableworkflow/server:0.2.144',
                'cli_release' => 'dw 0.1.45',
                'workflow_php_package' => 'durable-workflow/workflow 2.0.0-alpha.164',
                'sdk_python_package' => 'durable-workflow 0.4.60',
                'sdk_rust_package' => 'durable-workflow 0.1.5',
                'waterline_artifact' => 'waterline 2.0.0-alpha.54',
                'artifact_install_evidence' => $artifactInstallEvidence,
            ],
            'runtime_matrix' => [
                'runtimes' => ['workflow-php', 'sdk-python'],
                'same_language_cells' => [
                    [
                        'scenario' => 'python_parent_python_child_baseline',
                        'parent' => 'sdk-python',
                        'child' => 'sdk-python',
                        'status' => 'pass',
                    ],
                    [
                        'scenario' => 'php_parent_php_child_baseline',
                        'parent' => 'workflow-php',
                        'child' => 'workflow-php',
                        'status' => 'pass',
                    ],
                ],
                'cross_language_cells' => [
                    [
                        'scenario' => 'php_parent_python_child_cross_language',
                        'parent' => 'workflow-php',
                        'child' => 'sdk-python',
                        'status' => 'pass',
                    ],
                    [
                        'scenario' => 'python_parent_php_child_cross_language',
                        'parent' => 'sdk-python',
                        'child' => 'workflow-php',
                        'status' => 'pass',
                    ],
                ],
                'failure_round_trip_cells' => [
                    [
                        'scenario' => 'child_failure_round_trip_matrix',
                        'parent' => 'sdk-python',
                        'child' => 'sdk-python',
                        'status' => 'pass',
                    ],
                    [
                        'scenario' => 'child_failure_round_trip_matrix',
                        'parent' => 'workflow-php',
                        'child' => 'workflow-php',
                        'status' => 'pass',
                    ],
                    [
                        'scenario' => 'child_failure_round_trip_matrix',
                        'parent' => 'workflow-php',
                        'child' => 'sdk-python',
                        'status' => 'pass',
                    ],
                    [
                        'scenario' => 'child_failure_round_trip_matrix',
                        'parent' => 'sdk-python',
                        'child' => 'workflow-php',
                        'status' => 'pass',
                    ],
                ],
            ],
            'failure_round_trip' => [
                'failure_round_trip_cells' => [
                    [
                        'scenario' => 'child_failure_round_trip_matrix',
                        'parent' => 'sdk-python',
                        'child' => 'sdk-python',
                        'status' => 'pass',
                        'exception_class' => 'ChildWorkflowError',
                        'exception_type' => 'ChildWorkflowError',
                        'message' => 'python child failed',
                        'failure_kind' => 'child_workflow',
                        'parent_workflow_id' => 'parent-python-python-failure',
                        'parent_run_id' => 'parent-run-python-python-failure',
                        'child_workflow_id' => 'child-python-python-failure',
                        'child_run_id' => 'child-run-python-python-failure',
                        'task_queue' => 'failure-python-python',
                        'observed_at' => '2026-05-20T05:00:40Z',
                        'parent_history_excerpt' => ['ChildWorkflowScheduled', 'ChildRunFailed'],
                        'child_history_excerpt' => ['WorkflowFailed'],
                        ...$this->runtimeEvidence(
                            'failure-python-python',
                            '2026-05-20T05:00:40Z',
                            'ChildRunFailed',
                            'WorkflowFailed',
                        ),
                    ],
                    [
                        'scenario' => 'child_failure_round_trip_matrix',
                        'parent' => 'workflow-php',
                        'child' => 'workflow-php',
                        'status' => 'pass',
                        'exception_class' => 'ChildWorkflowError',
                        'exception_type' => 'ChildWorkflowError',
                        'message' => 'php child failed',
                        'failure_kind' => 'child_workflow',
                        'parent_workflow_id' => 'parent-php-php-failure',
                        'parent_run_id' => 'parent-run-php-php-failure',
                        'child_workflow_id' => 'child-php-php-failure',
                        'child_run_id' => 'child-run-php-php-failure',
                        'task_queue' => 'failure-php-php',
                        'observed_at' => '2026-05-20T05:00:41Z',
                        'parent_history_excerpt' => ['ChildWorkflowScheduled', 'ChildRunFailed'],
                        'child_history_excerpt' => ['WorkflowFailed'],
                        ...$this->runtimeEvidence(
                            'failure-php-php',
                            '2026-05-20T05:00:41Z',
                            'ChildRunFailed',
                            'WorkflowFailed',
                        ),
                    ],
                    [
                        'scenario' => 'child_failure_round_trip_matrix',
                        'parent' => 'workflow-php',
                        'child' => 'sdk-python',
                        'status' => 'pass',
                        'exception_class' => 'ChildWorkflowError',
                        'exception_type' => 'ChildWorkflowError',
                        'message' => 'python child failed',
                        'failure_kind' => 'child_workflow',
                        'parent_workflow_id' => 'parent-php-python-failure',
                        'parent_run_id' => 'parent-run-php-python-failure',
                        'child_workflow_id' => 'child-php-python-failure',
                        'child_run_id' => 'child-run-php-python-failure',
                        'task_queue' => 'failure-php-python',
                        'observed_at' => '2026-05-20T05:00:42Z',
                        'parent_history_excerpt' => ['ChildWorkflowScheduled', 'ChildRunFailed'],
                        'child_history_excerpt' => ['WorkflowFailed'],
                        ...$this->runtimeEvidence(
                            'failure-php-python',
                            '2026-05-20T05:00:42Z',
                            'ChildRunFailed',
                            'WorkflowFailed',
                        ),
                    ],
                    [
                        'scenario' => 'child_failure_round_trip_matrix',
                        'parent' => 'sdk-python',
                        'child' => 'workflow-php',
                        'status' => 'pass',
                        'exception_class' => 'ChildWorkflowError',
                        'exception_type' => 'ChildWorkflowError',
                        'message' => 'php child failed',
                        'failure_kind' => 'child_workflow',
                        'parent_workflow_id' => 'parent-python-php-failure',
                        'parent_run_id' => 'parent-run-python-php-failure',
                        'child_workflow_id' => 'child-python-php-failure',
                        'child_run_id' => 'child-run-python-php-failure',
                        'task_queue' => 'failure-python-php',
                        'observed_at' => '2026-05-20T05:00:43Z',
                        'parent_history_excerpt' => ['ChildWorkflowScheduled', 'ChildRunFailed'],
                        'child_history_excerpt' => ['WorkflowFailed'],
                        ...$this->runtimeEvidence(
                            'failure-python-php',
                            '2026-05-20T05:00:43Z',
                            'ChildRunFailed',
                            'WorkflowFailed',
                        ),
                    ],
                ],
            ],
            'cancellation_propagation' => [
                'parent_to_child' => [
                    'cancel_issued_at' => '2026-05-20T05:01:00Z',
                    'child_cancelled_at' => '2026-05-20T05:01:03Z',
                    ...$this->parentCancellationRuntimeEvidence(),
                    'parent_observed_at' => '2026-05-20T05:01:03Z',
                    'child_cancelled_at' => '2026-05-20T05:01:03Z',
                ],
                'direct_child' => [
                    'child_cancel_issued_at' => '2026-05-20T05:02:00Z',
                    'parent_observed_at' => '2026-05-20T05:02:02Z',
                    'parent_failure_kind' => 'cancelled',
                    'parent_workflow_id' => 'parent-direct-cancel',
                    'parent_run_id' => 'parent-run-direct-cancel',
                    'child_workflow_id' => 'child-direct-cancel',
                    'child_run_id' => 'child-run-direct-cancel',
                    'task_queue' => 'direct-cancel-queue',
                    'parent_history' => ['events' => [['event_type' => 'ChildRunCancelled']]],
                    'parent_exception_type' => 'ChildWorkflowCancelled',
                    'parent_exception_class' => 'durable_workflow.errors.ChildWorkflowCancelled',
                    ...$this->runtimeEvidence(
                        'direct-child-cancellation',
                        '2026-05-20T05:02:02Z',
                        'ChildRunCancelled',
                        'WorkflowCancelled',
                    ),
                    'runtime_observations' => $this->cancellationRuntimeObservations(
                        'direct-child-cancellation',
                        '2026-05-20T05:02:02Z',
                    ),
                    'parent_observed_at' => '2026-05-20T05:02:02Z',
                ],
                'parent_close_policy' => [
                    'policy' => 'request_cancel',
                    'parent_workflow_id' => 'parent-close',
                    'parent_run_id' => 'parent-run-close',
                    'child_workflow_id' => 'child-close',
                    'child_run_id' => 'child-run-close',
                    'task_queue' => 'parent-close-queue',
                    'child_status' => 'cancelled',
                    'history_excerpt' => ['CancelRequested', 'WorkflowCancelled'],
                ],
            ],
            'replay_restart' => [
                'parent_worker_stopped_at' => '2026-05-20T05:03:00Z',
                'parent_worker_restarted_at' => '2026-05-20T05:03:05Z',
                'original_decision_sequence' => ['start_child', 'await_child', 'complete_parent'],
                'replayed_decision_sequence' => ['start_child', 'await_child', 'complete_parent'],
                'duplicate_child_scheduled' => false,
                'parent_workflow_id' => 'parent-replay',
                'parent_run_id' => 'parent-run-replay',
                'child_workflow_id' => 'child-replay',
                'child_run_id' => 'child-run-replay',
                'task_queue' => 'replay-queue',
                'parent_history' => ['events' => [['event_type' => 'ChildRunCompleted']]],
                ...$this->runtimeEvidence('worker-restart-replay', '2026-05-20T05:03:05Z'),
            ],
            'fan_out' => [
                'child_count' => 5,
                'child_started_at_values' => [
                    '2026-05-20T05:04:00.000Z',
                    '2026-05-20T05:04:00.010Z',
                    '2026-05-20T05:04:00.020Z',
                    '2026-05-20T05:04:00.030Z',
                    '2026-05-20T05:04:00.040Z',
                ],
                'child_completed_at_values' => [
                    '2026-05-20T05:04:01.000Z',
                    '2026-05-20T05:04:01.010Z',
                    '2026-05-20T05:04:01.020Z',
                    '2026-05-20T05:04:01.030Z',
                    '2026-05-20T05:04:01.040Z',
                ],
                'aggregate_result' => 15,
                'overlap_observed' => true,
                'parent_workflow_id' => 'parent-fan-out',
                'parent_run_id' => 'parent-run-fan-out',
                'task_queue' => 'fan-out-queue',
                'parent_history' => ['events' => [['event_type' => 'ChildWorkflowScheduled']]],
                'child_run_identities' => [
                    ['workflow_id' => 'fan-child-1', 'run_id' => 'fan-run-1'],
                    ['workflow_id' => 'fan-child-2', 'run_id' => 'fan-run-2'],
                    ['workflow_id' => 'fan-child-3', 'run_id' => 'fan-run-3'],
                    ['workflow_id' => 'fan-child-4', 'run_id' => 'fan-run-4'],
                    ['workflow_id' => 'fan-child-5', 'run_id' => 'fan-run-5'],
                ],
                ...$this->fanOutRuntimeEvidence(),
            ],
            'namespace_behavior' => [
                'parent_namespace' => 'tenant-a',
                'child_namespace' => 'tenant-a',
                'lineage_links' => [
                    [
                        'parent_workflow_id' => 'cw-e09d50ec2aa2f6f0',
                        'parent_run_id' => 'E09D50EC2AA2F6F087DE881DD2',
                        'child_workflow_id' => 'cw-87de881dd22ec71a',
                        'child_run_id' => '2EC71A235114222CE34E15D597',
                    ],
                ],
                'cross_namespace_verdict' => 'documented',
                'parent_workflow_id' => 'namespace-parent',
                'parent_run_id' => 'namespace-parent-run',
                'child_workflow_id' => 'namespace-child',
                'child_run_id' => 'namespace-child-run',
                'task_queue' => 'namespace-queue',
                'operator_visible_debug' => ['lineage' => 'visible'],
                'parent_history_excerpt' => ['ChildWorkflowScheduled', 'ChildRunCompleted'],
                'child_history_excerpt' => ['WorkflowStarted', 'WorkflowCompleted'],
                ...$this->runtimeEvidence('namespace-lineage', '2026-05-20T05:04:30Z'),
            ],
            'findings' => [],
            'finding_links' => [],
            'scenario_results' => $scenarioResults,
        ];
    }

    /**
     * @param array<string, string> $env
     * @param array<string, array{name: string, content: array<string, mixed>}> $evidenceFiles
     *
     * @return array{exitCode: int, result: array<string, mixed>}
     */
    private function runChildWorkflowRunnerWithEvidence(array $env, array $evidenceFiles): array
    {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === ''
            || trim((string) shell_exec('command -v python3 2>/dev/null')) === '') {
            $this->markTestSkipped('bash and python3 are required to exercise the child-workflows runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = $repoRoot . '/storage/framework/child-workflows-' . bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($resultDir, 0777, true));

        try {
            foreach ($evidenceFiles as $envName => $spec) {
                $path = $resultDir . '/' . $spec['name'];
                file_put_contents(
                    $path,
                    json_encode($spec['content'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n",
                );
                $env[$envName] = $path;
            }

            $envPrefix = implode(' ', array_map(
                static fn (string $name, string $value): string => $name . '=' . escapeshellarg($value),
                array_keys($env),
                array_values($env),
            ));
            $command = sprintf(
                '%s bash %s --result-dir %s >/dev/null 2>&1',
                $envPrefix,
                escapeshellarg($repoRoot . '/scripts/conformance/child-workflows-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $exitCode);
            $result = json_decode(
                file_get_contents($resultDir . '/child-workflows-result.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            return [
                'exitCode' => $exitCode,
                'result' => $result,
            ];
        } finally {
            foreach (glob($resultDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($resultDir)) {
                rmdir($resultDir);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function childWorkflowRunnerBaseEnv(): array
    {
        return [
            'DW_SERVER_VERSION' => '9.9.9',
            'DW_CLI_VERSION' => '9.9.9',
            'DW_PYTHON_SDK_VERSION' => '9.9.9',
            'DW_RUST_SDK_VERSION' => '9.9.9',
            'DW_WORKFLOW_PHP_VERSION' => '9.9.9',
            'DW_WATERLINE_VERSION' => '9.9.9',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function childWorkflowArtifactInstallEvidence(): array
    {
        return [
            'schema' => 'durable-workflow.v2.child-workflow-runtime.artifact-install-evidence',
            'generated_at' => '2026-07-08T05:30:00Z',
            'local_product_source_checkouts_used' => false,
            'artifacts' => [
                [
                    'artifact' => 'server',
                    'version' => '9.9.9',
                    'source' => 'docker://durableworkflow/server:9.9.9',
                    'status' => 'pass',
                ],
                [
                    'artifact' => 'cli',
                    'version' => '9.9.9',
                    'source' => 'https://github.com/durable-workflow/cli/releases/download/v9.9.9/dw-linux-amd64',
                    'status' => 'pass',
                ],
                [
                    'artifact' => 'sdk-python',
                    'version' => '9.9.9',
                    'source' => 'pypi:durable-workflow==9.9.9',
                    'status' => 'pass',
                ],
                [
                    'artifact' => 'workflow-php',
                    'version' => '9.9.9',
                    'source' => 'packagist:durable-workflow/workflow:9.9.9',
                    'status' => 'pass',
                ],
                [
                    'artifact' => 'waterline',
                    'version' => '9.9.9',
                    'source' => 'packagist:durable-workflow/waterline:9.9.9',
                    'status' => 'pass',
                ],
                [
                    'artifact' => 'sdk-rust',
                    'version' => '9.9.9',
                    'source' => 'https://crates.io/crates/durable-workflow/9.9.9',
                    'status' => 'pass',
                ],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>>|null $cells
     *
     * @return array<string, mixed>
     */
    private function childWorkflowTypedFailureEvidence(?array $cells = null): array
    {
        return [
            'schema' => 'durable-workflow.v2.child-workflow-runtime.typed-failure-evidence',
            'generated_at' => '2026-07-08T05:31:00Z',
            'local_product_source_checkouts_used' => false,
            'artifact_versions' => [
                'server' => '9.9.9',
                'cli' => '9.9.9',
                'sdk-python' => '9.9.9',
                'sdk-rust' => '9.9.9',
                'workflow' => '9.9.9',
                'waterline' => '9.9.9',
            ],
            'failure_round_trip_cells' => $cells ?? [
                $this->childWorkflowTypedFailureCell('sdk-python', 'sdk-python'),
                $this->childWorkflowTypedFailureCell('workflow-php', 'workflow-php'),
                $this->childWorkflowTypedFailureCell('workflow-php', 'sdk-python'),
                $this->childWorkflowTypedFailureCell('sdk-python', 'workflow-php'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function childWorkflowTypedFailureCell(string $parent, string $child): array
    {
        $label = str_replace('-', '_', $parent . '_' . $child);

        return [
            'scenario' => 'child_failure_round_trip_matrix',
            'parent' => $parent,
            'child' => $child,
            'status' => 'pass',
            'exception_class' => 'ChildWorkflowDomainError',
            'message' => $child . ' child failed with a domain error',
            'failure_kind' => 'child_workflow',
            'parent_workflow_id' => 'parent-' . $label,
            'parent_run_id' => 'parent-run-' . $label,
            'child_workflow_id' => 'child-' . $label,
            'child_run_id' => 'child-run-' . $label,
            'parent_history_observations' => [
                'ChildWorkflowScheduled',
                'ChildWorkflowFailed',
            ],
            'child_history_observations' => [
                'WorkflowTaskFailed',
            ],
            'public_surfaces' => [
                $parent . ' child await surface',
                'server history API',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function childWorkflowFullMatrixEvidence(): array
    {
        $result = $this->completeChildWorkflowResult();
        $result['schema'] = 'durable-workflow.v2.child-workflow-runtime.full-matrix-evidence';
        $result['generated_at'] = '2026-07-08T05:32:00Z';
        $result['local_product_source_checkouts_used'] = false;
        $result['artifact_versions'] = [
            'server' => '9.9.9',
            'cli' => '9.9.9',
            'sdk-python' => '9.9.9',
            'sdk-rust' => '9.9.9',
            'workflow' => '9.9.9',
            'waterline' => '9.9.9',
        ];
        $result['failure_round_trip']['status'] = 'pass';
        $result['namespace_behavior']['lineage_links'] = [
            ['parent' => 'child-workflows-full-matrix', 'child' => 'child-workflows-full-matrix-child'],
        ];

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function scenarioResult(array $result, string $scenarioId): array
    {
        foreach ($result['scenario_results'] ?? [] as $scenario) {
            if (is_array($scenario) && ($scenario['scenario_id'] ?? null) === $scenarioId) {
                return $scenario;
            }
        }

        $this->fail('Missing scenario result for ' . $scenarioId);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, string>
     */
    private function failureRoundTripStatusByCell(array $result): array
    {
        $statuses = [];
        foreach ($result['failure_round_trip']['cells'] ?? [] as $cell) {
            if (! is_array($cell)) {
                continue;
            }

            $statuses[($cell['parent'] ?? '') . '->' . ($cell['child'] ?? '')] = (string) ($cell['status'] ?? '');
        }

        return $statuses;
    }

    private function read(string $path): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/' . $path) ?: '';
    }
}
