<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;
use Workflow\Serializers\AvroValueJsonProjection;
use Workflow\V2\Support\MemoPayload;

final class WorkflowMemoPayloadMigration
{
    private const LEGACY_ENVELOPE_MIGRATION =
        '2026_08_25_000100_encode_workflow_memos_for_portable_runtime';

    public static function ensureExpandedSchema(): void
    {
        if (! Schema::hasColumn('workflow_memos', 'portable_value')) {
            Schema::table('workflow_memos', static function (Blueprint $table): void {
                $table->json('portable_value')->nullable();
            });
        }

        if (! Schema::hasColumn('workflow_memos', 'portable_value_sequence')) {
            Schema::table('workflow_memos', static function (Blueprint $table): void {
                $table->unsignedInteger('portable_value_sequence')->nullable();
            });
        }
    }

    /**
     * Convert at most one bounded batch.
     *
     * @return int number of candidate rows inspected
     */
    public static function backfillBatch(
        int $batchSize = 100,
        ?int $legacyEnvelopeCutoffId = null,
    ): int {
        $ids = DB::table('workflow_memos')
            ->whereNull('portable_value_sequence')
            ->orderBy('id')
            ->limit(max(1, $batchSize))
            ->pluck('id');

        foreach ($ids as $id) {
            self::backfillRow((int) $id, $legacyEnvelopeCutoffId);
        }

        return $ids->count();
    }

    public static function backfillAll(?int $legacyEnvelopeCutoffId = null): void
    {
        while (self::backfillBatch(100, $legacyEnvelopeCutoffId) > 0) {
            // Each row is committed independently with its sequence marker so
            // a non-transactional database can resume after interruption.
        }
    }

    /**
     * Resolve the source representation without inspecting memo contents.
     *
     * A completed predecessor ledger entry proves that every row up to the
     * current high-water mark was written by the envelope-only revision. An
     * unrecorded MySQL rewrite is indistinguishable from legitimate business
     * values, so an operator must provide the ordered-ID boundary observed at
     * interruption. PostgreSQL rolls the unrecorded migration transaction
     * back and therefore remains on the raw-JSON path.
     */
    public static function legacyEnvelopeCutoff(): ?int
    {
        if (! Schema::hasTable('workflow_memos')) {
            return null;
        }

        if (
            Schema::hasTable('migrations')
            && DB::table('migrations')
                ->where('migration', self::LEGACY_ENVELOPE_MIGRATION)
                ->exists()
        ) {
            return self::latestMemoId();
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            return null;
        }

        $hasCandidates = Schema::hasColumn('workflow_memos', 'portable_value_sequence')
            ? DB::table('workflow_memos')->whereNull('portable_value_sequence')->exists()
            : DB::table('workflow_memos')->exists();

        if (! $hasCandidates || $driver !== 'mysql') {
            return null;
        }

        $recovery = trim((string) config(
            'server.migrations.workflow_memo_recovery',
            '',
        ));

        if ($recovery === 'raw-json') {
            return null;
        }

        if (preg_match('/\Aenvelope-prefix:([1-9][0-9]*)\z/D', $recovery, $matches) === 1) {
            $cutoff = $matches[1];
            $maximum = (string) PHP_INT_MAX;

            if (
                strlen($cutoff) < strlen($maximum)
                || (strlen($cutoff) === strlen($maximum) && strcmp($cutoff, $maximum) <= 0)
            ) {
                return (int) $cutoff;
            }
        }

        if ($recovery !== '') {
            throw new RuntimeException(
                'workflow_memo_payload_migration_recovery_invalid: expected '
                .'DW_WORKFLOW_MEMO_MIGRATION_RECOVERY=raw-json or '
                .'envelope-prefix:<last-converted-id>; no memo rows were changed.',
            );
        }

        throw new RuntimeException(
            'workflow_memo_payload_migration_source_ambiguous: MySQL contains memo rows '
            .'without a completed envelope-only migration record; no memo rows were changed. '
            .'Restore the pre-rewrite backup and set '
            .'DW_WORKFLOW_MEMO_MIGRATION_RECOVERY=raw-json, or set '
            .'envelope-prefix:<last-converted-id> only after identifying the ordered ID '
            .'prefix changed by the rc.47/rc.48 rewrite, then rerun server-bootstrap. '
            .'Memo contents were omitted.',
        );
    }

    private static function backfillRow(int $id, ?int $legacyEnvelopeCutoffId): bool
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $row = DB::table('workflow_memos')->where('id', $id)->first();

            if ($row === null || $row->portable_value_sequence !== null) {
                return false;
            }

            try {
                $stored = is_string($row->value)
                    ? json_decode($row->value, true, flags: JSON_THROW_ON_ERROR)
                    : $row->value;
                $logicalValue = self::logicalValue($stored, $id, $legacyEnvelopeCutoffId);
                $payload = MemoPayload::envelope($logicalValue);

                $updated = DB::table('workflow_memos')
                    ->where('id', $id)
                    ->where('upserted_at_sequence', $row->upserted_at_sequence)
                    ->whereNull('portable_value_sequence')
                    ->update([
                        'value' => json_encode(
                            AvroValueJsonProjection::project($logicalValue),
                            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
                        ),
                        'portable_value' => json_encode($payload, JSON_THROW_ON_ERROR),
                        'portable_value_sequence' => $row->upserted_at_sequence,
                    ]);

                if ($updated === 1) {
                    return true;
                }
            } catch (Throwable) {
                throw new RuntimeException(sprintf(
                    'workflow_memo_payload_migration_failed: row id %s could not be converted; memo contents were omitted.',
                    (string) $id,
                ));
            }
        }

        throw new RuntimeException(sprintf(
            'workflow_memo_payload_migration_contended: row id %s changed repeatedly; retry after memo traffic settles.',
            (string) $id,
        ));
    }

    private static function latestMemoId(): ?int
    {
        $id = DB::table('workflow_memos')->max('id');

        return $id === null ? null : (int) $id;
    }

    private static function logicalValue(
        mixed $stored,
        int $id,
        ?int $legacyEnvelopeCutoffId,
    ): mixed {
        if ($legacyEnvelopeCutoffId === null || $id > $legacyEnvelopeCutoffId) {
            return $stored;
        }

        if (! is_array($stored) || ! MemoPayload::isInlineEnvelope($stored)) {
            throw new RuntimeException('confirmed predecessor row is not an inline envelope');
        }

        return MemoPayload::decode($stored);
    }
}
