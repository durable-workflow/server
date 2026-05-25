<?php

namespace Tests\Unit;

use App\Support\SkewRefusalMatrixContract;
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
        $this->assertTrue($gate['outside_window_pairs_must_loud_refuse']);
        $this->assertTrue($gate['silent_success_is_blocking']);
        $this->assertTrue($gate['silent_failure_is_blocking']);
        $this->assertTrue($gate['corrupt_is_blocking']);
    }

    public function test_skewed_operations_require_wire_evidence(): void
    {
        $manifest = SkewRefusalMatrixContract::manifest();

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
                'classification',
            ] as $field) {
                $this->assertContains($field, $manifest['operation_groups'][$group]['evidence']);
            }
        }

        $this->assertContains(
            'screenshot_or_dom_snapshot',
            $manifest['operation_groups']['waterline_render']['evidence'],
        );
    }
}
