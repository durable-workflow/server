<?php

declare(strict_types=1);

use App\Support\RedisReadinessProcess;
use Illuminate\Redis\RedisManager;

$diagnosticStartedAt = hrtime(true);
$diagnosticsEnabled = getenv('DW_REDIS_READINESS_DIAGNOSTICS') === '1';
if ($diagnosticsEnabled) {
    @fwrite(STDERR, 'DW_REDIS_READY_ENTRY_NS '.$diagnosticStartedAt."\n");
}
$markStage = static function (string $stage) use ($diagnosticStartedAt, $diagnosticsEnabled): void {
    if (! $diagnosticsEnabled) {
        return;
    }

    // Fixed stage names and elapsed time only. Connector errors may contain
    // selected endpoints or credentials and must never cross this boundary.
    @fwrite(STDERR, sprintf(
        "DW_REDIS_READY_STAGE %s %d\n",
        $stage,
        intdiv(hrtime(true) - $diagnosticStartedAt, 1_000_000),
    ));
};
$markStage('entry');
require dirname(__DIR__).'/vendor/autoload.php';
$markStage('autoloaded');

$application = require dirname(__DIR__).'/bootstrap/app.php';
$markStage('bootstrapped');
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
    $markStage('input_decoded');

    $manager = new RedisManager(
        $application,
        (string) ($input['configuration']['client'] ?? 'phpredis'),
        $input['configuration'],
    );
    $markStage('connecting');
    $connection = $manager->connection($input['connection_name']);
    $client = $connection->client();
    $markStage('connected');

    // The official Laravel manager and connector retain all supported client
    // and connection semantics. The raw client avoids only Laravel's automatic
    // reconnect-on-command-error wrapper inside this disposable process.
    $client->setex($input['key'], max(1, $input['ttl_seconds']), $input['value']);
    $markStage('setex');
    $read = $client->get($input['key']);
    $markStage('get');
    if ($diagnosticsEnabled) {
        @fwrite(STDERR, 'DW_REDIS_READY_VALUE_MATCH '.($read === $input['value'] ? '1' : '0')."\n");
    }
    $client->del($input['key']);
    $markStage('del');

    $stdout = json_encode([
        'ok' => true,
        'value' => is_string($read) ? $read : '',
    ], JSON_THROW_ON_ERROR);
    $markStage('encoded');
    $roundTripCompleted = true;
} catch (Throwable) {
    $exitCode = 1;
} finally {
    if ($roundTripCompleted && is_object($client)) {
        try {
            $markStage('closing');
            if (method_exists($client, 'close')) {
                $client->close();
            } elseif (method_exists($client, 'disconnect')) {
                $client->disconnect();
            }
            $markStage('closed');
        } catch (Throwable) {
            $markStage('close_failed');
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

$written = fwrite(STDOUT, $stdout);
$markStage($written === strlen($stdout) ? 'written' : 'write_incomplete');
