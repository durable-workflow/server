<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Workflow\Serializers\AvroValueJsonProjection;
use Workflow\V2\Support\MemoPayload;

/**
 * @implements CastsAttributes<array{codec: string, blob: string}, mixed>
 */
final class WorkflowMemoValueCompatibilityCast implements CastsAttributes
{
    /**
     * Present the envelope expected by the installed Workflow model while
     * retaining a predecessor-readable JSON projection in the value column.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{codec: string, blob: string}
     */
    public function get(
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): array {
        $projection = self::decodeJsonColumn($value);
        $portable = self::decodeJsonColumn($attributes['portable_value'] ?? null);
        $sequence = $attributes['upserted_at_sequence'] ?? null;
        $portableSequence = $attributes['portable_value_sequence'] ?? null;

        if (
            is_array($portable)
            && $portableSequence !== null
            && (int) $portableSequence === (int) $sequence
        ) {
            return $portable;
        }

        return MemoPayload::envelope($projection);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function set(
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): array {
        $logicalValue = is_array($value) && MemoPayload::isInlineEnvelope($value)
            ? MemoPayload::decode($value)
            : $value;
        $portable = MemoPayload::envelope($logicalValue);

        return [
            'value' => json_encode(
                AvroValueJsonProjection::project($logicalValue),
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
            ),
            'portable_value' => json_encode($portable, JSON_THROW_ON_ERROR),
        ];
    }

    private static function decodeJsonColumn(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }
}
