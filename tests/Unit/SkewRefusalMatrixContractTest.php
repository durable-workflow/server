<?php

namespace Tests\Unit;

use App\Support\SkewRefusalMatrixContract;
use App\Support\SkewRefusalMatrixResultGate;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;

final class SkewRefusalMatrixContractTest extends TestCase
{
    public function test_manifest_advertises_identity_and_artifact_policy(): void
    {
        $manifest = SkewRefusalMatrixContract::manifest();

        $this->assertSame('durable-workflow.v2.skew-refusal-matrix.contract', $manifest['schema']);
        $this->assertSame(1, $manifest['version']);
        $this->assertSame('durable-workflow.v2.skew-refusal-matrix.result', $manifest['result_schema']);
        $this->assertSame(1, $manifest['result_version']);
        $this->assertSame(
            PlatformConformanceSuite::SCHEMA,
            $manifest['platform_conformance_suite_authority'],
        );
        $this->assertSame(
            PlatformConformanceSuite::SCHEMA,
            $manifest['scenario_manifest']['suite_schema'],
        );
        $this->assertSame(
            PlatformConformanceSuite::VERSION,
            $manifest['scenario_manifest']['suite_version'],
        );
        $this->assertSame(
            'static/platform-conformance/skew-refusal-matrix-scenarios.json',
            $manifest['scenario_manifest']['source_path'],
        );
        $this->assertSame(
            [
                'published_artifact_install_only',
                'cli_version_pair_matrix',
                'sdk_python_version_pair_matrix',
                'workflow_worker_version_pair_matrix',
                'waterline_version_pair_matrix',
                'future_version_boundary_matrix',
                'request_response_capture_for_skewed_operations',
                'focused_finding_routing',
            ],
            $manifest['required_scenarios'],
        );

        foreach (['server', 'cli', 'sdk-python', 'workflow', 'waterline'] as $artifact) {
            $this->assertContains($artifact, $manifest['artifact_policy']['required_artifacts']);
            $this->assertArrayHasKey($artifact, $manifest['artifact_policy']['install_channels']);
        }

        foreach ([
            'artifact_versions',
            'runner_blocked',
            'surface_results',
            'pairing_results',
            'operation_evidence',
            'finding_links',
        ] as $field) {
            $this->assertContains($field, $manifest['artifact_policy']['required_run_record_fields']);
        }
    }

