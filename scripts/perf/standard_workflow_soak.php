<?php

declare(strict_types=1);
use Composer\InstalledVersions;

require dirname(__DIR__, 2).'/benchmarks/capacity/v1/bindings/php/capacity_adapter.php';
capacityAutoload();

$duration = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 14400]]);
if ($duration === false) {
    throw new InvalidArgumentException('Supply a duration between 1 and 14400 seconds.');
}
$client = capacityClient(false);
$queue = capacityEnvironment('DURABLE_WORKFLOW_TASK_QUEUE');
$readyBy = microtime(true) + 30;
do {
    if ($client->listWorkers($queue, 'active') !== []) {
        break;
    }
    if (microtime(true) >= $readyBy) {
        throw new RuntimeException('Standard workflow worker did not register within 30 seconds.');
    }
    usleep(200000);
} while (true);

$payload = str_repeat('s', 1024);
$input = [
    'blob' => $payload,
    'payload_contract' => [
        'workflow_input_bytes' => 1024,
        'workflow_result_bytes' => 1024,
        'activity_input_bytes' => 1024,
        'activity_result_bytes' => 1024,
        'signal_bytes' => 0,
    ],
];
$expectedEvents = ['WorkflowStarted', 'ActivityScheduled', 'ActivityStarted', 'ActivityCompleted', 'WorkflowCompleted'];
$started = hrtime(true);
$stopAt = $started + $duration * 1000000000;
$nextStart = $started;
$failed = false;
echo json_encode([
    'phase' => 'started',
    'sdk' => InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
    'php' => PHP_VERSION,
    'interval_seconds' => 5,
    'duration_seconds' => $duration,
], JSON_THROW_ON_ERROR).PHP_EOL;

// Closed-loop canary: never accumulate unbounded work when the runtime slows.
while (hrtime(true) < $stopAt) {
    if (hrtime(true) < $nextStart) {
        usleep((int) min(200000, ($nextStart - hrtime(true)) / 1000));

        continue;
    }
    $workflowId = 'soak-standard-'.bin2hex(random_bytes(12));
    $attemptAt = hrtime(true);
    $row = ['workflow_id' => $workflowId, 'phase' => 'workflow', 'completed' => false];
    try {
        $handle = $client->startWorkflow('capacity.v1.one_activity', $workflowId, $queue, [$input]);
        $row['run_id'] = $handle->selectedRunId;
        $result = $handle->result(30, 1);
        $row['latency_seconds'] = (hrtime(true) - $attemptAt) / 1000000000;
        $execution = $handle->describe();
        $history = $client->workflowHistory($workflowId, $handle->selectedRunId);
        $events = array_column($history['events'] ?? [], 'event_type');
        $semanticEvents = array_values(array_intersect($events, $expectedEvents));
        if ($result !== $payload || $execution->status !== 'completed' || $semanticEvents !== $expectedEvents) {
            throw new RuntimeException('Standard workflow result, status, or ordered activity history did not match.');
        }
        $row['completed'] = true;
    } catch (Throwable $error) {
        $row['error'] = get_class($error).': '.$error->getMessage();
        $failed = true;
    }
    echo json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
    // Skip missed start slots instead of generating a catch-up burst.
    $nextStart = $started + ((int) ((hrtime(true) - $started) / 5000000000) + 1) * 5000000000;
}
echo json_encode(['phase' => 'finished', 'elapsed_seconds' => (hrtime(true) - $started) / 1000000000], JSON_THROW_ON_ERROR).PHP_EOL;
exit($failed ? 1 : 0);
