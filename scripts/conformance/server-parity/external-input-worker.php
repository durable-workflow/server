<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Transport\Psr18Transport;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\WorkflowContext;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require getenv('DW_PARITY_SDK_AUTOLOAD');
[$script, $url, $workflowId] = $argv;
$http = new HttpClient(['base_uri' => rtrim($url, '/'), 'http_errors' => false,
    'connect_timeout' => 1, 'timeout' => 5]);
$control = static function (string $method, string $path, array $body = []) use ($http): array {
    $response = $http->request($method, $path, ['headers' => ['Authorization' => 'Bearer external-fixture-token',
        'X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2'], 'json' => $body]);
    $value = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

    return [$response->getStatusCode(), $value];
};
if ($control('GET', '/api/namespaces/default')[0] === 404) {
    if ($control('POST', '/api/namespaces', ['name' => 'default'])[0] !== 201) {
        throw new RuntimeException('Default namespace setup failed');
    }
}
if ($control('PUT', '/api/namespaces/default/external-storage', ['driver' => 'local', 'enabled' => true,
    'threshold_bytes' => 16, 'config' => ['uri' => 'file:///state/objects']])[0] !== 200) {
    throw new RuntimeException('Local payload policy setup failed');
}
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
            if ($response->getStatusCode() === 200 && preg_match('#^/api/worker/workflow-tasks/[^/]+/complete$#', $path)) {
                $completions++;
                $worker?->requestShutdown();
            }

            return $response;
        });
    };
});
$client = new Client($url, token: 'external-fixture-token', transport: new Psr18Transport(
    client: new HttpClient(['http_errors' => false, 'handler' => $stack, 'connect_timeout' => 1, 'timeout' => 5]),
));
$text = str_repeat('x', 3 * 1024 * 1024);
$expected = ['bytes' => strlen($text), 'sha256' => hash('sha256', $text),
    'number' => 9007199254740993, 'optional' => null, 'unicode' => 'rangeλ'];
$blob = $client->payloadCodec()->encode([$text, 9007199254740993, null, 'rangeλ']);
$started = microtime(true);
$deadline = $started + 30;
$upload = $http->post('/api/external-payloads/v1', ['headers' => ['Authorization' => 'Bearer external-fixture-token',
    'X-Namespace' => 'default', 'Content-Type' => 'application/octet-stream',
    'X-Durable-Workflow-Payload-Codec' => 'avro', 'X-Durable-Workflow-Payload-Size' => (string) strlen($blob),
    'X-Durable-Workflow-Payload-SHA256' => hash('sha256', $blob)], 'body' => $blob]);
if ($upload->getStatusCode() !== 201) {
    throw new RuntimeException('Original payload upload failed: '.$upload->getStatusCode());
}
$reference = json_decode((string) $upload->getBody(), true, flags: JSON_THROW_ON_ERROR)['reference'];
[$status, $original] = $control('POST', '/api/workflows', ['workflow_id' => $workflowId,
    'workflow_type' => 'parity.v1.external_input', 'task_queue' => $workflowId,
    'input' => ['codec' => 'avro', 'external_payload' => $reference],
    'run_timeout_seconds' => 120, 'execution_timeout_seconds' => 600]);
if ($status !== 201) {
    throw new RuntimeException('External input admission failed: '.$status.' '.json_encode($original));
}
$invocations = 0;
$worker = new Worker($client, $workflowId, workerId: $workflowId.':reader', clock: static function () use ($deadline): float {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Original 30-second external input observation expired');
    }

    return microtime(true);
});
$worker->registerWorkflow('parity.v1.external_input', static function (WorkflowContext $ctx, mixed $text, mixed $number, mixed $optional, mixed $unicode) use (&$invocations, $expected): array {
    $invocations++;
    $actual = ['bytes' => strlen($text), 'sha256' => hash('sha256', $text),
        'number' => $number, 'optional' => $optional, 'unicode' => $unicode];
    if ($actual !== $expected) {
        throw new RuntimeException('Installed worker received different original arguments');
    }

    return $actual;
});
$worker->run(1);
[$status, $final] = $control('GET', '/api/workflows/'.$workflowId.'/runs/'.$original['run_id']);
$execution = $client->describeWorkflow($workflowId, $original['run_id']);
if ($status !== 200 || $final['status'] !== 'completed' || $invocations !== 1 || $completions !== 1
    || $fetches < 1 || $execution->output !== $expected
    || ($final['input_envelope']['external_payload'] ?? null) !== $reference || microtime(true) >= $deadline) {
    throw new RuntimeException('Installed external-input completion did not converge on its original');
}
echo json_encode(['outcome' => 'pass', 'scope' => 'actual_installed_worker_external_input',
    'sdk_version' => InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
    'sdk_source' => InstalledVersions::getReference('durable-workflow/sdk'),
    'sdk_lock_sha256' => hash_file('sha256', dirname(getenv('DW_PARITY_SDK_AUTOLOAD'), 2).'/composer.lock'),
    'worker_source_sha256' => hash_file('sha256', (new ReflectionClass(Worker::class))->getFileName()),
    'encoded_bytes' => strlen($blob), 'encoded_sha256' => hash('sha256', $blob),
    'workflow_id' => $workflowId, 'run_id' => $original['run_id'], 'original_budget_seconds' => 30,
    'elapsed_seconds' => microtime(true) - $started, 'workflow_invocations' => $invocations,
    'workflow_completions' => $completions, 'sdk_payload_gets' => $fetches, 'result' => $expected], JSON_THROW_ON_ERROR)."\n";
