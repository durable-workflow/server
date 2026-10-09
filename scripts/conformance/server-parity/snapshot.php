<?php

declare(strict_types=1);

// Both engines must be stopped. Hash files without opening SQLite or creating
// metadata. An optional earlier receipt requires the whole cohort to match.
if (! in_array($argc, [2, 3], true) || ! is_file($argv[1])) {
    throw new InvalidArgumentException('Usage: php snapshot.php SQLITE_FILE [BEFORE_JSON]');
}
$snapshot = [];
foreach (['database' => '', 'wal' => '-wal', 'journal' => '-journal'] as $key => $suffix) {
    $file = $argv[1].$suffix;
    // SQLite's read-only WAL open may create a zero-length WAL plus -shm.
    // Zero bytes contain no header or frames. Every nonempty original WAL,
    // rollback journal and database remains subject to an exact byte hash.
    $snapshot[$key] = is_file($file) && ! ($key === 'wal' && filesize($file) === 0)
        ? hash_file('sha256', $file) : null;
}
if ($argc === 3 && json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR) !== $snapshot) {
    throw new RuntimeException('Native preflight changed the published PHP database or journal cohort.');
}
echo json_encode($snapshot, JSON_THROW_ON_ERROR)."\n";
