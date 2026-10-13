<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Workflow\V2\CommandResult;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;
use Workflow\WorkflowOptions;

require __DIR__.'/selected-run-signal-state.php';

function selectedRunSignalFixture(array $fixture, bool $peer): array
{
    $pair = $fixture['selected_run_signal_pair'];
    unset($fixture['selected_run_signal_pair']);
    $fixture['selected_run_signal_run'] = $peer ? 'peer' : 'primary';
    // The existing author and transport recorder already support explicit
    // namespace headers. This marker selects capture, not an API rewrite.
    $fixture['namespace_signal_run'] = $peer ? 'peer' : 'primary';
    if ($peer) {
        $fixture['namespace'] = $pair['peer_namespace'];
        $fixture['signal_value'] = $pair['peer_signal_value'];
        $fixture['typed_signal_value'] = $pair['typed_peer_signal_value'];
    }
    return $fixture;
}

function selectedRunSignalHttpObservation(array $fixture, array $options): array
{
    $evidence = getenv('DW_PARITY_FAILURE_EVIDENCE_DIR');
    if (! is_string($evidence) || ! is_dir($evidence)) {
        throw new RuntimeException('Selected-run pair requires its retained evidence directory.');
    }
    $began = microtime(true);
    $state = ['applicable' => true, 'pair_started_at_unix' => $began,
        'pair_budget_seconds' => 70, 'run_budget_seconds' => 35, 'administration' => [],
        'initial' => [], 'controls' => [], 'timeline' => [], 'run_budgets' => []];
    $ids = [$options['workflow-id'], $options['workflow-id'].':peer'];
    $fixtures = [selectedRunSignalFixture($fixture, false), selectedRunSignalFixture($fixture, true)];
    $paths = $fibers = $clients = [];
    $base = $evidence.'/sdk-selected-run-signal-'.$ids[0].'-control';
    $journal = $base.'-http.jsonl';
    $arrivals = $base.'-arrivals.jsonl';
    $progress = $base.'-progress.json';
    file_put_contents($journal, '');
    file_put_contents($arrivals, '');
    $checkpoint = static function (string $stage) use ($progress, &$state, $ids): void {
        if (file_put_contents($progress, json_encode(['schema' => 'durable-workflow.selected-run-signal-progress/v1',
            'stage' => $stage, 'workflow_id' => $ids[0], 'at_unix' => microtime(true),
            'pair_deadline_at_unix' => $state['pair_started_at_unix'] + 70,
            'run_budgets' => $state['run_budgets']], JSON_THROW_ON_ERROR)."\n") === false) {
            throw new RuntimeException('Could not retain selected-run setup checkpoint.');
        }
    };
    $checkpoint('observer_start');
    $proxy = proc_open(['node', __DIR__.'/published-sdk-patch/observe-http.mjs', $options['url'], $journal, '--forwarding-state'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
        [...getenv(), 'DW_PARITY_HTTP_CAPTURE_NAMESPACE' => '1', 'DW_PARITY_HTTP_REQUEST_ARRIVALS' => $arrivals]);
    if (! is_resource($proxy)) {
        throw new RuntimeException('Could not start selected-run control observer.');
    }
    fclose($pipes[0]);
    try {
        $url = trim(fgets($pipes[1]));
        if (! preg_match('#^http://127\.0\.0\.1:[0-9]+$#', $url)) {
            throw new RuntimeException('Selected-run control observer failed to bind.');
        }
        $admin = new Client($url, namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null);
        foreach ($fixtures as $index => $runFixture) {
            $checkpoint('namespace_setup_'.$index);
            try {
                $namespace = $admin->describeNamespace($runFixture['namespace']);
                $created = false;
            } catch (ServerException $error) {
                if ($error->status !== 404) { throw $error; }
                $namespace = $admin->createNamespace($runFixture['namespace'], 'Selected-run SDK signal conformance', 7);
                $created = true;
            }
            $state['administration'][] = ['created' => $created, 'namespace' => $namespace->raw];
            $paths[$index] = tempnam(sys_get_temp_dir(), 'parity-selected-run-');
            file_put_contents($paths[$index], json_encode($runFixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $clients[$index] = new Client($options['url'], namespace: $runFixture['namespace'], token: getenv('DW_PARITY_TOKEN') ?: null);
            $start = microtime(true);
            $deadline = $start + 35;
            $state['run_budgets'][$index] = ['started_at_unix' => $start, 'deadline_at_unix' => $deadline];
            $runOptions = [...$options, 'workflow-id' => $ids[$index], 'fixture' => $paths[$index]];
            $fibers[$index] = new Fiber(static fn (): array => sdkDirectionHttpObservation($runFixture, $runOptions,
                static function (array $phase): void { Fiber::suspend($phase); }, $deadline));
            $checkpoint('original_author_'.$index);
            $phase = $fibers[$index]->start();
            if (! is_array($phase) || $phase['phase'] !== 'original' || $phase['snapshot']['status'] !== 'waiting') {
                throw new RuntimeException('Both selected original runs must durably wait before any signal.');
            }
            $state['initial'][$index] = $phase['snapshot'];
            $state['timeline'][] = ['run' => $index, 'phase' => 'original', 'at_unix' => microtime(true)];
        }
        $runs = array_column($state['initial'], 'run_id');
        $snapshots = static fn (): array => array_map(static fn (int $index): array => sdkDirectionSnapshot($clients[$index], $ids[$index], $runs[$index]), [0, 1]);
        $stored = static fn (): array => selectedRunSignalStoredState($ids, $runs, $clients[0]->payloadCodec()->decodeEnvelope(...));
        $control = static function (string $phase, int $index, int $scope, string $selected) use (&$state,
            $ids, $fixture, $paths, $url, $snapshots, $stored, $journal, $base, $checkpoint): void {
            $checkpoint($phase.'_'.$index);
            $before = $snapshots();
            $storedBefore = $stored();
            $result = namespaceSignalControlPhase($phase, $fixture['sdk_signal_direction']['client'], $url,
                $ids[$index], $selected, $paths[$scope], $state['run_budgets'][$index]['deadline_at_unix'],
                $journal, $base.'-'.$phase.'-'.$index.'.json', true);
            $state['controls'][] = ['phase' => $phase, 'target_run' => $index, 'scope_run' => $scope,
                'selected_run_id' => $selected, 'before' => $before, 'stored_before' => $storedBefore,
                'result' => $result, 'after' => $snapshots(), 'stored_after' => $stored()];
        };
        foreach ([0, 1] as $index) {
            $control('foreign-signal', $index, 1 - $index, $runs[$index]);
            $control('mismatched-run', $index, $index, $runs[1 - $index]);
        }
        foreach ([0, 1] as $index) {
            $control('unknown-signal', $index, $index, $runs[$index]);
            $control('invalid-signal', $index, $index, $runs[$index]);
        }
        foreach (['signal-1' => 'resumed', 'signal-2' => 'replacement'] as $signal => $author) {
            foreach ([0, 1] as $index) {
                foreach ([$signal, $author] as $expected) {
                    $checkpoint($expected.'_'.$index);
                    $phase = $fibers[$index]->resume();
                    if (! is_array($phase) || $phase['phase'] !== $expected) {
                        throw new RuntimeException('Selected original did not complete its next sender/author phase.');
                    }
                    $state['timeline'][] = ['run' => $index, 'phase' => $expected, 'at_unix' => microtime(true)];
                }
            }
        }
        $observations = [];
        foreach ($fibers as $index => $fiber) {
            $fiber->resume();
            $observations[$index] = $fiber->getReturn();
        }
        foreach ([0, 1] as $index) {
            $control('inactive-signal', $index, $index, $runs[$index]);
            $state['run_budgets'][$index]['finished_at_unix'] = microtime(true);
        }
        $state['final_stored'] = $stored();
        $state['pair_finished_at_unix'] = microtime(true);
        $state['peer'] = $observations[1];
        return $observations[0] + ['selected_run_signal_pair' => $state];
    } finally {
        foreach ($fibers as $fiber) {
            if ($fiber->isSuspended()) {
                try { $fiber->throw(new RuntimeException('Selected-run pair recording aborted.')); } catch (Throwable) { }
            }
        }
        $consolidated = file_put_contents($base.'.json', json_encode(['state' => $state,
            'transport_jsonl' => file_get_contents($journal), 'request_arrivals_jsonl' => file_get_contents($arrivals)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n") !== false;
        proc_terminate($proxy);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proxy);
        foreach ($paths as $path) { unlink($path); }
        if ($consolidated) {
            unlink($journal); unlink($arrivals); unlink($progress);
        } else {
            throw new RuntimeException('Selected-run consolidation failed; original partial evidence remains.');
        }
    }
}

function selectedRunSignalEmbeddedReceipt(CommandResult $command): array
{
    return ['accepted' => $command->accepted(), 'command_id' => $command->commandId(),
        'command_sequence' => $command->commandSequence(), 'workflow_id' => $command->workflowId(),
        'run_id' => $command->runId(), 'requested_run_id' => $command->requestedRunId(),
        'resolved_run_id' => $command->resolvedRunId(), 'target_scope' => $command->targetScope(),
        'command_status' => $command->status(), 'outcome' => $command->outcome(),
        'reason' => $command->rejectionReason(), 'validation_errors' => $command->validationErrors()];
}

function selectedRunSignalEmbeddedObservation(array $fixture, string $workflowId, string $root): array
{
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    require __DIR__.'/embedded-types.php';
    config(['queue.default' => 'database', 'workflows.v2.task_dispatch_mode' => 'queue',
        'workflows.v2.types.workflows' => ['parity.v1.sdk_signals' => \ServerParity\SdkSignalsWorkflow::class]]);
    $began = microtime(true);
    $state = ['applicable' => true, 'pair_started_at_unix' => $began, 'pair_budget_seconds' => 70,
        'run_budget_seconds' => 35, 'initial' => [], 'controls' => [], 'run_budgets' => []];
    $ids = [$workflowId, $workflowId.':peer'];
    $fixtures = [selectedRunSignalFixture($fixture, false), selectedRunSignalFixture($fixture, true)];
    $runs = $deliveries = [];
    $queue = $fixture['sdk_signal_direction']['task_queue'];
    foreach ($fixtures as $index => $runFixture) {
        $started = microtime(true);
        $state['run_budgets'][$index] = ['started_at_unix' => $started, 'deadline_at_unix' => $started + 35];
        $stub = WorkflowStub::make(\ServerParity\SdkSignalsWorkflow::class, $ids[$index], $runFixture['namespace']);
        $stub->start($runFixture['input'], 2, new WorkflowOptions(connection: 'database', queue: $queue),
            new StartOptions(executionTimeoutSeconds: 3600, runTimeoutSeconds: 600));
        $runs[$index] = $stub->runId();
        $deliveries[$index] = [];
    }
    $work = static function () use ($queue, &$state): void {
        $deadline = min(array_column($state['run_budgets'], 'deadline_at_unix'));
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) { throw new RuntimeException('Original embedded selected-run budget expired.'); }
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queue, '--stop-when-empty' => true,
            '--max-time' => max(1, (int) floor($remaining)), '--sleep' => 0, '--tries' => 1]);
        if (microtime(true) >= $deadline) { throw new RuntimeException('Embedded jobs exceeded the original selected-run budget.'); }
    };
    $work();
    $snapshots = static fn (): array => array_map(namespaceEmbeddedRun(...), $runs);
    $stored = static fn (): array => selectedRunSignalStoredState($ids, $runs,
        static fn (array $frame): mixed => \Workflow\Serializer::unserializeWithCodec($frame['codec'], $frame['blob']));
    $state['initial'] = $snapshots();
    foreach ($state['initial'] as $initial) {
        if ($initial['status'] !== 'waiting') { throw new RuntimeException('Both embedded originals must wait before signals.'); }
    }
    $control = static function (string $phase, int $index, int $scope, string $selected) use (&$state,
        $ids, $runs, $fixtures, $snapshots, $stored): void {
        $before = $snapshots();
        $storedBefore = $stored();
        try {
            $selection = WorkflowStub::loadSelection($ids[$index], $selected, $fixtures[$scope]['namespace']);
            $command = $selection->attemptSignalWithArguments($phase === 'unknown-signal' ? 'undeclared' : 'payload',
                $phase === 'invalid-signal' ? [] : [$fixtures[$index]['signal_value']]);
            $result = ['refused' => $command->rejected(), 'command' => selectedRunSignalEmbeddedReceipt($command)];
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $error) {
            $result = ['refused' => true, 'typed_error' => ['class' => $error::class]];
        }
        $state['controls'][] = ['phase' => $phase, 'target_run' => $index, 'scope_run' => $scope,
            'selected_run_id' => $selected, 'before' => $before, 'stored_before' => $storedBefore,
            'result' => $result, 'after' => $snapshots(), 'stored_after' => $stored()];
    };
    foreach ([0, 1] as $index) {
        $control('foreign-signal', $index, 1 - $index, $runs[$index]);
        $control('mismatched-run', $index, $index, $runs[1 - $index]);
    }
    foreach ([0, 1] as $index) {
        $control('unknown-signal', $index, $index, $runs[$index]);
        $control('invalid-signal', $index, $index, $runs[$index]);
    }
    for ($signal = 0; $signal < 2; ++$signal) {
        foreach ([0, 1] as $index) {
            $before = namespaceEmbeddedRun($runs[$index]);
            $command = WorkflowStub::loadSelection($ids[$index], $runs[$index], $fixtures[$index]['namespace'])
                ->attemptSignalWithArguments('payload', [$fixtures[$index]['signal_value']]);
            $deliveries[$index][] = ['before' => $before['execution'], 'response' => selectedRunSignalEmbeddedReceipt($command)];
            $work();
        }
    }
    $observations = $snapshots();
    foreach ([0, 1] as $index) {
        $control('inactive-signal', $index, $index, $runs[$index]);
        $state['run_budgets'][$index]['finished_at_unix'] = microtime(true);
        $observations[$index]['signal_deliveries'] = $deliveries[$index];
        $observations[$index]['sdk_signal_direction'] = ['applicable' => false, 'reason' => 'embedded_executes_php_author_definitions'];
    }
    $state['final_stored'] = $stored();
    $state['pair_finished_at_unix'] = microtime(true);
    $state['peer'] = $observations[1];
    return $observations[0] + ['selected_run_signal_pair' => $state];
}
