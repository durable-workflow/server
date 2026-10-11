<?php

declare(strict_types=1);
use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\WorkflowContext;

function sdkDirectionResponseSummary(mixed $response, bool $workflowHistory = false): mixed
{
    if (! is_array($response)) {
        return $response;
    }
    // Full responses and exact bytes are already retained in the phase sidecar.
    // Repeated discovery/capability metadata must not fill the recorder's pipe.
    $summary = array_intersect_key($response, array_flip([
        'run_id', 'registered', 'recorded', 'task', 'task_id', 'activity_attempt_id',
        'workflow_task_attempt', 'run_status', 'poll_status', 'outcome', 'reason',
        'error', 'message', 'version', 'server_id',
    ]));
    if (is_array($summary['task'] ?? null)) {
        $summary['task'] = array_intersect_key($summary['task'], array_flip([
            'task_id', 'workflow_id', 'run_id', 'workflow_run_id', 'task_type',
            'workflow_task_attempt', 'activity_attempt_id', 'lease_owner', 'task_queue',
            ...($workflowHistory ? ['queue', 'workflow_type', 'history_events', 'child_call_id',
                'child_workflow_run_id', 'resume_source_kind', 'resume_source_id',
                'workflow_event_type', 'workflow_sequence', 'open_wait_id'] : []),
        ]));
    }

    return $summary;
}

