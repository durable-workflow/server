<?php

namespace Tests\Unit;

use App\Support\ReplayPromotionGate;
use PHPUnit\Framework\TestCase;

class ReplayPromotionGateTest extends TestCase
{
    public function test_evaluate_maps_verdicts_to_canonical_promotion_decisions(): void
    {
        $cases = [
            'ok' => [ReplayPromotionGate::SAFE_TO_PROMOTE, ReplayPromotionGate::STATUS_PASS],
            'warning' => [ReplayPromotionGate::REVIEW_BEFORE_PROMOTE, ReplayPromotionGate::STATUS_REVIEW],
            'drifted' => [ReplayPromotionGate::BLOCK_UNTIL_COMPATIBLE, ReplayPromotionGate::STATUS_BLOCK],
            'failed' => [ReplayPromotionGate::BLOCK_AND_INVESTIGATE, ReplayPromotionGate::STATUS_BLOCK],
        ];

        foreach ($cases as $verdict => [$expectedDecision, $expectedStatus]) {
            $result = ReplayPromotionGate::evaluate(['verdict' => $verdict]);

            $this->assertSame($verdict, $result['verdict']);
            $this->assertSame($expectedDecision, $result['promotion_decision']);
            $this->assertSame($expectedStatus, $result['gate_status']);
            $this->assertNotEmpty($result['reason']);
        }
    }

    public function test_evaluate_treats_missing_or_unknown_verdict_as_failed(): void
    {
        $result = ReplayPromotionGate::evaluate([]);

        $this->assertSame('failed', $result['verdict']);
        $this->assertSame(ReplayPromotionGate::BLOCK_AND_INVESTIGATE, $result['promotion_decision']);
        $this->assertSame(ReplayPromotionGate::STATUS_BLOCK, $result['gate_status']);

        $bogus = ReplayPromotionGate::evaluate(['verdict' => 'something-new']);
        $this->assertSame(ReplayPromotionGate::BLOCK_AND_INVESTIGATE, $bogus['promotion_decision']);
        $this->assertSame('unknown_verdict', $bogus['reason']);
    }

    public function test_aggregate_picks_strictest_verdict_across_reports(): void
    {
        $aggregate = ReplayPromotionGate::aggregate([
            ['verdict' => 'ok'],
            ['verdict' => 'warning'],
            ['verdict' => 'drifted'],
            ['verdict' => 'ok'],
        ]);

        $this->assertSame('drifted', $aggregate['verdict']);
        $this->assertSame(ReplayPromotionGate::BLOCK_UNTIL_COMPATIBLE, $aggregate['promotion_decision']);
        $this->assertSame(ReplayPromotionGate::STATUS_BLOCK, $aggregate['gate_status']);
        $this->assertSame(4, $aggregate['evaluated']);
    }

    public function test_aggregate_promotes_when_all_clean(): void
    {
        $aggregate = ReplayPromotionGate::aggregate([
            ['verdict' => 'ok'],
            ['verdict' => 'ok'],
        ]);

        $this->assertSame('ok', $aggregate['verdict']);
        $this->assertSame(ReplayPromotionGate::SAFE_TO_PROMOTE, $aggregate['promotion_decision']);
        $this->assertSame(ReplayPromotionGate::STATUS_PASS, $aggregate['gate_status']);
    }

    public function test_aggregate_of_empty_list_blocks_promotion(): void
    {
        $aggregate = ReplayPromotionGate::aggregate([]);

        $this->assertSame('failed', $aggregate['verdict']);
        $this->assertSame(ReplayPromotionGate::BLOCK_AND_INVESTIGATE, $aggregate['promotion_decision']);
        $this->assertSame(ReplayPromotionGate::STATUS_BLOCK, $aggregate['gate_status']);
        $this->assertSame('no_reports', $aggregate['reason']);
        $this->assertSame(0, $aggregate['evaluated']);
    }

    public function test_aggregate_treats_unknown_verdicts_as_failed(): void
    {
        $aggregate = ReplayPromotionGate::aggregate([
            ['verdict' => 'ok'],
            ['verdict' => 'totally-bogus'],
        ]);

        $this->assertSame('failed', $aggregate['verdict']);
        $this->assertSame(ReplayPromotionGate::BLOCK_AND_INVESTIGATE, $aggregate['promotion_decision']);
    }

    public function test_evaluate_carries_report_schema_through(): void
    {
        $result = ReplayPromotionGate::evaluate([
            'schema' => 'durable-workflow.v2.replay-verification.report',
            'schema_version' => 1,
            'verdict' => 'ok',
            'evidence' => [
                'integrity_checked' => true,
                'replay_checked' => true,
                'replay_skipped' => false,
            ],
        ]);

        $this->assertSame('durable-workflow.v2.replay-verification.report', $result['report_schema']);
        $this->assertSame(1, $result['report_schema_version']);
        $this->assertTrue($result['evidence_complete']);
        $this->assertSame([], $result['evidence_issues']);
    }

