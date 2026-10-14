<?php

declare(strict_types=1);

use DurableWorkflow\Client;

require getenv('DW_PARITY_SDK_AUTOLOAD');
require __DIR__.'/observation-values.php';
require __DIR__.'/namespace-probe.php';
require __DIR__.'/corrected-marker-probe.php';
[$script, $url, $workflowId, $fixturePath, $output] = $argv;
$fixture = json_decode(file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
$spec = $fixture['continuation'];
$deadline = microtime(true) + $spec['overall_budget_seconds'];
$client = new Client($url, namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null);
$state = ['fixture_sha256' => hash_file('sha256', $fixturePath), 'phase' => 'start', 'outcome' => 'pending'];
$snapshot = static function (string $runId) use ($client, $workflowId): array {
    $run = namespaceHttpRun($client, $workflowId, $runId);
    $run['typed_memo'] = typedValue($run['execution']['memo']);

    return $run;
};
try {
    $handle = $client->startWorkflow($fixture['workflow_type'], $workflowId, $workflowId.'-workflow',
        [['generation' => 0, 'value' => $fixture['input']]],
        executionTimeoutSeconds: $spec['execution_timeout_seconds'], runTimeoutSeconds: $spec['run_timeout_seconds'],
        memo: $spec['initial_memo']);
    $state['predecessor_run_id'] = $handle->selectedRunId;
    $state['initial'] = $snapshot($state['predecessor_run_id']);
    foreach ($spec['phase_order'] as $phase) {
        $state['phase'] = $phase;
        $runId = $phase === 'successor' ? $state['successor_run_id'] : $state['predecessor_run_id'];
        $actor = correctedMarkerPhase([PHP_BINARY, __DIR__.'/continue-worker.php', $url, $phase, $workflowId,
            $runId, $fixturePath], dirname($output), $workflowId, $phase,
            min($deadline, microtime(true) + $spec['phase_budget_seconds']));
        $state['phases'][$phase] = $snapshot($runId) + ['actor' => $actor];
        if ($phase === 'continuation') {
            $current = $client->describeWorkflow($workflowId);
            $state['successor_run_id'] = $current->runId;
            if ($state['successor_run_id'] === $state['predecessor_run_id']) {
                throw new RuntimeException('The original continuation did not produce a distinct successor');
            }
            $state['inherited'] = $snapshot($state['successor_run_id']);
            $receipt = array_values(array_filter($actor['receipts'], static fn (array $receipt): bool =>
                preg_match('#^/api/worker/workflow-tasks/[^/]+/complete$#', $receipt['path'])
                && in_array('continue_as_new', array_column($receipt['request']['commands'] ?? [], 'type'), true)))[0] ?? null;
            if ($receipt === null || ! preg_match('#/workflow-tasks/([^/]+)/complete$#', $receipt['path'], $match)) {
                throw new RuntimeException('The normal SDK did not submit the original continuation');
            }
            $body = $receipt['request'];
            $state['duplicate'] = ['request' => $receipt,
                'before' => [$snapshot($state['predecessor_run_id']), $snapshot($state['successor_run_id'])],
                'receipt' => namespaceReceipt('identical_original_continuation', static fn (): array =>
                    $client->completeWorkflowTask($match[1], $body['lease_owner'], $body['workflow_task_attempt'], $body['commands'])),
                'after' => [$snapshot($state['predecessor_run_id']), $snapshot($state['successor_run_id'])],
                'current_after' => $client->describeWorkflow($workflowId)->raw];
        }
        file_put_contents($output, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
    }
    $state['predecessor_final'] = $snapshot($state['predecessor_run_id']);
    $state['current_final'] = $client->describeWorkflow($workflowId)->raw;
    $state['outcome'] = 'observed';
} catch (Throwable $error) {
    $state['outcome'] = 'failed';
    $state['error'] = ['type' => $error::class, 'message' => $error->getMessage()];
} finally {
    file_put_contents($output, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
}
echo file_get_contents($output);
exit($state['outcome'] === 'observed' ? 0 : 1);
