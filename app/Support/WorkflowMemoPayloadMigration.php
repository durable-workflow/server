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
        bool $recoverLegacyEnvelopeStorage = false,
    ): int {
        $ids = DB::table('workflow_memos')
            ->whereNull('portable_value_sequence')
            ->orderBy('id')
            ->limit(max(1, $batchSize))
            ->pluck('id');

        foreach ($ids as $id) {
            self::backfillRow((int) $id, $recoverLegacyEnvelopeStorage);
        }

        return $ids->count();
    }

    public static function backfillAll(bool $recoverLegacyEnvelopeStorage = false): void
    {
        while (self::backfillBatch(100, $recoverLegacyEnvelopeStorage) > 0) {
            // Each row is committed independently with its sequence marker so
            // a non-transactional database can resume after interruption.
        }
    }

    private static function backfillRow(int $id, bool $recoverLegacyEnvelopeStorage): bool
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
                $logicalValue = self::logicalValue($stored, $recoverLegacyEnvelopeStorage);
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

    private static function logicalValue(mixed $stored, bool $recoverLegacyEnvelopeStorage): mixed
    {
        if (
            ! $recoverLegacyEnvelopeStorage
            || ! is_array($stored)
            || ! MemoPayload::isInlineEnvelope($stored)
        ) {
            return $stored;
        }

        return MemoPayload::decode($stored);
    }
}
