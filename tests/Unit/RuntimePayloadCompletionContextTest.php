<?php

namespace Tests\Unit;

use App\Support\RuntimeExternalPayloadException;
use App\Support\RuntimePayloadCompletionContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RuntimePayloadCompletionContextTest extends TestCase
{
    #[DataProvider('validSlots')]
    public function test_only_existing_worker_payload_purposes_have_a_context(string $kind, string $operation, array $slot): void
    {
        $value = $this->value($kind, $operation, $slot);
        $context = RuntimePayloadCompletionContext::parse(json_encode($value, JSON_THROW_ON_ERROR));
        $this->assertSame($kind, $context->kind);
        $this->assertSame($operation, $context->operation);
        $this->assertSame($slot, $context->slot);
        $this->assertSame($context->scope('default'),
            RuntimePayloadCompletionContext::parse(json_encode(array_reverse($value, true), JSON_THROW_ON_ERROR))->scope('default'));
        $this->assertNotSame($context->scope('default'), $context->scope('other'));
    }

    public static function validSlots(): array
    {
        return [
            ['activity', 'complete', ['result']],
            ['activity', 'fail', ['failure', 'details']],
            ['query', 'complete', ['result_envelope']],
            ['workflow', 'complete', ['commands', 0, 'arguments']],
            ['workflow', 'complete', ['commands', 1, 'entries']],
            ['workflow', 'complete', ['commands', 2, 'request_payload']],
            ['workflow', 'complete', ['commands', 3, 'result']],
            ['workflow', 'complete', ['commands', 4, 'exception', 'details']],
            ['workflow', 'complete', ['commands', 5, 'workflow_stream', 'items', 0, 'payload']],
        ];
    }

    public function test_all_slots_and_outcomes_for_the_same_lease_share_one_budget(): void
    {
        $complete = RuntimePayloadCompletionContext::parse(json_encode($this->value(), JSON_THROW_ON_ERROR));
        $fail = RuntimePayloadCompletionContext::parse(json_encode($this->value('activity', 'fail', ['failure', 'details']), JSON_THROW_ON_ERROR));
        $this->assertSame($complete->scope('default'), $fail->scope('default'));
        $this->assertNotSame($complete->slotIdentity(), $fail->slotIdentity());
        foreach (['task_id', 'attempt', 'lease_owner'] as $key) {
            $other = $this->value();
            $other[$key] .= '-other';
            $this->assertNotSame($complete->scope('default'),
                RuntimePayloadCompletionContext::parse(json_encode($other, JSON_THROW_ON_ERROR))->scope('default'));
        }
    }

    #[DataProvider('invalidContexts')]
    public function test_malformed_or_unrelated_context_cannot_authorize_writes(array $changes): void
    {
        $this->expectException(RuntimeExternalPayloadException::class);
        RuntimePayloadCompletionContext::parse(json_encode(array_replace($this->value(), $changes), JSON_THROW_ON_ERROR));
    }

    public static function invalidContexts(): array
    {
        return array_map(static fn (array $changes): array => [$changes], [
            ['schema' => 'unknown'], ['extra' => true], ['kind' => 'client'], ['operation' => 'start'],
            ['operation' => []], ['operation' => null], ['slot' => null], ['task_id' => []],
            ['task_id' => ''], ['task_id' => str_repeat('a', 256)], ['lease_owner' => "worker\n"],
            ['attempt' => 1], ['slot' => ['input']], ['slot' => ['failure', 'details']],
            ['kind' => 'workflow', 'attempt' => '1', 'slot' => ['commands', 0, 'result']],
            ['kind' => 'workflow', 'attempt' => 0, 'slot' => ['commands', 0, 'result']],
            ['kind' => 'workflow', 'attempt' => 1, 'slot' => ['commands', '0', 'result']],
            ['kind' => 'workflow', 'attempt' => 1, 'slot' => ['commands', -1, 'result']],
            ['kind' => 'query', 'attempt' => 1, 'slot' => ['result']],
        ]);
    }

    public function test_oversized_header_is_rejected_before_json_decode(): void
    {
        $this->expectException(RuntimeExternalPayloadException::class);
        RuntimePayloadCompletionContext::parse(str_repeat(' ', 4097));
    }

    public function test_prepared_operations_and_legacy_completion_share_one_workflow_allowance(): void
    {
        $legacy = RuntimePayloadCompletionContext::parse(json_encode($this->value('workflow', 'complete', ['commands', 0, 'result']), JSON_THROW_ON_ERROR));
        $identities = [];
        foreach ([
            ['local_activity_checkpoint', ['commands', 0, 'result'], ['checkpoint_id' => 'prefix-1']],
            ['local_activity_checkpoint', ['commands', 0, 'result'], ['checkpoint_id' => 'prefix-2']],
            ['cancellation_scope_checkpoint', ['commands', 0, 'result'], ['checkpoint_id' => 'prefix-1']],
            ['cancellation_scope_checkpoint', ['commands', 0, 'result'], ['checkpoint_id' => 'prefix-2']],
            ['local_activity_group_checkpoint', ['commands', 0, 'arguments'], ['checkpoint_id' => 'group-1']],
            ['local_activity_group_checkpoint', ['commands', 1, 'arguments'], ['checkpoint_id' => 'group-1']],
            ['local_activity_group_checkpoint', ['commands', 0, 'arguments'], ['checkpoint_id' => 'group-2']],
            ['local_activity_prepare', ['descriptor', 'arguments'], ['sequence' => 2]],
            ['local_activity_prepare', ['descriptor', 'arguments'], ['sequence' => 3]],
            ['local_activity_recover', ['descriptor', 'arguments'], ['sequence' => 2]],
            ['local_activity_outcome', ['report', 'result'], ['activity_attempt_id' => 'attempt-1']],
            ['local_activity_outcome', ['report', 'result'], ['activity_attempt_id' => 'attempt-2']],
        ] as [$operation, $slot, $identity]) {
            $value = array_replace($this->value('workflow', $operation, $slot), ['schema' => RuntimePayloadCompletionContext::PREPARED_SCHEMA], $identity);
            $context = RuntimePayloadCompletionContext::parse(json_encode($value, JSON_THROW_ON_ERROR));
            $this->assertSame($value, $context->toArray());
            $this->assertSame($legacy->scope('default'), $context->scope('default'));
            $this->assertNotSame($context->scope('default'), $context->scope('other'));
            $this->assertSame($context->slotIdentity(), RuntimePayloadCompletionContext::parse(json_encode(array_reverse($value, true), JSON_THROW_ON_ERROR))->slotIdentity());
            $identities[] = $context->slotIdentity();
        }
        $this->assertCount(count($identities), array_unique($identities));
    }

    #[DataProvider('invalidPreparedContexts')]
    public function test_prepared_context_requires_exact_claim_identity_and_operation_slot(array $changes): void
    {
        $this->expectException(RuntimeExternalPayloadException::class);
        $value = array_replace($this->value('workflow', 'local_activity_prepare', ['descriptor', 'arguments']),
            ['schema' => RuntimePayloadCompletionContext::PREPARED_SCHEMA, 'sequence' => 2], $changes);
        RuntimePayloadCompletionContext::parse(json_encode($value, JSON_THROW_ON_ERROR));
    }

    public static function invalidPreparedContexts(): array
    {
        return array_map(static fn (array $changes): array => [$changes], [
            ['sequence' => 0], ['sequence' => '2'], ['sequence' => null], ['sequence' => []],
            ['kind' => 'activity'], ['kind' => 'query'], ['operation' => 'complete'],
            ['slot' => ['report', 'result']], ['attempt' => '1'], ['attempt' => 0],
            ['checkpoint_id' => 'extra'], ['activity_attempt_id' => 'extra'],
            ['operation' => 'local_activity_checkpoint'], ['operation' => 'local_activity_outcome'],
            ['schema' => RuntimePayloadCompletionContext::SCHEMA],
        ]);
    }

    private function value(string $kind = 'activity', string $operation = 'complete', array $slot = ['result']): array
    {
        return ['schema' => RuntimePayloadCompletionContext::SCHEMA, 'kind' => $kind,
            'task_id' => 'task', 'attempt' => $kind === 'activity' ? 'attempt' : 1,
            'lease_owner' => 'worker', 'operation' => $operation, 'slot' => $slot];
    }
}
