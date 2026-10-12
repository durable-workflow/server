<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Transport\Psr18Transport;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\QueryContext;
use DurableWorkflow\Worker\WorkflowContext;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

if (! in_array($phase, $fixture['sdk_update_direction']['phase_order'], true)) {
    throw new InvalidArgumentException('Unknown SDK update phase');
}
$deadline = microtime(true) + (float) $remaining;
$worker = null;
$completions = 0;
$stack = HandlerStack::create();
$stack->push(static function (callable $handler) use (&$worker, &$completions): callable {
    return static function (RequestInterface $request, array $options) use ($handler, &$worker, &$completions) {
        return $handler($request, $options)->then(static function (ResponseInterface $response) use ($request, &$worker, &$completions): ResponseInterface {
            if ($response->getStatusCode() === 200 && $request->getMethod() === 'POST'
                && preg_match('#^/api/worker/workflow-tasks/[^/]+/complete$#', $request->getUri()->getPath())) {
                ++$completions;
                $worker?->requestShutdown();
            }

            return $response;
        });
    };
});
$client = new Client($url, namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null,
    transport: new Psr18Transport(client: new GuzzleClient(['http_errors' => false, 'handler' => $stack])));
$common = ['pid' => getmypid(), 'language' => 'php',
    'sdk_version' => ltrim(InstalledVersions::getPrettyVersion('durable-workflow/sdk'), 'v'),
    'phase' => $phase, 'workflow_id' => $workflowId, 'run_id' => $runId];
if (preg_match('/^(update|duplicate-pending|duplicate-completed)-([12])$/', $phase, $match)) {
    $index = (int) $match[2] - 1;
    $value = $match[1] === 'update' ? $fixture['update_values'][$index] : $fixture['duplicate_update_value'];
    $requestId = $workflowId.':update:'.$index;
    $result = $client->updateWorkflow($workflowId, 'set_payload', [$value],
        waitFor: 'completed', requestId: $requestId);
    echo json_encode($common + ['update_sent' => true, 'client_finished' => true,
        'request_id' => $requestId, 'client_result' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
    return;
}
if ($phase === 'signal') {
    $result = $client->signalWorkflow($workflowId, 'payload', [$fixture['signal_value']]);
    echo json_encode($common + ['signal_sent' => true, 'sender_finished' => true, 'client_result' => $result],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
    return;
}
$type = $fixture['workflow_type'];
$queue = $fixture['sdk_update_direction']['task_queue'];
$workerId = $workflowId.':'.$phase;
$worker = new Worker($client, $queue, workerId: $workerId,
    clock: static function () use ($deadline): float {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('SDK update fixture deadline expired');
        }

        return microtime(true);
    });
$workflowInvocations = $updateInvocations = 0;
$worker->registerWorkflow($type, static function (WorkflowContext $ctx, mixed $value, int $target) use (&$workflowInvocations, &$runId): array {
    ++$workflowInvocations;
    $runId = $ctx->runId;
    $ctx->waitCondition(fn (): bool => $ctx->signals('payload') !== [], 'finish:0');

    return ['values' => array_map(static fn (array $arguments): mixed => $arguments[0], $ctx->updates('set_payload'))];
})->declareSignal($type, 'payload', static fn (array $value) => null)
    ->registerUpdate($type, 'set_payload', static function (QueryContext $ctx, array $value) use (&$updateInvocations): array {
        ++$updateInvocations;

        return ['value' => $value, 'applied' => count($ctx->events('UpdateApplied')) + 1];
    });
$worker->run(1);
$common['run_id'] = $runId;
echo json_encode($common + ['worker_id' => $workerId, 'task_queue' => $queue,
    'workflow_invocations' => $workflowInvocations, 'activity_invocations' => 0,
    'update_invocations' => $updateInvocations, 'workflow_completions' => $completions,
    'worker_finished' => true], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
