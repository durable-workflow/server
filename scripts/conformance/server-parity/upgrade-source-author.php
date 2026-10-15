<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Workflow\Serializers\Avro;

require '/app/vendor/autoload.php';

// Raw public protocol author, not an SDK continuation or a migration test.
[$script, $url, $prefix] = $argv;
$started = microtime(true);
$deadline = $started + 30;
$http = new Client(['base_uri' => rtrim($url, '/'), 'http_errors' => false, 'connect_timeout' => 1]);
$headers = ['Authorization' => 'Bearer '.getenv('DW_PARITY_TOKEN'), 'X-Namespace' => 'default',
    'X-Durable-Workflow-Control-Plane-Version' => '2', 'X-Durable-Workflow-Protocol-Version' => '1.20'];
$request = static function (string $method, string $path, array $body = []) use ($http, $headers, $deadline): array {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Original 30-second source preparation deadline expired');
    }
    $response = $http->request($method, $path, ['headers' => $headers, 'json' => $body,
        'timeout' => min(5, max(0.001, $deadline - microtime(true)))]);

    return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
};
$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$expect = static function (array $response, int $status, string $operation) use ($check): array {
    $check($response[0] === $status, $operation.': '.json_encode([
        'status' => $response[0], 'reason' => $response[1]['reason'] ?? null,
        'errors' => $response[1]['errors'] ?? null,
    ]));

    return $response[1];
};
if ($request('GET', '/api/namespaces/default')[0] === 404) {
    $expect($request('POST', '/api/namespaces', ['name' => 'default']), 201, 'namespace');
}
$worker = $prefix.'-worker';
$expect($request('POST', '/api/worker/register', ['worker_id' => $worker, 'task_queue' => $prefix,
    'runtime' => 'php', 'sdk_version' => 'raw-upgrade-inspection-author',
    'supported_workflow_types' => ['parity.upgrade_activity', 'parity.upgrade_timer'],
    'supported_activity_types' => ['parity.upgrade_activity'], 'capabilities' => [],
    'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
        ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_protocol_author']),
]), 201, 'registration');
$value = [9007199254740993, null, 'upgradeλ'];
$envelope = ['codec' => 'avro', 'blob' => Avro::serialize([$value])];
$poll = ['worker_id' => $worker, 'task_queue' => $prefix, 'timeout_seconds' => 0];
$originals = [];
foreach (['activity', 'timer'] as $kind) {
    $id = $prefix.'-'.$kind;
    $run = $expect($request('POST', '/api/workflows', ['workflow_id' => $id,
        'workflow_type' => 'parity.upgrade_'.$kind, 'task_queue' => $prefix, 'input' => $envelope,
        'run_timeout_seconds' => 900, 'execution_timeout_seconds' => 1200]), 201, $kind.' admission');
    fwrite(STDERR, json_encode(['phase' => 'admitted', 'kind' => $kind, 'run_id' => $run['run_id'],
        'started_at' => $started, 'original_deadline' => $deadline], JSON_THROW_ON_ERROR)."\n");
    $task = $expect($request('POST', '/api/worker/workflow-tasks/poll', $poll), 200, $kind.' claim')['task'];
    $check($task['run_id'] === $run['run_id'], 'Original workflow claim changed');
    $command = $kind === 'activity'
        ? ['type' => 'schedule_activity', 'activity_type' => 'parity.upgrade_activity', 'arguments' => $envelope,
            'retry_policy' => ['max_attempts' => 1, 'backoff_seconds' => []],
            'start_to_close_timeout' => 600, 'schedule_to_close_timeout' => 900]
        : ['type' => 'start_timer', 'delay_seconds' => 600];
    $expect($request('POST', '/api/worker/workflow-tasks/'.$task['task_id'].'/complete', [
        'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
        'commands' => [$command],
    ]), 200, $kind.' command');
    $activity = null;
    if ($kind === 'activity') {
        $activity = $expect($request('POST', '/api/worker/activity-tasks/poll', $poll), 200, 'activity claim')['task'];
        $check($activity['run_id'] === $run['run_id'] && $activity['attempt_number'] === 1, 'Original activity changed');
    }
    $history = $expect($request('GET', '/api/workflows/'.$id.'/runs/'.$run['run_id'].'/history'), 200, 'history')['events'];
    $check(array_column($history, 'event_type') === ($kind === 'activity'
        ? ['StartAccepted', 'WorkflowStarted', 'ActivityScheduled', 'ActivityStarted']
        : ['StartAccepted', 'WorkflowStarted', 'TimerScheduled']), 'Original pending history differs');
    $originals[$kind] = ['workflow_id' => $id, 'run_id' => $run['run_id'], 'workflow_task' => $task,
        'activity_task' => $activity, 'history' => $history];
}
$check(microtime(true) < $deadline, 'Original source preparation budget expired');
echo json_encode(['outcome' => 'pass', 'scope' => 'published_php_raw_protocol_pending_source',
    'started_at' => $started, 'original_deadline' => $deadline, 'finished_at' => microtime(true),
    'originals' => $originals], JSON_THROW_ON_ERROR)."\n";
