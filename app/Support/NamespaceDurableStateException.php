<?php

namespace App\Support;

use RuntimeException;
use Throwable;

final class NamespaceDurableStateException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $status,
        public readonly bool $retryable,
        string $message,
        public readonly ?string $resource = null,
        public readonly ?int $currentValue = null,
        public readonly ?int $configuredLimit = null,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
