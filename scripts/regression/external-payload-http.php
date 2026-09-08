<?php

declare(strict_types=1);

use App\Support\WorkerProtocol;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\PumpStream;
use Illuminate\Contracts\Console\Kernel;
use Workflow\Serializers\Serializer;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Laravel's console handler is intended for Artisan. This standalone regression
// must exit nonzero for an uncaught failure so CI cannot mistake it for a pass.
set_exception_handler(static function (Throwable $exception): void {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
});

$url = getenv('DW_PAYLOAD_HTTP_URL') ?: 'http://127.0.0.1:8080';
if (! in_array(parse_url($url, PHP_URL_HOST), ['127.0.0.1', 'localhost', '[::1]'], true)) {
    throw new RuntimeException('Use a disposable local Server, never a customer runtime.');
}
$namespace = 'payload-http-'.bin2hex(random_bytes(5));
if (($argv[1] ?? null) === '--verify') {
    $namespace = $argv[2] ?? '';
    if (! preg_match('/^payload-http-[0-9a-f]{10}$/D', $namespace)) {
        throw new RuntimeException('Provide the disposable namespace printed by the original run.');
    }
}
$client = new Client([
    'base_uri' => rtrim($url, '/').'/',
    'timeout' => 120,
    'http_errors' => false,
    'headers' => [
        'Authorization' => 'Bearer '.(getenv('DW_PAYLOAD_HTTP_TOKEN') ?: 'transport-regression-token'),
        'X-Durable-Workflow-Control-Plane-Version' => '2',
        'X-Namespace' => $namespace,
        'Accept' => 'application/json',
    ],
]);

function jsonResponse($response, int $status): array
{
    $body = (string) $response->getBody();
    if ($response->getStatusCode() !== $status) {
        throw new RuntimeException('Expected HTTP '.$status.', got '.$response->getStatusCode().': '.substr($body, 0, 512));
    }

    return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
}

if (($argv[1] ?? null) === '--verify') {
    $reference = null;
    foreach ([$namespace, $namespace.'-second'] as $workflowId) {
        $result = jsonResponse($client->get('api/workflows/'.$workflowId), 200);
        $reference = $result['output_envelope']['external_payload'] ?? null;
        if (($result['status'] ?? null) !== 'completed'
            || ($reference['size_bytes'] ?? null) !== 64 * 1024 * 1024
            || ($result['payload_previews']['output_omitted'] ?? null) !== true) {
            throw new RuntimeException('Workflow completion did not survive restart.');
        }
    }
    $activity = jsonResponse($client->get('api/activities/'.$namespace.'-activity'), 200);
    if (($activity['activity_status'] ?? null) !== 'completed'
        || ($activity['result']['external_payload']['sha256'] ?? null) !== $reference['sha256']) {
        throw new RuntimeException('Activity completion did not survive restart.');
    }
    $response = $client->get('api/external-payloads/v1/'.$reference['reference_id'], ['headers' => [
        'X-Durable-Workflow-Payload-Codec' => $reference['codec'],
        'X-Durable-Workflow-Payload-Size' => (string) $reference['size_bytes'],
        'X-Durable-Workflow-Payload-SHA256' => $reference['sha256'],
    ]]);
    $encoded = (string) $response->getBody();
    if ($response->getStatusCode() !== 200 || ! hash_equals($reference['sha256'], hash('sha256', $encoded))) {
        throw new RuntimeException('Retained bytes did not survive restart.');
    }
    $value = Serializer::unserializeWithCodec('avro', $encoded);
    if (! is_array($value) || count($value) !== 1 || ! is_string($value[0])
        || strlen($value[0]) !== 50331630
        || hash('sha256', $value[0]) !== '1c8d5fd0a3936542546ca61e18a3ef7a5409a903cab8fe0a3ed09ae213cc51fa') {
        throw new RuntimeException('Fresh consumer decoded an incorrect retained result.');
    }
    fwrite(STDOUT, "Cold workflow/activity completion, reference integrity and official Avro consumption: passed\n");
    exit(0);
}

jsonResponse($client->post('api/namespaces', ['json' => ['name' => $namespace]]), 201);
jsonResponse($client->put('api/namespaces/'.$namespace.'/external-storage', ['json' => [
    'driver' => 'local', 'enabled' => true, 'threshold_bytes' => 1024,
]]), 200);

