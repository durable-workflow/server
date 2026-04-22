<?php

namespace Tests\Unit;

use App\Support\InvocableCarrierResultMapper;
use PHPUnit\Framework\TestCase;

class InvocableCarrierResultMapperTest extends TestCase
{
    public function test_success_envelope_maps_to_activity_complete_payload(): void
    {
        $mapped = (new InvocableCarrierResultMapper)->map(
            $this->successEnvelope(),
            'acttask_123',
            'attempt_123',
            'invocable-carrier',
        );

        $this->assertSame('complete', $mapped['action']);
        $this->assertSame('handler_succeeded', $mapped['reason']);
        $this->assertSame('attempt_123', $mapped['payload']['activity_attempt_id']);
        $this->assertSame('invocable-carrier', $mapped['payload']['lease_owner']);
        $this->assertSame([
            'codec' => 'json/plain',
            'blob' => '{"ok":true}',
        ], $mapped['payload']['result']);
    }

    public function test_failure_envelope_maps_to_activity_fail_payload(): void
    {
        $mapped = (new InvocableCarrierResultMapper)->map(
            $this->failureEnvelope(),
            'acttask_123',
            'attempt_123',
            'invocable-carrier',
        );

        $this->assertSame('fail', $mapped['action']);
        $this->assertSame('handler_failed', $mapped['reason']);
        $this->assertSame('attempt_123', $mapped['payload']['activity_attempt_id']);
        $this->assertSame('invocable-carrier', $mapped['payload']['lease_owner']);
        $this->assertSame('ProviderTimeout', $mapped['payload']['failure']['type']);
        $this->assertSame('timeout', $mapped['payload']['failure']['kind']);
        $this->assertSame('deadline_exceeded', $mapped['payload']['failure']['timeout_type']);
        $this->assertTrue($mapped['payload']['failure']['retryable']);
        $this->assertFalse($mapped['payload']['failure']['non_retryable']);
        $this->assertSame([
            'codec' => 'json/plain',
            'blob' => '{"provider":"billing"}',
        ], $mapped['payload']['failure']['details']);
    }

    public function test_invalid_envelope_fails_closed_as_malformed_output(): void
    {
        $mapped = (new InvocableCarrierResultMapper)->map(
            [
                'schema' => 'durable-workflow.v2.external-task-result',
                'version' => 1,
                'outcome' => ['status' => 'succeeded'],
                'task' => [
                    'id' => 'different-task',
                    'kind' => 'activity_task',
                    'attempt' => 1,
                    'idempotency_key' => 'attempt_123',
                ],
            ],
            'acttask_123',
            'attempt_123',
            'invocable-carrier',
        );

        $this->assertSame('fail', $mapped['action']);
        $this->assertSame('malformed_transport_output', $mapped['reason']);
        $this->assertSame('malformed_output', $mapped['payload']['failure']['kind']);
        $this->assertSame('MalformedExternalTaskOutput', $mapped['payload']['failure']['type']);
        $this->assertFalse($mapped['payload']['failure']['retryable']);
        $this->assertTrue($mapped['payload']['failure']['non_retryable']);
        $this->assertTrue($mapped['payload']['failure']['malformed_output']);
    }

    public function test_malformed_transport_output_can_preserve_raw_diagnostics(): void
    {
        $mapped = (new InvocableCarrierResultMapper)->malformedTransportResult(
            'acttask_123',
            'attempt_123',
            'invocable-carrier',
            'Handler returned invalid JSON.',
            [
                'status_code' => 502,
                'stdout_preview' => '{not-json',
            ],
            retryable: true,
        );

        $this->assertSame('fail', $mapped['action']);
        $this->assertSame('Handler returned invalid JSON.', $mapped['payload']['failure']['message']);
        $this->assertTrue($mapped['payload']['failure']['retryable']);
        $this->assertFalse($mapped['payload']['failure']['non_retryable']);
        $this->assertSame('json/plain', $mapped['payload']['failure']['details']['codec']);
        $this->assertStringContainsString('status_code', $mapped['payload']['failure']['details']['blob']);
    }

    /**
     * @return array<string, mixed>
     */
    private function successEnvelope(): array
    {
        return [
            'schema' => 'durable-workflow.v2.external-task-result',
            'version' => 1,
            'outcome' => [
                'status' => 'succeeded',
                'recorded' => false,
            ],
            'task' => $this->task(),
            'result' => [
                'payload' => [
                    'codec' => 'json/plain',
                    'blob' => '{"ok":true}',
                ],
                'metadata' => null,
            ],
            'metadata' => ['duration_ms' => 42],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failureEnvelope(): array
    {
        return [
            'schema' => 'durable-workflow.v2.external-task-result',
            'version' => 1,
            'outcome' => [
                'status' => 'failed',
                'retryable' => true,
                'recorded' => false,
            ],
            'task' => $this->task(),
            'failure' => [
                'kind' => 'timeout',
                'classification' => 'deadline_exceeded',
                'message' => 'Deadline exceeded while waiting for billing provider.',
                'type' => 'ProviderTimeout',
                'stack_trace' => null,
                'timeout_type' => 'deadline_exceeded',
                'cancelled' => false,
                'details' => [
                    'codec' => 'json/plain',
                    'blob' => '{"provider":"billing"}',
                ],
            ],
            'metadata' => ['duration_ms' => 5000],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function task(): array
    {
        return [
            'id' => 'acttask_123',
            'kind' => 'activity_task',
            'attempt' => 1,
            'idempotency_key' => 'attempt_123',
        ];
    }
}
