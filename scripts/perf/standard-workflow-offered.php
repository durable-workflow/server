<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/benchmarks/capacity/v1/bindings/php/capacity_adapter.php';
capacityAutoload();

$rate = filter_var($argv[1] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0.1, 'max_range' => 20.0]]);
$duration = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => 120]]);
$drain = filter_var($argv[3] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 10, 'max_range' => 180]]);
if ($rate === false || $duration === false || $drain === false) {
    throw new InvalidArgumentException('Usage: standard-workflow-offered.php <0.1-20 starts/sec> <5-120 offer seconds> <10-180 drain seconds>.');
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
$planned = (int) ceil($rate * $duration);
$intervalNs = (int) round(1_000_000_000 / $rate);
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
        $id = array_shift($pending);
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
    } catch (Throwable $error) {
        $errors[] = $id.': verification: '.$error->getMessage();
    }
}

$latencies = array_column($completed, 'latency');
sort($latencies);
$percentile = static fn (float $fraction): ?float => $latencies === []
    ? null
    : $latencies[max(0, (int) ceil($fraction * count($latencies)) - 1)];
$closedInWindow = count(array_filter($completed, static fn (array $sample): bool => $sample['closed_wall'] < $offerEndsWall));
$result = [
    'probe' => 'fixed-offered-standard-experiment',
    'offered_rate_per_second' => $rate,
    'offer_seconds' => $duration,
    'drain_limit_seconds' => $drain,
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
    'latency_p50_seconds' => $percentile(0.50),
    'latency_p95_seconds' => $percentile(0.95),
    'latency_p99_seconds' => $percentile(0.99),
    'poll_errors' => $pollErrors,
    'poll_error_classes' => $pollErrorClasses,
    'errors' => $errors,
];
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
exit($errors === [] && $skippedOffers === 0 && $pending === [] && count($completed) === count($started) && $lateStarts === 0 && $pollErrors === 0 ? 0 : 1);
