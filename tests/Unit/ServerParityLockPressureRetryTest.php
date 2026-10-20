<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/scripts/conformance/server-parity/lock-pressure-retry.php';

final class ServerParityLockPressureRetryTest extends TestCase
{
    private const POLL = 'http://parity-server:8080/api/worker/workflow-tasks/poll';

    /** @return array<string, mixed> */
    private function refusal(array $overrides = []): array
    {
        return array_replace([
            'task' => null,
            'poll_status' => 'backend_lock_pressure',
            'reason' => 'backend_lock_pressure',
            'retry_after_seconds' => 1,
        ], $overrides);
    }

    public function test_documented_poll_refusal_waits_for_the_advertised_interval(): void
    {
        self::assertSame(1.0, \lockPressurePollRetryDelay('POST', self::POLL, 503, $this->refusal(), 0, 0.0));
        self::assertSame(1.0, \lockPressurePollRetryDelay('POST',
            'http://parity-server:8080/api/worker/activity-tasks/poll', 503, $this->refusal(), 3, 2.0));
        self::assertSame(5.0, \lockPressurePollRetryDelay('POST', self::POLL, 503,
            $this->refusal(['retry_after_seconds' => 60]), 0, 0.0));
    }

    public function test_other_requests_statuses_and_bodies_are_never_retried(): void
    {
        foreach ([
            ['GET', self::POLL, 503, $this->refusal()],
            ['POST', 'http://parity-server:8080/api/worker/workflow-tasks/task/complete', 503, $this->refusal()],
            ['POST', self::POLL, 409, $this->refusal()],
            ['POST', self::POLL, 500, $this->refusal()],
            ['POST', self::POLL, null, $this->refusal()],
            ['POST', self::POLL, 503, $this->refusal(['reason' => 'backend_unavailable'])],
            ['POST', self::POLL, 503, $this->refusal(['task' => ['task_id' => 'claimed']])],
            ['POST', self::POLL, 503, array_diff_key($this->refusal(), ['task' => true])],
            ['POST', self::POLL, 503, $this->refusal(['retry_after_seconds' => 'soon'])],
            ['POST', self::POLL, 503, null],
        ] as $index => [$method, $uri, $status, $response]) {
            self::assertNull(\lockPressurePollRetryDelay($method, $uri, $status, $response, 0, 0.0), (string) $index);
        }
    }

    public function test_documented_operation_refusals_are_retried_and_polls_keep_their_rules(): void
    {
        $deferred = ['reason' => 'backend_lock_pressure', 'outcome' => 'deferred', 'retryable' => true, 'retry_after_seconds' => 1];
        $complete = 'http://parity-server:8080/api/worker/workflow-tasks/task/complete';
        $start = 'http://parity-server:8080/api/workflows';
        self::assertSame(1.0, \lockPressureOperationRetryDelay('POST', $complete, 503, $deferred, 0, 0.0));
        self::assertSame(1.0, \lockPressureOperationRetryDelay('POST', $start, 503,
            ['reason' => 'backend_lock_pressure', 'retryable' => true, 'retry_after_seconds' => 1], 0, 0.0));
        self::assertSame(1.0, \lockPressureOperationRetryDelay('POST', self::POLL, 503, $this->refusal(), 0, 0.0));
        foreach ([
            [$complete, 503, array_replace($deferred, ['retryable' => false])],
            [$complete, 503, array_diff_key($deferred, ['retryable' => true])],
            [$complete, 503, array_replace($deferred, ['reason' => 'backend_unavailable'])],
            [$complete, 409, $deferred],
            [$complete, 503, null],
            // A poll refusal still needs task null, even through this entry point.
            [self::POLL, 503, $deferred],
        ] as $index => [$uri, $status, $response]) {
            self::assertNull(\lockPressureOperationRetryDelay('POST', $uri, $status, $response, 0, 0.0), (string) $index);
        }
        self::assertNull(\lockPressureOperationRetryDelay('POST', $complete, 503, $deferred, 15, 0.0));
        self::assertNull(\lockPressureOperationRetryDelay('POST', $complete, 503, $deferred, 2, 29.5));
    }

    public function test_retries_stop_at_the_attempt_and_wait_budgets(): void
    {
        self::assertNull(\lockPressurePollRetryDelay('POST', self::POLL, 503, $this->refusal(), 15, 0.0));
        self::assertNull(\lockPressurePollRetryDelay('POST', self::POLL, 503, $this->refusal(), 2, 29.5));
        self::assertSame(1.0, \lockPressurePollRetryDelay('POST', self::POLL, 503, $this->refusal(), 14, 29.0));
    }
}
