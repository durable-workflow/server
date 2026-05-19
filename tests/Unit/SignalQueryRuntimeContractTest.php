<?php

namespace Tests\Unit;

use App\Support\SignalQueryRuntimeContract;
use App\Support\SignalQueryRuntimeResultGate;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;

class SignalQueryRuntimeContractTest extends TestCase
{
    public function test_manifest_requires_published_artifacts_and_run_record_fields(): void
    {
        $manifest = SignalQueryRuntimeContract::manifest();

        $this->assertSame('durable-workflow.v2.signal-query-runtime.contract', $manifest['schema']);
        $this->assertSame(1, $manifest['version']);
        $this->assertSame('durable-workflow.v2.signal-query-runtime.result', $manifest['result_schema']);
        $this->assertSame('signal_query_runtime_contract', $manifest['fixture_category']);
        $this->assertSame(
            PlatformConformanceSuite::SCHEMA,
            $manifest['platform_conformance_suite_authority'],
        );

        $this->assertSame(
            'latest_published_artifacts_at_run_time',
            $manifest['artifact_policy']['version_source'],
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
            'outcome',
            'scenario_results',
            'findings',
            'finding_links',
        ] as $field) {
            $this->assertContains($field, $manifest['artifact_policy']['required_run_record_fields']);
        }
    }

    public function test_manifest_names_the_runtime_client_and_observer_matrix(): void
    {
        $matrix = SignalQueryRuntimeContract::manifest()['required_matrix'];

        $this->assertSame(['workflow-php', 'sdk-python'], $matrix['runtimes']);
        $this->assertContains('cli', $matrix['client_paths']);
        $this->assertContains('workflow-php-sdk', $matrix['client_paths']);
        $this->assertContains('sdk-python', $matrix['client_paths']);
        $this->assertContains('waterline-selected-run-detail', $matrix['observer_paths']);
        $this->assertContains('waterline-query-action', $matrix['observer_paths']);

        $this->assertContains(
            [
                'worker' => 'sdk-python',
                'clients' => ['workflow-php-sdk', 'cli'],
                'scenario' => 'python_worker_php_facing_and_cli_clients',
            ],
            $matrix['cross_language_cells'],
        );
        $this->assertContains(
            [
                'worker' => 'workflow-php',
                'clients' => ['sdk-python', 'cli'],
                'scenario' => 'php_worker_python_and_cli_clients',
            ],
            $matrix['cross_language_cells'],
        );
    }

    public function test_manifest_keeps_smoke_only_coverage_non_passing(): void
    {
        $manifest = SignalQueryRuntimeContract::manifest();
        $gate = $manifest['coverage_gate'];

        $this->assertContains('not_covered', $manifest['scenario_statuses']);
        $this->assertSame('non_passing', $gate['uncovered_required_scenario_outcome']);
        $this->assertSame('non_passing', $gate['smoke_subset_outcome']);

        foreach ([
            'all_required_scenarios_reported',
            'all_required_runtimes_present',
            'cross_language_cells_reported',
            'replay_timing_reported',
            'terminal_run_behavior_reported',
            'adversarial_errors_typed',
            'waterline_observer_comparison_reported',
            'findings_linked_for_non_pass_scenarios',
        ] as $requirement) {
            $this->assertContains($requirement, $gate['passing_outcome_requires']);
        }

        foreach ([
            'published_artifact_install_only',
            'python_worker_cli_and_sdk_baseline',
            'php_worker_cli_and_sdk_baseline',
            'python_worker_php_facing_and_cli_clients',
            'php_worker_python_and_cli_clients',
            'ordered_signal_delivery',
            'dedup_contract_observation',
            'signal_during_replay',
            'query_during_replay',
            'completed_run_signal_and_query',
            'unknown_signal_and_query_errors',
            'malformed_signal_and_query_payloads',
            'waterline_operator_visibility',
        ] as $scenario) {
            $this->assertContains($scenario, $manifest['required_scenarios']);
        }
    }

    public function test_manifest_requires_actionable_diagnostics_for_replay_adversarial_and_observer_cases(): void
    {
        $requirements = SignalQueryRuntimeContract::manifest()['scenario_requirements'];

        $this->assertSame(
            'signal_applies_after_replay_consistent_point',
            $requirements['signal_during_replay']['required_behavior'],
        );
        $this->assertSame(
            'query_waits_for_replay_consistency',
            $requirements['query_during_replay']['required_behavior'],
        );
        $this->assertContains(
            'invalid_signal_arguments',
            $requirements['malformed_signal_and_query_payloads']['required_errors'],
        );
        $this->assertContains(
            'invalid_query_arguments',
            $requirements['malformed_signal_and_query_payloads']['required_errors'],
        );
        $this->assertSame(
            'query_results_not_materialized_in_selected_run_detail',
            $requirements['waterline_operator_visibility']['allowed_live_query_detail_limitation'],
        );

        $findingPolicy = SignalQueryRuntimeContract::manifest()['finding_policy'];
        $this->assertSame('link_root_cause_finding_against_server', $findingPolicy['ordering_drift']);
        $this->assertSame('link_root_cause_finding_against_waterline', $findingPolicy['observer_mismatch']);
        $this->assertSame(
            'link_root_cause_finding_against_surface_owner',
            $findingPolicy['unsupported_public_surface'],
        );
    }

    public function test_manifest_publishes_an_enforceable_result_gate(): void
    {
        $resultGate = SignalQueryRuntimeContract::manifest()['result_gate'];

        $this->assertSame(SignalQueryRuntimeResultGate::SCHEMA, $resultGate['schema']);
        $this->assertSame(SignalQueryRuntimeResultGate::VERSION, $resultGate['version']);
        $this->assertSame(
            SignalQueryRuntimeContract::RESULT_SCHEMA,
            $resultGate['evaluates_result_schema'],
        );
        $this->assertContains('scenario_results', $resultGate['scenario_results_fields']);
        $this->assertContains('artifactVersions', $resultGate['artifact_versions_fields']);
        $this->assertContains('every_required_scenario_has_one_result', $resultGate['pass_requires']);
        $this->assertContains('same_language_and_cross_language_cells_are_reported', $resultGate['pass_requires']);
        $this->assertContains('each_non_pass_scenario_has_linked_findings', $resultGate['pass_requires']);
        $this->assertSame('non_passing', $resultGate['smoke_subset_outcome']);
    }

    public function test_result_gate_rejects_python_smoke_subset_even_when_the_smoke_passes(): void
    {
        $evaluation = SignalQueryRuntimeResultGate::evaluate([
            'schema' => SignalQueryRuntimeContract::RESULT_SCHEMA,
            'artifactVersions' => [
                'server' => '0.2.140',
                'cli' => '0.1.45',
                'sdk-python' => '0.4.58',
                'workflow' => '2.0.0-alpha.161',
                'waterline' => '2.0.0-alpha.54',
            ],
            'runtime_matrix' => [
                'runtimes' => ['sdk-python'],
                'same_language_cells' => [
                    [
                        'scenario' => 'python_worker_cli_and_sdk_baseline',
                        'worker' => 'sdk-python',
                        'clients' => ['cli', 'sdk-python'],
                    ],
                ],
            ],
            'scenario_results' => [
                [
                    'scenario_id' => 'python_worker_cli_and_sdk_baseline',
                    'status' => 'pass',
                    'observed_outputs' => [
                        'query_result' => 3,
                    ],
                ],
            ],
        ]);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertTrue($evaluation['smoke_subset_detected']);
        $this->assertContains('php_worker_cli_and_sdk_baseline', $evaluation['missing_scenarios']);
        $this->assertContains(
            'smoke_subset_cannot_pass',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_findings_for_non_pass_scenarios(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['malformed_signal_and_query_payloads']['status'] = 'fail';
        unset($result['scenario_results']['malformed_signal_and_query_payloads']['linked_findings']);

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('malformed_signal_and_query_payloads', $evaluation['non_pass_scenarios']);
        $this->assertContains(
            'missing_non_pass_finding',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_duplicate_scenario_results(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results'] = array_values($result['scenario_results']);
        $result['scenario_results'][] = [
            'scenario_id' => 'python_worker_cli_and_sdk_baseline',
            'status' => 'pass',
            'observed_outputs' => [
                'query_result' => 4,
            ],
        ];

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
        $duplicateFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'duplicate_scenario_result',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertCount(1, $duplicateFailures);
        $this->assertSame('python_worker_cli_and_sdk_baseline', $duplicateFailures[0]['scenario_id']);
        $this->assertSame(2, $duplicateFailures[0]['count']);
        $this->assertSame(
            ['python_worker_cli_and_sdk_baseline' => 2],
            $evaluation['duplicate_scenarios'],
        );
    }

    public function test_result_gate_rejects_empty_pass_output_arrays(): void
    {
        $emptyObservedOutputs = $this->completeSignalQueryResult();
        $emptyObservedOutputs['scenario_results']['python_worker_cli_and_sdk_baseline']['observed_outputs'] = [];

        $emptyRuntimeMatrix = $this->completeSignalQueryResult();
        unset($emptyRuntimeMatrix['scenario_results']['python_worker_cli_and_sdk_baseline']['observed_outputs']);
        $emptyRuntimeMatrix['scenario_results']['python_worker_cli_and_sdk_baseline']['runtime_matrix'] = [];

        foreach ([$emptyObservedOutputs, $emptyRuntimeMatrix] as $result) {
            $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertContains(
                'missing_pass_observed_outputs',
                array_column($evaluation['gate_failures'], 'code'),
            );
        }
    }

    public function test_result_gate_accepts_a_complete_passing_matrix(): void
    {
        $evaluation = SignalQueryRuntimeResultGate::evaluate($this->completeSignalQueryResult());

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['missing_scenarios']);
        $this->assertSame([], $evaluation['non_pass_scenarios']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    /**
     * @return array<string, mixed>
     */
    private function completeSignalQueryResult(): array
    {
        $scenarioResults = [];
        foreach (SignalQueryRuntimeContract::manifest()['required_scenarios'] as $scenario) {
            $scenarioResults[$scenario] = [
                'scenario_id' => $scenario,
                'status' => 'pass',
                'observed_outputs' => [
                    'recorded' => true,
                ],
            ];
        }

        return [
            'schema' => SignalQueryRuntimeContract::RESULT_SCHEMA,
            'artifactVersions' => [
                'server' => '0.2.140',
                'cli' => '0.1.45',
                'sdk-python' => '0.4.58',
                'workflow' => '2.0.0-alpha.161',
                'waterline' => '2.0.0-alpha.54',
            ],
            'runtime_matrix' => [
                'runtimes' => ['workflow-php', 'sdk-python'],
                'same_language_cells' => [
                    [
                        'scenario' => 'python_worker_cli_and_sdk_baseline',
                        'worker' => 'sdk-python',
                        'clients' => ['cli', 'sdk-python'],
                    ],
                    [
                        'scenario' => 'php_worker_cli_and_sdk_baseline',
                        'worker' => 'workflow-php',
                        'clients' => ['cli', 'workflow-php-sdk'],
                    ],
                ],
                'cross_language_cells' => [
                    [
                        'scenario' => 'python_worker_php_facing_and_cli_clients',
                        'worker' => 'sdk-python',
                        'clients' => ['workflow-php-sdk', 'cli'],
                    ],
                    [
                        'scenario' => 'php_worker_python_and_cli_clients',
                        'worker' => 'workflow-php',
                        'clients' => ['sdk-python', 'cli'],
                    ],
                ],
            ],
            'replay_timing' => [
                'signal_during_replay' => ['delay_ms' => 1],
                'query_during_replay' => ['answer' => 8],
            ],
            'terminal_run_behavior' => [
                'completed_run_signal_and_query' => ['query_result' => 8],
            ],
            'adversarial_errors' => [
                'unknown_signal_and_query_errors' => ['typed' => true],
                'malformed_signal_and_query_payloads' => ['typed' => true],
            ],
            'waterline_observer_comparison' => [
                'waterline_operator_visibility' => ['selected_run' => true],
            ],
            'scenario_results' => $scenarioResults,
        ];
    }
}
