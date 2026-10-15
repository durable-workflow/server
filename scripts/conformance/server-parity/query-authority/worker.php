<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Exception\QueryFailed;
use DurableWorkflow\Transport\BoundedPayloadTransport;
use DurableWorkflow\Transport\BoundedPayloadUploadTransport;
use DurableWorkflow\Transport\BoundedProgressPayloadTransport;
use DurableWorkflow\Transport\BoundedTransport;
use DurableWorkflow\Transport\Psr18Transport;
use DurableWorkflow\Transport\Transport;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\QueryContext;
use DurableWorkflow\Worker\WorkflowContext;

require getenv('DW_PARITY_SDK_AUTOLOAD');

function queryCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Hold an actual reply after the normal installed transport received it. */
final class QueryAuthorityTransport implements Transport, BoundedTransport,
    BoundedPayloadTransport, BoundedPayloadUploadTransport, BoundedProgressPayloadTransport
{
    private readonly Psr18Transport $inner;

    public function __construct(private readonly string $mode, private readonly Closure $record)
    {
        $this->inner = new Psr18Transport();
    }

    public function supportsBoundedRequests(): bool { return $this->inner->supportsBoundedRequests(); }
    public function send(string $method, string $uri, array $headers, ?array $body = null): ?array
    {
        $this->beforeSend($method, $uri, $body);
        return $this->observe($uri, $this->inner->send($method, $uri, $headers, $body));
    }
    public function sendBounded(string $method, string $uri, array $headers, ?array $body, int $timeoutSeconds): ?array
    {
        $this->beforeSend($method, $uri, $body);
        return $this->observe($uri, $this->inner->sendBounded($method, $uri, $headers, $body, $timeoutSeconds));
    }
    public function uploadPayload(string $uri, array $headers, string $blob, int $timeoutSeconds): array
    { return $this->inner->uploadPayload($uri, $headers, $blob, $timeoutSeconds); }
    public function uploadPayloadBounded(string $uri, array $headers, string $blob, int $timeoutSeconds): array
    { return $this->inner->uploadPayloadBounded($uri, $headers, $blob, $timeoutSeconds); }
    public function fetchPayload(string $uri, array $headers, int $maxBytes): string
    { return $this->inner->fetchPayload($uri, $headers, $maxBytes); }
    public function fetchPayloadBounded(string $uri, array $headers, int $maxBytes, int $timeoutSeconds): string
    { return $this->inner->fetchPayloadBounded($uri, $headers, $maxBytes, $timeoutSeconds); }
    public function fetchPayloadWithProgress(string $uri, array $headers, int $maxBytes, Closure $progress): string
    { return $this->inner->fetchPayloadWithProgress($uri, $headers, $maxBytes, $progress); }
    public function fetchPayloadWithBoundedProgress(string $uri, array $headers, int $maxBytes, Closure $progress, ?int $timeoutSeconds = null): string
    { return $this->inner->fetchPayloadWithBoundedProgress($uri, $headers, $maxBytes, $progress, $timeoutSeconds); }

    private function beforeSend(string $method, string $uri, ?array $body): void
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if ($method === 'POST' && preg_match('~/query-tasks/[^/]+/(complete|fail)$~', $path)) {
            // A rejected late POST is still a submission. Observe before I/O,
            // rather than counting successful acknowledgements alone.
            ($this->record)('submission', ['path' => $path,
                'lease_owner' => $body['lease_owner'] ?? null,
                'query_task_attempt' => $body['query_task_attempt'] ?? null]);
        }
    }

    private function observe(string $uri, ?array $reply): ?array
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if ($path === '/api/worker/query-tasks/poll' && is_array($reply['task'] ?? null)) {
            ($this->record)('claim', $reply['task']);
            if ($this->mode === 'late-poll') {
                sleep(6);
            }
        } elseif (str_contains($path, '/query-tasks/') && str_ends_with($path, '/status')) {
            ($this->record)('status', $reply ?? []);
        } elseif (preg_match('~/query-tasks/[^/]+/(complete|fail)$~', $path)) {
            ($this->record)('acknowledgement', $reply ?? []);
        }

        return $reply;
    }
}

