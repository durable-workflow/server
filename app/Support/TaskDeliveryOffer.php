<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/** Bounded, sealed offer packets; these packets never grant execution. */
final class TaskDeliveryOffer
{
    public static function encode(array $data): string
    {
        $token = Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR));
        if (strlen($token) > 8192) {
            throw new TaskDeliveryOfferRejected('task_offer_too_large');
        }

        return $token;
    }

    public static function decode(mixed $token): array
    {
        try {
            $data = is_string($token) && strlen($token) <= 8192
                ? json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (DecryptException|JsonException) {
            $data = null;
        }
        if (! is_array($data) || ($data['schema'] ?? null) !== WorkerTaskDelivery::SCHEMA
            || ! is_string($data['task_id'] ?? null) || ! is_string($data['task_kind'] ?? null)
            || ! is_array($data['worker'] ?? null) || ! is_array($data['candidate'] ?? null)
            || ! is_int($data['offered_attempt'] ?? null) || ! is_int($data['expires_at_ms'] ?? null)
            || ! is_string($data['fingerprint'] ?? null) || ! is_string($data['delivery_id'] ?? null)) {
            throw new TaskDeliveryOfferRejected('task_offer_invalid');
        }

        return $data;
    }

    public static function identifier(array $data): string
    {
        return hash('sha256', json_encode([
            $data['schema'], $data['namespace'], $data['queue'], $data['route'], $data['poll_request_id'],
            $data['task_kinds'], $data['worker']['registration_id'], $data['worker']['registration_incarnation'],
            $data['task_id'], $data['task_kind'], $data['offered_attempt'], $data['fingerprint'],
        ], JSON_THROW_ON_ERROR));
    }

    public static function assertUnexpired(array $data): void
    {
        if (now()->getTimestampMs() >= $data['expires_at_ms']) {
            throw new TaskDeliveryOfferRejected('task_offer_expired');
        }
    }

    public static function acceptanceMilliseconds(): int
    {
        return max(1000, min(10000, (int) config('server.polling.task_offer_accept_seconds', 2) * 1000));
    }
}
