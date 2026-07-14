<?php

namespace App\Support;

use InvalidArgumentException;

class ScheduleVisibilityQueryException extends InvalidArgumentException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'invalid_schedule_visibility_query',
    ) {
        parent::__construct($message);
    }
}