$bytes = 64 * 1024 * 1024;
// Official Avro encoding, including its base64 transport representation.
$payload = Serializer::serializeWithCodec('avro', [str_repeat('m', 50331648 - 18)]);
if (strlen($payload) !== $bytes) {
    throw new RuntimeException('Fixture must be exactly the advertised 64 MiB limit, got '.strlen($payload));
}
$sha256 = hash('sha256', $payload);
$headers = [
    'Content-Type' => 'application/octet-stream',
    'X-Durable-Workflow-Payload-Codec' => 'avro',
    'X-Durable-Workflow-Payload-Size' => (string) $bytes,
    'X-Durable-Workflow-Payload-SHA256' => $sha256,
];
$references = [];
$promises = [];
for ($i = 0; $i < 2; $i++) {
    $promises[] = $client->postAsync('api/external-payloads/v1', ['headers' => $headers, 'body' => $payload]);
}
foreach (Utils::unwrap($promises) as $response) {
    $reference = jsonResponse($response, 201)['reference'];
    if ($reference['size_bytes'] !== $bytes || $reference['sha256'] !== $sha256) {
        throw new RuntimeException('Uploaded reference differs from the source.');
    }
    $references[] = $reference;
}
fwrite(STDOUT, "Concurrent exact-limit uploads: passed\n");

foreach ($references as $reference) {
    $response = $client->get('api/external-payloads/v1/'.$reference['reference_id'], ['headers' => $headers]);
    if ($response->getStatusCode() !== 200 || ! hash_equals($sha256, hash('sha256', (string) $response->getBody()))) {
        throw new RuntimeException('Fetched bytes differ from the source.');
    }
}
fwrite(STDOUT, "Exact-limit fetch and integrity: passed\n");

$tooLarge = jsonResponse($client->post('api/external-payloads/v1', [
    'headers' => $headers,
    'body' => $payload.'x',
]), 413);
if (($tooLarge['reason'] ?? null) !== 'external_payload_oversized') {
    throw new RuntimeException('Incorrect oversized upload response.');
}
foreach (['POST', 'PUT', 'PATCH'] as $method) {
    foreach (['application/json', 'application/x-www-form-urlencoded'] as $mediaType) {
        $response = $client->request($method, 'api/workflows', [
            'headers' => ['Content-Type' => $mediaType],
            'body' => str_repeat('x', 9 * 1024 * 1024),
        ]);
        if ((jsonResponse($response, 413)['reason'] ?? null) !== 'payload_too_large') {
            throw new RuntimeException('Incorrect oversized ordinary request response.');
        }
    }
}
fwrite(STDOUT, "Oversized upload and ordinary request rejection: passed\n");

// An unknown-size stream makes Guzzle use chunked HTTP transfer. The runtime
// must bound observed bytes even when Content-Length cannot reject them early.
foreach (['api/workflows' => 9 * 1024 * 1024, 'api/external-payloads/v1' => $bytes + 1] as $path => $remaining) {
    $body = new PumpStream(static function (int $length) use (&$remaining): string|false {
        if ($remaining === 0) {
            return false;
        }
        $length = min($length, $remaining, 8192);
        $remaining -= $length;

        return str_repeat('x', $length);
    });
    jsonResponse($client->post($path, [
        'headers' => $path === 'api/workflows' ? ['Content-Type' => 'application/json'] : $headers,
        'body' => $body,
    ]), 413);
}
fwrite(STDOUT, "Chunked request size enforcement: passed\n");

$start = jsonResponse($client->post('api/workflows', ['json' => [
    'workflow_id' => $namespace,
    'workflow_type' => 'payload.http.echo',
    'task_queue' => $namespace,
    'input' => ['codec' => 'avro', 'external_payload' => $references[0]],
]]), 201);
fwrite(STDOUT, json_encode(['namespace' => $namespace, 'workflow_id' => $namespace, 'run_id' => $start['run_id']], JSON_THROW_ON_ERROR)."\n");
fwrite(STDOUT, "Retained workflow start: passed\n");
$description = jsonResponse($client->get('api/workflows/'.$namespace), 200);
if (($description['payload_previews']['input_omitted'] ?? null) !== true
    || ($description['input_envelope']['external_payload']['reference_id'] ?? null) !== $references[0]['reference_id']) {
    throw new RuntimeException('Workflow description must retain the full reference and explicitly omit its oversized preview.');
}
fwrite(STDOUT, "Retained workflow description: passed\n");

