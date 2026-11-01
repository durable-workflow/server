<?php

declare(strict_types=1);

require '/tmp/dw-php/vendor/autoload.php';

use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;
use DurableWorkflow\Exception\WorkflowCancelled;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\WorkflowContext;

final class NexusFixtureFailure extends RuntimeException
{
}

$input = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
error_log(json_encode([
    'process_id' => getmypid(),
    'installed_sdk_version' => Composer\InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
    'namespace' => 'shared',
    'workflow_type' => 'nexus.fixture.php.greeter',
    'worker_protocol' => '1.20',
    'cooperative_cancellation' => true,
    'pcntl_available' => function_exists('pcntl_fork'),
    'posix_available' => function_exists('posix_kill'),
], JSON_THROW_ON_ERROR));

$client = new Client('http://server:8080', namespace: 'shared', token: $input['token'], workerProtocolVersion: '1.20');
$worker = (new Worker($client, 'nexus-php-workflow',
    diagnosticListener: static function (string $event, array $context): void {
        $fields = array_intersect_key($context, array_flip([
            'worker_id', 'task_queue', 'task_id', 'operation', 'kind', 'reason', 'terminal_reason',
            'status', 'poll_status', 'poll_request_id', 'attempt', 'retry_after_seconds',
        ]));
        $fields = array_filter($fields, static fn (mixed $value): bool => is_scalar($value) || $value === null);
        error_log(json_encode(['event' => $event, 'context' => $fields], JSON_THROW_ON_ERROR));
    }, enableCooperativeCancellation: true))
    ->registerWorkflow('nexus.fixture.php.greeter', static function (WorkflowContext $context, string $name): array {
        if (str_starts_with($name, 'nexus-external:')) {
            $context->sleep(2);

            return ['language' => 'php', 'name' => $name, 'greeting' => "hello, {$name}",
                'bigint' => PHP_INT_MAX, 'nullable' => null, 'unicode' => '雪☃'];
        }
        if ($name === 'fail') {
            throw new NexusFixtureFailure('fixture service failure');
        }
        if ($name === 'deadline') {
            $context->sleep(3);
        }
        if ($name === 'recover') {
            $context->sleep(8);
        }
        if ($name === 'cancel') {
            try {
                $context->sleep(300);
            } catch (WorkflowCancelled $cancelled) {
                error_log(json_encode(['scenario' => 'service_cancellation_observed',
                    'request_id' => $cancelled->requestId, 'type' => $cancelled::class], JSON_THROW_ON_ERROR));
                throw $cancelled;
            }
        }
        return ['language' => 'php', 'name' => $name, 'greeting' => "hello, {$name}"];
    });
try {
    $worker->run();
} catch (Throwable $failure) {
    error_log(json_encode([
        'event' => 'service_worker_failed', 'exception_type' => $failure::class,
        'status' => $failure instanceof ServerException ? $failure->status : null,
        'reason' => $failure instanceof ServerException ? $failure->reason : null,
        'message' => substr($failure->getMessage(), 0, 500),
        'client_calls' => array_slice(array_values(array_map(
            static fn (array $frame): string => $frame['function'],
            array_filter($failure->getTrace(), static fn (array $frame): bool => ($frame['class'] ?? null) === Client::class),
        )), 0, 6),
    ], JSON_THROW_ON_ERROR));
    throw $failure;
}
