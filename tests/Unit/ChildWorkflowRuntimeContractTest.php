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

    public function test_result_gate_requires_published_artifact_install_evidence(): void
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
            'published_artifact_install' => [
                'server_image' => 'durableworkflow/server:0.2.144',
                'cli_release' => 'dw 0.1.45',
                'workflow_php_package' => 'durable-workflow/workflow 2.0.0-alpha.164',
                'sdk_python_package' => 'durable-workflow 0.4.60',
                'waterline_artifact' => 'waterline 2.0.0-alpha.54',
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
}