    public function test_known_verify_report_without_evidence_blocks_clean_verdict(): void
    {
        $result = ReplayPromotionGate::evaluate([
            'schema' => 'durable-workflow.v2.replay-verification.report',
            'schema_version' => 1,
            'verdict' => 'ok',
        ]);

        $this->assertSame('ok', $result['verdict']);
        $this->assertSame(ReplayPromotionGate::BLOCK_AND_INVESTIGATE, $result['promotion_decision']);
        $this->assertSame(ReplayPromotionGate::STATUS_BLOCK, $result['gate_status']);
        $this->assertFalse($result['evidence_complete']);
        $this->assertSame(['evidence_missing'], $result['evidence_issues']);
    }

    public function test_known_verify_report_with_skipped_replay_requires_review(): void
    {
        $result = ReplayPromotionGate::evaluate([
            'schema' => 'durable-workflow.v2.replay-verification.report',
            'schema_version' => 1,
            'verdict' => 'ok',
            'evidence' => [
                'integrity_checked' => true,
                'replay_checked' => false,
                'replay_skipped' => true,
            ],
        ]);

        $this->assertSame(ReplayPromotionGate::REVIEW_BEFORE_PROMOTE, $result['promotion_decision']);
        $this->assertSame(ReplayPromotionGate::STATUS_REVIEW, $result['gate_status']);
        $this->assertSame(['replay_skipped'], $result['evidence_issues']);
    }

    public function test_aggregate_accounts_for_evidence_policy(): void
    {
        $aggregate = ReplayPromotionGate::aggregate([
            [
                'schema' => 'durable-workflow.v2.replay-verification.report',
                'schema_version' => 1,
                'verdict' => 'ok',
                'evidence' => [
                    'integrity_checked' => true,
                    'replay_checked' => true,
                    'replay_skipped' => false,
                ],
            ],
            [
                'schema' => 'durable-workflow.v2.replay-verification.report',
                'schema_version' => 1,
                'verdict' => 'ok',
            ],
        ]);

        $this->assertSame(ReplayPromotionGate::BLOCK_AND_INVESTIGATE, $aggregate['promotion_decision']);
        $this->assertSame(ReplayPromotionGate::STATUS_BLOCK, $aggregate['gate_status']);
        $this->assertFalse($aggregate['evidence_complete']);
        $this->assertSame(['evidence_missing'], $aggregate['evidence_issues']);
    }

    public function test_known_simulation_report_with_complete_evidence_can_pass(): void
    {
        $result = ReplayPromotionGate::evaluate([
            'schema' => 'durable-workflow.v2.replay-simulation.report',
            'schema_version' => 1,
            'verdict' => 'ok',
            'evidence' => [
                'bundle_count' => 2,
                'missing_bundle_count' => 0,
                'integrity_checked_count' => 2,
                'replay_checked_count' => 2,
                'replay_skipped' => false,
            ],
        ]);

        $this->assertSame(ReplayPromotionGate::SAFE_TO_PROMOTE, $result['promotion_decision']);
        $this->assertSame(ReplayPromotionGate::STATUS_PASS, $result['gate_status']);
        $this->assertTrue($result['evidence_complete']);
    }

    public function test_clean_simulation_report_with_skipped_replay_requires_review(): void
    {
        $result = ReplayPromotionGate::evaluate([
            'schema' => 'durable-workflow.v2.replay-simulation.report',
            'schema_version' => 1,
            'verdict' => 'ok',
            'evidence' => [
                'bundle_count' => 2,
                'missing_bundle_count' => 0,
                'integrity_checked_count' => 2,
                'replay_checked_count' => 0,
                'replay_skipped' => true,
            ],
        ]);

        $this->assertSame(ReplayPromotionGate::REVIEW_BEFORE_PROMOTE, $result['promotion_decision']);
        $this->assertSame(ReplayPromotionGate::STATUS_REVIEW, $result['gate_status']);
        $this->assertSame(['replay_skipped'], $result['evidence_issues']);
    }

    public function test_clean_simulation_report_without_replay_count_blocks(): void
    {
        $result = ReplayPromotionGate::evaluate([
            'schema' => 'durable-workflow.v2.replay-simulation.report',
            'schema_version' => 1,
            'verdict' => 'ok',
            'evidence' => [
                'bundle_count' => 2,
                'missing_bundle_count' => 0,
                'integrity_checked_count' => 2,
                'replay_checked_count' => 1,
                'replay_skipped' => false,
            ],
        ]);

        $this->assertSame(ReplayPromotionGate::BLOCK_AND_INVESTIGATE, $result['promotion_decision']);
        $this->assertSame(ReplayPromotionGate::STATUS_BLOCK, $result['gate_status']);
        $this->assertSame(['replay_diff_missing'], $result['evidence_issues']);
    }
}
