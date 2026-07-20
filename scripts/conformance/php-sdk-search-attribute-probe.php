<?php

declare(strict_types=1);

/**
 * @return array{
 *     definitions: array<string, string>,
 *     start_search_attributes: array<string, mixed>,
 *     upserted_search_attributes: array<string, mixed>,
 *     expected_search_attributes: array<string, mixed>,
 *     visibility_query: array{attribute_source: string, name: string, value: string, query: string},
 *     namespace_isolation_query: array{attribute_source: string, name: string, value: string, query: string}
 * }
 */
function php_sdk_search_attribute_probe_values(string $suffix): array
{
    $definitions = [
        'customer_id' => 'string',
        'order_total_cents' => 'int',
        'discount_ratio' => 'double',
        'priority_tier' => 'keyword',
        'is_vip' => 'bool',
        'created_at' => 'datetime',
        'tags' => 'keyword_list',
    ];
    $startAttributes = [
        'customer_id' => 'cust-php-'.$suffix,
        'order_total_cents' => 7350,
        'discount_ratio' => 0.2,
        'priority_tier' => 'silver',
        'is_vip' => false,
        'created_at' => '2026-05-20T12:00:00Z',
        'tags' => ['php', 'initial'],
    ];
    $upsertedAttributes = [
        'priority_tier' => 'platinum-'.$suffix,
        'tags' => ['php', 'mirror', 'upserted'],
    ];
    $queryName = 'priority_tier';
    $queryValue = $upsertedAttributes[$queryName];

    return [
        'definitions' => $definitions,
        'start_search_attributes' => $startAttributes,
        'upserted_search_attributes' => $upsertedAttributes,
        'expected_search_attributes' => array_replace($startAttributes, $upsertedAttributes),
        'visibility_query' => [
            'attribute_source' => 'upserted_search_attributes',
            'name' => $queryName,
            'value' => $queryValue,
            'query' => php_sdk_search_attribute_equality_query($queryName, $queryValue),
        ],
        'namespace_isolation_query' => [
            'attribute_source' => 'start_search_attributes',
            'name' => 'customer_id',
            'value' => $startAttributes['customer_id'],
            'query' => php_sdk_search_attribute_equality_query('customer_id', $startAttributes['customer_id']),
        ],
    ];
}

/**
 * Build bounded namespace evidence from a start-time attribute that does not
 * require a worker to execute the peer workflow.
 *
 * @param  array{attribute_source: string, name: string, value: string, query: string}  $query
 * @param  list<string>  $primaryWorkflowIds
 * @param  list<string>  $peerWorkflowIds
 * @return array<string, mixed>
 */
function php_sdk_search_attribute_namespace_isolation_evidence(
    string $primaryNamespace,
    string $peerNamespace,
    string $primaryWorkflowId,
    string $peerWorkflowId,
    array $query,
    array $primaryWorkflowIds,
    array $peerWorkflowIds,
): array {
    return [
        'primary_namespace' => $primaryNamespace,
        'peer_namespace' => $peerNamespace,
        'attribute_source' => $query['attribute_source'],
        'attribute_name' => $query['name'],
        'attribute_value' => $query['value'],
        'query' => $query['query'],
        'peer_execution_required' => false,
        'primary_query_count' => count($primaryWorkflowIds),
        'peer_query_count' => count($peerWorkflowIds),
        'primary_visibility_match' => in_array($primaryWorkflowId, $primaryWorkflowIds, true),
        'peer_visibility_match' => in_array($peerWorkflowId, $peerWorkflowIds, true),
        'primary_workflow_ids' => array_slice($primaryWorkflowIds, 0, 20),
        'peer_workflow_ids' => array_slice($peerWorkflowIds, 0, 20),
        'cross_namespace_leak_detected' => in_array($peerWorkflowId, $primaryWorkflowIds, true)
            || in_array($primaryWorkflowId, $peerWorkflowIds, true),
    ];
}

function php_sdk_search_attribute_equality_query(string $name, string|int|float|bool $value): string
{
    if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $name) !== 1) {
        throw new InvalidArgumentException("Invalid search attribute name [{$name}].");
    }

    return $name.' = '.json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/**
 * Make a fixed conformance schema reusable without hiding incompatible shared state.
 *
 * @param  array<string, string>  $requiredDefinitions
 * @param  callable(): array<string, string>  $listDefinitions
 * @param  callable(string, string): array{outcome: string, name?: string, type?: string|null}  $createDefinition
 * @return array<string, string>
 */
function php_sdk_ensure_search_attribute_definitions(
    array $requiredDefinitions,
    callable $listDefinitions,
    callable $createDefinition,
): array {
    $listedDefinitions = $listDefinitions();

    foreach ($requiredDefinitions as $name => $expectedType) {
        if (array_key_exists($name, $listedDefinitions)) {
            php_sdk_assert_search_attribute_definition_type($name, $expectedType, $listedDefinitions[$name]);

            continue;
        }

        $result = $createDefinition($name, $expectedType);
        $outcome = $result['outcome'] ?? '';
        if (! in_array($outcome, ['created', 'already_exists'], true)) {
            throw new RuntimeException("Search attribute {$name} returned unsupported creation outcome [{$outcome}].");
        }
        if (($result['name'] ?? $name) !== $name) {
            throw new RuntimeException("Search attribute {$name} returned a different definition name.");
        }

        php_sdk_assert_search_attribute_definition_type($name, $expectedType, $result['type'] ?? null);
        $listedDefinitions[$name] = $expectedType;
    }

    $listedDefinitions = $listDefinitions();
    foreach ($requiredDefinitions as $name => $expectedType) {
        php_sdk_assert_search_attribute_definition_type(
            $name,
            $expectedType,
            $listedDefinitions[$name] ?? null,
        );
    }

    return $listedDefinitions;
}

function php_sdk_assert_search_attribute_definition_type(
    string $name,
    string $expectedType,
    mixed $actualType,
): void {
    if ($actualType === $expectedType) {
        return;
    }

    $renderedType = is_string($actualType) && $actualType !== '' ? $actualType : 'missing';
    throw new RuntimeException(
        "Search attribute {$name} has type [{$renderedType}]; expected [{$expectedType}].",
    );
}
