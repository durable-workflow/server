<?php

namespace App\Support;

use App\Models\SearchAttributeDefinition;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;
use Workflow\V2\Models\WorkflowSearchAttribute;

class SearchAttributeValueValidator
{
    /**
     * @param  array<int|string, mixed>  $searchAttributes
     * @param  array<int|string, mixed>  $declaredTypes
     * @return array<string, string>
     */
    public function validateForNamespace(
        ?string $namespace,
        array $searchAttributes,
        string $errorKey = 'search_attributes',
        array $declaredTypes = [],
    ): array {
        $maxKeyLength = (int) config('server.limits.max_search_attribute_key_length', 128);
        $maxValueBytes = (int) config('server.limits.max_search_attribute_value_bytes', 2048);
        $customDefinitions = $this->customDefinitionTypes($namespace);
        $definitions = array_merge(SearchAttributeDefinition::SYSTEM_ATTRIBUTES, $customDefinitions);
        $schemaEnforced = $customDefinitions !== [];
        $attributeTypes = [];
        $messages = [];

        foreach ($declaredTypes as $key => $type) {
            if (! is_string($key) || ! array_key_exists($key, $searchAttributes)) {
                $messages[] = 'Declared search attribute types must name keys present in the attribute update.';

                continue;
            }

            if (! is_string($type) || SearchAttributeDefinition::canonicalType($type) !== $type) {
                $messages[] = sprintf(
                    'Search attribute [%s] declares unsupported type [%s]; allowed types are %s.',
                    $key,
                    is_scalar($type) ? (string) $type : get_debug_type($type),
                    implode(', ', SearchAttributeDefinition::CANONICAL_TYPES),
                );
            }
        }

        foreach ($searchAttributes as $key => $value) {
            if (! is_string($key) || $key === '') {
                $messages[] = 'Search attribute keys must be non-empty strings.';

                continue;
            }

            if ($maxKeyLength > 0 && strlen($key) > $maxKeyLength) {
                $messages[] = sprintf(
                    'Search attribute key [%s] exceeds the maximum length of %d bytes.',
                    $key,
                    $maxKeyLength,
                );

                continue;
            }

            if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $key) !== 1) {
                $messages[] = sprintf(
                    'Search attribute key [%s] must start with a letter and contain only letters, numbers, and underscores.',
                    $key,
                );

                continue;
            }

            $registeredType = isset($definitions[$key])
                ? SearchAttributeDefinition::canonicalType($definitions[$key])
                : null;
            $declaredType = isset($declaredTypes[$key]) && is_string($declaredTypes[$key])
                ? SearchAttributeDefinition::canonicalType($declaredTypes[$key])
                : null;

            if ($registeredType !== null && $declaredType !== null && $registeredType !== $declaredType) {
                $messages[] = sprintf(
                    'Search attribute [%s] declares type [%s] but is registered as [%s].',
                    $key,
                    $declaredType,
                    $registeredType,
                );

                continue;
            }

            $effectiveType = $registeredType ?? $declaredType;

            if ($effectiveType !== null) {
                $this->validateDeclaredValue($messages, $key, $value, $effectiveType, $maxValueBytes);

                if ($value !== null || $declaredType !== null) {
                    $attributeTypes[$key] = $effectiveType;
                }

                continue;
            }

            if ($schemaEnforced) {
                $this->rejectUnregisteredValue($messages, $key);

                continue;
            }

            $this->validateUnregisteredValue($messages, $key, $value, $maxValueBytes);
            if ($value !== null) {
                $attributeTypes[$key] = WorkflowSearchAttribute::inferType($value);
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages([
                $errorKey => $messages,
            ]);
        }

        ksort($attributeTypes);

