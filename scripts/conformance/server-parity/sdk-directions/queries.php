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

// Included only by the ordinary published SDK fixture entry point.
if (! in_array($phase, $fixture['sdk_query_direction']['phase_order'], true)) {
    throw new InvalidArgumentException('Unknown SDK query phase');
}
$deadline = microtime(true) + (float) $remaining;
$worker = null;
$queryCompletions = 0;
$stack = HandlerStack::create();
$stack->push(static function (callable $handler) use (&$worker, &$queryCompletions): callable {
    return static function (RequestInterface $request, array $options) use ($handler, &$worker, &$queryCompletions) {
        return $handler($request, $options)->then(static function (ResponseInterface $response) use ($request, &$worker, &$queryCompletions): ResponseInterface {
            if ($response->getStatusCode() === 200 && $request->getMethod() === 'POST'
                && preg_match('#^/api/worker/query-tasks/[^/]+/complete$#', $request->getUri()->getPath())) {
                ++$queryCompletions;
                // Shutdown follows the actual fenced query completion receipt.
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
if (in_array($phase, ['signal-1', 'signal-2'], true)) {
    $result = $client->signalWorkflow($workflowId, 'payload', [$fixture['signal_value']]);
    echo json_encode($common + ['signal_sent' => true, 'sender_finished' => true, 'client_result' => $result],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
    return;
}
if (str_starts_with($phase, 'query-')) {
    $result = $client->queryWorkflow($workflowId, 'state', []);
    echo json_encode($common + ['query_sent' => true, 'client_finished' => true, 'client_result' => $result],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
    return;
}
$type = $fixture['workflow_type'];
$queue = $fixture['sdk_query_direction']['task_queue'];
$workerId = $workflowId.':'.$phase;
$worker = new Worker($client, $queue, workerId: $workerId,
    clock: static function () use ($deadline): float {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('SDK query fixture deadline expired');
        }

        return microtime(true);
    });
$workflowInvocations = $queryInvocations = 0;
$worker->registerWorkflow($type, static function (WorkflowContext $ctx, mixed $value, int $target) use (&$workflowInvocations, &$runId): mixed {
    ++$workflowInvocations;
    $runId = $ctx->runId;
    for ($index = 0; $index < $target; ++$index) {
        $ctx->waitCondition(fn (): bool => count($ctx->signals('payload')) > $index, 'payload:'.$index);
    }

    return $ctx->signals('payload')[$target - 1][0];
})->declareSignal($type, 'payload', static fn (array $value) => null)
    ->registerQuery($type, 'state', static function (QueryContext $ctx) use ($client, &$queryInvocations): array {
        ++$queryInvocations;
        $applied = $ctx->events('SignalApplied');

        return ['received' => count($applied), 'last' => $applied === [] ? null
            : $client->payloadCodec()->decodeEnvelope($applied[array_key_last($applied)]['payload']['value'])];
    });
$worker->run(1);
$common['run_id'] = $runId;
echo json_encode($common + ['worker_id' => $workerId, 'task_queue' => $queue,
    'workflow_invocations' => $workflowInvocations, 'activity_invocations' => 0,
    'query_invocations' => $queryInvocations, 'query_completions' => $queryCompletions,
    'worker_finished' => true], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
