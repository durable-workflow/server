<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2).'/scripts/conformance/server-parity/large-command-batches-probe.php';

final class ServerParityLargeCommandBatchesProbeTest extends TestCase
{
    private function refusal(array $poll, int $retries = 0): array
    {
        try {
            \largeCommandBatchesClaim($poll, 'select_key_overflow', $retries);
        } catch (RuntimeException $error) {
            $prefix = 'Large command batches: no workflow task for select_key_overflow ';
            self::assertStringStartsWith($prefix, $error->getMessage());
            self::assertStringNotContainsString('SECRET', $error->getMessage());
            self::assertLessThan(1024, strlen($error->getMessage()));

            return json_decode(substr($error->getMessage(), strlen($prefix)), true, flags: JSON_THROW_ON_ERROR);
        }
        self::fail('A response without a task was accepted.');
    }

    public function test_a_valid_claim_is_returned_unchanged(): void
    {
        $task = ['task_id' => 'task-1', 'lease_owner' => 'worker-1', 'workflow_task_attempt' => 1,
            'history_events' => [['payload' => 'SECRET-PAYLOAD']]];
        self::assertSame($task, \largeCommandBatchesClaim(['status' => 200, 'response' => ['task' => $task]], 'timers', 3));
        self::assertSame([], \largeCommandBatchesClaim(['status' => 200, 'response' => ['task' => []]], 'timers', 0));
    }

    public function test_a_final_lock_refusal_keeps_its_own_retry_count_and_hint(): void
    {
        $result = $this->refusal(['status' => 503, 'response' => ['task' => null, 'poll_status' => 'deferred',
            'reason' => 'backend_lock_pressure', 'outcome' => 'deferred', 'retryable' => true, 'retry_after_seconds' => 1,
            'message' => 'SECRET-MESSAGE', 'server_capabilities' => ['SECRET-CAPABILITY']]], 20);
        self::assertSame(503, $result['http_status']);
        self::assertSame('backend_lock_pressure', $result['reason']);
        self::assertSame([20, true, 1], [$result['poll_retries'], $result['retryable'], $result['retry_after_seconds']]);
    }

    public function test_an_empty_success_and_missing_task_remain_distinguishable(): void
    {
        $empty = $this->refusal(['status' => 200, 'response' => ['poll_status' => 'empty', 'task' => null]]);
        self::assertSame([200, 'empty', true, 'null'],
            [$empty['http_status'], $empty['poll_status'], $empty['task_present'], $empty['task_type']]);
        $missing = $this->refusal(['status' => 409, 'response' => ['reason' => 'stale_registration']]);
        self::assertSame([409, false, 'stale_registration'],
            [$missing['http_status'], $missing['task_present'], $missing['reason']]);
    }

    public function test_malformed_and_large_metadata_cannot_fill_the_failure_record(): void
    {
        $result = $this->refusal(['status' => 503, 'response' => ['task' => 'SECRET-TASK',
            'poll_status' => str_repeat('x', 100000), 'reason' => ['SECRET-NESTED'],
            'headers' => ['Authorization' => 'SECRET-TOKEN'], 'history_events' => ['SECRET-HISTORY']]]);
        self::assertSame('string', $result['task_type']);
        self::assertSame(96, strlen($result['poll_status']));
        self::assertNull($result['reason']);
        self::assertSame('string', $this->refusal(['status' => 502, 'response' => 'SECRET-BODY'])['response_type']);
    }
}
