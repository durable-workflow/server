<?php

namespace App\Support;

use InvalidArgumentException;

final class BoundedMetricPolicy
{
    /**
     * @param  array<string, array<string, mixed>>  $runtimeDimensions
     * @return array<string, mixed>
     */
    public static function labelSet(string $metric, array $runtimeDimensions = []): array
    {
        $metrics = config('dw-bounded-growth.metrics', []);

        if (! is_array($metrics) || ! array_key_exists($metric, $metrics)) {
            throw new InvalidArgumentException("Metric {$metric} is missing from config/dw-bounded-growth.php.");
        }

        $declared = $metrics[$metric]['dimensions'] ?? null;

        if (! is_array($declared)) {
            throw new InvalidArgumentException("Metric {$metric} must declare bounded-growth dimensions.");
        }

        $unknownDimensions = array_diff(array_keys($runtimeDimensions), array_keys($declared));

        if ($unknownDimensions !== []) {
            throw new InvalidArgumentException(sprintf(
                'Metric %s disclosed undeclared runtime dimension(s): %s.',
                $metric,
                implode(', ', $unknownDimensions),
            ));
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
