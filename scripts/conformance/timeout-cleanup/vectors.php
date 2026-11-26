<?php

declare(strict_types=1);

// Installed timeout-cleanup vectors (Server #1201) against a running native
// Server, driven over raw HTTP. Vectors follow the table in Workflow's
// docs/architecture/timeout-cleanup.md with shorter clocks (a 12 s allowance). run.sh starts the
// Server and the published-PHP-SDK workers and runs the phases in order:
//
//   main          admission, vectors 1-4 and 7-12, all at once
//   late-start    vector 5: start, then run.sh pauses the Server until after
//   late-check      the work deadline so the engine notices it late
//   replace-start vector 6: the worker holding the cleanup turn is killed and
//   replace-check   a fresh replacement process finishes it
//   extended      task-boundary expiry, replay-blocking precedence, malformed
//                 and unauthorized markers (a raw worker-protocol client),
//                 redrive inheritance, and scoped descendants (PHP workers)
//
// Each phase appends its results to /evidence/results.jsonl and exits
// non-zero when a check fails.

require __DIR__.'/vendor/autoload.php';

use DurableWorkflow\Codec\AvroPayloadCodec;
use GuzzleHttp\Client as Http;

const EVIDENCE = '/evidence';
// Cleanup windows wide enough for a loaded shared runner.
const ALLOWANCE = 12;
$phase = $argv[1] ?? 'main';
$suffix = getenv('TC_RUN_SUFFIX') ?: (string) time();
$http = new Http(['base_uri' => getenv('TC_SERVER_URL') ?: 'http://server:8080', 'http_errors' => false, 'timeout' => 30,
    'headers' => ['Authorization' => 'Bearer '.(getenv('TC_TOKEN') ?: 'timeout-cleanup-token'), 'X-Namespace' => 'default',
        'X-Durable-Workflow-Control-Plane-Version' => '2', 'Accept' => 'application/json']]);
$codec = new AvroPayloadCodec();
$failures = 0;

/** @return array{int, array<mixed>} */
function call(Http $http, string $method, string $path, ?array $body = null): array
{
    for ($attempt = 1; ; $attempt++) {
        try {
            $response = $http->request($method, $path, $body === null ? [] : ['json' => $body]);
            break;
        } catch (GuzzleHttp\Exception\ConnectException $error) {
            if ($attempt >= 30) {
                throw $error;
            }
            usleep(500_000);
        }
    }
    $decoded = json_decode((string) $response->getBody(), true);

    return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
}

function start(Http $http, AvroPayloadCodec $codec, string $id, string $type, string $queue, array $options): array
{
    return call($http, 'POST', '/api/workflows', ['workflow_id' => $id, 'workflow_type' => $type, 'task_queue' => $queue,
        'input' => $codec->envelope([$id])] + $options);
}

