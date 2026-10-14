<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Worker\CancellationDelivery;

require getenv('DW_PARITY_SDK_AUTOLOAD');
require __DIR__.'/observation-values.php';
require __DIR__.'/namespace-probe.php';

[$script, $role, $url, $prefix, $specPath, $artifactsPath, $output] = $argv;
$spec = json_decode(file_get_contents($specPath), true, flags: JSON_THROW_ON_ERROR);
$artifacts = json_decode(file_get_contents($artifactsPath), true, flags: JSON_THROW_ON_ERROR);
$policy = $spec['operations'][$role] ?? throw new RuntimeException('Unknown CPU operation role');
$client = new Client($url, namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null, workerProtocolVersion: '1.20');
$id = $prefix.'-'.$role;
$worker = $id.'-worker';
$type = 'parity.v1.cpu_'.$role;
$state = ['role'=>$role, 'specification_sha256'=>hash_file('sha256', $specPath),
    'sdk_version'=>InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
    'sdk_source'=>InstalledVersions::getReference('durable-workflow/sdk'), 'preparation_started_at'=>microtime(true)];
$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$checkpoint = static function () use ($output, &$state): void {
    file_put_contents($output, json_encode($state, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n");
};
try {
    $check($state['sdk_version']===$artifacts['sdk_php'] && $state['sdk_source']===$artifacts['sdk_php_source_commit'], 'Wrong installed CPU observer');
    $client->registerWorker($worker, $id, [$type], ['parity.v1.cpu_activity'],
        capabilities: $role==='cancellation' ? ['cooperative_cancellation'] : []);
    $handle = $client->startWorkflow($type, $id, $id, [$spec['row']], executionTimeoutSeconds: 1200, runTimeoutSeconds: 1200);
    $task = $client->pollWorkflowTask($worker, $id, 0);
    $check($task!==null, 'Original CPU observer claim is missing');
    $state += ['workflow_id'=>$id, 'run_id'=>$handle->selectedRunId, 'task'=>$task,
        'prepared'=>namespaceHttpRun($client, $id, $handle->selectedRunId), 'phase'=>'prepared_original', 'outcome'=>'pending'];
    $checkpoint();
    echo "{\"ready\":true}\n"; flush();
    $check(trim((string) fgets(STDIN))==='start', 'Missing explicit CPU operation handoff');
    $started = microtime(true);
    $deadline = $started + $spec['observer_budget_seconds'];
    $state['observer_started_at'] = $started;
    $state['observer_deadline'] = $deadline;
    $budget = static function () use ($deadline): void {
        if (microtime(true)>=$deadline) throw new RuntimeException('Original CPU operation budget expired');
    };
    $submit = static function (string $name, Closure $call) use (&$state, $budget): array {
        $budget(); $at = microtime(true);
        try {
            $result = $call(); $budget();
        } catch (Throwable $error) {
            $state['failed_call'] = ['name'=>$name, 'submitted_at'=>$at,
                'observed_at'=>microtime(true), 'type'=>$error::class, 'message'=>$error->getMessage()];
            throw $error;
        }
        $state['calls'][] = ['name'=>$name, 'submitted_at'=>$at, 'observed_at'=>microtime(true), 'response'=>$result];
        return $result;
    };
    $complete = static fn (array $claim, array $commands): array => $client->completeWorkflowTask(
        $claim['task_id'], $claim['lease_owner'], $claim['workflow_task_attempt'], $commands);
    $poll = static function () use ($client, $worker, $id, $budget): array {
        do {
            $budget(); $next = $client->pollWorkflowTask($worker, $id, 0);
            if ($next===null) usleep(25000);
        } while ($next===null);
        return $next;
    };
    if ($role==='renewal') {
        $submit('admit_activity', static fn (): array => $complete($task, [[
            'type'=>'schedule_activity', 'activity_type'=>'parity.v1.cpu_activity', 'queue'=>$id,
            'arguments'=>$client->payloadCodec()->envelope([$spec['row']]),
            'schedule_to_close_timeout'=>$policy['schedule_to_close_seconds'],
            'start_to_close_timeout'=>$policy['start_to_close_seconds'], 'heartbeat_timeout'=>$policy['heartbeat_timeout_seconds'],
            'retry_policy'=>['max_attempts'=>1, 'backoff_seconds'=>[], 'non_retryable_error_types'=>[]],
        ]]));
        $activity = $client->pollActivityTask($worker, $id, 0);
        $check($activity!==null, 'Original renewal activity is missing');
        $claimed = microtime(true);
        $state['activity_task'] = $activity;
        $state['activity_claimed_at'] = $claimed;
        $state['before'] = namespaceHttpRun($client, $id, $handle->selectedRunId);
        $status = static fn (): array => $client->activityTaskStatus($activity['task_id'], $activity['activity_attempt_id'], $activity['lease_owner']);
        $state['status_before'] = $submit('status_before', $status);
        foreach (['first','second'] as $name) {
            $wait = $claimed + $policy[$name.'_heartbeat_delay_seconds'] - microtime(true);
            if ($wait>0) usleep((int) ceil($wait*1000000));
            $state['status_'.$name] = $submit('status_'.$name, $status);
            $state['heartbeat_'.$name] = $submit('heartbeat_'.$name, static fn (): array => $client->heartbeatActivityTask(
                $activity['task_id'], $activity['activity_attempt_id'], $activity['lease_owner'], ['message'=>$name.' CPU renewal λ']));
            $state['after_'.$name] = namespaceHttpRun($client, $id, $handle->selectedRunId);
            $checkpoint();
        }
        $state['status_after'] = $submit('status_after', $status);
        $state['after_status'] = namespaceHttpRun($client, $id, $handle->selectedRunId);
        $state['activity_completion'] = $submit('complete_activity', static fn (): array => $client->completeActivityTask(
            $activity['task_id'], $activity['activity_attempt_id'], $activity['lease_owner'], $spec['row']));
        $parent = $poll();
        $state['parent'] = $parent;
        $state['workflow_completion'] = $submit('complete_workflow', static fn (): array => $complete($parent,
            [['type'=>'complete_workflow', 'result'=>$client->payloadCodec()->envelope($spec['row'])]]));
    } else {
        $state['timer_admission'] = $submit('admit_timer', static fn (): array => $complete($task,
            [['type'=>'start_timer', 'delay_seconds'=>$policy[$role==='timer'?'delay_seconds':'original_delay_seconds']]]));
        $state['before'] = namespaceHttpRun($client, $id, $handle->selectedRunId);
        if ($role==='timer') {
            $parent = $poll();
            $state['parent'] = $parent;
            $state['timer_delivered_at'] = microtime(true);
            $state['workflow_completion'] = $submit('complete_workflow', static fn (): array => $complete($parent,
                [['type'=>'complete_workflow', 'result'=>$client->payloadCodec()->envelope($spec['row'])]]));
        } else {
            $state['accepted'] = $submit('request_cancellation', static fn (): array => $client->requestWorkflowCancellation(
                $id, $policy['reason'], $policy['cleanup_timeout_seconds'], $handle->selectedRunId));
            $state['after_request'] = namespaceHttpRun($client, $id, $handle->selectedRunId);
            $state['duplicate'] = $submit('duplicate_cancellation', static fn (): array => $client->requestWorkflowCancellation($id, 'replacement', 300, $handle->selectedRunId));
            $claim = $poll();
            $state['delivery_claim'] = $claim;
            $delivery = CancellationDelivery::fromPayload([
                'workflow_command_id'=>$state['accepted']['cancellation_request']['request_id'], 'sequence'=>1, 'call_kind'=>'timer',
            ]);
            $state['delivery'] = $submit('deliver_cancellation', static fn (): array => $client->deliverWorkflowCancellation(
                $claim['task_id'], $claim['lease_owner'], $claim['workflow_task_attempt'], $delivery));
            $state['renewal'] = $submit('renew_cleanup', static fn (): array => $client->heartbeatWorkflowTask(
                $claim['task_id'], $claim['lease_owner'], $claim['workflow_task_attempt']));
            $state['cleanup_admission'] = $submit('admit_cleanup_timer', static fn (): array => $complete($claim,
                [['type'=>'start_timer', 'delay_seconds'=>$policy['cleanup_delay_seconds']]]));
            $state['before_expiry'] = namespaceHttpRun($client, $id, $handle->selectedRunId);
            $checkpoint();
            // Observe the canonical terminal event without repeatedly asking
            // the loaded service to decode unrelated workflow input. Full
            // state, typed input and exact prefix are checked after observation.
            do {
                $budget(); $history = httpHistory($client, $id, $handle->selectedRunId);
                $closed = array_values(array_filter($history, static fn (array $event): bool => $event['event_type']==='WorkflowCancelled'));
                if ($closed===[]) usleep(25000);
            } while ($closed===[]);
            $state['closure_observed_at'] = microtime(true);
            $check(count($closed)===1, 'Exactly one canonical cleanup closure is required');
            $state['closure_event'] = $closed[0];
            $state['terminal_duplicate'] = $submit('terminal_duplicate', static fn (): array => $client->requestWorkflowCancellation($id, 'terminal replacement', 300, $handle->selectedRunId));
        }
    }
    $state['after'] = namespaceHttpRun($client, $id, $handle->selectedRunId);
    $state['observer_finished_at'] = microtime(true);
    $budget(); $state['phase']='complete'; $state['outcome']='observed';
} catch (Throwable $error) {
    $state['outcome']='failed'; $state['error']=['type'=>$error::class, 'message'=>$error->getMessage()];
} finally { $checkpoint(); }
exit($state['outcome']==='observed'?0:1);
