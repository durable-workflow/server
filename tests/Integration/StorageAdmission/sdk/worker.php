<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\WorkflowContext;

require __DIR__.'/vendor/autoload.php';

$worker = new Worker(new Client('http://server:8080', token: 'storage-fixture'),
    'storage-php', workerId: 'storage-php', heartbeatIntervalSeconds: 5);
$worker->registerWorkflow('storage.php', static function (WorkflowContext $context): array {
    $value = $context->activity('storage.php.echo', [], ['start_to_close_timeout' => 180]);

    return ['runtime' => 'php', 'bytes' => strlen($value), 'sha256' => hash('sha256', $value)];
});
$worker->registerActivity('storage.php.echo', static function (ActivityContext $context): string {
    $value = 'php:'.str_repeat('x', 262144);
    file_put_contents('/observation/php.effects', "executed\n", FILE_APPEND | LOCK_EX);
    $deadline = microtime(true) + 120;
    while (! is_file('/observation/release-activities')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Fixture release barrier timed out.');
        }
        usleep(100000);
    }
    file_put_contents('/observation/php.returned', 'ready');

    return $value;
});
$worker->run(1);
