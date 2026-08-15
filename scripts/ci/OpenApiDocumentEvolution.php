<?php

declare(strict_types=1);

namespace DurableWorkflow\Server\Ci;

use RuntimeException;

final class OpenApiDocumentEvolution
{
    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $candidate
     * @return array{previous_version:string,candidate_version:string,semantic_shape_changed:bool}
     */
    public static function assertVersionedChange(array $previous, array $candidate): array
    {
        $previousVersion = self::documentVersion($previous, 'previous');
        $candidateVersion = self::documentVersion($candidate, 'candidate');

        if ((int) $candidateVersion < (int) $previousVersion) {
            throw new RuntimeException(sprintf(
                'OpenAPI document version must not move backwards (previous %s, candidate %s).',
                $previousVersion,
                $candidateVersion,
            ));
        }

        $semanticShapeChanged = self::semanticShape($previous) !== self::semanticShape($candidate);

        if ($semanticShapeChanged && $candidateVersion === $previousVersion) {
            throw new RuntimeException(sprintf(
                'Semantic OpenAPI changes require a new info.version; candidate reuses version %s.',
                $candidateVersion,
            ));
        }

        return [
            'previous_version' => $previousVersion,
            'candidate_version' => $candidateVersion,
            'semantic_shape_changed' => $semanticShapeChanged,
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function documentVersion(array $document, string $label): string
    {
        $version = $document['info']['version'] ?? null;

        if (! is_string($version) || preg_match('/^[1-9][0-9]*$/', $version) !== 1) {
            throw new RuntimeException(sprintf(
                '%s OpenAPI document must declare info.version as a positive integer string.',
                ucfirst($label),
            ));
        }

        return $version;
    }

    /**
     * Descriptions are explanatory copy, while every other parsed OpenAPI field
     * contributes to the machine-readable contract. Mapping keys are sorted so
     * formatting and key-order changes do not create a semantic difference.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private static function semanticShape(array $document): array
    {
        unset($document['info']['version']);

        /** @var array<string, mixed> $normalized */
        $normalized = self::normalize($document, 'openapi');

        return $normalized;
    }

    private static function normalize(mixed $value, string $context): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($context === 'literal') {
            return self::normalizeLiteral($value);
        }

        if (str_starts_with($context, 'list:')) {
            $itemContext = substr($context, strlen('list:'));

            return array_map(
                fn (mixed $child): mixed => self::normalize($child, $itemContext),
                $value,
            );
        }

        if (str_starts_with($context, 'map:') || str_starts_with($context, 'mapx:')) {
            $allowsExtensions = str_starts_with($context, 'mapx:');
            $prefix = $allowsExtensions ? 'mapx:' : 'map:';
            $itemContext = substr($context, strlen($prefix));
            ksort($value);

            foreach ($value as $key => $child) {
                $childContext = $allowsExtensions && str_starts_with((string) $key, 'x-')
                    ? 'literal'
                    : $itemContext;
                $value[$key] = self::normalize($child, $childContext);
            }

            return $value;
        }

        // A Schema Object may contain schema arrays in older compatible shapes.
        if (array_is_list($value)) {
            $itemContext = $context === 'schema' ? 'schema' : 'literal';

            return array_map(
                fn (mixed $child): mixed => self::normalize($child, $itemContext),
                $value,
            );
        }

        if ($context !== 'schema' && isset($value['$ref']) && is_string($value['$ref'])) {
            $context = 'reference';
        }

        if (self::hasDescriptionAnnotation($context)
            && isset($value['description'])
            && is_string($value['description'])) {
            unset($value['description']);
        }
        ksort($value);

        foreach ($value as $key => $child) {
            $value[$key] = self::normalize($child, self::childContext($context, (string) $key));
        }

        return $value;
    }

