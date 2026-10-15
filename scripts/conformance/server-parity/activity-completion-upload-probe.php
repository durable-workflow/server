<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Workflow\Serializers\Avro;

require '/app/vendor/autoload.php';

[$script, $url, $prefix] = $argv;
$workerId = $prefix.':workerλ😀';
$started = microtime(true);
$deadline = $started + 30;
$http = new Client(['base_uri' => rtrim($url, '/'), 'http_errors' => false,
    'connect_timeout' => 1, 'timeout' => 5]);
$headers = ['Authorization' => 'Bearer '.(getenv('DW_PARITY_TOKEN') ?: 'completion-fixture-token'), 'X-Namespace' => 'default',
    'X-Durable-Workflow-Control-Plane-Version' => '2', 'X-Durable-Workflow-Protocol-Version' => '1.20'];
$request = static function (string $method, string $path, array $body = []) use ($http, $headers, $deadline): array {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Original 30-second observation expired');
    }
    $response = $http->request($method, $path, ['headers' => $headers, 'json' => $body,
        'timeout' => min(5, max(0.001, $deadline - microtime(true)))]);

    return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
};
$require = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$expect = static function (array $response, int $status, string $operation) use ($require): array {
    $require($response[0] === $status, $operation.': '.json_encode([
        'status' => $response[0], 'reason' => $response[1]['reason'] ?? null,
        'errors' => $response[1]['errors'] ?? null,
    ]));

    return $response[1];
};
$envelope = static fn (mixed $value): array => ['codec' => 'avro', 'blob' => Avro::serialize($value)];
$sameReference = static function (array $left, array $right): bool {
    if (count($left) !== 5 || count($right) !== 5) {
        return false;
    }
    foreach (['schema', 'reference_id', 'codec', 'size_bytes', 'sha256'] as $field) {
        if (! array_key_exists($field, $left) || ! array_key_exists($field, $right) || $left[$field] !== $right[$field]) {
            return false;
        }
    }

    return true;
};
if ($request('GET', '/api/namespaces/default')[0] === 404) {
    $expect($request('POST', '/api/namespaces', ['name' => 'default']), 201, 'namespace');
}
$expect($request('PUT', '/api/namespaces/default/external-storage', ['driver' => 'local', 'enabled' => true,
    'threshold_bytes' => 16, 'config' => ['uri' => 'file:///state/objects']]), 200, 'policy');
$expect($request('POST', '/api/worker/register', ['worker_id' => $workerId, 'task_queue' => $prefix,
    'runtime' => 'php', 'sdk_version' => 'raw-http-control',
    'supported_workflow_types' => ['parity.completion_upload'], 'supported_activity_types' => ['parity.completion_activity'],
    'capabilities' => [], 'capability_manifest' => array_fill_keys(
        ['local_activities', 'worker_sessions', 'sticky_execution'],
        ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http_control'],
    )]), 201, 'registration');
$original = $expect($request('POST', '/api/workflows', ['workflow_id' => $prefix,
    'workflow_type' => 'parity.completion_upload', 'task_queue' => $prefix, 'input' => $envelope([]),
    'run_timeout_seconds' => 120, 'execution_timeout_seconds' => 600]), 201, 'original admission');
fwrite(STDERR, json_encode(['phase' => 'admitted', 'run_id' => $original['run_id'],
    'started_at' => $started, 'original_deadline' => $deadline], JSON_THROW_ON_ERROR)."\n");
$poll = ['worker_id' => $workerId, 'task_queue' => $prefix, 'timeout_seconds' => 0];
$task = $expect($request('POST', '/api/worker/workflow-tasks/poll', $poll), 200, 'workflow claim')['task'];
$require($task['run_id'] === $original['run_id'], 'Original workflow claim changed');
$expect($request('POST', '/api/worker/workflow-tasks/'.$task['task_id'].'/complete', [
    'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
    'commands' => [['type' => 'schedule_activity', 'activity_type' => 'parity.completion_activity',
        'arguments' => $envelope([]), 'retry_policy' => ['max_attempts' => 1, 'backoff_seconds' => []],
        'start_to_close_timeout' => 120, 'schedule_to_close_timeout' => 600]],
]), 200, 'activity admission');
$activity = $expect($request('POST', '/api/worker/activity-tasks/poll', $poll), 200, 'activity claim')['task'];
$require($activity['run_id'] === $original['run_id'], 'Original activity claim changed');
$statusPath = '/api/worker/activity-tasks/'.$activity['task_id'].'/status';
$ownership = ['activity_attempt_id' => $activity['activity_attempt_id'], 'lease_owner' => $activity['lease_owner']];
$beforeUpload = $expect($request('POST', $statusPath, $ownership), 200, 'original activity status');
$require($beforeUpload['can_continue'] === true, 'Original activity lacks upload authority');
$context = ['schema' => 'durable-workflow.v2.payload-completion-context.v1', 'kind' => 'activity',
    'task_id' => $activity['task_id'], 'attempt' => $activity['activity_attempt_id'],
    'lease_owner' => $activity['lease_owner'], 'operation' => 'complete', 'slot' => ['result']];
