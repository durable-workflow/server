<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Throwable;

final class ControlPlaneTimestamp
{
    public static function zuluSecond(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            if ($value instanceof CarbonInterface) {
                return $value->copy()->utc()->format('Y-m-d\TH:i:s\Z');
            }

            if ($value instanceof DateTimeInterface) {
                return CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:s\Z');
            }

            if (is_string($value) && trim($value) !== '') {
                return CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s\Z');
            }
        } catch (Throwable) {
            return is_string($value) && trim($value) !== '' ? $value : null;
        }

        return null;
    }
}
