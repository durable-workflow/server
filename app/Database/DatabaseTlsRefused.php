<?php

namespace App\Database;

final class DatabaseTlsRefused extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($reason.': '.$message, 0, $previous);
    }
}
