<?php

declare(strict_types=1);

require '/tmp/dw-php/vendor/autoload.php';

use DurableWorkflow\Client;
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
(new Worker($client, 'nexus-php-workflow', enableCooperativeCancellation: true))
    ->registerWorkflow('nexus.fixture.php.greeter', static function (WorkflowContext $context, string $name): array {
        if ($name === 'fail') {
            throw new NexusFixtureFailure('fixture service failure');
        }
        if ($name === 'deadline') {
            $context->sleep(3);
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
    })
    ->run();