    private static function normalizeLiteral(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn (mixed $child): mixed => is_array($child) ? self::normalizeLiteral($child) : $child,
                $value,
            );
        }

        ksort($value);

        foreach ($value as $key => $child) {
            $value[$key] = is_array($child) ? self::normalizeLiteral($child) : $child;
        }

        return $value;
    }

    private static function hasDescriptionAnnotation(string $context): bool
    {
        return in_array($context, [
            'example',
            'externalDocumentation',
            'header',
            'info',
            'link',
            'operation',
            'parameter',
            'pathItem',
            'reference',
            'requestBody',
            'response',
            'schema',
            'securityScheme',
            'server',
            'serverVariable',
            'tag',
        ], true);
    }

    private static function childContext(string $context, string $key): string
    {
        return match ($context) {
            'openapi' => match ($key) {
                'info' => 'info',
                'servers' => 'list:server',
                'paths' => 'mapx:pathItem',
                'webhooks' => 'map:pathItem',
                'components' => 'components',
                'tags' => 'list:tag',
                'externalDocs' => 'externalDocumentation',
                default => 'literal',
            },
            'components' => match ($key) {
                'schemas' => 'map:schema',
                'responses' => 'map:response',
                'parameters' => 'map:parameter',
                'examples' => 'map:example',
                'requestBodies' => 'map:requestBody',
                'headers' => 'map:header',
                'securitySchemes' => 'map:securityScheme',
                'links' => 'map:link',
                'callbacks' => 'map:callback',
                'pathItems' => 'map:pathItem',
                default => 'literal',
            },
            'server' => $key === 'variables' ? 'map:serverVariable' : 'literal',
            'pathItem' => match ($key) {
                'get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace' => 'operation',
                'servers' => 'list:server',
                'parameters' => 'list:parameter',
                default => 'literal',
            },
            'operation' => match ($key) {
                'externalDocs' => 'externalDocumentation',
                'parameters' => 'list:parameter',
                'requestBody' => 'requestBody',
                'responses' => 'mapx:response',
                'callbacks' => 'map:callback',
                'servers' => 'list:server',
                default => 'literal',
            },
            'parameter', 'header' => match ($key) {
                'schema' => 'schema',
                'examples' => 'map:example',
                'content' => 'map:mediaType',
                default => 'literal',
            },
            'requestBody' => $key === 'content' ? 'map:mediaType' : 'literal',
            'response' => match ($key) {
                'headers' => 'map:header',
                'content' => 'map:mediaType',
                'links' => 'map:link',
                default => 'literal',
            },
            'mediaType' => match ($key) {
                'schema' => 'schema',
                'examples' => 'map:example',
                'encoding' => 'map:encoding',
                default => 'literal',
            },
            'encoding' => $key === 'headers' ? 'map:header' : 'literal',
            'example' => 'literal',
            'link' => $key === 'server' ? 'server' : 'literal',
            'tag' => $key === 'externalDocs' ? 'externalDocumentation' : 'literal',
            'callback' => $key === '$ref' || str_starts_with($key, 'x-') ? 'literal' : 'pathItem',
            'schema' => self::schemaChildContext($key),
            'securityScheme' => $key === 'flows' ? 'oauthFlows' : 'literal',
            'oauthFlows' => in_array($key, ['implicit', 'password', 'clientCredentials', 'authorizationCode'], true)
                ? 'oauthFlow'
                : 'literal',
            default => 'literal',
        };
    }

    private static function schemaChildContext(string $key): string
    {
        return match ($key) {
            '$defs', 'definitions', 'properties', 'patternProperties', 'dependentSchemas', 'dependencies' => 'map:schema',
            'allOf', 'anyOf', 'oneOf', 'prefixItems' => 'list:schema',
            'additionalProperties', 'unevaluatedProperties', 'propertyNames', 'items',
            'contains', 'unevaluatedItems', 'not', 'if', 'then', 'else', 'contentSchema' => 'schema',
            'externalDocs' => 'externalDocumentation',
            // These keywords contain instance data, where a key named
            // "description" is data rather than a Schema Object annotation.
            'const', 'enum', 'default', 'example', 'examples' => 'literal',
            default => 'literal',
        };
    }
}