[$script, $url, $mode, $protocol] = $argv;
queryCheck(in_array([$mode, $protocol], [
    ['timely', '1.20'], ['timely', '1.19'], ['late-poll', '1.20'], ['late-handler', '1.20'], ['timely-active', '1.20'],
    ['timely-named-active', '1.20'],
], true), 'Unknown query authority case.');
$artifacts = json_decode(file_get_contents(__DIR__.'/../../../../tests/Fixtures/ServerParityProfiles/query-authority/artifacts.json'), true, flags: JSON_THROW_ON_ERROR);
queryCheck(InstalledVersions::getPrettyVersion('durable-workflow/sdk') === $artifacts['sdk_php']['version']
    && InstalledVersions::getReference('durable-workflow/sdk') === $artifacts['sdk_php']['source'], 'The installed SDK is not the separate qualified published tuple.');
foreach ($artifacts['sdk_php']['loaded_sha256'] as $class => $sha256) {
    queryCheck(hash_file('sha256', (new ReflectionClass($class))->getFileName()) === $sha256,
        'Loaded published SDK bytes differ: '.$class);
}
queryCheck(function_exists('pcntl_fork') && function_exists('posix_kill'), 'This real Worker fixture requires Unix CLI.');
$started = hrtime(true) / 1e9;
$until = $started + $artifacts['original_observation_seconds'];
$workflowId = 'parity-query-authority-'.bin2hex(random_bytes(6));
$active = in_array($mode, ['timely-active', 'timely-named-active'], true);
$timely = in_array($mode, ['timely', 'timely-active', 'timely-named-active'], true);
$namespace = $mode === 'timely-active' ? 'default' : $workflowId;
$token = getenv('DW_PARITY_TOKEN') ?: 'query-authority-fixture-token';
$admin = new Client($url, token: $token);
if ($namespace !== 'default') {
    $admin->createNamespace($namespace);
}
$client = new Client($url, namespace: $namespace, token: $token);
$journal = tempnam(sys_get_temp_dir(), 'query-authority-');
$record = static function (string $event, array $details = []) use ($journal): void {
    file_put_contents($journal, json_encode(['event' => $event, 'observed_at' => microtime(true),
        'details' => $details], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
};
$read = static function () use ($journal): array {
    $file = fopen($journal, 'rb');
    queryCheck(is_resource($file), 'Could not read the original Worker journal.');
    try {
        queryCheck(flock($file, LOCK_SH), 'Could not lock the original Worker journal.');
        $bytes = stream_get_contents($file);
    } finally {
        flock($file, LOCK_UN);
        fclose($file);
    }
    return array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", $bytes), static fn (string $line): bool => $line !== '')));
};
$pid = 0;
$handle = null;
$result = null;
try {
    $pid = pcntl_fork();
    queryCheck($pid >= 0, 'Could not fork the real Worker.');
    if ($pid === 0) {
        try {
            $worker = new Worker(new Client($url, namespace: $namespace, token: $token,
                transport: new QueryAuthorityTransport($mode, $record), workerProtocolVersion: $protocol), $workflowId,
                clock: static function () use ($until): float {
                    queryCheck(hrtime(true) / 1e9 < $until, 'Original Worker observation deadline expired.');
                    return microtime(true);
                }, diagnosticListener: static function (string $event, array $details) use ($record): void {
                    if ($event === 'worker.claim_aborted') {
                        $record('aborted', $details);
                    } elseif ($event === 'worker.registered') {
                        $record('registered', $details);
                    }
                }, requireQueryTaskAuthority: $protocol === '1.20');
            $worker->registerWorkflow('parity.query-authority.v1', static function (WorkflowContext $context) use ($active): int {
                if ($active) {
                    $context->sleep(60);
                }
                return 7;
            });
            $worker->registerQuery('parity.query-authority.v1', 'value', static function (QueryContext $context) use ($mode, $record): array {
                $record('handler');
                if ($mode === 'late-handler') {
                    sleep(6);
                }
                $record('returned');
                return ['long' => 9007199254740993, 'nil' => null, 'text' => 'rangeλ'];
            });
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static fn () => $worker->requestShutdown());
            $worker->run(0);
            $record('stopped');
            exit(0);
        } catch (Throwable $error) {
            $record('actor-error', ['type' => $error::class, 'message' => $error->getMessage()]);
            exit(1);
        }
    }
    do {
        $events = $read();
        queryCheck(!in_array('actor-error', array_column($events, 'event'), true), 'The Worker refused startup: '.json_encode($events));
        if (in_array('registered', array_column($events, 'event'), true)) {
            break;
        }
        usleep(20000);
    } while (hrtime(true) / 1e9 < $until);
    queryCheck(hrtime(true) / 1e9 < $until, 'No Worker registered within the original observation.');
    $handle = $client->startWorkflow('parity.query-authority.v1', $workflowId, $workflowId);
    $expectedStatus = $active ? 'waiting' : 'completed';
    do {
        $status = strtolower((string) $handle->describe()->status);
        if ($status === $expectedStatus) {
            break;
        }
        usleep(20000);
    } while (hrtime(true) / 1e9 < $until);
    queryCheck($status === $expectedStatus, 'The original did not reach its committed '.$expectedStatus.' state.');
    $before = $client->workflowHistory($workflowId, $handle->selectedRunId);
    $expected = ['long' => 9007199254740993, 'nil' => null, 'text' => 'rangeλ'];
    if ($timely) {
        queryCheck($handle->query('value') === $expected, 'The actual typed query result changed.');
        // The independent caller can observe the commit before the Worker
        // receives its acknowledgement. Wait inside the original budget.
        do {
            $events = $read();
            queryCheck(!in_array('actor-error', array_column($events, 'event'), true), 'The Worker refused the original outcome.');
            if (in_array('acknowledgement', array_column($events, 'event'), true)) {
                break;
            }
            usleep(20000);
        } while (hrtime(true) / 1e9 < $until);
    } else {
        try {
            $handle->query('value');
            throw new RuntimeException('An expired original query succeeded.');
        } catch (QueryFailed $error) {
            queryCheck($error->status === 504 && ($error->details['reason'] ?? null) === 'query_worker_execution_timeout',
                'The original caller did not retain its published timeout diagnostic.');
        }
        do {
            if (in_array('aborted', array_column($read(), 'event'), true)) {
                break;
            }
            usleep(20000);
        } while (hrtime(true) / 1e9 < $until);
        queryCheck(in_array('aborted', array_column($read(), 'event'), true), 'The Worker did not refuse its expired original.');
    }
    $events = $read();
    $named = static fn (string $name): array => array_values(array_filter($events, static fn (array $event): bool => $event['event'] === $name));
    queryCheck(count($named('claim')) === 1, 'Expected one actual original query claim.');
    $claim = $named('claim')[0]['details'];
    queryCheck(($claim['workflow_id'] ?? null) === $workflowId && ($claim['run_id'] ?? null) === $handle->selectedRunId
        && ($claim['task_queue'] ?? null) === $workflowId && ($claim['namespace'] ?? $client->namespace) === $namespace
        && ($claim['query_task_attempt'] ?? null) === 1, 'The delivery changed its original run, namespace or attempt.');
    foreach (['query_admitted_at', 'query_deadline_at', 'lease_expires_at', 'server_time'] as $field) {
        queryCheck(is_string($claim[$field] ?? null) && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/D', $claim[$field]) === 1,
            'Query timing is not canonical UTC6: '.$field);
    }
    $admitted = new DateTimeImmutable($claim['query_admitted_at']);
    $deadline = new DateTimeImmutable($claim['query_deadline_at']);
    queryCheck((float) $deadline->format('U.u') - (float) $admitted->format('U.u') === 4.0,
        'The original admission-to-result deadline is not four seconds.');
    queryCheck(new DateTimeImmutable($claim['lease_expires_at']) <= $deadline, 'The lease extends beyond original result authority.');
    foreach ($named('status') as $observation) {
        foreach (['query_task_id', 'query_task_attempt', 'lease_owner', 'query_admitted_at', 'query_deadline_at', 'lease_expires_at'] as $field) {
            queryCheck(($observation['details'][$field] ?? null) === $claim[$field], 'Status changed original query authority: '.$field);
        }
    }
    queryCheck(($named('status') !== []) === ($protocol === '1.20'), 'Protocol status use does not match the declared boundary.');
    queryCheck(count($named('handler')) === ($mode === 'late-poll' ? 0 : 1), 'The callback dispatched outside its original authority.');
    queryCheck(count($named('submission')) === ($timely ? 1 : 0), 'An expired callback attempted a terminal submission.');
    queryCheck(count($named('acknowledgement')) === ($timely ? 1 : 0), 'The original Worker acknowledgement count changed.');
    queryCheck($before === $client->workflowHistory($workflowId, $handle->selectedRunId), 'Query execution changed the original workflow history.');
    queryCheck($named('actor-error') === [] && hrtime(true) / 1e9 < $until, 'The original Worker observation failed.');
    $result = ['outcome' => 'pass', 'mode' => $mode, 'protocol' => $protocol, 'workflow_id' => $workflowId,
        'namespace' => $namespace, 'original_run_status' => $expectedStatus,
        'run_id' => $handle->selectedRunId, 'claim' => $claim, 'observations' => $events,
        'original_budget_seconds' => 30, 'sdk_version' => InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
        'sdk_source' => InstalledVersions::getReference('durable-workflow/sdk'),
        'runner_sha256' => hash_file('sha256', __FILE__),
        'artifacts_sha256' => hash_file('sha256', __DIR__.'/../../../../tests/Fixtures/ServerParityProfiles/query-authority/artifacts.json'),
        'sdk_lock_sha256' => hash_file('sha256', dirname(getenv('DW_PARITY_SDK_AUTOLOAD'), 2).'/composer.lock'),
        'worker_sha256' => hash_file('sha256', (new ReflectionClass(Worker::class))->getFileName()),
        'history_sha256' => hash('sha256', json_encode($before, JSON_THROW_ON_ERROR))];
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['outcome' => 'fail', 'mode' => $mode, 'protocol' => $protocol,
        'workflow_id' => $workflowId, 'namespace' => $namespace, 'run_id' => $handle?->selectedRunId,
        'error' => $error->getMessage(), 'observations' => $read()], JSON_THROW_ON_ERROR)."\n");
    throw $error;
} finally {
    $drained = false;
    if ($pid > 0) {
        posix_kill($pid, SIGTERM);
        do {
            $ended = pcntl_waitpid($pid, $status, WNOHANG);
            if ($ended === $pid) {
                break;
            }
            usleep(20000);
        } while (hrtime(true) / 1e9 < $until);
        if ($ended !== $pid) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
        $drained = pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0;
    }
    // The caller destroys this profile's isolated database. Query authority
    // does not depend on a separate workflow-termination capability.
    unlink($journal);
    queryCheck($pid > 0 && $drained, 'The real Worker did not exit zero and deregister within the original observation.');
}
queryCheck(hrtime(true) / 1e9 < $until, 'Cleanup exceeded the original observation budget.');
echo json_encode($result + ['elapsed_seconds' => hrtime(true) / 1e9 - $started, 'worker_exit' => 0],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
