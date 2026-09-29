<?php

declare(strict_types=1);

require getenv('PROBE_AUTOLOAD') ?: '/app/vendor/autoload.php';

use DurableWorkflow\Attribute\Activity;
use DurableWorkflow\Attribute\Signal;
use DurableWorkflow\Attribute\Workflow;
use DurableWorkflow\Client;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\WorkflowContext;

final class MixedHistoryWorkflow
{
    #[Workflow('history-qualification.php-mixed')]
    public function run(
        WorkflowContext $context,
        int $target,
        int $sideEffects,
        int $stage = 0,
        int $carriedTotal = 0,
    ): array {
        if ($stage === 1) {
            return ['count' => $target, 'total' => $carriedTotal];
        }

        $satisfied = $context->waitCondition(
            static fn (): bool => count($context->signals('append')) === $target,
            key: 'all-signals',
        );
        if (! $satisfied) {
            throw new RuntimeException('The signal wait ended before the target was reached.');
        }

        $signals = $context->signals('append');
        $seen = [];
        $total = 0;
        foreach ($signals as $arguments) {
            $index = $arguments[0] ?? null;
            if (! is_int($index) || $index < 0 || $index >= $target || isset($seen[$index])) {
                throw new RuntimeException('Signals were lost, duplicated, or decoded incorrectly.');
            }
            $seen[$index] = true;
            $total += $index;
        }
        if (count($seen) !== $target || $total !== intdiv($target * ($target - 1), 2)) {
            throw new RuntimeException('The acknowledged signal set did not survive replay.');
        }

        for ($index = 0; $index < $sideEffects; ++$index) {
            $recorded = $context->sideEffect(static fn (): int => $index);
            if ($recorded !== $index) {
                throw new RuntimeException("Side effect {$index} replayed as ".json_encode($recorded));
            }
            if (($index + 1) % 500 === 0) {
                $boundary = $index + 1;
                $activityResult = $context->activity('history-qualification.php-boundary', [$boundary]);
                if ($activityResult !== $boundary) {
                    throw new RuntimeException("Activity boundary {$boundary} returned ".json_encode($activityResult));
                }
                $context->sleep(0);
            }
        }

        $context->continueAsNew([$target, $sideEffects, 1, $total]);
    }

    #[Signal('append')]
    public function append(int $index): void
    {
        // The committed signal history is the source of truth on every replay.
    }
}

final class MixedHistoryActivities
{
    #[Activity('history-qualification.php-boundary')]
    public function boundary(ActivityContext $context, int $index): int
    {
        return $index;
    }
}

$mode = $argv[1] ?? '';
$runtimeUrl = getenv('PROBE_RUNTIME_URL') ?: '';
$workflowId = getenv('PROBE_WORKFLOW_ID') ?: '';
$queue = getenv('PROBE_TASK_QUEUE') ?: '';
$target = (int) (getenv('PROBE_TARGET') ?: '4000');
$sideEffects = (int) (getenv('PROBE_SIDE_EFFECTS') ?: '4000');
if (! in_array($mode, ['start', 'wait', 'worker', 'result'], true)
    || ! in_array(parse_url($runtimeUrl, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)
    || $workflowId === '' || $queue === '' || $target < 1 || $target > 6000
    || $sideEffects < 1 || $sideEffects > 6000 || $sideEffects % 500 !== 0) {
    throw new InvalidArgumentException('Use an isolated loopback Server and bounded probe settings.');
}

$client = new Client(
    $runtimeUrl,
    namespace: 'default',
    controlToken: getenv('PROBE_CONTROL_TOKEN') ?: null,
    workerToken: getenv('PROBE_WORKER_TOKEN') ?: null,
);

if ($mode === 'start') {
    $handle = $client->startWorkflow(
        workflowType: 'history-qualification.php-mixed',
        workflowId: $workflowId,
        taskQueue: $queue,
        input: [$target, $sideEffects],
        executionTimeoutSeconds: 3600,
        runTimeoutSeconds: 3600,
    );
    echo json_encode(['phase' => 'started', 'workflow_id' => $workflowId, 'run_id' => $handle->selectedRunId], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === 'worker') {
    Worker::create($client, $queue)
        ->register(MixedHistoryWorkflow::class, MixedHistoryActivities::class)
        ->run(1);
    echo json_encode(['phase' => 'worker_stopped', 'peak_php_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === 'wait') {
    $deadline = microtime(true) + 120;
    while (microtime(true) < $deadline) {
        $execution = $client->describeWorkflow($workflowId);
        if ($execution->status === 'waiting') {
            echo json_encode(['phase' => 'waiting', 'run_id' => $execution->runId], JSON_THROW_ON_ERROR).PHP_EOL;
            exit(0);
        }
        if (in_array($execution->status, ['failed', 'terminated', 'cancelled'], true)) {
            throw new RuntimeException('Initial workflow stopped: '.$execution->status);
        }
        usleep(200000);
    }
    throw new RuntimeException('Workflow did not reach the initial signal wait.');
}

$deadline = microtime(true) + 1800;
while (microtime(true) < $deadline) {
    $execution = $client->describeWorkflow($workflowId);
    if ($execution->status === 'completed') {
        $result = $client->workflowResult($workflowId, null, 30, 0.5, true);
        $expected = ['count' => $target, 'total' => intdiv($target * ($target - 1), 2)];
        if ($result !== $expected) {
            throw new RuntimeException('Wrong completed result: '.json_encode($result));
        }
        echo json_encode([
            'phase' => 'completed',
            'workflow_id' => $workflowId,
            'run_id' => $execution->runId,
            'result' => $result,
        ], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    if (in_array($execution->status, ['failed', 'terminated', 'cancelled'], true)) {
        throw new RuntimeException('Workflow stopped: '.$execution->status);
    }
    usleep(1000000);
}
throw new RuntimeException('Workflow did not complete in 1,800 seconds.');
