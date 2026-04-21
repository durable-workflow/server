<?php

namespace App\Support;

use RuntimeException;

final class QueryTaskQueueFullException extends RuntimeException
{
    public function __construct(
        public readonly string $namespace,
        public readonly string $taskQueue,
        public readonly int $limit,
    ) {
        parent::__construct(sprintf(
            'Query task queue [%s] in namespace [%s] reached the configured pending limit of %d.',
            $taskQueue,
            $namespace,
            $limit,
        ));
    }
}
