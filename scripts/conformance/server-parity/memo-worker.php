<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Transport\Psr18Transport;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\WorkflowContext;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require getenv('DW_PARITY_SDK_AUTOLOAD');
[$script, $url, $phase, $workflowId, $runId, $fixturePath] = $argv;
$fixture = json_decode(file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
if (! in_array($phase, $fixture['memo_replay']['phase_order'], true)) {
    throw new InvalidArgumentException('Unknown memo phase');
}
$deadline = microtime(true) + $fixture['memo_replay']['phase_budget_seconds'];
$receipts = [];
$invocations = 0;
$worker = null;
$stack = HandlerStack::create();
$stack->push(static function (callable $handler) use (&$receipts, &$worker, $phase): callable {
    return static function (RequestInterface $request, array $options) use ($handler, &$receipts, &$worker, $phase) {
        return $handler($request, $options)->then(static function (ResponseInterface $response) use ($request, &$receipts, &$worker, $phase): ResponseInterface {
            $path = $request->getUri()->getPath();
            if (str_starts_with($path, '/api/worker/')) {
                $body = (string) $request->getBody();
                $input = $body === '' ? null : json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                $receipts[] = ['method' => $request->getMethod(), 'path' => $path, 'request' => $input,
                    'status' => $response->getStatusCode(),
                    'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
                $commands = array_column($input['commands'] ?? [], 'type');
                $finished = $phase === 'activity'
                    ? preg_match('#^/api/worker/activity-tasks/[^/]+/complete$#', $path)
                    : preg_match('#^/api/worker/workflow-tasks/[^/]+/complete$#', $path)
                        && in_array($phase === 'original' ? 'schedule_activity' : 'complete_workflow', $commands, true);
                if ($response->getStatusCode() === 200 && $finished) {
                    $worker?->requestShutdown();
                }
            }

            return $response;
        });
    };
});
$client = new Client($url, namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null,
    transport: new Psr18Transport(client: new HttpClient(['http_errors' => false, 'handler' => $stack,
        'connect_timeout' => 1, 'timeout' => 5])));
$queue = $workflowId.($phase === 'activity' ? '-activity' : '-workflow');
$worker = new Worker($client, $queue, workerId: $workflowId.':memo:'.$phase,
    clock: static function () use ($deadline): float {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Original 30-second memo phase observation expired');
        }

        return microtime(true);
    });
if ($phase === 'activity') {
    $worker->registerActivity('parity.v1.memo_echo', static function (ActivityContext $ctx, mixed $input) use (&$invocations): mixed {
        $invocations++;

        return $input;
    });
} else {
    $worker->registerWorkflow($fixture['workflow_type'], static function (WorkflowContext $ctx, mixed $input) use ($fixture, $workflowId): mixed {
        $spec = $fixture['memo_replay'];
        $ctx->upsertMemo($spec['updates'][0]);
        $value = $ctx->activity('parity.v1.memo_echo', [$input],
            $spec['activity_options'] + ['queue' => $workflowId.'-activity']);
        $ctx->upsertMemo($spec['updates'][1]);

        return $value;
    });
}
$worker->run(1);
$sources = [];
foreach ([Client::class => 'src/Client.php', Worker::class => 'src/Worker.php', WorkflowContext::class => 'src/Worker/WorkflowContext.php'] as $class => $path) {
    $sources[$path] = hash_file('sha256', (new ReflectionClass($class))->getFileName());
}
echo json_encode(['phase' => $phase, 'pid' => getmypid(), 'workflow_id' => $workflowId, 'run_id' => $runId,
    'sdk_version' => ltrim(InstalledVersions::getPrettyVersion('durable-workflow/sdk'), 'v'),
    'sdk_source' => InstalledVersions::getReference('durable-workflow/sdk'),
    'sdk_composer_lock_sha256' => hash_file('sha256', dirname(getenv('DW_PARITY_SDK_AUTOLOAD'), 2).'/composer.lock'),
    'sdk_loaded_sources' => $sources, 'definition_sha256' => hash_file('sha256', __FILE__),
    'activity_invocations' => $invocations, 'receipts' => $receipts, 'worker_finished' => true],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
