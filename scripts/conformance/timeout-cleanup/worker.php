<?php

declare(strict_types=1);

// Installed timeout-cleanup worker (Server #1201). It runs the published PHP
// SDK from vendor/, never product source, on the task queue in TC_QUEUE.
// TC_COOPERATIVE=0 registers an ordinary worker, which does not advertise
// timeout_cleanup. TC_DIE_ON_CLEANUP=1 kills this process with SIGKILL when a
// cleanup turn reaches workflow code, so a replacement worker must finish it.
// Each workflow type is one cleanup behavior; vectors.php names the vectors.

require __DIR__.'/vendor/autoload.php';

use DurableWorkflow\Client;
use DurableWorkflow\Exception\WorkflowCancelled;
use DurableWorkflow\Exception\WorkflowTimeoutCleanupRequested;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\WorkflowContext;

$queue = getenv('TC_QUEUE') ?: 'timeout-cleanup';
$cooperative = getenv('TC_COOPERATIVE') !== '0';
$dieOnCleanup = getenv('TC_DIE_ON_CLEANUP') === '1';
$observations = getenv('TC_OBSERVATIONS') ?: '/evidence/observations.jsonl';

$client = new Client(
    getenv('TC_SERVER_URL') ?: 'http://server:8080',
    namespace: 'default',
    workerToken: getenv('TC_TOKEN') ?: 'timeout-cleanup-token',
    workerProtocolVersion: '1.20',
);
$worker = new Worker($client, $queue, workerId: getenv('TC_WORKER_ID') ?: null,
    diagnosticListener: static function (string $event, array $context): void {
        fwrite(STDERR, json_encode(['at' => gmdate('c'), 'event' => $event] + $context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)."\n");
    },
    enableCooperativeCancellation: $cooperative);

// What workflow code saw when the interruption reached it, written by an
// activity so the driver reads it without decoding payloads.
$observe = static function (WorkflowContext $context, string $vector, WorkflowCancelled $error) use ($dieOnCleanup): void {
    if ($dieOnCleanup) {
        posix_kill(posix_getpid(), SIGKILL);
    }
    $context->cancellationShield(static fn () => $context->activity('tc.observe', [[
        'vector' => $vector,
        'class' => $error::class,
        'timeout_subtype' => $error instanceof WorkflowTimeoutCleanupRequested,
        'cause' => $error->context?->cause,
        'request_id' => $error->requestId,
    ]]));
};

// Work waits on a long timer, so only the engine's deadline interrupts it.
$cleanup = static function (string $mode) use ($observe): Closure {
    return static function (WorkflowContext $context, string $vector = '') use ($mode, $observe): string {
        try {
            $context->sleep(3600);

            return 'work finished';
        } catch (WorkflowCancelled $error) {
            $observe($context, $vector, $error);
            switch ($mode) {
                case 'return':
                    return 'compensated';
                case 'rethrow':
                    throw $error;
                case 'fail':
                    throw new RuntimeException('compensation failed');
                default:
                    // Cleanup that outlives every deadline.
                    $context->cancellationShield(static fn () => $context->sleep(3600));

                    return 'lingered';
            }
        }
    };
};

foreach (['return', 'rethrow', 'fail', 'linger'] as $mode) {
    $worker->registerWorkflow("tc.$mode", $cleanup($mode));
}

// Continues as new once, then waits for cleanup and compensates.
$worker->registerWorkflow('tc.continue', static function (WorkflowContext $context, string $vector = '', int $generation = 0) use ($observe): string {
    if ($generation === 0) {
        $context->sleep(2);
        $context->continueAsNew([$vector, 1]);
    }
    try {
        $context->sleep(3600);

        return 'work finished';
    } catch (WorkflowCancelled $error) {
        $observe($context, $vector, $error);

        return 'compensated';
    }
});

// Scoped descendants: the parent's cleanup cancels its child (try_cancel);
// the child observes an ordinary cancellation and cleans up.
$worker->registerWorkflow('tc.parent', static function (WorkflowContext $context, string $vector = '') use ($observe): string {
    try {
        $context->childWorkflow('tc.child', [$vector.'-child'], ['cancellation_policy' => 'try_cancel']);

        return 'work finished';
    } catch (WorkflowCancelled $error) {
        $observe($context, $vector, $error);

        return 'compensated';
    }
});
$worker->registerWorkflow('tc.child', static function (WorkflowContext $context, string $vector = '') use ($observe): string {
    try {
        $context->sleep(3600);

        return 'work finished';
    } catch (WorkflowCancelled $error) {
        $observe($context, $vector, $error);

        return 'child cleaned up';
    }
});

// Redrive: the only activity attempt fails, so the run fails at that step,
// long before its work deadline.
$worker->registerWorkflow('tc.redrive', static function (WorkflowContext $context, string $vector = ''): string {
    return (string) $context->activity('tc.flaky', [], ['retry_policy' => ['max_attempts' => 1]]);
});
$worker->registerActivity('tc.flaky', static function (ActivityContext $context): string {
    throw new RuntimeException('business failure');
});

// No opt-in: workflow code must never run again after the hard deadline.
$worker->registerWorkflow('tc.plain', static function (WorkflowContext $context, string $vector = '') use ($observe): string {
    try {
        $context->sleep(3600);
    } catch (WorkflowCancelled $error) {
        $observe($context, $vector, $error);
    }

    return 'unexpected';
});

$worker->registerActivity('tc.observe', static function (ActivityContext $context, array $seen) use ($observations): bool {
    file_put_contents($observations, json_encode($seen + ['at' => gmdate('c')], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);

    return true;
});

$worker->run(pollTimeoutSeconds: 2);
