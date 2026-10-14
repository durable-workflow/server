<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Transport\Psr18Transport;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require getenv('DW_PARITY_SDK_AUTOLOAD');
[$script, $url, $workflowId, $phase] = $argv;
$worker = null;
$completions = 0;
$fetches = 0;
$stack = HandlerStack::create();
$stack->push(static function (callable $handler) use (&$worker, &$completions, &$fetches): callable {
    return static function (RequestInterface $request, array $options) use ($handler, &$worker, &$completions, &$fetches) {
        return $handler($request, $options)->then(static function (ResponseInterface $response) use ($request, &$worker, &$completions, &$fetches): ResponseInterface {
            $path = $request->getUri()->getPath();
            if ($request->getMethod() === 'GET' && str_starts_with($path, '/api/external-payloads/v1/')) {
                $fetches++;
            }
            if ($response->getStatusCode() === 200 && preg_match('#^/api/worker/activity-tasks/[^/]+/complete$#', $path)) {
                $completions++;
                $worker?->requestShutdown();
            }

            return $response;
        });
    };
});
$http = new HttpClient(['base_uri' => rtrim($url, '/'), 'http_errors' => false, 'handler' => $stack,
    'connect_timeout' => 1, 'timeout' => 5]);
$client = new Client($url, token: 'external-fixture-token', transport: new Psr18Transport(client: $http));
$control = static function (string $method, string $path, array $body = []) use ($http): array {
    $response = $http->request($method, $path, ['headers' => ['Authorization' => 'Bearer external-fixture-token',
        'X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2'], 'json' => $body]);

    return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
};
$text = str_repeat('x', 3 * 1024 * 1024);
$expected = ['text_bytes' => strlen($text), 'text_sha256' => hash('sha256', $text),
    'number' => 9007199254740993, 'optional' => null, 'unicode' => 'rangeλ'];
