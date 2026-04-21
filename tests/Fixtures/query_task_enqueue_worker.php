<?php

use App\Support\QueryTaskQueueFullException;
use App\Support\WorkflowQueryTaskBroker;
use Illuminate\Contracts\Console\Kernel;
use Workflow\V2\Models\WorkflowRun;

require __DIR__.'/../../vendor/autoload.php';

foreach ([
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:dGVzdGluZy10ZXN0aW5nLXRlc3RpbmctdGVzdGluZzEyMzQ1Ng==',
    'CACHE_STORE' => 'file',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DW_AUTH_DRIVER' => 'none',
    'DW_WORKER_POLL_TIMEOUT' => '0',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $cachePath, $barrierPath, $readyDir, $limit, $namespace, $taskQueue, $workerId] = $argv + array_fill(0, 8, null);

if (! is_string($cachePath) || ! is_string($barrierPath) || ! is_string($readyDir) || ! is_string($workerId)) {
    fwrite(STDERR, "Missing required query-task enqueue worker arguments.\n");
    exit(2);
}

config([
    'cache.default' => 'file',
    'server.polling.cache_path' => $cachePath,
    'server.query_tasks.max_pending_per_queue' => max(1, (int) $limit),
]);

@touch($readyDir.'/'.$workerId.'.ready');

$deadline = microtime(true) + 10;

while (! file_exists($barrierPath) && microtime(true) < $deadline) {
    usleep(1000);
}

if (! file_exists($barrierPath)) {
    echo json_encode([
        'status' => 'error',
        'type' => 'barrier_timeout',
        'message' => 'Timed out waiting for enqueue barrier.',
    ]).PHP_EOL;

    exit(2);
}

$run = new WorkflowRun;
$run->id = 'run-'.$workerId;
$run->workflow_instance_id = 'wf-'.$workerId;
$run->workflow_type = 'python.queryable';
$run->queue = $taskQueue ?: 'python-queries';
$run->payload_codec = 'json';

try {
    /** @var WorkflowQueryTaskBroker $broker */
    $broker = app(WorkflowQueryTaskBroker::class);
    $task = $broker->enqueue($namespace ?: 'default', $run, 'status', [
        'codec' => 'json',
        'blob' => '[]',
    ]);

    echo json_encode([
        'status' => 'enqueued',
        'query_task_id' => $task['query_task_id'],
    ]).PHP_EOL;

    exit(0);
} catch (QueryTaskQueueFullException $exception) {
    echo json_encode([
        'status' => 'full',
        'message' => $exception->getMessage(),
    ]).PHP_EOL;

    exit(0);
} catch (Throwable $exception) {
    echo json_encode([
        'status' => 'error',
        'type' => $exception::class,
        'message' => $exception->getMessage(),
    ]).PHP_EOL;

    exit(2);
}
