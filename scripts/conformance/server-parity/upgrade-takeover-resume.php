<?php

declare(strict_types=1);

require '/app/vendor/autoload.php';

use GuzzleHttp\Client;
use Workflow\Serializers\Avro;

// Resumes the published-PHP originals under native after a backup-first
// takeover, through the raw public protocol. Arguments: native URL, the
// author's originals JSON file, and the timer's wait budget in seconds.
[$script, $url, $originalsFile, $timerBudget] = $argv;
$originals = json_decode((string) file_get_contents($originalsFile), true, flags: JSON_THROW_ON_ERROR)['originals'];
$http = new Client(['base_uri' => rtrim($url, '/'), 'http_errors' => false, 'connect_timeout' => 1, 'timeout' => 10]);
$headers = ['Authorization' => 'Bearer '.getenv('DW_PARITY_TOKEN'), 'X-Namespace' => 'default',
    'X-Durable-Workflow-Control-Plane-Version' => '2', 'X-Durable-Workflow-Protocol-Version' => '1.20'];
$request = static function (string $method, string $path, array $body = []) use ($http, $headers): array {
    $response = $http->request($method, $path, ['headers' => $headers] + ($body === [] ? [] : ['json' => $body]));

    return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
};
$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$expect = static function (array $response, int $status, string $operation) use ($check): array {
    $check($response[0] === $status, $operation.': '.json_encode(['status' => $response[0],
        'reason' => $response[1]['reason'] ?? null], JSON_THROW_ON_ERROR));

    return $response[1];
};
// Complete events, keys sorted at every level: PHP-written history must read
// exactly as PHP returned it before the takeover.
$sorted = static function (mixed $value) use (&$sorted): mixed {
    if (! is_array($value)) {
        return $value;
    }
    if (! array_is_list($value)) {
        ksort($value);
    }

    return array_map($sorted, $value);
};
$keep = static fn (array $original, array $current): bool => json_encode($sorted($original), JSON_THROW_ON_ERROR)
    === json_encode($sorted(array_slice($current, 0, count($original))), JSON_THROW_ON_ERROR);
$types = static fn (array $history): array => array_column($history, 'event_type');
$history = static function (array $original) use ($request, $expect): array {
    return $expect($request('GET', '/api/workflows/'.$original['workflow_id'].'/runs/'.$original['run_id'].'/history'),
        200, 'history')['events'];
};
$worker = $originals['activity']['activity_task']['lease_owner'];
$queue = $originals['activity']['workflow_task']['task_queue'] ?? 'upgrade-source';
$result = ['codec' => 'avro', 'blob' => Avro::serialize(['resumed under native'])];
$outcome = [];

// The activity leased under PHP completes with its original attempt.
$activity = $originals['activity'];
$task = $activity['activity_task'];
$completion = ['activity_attempt_id' => $task['activity_attempt_id'], 'lease_owner' => $task['lease_owner'], 'result' => $result];
$completed = $expect($request('POST', '/api/worker/activity-tasks/'.$task['task_id'].'/complete', $completion), 200, 'original activity completion');
$check(($completed['outcome'] ?? null) === 'completed' && ($completed['recorded'] ?? null) === true, 'Original activity completion was not recorded');
$replayed = $expect($request('POST', '/api/worker/activity-tasks/'.$task['task_id'].'/complete', $completion), 200, 'activity completion replay');
$check(($replayed['recorded'] ?? null) === false, 'A replayed activity completion was recorded twice');
$resume = $expect($request('POST', '/api/worker/workflow-tasks/poll', ['worker_id' => $worker, 'task_queue' => $queue,
    'timeout_seconds' => 5]), 200, 'resume claim')['task'];
$check(is_array($resume) && $resume['run_id'] === $activity['run_id'], 'The resumed workflow task was not claimed');
$original = $activity['history'];
$check(array_slice($types($resume['history_events']), 0, count($original)) === $types($original), 'Original activity history changed in the resumed task');
$check(end($resume['history_events'])['event_type'] === 'ActivityCompleted', 'Activity completion is missing from history');
$expect($request('POST', '/api/worker/workflow-tasks/'.$resume['task_id'].'/complete', ['lease_owner' => $resume['lease_owner'],
    'workflow_task_attempt' => $resume['workflow_task_attempt'],
    'commands' => [['type' => 'complete_workflow', 'result' => $result]]]), 200, 'resumed workflow completion');
// The workflow task PHP already completed is a stale attempt.
$stale = $activity['workflow_task'];
$refused = $request('POST', '/api/worker/workflow-tasks/'.$stale['task_id'].'/complete', ['lease_owner' => $stale['lease_owner'],
    'workflow_task_attempt' => $stale['workflow_task_attempt'], 'commands' => [['type' => 'complete_workflow', 'result' => $result]]]);
$check($refused[0] === 409, 'A stale PHP workflow task attempt was accepted');
$closed = $history($activity);
$check($keep($original, $closed) && end($closed)['event_type'] === 'WorkflowCompleted',
    'The resumed activity workflow did not complete on its original history');
$outcome['activity'] = ['run_id' => $activity['run_id'], 'events' => array_column($closed, 'event_type'),
    'stale_attempt_status' => $refused[0], 'stale_attempt_reason' => $refused[1]['reason'] ?? null];

// The timer scheduled under PHP keeps its deadline and fires under native.
$timer = $originals['timer'];
$scheduled = array_values(array_filter($timer['history'], static fn (array $event): bool => $event['event_type'] === 'TimerScheduled'))[0];
$fireAt = $scheduled['payload']['fire_at'];
$current = $history($timer);
$check($keep($timer['history'], $current), 'Original timer history changed');
$deadline = (float) (new DateTimeImmutable($fireAt))->format('U.u');
$check($deadline - microtime(true) <= (float) $timerBudget, 'The original timer deadline is outside the wait budget');
$fired = null;
while ($fired === null && microtime(true) < $deadline + 30) {
    $poll = $expect($request('POST', '/api/worker/workflow-tasks/poll', ['worker_id' => $worker, 'task_queue' => $queue,
        'timeout_seconds' => 5]), 200, 'timer claim');
    $fired = $poll['task'] ?? null;
}
$check(is_array($fired) && $fired['run_id'] === $timer['run_id'], 'The original timer did not fire under native');
$firedEvent = end($fired['history_events']);
$check($firedEvent['event_type'] === 'TimerFired', 'Timer firing is missing from history');
$firedPublic = array_values(array_filter($history($timer), static fn (array $event): bool => $event['event_type'] === 'TimerFired'));
$check(count($firedPublic) === 1, 'The original timer did not fire exactly once');
$firedAt = (float) (new DateTimeImmutable($firedPublic[0]['timestamp']))->format('U.u');
$check($firedAt >= $deadline, 'The original timer fired before its deadline');
$expect($request('POST', '/api/worker/workflow-tasks/'.$fired['task_id'].'/complete', ['lease_owner' => $fired['lease_owner'],
    'workflow_task_attempt' => $fired['workflow_task_attempt'],
    'commands' => [['type' => 'complete_workflow', 'result' => $result]]]), 200, 'timer workflow completion');
$outcome['timer'] = ['run_id' => $timer['run_id'], 'fire_at' => $fireAt,
    'fired_after_seconds' => round($firedAt - $deadline, 3), 'events' => array_column($history($timer), 'event_type')];
echo json_encode(['outcome' => 'pass', 'scope' => 'native_resumes_published_php_originals_after_takeover'] + $outcome,
    JSON_THROW_ON_ERROR)."\n";
