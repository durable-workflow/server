<?php

namespace Tests\Support;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Yaml\Yaml;

final class OpenApiSchema
{
    /** @var array<string, array<string, mixed>> */
    private array $documents = [];

    /**
     * @param  array<string, mixed>  $document
     */
    private function __construct(
        private readonly string $documentPath,
        private readonly array $document,
    ) {
        $this->documents[$documentPath] = $document;
    }

    public static function fromFile(string $documentPath): self
    {
        $document = Yaml::parseFile($documentPath);
        Assert::assertIsArray($document, "OpenAPI document {$documentPath} must parse as an object.");

        return new self(realpath($documentPath) ?: $documentPath, $document);
    }

    public function assertReferenceMatches(string $reference, mixed $value): void
    {
        $this->assertMatches(
            ['$ref' => $reference],
            $value,
            $this->document,
            $this->documentPath,
            '$',
        );
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $document
     */
    private function assertMatches(
        array $schema,
        mixed $value,
        array $document,
        string $documentPath,
        string $pointer,
    ): void {
        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            [$resolved, $resolvedDocument, $resolvedPath] = $this->resolveReference(
                $schema['$ref'],
                $document,
                $documentPath,
            );
            $this->assertMatches($resolved, $value, $resolvedDocument, $resolvedPath, $pointer);

            return;
        }

        foreach ($schema['allOf'] ?? [] as $part) {
            Assert::assertIsArray($part, "{$pointer} allOf entries must be schemas.");
            $this->assertMatches($part, $value, $document, $documentPath, $pointer);
        }

        if (isset($schema['oneOf'])) {
            $matches = 0;

            foreach ($schema['oneOf'] as $part) {
                Assert::assertIsArray($part, "{$pointer} oneOf entries must be schemas.");

                try {
                    $this->assertMatches($part, $value, $document, $documentPath, $pointer);
                    $matches++;
                } catch (AssertionFailedError) {
                    // A oneOf branch is expected to reject non-matching values.
                }
            }

            Assert::assertSame(1, $matches, "{$pointer} must match exactly one oneOf branch.");
        }

        if (array_key_exists('const', $schema)) {
            Assert::assertSame($schema['const'], $value, "{$pointer} must match its const value.");
        }

        if (isset($schema['enum'])) {
            Assert::assertTrue(
                in_array($value, $schema['enum'], true),
                "{$pointer} must match an enum value.",
            );
        }

        if (isset($schema['type'])) {
            $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];
            $matchesType = false;

            foreach ($types as $type) {
                $matchesType = $matchesType || $this->matchesType($type, $value);
            }

            Assert::assertTrue($matchesType, "{$pointer} must have type ".implode(' or ', $types).'.');
        }

        if (is_string($value)) {
            if (isset($schema['minLength'])) {
                Assert::assertGreaterThanOrEqual($schema['minLength'], mb_strlen($value), "{$pointer} is too short.");
            }
            if (isset($schema['maxLength'])) {
                Assert::assertLessThanOrEqual($schema['maxLength'], mb_strlen($value), "{$pointer} is too long.");
            }
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum'])) {
                Assert::assertGreaterThanOrEqual($schema['minimum'], $value, "{$pointer} is below its minimum.");
            }
            if (isset($schema['maximum'])) {
                Assert::assertLessThanOrEqual($schema['maximum'], $value, "{$pointer} exceeds its maximum.");
            }
        }

        if (is_array($value)) {
            if (isset($schema['minItems'])) {
                Assert::assertGreaterThanOrEqual($schema['minItems'], count($value), "{$pointer} has too few items.");
            }
            if (isset($schema['maxItems'])) {
                Assert::assertLessThanOrEqual($schema['maxItems'], count($value), "{$pointer} has too many items.");
            }
            if (($schema['uniqueItems'] ?? false) === true) {
                $encoded = array_map(static fn (mixed $item): string => json_encode($item, JSON_THROW_ON_ERROR), $value);
                Assert::assertSame($encoded, array_values(array_unique($encoded)), "{$pointer} must contain unique items.");
            }
            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $index => $item) {
                    $this->assertMatches(
                        $schema['items'],
                        $item,
                        $document,
                        $documentPath,
                        "{$pointer}/{$index}",
                    );
                }
            }
        }

        if (is_object($value)) {
            $properties = get_object_vars($value);

            foreach ($schema['required'] ?? [] as $required) {
                Assert::assertArrayHasKey($required, $properties, "{$pointer}/{$required} is required.");
            }

            foreach ($properties as $name => $propertyValue) {
                $propertySchema = $schema['properties'][$name] ?? null;

                if (is_array($propertySchema)) {
                    $this->assertMatches(
                        $propertySchema,
                        $propertyValue,
                        $document,
                        $documentPath,
                        "{$pointer}/{$name}",
                    );
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    Assert::fail("{$pointer}/{$name} is not allowed.");
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    $this->assertMatches(
                        $schema['additionalProperties'],
                        $propertyValue,
                        $document,
                        $documentPath,
                        "{$pointer}/{$name}",
                    );
                }
            }
        }
    }

    private function matchesType(mixed $type, mixed $value): bool
    {
        return match ($type) {
            'null' => $value === null,
            'object' => is_object($value),
            'array' => is_array($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}
     */
    private function resolveReference(string $reference, array $document, string $documentPath): array
    {
        [$relativePath, $fragment] = array_pad(explode('#', $reference, 2), 2, '');

        if ($relativePath !== '') {
            $resolvedPath = realpath(dirname($documentPath).'/'.$relativePath);
            Assert::assertIsString($resolvedPath, "Referenced schema {$relativePath} must exist.");

            if (! isset($this->documents[$resolvedPath])) {
                $resolvedDocument = Yaml::parseFile($resolvedPath);
                Assert::assertIsArray($resolvedDocument, "Referenced schema {$resolvedPath} must parse as an object.");
                $this->documents[$resolvedPath] = $resolvedDocument;
            }

            $document = $this->documents[$resolvedPath];
            $documentPath = $resolvedPath;
        }

        $schema = $document;

        foreach (array_filter(explode('/', ltrim($fragment, '/')), 'strlen') as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            Assert::assertIsArray($schema, "Reference {$reference} must traverse schema objects.");
            Assert::assertArrayHasKey($segment, $schema, "Reference {$reference} must resolve.");
            $schema = $schema[$segment];
        }

        Assert::assertIsArray($schema, "Reference {$reference} must resolve to a schema object.");

        return [$schema, $document, $documentPath];
    }
}
