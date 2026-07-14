<?php

namespace App\Support;

use App\Models\SearchAttributeDefinition;
use Illuminate\Database\Eloquent\Builder;

class ScheduleVisibilityQuery
{
    /** @var array<string, array{column: string, type: string}> */
    private const SYSTEM_FIELDS = [
        'ScheduleId' => ['column' => 'schedule_id', 'type' => 'keyword'],
        'Status' => ['column' => 'status', 'type' => 'schedule_status'],
        'WorkflowType' => ['column' => 'action->workflow_type', 'type' => 'keyword'],
        'TaskQueue' => ['column' => 'action->task_queue', 'type' => 'keyword'],
        'Note' => ['column' => 'note', 'type' => 'text'],
    ];

    /**
     * Parse the documented schedule visibility grammar.
     *
     * The grammar deliberately supports only equality predicates joined by
     * AND. Keeping the accepted surface small makes unsupported predicates a
     * typed refusal instead of an expression that is silently ignored.
     *
     * @return list<array{field: string, column: string|null, type: string, literal: bool|float|int|string}>
     */
    public function parse(string $namespace, string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            throw new ScheduleVisibilityQueryException('Schedule visibility query must not be empty.');
        }

        $parts = $this->splitAndPredicates($query);

        if ($parts === []) {
            throw new ScheduleVisibilityQueryException(
                'Schedule visibility query contains an unterminated string or empty predicate.',
            );
        }

        $definitions = SearchAttributeDefinition::query()
            ->where('namespace', $namespace)
            ->pluck('type', 'name')
            ->all();

        $predicates = [];

        foreach ($parts as $part) {
            $this->rejectUnsupportedPredicate($part);

            if (preg_match('/^([A-Za-z][A-Za-z0-9_]*)\s*=\s*(.+)$/s', $part, $matches) !== 1) {
                throw new ScheduleVisibilityQueryException(
                    'Schedule visibility predicates must use: Field = literal.',
                );
            }

            $field = $matches[1];
            $literal = $this->parseLiteral(trim($matches[2]));
            $system = self::SYSTEM_FIELDS[$field] ?? null;

            if ($system !== null) {
                $type = $system['type'];
                $column = $system['column'];
            } elseif (isset($definitions[$field])) {
                $type = (string) $definitions[$field];
                $column = null;
            } else {
                throw new ScheduleVisibilityQueryException(
                    sprintf(
                        'Schedule visibility field [%s] is not searchable. Use ScheduleId, Status, '
                            .'WorkflowType, TaskQueue, Note, or a registered search attribute.',
                        $field,
                    ),
                    'unsupported_schedule_visibility_field',
                );
            }

            $this->validateLiteralType($field, $type, $literal);

            $predicates[] = [
                'field' => $field,
                'column' => $column,
                'type' => $type,
                'literal' => $literal,
            ];
        }

