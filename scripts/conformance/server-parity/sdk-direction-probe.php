<?php

declare(strict_types=1);

function sdkDirectionSnapshot(\DurableWorkflow\Client $client, string $workflowId, string $runId): array
{
    $execution = $client->describeWorkflow($workflowId, $runId);

    return ['execution' => $execution->raw, 'workflow_id' => $workflowId, 'run_id' => $execution->runId,
        'workflow_type' => $execution->workflowType, 'namespace' => $execution->namespace,
        'task_queue' => $execution->taskQueue, 'status' => $execution->status,
        'payload_codec' => $execution->raw['payload_codec'], 'input' => $execution->input,
        'output' => $execution->output, 'typed_input' => typedValue($execution->input),
        'typed_output' => typedValue($execution->output),
        'events' => decodeHistory(httpHistory($client, $workflowId, $runId), $client->payloadCodec()->decodeEnvelope(...))];
}

function sdkDirectionHttpObservation(array $fixture, array $options): array
{
    $evidence = getenv('DW_PARITY_FAILURE_EVIDENCE_DIR');
    if (! is_string($evidence) || ! is_dir($evidence)) {
        throw new RuntimeException('SDK direction requires an existing evidence directory.');
    }
    $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $options['workflow-id']);
    $journal = tempnam(sys_get_temp_dir(), 'parity-direction-');
    $proxy = proc_open(['node', __DIR__.'/published-sdk-patch/observe-http.mjs', $options['url'], $journal, '--forwarding-state'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $proxyPipes);
    if (! is_resource($proxy)) {
        unlink($journal);
        throw new RuntimeException('Could not start the byte-preserving SDK observer.');
    }
    fclose($proxyPipes[0]);
    $phases = [];
    $runId = '';
    $client = new \DurableWorkflow\Client($options['url'], namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null);
    $deadline = microtime(true) + 35;
    try {
        $url = trim(fgets($proxyPipes[1]));
        if (! preg_match('#^http://127\\.0\\.0\\.1:[0-9]+$#', $url)) {
            throw new RuntimeException('SDK observer did not bind its local port.');
        }
        foreach ($fixture['sdk_direction']['phase_order'] as $phase) {
            $language = $fixture['sdk_direction'][$phase === 'activity' ? 'activity' : 'workflow'];
            $arguments = match ($language) {
                'php' => [PHP_BINARY, __DIR__.'/sdk-directions/php.php'],
                'python' => ['python3', __DIR__.'/sdk-directions/python.py'],
                'rust' => [getenv('DW_PARITY_RUST_DIRECTION_WORKER') ?: '/sdk-directions/rust-worker'],
                default => throw new RuntimeException('Unknown published SDK direction language.'),
            };
            array_push($arguments, $phase, $url, $options['workflow-id'], $runId, $options['fixture'],
                (string) max(0.001, $deadline - microtime(true)));
            $offset = count(file($journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
            $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (! is_resource($process)) {
                throw new RuntimeException('Could not start the published SDK phase.');
            }
            fclose($pipes[0]);
            try {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
            } finally {
                fclose($pipes[1]);
                fclose($pipes[2]);
            }
            $status = proc_close($process);
            $lines = array_slice(file($journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), $offset);
            file_put_contents($evidence.'/sdk-direction-'.$name.'-'.$phase.'.json', json_encode([
                'language' => $language, 'phase' => $phase, 'stdout' => $stdout, 'stderr' => $stderr,
                'exit_status' => $status, 'transport_jsonl' => implode("\n", $lines)."\n",
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
            $requests = array_map(static function (string $line): array {
                $item = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                foreach (['request', 'response'] as $side) {
                    $body = $item[$side.'_body'];
                    $unsent = $side === 'request' && $item['status'] === 0 && $item['client_cancelled'] === true
                        && $item['transport_error'] === null && $item['upstream_request_started'] === false
                        && $item['request_body_complete'] === false;
                    $item[$side] = $body === '' || $unsent ? null : json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                }
                // Exact request/response bytes remain in the retained sidecar.
                unset($item['request_body'], $item['response_body'], $item['response_bytes_base64']);

                return $item;
            }, $lines);
            $result = $status === 0 ? json_decode($stdout, true, flags: JSON_THROW_ON_ERROR)
                : ['phase' => $phase, 'language' => $language, 'worker_finished' => false, 'worker_error' => trim($stderr)];
            $runId = $result['run_id'] ?? $runId;
            if ($runId === '') {
                foreach ($requests as $request) {
                    if ($request['method'] === 'POST' && $request['path'] === '/api/workflows'
                        && $request['status'] === 201 && is_string($request['response']['run_id'] ?? null)) {
                        $runId = $request['response']['run_id'];
                    }
                }
            }
            if ($runId === '') {
                throw new RuntimeException('Published client did not return an original run; exact transport failure is retained.');
            }
            $snapshot = sdkDirectionSnapshot($client, $options['workflow-id'], $runId);
            $phases[] = $result + ['requests' => $requests, 'snapshot' => $snapshot];
            if ($status !== 0) {
                break; // Preserve the actual open run and let the reader classify the product failure.
            }
        }
        $observation = $phases[array_key_last($phases)]['snapshot'];
        $identityPath = getenv('DW_PARITY_DIRECTION_IDENTITY') ?: '/sdk-directions/identity.json';
        $observation['sdk_direction'] = ['applicable' => true, 'phases' => $phases,
            'packages' => json_decode(file_get_contents($identityPath), true, flags: JSON_THROW_ON_ERROR)];

        return $observation;
    } finally {
        proc_terminate($proxy);
        fclose($proxyPipes[1]);
        fclose($proxyPipes[2]);
        proc_close($proxy);
        unlink($journal);
    }
}

function sdkDirectionPackageObservation(array &$observation, string $sdkAutoload, ?string $applicationRoot): void
{
    $observation['sdk_composer_lock_sha256'] = hash_file('sha256', dirname($sdkAutoload, 2).'/composer.lock');
    $observation['sdk_loaded_sources'] = [];
    foreach ([\DurableWorkflow\Worker::class, \DurableWorkflow\Worker\WorkflowContext::class] as $class) {
        $observation['sdk_loaded_sources'][$class] = hash_file('sha256', (new ReflectionClass($class))->getFileName());
    }
    if ($applicationRoot !== null) {
        $observation['workflow_source'] = \Composer\InstalledVersions::getReference('durable-workflow/workflow');
        $observation['embedded_composer_lock_sha256'] = hash_file('sha256', $applicationRoot.'/composer.lock');
        $observation['workflow_loaded_sources'] = [];
        foreach (['TaskRepair', 'VersionResolver', 'WorkflowExecutor'] as $class) {
            $type = 'Workflow\\V2\\Support\\'.$class;
            $observation['workflow_loaded_sources']['src/V2/Support/'.$class.'.php'] = hash_file('sha256', (new ReflectionClass($type))->getFileName());
        }
    }
}
