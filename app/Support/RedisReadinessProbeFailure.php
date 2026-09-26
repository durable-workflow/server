<?php

namespace App\Support;

use RuntimeException;

final class RedisReadinessProbeFailure extends RuntimeException
{
    public const TIMEOUT = 'probe_timeout';

    public const CHILD_FAILURE = 'probe_child_failed';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
