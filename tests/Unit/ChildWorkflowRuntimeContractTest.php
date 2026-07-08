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

        foreach (['server', 'cli', 'workflow-php', 'sdk-python', 'waterline'] as $artifact) {
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
            'waterline_artifact',
        ] as $field) {
            $this->assertContains($field, $requirements['published_artifact_install_only']['evidence']);
        }

        $this->assertSame(
            'child_reaches_cancelled_after_parent_cancel',
            $requirements['parent_cancellation_propagates_to_child']['required_behavior'],
        );
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

        foreach ([
            'DW_SERVER_VERSION',
            'DW_CLI_VERSION',
            'DW_PYTHON_SDK_VERSION',
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

    public function test_published_artifact_runner_rejects_typed_failure_evidence_without_install_evidence(): void
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
        $this->assertContains(
            'typed_failure_evidence requires passing published artifact install evidence',
            $result['failure_round_trip']['typed_failure_evidence_failures'] ?? [],
        );
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
        $this->assertSame('pass', $cellStatuses['sdk-python->sdk-python'] ?? null);
        $this->assertSame('not_covered', $cellStatuses['workflow-php->workflow-php'] ?? null);
        $this->assertSame('not_covered', $cellStatuses['workflow-php->sdk-python'] ?? null);
        $this->assertSame('not_covered', $cellStatuses['sdk-python->workflow-php'] ?? null);
        $this->assertStringContainsString(
            'typed failure evidence did not include required failure round-trip cells',
            $scenario['linked_findings'][0]['observed_behavior'] ?? '',
        );
    }

    public function test_published_artifact_runner_consumes_default_typed_failure_evidence_path(): void
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
        $this->assertSame('pass', $cellStatuses['sdk-python->sdk-python'] ?? null);
        $this->assertSame(
            'typed-failure-evidence.json',
            basename((string) ($result['failure_round_trip']['typed_failure_evidence_path'] ?? '')),
        );
    }

    public function test_published_artifact_runner_passes_typed_failure_matrix_only_with_all_required_cells(): void
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
        $this->assertSame('pass', $scenario['status']);
        $this->assertSame('pass', $result['failure_round_trip']['status'] ?? null);
        $this->assertSame(
            ['pass', 'pass', 'pass', 'pass'],
            array_column($result['failure_round_trip']['cells'] ?? [], 'status'),
        );
        $this->assertSame(
            'non_passing',
            $result['outcome'] ?? null,
            'passing the focused typed-failure matrix must not reopen the broader child-workflows matrix',
        );
    }

    public function test_published_artifact_runner_passes_with_full_matrix_evidence(): void
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

        $this->assertSame(0, $run['exitCode']);
        $this->assertSame('pass', $result['outcome'] ?? null);
        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame('pass', $this->scenarioResult($result, 'php_parent_python_child_cross_language')['status']);
        $this->assertSame('pass', $result['failure_round_trip']['status'] ?? null);
        $this->assertSame('child-workflows-full-matrix', $result['namespace_behavior']['lineage_links'][0]['parent'] ?? null);
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
    private function completeChildWorkflowResult(): array
    {
        $scenarioResults = [];
        foreach (ChildWorkflowRuntimeContract::manifest()['required_scenarios'] as $scenario) {
            $scenarioResults[$scenario] = [
                'scenario_id' => $scenario,
                'status' => 'pass',
                'observed_outputs' => [
                    'recorded' => true,
                    'parent_workflow_id' => 'parent-' . $scenario,
                    'child_workflow_id' => 'child-' . $scenario,
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
                ],
                [
                    'artifact' => 'cli',
                    'version' => '0.1.45',
                    'source' => 'https://github.com/durable-workflow/cli/releases/download/v0.1.45/dw-linux-amd64',
                    'status' => 'pass',
                ],
                [
                    'artifact' => 'workflow-php',
                    'version' => '2.0.0-alpha.164',
                    'source' => 'packagist:durable-workflow/workflow:2.0.0-alpha.164',
                    'status' => 'pass',
                ],
                [
                    'artifact' => 'sdk-python',
                    'version' => '0.4.60',
                    'source' => 'pypi:durable-workflow==0.4.60',
                    'status' => 'pass',
                ],
                [
                    'artifact' => 'waterline',
                    'version' => '2.0.0-alpha.54',
                    'source' => 'packagist:durable-workflow/waterline:2.0.0-alpha.54',
                    'status' => 'pass',
                ],
            ],
        ];
        $scenarioResults['published_artifact_install_only']['observed_outputs']['artifact_install_evidence'] =
            $artifactInstallEvidence;

        return [
            'schema' => ChildWorkflowRuntimeContract::RESULT_SCHEMA,
            'started_at' => '2026-05-20T05:00:00Z',
            'finished_at' => '2026-05-20T05:05:00Z',
            'generated_at' => '2026-05-20T05:05:00Z',
            'outcome' => 'pass',
            'artifactVersions' => [
                'server' => '0.2.144',
                'cli' => '0.1.45',
                'sdk-python' => '0.4.60',
                'workflow' => '2.0.0-alpha.164',
                'waterline' => '2.0.0-alpha.54',
            ],
            'artifact_sources' => [
                'server' => 'docker://durableworkflow/server:0.2.144',
                'cli' => 'https://github.com/durable-workflow/cli/releases/download/v0.1.45/dw-linux-amd64',
                'sdk-python' => 'pypi:durable-workflow==0.4.60',
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
                    ],
                    [
                        'scenario' => 'php_parent_php_child_baseline',
                        'parent' => 'workflow-php',
                        'child' => 'workflow-php',
                    ],
                ],
                'cross_language_cells' => [
                    [
                        'scenario' => 'php_parent_python_child_cross_language',
                        'parent' => 'workflow-php',
                        'child' => 'sdk-python',
                    ],
                    [
                        'scenario' => 'python_parent_php_child_cross_language',
                        'parent' => 'sdk-python',
                        'child' => 'workflow-php',
                    ],
                ],
                'failure_round_trip_cells' => [
                    [
                        'scenario' => 'child_failure_round_trip_matrix',
                        'parent' => 'sdk-python',
                        'child' => 'sdk-python',
                    ],
                    [
                        'scenario' => 'child_failure_round_trip_matrix',
                        'parent' => 'workflow-php',
                        'child' => 'workflow-php',
                    ],
                    [
                        'scenario' => 'child_failure_round_trip_matrix',
                        'parent' => 'workflow-php',
                        'child' => 'sdk-python',
                    ],
                    [
                        'scenario' => 'child_failure_round_trip_matrix',
                        'parent' => 'sdk-python',
                        'child' => 'workflow-php',
                    ],
                ],
            ],
            'failure_round_trip' => [
                'failure_round_trip_cells' => [
                    [
                        'scenario' => 'child_failure_round_trip_matrix',
                        'parent' => 'sdk-python',
                        'child' => 'sdk-python',
                        'exception_class' => 'ChildWorkflowError',
                        'message' => 'python child failed',
                        'failure_kind' => 'child_workflow',
                    ],
                    [
                        'scenario' => 'child_failure_round_trip_matrix',
                        'parent' => 'workflow-php',
                        'child' => 'workflow-php',
                        'exception_class' => 'ChildWorkflowError',
                        'message' => 'php child failed',
                        'failure_kind' => 'child_workflow',
                    ],
                    [
                        'scenario' => 'child_failure_round_trip_matrix',
                        'parent' => 'workflow-php',
                        'child' => 'sdk-python',
                        'exception_class' => 'ChildWorkflowError',
                        'message' => 'python child failed',
                        'failure_kind' => 'child_workflow',
                    ],
                    [
                        'scenario' => 'child_failure_round_trip_matrix',
                        'parent' => 'sdk-python',
                        'child' => 'workflow-php',
                        'exception_class' => 'ChildWorkflowError',
                        'message' => 'php child failed',
                        'failure_kind' => 'child_workflow',
                    ],
                ],
            ],
            'cancellation_propagation' => [
                'parent_to_child' => [
                    'cancel_issued_at' => '2026-05-20T05:01:00Z',
                    'child_cancelled_at' => '2026-05-20T05:01:03Z',
                    'worker_observed_typed_cancellation' => true,
                ],
                'direct_child' => [
                    'child_cancel_issued_at' => '2026-05-20T05:02:00Z',
                    'parent_observed_at' => '2026-05-20T05:02:02Z',
                    'parent_failure_kind' => 'cancelled',
                ],
            ],
            'replay_restart' => [
                'parent_worker_stopped_at' => '2026-05-20T05:03:00Z',
                'parent_worker_restarted_at' => '2026-05-20T05:03:05Z',
                'original_decision_sequence' => ['start_child', 'await_child', 'complete_parent'],
                'replayed_decision_sequence' => ['start_child', 'await_child', 'complete_parent'],
                'duplicate_child_scheduled' => false,
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
            ],
            'namespace_behavior' => [
                'parent_namespace' => 'tenant-a',
                'child_namespace' => 'tenant-a',
                'lineage_links' => [
                    ['parent' => 'tenant-a/parent', 'child' => 'tenant-a/child'],
                ],
                'cross_namespace_verdict' => 'documented',
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
