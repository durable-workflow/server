<?php

declare(strict_types=1);

use DurableWorkflow\Client;

require getenv('DW_PARITY_SDK_AUTOLOAD');
require __DIR__.'/observation-values.php';
require __DIR__.'/namespace-probe.php';
require __DIR__.'/corrected-marker-probe.php';

[$script, $url, $workflowId, $fixturePath, $output] = $argv;
$fixture = json_decode(file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
$spec = $fixture['memo_replay'];
$directory = dirname($output);
$deadline = microtime(true) + $spec['overall_budget_seconds'];
$client = new Client($url, namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null);
$state = ['fixture_sha256' => hash_file('sha256', $fixturePath), 'phase' => 'start', 'outcome' => 'pending'];
$snapshot = static function () use ($client, $workflowId, &$state): array {
    $run = namespaceHttpRun($client, $workflowId, $state['run_id']);
    $run['typed_memo'] = typedValue($run['execution']['memo']);
    foreach ($run['events'] as &$event) {
        if (in_array($event['event_type'], ['StartAccepted', 'WorkflowStarted'], true)) {
            $event['typed_decoded']['initial_memo'] = typedValue($event['payload']['memo']);
        }
        if ($event['event_type'] === 'MemoUpserted') {
            foreach (['entries', 'merged'] as $field) {
                $event['typed_decoded'][$field] = typedValue($client->payloadCodec()->decodeEnvelope($event['payload'][$field]));
            }
        }
    }
    unset($event);

    return $run;
};
try {
    $handle = $client->startWorkflow($fixture['workflow_type'], $workflowId, $workflowId.'-workflow', [$fixture['input']],
        executionTimeoutSeconds: 1200, runTimeoutSeconds: 1200, memo: $spec['initial']);
    $state['run_id'] = $handle->selectedRunId;
    $state['initial'] = $snapshot();
    foreach ($spec['phase_order'] as $phase) {
        $state['phase'] = $phase;
        $actor = correctedMarkerPhase([PHP_BINARY, __DIR__.'/memo-worker.php', $url, $phase, $workflowId,
            $state['run_id'], $fixturePath], $directory, $workflowId, $phase,
            min($deadline, microtime(true) + $spec['phase_budget_seconds']));
        $state['phases'][$phase] = $snapshot() + ['actor' => $actor];
        if ($phase === 'original') {
            $poll = array_values(array_filter($actor['receipts'], static fn (array $receipt): bool =>
                $receipt['path'] === '/api/worker/workflow-tasks/poll' && isset($receipt['response']['task'])))[0] ?? null;
            $state['discovery'] = $poll['response']['server_capabilities'] ?? null;
            $receipt = array_values(array_filter($actor['receipts'], static fn (array $receipt): bool =>
                preg_match('#^/api/worker/workflow-tasks/[^/]+/complete$#', $receipt['path'])
                && in_array(($spec['initial_only'] ?? false) ? 'schedule_activity' : 'upsert_memo',
                    array_column($receipt['request']['commands'] ?? [], 'type'), true)))[0] ?? null;
            if ($receipt === null || ! preg_match('#/workflow-tasks/([^/]+)/complete$#', $receipt['path'], $match)) {
                throw new RuntimeException('Original SDK did not submit an actual memo completion');
            }
            $body = $receipt['request'];
            $state['duplicate'] = ['before' => $snapshot(), 'request' => $receipt];
            $state['duplicate']['receipt'] = namespaceReceipt('identical_original_memo_completion', static fn (): array =>
                $client->completeWorkflowTask($match[1], $body['lease_owner'], $body['workflow_task_attempt'], $body['commands']));
            $state['duplicate']['after'] = $snapshot();
        }
        file_put_contents($output, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
    }
    $state['outcome'] = 'observed';
} catch (Throwable $error) {
    $state['outcome'] = 'failed';
    $state['error'] = ['type' => $error::class, 'message' => $error->getMessage()];
} finally {
    file_put_contents($output, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
}
echo file_get_contents($output);
exit($state['outcome'] === 'observed' ? 0 : 1);
