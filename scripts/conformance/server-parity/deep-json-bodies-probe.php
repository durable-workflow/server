<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as DeepJsonBodiesHttp;

// Request bodies nested as deep as PHP's json_decode reads them (511 levels
// at its default depth of 512), over raw HTTP in a fresh namespace. Each case
// sends a workflow start whose input nests the case's number of arrays, and a
// search attribute definition whose name does, and records the status and body.
function finishHttpDeepJsonBodies(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['deep_json_bodies'];
    $namespace = strtolower($workflowId.'-djb');
    $admin->createNamespace($namespace, 'deep json bodies λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $http = new DeepJsonBodiesHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30]);
    $send = static function (string $path, string $body) use ($http, $token, $namespace): array {
        $response = $http->request('POST', $path, ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ]), 'body' => $body]);
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(),
            'json' => is_array($decoded),
            'reason' => is_array($decoded) ? ($decoded['reason'] ?? null) : null,
            'message' => is_array($decoded) ? ($decoded['message'] ?? null) : null,
            'json_error' => is_array($decoded) ? ($decoded['json_error'] ?? null) : null];
    };
    $nested = static fn (int $levels): string => str_repeat('[', $levels).'1'.str_repeat(']', $levels);
    $state = ['applicable' => true];
    foreach ($definition['levels'] as $levels) {
        // The start body is one object around the input, so the input nests
        // one level less than the body.
        $id = $workflowId.'-djb-'.$levels;
        $state['start_'.$levels] = $send('workflows', sprintf('{"workflow_id":"%s","workflow_type":"%s","task_queue":"%s","input":%s}',
            $id, $definition['workflow_type'], $id.'-queue', $nested($levels - 1)));
        // A search attribute definition whose name nests: the body is read,
        // then the name is refused by validation, with no payload codec.
        $state['definition_'.$levels] = $send('search-attributes',
            sprintf('{"name":%s,"type":"keyword"}', $nested($levels - 1)));
    }
    // End the started runs so nothing stays for later fixtures.
    foreach ($definition['levels'] as $levels) {
        if (($state['start_'.$levels]['status'] ?? 0) === 201) {
            $send('workflows/'.rawurlencode($workflowId.'-djb-'.$levels).'/terminate', '{"reason":"deep json bodies recorded"}');
        }
    }

    return $state;
}