function history(Http $http, string $id, string $run): array
{
    [$status, $body] = call($http, 'GET', "/api/workflows/$id/runs/$run/history");
    if ($status !== 200) {
        throw new RuntimeException("history $id/$run: $status ".json_encode($body));
    }
    @mkdir(EVIDENCE.'/histories');
    file_put_contents(EVIDENCE."/histories/$id-$run.json", json_encode($body['events'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $body['events'] ?? [];
}

function events(array $history, string $type): array
{
    return array_values(array_filter($history, static fn (array $event): bool => $event['event_type'] === $type));
}

function payload(array $history, string $type): array
{
    $found = events($history, $type);

    return count($found) === 1 ? ($found[0]['payload'] ?? []) : ['missing' => $type, 'count' => count($found)];
}

function describe(Http $http, string $id): array
{
    return call($http, 'GET', "/api/workflows/$id")[1];
}

/** Waits until the workflow's current run is closed; returns its final run id. */
function closed(Http $http, string $id, int $seconds = 90): string
{
    $until = time() + $seconds;
    do {
        $described = describe($http, $id);
        if (($described['status'] ?? null) !== null && !in_array($described['status'], ['pending', 'running', 'waiting'], true)
            && ($described['is_terminal'] ?? true) !== false) {
            return (string) $described['run_id'];
        }
        sleep(1);
    } while (time() < $until);
    throw new RuntimeException("$id did not close: ".json_encode(describe($http, $id)));
}

function waitFor(Http $http, string $id, string $run, string $type, int $seconds = 60): array
{
    $until = time() + $seconds;
    do {
        $history = history($http, $id, $run);
        if (events($history, $type) !== []) {
            return $history;
        }
        sleep(1);
    } while (time() < $until);
    throw new RuntimeException("$id: no $type within {$seconds}s");
}

function at(string $value): float
{
    return (float) (new DateTimeImmutable($value))->format('U.u');
}

/** Deadlines in events are whole seconds; WorkflowStarted keeps milliseconds. */
function same(string $left, string $right, float $offset = 0.0): bool
{
    return abs(at($left) + $offset - at($right)) < 1;
}

function observations(string $id): array
{
    $file = EVIDENCE.'/observations.jsonl';
    $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

    return array_values(array_filter(array_map(static fn (string $line): array => json_decode($line, true), $lines),
        static fn (array $seen): bool => $seen['vector'] === $id));
}

function check(int|string $vector, string $what, bool $passed, mixed $detail = null): void
{
    global $failures;
    $vector = (string) $vector;
    if (!$passed) {
        $failures++;
    }
    $line = ['vector' => $vector, 'check' => $what, 'passed' => $passed] + ($passed ? [] : ['detail' => $detail]);
    file_put_contents(EVIDENCE.'/results.jsonl', json_encode($line, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
    fwrite(STDOUT, ($passed ? 'PASS ' : 'FAIL ')."$vector: $what".($passed ? '' : ' '.json_encode($detail, JSON_UNESCAPED_SLASHES))."\n");
}

/** One WorkflowTimeoutCleanupStarted, a typed timeout delivery, and a timed-out close. */
function timedOut(Http $http, int|string $vector, string $id, string $run, string $outcome, string $kind): array
{
    $history = history($http, $id, $run);
    $entered = events($history, 'WorkflowTimeoutCleanupStarted');
    check($vector, 'cleanup entered exactly once', count($entered) === 1, count($entered));
    $started = $entered[0]['payload'] ?? [];
    $closed = payload($history, 'WorkflowTimedOut');
    check($vector, "WorkflowTimedOut with outcome $outcome", ($closed['timeout_cleanup']['outcome'] ?? null) === $outcome, $closed);
    check($vector, "timeout kind $kind", ($started['timeout_kind'] ?? null) === $kind && ($closed['timeout_cleanup']['timeout_kind'] ?? null) === $kind,
        [$started, $closed['timeout_cleanup'] ?? null]);
    check($vector, 'cleanup deadline is the hard deadline', isset($started['cleanup_deadline_at'], $started['hard_deadline_at'])
        && at($started['cleanup_deadline_at']) === at($started['hard_deadline_at'])
        && ($closed['timeout_cleanup']['cleanup_deadline_at'] ?? null) === $started['cleanup_deadline_at'], $started);
    $described = describe($http, $id);
    check($vector, 'describe failed / timed_out', ($described['status'] ?? null) === 'failed'
        && ($described['closed_reason'] ?? null) === 'timed_out', array_intersect_key($described, ['status' => 1, 'closed_reason' => 1]));
    $seen = observations($id);
    check($vector, 'workflow code received WorkflowTimeoutCleanupRequested with cause timeout', $seen !== []
        && array_filter($seen, static fn (array $one): bool => $one['timeout_subtype'] !== true || $one['cause'] !== 'timeout') === [], $seen);

    return [$history, $started, $closed];
}

$main = static function () use ($http, $codec, $suffix): void {
    $q = 'timeout-cleanup';
    $id = static fn (string $name): string => "tc-$name-$suffix";
    $short = ['execution_timeout_seconds' => 30, 'timeout_cleanup_seconds' => ALLOWANCE];

    // Admission: no worker, then only an ordinary worker, refuse without writing.
    foreach (['tc-no-worker' => 'no worker on the queue', 'tc-plain-queue' => 'only a non-cooperative worker'] as $queue => $why) {
        [$status, $body] = start($http, $codec, $id("admission-$queue"), 'tc.return', $queue, $short);
        check('admission', "refused with $why", $status === 422 && ($body['reason'] ?? null) === 'timeout_cleanup_not_supported', [$status, $body]);
        check('admission', "nothing written with $why", call($http, 'GET', '/api/workflows/'.$id("admission-$queue"))[0] === 404);
    }
    // Vector 12, and the other field rules.
    foreach ([
        'vector-12' => [['execution_timeout_seconds' => 10, 'timeout_cleanup_seconds' => 10], 'must be shorter than the execution timeout'],
        'no hard timeout' => [['timeout_cleanup_seconds' => 10], 'needs an execution or run timeout'],
        'zero' => [['execution_timeout_seconds' => 100, 'timeout_cleanup_seconds' => 0], 'at least 1'],
        'above 86400' => [['execution_timeout_seconds' => 100000, 'timeout_cleanup_seconds' => 86401], 'not be greater than 86400'],
    ] as $name => [$options, $message]) {
        [$status, $body] = start($http, $codec, $id('v12'), 'tc.return', $q, $options);
        $errors = implode(' ', $body['errors']['timeout_cleanup_seconds'] ?? []);
        check($name === 'vector-12' ? '12' : 'admission', "refused: $name", $status === 422 && str_contains($errors, $message), [$status, $body]);
    }

    $runs = [];
    foreach ([
        '1' => ['tc.return', $short], '1-rethrow' => ['tc.rethrow', $short], '2' => ['tc.fail', $short],
        '3' => ['tc.linger', $short], '4-terminate' => ['tc.linger', $short], '4-cancel' => ['tc.linger', $short],
        '7' => ['tc.continue', ['execution_timeout_seconds' => 50, 'timeout_cleanup_seconds' => ALLOWANCE]],
        '8' => ['tc.continue', ['run_timeout_seconds' => 30, 'timeout_cleanup_seconds' => ALLOWANCE]],
        '9' => ['tc.return', ['execution_timeout_seconds' => 90, 'run_timeout_seconds' => 30, 'timeout_cleanup_seconds' => ALLOWANCE]],
        '10' => ['tc.linger', $short],
        '11' => ['tc.plain', ['execution_timeout_seconds' => 10]],
    ] as $vector => [$type, $options]) {
        [$status, $body] = start($http, $codec, $id("v$vector"), $type, $q, $options);
        check($vector, 'admitted', $status === 201, [$status, $body]);
        $runs[$vector] = ['id' => $id("v$vector"), 'run' => (string) ($body['run_id'] ?? '')];
        $started = payload(history($http, $runs[$vector]['id'], $runs[$vector]['run']), 'WorkflowStarted');
        $runs[$vector]['started'] = $started;
    }

    // Immutable admission: the allowance and work deadline in WorkflowStarted.
    $w1 = $runs['1']['started'];
    check('1', 'WorkflowStarted records allowance and work deadline = hard - allowance',
        ($w1['timeout_cleanup']['allowance_seconds'] ?? null) === ALLOWANCE
        && same($w1['timeout_cleanup']['work_deadlines']['execution'] ?? 'now', $w1['execution_deadline_at'] ?? 'now', ALLOWANCE), $w1);
    check('11', 'no opt-in: WorkflowStarted has no timeout_cleanup', !array_key_exists('timeout_cleanup', $runs['11']['started']), $runs['11']['started']);

    // Vector 10: a user's cooperative request before the work deadline owns cleanup.
    sleep(3);
    [$status, $accepted] = call($http, 'POST', "/api/workflows/{$runs['10']['id']}/runs/{$runs['10']['run']}/request-cancellation",
        ['reason' => 'user', 'cleanup_timeout_seconds' => 120]);
    check('10', 'cooperative request accepted, cleanup deadline capped at the hard deadline', $status === 202
        && abs(at($accepted['cancellation_request']['cleanup_deadline_at'] ?? 'now') - at($runs['10']['started']['execution_deadline_at'])) < 1,
        [$status, $accepted]);

    // Vector 4: preempt cleanup once it is running.
    foreach (['4-terminate' => 'terminate', '4-cancel' => 'cancel'] as $vector => $verb) {
        $run = $runs[$vector];
        waitFor($http, $run['id'], $run['run'], 'WorkflowTimeoutCleanupStarted');
        $until = time() + 20;
        while (observations($run['id']) === [] && time() < $until) {
            sleep(1);
        }
        [$status, $body] = call($http, 'POST', "/api/workflows/{$run['id']}/$verb", ['reason' => 'operator']);
        check($vector, "$verb accepted during cleanup", $status === 200, [$status, $body]);
    }

    foreach ($runs as $vector => $run) {
        $runs[$vector]['final'] = closed($http, $run['id']);
    }

    foreach (['1' => 'completed', '1-rethrow' => 'completed'] as $vector => $outcome) {
        [$history, $started, $closed] = timedOut($http, $vector, $runs[$vector]['id'], $runs[$vector]['final'], $outcome, 'execution');
        check($vector, 'work deadline = hard deadline - allowance',
            abs(at($started['hard_deadline_at']) - at($started['work_deadline_at']) - ALLOWANCE) < 0.001, $started);
        check($vector, 'no business result: run output discarded', events($history, 'WorkflowCompleted') === []);
    }
    [$history, , $closed] = timedOut($http, '2', $runs['2']['id'], $runs['2']['final'], 'failed', 'execution');
    check('2', 'failed outcome links failure_id', is_string($closed['timeout_cleanup']['failure_id'] ?? null), $closed);
    check('2', 'failure_category stays timeout', ($closed['failure_category'] ?? null) === 'timeout', $closed);
    check('2', 'message says cleanup failed plainly', str_contains((string) ($closed['message'] ?? ''), 'Timeout cleanup failed.'), $closed);

    [$history, , $closed] = timedOut($http, '3', $runs['3']['id'], $runs['3']['final'], 'expired', 'execution');
    $types = array_column($history, 'event_type');
    $close = array_search('WorkflowTimedOut', $types, true);
    $opened = count(events($history, 'TimerScheduled'));
    $settled = count(array_filter(array_slice($types, 0, (int) $close), static fn (string $type): bool => in_array($type, ['TimerFired', 'TimerCancelled'], true)));
    check('3', 'open timers settled before WorkflowTimedOut', $close !== false && $opened > 0 && $settled === $opened, array_slice($types, -8));

    foreach (['4-terminate' => 'WorkflowTerminated', '4-cancel' => 'WorkflowCancelled'] as $vector => $event) {
        $history = history($http, $runs[$vector]['id'], $runs[$vector]['final']);
        $closed = payload($history, $event);
        check($vector, "$event with outcome preempted", ($closed['timeout_cleanup']['outcome'] ?? null) === 'preempted', $closed);
        check($vector, 'no WorkflowTimedOut', events($history, 'WorkflowTimedOut') === []);
    }

    // Vectors 7 and 8: continue-as-new inherits the allowance and the execution clock.
    foreach (['7' => 'execution', '8' => 'run'] as $vector => $kind) {
        $run = $runs[$vector];
        check($vector, 'continued as new', $run['final'] !== $run['run'], $run);
        [$history, $started] = timedOut($http, $vector, $run['id'], $run['final'], 'completed', $kind);
        $successor = payload($history, 'WorkflowStarted');
        check($vector, 'successor keeps the allowance', ($successor['timeout_cleanup']['allowance_seconds'] ?? null) === ALLOWANCE, $successor);
        if ($kind === 'execution') {
            check($vector, 'successor keeps the original execution work deadline and hard cap',
                ($successor['timeout_cleanup']['work_deadlines']['execution'] ?? null) === $run['started']['timeout_cleanup']['work_deadlines']['execution']
                && same($started['hard_deadline_at'], $run['started']['execution_deadline_at']), [$run['started'], $successor, $started]);
        } else {
            $original = at($run['started']['timeout_cleanup']['work_deadlines']['run']);
            $next = at($successor['timeout_cleanup']['work_deadlines']['run'] ?? 'now');
            check($vector, 'successor run work deadline = its own run deadline - allowance, later than the original',
                $next > $original && abs(at($started['hard_deadline_at']) - $next - ALLOWANCE) < 0.001, [$run['started'], $successor, $started]);
        }
    }

    // Vector 9: the earlier run deadline names the kind and ends cleanup.
    [, $started] = timedOut($http, '9', $runs['9']['id'], $runs['9']['final'], 'completed', 'run');
    check('9', 'cleanup deadline = min(run, execution) hard deadline = run deadline',
        same($started['cleanup_deadline_at'], $runs['9']['started']['run_deadline_at']), [$started, $runs['9']['started']]);

    // Vector 10: no timeout cleanup; the hard timeout wins and preempts the user's cleanup.
    $history = history($http, $runs['10']['id'], $runs['10']['final']);
    $closed = payload($history, 'WorkflowTimedOut');
    check('10', 'no WorkflowTimeoutCleanupStarted', events($history, 'WorkflowTimeoutCleanupStarted') === []);
    check('10', 'WorkflowTimedOut records the cooperative cleanup preempted', ($closed['cancellation_cleanup']['outcome'] ?? null) === 'preempted'
        && ($closed['cancellation_cleanup']['request_id'] ?? null) === ($accepted['cancellation_request']['request_id'] ?? false)
        && !array_key_exists('timeout_cleanup', $closed), $closed);
    $seen = observations($runs['10']['id']);
    check('10', 'workflow code saw an ordinary cancellation (no timeout cause)', count($seen) === 1 && $seen[0]['cause'] !== 'timeout'
        && $seen[0]['timeout_subtype'] === false, $seen);

    // Vector 11: unchanged hard timeout.
    $history = history($http, $runs['11']['id'], $runs['11']['final']);
    $closed = payload($history, 'WorkflowTimedOut');
    check('11', 'WorkflowTimedOut without timeout_cleanup', !isset($closed['missing']) && !array_key_exists('timeout_cleanup', $closed), $closed);
    check('11', 'no cleanup entered and no workflow code after the deadline', events($history, 'WorkflowTimeoutCleanupStarted') === []
        && observations($runs['11']['id']) === []);
};

$state = static fn (string $name): string => EVIDENCE."/$name.json";

// A raw worker-protocol client for the cases an SDK worker never produces:
// late completions, replay-blocking failures and malformed or unauthorized
// markers. Each case has its own task queue and worker identity.
function wcall(Http $http, string $path, array $body): array
{
    for ($attempt = 1; ; $attempt++) {
        try {
            $response = $http->request('POST', "/api/worker/$path", ['json' => $body,
                'headers' => ['X-Durable-Workflow-Protocol-Version' => '1.20']]);
            break;
        } catch (GuzzleHttp\Exception\ConnectException $error) {
            if ($attempt >= 30) {
                throw $error;
            }
            usleep(500_000);
        }
    }
    $decoded = json_decode((string) $response->getBody(), true);

    return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
}

function rawRegister(Http $http, string $worker, string $queue, bool $capable): void
{
    $capabilities = ['cooperative_cancellation'];
    $manifest = ['local_activities' => ['supported' => false, 'minimum_protocol_version' => '1.18'],
        'worker_sessions' => ['supported' => false, 'minimum_protocol_version' => '1.18'],
        'sticky_execution' => ['supported' => false, 'minimum_protocol_version' => '1.18']];
    if ($capable) {
        $capabilities[] = 'timeout_cleanup';
        $manifest['timeout_cleanup'] = ['supported' => true, 'minimum_protocol_version' => '1.20', 'version' => 1];
    }
    [$status, $body] = wcall($http, 'register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
        'supported_workflow_types' => ['tc.raw'], 'supported_activity_types' => ['tc.raw-activity'], 'capabilities' => $capabilities,
        'capability_manifest' => $manifest]);
    if ($status !== 201) {
        throw new RuntimeException("register $worker: $status ".json_encode($body));
    }
}

/** Polls until this worker claims a task for $run. */
function rawPoll(Http $http, string $worker, string $queue, string $run, int $seconds = 30): array
{
    $until = time() + $seconds;
    do {
        [$status, $body] = wcall($http, 'workflow-tasks/poll', ['worker_id' => $worker, 'task_queue' => $queue, 'timeout_seconds' => 2]);
        if ($status === 200 && ($body['task']['run_id'] ?? null) === $run) {
            return $body['task'];
        }
    } while (time() < $until);
    throw new RuntimeException("$worker claimed no task for $run within {$seconds}s");
}

function rawComplete(Http $http, array $task, array $commands): array
{
    return wcall($http, "workflow-tasks/{$task['task_id']}/complete", ['lease_owner' => $task['lease_owner'],
        'workflow_task_attempt' => $task['workflow_task_attempt'], 'commands' => $commands]);
}

function rawDeliver(Http $http, array $task): array
{
    return wcall($http, "workflow-tasks/{$task['task_id']}/deliver-cancellation", ['lease_owner' => $task['lease_owner'],
        'workflow_task_attempt' => $task['workflow_task_attempt'], 'request_id' => $task['cancellation_request']['request_id'] ?? null,
        'sequence' => 1, 'call_kind' => 'timer']);
}

/** A raw opted-in run whose first turn opens a long timer, then its delivered cleanup turn. */
function rawCleanupTurn(Http $http, AvroPayloadCodec $codec, string $vector, string $id, string $queue, string $worker,
    array $options): array
{
    [$status, $body] = start($http, $codec, $id, 'tc.raw', $queue, $options);
    check($vector, 'admitted', $status === 201, [$status, $body]);
    $run = (string) ($body['run_id'] ?? '');
    $first = rawPoll($http, $worker, $queue, $run);
    [$status, $body] = rawComplete($http, $first, [['type' => 'start_timer', 'delay_seconds' => 3600]]);
    check($vector, 'first turn opens the timer', $status === 200, [$status, $body]);
    waitFor($http, $id, $run, 'WorkflowTimeoutCleanupStarted');
    $turn = rawPoll($http, $worker, $queue, $run);
    check($vector, 'cleanup turn carries the engine request', is_string($turn['cancellation_request']['request_id'] ?? null), $turn);
    [$status, $body] = rawDeliver($http, $turn);
    check($vector, 'interruption delivered', $status === 200, [$status, $body]);

    return [$run, $turn];
}

$extended = static function () use ($http, $codec, $suffix): void {
    $id = static fn (string $name): string => "tc-$name-$suffix";
    $short = ['execution_timeout_seconds' => 30, 'timeout_cleanup_seconds' => ALLOWANCE];
    $null = $codec->envelope(null);

    // Task-boundary expiry: the worker holding the cleanup turn completes it
    // after the cleanup deadline. The late completion commits nothing and the
    // run expires at the original deadline.
    rawRegister($http, 'raw-boundary', 'tc-raw-boundary', true);
    $wid = $id('boundary');
    [$run, $turn] = rawCleanupTurn($http, $codec, 'boundary', $wid, 'tc-raw-boundary', 'raw-boundary',
        ['execution_timeout_seconds' => 20, 'timeout_cleanup_seconds' => 8]);
    $entered = payload(history($http, $wid, $run), 'WorkflowTimeoutCleanupStarted');
    while (microtime(true) < at($entered['cleanup_deadline_at']) + 3) {
        usleep(250_000);
    }
    [$status, $body] = rawComplete($http, $turn, [['type' => 'complete_workflow', 'result' => $null]]);
    check('boundary', 'late completion after the cleanup deadline is refused', $status >= 400 && $status < 500, [$status, $body]);
    $final = closed($http, $wid);
    $history = history($http, $wid, $final);
    check('boundary', 'no WorkflowCompleted', events($history, 'WorkflowCompleted') === []);
    $closed = payload($history, 'WorkflowTimedOut');
    check('boundary', 'WorkflowTimedOut with outcome expired at the original deadline', ($closed['timeout_cleanup']['outcome'] ?? null) === 'expired'
        && ($closed['timeout_cleanup']['cleanup_deadline_at'] ?? null) === $entered['cleanup_deadline_at'], $closed);
    check('boundary', 'cleanup entered exactly once', count(events($history, 'WorkflowTimeoutCleanupStarted')) === 1);

    // Replay-blocking precedence: a nondeterministic replay failure on the
    // cleanup turn stays blocked for repair; it is never failed cleanup. The
    // hard deadline still expires the run.
    rawRegister($http, 'raw-blocked', 'tc-raw-blocked', true);
    $wid = $id('blocked');
    [$run, $turn] = rawCleanupTurn($http, $codec, 'blocked', $wid, 'tc-raw-blocked', 'raw-blocked',
        ['execution_timeout_seconds' => 20, 'timeout_cleanup_seconds' => 8]);
    [$status, $body] = wcall($http, "workflow-tasks/{$turn['task_id']}/fail", ['lease_owner' => $turn['lease_owner'],
        'workflow_task_attempt' => $turn['workflow_task_attempt'],
        'failure' => ['type' => 'NondeterministicReplayException', 'message' => 'nondeterministic replay of cleanup']]);
    check('blocked', 'replay-blocking task failure accepted', $status >= 200 && $status < 300, [$status, $body]);
    $history = history($http, $wid, $run);
    check('blocked', 'no terminal event and no failed cleanup', events($history, 'WorkflowTimedOut') === []
        && events($history, 'WorkflowFailed') === [], array_column($history, 'event_type'));
    $described = describe($http, $wid);
    check('blocked', 'describe still reports cleanup running', ($described['timeout_cleanup']['outcome'] ?? null) === 'running'
        && ($described['status'] ?? null) !== 'failed', array_intersect_key($described, ['status' => 1, 'timeout_cleanup' => 1]));
    $final = closed($http, $wid);
    $closed = payload(history($http, $wid, $final), 'WorkflowTimedOut');
    check('blocked', 'the hard deadline expires it', ($closed['timeout_cleanup']['outcome'] ?? null) === 'expired'
        && !isset($closed['timeout_cleanup']['failure_id']), $closed);

    // Malformed markers are refused before any write; a valid one completes.
    rawRegister($http, 'raw-marker', 'tc-raw-marker', true);
    $wid = $id('marker');
    [$run, $turn] = rawCleanupTurn($http, $codec, 'marker', $wid, 'tc-raw-marker', 'raw-marker', $short);
    $before = count(history($http, $wid, $run));
    foreach (['unknown string' => 'cancellation', 'number' => 1, 'boolean' => true, 'object' => ['kind' => 'timeout_cleanup']] as $case => $marker) {
        [$status, $body] = rawComplete($http, $turn, [['type' => 'fail_workflow', 'message' => 'interrupted',
            'exception_type' => 'TimeoutCleanupInterruption', 'failure' => ['interruption' => $marker]]]);
        check('marker', "malformed marker ($case) refused with 422 invalid_workflow_failure_interruption",
            $status === 422 && ($body['reason'] ?? null) === 'invalid_workflow_failure_interruption', [$status, $body]);
    }
    check('marker', 'refusals wrote nothing', count(history($http, $wid, $run)) === $before);
    [$status, $body] = rawComplete($http, $turn, [['type' => 'fail_workflow', 'message' => 'interrupted',
        'exception_type' => 'TimeoutCleanupInterruption', 'failure' => ['interruption' => 'timeout_cleanup']]]);
    check('marker', 'the valid marker is accepted on the same turn', $status === 200, [$status, $body]);
    $closed = payload(history($http, $wid, closed($http, $wid)), 'WorkflowTimedOut');
    check('marker', 'WorkflowTimedOut with outcome completed', ($closed['timeout_cleanup']['outcome'] ?? null) === 'completed', $closed);

    // Request normalization (Laravel's TrimStrings and ConvertEmptyStringsToNull,
    // which native applies to every request) turns an empty marker into an
    // absent one: an ordinary failure, recorded as failed cleanup, never a
    // completed one.
    rawRegister($http, 'raw-empty', 'tc-raw-empty', true);
    $wid = $id('marker-empty');
    [$run, $turn] = rawCleanupTurn($http, $codec, 'marker-empty', $wid, 'tc-raw-empty', 'raw-empty', $short);
    [$status, $body] = rawComplete($http, $turn, [['type' => 'fail_workflow', 'message' => 'interrupted',
        'exception_type' => 'TimeoutCleanupInterruption', 'failure' => ['interruption' => '']]]);
    check('marker-empty', 'an empty marker is normalized to none and accepted as an ordinary failure', $status === 200, [$status, $body]);
    $closed = payload(history($http, $wid, closed($http, $wid)), 'WorkflowTimedOut');
    check('marker-empty', 'recorded as failed cleanup, not completed', ($closed['timeout_cleanup']['outcome'] ?? null) === 'failed', $closed);

    // A worker that did not negotiate timeout_cleanup cannot send the marker,
    // even on a queue where a capable worker made the start admissible.
    rawRegister($http, 'raw-capable', 'tc-raw-mixed', true);
    rawRegister($http, 'raw-plain', 'tc-raw-mixed', false);
    $wid = $id('mixed');
    [$run, $turn] = rawCleanupTurn($http, $codec, 'mixed', $wid, 'tc-raw-mixed', 'raw-plain', $short);
    $before = count(history($http, $wid, $run));
    [$status, $body] = rawComplete($http, $turn, [['type' => 'fail_workflow', 'message' => 'interrupted',
        'exception_type' => 'TimeoutCleanupInterruption', 'failure' => ['interruption' => 'timeout_cleanup']]]);
    check('mixed', 'marker from a non-capable worker refused with 409 timeout_cleanup_not_supported',
        $status === 409 && ($body['reason'] ?? null) === 'timeout_cleanup_not_supported', [$status, $body]);
    check('mixed', 'the refusal wrote nothing', count(history($http, $wid, $run)) === $before);
    [$status, $body] = rawComplete($http, $turn, [['type' => 'fail_workflow', 'message' => 'interrupted',
        'exception_type' => 'TimeoutCleanupInterruption']]);
    check('mixed', 'the unmarked failure is accepted', $status === 200, [$status, $body]);
    $closed = payload(history($http, $wid, closed($http, $wid)), 'WorkflowTimedOut');
    check('mixed', 'recorded as failed cleanup with failure_id', ($closed['timeout_cleanup']['outcome'] ?? null) === 'failed'
        && is_string($closed['timeout_cleanup']['failure_id'] ?? null), $closed);

    // Redrive inheritance: an opted-in run that fails at an activity step before
    // its work deadline is redriven; the successor keeps the allowance and the
    // execution clock, and its run work deadline comes from its own run clock.
    // (A timed-out run is final: redrive refuses it, in native and embedded.)
    if (getenv('TC_WORKER_SDK') === 'php') {
        $wid = $id('redrive');
        [$status, $body] = start($http, $codec, $wid, 'tc.redrive', 'timeout-cleanup',
            ['execution_timeout_seconds' => 300, 'run_timeout_seconds' => 120, 'timeout_cleanup_seconds' => ALLOWANCE]);
        check('redrive', 'admitted', $status === 201, [$status, $body]);
        $source = (string) ($body['run_id'] ?? '');
        closed($http, $wid);
        $sourceHistory = history($http, $wid, $source);
        $sourceStarted = payload($sourceHistory, 'WorkflowStarted');
        check('redrive', 'the source failed at its activity step without entering cleanup',
            (payload($sourceHistory, 'WorkflowFailed')['failed_step_kind'] ?? null) === 'activity'
            && events($sourceHistory, 'WorkflowTimeoutCleanupStarted') === [], array_column($sourceHistory, 'event_type'));
        [$status, $body] = call($http, 'POST', "/api/workflows/$wid/runs/$source/redrive", []);
        check('redrive', 'the failed opted-in run is redriven', $status >= 200 && $status < 300, [$status, $body]);
        $successor = (string) ($body['run_id'] ?? '');
        $started = $successor === '' ? [] : payload(history($http, $wid, $successor), 'WorkflowStarted');
        check('redrive', 'successor keeps the allowance, the execution work deadline and the execution hard cap',
            ($started['recovery_kind'] ?? null) === 'redrive'
            && ($started['timeout_cleanup']['allowance_seconds'] ?? null) === ALLOWANCE
            && ($started['timeout_cleanup']['work_deadlines']['execution'] ?? null) === ($sourceStarted['timeout_cleanup']['work_deadlines']['execution'] ?? false)
            && same($started['execution_deadline_at'] ?? 'now', $sourceStarted['execution_deadline_at'] ?? 'then'), [$sourceStarted, $started]);
        check('redrive', 'successor run work deadline = its own run deadline - allowance, later than the source',
            same($started['timeout_cleanup']['work_deadlines']['run'] ?? 'now', $started['run_deadline_at'] ?? 'then', ALLOWANCE)
            && at($started['run_deadline_at'] ?? '2000-01-01') > at($sourceStarted['run_deadline_at'] ?? 'now'), [$sourceStarted, $started]);
        closed($http, $wid);
    }

    // Scoped descendants: a child the parent's cleanup cancels sees an
    // ordinary cancellation lineage under the root request, never the cause.
    if (getenv('TC_WORKER_SDK') === 'php') {
        $wid = $id('descendant');
        [$status, $body] = start($http, $codec, $wid, 'tc.parent', 'timeout-cleanup', $short);
        check('descendant', 'admitted', $status === 201, [$status, $body]);
        $parent = (string) ($body['run_id'] ?? '');
        [$history, $entered] = timedOut($http, 'descendant', $wid, closed($http, $wid), 'completed', 'execution');
        $child = events($history, 'ChildRunStarted')[0]['payload'] ?? [];
        $childId = (string) ($child['child_workflow_instance_id'] ?? '');
        $childRun = (string) ($child['child_workflow_run_id'] ?? '');
        check('descendant', 'the parent started a child', $childId !== '' && $childRun !== '', $child);
        $until = time() + 60;
        while (observations($wid.'-child') === [] && time() < $until) {
            sleep(1);
        }
        $childHistory = history($http, $childId, $childRun);
        $requested = events($childHistory, 'CooperativeCancellationRequested');
        $context = $requested[0]['payload']['cancellation'] ?? [];
        check('descendant', "the child's request has no cause and names the parent's timeout request as root and parent",
            count($requested) === 1 && !array_key_exists('cause', $context)
            && ($context['root_request_id'] ?? null) === ($entered['workflow_command_id'] ?? false)
            && ($context['parent_request_id'] ?? null) === ($entered['workflow_command_id'] ?? false), [$context, $entered]);
        check('descendant', 'the child never enters timeout cleanup', events($childHistory, 'WorkflowTimeoutCleanupStarted') === []);
        $seen = observations($wid.'-child');
        check('descendant', 'child workflow code saw an ordinary cancellation', count($seen) === 1
            && $seen[0]['timeout_subtype'] === false && $seen[0]['cause'] === null, $seen);
    }
};

$lateStart = static function () use ($http, $codec, $suffix, $state): void {
    $id = "tc-v5-$suffix";
    // A wide window: run.sh pauses the Server as soon as this returns, which
    // can take a while on a loaded Docker host, and resumes it at the printed
    // absolute time, five seconds after the work deadline. The first claim or
    // idle wake after that notices the deadline late.
    [$status, $body] = start($http, $codec, $id, 'tc.return', 'timeout-cleanup', ['execution_timeout_seconds' => 180, 'timeout_cleanup_seconds' => 90]);
    check('5', 'admitted', $status === 201, [$status, $body]);
    $started = payload(history($http, $id, (string) $body['run_id']), 'WorkflowStarted');
    file_put_contents($state('late'), json_encode(['id' => $id, 'run' => $body['run_id'], 'started' => $started]));
    fwrite(STDOUT, (string) ((int) ceil(at($started['timeout_cleanup']['work_deadlines']['execution'])) + 5)."\n");
};

$lateCheck = static function () use ($http, $state): void {
    $late = json_decode(file_get_contents($state('late')), true);
    // The Server can run from the moment the unpause starts; unpaused-at is
    // only written once the docker CLI returns, which can be seconds later.
    $resuming = (float) trim((string) file_get_contents(EVIDENCE.'/unpausing-at'));
    $resumed = (float) trim((string) file_get_contents(EVIDENCE.'/unpaused-at'));
    $paused = (float) trim((string) file_get_contents(EVIDENCE.'/paused-at'));
    $work = at($late['started']['timeout_cleanup']['work_deadlines']['execution']);
    check('5', 'harness paused the Server before the work deadline', $paused < $work, ['paused' => $paused, 'work' => $work]);
    $final = closed($http, $late['id']);
    [, $started] = timedOut($http, '5', $late['id'], $final, 'completed', 'execution');
    check('5', 'detected late (after the Server resumed, past the work deadline)', at($started['detected_at']) >= $resuming - 1
        && at($started['detected_at']) > $work + 3, ['detected_at' => $started['detected_at'], 'work' => $work, 'resuming' => $resuming, 'resumed' => $resumed]);
    check('5', 'work deadline and cleanup deadline come from admission, not detection',
        at($started['work_deadline_at']) === $work && same($started['cleanup_deadline_at'], $late['started']['execution_deadline_at']), $started);
};

$replaceStart = static function () use ($http, $codec, $suffix, $state): void {
    $id = "tc-v6-$suffix";
    // Vector 5 has just paused the Server for over a minute, so the doomed
    // worker's heartbeats lapsed and it is still backing off. Admission needs
    // a live cooperative worker on tc-replace; wait for it to come back
    // instead of racing its next heartbeat. A rejected start creates nothing.
    $deadline = microtime(true) + 45;
    $attempts = 0;
    do {
        $attempts++;
        [$status, $body] = start($http, $codec, $id, 'tc.return', 'tc-replace', ['execution_timeout_seconds' => 80, 'timeout_cleanup_seconds' => 40]);
        $waiting = $status === 422 && ($body['reason'] ?? null) === 'timeout_cleanup_not_supported';
    } while ($waiting && microtime(true) < $deadline && sleep(1) === 0);
    check('6', 'admitted', $status === 201, [$status, $body, 'attempts' => $attempts]);
    $history = waitFor($http, $id, (string) $body['run_id'], 'WorkflowTimeoutCleanupStarted');
    file_put_contents($state('replace'), json_encode(['id' => $id, 'run' => $body['run_id'],
        'entered' => payload($history, 'WorkflowTimeoutCleanupStarted')]));
};

$replaceCheck = static function () use ($http, $state): void {
    $replace = json_decode(file_get_contents($state('replace')), true);
    $final = closed($http, $replace['id']);
    [$history, $started] = timedOut($http, '6', $replace['id'], $final, 'completed', 'execution');
    check('6', 'replacement kept the original entry and deadlines', $started === $replace['entered'], [$started, $replace['entered']]);
    $seen = observations($replace['id']);
    check('6', 'only the replacement process ran cleanup to the activity', count($seen) === 1, $seen);
};

try {
    match ($phase) {
        'main' => $main(),
        'late-start' => $lateStart(),
        'late-check' => $lateCheck(),
        'replace-start' => $replaceStart(),
        'replace-check' => $replaceCheck(),
        'extended' => $extended(),
    };
} catch (Throwable $error) {
    check($phase, 'phase completed', false, $error->getMessage());
}
exit($failures === 0 ? 0 : 1);
