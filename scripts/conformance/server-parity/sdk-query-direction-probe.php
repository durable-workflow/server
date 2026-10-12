<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use DurableWorkflow\Client;

function sdkQueryActorOpen(string $language, string $phase, array $fixture, array $options, string $runId, float $deadline): array
{
    $arguments = match ($language) {
        'php' => [PHP_BINARY, __DIR__.'/sdk-directions/php.php'],
        'python' => ['python3', __DIR__.'/sdk-directions/python.py'],
        'rust' => [getenv('DW_PARITY_RUST_DIRECTION_WORKER') ?: '/sdk-directions/rust-worker'],
        default => throw new RuntimeException('Unknown query SDK language.'),
    };
    $journal = tempnam(sys_get_temp_dir(), 'parity-query-wire-');
    $receipt = tempnam(sys_get_temp_dir(), 'parity-query-stop-');
    unlink($receipt); // Presence means the real independent caller has finished.
    $proxyArguments = ['node', __DIR__.'/published-sdk-patch/observe-http.mjs', $options['url'], $journal, '--forwarding-state'];
    if ($phase === 'original') {
        array_push($proxyArguments, '--workflow-poll-after-start', $options['workflow-id']);
    }
    $proxy = proc_open($proxyArguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $proxyPipes);
    if (! is_resource($proxy)) {
        unlink($journal);
        throw new RuntimeException('Could not open SDK query observer.');
    }
    fclose($proxyPipes[0]);
    stream_set_blocking($proxyPipes[1], false);
    stream_set_blocking($proxyPipes[2], false);
    $url = '';
    do {
        $url .= stream_get_contents($proxyPipes[1]);
        if (str_contains($url, "\n") || ! proc_get_status($proxy)['running'] || microtime(true) >= $deadline) {
            break;
        }
        usleep(10000);
    } while (true);
    $url = trim($url);
    if (! preg_match('#^http://127\.0\.0\.1:[0-9]+$#', $url)) {
        proc_terminate($proxy);
        fclose($proxyPipes[1]);
        fclose($proxyPipes[2]);
        proc_close($proxy);
        unlink($journal);
        throw new RuntimeException('SDK query observer did not bind.');
    }
    array_push($arguments, $phase, $url, $options['workflow-id'], $runId, $options['fixture'],
        (string) max(0.001, $deadline - microtime(true)));
    $environment = getenv();
    $environment['DW_PARITY_QUERY_RECEIPT_FILE'] = $receipt;
    $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (! is_resource($process)) {
        proc_terminate($proxy);
        fclose($proxyPipes[1]);
        fclose($proxyPipes[2]);
        proc_close($proxy);
        unlink($journal);
        throw new RuntimeException('Could not open actual SDK query process.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    return compact('language', 'phase', 'journal', 'receipt', 'proxy', 'proxyPipes', 'process', 'pipes', 'url')
        + ['stdout' => '', 'stderr' => '', 'exit_status' => null, 'observer_error' => null];
}

function sdkQueryActorPoll(array &$actor): bool
{
    $actor['stdout'] .= stream_get_contents($actor['pipes'][1]);
    $actor['stderr'] .= stream_get_contents($actor['pipes'][2]);
    $status = proc_get_status($actor['process']);
    if (! $status['running'] && $actor['exit_status'] === null) {
        $actor['exit_status'] = $status['exitcode'];
        $actor['stdout'] .= stream_get_contents($actor['pipes'][1]);
        $actor['stderr'] .= stream_get_contents($actor['pipes'][2]);
    }

    return $status['running'];
}

function sdkQueryWireRequests(array $actor): array
{
    return array_map(static function (string $line): array {
        $wire = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        foreach (['request', 'response'] as $side) {
            $bytes = $wire[$side.'_body'];
            $incomplete = $side === 'request' && ! $wire['request_body_complete']
                && ! $wire['upstream_request_started'];
            $wire[$side] = $bytes === '' || $incomplete ? null : json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        }
        unset($wire['request_body'], $wire['response_body'], $wire['response_bytes_base64']);
        // Keep every task/receipt field; summarize only repeated discovery bodies.
        if (is_array($wire['response']) && in_array($wire['path'], ['/api', '/api/version', '/api/capabilities', '/api/cluster/info'], true)) {
            $wire['response'] = sdkDirectionResponseSummary($wire['response']);
        }

        return $wire;
    }, file($actor['journal'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
}

function sdkQueryActorFinish(array &$actor, float $deadline, string $evidence, string $workflowId): array
{
    while (sdkQueryActorPoll($actor)) {
        if (microtime(true) >= $deadline) {
            $actor['observer_error'] = 'Actual SDK query process exceeded the remaining fixture budget.';
            proc_terminate($actor['process']);
            break;
        }
        usleep(10000);
    }
    foreach ([1, 2] as $pipe) {
        $actor[$pipe === 1 ? 'stdout' : 'stderr'] .= stream_get_contents($actor['pipes'][$pipe]);
        fclose($actor['pipes'][$pipe]);
    }
    $closed = proc_close($actor['process']);
    $status = $actor['exit_status'] ?? $closed;
    $journal = file_get_contents($actor['journal']);
    $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $workflowId);
    file_put_contents($evidence.'/sdk-query-direction-'.$name.'-'.$actor['phase'].'.json', json_encode([
        'language' => $actor['language'], 'phase' => $actor['phase'],
        'stdout' => $actor['stdout'], 'stderr' => $actor['stderr'], 'exit_status' => $status,
        'transport_jsonl' => $journal, 'observer_error' => $actor['observer_error'],
        ...($actor['phase'] === 'original' ? ['observer_start' => $actor['observer_start'] ?? null] : []),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
    $requests = sdkQueryWireRequests($actor);
    proc_terminate($actor['proxy']);
    fclose($actor['proxyPipes'][1]);
    fclose($actor['proxyPipes'][2]);
    proc_close($actor['proxy']);
    unlink($actor['journal']);
    if (is_file($actor['receipt'])) {
        unlink($actor['receipt']);
    }
    $actor['closed'] = true;
    $result = $status === 0 ? json_decode($actor['stdout'], true, flags: JSON_THROW_ON_ERROR)
        : ['language' => $actor['language'], 'phase' => $actor['phase'], 'worker_finished' => false,
            'worker_error' => trim($actor['stderr']), 'actual_exit_status' => $status];
    if (array_key_exists('client_result', $result)) {
        $result['typed_client_result'] = typedValue($result['client_result']);
    }

    return $result + ['requests' => $requests,
        ...($actor['observer_error'] !== null ? ['observer_error' => $actor['observer_error']] : [])];
}

function sdkQueryDirectionHttpObservation(array $fixture, array $options): array
{
    $evidence = getenv('DW_PARITY_FAILURE_EVIDENCE_DIR');
    if (! is_string($evidence) || ! is_dir($evidence)) {
        throw new RuntimeException('SDK query direction requires its evidence directory.');
    }
    $spec = $fixture['sdk_query_direction'];
    $client = new Client($options['url'], namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null);
    $deadline = microtime(true) + 35;
    $runId = '';
    $phases = $actors = [];
    $observerStart = null;
    $snapshot = null;
    try {
        foreach (['original', 'resumed', 'replacement'] as $checkpoint => $phase) {
            $actors[$phase] = sdkQueryActorOpen($spec['workflow'], $phase, $fixture, $options, $runId, $deadline);
            $author = &$actors[$phase];
            while (true) {
                if (! sdkQueryActorPoll($author) || microtime(true) >= $deadline) {
                    break;
                }
                if ($phase === 'original' && $observerStart === null) {
                    foreach (sdkQueryWireRequests($author) as $request) {
                        if ($request['method'] === 'POST' && $request['path'] === '/api/worker/register'
                            && $request['status'] === 201 && ($request['response']['registered'] ?? null) === true
                            && ($request['request']['worker_id'] ?? null) === $options['workflow-id'].':original') {
                            $startClient = new Client($author['url'], namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null);
                            $runId = $startClient->startWorkflow($fixture['workflow_type'], $options['workflow-id'],
                                $spec['task_queue'], [$fixture['input'], $fixture['signal_count']])->selectedRunId;
                            $observerStart = ['role' => 'observer', 'language' => 'php', 'pid' => getmypid(),
                                'sdk_version' => ltrim(InstalledVersions::getPrettyVersion('durable-workflow/sdk'), 'v'),
                                'run_id' => $runId];
                            $author['observer_start'] = $observerStart;
                            break;
                        }
                    }
                }
                if ($runId !== '') {
                    $snapshot = sdkDirectionSnapshot($client, $options['workflow-id'], $runId);
                    $applied = count(array_filter($snapshot['events'], static fn (array $event): bool => $event['event_type'] === 'SignalApplied'));
                    if ($applied === $checkpoint && $snapshot['status'] === ($checkpoint === 2 ? 'completed' : 'waiting')) {
                        break;
                    }
                }
                usleep(10000);
            }
            if ($snapshot === null || $snapshot['status'] !== ($checkpoint === 2 ? 'completed' : 'waiting')
                || count(array_filter($snapshot['events'], static fn (array $event): bool => $event['event_type'] === 'SignalApplied')) !== $checkpoint) {
                $failed = sdkQueryActorFinish($author, $deadline, $evidence, $options['workflow-id']);
                if ($runId === '') {
                    throw new RuntimeException('Actual query author did not reach its original run; complete sidecar retained.');
                }
                $phases[] = $failed + ['snapshot' => sdkDirectionSnapshot($client, $options['workflow-id'], $runId)];
                break;
            }
            $before = $snapshot;
            $queryPhase = 'query-'.$checkpoint;
            $actors[$queryPhase] = sdkQueryActorOpen($spec['client'], $queryPhase, $fixture, $options, $runId, $deadline);
            $query = sdkQueryActorFinish($actors[$queryPhase], $deadline, $evidence, $options['workflow-id']);
            $after = sdkDirectionSnapshot($client, $options['workflow-id'], $runId);
            if (($query['client_finished'] ?? false) === true) {
                file_put_contents($author['receipt'], $actors[$queryPhase]['stdout']);
            } else {
                proc_terminate($author['process']);
            }
            $result = sdkQueryActorFinish($author, $deadline, $evidence, $options['workflow-id']);
            $phases[] = $result + ['snapshot' => sdkDirectionSnapshot($client, $options['workflow-id'], $runId)];
            $phases[] = $query + ['snapshot_before' => $before, 'snapshot' => $after];
            unset($author);
            if (($result['worker_finished'] ?? false) !== true || ($query['client_finished'] ?? false) !== true) {
                break;
            }
            if ($checkpoint < 2) {
                $signalPhase = 'signal-'.($checkpoint + 1);
                $actors[$signalPhase] = sdkQueryActorOpen('php', $signalPhase, $fixture, $options, $runId, $deadline);
                $signal = sdkQueryActorFinish($actors[$signalPhase], $deadline, $evidence, $options['workflow-id']);
                $phases[] = $signal + ['snapshot_before' => $after,
                    'snapshot' => sdkDirectionSnapshot($client, $options['workflow-id'], $runId)];
                if (($signal['sender_finished'] ?? false) !== true) {
                    break;
                }
            }
        }
        $observation = sdkDirectionSnapshot($client, $options['workflow-id'], $runId);
        $identity = getenv('DW_PARITY_DIRECTION_IDENTITY') ?: '/sdk-directions/identity.json';
        $observation['sdk_query_direction'] = ['applicable' => true, 'phases' => $phases,
            'packages' => json_decode(file_get_contents($identity), true, flags: JSON_THROW_ON_ERROR),
            'observer_start' => $observerStart];
        $observation['workflow_polls'] = $observation['workflow_completions'] = $observation['signal_deliveries'] = [];
        foreach ($phases as &$record) {
            foreach ($record['requests'] as &$request) {
                if ($request['path'] === '/api/worker/workflow-tasks/poll' && is_array($request['response']['task'] ?? null)) {
                    $observation['workflow_polls'][] = $request['response']['task'];
                } elseif ($request['path'] === '/api/worker/query-tasks/poll' && is_array($request['response']['task'] ?? null)) {
                    $task = $request['response']['task'];
                    $request['typed_query_arguments'] = typedValue($client->payloadCodec()->decodeEnvelope($task['query_arguments']));
                    $request['typed_workflow_arguments'] = typedValue($client->payloadCodec()->decodeEnvelope($task['workflow_arguments']));
                } elseif ($request['method'] === 'POST' && preg_match('#^/api/worker/workflow-tasks/[^/]+/complete$#', $request['path'])) {
                    $request['decoded_commands'] = [];
                    foreach ($request['request']['commands'] as $command) {
                        $decoded = [];
                        foreach (['arguments', 'result'] as $field) {
                            if (array_key_exists($field, $command)) {
                                $decoded[$field] = $client->payloadCodec()->decodeEnvelope($command[$field]);
                            }
                        }
                        $request['decoded_commands'][] = ['decoded' => (object) $decoded,
                            'typed_decoded' => (object) array_map(typedValue(...), $decoded)];
                    }
                    $observation['workflow_completions'][] = $request;
                } elseif ($request['method'] === 'POST' && $request['path'] === '/api/workflows/'.$options['workflow-id'].'/signal/payload') {
                    $request['decoded_input'] = $client->payloadCodec()->decodeEnvelope($request['request']['input']);
                    $request['typed_decoded_input'] = typedValue($request['decoded_input']);
                    $observation['signal_deliveries'][] = ['before' => $record['snapshot_before']['execution'],
                        'response' => $request['response']];
                } elseif ($request['method'] === 'POST' && preg_match('#^/api/workflows/[^/]+/query/state$#', $request['path'])) {
                    $input = $request['request']['input'] ?? null;
                    $request['typed_decoded_input'] = typedValue($input === null ? [] : $client->payloadCodec()->decodeEnvelope($input));
                    if (isset($request['response']['result_envelope'])) {
                        $request['decoded_result'] = $client->payloadCodec()->decodeEnvelope($request['response']['result_envelope']);
                        $request['typed_decoded_result'] = typedValue($request['decoded_result']);
                    }
                } elseif ($request['method'] === 'POST' && preg_match('#^/api/worker/query-tasks/[^/]+/complete$#', $request['path'])) {
                    $request['decoded_result'] = $client->payloadCodec()->decodeEnvelope($request['request']['result_envelope']);
                    $request['typed_decoded_result'] = typedValue($request['decoded_result']);
                }
            }
            unset($request);
        }
        unset($record);
        $observation['sdk_query_direction']['phases'] = $phases;

        return $observation;
    } finally {
        foreach ($actors as &$actor) {
            if (! ($actor['closed'] ?? false)) {
                proc_terminate($actor['process']);
                sdkQueryActorFinish($actor, microtime(true), $evidence, $options['workflow-id']);
            }
        }
        unset($actor);
    }
}
