<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\ExternalGreetingWorkflow;

// Separate HTTP kernels and database connections exercise the request/claim
// race. The parent supplies only a disposable database and fixture identities.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config([
    'app.env' => 'testing',
    'app.key' => 'base64:dGVzdGluZy10ZXN0aW5nLXRlc3RpbmctdGVzdGluZzEyMzQ1Ng==',
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $argv[1],
    'database.connections.sqlite.busy_timeout' => 100,
    'database.connections.sqlite.journal_mode' => 'WAL',
    'database.connections.sqlite.transaction_mode' => 'IMMEDIATE',
    'cache.default' => 'array',
    'server.auth.driver' => 'none',
    'server.worker_protocol.version' => '1.20',
    'server.polling.timeout' => 0,
    'server.polling.interval_ms' => 1,
    'server.polling.cache_path' => sys_get_temp_dir().'/dw-cooperative-race-'.getmypid(),
    'workflows.v2.types.workflows' => [
        'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
    ],
]);
DB::purge('sqlite');
// Initialize the connection before releasing the request barrier. The race
// exercises the durable mutations rather than connection setup PRAGMAs.
DB::connection()->getPdo();
Queue::fake();
register_shutdown_function(static function (): void {
    (new Filesystem)->deleteDirectory(
        sys_get_temp_dir().'/dw-cooperative-race-'.getmypid(),
    );
});

fwrite(STDOUT, "ready\n");
if (trim((string) fgets(STDIN)) !== 'go') {
    exit(2);
}

$operation = $argv[2];
$path = $operation === 'request'
    ? '/api/workflows/'.$argv[3].'/request-cancellation'
    : '/api/worker/workflow-tasks/poll';
$body = $operation === 'request' ? [] : [
    'worker_id' => $argv[4], 'task_queue' => $argv[5], 'poll_request_id' => 'race-poll',
];
$attempts = [];
for ($attempt = 1; $attempt <= 3; $attempt++) {
    $request = Request::create($path, 'POST', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_NAMESPACE' => 'default',
        'HTTP_X_DURABLE_WORKFLOW_PROTOCOL_VERSION' => $argv[6],
        'HTTP_X_DURABLE_WORKFLOW_CONTROL_PLANE_VERSION' => '2',
    ], content: json_encode($body, JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    $responseBody = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    $attempts[] = ['status' => $response->getStatusCode(), 'reason' => $responseBody['reason'] ?? null];
    $kernel->terminate($request, $response);
    if ($response->getStatusCode() !== 503 || ($responseBody['reason'] ?? null) !== 'backend_lock_pressure' || $attempt === 3) {
        break;
    }
    // Follow the published pressure response without changing request or poll
    // identity. Every other failure remains visible to the parent assertion.
    usleep(max(1, (int) ($responseBody['retry_after_seconds'] ?? 1)) * 1000000);
}
fwrite(STDOUT, json_encode([
    'status' => $response->getStatusCode(),
    'body' => $responseBody,
    'attempts' => $attempts,
], JSON_THROW_ON_ERROR));
