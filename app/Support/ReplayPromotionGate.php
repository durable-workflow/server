<?php

namespace App\Support;

/**
 * Apply the platform-level replay-verification promotion gate.
 *
 * The gate consumes a `durable-workflow.v2.replay-verification.report`
 * (single bundle) or a `durable-workflow.v2.replay-simulation.report` (batch)
 * and returns one of the canonical promotion decisions published by
 * {@see ReplayVerificationContract}: `safe_to_promote`,
 * `review_before_promote`, `block_until_compatible`, or
 * `block_and_investigate`.
 *
 * Both report shapes already carry a `verdict` field; the gate simply
 * resolves it through the canonical mapping. Centralizing the mapping
 * here lets server-side rollout/promotion controllers and CI gates
 * call one helper instead of re-implementing the table — and keeps
 * the table in sync with the one workflow-php and sdk-python publish
 * via their replay-verify CLIs.
 */
final class ReplayPromotionGate
{
    public const SAFE_TO_PROMOTE = 'safe_to_promote';

    public const REVIEW_BEFORE_PROMOTE = 'review_before_promote';

    public const BLOCK_UNTIL_COMPATIBLE = 'block_until_compatible';

    public const BLOCK_AND_INVESTIGATE = 'block_and_investigate';

    public const STATUS_PASS = 'pass';

    public const STATUS_REVIEW = 'review';

    public const STATUS_BLOCK = 'block';

    /**
     * Decide a single verify report's promotion outcome.
     *
     * @param array<string, mixed> $report
     *
     * @return array{
     *     verdict: string,
     *     promotion_decision: string,
     *     gate_status: string,
     *     reason: string,
     *     report_schema: ?string,
     *     report_schema_version: ?int
     * }
     */
    public static function evaluate(array $report): array
    {
        $verdict = self::stringOrNull($report['verdict'] ?? null) ?? 'failed';
        $decision = self::decisionForVerdict($verdict);

        return [
            'verdict' => $verdict,
            'promotion_decision' => $decision,
            'gate_status' => self::statusForDecision($decision),
            'reason' => self::reasonForVerdict($verdict),
            'report_schema' => self::stringOrNull($report['schema'] ?? null),
            'report_schema_version' => is_int($report['schema_version'] ?? null)
                ? $report['schema_version']
                : null,
        ];
    }

    /**
     * Reduce a batch of per-bundle gate decisions to a single overall
     * gate. The reduction matches the workflow-php replay-simulate
     * report's aggregation: the worst verdict pins the overall.
     *
     * @param list<array<string, mixed>> $reports
     *
     * @return array{
     *     verdict: string,
     *     promotion_decision: string,
     *     gate_status: string,
     *     reason: string,
     *     evaluated: int
     * }
     */
    public static function aggregate(array $reports): array
    {
        if ($reports === []) {
            return [
                'verdict' => 'failed',
                'promotion_decision' => self::BLOCK_AND_INVESTIGATE,
                'gate_status' => self::STATUS_BLOCK,
                'reason' => 'no_reports',
                'evaluated' => 0,
            ];
        }

        $rank = [
            'ok' => 0,
            'warning' => 1,
            'drifted' => 2,
            'failed' => 3,
        ];

        $worst = 'ok';

        foreach ($reports as $report) {
            $verdict = self::stringOrNull($report['verdict'] ?? null) ?? 'failed';

            $currentRank = $rank[$verdict] ?? $rank['failed'];
            if ($currentRank > $rank[$worst]) {
                $worst = isset($rank[$verdict]) ? $verdict : 'failed';
            }
        }

        $decision = self::decisionForVerdict($worst);

        return [
            'verdict' => $worst,
            'promotion_decision' => $decision,
            'gate_status' => self::statusForDecision($decision),
            'reason' => self::reasonForVerdict($worst),
            'evaluated' => count($reports),
        ];
    }

    public static function decisionForVerdict(string $verdict): string
    {
        return match ($verdict) {
            'ok' => self::SAFE_TO_PROMOTE,
            'warning' => self::REVIEW_BEFORE_PROMOTE,
            'drifted' => self::BLOCK_UNTIL_COMPATIBLE,
            'failed' => self::BLOCK_AND_INVESTIGATE,
            default => self::BLOCK_AND_INVESTIGATE,
        };
    }

    public static function statusForDecision(string $decision): string
    {
        return match ($decision) {
            self::SAFE_TO_PROMOTE => self::STATUS_PASS,
            self::REVIEW_BEFORE_PROMOTE => self::STATUS_REVIEW,
            self::BLOCK_UNTIL_COMPATIBLE,
            self::BLOCK_AND_INVESTIGATE => self::STATUS_BLOCK,
            default => self::STATUS_BLOCK,
        };
    }

    private static function reasonForVerdict(string $verdict): string
    {
        return match ($verdict) {
            'ok' => 'integrity_and_replay_clean',
            'warning' => 'structural_advisories_present',
            'drifted' => 'replay_diverges_from_history',
            'failed' => 'integrity_or_replay_failed',
            default => 'unknown_verdict',
        };
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
