<?php

declare(strict_types=1);

// The existing byte-preserving observer retains complete public deliveries.
// Actors run in independent processes; no response or stored history is edited.
function correctedMarkerHttpObservation(array $fixture, array $options): array
{
    $spec = $fixture['corrected_markers'];
    $directory = getenv('DW_PARITY_FAILURE_EVIDENCE_DIR');
    if (! is_string($directory) || ! is_dir($directory)) {
        throw new RuntimeException('Corrected marker evidence directory is required');
    }
    $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $options['workflow-id']);
    $journal = $directory.'/'.$name.'-http.jsonl';
    $proxy = proc_open(['node', __DIR__.'/published-sdk-patch/observe-http.mjs', $options['url'], $journal, '--forwarding-state'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $directory.'/'.$name.'-observer.stderr', 'w']], $pipes);
    if (! is_resource($proxy)) {
        throw new RuntimeException('Could not start the HTTP recorder');
    }
    fclose($pipes[0]);
    $runId = '';
    $phases = [];
    $deadline = microtime(true) + $spec['overall_budget_seconds'];
    try {
        $url = trim(fgets($pipes[1]));
        if (! preg_match('#^http://127\.0\.0\.1:[0-9]+$#', $url)) {
            throw new RuntimeException('HTTP recorder did not bind its local port');
        }
        $client = new \DurableWorkflow\Client($url, namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null);
        $handle = $client->startWorkflow($fixture['workflow_type'], $options['workflow-id'],
            $options['workflow-id'].'-workflow', [$fixture['input']],
            executionTimeoutSeconds: 1200, runTimeoutSeconds: 1200);
        $runId = $handle->selectedRunId;
        foreach (['original', 'activity', 'replacement'] as $phase) {
            $language = $phase === 'original' ? $spec['producer'] : 'rust';
            $command = match ($language) {
                'php' => [PHP_BINARY, __DIR__.'/corrected-markers/php.php'],
                'rust' => [getenv('DW_PARITY_CORRECTED_RUST_WORKER') ?: '/sdk-adapters/rust-worker'],
                'python' => ['python3', __DIR__.'/corrected-markers/python250.py'],
                default => throw new InvalidArgumentException('Unsupported producer language'),
            };
            array_push($command, $url, $phase === 'activity' ? 'activity' : 'workflow',
                $options['workflow-id'], $runId, $spec['case'],
                $phase === 'replacement' ? 'wider' : 'original', $spec['retry_policy'],
                json_encode($fixture['input'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $stdout = $directory.'/'.$name.'-'.$phase.'.stdout';
            $stderr = $directory.'/'.$name.'-'.$phase.'.stderr';
            $actor = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'],
                2 => ['file', $stderr, 'w']], $actorPipes);
            if (! is_resource($actor)) {
                throw new RuntimeException('Could not start a published actor');
            }
            fclose($actorPipes[0]);
            $phaseDeadline = min($deadline, microtime(true) + $spec['phase_budget_seconds']);
            try {
                do {
                    $state = proc_get_status($actor);
                    if (! $state['running']) {
                        break;
                    }
                    if (microtime(true) >= $phaseDeadline) {
                        proc_terminate($actor, 15);
                        throw new RuntimeException('Original fixed '.$phase.' observation expired');
                    }
                    usleep(10000);
                } while (true);
            } finally {
                proc_close($actor);
            }
            if ($state['exitcode'] !== 0) {
                throw new RuntimeException($phase.' actor failed: '.trim(file_get_contents($stderr)));
            }
            $lines = array_values(array_filter(explode("\n", trim(file_get_contents($stdout)))));
            $actorResult = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            $run = $client->describeWorkflow($options['workflow-id'], $runId);
            $snapshot = ['actor' => $actorResult, 'status' => $run->status, 'execution' => $run->raw,
                'events' => decodeHistory(httpHistory($client, $options['workflow-id'], $runId), $client->payloadCodec()->decodeEnvelope(...))];
            file_put_contents($directory.'/'.$name.'-'.$phase.'-snapshot.json', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
            $phases[$phase] = $snapshot;
            if ($phase === 'original' && ! in_array('ActivityScheduled', array_column($snapshot['events'], 'event_type'), true)) {
                throw new RuntimeException('Original actor did not commit its pending activity checkpoint');
            }
            if ($phase === 'activity' && ! in_array('ActivityCompleted', array_column($snapshot['events'], 'event_type'), true)) {
                throw new RuntimeException('Independent activity actor did not commit its result');
            }
        }
        $run = $client->describeWorkflow($options['workflow-id'], $runId);

        return ['workflow_id' => $options['workflow-id'], 'run_id' => $runId,
            'workflow_type' => $run->workflowType, 'namespace' => $run->namespace, 'task_queue' => $run->taskQueue,
            'status' => $run->status, 'payload_codec' => $run->raw['payload_codec'], 'execution' => $run->raw,
            'input' => $run->input, 'output' => $run->output,
            'events' => decodeHistory(httpHistory($client, $options['workflow-id'], $runId), $client->payloadCodec()->decodeEnvelope(...)),
            'corrected_markers' => ['phases' => $phases, 'requests' => array_map(
                static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)),
                'overall_budget_seconds' => $spec['overall_budget_seconds'], 'phase_budget_seconds' => $spec['phase_budget_seconds']]];
    } finally {
        fclose($pipes[1]);
        proc_terminate($proxy, 15);
        proc_close($proxy);
    }
}
