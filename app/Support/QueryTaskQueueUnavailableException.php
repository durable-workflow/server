<?php

namespace App\Support;

use RuntimeException;
use Throwable;

final class QueryTaskQueueUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly string $namespace,
        public readonly string $taskQueue,
        string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf(
            'Query task queue [%s] in namespace [%s] is unavailable: %s',
            $taskQueue,
            $namespace,
            $reason,
        ), 0, $previous);
    }
}
