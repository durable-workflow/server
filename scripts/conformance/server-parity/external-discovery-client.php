<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Transport\Psr18Transport;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;

// Runtime external payload discovery as the installed SDK reads it from
// /api/cluster/info: the ordinary request limit, the namespace's storage policy
// and the transport manifest. Four namespaces cover an available, an
// unconfigured, a disabled and a missing policy. One public SDK start then sends
// an input over the default namespace's 16-byte threshold, with no explicit
// reference, so the SDK's own discovery decides to upload it.
require getenv('DW_PARITY_SDK_AUTOLOAD');
[$script, $url, $label] = $argv;
$http = new HttpClient(['base_uri' => rtrim($url, '/'), 'http_errors' => false,
    'connect_timeout' => 1, 'timeout' => 5]);
$control = static function (string $method, string $path, string $namespace, ?array $body = null) use ($http): array {
    $options = ['headers' => ['Authorization' => 'Bearer external-fixture-token', 'X-Namespace' => $namespace,
        'X-Durable-Workflow-Control-Plane-Version' => '2']];
    if ($body !== null) {
        $options['json'] = $body;
    }
    $response = $http->request($method, $path, $options);

    return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
};
$require = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$plain = $label.'-plain';
$off = $label.'-off';
if ($control('GET', '/api/namespaces/default', 'default')[0] === 404) {
    $require($control('POST', '/api/namespaces', 'default', ['name' => 'default'])[0] === 201, 'default namespace setup');
}
$require($control('PUT', '/api/namespaces/default/external-storage', 'default', ['driver' => 'local', 'enabled' => true,
    'threshold_bytes' => 16, 'config' => ['uri' => 'file:///state/objects']])[0] === 200, 'available policy setup');
foreach ([$plain, $off] as $name) {
    $require($control('POST', '/api/namespaces', 'default', ['name' => $name])[0] === 201, "$name setup");
}
$require($control('PUT', "/api/namespaces/$off/external-storage", 'default', ['driver' => 'local', 'enabled' => false,
    'threshold_bytes' => 32, 'config' => ['uri' => 'file:///state/objects']])[0] === 200, 'disabled policy setup');

$discovery = [];
foreach (['available' => 'default', 'unconfigured' => $plain, 'disabled' => $off, 'missing' => $label.'-absent'] as $case => $namespace) {
    [$status, $info] = $control('GET', '/api/cluster/info', $namespace);
    $discovery[$case] = ['status' => $status, 'namespace' => $info['namespace'] ?? null, 'limits' => $info['limits'] ?? null,
        'default_namespace' => $info['default_namespace'] ?? null,
        'server_id_present' => is_string($info['server_id'] ?? null) && $info['server_id'] !== '',
        'transport_capability' => $info['worker_protocol']['server_capabilities']['runtime_external_payload_transport'] ?? null];
}

$requests = [];
$stack = HandlerStack::create();
$stack->push(static function (callable $handler) use (&$requests): callable {
    return static function (RequestInterface $request, array $options) use ($handler, &$requests) {
        $entry = ['method' => $request->getMethod(), 'path' => $request->getUri()->getPath()];
        if ($entry === ['method' => 'POST', 'path' => '/api/workflows']) {
            $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $request->getBody()->rewind();
            $entry['input_members'] = array_keys($body['input'] ?? []);
        }
        $requests[] = $entry;

        return $handler($request, $options);
    };
});
$client = new Client($url, token: 'external-fixture-token', transport: new Psr18Transport(
    client: new HttpClient(['http_errors' => false, 'handler' => $stack, 'connect_timeout' => 1, 'timeout' => 5]),
));
$text = str_repeat('d', 256);
$handle = $client->startWorkflow('parity.v1.external_discovery', $label, $label, [$text, 9007199254740993, null, 'rangeλ'],
    executionTimeoutSeconds: 600, runTimeoutSeconds: 120);
[$status, $described] = $control('GET', '/api/workflows/'.rawurlencode($label), 'default');
$require($status === 200, 'described SDK start');

echo json_encode(['outcome' => 'pass', 'scope' => 'installed_sdk_runtime_payload_discovery',
    'run_id' => $handle->selectedRunId, 'described_run_id' => $described['run_id'] ?? null,
    'sdk_version' => InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
    'sdk_source' => InstalledVersions::getReference('durable-workflow/sdk'),
    'discovery' => $discovery, 'requests' => $requests], JSON_THROW_ON_ERROR)."\n";
