<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Transport\Psr18Transport;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;

// Public SDK operations only. No Server models, database writes or projection repair.
if ($argc !== 4 || ! in_array($argv[2], ['execute', 'cold-read'], true)) {
    throw new InvalidArgumentException('Usage: run-observation-consumer.php URL execute|cold-read IDENTITY_JSON');
}
$autoload = getenv('DW_PARITY_SDK_AUTOLOAD');
if (! is_string($autoload) || $autoload === '' || ! is_file($autoload)) {
    throw new RuntimeException('DW_PARITY_SDK_AUTOLOAD must name the installed SDK autoloader.');
}
require $autoload;
[$script, $url, $phase, $identityPath] = $argv;
$profile = json_decode(file_get_contents(__DIR__.'/../activity-result-browser/profile.json'), true, flags: JSON_THROW_ON_ERROR);
$sdk = $profile['author_php_sdk'];
if (InstalledVersions::getPrettyVersion('durable-workflow/sdk') !== $sdk['version']
    || InstalledVersions::getReference('durable-workflow/sdk') !== $sdk['source']) {
    throw new RuntimeException('Installed SDK differs from the observation consumer tuple.');
}
$state = json_decode(file_get_contents($identityPath), true, flags: JSON_THROW_ON_ERROR);
$workflow = $state['workflow_id'];
$namespace = $state['namespace'];
$require = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$budget = static function () use (&$state, $require): void {
    $require(microtime(true) < $state['original_deadline'], 'Original observation budget expired.');
};
$payloadGets = 0;
$stack = HandlerStack::create();
$stack->push(static function (callable $handler) use (&$payloadGets): callable {
    return static function (RequestInterface $request, array $options) use ($handler, &$payloadGets) {
        if ($request->getMethod() === 'GET' && str_starts_with($request->getUri()->getPath(), '/api/external-payloads/')) {
            $payloadGets++;
        }

        return $handler($request, $options);
    };
});
$http = new HttpClient(['base_uri' => rtrim($url, '/'), 'handler' => $stack,
    'http_errors' => false, 'connect_timeout' => 1, 'timeout' => 5]);
$client = new Client($url, namespace: $namespace, token: 'observation-fixture-token',
    transport: new Psr18Transport(client: $http));
