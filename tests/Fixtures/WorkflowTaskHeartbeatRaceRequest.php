<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Support\WorkflowTaskOwnership;

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
    'server.worker_protocol.version' => '1.19',
    'server.polling.timeout' => 0,
    'server.polling.interval_ms' => 1,
    'server.polling.cache_path' => sys_get_temp_dir().'/dw-heartbeat-race-'.getmypid(),
    'workflows.v2.workflow_task_lease_seconds' => 1,
    'workflows.v2.types.workflows' => ['tests.external-greeting-workflow' => ExternalGreetingWorkflow::class],
]);
Carbon::setTestNow(Carbon::parse($argv[5]));
DB::purge('sqlite');
DB::connection()->getPdo();
Queue::fake();
register_shutdown_function(static function (): void {
    (new Filesystem)->deleteDirectory(sys_get_temp_dir().'/dw-heartbeat-race-'.getmypid());
});

if ($argv[2] === 'poll') {
    Carbon::setTestNow(Carbon::parse($argv[5])->addSeconds(2));
} else {
    $native = $app->make(WorkflowTaskBridge::class);
    // Add only an IPC barrier. Status and renewal execute the real published
    // Native methods against separate database connections in these kernels.
    $bridge = Mockery::mock(WorkflowTaskBridge::class);
    $bridge->shouldReceive('status')->andReturnUsing($native->status(...));
    $paused = false;
    $bridge->shouldReceive('heartbeat')->andReturnUsing(static function (string $taskId) use ($native, &$paused, $argv): array {
        if (! $paused) {
            $paused = true;
            Carbon::setTestNow(Carbon::parse($argv[5])->addSeconds(2));
            fwrite(STDOUT, "checked\n");
            if (trim((string) fgets(STDIN)) !== 'go') {
                exit(2);
            }
        }

        return $native->heartbeat($taskId);
    });
    $app->instance(WorkflowTaskBridge::class, $bridge);
    $app->instance(WorkflowTaskOwnership::class, new WorkflowTaskOwnership($bridge));
}

fwrite(STDOUT, "ready\n");
if (trim((string) fgets(STDIN)) !== 'go') {
    exit(2);
}
$path = $argv[2] === 'heartbeat'
    ? '/api/worker/workflow-tasks/'.$argv[3].'/heartbeat'
    : '/api/worker/workflow-tasks/poll';
$body = $argv[2] === 'heartbeat'
    ? ['lease_owner' => $argv[4], 'workflow_task_attempt' => (int) $argv[6]]
    : ['worker_id' => $argv[4], 'task_queue' => 'heartbeat-race', 'poll_request_id' => 'replacement-poll'];
$attempts = [];
for ($attempt = 1; $attempt <= 3; $attempt++) {
    $request = Request::create($path, 'POST', server: [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_NAMESPACE' => 'default', 'HTTP_X_DURABLE_WORKFLOW_PROTOCOL_VERSION' => '1.19',
    ], content: json_encode($body, JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    $responseBody = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    $attempts[] = ['status' => $response->getStatusCode(), 'reason' => $responseBody['reason'] ?? null];
    $kernel->terminate($request, $response);
    if ($response->getStatusCode() !== 503 || ($responseBody['reason'] ?? null) !== 'backend_lock_pressure' || $attempt === 3) {
        break;
    }
    usleep(max(1, (int) ($responseBody['retry_after_seconds'] ?? 1)) * 1000000);
}
fwrite(STDOUT, json_encode([
    'status' => $response->getStatusCode(), 'body' => $responseBody, 'attempts' => $attempts,
], JSON_THROW_ON_ERROR));
