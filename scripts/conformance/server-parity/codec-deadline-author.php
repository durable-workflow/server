<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use DurableWorkflow\Client;

require getenv('DW_PARITY_SDK_AUTOLOAD');
require __DIR__.'/observation-values.php';
require __DIR__.'/namespace-probe.php';

[$script, $url, $prefix, $specPath, $artifactsPath, $output] = $argv;
$spec = json_decode(file_get_contents($specPath), true, flags: JSON_THROW_ON_ERROR);
$artifacts = json_decode(file_get_contents($artifactsPath), true, flags: JSON_THROW_ON_ERROR);
$preparedAt = microtime(true);
$preparationDeadline = $preparedAt + $spec['preparation_budget_seconds'];
$client = new Client($url, namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null);
$state = ['specification_sha256'=>hash_file('sha256', $specPath), 'preparation_started_at'=>$preparedAt,
    'sdk_version'=>InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
    'sdk_source'=>InstalledVersions::getReference('durable-workflow/sdk')];
$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
try {
    $check($state['sdk_version']===$artifacts['sdk_php'], 'Wrong normally installed SDK version');
    $check($state['sdk_source']===$artifacts['sdk_php_source_commit'], 'Wrong normally installed SDK source');
    $check(\DurableWorkflow\Version::CONTROL_PLANE_PROTOCOL===$spec['control_plane_protocol'], 'Wrong installed control-plane protocol');
    $dense = array_fill(0, $spec['rows'], $spec['row']);
    $arguments = [$dense];
    $envelope = $client->payloadCodec()->envelope($arguments);
    $check(strlen(json_encode($envelope, JSON_THROW_ON_ERROR))<=$spec['maximum_envelope_bytes'], 'Dense input exceeds its declared envelope limit');
    $seedId = $prefix.'-dense';
    $client->registerWorker($seedId.'-worker', $seedId, ['parity.v1.codec_seed'], []);
    $seed = $client->startWorkflow('parity.v1.codec_seed', $seedId, $seedId, $arguments,
        executionTimeoutSeconds: 1200, runTimeoutSeconds: 1200);
    $decoded = $client->describeWorkflow($seedId, $seed->selectedRunId);
    $check($decoded->input===$arguments, 'The actual original dense input did not decode exactly');
    $state['dense'] = ['workflow_id'=>$seedId, 'run_id'=>$seed->selectedRunId,
        'row_count'=>$spec['rows'], 'decoded_sha256'=>hash('sha256', json_encode($decoded->input, JSON_THROW_ON_ERROR)),
        'envelope_sha256'=>hash('sha256', json_encode($envelope, JSON_THROW_ON_ERROR))];
    unset($dense, $arguments, $envelope, $decoded);
    $id = $prefix.'-activity';
    $worker = $id.'-workflow-worker';
    $activityWorker = $id.'-activity-worker';
    $client->registerWorker($worker, $id.'-workflow', ['parity.v1.codec_deadline'], []);
    $client->registerWorker($activityWorker, $id.'-activity', [], ['parity.v1.deadline_activity']);
    $original = $client->startWorkflow('parity.v1.codec_deadline', $id, $id.'-workflow', [$spec['row']],
        executionTimeoutSeconds: 1200, runTimeoutSeconds: 1200);
    $workflow = $client->pollWorkflowTask($worker, $id.'-workflow', 0);
    $check($workflow!==null, 'Original codec deadline author task is missing');
    $command = ['type'=>'schedule_activity', 'activity_type'=>'parity.v1.deadline_activity',
        'queue'=>$id.'-activity', 'arguments'=>$client->payloadCodec()->envelope([$spec['row']]),
        'schedule_to_close_timeout'=>$spec['activity_schedule_to_close_seconds'],
        'retry_policy'=>['max_attempts'=>1, 'backoff_seconds'=>[1,2,5,10,15,30,60,120], 'non_retryable_error_types'=>[]]];
    $state['admission'] = $client->completeWorkflowTask($workflow['task_id'], $workflow['lease_owner'],
        $workflow['workflow_task_attempt'], [$command]);
    $admittedAt = microtime(true);
    $state['admission_observed_at'] = $admittedAt;
    $activity = $client->pollActivityTask($activityWorker, $id.'-activity', 0);
    $check($activity!==null, 'Original codec deadline activity claim is missing');
    $state['workflow_id'] = $id;
    $state['run_id'] = $original->selectedRunId;
    $state['workflow_task'] = $workflow;
    $state['activity_task'] = $activity;
    $state['command'] = $command;
    $state['before'] = namespaceHttpRun($client, $id, $original->selectedRunId);
    $state['status_before'] = $client->activityTaskStatus($activity['task_id'], $activity['activity_attempt_id'], $activity['lease_owner']);
    $check($state['status_before']['can_continue']===true, 'Original activity already lacks authority before load');
    $check(microtime(true)<$preparationDeadline, 'Original preparation budget expired');
    // Both independent load processes are ready before the parent releases this
    // barrier. No new execution or activity budget is issued at the barrier.
    echo json_encode(['ready'=>true, 'dense_workflow_id'=>$seedId, 'dense_run_id'=>$seed->selectedRunId], JSON_THROW_ON_ERROR)."\n";
    flush();
    $check(trim((string) fgets(STDIN))==='start', 'Missing explicit load handoff');
    $startedAt = microtime(true);
    $check($startedAt-$admittedAt<=$spec['maximum_handoff_delay_seconds'], 'Activity handoff exceeded its predeclared limit');
    $deadline = $startedAt + $spec['observer_budget_seconds'];
    $budget = static function () use ($deadline): void {
        if (microtime(true)>=$deadline) throw new RuntimeException('Original codec deadline observer budget expired');
    };
    $wait = $admittedAt + $spec['late_outcome_delay_seconds'] - microtime(true);
    if ($wait>0) usleep((int) ceil($wait*1000000));
    $budget();
    $state['status_after'] = $client->activityTaskStatus($activity['task_id'], $activity['activity_attempt_id'], $activity['lease_owner']);
    $state['late_observed_at'] = microtime(true);
    $late = static function (string $kind, Closure $submit): array {
        $submittedAt = microtime(true);
        $receipt = namespaceReceipt($kind, $submit);
        return $receipt + ['submitted_at'=>$submittedAt, 'observed_at'=>microtime(true)];
    };
    $state['late_outcomes'] = [
        $late('complete', static fn (): array => $client->completeActivityTask($activity['task_id'],
            $activity['activity_attempt_id'], $activity['lease_owner'], $spec['row'])),
        $late('fail', static fn (): array => $client->failActivityTask($activity['task_id'],
            $activity['activity_attempt_id'], $activity['lease_owner'], 'late codec-load result')),
        $late('heartbeat', static fn (): array => $client->heartbeatActivityTask($activity['task_id'],
            $activity['activity_attempt_id'], $activity['lease_owner'], ['message'=>'late codec-load progress'])),
    ];
    do {
        $budget();
        $state['extra_activity_poll'] = $client->pollActivityTask($activityWorker, $id.'-activity', 0);
        $parent = $client->pollWorkflowTask($worker, $id.'-workflow', 0);
        if ($parent===null) usleep(25000);
    } while ($parent===null);
    $state['parent_delivery'] = $parent;
    $state['after'] = namespaceHttpRun($client, $id, $original->selectedRunId);
    // Let the controller finish the read wave before checking that its original
    // Avro value survived. This read cannot re-admit or repair either workflow.
    echo json_encode(['deadline_observed'=>true], JSON_THROW_ON_ERROR)."\n";
    flush();
    $check(trim((string) fgets(STDIN))==='finish', 'Missing completed load observation');
    $budget();
    $afterSeed = $client->describeWorkflow($seedId, $seed->selectedRunId);
    $budget();
    $state['dense']['after_decoded_sha256'] = hash('sha256', json_encode($afterSeed->input, JSON_THROW_ON_ERROR));
    $check($state['dense']['after_decoded_sha256']===$state['dense']['decoded_sha256'], 'Original dense input changed');
    $state['observer_started_at'] = $startedAt;
    $state['observer_deadline'] = $deadline;
    $state['observer_finished_at'] = microtime(true);
    $state['outcome'] = 'observed';
} catch (Throwable $error) {
    $state['outcome'] = 'failed';
    $state['error'] = ['type'=>$error::class,
        'status'=>$error instanceof \DurableWorkflow\Exception\ServerException ? $error->status : null,
        'reason'=>$error instanceof \DurableWorkflow\Exception\ServerException ? $error->reason : null];
} finally {
    file_put_contents($output, json_encode($state, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n");
}
exit($state['outcome']==='observed' ? 0 : 1);