    public function test_scenario_manifest_source_path_is_published_and_matches_contract(): void
    {
        $manifest = SkewRefusalMatrixContract::manifest();
        $scenarioManifestPath = dirname(__DIR__, 2) . '/' . $manifest['scenario_manifest']['source_path'];

        $this->assertFileExists(
            $scenarioManifestPath,
            'cluster info must not advertise a skew-refusal scenario manifest source path that is missing from the release tree',
        );

        $scenarioManifest = json_decode(
            (string) file_get_contents($scenarioManifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame($manifest['scenario_manifest']['schema'], $scenarioManifest['schema']);
        $this->assertSame($manifest['scenario_manifest']['version'], $scenarioManifest['version']);
        $this->assertSame($manifest['scenario_manifest']['category'], $scenarioManifest['category']);
        $this->assertSame($manifest['scenario_manifest']['suite_schema'], $scenarioManifest['suite_schema']);
        $this->assertSame($manifest['scenario_manifest']['suite_version'], $scenarioManifest['suite_version']);
        $this->assertSame(PlatformConformanceSuite::VERSION, $scenarioManifest['suite_version']);
        $this->assertSame($manifest['result_schema'], $scenarioManifest['result_schema']);
        $this->assertSame($manifest['scenario_statuses'], $scenarioManifest['result_statuses']);
        $this->assertSame(
            $manifest['required_scenarios'],
            array_column($scenarioManifest['scenarios'], 'id'),
        );
        $this->assertSame(
            $manifest['artifact_policy']['required_artifacts'],
            $scenarioManifest['artifact_policy']['required_artifacts'],
        );
        $this->assertSame(
            $manifest['artifact_policy']['required_run_record_fields'],
            $scenarioManifest['artifact_policy']['required_run_record_fields'],
        );

        foreach ($manifest['artifact_policy']['required_run_record_fields'] as $field) {
            $this->assertContains(
                $field,
                $scenarioManifest['common_result_evidence'],
                sprintf('public skew scenario manifest must advertise run record field %s', $field),
            );
        }

        $this->assertNotContains('linked_findings', $scenarioManifest['common_result_evidence']);
        $this->assertContains(
            'finding_links',
            $scenarioManifest['scenario_requirements']['focused_finding_routing']['required_fields'],
        );
        $this->assertNotContains(
            'linked_findings',
            $scenarioManifest['scenario_requirements']['focused_finding_routing']['required_fields'],
        );
        $this->assertSame(array_keys($manifest['required_surfaces']), $scenarioManifest['required_matrix']['surfaces']);
        $this->assertSame(
            $manifest['required_surfaces']['cli']['required_pairing_classes'],
            $scenarioManifest['required_matrix']['pairing_classes'],
        );

        foreach ($manifest['required_surfaces'] as $surface => $surfaceContract) {
            $this->assertSame(
                $surfaceContract['operation_groups'],
                $scenarioManifest['required_matrix']['operation_groups'][$surface],
                sprintf('public skew scenario manifest operation groups drifted for %s', $surface),
            );
        }

        $this->assertSame(
            $manifest['worker_skew_classification']['allowed'],
            $scenarioManifest['required_matrix']['worker_skew_classifications'],
        );
        $this->assertSame(
            $manifest['waterline_skew_classification']['allowed'],
            $scenarioManifest['required_matrix']['waterline_skew_classifications'],
        );
        $this->assertSame(
            [
                ...$manifest['worker_skew_classification']['blocking'],
                ...$manifest['waterline_skew_classification']['blocking'],
            ],
            $scenarioManifest['required_matrix']['blocking_classifications'],
        );
        $this->assertSame(
            $manifest['host_runner_contract'],
            $scenarioManifest['host_runner_contract'],
        );
    }

    public function test_required_surfaces_cover_full_skew_matrix(): void
    {
        $manifest = SkewRefusalMatrixContract::manifest();
        $requiredClasses = ['compatible', 'backward_skew', 'forward_skew', 'outside_window'];

        foreach (['cli', 'sdk-python', 'workflow-worker', 'waterline'] as $surface) {
            $this->assertArrayHasKey($surface, $manifest['required_surfaces']);
            $this->assertSame(
                $requiredClasses,
                $manifest['required_surfaces'][$surface]['required_pairing_classes'],
                "$surface must cover compatible, backward, forward, and outside-window pairings",
            );
            $this->assertContains(
                'cluster_info_probe',
                $manifest['required_surfaces'][$surface]['operation_groups'],
                "$surface must prove cluster-info compatibility discovery",
            );
            $this->assertContains(
                'suggests_upgrade_or_pin_next_step',
                $manifest['required_surfaces'][$surface]['refusal_requirements'],
                "$surface refusals must tell users the next step",
            );
        }

        $this->assertContains(
            'worker_lifecycle',
            $manifest['required_surfaces']['workflow-worker']['operation_groups'],
        );
        $this->assertContains(
            'waterline_render',
            $manifest['required_surfaces']['waterline']['operation_groups'],
        );
    }

    public function test_blocking_classifications_and_smoke_gate_are_explicit(): void
    {
        $manifest = SkewRefusalMatrixContract::manifest();

        $this->assertSame(
            ['register_and_drop'],
            $manifest['worker_skew_classification']['blocking'],
            'a worker that registers and drops work must block the release',
        );
        $this->assertSame(
            ['stale_render'],
            $manifest['waterline_skew_classification']['blocking'],
            'Waterline stale render must route as a blocking product finding',
        );

        $gate = $manifest['coverage_gate'];
        $this->assertTrue($gate['full_matrix_required']);
        $this->assertSame('non_passing_smoke_only', $gate['smoke_only_outcome']);
        $this->assertTrue($gate['all_required_surfaces_required']);
        $this->assertTrue($gate['all_pairing_classes_required_per_surface']);
        $this->assertTrue($gate['all_advertised_requests_required_per_operation_group']);
        $this->assertTrue($gate['outside_window_pairs_must_loud_refuse']);
        $this->assertTrue($gate['silent_success_is_blocking']);
        $this->assertTrue($gate['silent_failure_is_blocking']);
        $this->assertTrue($gate['corrupt_is_blocking']);

        $this->assertSame(SkewRefusalMatrixResultGate::SCHEMA, $manifest['result_gate']['schema']);
        $this->assertContains(
            'every_required_operation_group_has_evidence_for_every_pairing_class',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'every_advertised_operation_request_has_matching_evidence',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'smoke_only_results_remain_non_passing',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'each_non_pass_cell_has_a_focused_finding_link',
            $manifest['result_gate']['pass_requires'],
        );
    }

    public function test_manifest_publishes_host_runner_contract_for_full_skew_matrix(): void
    {
        $manifest = SkewRefusalMatrixContract::manifest();
        $hostRunner = $manifest['host_runner_contract'];

        $this->assertSame('required_for_passing_skew_refusal_matrix_conformance', $hostRunner['status']);
        $this->assertSame(SkewRefusalMatrixContract::RESULT_SCHEMA, $hostRunner['result_schema']);
        $this->assertTrue($hostRunner['must_execute_against_published_artifacts']);
        $this->assertSame($manifest['required_scenarios'], $hostRunner['required_scenarios']);
        $this->assertTrue($hostRunner['must_record_runner_blocked_false_for_product_evidence']);
        $this->assertTrue($hostRunner['must_emit_result_for_every_required_surface_pairing_operation_group']);
        $this->assertTrue($hostRunner['must_capture_request_response_for_every_skewed_operation']);
        $this->assertSame('non_passing_smoke_only', $hostRunner['smoke_summary_only_outcome']);
        $this->assertSame('not_covered', $hostRunner['unexecuted_required_cell_status']);
        $this->assertSame('conformance_runner_coverage_gap', $hostRunner['coverage_gap_finding_type']);
        $this->assertSame('conformance_harness', $hostRunner['coverage_gap_owner']);

        foreach ([
            'published-artifact-install',
            'cli-skew-surface-shard',
            'sdk-python-skew-surface-shard',
            'workflow-worker-skew-surface-shard',
            'waterline-skew-surface-shard',
            'future-version-boundary-shard',
            'request-response-evidence-shard',
        ] as $scope) {
            $this->assertContains($scope, $hostRunner['required_execution_scopes']);
        }

        foreach (['cli', 'sdk-python', 'workflow-worker', 'waterline'] as $surface) {
            $this->assertArrayHasKey($surface, $hostRunner['runtime_shards']);
            $this->assertSame(
                ['compatible', 'backward_skew', 'forward_skew', 'outside_window'],
                $hostRunner['runtime_shards'][$surface]['must_cover_pairing_classes'],
            );
            $this->assertSame('not_covered', $hostRunner['runtime_shards'][$surface]['fallback_status_when_surface_missing']);
            $this->assertSame(
                'conformance_runner_coverage_gap',
                $hostRunner['runtime_shards'][$surface]['fallback_finding_type'],
            );
        }

        $this->assertContains(
            'workflow_control_plane',
            $hostRunner['runtime_shards']['cli']['must_cover_operation_groups'],
        );
        $this->assertContains(
            'worker_lifecycle',
            $hostRunner['runtime_shards']['workflow-worker']['must_cover_operation_groups'],
        );
        $this->assertSame(
            'register_and_drop',
            $hostRunner['runtime_shards']['workflow-worker']['blocking_classification'],
        );
        $this->assertContains(
            'waterline_render',
            $hostRunner['runtime_shards']['waterline']['must_cover_operation_groups'],
        );
        $this->assertSame(
            'stale_render',
            $hostRunner['runtime_shards']['waterline']['blocking_classification'],
        );
        $this->assertSame(
            'conformance_runner_coverage_gap',
            $hostRunner['routing_policy']['missing_required_cell']['finding_type'],
        );
        $this->assertSame(
            'durable-workflow/waterline',
            $hostRunner['routing_policy']['waterline_stale_render']['owner'],
        );
    }

    public function test_skewed_operations_require_wire_evidence(): void
    {
        $manifest = SkewRefusalMatrixContract::manifest();

        $workflowRequests = $manifest['operation_groups']['workflow_control_plane']['requests'];
        $this->assertContains('GET /api/workflows/{workflowId}/runs/{runId}/history', $workflowRequests);
        $this->assertContains('POST /api/workflows/{workflowId}/signal/{signalName}', $workflowRequests);
        $this->assertContains('POST /api/workflows/{workflowId}/query/{queryName}', $workflowRequests);
        $this->assertContains('POST /api/workflows/{workflowId}/update/{updateName}', $workflowRequests);
        $this->assertNotContains('GET /api/workflows/{id}/history', $workflowRequests);
        $this->assertNotContains('POST /api/workflows/{id}/signals', $workflowRequests);
        $this->assertNotContains('POST /api/workflows/{id}/queries', $workflowRequests);
        $this->assertNotContains('POST /api/workflows/{id}/updates', $workflowRequests);
        $this->assertContains('request', $manifest['operation_groups']['cluster_info_probe']['evidence']);
        $this->assertContains('status', $manifest['operation_groups']['cluster_info_probe']['evidence']);

        foreach ([
            'workflow_control_plane',
            'worker_lifecycle',
            'schedule_control_plane',
        ] as $group) {
            foreach ([
                'request_method',
                'request_path',
                'request_headers',
                'request_body',
                'response_status',
                'response_headers',
                'response_body',
                'client_or_worker_version',
                'server_version',
                'compatibility_window',
                'status',
            ] as $field) {
                $this->assertContains($field, $manifest['operation_groups'][$group]['evidence']);
            }
        }

        $this->assertContains(
            'status',
            $manifest['operation_groups']['waterline_render']['evidence'],
        );
        $this->assertContains(
            'waterline_skew_classification',
            $manifest['operation_groups']['waterline_render']['evidence'],
        );
        $this->assertContains(
            'screenshot_or_dom_snapshot',
            $manifest['operation_groups']['waterline_render']['evidence'],
        );
        $this->assertNotContains(
            'classification',
            $manifest['operation_groups']['waterline_render']['evidence'],
        );
    }

    public function test_result_gate_rejects_cluster_info_smoke_as_passing_evidence(): void
    {
        $result = $this->completeSkewResult();
        $result['operation_evidence'] = [];

        foreach (SkewRefusalMatrixContract::manifest()['required_surfaces'] as $surface => $surfaceContract) {
            foreach ($surfaceContract['required_pairing_classes'] as $pairingClass) {
                $result['operation_evidence'][$surface][$pairingClass]['cluster_info_probe'][] = $this->operationEvidence(
                    $surface,
                    $pairingClass,
                    'cluster_info_probe',
                    $pairingClass === 'compatible' ? 'pass' : 'loud_refuse',
                );
            }
        }

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertTrue($evaluation['smoke_subset_detected']);
        $this->assertContains('smoke_only', $evaluation['non_pass_cells']);
        $this->assertContains(
            'declared_outcome_status_mismatch',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_conflicting_outcome_status_and_verdict_aliases(): void
    {
        $result = $this->completeSkewResult();
        $result['outcome'] = 'pass';
        $result['status'] = 'non_passing';
        $result['verdict'] = 'non_passing';

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');
        $conflictFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'conflicting_outcome_tokens',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('declared_outcome_status_mismatch', $failureCodes);
        $this->assertContains('conflicting_outcome_tokens', $failureCodes);
        $this->assertCount(1, $conflictFailures);
        $this->assertSame([
            'outcome' => 'pass',
            'status' => 'non_passing',
            'verdict' => 'non_passing',
        ], $conflictFailures[0]['declared_outcomes']);
        $this->assertSame([
            'outcome' => 'pass',
            'status' => 'non_passing',
            'verdict' => 'non_passing',
        ], $conflictFailures[0]['declared_statuses']);
    }

    public function test_result_gate_rejects_forbidden_artifact_sources_and_source_paths(): void
    {
        $result = $this->completeSkewResult();
        $result['artifact_sources'] = [
            'server' => 'workspace_repo_as_artifact_under_test',
        ];
        $result['operation_evidence']['cli']['compatible']['cluster_info_probe'][0]['source_paths'] = [
            'cli' => 'local_product_source_checkout',
        ];

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);
        $sourceFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'forbidden_artifact_source',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertCount(2, $sourceFailures);
        $this->assertSame(['artifact_sources', 'source_paths'], array_column($sourceFailures, 'field'));
    }

    public function test_result_gate_requires_linked_findings_for_uncovered_matrix_cells(): void
    {
        $result = $this->completeSkewResult();
        unset($result['operation_evidence']['sdk-python']['outside_window']['worker_lifecycle']);
        $result['outcome'] = 'fail';
        $result['finding_links'] = [];

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'missing_operation_evidence',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertContains(
            'missing_linked_findings_for_non_pass_cells',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_focused_findings_for_each_uncovered_matrix_cell(): void
    {
        $result = $this->completeSkewResult();
        unset($result['operation_evidence']['sdk-python']['outside_window']['worker_lifecycle']);
        $result['outcome'] = 'fail';
        $result['finding_links'] = [
            'cli.compatible.cluster_info_probe' => 'https://durable-workflow.github.io/conformance/findings/cli-cluster-info-skew',
        ];

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);
        $focusedFindingFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_focused_findings_for_non_pass_cells',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertCount(1, $focusedFindingFailures);
        $this->assertContains(
            'sdk-python.outside_window.worker_lifecycle',
            $focusedFindingFailures[0]['non_pass_cells'],
        );
    }

    public function test_result_gate_accepts_surface_scoped_findings_for_uncovered_matrix_cells(): void
    {
        $result = $this->completeSkewResult();
        unset($result['operation_evidence']['sdk-python']['outside_window']['worker_lifecycle']);
        $result['outcome'] = 'fail';
        $result['finding_links'] = [
            'sdk-python.outside_window' => 'https://durable-workflow.github.io/conformance/findings/sdk-python-worker-skew',
        ];

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotContains(
            'missing_focused_findings_for_non_pass_cells',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_each_advertised_request_in_an_operation_group(): void
    {
        $result = $this->completeSkewResult();
        array_pop($result['operation_evidence']['cli']['compatible']['schedule_control_plane']);
        $result['outcome'] = 'fail';
        $result['finding_links'] = [
            'cli.compatible.schedule_control_plane' => 'https://durable-workflow.github.io/conformance/findings/cli-schedule-skew',
        ];

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);
        $missingRequestFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_operation_request_evidence',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($missingRequestFailures);
        $this->assertContains(
            'POST /api/schedules/{id}/trigger',
            array_column($missingRequestFailures, 'advertised_request'),
        );
    }

    public function test_result_gate_rejects_operation_evidence_for_the_wrong_advertised_request_group(): void
    {
        $result = $this->completeSkewResult();
        $result['operation_evidence']['workflow-worker']['outside_window']['worker_lifecycle'] = [
            $this->operationEvidence(
                'workflow-worker',
                'outside_window',
                'worker_lifecycle',
                'loud_refuse',
                'POST /api/workflows',
            ),
        ];
        $result['outcome'] = 'fail';
        $result['finding_links'] = [
            'workflow-worker.outside_window.worker_lifecycle' => 'https://durable-workflow.github.io/conformance/findings/workflow-worker-skew',
        ];

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);
        $codes = array_column($evaluation['gate_failures'], 'code');
        $unexpectedRequestFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'unexpected_operation_request',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('unexpected_operation_request', $codes);
        $this->assertContains('missing_operation_request_evidence', $codes);
        $this->assertSame('POST /api/workflows', $unexpectedRequestFailures[0]['request']);
        $this->assertContains(
            'POST /api/worker/register',
            $unexpectedRequestFailures[0]['advertised_requests'],
        );
    }

    public function test_result_gate_requires_status_for_cluster_info_operation_evidence(): void
    {
        $result = $this->completeSkewResult();
        unset($result['operation_evidence']['cli']['compatible']['cluster_info_probe'][0]['status']);
        $result['outcome'] = 'fail';
        $result['finding_links'] = [
            'cli.compatible.cluster_info_probe' => 'https://durable-workflow.github.io/conformance/findings/cli-cluster-info-skew',
        ];

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);
        $codes = array_column($evaluation['gate_failures'], 'code');
        $missingFields = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_operation_evidence_field',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('missing_operation_evidence_status', $codes);
        $this->assertContains('status', array_column($missingFields, 'field'));
    }

    public function test_result_gate_rejects_ambiguous_waterline_classification_evidence(): void
    {
        $result = $this->completeSkewResult();
        $row = &$result['operation_evidence']['waterline']['outside_window']['waterline_render'][0];
        unset($row['status'], $row['waterline_skew_classification']);
        $row['classification'] = 'render_refused';
        unset($row);
        $result['outcome'] = 'fail';
        $result['finding_links'] = [
            'waterline.outside_window.waterline_render' => 'https://durable-workflow.github.io/conformance/findings/waterline-skew',
        ];

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);
        $codes = array_column($evaluation['gate_failures'], 'code');
        $missingFields = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_operation_evidence_field',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('missing_operation_evidence_status', $codes);
        $this->assertContains('missing_waterline_skew_classification', $codes);
        $this->assertContains('status', array_column($missingFields, 'field'));
        $this->assertContains('waterline_skew_classification', array_column($missingFields, 'field'));
    }

    public function test_result_gate_blocks_register_and_drop_and_stale_render(): void
    {
        $result = $this->completeSkewResult();
        $result['outcome'] = 'fail';
        $result['finding_links'] = [
            'workflow-worker.outside_window' => 'https://durable-workflow.github.io/conformance/findings/workflow-worker-skew',
            'waterline.outside_window' => 'https://durable-workflow.github.io/conformance/findings/waterline-skew',
        ];
        $result['pairing_results']['workflow-worker']['outside_window']['worker_skew_classification'] = 'register_and_drop';
        $result['pairing_results']['waterline']['outside_window']['waterline_skew_classification'] = 'stale_render';

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $codes = array_column($evaluation['gate_failures'], 'code');
        $this->assertContains('blocking_worker_skew_classification', $codes);
        $this->assertContains('blocking_waterline_skew_classification', $codes);
    }

    public function test_result_gate_accepts_complete_passing_matrix(): void
    {
        $evaluation = SkewRefusalMatrixResultGate::evaluate($this->completeSkewResult());

        $this->assertSame('pass', $evaluation['status']);
        $this->assertFalse($evaluation['smoke_subset_detected']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_matches_concrete_paths_to_advertised_request_templates(): void
    {
        $result = $this->completeSkewResult();
        $result['operation_evidence']['cli']['compatible']['schedule_control_plane'][1]['request_path'] = '/api/schedules/nightly-cutover';

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_parses_nested_surface_pairings_without_operation_evidence_leakage(): void
    {
        $result = $this->completeSkewResult();
        foreach ($result['surface_results'] as $surface => $surfaceResult) {
            $result['surface_results'][$surface] = [
                ...$surfaceResult,
                'pairings' => $result['pairing_results'][$surface],
            ];
        }
        $result['pairing_results'] = [
            'format' => ['source' => 'surface_scoped_pairings'],
        ];

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    /**
     * @return array<string, mixed>
     */
    private function completeSkewResult(): array
    {
        $contract = SkewRefusalMatrixContract::manifest();
        $result = [
            'artifact_versions' => [
                'server' => '0.2.191',
                'cli' => '0.1.67',
                'sdk-python' => '0.4.78',
                'workflow' => '2.0.0-alpha.177',
                'waterline' => '2.0.0-alpha.64',
            ],
            'started_at' => '2026-05-25T05:00:00Z',
            'finished_at' => '2026-05-25T05:10:00Z',
            'outcome' => 'pass',
            'runner_blocked' => false,
            'surface_results' => [],
            'pairing_results' => [],
            'operation_evidence' => [],
            'findings' => [],
            'finding_links' => [],
        ];

        foreach ($contract['required_surfaces'] as $surface => $surfaceContract) {
            $result['surface_results'][$surface] = ['status' => 'pass'];

            foreach ($surfaceContract['required_pairing_classes'] as $pairingClass) {
                $status = $pairingClass === 'compatible' ? 'pass' : 'loud_refuse';
                $result['pairing_results'][$surface][$pairingClass] = $this->pairingResult(
                    $surface,
                    $pairingClass,
                    $status,
                );

                foreach ($surfaceContract['operation_groups'] as $operationGroup) {
                    foreach ($contract['operation_groups'][$operationGroup]['requests'] as $request) {
                        $result['operation_evidence'][$surface][$pairingClass][$operationGroup][] = $this->operationEvidence(
                            $surface,
                            $pairingClass,
                            $operationGroup,
                            $status,
                            $request,
                        );
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function pairingResult(string $surface, string $pairingClass, string $status): array
    {
        $result = [
            'status' => $status,
        ];

        if ($status === 'loud_refuse') {
            $manifest = SkewRefusalMatrixContract::manifest();
            $result['refusal_requirements_met'] = $manifest['required_surfaces'][$surface]['refusal_requirements'];
        }

        if ($surface === 'workflow-worker') {
            $result['worker_skew_classification'] = $pairingClass === 'compatible'
                ? 'register_and_serve'
                : 'register_refused';
        }

        if ($surface === 'waterline') {
            $result['waterline_skew_classification'] = $pairingClass === 'compatible'
                ? 'banner'
                : 'render_refused';
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function operationEvidence(
        string $surface,
        string $pairingClass,
        string $operationGroup,
        string $status,
        ?string $request = null,
    ): array {
        $request ??= SkewRefusalMatrixContract::manifest()['operation_groups'][$operationGroup]['requests'][0];
        [$method, $path] = explode(' ', $request, 2);

        $row = match ($operationGroup) {
            'cluster_info_probe' => [
                'request' => $request,
                'status_code' => 200,
                'response_body' => ['server_version' => '0.2.191'],
                'client_or_observer_version' => '0.1.67',
                'server_version' => '0.2.191',
                'protocol_manifest_versions' => ['control_plane' => '2'],
            ],
            'waterline_render' => [
                'request' => $request,
                'response_status' => 200,
                'response_body' => ['ok' => true],
                'screenshot_or_dom_snapshot' => '<main data-compatibility-banner="visible"></main>',
                'server_version' => '0.2.191',
                'waterline_version' => '2.0.0-alpha.64',
            ],
            default => [
                'request_method' => $method,
                'request_path' => $path,
                'request_headers' => ['X-Durable-Workflow-Control-Plane-Version' => '2'],
                'request_body' => ['workflow_type' => 'Conformance'],
                'response_status' => 200,
                'response_headers' => ['X-Durable-Workflow-Control-Plane-Version' => '2'],
                'response_body' => ['outcome' => 'accepted'],
                'client_or_worker_version' => $surface === 'sdk-python' ? '0.4.78' : '0.1.67',
                'server_version' => '0.2.191',
                'compatibility_window' => '>=0.2,<1.0',
            ],
        };

        $row['status'] = $status;

        if ($status === 'loud_refuse') {
            $row['refusal_requirements_met'] = SkewRefusalMatrixContract::manifest()['required_surfaces'][$surface]['refusal_requirements'];
        }

        if ($surface === 'workflow-worker') {
            $row['worker_skew_classification'] = $pairingClass === 'compatible'
                ? 'register_and_serve'
                : 'register_refused';
        }

        if ($surface === 'waterline') {
            $row['waterline_skew_classification'] = $pairingClass === 'compatible'
                ? 'banner'
                : 'render_refused';
        }

        return $row;
    }
}
