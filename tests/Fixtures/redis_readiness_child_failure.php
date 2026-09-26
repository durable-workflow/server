<?php

declare(strict_types=1);

$input = (string) stream_get_contents(STDIN);

$mode = $argv[1] ?? '';

if ($mode === 'success') {
    $request = unserialize($input, ['allowed_classes' => false]);
    if (! is_array($request) || ! is_string($request['value'] ?? null)) {
        exit(2);
    }

    echo json_encode(['ok' => true, 'value' => $request['value']], JSON_THROW_ON_ERROR);
    exit(0);
}

if ($mode === 'timeout') {
    usleep(3_000_000);
    exit(0);
}

if ($mode === 'staged_timeout') {
    fwrite(STDERR, "DW_REDIS_READY_STAGE entry 0\nDW_REDIS_READY_STAGE connecting 12\n");
    fwrite(STDERR, "DW_REDIS_READY_STAGE private-endpoint 13\n".$input);
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

if ($mode === 'staged_crash') {
    fwrite(STDERR, "DW_REDIS_READY_STAGE connected 15\n".str_repeat($input, 256));
    exit(17);
}

if ($mode === 'malformed') {
    fwrite(STDOUT, 'not-json');
    exit(0);
}

exit(2);
