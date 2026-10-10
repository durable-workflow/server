<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;
use Workflow\WorkflowOptions;

function parityCliCommand(string $phar, string $url, string $namespace, array $arguments): array
{
    // Execute the unchanged published consumer. Tokens remain in the child
    // environment; neither argv nor retained output contains request headers.
    $environment = [...getenv(), 'DURABLE_WORKFLOW_SERVER_URL' => $url,
        'DURABLE_WORKFLOW_NAMESPACE' => $namespace,
        'DURABLE_WORKFLOW_AUTH_TOKEN' => getenv('DW_PARITY_TOKEN') ?: ''];
    $process = proc_open([PHP_BINARY, $phar, ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, env_vars: $environment);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not launch the verified CLI artifact.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = $stderr = '';
    $deadline = microtime(true) + 5;
    $timedOut = false;
    do {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        if (strlen($stdout) + strlen($stderr) > 2 * 1024 * 1024) {
            proc_terminate($process, 9);
            throw new RuntimeException('CLI observation exceeded its output budget.');
        }
        $status = proc_get_status($process);
        if (! $status['running']) {
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            proc_terminate($process, 9);
            break;
        }
        usleep(10000);
    } while (true);
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeCode = proc_close($process);
    $exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : $closeCode;

    return ['arguments' => $arguments, 'exit_code' => $exitCode,
        'timed_out' => $timedOut, 'stdout' => $stdout, 'stderr' => $stderr];
}

function parityVisibilityCli(Client $client, array $fixture, string $workflowId, string $runId, string $url, string $namespace): array
{
    $phar = getenv('DW_PARITY_CLI_PHAR') ?: '';
    $expected = $fixture['visibility']['cli'];
    if (! is_file($phar) || hash_file('sha256', $phar) !== $expected['phar_sha256']) {
        throw new RuntimeException('Visibility qualification requires the exact published CLI artifact.');
    }
    $commands = [
        'version' => ['--version'],
        'list' => ['workflow:list', '--query='.$workflowId, '--limit=2', '--output=json'],
        'running' => ['workflow:list', '--query='.$workflowId, '--status=running', '--limit=2', '--output=json'],
        'completed' => ['workflow:list', '--query='.$workflowId, '--status=completed', '--limit=2', '--output=json'],
        'alias' => ['workflow:list', '--query='.$workflowId, '--status='.$fixture['visibility']['rejected_status_alias'], '--output=json'],
        'describe' => ['workflow:describe', $workflowId, '--run-id='.$runId, '--output=json'],
        'history' => ['workflow:history', $workflowId, $runId, '--page-size=1', '--output=json'],
    ];
    $observed = ['sha256' => hash_file('sha256', $phar), 'commands' => []];
    foreach ($commands as $name => $arguments) {
        $receipt = parityCliCommand($phar, $url, $namespace, $arguments);
        if (! in_array($name, ['version', 'alias'], true) && $receipt['exit_code'] === 0) {
            $receipt['document'] = json_decode($receipt['stdout'], true, flags: JSON_THROW_ON_ERROR);
            if ($name === 'describe') {
                $receipt['typed_input_preview'] = typedValue($receipt['document']['input'] ?? null);
                $receipt['typed_output_preview'] = typedValue($receipt['document']['output'] ?? null);
            } elseif ($name === 'history') {
                $receipt['decoded_events'] = decodeHistory($receipt['document']['events'], $client->payloadCodec()->decodeEnvelope(...));
            }
        }
        $observed['commands'][$name] = $receipt;
    }

    return $observed;
}

function parityHttpVisibilityPages(Client $client, string $workflowId, int $pageSize): array
{
    $pages = [];
    $token = null;
    do {
        $page = $client->listWorkflows(query: $workflowId, pageSize: $pageSize, nextPageToken: $token);
        $pages[] = ['request_token' => $token, 'response' => $page->raw];
        $next = $page->nextPageToken;
        if ($next !== null && (count($pages) > 3 || $next === $token || $page->executions === [])) {
            throw new RuntimeException('Visibility cursor did not advance within the original two-run scope.');
        }
        $token = $next;
    } while ($token !== null);

    return $pages;
}

function finishHttpVisibility(Client $client, array $fixture, string $workflowId, string $runId, string $queue, string $url, string $namespace): array
{
    $definition = $fixture['visibility'];
    $peerId = $workflowId.$definition['peer_suffix'];
    $peer = $client->startWorkflow($definition['peer_workflow_type'], $peerId, $queue, [$fixture['input']]);
    $state = ['peer_workflow_id' => $peerId, 'peer_run_id' => $peer->selectedRunId,
        'peer_before' => $peer->describeSelectedRun()->raw];
    try {
        $state['cluster'] = $client->clusterInfo()->raw;
        $state['pages'] = parityHttpVisibilityPages($client, $workflowId, $definition['page_size']);
        foreach ($definition['status_filters'] as $status) {
            $state['filters'][$status] = $client->listWorkflows(status: $status, query: $workflowId, pageSize: 2)->raw;
        }
        foreach (['completed' => $fixture['workflow_type'], 'pending' => $definition['peer_workflow_type']] as $name => $type) {
            $state['type_filters'][$name] = $client->listWorkflows(workflowType: $type, query: $workflowId, pageSize: 2)->raw;
        }
        try {
            $state['alias'] = ['outcome' => 'observed', 'response' => $client->listWorkflows(
                status: $definition['rejected_status_alias'], query: $workflowId, pageSize: 2)->raw];
        } catch (ServerException $error) {
            $state['alias'] = ['status' => $error->status, 'reason' => $error->reason, 'response' => $error->details];
        }
        $state['cli'] = parityVisibilityCli($client, $fixture, $workflowId, $runId, $url, $namespace);
    } catch (Throwable $error) {
        parityHttpFailureEvidence($error, 'visibility', $workflowId, $runId, $url, $namespace, [], ['visibility' => $state]);
        throw $error;
    } finally {
        $peer->cancelSelectedRun($definition['cleanup']['reason']);
    }
    $state['peer_after'] = $peer->describeSelectedRun()->raw;
    $state['peer_history'] = httpHistory($client, $peerId, $peer->selectedRunId);
    $state['after_cleanup'] = $client->listWorkflows(status: 'failed', query: $workflowId, pageSize: 2)->raw;

    return $state;
}

function finishEmbeddedVisibility(array $fixture, string $workflowId, string $queue, string $namespace): array
{
    $definition = $fixture['visibility'];
    $peerId = $workflowId.$definition['peer_suffix'];
    $peer = WorkflowStub::make(\ServerParity\OneActivityWorkflow::class, $peerId);
    $peer->start($fixture['input'], new WorkflowOptions(connection: 'database', queue: $queue),
        new StartOptions(executionTimeoutSeconds: 3600, runTimeoutSeconds: 600));
    $peerRun = WorkflowRun::query()->findOrFail($peer->runId());
    // The embedded API exposes persisted summaries through its public model.
    // These are read-only observations, not constructed HTTP cursor receipts.
    $base = WorkflowRunSummary::query()->where('namespace', $namespace)
        ->where('workflow_instance_id', 'like', '%'.$workflowId.'%')
        ->orderByDesc('sort_timestamp')->orderByDesc('id');
    $rows = static fn ($query): array => $query->get()->map(static fn ($row): array => $row->toArray())->all();
    $state = ['peer_workflow_id' => $peerId, 'peer_run_id' => $peerRun->id,
        'peer_before' => $peerRun->toArray(), 'summaries' => $rows(clone $base)];
    try {
        foreach ($definition['status_filters'] as $status) {
            $state['filters'][$status] = $rows((clone $base)->where('status_bucket', $status));
        }
        foreach (['completed' => $fixture['workflow_type'], 'pending' => $definition['peer_workflow_type']] as $name => $type) {
            $state['type_filters'][$name] = $rows((clone $base)->where('workflow_type', $type));
        }
    } finally {
        $result = $peer->attemptCancel($definition['cleanup']['reason']);
        $state['cleanup'] = ['accepted' => $result->accepted(), 'command_id' => $result->commandId(),
            'command_sequence' => $result->commandSequence(), 'workflow_id' => $result->workflowId(),
            'run_id' => $result->resolvedRunId(), 'status' => $result->status(),
            'outcome' => $result->outcome(), 'reason' => $result->reason()];
    }
    $peerRun->refresh();
    $state['peer_after'] = $peerRun->toArray();
    $state['peer_history'] = $peerRun->historyEvents()->orderBy('sequence')->get()->map(static fn ($row): array => [
        'sequence' => $row->sequence, 'event_type' => $row->event_type->value,
        'timestamp' => $row->recorded_at->toISOString(), 'payload' => $row->payload,
    ])->all();
    $state['after_cleanup'] = $rows((clone $base)->where('status_bucket', 'failed'));

    return $state;
}
