<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use DurableWorkflow\Client;

function sdkUpdateActorOpen(string $language, string $phase, array $fixture, array $options, string $runId, float $deadline): array
{
    $arguments = match ($language) {
        'php' => [PHP_BINARY, __DIR__.'/sdk-directions/php.php'],
        'python' => ['python3', __DIR__.'/sdk-directions/python.py'],
        'rust' => [getenv('DW_PARITY_RUST_DIRECTION_WORKER') ?: '/sdk-directions/rust-worker'],
        default => throw new RuntimeException('Unknown query SDK language.'),
    };
    $arrivals = tempnam(sys_get_temp_dir(), 'parity-update-arrivals-');
    $journal = tempnam(sys_get_temp_dir(), 'parity-update-wire-');
    $receipt = tempnam(sys_get_temp_dir(), 'parity-update-stop-');
    unlink($receipt); // Presence means the real independent caller has finished.
    $proxyArguments = ['node', __DIR__.'/published-sdk-patch/observe-http.mjs', $options['url'], $journal, '--forwarding-state'];
    if ($phase === 'original') {
        array_push($proxyArguments, '--workflow-poll-after-start', $options['workflow-id']);
    }
    $proxyEnvironment = getenv();
    $proxyEnvironment['DW_PARITY_HTTP_REQUEST_ARRIVALS'] = $arrivals;
    $proxy = proc_open($proxyArguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $proxyPipes, null, $proxyEnvironment);
    if (! is_resource($proxy)) {
        unlink($journal);
        unlink($arrivals);
        throw new RuntimeException('Could not open SDK update observer.');
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
        unlink($arrivals);
        throw new RuntimeException('SDK update observer did not bind.');
    }
    array_push($arguments, $phase, $url, $options['workflow-id'], $runId, $options['fixture'],
        (string) max(0.001, $deadline - microtime(true)));
    $environment = getenv();
    $environment['DW_PARITY_UPDATE_RECEIPT_FILE'] = $receipt;
    $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (! is_resource($process)) {
        proc_terminate($proxy);
        fclose($proxyPipes[1]);
        fclose($proxyPipes[2]);
        proc_close($proxy);
        unlink($journal);
        unlink($arrivals);
        throw new RuntimeException('Could not open actual SDK update process.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    return compact('language', 'phase', 'arrivals', 'journal', 'receipt', 'proxy', 'proxyPipes', 'process', 'pipes', 'url')
        + ['stdout' => '', 'stderr' => '', 'exit_status' => null, 'observer_error' => null];
}
function sdkUpdateDirectionHttpObservation(array $fixture, array $options): array
{
    $evidence = getenv('DW_PARITY_FAILURE_EVIDENCE_DIR');
    if (! is_string($evidence) || ! is_dir($evidence)) {
        throw new RuntimeException('SDK update direction requires its evidence directory.');
    }
    $spec = $fixture['sdk_update_direction'];
    $client = new Client($options['url'], namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null);
    $deadline = microtime(true) + 35;
    $workflowId = $options['workflow-id'];
    $runId = '';
    $actors = $records = $updates = $order = [];
    $observerStart = null;
    $trace = static function (string $event, string $phase, array $details = []) use (&$order): int {
        $sequence = count($order) + 1;
        $order[] = ['sequence' => $sequence, 'event' => $event, 'phase' => $phase] + $details;

        return $sequence;
    };
    $open = static function (string $language, string $phase) use (&$actors, &$runId, $fixture, $options, $deadline, $trace): void {
        $actors[$phase] = sdkUpdateActorOpen($language, $phase, $fixture, $options, $runId, $deadline);
        $actors[$phase]['started_order'] = $trace('actor_started', $phase);
    };
    $finish = static function (string $phase, array $before, array $details = []) use (&$actors, &$records, &$runId, $client, $deadline, $evidence, $workflowId): array {
        $result = sdkUpdateActorFinish($actors[$phase], $deadline, $evidence, $workflowId);
        $records[$phase] = $result + ['started_order' => $actors[$phase]['started_order'],
            'snapshot_before' => $before, 'snapshot' => sdkDirectionSnapshot($client, $workflowId, $runId)] + $details;

        return $records[$phase];
    };
    $author = static function (string $phase, ?string $updateId, int $completedCount) use (&$actors, &$records, &$runId,
        &$observerStart, $client, $fixture, $spec, $options, $deadline, $workflowId, $trace, $finish): array {
        $actor = &$actors[$phase];
        $commandType = isset($fixture['sdk_update_failure']) && $phase === 'resumed-1' ? 'fail_update' : 'complete_update';
        $ack = $snapshot = null;
        while (microtime(true) < $deadline) {
            $requests = sdkQueryLiveWireRequests($actor);
            if ($phase === 'original' && $observerStart === null) {
                foreach ($requests as $request) {
                    if ($request['method'] === 'POST' && $request['path'] === '/api/worker/register'
                        && $request['status'] === 201 && ($request['response']['registered'] ?? null) === true
                        && ($request['request']['worker_id'] ?? null) === $workflowId.':original') {
                        $start = new Client($actor['url'], namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null);
                        $runId = $start->startWorkflow($fixture['workflow_type'], $workflowId,
                            $spec['task_queue'], [$fixture['input'], $fixture['signal_count']])->selectedRunId;
                        $observerStart = ['role' => 'observer', 'language' => 'php', 'pid' => getmypid(),
                            'sdk_version' => ltrim(InstalledVersions::getPrettyVersion('durable-workflow/sdk'), 'v'),
                            'run_id' => $runId];
                        $actor['observer_start'] = $observerStart;
                        $trace('start_receipt_observed', $phase, ['run_id' => $runId]);
                        break;
                    }
                }
            }
            if ($runId !== '') {
                $ack = sdkUpdateWorkflowAcknowledgement($requests, $workflowId, $runId, $phase, $updateId, $commandType);
                if ($ack !== null) {
                    $snapshot = sdkDirectionSnapshot($client, $workflowId, $runId);
                    $completed = count(array_filter($snapshot['events'], static fn (array $event): bool => $event['event_type'] === 'UpdateCompleted'));
                    if ($completed === $completedCount && $snapshot['status'] === ($phase === 'replacement' ? 'completed' : 'waiting')) {
                        break;
                    }
                }
            }
            if (! sdkQueryActorPoll($actor)) {
                // A synchronous SDK may already have exited after its response.
                $requests = sdkQueryLiveWireRequests($actor);
                $ack = sdkUpdateWorkflowAcknowledgement($requests, $workflowId, $runId, $phase, $updateId, $commandType);
                if ($ack !== null) {
                    $snapshot = sdkDirectionSnapshot($client, $workflowId, $runId);
                }
                break;
            }
            usleep(10000);
        }
        if ($ack === null || $snapshot === null || $snapshot['status'] !== ($phase === 'replacement' ? 'completed' : 'waiting')
            || count(array_filter($snapshot['events'], static fn (array $event): bool => $event['event_type'] === 'UpdateCompleted')) !== $completedCount) {
            throw new RuntimeException('Actual SDK update '.$phase.' phase did not reach its matching fenced checkpoint; sidecar retained.');
        }
        $ackOrder = $trace('checkpoint_receipt_observed', $phase, ['task_id' => $ack['task_id'], 'request_index' => $ack['request_index']]);
        file_put_contents($actor['receipt'], json_encode($ack, JSON_THROW_ON_ERROR)."\n");
        $result = $finish($phase, $snapshot, ['checkpoint_workflow_ack' => $ack, 'checkpoint_ack_order' => $ackOrder]);
        if (($result['worker_finished'] ?? false) !== true) {
            throw new RuntimeException('Actual SDK update '.$phase.' phase failed after its checkpoint; sidecar retained.');
        }

        return $snapshot;
    };
    try {
        $open($spec['workflow'], 'original');
        $snapshot = $author('original', null, 0);
        for ($index = 0; $index < 2; ++$index) {
            $number = $index + 1;
            $requestId = $workflowId.':update:'.$index;
            $initial = 'update-'.$number;
            $pending = 'duplicate-pending-'.$number;
            $resumed = 'resumed-'.$number;
            $duplicate = 'duplicate-completed-'.$number;
            $before = $snapshot;
            $open($spec['client'], $initial);
            $originalArrival = sdkUpdateSubmission($actors[$initial], $workflowId, $requestId, $deadline);
            $originalOrder = $trace('request_arrival_observed', $initial, ['request_observation_id' => $originalArrival['request_observation_id']]);
            $accepted = null;
            while (microtime(true) < $deadline) {
                $acceptedSnapshot = sdkDirectionSnapshot($client, $workflowId, $runId);
                $acceptedUpdates = array_values(array_filter($acceptedSnapshot['events'], static fn (array $event): bool => $event['event_type'] === 'UpdateAccepted'));
                if (count($acceptedUpdates) === $number) {
                    $accepted = $acceptedUpdates[$index];
                    break;
                }
                if (! sdkQueryActorPoll($actors[$initial])) {
                    break;
                }
                usleep(10000);
            }
            if ($accepted === null) {
                throw new RuntimeException('Original submitted update was not durably accepted before its duplicate; sidecar retained.');
            }
            $acceptanceOrder = $trace('server_acceptance_observed', $initial, ['update_id' => $accepted['payload']['update_id']]);
            $open($spec['client'], $pending);
            $pendingArrival = sdkUpdateSubmission($actors[$pending], $workflowId, $requestId, $deadline);
            $pendingOrder = $trace('request_arrival_observed', $pending, ['request_observation_id' => $pendingArrival['request_observation_id']]);
            $pendingSnapshot = sdkDirectionSnapshot($client, $workflowId, $runId);
            $pendingProofs = [];
            foreach ([$initial, $pending] as $clientPhase) {
                $live = sdkQueryLiveWireRequests($actors[$clientPhase]);
                $responses = array_filter($live, static fn (array $request): bool => $request['method'] === 'POST'
                    && $request['path'] === '/api/workflows/'.$workflowId.'/update/set_payload');
                if ($responses !== [] || ! sdkQueryActorPoll($actors[$clientPhase])) {
                    throw new RuntimeException('Actual completed-wait update client returned before its cold author; sidecar retained.');
                }
                $pendingProofs[$clientPhase] = ['client_running' => true, 'completed_update_responses' => 0];
            }
            $open($spec['workflow'], $resumed);
            $snapshot = $author($resumed, $accepted['payload']['update_id'], $number);
            $finish($initial, $before, ['submission' => $originalArrival, 'submission_order' => $originalOrder,
                'acceptance_snapshot' => $acceptedSnapshot, 'acceptance_order' => $acceptanceOrder,
                'pending_before_author' => $pendingProofs[$initial]]);
            $finish($pending, $acceptedSnapshot, ['submission' => $pendingArrival, 'submission_order' => $pendingOrder,
                'submission_snapshot' => $pendingSnapshot, 'pending_before_author' => $pendingProofs[$pending]]);
            $beforeDuplicate = sdkDirectionSnapshot($client, $workflowId, $runId);
            $open($spec['client'], $duplicate);
            $result = $finish($duplicate, $beforeDuplicate);
            if (($result['client_finished'] ?? false) !== true
                || ($records[$initial]['client_finished'] ?? false) !== true
                || ($records[$pending]['client_finished'] ?? false) !== true) {
                throw new RuntimeException('Actual update client failed; complete sidecars retained.');
            }
            $updates[] = ['name' => $fixture['update_name'], 'request_id' => $requestId,
                'arguments' => [$fixture['update_values'][$index]],
                'typed_arguments' => typedValue([$fixture['update_values'][$index]]), 'before' => $before['execution'],
                'history_after_acceptance' => $acceptedSnapshot['events'], 'history_after_duplicate' => $pendingSnapshot['events'],
                'history_before_result' => $beforeDuplicate['events'], 'history_after_result' => $result['snapshot']['events']];
            $snapshot = $result['snapshot'];
        }
        $beforeSignal = $snapshot;
        $open('php', 'signal');
        $signal = $finish('signal', $beforeSignal);
        if (($signal['sender_finished'] ?? false) !== true) {
            throw new RuntimeException('Actual finishing signal failed; sidecar retained.');
        }
        $open($spec['workflow'], 'replacement');
        $author('replacement', null, 2);
        $observation = sdkDirectionSnapshot($client, $workflowId, $runId);
        $phases = [];
        $observation['workflow_polls'] = $observation['workflow_completions'] = $observation['signal_deliveries'] = [];
        foreach ($spec['phase_order'] as $phase) {
            $record = $records[$phase];
            foreach ($record['requests'] as &$request) {
                if ($request['path'] === '/api/worker/workflow-tasks/poll' && is_array($request['response']['task'] ?? null)) {
                    $task = $request['response']['task'];
                    $observation['workflow_polls'][] = $task;
                    if (str_starts_with($phase, 'resumed-')) {
                        $index = (int) substr($phase, -1) - 1;
                        $accepted = array_values(array_filter($task['history_events'], static fn (array $event): bool =>
                            $event['event_type'] === 'UpdateAccepted' && ($event['payload']['update_id'] ?? null) === $task['workflow_update_id']));
                        if (count($accepted) !== 1) {
                            throw new RuntimeException('Update task has no unique original accepted update.');
                        }
                        $arguments = $client->payloadCodec()->decodeEnvelope($accepted[0]['payload']['arguments']);
                        $updates[$index]['worker'] = ['task' => $task, 'arguments' => $arguments, 'typed_arguments' => typedValue($arguments)];
                    }
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
                } elseif ($request['method'] === 'POST' && $request['path'] === '/api/workflows/'.$workflowId.'/signal/payload') {
                    $request['decoded_input'] = $client->payloadCodec()->decodeEnvelope($request['request']['input']);
                    $request['typed_decoded_input'] = typedValue($request['decoded_input']);
                    $observation['signal_deliveries'][] = ['before' => $record['snapshot_before']['execution'], 'response' => $request['response']];
                } elseif ($request['method'] === 'POST' && $request['path'] === '/api/workflows/'.$workflowId.'/update/set_payload') {
                    $request['decoded_input'] = $client->payloadCodec()->decodeEnvelope($request['request']['input']);
                    $request['typed_decoded_input'] = typedValue($request['decoded_input']);
                    if (isset($fixture['sdk_update_failure']) && str_ends_with($phase, '-1')
                        && $request['status'] === 422 && ($request['response']['outcome'] ?? null) === 'update_failed') {
                        $updates[0]['failed_receipts'][] = $request['response'];
                    }
                    if (isset($request['response']['result_envelope'])) {
                        $request['decoded_result'] = $client->payloadCodec()->decodeEnvelope($request['response']['result_envelope']);
                        $request['typed_decoded_result'] = typedValue($request['decoded_result']);
                        $index = (int) substr($phase, -1) - 1;
                        $updates[$index]['completed_receipts'][] = $request['response'];
                        $updates[$index]['result'] = $request['decoded_result'];
                        $updates[$index]['typed_result'] = $request['typed_decoded_result'];
                    }
                }
            }
            unset($request);
            $phases[] = $record;
        }
        $identity = getenv('DW_PARITY_DIRECTION_IDENTITY') ?: '/sdk-directions/identity.json';
        $observation['updates'] = $updates;
        $observation['sdk_update_direction'] = ['applicable' => true, 'phases' => $phases, 'observer_order' => $order,
            'packages' => json_decode(file_get_contents($identity), true, flags: JSON_THROW_ON_ERROR), 'observer_start' => $observerStart];

        return $observation;
    } finally {
        foreach ($actors as &$actor) {
            if (! ($actor['closed'] ?? false)) {
                proc_terminate($actor['process']);
                sdkUpdateActorFinish($actor, microtime(true), $evidence, $workflowId);
            }
        }
        unset($actor);
    }
}


function sdkUpdateArrivalRecords(string $journal): array
{
    if ($journal !== '' && ! str_ends_with($journal, "\n")) {
        throw new RuntimeException('Final update arrival journal has an unterminated record.');
    }

    return array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", $journal), static fn (string $line): bool => $line !== '')));
}

function sdkUpdateLiveArrivals(array $actor): array
{
    $journal = file_get_contents($actor['arrivals']);
    $newline = strrpos($journal, "\n");

    return $newline === false ? [] : sdkUpdateArrivalRecords(substr($journal, 0, $newline + 1));
}

function sdkUpdateSubmissionArrival(array $arrivals, string $workflowId, string $requestId): ?array
{
    foreach ($arrivals as $arrival) {
        if ($arrival['schema'] === 'durable-workflow.server-parity-http-request-arrival/v1'
            && $arrival['method'] === 'POST' && $arrival['path'] === '/api/workflows/'.$workflowId.'/update/set_payload'
            && $arrival['request_body_complete'] === true && $arrival['upstream_request_started'] === true
            && $arrival['response_observed'] === false) {
            $request = json_decode($arrival['request_body'], true, flags: JSON_THROW_ON_ERROR);
            if (($request['request_id'] ?? null) === $requestId) {
                return $arrival;
            }
        }
    }

    return null;
}

function sdkUpdateSubmission(array &$actor, string $workflowId, string $requestId, float $deadline): array
{
    while (microtime(true) < $deadline) {
        $arrival = sdkUpdateSubmissionArrival(sdkUpdateLiveArrivals($actor), $workflowId, $requestId);
        if ($arrival !== null) {
            return $arrival;
        }
        if (! sdkQueryActorPoll($actor)) {
            break;
        }
        usleep(10000);
    }
    throw new RuntimeException('Actual update submission did not reach its complete forwarded request; sidecar retained.');
}

function sdkUpdateWorkflowAcknowledgement(array $requests, string $workflowId, string $runId, string $phase, ?string $updateId, string $commandType = 'complete_update'): ?array
{
    if ($phase === 'original' || $phase === 'replacement') {
        return sdkQueryWorkflowAcknowledgement($requests, $workflowId, $runId, $phase === 'original' ? 0 : 2);
    }
    foreach ($requests as $pollIndex => $poll) {
        $task = $poll['response']['task'] ?? null;
        if (($poll['method'] ?? null) !== 'POST' || ($poll['path'] ?? null) !== '/api/worker/workflow-tasks/poll'
            || ($poll['status'] ?? null) !== 200 || ! is_array($task)
            || ($task['workflow_id'] ?? null) !== $workflowId || ($task['run_id'] ?? null) !== $runId
            || ($task['workflow_update_id'] ?? null) !== $updateId || ! is_string($updateId) || $updateId === ''
            || ! is_string($task['task_id'] ?? null) || $task['task_id'] === ''
            || ! is_string($task['lease_owner'] ?? null) || $task['lease_owner'] === ''
            || ($task['workflow_task_attempt'] ?? null) !== 1) {
            continue;
        }
        $path = '/api/worker/workflow-tasks/'.$task['task_id'].'/complete';
        foreach ($requests as $index => $completion) {
            $request = $completion['request'] ?? null;
            $response = $completion['response'] ?? null;
            if ($index <= $pollIndex || ($completion['method'] ?? null) !== 'POST' || ($completion['path'] ?? null) !== $path
                || ($completion['status'] ?? null) !== 200 || ($completion['client_cancelled'] ?? true) !== false
                || ($completion['request_body_complete'] ?? false) !== true || ($completion['upstream_request_started'] ?? false) !== true
                || ($completion['transport_error'] ?? null) !== null || ! is_array($request) || ! is_array($response)
                || ($request['lease_owner'] ?? null) !== $task['lease_owner']
                || ($request['workflow_task_attempt'] ?? null) !== $task['workflow_task_attempt']
                || ($response['task_id'] ?? null) !== $task['task_id'] || ($response['run_id'] ?? null) !== $runId
                || ($response['workflow_task_attempt'] ?? null) !== $task['workflow_task_attempt']
                || ($response['recorded'] ?? null) !== true || ($response['reason'] ?? null) !== null) {
                continue;
            }
            $commands = $request['commands'] ?? null;
            if (! in_array($commandType, ['complete_update', 'fail_update'], true)
                || ! is_array($commands) || count($commands) !== 1 || ($commands[0]['type'] ?? null) !== $commandType
                || ($commands[0]['update_id'] ?? null) !== $updateId) {
                continue;
            }

            return ['path' => $path, 'task_id' => $task['task_id'], 'lease_owner' => $task['lease_owner'],
                'workflow_task_attempt' => $task['workflow_task_attempt'], 'request_index' => $index, 'status' => 200];
        }
    }

    return null;
}

function sdkUpdateActorFinish(array &$actor, float $deadline, string $evidence, string $workflowId): array
{
    while (sdkQueryActorPoll($actor)) {
        if (microtime(true) >= $deadline) {
            $actor['observer_error'] = 'Actual SDK update process exceeded the remaining fixture budget.';
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
    $arrivalJournal = file_get_contents($actor['arrivals']);
    $arrivals = sdkUpdateArrivalRecords($arrivalJournal);
    $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $workflowId);
    file_put_contents($evidence.'/sdk-update-direction-'.$name.'-'.$actor['phase'].'.json', json_encode([
        'language' => $actor['language'], 'phase' => $actor['phase'],
        'stdout' => $actor['stdout'], 'stderr' => $actor['stderr'], 'exit_status' => $status,
        'transport_jsonl' => $journal, 'request_arrivals_jsonl' => $arrivalJournal, 'observer_error' => $actor['observer_error'],
        ...($actor['phase'] === 'original' ? ['observer_start' => $actor['observer_start'] ?? null] : []),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
    $requests = sdkQueryWireRequests($actor, $journal);
    proc_terminate($actor['proxy']);
    fclose($actor['proxyPipes'][1]);
    fclose($actor['proxyPipes'][2]);
    proc_close($actor['proxy']);
    unlink($actor['journal']);
    unlink($actor['arrivals']);
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

    return $result + ['requests' => $requests, 'request_arrivals' => $arrivals,
        ...($actor['observer_error'] !== null ? ['observer_error' => $actor['observer_error']] : [])];
}
