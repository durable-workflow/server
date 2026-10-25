<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Tests\Fixtures\ExternalGreetingWorkflow;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$kernel->bootstrap();
config(['workflows.v2.types.workflows' => ['tests.external-greeting-workflow' => ExternalGreetingWorkflow::class]]);
fwrite(STDOUT, "READY\n");
touch($input['ready']);
$deadline = microtime(true) + 10;
while (! is_file($input['barrier'])) {
    clearstatcache(true, $input['barrier']);
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Concurrent acceptance barrier timed out.');
    }
    usleep(1000);
}
$input['body'] = json_decode(file_get_contents($input['body_file']), true, flags: JSON_THROW_ON_ERROR);
$request = Request::create($input['path'], 'POST', [], [], [], [
    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    'HTTP_X_NAMESPACE' => 'default',
    'HTTP_X_DURABLE_WORKFLOW_PROTOCOL_VERSION' => '1.20',
    'HTTP_X_DURABLE_WORKFLOW_TASK_DELIVERY' => 'offer-accept.v1',
], json_encode($input['body'], JSON_THROW_ON_ERROR));
$response = $kernel->handle($request);
fwrite(STDOUT, json_encode(['status' => $response->getStatusCode(),
    'body' => json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR)."\n");
$kernel->terminate($request, $response);
