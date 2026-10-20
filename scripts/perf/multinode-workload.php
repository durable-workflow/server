<?php

declare(strict_types=1);

// Offered one-activity workflows through a load balancer while server nodes
// are killed and restarted. A request a dying node never answered is retried
// under the same workflow ID, so no start is lost and none is duplicated.
// Afterwards every workflow must have completed once, with its original
// output and an ordered history: one completion, and an activity completed
// by the last attempt that started it.
//
//   multinode-workload.php <0.1-5 starts/sec> <5-600 offer seconds> <10-600 drain seconds> <workflow-id prefix>
use Composer\InstalledVersions;
use DurableWorkflow\Exception\ServerException;

require_once dirname(__DIR__, 2).'/benchmarks/capacity/v1/bindings/php/capacity_adapter.php';
capacityAutoload();

// A completed workflow whose output or history breaks the qualification.
final class MultinodeVerificationFailure extends RuntimeException
{
}

$rate = filter_var($argv[1] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0.1, 'max_range' => 5.0]]);
$duration = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => 600]]);
$drain = filter_var($argv[3] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 10, 'max_range' => 600]]);
$prefix = $argv[4] ?? '';
if ($rate === false || $duration === false || $drain === false || ! preg_match('/\A[a-z0-9-]{1,40}\z/', $prefix)) {
    throw new InvalidArgumentException('Usage: multinode-workload.php <0.1-5 starts/sec> <5-600 offer seconds> <10-600 drain seconds> <prefix>.');
}
$queue = capacityEnvironment('DURABLE_WORKFLOW_TASK_QUEUE');
if ($queue === null) {
    throw new InvalidArgumentException('Set DURABLE_WORKFLOW_TASK_QUEUE.');
}
$client = capacityClient(false);
$blob = str_repeat('m', 1024);
$input = ['blob' => $blob, 'payload_contract' => ['workflow_input_bytes' => 1024, 'workflow_result_bytes' => 1024,
    'activity_input_bytes' => 1024, 'activity_result_bytes' => 1024, 'signal_bytes' => 0]];
$retries = [];
$errors = [];

// Only a server's 4xx answer is final. A transport failure, a 5xx answer, or
// a reply cut off when its node was killed (even under a 2xx status) is
// retried; every retried request is safe to repeat under its workflow ID.
$retrying = static function (string $what, callable $call, float $until) use (&$retries) {
    $delay = 0.2;
    while (true) {
        try {
            return $call();
        } catch (ServerException $error) {
            if ($error->status >= 400 && $error->status < 500 || microtime(true) >= $until) {
                throw $error;
            }
            $class = 'http_'.$error->status;
        } catch (Throwable $error) {
            if (microtime(true) >= $until) {
                throw $error;
            }
            $class = get_class($error);
        }
        $retries[$what][$class] = ($retries[$what][$class] ?? 0) + 1;
        usleep((int) ($delay * 1_000_000));
        $delay = min(2.0, $delay * 2);
    }
};

$planned = (int) ceil($rate * $duration);
$began = microtime(true);
$offerEnds = $began + $duration;
$deadline = $offerEnds + $drain;
$ids = [];
$started = [];
for ($n = 0; $n < $planned; $n++) {
    $due = $began + $n / $rate;
    if (($wait = $due - microtime(true)) > 0) {
        usleep((int) ($wait * 1_000_000));
    }
    $id = sprintf('%s-%04d', $prefix, $n);
    $ids[] = $id;
    try {
        $retrying('start', static function () use ($client, $id, $queue, $input): void {
            try {
                $client->startWorkflow('capacity.v1.one_activity', $id, $queue, [$input]);
            } catch (ServerException $error) {
                // A retried start whose first attempt was accepted finds its own run.
                if ($error->status !== 409) {
                    throw $error;
                }
                $existing = $client->describeWorkflow($id);
                if ($existing->workflowType !== 'capacity.v1.one_activity') {
                    throw $error;
                }
            }
        }, $due + 60);
        $started[$id] = microtime(true);
    } catch (Throwable $error) {
        $errors[] = $id.': start: '.$error->getMessage();
    }
}

