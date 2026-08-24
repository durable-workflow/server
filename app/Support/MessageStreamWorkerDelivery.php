<?php

declare(strict_types=1);

namespace App\Support;

use Workflow\Serializers\Serializer;

/**
 * Resolves Server-owned stream payload references only when history crosses
 * the worker boundary. Durable stream rows and signal history stay
 * reference-backed.
 */
final class MessageStreamWorkerDelivery
{
    public function __construct(
        private readonly NamespaceExternalPayloadStorage $externalPayloadStorage,
    ) {}

    public function signalArguments(
        ?string $namespace,
        ?string $signalName,
        mixed $argumentsEnvelope,
    ): mixed {
        if ($signalName !== MessageStreamsContract::INTERNAL_SIGNAL
            || ! is_array($argumentsEnvelope)) {
            return $argumentsEnvelope;
        }

        $storage = $this->externalPayloadStorage->driverFor($namespace);
        $resolved = AvroPayloadEnvelopeResolver::resolve(
            $argumentsEnvelope,
            'message_stream_signal_arguments',
            $storage,
        );
        $codec = PayloadCodecContract::canonicalize($resolved['codec'] ?? null);
        $blob = $resolved['blob'] ?? null;

        if (! is_string($blob)) {
            return $argumentsEnvelope;
        }

        $arguments = Serializer::unserializeWithCodec($codec, $blob);
        if (! is_array($arguments) || count($arguments) !== 1 || ! is_array($arguments[0])) {
            return $argumentsEnvelope;
        }

        $delivery = $arguments[0];
        if (($delivery['schema'] ?? null) !== MessageStreamsContract::MESSAGE_SCHEMA
            || ! is_array($delivery['payload_envelope'] ?? null)) {
            return $argumentsEnvelope;
        }

        $payload = AvroPayloadEnvelopeResolver::resolve(
            $delivery['payload_envelope'],
            'message_stream_payload_envelope',
            $storage,
        );
        $payloadCodec = PayloadCodecContract::canonicalize($payload['codec'] ?? null);
        $payloadBlob = $payload['blob'] ?? null;

        if (! is_string($payloadBlob)) {
            return $argumentsEnvelope;
        }

        $arguments[0]['payload_envelope'] = [
            'codec' => $payloadCodec,
            'blob' => $payloadBlob,
        ];

        return [
            'codec' => $codec,
            'blob' => Serializer::serializeWithCodec($codec, $arguments),
        ];
    }

    /**
     * @param  array<int, mixed>  $events
     * @return array<int, mixed>
     */
    public function historyEvents(?string $namespace, array $events): array
    {
        foreach ($events as $index => $event) {
            if (! is_array($event) || ($event['event_type'] ?? null) !== 'SignalReceived') {
                continue;
            }

            $payload = $event['payload'] ?? null;
            if (! is_array($payload)
                || ($payload['signal_name'] ?? null) !== MessageStreamsContract::INTERNAL_SIGNAL) {
                continue;
            }

            $payload['arguments'] = $this->signalArguments(
                $namespace,
                MessageStreamsContract::INTERNAL_SIGNAL,
                $payload['arguments'] ?? null,
            );
            $event['payload'] = $payload;
            $events[$index] = $event;
        }

        return $events;
    }
}