        return $predicates;
    }

    /**
     * @param list<array{field: string, column: string|null, type: string, literal: bool|float|int|string}> $predicates
     */
    public function apply(Builder $builder, array $predicates): void
    {
        foreach ($predicates as $predicate) {
            if ($predicate['column'] !== null) {
                $builder->where($predicate['column'], '=', $predicate['literal']);

                continue;
            }

            $path = 'search_attributes->'.$predicate['field'];

            if ($predicate['type'] === 'keyword_list') {
                $builder->whereJsonContains($path, $predicate['literal']);

                continue;
            }

            $builder->where($path, '=', $predicate['literal']);
        }
    }

    private function rejectUnsupportedPredicate(string $predicate): void
    {
        $unquoted = $this->withoutQuotedLiterals($predicate);

        if (preg_match('/(?:!=|<>|>=|<=|>|<)/', $unquoted) === 1
            || preg_match('/\b(?:OR|NOT|IN|LIKE|CONTAINS|STARTS_WITH|ENDS_WITH)\b/i', $unquoted) === 1
        ) {
            throw new ScheduleVisibilityQueryException(
                'Schedule visibility supports only equality predicates joined by AND.',
                'unsupported_schedule_visibility_predicate',
            );
        }
    }

    private function withoutQuotedLiterals(string $value): string
    {
        $result = '';
        $quote = null;
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];

            if ($quote !== null) {
                if ($character === '\\' && $index + 1 < $length) {
                    $index++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                $result .= ' ';

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
                $result .= ' ';

                continue;
            }

            $result .= $character;
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function splitAndPredicates(string $query): array
    {
        $parts = [];
        $current = '';
        $quote = null;
        $length = strlen($query);

        for ($index = 0; $index < $length; $index++) {
            $character = $query[$index];

            if ($quote !== null) {
                $current .= $character;

                if ($character === '\\' && $index + 1 < $length) {
                    $current .= $query[++$index];

                    continue;
                }

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
                $current .= $character;

                continue;
            }

            if ($this->startsAndAt($query, $index)) {
                $part = trim($current);

                if ($part === '') {
                    return [];
                }

                $parts[] = $part;
                $current = '';
                $index += 2;

                continue;
            }

            $current .= $character;
        }

        $last = trim($current);

        if ($quote !== null || $last === '') {
            return [];
        }

        $parts[] = $last;

        return $parts;
    }

    private function startsAndAt(string $query, int $index): bool
    {
        if (strncasecmp(substr($query, $index, 3), 'AND', 3) !== 0) {
            return false;
        }

        $before = $index === 0 ? ' ' : $query[$index - 1];
        $after = $query[$index + 3] ?? ' ';

        return ctype_space($before) && ctype_space($after);
    }

    private function parseLiteral(string $literal): bool|float|int|string
    {
        if ($literal === '') {
            throw new ScheduleVisibilityQueryException('Schedule visibility predicate literal must not be empty.');
        }

        if ($literal[0] === '"') {
            $decoded = json_decode($literal, true);

            if (! is_string($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                throw new ScheduleVisibilityQueryException(
                    sprintf('Schedule visibility literal [%s] is not a valid JSON string.', $literal),
                );
            }

            return $decoded;
        }

        if ($literal[0] === "'") {
            if (strlen($literal) < 2 || ! str_ends_with($literal, "'")) {
                throw new ScheduleVisibilityQueryException(
                    sprintf('Schedule visibility literal [%s] is not a valid quoted string.', $literal),
                );
            }

            return str_replace(["\\'", '\\\\'], ["'", '\\'], substr($literal, 1, -1));
        }

        if (strcasecmp($literal, 'true') === 0) {
            return true;
        }

        if (strcasecmp($literal, 'false') === 0) {
            return false;
        }

        if (preg_match('/^-?(?:0|[1-9][0-9]*)$/', $literal) === 1) {
            return (int) $literal;
        }

        if (preg_match('/^-?(?:0|[1-9][0-9]*)\.[0-9]+$/', $literal) === 1) {
            return (float) $literal;
        }

        throw new ScheduleVisibilityQueryException(
            sprintf('Schedule visibility literal [%s] must be a quoted string, number, or boolean.', $literal),
        );
    }

    private function validateLiteralType(string $field, string $type, bool|float|int|string $literal): void
    {
        $valid = match ($type) {
            'keyword', 'string', 'text', 'datetime', 'keyword_list' => is_string($literal),
            'int' => is_int($literal),
            'double' => is_float($literal) || is_int($literal),
            'bool' => is_bool($literal),
            'schedule_status' => is_string($literal) && in_array($literal, ['active', 'paused'], true),
            default => false,
        };

        if ($valid) {
            return;
        }

        throw new ScheduleVisibilityQueryException(sprintf(
            'Schedule visibility field [%s] requires a %s literal.',
            $field,
            match ($type) {
                'int' => 'integer',
                'double' => 'number',
                'bool' => 'boolean',
                'schedule_status' => 'quoted active or paused',
                default => 'quoted string',
            },
        ));
    }
}
