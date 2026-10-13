<?php

declare(strict_types=1);

require_once __DIR__.'/namespace-probe.php';

// Actual installed worker-protocol receipts, without backdating stored state.
function activityDeadlineReceiptsHttpObservation(array $fixture, array $options): array
{
    $spec = $fixture['activity_deadline_receipts'];
    $directory = getenv('DW_PARITY_FAILURE_EVIDENCE_DIR');
    if (! is_string($directory) || ! is_dir($directory)) {
        throw new RuntimeException('Actual deadline receipt transport is required');
    }
    $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $options['workflow-id']);
    $journal = $directory.'/'.$name.'-http.jsonl';
    if (file_exists($journal)) {
        throw new RuntimeException('Receipt transport requires a fresh journal before admission');
    }
    $proxy = proc_open(['node', __DIR__.'/published-sdk-patch/observe-http.mjs', $options['url'], $journal],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $directory.'/'.$name.'-observer.stderr', 'w']], $pipes);
    if (! is_resource($proxy)) {
        throw new RuntimeException('Could not start the original-byte receipt observer');
    }
    fclose($pipes[0]);
    $deadline = microtime(true) + $spec['overall_budget_seconds'];
    $budget = static function () use ($deadline): void {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Original activity receipt observation expired');
        }
    };
    try {
        $url = trim(fgets($pipes[1]));
        if (! preg_match('#^http://127\.0\.0\.1:[0-9]+$#', $url)) {
            throw new RuntimeException('Receipt observer did not bind its local port');
        }
        $client = new \DurableWorkflow\Client($url, namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null);
        $id = $options['workflow-id'];
        $workflowWorker = $id.'-workflow-worker';
        $activityWorker = $id.'-activity-worker';
        $workflowQueue = $id.'-workflow';
        $activityQueue = $id.'-activity';
        $client->registerWorker($workflowWorker, $workflowQueue, [$fixture['workflow_type']], []);
        $client->registerWorker($activityWorker, $activityQueue, [], ['parity.v1.deadline_activity']);
        $handle = $client->startWorkflow($fixture['workflow_type'], $id, $workflowQueue, [$fixture['input']],
            executionTimeoutSeconds: 1200, runTimeoutSeconds: 1200);
        $snapshot = static fn (): array => namespaceHttpRun($client, $id, $handle->selectedRunId);
        $workflow = $client->pollWorkflowTask($workflowWorker, $workflowQueue, 0);
        if ($workflow === null) {
            throw new RuntimeException('Original workflow author delivery is missing');
        }
        $command = ['type' => 'schedule_activity', 'activity_type' => 'parity.v1.deadline_activity',
            'arguments' => $client->payloadCodec()->envelope([$fixture['input']]), 'queue' => $activityQueue,
            'start_to_close_timeout' => $spec['start_to_close_timeout'],
            'schedule_to_close_timeout' => $spec['schedule_to_close_timeout'],
            'retry_policy' => ['max_attempts' => $spec['max_attempts'],
                'backoff_seconds' => $spec['backoff_seconds'], 'non_retryable_error_types' => []]];
        $admission = $client->completeWorkflowTask($workflow['task_id'], $workflow['lease_owner'],
            $workflow['workflow_task_attempt'], [$command]);
        $admittedAt = microtime(true);
        $first = $client->pollActivityTask($activityWorker, $activityQueue, 0);
        if ($first === null) {
            throw new RuntimeException('Original activity claim is missing');
        }
        $before = $snapshot();
        usleep(($spec['start_to_close_timeout'] * 1000000) + 250000);
        $budget();
        $refused = namespaceReceipt('expired-first-completion', static fn (): array => $client->completeActivityTask(
            $first['task_id'], $first['activity_attempt_id'], $first['lease_owner'], $fixture['input']));
        $second = null;
        do {
            $budget();
            $second = $client->pollActivityTask($activityWorker, $activityQueue, 0);
            if ($second === null) {
                usleep(25000);
            }
        } while ($second === null);
        $retried = $snapshot();
        if ($spec['global_timeout_control'] ?? false) {
            $remaining = $admittedAt + $spec['schedule_to_close_timeout'] + 0.25 - microtime(true);
            if ($remaining > 0) usleep((int) ceil($remaining * 1000000));
            $budget();
            $elapsed = microtime(true) - $admittedAt;
            $status = $client->activityTaskStatus($second['task_id'], $second['activity_attempt_id'], $second['lease_owner']);
            $late = [
                namespaceReceipt('complete', static fn (): array => $client->completeActivityTask(
                    $second['task_id'], $second['activity_attempt_id'], $second['lease_owner'], $fixture['input'])),
                namespaceReceipt('fail', static fn (): array => $client->failActivityTask(
                    $second['task_id'], $second['activity_attempt_id'], $second['lease_owner'], 'late global result')),
                namespaceReceipt('heartbeat', static fn (): array => $client->heartbeatActivityTask(
                    $second['task_id'], $second['activity_attempt_id'], $second['lease_owner'], ['message' => 'late global progress'])),
            ];
            $activityPolls = [];
            do {
                $budget();
                $extraActivity = $client->pollActivityTask($activityWorker, $activityQueue, 0);
                $activityPolls[] = $extraActivity;
                $parent = $client->pollWorkflowTask($workflowWorker, $workflowQueue, 0);
                if ($parent === null) usleep(25000);
            } while ($parent === null);
            $after = $snapshot();

            return [...$after, 'status' => 'global_deadline_observed', 'activity_deadline_receipts' => [
                'driver' => 'installed_sdk_worker_protocol', 'overall_budget_seconds' => $spec['overall_budget_seconds'],
                'global_elapsed_seconds' => $elapsed, 'workflow_task' => $workflow, 'command' => $command,
                'admission' => $admission, 'first_attempt' => $first, 'before_expiry' => $before,
                'first_refusal' => $refused, 'second_attempt' => $second, 'after_retry' => $retried,
                'status_after_expiry' => $status, 'late_outcomes' => $late, 'parent_delivery' => $parent,
                'post_expiry_activity_polls' => $activityPolls,
                'extra_parent_poll' => $client->pollWorkflowTask($workflowWorker, $workflowQueue, 0),
                'requests' => array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                    file($journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))]];
        }
        $completion = $client->completeActivityTask($second['task_id'], $second['activity_attempt_id'],
            $second['lease_owner'], $fixture['input']);
        $parent = $client->pollWorkflowTask($workflowWorker, $workflowQueue, 0);
        if ($parent === null) {
            throw new RuntimeException('Actual successful retry parent delivery is missing');
        }
        $finish = [['type' => 'complete_workflow', 'result' => $client->payloadCodec()->envelope($fixture['input'])]];
        $activeControl = (bool) ($spec['active_receipt_control'] ?? false);
        $activeBeforeElapsed = $activeControl ? microtime(true) - $admittedAt : null;
        $activeBefore = $activeControl ? namespaceReceipt('active-before-expiry', static fn (): array => $client->completeActivityTask(
            $second['task_id'], $second['activity_attempt_id'], $second['lease_owner'], $fixture['input'])) : null;
        $workflowCompletion = $activeControl ? null : $client->completeWorkflowTask($parent['task_id'], $parent['lease_owner'],
            $parent['workflow_task_attempt'], $finish);
        $committed = $snapshot();
        // Admission is acknowledged after the server snapshots the original
        // global deadline, so this wait conservatively crosses that boundary.
        $remaining = $admittedAt + $spec['schedule_to_close_timeout'] + 0.25 - microtime(true);
        if ($remaining > 0) {
            usleep((int) ceil($remaining * 1000000));
        }
        $budget();
        $elapsed = microtime(true) - $admittedAt;
        $duplicate = namespaceReceipt('identical-activity', static fn (): array => $client->completeActivityTask(
            $second['task_id'], $second['activity_attempt_id'], $second['lease_owner'], $fixture['input']));
        $conflict = namespaceReceipt('conflicting-activity', static fn (): array => $client->completeActivityTask(
            $second['task_id'], $second['activity_attempt_id'], $second['lease_owner'], ['different' => true]));
        $activeAfter = $activeControl ? $snapshot() : null;
        if ($activeControl) {
            $workflowCompletion = $client->completeWorkflowTask($parent['task_id'], $parent['lease_owner'],
                $parent['workflow_task_attempt'], $finish);
        }
        $workflowDuplicate = namespaceReceipt('identical-workflow', static fn (): array => $client->completeWorkflowTask(
            $parent['task_id'], $parent['lease_owner'], $parent['workflow_task_attempt'], $finish));
        $after = $snapshot();

        return [...$after, 'activity_deadline_receipts' => ['driver' => 'installed_sdk_worker_protocol',
            'overall_budget_seconds' => $spec['overall_budget_seconds'], 'global_elapsed_seconds' => $elapsed,
            'workflow_task' => $workflow, 'command' => $command, 'admission' => $admission,
            'first_attempt' => $first, 'before_expiry' => $before, 'first_refusal' => $refused,
            'second_attempt' => $second, 'after_retry' => $retried, 'activity_completion' => $completion,
            'parent_delivery' => $parent, 'workflow_command' => $finish, 'workflow_completion' => $workflowCompletion,
            'committed' => $committed, 'duplicate' => $duplicate, 'conflict' => $conflict,
            'active_before_expiry' => $activeBefore, 'active_before_expiry_elapsed_seconds' => $activeBeforeElapsed,
            'active_after_expiry' => $activeAfter,
            'workflow_duplicate' => $workflowDuplicate,
            'requests' => array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))]];
    } finally {
        fclose($pipes[1]);
        proc_terminate($proxy, 15);
        proc_close($proxy);
    }
}