        return $attributeTypes;
    }

    /**
     * @return array<string, string>
     */
    private function customDefinitionTypes(?string $namespace): array
    {
        if (is_string($namespace) && $namespace !== '') {
            /** @var array<string, string> $custom */
            return SearchAttributeDefinition::query()
                ->where('namespace', $namespace)
                ->pluck('type', 'name')
                ->all();
        }

        return [];
    }

    /**
     * @param  list<string>  $messages
     */
    private function validateDeclaredValue(
        array &$messages,
        string $key,
        mixed $value,
        string $declaredType,
        int $maxValueBytes,
    ): void {
        if ($value === null) {
            return;
        }

        match ($declaredType) {
            'keyword', 'string', 'text' => $this->validateStringValue($messages, $key, $value, $declaredType, $maxValueBytes),
            'int' => $this->validateIntValue($messages, $key, $value),
            'float' => $this->validateFloatValue($messages, $key, $value),
            'bool' => $this->validateBoolValue($messages, $key, $value),
            'datetime' => $this->validateDatetimeValue($messages, $key, $value, $maxValueBytes),
            'keyword_list' => $this->validateKeywordListValue($messages, $key, $value, $maxValueBytes),
            default => $messages[] = sprintf(
                'Search attribute [%s] has unsupported registered type [%s].',
                $key,
                $declaredType,
            ),
        };
    }

    /**
     * @param  list<string>  $messages
     */
    private function rejectUnregisteredValue(array &$messages, string $key): void
    {
        $messages[] = sprintf(
            'Search attribute [%s] is not registered for this namespace.',
            $key,
        );
    }

    /**
     * @param  list<string>  $messages
     */
    private function validateUnregisteredValue(array &$messages, string $key, mixed $value, int $maxValueBytes): void
    {
        if ($value !== null && ! is_scalar($value)) {
            $messages[] = sprintf(
                'Search attribute [%s] must be a scalar value or null unless it is registered as keyword_list.',
                $key,
            );

            return;
        }

        if (is_string($value) && $maxValueBytes > 0 && strlen($value) > $maxValueBytes) {
            $messages[] = sprintf(
                'Search attribute [%s] value exceeds the maximum of %d bytes.',
                $key,
                $maxValueBytes,
            );
        }
    }

    /**
     * @param  list<string>  $messages
     */
    private function validateStringValue(
        array &$messages,
        string $key,
        mixed $value,
        string $declaredType,
        int $maxValueBytes,
    ): void {
        if (! is_string($value)) {
            $messages[] = sprintf(
                'Search attribute [%s] is registered as %s and must be a string.',
                $key,
                $declaredType,
            );

            return;
        }

        $maxBytes = $declaredType === 'keyword'
            ? min($this->positiveLimit($maxValueBytes), WorkflowSearchAttribute::MAX_KEYWORD_LENGTH)
            : $maxValueBytes;

        if ($maxBytes > 0 && strlen($value) > $maxBytes) {
            $messages[] = sprintf(
                'Search attribute [%s] value exceeds the maximum of %d bytes.',
                $key,
                $maxBytes,
            );
        }
    }

    /**
     * @param  list<string>  $messages
     */
    private function validateIntValue(array &$messages, string $key, mixed $value): void
    {
        if (! is_int($value)) {
            $messages[] = sprintf(
                'Search attribute [%s] is registered as int and must be an integer.',
                $key,
            );
        }
    }

    /**
     * @param  list<string>  $messages
     */
    private function validateFloatValue(array &$messages, string $key, mixed $value): void
    {
        if ((! is_int($value) && ! is_float($value)) || is_bool($value)) {
            $messages[] = sprintf(
                'Search attribute [%s] is registered as float and must be a number.',
                $key,
            );
        }
    }

    /**
     * @param  list<string>  $messages
     */
    private function validateBoolValue(array &$messages, string $key, mixed $value): void
    {
        if (! is_bool($value)) {
            $messages[] = sprintf(
                'Search attribute [%s] is registered as bool and must be a boolean.',
                $key,
            );
        }
    }

    /**
     * @param  list<string>  $messages
     */
    private function validateDatetimeValue(array &$messages, string $key, mixed $value, int $maxValueBytes): void
    {
        if (! is_string($value)) {
            $messages[] = sprintf(
                'Search attribute [%s] is registered as datetime and must be an RFC3339 datetime string.',
                $key,
            );

            return;
        }

        if ($maxValueBytes > 0 && strlen($value) > $maxValueBytes) {
            $messages[] = sprintf(
                'Search attribute [%s] value exceeds the maximum of %d bytes.',
                $key,
                $maxValueBytes,
            );

            return;
        }

        try {
            Carbon::parse($value);
        } catch (Throwable) {
            $messages[] = sprintf(
                'Search attribute [%s] is registered as datetime and must be an RFC3339 datetime string.',
                $key,
            );
        }
    }

    /**
     * @param  list<string>  $messages
     */
    private function validateKeywordListValue(array &$messages, string $key, mixed $value, int $maxValueBytes): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $messages[] = sprintf(
                'Search attribute [%s] is registered as keyword_list and must be a list of strings.',
                $key,
            );

            return;
        }

        $maxBytes = min($this->positiveLimit($maxValueBytes), WorkflowSearchAttribute::MAX_KEYWORD_LENGTH);

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                $messages[] = sprintf(
                    'Search attribute [%s] is registered as keyword_list and must contain only strings.',
                    $key,
                );

                return;
            }

            if ($maxBytes > 0 && strlen($entry) > $maxBytes) {
                $messages[] = sprintf(
                    'Search attribute [%s] list value exceeds the maximum of %d bytes.',
                    $key,
                    $maxBytes,
                );

                return;
            }
        }
    }

    private function positiveLimit(int $limit): int
    {
        return $limit > 0 ? $limit : WorkflowSearchAttribute::MAX_KEYWORD_LENGTH;
    }
}
