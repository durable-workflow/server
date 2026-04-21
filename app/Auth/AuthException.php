<?php

namespace App\Auth;

use RuntimeException;

final class AuthException extends RuntimeException
{
    private function __construct(
        private readonly int $status,
        private readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function configuration(string $message): self
    {
        return new self(500, 'server_error', $message);
    }

    public static function unauthenticated(string $message): self
    {
        return new self(401, 'unauthorized', $message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
