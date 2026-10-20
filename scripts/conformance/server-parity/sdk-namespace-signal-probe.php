<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;
use Workflow\WorkflowOptions;
require_once __DIR__.'/lock-pressure-middleware.php';

function namespaceSignalRunFixture(array $fixture, bool $peer): array
{
    $pair = $fixture['namespace_signal_pair'];
    unset($fixture['namespace_signal_pair']);
    $fixture['namespace_signal_run'] = $peer ? 'peer' : 'primary';
    if ($peer) {
        $fixture['namespace'] = $pair['peer_namespace'];
        $fixture['signal_value'] = $pair['peer_signal_value'];
        $fixture['typed_signal_value'] = $pair['typed_peer_signal_value'];
    }

    return $fixture;
}

function namespaceSignalRequests(array $lines): array
{
    return array_map(static function (string $line): array {
        $wire = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        foreach (['request', 'response'] as $side) {
            $body = $wire[$side.'_body'];
            $unsent = $side === 'request' && $wire['status'] === 0 && $wire['client_cancelled'] === true
                && $wire['transport_error'] === null && $wire['upstream_request_started'] === false
                && $wire['request_body_complete'] === false;
            $wire[$side] = $body === '' || $unsent ? null : json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        }
        unset($wire['request_body'], $wire['response_body'], $wire['response_bytes_base64']);

        return $wire;
    }, $lines);
}

