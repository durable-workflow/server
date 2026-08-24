<?php

namespace App\Support;

use Exception;

class RuntimeExternalPayloadException extends Exception
{
    public function __construct(
        public readonly string $reason,
        public readonly int $status,
        public readonly bool $retryable,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
