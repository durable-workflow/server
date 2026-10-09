<?php

namespace App\Database;

use RuntimeException;

final class PhpDatabaseRefused extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('php_database_refused: This database has a Rust Server schema marker. PHP cannot use it. Restart Rust, or restore the complete pre-upgrade backup into a separate database. Do not remove the marker or run a mixed PHP/Rust fleet.');
    }
}
