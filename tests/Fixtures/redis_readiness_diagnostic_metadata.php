<?php

declare(strict_types=1);

$input = (string) stream_get_contents(STDIN);
$entry = match ($argv[1]) {
    'valid' => (string) hrtime(true),
    'past' => (string) (hrtime(true) - 11_000_000_000),
    'future' => (string) (hrtime(true) + 11_000_000_000),
    'near_future' => (string) (hrtime(true) + 1_000_000_000),
    'leading_zero' => '0'.hrtime(true),
    default => $argv[1],
};
fwrite(STDERR, 'DW_REDIS_READY_ENTRY_NS '.$entry."\n");
fwrite(STDERR, 'DW_REDIS_READY_VALUE_MATCH '.$argv[2]."\n");
fwrite(STDERR, "DW_REDIS_READY_STAGE private-endpoint 12\nDW_REDIS_READY_STAGE encoded 10001\nDW_REDIS_READY_STAGE encoded 13\n");
fwrite(STDERR, str_repeat($input, 512));
exit(17);
