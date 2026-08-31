<?php

namespace App\Support;

use App\Models\SearchAttributeDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class WorkflowVisibilityQuery
{
    private const SYSTEM_COLUMNS = [
        'WorkflowType' => ['column' => 'workflow_run_summaries.workflow_type', 'type' => 'keyword'],
        'WorkflowId' => ['column' => 'workflow_run_summaries.workflow_instance_id', 'type' => 'keyword'],
        'RunId' => ['column' => 'workflow_run_summaries.id', 'type' => 'keyword'],
        'TaskQueue' => ['column' => 'workflow_run_summaries.queue', 'type' => 'keyword'],
        'BuildId' => ['column' => 'workflow_run_summaries.compatibility', 'type' => 'keyword'],
        'BuildIds' => ['column' => 'workflow_run_summaries.compatibility', 'type' => 'keyword'],
        'StartTime' => ['column' => 'workflow_run_summaries.started_at', 'type' => 'datetime'],
        'ExecutionTime' => ['column' => 'workflow_run_summaries.started_at', 'type' => 'datetime'],
        'CloseTime' => ['column' => 'workflow_run_summaries.closed_at', 'type' => 'datetime'],
    ];

    /**
     * Apply a Temporal-style visibility query if the input can be parsed.
     *
     * Plain terms such as "order-123" intentionally return false so callers
     * can keep using their legacy free-text workflow-id/business-key search.
     */
    public function apply(Builder $builder, string $namespace, string $query): bool
    {
        $comparisonGroups = $this->parse($query);

        if ($comparisonGroups === null) {
            return false;
        }

        $definitions = SearchAttributeDefinition::query()
            ->where('namespace', $namespace)
            ->pluck('type', 'name')
            ->all();

        $builder->where(function (Builder $visibilityQuery) use ($comparisonGroups, $definitions, $namespace): void {
            foreach ($comparisonGroups as $index => $comparisons) {
                $method = $index === 0 ? 'where' : 'orWhere';

                $visibilityQuery->{$method}(function (Builder $groupQuery) use ($comparisons, $definitions, $namespace): void {
                    foreach ($comparisons as $comparison) {
                        $this->applyComparison($groupQuery, $comparison, $definitions, $namespace);
                    }
                });
            }
        });

        return true;
    }

    /**
     * @return list<list<array{field: string, operator: string, literal: mixed, bare?: bool}>>|null
     */
    private function parse(string $query): ?array
    {
        $query = trim($query);

        if (! $this->looksLikeVisibilityQuery($query)) {
            return null;
        }

        $orParts = $this->splitConditionsByKeyword($query, 'OR');

        if ($orParts === []) {
            throw $this->invalidQuery('Visibility query predicates must use: Field = literal.');
        }

        $groups = [];

        foreach ($orParts as $orPart) {
            $andParts = $this->splitConditionsByKeyword($orPart, 'AND');

            if ($andParts === []) {
                throw $this->invalidQuery('Visibility query predicates must use: Field = literal.');
            }

            $comparisons = [];

            foreach ($andParts as $part) {
                $comparison = $this->parsePredicate($part);

                if ($comparison === null) {
                    throw $this->invalidQuery('Visibility query predicates must use: Field = literal.');
                }

                $comparisons[] = $comparison;
            }

            $groups[] = $comparisons;
        }

        return $groups;
    }

    /**
     * @param  array{field: string, operator: string, literal: mixed, bare?: bool}  $comparison
     * @param  array<string, string>  $definitions
     */
    private function applyComparison(
        Builder $builder,
        array $comparison,
        array $definitions,
        string $namespace,
    ): void {
        $field = $comparison['field'];

        if ($field === 'Status' || $field === 'ExecutionStatus') {
            $this->applyStatusComparison($builder, $comparison);

            return;
        }

        if (isset(self::SYSTEM_COLUMNS[$field])) {
            $this->applyColumnComparison($builder, self::SYSTEM_COLUMNS[$field], $comparison);

            return;
        }

        if (! isset($definitions[$field])) {
            throw ValidationException::withMessages([
                'query' => [sprintf(
                    'Search attribute [%s] is not defined in namespace [%s].',
                    $field,
                    $namespace,
                )],
            ]);
        }

        $this->applySearchAttributeComparison($builder, $field, (string) $definitions[$field], $comparison);
    }

    /**
     * @return array{field: string, operator: string, literal: mixed, bare?: bool}|null
     */
    private function parsePredicate(string $part): ?array
    {
        $part = trim($part);

        if (preg_match('/^NOT\s+(.+)$/i', $part, $matches) === 1) {
            $comparison = $this->parsePredicate($matches[1]);

            return $comparison === null ? null : $this->invertComparison($comparison);
        }

        if (preg_match('/^([A-Za-z][A-Za-z0-9_]*)\s*(=|!=|>=|<=|>|<)\s*(.+)$/', $part, $matches) === 1) {
            $literal = $this->parseLiteral($matches[3]);

            if ($literal === self::invalidLiteral()) {
                throw $this->invalidQuery(sprintf('Visibility query literal [%s] is not valid.', trim($matches[3])));
            }

            return [
                'field' => $matches[1],
                'operator' => $matches[2],
                'literal' => $literal,
            ];
        }

        if (preg_match('/^([A-Za-z][A-Za-z0-9_]*)\s+IN\s*\((.*)\)$/i', $part, $matches) === 1) {
            $literals = $this->parseLiteralList($matches[2]);

            if ($literals === []) {
                throw $this->invalidQuery(sprintf('Visibility query literal list [%s] is not valid.', trim($matches[2])));
            }

            return [
                'field' => $matches[1],
                'operator' => 'IN',
                'literal' => $literals,
            ];
        }

        if (preg_match('/^([A-Za-z][A-Za-z0-9_]*)$/', $part, $matches) === 1) {
            return [
                'field' => $matches[1],
                'operator' => '=',
                'literal' => true,
                'bare' => true,
            ];
        }

        return null;
    }

    /**
     * @param  array{field: string, operator: string, literal: mixed, bare?: bool}  $comparison
     * @return array{field: string, operator: string, literal: mixed, bare?: bool}
     */
    private function invertComparison(array $comparison): array
    {
        if (($comparison['bare'] ?? false) === true && $comparison['operator'] === '=') {
            $comparison['literal'] = false;

            return $comparison;
        }

        $comparison['operator'] = match ($comparison['operator']) {
            '=' => '!=',
            '!=' => '=',
            '>' => '<=',
            '>=' => '<',
            '<' => '>=',
            '<=' => '>',
            'IN' => 'NOT IN',
            'NOT IN' => 'IN',
            default => $comparison['operator'],
        };

        return $comparison;
    }

    private function looksLikeVisibilityQuery(string $query): bool
    {
        return preg_match('/\b[A-Za-z][A-Za-z0-9_]*\s*(=|!=|>=|<=|>|<)\s*/', $query) === 1
            || preg_match('/\b[A-Za-z][A-Za-z0-9_]*\s+IN\s*\(/i', $query) === 1
            || preg_match('/(?:^|\bAND\s+)NOT\s+[A-Za-z][A-Za-z0-9_]*\b/i', $query) === 1;
    }

    /**
     * @return list<string>
     */
    private function splitConditionsByKeyword(string $query, string $keyword): array
    {
        if ($query === '') {
            return [];
        }

        $parts = [];
        $current = '';
        $quote = null;
        $parenDepth = 0;
        $length = strlen($query);
        $keywordLength = strlen($keyword);

        for ($i = 0; $i < $length; $i++) {
            $char = $query[$i];

            if ($quote !== null) {
                $current .= $char;

                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $query[++$i];

                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '(') {
                $parenDepth++;
                $current .= $char;

                continue;
            }

            if ($char === ')') {
                $parenDepth--;

                if ($parenDepth < 0) {
                    return [];
                }

                $current .= $char;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $current .= $char;

                continue;
            }

            if ($parenDepth === 0 && $this->startsKeywordAt($query, $i, $keyword)) {
                $part = trim($current);

                if ($part === '') {
                    return [];
                }

                $parts[] = $part;
                $current = '';
                $i += $keywordLength - 1;

                continue;
            }

            $current .= $char;
        }

        $part = trim($current);

        if ($quote !== null || $parenDepth !== 0 || $part === '') {
            return [];
        }

        $parts[] = $part;

        return $parts;
    }

    /**
     * @return list<mixed>
     */
    private function parseLiteralList(string $raw): array
    {
        $parts = $this->splitCommaList($raw);

        if ($parts === []) {
            return [];
        }

        $literals = [];

        foreach ($parts as $part) {
            $literal = $this->parseLiteral($part);

            if ($literal === self::invalidLiteral()) {
                return [];
            }

            $literals[] = $literal;
        }

        return $literals;
    }

    /**
     * @return list<string>
     */
    private function splitCommaList(string $raw): array
    {
        $parts = [];
        $current = '';
        $quote = null;
        $length = strlen($raw);

        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];

            if ($quote !== null) {
                $current .= $char;

                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $raw[++$i];

                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $current .= $char;

                continue;
            }

            if ($char === ',') {
                $part = trim($current);

                if ($part === '') {
                    return [];
                }

                $parts[] = $part;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $part = trim($current);

        if ($quote !== null || $part === '') {
            return [];
        }

        $parts[] = $part;

        return $parts;
    }

    private function startsKeywordAt(string $query, int $offset, string $keyword): bool
    {
        $keywordLength = strlen($keyword);

        if (strtolower(substr($query, $offset, $keywordLength)) !== strtolower($keyword)) {
            return false;
        }

        $before = $offset === 0 ? ' ' : $query[$offset - 1];
        $afterOffset = $offset + $keywordLength;
        $after = $afterOffset >= strlen($query) ? ' ' : $query[$afterOffset];

        return ! preg_match('/[A-Za-z0-9_]/', $before)
            && ! preg_match('/[A-Za-z0-9_]/', $after);
    }

    private function parseLiteral(string $raw): mixed
    {
        $raw = trim($raw);

        if ($raw === '') {
            return self::invalidLiteral();
        }

        $quote = $raw[0];

        if (($quote === '"' || $quote === "'") && substr($raw, -1) === $quote && strlen($raw) >= 2) {
            return stripcslashes(substr($raw, 1, -1));
        }

        $lower = strtolower($raw);

        if ($lower === 'true') {
            return true;
        }

        if ($lower === 'false') {
            return false;
        }

        if (preg_match('/^[+-]?\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        if (is_numeric($raw) && preg_match('/^[+-]?(?:\d+\.\d*|\.\d+|\d+[eE][+-]?\d+|\d+\.\d*[eE][+-]?\d+|\.\d+[eE][+-]?\d+)$/', $raw) === 1) {
            return (float) $raw;
        }

        if (preg_match('/^[A-Za-z0-9_.:-]+$/', $raw) === 1) {
            return $raw;
        }

        return self::invalidLiteral();
    }

    /**
     * @param  array{field: string, operator: string, literal: mixed, bare?: bool}  $comparison
     */
    private function applyStatusComparison(Builder $builder, array $comparison): void
    {
        $value = strtolower($this->coerceKeyword($comparison['literal'], 'Status'));
        $column = in_array($value, ['running', 'completed', 'failed'], true)
            ? 'workflow_run_summaries.status_bucket'
            : 'workflow_run_summaries.status';

        $this->assertEqualityOperator($comparison['operator'], 'Status');
        $builder->where($column, $comparison['operator'], $value);
    }

    /**
     * @param  array{column: string, type: string}  $column
     * @param  array{field: string, operator: string, literal: mixed, bare?: bool}  $comparison
     */
    private function applyColumnComparison(Builder $builder, array $column, array $comparison): void
    {
        if ($comparison['operator'] === 'IN' || $comparison['operator'] === 'NOT IN') {
            $this->applyColumnInComparison($builder, $column, $comparison);

            return;
        }

        $value = $this->coerceValue($comparison['literal'], $column['type'], $comparison['field']);

        if ($column['type'] === 'keyword') {
            $this->assertEqualityOperator($comparison['operator'], $comparison['field']);
        }

        $builder->where($column['column'], $comparison['operator'], $value);
    }

    /**
     * @param  array{column: string, type: string}  $column
     * @param  array{field: string, operator: string, literal: mixed, bare?: bool}  $comparison
     */
    private function applyColumnInComparison(Builder $builder, array $column, array $comparison): void
    {
        $values = $this->coerceLiteralList($comparison['literal'], $column['type'], $comparison['field']);

        $comparison['operator'] === 'IN'
            ? $builder->whereIn($column['column'], $values)
            : $builder->whereNotIn($column['column'], $values);
    }

    /**
     * @param  array{field: string, operator: string, literal: mixed, bare?: bool}  $comparison
     */
    private function applySearchAttributeComparison(
        Builder $builder,
        string $field,
        string $definitionType,
        array $comparison,
    ): void {
        $type = $this->normalizeSearchAttributeType($definitionType);
        $operator = $comparison['operator'];

        if (($comparison['bare'] ?? false) === true && $type !== 'bool') {
            throw $this->invalidValue($field, 'an explicit comparison');
        }

        if ($operator === 'IN' || $operator === 'NOT IN') {
            $values = $this->coerceLiteralList($comparison['literal'], $type, $field);

            $builder->whereHas('searchAttributes', function ($attributeQuery) use ($field, $type, $operator, $values): void {
                $attributeQuery->where('key', $field);
                $this->applySearchAttributeInComparison($attributeQuery, $type, $operator, $values);
            });

            return;
        }

        $value = $this->coerceValue($comparison['literal'], $type, $field);

        $builder->whereHas('searchAttributes', function ($attributeQuery) use ($field, $type, $operator, $value): void {
            $attributeQuery->where('key', $field);

            match ($type) {
                'keyword' => $this->applyKeywordAttributeComparison($attributeQuery, $operator, $value),
                'keyword_list' => $this->applyKeywordListAttributeComparison($attributeQuery, $operator, $value),
                'text' => $this->applyTextAttributeComparison($attributeQuery, $operator, $value),
                'int' => $attributeQuery->where('value_int', $operator, $value),
                'double' => $this->applyDoubleAttributeComparison($attributeQuery, $operator, $value),
                'bool' => $attributeQuery->where('value_bool', $operator, $value),
                'datetime' => $attributeQuery->where('value_datetime', $operator, $value),
            };
        });
    }

    private function applyKeywordAttributeComparison($query, string $operator, mixed $value): void
    {
        $this->assertEqualityOperator($operator, 'keyword search attribute');
        $query->where('value_keyword', $operator, $value);
    }

    private function applyKeywordListAttributeComparison($query, string $operator, mixed $value): void
    {
        if ($operator !== '=') {
            throw ValidationException::withMessages([
                'query' => ['keyword_list search attributes only support membership equality comparisons.'],
            ]);
        }

        $query->whereJsonContains('value_keyword_list', $value);
    }

    /**
     * @param  list<mixed>  $values
     */
    private function applySearchAttributeInComparison($query, string $type, string $operator, array $values): void
    {
        $not = $operator === 'NOT IN';

        match ($type) {
            'keyword' => $not
                ? $query->whereNotIn('value_keyword', $values)
                : $query->whereIn('value_keyword', $values),
            'keyword_list' => $this->applyKeywordListInComparison($query, $values, $not),
            'text' => $this->applyTextInComparison($query, $values, $not),
            'int' => $not
                ? $query->whereNotIn('value_int', $values)
                : $query->whereIn('value_int', $values),
            'double' => $this->applyDoubleInComparison($query, $values, $not),
            'bool' => $not
                ? $query->whereNotIn('value_bool', $values)
                : $query->whereIn('value_bool', $values),
            'datetime' => $not
                ? $query->whereNotIn('value_datetime', $values)
                : $query->whereIn('value_datetime', $values),
        };
    }

    /**
     * @param  list<mixed>  $values
     */
    private function applyKeywordListInComparison($query, array $values, bool $not): void
    {
        if ($not) {
            throw ValidationException::withMessages([
                'query' => ['keyword_list search attributes do not support NOT IN comparisons.'],
            ]);
        }

        $query->where(function ($scoped) use ($values): void {
            foreach ($values as $index => $value) {
                $index === 0
                    ? $scoped->whereJsonContains('value_keyword_list', $value)
                    : $scoped->orWhereJsonContains('value_keyword_list', $value);
            }
        });
    }

    /**
     * @param  list<mixed>  $values
     */
    private function applyTextInComparison($query, array $values, bool $not): void
    {
        $query->where(function ($scoped) use ($values, $not): void {
            if ($not) {
                $scoped->whereNotIn('value_string', $values)
                    ->whereNotIn('value_keyword', $values);

                return;
            }

            $scoped->whereIn('value_string', $values)
                ->orWhereIn('value_keyword', $values);
        });
    }

    /**
     * @param  list<mixed>  $values
     */
    private function applyDoubleInComparison($query, array $values, bool $not): void
    {
        $query->where(function ($scoped) use ($values, $not): void {
            if ($not) {
                $scoped->whereNotIn('value_float', $values)
                    ->whereNotIn('value_int', $values);

                return;
            }

            $scoped->whereIn('value_float', $values)
                ->orWhereIn('value_int', $values);
        });
    }

    private function applyTextAttributeComparison($query, string $operator, mixed $value): void
    {
        $this->assertEqualityOperator($operator, 'text search attribute');
        $query->where(function ($scoped) use ($operator, $value): void {
            $scoped->where('value_string', $operator, $value)
                ->orWhere('value_keyword', $operator, $value);
        });
    }

    private function applyDoubleAttributeComparison($query, string $operator, mixed $value): void
    {
        $query->where(function ($scoped) use ($operator, $value): void {
            $scoped->where('value_float', $operator, $value)
                ->orWhere('value_int', $operator, $value);
        });
    }

    private function normalizeSearchAttributeType(string $type): string
    {
        return match ($type) {
            'keyword', 'keyword_list', 'string', 'text', 'int', 'bool', 'datetime' => $type === 'string' ? 'text' : $type,
            'double', 'float' => 'double',
            default => throw ValidationException::withMessages([
                'query' => [sprintf('Search attribute type [%s] is not supported in visibility queries.', $type)],
            ]),
        };
    }

    private function coerceValue(mixed $literal, string $type, string $field): mixed
    {
        return match ($type) {
            'keyword', 'keyword_list', 'text' => $this->coerceKeyword($literal, $field),
            'int' => $this->coerceInt($literal, $field),
            'double' => $this->coerceDouble($literal, $field),
            'bool' => $this->coerceBool($literal, $field),
            'datetime' => $this->coerceDatetime($literal, $field),
            default => throw ValidationException::withMessages([
                'query' => [sprintf('Search attribute type [%s] is not supported in visibility queries.', $type)],
            ]),
        };
    }

    /**
     * @return list<mixed>
     */
    private function coerceLiteralList(mixed $literal, string $type, string $field): array
    {
        if (! is_array($literal) || ! array_is_list($literal) || $literal === []) {
            throw $this->invalidValue($field, 'a non-empty literal list');
        }

        return array_map(
            fn (mixed $value): mixed => $this->coerceValue($value, $type, $field),
            $literal,
        );
    }

    private function coerceKeyword(mixed $literal, string $field): string
    {
        if (is_bool($literal)) {
            return $literal ? 'true' : 'false';
        }

        if (is_scalar($literal)) {
            return (string) $literal;
        }

        throw $this->invalidValue($field, 'a string literal');
    }

    private function coerceInt(mixed $literal, string $field): int
    {
        if (is_int($literal)) {
            return $literal;
        }

        throw $this->invalidValue($field, 'an integer literal');
    }

    private function coerceDouble(mixed $literal, string $field): float
    {
        if (is_int($literal) || is_float($literal)) {
            return (float) $literal;
        }

        throw $this->invalidValue($field, 'a numeric literal');
    }

    private function coerceBool(mixed $literal, string $field): bool
    {
        if (is_bool($literal)) {
            return $literal;
        }

        throw $this->invalidValue($field, 'a boolean literal');
    }

    private function coerceDatetime(mixed $literal, string $field): Carbon
    {
        if ($literal instanceof \DateTimeInterface) {
            return Carbon::instance($literal);
        }

        if (is_string($literal) || is_int($literal) || is_float($literal)) {
            try {
                return Carbon::parse($literal);
            } catch (\Throwable) {
                throw $this->invalidValue($field, 'a datetime literal');
            }
        }

        throw $this->invalidValue($field, 'a datetime literal');
    }

    private function assertEqualityOperator(string $operator, string $field): void
    {
        if (! in_array($operator, ['=', '!='], true)) {
            throw ValidationException::withMessages([
                'query' => [sprintf('[%s] only supports equality comparisons.', $field)],
            ]);
        }
    }

    private function invalidValue(string $field, string $expected): ValidationException
    {
        return ValidationException::withMessages([
            'query' => [sprintf('[%s] must be compared with %s.', $field, $expected)],
        ]);
    }

    private function invalidQuery(string $message): ValidationException
    {
        return ValidationException::withMessages([
            'query' => [$message],
        ]);
    }

    private static function invalidLiteral(): object
    {
        static $invalid;

        return $invalid ??= new class {};
    }
}
