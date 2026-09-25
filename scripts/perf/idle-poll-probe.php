<?php

declare(strict_types=1);

use DurableWorkflow\Exception\ServerException;

require dirname(__DIR__, 2).'/benchmarks/capacity/v1/bindings/php/capacity_adapter.php';
capacityAutoload();

$count = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 24]]);
$pollSeconds = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2, 'max_range' => 30]]);
if ($count === false || $pollSeconds === false || ! function_exists('pcntl_fork')) {
    throw new InvalidArgumentException('Supply 1-24 polls and a 2-30 second timeout; pcntl is required.');
}

$queue = capacityEnvironment('DURABLE_WORKFLOW_TASK_QUEUE');
if ($queue === null) {
    throw new InvalidArgumentException('Set DURABLE_WORKFLOW_TASK_QUEUE.');
}

$children = [];
for ($index = 0; $index < $count; ++$index) {
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($sockets === false) {
        throw new RuntimeException('Could not create probe socket pair.');
    }
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Could not fork probe process.');
    }
    if ($pid === 0) {
        foreach ($children as $child) {
            fclose($child['socket']);
        }
        fclose($sockets[0]);
        $socket = $sockets[1];
        stream_set_timeout($socket, 35);
        $workerId = 'idle-probe-'.bin2hex(random_bytes(8));
        $registered = false;
        $result = ['index' => $index, 'outcome' => 'error'];
        try {
            $client = capacityClient(true);
            $client->registerWorker($workerId, $queue, ['capacity.v1.one_activity'], [], [], 1, 0);
            $registered = true;
            fwrite($socket, "ready\n");
            if (trim((string) fgets($socket)) !== 'go') {
                $result['outcome'] = 'aborted';
            } else {
                $started = hrtime(true);
                try {
                    $response = $client->pollWorkflowTaskResponse($workerId, $queue, $pollSeconds);
                    $result['outcome'] = isset($response['task']) && is_array($response['task']) ? 'task' : 'empty';
                    $result['poll_status'] = $response['poll_status'] ?? null;
                } catch (ServerException $exception) {
                    $result['outcome'] = $exception->status === 429
                        && $exception->reason === 'long_poll_capacity_exhausted' ? 'backpressure' : 'error';
                    $result['http_status'] = $exception->status;
                    $result['reason'] = $exception->reason;
                }
                $result['poll_elapsed_seconds'] = (hrtime(true) - $started) / 1_000_000_000;
            }
        } catch (Throwable $exception) {
            $result['exception'] = $exception::class;
        } finally {
            if ($registered) {
                try {
                    $client->deregisterWorkerRegistration($workerId);
                } catch (Throwable $exception) {
                    $result['deregister_error'] = $exception::class;
                }
            }
        }
        fwrite($socket, json_encode($result, JSON_THROW_ON_ERROR)."\n");
        fclose($socket);
        exit($result['outcome'] === 'error' ? 1 : 0);
    }
    fclose($sockets[1]);
    stream_set_timeout($sockets[0], 35);
    $children[] = ['pid' => $pid, 'socket' => $sockets[0]];
}

$ready = true;
foreach ($children as $child) {
    if (trim((string) fgets($child['socket'])) !== 'ready') {
        $ready = false;
    }
}
foreach ($children as $child) {
    fwrite($child['socket'], $ready ? "go\n" : "stop\n");
}

$results = [];
foreach ($children as $child) {
    $line = fgets($child['socket']);
    $results[] = is_string($line) ? json_decode($line, true, 512, JSON_THROW_ON_ERROR)
        : ['outcome' => 'error', 'exception' => 'missing child result'];
    fclose($child['socket']);
    pcntl_waitpid($child['pid'], $status);
}
$counts = array_count_values(array_column($results, 'outcome'));
$cleanupErrors = count(array_filter($results, static fn (array $row): bool => isset($row['deregister_error'])));
echo json_encode([
    'probe' => 'idle-workflow-poll-not-capacity',
    'requested_polls' => $count,
    'poll_timeout_seconds' => $pollSeconds,
    'all_registered' => $ready,
    'cleanup_errors' => $cleanupErrors,
    'outcomes' => $counts,
    'results' => $results,
], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
exit($ready && $cleanupErrors === 0 && ! isset($counts['error']) && ! isset($counts['task']) && ! isset($counts['aborted']) ? 0 : 1);
