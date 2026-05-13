<?php

namespace App\Support;

use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloads;

class ExternalPayloadEnvelopeService
{
    public function __construct(
        private readonly NamespaceExternalPayloadStorage $externalPayloadStorage,
    ) {}

    /**
     * Return the worker-protocol payload envelope for an encoded blob.
     *
     * Small payloads remain inline as `{codec, blob}`. Payloads larger than
     * the namespace threshold are written through the configured external
     * storage driver and returned as `{codec, external_storage}`.
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

        $driver = $this->driver($namespace);

        if ($this->shouldExternalize($namespace, $blob, $driver)) {
            return [
                'codec' => $codec,
                'external_storage' => $this->storeExternalPayload($driver, $blob, $codec),
            ];
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

        $driver = $this->driver($namespace);

        if ($this->shouldExternalize($namespace, $value, $driver)) {
            return [
                'codec' => $codec,
                'external_storage' => $this->storeExternalPayload($driver, $value, $codec),
            ];
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
                $event['payload'] = $this->historyPayload($namespace, $payload, $fallbackCodec);
                $events[$index] = $event;
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function historyPayload(?string $namespace, array $payload, ?string $fallbackCodec = null): array
    {
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
            $payload['exception'] = $this->failureSnapshot($namespace, $payload['exception'], $codec);
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
    private function failureSnapshot(?string $namespace, array $snapshot, ?string $fallbackCodec): array
    {
        $codec = $this->stringValue($snapshot['details_payload_codec'] ?? null) ?? $fallbackCodec;

        if (array_key_exists('details', $snapshot)) {
            $snapshot['details'] = $this->historyValue($namespace, $codec, $snapshot['details']);
        }

        return $snapshot;
    }

    private function shouldExternalize(
        ?string $namespace,
        string $blob,
        ?ExternalPayloadStorageDriver $driver,
    ): bool {
        $threshold = $this->thresholdBytes($namespace);

        return $threshold !== null
            && strlen($blob) > $threshold
            && $driver instanceof ExternalPayloadStorageDriver;
    }

    private function driver(?string $namespace): ?ExternalPayloadStorageDriver
    {
        return $this->externalPayloadStorage->driverFor($namespace);
    }

    private function thresholdBytes(?string $namespace): ?int
    {
        return $this->externalPayloadStorage->thresholdBytesFor($namespace);
    }

    /**
     * @return array{schema: string, uri: string, sha256: string, size_bytes: int, codec: string}
     */
    private function storeExternalPayload(ExternalPayloadStorageDriver $driver, string $blob, string $codec): array
    {
        $sha256 = hash('sha256', $blob);

        return [
            'schema' => ExternalPayloadReference::SCHEMA,
            'uri' => $driver->put($blob, $sha256, $codec),
            'sha256' => $sha256,
            'size_bytes' => strlen($blob),
            'codec' => $codec,
        ];
    }

    private function responseCodec(?string $codec): string
    {
        return is_string($codec) && $codec !== ''
            ? $codec
            : CodecRegistry::defaultCodec();
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
