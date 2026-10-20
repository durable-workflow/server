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
$activity = $originals['activity'];
$timer = $originals['timer'];
$complete = static function (array $claimed, string $operation) use ($expect, $request, $result): void {
    $expect($request('POST', '/api/worker/workflow-tasks/'.$claimed['task_id'].'/complete', ['lease_owner' => $claimed['lease_owner'],
        'workflow_task_attempt' => $claimed['workflow_task_attempt'],
        'commands' => [['type' => 'complete_workflow', 'result' => $result]]]), 200, $operation);
};

// The timer scheduled under PHP keeps its history and deadline.
$scheduled = array_values(array_filter($timer['history'], static fn (array $event): bool => $event['event_type'] === 'TimerScheduled'))[0];
$fireAt = $scheduled['payload']['fire_at'];
$check($keep($timer['history'], $history($timer)), 'Original timer history changed');
$deadline = (float) (new DateTimeImmutable($fireAt))->format('U.u');
$check($deadline - microtime(true) <= (float) $timerBudget, 'The original timer deadline is outside the wait budget');

// The activity leased under PHP completes with its original attempt.
$task = $activity['activity_task'];
$completion = ['activity_attempt_id' => $task['activity_attempt_id'], 'lease_owner' => $task['lease_owner'], 'result' => $result];
$completed = $expect($request('POST', '/api/worker/activity-tasks/'.$task['task_id'].'/complete', $completion), 200, 'original activity completion');
$check(($completed['outcome'] ?? null) === 'completed' && ($completed['recorded'] ?? null) === true, 'Original activity completion was not recorded');
$replayed = $expect($request('POST', '/api/worker/activity-tasks/'.$task['task_id'].'/complete', $completion), 200, 'activity completion replay');
$check(($replayed['recorded'] ?? null) === false, 'A replayed activity completion was recorded twice');

// Each original workflow then gets one workflow task: the activity's at
// once and the timer's at its deadline. On a slow host the timer is already
// due, so either may be claimed first.
$original = $activity['history'];
$claims = [];
$until = max($deadline, microtime(true)) + 30;
while (count($claims) < 2 && microtime(true) < $until) {
    $claimed = $expect($request('POST', '/api/worker/workflow-tasks/poll', ['worker_id' => $worker, 'task_queue' => $queue,
        'timeout_seconds' => 5]), 200, 'workflow task claim')['task'] ?? null;
    if (! is_array($claimed)) {
        continue;
    }
    $run = $claimed['run_id'];
    $check(in_array($run, [$activity['run_id'], $timer['run_id']], true) && ! isset($claims[$run]),
        'An unexpected workflow task was claimed');
    $claims[$run] = true;
    if ($run === $activity['run_id']) {
        $check(array_slice($types($claimed['history_events']), 0, count($original)) === $types($original),
            'Original activity history changed in the resumed task');
        $check(end($claimed['history_events'])['event_type'] === 'ActivityCompleted', 'Activity completion is missing from history');
        $complete($claimed, 'resumed workflow completion');
    } else {
        $check(end($claimed['history_events'])['event_type'] === 'TimerFired', 'Timer firing is missing from history');
        $complete($claimed, 'timer workflow completion');
    }
}
$check(isset($claims[$activity['run_id']]), 'The resumed workflow task was not claimed');
$check(isset($claims[$timer['run_id']]), 'The original timer did not fire under native');

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

$timerHistory = $history($timer);
$firedPublic = array_values(array_filter($timerHistory, static fn (array $event): bool => $event['event_type'] === 'TimerFired'));
$check(count($firedPublic) === 1, 'The original timer did not fire exactly once');
$firedAt = (float) (new DateTimeImmutable($firedPublic[0]['timestamp']))->format('U.u');
$check($firedAt >= $deadline, 'The original timer fired before its deadline');
$check($keep($timer['history'], $timerHistory) && end($timerHistory)['event_type'] === 'WorkflowCompleted',
    'The timer workflow did not complete on its original history');
$outcome['timer'] = ['run_id' => $timer['run_id'], 'fire_at' => $fireAt,
    'fired_after_seconds' => round($firedAt - $deadline, 3), 'events' => array_column($timerHistory, 'event_type')];
echo json_encode(['outcome' => 'pass', 'scope' => 'native_resumes_published_php_originals_after_takeover'] + $outcome,
    JSON_THROW_ON_ERROR)."\n";
