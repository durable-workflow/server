<?php

declare(strict_types=1);

use App\Support\RedisReadinessProcess;
use Illuminate\Redis\RedisManager;

require dirname(__DIR__).'/vendor/autoload.php';

$application = require dirname(__DIR__).'/bootstrap/app.php';
$client = null;
$stdout = '';
$exitCode = 0;
$roundTripCompleted = false;

try {
    $serialized = stream_get_contents(STDIN, 65_537);

    if (! is_string($serialized) || strlen($serialized) > 65_536) {
        throw new RuntimeException('Redis readiness input exceeds the safety limit.');
    }

    $input = @unserialize($serialized, ['allowed_classes' => false]);

    if (! is_array($input)
        || ($input['version'] ?? null) !== 1
        || ! is_string($input['connection_name'] ?? null)
        || ! is_array($input['configuration'] ?? null)
        || ! is_string($input['key'] ?? null)
        || ! is_string($input['value'] ?? null)
        || ! is_int($input['ttl_seconds'] ?? null)) {
        throw new RuntimeException('Redis readiness input is invalid.');
    }

    $manager = new RedisManager(
        $application,
        (string) ($input['configuration']['client'] ?? 'phpredis'),
        $input['configuration'],
    );
    $connection = $manager->connection($input['connection_name']);
    $client = $connection->client();

    // The official Laravel manager and connector retain all supported client
    // and connection semantics. The raw client avoids only Laravel's automatic
    // reconnect-on-command-error wrapper inside this disposable process.
    $client->setex($input['key'], max(1, $input['ttl_seconds']), $input['value']);
    $read = $client->get($input['key']);
    $client->del($input['key']);

    $stdout = json_encode([
        'ok' => true,
        'value' => is_string($read) ? $read : '',
    ], JSON_THROW_ON_ERROR);
    $roundTripCompleted = true;
} catch (Throwable) {
    $exitCode = 1;
} finally {
    if ($roundTripCompleted && is_object($client)) {
        try {
            if (method_exists($client, 'close')) {
                $client->close();
            } elseif (method_exists($client, 'disconnect')) {
                $client->disconnect();
            }
        } catch (Throwable) {
            // The client is non-persistent and the process exits immediately.
        }
    }

    // A failed phpredis command can leave extension state that reconnects when
    // close() is called. On failure this disposable, non-persistent process
    // exits immediately and lets the OS close its socket instead.
}

if ($exitCode !== 0) {
    // Connector exceptions can embed the selected Redis URL, credentials, or
    // endpoint. Emit only the stable category understood by the parent.
    fwrite(STDERR, RedisReadinessProcess::FAILURE_MESSAGE);
    exit($exitCode);
}

fwrite(STDOUT, $stdout);
