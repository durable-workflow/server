<?php

declare(strict_types=1);

require '/opt/php/vendor/autoload.php';

use DurableWorkflow\Client;
use DurableWorkflow\Transport\Psr18Transport;
use DurableWorkflow\Transport\Transport;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\WorkflowContext;

const WORKFLOW_TYPE = 'conformance.rust-build-cohort';

function client(?Transport $transport = null): Client
{
    return new Client(getenv('DW_WV_SERVER_URL'), namespace: getenv('DW_WV_NAMESPACE'),
        token: getenv('DW_WV_AUTH_TOKEN') ?: 'dev-token', transport: $transport);
}

if ($argv[1] === '--client') {
    $request = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $handle = client()->startWorkflow(WORKFLOW_TYPE, $request['workflow_id'], $request['task_queue'], [$request['workflow_id']]);
    echo json_encode(['workflow_id' => $handle->workflowId, 'run_id' => $handle->selectedRunId], JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

[, , $queue, $workerId, $build, $producer, $tracePath] = $argv;
function trace(array $record): void
{
    global $tracePath;
    file_put_contents($tracePath, json_encode(['observed_at' => microtime(true), ...$record], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
}

$transport = new class implements Transport {
    private Psr18Transport $sdk;

    public function __construct() { $this->sdk = new Psr18Transport(); }

    public function send(string $method, string $uri, array $headers, ?array $body = null): ?array
    {
        try {
            if (str_contains($uri, 'workflow-tasks/poll')) {
                global $tracePath;
                $gate = $tracePath.'.enabled';
                clearstatcache(true, $gate);
                if (!file_exists($gate)) {
                    trace(['kind' => 'paused']);
                    while (!file_exists($gate)) {
                        usleep(20000);
                        clearstatcache(true, $gate);
                    }
                }
            }
            $response = $this->sdk->send($method, $uri, $headers, $body);
            if (str_contains($uri, 'workflow-tasks/poll')) {
                trace(['kind' => 'poll', 'task' => $response['task'] ?? null]);
            }
            return $response;
        } catch (Throwable $error) {
            trace(['kind' => 'error', 'error' => $error->getMessage()]);
            throw $error;
        }
    }
};
$worker = new Worker(client($transport), $queue, workerId: $workerId, buildId: $build,
    diagnosticListener: static function (string $event, array $details) use ($workerId): void {
        if ($event === 'worker.registered') {
            echo json_encode(['registered' => true, 'pid' => getmypid(), 'worker_id' => $workerId,
                'sdk_version' => Composer\InstalledVersions::getPrettyVersion('durable-workflow/sdk')], JSON_THROW_ON_ERROR), "\n";
            fflush(STDOUT);
        }
        if ($event === 'worker.failed') { trace(['kind' => 'error', 'error' => (string) ($details['exception'] ?? $event)]); }
    });
$worker->registerWorkflow(WORKFLOW_TYPE, static function (WorkflowContext $context, string $workflowId) use ($producer): array {
    $recorded = $context->sideEffect(static function () use ($workflowId, $producer): string {
        trace(['kind' => 'producer', 'workflow_id' => $workflowId, 'producer' => $producer]);
        return $producer;
    });
    trace(['kind' => 'callback', 'workflow_id' => $context->workflowId, 'run_id' => $context->runId, 'recorded' => $recorded]);
    $context->waitCondition(static fn (): bool => $context->signals('finish') !== [], key: 'mixed-build-finish');
    return ['producer' => $recorded];
});
$worker->declareSignal(WORKFLOW_TYPE, 'finish');
$worker->run(1);
