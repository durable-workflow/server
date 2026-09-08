<?php

namespace App\Support;

use JsonException;

final readonly class RuntimePayloadCompletionContext
{
    public const HEADER = 'X-Durable-Workflow-Payload-Completion';

    public const SCHEMA = 'durable-workflow.v2.payload-completion-context.v1';

    /** @param list<int|string> $slot */
    private function __construct(
        public string $kind,
        public string $taskId,
        public int|string $attempt,
        public string $leaseOwner,
        public string $operation,
        public array $slot,
    ) {}

    public static function parse(string $header): self
    {
        if (strlen($header) > 4096) {
            throw self::invalid();
        }
        try {
            $value = json_decode($header, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw self::invalid();
        }
        $keys = ['schema', 'kind', 'task_id', 'attempt', 'lease_owner', 'operation', 'slot'];
        if (! is_array($value) || count($value) !== count($keys)
            || array_diff($keys, array_keys($value)) !== []
            || ($value['schema'] ?? null) !== self::SCHEMA
            || ! in_array($value['kind'] ?? null, ['activity', 'workflow', 'query'], true)
            || ! is_string($value['operation'])
            || ! self::identifier($value['task_id']) || ! self::identifier($value['lease_owner'])
            || ! is_array($value['slot']) || ! array_is_list($value['slot'])) {
            throw self::invalid();
        }
        if ($value['kind'] === 'activity'
            ? ! self::identifier($value['attempt'])
            : (! is_int($value['attempt']) || $value['attempt'] < 1)) {
            throw self::invalid();
        }
        $validSlot = match ($value['kind'].'.'.($value['operation'] ?? '')) {
            'activity.complete' => $value['slot'] === ['result'],
            'activity.fail' => $value['slot'] === ['failure', 'details'],
            'query.complete' => $value['slot'] === ['result_envelope'],
            'workflow.complete' => self::workflowSlot($value['slot']),
            default => false,
        };
        if (! $validSlot) {
            throw self::invalid();
        }

        return new self($value['kind'], $value['task_id'], $value['attempt'],
            $value['lease_owner'], $value['operation'], $value['slot']);
    }

    public function scope(string $namespace): string
    {
        // Complete/fail and every payload slot share one allowance for this lease.
        return hash('sha256', json_encode([$namespace, $this->kind, $this->taskId,
            $this->attempt, $this->leaseOwner], JSON_THROW_ON_ERROR));
    }

    public function slotIdentity(): string
    {
        return hash('sha256', json_encode([$this->operation, $this->slot], JSON_THROW_ON_ERROR));
    }

    public function toArray(): array
    {
        return ['schema' => self::SCHEMA, 'kind' => $this->kind, 'task_id' => $this->taskId,
            'attempt' => $this->attempt, 'lease_owner' => $this->leaseOwner,
            'operation' => $this->operation, 'slot' => $this->slot];
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 255
            && preg_match('/[\x00-\x1f\x7f]/', $value) === 0;
    }

    private static function workflowSlot(array $slot): bool
    {
        if (($slot[0] ?? null) !== 'commands' || ! is_int($slot[1] ?? null) || $slot[1] < 0) {
            return false;
        }
        if (count($slot) === 3) {
            return in_array($slot[2], ['arguments', 'entries', 'request_payload', 'result'], true);
        }
        if (count($slot) === 4) {
            return array_slice($slot, 2) === ['exception', 'details'];
        }

        return count($slot) === 6 && $slot[2] === 'workflow_stream' && $slot[3] === 'items'
            && is_int($slot[4]) && $slot[4] >= 0 && $slot[5] === 'payload';
    }

    private static function invalid(): RuntimeExternalPayloadException
    {
        return new RuntimeExternalPayloadException('external_payload_completion_invalid', 422, false,
            'A canonical worker completion context is required.');
    }
}