if ($phase === 'author') {
    if ($control('GET', '/api/namespaces/default')[0] === 404
        && $control('POST', '/api/namespaces', ['name' => 'default'])[0] !== 201) {
        throw new RuntimeException('Namespace setup failed');
    }
    if ($control('PUT', '/api/namespaces/default/external-storage', ['driver' => 'local', 'enabled' => true,
        'threshold_bytes' => 16, 'config' => ['uri' => 'file:///state/objects']])[0] !== 200) {
        throw new RuntimeException('Local policy setup failed');
    }
    $started = microtime(true);
    $blob = $client->payloadCodec()->encode([$text, 9007199254740993, null, 'rangeλ']);
    $upload = $http->post('/api/external-payloads/v1', ['headers' => ['Authorization' => 'Bearer external-fixture-token',
        'X-Namespace' => 'default', 'Content-Type' => 'application/octet-stream',
        'X-Durable-Workflow-Payload-Codec' => 'avro', 'X-Durable-Workflow-Payload-Size' => (string) strlen($blob),
        'X-Durable-Workflow-Payload-SHA256' => hash('sha256', $blob)], 'body' => $blob]);
    if ($upload->getStatusCode() !== 201) {
        throw new RuntimeException('Original argument upload failed: '.$upload->getStatusCode());
    }
    $reference = json_decode((string) $upload->getBody(), true, flags: JSON_THROW_ON_ERROR)['reference'];
    [$status, $original] = $control('POST', '/api/workflows', ['workflow_id' => $workflowId,
        'workflow_type' => 'parity.v1.external_arguments', 'task_queue' => $workflowId,
        'input' => $client->payloadCodec()->envelope([]), 'run_timeout_seconds' => 120,
        'execution_timeout_seconds' => 600]);
    if ($status !== 201) {
        throw new RuntimeException('Original workflow admission failed: '.$status);
    }
    $client->registerWorker($workflowId.':author', $workflowId, ['parity.v1.external_arguments'], [], capabilities: []);
    $task = $client->pollWorkflowTask($workflowId.':author', $workflowId, 0);
    if ($task === null || $task['run_id'] !== $original['run_id']) {
        throw new RuntimeException('Original workflow task was not delivered');
    }
    $accepted = $client->completeWorkflowTask($task['task_id'], $task['lease_owner'], $task['workflow_task_attempt'], [[
        'type' => 'schedule_activity', 'activity_type' => 'parity.v1.external_arguments_activity',
        'arguments' => ['codec' => 'avro', 'external_payload' => $reference],
    ]]);
    if (($accepted['recorded'] ?? null) !== true || microtime(true) >= $started + 30) {
        throw new RuntimeException('Original external activity arguments were not admitted');
    }
    echo json_encode(['outcome' => 'pass', 'phase' => $phase, 'run_id' => $original['run_id'],
        'reference' => $reference, 'started_at' => $started, 'original_deadline' => $started + 30,
        'encoded_bytes' => strlen($blob), 'encoded_sha256' => hash('sha256', $blob)], JSON_THROW_ON_ERROR)."\n";
} elseif ($phase === 'activity') {
    $author = json_decode(file_get_contents($argv[4]), true, flags: JSON_THROW_ON_ERROR);
    $invocations = 0;
    $worker = new Worker($client, $workflowId, workerId: $workflowId.':activity', clock: static function () use ($author): float {
        if (microtime(true) >= $author['original_deadline']) {
            throw new RuntimeException('Original 30-second activity observation expired');
        }

        return microtime(true);
    });
    $worker->registerActivity('parity.v1.external_arguments_activity', static function (ActivityContext $context, mixed $text, mixed $number, mixed $optional, mixed $unicode) use (&$invocations, $expected): array {
        $invocations++;
        $actual = ['text_bytes' => strlen($text), 'text_sha256' => hash('sha256', $text),
            'number' => $number, 'optional' => $optional, 'unicode' => $unicode];
        if ($actual !== $expected) {
            throw new RuntimeException('Installed activity callback received different arguments');
        }

        return $actual;
    });
    $worker->run(1);
    if ($invocations !== 1 || $completions !== 1 || $fetches < 1 || microtime(true) >= $author['original_deadline']) {
        throw new RuntimeException('Actual installed activity did not complete its original');
    }
    echo json_encode(['outcome' => 'pass', 'phase' => $phase, 'run_id' => $author['run_id'],
        'sdk_version' => InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
        'sdk_source' => InstalledVersions::getReference('durable-workflow/sdk'),
        'activity_invocations' => $invocations, 'activity_completions' => $completions,
        'sdk_payload_gets' => $fetches, 'result' => $expected], JSON_THROW_ON_ERROR)."\n";
} elseif ($phase === 'reader') {
    $author = json_decode(file_get_contents($argv[4]), true, flags: JSON_THROW_ON_ERROR);
    $task = $client->pollWorkflowTask($workflowId.':author', $workflowId, 0);
    if ($task === null || $task['run_id'] !== $author['run_id']) {
        throw new RuntimeException('Cold original workflow task was not delivered');
    }
    $completed = array_values(array_filter($task['history_events'],
        static fn (array $event): bool => $event['event_type'] === 'ActivityCompleted'));
    if (count($completed) !== 1 || $client->payloadCodec()->decodeEnvelope($completed[0]['payload']['result']) !== $expected) {
        throw new RuntimeException('Cold installed workflow reader received a different activity result');
    }
    $client->completeWorkflowTask($task['task_id'], $task['lease_owner'], $task['workflow_task_attempt'], [
        ['type' => 'complete_workflow', 'result' => $client->payloadCodec()->envelope($expected)],
    ]);
    $path = '/api/workflows/'.$workflowId.'/runs/'.$author['run_id'];
    [$status, $before] = $control('GET', $path);
    [$historyStatus, $historyBefore] = $control('GET', $path.'/history');
    $actual = $client->describeWorkflow($workflowId, $author['run_id'])->output;
    [$afterStatus, $after] = $control('GET', $path);
    [$afterHistoryStatus, $historyAfter] = $control('GET', $path.'/history');
    if ($status !== 200 || $afterStatus !== 200 || $historyStatus !== 200 || $afterHistoryStatus !== 200
        || $before['status'] !== 'completed' || $actual !== $expected || $before !== $after
        || $historyBefore !== $historyAfter || microtime(true) >= $author['original_deadline']) {
        throw new RuntimeException('Cold original workflow completion did not converge');
    }
    echo json_encode(['outcome' => 'pass', 'phase' => $phase, 'run_id' => $author['run_id'],
        'sdk_version' => InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
        'encoded_bytes' => $author['encoded_bytes'], 'encoded_sha256' => $author['encoded_sha256'],
        'result' => $expected, 'history_unchanged' => true, 'original_budget_seconds' => 30,
        'elapsed_seconds' => microtime(true) - $author['started_at']], JSON_THROW_ON_ERROR)."\n";
} else {
    throw new RuntimeException('Unknown fixture phase');
}
