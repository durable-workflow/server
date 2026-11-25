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
    $resumed = (float) trim((string) file_get_contents(EVIDENCE.'/unpaused-at'));
    $paused = (float) trim((string) file_get_contents(EVIDENCE.'/paused-at'));
    $work = at($late['started']['timeout_cleanup']['work_deadlines']['execution']);
    check('5', 'harness paused the Server before the work deadline', $paused < $work, ['paused' => $paused, 'work' => $work]);
    $final = closed($http, $late['id']);
    [, $started] = timedOut($http, '5', $late['id'], $final, 'completed', 'execution');
    check('5', 'detected late (after the Server resumed, past the work deadline)', at($started['detected_at']) >= $resumed - 1
        && at($started['detected_at']) > $work + 3, ['detected_at' => $started['detected_at'], 'work' => $work, 'resumed' => $resumed]);
    check('5', 'work deadline and cleanup deadline come from admission, not detection',
        at($started['work_deadline_at']) === $work && same($started['cleanup_deadline_at'], $late['started']['execution_deadline_at']), $started);
};

$replaceStart = static function () use ($http, $codec, $suffix, $state): void {
    $id = "tc-v6-$suffix";
    [$status, $body] = start($http, $codec, $id, 'tc.return', 'tc-replace', ['execution_timeout_seconds' => 80, 'timeout_cleanup_seconds' => 40]);
    check('6', 'admitted', $status === 201, [$status, $body]);
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
    };
} catch (Throwable $error) {
    check($phase, 'phase completed', false, $error->getMessage());
}
exit($failures === 0 ? 0 : 1);
