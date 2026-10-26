<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Worker\CapabilityManifest;
use GuzzleHttp\Client as RecordLocalActivityHttp;
use GuzzleHttp\HandlerStack;

// The record_local_activity workflow-task command (worker protocol 1.18+)
// over raw worker HTTP in a fresh namespace. Raw workers register as a
// non-PHP runtime, with and without the local_activities capability, and
// complete their workflow tasks with recorded local activities: the
// advertised contract, the registration and completion gates, the
// validation refusals at each layer, a success after a retried attempt, a
// failure after retries, a timeout, and the protocol 1.18 rolling-upgrade
// shape. History, the run's activity view and its status are recorded with
// run identities as labels and the synthesized attempt times as offsets from
// each local activity's close.
function finishHttpRecordLocalActivity(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['record_local_activity'];
    $namespace = strtolower($workflowId.'-rla');
    $admin->createNamespace($namespace, 'record local activity λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    // The SQLite reference shares its database with a one-second maintenance
    // loop; retry its retryable lock-pressure refusals, as the other probes do.
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new RecordLocalActivityHttp(['base_uri' => rtrim($url, '/'), 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    // $protocol null is a control-plane request; otherwise the worker header.
    $raw = static function (string $method, string $path, ?array $body = null, ?string $protocol = null) use ($http, $token, $namespace): array {
        $headers = array_filter(['Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $namespace,
            $protocol === null ? 'X-Durable-Workflow-Control-Plane-Version' : 'X-Durable-Workflow-Protocol-Version' => $protocol ?? '2']);
        $response = $http->request($method, $path, ['headers' => $headers] + ($body === null ? [] : ['json' => $body]));
        $decoded = json_decode((string) $response->getBody(), true);
        $capabilities = is_array($decoded) ? ($decoded['server_capabilities'] ?? null) : null;
        if (is_array($decoded)) {
            unset($decoded['server_capabilities'], $decoded['control_plane'], $decoded['protocol_version']);
        }

        return ['status' => $response->getStatusCode(), 'response' => $decoded, 'capabilities' => $capabilities];
    };
    $answer = static fn (array $recorded): array => ['status' => $recorded['status'], 'response' => $recorded['response']];
    $ok = static function (array $recorded, int $status, string $operation): array {
        if ($recorded['status'] !== $status) {
            throw new RuntimeException('Record local activity '.$operation.': '.json_encode($recorded));
        }

        return $recorded['response'];
    };
    $type = $definition['workflow_type'];
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $done = ['codec' => 'avro', 'blob' => $definition['result_blob']];
    $contract = ['signals' => ['resume'], 'signal_contracts' => [['name' => 'resume', 'parameters' => []]],
        'queries' => [], 'updates' => [], 'update_contracts' => []];
    $register = static function (string $suffix, array $capabilities, ?array $manifest = null) use ($raw, $type, $contract, $workflowId): array {
        $worker = $workflowId.'-rla-'.$suffix;

        return $raw('POST', '/api/worker/register', ['worker_id' => $worker, 'task_queue' => $worker.'-queue',
            'runtime' => 'python', 'sdk_version' => 'raw-http', 'supported_workflow_types' => [$type],
            'supported_activity_types' => [], 'workflow_command_contracts' => [$type => $contract],
            'capabilities' => $capabilities,
            'capability_manifest' => $manifest ?? CapabilityManifest::forCapabilities($capabilities)], '1.20');
    };
    // The local-activity part of an advertised capability map. The prepared
    // flags' values belong to the prepared-local routes, which have their own
    // fixture; here only their presence, type and agreement are observed.
    $advertised = static function (?array $capabilities): array {
        $prepared = $capabilities['prepared_local_activities'] ?? null;
        $groups = $capabilities['prepared_local_activity_groups'] ?? null;
        $policies = $capabilities['prepared_local_activity_cancellation_policies'] ?? null;
        $known = ['try_cancel', 'wait_cancellation_completed'];

        return [
            'local_activities' => is_array($capabilities) && array_key_exists('local_activities', $capabilities)
                ? $capabilities['local_activities'] : '(absent)',
            'record_local_activity_command' => in_array('record_local_activity',
                (array) ($capabilities['supported_workflow_task_commands'] ?? []), true),
            'prepared_flags' => [
                'prepared_local_activities' => get_debug_type($prepared),
                'prepared_local_activity_groups' => get_debug_type($groups),
                'prepared_local_activity_cancellation_policies' => is_array($policies) && array_is_list($policies)
                    && array_values(array_intersect($known, $policies)) === $policies ? 'ordered subset' : get_debug_type($policies),
                'agree' => is_bool($prepared) && is_bool($groups) && is_array($policies)
                    && ($prepared || (! $groups && $policies === [])) && (! $groups || $prepared),
            ],
        ];
    };
    // Starts a run on a worker's queue and claims its first workflow task.
    $claim = static function (string $suffix) use ($raw, $ok, $empty, $type, $workflowId): array {
        $worker = $workflowId.'-rla-'.$suffix;
        $id = $workflowId.'-rla-run-'.$suffix;
        $started = $ok($raw('POST', '/api/workflows', ['workflow_id' => $id, 'workflow_type' => $type,
            'task_queue' => $worker.'-queue', 'input' => $empty]), 201, 'start '.$suffix);
        $task = $ok($raw('POST', '/api/worker/workflow-tasks/poll', ['worker_id' => $worker, 'task_queue' => $worker.'-queue',
            'timeout_seconds' => 5], '1.20'), 200, 'claim '.$suffix)['task'] ?? null;
        if (! is_array($task) || ($task['run_id'] ?? null) !== $started['run_id']) {
            throw new RuntimeException('Record local activity claim '.$suffix.': '.json_encode($task));
        }

        return ['workflow_id' => $id, 'run_id' => $started['run_id'], 'task_id' => $task['task_id'],
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt']];
    };
    $complete = static fn (array $claimed, array $commands, string $protocol = '1.20', ?string $owner = null): array => $answer($raw('POST',
        '/api/worker/workflow-tasks/'.$claimed['task_id'].'/complete', ['lease_owner' => $owner ?? $claimed['lease_owner'],
            'workflow_task_attempt' => $claimed['workflow_task_attempt'], 'commands' => $commands], $protocol));
    $wait = ['type' => 'open_signal_wait', 'signal_name' => 'resume'];
    // A minimal valid record: one completed attempt.
    $record = static fn (array $fields = []): array => array_merge(['type' => 'record_local_activity',
        'activity_type' => $definition['activity_type'], 'arguments' => $empty, 'result' => $done, 'outcome' => 'completed',
        'attempts' => [['attempt_id' => 'w-1', 'attempt_number' => 1, 'outcome' => 'completed', 'duration_ms' => 25]]], $fields);
    $events = static fn (array $claimed): array => $ok($raw('GET', '/api/workflows/'.$claimed['workflow_id'].'/runs/'
        .$claimed['run_id'].'/history?page_size=1000'), 200, 'history')['events'] ?? [];
    // The run after its completion: every event type, every Activity* event
    // in full, the run's activity view and its status.
    $observeRun = static function (array $claimed) use ($raw, $ok, $events): array {
        $history = $events($claimed);
        $activities = $ok($raw('GET', '/api/workflows/'.$claimed['workflow_id'].'/runs/'.$claimed['run_id'].'/activities'),
            200, 'activities')['activities'] ?? null;
        $run = $ok($raw('GET', '/api/workflows/'.$claimed['workflow_id'].'/runs/'.$claimed['run_id']), 200, 'describe');

        $activityEvents = array_values(array_filter($history,
            static fn (array $event): bool => str_starts_with((string) ($event['event_type'] ?? ''), 'Activity')));
        // Every event of one completion snapshots the same hosting task: an
        // identical snapshot after the first is recorded by reference.
        foreach ($activityEvents as $index => $event) {
            if ($index > 0 && ($event['payload']['task'] ?? null) === ($activityEvents[0]['payload']['task'] ?? null)) {
                $activityEvents[$index]['payload']['task'] = '(the first event\'s task)';
            }
        }

        return [
            'event_types' => array_column($history, 'event_type'),
            'activity_events' => $activityEvents,
            'activities' => $activities,
            'run' => array_intersect_key($run, array_flip(['status', 'status_bucket', 'is_terminal', 'closed_reason',
                'wait_kind', 'wait_reason'])),
        ];
    };

    $state = ['applicable' => true, 'namespace' => $namespace];

    // The advertised contract.
    $cluster = $ok($raw('GET', '/api/cluster/info'), 200, 'cluster info');
    $clusterCapabilities = $cluster['worker_protocol']['server_capabilities'] ?? null;
    $state['cluster_info'] = $advertised($clusterCapabilities) + [
        'cluster_capabilities_local_keys' => array_values(array_filter(array_keys((array) ($cluster['capabilities'] ?? [])),
            static fn (string $key): bool => str_contains($key, 'local'))),
    ];

    // Registration: the local capability and its manifest entry agree.
    $registered = $register('a', ['local_activities']);
    $state['register_local'] = [
        'status' => $registered['status'],
        'response' => array_intersect_key((array) $registered['response'],
            array_flip(['registered', 'status', 'capabilities', 'capability_manifest'])),
        'advertised_as_cluster_info' => $advertised($registered['capabilities']) === $advertised($clusterCapabilities),
    ];
    $state['register_flat_only'] = $answer($register('flat-only', ['local_activities'], CapabilityManifest::forCapabilities([])));
    $state['register_manifest_only'] = $answer($register('manifest-only', [], CapabilityManifest::forCapabilities(['local_activities'])));

    // Run A: refusals on one claim, then a success after a retried attempt.
    $a = $claim('a');
    $state['refused_protocol_floor'] = $complete($a, [$record(), $wait], '1.17');
    $state['refused_wrong_owner'] = $complete($a, [$record(), $wait], '1.20', 'someone-else');
    $semantic = $record(['non_retryable' => false, 'result' => null,
        'attempts' => [['attempt_number' => 2, 'outcome' => 'failed', 'extra' => 1]]]);
    $state['refused_wrong_owner_invalid_report'] = $complete($a, [$semantic], '1.20', 'someone-else');
    $state['invalid_transport'] = $complete($a, [$record(['outcome' => 'weird', 'attempts' => [['outcome' => 'bogus']]])]);
    $state['invalid_worker_session'] = $complete($a, [$record(['worker_session' => ['session_id' => 's-1']])]);
    $state['invalid_cancellation_policy'] = $complete($a, [$record(['cancellation_policy' => 'try_cancel'])]);
    $state['invalid_timeout_envelope'] = $complete($a, [$record(['start_to_close_timeout' => 10, 'heartbeat_timeout' => 20])]);
    $state['invalid_field_scope'] = $complete($a, [$record(['schedule_to_start_timeout' => 5])]);
    $state['invalid_activity_type'] = $complete($a, [$record(['activity_type' => '   ', 'outcome' => null])]);
    $state['invalid_outcome'] = $complete($a, [$record(['outcome' => null, 'result' => null])]);
    $state['invalid_report'] = $complete($a, [$semantic]);
    $missing = $record();
    unset($missing['attempts']);
    $state['invalid_missing_attempts'] = $complete($a, [$missing]);
    $state['invalid_attempt_budget'] = $complete($a, [$record(['attempts' => [
        ['attempt_number' => 1, 'outcome' => 'failed', 'message' => 'one', 'retry_reason' => 'failure', 'backoff_seconds' => 0],
        ['attempt_number' => 2, 'outcome' => 'completed'],
    ]])]);
    $state['invalid_retry_policy'] = $complete($a, [$record(['retry_policy' => ['max_attempts' => 101, 'backoff_seconds' => [1, 2],
        'non_retryable_error_types' => ['A', 'A'], 'jitter' => 1]])]);
    // Each report also once with an optional field missing from its first
    // entry only, which published PHP's transport validation reorders.
    $retries = static fn (?string $first): array => $record(['retry_policy' => ['max_attempts' => 3, 'backoff_seconds' => [4]],
        'attempts' => [
            array_filter(['attempt_id' => $first, 'attempt_number' => 1, 'outcome' => 'completed', 'non_retryable' => true],
                static fn (mixed $value): bool => $value !== null),
            ['attempt_id' => 'w-2', 'attempt_number' => 2, 'outcome' => 'failed', 'message' => 'two', 'retry_reason' => 'timeout',
                'backoff_seconds' => 5, 'non_retryable' => true, 'timeout_kind' => 'heartbeat'],
            ['attempt_id' => 'w-2', 'attempt_number' => 3, 'outcome' => 'completed', 'retry_reason' => 'failure'],
        ]]);
    $state['invalid_retry_attempts'] = $complete($a, [$retries('w-1')]);
    $state['reordered_attempts_report'] = $complete($a, [$retries(null)]);
    $state['invalid_timed_out'] = $complete($a, [$record(['outcome' => 'timed_out', 'result' => null, 'message' => '  ',
        'non_retryable' => true, 'attempts' => [['attempt_number' => 1, 'outcome' => 'timed_out', 'message' => 'late',
            'non_retryable' => false]]])]);
    $beats = static fn (array $heartbeats): array => $record(['attempts' => [['attempt_number' => 1, 'outcome' => 'completed',
        'heartbeats' => $heartbeats]]]);
    $state['invalid_heartbeats'] = $complete($a, [$beats([['elapsed_ms' => 500, 'details' => ['a' => 1]],
        ['elapsed_ms' => 100, 'details' => [1, 2]], ['elapsed_ms' => 600, 'note' => 'x']])]);
    $state['reordered_heartbeats_report'] = $complete($a, [$beats([['elapsed_ms' => 500],
        ['elapsed_ms' => 100, 'details' => [1, 2]], ['elapsed_ms' => 600, 'note' => 'x'], 'beat'])]);
    $state['invalid_second_command'] = $complete($a, [$record(), $record(['activity_type' => null]), $wait]);
    $success = $record(['arguments' => $definition['empty_blob'], 'payload_codec' => 'avro', 'queue' => 'ignored-queue',
        'connection' => 'ignored-connection', 'retry_policy' => ['max_attempts' => 2, 'backoff_seconds' => [1]],
        'start_to_close_timeout' => 300, 'schedule_to_close_timeout' => 600, 'heartbeat_timeout' => 120,
        'attempts' => [
            ['attempt_id' => 'w-1', 'attempt_number' => 1, 'outcome' => 'failed', 'duration_ms' => 1500, 'message' => ' first λ ',
                'exception_type' => 'RuntimeException', 'retry_reason' => 'failure', 'backoff_seconds' => 1,
                'heartbeats' => [['elapsed_ms' => 500, 'details' => ['step' => ' one ']]]],
            ['attempt_id' => 'w-2', 'attempt_number' => 2, 'outcome' => 'completed', 'duration_ms' => 200, 'heartbeats' => []],
        ]]);
    $state['completed'] = $complete($a, [$success, $wait]);
    $state['completed_run'] = $observeRun($a);

    // Run B: a failure after retries and a timeout, then the run completes.
    $register('b', ['local_activities']);
    $b = $claim('b');
    $state['failed'] = $complete($b, [
        $record(['outcome' => 'failed', 'result' => null, 'message' => 'gave up', 'exception_type' => 'App\\Errors\\Fatal',
            'non_retryable' => true, 'retry_policy' => ['max_attempts' => 3, 'backoff_seconds' => [2, 0],
                'non_retryable_error_types' => [' App\\Errors\\Fatal ']],
            'attempts' => [
                ['attempt_id' => 'b-1', 'attempt_number' => 1, 'outcome' => 'timed_out', 'timeout_kind' => 'start_to_close',
                    'message' => 'slow', 'duration_ms' => 3000, 'retry_reason' => 'timeout', 'backoff_seconds' => 2,
                    'heartbeats' => [['elapsed_ms' => 1000, 'details' => []], ['elapsed_ms' => 1000, 'details' => new stdClass()],
                        ['elapsed_ms' => 2500, 'details' => ['p' => 2]]]],
                ['attempt_id' => 'b-2', 'attempt_number' => 2, 'outcome' => 'failed', 'message' => 'flaky',
                    'exception_type' => 'RuntimeException', 'retry_reason' => 'failure', 'backoff_seconds' => 0],
                ['attempt_id' => 'b-3', 'attempt_number' => 3, 'outcome' => 'failed', 'message' => 'fatal',
                    'exception_type' => 'App\\Errors\\Fatal', 'non_retryable' => true, 'duration_ms' => 40],
            ]]),
        $record(['activity_type' => $definition['activity_type'].'.slow', 'outcome' => 'timed_out', 'result' => null,
            'message' => 'deadline', 'timeout_kind' => 'schedule_to_close', 'schedule_to_close_timeout' => 30,
            'attempts' => [['attempt_number' => 1, 'outcome' => 'timed_out', 'timeout_kind' => 'schedule_to_close',
                'message' => 'deadline', 'duration_ms' => 30000]]]),
        ['type' => 'complete_workflow', 'result' => $done],
    ]);
    $state['failed_run'] = $observeRun($b);

    // Run C: the protocol 1.18 shape, where attempts are optional and unknown
    // nested fields are ignored.
    $register('c', ['local_activities']);
    $c = $claim('c');
    $bare = static function (array $fields) use ($record): array {
        $command = $record(['result' => null] + $fields);
        unset($command['attempts']);

        return $command;
    };
    $state['protocol_1_18_missing_result'] = $complete($c, [$bare(['outcome' => 'completed'])], '1.18');
    $state['protocol_1_18'] = $complete($c, [
        $bare(['outcome' => 'failed', 'message' => 'no attempts', 'exception_type' => 'RuntimeException', 'non_retryable' => false]),
        $bare(['outcome' => 'timed_out', 'message' => 'no attempts', 'timeout_kind' => 'heartbeat']),
        $bare(['outcome' => 'cancelled', 'message' => 'no attempts']),
        $record(['retry_policy' => ['max_attempts' => 1, 'jitter' => 1], 'attempts' => [['attempt_number' => 1,
            'outcome' => 'completed', 'shard' => 'x', 'heartbeats' => [['elapsed_ms' => 5, 'note' => 'x']]]]]),
        $wait,
    ], '1.18');
    $state['protocol_1_18_run'] = $observeRun($c);

    // Run E: a valid report whose first attempt alone has no attempt_id.
    $register('e', ['local_activities']);
    $e = $claim('e');
    $state['reordered_valid_report'] = $complete($e, [$record(['retry_policy' => ['max_attempts' => 2, 'backoff_seconds' => [0]],
        'attempts' => [
            ['attempt_number' => 1, 'outcome' => 'failed', 'message' => 'one', 'retry_reason' => 'failure', 'backoff_seconds' => 0],
            ['attempt_id' => 'e-2', 'attempt_number' => 2, 'outcome' => 'completed',
                'heartbeats' => [['elapsed_ms' => 5], ['elapsed_ms' => 9, 'details' => ['k' => 'v']]]],
        ]]), $wait]);

    // Run D: a worker without the capability.
    $register('d', []);
    $d = $claim('d');
    $state['refused_capability'] = $complete($d, [$record(), $wait]);
    $state['refused_capability_below_floor'] = $complete($d, [$record(), $wait], '1.17');
    $state['released_without_local'] = $complete($d, [$wait])['status'];

    return recordLocalActivityLabels($state, [$a, $b, $c, $e, $d]);
}

/**
 * Replaces run identities with labels and synthesized times with offsets.
 *
 * Identities are labelled in a fixed order that does not depend on either
 * server's key order: claimed runs and tasks, then for each run's history in
 * sequence order the execution, attempt and failure identities. An attempt
 * identity's label keeps its letter case (`:upper`, `:lower`): published
 * attempt identities are uppercase ULIDs, unlike its other identities. Inside each
 * Activity* event and activity view entry, attempt and execution times
 * become `@<milliseconds>` from that local activity's close; every other time
 * becomes `{time}`. The raw order of the attempt identities is recorded too.
 *
 * @param list<array<string, mixed>> $claims
 */
function recordLocalActivityLabels(array $state, array $claims): array
{
    $labels = [];
    $counters = [];
    $label = static function (?string $raw, string $kind) use (&$labels, &$counters): void {
        if ($raw === null || $raw === '' || isset($labels[$raw])) {
            return;
        }
        $counters[$kind] = ($counters[$kind] ?? 0) + 1;
        $labels[$raw] = $kind.'-'.$counters[$kind];
        if ($kind === 'attempt') {
            $labels[$raw] .= preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $raw) === 1 ? ':upper'
                : (preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/D', $raw) === 1 ? ':lower' : ':other');
        }
    };
    foreach ($claims as $claim) {
        $label($claim['run_id'], 'run');
        $label($claim['task_id'], 'task');
    }
    $time = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D';
    $instant = static fn (string $value): float => (float) (new DateTimeImmutable($value))->format('U.u');
    $offset = static function (mixed $value, ?float $anchor) use ($time, $instant): mixed {
        if (! is_string($value) || preg_match($time, $value) !== 1 || $anchor === null) {
            return $value;
        }
        $micros = (int) round(($instant($value) - $anchor) * 1_000_000);

        return $micros % 1000 === 0 ? '@'.intdiv($micros, 1000).'ms' : '@'.$micros.'us';
    };
    $attemptOrder = [];
    foreach ($state as $step => $value) {
        if (! is_array($value) || ! array_key_exists('activity_events', $value)) {
            continue;
        }
        $anchors = [];
        foreach ($value['activity_events'] as $event) {
            $payload = $event['payload'] ?? [];
            $label($payload['activity_execution_id'] ?? null, 'execution');
            $label($payload['activity_attempt_id'] ?? null, 'attempt');
            $label($payload['failure_id'] ?? null, 'failure');
            if (in_array($event['event_type'] ?? null, ['ActivityCompleted', 'ActivityFailed', 'ActivityTimedOut', 'ActivityCancelled'], true)
                && is_string($payload['activity']['closed_at'] ?? null)) {
                $anchors[$payload['activity_execution_id'] ?? ''] = $instant($payload['activity']['closed_at']);
            }
            if (is_string($payload['activity_attempt_id'] ?? null)) {
                $attemptOrder[$step][$payload['activity_attempt_id']] = true;
            }
        }
        foreach ($value['activity_events'] as $index => $event) {
            $anchor = $anchors[$event['payload']['activity_execution_id'] ?? ''] ?? null;
            foreach (['heartbeat_at', 'cancelled_at'] as $field) {
                if (array_key_exists($field, $event['payload'] ?? [])) {
                    $state[$step]['activity_events'][$index]['payload'][$field] = $offset($event['payload'][$field], $anchor);
                }
            }
            foreach (['activity', 'activity_attempt'] as $snapshot) {
                foreach (['started_at', 'closed_at', 'last_heartbeat_at'] as $field) {
                    if (is_array($event['payload'][$snapshot] ?? null) && array_key_exists($field, $event['payload'][$snapshot])) {
                        $state[$step]['activity_events'][$index]['payload'][$snapshot][$field]
                            = $offset($event['payload'][$snapshot][$field], $anchor);
                    }
                }
            }
        }
        foreach ((array) ($value['activities'] ?? []) as $index => $activity) {
            $anchor = is_array($activity) ? ($anchors[$activity['id'] ?? ''] ?? null) : null;
            $walk = static function (mixed $node, string $key = '') use (&$walk, $offset, $anchor): mixed {
                if (is_array($node)) {
                    foreach ($node as $field => $child) {
                        $node[$field] = $walk($child, (string) $field);
                    }

                    return $node;
                }

                return in_array($key, ['created_at', 'scheduled_at', 'lease_expires_at'], true) ? $node : $offset($node, $anchor);
            };
            $state[$step]['activities'][$index] = $walk($activity);
        }
    }
    // The raw identities' sort order, as attempt labels.
    foreach ($attemptOrder as $step => $ids) {
        $ids = array_keys($ids);
        sort($ids, SORT_STRING);
        $state[$step]['attempt_identity_order'] = array_map(static fn (string $id): string => $labels[$id] ?? $id, $ids);
    }
    $replace = static function (mixed $node) use (&$replace, $labels, $time): mixed {
        if (is_array($node)) {
            return array_map($replace, $node);
        }
        if (! is_string($node)) {
            return $node;
        }
        if (isset($labels[$node])) {
            return $labels[$node];
        }

        return preg_match($time, $node) === 1 ? (str_ends_with($node, 'Z') ? '{time}' : '{time+offset}') : $node;
    };

    return $replace($state);
}
