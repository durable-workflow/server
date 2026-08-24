<?php

namespace App\Support;

use RuntimeException;
use Throwable;

final class RuntimeExternalPayloadCleanupStorageFailure extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('Runtime external payload backing-object deletion failed.', 0, $previous);
    }
}
