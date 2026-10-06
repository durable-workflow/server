<?php

namespace App\Support;

final class HistoryPageToken
{
    public static function decode(?string $token): ?int
    {
        if (! is_string($token) || trim($token) === '') {
            return null;
        }

        $decoded = base64_decode($token, true);

        return is_string($decoded) && ctype_digit($decoded)
            ? (int) $decoded
            : null;
    }

    public static function encode(int $sequence): string
    {
        return base64_encode((string) $sequence);
    }
}
