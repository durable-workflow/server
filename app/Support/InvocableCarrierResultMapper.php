<?php

namespace App\Support;

final class InvocableCarrierResultMapper
{
    public const RESULT_SCHEMA = 'durable-workflow.v2.external-task-result';

    /**
     * @return array{action: string, payload: array<string, mixed>, reason: string}
     */
    public function map(array $envelope, string $taskId, string $attemptId, string $leaseOwner): array
    {
        if (! $this->validEnvelopeForTask($envelope, $taskId, $attemptId)) {
            return $this->malformed(
                $taskId,
                $attemptId,
                $leaseOwner,
                'Handler returned an invalid external task result envelope.',
                'invalid_envelope',
            );
        }

        $status = $this->stringValue($envelope['outcome']['status'] ?? null);

        if ($status === 'succeeded') {
            return [
                'action' => 'complete',
                'payload' => [
                    'activity_attempt_id' => $attemptId,
                    'lease_owner' => $leaseOwner,
                    'result' => $this->payloadEnvelope($envelope['result']['payload'] ?? null),
                ],
                'reason' => 'handler_succeeded',
            ];
        }

        if ($status === 'failed') {
            return [
                'action' => 'fail',
                'payload' => [
                    'activity_attempt_id' => $attemptId,
                    'lease_owner' => $leaseOwner,
                    'failure' => $this->failurePayload($envelope),
                ],
                'reason' => 'handler_failed',
            ];
        }

        return $this->malformed(
            $taskId,
            $attemptId,
            $leaseOwner,
            'Handler result envelope has an unsupported outcome status.',
            'unsupported_status',
        );
    }

    /**
     * @param  array<string, mixed>  $rawOutput
     * @return array{action: string, payload: array<string, mixed>, reason: string}
     */
    public function malformedTransportResult(
        string $taskId,
        string $attemptId,
        string $leaseOwner,
        string $message,
        array $rawOutput = [],
        bool $retryable = false,
    ): array {
        return [
            'action' => 'fail',
            'payload' => [
                'activity_attempt_id' => $attemptId,
                'lease_owner' => $leaseOwner,
                'failure' => array_filter([
                    'message' => $message,
                    'type' => 'MalformedExternalTaskOutput',
                    'kind' => 'malformed_output',
                    'retryable' => $retryable,
                    'non_retryable' => ! $retryable,
                    'cancelled' => false,
                    'malformed_output' => true,
                    'details' => $rawOutput === [] ? null : [
                        'codec' => 'json/plain',
                        'blob' => (string) json_encode($rawOutput, JSON_UNESCAPED_SLASHES),
                    ],
                ], static fn (mixed $value): bool => $value !== null),
            ],
            'reason' => 'malformed_transport_output',
        ];
    }

    private function validEnvelopeForTask(array $envelope, string $taskId, string $attemptId): bool
    {
        return ($envelope['schema'] ?? null) === self::RESULT_SCHEMA
            && ($envelope['version'] ?? null) === ExternalTaskResultContract::VERSION
            && ($envelope['task']['kind'] ?? null) === 'activity_task'
            && ($envelope['task']['id'] ?? null) === $taskId
            && ($envelope['task']['idempotency_key'] ?? null) === $attemptId
            && is_array($envelope['outcome'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function failurePayload(array $envelope): array
    {
        $failure = is_array($envelope['failure'] ?? null) ? $envelope['failure'] : [];
        $retryable = (bool) ($envelope['outcome']['retryable'] ?? false);

        return array_filter([
            'message' => $this->stringValue($failure['message'] ?? null) ?? 'External activity handler failed.',
            'type' => $this->stringValue($failure['type'] ?? null),
            'stack_trace' => $this->stringValue($failure['stack_trace'] ?? null),
            'kind' => $this->failureKind($failure['kind'] ?? null),
            'timeout_type' => $this->timeoutType($failure['timeout_type'] ?? null),
            'retryable' => $retryable,
            'non_retryable' => ! $retryable,
            'cancelled' => (bool) ($failure['cancelled'] ?? false),
            'malformed_output' => ($failure['kind'] ?? null) === 'malformed_output',
            'details' => $this->payloadEnvelope($failure['details'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payloadEnvelope(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        $codec = $this->stringValue($payload['codec'] ?? null);
        $blob = $payload['blob'] ?? null;

        if ($codec === null || ! is_string($blob)) {
            return null;
        }

        return [
            'codec' => $codec,
            'blob' => $blob,
        ];
    }

    private function failureKind(mixed $kind): string
    {
        $kind = $this->stringValue($kind);

        return in_array($kind, [
            'application',
            'timeout',
            'cancellation',
            'malformed_output',
            'handler_crash',
            'decode_failure',
            'unsupported_payload',
        ], true) ? $kind : 'application';
    }

    private function timeoutType(mixed $timeoutType): ?string
    {
        $timeoutType = $this->stringValue($timeoutType);

        return in_array($timeoutType, [
            'schedule_to_start',
            'start_to_close',
            'schedule_to_close',
            'heartbeat',
            'deadline_exceeded',
        ], true) ? $timeoutType : null;
    }

    /**
     * @return array{action: string, payload: array<string, mixed>, reason: string}
     */
    private function malformed(
        string $taskId,
        string $attemptId,
        string $leaseOwner,
        string $message,
        string $reason,
    ): array {
        return $this->malformedTransportResult(
            $taskId,
            $attemptId,
            $leaseOwner,
            $message,
            ['reason' => $reason],
        );
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
