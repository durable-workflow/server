<?php

declare(strict_types=1);

namespace App\Support {
    function mkdir(string $directory, int $permissions = 0777, bool $recursive = false): bool
    {
        // Reproduce a competing writer creating the directory between is_dir and mkdir.
        \mkdir($directory, $permissions, $recursive);
        \trigger_error('mkdir(): File exists', E_USER_WARNING);

        return false;
    }
}

namespace {
    use App\Support\RuntimeLocalExternalPayloadStorage;

    require __DIR__.'/../../vendor/autoload.php';

    set_error_handler(static function (int $severity, string $message): bool {
        if ((error_reporting() & $severity) !== 0) {
            throw new ErrorException($message, 0, $severity);
        }

        return false;
    });

    $payload = base64_decode($argv[2], true);
    if (! is_string($payload)) {
        throw new RuntimeException('Invalid test payload.');
    }

    $driver = new RuntimeLocalExternalPayloadStorage($argv[1]);
    echo $driver->put($payload, hash('sha256', $payload), 'avro');
}