function namespaceSignalControlPhase(string $phase, string $language, string $url, string $id, string $runId,
    string $fixturePath, float $deadline, string $journal, string $evidence, bool $selectedRun = false): array
{
    $arguments = match ($language) {
        'php' => [PHP_BINARY, __DIR__.'/sdk-directions/'.($selectedRun ? 'selected-run-signals.php' : 'php.php')],
        'python' => ['python3', __DIR__.'/sdk-directions/python.py'],
        'rust' => [$selectedRun
            ? (getenv('DW_PARITY_RUST_SELECTED_RUN_SENDER') ?: '/sdk-directions/rust-selected-run-sender')
            : (getenv('DW_PARITY_RUST_DIRECTION_WORKER') ?: '/sdk-directions/rust-worker')],
        default => throw new RuntimeException('Unknown namespace signal SDK language.'),
    };
    $remaining = $deadline - microtime(true);
    if ($remaining <= 0) {
        throw new RuntimeException('Original namespace run budget expired before control phase.');
    }
    array_push($arguments, $phase, $url, $id, $runId, $fixturePath, (string) $remaining);
    $offset = count(file($journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start actual namespace signal control process.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = $stderr = '';
    $status = null;
    try {
        do {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            $state = proc_get_status($process);
            if (! $state['running']) {
                $status = $state['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                throw new RuntimeException('Actual namespace signal control exceeded the original run budget.');
            }
            usleep(10000);
        } while (true);
    } finally {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);
        $lines = array_slice(file($journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), $offset);
        file_put_contents($evidence, json_encode(['phase' => $phase, 'language' => $language,
            'stdout' => $stdout, 'stderr' => $stderr, 'exit_status' => $status ?? $closed,
            'transport_jsonl' => implode("\n", $lines)."\n"], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
    }
    if (($status ?? $closed) !== 0) {
        throw new RuntimeException('Actual namespace signal control failed; original sidecar retained.');
    }

    $requests = namespaceSignalRequests($lines);
    if ($selectedRun) {
        // Preserve full bytes in the sidecar above. Repeated discovery schemas
        // are not selected-run observations and must not fill the parent heap.
        foreach ($requests as &$request) {
            if ($request['method'] !== 'POST' || ! preg_match('#^/api/workflows/[^/]+/runs/[^/]+/signal/[^/]+$#', $request['path'])) {
                $request['response'] = sdkDirectionResponseSummary($request['response']);
            }
        }
        unset($request);
    }
    return json_decode($stdout, true, flags: JSON_THROW_ON_ERROR) + ['requests' => $requests];
}

function namespaceSignalHttpObservation(array $fixture, array $options): array
{
    $evidence = getenv('DW_PARITY_FAILURE_EVIDENCE_DIR');
    if (! is_string($evidence) || ! is_dir($evidence)) {
        throw new RuntimeException('Namespace signal pair needs its retained evidence directory.');
    }
    $pairStart = microtime(true);
    $state = ['applicable' => true, 'pair_started_at_unix' => $pairStart, 'pair_budget_seconds' => 70,
        'run_budget_seconds' => 35, 'administration' => [], 'initial' => [], 'foreign_signals' => [],
        'foreign_polls' => [], 'timeline' => [], 'run_budgets' => []];
    $ids = [$options['workflow-id'], $options['workflow-id'].':peer'];
    $fixtures = [namespaceSignalRunFixture($fixture, false), namespaceSignalRunFixture($fixture, true)];
    $paths = $fibers = $clients = [];
    $controlBase = $evidence.'/sdk-namespace-signal-'.$ids[0].'-control';
    $journal = $controlBase.'-http.jsonl';
    $arrivals = $controlBase.'-arrivals.jsonl';
    $progress = $controlBase.'-progress.json';
    if (file_put_contents($journal, '') === false || file_put_contents($arrivals, '') === false) {
        throw new RuntimeException('Could not initialize retained namespace control journals.');
    }
    $checkpoint = static function (string $stage, ?string $namespace = null) use ($progress, $ids, $pairStart, &$state): void {
        if (file_put_contents($progress, json_encode([
            'schema' => 'durable-workflow.server-parity-namespace-control-progress/v1',
            'stage' => $stage, 'workflow_id' => $ids[0], 'namespace' => $namespace,
            'at_unix' => microtime(true), 'pair_started_at_unix' => $pairStart,
            'pair_deadline_at_unix' => $pairStart + 70, 'run_budgets' => $state['run_budgets'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n") === false) {
            throw new RuntimeException('Could not checkpoint namespace control setup.');
        }
    };
    $checkpoint('observer_start');
    $proxy = proc_open(['node', __DIR__.'/published-sdk-patch/observe-http.mjs', $options['url'], $journal, '--forwarding-state'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $proxyPipes, null,
        [...getenv(), 'DW_PARITY_HTTP_CAPTURE_NAMESPACE' => '1', 'DW_PARITY_HTTP_REQUEST_ARRIVALS' => $arrivals]);
    if (! is_resource($proxy)) {
        throw new RuntimeException('Could not start namespace control observer.');
    }
    fclose($proxyPipes[0]);
    try {
        $checkpoint('observer_bind');
        $url = trim(fgets($proxyPipes[1]));
        if (! preg_match('#^http://127\.0\.0\.1:[0-9]+$#', $url)) {
            throw new RuntimeException('Namespace control observer did not bind its local port.');
        }
        $checkpoint('client_setup');
        $admin = parityClient($url, namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null);
        foreach ($fixtures as $index => $runFixture) {
            try {
                $checkpoint('namespace_describe', $runFixture['namespace']);
                $namespace = $admin->describeNamespace($runFixture['namespace']);
                $created = false;
            } catch (ServerException $error) {
                if ($error->status !== 404) {
                    throw $error;
                }
                $checkpoint('namespace_create', $runFixture['namespace']);
                $namespace = $admin->createNamespace($runFixture['namespace'], 'Paired SDK signal conformance', 7);
                $created = true;
            }
            $state['administration'][] = ['created' => $created, 'namespace' => $namespace->raw];
            $paths[$index] = tempnam(sys_get_temp_dir(), 'parity-namespace-fixture-');
            file_put_contents($paths[$index], json_encode($runFixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $clients[$index] = parityClient($options['url'], namespace: $runFixture['namespace'], token: getenv('DW_PARITY_TOKEN') ?: null);
            $start = microtime(true);
            $deadline = $start + 35;
            $state['run_budgets'][$index] = ['started_at_unix' => $start, 'deadline_at_unix' => $deadline];
            $runOptions = [...$options, 'workflow-id' => $ids[$index], 'fixture' => $paths[$index]];
            $fibers[$index] = new Fiber(static fn (): array => sdkDirectionHttpObservation($runFixture, $runOptions,
                static function (array $phase): void { Fiber::suspend($phase); }, $deadline));
            $checkpoint('original_author', $runFixture['namespace']);
            $original = $fibers[$index]->start();
            if (! is_array($original) || $original['phase'] !== 'original' || $original['snapshot']['status'] !== 'waiting') {
                throw new RuntimeException('Both original namespace runs must durably wait before signal delivery.');
            }
            $state['initial'][$index] = $original['snapshot'];
            $state['timeline'][] = ['run' => $index, 'phase' => 'original', 'at_unix' => microtime(true)];
        }
        $snapshots = static fn (): array => array_map(static fn (int $index): array => sdkDirectionSnapshot(
            $clients[$index], $ids[$index], $state['initial'][$index]['run_id']), [0, 1]);
        foreach ([0, 1] as $index) {
            $before = $snapshots();
            $result = namespaceSignalControlPhase('foreign-signal', $fixture['sdk_signal_direction']['client'], $url,
                $ids[$index], $state['initial'][$index]['run_id'], $paths[1 - $index],
                $state['run_budgets'][$index]['deadline_at_unix'], $journal,
                $evidence.'/sdk-namespace-signal-'.$ids[0].'-foreign-signal-'.$index.'.json');
            $state['foreign_signals'][] = ['target_run' => $index, 'before' => $before,
                'result' => $result, 'after' => $snapshots()];
        }
        foreach (['signal-1' => 'resumed', 'signal-2' => 'replacement'] as $signal => $author) {
            foreach ([0, 1] as $index) {
                $sent = $fibers[$index]->resume();
                if (! is_array($sent) || $sent['phase'] !== $signal) {
                    throw new RuntimeException('Paired SDK sender did not complete its original next phase.');
                }
                $state['timeline'][] = ['run' => $index, 'phase' => $signal, 'at_unix' => microtime(true)];
                $before = $snapshots();
                $result = namespaceSignalControlPhase('foreign-poll', 'php', $url, $ids[$index],
                    $state['initial'][$index]['run_id'], $paths[1 - $index],
                    $state['run_budgets'][$index]['deadline_at_unix'], $journal,
                    $evidence.'/sdk-namespace-signal-'.$ids[0].'-foreign-poll-'.$index.'-'.$signal.'.json');
                $state['foreign_polls'][] = ['target_run' => $index, 'after_signal' => $signal,
                    'before' => $before, 'result' => $result, 'after' => $snapshots()];
                $resumed = $fibers[$index]->resume();
                if (! is_array($resumed) || $resumed['phase'] !== $author) {
                    throw new RuntimeException('Correct cold namespace author did not complete the next original phase.');
                }
                $state['timeline'][] = ['run' => $index, 'phase' => $author, 'at_unix' => microtime(true)];
                if ($author === 'replacement') {
                    $state['run_budgets'][$index]['finished_at_unix'] = microtime(true);
                }
            }
        }
        $observations = [];
        foreach ($fibers as $index => $fiber) {
            $fiber->resume();
            $observations[$index] = $fiber->getReturn();
        }
        $state['pair_finished_at_unix'] = microtime(true);
        $state['peer'] = $observations[1];
        return $observations[0] + ['namespace_signal_pair' => $state];
    } finally {
        foreach ($fibers as $fiber) {
            if ($fiber->isSuspended()) {
                try { $fiber->throw(new RuntimeException('Paired namespace recording aborted.')); } catch (Throwable) { }
            }
        }
        $consolidated = file_put_contents($controlBase.'.json',
            json_encode(['state' => $state, 'transport_jsonl' => file_get_contents($journal),
                'request_arrivals_jsonl' => file_get_contents($arrivals)],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n") !== false;
        proc_terminate($proxy);
        fclose($proxyPipes[1]);
        fclose($proxyPipes[2]);
        proc_close($proxy);
        if ($consolidated) {
            unlink($journal);
            unlink($arrivals);
            unlink($progress);
        }
        foreach ($paths as $path) { unlink($path); }
        if (! $consolidated) {
            throw new RuntimeException('Could not consolidate namespace control evidence; partial journals retained.');
        }
    }
}

function namespaceSignalEmbeddedStoredState(array $runIds): array
{
    return ['runs' => array_map(namespaceEmbeddedRun(...), $runIds),
        'commands' => WorkflowCommand::query()->whereIn('workflow_run_id', $runIds)->orderBy('id')->get()->toArray(),
        'tasks' => WorkflowTask::query()->whereIn('workflow_run_id', $runIds)->orderBy('id')->get()->toArray()];
}

function namespaceSignalEmbeddedObservation(array $fixture, string $workflowId, string $root): array
{
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    require __DIR__.'/embedded-types.php';
    config(['queue.default' => 'database', 'workflows.v2.task_dispatch_mode' => 'queue',
        'workflows.v2.types.workflows' => ['parity.v1.sdk_signals' => \ServerParity\SdkSignalsWorkflow::class]]);
    $start = microtime(true);
    $state = ['applicable' => true, 'pair_started_at_unix' => $start, 'pair_budget_seconds' => 70,
        'run_budget_seconds' => 35, 'initial' => [], 'foreign_signals' => [],
        'foreign_polls' => ['applicable' => false, 'reason' => 'embedded_has_no_http_worker_poll'], 'run_budgets' => []];
    $ids = [$workflowId, $workflowId.':peer'];
    $fixtures = [namespaceSignalRunFixture($fixture, false), namespaceSignalRunFixture($fixture, true)];
    $stubs = $runIds = $deliveries = [];
    $queue = $fixture['sdk_signal_direction']['task_queue'];
    foreach ($fixtures as $index => $runFixture) {
        $started = microtime(true);
        $state['run_budgets'][$index] = ['started_at_unix' => $started, 'deadline_at_unix' => $started + 35];
        $stubs[$index] = WorkflowStub::make(\ServerParity\SdkSignalsWorkflow::class, $ids[$index], $runFixture['namespace']);
        $stubs[$index]->start($runFixture['input'], 2, new WorkflowOptions(connection: 'database', queue: $queue),
            new StartOptions(executionTimeoutSeconds: 3600, runTimeoutSeconds: 600));
        $runIds[$index] = $stubs[$index]->runId();
        $deliveries[$index] = [];
    }
    $work = static function () use ($queue, &$state): void {
        $remaining = min(array_column($state['run_budgets'], 'deadline_at_unix')) - microtime(true);
        if ($remaining <= 0) { throw new RuntimeException('Original embedded namespace budget expired.'); }
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queue, '--stop-when-empty' => true,
            '--max-time' => max(1, (int) floor($remaining)), '--sleep' => 0, '--tries' => 1]);
        if (microtime(true) >= min(array_column($state['run_budgets'], 'deadline_at_unix'))) {
            throw new RuntimeException('Embedded namespace jobs exceeded the original run budget.');
        }
    };
    $work();
    $state['initial'] = array_map(namespaceEmbeddedRun(...), $runIds);
    foreach ([0, 1] as $index) {
        if ($state['initial'][$index]['status'] !== 'waiting') {
            throw new RuntimeException('Both original embedded namespace runs must wait before signals.');
        }
        $before = namespaceSignalEmbeddedStoredState($runIds);
        try {
            WorkflowStub::loadSelection($ids[$index], $runIds[$index], $fixtures[1 - $index]['namespace'])
                ->attemptSignalWithArguments('payload', [$fixtures[1 - $index]['signal_value']]);
            $result = ['refused' => false];
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $error) {
            $result = ['refused' => true, 'typed_error' => ['class' => $error::class]];
        }
        $state['foreign_signals'][] = ['target_run' => $index, 'before' => $before,
            'result' => $result, 'after' => namespaceSignalEmbeddedStoredState($runIds)];
    }
    for ($signal = 0; $signal < 2; ++$signal) {
        foreach ($stubs as $index => $stub) {
            $before = namespaceEmbeddedRun($runIds[$index]);
            $selected = WorkflowStub::loadSelection($ids[$index], $runIds[$index], $fixtures[$index]['namespace']);
            $command = $selected->attemptSignalWithArguments('payload', [$fixtures[$index]['signal_value']]);
            $deliveries[$index][] = ['before' => $before['execution'], 'response' => ['accepted' => $command->accepted(),
                'command_id' => $command->commandId(), 'workflow_id' => $command->workflowId(),
                'run_id' => $command->runId(), 'outcome' => $command->outcome()]];
            $work();
            if ($signal === 1) { $state['run_budgets'][$index]['finished_at_unix'] = microtime(true); }
        }
    }
    $observations = array_map(namespaceEmbeddedRun(...), $runIds);
    foreach ($observations as $index => &$observation) {
        $observation['signal_deliveries'] = $deliveries[$index];
        $observation['sdk_signal_direction'] = ['applicable' => false, 'reason' => 'embedded_executes_php_author_definitions'];
    }
    unset($observation);
    $state['pair_finished_at_unix'] = microtime(true);
    $state['peer'] = $observations[1];
    return $observations[0] + ['namespace_signal_pair' => $state];
}
