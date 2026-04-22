<?php

namespace App\Support;

final class BoundedMetricPolicy
{
    /**
     * @param  array<string, array<string, mixed>>  $runtimeDimensions
     * @return array<string, mixed>
     */
    public static function labelSet(string $metric, array $runtimeDimensions = []): array
    {
        $declared = config("dw-bounded-growth.metrics.{$metric}.dimensions", []);

        if (! is_array($declared)) {
            return $runtimeDimensions;
        }

        $labelSet = [];

        foreach ($declared as $dimension => $cardinalityClass) {
            $dimension = (string) $dimension;
            $cardinalityClass = (string) $cardinalityClass;
            $runtime = $runtimeDimensions[$dimension] ?? null;

            if (is_array($runtime)) {
                $labelSet[$dimension] = [
                    'cardinality_class' => $cardinalityClass,
                    ...$runtime,
                ];

                continue;
            }

            $labelSet[$dimension] = $cardinalityClass;
        }

        return $labelSet;
    }
}
