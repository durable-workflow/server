<?php

declare(strict_types=1);

require '/tmp/dw-php/vendor/autoload.php';

use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServiceOperationFailed;
use DurableWorkflow\Transport\Psr18Transport;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\WorkflowContext;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\TransferStats;

$input = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);

if (($argv[2] ?? null) === 'worker') {
    $namespace = $argv[3];
    error_log(json_encode([
        'process_id' => getmypid(),
        'installed_sdk_version' => Composer\InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
        'namespace' => $namespace,
    ], JSON_THROW_ON_ERROR));
    $http = new HttpClient(['http_errors' => false, 'on_stats' => static function (TransferStats $stats): void {
        $response = $stats->getResponse();
        if ($response === null || $response->getStatusCode() < 400 || !$response->getBody()->isSeekable()) {
            return;
        }
        $stream = $response->getBody();
        $position = $stream->tell();
        try {
            $body = json_decode((string) $stream, true);
        } finally {
            $stream->seek($position);
        }
        error_log(json_encode([
            'event' => 'worker_http_refusal', 'status' => $response->getStatusCode(),
            'path' => $stats->getRequest()->getUri()->getPath(),
            'reason' => $body['reason'] ?? null, 'limit' => $body['limit'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }]);
    $client = new Client('http://server:8080', namespace: $namespace,
        workerToken: $input['worker_token'] ?? $input['token'], transport: new Psr18Transport($http));
    $worker = ($input['scenario'] ?? null) === 'caller_cancelled'
        ? new Worker($client, 'nexus-php-caller', enableCooperativeCancellation: true)
        : Worker::create($client, 'nexus-php-caller');
    $worker
        ->registerWorkflow('nexus.fixture.php.caller', static function (
            WorkflowContext $context,
            string $endpoint,
            string $service,
            string $operation,
            string $name,
            ?string $targetNamespace,
            bool $handleFailure = true,
        ): array {
            $external = $name === 'external';
            if ($external) {
                $name = 'nexus-external:'.str_repeat('x', 4 * 1024 * 1024).'☃';
            }
            $options = ['wait_timeout_seconds' => 5];
            if ($targetNamespace !== null) {
                $options['namespace'] = $targetNamespace;
            }
            try {
                $result = $context->serviceOperation($endpoint, $service, $operation, [$name], $options);
            } catch (ServiceOperationFailed $failure) {
                if (!$handleFailure) {
                    throw $failure;
                }
                return [
                    'service_call_id' => $failure->serviceCallId,
                    'status' => $failure->cancelled ? 'cancelled' : 'failed',
                    'failure_type' => $failure->failureType,
                    'failure_message' => $failure->getMessage(),
                    'failure_class' => $failure::class,
                    'cancelled' => $failure->cancelled,
                    'service_call' => $failure->failure,
                ];
            }

            if ($external) {
                $value = $result->responsePayload;

                return ['service_call_id' => $result->serviceCallId, 'status' => $result->status,
                    'external_result' => [
                        'language' => $value['language'],
                        'name_bytes' => strlen($value['name']), 'name_sha256' => hash('sha256', $value['name']),
                        'greeting_bytes' => strlen($value['greeting']), 'greeting_sha256' => hash('sha256', $value['greeting']),
                        'bigint' => (string) $value['bigint'], 'bigint_type' => get_debug_type($value['bigint']),
                        'nullable' => $value['nullable'], 'nullable_present' => array_key_exists('nullable', $value),
                        'unicode' => $value['unicode'],
                    ]];
            }

            return [
                'service_call_id' => $result->serviceCallId,
                'status' => $result->status,
                'outcome' => $result->outcome,
                'result' => $result->responsePayload,
                'service_call' => $result->serviceCall,
            ];
        })
        ->run(1);
    exit(0);
}

$observations = [];
foreach (['shared', 'tenant-a'] as $namespace) {
    $startedAt = microtime(true);
    $logPath = '/tmp/nexus-php-caller-'.$namespace.'-'.getmypid().'.log';
    $worker = proc_open([PHP_BINARY, __FILE__, $argv[1], 'worker', $namespace], [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $logPath, 'a'],
        2 => ['file', $logPath, 'a'],
    ], $pipes);
    if (!is_resource($worker)) {
        throw new RuntimeException('Could not start the actual PHP caller worker.');
    }
    $row = null;
    try {
        $client = new Client('http://server:8080', namespace: $namespace, controlToken: $input['token']);
        $workflowArguments = [$input['endpoint'], $input['service'], $input['operation'], $input['name'] ?? 'world',
            $namespace === 'shared' ? null : 'shared'];
        if (($input['scenario'] ?? null) === 'uncaught_failed') {
            $workflowArguments[] = false;
        }
        $handle = $client->startWorkflow('nexus.fixture.php.caller',
            'nexus-php-caller-'.$namespace.'-'.($input['scenario'] ?? 'completed').'-'.getmypid(),
            'nexus-php-caller', $workflowArguments);
        $row = [
            'caller_namespace' => $namespace, 'target_namespace' => 'shared',
            'scenario' => $input['scenario'] ?? 'completed',
            'workflow_id' => $handle->workflowId, 'run_id' => $handle->selectedRunId,
            'process_id' => getmypid(), 'worker_process_id' => proc_get_status($worker)['pid'],
            'worker_signal_handlers_available' => function_exists('pcntl_async_signals') && function_exists('pcntl_signal'),
            'installed_sdk_version' => Composer\InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
            'public_method' => 'WorkflowContext.serviceOperation',
        ];
        if (($input['scenario'] ?? null) === 'permission_denied') {
            $row['admin_namespace_name'] = 'nexus-worker-denied-'.getmypid().'-'.$namespace;
            $workerOnly = new Client('http://server:8080', namespace: $namespace, controlToken: $input['worker_token']);
            try {
                $workerOnly->createNamespace($row['admin_namespace_name']);
                $row['admin_refusal_status'] = 200;
            } catch (DurableWorkflow\Exception\ServerException $refused) {
                $row['admin_refusal_status'] = $refused->status;
                $row['admin_refusal_type'] = $refused::class;
            }
        }
        try {
            if (in_array($input['scenario'] ?? null, ['cancelled', 'caller_cancelled'], true)) {
                $readyUntil = microtime(true) + 20;
                $serviceClient = new Client('http://server:8080', namespace: 'shared', controlToken: $input['token']);
                do {
                    $history = $client->workflowHistory($handle->workflowId, $handle->selectedRunId);
                    $started = array_values(array_filter($history['events'] ?? [],
                        static fn (array $event): bool => ($event['event_type'] ?? null) === 'ServiceCallStarted'));
                    $linked = $started[0]['payload']['service_call'] ?? [];
                    $linkedId = $linked['linked_workflow_instance_id'] ?? null;
                    $linkedRun = $linked['linked_workflow_run_id'] ?? null;
                    $serviceHistory = is_string($linkedId) && is_string($linkedRun)
                        ? $serviceClient->workflowHistory($linkedId, $linkedRun) : [];
                    $waiting = array_filter($serviceHistory['events'] ?? [],
                        static fn (array $event): bool => ($event['event_type'] ?? null) === 'TimerScheduled');
                    if ($waiting !== []) {
                        $row['service_call_before_cancellation'] = $linked;
                        if (($input['scenario'] ?? null) === 'caller_cancelled') {
                            $row['caller_cancellation_requests'] = [
                                $client->requestWorkflowCancellation($handle->workflowId, 'fixture waiting caller cancellation', 30, $handle->selectedRunId),
                                $client->requestWorkflowCancellation($handle->workflowId, 'duplicate must not replace reason', 1, $handle->selectedRunId),
                            ];
                        } else {
                            $row['linked_cancellation_requests'] = [
                                $serviceClient->requestWorkflowCancellation($linkedId, 'fixture service cancellation', 30, $linkedRun),
                                $serviceClient->requestWorkflowCancellation($linkedId, 'duplicate must not replace reason', 1, $linkedRun),
                            ];
                        }
                        break;
                    }
                    usleep(100000);
                } while (microtime(true) < $readyUntil);
                if (!isset($row['linked_cancellation_requests']) && !isset($row['caller_cancellation_requests'])) {
                    throw new RuntimeException('The real linked service did not reach its durable timer before cancellation.');
                }
            }
            $row['result'] = $handle->resultOfSelectedRun(30);
        } catch (Throwable $failure) {
            $row['failure'] = ['type' => $failure::class, 'message' => $failure->getMessage()];
        }
        $row['history'] = $client->workflowHistory($handle->workflowId, $handle->selectedRunId);
        $row['caller_status'] = $handle->describeSelectedRun()->status;
        $admissions = array_values(array_filter($row['history']['events'] ?? [],
            static fn (array $event): bool => ($event['event_type'] ?? $event['type'] ?? null) === 'ServiceCallStarted'));
        $row['request_qualified'] = count($admissions) === 1
            && ($admissions[0]['payload']['caller_workflow_instance_id'] ?? null) === $handle->workflowId
            && ($admissions[0]['payload']['caller_workflow_run_id'] ?? null) === $handle->selectedRunId
            && ($admissions[0]['payload']['caller_sdk_language'] ?? null) === 'sdk-php';
        if (($input['scenario'] ?? null) === 'uncaught_failed' && count($admissions) === 1) {
            $row['original_service_call_id'] = $admissions[0]['payload']['service_call_id'] ?? null;
        }
        $surface = $row['result']['service_call'] ?? $admissions[0]['payload']['service_call'] ?? [];
        $linkedId = $surface['linked_workflow_instance_id'] ?? null;
        $linkedRun = $surface['linked_workflow_run_id'] ?? null;
        if (is_string($linkedId) && is_string($linkedRun)) {
            $serviceClient = new Client('http://server:8080', namespace: 'shared', controlToken: $input['token']);
            $serviceHandle = $serviceClient->workflowHandle($linkedId, $linkedRun);
            try {
                $row['linked_service_result'] = $serviceHandle->resultOfSelectedRun(10);
            } catch (Throwable $failure) {
                $row['linked_service_failure'] = ['type' => $failure::class, 'message' => $failure->getMessage()];
            }
            $row['linked_service_run_id'] = $linkedRun;
            $row['linked_service_history'] = $serviceClient->workflowHistory($linkedId, $linkedRun);
        }
        $expected = ['greeting' => 'hello, world', 'language' => $input['service_language'], 'name' => 'world'];
        $matches = static function (mixed $value) use ($expected): bool {
            if (!is_array($value)) {
                return false;
            }
            ksort($value);

            return $value === $expected;
        };
        $row['service_input_qualified'] = $matches($row['linked_service_result'] ?? null);
        $row['completed'] = ($row['result']['status'] ?? null) === 'completed'
            && $matches($row['result']['result'] ?? null);
        $row['typed_failure_qualified'] = ($row['result']['status'] ?? null) === 'failed'
            && ($row['result']['failure_class'] ?? null) === ServiceOperationFailed::class
            && ($row['result']['failure_message'] ?? null) === 'fixture service failure'
            && ($row['result']['cancelled'] ?? null) === false;
        $row['uncaught_failure_qualified'] = ($input['scenario'] ?? null) === 'uncaught_failed'
            && ($row['failure']['type'] ?? null) === DurableWorkflow\Exception\WorkflowFailed::class
            && strtolower($row['caller_status'] ?? '') === 'failed'
            && count(array_filter($row['history']['events'] ?? [],
                static fn (array $event): bool => ($event['event_type'] ?? null) === 'WorkflowFailed')) === 1
            && count(array_filter($row['history']['events'] ?? [],
                static fn (array $event): bool => ($event['event_type'] ?? null) === 'WorkflowCompleted')) === 0;
        $row['cancellation_qualified'] = ($row['result']['status'] ?? null) === 'cancelled'
            && ($row['result']['failure_class'] ?? null) === ServiceOperationFailed::class
            && ($row['result']['cancelled'] ?? null) === true;
        $row['caller_cancellation_qualified'] = isset($row['caller_cancellation_requests'])
            && strtolower($row['caller_status'] ?? '') === 'cancelled'
            && count(array_filter($row['linked_service_history']['events'] ?? [],
                static fn (array $event): bool => ($event['event_type'] ?? null) === 'WorkflowCancelled')) === 1;
        $refused = array_values(array_filter($row['history']['events'] ?? [],
            static fn (array $event): bool => ($event['event_type'] ?? null) === 'ServiceCallFailed'
                && ($event['payload']['outcome'] ?? null) === 'rejected_forbidden'));
        $row['authorization_qualified'] = ($input['scenario'] ?? null) === 'permission_denied'
            && count($refused) === 1 && $admissions === []
            && ($row['result']['failure_class'] ?? null) === ServiceOperationFailed::class;
    } finally {
        proc_terminate($worker, 15);
        $exitCode = proc_close($worker);
        if ($row !== null) {
            $row['worker_exit_code'] = $exitCode;
            if (in_array($input['scenario'] ?? null, ['permission_denied', 'uncaught_failed'], true)) {
                $row['budget_seconds'] = 30;
                $row['elapsed_seconds'] = microtime(true) - $startedAt;
            }
            $row['worker_log'] = file_get_contents($logPath);
            $observations[] = $row;
        }
    }
}
echo json_encode([
    'scope' => 'php_caller_'.$input['service_language'].'_workflow_service',
    'outcome' => count(array_filter($observations, static fn (array $row): bool =>
        $row[match ($input['scenario'] ?? 'completed') {
            'failed' => 'typed_failure_qualified', 'cancelled' => 'cancellation_qualified',
            'caller_cancelled' => 'caller_cancellation_qualified',
            'uncaught_failed' => 'uncaught_failure_qualified',
            'permission_denied' => 'authorization_qualified', default => 'completed',
        }])) === 2 ? 'pass' : 'fail',
    'observations' => $observations,
    'recovery_qualified' => false, 'cancellation_qualified' => false,
], JSON_THROW_ON_ERROR).PHP_EOL;
