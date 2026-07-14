<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;
use Throwable;

if ($argc < 9) {
    fwrite(STDERR, "usage: signals-queries-client.php <operation> <base-url> <token> <namespace> <workflow-type> <workflow-id> <task-queue> <name> [args-json]\n");
    exit(2);
}

[$script, $operation, $baseUrl, $token, $namespace, $workflowType, $workflowId, $taskQueue, $name] = $argv;
$decoded = json_decode($argv[9] ?? '[]', true);
$arguments = is_array($decoded) && array_is_list($decoded) ? $decoded : [];
$client = new Client($baseUrl, namespace: $namespace, token: $token);
$sample = [
    'client' => 'sdk-php',
    'operation' => $operation,
    'operation_name' => $name,
    'workflow_id' => $workflowId,
    'workflow_type' => $workflowType,
    'sdk_version' => InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
    'process_id' => getmypid(),
];

try {
    $sample['result'] = match ($operation) {
        'start' => (static function () use ($client, $workflowType, $workflowId, $taskQueue): array {
            $handle = $client->startWorkflow($workflowType, $workflowId, $taskQueue);

            return [
                'workflow_id' => $handle->workflowId,
                'run_id' => $handle->selectedRunId,
                'workflow_type' => $handle->workflowType,
            ];
        })(),
        'signal' => $client->signalWorkflow($workflowId, $name, $arguments),
        'query' => $client->queryWorkflow($workflowId, $name, $arguments),
        default => throw new InvalidArgumentException(sprintf('unsupported operation [%s]', $operation)),
    };
    $sample['ok'] = true;
    echo json_encode($sample, JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
} catch (ServerException $exception) {
    $sample['ok'] = false;
    $sample['exception'] = $exception::class;
    $sample['status_code'] = $exception->status;
    $sample['body'] = $exception->details;
    $sample['reason'] = $exception->reason;
    echo json_encode($sample, JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
} catch (Throwable $throwable) {
    $sample['ok'] = false;
    $sample['exception'] = $throwable::class;
    $sample['message'] = $throwable->getMessage();
    echo json_encode($sample, JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
}
