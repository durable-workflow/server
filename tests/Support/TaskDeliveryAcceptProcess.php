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
$body = file_get_contents($input['body_file']);
$attempts = [];
for ($attempt = 0; $attempt < 3; $attempt++) {
    $request = Request::create($input['path'], 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_NAMESPACE' => 'default',
        'HTTP_X_DURABLE_WORKFLOW_PROTOCOL_VERSION' => '1.20',
        'HTTP_X_DURABLE_WORKFLOW_TASK_DELIVERY' => 'offer-accept.v1',
    ], $body);
    $response = $kernel->handle($request);
    $result = ['status' => $response->getStatusCode(),
        'body' => json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)];
    $kernel->terminate($request, $response);
    $attempts[] = ['status' => $result['status'], 'reason' => $result['body']['reason'] ?? null,
        'task' => $result['body']['task']['task_id'] ?? null];
    if ($result['status'] !== 503 || ($result['body']['reason'] ?? null) !== 'backend_lock_pressure' || $attempt === 2) {
        break;
    }
    // This is the normal typed retry contract, with identical request bytes.
    // The offer's original two-second deadline is never extended.
    sleep(max(1, (int) ($result['body']['retry_after_seconds'] ?? 1)));
}
$result['attempts'] = $attempts;
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR)."\n");
