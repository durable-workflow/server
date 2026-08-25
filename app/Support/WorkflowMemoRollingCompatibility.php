<?php

declare(strict_types=1);

namespace App\Support;

use App\Casts\WorkflowMemoValueCompatibilityCast;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Support\MemoPayload;

final class WorkflowMemoRollingCompatibility
{
    public static function register(): void
    {
        WorkflowMemo::retrieved(self::attach(...));
        WorkflowMemo::saving(static function (WorkflowMemo $memo): void {
            $attributes = $memo->getAttributes();
            $rawPortable = $attributes['portable_value'] ?? null;
            $rawValue = $attributes['value'] ?? null;

            self::attach($memo);

            // A newly constructed package model encoded its logical value as
            // an envelope before this host-level cast could be attached.
            if (! $memo->exists && $rawPortable === null) {
                $candidate = is_string($rawValue)
                    ? json_decode($rawValue, true, flags: JSON_THROW_ON_ERROR)
                    : $rawValue;

                if (is_array($candidate) && MemoPayload::isInlineEnvelope($candidate)) {
                    $memo->value = $candidate;
                }
            }

            $attributes = $memo->getAttributes();
            if (
                ($attributes['portable_value'] ?? null) !== null
                && $memo->isDirty(['value', 'portable_value', 'upserted_at_sequence'])
            ) {
                $memo->portable_value_sequence = $memo->upserted_at_sequence;
            }
        });
    }

    private static function attach(WorkflowMemo $memo): void
    {
        $memo->mergeCasts([
            'value' => WorkflowMemoValueCompatibilityCast::class,
            'portable_value' => 'array',
            'portable_value_sequence' => 'integer',
        ]);
    }
}