$stable = static function (array $value): array {
    unset($value['observed_at'], $value['current_waits']['observed_at']);

    return $value;
};
$capture = static function (string $stage, string $status) use (
    $client, $workflow, $namespace, &$state, &$payloadGets, $budget, $require, $stable,
): void {
    $budget();
    $run = $state['run_id'];
    $before = $client->workflowHistory($workflow, $run);
    $current = $client->workflowObservation($workflow);
    $selected = $client->workflowObservation($workflow, $run);
    $after = $client->workflowHistory($workflow, $run);
    $require($before === $after, $stage.': observation changed history.');
    $require($payloadGets === 0, $stage.': observation fetched external payload bytes.');
    $require($stable($current) === $stable($selected), $stage.': current and selected reads differ.');
    foreach ([$current, $selected] as $observed) {
        foreach (['run_id', 'selected_run_id', 'current_run_id'] as $key) {
            $require($observed[$key] === $run, $stage.': wrong '.$key.'.');
        }
        $require($observed['workflow_id'] === $workflow && $observed['namespace'] === $namespace
            && $observed['status'] === $status && $observed['is_current_run'] === true,
            $stage.': selected workflow state differs.');
        $require($observed['read_mode'] === 'bounded' && $observed['history_audit'] === 'not_evaluated'
            && $observed['current_run_audit'] === 'not_evaluated'
            && $observed['control_plane']['operation'] === 'observe_workflow', $stage.': read contract differs.');
        foreach (['arguments', 'input', 'output', 'memo', 'cancellation_cascade'] as $key) {
            $require(! array_key_exists($key, $observed), $stage.': application field returned: '.$key);
        }
        $events = $observed['history']['events'];
        $require(count($events) === count($before['events']) && $observed['history']['has_more'] === false,
            $stage.': original history is incomplete.');
        foreach ($events as $index => $event) {
            // The history API wraps stored Avro strings in transport envelopes;
            // observation deliberately returns the original stored payload shape.
            foreach (['sequence', 'event_type'] as $key) {
                $require($event[$key] === $before['events'][$index][$key], $stage.': history '.$key.' differs.');
            }
        }
    }
    // Record absent production as a product gap while completing this same original.
    // This is never normalized into an empty or healthy projection.
    if ($selected['summary_state'] !== 'observed') {
        $state['product_gaps'][] = $stage.': summary_state='.$selected['summary_state'];
    } else {
        $require($selected['history_event_count'] === count($before['events']), $stage.': stale summary event count.');
        $require($selected['exception_count'] === 0, $stage.': retry became a terminal failure.');
        $require(is_int($selected['history_size_bytes']) && $selected['history_size_bytes'] >= 0,
            $stage.': missing history size counter.');
        // The published fresh-start shortcut stores zero. Later full projections
        // count the exact stored event type and compact, unescaped JSON bytes.
        if ($stage !== 'started') {
            $bytes = array_sum(array_map(static fn (array $event): int => strlen($event['event_type'])
                + strlen(json_encode($event['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                $selected['history']['events']));
            if ($selected['history_size_bytes'] !== $bytes) {
                $state['product_gaps'][] = $stage.': summary bytes '.$selected['history_size_bytes'].' differ from stored history '.$bytes;
            }
        }
    }
    if (in_array($stage, ['scheduled', 'claimed', 'heartbeat', 'retry', 'resumed'], true)
        && ($selected['current_waits']['state'] !== 'partial' || $selected['current_waits']['returned_count'] !== 1)) {
        $state['product_gaps'][] = $stage.': activity wait projection missing';
    }
    if ($status === 'completed') {
        $require($selected['current_waits']['state'] === 'available'
            && $selected['current_waits']['total_count'] === 0, $stage.': closed run retains current waits.');
    }
    $state['snapshots'][$stage] = ['current' => $current, 'selected' => $selected];
    $state['history_counts'] = array_count_values(array_column($after['events'], 'event_type'));
    $budget();
};

if ($phase === 'execute') {
    $headers = ['Authorization' => 'Bearer observation-fixture-token', 'X-Namespace' => $namespace,
        'X-Durable-Workflow-Control-Plane-Version' => '2'];
    $response = $http->get('/api/namespaces/'.$namespace, ['headers' => $headers]);
    if ($response->getStatusCode() === 404) {
        $response = $http->post('/api/namespaces', ['headers' => $headers, 'json' => ['name' => $namespace]]);
        $require($response->getStatusCode() === 201, 'Namespace setup failed.');
    } else {
        $require($response->getStatusCode() === 200, 'Namespace lookup failed.');
    }
    $state['started_at'] = microtime(true);
    $state['original_deadline'] = $state['started_at'] + $profile['original_observation_budget_seconds'];
    $state['product_gaps'] = [];
    $state['snapshots'] = [];
    $worker = $workflow.':worker';
    $client->registerWorker($worker, $workflow, ['parity.observation'], ['parity.observed_value']);
    $original = $client->startWorkflow('parity.observation', $workflow, $workflow, [],
        executionTimeoutSeconds: 600, runTimeoutSeconds: 120);
    $state['run_id'] = $original->selectedRunId;
    $capture('started', 'pending');
    $author = $client->pollWorkflowTask($worker, $workflow, 0);
    $require($author !== null && $author['run_id'] === $state['run_id'], 'Original workflow claim missing.');
    $state['author_claim'] = $author;
    $value = ['integer' => 9007199254740993, 'optional' => null, 'text' => '日本語 λ'];
    $receipt = $client->completeWorkflowTask($author['task_id'], $author['lease_owner'], $author['workflow_task_attempt'], [[
        'type' => 'schedule_activity', 'activity_type' => 'parity.observed_value',
        'arguments' => $client->payloadCodec()->envelope([$value]),
        'retry_policy' => ['max_attempts' => 2, 'backoff_seconds' => []],
        'start_to_close_timeout' => 120, 'schedule_to_close_timeout' => 600,
    ]]);
    $require(($receipt['recorded'] ?? null) === true, 'Original activity schedule was not committed.');
    $capture('scheduled', 'waiting');
    $state['window'] = $client->workflowObservation($workflow, $state['run_id'], historyPageSize: 1)['history'];
    $require(is_string($state['window']['next_page_token']), 'Original page did not expose its continuation.');
    $first = $client->pollActivityTask($worker, $workflow, 0);
    $require($first !== null && $client->payloadCodec()->decodeEnvelope($first['arguments']) === [$value],
        'Original activity arguments differ.');
    $state['activity_id'] = $first['activity_execution_id'];
    $state['claims'] = [$first];
    $capture('claimed', 'waiting');
    $client->heartbeatActivityTask($first['task_id'], $first['activity_attempt_id'], $first['lease_owner'], $value);
    $capture('heartbeat', 'waiting');
    $client->failActivityTask($first['task_id'], $first['activity_attempt_id'], $first['lease_owner'],
        'observation retry λ', nonRetryable: false);
    $capture('retry', 'waiting');
    $second = $client->pollActivityTask($worker, $workflow, 0);
    $require($second !== null && $second['activity_execution_id'] === $state['activity_id']
        && $second['activity_attempt_id'] !== $first['activity_attempt_id'] && $second['attempt_number'] === 2,
        'Original retry identity differs.');
    $state['claims'][] = $second;
    $capture('resumed', 'waiting');
    $client->completeActivityTask($second['task_id'], $second['activity_attempt_id'], $second['lease_owner'], $value);
    $capture('activity_completed', 'waiting');
    $parent = $client->pollWorkflowTask($worker, $workflow, 0);
    $require($parent !== null && $parent['run_id'] === $state['run_id'], 'Original parent continuation missing.');
    $state['parent_claim'] = $parent;
    $client->completeWorkflowTask($parent['task_id'], $parent['lease_owner'], $parent['workflow_task_attempt'], [[
        'type' => 'complete_workflow', 'result' => $client->payloadCodec()->envelope('separate parent output'),
    ]]);
    $capture('closed', 'completed');
    foreach (['ActivityScheduled' => 1, 'ActivityStarted' => 2, 'ActivityHeartbeatRecorded' => 1,
        'ActivityRetryScheduled' => 1, 'ActivityCompleted' => 1, 'ActivityFailed' => 0, 'WorkflowCompleted' => 1] as $kind => $count) {
        $require(($state['history_counts'][$kind] ?? 0) === $count, 'Wrong original event count: '.$kind);
    }
    foreach (['scheduled', 'claimed', 'heartbeat', 'retry', 'resumed'] as $stage) {
        $waits = $state['snapshots'][$stage]['selected']['current_waits'];
        if ($waits['state'] !== 'partial' || $waits['returned_count'] !== 1) {
            continue; // The capture already recorded an explicit product failure.
        }
        $wait = $waits['waits'][0];
        $claim = in_array($stage, ['retry', 'resumed'], true) ? $second : $first;
        $ready = in_array($stage, ['scheduled', 'retry'], true);
        $require($wait['dependency_id'] === $state['activity_id'] && $wait['task_id'] === $claim['task_id']
            && $wait['task_status'] === ($ready ? 'ready' : 'leased') && $wait['task_metadata_state'] === 'available'
            && $wait['kind'] === (in_array($stage, ['retry', 'resumed'], true) ? 'activity_retry' : 'activity')
            && $wait['attempt_limit'] === 2,
            $stage.': wait does not match its actual dependency and task.');
        $require($wait['state'] === ($ready ? 'eligible' : 'resume_time_unknown')
            && $wait['unavailable_reason'] === null, $stage.': wait timing availability differs.');
        $require($wait['attempt_id'] === ($ready ? null : $claim['activity_attempt_id'])
            && $wait['attempt_number'] === ($stage === 'scheduled' ? null : $claim['attempt_number']),
            $stage.': wait selected a different attempt.');
    }
} elseif ($phase === 'cold-read') {
    $budget();
    $capture('cold', 'completed');
    $require($stable($state['snapshots']['closed']['selected']) === $stable($state['snapshots']['cold']['selected']),
        'Fresh client changed retained closed observation.');
    $window = $state['window'];
    $before = $client->workflowHistory($workflow, $state['run_id']);
    $pages = [];
    $sequences = [];
    $token = $window['next_page_token'];
    $after = $window['last_sequence'];
    do {
        $budget();
        $require(count($pages) < 32, 'Original history continuation exceeded the fixture bound.');
        $page = $client->workflowObservation($workflow, $state['run_id'], historyPageSize: 1,
            historyPageToken: $token)['history'];
        $require($page['through_sequence'] === $window['through_sequence'] && $page['after_sequence'] === $after,
            'Original history window changed after later execution.');
        $require($page['has_more'] === is_string($page['next_page_token']), 'History continuation availability differs.');
        $pages[] = $page;
        array_push($sequences, ...array_column($page['events'], 'sequence'));
        $after = $page['last_sequence'];
        $token = $page['next_page_token'];
    } while ($page['has_more']);
    $expected = array_values(array_filter($before['events'], static fn (array $event): bool =>
        $event['sequence'] > $window['last_sequence'] && $event['sequence'] <= $window['through_sequence']));
    $require($sequences === array_column($expected, 'sequence'),
        'Original history continuation lost or added events.');
    $require($before === $client->workflowHistory($workflow, $state['run_id']), 'Cold page read changed history.');
    $state['continued_window'] = $pages;
} else {
    throw new RuntimeException('Unknown observation consumer phase.');
}
$budget();
$state['sdk'] = $sdk;
$state['metadata_payload_gets'] = $payloadGets;
if (file_put_contents($identityPath, json_encode($state, JSON_THROW_ON_ERROR)) === false) {
    throw new RuntimeException('Cannot retain the original observation evidence.');
}
echo json_encode(['outcome' => $state['product_gaps'] === [] ? 'pass' : 'product-fail',
    'phase' => $phase, 'workflow_id' => $workflow, 'run_id' => $state['run_id'], 'namespace' => $namespace,
    'snapshots' => array_keys($state['snapshots']), 'product_gaps' => $state['product_gaps'],
    'history_unchanged_by_reads' => true, 'metadata_payload_gets' => $payloadGets,
    'elapsed_seconds' => microtime(true) - $state['started_at']], JSON_THROW_ON_ERROR)."\n";
exit($state['product_gaps'] === [] ? 0 : 1);
