<?php

namespace Tests\Unit;

use App\Support\WorkflowLifecycleContract;
use App\Support\WorkflowLifecycleResultGate;
use PHPUnit\Framework\TestCase;

class WorkflowLifecycleContractTest extends TestCase
{
    public function test_manifest_publishes_an_enforceable_result_gate(): void
    {
        $manifest = WorkflowLifecycleContract::manifest();
        $resultGate = $manifest['result_gate'];

        $this->assertSame(WorkflowLifecycleContract::SCHEMA, $manifest['schema']);
        $this->assertSame(WorkflowLifecycleContract::RESULT_SCHEMA, $manifest['result_schema']);
        $this->assertSame(WorkflowLifecycleResultGate::SCHEMA, $resultGate['schema']);
        $this->assertSame(WorkflowLifecycleResultGate::VERSION, $resultGate['version']);
        $this->assertSame(
            WorkflowLifecycleContract::RESULT_SCHEMA,
            $resultGate['evaluates_result_schema'],
        );
        $this->assertContains('artifact_sources', $manifest['artifact_policy']['required_run_record_fields']);
        $this->assertContains('lifecycle_cell_outcomes', $manifest['artifact_policy']['required_run_record_fields']);
        $this->assertContains('findings', $manifest['artifact_policy']['required_run_record_fields']);
        $this->assertContains('local_product_source_checkouts_used', $manifest['artifact_policy']['required_run_record_fields']);
        $this->assertContains('source_policy', $manifest['artifact_policy']['required_run_record_fields']);
        $this->assertContains('local_product_source_truthy_values_are_refused_consistently', $resultGate['pass_requires']);
    }

