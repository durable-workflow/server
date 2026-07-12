<?php

namespace App\Support;

use RuntimeException;

final class LongPollCapacityExhaustedException extends RuntimeException
{
    public function __construct(
        public readonly string $pool,
    ) {
        parent::__construct("The {$pool} long-poll wait capacity is exhausted.");
    }
}
