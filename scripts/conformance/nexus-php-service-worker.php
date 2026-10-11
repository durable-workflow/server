<?php

declare(strict_types=1);

require '/tmp/dw-php/vendor/autoload.php';

use DurableWorkflow\Client;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\WorkflowContext;

$input = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
error_log(json_encode([
    'process_id' => getmypid(),
    'installed_sdk_version' => Composer\InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
    'namespace' => 'shared',
    'workflow_type' => 'nexus.fixture.php.greeter',
], JSON_THROW_ON_ERROR));

$client = new Client('http://server:8080', namespace: 'shared', workerToken: $input['token']);
Worker::create($client, 'nexus-php-workflow')
    ->registerWorkflow('nexus.fixture.php.greeter', static function (WorkflowContext $context, string $name): array {
        return ['language' => 'php', 'name' => $name, 'greeting' => "hello, {$name}"];
    })
    ->run();