    public function test_result_gate_accepts_complete_published_artifact_lifecycle_pass(): void
    {
        $evaluation = WorkflowLifecycleResultGate::evaluate($this->completeLifecycleResult());

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_rejects_pass_when_required_provenance_is_missing(): void
    {
        $result = $this->completeLifecycleResult();
        unset(
            $result['artifact_sources'],
            $result['lifecycle_cell_outcomes'],
            $result['findings'],
            $result['local_product_source_checkouts_used'],
            $result['source_policy'],
        );

        $evaluation = WorkflowLifecycleResultGate::evaluate($result);
        $missingFields = $this->missingRunRecordFields($evaluation);

        $this->assertSame('non_passing', $evaluation['status']);
        foreach ([
            'artifact_sources',
            'lifecycle_cell_outcomes',
            'findings',
            'local_product_source_checkouts_used',
            'source_policy',
        ] as $field) {
            $this->assertContains($field, $missingFields);
        }
        $this->assertContains('missing_source_policy', array_column($evaluation['gate_failures'], 'code'));
        $this->assertContains('declared_outcome_mismatch', array_column($evaluation['gate_failures'], 'code'));
    }

    /**
     * @dataProvider truthyLocalSourceMarkers
     */
    public function test_result_gate_rejects_alternate_truthy_local_source_markers(mixed $marker): void
    {
        $result = $this->completeLifecycleResult();
        $result['local_product_source_checkouts_used'] = $marker;

        $evaluation = WorkflowLifecycleResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'local_product_source_checkout_used'
                && ($failure['field'] ?? null) === 'local_product_source_checkouts_used',
        ));
    }

    public function test_result_gate_rejects_nested_truthy_local_source_markers_consistently(): void
    {
        $result = $this->completeLifecycleResult();
        $scenarioId = 'continue_as_new_run_chain_visibility';
        $result['source_policy']['local_product_source_checkout_used_as_pass_evidence'] = 'yes';
        $result['lifecycle_cell_outcomes'][$scenarioId]['localProductSourceCheckoutsUsed'] = '1';
        $result['scenario_results'][$scenarioId]['observed_outputs']['local_product_source_checkouts_used'] = 'on';

        $evaluation = WorkflowLifecycleResultGate::evaluate($result);
        $sourceFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'local_product_source_checkout_used',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        foreach ([
            'source_policy',
            'lifecycle_cell_outcomes.localProductSourceCheckoutsUsed',
            'observed_outputs.local_product_source_checkouts_used',
        ] as $field) {
            $this->assertNotEmpty(array_filter(
                $sourceFailures,
                static fn (array $failure): bool => ($failure['field'] ?? null) === $field,
            ), $field);
        }
    }

    public function test_result_gate_rejects_contradictory_source_policy(): void
    {
        $result = $this->completeLifecycleResult();
        $result['source_policy']['published_artifacts_only'] = 'off';
        $result['source_policy']['allows_local_product_source_checkout_pass_evidence'] = 'on';

        $evaluation = WorkflowLifecycleResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('source_policy_must_require_published_artifacts', $failureCodes);
        $this->assertContains('source_policy_allows_local_product_source_pass_evidence', $failureCodes);
    }

    public function test_result_gate_requires_focused_findings_for_non_pass_lifecycle_cells(): void
    {
        $result = $this->completeLifecycleResult();
        $scenarioId = 'workflow_retry_backoff_or_refusal';
        $result['outcome'] = 'fail';
        $result['scenario_results'][$scenarioId]['status'] = 'not_covered';
        $result['scenario_results'][$scenarioId]['lifecycle_cell_outcome'] = 'not_covered';
        $result['lifecycle_cell_outcomes'][$scenarioId]['status'] = 'not_covered';

        $evaluation = WorkflowLifecycleResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains($scenarioId, $evaluation['non_pass_scenarios']);
        $this->assertContains(
            [
                'code' => 'missing_focused_finding_for_non_pass_cell',
                'scenario_id' => $scenarioId,
                'status' => 'not_covered',
            ],
            $evaluation['gate_failures'],
        );
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function truthyLocalSourceMarkers(): iterable
    {
        yield 'boolean true' => [true];
        yield 'integer one' => [1];
        yield 'string one' => ['1'];
        yield 'string true' => ['true'];
        yield 'string yes' => ['yes'];
        yield 'string on' => ['on'];
    }

    /**
     * @return array<string, mixed>
     */
    private function completeLifecycleResult(): array
    {
        $artifactVersions = [
            'server' => '0.2.512',
            'cli' => '0.1.82',
            'workflow-php' => '2.0.0-alpha.224',
            'sdk-python' => '0.4.91',
            'waterline' => '2.0.0-alpha.111',
        ];
        $artifactSources = [
            'server' => 'docker://durableworkflow/server:0.2.512',
            'cli' => 'github-release://durable-workflow/cli/v0.1.82/install.sh',
            'workflow-php' => 'packagist://durable-workflow/workflow:2.0.0-alpha.224',
            'sdk-python' => 'pypi://durable-workflow/0.4.91',
            'waterline' => 'npm://durable-workflow-waterline/2.0.0-alpha.111',
        ];
        $sourcePolicy = [
            'published_artifacts_only' => true,
            'local_product_source_checkouts_used' => false,
            'local_product_source_checkout_used_as_pass_evidence' => false,
            'statement' => 'Workflow lifecycle conformance ran against pinned published artifacts.',
        ];

        $scenarioResults = [];
        $cellOutcomes = [];
        foreach (WorkflowLifecycleContract::manifest()['required_scenarios'] as $scenarioId) {
            $scenarioResults[$scenarioId] = [
                'scenario_id' => $scenarioId,
                'status' => 'pass',
                'lifecycle_cell_outcome' => 'pass',
                'artifact_sources' => $artifactSources,
                'local_product_source_checkouts_used' => false,
                'observed_outputs' => [
                    'status' => 'pass',
                    'workflow_id' => $scenarioId . '-workflow',
                    'run_id' => $scenarioId . '-run',
                    'public_surface' => 'api_cli_history_waterline',
                    'local_product_source_checkouts_used' => false,
                ],
            ];
            $cellOutcomes[$scenarioId] = [
                'status' => 'pass',
                'observed_at' => '2026-06-28T00:01:00Z',
                'local_product_source_checkouts_used' => false,
            ];
        }

        return [
            'schema' => WorkflowLifecycleContract::RESULT_SCHEMA,
            'version' => WorkflowLifecycleContract::RESULT_VERSION,
            'artifact_versions' => $artifactVersions,
            'published_artifact_versions' => $artifactVersions,
            'artifact_sources' => $artifactSources,
            'started_at' => '2026-06-28T00:00:00Z',
            'finished_at' => '2026-06-28T00:05:00Z',
            'generated_at' => '2026-06-28T00:05:01Z',
            'outcome' => 'pass',
            'runner_blocked' => false,
            'scenario_results' => $scenarioResults,
            'lifecycle_cell_outcomes' => $cellOutcomes,
            'findings' => [],
            'local_product_source_checkouts_used' => false,
            'source_policy' => $sourcePolicy,
        ];
    }

    /**
     * @param array<string, mixed> $evaluation
     *
     * @return list<string>
     */
    private function missingRunRecordFields(array $evaluation): array
    {
        return array_values(array_map(
            static fn (array $failure): string => (string) ($failure['field'] ?? ''),
            array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_run_record_field',
            ),
        ));
    }
}
