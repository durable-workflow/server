<?php

namespace Tests\Unit;

use App\Support\ReplayVerificationContract;
use PHPUnit\Framework\TestCase;

class ReplayVerificationContractTest extends TestCase
{
    public function test_manifest_publishes_canonical_schema_and_offline_cli_surface(): void
    {
        $manifest = ReplayVerificationContract::manifest();

        $this->assertSame('durable-workflow.v2.replay-verification.contract', $manifest['schema']);
        $this->assertSame(1, $manifest['version']);

        $this->assertSame('durable-workflow.v2.history-export', $manifest['bundle']['schema']);
        $this->assertSame(1, $manifest['bundle']['schema_version']);

        $this->assertSame('workflow:v2:replay-verify', $manifest['offline_cli']['command']);
        $this->assertSame(0, $manifest['offline_cli']['exit_codes']['ok']);
        $this->assertSame(1, $manifest['offline_cli']['exit_codes']['drifted']);
        $this->assertSame(1, $manifest['offline_cli']['exit_codes']['failed']);
    }

    public function test_manifest_publishes_integrity_and_report_schemas(): void
    {
        $manifest = ReplayVerificationContract::manifest();

        $this->assertSame('json-recursive-ksort-v1', $manifest['integrity']['canonicalization']);
        $this->assertSame('sha256', $manifest['integrity']['checksum_algorithm']);
        $this->assertContains('hmac-sha256', $manifest['integrity']['signature_algorithms']);

        $this->assertSame(
            'durable-workflow.v2.history-bundle-verification',
            $manifest['integrity_report']['schema'],
        );
        $this->assertSame(1, $manifest['integrity_report']['schema_version']);

        foreach (['ok', 'warning', 'failed'] as $status) {
            $this->assertContains($status, $manifest['integrity_report']['statuses']);
        }

        foreach ([
            'integrity.checksum_mismatch',
            'integrity.signature_mismatch',
            'history_events.sequence_not_monotonic',
            'payload_manifest.writer_schema_fingerprint_mismatch',
        ] as $rule) {
            $this->assertContains($rule, $manifest['integrity_report']['rules']);
        }
    }

    public function test_manifest_publishes_replay_diff_statuses_and_reasons(): void
    {
        $manifest = ReplayVerificationContract::manifest();

        $this->assertSame('durable-workflow.v2.replay-diff', $manifest['replay_diff']['schema']);
        $this->assertSame(1, $manifest['replay_diff']['schema_version']);

        foreach (['replayed', 'drifted', 'failed'] as $status) {
            $this->assertContains($status, $manifest['replay_diff']['statuses']);
        }

        foreach (['none', 'shape_mismatch', 'replay_error', 'bundle_invalid'] as $reason) {
            $this->assertContains($reason, $manifest['replay_diff']['reasons']);
        }

        foreach (['workflow_sequence', 'expected_shape', 'recorded_event_types'] as $field) {
            $this->assertContains($field, $manifest['replay_diff']['shape_mismatch_fields']);
        }
    }

    public function test_manifest_publishes_composite_verification_report_schema(): void
    {
        $manifest = ReplayVerificationContract::manifest();

        $this->assertSame(
            'durable-workflow.v2.replay-verification.report',
            $manifest['verification_report']['schema'],
        );
        $this->assertSame(1, $manifest['verification_report']['schema_version']);

        foreach (['verdict', 'promotion_decision', 'bundle_path', 'integrity', 'replay_diff'] as $field) {
            $this->assertContains($field, $manifest['verification_report']['fields']);
        }

        foreach (['ok', 'warning', 'drifted', 'failed'] as $verdict) {
            $this->assertContains($verdict, $manifest['verification_report']['verdicts']);
        }
    }

    public function test_verdicts_map_to_promotion_decisions(): void
    {
        $manifest = ReplayVerificationContract::manifest();

        $this->assertSame('safe_to_promote', $manifest['verdicts']['ok']['promotion_decision']);
        $this->assertSame('block_until_compatible', $manifest['verdicts']['drifted']['promotion_decision']);
        $this->assertSame('block_and_investigate', $manifest['verdicts']['failed']['promotion_decision']);
    }

    public function test_golden_history_pins_required_families_across_runtimes(): void
    {
        $manifest = ReplayVerificationContract::manifest();

        $this->assertSame('durable-workflow.golden-history.v1', $manifest['golden_history']['fixture_schema']);

        foreach ([
            'activity',
            'saga-compensation',
            'signal-update',
            'version-marker',
            'wait-condition',
        ] as $family) {
            $this->assertContains($family, $manifest['golden_history']['required_families']);
        }

        $this->assertContains('workflow-php', $manifest['golden_history']['official_runtimes']);
        $this->assertContains('sdk-python', $manifest['golden_history']['official_runtimes']);
    }
}
