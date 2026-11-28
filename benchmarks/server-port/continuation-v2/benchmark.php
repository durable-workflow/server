<?php
declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\WorkflowContext;

require __DIR__.'/vendor/autoload.php';

$client = new Client('http://server:8080', token: 'fixture-token', workerProtocolVersion: '1.20');
$queue = 'continuation-benchmark';
$workflowType = 'bench.five_steps';
$activityType = 'bench.echo';
$payload = str_repeat('x', 1024);
[$mode, $identifier, $option] = array_slice($argv, 1, 3);

if ($mode === 'worker') {
    $worker = new Worker($client, $queue, workerId: $identifier, buildId: 'php-3.33.0',
        diagnosticListener: static function (string $event): void {
            if ($event === 'worker.registered') {
                echo "registered\n";
            }
        },
        enableActivityContinuation: $option === 'on');
    $worker->registerWorkflow($workflowType, static function (WorkflowContext $context, string $input) use ($activityType): string {
        for ($step = 0; $step < 5; ++$step) {
            $input = $context->activity($activityType, [$input]);
        }

        return $input;
    });
    $worker->registerActivity($activityType, static function (ActivityContext $context, string $input): string {
        usleep(20_000);

        return $input;
    });
    $worker->run(5);
    exit;
}

if (!in_array($mode, ['batch', 'sequential'], true)) {
    throw new InvalidArgumentException('Unknown benchmark workload.');
}
for ($index = 1; $index <= (int) $option; ++$index) {
    $workflowId = "$identifier-$index";
    $client->startWorkflow($workflowType, $workflowId, $queue, [$payload]);
    if ($mode === 'sequential' && $client->workflowResult($workflowId, timeoutSeconds: 40) !== $payload) {
        throw new RuntimeException("Unexpected result for $workflowId.");
    }
}
if ($mode === 'batch' && $client->workflowResult("$identifier-$option", timeoutSeconds: 150) !== $payload) {
    throw new RuntimeException('Unexpected batch result.');
}
