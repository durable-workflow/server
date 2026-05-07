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
 * Both report shapes already carry a `verdict` field; the gate resolves
 * it through the canonical mapping, then checks the evidence block that
 * proves which verifier steps actually ran. Centralizing the policy here
 * lets server-side rollout/promotion controllers and CI gates call one
 * helper instead of re-implementing the table.
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
     *     evidence_complete: bool,
     *     evidence_issues: list<string>,
     *     report_schema: ?string,
     *     report_schema_version: ?int
     * }
     */
    public static function evaluate(array $report): array
    {
        $verdict = self::stringOrNull($report['verdict'] ?? null) ?? 'failed';
        $decision = self::decisionForVerdict($verdict);
        $evidenceIssues = self::evidenceIssues($report);
        $reason = self::reasonForVerdict($verdict);

        if ($decision === self::SAFE_TO_PROMOTE && $evidenceIssues !== []) {
            if ($evidenceIssues === ['replay_skipped']) {
                $decision = self::REVIEW_BEFORE_PROMOTE;
                $reason = 'replay_evidence_review_required';
            } else {
                $decision = self::BLOCK_AND_INVESTIGATE;
                $reason = 'replay_evidence_incomplete';
            }
        }

        return [
            'verdict' => $verdict,
            'promotion_decision' => $decision,
            'gate_status' => self::statusForDecision($decision),
            'reason' => $reason,
            'evidence_complete' => $evidenceIssues === [],
            'evidence_issues' => $evidenceIssues,
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
     *     evidence_complete: bool,
     *     evidence_issues: list<string>,
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
                'evidence_complete' => false,
                'evidence_issues' => ['no_reports'],
                'evaluated' => 0,
            ];
        }

        $verdictRank = [
            'ok' => 0,
            'warning' => 1,
            'drifted' => 2,
            'failed' => 3,
        ];
        $gateRank = [
            self::STATUS_PASS => 0,
            self::STATUS_REVIEW => 1,
            self::STATUS_BLOCK => 2,
        ];

        $worst = self::evaluate($reports[0]);
        $evidenceIssues = [];

        foreach ($reports as $report) {
            $current = self::evaluate($report);
            $evidenceIssues = array_values(array_unique(array_merge(
                $evidenceIssues,
                $current['evidence_issues'],
            )));

            $currentGateRank = $gateRank[$current['gate_status']] ?? $gateRank[self::STATUS_BLOCK];
            $worstGateRank = $gateRank[$worst['gate_status']] ?? $gateRank[self::STATUS_BLOCK];

            $currentVerdictRank = $verdictRank[$current['verdict']] ?? $verdictRank['failed'];
            $worstVerdictRank = $verdictRank[$worst['verdict']] ?? $verdictRank['failed'];

            if (
                $currentGateRank > $worstGateRank
                || ($currentGateRank === $worstGateRank && $currentVerdictRank > $worstVerdictRank)
            ) {
                $worst = $current;
            }
        }

        return [
            'verdict' => $worst['verdict'],
            'promotion_decision' => $worst['promotion_decision'],
            'gate_status' => $worst['gate_status'],
            'reason' => $worst['reason'],
            'evidence_complete' => $evidenceIssues === [],
            'evidence_issues' => $evidenceIssues,
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

    /**
     * @param array<string, mixed> $report
     * @return list<string>
     */
    private static function evidenceIssues(array $report): array
    {
        $schema = self::stringOrNull($report['schema'] ?? null);

        if (! in_array($schema, [
            ReplayVerificationContract::VERIFICATION_REPORT_SCHEMA,
            ReplayVerificationContract::SIMULATION_REPORT_SCHEMA,
        ], true)) {
            return [];
        }

        $evidence = $report['evidence'] ?? null;
        if (! is_array($evidence)) {
            return ['evidence_missing'];
        }

        if ($schema === ReplayVerificationContract::VERIFICATION_REPORT_SCHEMA) {
            return self::verificationEvidenceIssues($evidence);
        }

        return self::simulationEvidenceIssues($evidence);
    }

    /**
     * @param array<string, mixed> $evidence
     * @return list<string>
     */
    private static function verificationEvidenceIssues(array $evidence): array
    {
        $issues = [];

        if (($evidence['integrity_checked'] ?? null) !== true) {
            $issues[] = 'integrity_evidence_missing';
        }

        if (($evidence['replay_checked'] ?? null) !== true) {
            $issues[] = ($evidence['replay_skipped'] ?? null) === true
                ? 'replay_skipped'
                : 'replay_diff_missing';
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $evidence
     * @return list<string>
     */
    private static function simulationEvidenceIssues(array $evidence): array
    {
        $issues = [];
        $bundleCount = self::intOrNull($evidence['bundle_count'] ?? null);
        $missingBundleCount = self::intOrNull($evidence['missing_bundle_count'] ?? null);
        $integrityCheckedCount = self::intOrNull($evidence['integrity_checked_count'] ?? null);
        $replayCheckedCount = self::intOrNull($evidence['replay_checked_count'] ?? null);
        $replaySkipped = ($evidence['replay_skipped'] ?? null) === true;

        if ($bundleCount === null || $bundleCount <= 0) {
            $issues[] = 'no_bundles';
        }

        if ($missingBundleCount === null || $missingBundleCount > 0) {
            $issues[] = 'missing_bundles';
        }

        if ($bundleCount !== null && $bundleCount > 0 && $integrityCheckedCount !== $bundleCount) {
            $issues[] = 'integrity_evidence_missing';
        }

        if ($replaySkipped) {
            $issues[] = 'replay_skipped';
        } elseif ($bundleCount !== null && $bundleCount > 0 && $replayCheckedCount !== $bundleCount) {
            $issues[] = 'replay_diff_missing';
        }

        return $issues;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
