<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/benchmarks/capacity/v1/bindings/php/capacity_adapter.php';
capacityAutoload();

$count = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
if ($count === false) {
    throw new InvalidArgumentException('Supply 1 to 100 workflows.');
}

$client = capacityClient(false);
$queue = capacityEnvironment('DURABLE_WORKFLOW_TASK_QUEUE');
if ($queue === null) {
    throw new InvalidArgumentException('Set DURABLE_WORKFLOW_TASK_QUEUE.');
}
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
$handles = [];
$observationLags = [];
$errors = [];
$started = hrtime(true);

for ($index = 0; $index < $count; $index++) {
    $id = 'burst-standard-'.bin2hex(random_bytes(12));
    $startAt = hrtime(true);
    try {
        $handles[$id] = [
            'handle' => $client->startWorkflow('capacity.v1.one_activity', $id, $queue, [$input]),
            'started' => $startAt,
        ];
    } catch (Throwable $error) {
        $errors[] = $id.': '.$error->getMessage();
    }
}

foreach ($handles as $id => $entry) {
    try {
        $handle = $entry['handle'];
        $result = $handle->result(120, 1);
        $execution = $handle->describe();
        $history = $client->workflowHistory($id, $handle->selectedRunId);
        $events = array_column($history['events'] ?? [], 'event_type');
        if ($result !== $payload || $execution->status !== 'completed' || array_values(array_intersect($events, $expectedEvents)) !== $expectedEvents) {
            throw new RuntimeException('Result, status, or ordered semantic history did not match.');
        }
        $observationLags[] = (hrtime(true) - $entry['started']) / 1_000_000_000;
    } catch (Throwable $error) {
        $errors[] = $id.': '.$error->getMessage();
    }
}

sort($observationLags);
$elapsed = (hrtime(true) - $started) / 1_000_000_000;
$percentile = static fn (float $fraction): ?float => $observationLags === []
    ? null
    : $observationLags[max(0, (int) ceil($fraction * count($observationLags)) - 1)];
echo json_encode([
    'probe' => 'exploratory-burst-not-capacity',
    'attempted' => $count,
    'completed' => count($observationLags),
    'errors' => $errors,
    'elapsed_seconds' => $elapsed,
    'drain_inclusive_completions_per_second' => count($observationLags) / $elapsed,
    'observation' => 'Sequential starts followed by serial result and history reads; lags include client collection delay.',
    'serial_observation_lag_p50_seconds' => $percentile(0.50),
    'serial_observation_lag_p95_seconds' => $percentile(0.95),
    'serial_observation_lag_p99_seconds' => $percentile(0.99),
], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
exit($errors === [] ? 0 : 1);
