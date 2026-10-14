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
$language = getenv('DW_PARITY_MEMO_WORKFLOW_LANGUAGE') ?: 'php';
if (! in_array($language, ['php', 'python'], true)) {
    throw new InvalidArgumentException('Unsupported memo workflow language');
}
$client = new Client($url, namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null);
$state = ['fixture_sha256' => hash_file('sha256', $fixturePath), 'workflow_language' => $language,
    'phase' => 'start', 'outcome' => 'pending'];
$proxy = null;
$actorUrl = $url;
$journal = $directory.'/'.$workflowId.'-memo-http.jsonl';
$observedLines = 0;
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
    if ($language !== 'php') {
        $proxy = proc_open(['node', __DIR__.'/published-sdk-patch/observe-http.mjs', $url, $journal, '--forwarding-state'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $directory.'/'.$workflowId.'-memo-observer.stderr', 'w']], $pipes);
        if (! is_resource($proxy)) {
            throw new RuntimeException('Could not start the existing memo HTTP recorder');
        }
        fclose($pipes[0]);
        $actorUrl = trim(fgets($pipes[1]));
        if (! preg_match('#^http://127\\.0\\.0\\.1:[0-9]+$#', $actorUrl)) {
            throw new RuntimeException('Memo HTTP recorder did not bind its local port');
        }
    }
    $handle = $client->startWorkflow($fixture['workflow_type'], $workflowId, $workflowId.'-workflow', [$fixture['input']],
        executionTimeoutSeconds: 1200, runTimeoutSeconds: 1200, memo: $spec['initial']);
    $state['run_id'] = $handle->selectedRunId;
    $state['initial'] = $snapshot();
    foreach ($spec['phase_order'] as $phase) {
        $state['phase'] = $phase;
        $command = $language === 'python' && $phase !== 'activity'
            ? ['env', 'PYTHONPATH='.(getenv('DW_PARITY_CORRECTED_PYTHON_CURRENT_ROOT') ?: '/sdk-python-current'),
                'python3', __DIR__.'/memo-python-worker.py']
            : [PHP_BINARY, __DIR__.'/memo-worker.php'];
        array_push($command, $actorUrl, $phase, $workflowId, $state['run_id'], $fixturePath);
        $actor = correctedMarkerPhase($command, $directory, $workflowId, $phase,
            min($deadline, microtime(true) + $spec['phase_budget_seconds']));
        if ($language !== 'php') {
            $lines = file($journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $actor['receipts'] = [];
            foreach (array_slice($lines, $observedLines) as $line) {
                $observed = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (! str_starts_with($observed['path'], '/api/worker/')) {
                    continue;
                }
                $actor['receipts'][] = ['method' => $observed['method'], 'path' => $observed['path'],
                    'request' => $observed['request_body'] === '' ? null : json_decode($observed['request_body'], true, flags: JSON_THROW_ON_ERROR),
                    'status' => $observed['status'],
                    'response' => $observed['response_body'] === '' ? null : json_decode($observed['response_body'], true, flags: JSON_THROW_ON_ERROR)];
            }
            $observedLines = count($lines);
        }
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
            $send = static fn (array $request): array => $client->completeWorkflowTask($match[1],
                $request['lease_owner'], $request['workflow_task_attempt'], $request['commands'],
                $request['message_stream_cursors'] ?? [], $request['message_stream_waits'] ?? [],
                $request['sticky_cache'] ?? null);
            $state['duplicate'] = ['before' => $snapshot(), 'request' => $receipt];
            $state['duplicate']['submitted_request'] = $body;
            $state['duplicate']['receipt'] = namespaceReceipt('identical_original_memo_completion', static fn (): array =>
                $send($body));
            $state['duplicate']['after'] = $snapshot();
            $conflict = $body;
            if ($conflict['commands'][0]['type'] === 'schedule_activity') {
                $conflict['commands'][0]['queue'] = $workflowId.'-conflicting-queue';
            } else {
                $conflict['commands'][0]['entries'] = $client->payloadCodec()->envelope(['conflicting' => true]);
            }
            $state['conflicting_completion'] = ['before' => $snapshot(), 'submitted_request' => $conflict,
                'receipt' => namespaceReceipt('conflicting_original_memo_completion', static fn (): array => $send($conflict)),
                'after' => $snapshot()];
        }
        file_put_contents($output, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
    }
    $state['outcome'] = 'observed';
} catch (Throwable $error) {
    $state['outcome'] = 'failed';
    $state['error'] = ['type' => $error::class, 'message' => $error->getMessage()];
} finally {
    if (is_resource($proxy)) {
        fclose($pipes[1]);
        proc_terminate($proxy, 15);
        proc_close($proxy);
    }
    file_put_contents($output, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
}
echo file_get_contents($output);
exit($state['outcome'] === 'observed' ? 0 : 1);
