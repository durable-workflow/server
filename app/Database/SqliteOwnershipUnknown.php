<?php

namespace App\Database;

use RuntimeException;

/** Fixed, redacted recovery diagnostics; never include a PDO message or path. */
final class SqliteOwnershipUnknown extends RuntimeException {}
