<?php

declare(strict_types=1);

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
fwrite(STDOUT, "Retained workflow start: passed; worker completion must also be qualified.\n");
$description = jsonResponse($client->get('api/workflows/'.$namespace), 200);
if (($description['payload_previews']['input_omitted'] ?? null) !== true
    || ($description['input_envelope']['external_payload']['reference_id'] ?? null) !== $references[0]['reference_id']) {
    throw new RuntimeException('Workflow description must retain the full reference and explicitly omit its oversized preview.');
}
fwrite(STDOUT, "Retained workflow description: passed\n");
