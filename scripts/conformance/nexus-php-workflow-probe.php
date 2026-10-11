<?php

declare(strict_types=1);

require '/tmp/dw-php/vendor/autoload.php';

use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServiceOperationFailed;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\WorkflowContext;

$input = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);

if (($argv[2] ?? null) === 'worker') {
    $namespace = $argv[3];
    error_log(json_encode([
        'process_id' => getmypid(),
        'installed_sdk_version' => Composer\InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
        'namespace' => $namespace,
    ], JSON_THROW_ON_ERROR));
    $client = new Client('http://server:8080', namespace: $namespace, workerToken: $input['token']);
    Worker::create($client, 'nexus-php-caller')
        ->registerWorkflow('nexus.fixture.php.caller', static function (
            WorkflowContext $context,
            string $endpoint,
            string $service,
            string $operation,
            string $name,
            ?string $targetNamespace,
        ): array {
            $options = ['wait_timeout_seconds' => 5];
            if ($targetNamespace !== null) {
                $options['namespace'] = $targetNamespace;
            }
            try {
                $result = $context->serviceOperation($endpoint, $service, $operation, [$name], $options);
            } catch (ServiceOperationFailed $failure) {
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
    $logPath = '/tmp/nexus-php-caller-'.$namespace.'.log';
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
        $handle = $client->startWorkflow('nexus.fixture.php.caller',
            'nexus-php-caller-'.$namespace.'-'.($input['scenario'] ?? 'completed').'-'.getmypid(), 'nexus-php-caller', [
                $input['endpoint'], $input['service'], $input['operation'], $input['name'] ?? 'world',
                $namespace === 'shared' ? null : 'shared',
            ]);
        $row = [
            'caller_namespace' => $namespace, 'target_namespace' => 'shared',
            'scenario' => $input['scenario'] ?? 'completed',
            'workflow_id' => $handle->workflowId, 'run_id' => $handle->selectedRunId,
            'process_id' => getmypid(), 'worker_process_id' => proc_get_status($worker)['pid'],
            'worker_signal_handlers_available' => function_exists('pcntl_async_signals') && function_exists('pcntl_signal'),
            'installed_sdk_version' => Composer\InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
            'public_method' => 'WorkflowContext.serviceOperation',
        ];
        try {
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
        $surface = $row['result']['service_call'] ?? [];
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
    } finally {
        proc_terminate($worker, 15);
        $exitCode = proc_close($worker);
        if ($row !== null) {
            $row['worker_exit_code'] = $exitCode;
            $row['worker_log'] = file_get_contents($logPath);
            $observations[] = $row;
        }
    }
}
echo json_encode([
    'scope' => 'php_caller_'.$input['service_language'].'_workflow_service',
    'outcome' => count(array_filter($observations, static fn (array $row): bool =>
        $row[($input['scenario'] ?? 'completed') === 'failed' ? 'typed_failure_qualified' : 'completed'])) === 2 ? 'pass' : 'fail',
    'observations' => $observations,
    'recovery_qualified' => false, 'cancellation_qualified' => false,
], JSON_THROW_ON_ERROR).PHP_EOL;
