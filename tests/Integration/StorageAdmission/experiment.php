<?php

declare(strict_types=1);
use App\Support\WorkerProtocol;
use Workflow\Serializers\Serializer;

require '/app/vendor/autoload.php';

// Disposable Server/MySQL contract test, not a production storage collector.
const MIB = 1048576;
const WORKER = 'storage-worker';
const QUEUE = 'storage-test';

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function database(): PDO
{
    $db = new PDO('mysql:host=mysql;dbname=storage_admission', 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    check((int) $db->query('SELECT @@innodb_file_per_table')->fetchColumn() === 0, 'Fixture requires system-tablespace allocation.');

    return $db;
}

function freeBytes(PDO $db): int
{
    $setting = (string) $db->query('SELECT @@innodb_data_file_path')->fetchColumn();
    check(preg_match('/^ibdata1:12M:autoextend:max:(\d+)M$/', $setting, $match) === 1, 'Not a capacity-limited fixture database.');
    $row = $db->query("SELECT TOTAL_EXTENTS, FREE_EXTENTS, EXTENT_SIZE FROM information_schema.FILES WHERE TABLESPACE_NAME='innodb_system'")->fetch(PDO::FETCH_ASSOC);
    check(is_array($row), 'Native tablespace statistics unavailable.');

    return (int) $match[1] * MIB - ((int) $row['TOTAL_EXTENTS'] - (int) $row['FREE_EXTENTS']) * (int) $row['EXTENT_SIZE'];
}

function observe(string $state): void
{
    $path = '/observation/pressure.json';
    file_put_contents($path.'.tmp', json_encode([
        'schema' => 'durable-workflow.storage-pressure.v1', 'source' => 'bounded-mysql',
        'observed_at' => time(), 'state' => $state,
    ], JSON_THROW_ON_ERROR));
    chmod($path.'.tmp', 0644);
    rename($path.'.tmp', $path);
}

function keepWorkerAlive(string $state): void
{
    static $lastHeartbeat = 0.0;
    observe($state);
    if (microtime(true) - $lastHeartbeat >= 5) {
        call('POST', '/worker/heartbeat', ['worker_id' => WORKER], true);
        $lastHeartbeat = microtime(true);
    }
}

function failureSummary(int $status, array $body): string
{
    return json_encode(['status' => $status] + array_intersect_key($body,
        array_flip(['error', 'message', 'reason', 'task_status', 'lease_expires_at'])), JSON_THROW_ON_ERROR);
}

function requests(array $specifications, ?callable $during = null): array
{
    $multi = curl_multi_init();
    $handles = [];
    foreach ($specifications as [$method, $path, $body, $worker]) {
        $handle = curl_init('http://server:8080/api'.$path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer storage-fixture', 'X-Namespace: default',
                $worker ? WorkerProtocol::HEADER.': '.WorkerProtocol::VERSION : 'X-Durable-Workflow-Control-Plane-Version: 2'],
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        curl_multi_add_handle($multi, $handle);
        $handles[] = $handle;
    }
    do {
        $during?->__invoke();
        $result = curl_multi_exec($multi, $active);
        check($result === CURLM_OK, 'HTTP multiplexer failed.');
        if ($active) {
            curl_multi_select($multi, 0.05);
        }
    } while ($active);
    $responses = [];
    foreach ($handles as $handle) {
        $body = curl_multi_getcontent($handle);
        check(curl_errno($handle) === 0, 'HTTP transport failed: '.curl_error($handle));
        $responses[] = [(int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);

    return $responses;
}

function call(string $method, string $path, ?array $body = null, bool $worker = false, int $status = 200): array
{
    [$actual, $response] = requests([[$method, $path, $body, $worker]])[0];
    check($actual === $status, "$method $path: expected $status, received $actual: ".substr(json_encode($response), 0, 500));

    return $response;
}

function startBody(string $id, int $bytes = 0): array
{
    return ['workflow_id' => $id, 'workflow_type' => 'storage.workflow', 'task_queue' => QUEUE, 'input' => [str_repeat('x', $bytes)]];
}

function completion(array $task): array
{
    return ['POST', '/worker/workflow-tasks/'.$task['task_id'].'/complete', [
        'lease_owner' => WORKER, 'workflow_task_attempt' => $task['workflow_task_attempt'],
        'commands' => [['type' => 'complete_workflow', 'result' => Serializer::serializeWithCodec('avro', str_repeat('result-', 131072))]],
    ], true];
}

function counts(PDO $db): array
{
    $counts = [];
    foreach (['workflow_instances', 'workflow_runs', 'workflow_history_events', 'workflow_tasks', 'jobs'] as $table) {
        $counts[$table] = (int) $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }

    return $counts;
}

if (defined('STORAGE_ADMISSION_HELPERS_ONLY')) {
    return;
}

$phase = $argv[1] ?? '';
try {
    if ($phase === 'normal') {
        observe('normal');
        exit(0);
    }
    $db = database();
    if ($phase === 'pressure') {
        check(! file_exists('/observation/evidence.json'), 'Use a new fixture; do not overwrite previous evidence.');
        observe('normal');
        call('GET', '/ready');
        $manifest = array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'], [
            'supported' => false, 'minimum_protocol_version' => WorkerProtocol::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION,
            'reason' => 'fixture_does_not_execute_affinity_operations',
        ]);
        call('POST', '/worker/register', ['worker_id' => WORKER, 'runtime' => 'php', 'task_queue' => QUEUE,
            'supported_workflow_types' => ['storage.workflow'], 'supported_activity_types' => [],
            'capability_manifest' => $manifest], true, 201);
        $tasks = $runs = [];
        for ($i = 0; $i < 8; $i++) {
            $id = 'leased-'.$i;
            $runs[$id] = call('POST', '/workflows', startBody($id), false, 201)['run_id'];
            $tasks[] = call('POST', '/worker/workflow-tasks/poll', ['worker_id' => WORKER, 'task_queue' => QUEUE,
                'poll_request_id' => 'initial-'.$i, 'timeout_seconds' => 0], true)['task'];
            check(is_array($tasks[$i]), 'Workflow task was not leased.');
        }
        $db->exec('CREATE TABLE storage_filler (id INT AUTO_INCREMENT PRIMARY KEY, body LONGBLOB NOT NULL) ENGINE=InnoDB');
        $insert = $db->prepare('INSERT INTO storage_filler (body) VALUES (?)');
        $filler = random_bytes(MIB);
        while (freeBytes($db) > 128 * MIB) {
            keepWorkerAlive('normal');
            $insert->execute([$filler]);
        }
        $minimum = $initialFree = freeBytes($db);
        echo json_encode(['phase' => 'prefilled', 'free_bytes' => $initialFree], JSON_THROW_ON_ERROR).PHP_EOL;
        $state = 'normal';
        $accepted = $rejected = 0;
        $lastObservation = 0.0;
        $collector = static function () use ($db, &$minimum, &$state, &$lastObservation): void {
            if (microtime(true) - $lastObservation < 0.1) {
                return;
            }
            $free = freeBytes($db);
            $minimum = min($minimum, $free);
            if ($free <= 64 * MIB) {
                $state = 'draining';
            }
            keepWorkerAlive($state);
            $lastObservation = microtime(true);
        };
        for ($wave = 0; $wave < 100 && $rejected < 16; $wave++) {
            $specs = [];
            for ($i = 0; $i < 8; $i++) {
                $specs[] = ['POST', '/workflows', startBody("producer-$wave-$i", 262144), false];
            }
            foreach (requests($specs, $collector) as [$status, $body]) {
                check(in_array($status, [201, 503], true), 'Producer failed outside admission: '.failureSummary($status, $body));
                if ($status === 201) {
                    $accepted++;
                } else {
                    check(($body['reason'] ?? '') === 'storage_pressure' && $body['request_admitted'] === false, 'Unexpected producer refusal.');
                    $rejected++;
                }
            }
        }
        check($state === 'draining' && $accepted > 0 && $rejected >= 16,
            'Did not cross admission boundary with concurrent writers: '.json_encode(compact('state', 'accepted', 'rejected', 'initialFree', 'minimum')));
        echo json_encode(['phase' => 'draining', 'accepted' => $accepted, 'rejected' => $rejected, 'free_bytes' => $minimum], JSON_THROW_ON_ERROR).PHP_EOL;
        observe('draining');
        call('POST', '/worker/heartbeat', ['worker_id' => WORKER], true);
        call('POST', '/worker/workflow-tasks/poll', ['worker_id' => WORKER, 'task_queue' => QUEUE, 'poll_request_id' => 'paused'], true, 503);
        foreach (requests(array_map(completion(...), $tasks), $collector) as [$status, $body]) {
            check($status === 200 && ($body['recorded'] ?? false), 'Outstanding completion failed: '.failureSummary($status, $body));
        }
        $minimum = min($minimum, freeBytes($db));
        check($minimum > 0, 'Drain exhausted database headroom.');
        observe('fenced');
        $before = counts($db);
        call('POST', '/workflows', startBody('must-not-start'), false, 503);
        check(counts($db) === $before, 'Fenced producer mutated durable state.');
        $fullError = null;
        for ($i = 0; $i < 256; $i++) {
            try {
                $insert->execute([$filler]);
            } catch (PDOException $error) {
                $fullError = (int) ($error->errorInfo[1] ?? 0);
                break;
            }
        }
        check($fullError === 1114, 'Native write limit did not reject the independent writer.');
        observe('fenced');
        call('POST', '/workflows', startBody('hard-full-rejected'), false, 503);
        foreach ($runs as $id => $run) {
            $body = call('GET', '/workflows/'.$id.'/runs/'.$run);
            check($body['status'] === 'completed', 'Completed run lost at native capacity.');
        }
        $evidence = ['mysql_version' => $db->query('SELECT VERSION()')->fetchColumn(), 'max_mib' => 256,
            'initial_free_bytes' => $initialFree, 'minimum_free_bytes_during_drain' => $minimum,
            'accepted_producers' => $accepted, 'rejected_producers' => $rejected, 'completed_leases' => count($tasks),
            'native_error' => $fullError, 'filler_sha256' => hash('sha256', $filler),
            'filler_count' => (int) $db->query('SELECT COUNT(*) FROM storage_filler')->fetchColumn(),
            'durable_counts' => counts($db), 'runs' => $runs];
        file_put_contents('/observation/evidence.json', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        echo json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
    } elseif ($phase === 'recover') {
        $before = json_decode(file_get_contents('/observation/evidence.json'), true, 512, JSON_THROW_ON_ERROR);
        check(freeBytes($db) > 64 * MIB, 'Increase native capacity before reopening admission.');
        check(counts($db) === $before['durable_counts'], 'Durable rows changed during fenced restart.');
        check((int) $db->query('SELECT COUNT(*) FROM storage_filler')->fetchColumn() === $before['filler_count'], 'Filler rows were removed.');
        check(hash_equals($before['filler_sha256'], hash('sha256', $db->query('SELECT body FROM storage_filler WHERE id=1')->fetchColumn())), 'Original data changed.');
        observe('normal');
        foreach ($before['runs'] as $id => $run) {
            $body = call('GET', '/workflows/'.$id.'/runs/'.$run);
            check($body['status'] === 'completed' && ($body['output'] ?? null) === str_repeat('result-', 131072), 'Recovered result changed.');
        }
        call('POST', '/workflows', startBody('after-growth'), false, 201);
        echo json_encode(['recovered' => true, 'original_runs' => count($before['runs']), 'free_bytes' => freeBytes($db), 'new_start' => 'accepted'], JSON_THROW_ON_ERROR).PHP_EOL;
    } else {
        throw new RuntimeException('Expected normal, pressure, or recover.');
    }
} catch (Throwable $error) {
    observe('fenced');
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
