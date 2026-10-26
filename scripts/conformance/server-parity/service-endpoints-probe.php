<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as ServiceEndpointsHttp;
use GuzzleHttp\HandlerStack;

// The service catalog administration routes, raw and in order, against a
// fresh namespace: endpoints, services and operations are created, read,
// updated and deleted with each refusal the controller answers, and the
// catalog reads are taken while the rows exist. A path may name a field of an
// earlier response as "{label.field}". A request sends `body` as JSON, or
// `raw_body` verbatim where the JSON shape itself matters ({} against []).
// "@own" is the fixture's namespace.
function finishHttpServiceEndpoints(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $own = strtolower($workflowId.'-endpoints');
    $admin->createNamespace($own, 'service endpoints λ', 30);

    return ['applicable' => true, 'namespace' => $own,
        'responses' => serviceCatalogRequests($fixture['service_endpoints']['requests'], $own, $url)];
}

/**
 * Sends each request in order and keeps every answer by label. A path may
 * name a field of an earlier answer as "{label.field}"; "@own" is the
 * fixture's namespace.
 *
 * @param  list<array<string, mixed>>  $requests
 * @return array<string, array{0: int, 1: mixed}>
 */
function serviceCatalogRequests(array $requests, string $own, string $url): array
{
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    // The SQLite reference shares its database with a one-second maintenance
    // loop; retry its retryable lock-pressure refusals, as the other probes do.
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new ServiceEndpointsHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $responses = [];
    foreach ($requests as $request) {
        $path = preg_replace_callback('/\{([^{}.]+)\.([a-z_]+)\}/', static function (array $match) use (&$responses): string {
            $value = $responses[$match[1]][1][$match[2]] ?? null;
            if (! is_string($value)) {
                throw new RuntimeException("No {$match[1]}.{$match[2]} for a later path.");
            }

            return rawurlencode($value);
        }, $request['path']);
        $namespace = ($request['namespace'] ?? '@own') === '@own' ? $own : $request['namespace'];
        $options = ['headers' => [
            ...($token === null ? [] : ['Authorization' => 'Bearer '.$token]),
            'Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ]];
        if (array_key_exists('raw_body', $request)) {
            $options['body'] = $request['raw_body'];
        } elseif (array_key_exists('body', $request)) {
            $options['body'] = json_encode($request['body'], JSON_THROW_ON_ERROR);
        }
        $response = $http->request($request['method'] ?? 'GET', $path, $options);
        $responses[$request['label']] = [$response->getStatusCode(),
            json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    }

    return $responses;
}
