<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as SearchAttributeHttp;

// The custom search attribute registry of two fresh namespaces: the
// published SDK's list, create and delete calls, then raw requests for the
// validation, reservation, duplicate, limit and removal refusals the SDK's
// typed arguments cannot send.
function finishHttpSearchAttributes(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['search_attributes'];
    $names = [strtolower($workflowId.'-attributes'), strtolower($workflowId.'-attribute-limit')];
    foreach ($names as $name) {
        $admin->createNamespace($name, 'search attributes λ', 30);
    }
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $client = parityClient($url, namespace: $names[0], token: $token);
    $http = new SearchAttributeHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 10]);
    $raw = static function (string $namespace, string $method, string $path, array|object|null $body = null) use ($http, $token): array {
        $response = $http->request($method, $path, ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ]), ...($body === null ? [] : ['json' => $body])]);

        return ['status' => $response->getStatusCode(),
            'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    };
    $state = ['applicable' => true, 'namespaces' => $names];
    $state['sdk_empty'] = $client->listSearchAttributes()->raw;
    foreach ($definition['created'] as [$name, $type]) {
        $state['sdk_created'][] = $client->createSearchAttribute($name, $type)->raw;
    }
    $state['sdk_listed'] = $client->listSearchAttributes()->raw;
    // Laravel trims request strings before validation.
    $state['trimmed'] = $raw($names[0], 'POST', 'search-attributes', $definition['untrimmed']);
    $state['other_namespace'] = $raw($names[1], 'GET', 'search-attributes');
    $state['duplicate'] = $raw($names[0], 'POST', 'search-attributes', $definition['duplicate']);
    foreach ($definition['reserved'] as $name) {
        $state['reserved'][] = $raw($names[0], 'POST', 'search-attributes', ['name' => $name, 'type' => 'keyword']);
    }
    foreach ($definition['invalid'] as $body) {
        $state['invalid'][] = $raw($names[0], 'POST', 'search-attributes', $body === [] ? (object) [] : $body);
    }
    $state['delete_system'] = $raw($names[0], 'DELETE', 'search-attributes/WorkflowType');
    $state['delete_missing'] = $raw($names[0], 'DELETE', 'search-attributes/Absent');
    $state['sdk_deleted'] = $client->deleteSearchAttribute($definition['created'][0][0])->raw;
    $state['listed_after_delete'] = $raw($names[0], 'GET', 'search-attributes');
    for ($index = 0; $index < $definition['limit']; $index++) {
        $created = $raw($names[1], 'POST', 'search-attributes', ['name' => 'Attribute'.$index, 'type' => 'int']);
        if ($created['status'] !== 201) {
            $state['limit_fill_refusal'] = ['index' => $index, ...$created];
            break;
        }
    }
    $state['over_limit'] = $raw($names[1], 'POST', 'search-attributes', ['name' => 'OneTooMany', 'type' => 'int']);
    $state['limit_count'] = count($raw($names[1], 'GET', 'search-attributes')['response']['custom_attributes']);

    return $state;
}
