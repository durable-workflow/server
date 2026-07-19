<?php

declare(strict_types=1);

/**
 * @param  list<array<string, mixed>>  $events
 * @param  callable(array<mixed>|string): mixed  $decodeEnvelope
 * @return list<int>
 */
function php_sdk_decoded_signal_inputs(array $events, string $signalName, callable $decodeEnvelope): array
{
    if ($signalName === '') {
        throw new InvalidArgumentException('A signal name is required when decoding signal inputs.');
    }

    $inputs = [];
    foreach ($events as $event) {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        if (($payload['signal_name'] ?? null) !== $signalName) {
            continue;
        }

        $raw = $payload['value'] ?? $payload['input'] ?? $payload['arguments'] ?? null;
        $decoded = is_array($raw) || is_string($raw) ? $decodeEnvelope($raw) : [];
        $arguments = is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded];
        $inputs[] = (int) ($arguments[0] ?? 0);
    }

    return $inputs;
}