$workerId = $namespace.'-worker';
$workerHeaders = [WorkerProtocol::HEADER => WorkerProtocol::VERSION];
$manifest = array_fill_keys(WorkerProtocol::PORTABLE_WORKER_AFFINITY_CAPABILITIES, [
    'supported' => false,
    'minimum_protocol_version' => WorkerProtocol::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION,
    'reason' => 'Transport regression uses ordinary tasks only.',
]);
jsonResponse($client->post('api/worker/register', ['headers' => $workerHeaders, 'json' => [
    'worker_id' => $workerId, 'task_queue' => $namespace, 'runtime' => 'external',
    'supported_workflow_types' => ['payload.http.echo'],
    'supported_activity_types' => ['payload.http.activity'],
    'max_concurrent_workflow_tasks' => 2,
    'capability_manifest' => $manifest,
]]), 201);
try {
    jsonResponse($client->post('api/workflows', ['json' => [
        'workflow_id' => $namespace.'-second', 'workflow_type' => 'payload.http.echo',
        'task_queue' => $namespace, 'input' => [],
    ]]), 201);
    $tasks = [];
    for ($i = 0; $i < 2; $i++) {
        $task = jsonResponse($client->post('api/worker/workflow-tasks/poll', [
            'headers' => $workerHeaders,
            'json' => ['worker_id' => $workerId, 'task_queue' => $namespace],
        ]), 200)['task'] ?? null;
        if (! is_array($task)) {
            throw new RuntimeException('Expected an available workflow task.');
        }
        $tasks[] = $task;
    }
    $completions = [];
    foreach ($tasks as $task) {
        $completions[] = $client->postAsync('api/worker/workflow-tasks/'.$task['task_id'].'/complete', [
            'headers' => $workerHeaders,
            'json' => [
                'lease_owner' => $workerId, 'workflow_task_attempt' => $task['workflow_task_attempt'],
                'commands' => [[
                    'type' => 'complete_workflow', 'sequence' => 1,
                    'result' => ['codec' => 'avro', 'external_payload' => $references[0]],
                ]],
            ],
        ]);
    }
    foreach (Utils::unwrap($completions) as $response) {
        if ((jsonResponse($response, 200)['recorded'] ?? null) !== true) {
            throw new RuntimeException('Large workflow completion was not recorded.');
        }
    }
    foreach ([$namespace, $namespace.'-second'] as $workflowId) {
        $result = jsonResponse($client->get('api/workflows/'.$workflowId), 200);
        if (($result['status'] ?? null) !== 'completed'
            || ($result['output_envelope']['external_payload']['sha256'] ?? null) !== $sha256
            || ($result['payload_previews']['output_omitted'] ?? null) !== true) {
            throw new RuntimeException('Completed output reference or bounded description is incorrect.');
        }
    }
    fwrite(STDOUT, "Concurrent exact-limit workflow completion and retained result: passed\n");

    jsonResponse($client->post('api/activities', ['json' => [
        'activity_id' => $namespace.'-activity', 'activity_type' => 'payload.http.activity',
        'task_queue' => $namespace, 'input' => ['codec' => 'avro', 'external_payload' => $references[0]],
    ]]), 201);
    $activityTask = jsonResponse($client->post('api/worker/activity-tasks/poll', [
        'headers' => $workerHeaders,
        'json' => ['worker_id' => $workerId, 'task_queue' => $namespace],
    ]), 200)['task'] ?? null;
    if (! is_array($activityTask)) {
        throw new RuntimeException('Expected an available activity task.');
    }
    if (($activityTask['arguments']['external_payload']['sha256'] ?? null) !== $sha256) {
        throw new RuntimeException('Activity poll did not preserve the full input reference.');
    }
    jsonResponse($client->post('api/worker/activity-tasks/'.$activityTask['task_id'].'/complete', [
        'headers' => $workerHeaders,
        'json' => [
            'activity_attempt_id' => $activityTask['activity_attempt_id'], 'lease_owner' => $workerId,
            'result' => ['codec' => 'avro', 'external_payload' => $references[0]],
        ],
    ]), 200);
    fwrite(STDOUT, "Exact-limit activity completion: passed\n");
    $activity = jsonResponse($client->get('api/activities/'.$namespace.'-activity'), 200);
    if (($activity['activity_status'] ?? null) !== 'completed'
        || ($activity['result']['external_payload']['sha256'] ?? null) !== $sha256) {
        throw new RuntimeException('Completed activity result reference is incorrect.');
    }
    fwrite(STDOUT, "Retained activity result description: passed\n");
} finally {
    jsonResponse($client->delete('api/worker/registrations/'.$workerId, ['headers' => $workerHeaders]), 200);
}
