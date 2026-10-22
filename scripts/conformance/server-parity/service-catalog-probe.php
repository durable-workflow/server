<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as ServiceCatalogHttp;

// Each service catalog read the fixture lists, raw, against a fresh namespace
// that holds no catalog objects. "@own" in a path or namespace header is that
// namespace, "@OWN" the same name in upper case.
function finishHttpServiceCatalog(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['service_catalog'];
    $own = strtolower($workflowId.'-catalog');
    $admin->createNamespace($own, 'service catalog λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $http = new ServiceCatalogHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30]);
    $state = ['applicable' => true, 'namespace' => $own, 'responses' => []];
    foreach ($definition['requests'] as $request) {
        $namespace = match ($request['namespace']) {
            '@own' => $own,
            '@OWN' => strtoupper($own),
            default => $request['namespace'],
        };
        $response = $http->get(str_replace('@own', $own, $request['path']), ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => $request['version'] ? '2' : null,
        ], static fn (?string $value): bool => $value !== null)]);
        $state['responses'][$request['label']] = [$response->getStatusCode(),
            json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    }

    return $state;
}
