<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/benchmarks/capacity/v1/bindings/php/capacity_adapter.php';
capacityAutoload();

$rate = filter_var($argv[1] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0.1, 'max_range' => 20.0]]);
$duration = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => 120]]);
$drain = filter_var($argv[3] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 10, 'max_range' => 180]]);
$describeIntervalMs = filter_var($argv[4] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000]]);
if ($rate === false || $duration === false || $drain === false || $describeIntervalMs === false) {
    throw new InvalidArgumentException('Usage: standard-workflow-offered.php <0.1-20 starts/sec> <5-120 offer seconds> <10-180 drain seconds> [0-1000 describe interval ms].');
}

$queue = capacityEnvironment('DURABLE_WORKFLOW_TASK_QUEUE');
if ($queue === null) {
    throw new InvalidArgumentException('Set DURABLE_WORKFLOW_TASK_QUEUE.');
}

$client = capacityClient(false);
$blob = str_repeat('s', 1024);
$input = [
    'blob' => $blob,
    'payload_contract' => [
        'workflow_input_bytes' => 1024,
        'workflow_result_bytes' => 1024,
        'activity_input_bytes' => 1024,
        'activity_result_bytes' => 1024,
        'signal_bytes' => 0,
    ],
];
$expectedEvents = ['WorkflowStarted', 'ActivityScheduled', 'ActivityStarted', 'ActivityCompleted', 'WorkflowCompleted'];
$stageNames = [
    'WorkflowStarted' => 'client_start_to_workflow_started',
    'ActivityScheduled' => 'workflow_started_to_activity_scheduled',
    'ActivityStarted' => 'activity_scheduled_to_activity_started',
    'ActivityCompleted' => 'activity_started_to_activity_completed',
    'WorkflowCompleted' => 'activity_completed_to_workflow_completed',
];
$stageSamples = array_fill_keys(array_values($stageNames), []);
$planned = (int) ceil($rate * $duration);
$intervalNs = (int) round(1_000_000_000 / $rate);
$describeIntervalNs = $describeIntervalMs * 1_000_000;
$beganWall = microtime(true);
$beganNs = hrtime(true);
$offerEndsWall = $beganWall + $duration;
$offerEndsNs = $beganNs + $duration * 1_000_000_000;
$deadlineNs = $offerEndsNs + $drain * 1_000_000_000;
$next = 0;
$attempted = 0;
$skippedOffers = 0;
$started = [];
$pending = [];
$completed = [];
$errors = [];
$lateStarts = 0;
$maxStartLagSeconds = 0.0;
$pollErrors = 0;
$pollErrorClasses = [];
$describeCalls = 0;
$nextDescribeNs = $beganNs;

while (($next < $planned || $pending !== []) && hrtime(true) < $deadlineNs) {
    $nowNs = hrtime(true);
    $dueNs = $beganNs + $next * $intervalNs;
    if ($next < $planned && $nowNs >= $offerEndsNs) {
        $skippedOffers = $planned - $next;
        $next = $planned;

        continue;
    }
    if ($next < $planned && $nowNs >= $dueNs) {
        $lagSeconds = ($nowNs - $dueNs) / 1_000_000_000;
        $maxStartLagSeconds = max($maxStartLagSeconds, $lagSeconds);
        if ($lagSeconds > 1 / $rate) {
            $lateStarts++;
        }
        $id = 'offered-standard-'.bin2hex(random_bytes(12));
        $startWall = microtime(true);
        $attempted++;
        try {
            $handle = $client->startWorkflow('capacity.v1.one_activity', $id, $queue, [$input]);
            $started[$id] = ['handle' => $handle, 'wall' => $startWall];
            $pending[] = $id;
        } catch (Throwable $error) {
            $errors[] = $id.': start: '.$error->getMessage();
        }
        $next++;

        continue;
    }
    if ($pending !== []) {
        if ($nowNs < $nextDescribeNs) {
            $nextWakeNs = $next < $planned ? min($nextDescribeNs, $dueNs) : $nextDescribeNs;
            usleep((int) min(50_000, max(1, ($nextWakeNs - $nowNs) / 1000)));

            continue;
        }
        $nextDescribeNs = $nowNs + $describeIntervalNs;
        $id = array_shift($pending);
        $describeCalls++;
        try {
            $execution = $started[$id]['handle']->describe();
            if ($execution->status === 'completed') {
                if ($execution->closedAt === null) {
                    $errors[] = $id.': completed execution has no closed_at';

                    continue;
                }
                $closedWall = (float) (new DateTimeImmutable($execution->closedAt))->format('U.u');
                if ($closedWall < $started[$id]['wall']) {
                    $errors[] = $id.': Server closed_at precedes client start; clock contract failed';

                    continue;
                }
                $completed[$id] = [
                    'latency' => $closedWall - $started[$id]['wall'],
                    'closed_wall' => $closedWall,
                    'output' => $execution->output,
                ];
            } elseif ($execution->isTerminal === true || in_array($execution->status, ['failed', 'canceled', 'terminated', 'timed_out'], true)) {
                $errors[] = $id.': terminal status '.$execution->status;
            } else {
                $pending[] = $id;
            }
        } catch (Throwable $error) {
            $pollErrors++;
            $class = get_class($error);
            $pollErrorClasses[$class] = ($pollErrorClasses[$class] ?? 0) + 1;
            $pending[] = $id;
        }
    } else {
        $remainingNs = $dueNs - hrtime(true);
        if ($remainingNs > 0) {
            usleep((int) min(50_000, $remainingNs / 1000));
        }
    }
}

