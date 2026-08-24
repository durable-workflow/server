<?php

namespace App\Support;

use Workflow\V2\Support\ExternalPayloads;

class ExternalPayloadEnvelopeService
{
    private const ACTIVITY_FAILURE_NEUTRAL_EXCEPTION_KEYS = [
        'type',
        'message',
        'code',
        'kind',
        'classification',
        'retryable',
        'non_retryable',
        'timeout_type',
        'cancelled',
        'malformed_output',
        'details',
        'details_payload_codec',
    ];

    private const EXPLICIT_DIAGNOSTIC_KEYS = [
        'diagnostics',
        'runtime_diagnostics',
    ];

    /**
     * Return the worker-protocol payload envelope for an encoded blob.
     *
     * This is an internal read-path presenter. Persisted provider references
     * remain `{codec, external_storage}` until the authenticated HTTP boundary
     * replaces them with opaque `{codec, external_payload}` references.
     * Ordinary strings remain inline as `{codec, blob}`.
     *
     * @return array{codec: string, blob: string}|array{codec: string, external_storage: array<string, mixed>}|null
     */
    public function workerEnvelope(?string $namespace, ?string $codec, ?string $blob): ?array
    {
        if ($blob === null) {
            return null;
        }

        $codec = $this->responseCodec($codec);

        if (ExternalPayloads::isStoredReference($blob)) {
            return ExternalPayloads::wireEnvelope($blob, $codec, $namespace);
        }

        return [
            'codec' => $codec,
            'blob' => $blob,
        ];
    }

    /**
     * Return a history payload value as a codec-tagged envelope when it is a blob.
     */
    public function historyValue(?string $namespace, ?string $codec, mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $codec = $this->responseCodec($codec);

        if (ExternalPayloads::isStoredReference($value)) {
            return ExternalPayloads::historyValue($value, $codec, $namespace);
        }

        return [
            'codec' => $codec,
            'blob' => $value,
        ];
    }

    /**
     * @param  array<int, mixed>  $events
     * @return array<int, mixed>
     */
    public function historyEvents(?string $namespace, array $events, ?string $fallbackCodec = null): array
    {
        foreach ($events as $index => $event) {
            if (! is_array($event)) {
                continue;
            }

            $payload = $event['payload'] ?? null;
            if (is_array($payload)) {
                $event['payload'] = $this->historyPayload(
                    $namespace,
                    $payload,
                    $fallbackCodec,
                    $this->stringValue($event['event_type'] ?? null),
                );
                $events[$index] = $event;
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function historyPayload(
        ?string $namespace,
        array $payload,
        ?string $fallbackCodec = null,
        ?string $eventType = null,
    ): array {
        $codec = $this->stringValue($payload['payload_codec'] ?? null) ?? $fallbackCodec;

        foreach (['arguments', 'result', 'output'] as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = $this->historyValue($namespace, $codec, $payload[$field]);
            }
        }

        if (isset($payload['command']) && is_array($payload['command'])) {
            $payload['command'] = $this->commandSnapshot($namespace, $payload['command'], $codec);
        }

        if (isset($payload['activity']) && is_array($payload['activity'])) {
            $payload['activity'] = $this->activitySnapshot($namespace, $payload['activity'], $codec);
        }

        if (isset($payload['exception']) && is_array($payload['exception'])) {
            $payload['exception'] = $this->failureSnapshot(
                $namespace,
                $payload['exception'],
                $codec,
                $this->redactActivityFailureRuntimeInternals($eventType, $payload),
                $this->isLegacyV1OpaqueExceptionProjection($eventType, $payload),
            );
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function commandSnapshot(?string $namespace, array $snapshot, ?string $fallbackCodec): array
    {
        $codec = $this->stringValue($snapshot['payload_codec'] ?? null) ?? $fallbackCodec;

        if (array_key_exists('payload', $snapshot)) {
            $snapshot['payload'] = $this->historyValue($namespace, $codec, $snapshot['payload']);
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function activitySnapshot(?string $namespace, array $snapshot, ?string $fallbackCodec): array
    {
        $codec = $this->stringValue($snapshot['payload_codec'] ?? null) ?? $fallbackCodec;

        foreach (['arguments', 'result'] as $field) {
            if (array_key_exists($field, $snapshot)) {
                $snapshot[$field] = $this->historyValue($namespace, $codec, $snapshot[$field]);
            }
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function failureSnapshot(
        ?string $namespace,
        array $snapshot,
        ?string $fallbackCodec,
        bool $redactRuntimeInternals = false,
        bool $preserveOpaqueProjectionValue = false,
    ): array {
        $codec = $this->stringValue($snapshot['details_payload_codec'] ?? null) ?? $fallbackCodec;

        if (array_key_exists('details', $snapshot)) {
            $snapshot['details'] = $this->historyValue($namespace, $codec, $snapshot['details']);
        }

        if (! $redactRuntimeInternals) {
            return $snapshot;
        }

        $neutral = [];

        foreach (self::ACTIVITY_FAILURE_NEUTRAL_EXCEPTION_KEYS as $key) {
            if (array_key_exists($key, $snapshot)) {
                $neutral[$key] = $snapshot[$key];
            }
        }

        foreach (self::EXPLICIT_DIAGNOSTIC_KEYS as $key) {
            if (isset($snapshot[$key]) && is_array($snapshot[$key])) {
                $neutral[$key] = $snapshot[$key];
            }
        }

        if (
            $preserveOpaqueProjectionValue
            && isset($snapshot['value'])
            && $this->isOpaqueLegacyPayloadDescriptor($snapshot['value'])
        ) {
            $neutral['value'] = $snapshot['value'];
        }

        return $neutral;
    }

    /** @param array<string, mixed> $payload */
    private function isLegacyV1OpaqueExceptionProjection(?string $eventType, array $payload): bool
    {
        $projection = is_array($payload['migration_projection'] ?? null)
            ? $payload['migration_projection']
            : [];

        return $eventType === 'ActivityFailed'
            && ($projection['source_record'] ?? null) === 'workflow_exception';
    }

    private function isOpaqueLegacyPayloadDescriptor(mixed $value): bool
    {
        return is_array($value)
            && is_bool($value['available'] ?? null)
            && ($value['encoding'] ?? null) === 'php-serialize'
            && array_key_exists('value', $value)
            && (is_string($value['value'] ?? null) || ($value['value'] ?? null) === null)
            && ($value['portable'] ?? null) === false
            && ($value['unsupported_reason'] ?? null) === 'legacy_php_serialization_not_portable'
            && ($value['remediation'] ?? null) === 'Treat the preserved value as opaque; export decoded JSON from the source application if portable payload access is required.';
    }

    /**
     * Default PHP Throwable payloads contain server-local class/file/line/trace
     * details. Activity failure history is a cross-runtime contract, so only
     * neutral failure fields and explicit diagnostics envelopes are emitted.
     *
     * @param  array<string, mixed>  $payload
     */
    private function redactActivityFailureRuntimeInternals(?string $eventType, array $payload): bool
    {
        if ($eventType === 'ActivityFailed') {
            return true;
        }

        return $eventType === null
            && (
                array_key_exists('activity_execution_id', $payload)
                || array_key_exists('activity_attempt_id', $payload)
                || isset($payload['activity'])
            );
    }

    private function responseCodec(?string $codec): string
    {
        return PayloadCodecContract::canonicalize($codec);
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
