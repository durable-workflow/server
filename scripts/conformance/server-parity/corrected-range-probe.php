<?php

declare(strict_types=1);

require_once __DIR__.'/corrected-marker-probe.php';
require_once __DIR__.'/range-repair-probe.php';

function correctedRangeCheckpoint(\DurableWorkflow\Client $client, string $url, array $options, string $runId): array
{
    $run = $client->describeWorkflow($options['workflow-id'], $runId);
    $response = (new \GuzzleHttp\Client(['http_errors' => false]))->get($url.'/api/workflows/'
        .rawurlencode($options['workflow-id']).'/runs/'.rawurlencode($runId).'/debug', [
        'headers' => ['Authorization' => 'Bearer '.(getenv('DW_PARITY_TOKEN') ?: ''),
            'X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2'],
    ]);

    return ['workflow_id' => $options['workflow-id'], 'run_id' => $runId,
        'workflow_type' => $run->workflowType, 'namespace' => $run->namespace, 'task_queue' => $run->taskQueue,
        'status' => $run->status, 'payload_codec' => $run->raw['payload_codec'], 'execution' => $run->raw,
        'input' => $run->input, 'output' => $run->output,
        'typed_input' => typedValue($run->input), 'typed_output' => typedValue($run->output),
        'events' => rangeDecodeHistory(httpHistory($client, $options['workflow-id'], $runId), $client->payloadCodec()->decodeEnvelope(...)),
        'debug_status' => $response->getStatusCode(), 'debug' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
}

function correctedRangeHttpObservation(array $fixture, array $options): array
{
    $spec = $fixture['corrected_range'];
    $directory = getenv('DW_PARITY_FAILURE_EVIDENCE_DIR');
    $legacyRoot = getenv('DW_PARITY_CORRECTED_RANGE_PHP_ROOT');
    if (! is_string($directory) || ! is_dir($directory)
        || ! is_string($legacyRoot) || ! is_file($legacyRoot.'/vendor/autoload.php')) {
        throw new RuntimeException('Range evidence directory and normal historical PHP producer are required');
    }
    $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $options['workflow-id']);
    $journal = $directory.'/'.$name.'-http.jsonl';
    $proxy = proc_open(['node', __DIR__.'/published-sdk-patch/observe-http.mjs', $options['url'], $journal, '--forwarding-state'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $directory.'/'.$name.'-observer.stderr', 'w']], $pipes);
    if (! is_resource($proxy)) {
        throw new RuntimeException('Could not start the original-byte range observer');
    }
    fclose($pipes[0]);
    $phases = [];
    $neighbors = [];
    $runId = '';
    // This new language direction retains the existing complete 35-second recipe.
    $deadline = microtime(true) + $spec['overall_budget_seconds'];
    try {
        $url = trim(fgets($pipes[1]));
        if (! preg_match('#^http://127\.0\.0\.1:[0-9]+$#', $url)) {
            throw new RuntimeException('Range observer did not bind its local port');
        }
        $client = new \DurableWorkflow\Client($url, namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null);
        foreach (['left', 'right'] as $side) {
            $neighborOptions = ['workflow-id' => $options['workflow-id'].'-neighbor-'.$side];
            $neighborRun = $client->startWorkflow($fixture['workflow_type'], $neighborOptions['workflow-id'],
                $neighborOptions['workflow-id'].'-queue', [$fixture['input']])->selectedRunId;
            $neighbors[$side] = ['before' => correctedRangeCheckpoint($client, $url, $neighborOptions, $neighborRun)];
        }
        $original = correctedMarkerPhase(['env', 'DW_PARITY_SDK_AUTOLOAD='.$legacyRoot.'/vendor/autoload.php',
            PHP_BINARY, __DIR__.'/probe.php', '--mode', 'http', '--url', $url,
            '--fixture', dirname(__DIR__, 3).'/'.$spec['original_fixture'],
            '--workflow-id', $options['workflow-id'], '--range-phase', 'original', '--range-deadline', (string) $deadline],
            $directory, $name, 'original', $deadline);
        $runId = $original['run_id'];
        $phases['original'] = correctedRangeCheckpoint($client, $url, $options, $runId) + ['actor' => $original];
        $scheduled = array_values(array_filter($phases['original']['events'], static fn (array $event): bool => $event['event_type'] === 'ActivityScheduled'));
        if (count($scheduled) !== 1) {
            throw new RuntimeException('Original range producer did not schedule exactly one activity');
        }
        $policy = $scheduled[0]['payload']['activity']['retry_policy'] ?? $scheduled[0]['payload']['retry_policy'] ?? null;
        if (! is_array($policy)) {
            throw new RuntimeException('Original recorded retry snapshot is unavailable');
        }
        file_put_contents($directory.'/'.$name.'-original-snapshot.json', json_encode($phases['original'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
        $order = $spec['pending_activity']
            ? ['incompatible', 'repair', 'repaired-wait', 'activity', 'final']
            : ['activity', 'incompatible', 'repair', 'final'];
        foreach ($order as $phase) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Original 35-second range scenario budget expired');
            }
            if ($phase === 'repair') {
                $actor = ['role' => 'operator', 'repair' => $client->repairWorkflow($options['workflow-id'], $runId)];
            } else {
                $profile = match ($phase) { 'incompatible' => 'excluding', 'repaired-wait' => 'repaired-wait', default => 'restored' };
                $actor = correctedMarkerPhase([getenv('DW_PARITY_CORRECTED_RUST_WORKER') ?: '/sdk-adapters/rust-worker',
                    $url, $phase === 'activity' ? 'activity' : 'workflow', $options['workflow-id'], $runId,
                    $spec['pending_activity'] ? 'range_pending' : 'range_completed', $profile,
                    json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    json_encode($fixture['input'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
                    $directory, $name, $phase, $deadline);
            }
            $phases[$phase] = correctedRangeCheckpoint($client, $url, $options, $runId) + ['actor' => $actor];
            file_put_contents($directory.'/'.$name.'-'.$phase.'-snapshot.json', json_encode($phases[$phase], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
        }
        $final = $phases['final'];
        foreach ($neighbors as &$neighbor) {
            $neighbor['after'] = correctedRangeCheckpoint($client, $url,
                ['workflow-id' => $neighbor['before']['workflow_id']], $neighbor['before']['run_id']);
        }
        unset($neighbor);
        $final['corrected_range'] = ['phases' => $phases, 'recorded_policy' => $policy,
            'neighbors' => $neighbors,
            'overall_budget_seconds' => $spec['overall_budget_seconds'], 'requests' => array_map(
                static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))];

        return $final;
    } finally {
        fclose($pipes[1]);
        proc_terminate($proxy, 15);
        proc_close($proxy);
    }
}

function correctedRangeEmbeddedObservation(array $fixture, array $options): array
{
    // Execute the same durable boundary with embedded PHP author definitions;
    // do not represent a PHP CLI executor as a Rust worker.
    $legacyOptions = $options;
    $legacyOptions['fixture'] = dirname(__DIR__, 3).'/'.$fixture['corrected_range']['original_fixture'];
    $legacyFixture = json_decode(file_get_contents($legacyOptions['fixture']), true, flags: JSON_THROW_ON_ERROR);
    $observation = rangeRepairObservation($legacyFixture, $legacyOptions);
    $observation['corrected_range'] = ['applicable' => false, 'reason' => 'embedded_executes_original_php_definitions',
        'overall_budget_seconds' => $fixture['corrected_range']['overall_budget_seconds']];

    return $observation;
}
