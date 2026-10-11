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
$fixture = json_decode(file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
$childDirection = isset($fixture['sdk_child_direction']);
$timerAuthor = isset($fixture['sdk_timer_author']);
$signalDirection = isset($fixture['sdk_signal_direction']);
if (! in_array($phase, $signalDirection ? ['original', 'signal-1', 'resumed', 'signal-2', 'replacement'] : ($timerAuthor ? ['original', 'replacement'] : ($childDirection ? ['original', 'child', 'replacement'] : ['original', 'activity', 'replacement'])), true)) {
    throw new InvalidArgumentException('Unknown SDK direction phase');
}
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
if ($signalDirection && in_array($phase, ['signal-1', 'signal-2'], true)) {
    $result = $client->signalWorkflow($workflowId, 'payload', [$fixture['signal_value']]);
    echo json_encode(['pid' => getmypid(), 'language' => 'php',
        'sdk_version' => ltrim(InstalledVersions::getPrettyVersion('durable-workflow/sdk'), 'v'),
        'phase' => $phase, 'workflow_id' => $workflowId, 'run_id' => $runId,
        'signal_sent' => true, 'sender_finished' => true, 'client_result' => $result],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
    exit(0);
}
if ($phase === 'original' && ! $signalDirection) {
    $runId = $client->startWorkflow($fixture['workflow_type'], $workflowId,
        $timerAuthor ? 'server-parity-sdk-timer' : ($childDirection ? 'server-parity-sdk-parent' : 'server-parity-sdk-workflow'), [$fixture['input']])->selectedRunId;
}
$queue = $signalDirection ? 'server-parity-sdk-signals' : ($timerAuthor ? 'server-parity-sdk-timer' : ($childDirection
    ? ($phase === 'child' ? 'server-parity-sdk-child' : 'server-parity-sdk-parent')
    : ($phase === 'activity' ? 'server-parity-sdk-activity' : 'server-parity-sdk-workflow')));
$workerId = $workflowId.':'.$phase;
$worker = new Worker($client, $queue, workerId: $workerId,
    clock: static function () use ($deadline): float {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('SDK direction fixture deadline expired');
        }

        return microtime(true);
    });
$workflowInvocations = $activityInvocations = 0;
if ($signalDirection) {
    $worker->registerWorkflow('parity.v1.sdk_signals', static function (WorkflowContext $ctx, mixed $value, int $target) use (&$workflowInvocations, &$runId): mixed {
        ++$workflowInvocations;
        $runId = $ctx->runId;
        for ($index = 0; $index < $target; ++$index) {
            $ctx->waitCondition(fn (): bool => count($ctx->signals('payload')) > $index, 'payload:'.$index);
        }

        return $ctx->signals('payload')[$target - 1][0];
    })->declareSignal('parity.v1.sdk_signals', 'payload', static fn (array $value) => null);
} elseif ($timerAuthor) {
    $worker->registerWorkflow('parity.v1.sdk_timer', static function (WorkflowContext $ctx, mixed $value) use (&$workflowInvocations): mixed {
        ++$workflowInvocations;
        $ctx->sleep($value['sleep_ms'] / 1000);

        return $value;
    });
} elseif ($childDirection && $phase === 'child') {
    $worker->registerWorkflow('parity.v1.echo_child', static function (WorkflowContext $ctx, mixed $value) use (&$workflowInvocations): mixed {
        ++$workflowInvocations;

        return $value;
    });
} elseif ($childDirection) {
    $worker->registerWorkflow('parity.v1.one_child', static function (WorkflowContext $ctx, mixed $value) use (&$workflowInvocations): array {
        ++$workflowInvocations;
        $results = [];
        foreach ($value['payloads'] as $payload) {
            $results[] = $ctx->childWorkflow('parity.v1.echo_child', [$payload], ['queue' => 'server-parity-sdk-child']);
        }

        return ['child_results' => $results];
    });
} elseif ($phase === 'activity') {
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
