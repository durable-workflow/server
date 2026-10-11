<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Transport\Psr18Transport;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\WorkflowContext;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require getenv('DW_PARITY_SDK_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
[$script, $phase, $url, $workflowId, $runId, $fixturePath, $remaining] = $argv;
if (! in_array($phase, ['original', 'activity', 'replacement'], true)) {
    throw new InvalidArgumentException('Unknown SDK direction phase');
}
$fixture = json_decode(file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
$deadline = microtime(true) + (float) $remaining;
$worker = null;
$stack = HandlerStack::create();
$stack->push(static function (callable $handler) use (&$worker, $phase): callable {
    return static function (RequestInterface $request, array $options) use ($handler, &$worker, $phase) {
        return $handler($request, $options)->then(static function (ResponseInterface $response) use ($request, &$worker, $phase): ResponseInterface {
            $kind = $phase === 'activity' ? 'activity' : 'workflow';
            if ($response->getStatusCode() === 200 && $request->getMethod() === 'POST'
                && preg_match('#^/api/worker/'.$kind.'-tasks/[^/]+/complete$#', $request->getUri()->getPath())) {
                // Stop through the public API after the real checkpoint's commit.
                // Preserve every HTTP byte and let the SDK finish its completion.
                $worker?->requestShutdown();
            }

            return $response;
        });
    };
});
$client = new Client($url, namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null,
    transport: new Psr18Transport(client: new GuzzleClient(['http_errors' => false, 'handler' => $stack])));
if ($phase === 'original') {
    $runId = $client->startWorkflow('parity.v1.one_activity', $workflowId, 'server-parity-sdk-workflow', [$fixture['input']])->runId;
}
$queue = $phase === 'activity' ? 'server-parity-sdk-activity' : 'server-parity-sdk-workflow';
$workerId = $workflowId.':'.$phase;
$worker = new Worker($client, $queue, workerId: $workerId, stickyCacheCapacity: 0,
    clock: static function () use ($deadline): float {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('SDK direction fixture deadline expired');
        }

        return microtime(true);
    });
$workflowInvocations = $activityInvocations = 0;
if ($phase === 'activity') {
    $worker->registerActivity('parity.v1.echo_activity', static function (ActivityContext $ctx, mixed $value) use (&$activityInvocations): mixed {
        ++$activityInvocations;

        return $value;
    });
} else {
    $worker->registerWorkflow('parity.v1.one_activity', static function (WorkflowContext $ctx, mixed $value) use (&$workflowInvocations): mixed {
        ++$workflowInvocations;

        return $ctx->activity('parity.v1.echo_activity', [$value], ['queue' => 'server-parity-sdk-activity']);
    });
}
$worker->run(1);
echo json_encode(['pid' => getmypid(), 'language' => 'php',
    'sdk_version' => ltrim(InstalledVersions::getPrettyVersion('durable-workflow/sdk'), 'v'),
    'phase' => $phase, 'run_id' => $runId, 'worker_id' => $workerId, 'task_queue' => $queue,
    'workflow_invocations' => $workflowInvocations, 'activity_invocations' => $activityInvocations,
    'worker_finished' => true], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
