<?php

declare(strict_types=1);

require '/tmp/dw-php/vendor/autoload.php';

use DurableWorkflow\Client;

$input = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$observations = [];
$eventsOfType = static fn (array $history, string $type): array => array_values(array_filter(
    $history['events'] ?? [], static fn (array $event): bool => ($event['event_type'] ?? null) === $type,
));

foreach (['shared', 'tenant-a'] as $namespace) {
    $logPath = '/tmp/nexus-php-recovery-'.$namespace.'-'.getmypid().'.log';
    $startWorker = static function () use ($argv, $namespace, $logPath) {
        $process = proc_open([PHP_BINARY, __DIR__.'/nexus-php-workflow-probe.php', $argv[1], 'worker', $namespace], [
            0 => ['file', '/dev/null', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the installed PHP caller worker.');
        }

        return $process;
    };
    $worker = $startWorker();
    $row = ['caller_namespace' => $namespace, 'target_namespace' => 'shared', 'scenario' => 'caller_recovery',
        'installed_sdk_version' => Composer\InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
        'worker_process_id' => proc_get_status($worker)['pid'], 'budget_seconds' => 60];
    $deadline = microtime(true) + $row['budget_seconds'];
    try {
        $client = new Client('http://server:8080', namespace: $namespace, controlToken: $input['token']);
        $handle = $client->startWorkflow('nexus.fixture.php.caller',
            'nexus-php-recovery-'.$namespace.'-'.bin2hex(random_bytes(6)), 'nexus-php-caller', [
                $input['endpoint'], $input['service'], $input['operation'], 'recover',
                $namespace === 'shared' ? null : 'shared',
            ]);
        $row['workflow_id'] = $handle->workflowId;
        $row['run_id'] = $handle->selectedRunId;
        $serviceClient = new Client('http://server:8080', namespace: 'shared', controlToken: $input['token']);
        $readyUntil = min($deadline, microtime(true) + 20);
        do {
            $history = $client->workflowHistory($handle->workflowId, $handle->selectedRunId);
            $starts = $eventsOfType($history, 'ServiceCallStarted');
            $linked = $starts[0]['payload']['service_call'] ?? [];
            $linkedId = $linked['linked_workflow_instance_id'] ?? null;
            $linkedRun = $linked['linked_workflow_run_id'] ?? null;
            $serviceHistory = is_string($linkedId) && is_string($linkedRun)
                ? $serviceClient->workflowHistory($linkedId, $linkedRun) : [];
            if (count($starts) === 1 && count($eventsOfType($serviceHistory, 'TimerScheduled')) === 1
                && $eventsOfType($serviceHistory, 'WorkflowCompleted') === []
                && $eventsOfType($history, 'ServiceCallCompleted') === []) {
                $row['before_kill_history'] = $history;
                $row['before_kill_service_history'] = $serviceHistory;
                $row['original_service_call'] = $starts[0]['payload']['service_call'];
                $row['original_service_call_id'] = $starts[0]['payload']['service_call_id'];
                $row['linked_service_run_id'] = $linkedRun;
                break;
            }
            usleep(100000);
        } while (microtime(true) < $readyUntil);
        if (!isset($row['original_service_call_id'])) {
            throw new RuntimeException('The original call and waiting linked service were not observed before the kill.');
        }
        if (!proc_terminate($worker, 9)) {
            throw new RuntimeException('Could not SIGKILL the original PHP caller worker.');
        }
        $exitUntil = min($deadline, microtime(true) + 2);
        do {
            $status = proc_get_status($worker);
            if (!$status['running']) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $exitUntil);
        $row['killed_worker'] = $status;
        if ($status['running'] || !$status['signaled'] || $status['termsig'] !== 9) {
            throw new RuntimeException('The original caller process was not confirmed dead from SIGKILL.');
        }
        $row['killed_worker_exit_code'] = proc_close($worker);
        $worker = null;

        $serviceHandle = $serviceClient->workflowHandle($linkedId, $linkedRun);
        $row['linked_service_result'] = $serviceHandle->resultOfSelectedRun(max(1, (int) min(20, $deadline - microtime(true))));
        $row['linked_service_history'] = $serviceClient->workflowHistory($linkedId, $linkedRun);
        $row['before_replacement_history'] = $client->workflowHistory($handle->workflowId, $handle->selectedRunId);
        $row['before_replacement_status'] = $handle->describeSelectedRun()->status;

        $worker = $startWorker();
        $row['replacement_worker_process_id'] = proc_get_status($worker)['pid'];
        $row['result'] = $handle->resultOfSelectedRun(max(1, (int) min(30, $deadline - microtime(true))));
        $row['history'] = $client->workflowHistory($handle->workflowId, $handle->selectedRunId);
        $row['caller_status'] = $handle->describeSelectedRun()->status;
        $row['elapsed_seconds'] = $row['budget_seconds'] - max(0, $deadline - microtime(true));
        $row['recovery_qualified'] = $row['elapsed_seconds'] < $row['budget_seconds']
            && $row['result']['service_call_id'] === $row['original_service_call_id']
            && $row['replacement_worker_process_id'] !== $row['worker_process_id']
            && count($eventsOfType($row['history'], 'ServiceCallStarted')) === 1
            && count($eventsOfType($row['history'], 'ServiceCallCompleted')) === 1;
    } catch (Throwable $failure) {
        $row['failure'] = ['type' => $failure::class, 'message' => $failure->getMessage()];
        $row['recovery_qualified'] = false;
    } finally {
        if (is_resource($worker)) {
            proc_terminate($worker, 15);
            $row['replacement_worker_exit_code'] = proc_close($worker);
        }
        $row['worker_log'] = file_get_contents($logPath);
        $observations[] = $row;
    }
}
echo json_encode(['scope' => 'actual_php_caller_sigkill_recovery', 'observations' => $observations,
    'outcome' => count(array_filter($observations, static fn (array $row): bool => $row['recovery_qualified'])) === 2 ? 'pass' : 'fail',
], JSON_THROW_ON_ERROR).PHP_EOL;
