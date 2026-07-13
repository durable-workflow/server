<?php

declare(strict_types=1);

$input = (string) stream_get_contents(STDIN);

$mode = $argv[1] ?? '';

if ($mode === 'timeout') {
    usleep(3_000_000);
    exit(0);
}

if ($mode === 'crash') {
    // Simulate an untrusted connector placing selected configuration in both
    // output streams. The parent must retain neither stream in its exception.
    fwrite(STDOUT, str_repeat($input, 256));
    fwrite(STDERR, str_repeat($input, 256));
    exit(17);
}

if ($mode === 'malformed') {
    fwrite(STDOUT, 'not-json');
    exit(0);
}

exit(2);