$lastObservationNs = hrtime(true);
foreach ($completed as $id => $sample) {
    try {
        $handle = $started[$id]['handle'];
        $history = $client->workflowHistory($id, $handle->selectedRunId);
        $events = array_column($history['events'] ?? [], 'event_type');
        if ($sample['output'] !== $blob || array_values(array_intersect($events, $expectedEvents)) !== $expectedEvents) {
            throw new RuntimeException('Output or ordered semantic history did not match.');
        }
        $eventTimes = [];
        foreach ($history['events'] ?? [] as $event) {
            $type = $event['event_type'] ?? null;
            if (isset($stageNames[$type]) && ! isset($eventTimes[$type])) {
                $timestamp = $event['timestamp'] ?? null;
                if (! is_string($timestamp) || $timestamp === '') {
                    throw new RuntimeException('Standard history event has no timestamp: '.$type);
                }
                $eventTimes[$type] = (float) (new DateTimeImmutable($timestamp))->format('U.u');
            }
        }
        $previousTime = $started[$id]['wall'];
        $intervals = [];
        foreach ($stageNames as $eventType => $stage) {
            $eventTime = $eventTimes[$eventType] ?? null;
            if ($eventTime === null || $eventTime < $previousTime) {
                throw new RuntimeException('Standard history timestamps are missing or out of order: '.$eventType);
            }
            $intervals[$stage] = $eventTime - $previousTime;
            $previousTime = $eventTime;
        }
        foreach ($intervals as $stage => $interval) {
            $stageSamples[$stage][] = $interval;
        }
    } catch (Throwable $error) {
        $errors[] = $id.': verification: '.$error->getMessage();
    }
}

$latencies = array_column($completed, 'latency');
$percentile = static function (array $values, float $fraction): ?float {
    if ($values === []) {
        return null;
    }
    sort($values);

    return $values[max(0, (int) ceil($fraction * count($values)) - 1)];
};
$stageIntervals = [];
foreach ($stageSamples as $stage => $values) {
    $stageIntervals[$stage] = [
        'count' => count($values),
        'p50' => $percentile($values, 0.50),
        'p95' => $percentile($values, 0.95),
        'p99' => $percentile($values, 0.99),
    ];
}
$closedInWindow = count(array_filter($completed, static fn (array $sample): bool => $sample['closed_wall'] < $offerEndsWall));
$result = [
    'probe' => 'fixed-offered-standard-experiment',
    'offered_rate_per_second' => $rate,
    'offer_seconds' => $duration,
    'drain_limit_seconds' => $drain,
    'describe_interval_ms' => $describeIntervalMs,
    'describe_calls' => $describeCalls,
    'planned_starts' => $planned,
    'attempted_starts' => $attempted,
    'skipped_offer_slots' => $skippedOffers,
    'accepted_starts' => count($started),
    'late_starts_over_one_slot' => $lateStarts,
    'max_start_lag_seconds' => $maxStartLagSeconds,
    'completed_by_observation_deadline' => count($completed),
    'pending_at_deadline' => count($pending),
    'closed_during_offer_window' => $closedInWindow,
    'offer_window_completions_per_second' => $closedInWindow / $duration,
    'observation_seconds_excluding_history_verification' => ($lastObservationNs - $beganNs) / 1_000_000_000,
    'latency_boundary' => 'Client start request beginning to Server closed_at; shared host clock, SDK describe collection delay excluded.',
    'latency_p50_seconds' => $percentile($latencies, 0.50),
    'latency_p95_seconds' => $percentile($latencies, 0.95),
    'latency_p99_seconds' => $percentile($latencies, 0.99),
    'history_stage_intervals_seconds' => $stageIntervals,
    'poll_errors' => $pollErrors,
    'poll_error_classes' => $pollErrorClasses,
    'errors' => $errors,
];
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
exit($errors === [] && $skippedOffers === 0 && $pending === [] && count($completed) === count($started) && $lateStarts === 0 && $pollErrors === 0 ? 0 : 1);
