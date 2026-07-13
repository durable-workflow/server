<?php

declare(strict_types=1);

$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

if ($server === false) {
    fwrite(STDERR, sprintf("Unable to start Redis transport fixture (%d): %s\n", $errorCode, $errorMessage));
    exit(1);
}

$fallbackServer = stream_socket_server('tcp://127.0.0.1:0', $fallbackErrorCode, $fallbackErrorMessage);

if ($fallbackServer === false) {
    fwrite(STDERR, sprintf("Unable to start fallback Redis transport fixture (%d): %s\n", $fallbackErrorCode, $fallbackErrorMessage));
    exit(1);
}

$address = stream_socket_get_name($server, false);
$fallbackAddress = stream_socket_get_name($fallbackServer, false);

if (! is_string($address) || ! str_contains($address, ':')
    || ! is_string($fallbackAddress) || ! str_contains($fallbackAddress, ':')) {
    fwrite(STDERR, "Redis transport fixture did not receive a TCP address.\n");
    exit(1);
}

fwrite(STDOUT, json_encode([
    'selected_port' => (int) substr($address, strrpos($address, ':') + 1),
    'fallback_port' => (int) substr($fallbackAddress, strrpos($fallbackAddress, ':') + 1),
], JSON_THROW_ON_ERROR)."\n");
fflush(STDOUT);

$connectionCount = 0;
$values = [];

while (true) {
    fwrite(STDERR, sprintf("selected:accepting:%d\n", $connectionCount + 1));
    fflush(STDERR);

    $read = [$server, $fallbackServer];
    $write = null;
    $except = null;

    if (stream_select($read, $write, $except, null) === false) {
        continue;
    }

    if (in_array($fallbackServer, $read, true)) {
        $fallback = stream_socket_accept($fallbackServer, 0);

        if (is_resource($fallback)) {
            fwrite(STDERR, "fallback:accepted\n");
            fflush(STDERR);
            fclose($fallback);
        }
    }

    if (! in_array($server, $read, true)) {
        continue;
    }

    $connection = stream_socket_accept($server, 0);

    if (! is_resource($connection)) {
        continue;
    }

    $connectionCount++;
    $slow = $connectionCount === 1;
    fwrite(STDERR, sprintf("selected:%d\n", $connectionCount));
    fflush(STDERR);

    while (($arguments = readCommand($connection)) !== null) {
        $command = strtoupper($arguments[0] ?? '');
        fwrite(STDERR, sprintf("selected:%d:command:%s\n", $connectionCount, $command));
        fflush(STDERR);

        if ($slow) {
            fwrite(STDERR, "slow:".$command."\n");
            fflush(STDERR);

            // Every setup and cache phase consumes meaningful latency without
            // exceeding its readiness transport limit. DEL then stalls beyond
            // that limit, proving the cumulative multi-phase path is bounded
            // without an asynchronous process signal.
            usleep($command === 'DEL' ? 300_000 : 120_000);
        }

        if (in_array($command, ['AUTH', 'SELECT'], true)) {
            @fwrite($connection, "+OK\r\n");
        } elseif (in_array($command, ['SET', 'SETEX'], true) && isset($arguments[1], $arguments[2])) {
            $valueIndex = $command === 'SETEX' ? 3 : 2;

            if (! isset($arguments[$valueIndex])) {
                @fwrite($connection, "-ERR missing test value\r\n");

                continue;
            }

            $values[$arguments[1]] = $arguments[$valueIndex];
            @fwrite($connection, "+OK\r\n");
        } elseif ($command === 'GET' && isset($arguments[1])) {
            $value = $values[$arguments[1]] ?? null;
            @fwrite($connection, $value === null ? '$-1'."\r\n" : '$'.strlen($value)."\r\n".$value."\r\n");
        } elseif ($command === 'DEL' && isset($arguments[1])) {
            $deleted = array_key_exists($arguments[1], $values) ? 1 : 0;
            unset($values[$arguments[1]]);
            @fwrite($connection, ':'.$deleted."\r\n");
        } else {
            @fwrite($connection, "-ERR unsupported test command\r\n");
        }
    }

    fclose($connection);
    fwrite(STDERR, sprintf("selected:%d:closed\n", $connectionCount));
    fflush(STDERR);
}

/**
 * @param  resource  $connection
 * @return list<string>|null
 */
function readCommand($connection): ?array
{
    $header = fgets($connection);

    if (! is_string($header) || ! str_starts_with($header, '*')) {
        return null;
    }

    $count = (int) trim(substr($header, 1));
    $arguments = [];

    for ($index = 0; $index < $count; $index++) {
        $lengthHeader = fgets($connection);

        if (! is_string($lengthHeader) || ! str_starts_with($lengthHeader, '$')) {
            return null;
        }

        $length = (int) trim(substr($lengthHeader, 1));
        $argument = '';

        while (strlen($argument) < $length + 2) {
            $chunk = fread($connection, $length + 2 - strlen($argument));

            if (! is_string($chunk) || $chunk === '') {
                return null;
            }

            $argument .= $chunk;
        }

        $arguments[] = substr($argument, 0, $length);
    }

    return $arguments;
}