$require(str_contains($context['lease_owner'], 'λ😀'), 'Completion owner lost actual UTF-8');
$expected = [9007199254740993, null, 'rangeλ'];
$bytes = Avro::serialize($expected);
$upload = static function (array $context, string $bytes) use ($http, $headers, $deadline): array {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Original upload observation expired');
    }
    $response = $http->post('/api/external-payloads/v1', ['headers' => $headers + [
        'Content-Type' => 'application/octet-stream', 'X-Durable-Workflow-Payload-Codec' => 'avro',
        'X-Durable-Workflow-Payload-Size' => (string) strlen($bytes),
        'X-Durable-Workflow-Payload-SHA256' => hash('sha256', $bytes),
        'X-Durable-Workflow-Payload-Completion' => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    ], 'body' => $bytes, 'timeout' => min(5, max(0.001, $deadline - microtime(true)))]);

    return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
};
$first = $upload($context, $bytes);
if ($first[0] === 422 && ($first[1]['reason'] ?? null) === 'development_payload_completion_upload_unqualified') {
    echo json_encode(['outcome' => 'unsupported', 'scope' => 'native_completion_upload_counterfactual',
        'run_id' => $original['run_id'], 'task_id' => $activity['task_id'], 'response' => $first,
        'original_budget_seconds' => 30], JSON_THROW_ON_ERROR)."\n";
    exit(2);
}
$reference = $expect($first, 201, 'completion upload')['reference'];
$require($reference['sha256'] === hash('sha256', $bytes) && $reference['size_bytes'] === strlen($bytes), 'Upload bytes changed');
$duplicate = $expect($upload($context, $bytes), 201, 'identical upload')['reference'];
$require($sameReference($duplicate, $reference), 'Duplicate upload identity changed: '.json_encode([$reference, $duplicate], JSON_THROW_ON_ERROR));
$conflict = $upload($context, Avro::serialize('different'));
$expect($conflict, 409, 'conflicting upload');
$require($conflict[1]['reason'] === 'external_payload_completion_conflict', 'Unexpected conflict disposition');
$wrongOwner = $context;
$wrongOwner['lease_owner'] .= '-wrong';
$refused = $upload($wrongOwner, $bytes);
$expect($refused, 409, 'foreign owner');
$require($refused[1]['reason'] === 'external_payload_completion_lease_rejected', 'Unexpected owner disposition');
$afterUpload = $expect($request('POST', $statusPath, $ownership), 200, 'activity status after uploads');
$require($afterUpload['can_continue'] === true && $afterUpload['lease_expires_at'] === $beforeUpload['lease_expires_at'],
    'Upload changed original activity lease authority');
$completion = $expect($request('POST', '/api/worker/activity-tasks/'.$activity['task_id'].'/complete', [
    'lease_owner' => $activity['lease_owner'], 'activity_attempt_id' => $activity['activity_attempt_id'],
    'result' => ['codec' => 'avro', 'external_payload' => $reference],
]), 200, 'activity completion');
$require($completion['recorded'] === true, 'Activity outcome was not committed');
$require($sameReference($expect($upload($context, $bytes), 201, 'closed upload reconciliation')['reference'], $reference),
    'Closed upload retry changed its reference');
$parent = $expect($request('POST', '/api/worker/workflow-tasks/poll', $poll), 200, 'parent claim')['task'];
$require($parent['run_id'] === $original['run_id'], 'Cold parent changed original');
$completed = array_values(array_filter($parent['history_events'], static fn (array $event): bool => $event['event_type'] === 'ActivityCompleted'));
$require(count($completed) === 1, 'Activity completion history is not unique');
$result = $completed[0]['payload']['result'];
if (isset($result['blob'])) {
    $require(Avro::unserialize($result['blob']) === $expected, 'Cold inline result changed');
} else {
    $require($sameReference($result['external_payload'] ?? [], $reference), 'Cold external result changed');
    $response = $http->get('/api/external-payloads/v1/'.$reference['reference_id'], ['headers' => $headers + [
        'X-Durable-Workflow-Payload-Codec' => 'avro', 'X-Durable-Workflow-Payload-Size' => (string) strlen($bytes),
        'X-Durable-Workflow-Payload-SHA256' => hash('sha256', $bytes),
    ]]);
    $require($response->getStatusCode() === 200 && (string) $response->getBody() === $bytes, 'Cold bytes changed');
}
$expect($request('POST', '/api/worker/workflow-tasks/'.$parent['task_id'].'/complete', [
    'lease_owner' => $parent['lease_owner'], 'workflow_task_attempt' => $parent['workflow_task_attempt'],
    'commands' => [['type' => 'complete_workflow', 'result' => $envelope($expected)]],
]), 200, 'parent completion');
$describe = $expect($request('GET', '/api/workflows/'.$prefix.'/runs/'.$original['run_id']), 200, 'original result');
$require($describe['status'] === 'completed' && microtime(true) < $deadline, 'Original did not complete within its budget');
$expect($request('DELETE', '/api/worker/registrations/'.rawurlencode($workerId)), 200, 'registration retirement');
$finished = microtime(true);
echo json_encode(['outcome' => 'pass', 'scope' => 'raw_http_activity_completion_upload',
    'run_id' => $original['run_id'], 'task_id' => $activity['task_id'], 'activity_attempt_id' => $activity['activity_attempt_id'],
    'lease_owner' => $activity['lease_owner'],
    'parent_run_id' => $parent['run_id'], 'parent_task_id' => $parent['task_id'], 'result' => $expected,
    'activity_completion_count' => count($completed), 'workflow_status' => $describe['status'],
    'lease_expires_before' => $beforeUpload['lease_expires_at'], 'lease_expires_after' => $afterUpload['lease_expires_at'],
    'reference' => $reference, 'duplicate_reference' => $duplicate,
    'duplicate_key_order_changed' => array_keys($reference) !== array_keys($duplicate),
    'original_budget_seconds' => 30, 'elapsed_seconds' => $finished - $started,
    'started_at' => $started, 'original_deadline' => $deadline, 'finished_at' => $finished,
    'controls' => ['identical_upload', 'conflicting_slot', 'foreign_owner', 'closed_upload_reconciliation', 'cold_parent', 'utf8_completion_context']], JSON_THROW_ON_ERROR)."\n";
