<?php

declare(strict_types=1);

function php_sdk_activity_callback_phase(mixed $value): string
{
    if (is_array($value) && ($value['replay'] ?? null) === true) {
        return 'durable_replay';
    }

    $matrixStep = is_array($value) ? ($value['matrix'] ?? null) : null;

    return match ($matrixStep) {
        'activity', 'compensation', 'after-signal' => 'replay_matrix',
        'in-flight-after-signal' => 'in_flight_replay',
        default => 'initial_execution',
    };
}

/**
 * @param  array<string, mixed>  $callback
 */
function php_sdk_record_activity_callback(string $file, string $phase, array $callback): void
{
    $handle = fopen($file, 'c+');
    if ($handle === false) {
        throw new RuntimeException("Unable to open activity callback evidence {$file}.");
    }

    try {
        if (! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock activity callback evidence.');
        }

        $raw = stream_get_contents($handle);
        $evidence = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
        if (! is_array($evidence)) {
            $evidence = [];
        }

        $callbacks = is_array($evidence['callbacks'] ?? null) ? $evidence['callbacks'] : [];
        $callbacks[] = ['phase' => $phase] + $callback;

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode(['callbacks' => $callbacks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

/**
 * @param  array<string, mixed>  $callbackEvidence
 * @param  array<string, array<string, array<string, int>>>  $historyEventCountsByPhase
 * @return array<string, mixed>
 */
function php_sdk_activity_callback_cardinality(
    array $callbackEvidence,
    array $historyEventCountsByPhase,
    bool $replayMatrixEnabled,
): array {
    $expectedCallbackCounts = [
        'initial_execution' => 1,
        'durable_replay' => 1,
    ];
    $expectedHistoryCounts = [
        'initial_execution' => [
            'completed' => ['ActivityScheduled' => 1, 'ActivityCompleted' => 1],
        ],
        'durable_replay' => [
            'before_worker_restart' => ['ActivityScheduled' => 1, 'ActivityCompleted' => 1],
            'after_worker_restart' => ['ActivityScheduled' => 1, 'ActivityCompleted' => 1],
        ],
    ];

    if ($replayMatrixEnabled) {
        $expectedCallbackCounts += [
            'replay_matrix' => 3,
            'in_flight_replay' => 1,
        ];
        $expectedHistoryCounts += [
            'replay_matrix' => [
                'completed' => ['ActivityScheduled' => 4, 'ActivityCompleted' => 3, 'ActivityFailed' => 1],
                'after_worker_restart' => ['ActivityScheduled' => 4, 'ActivityCompleted' => 3, 'ActivityFailed' => 1],
            ],
            'in_flight_replay' => [
                'after_worker_restart' => ['ActivityScheduled' => 1, 'ActivityCompleted' => 1],
            ],
        ];
    }

    $callbacks = array_values(array_filter($callbackEvidence['callbacks'] ?? [], 'is_array'));
    $observedCallbackCounts = [];
    $taskIdsByPhase = [];
    $attemptIdsByPhase = [];
    foreach ($callbacks as $callback) {
        $phase = (string) ($callback['phase'] ?? 'unknown');
        $observedCallbackCounts[$phase] = (int) ($observedCallbackCounts[$phase] ?? 0) + 1;
        $taskIdsByPhase[$phase][] = (string) ($callback['task_id'] ?? '');
        $attemptIdsByPhase[$phase][] = (string) ($callback['activity_attempt_id'] ?? '');
    }
    ksort($observedCallbackCounts);

    $phaseResults = [];
    foreach ($expectedCallbackCounts as $phase => $expectedCallbackCount) {
        $historyResults = [];
        foreach ($expectedHistoryCounts[$phase] as $checkpoint => $expectedCounts) {
            $observedCounts = $historyEventCountsByPhase[$phase][$checkpoint] ?? [];
            $matchingCounts = true;
            foreach ($expectedCounts as $eventType => $expectedCount) {
                if ((int) ($observedCounts[$eventType] ?? 0) !== $expectedCount) {
                    $matchingCounts = false;
                }
            }
            $historyResults[$checkpoint] = [
                'passed' => $matchingCounts,
                'expected_event_counts' => $expectedCounts,
                'observed_event_counts' => $observedCounts,
            ];
        }

        $taskIds = array_values(array_filter($taskIdsByPhase[$phase] ?? [], static fn (string $id): bool => $id !== ''));
        $attemptIds = array_values(array_filter($attemptIdsByPhase[$phase] ?? [], static fn (string $id): bool => $id !== ''));
        $callbackCount = (int) ($observedCallbackCounts[$phase] ?? 0);
        $phaseResults[$phase] = [
            'passed' => $callbackCount === $expectedCallbackCount
                && count(array_unique($taskIds)) === $expectedCallbackCount
                && count(array_unique($attemptIds)) === $expectedCallbackCount
                && ! in_array(false, array_column($historyResults, 'passed'), true),
            'expected_callback_count' => $expectedCallbackCount,
            'observed_callback_count' => $callbackCount,
            'distinct_task_ids' => array_values(array_unique($taskIds)),
            'distinct_activity_attempt_ids' => array_values(array_unique($attemptIds)),
            'history_checkpoints' => $historyResults,
        ];
    }

    $unexpectedPhases = array_values(array_diff(array_keys($observedCallbackCounts), array_keys($expectedCallbackCounts)));

    return [
        'passed' => $unexpectedPhases === []
            && ! in_array(false, array_column($phaseResults, 'passed'), true),
        'expected_callback_counts_by_phase' => $expectedCallbackCounts,
        'observed_callback_counts_by_phase' => $observedCallbackCounts,
        'unexpected_phases' => $unexpectedPhases,
        'phase_results' => $phaseResults,
        'callback_records' => $callbacks,
    ];
}
