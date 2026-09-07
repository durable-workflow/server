<?php

namespace App\Auth;

use RuntimeException;
use Throwable;

final class AuthException extends RuntimeException
{
    private function __construct(
        private readonly int $status,
        private readonly string $reason,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function configuration(string $message, ?Throwable $previous = null): self
    {
        return new self(500, 'server_error', $message, $previous);
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
