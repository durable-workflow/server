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
$spec = $fixture['continuation'];
if (! in_array($phase, $spec['phase_order'], true)) {
    throw new InvalidArgumentException('Unknown continuation phase');
}
$deadline = microtime(true) + $spec['phase_budget_seconds'];
$receipts = [];
$invocations = 0;
$worker = null;
$terminal = match ($phase) {
    'original' => 'schedule_activity',
    'continuation' => 'continue_as_new',
    'successor' => 'complete_workflow',
    'activity' => null,
};
$stack = HandlerStack::create();
$stack->push(static function (callable $handler) use (&$receipts, &$worker, $terminal): callable {
    return static function (RequestInterface $request, array $options) use ($handler, &$receipts, &$worker, $terminal) {
        return $handler($request, $options)->then(static function (ResponseInterface $response) use ($request, &$receipts, &$worker, $terminal): ResponseInterface {
            $path = $request->getUri()->getPath();
            if (str_starts_with($path, '/api/worker/')) {
                $body = (string) $request->getBody();
                $input = $body === '' ? null : json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                $receipts[] = ['method' => $request->getMethod(), 'path' => $path, 'request' => $input,
                    'status' => $response->getStatusCode(),
                    'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
                $done = $terminal === null
                    ? preg_match('#^/api/worker/activity-tasks/[^/]+/complete$#', $path)
                    : preg_match('#^/api/worker/workflow-tasks/[^/]+/complete$#', $path)
                        && in_array($terminal, array_column($input['commands'] ?? [], 'type'), true);
                if ($done && $response->getStatusCode() === 200) {
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
$worker = new Worker($client, $workflowId.($phase === 'activity' ? '-activity' : '-workflow'),
    workerId: $workflowId.':continue:'.$phase, clock: static function () use ($deadline): float {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Original continuation phase deadline expired');
        }

        return microtime(true);
    });
if ($phase === 'activity') {
    $worker->registerActivity('parity.v1.continue_echo', static function (ActivityContext $ctx, mixed $value) use (&$invocations): mixed {
        $invocations++;

        return $value;
    });
} else {
    $worker->registerWorkflow($fixture['workflow_type'], static function (WorkflowContext $ctx, array $input) use ($spec, $workflowId, $runId): mixed {
        if ($ctx->runId !== $runId || $ctx->workflowId !== $workflowId) {
            throw new RuntimeException('Unexpected original continuation identity');
        }
        if ($input['generation'] === 0) {
            $value = $ctx->activity('parity.v1.continue_echo', [$input['value']],
                $spec['activity_options'] + ['queue' => $workflowId.'-activity']);
            if (isset($spec['memo_before_continuation'])) {
                $ctx->upsertMemo($spec['memo_before_continuation']);
            }
            $ctx->continueAsNew([['generation' => 1, 'value' => $value]]);
        }
        if ($input['generation'] !== 1) {
            throw new RuntimeException('Unexpected continuation generation');
        }

        return $input['value'];
    });
}
$worker->run(1);
$loaded = [];
foreach ([Client::class => 'src/Client.php', Worker::class => 'src/Worker.php', WorkflowContext::class => 'src/Worker/WorkflowContext.php'] as $class => $path) {
    $loaded[$path] = hash_file('sha256', (new ReflectionClass($class))->getFileName());
}
echo json_encode(['phase' => $phase, 'pid' => getmypid(), 'workflow_id' => $workflowId, 'run_id' => $runId,
    'sdk_version' => ltrim(InstalledVersions::getPrettyVersion('durable-workflow/sdk'), 'v'),
    'sdk_source' => InstalledVersions::getReference('durable-workflow/sdk'),
    'sdk_composer_lock_sha256' => hash_file('sha256', dirname(getenv('DW_PARITY_SDK_AUTOLOAD'), 2).'/composer.lock'),
    'sdk_loaded_sources' => $loaded, 'definition_sha256' => hash_file('sha256', __FILE__),
    'activity_invocations' => $invocations, 'receipts' => $receipts, 'worker_finished' => true],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