function sdkDirectionSnapshot(Client $client, string $workflowId, string $runId): array
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
    $childDirection = isset($fixture['sdk_child_direction']);
    $timerAuthor = isset($fixture['sdk_timer_author']);
    $signalDirection = isset($fixture['sdk_signal_direction']);
    $directionKey = $signalDirection ? 'sdk_signal_direction' : ($timerAuthor ? 'sdk_timer_author' : ($childDirection ? 'sdk_child_direction' : 'sdk_direction'));
    $spec = $fixture[$directionKey];
    $childWorkflowId = $childRunId = '';
    $client = new Client($options['url'], namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null);
    $deadline = microtime(true) + 35;
    $observerStart = null;
    try {
        $url = trim(fgets($proxyPipes[1]));
        if (! preg_match('#^http://127\\.0\\.0\\.1:[0-9]+$#', $url)) {
            throw new RuntimeException('SDK observer did not bind its local port.');
        }
        $startClient = $signalDirection ? new Client($url, namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null) : null;
        foreach ($spec['phase_order'] as $phase) {
            $language = $signalDirection ? $spec[in_array($phase, ['signal-1', 'signal-2'], true) ? 'client' : 'workflow']
                : ($timerAuthor ? $spec['language'] : ($childDirection ? $spec[$phase === 'child' ? 'child' : 'parent']
                : $spec[$phase === 'activity' ? 'activity' : 'workflow']));
            $actorWorkflowId = $phase === 'child' ? $childWorkflowId : $options['workflow-id'];
            $actorRunId = $phase === 'child' ? $childRunId : $runId;
            $arguments = match ($language) {
                'php' => [PHP_BINARY, __DIR__.'/sdk-directions/php.php'],
                'python' => ['python3', __DIR__.'/sdk-directions/python.py'],
                'rust' => [getenv('DW_PARITY_RUST_DIRECTION_WORKER') ?: '/sdk-directions/rust-worker'],
                default => throw new RuntimeException('Unknown published SDK direction language.'),
            };
            array_push($arguments, $phase, $url, $actorWorkflowId, $actorRunId, $options['fixture'],
                (string) max(0.001, $deadline - microtime(true)));
            $offset = count(file($journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
            $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (! is_resource($process)) {
                throw new RuntimeException('Could not start the published SDK phase.');
            }
            fclose($pipes[0]);
            $observedStatus = null;
            $observerError = null;
            try {
                if ($signalDirection && $phase === 'original') {
                    // Admit signals from the real worker's captured declaration.
                    // The observer starts only after its successful registration.
                    stream_set_blocking($pipes[1], false);
                    stream_set_blocking($pipes[2], false);
                    $stdout = $stderr = '';
                    while (true) {
                        $stdout .= stream_get_contents($pipes[1]);
                        $stderr .= stream_get_contents($pipes[2]);
                        $processStatus = proc_get_status($process);
                        if (! $processStatus['running']) {
                            $observedStatus = $processStatus['exitcode'];
                            $stdout .= stream_get_contents($pipes[1]);
                            $stderr .= stream_get_contents($pipes[2]);
                            break;
                        }
                        if (microtime(true) >= $deadline) {
                            throw new RuntimeException('Signal original worker exceeded the remaining fixture budget.');
                        }
                        if ($observerStart === null) {
                            foreach (array_slice(file($journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), $offset) as $line) {
                                $wire = json_decode($line, true);
                                if (! is_array($wire) || $wire['method'] !== 'POST' || $wire['path'] !== '/api/worker/register' || $wire['status'] !== 201) {
                                    continue;
                                }
                                $registration = json_decode($wire['request_body'], true, flags: JSON_THROW_ON_ERROR);
                                $receipt = json_decode($wire['response_body'], true, flags: JSON_THROW_ON_ERROR);
                                if (($registration['worker_id'] ?? null) !== $actorWorkflowId.':original' || ($receipt['registered'] ?? null) !== true) {
                                    continue;
                                }
                                $runId = $startClient->startWorkflow($fixture['workflow_type'], $actorWorkflowId,
                                    $spec['task_queue'], [$fixture['input'], $fixture['signal_count']])->selectedRunId;
                                $observerStart = ['role' => 'observer', 'language' => 'php', 'pid' => getmypid(),
                                    'sdk_version' => ltrim(InstalledVersions::getPrettyVersion('durable-workflow/sdk'), 'v'),
                                    'run_id' => $runId];
                                break;
                            }
                        }
                        usleep(10000);
                    }
                } else {
                    $stdout = stream_get_contents($pipes[1]);
                    $stderr = stream_get_contents($pipes[2]);
                }
            } catch (Throwable $error) {
                if (! $signalDirection) {
                    throw $error;
                }
                $observerError = $error::class.': '.$error->getMessage();
                proc_terminate($process);
            } finally {
                fclose($pipes[1]);
                fclose($pipes[2]);
            }
            $closedStatus = proc_close($process);
            $status = $observedStatus ?? $closedStatus;
            $lines = array_slice(file($journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), $offset);
            file_put_contents($evidence.'/'.($signalDirection ? 'sdk-signal-direction-' : ($timerAuthor ? 'sdk-timer-author-' : ($childDirection ? 'sdk-child-direction-' : 'sdk-direction-'))).$name.'-'.$phase.'.json', json_encode([
                'language' => $language, 'phase' => $phase, 'stdout' => $stdout, 'stderr' => $stderr,
                'exit_status' => $status, 'transport_jsonl' => implode("\n", $lines)."\n",
                ...($signalDirection && $phase === 'original' ? ['observer_start' => $observerStart, 'observer_error' => $observerError] : []),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
            $requests = array_map(static function (string $line) use ($childDirection, $timerAuthor, $signalDirection): array {
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
                $signalReceipt = $signalDirection && $item['method'] === 'POST'
                    && preg_match('#^/api/workflows/[^/]+/signal/payload$#', $item['path']);
                if (! $signalReceipt) {
                    $item['response'] = sdkDirectionResponseSummary($item['response'], $childDirection || $timerAuthor || $signalDirection);
                }

                return $item;
            }, $lines);
            $result = $status === 0 ? json_decode($stdout, true, flags: JSON_THROW_ON_ERROR)
                : ['phase' => $phase, 'language' => $language, 'worker_finished' => false, 'worker_error' => trim($stderr)];
            if ((! $childDirection && ! $timerAuthor && ! $signalDirection) || $phase === 'original') {
                $runId = $result['run_id'] ?? $runId;
            }
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
            $childSnapshot = null;
            if ($childDirection) {
                foreach ($snapshot['events'] as $event) {
                    if ($event['event_type'] === 'ChildRunStarted') {
                        $childWorkflowId = $event['payload']['child_workflow_instance_id'];
                        $childRunId = $event['payload']['child_workflow_run_id'];
                    }
                }
                if ($childWorkflowId !== '' && $childRunId !== '') {
                    $childSnapshot = sdkDirectionSnapshot($client, $childWorkflowId, $childRunId);
                }
            }
            $phases[] = $result + ['requests' => $requests, 'snapshot' => $snapshot,
                ...($childDirection ? ['child_snapshot' => $childSnapshot] : []),
                ...($observerError !== null ? ['observer_error' => $observerError] : [])];
            if ($status !== 0) {
                break; // Preserve the actual open run and let the reader classify the product failure.
            }
        }
        $observation = $phases[array_key_last($phases)]['snapshot'];
        $identityPath = getenv('DW_PARITY_DIRECTION_IDENTITY') ?: '/sdk-directions/identity.json';
        $observation[$directionKey] = ['applicable' => true, 'phases' => $phases,
            'packages' => json_decode(file_get_contents($identityPath), true, flags: JSON_THROW_ON_ERROR),
            ...($signalDirection ? ['observer_start' => $observerStart] : [])];
        if ($childDirection) {
            $observation['children'] = [$phases[array_key_last($phases)]['child_snapshot']];
        }
        if ($signalDirection) {
            $observation['signal_deliveries'] = [];
            foreach ($phases as $index => &$phase) {
                if (! in_array($phase['phase'], ['signal-1', 'signal-2'], true)) {
                    continue;
                }
                foreach ($phase['requests'] as &$request) {
                    if ($request['method'] === 'POST' && $request['path'] === '/api/workflows/'.$options['workflow-id'].'/signal/payload') {
                        $request['decoded_input'] = $client->payloadCodec()->decodeEnvelope($request['request']['input']);
                        $request['typed_decoded_input'] = typedValue($request['decoded_input']);
                        $observation['signal_deliveries'][] = ['before' => $phases[$index - 1]['snapshot']['execution'],
                            'response' => $request['response']];
                    }
                }
                unset($request);
            }
            unset($phase);
            $observation[$directionKey]['phases'] = $phases;
        }
        if ($childDirection || $timerAuthor || $signalDirection) {
            $observation['workflow_polls'] = $observation['workflow_completions'] = [];
            foreach ($phases as $phase) {
                foreach ($phase['requests'] as $request) {
                    if ($request['path'] === '/api/worker/workflow-tasks/poll' && is_array($request['response']['task'] ?? null)) {
                        $observation['workflow_polls'][] = $request['response']['task'];
                    } elseif ($request['method'] === 'POST' && preg_match('#^/api/worker/workflow-tasks/[^/]+/complete$#', $request['path'])) {
                        $request['decoded_commands'] = [];
                        foreach ($request['request']['commands'] as $command) {
                            $decoded = [];
                            foreach (['arguments', 'result'] as $field) {
                                if (array_key_exists($field, $command)) {
                                    $decoded[$field] = $client->payloadCodec()->decodeEnvelope($command[$field]);
                                }
                            }
                            // These are field-name maps, including a timer command's
                            // empty map; preserve that JSON shape without changing values.
                            $request['decoded_commands'][] = ['decoded' => (object) $decoded, 'typed_decoded' => (object) array_map(typedValue(...), $decoded)];
                        }
                        $observation['workflow_completions'][] = $request;
                    }
                }
            }
        }

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
    foreach ([Worker::class, WorkflowContext::class] as $class) {
        $observation['sdk_loaded_sources'][$class] = hash_file('sha256', (new ReflectionClass($class))->getFileName());
    }
    if ($applicationRoot !== null) {
        $observation['workflow_source'] = InstalledVersions::getReference('durable-workflow/workflow');
        $observation['embedded_composer_lock_sha256'] = hash_file('sha256', $applicationRoot.'/composer.lock');
        $observation['workflow_loaded_sources'] = [];
        foreach (['TaskRepair', 'VersionResolver', 'WorkflowExecutor'] as $class) {
            $type = 'Workflow\\V2\\Support\\'.$class;
            $observation['workflow_loaded_sources']['src/V2/Support/'.$class.'.php'] = hash_file('sha256', (new ReflectionClass($type))->getFileName());
        }
    }
}
