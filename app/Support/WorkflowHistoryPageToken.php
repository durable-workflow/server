<?php

namespace App\Support;

final class WorkflowHistoryPageToken
{
    public static function encode(int $sequence): string
    {
        return base64_encode((string) $sequence);
    }

    public static function decode(?string $token): ?int
    {
        if (! is_string($token) || trim($token) === '') {
            return null;
        }
        $decoded = base64_decode($token, true);
        if (! is_string($decoded) || ! ctype_digit($decoded)) {
            return null;
        }

        return (int) $decoded;
    }
}
