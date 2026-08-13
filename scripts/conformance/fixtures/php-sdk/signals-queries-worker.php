<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\QueryContext;
use DurableWorkflow\Worker\WorkflowContext;

if ($argc < 7) {
    fwrite(STDERR, "usage: signals-queries-worker.php <base-url> <token> <namespace> <task-queue> <worker-id> <evidence-path>\n");
    exit(2);
}

[$script, $baseUrl, $token, $namespace, $taskQueue, $workerId, $evidencePath] = $argv;
$workflowType = 'conformance.counter.php';
$client = new Client($baseUrl, namespace: $namespace, token: $token);
$worker = new Worker($client, $taskQueue, $workerId, heartbeatIntervalSeconds: 10);

$worker->registerWorkflow(
    $workflowType,
    static function (WorkflowContext $context): array {
        $context->sleep(3600);

        return ['signals' => count($context->signals('increment'))];
    },
);
$worker->declareSignal(
    $workflowType,
    'increment',
    static fn (int $amount): mixed => null,
);

$counterQuery = static function (QueryContext $context) use ($client, $evidencePath, $workerId, $taskQueue): int {
    $count = 0;
    foreach ($context->events('SignalReceived') as $event) {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        if (($payload['signal_name'] ?? null) !== 'increment') {
            continue;
        }
        $raw = $payload['value'] ?? $payload['input'] ?? $payload['arguments'] ?? null;
        $decoded = (is_array($raw) || is_string($raw))
            ? $client->payloadCodec()->decodeEnvelope($raw)
            : null;
        $arguments = is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded];
        $count += (int) ($arguments[0] ?? 0);
    }

    $task = $context->task;
    $record = [
        'schema' => 'durable-workflow.v2.signal-query-runtime.php-routed-query-task',
        'status' => 'pass',
        'worker_runtime' => 'sdk-php',
        'worker_id' => $workerId,
        'task_queue' => $taskQueue,
        'query_task_id' => $task['query_task_id'] ?? $task['task_id'] ?? null,
        'query_task_attempt' => $task['query_task_attempt'] ?? null,
        'workflow_id' => $context->workflowId,
        'run_id' => $context->runId,
        'workflow_type' => $task['workflow_type'] ?? null,
        'query_name' => $task['query_name'] ?? null,
        'lease_owner' => $task['lease_owner'] ?? null,
        'server_route' => 'worker_query_task_poll',
        'completion_route' => 'worker_query_task_complete',
        'observed_via' => 'durable-workflow/sdk Worker query handler',
        'observed_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    file_put_contents($evidencePath, json_encode($record, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);

    return $count;
};

$worker->registerQuery($workflowType, 'state', $counterQuery);
$worker->registerQuery($workflowType, 'current', $counterQuery);

fwrite(STDOUT, json_encode([
    'event' => 'worker_starting',
    'worker_id' => $workerId,
    'task_queue' => $taskQueue,
    'workflow_type' => $workflowType,
    'sdk_version' => InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
    'process_id' => getmypid(),
], JSON_UNESCAPED_SLASHES).PHP_EOL);

$worker->run(1);
