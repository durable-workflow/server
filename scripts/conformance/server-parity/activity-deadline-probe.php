<?php

declare(strict_types=1);

use DurableWorkflow\Client;

require_once __DIR__.'/namespace-probe.php';
require_once __DIR__.'/activity-deadline-poll.php';

// Real installed Client worker-protocol calls. This narrow boundary deliberately
// retains the parent delivery; it does not fabricate an authored workflow result.
function activityDeadlineHttpObservation(array $fixture, array $options): array
{
    $spec = $fixture['activity_deadline_authority'];
    $directory = getenv('DW_PARITY_FAILURE_EVIDENCE_DIR');
    if (!is_string($directory) || !is_dir($directory)) {
        throw new RuntimeException('Activity deadline evidence directory is required');
    }
    $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $options['workflow-id']);
    $journal = $directory.'/'.$name.'-http.jsonl';
    $proxy = proc_open(['node', __DIR__.'/published-sdk-patch/observe-http.mjs', $options['url'], $journal],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['file',$directory.'/'.$name.'-observer.stderr','w']], $pipes,
        null, array_merge(getenv(), ['DW_PARITY_HTTP_CAPTURE_TIMING'=>'1']));
    if (!is_resource($proxy)) {
        throw new RuntimeException('Could not start the activity deadline HTTP recorder');
    }
    fclose($pipes[0]);
    $startedAt = microtime(true);
    $deadline = $startedAt + $spec['overall_budget_seconds'];
    $steps = [];
    $pollRetries = [];
    try {
        $url = trim(fgets($pipes[1]));
        if (!preg_match('#^http://127\.0\.0\.1:[0-9]+$#', $url)) {
            throw new RuntimeException('Activity deadline recorder did not bind its local port');
        }
        $client = parityClient($url, namespace:'default', token:getenv('DW_PARITY_TOKEN') ?: null);
        $checkBudget = static function () use ($deadline): void {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Original activity deadline observation expired');
            }
        };
        foreach ($spec['fields'] as $field) {
            $checkBudget();
            $id = $options['workflow-id'].'-'.$field;
            $workflowQueue = $id.'-workflow';
            $activityQueue = $id.'-activity';
            $workflowWorker = $id.'-workflow-worker';
            $activityWorker = $id.'-activity-worker';
            $pollWorkflow = static function () use ($client, $workflowWorker, $workflowQueue, $deadline, &$pollRetries): ?array {
                return activityDeadlinePoll(
                    static fn (string $requestId): array => $client->pollWorkflowTaskResponse($workflowWorker, $workflowQueue, 0, $requestId),
                    $workflowWorker, $workflowQueue, 'workflow', $deadline, $pollRetries);
            };
            $pollActivity = static function () use ($client, $activityWorker, $activityQueue, $deadline, &$pollRetries): ?array {
                return activityDeadlinePoll(
                    static fn (string $requestId): array => $client->pollActivityTaskResponse($activityWorker, $activityQueue, 0, $requestId),
                    $activityWorker, $activityQueue, 'activity', $deadline, $pollRetries);
            };
            $client->registerWorker($workflowWorker, $workflowQueue, [$fixture['workflow_type']], []);
            $client->registerWorker($activityWorker, $activityQueue, [], ['parity.v1.deadline_activity']);
            $handle = $client->startWorkflow($fixture['workflow_type'], $id, $workflowQueue, [$fixture['input']],
                executionTimeoutSeconds:1200, runTimeoutSeconds:1200);
            $snapshot = static fn (): array => namespaceHttpRun($client, $id, $handle->selectedRunId);
            $workflow = $pollWorkflow();
            if ($workflow === null) {
                throw new RuntimeException('The original activity deadline workflow task is missing');
            }
            $command = ['type'=>'schedule_activity','activity_type'=>'parity.v1.deadline_activity',
                'arguments'=>$client->payloadCodec()->envelope([$fixture['input']]),'queue'=>$activityQueue,
                'retry_policy'=>['max_attempts'=>$spec['terminal_max_attempts'],
                    'backoff_seconds'=>$spec['retry_backoff_seconds'],'non_retryable_error_types'=>[]],
                $field=>$spec['timeout_seconds']];
            $admission = $client->completeWorkflowTask($workflow['task_id'], $workflow['lease_owner'],
                $workflow['workflow_task_attempt'], [$command]);
            $step = ['field'=>$field,'workflow_id'=>$id,'run_id'=>$handle->selectedRunId,
                'workflow_worker'=>$workflowWorker,'activity_worker'=>$activityWorker,
                'workflow_task'=>$workflow,'command'=>$command,'admission'=>$admission,'scheduled'=>$snapshot()];
            $activity = $field === 'schedule_to_start_timeout' ? null
                : $pollActivity();
            if ($field !== 'schedule_to_start_timeout' && $activity === null) {
                throw new RuntimeException('Original activity deadline claim is missing');
            }
            $step['activity_task'] = $activity;
            if ($activity !== null) {
                $step['status_before_expiry'] = $client->activityTaskStatus($activity['task_id'],
                    $activity['activity_attempt_id'], $activity['lease_owner']);
            }
            $step['before_expiry'] = $snapshot();
            file_put_contents($directory.'/'.$name.'-'.$field.'-before-expiry.json', json_encode($step,
                JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n");
            // Actual wall time crosses the original authored boundary; no SQL,
            // history or server clock is changed and no new deadline is granted.
            usleep(($spec['timeout_seconds'] * 1000000) + 250000);
            $checkBudget();
            if ($activity !== null) {
                $step['status_after_expiry'] = $client->activityTaskStatus($activity['task_id'],
                    $activity['activity_attempt_id'], $activity['lease_owner']);
                $step['late_outcomes'] = [
                    namespaceReceipt('complete', static fn (): array => $client->completeActivityTask(
                        $activity['task_id'], $activity['activity_attempt_id'], $activity['lease_owner'], $fixture['input'])),
                    namespaceReceipt('fail', static fn (): array => $client->failActivityTask(
                        $activity['task_id'], $activity['activity_attempt_id'], $activity['lease_owner'], 'late deadline outcome')),
                    namespaceReceipt('heartbeat', static fn (): array => $client->heartbeatActivityTask(
                        $activity['task_id'], $activity['activity_attempt_id'], $activity['lease_owner'], ['message'=>'late deadline progress'])),
                ];
            }
            file_put_contents($directory.'/'.$name.'-'.$field.'-late-outcomes.json', json_encode($step,
                JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n");
            $parent = null;
            do {
                $checkBudget();
                // Normal polls permit the published backend's expiry sweeps.
                $step['post_expiry_activity_poll'] = $pollActivity();
                $parent = $pollWorkflow();
                if ($parent !== null) { break; }
                usleep(25000);
            } while (true);
            $step['parent_delivery'] = $parent;
            $step['after_expiry'] = $snapshot();
            $step['extra_parent_poll'] = $pollWorkflow();
            $steps[$field] = $step;
            file_put_contents($directory.'/'.$name.'-'.$field.'-snapshot.json', json_encode($step,
                JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n");
        }
        return ['workflow_id'=>$options['workflow-id'],'workflow_type'=>$fixture['workflow_type'],
            'namespace'=>'default','status'=>'deadline_observed','input'=>[$fixture['input']],'output'=>null,
            'events'=>[],'activity_deadline_authority'=>['driver'=>'installed_sdk_worker_protocol',
                'overall_budget_seconds'=>$spec['overall_budget_seconds'],'steps'=>$steps,
                'observer_started_at'=>$startedAt, 'observer_deadline'=>$deadline, 'poll_retries'=>$pollRetries,
                'requests'=>array_map(static fn (string $line): array => json_decode($line,true,flags:JSON_THROW_ON_ERROR),
                    file($journal,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES))]];
    } finally {
        fclose($pipes[1]);
        proc_terminate($proxy,15);
        proc_close($proxy);
    }
}