$completed = [];
$pending = array_keys($started);
while ($pending !== [] && microtime(true) < $deadline) {
    $id = array_shift($pending);
    try {
        $execution = $retrying('describe', static fn () => $client->describeWorkflow($id), $deadline);
        if ($execution->status === 'completed') {
            $completed[$id] = $execution;
        } elseif ($execution->isTerminal === true) {
            $errors[] = $id.': terminal status '.$execution->status;
        } else {
            $pending[] = $id;
            usleep(20_000);
        }
    } catch (Throwable $error) {
        $pending[] = $id;
    }
}

$verified = 0;
$attemptsByWorkflow = [];
$repairedWorkflows = [];
foreach ($completed as $id => $execution) {
    try {
        if ($execution->output !== $blob) {
            throw new MultinodeVerificationFailure('output differs');
        }
        $history = $retrying('history', static fn () => $client->workflowHistory($id, $execution->runId), microtime(true) + 60);
        $events = $history['events'] ?? [];
        $sequences = array_column($events, 'sequence');
        if ($sequences !== range(1, count($events))) {
            throw new MultinodeVerificationFailure('history sequences are not contiguous');
        }
        $types = array_column($events, 'event_type');
        $counts = array_count_values($types);
        // A worker that dies holding a workflow task lease leaves the task to
        // the server's expired-lease recovery, which records RepairRequested.
        $allowed = ['StartAccepted', 'WorkflowStarted', 'ActivityScheduled', 'ActivityStarted', 'ActivityCompleted',
            'WorkflowCompleted', 'RepairRequested'];
        if (($unexpected = array_diff(array_keys($counts), $allowed)) !== []) {
            throw new MultinodeVerificationFailure('unexpected events '.implode(',', $unexpected));
        }
        foreach (['WorkflowStarted', 'ActivityScheduled', 'ActivityCompleted', 'WorkflowCompleted'] as $once) {
            if (($counts[$once] ?? 0) !== 1) {
                throw new MultinodeVerificationFailure($once.' recorded '.($counts[$once] ?? 0).' times');
            }
        }
        $starts = array_values(array_filter($events, static fn (array $e): bool => $e['event_type'] === 'ActivityStarted'));
        $completion = array_values(array_filter($events, static fn (array $e): bool => $e['event_type'] === 'ActivityCompleted'))[0];
        if ($starts === [] || end($starts)['payload']['activity_attempt_id'] !== $completion['payload']['activity_attempt_id']) {
            throw new MultinodeVerificationFailure('the completed activity attempt is not the last one started');
        }
        $order = array_values(array_intersect($types, ['WorkflowStarted', 'ActivityScheduled', 'ActivityCompleted', 'WorkflowCompleted']));
        if ($order !== ['WorkflowStarted', 'ActivityScheduled', 'ActivityCompleted', 'WorkflowCompleted']) {
            throw new MultinodeVerificationFailure('events out of order');
        }
        $attemptsByWorkflow[count($starts)] = ($attemptsByWorkflow[count($starts)] ?? 0) + 1;
        if (($counts['RepairRequested'] ?? 0) > 0) {
            $repairedWorkflows[$id] = $counts['RepairRequested'];
        }
        $verified++;
    } catch (Throwable $error) {
        $errors[] = $id.': verification: '.$error->getMessage();
    }
}
foreach ($pending as $id) {
    $errors[] = $id.': not completed by the drain deadline';
}

echo json_encode([
    'probe' => 'multinode-offered-one-activity',
    'sdk_php' => InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
    'prefix' => $prefix,
    'offered_rate_per_second' => $rate,
    'offer_seconds' => $duration,
    'drain_limit_seconds' => $drain,
    'started_at_unix_seconds' => $began,
    'finished_at_unix_seconds' => microtime(true),
    'planned_starts' => $planned,
    'accepted_starts' => count($started),
    'completed' => count($completed),
    'verified' => $verified,
    'activity_attempts_per_workflow' => $attemptsByWorkflow,
    'repairs_by_workflow' => (object) $repairedWorkflows,
    'retries' => $retries,
    'errors' => $errors,
    'outcome' => $errors === [] && $verified === $planned ? 'pass' : 'fail',
], JSON_PRETTY_PRINT)."\n";
exit($errors === [] && $verified === $planned ? 0 : 1);
