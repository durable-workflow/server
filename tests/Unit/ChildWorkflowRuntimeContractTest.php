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
        $this->assertSame(1, $manifest['version']);
        $this->assertSame('durable-workflow.v2.child-workflow-runtime.result', $manifest['result_schema']);
        $this->assertSame('child_workflow_runtime_contract', $manifest['fixture_category']);
        $this->assertSame(
            PlatformConformanceSuite::SCHEMA,
            $manifest['platform_conformance_suite_authority'],
        );

        foreach (['server', 'cli', 'workflow-php', 'sdk-python'] as $artifact) {
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
            'outcome',
            'scenario_results',
            'findings',
            'finding_links',
        ] as $field) {
            $this->assertContains($field, $manifest['artifact_policy']['required_run_record_fields']);
        }
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
        $this->assertContains('every_required_scenario_has_one_result', $resultGate['pass_requires']);
        $this->assertContains(
            'same_language_and_cross_language_parent_child_cells_are_reported',
            $resultGate['pass_requires'],
        );
        $this->assertContains('each_non_pass_scenario_has_linked_findings', $resultGate['pass_requires']);
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

    public function test_result_gate_accepts_a_complete_passing_matrix(): void
    {
        $evaluation = ChildWorkflowRuntimeResultGate::evaluate($this->completeChildWorkflowResult());

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['missing_scenarios']);
        $this->assertSame([], $evaluation['non_pass_scenarios']);
        $this->assertSame([], $evaluation['gate_failures']);
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
                ],
            ];
        }

        return [
            'schema' => ChildWorkflowRuntimeContract::RESULT_SCHEMA,
            'artifactVersions' => [
                'server' => '0.2.144',
                'cli' => '0.1.45',
                'sdk-python' => '0.4.60',
                'workflow' => '2.0.0-alpha.164',
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
                'typed_failures' => true,
            ],
            'cancellation_propagation' => [
                'parent_to_child' => ['cancelled' => true],
                'direct_child' => ['observed_by_parent' => true],
            ],
            'replay_restart' => [
                'decision_sequence_matches' => true,
            ],
            'fan_out' => [
                'child_count' => 5,
                'overlap_observed' => true,
            ],
            'namespace_behavior' => [
                'same_namespace_lineage' => true,
                'cross_namespace_verdict' => 'documented',
            ],
            'scenario_results' => $scenarioResults,
        ];
    }
}
