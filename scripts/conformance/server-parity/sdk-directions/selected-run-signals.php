<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;

require getenv('DW_PARITY_SDK_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
[$script, $phase, $url, $workflowId, $runId, $fixturePath, $remaining] = $argv;
$fixture = json_decode(file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
$seconds = (float) $remaining;
if (! is_finite($seconds) || $seconds <= 0 || $runId === '') {
    throw new InvalidArgumentException('Selected-run sender requires its original run and remaining budget.');
}
$namespace = $fixture['namespace'];
if (! is_string($namespace) || $namespace === '' || trim($namespace) !== $namespace) {
    throw new InvalidArgumentException('Invalid fixture namespace.');
}
[$name, $arguments, $expectedStatus] = match ($phase) {
    'foreign-signal', 'mismatched-run', 'inactive-signal' => ['payload', [$fixture['signal_value']], $phase === 'foreign-signal' ? 404 : 409],
    'unknown-signal' => ['undeclared', [$fixture['signal_value']], 404],
    'invalid-signal' => ['payload', [17], 422],
    'signal-1', 'signal-2' => ['payload', [$fixture['signal_value']], null],
    default => throw new InvalidArgumentException('Unknown selected-run sender phase.'),
};
$client = new Client($url, namespace: $namespace, token: getenv('DW_PARITY_TOKEN') ?: null);
$common = ['pid' => getmypid(), 'language' => 'php',
    'sdk_version' => ltrim(InstalledVersions::getPrettyVersion('durable-workflow/sdk'), 'v'),
    'phase' => $phase, 'workflow_id' => $workflowId, 'run_id' => $runId];
try {
    $result = $client->signalWorkflow($workflowId, $name, $arguments, $runId);
    if ($expectedStatus !== null) {
        throw new RuntimeException('Selected-run negative control was admitted.');
    }
    $result = $common + ['signal_sent' => true, 'sender_finished' => true, 'client_result' => $result];
} catch (ServerException $error) {
    if ($expectedStatus === null || $error->status !== $expectedStatus) {
        throw $error;
    }
    $result = $common + ['signal_sent' => false, 'sender_finished' => true,
        'typed_error' => ['class' => $error::class, 'status' => $error->status,
            'reason' => $error->reason, 'details' => $error->details]];
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
