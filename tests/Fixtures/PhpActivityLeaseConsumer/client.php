<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use DurableWorkflow\Client;

$mode = $argv[1] ?? 'long';
if (! in_array($mode, ['long', 'loss'], true)) {
    throw new InvalidArgumentException('Unknown control.');
}
$client = new Client(getenv('SERVER_URL'), namespace: getenv('NAMESPACE') ?: 'default', token: 'synthetic');
$started = hrtime(true);
$workflowId = getenv('WORKFLOW_ID') ?: 'lease-'.$mode.'-original';
$handle = $client->startWorkflow('qualification.runtime-lease', $workflowId,
    'lease-qualification', [360, $mode], executionTimeoutSeconds: 420, runTimeoutSeconds: 420);
file_put_contents('/state/started.json', json_encode([
    'workflow_id' => $handle->workflowId, 'run_id' => $handle->selectedRunId,
    'mode' => $mode, 'monotonic_ns' => $started,
], JSON_THROW_ON_ERROR));
$result = $handle->result($mode === 'long' ? 420 : 30);
if ($result !== [9007199254740993, null, 'λ']) {
    throw new RuntimeException('The published SDK returned a different typed result.');
}
$elapsed = (hrtime(true) - $started) / 1e9;
if ($mode === 'long' && $elapsed < 360) {
    throw new RuntimeException('The real-duration control finished before six minutes.');
}
echo json_encode(['mode' => $mode, 'elapsed_seconds' => $elapsed,
    'run_id' => $handle->selectedRunId, 'result' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
