<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as CancellationScopesHttp;
use GuzzleHttp\HandlerStack;

// Workflow-task cancellation scopes
// (POST /api/worker/workflow-tasks/{task}/cancellation-scopes/{open,prepare,deliver})
// over raw worker HTTP in a fresh namespace, with cooperative workers on
// protocol 1.20 that register as a Python runtime. Each run has its own queue
// and workers.
//
// The main run opens a scope tree (S1, S2 under S1, shielded S3 under S1, S4
// under S2), schedules scoped members on completion (an activity in S1, a
// timer in S2 and one in S3) and claims the activity. A run cancellation
// request then requests every unshielded scope; the next claim prepares S1 at
// its activity and delivers it, which fences the activity and S2's timer. The
// activity worker sees the fence and acknowledges it with the scope's request.
// Each route is also refused for every gate, validation and history fault the
// published server reports, and completions are refused or admitted by scope.
// A second run prepares a descendant scope before its parent, so the parent's
// delivery waits for it, and a root completion is refused at a reserved
// boundary. Two more claims cannot open or prepare: one from a worker without
// the capability, one polled on protocol 1.19.
//
// The observation is normalized here: identities become labels (named where
// the probe knows them, else `{ulid:N}` by first appearance with object keys
// in sorted order, case-insensitive, since servers differ in key order and
// ULID case), digests become `{sha256:N}`, the run
// request's two instants become named anchors and every other instant becomes
// its format, `{time:Z<fraction digits>}`. Relations that depend on the clock
// are recorded as measured facts, never as raw times.
function finishHttpCancellationScopes(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['cancellation_scopes'];
    $namespace = strtolower($workflowId.'-csc');
    $admin->createNamespace($namespace, 'cancellation scopes λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    // The SQLite reference shares its database with a one-second maintenance
    // loop; retry its retryable lock-pressure refusals, as the other probes do.
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new CancellationScopesHttp(['base_uri' => rtrim($url, '/'), 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $raw = static function (string $method, string $path, ?array $body = [], ?string $protocol = '1.20', ?string $space = null) use ($http, $token, $namespace): array {
        $options = ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $space ?? $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2', 'X-Durable-Workflow-Protocol-Version' => $protocol,
        ])];
        if ($body !== null) {
            $options['json'] = $body === [] ? (object) [] : $body;
        }
        $response = $http->request($method, $path, $options);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        // The worker envelope's capability map is recorded by presence only;
        // discovery records the cooperative flag once.
        if (is_array($decoded) && array_key_exists('server_capabilities', $decoded)) {
            $decoded['server_capabilities'] = '{present}';
        }
        unset($decoded['control_plane']);

        return ['status' => $response->getStatusCode(), 'response' => $decoded];
    };
    $ok = static function (array $recorded, int $status, string $operation): array {
        if ($recorded['status'] !== $status) {
            throw new RuntimeException('Cancellation scopes '.$operation.': '.json_encode($recorded));
        }

        return $recorded['response'];
    };
    // Avro payload precomputed by the published serializer: [].
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $type = $definition['workflow_type'];
    $activityType = $definition['activity_type'];
    $budget = $definition['cleanup_timeout_seconds'];
    // Labels for identities and instants the probe knows by name.
    $names = [];
    $name = static function (?string $value, string $label) use (&$names): void {
        if (is_string($value) && $value !== '') {
            $names[strtoupper($value)] ??= '{'.$label.'}';
        }
    };
    $register = static function (string $worker, string $queue, array $capabilities) use ($raw, $ok, $type, $activityType): void {
        $ok($raw('POST', '/api/worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'python',
            'sdk_version' => 'raw-http', 'supported_workflow_types' => [$type], 'supported_activity_types' => [$activityType],
            'capabilities' => $capabilities, 'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
                ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http'])]), 201, 'registration '.$worker);
    };
    // Registrations go stale after 30 seconds; polls do not refresh them.
    $alive = static function (string ...$workers) use ($raw, $ok): void {
        foreach ($workers as $worker) {
            $ok($raw('POST', '/api/worker/heartbeat', ['worker_id' => $worker]), 200, 'worker heartbeat '.$worker);
        }
    };
    $start = static function (string $run, string $queue) use ($raw, $ok, $type, $empty, $workflowId, $name): array {
        $id = $workflowId.'-'.strtolower($run);
        $started = $ok($raw('POST', '/api/workflows', ['workflow_id' => $id, 'workflow_type' => $type, 'task_queue' => $queue,
            'input' => $empty]), 201, 'start '.$run);
        $name($started['run_id'], $run);

        return ['workflow_id' => $id, 'run_id' => $started['run_id'], 'label' => $run];
    };
    $poll = static function (string $worker, string $queue, string $label, string $protocol = '1.20') use ($raw, $ok, $name): array {
        $before = microtime(true);
        $task = $ok($raw('POST', '/api/worker/workflow-tasks/poll', ['worker_id' => $worker, 'task_queue' => $queue,
            'timeout_seconds' => 0], $protocol), 200, 'poll '.$label)['task'] ?? null;
        if (! is_array($task)) {
            throw new RuntimeException('Cancellation scopes poll '.$label.': no task');
        }
        $name($task['task_id'], $label);

        return $task + ['_polled_at' => [$before, microtime(true)]];
    };
    $own = static fn (array $task): array => ['lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt']];
    $route = static fn (string $action, array $task, ?array $body, ?string $protocol = '1.20', ?string $space = null, ?string $taskId = null): array => $raw('POST',
        '/api/worker/workflow-tasks/'.($taskId ?? $task['task_id']).'/cancellation-scopes/'.$action, $body, $protocol, $space);
    $complete = static fn (array $task, array $commands, ?string $protocol = '1.20'): array => $raw('POST',
        '/api/worker/workflow-tasks/'.$task['task_id'].'/complete', $own($task) + ['commands' => $commands], $protocol);
    $timer = static fn (?string $scope): array => ['type' => 'start_timer', 'delay_seconds' => 3600]
        + ($scope === null ? [] : ['cancellation_scope_id' => $scope]);
    $events = static fn (array $run): array => $ok($raw('GET', '/api/workflows/'.$run['workflow_id'].'/runs/'.$run['run_id'].'/history', null),
        200, 'history '.$run['label'])['events'] ?? [];
    // Whether an instant lies `seconds` after a measured window, within a
    // half-second of either end.
    $after = static function (?string $instant, array $window, float $seconds): bool {
        if (! is_string($instant)) {
            return false;
        }
        $at = (float) (new DateTimeImmutable($instant))->format('U.u');

        return $at >= $window[0] + $seconds - 0.5 && $at <= $window[1] + $seconds + 0.5;
    };
    // Whether an instant lies after another by at most `seconds` (and a
    // half-second of rounding).
    $within = static fn (?string $later, ?string $earlier, float $seconds): bool => is_string($later) && is_string($earlier)
        && ($gap = (float) (new DateTimeImmutable($later))->format('U.u') - (float) (new DateTimeImmutable($earlier))->format('U.u')) > 0
        && $gap <= $seconds + 0.5;
    $seconds = static fn (?string $later, ?string $earlier): ?int => is_string($later) && is_string($earlier)
        ? (int) round((float) (new DateTimeImmutable($later))->format('U.u') - (float) (new DateTimeImmutable($earlier))->format('U.u'))
        : null;
    // A history event's claim or task snapshot, without its timing fields.
    $snapshot = static fn (mixed $task): mixed => is_array($task) ? array_intersect_key($task,
        array_flip(['id', 'type', 'status', 'lease_owner', 'attempt_count'])) : $task;
    $field = static fn (array $values, string $key): mixed => array_key_exists($key, $values) ? $values[$key] : '{absent}';
    // A history projection: every event's type, the scope events whole (claim
    // snapshots projected), the scope of each scheduled member, and the
    // scope-relevant part of a cancelled activity. Other payloads are covered
    // by their own fixtures. The worker view also carries each event's task
    // and command columns.
    $project = static function (array $raw, bool $columns) use ($snapshot, $field, $within): array {
        $projected = [];
        foreach ($raw as $event) {
            $payload = $event['payload'] ?? [];
            $item = ['sequence' => $event['sequence'] ?? null, 'event_type' => $event['event_type'] ?? null];
            if ($columns) {
                $item += ['workflow_task_id' => $event['workflow_task_id'] ?? null, 'workflow_command_id' => $event['workflow_command_id'] ?? null];
            }
            switch ($item['event_type']) {
                case 'CancellationScopeOpened':
                case 'CancellationScopeRequested':
                case 'CancellationScopeDeliveryPrepared':
                case 'CancellationScopeDelivered':
                case 'TimerCancelled':
                case 'ActivityCancellationAcknowledged':
                    if (array_key_exists('task', $payload)) {
                        if (in_array($item['event_type'], ['CancellationScopeDeliveryPrepared', 'CancellationScopeDelivered'], true)) {
                            // Preparation and delivery hold the claim on the
                            // ten-second cleanup lease, renewed before they record.
                            $item['claim_lease_within_ten_seconds'] = $within($payload['task']['lease_expires_at'] ?? null,
                                $event['recorded_at'] ?? $event['timestamp'] ?? null, 10.0);
                        }
                        $payload['task'] = $snapshot($payload['task']);
                    }
                    $item['payload'] = $payload;
                    break;
                case 'ActivityScheduled':
                    $item['payload'] = ['sequence' => $payload['sequence'] ?? null,
                        'cancellation_scope_id' => $field($payload, 'cancellation_scope_id'),
                        'activity.cancellation_scope_id' => $field($payload['activity'] ?? [], 'cancellation_scope_id'),
                        'activity.cancellation_policy' => $field($payload['activity'] ?? [], 'cancellation_policy')];
                    break;
                case 'TimerScheduled':
                    $item['payload'] = ['sequence' => $payload['sequence'] ?? null,
                        'cancellation_scope_id' => $field($payload, 'cancellation_scope_id')];
                    break;
                case 'ActivityCancelled':
                    $activity = $payload['activity'] ?? [];
                    $attempt = $payload['activity_attempt'] ?? null;
                    $item['payload'] = array_diff_key($payload, array_flip(['activity', 'activity_attempt', 'task'])) + [
                        'activity' => ['id' => $field($activity, 'id'), 'sequence' => $field($activity, 'sequence'),
                            'status' => $field($activity, 'status'), 'attempt_id' => $field($activity, 'attempt_id'),
                            'cancellation_scope_id' => $field($activity, 'cancellation_scope_id'),
                            'cancellation_policy' => $field($activity, 'cancellation_policy')],
                        'activity_attempt' => is_array($attempt) ? array_intersect_key($attempt,
                            array_flip(['id', 'activity_execution_id', 'task_id', 'status', 'lease_owner'])) : $attempt,
                        'task' => $snapshot($payload['task'] ?? null)];
                    break;
            }
            $projected[] = $item;
        }

        return $projected;
    };
    $history = static fn (array $run): array => $project($events($run), false);
    // The worker's view of the claimed run's history, page by page from the
    // refresh token scope routes return.
    $taskHistory = static function (array $task) use ($raw, $ok, $own, $project): array {
        $all = [];
        $token = 'MA==';
        do {
            $page = $ok($raw('POST', '/api/worker/workflow-tasks/'.$task['task_id'].'/history',
                $own($task) + ['next_history_page_token' => $token]), 200, 'task history');
            array_push($all, ...($page['history_events'] ?? []));
            $token = $page['next_history_page_token'] ?? null;
        } while ($token !== null);

        return $project($all, true);
    };
    // The scope request ids history records for a run, by scope.
    $requests = static function (array $run) use ($events): array {
        $found = [];
        foreach ($events($run) as $event) {
            if (($event['event_type'] ?? null) === 'CancellationScopeRequested') {
                $found[$event['payload']['scope_id']] = $event['payload']['request_id'];
            }
        }

        return $found;
    };
    $cancel = static fn (array $run): array => $raw('POST', '/api/workflows/'.$run['workflow_id'].'/runs/'.$run['run_id'].'/request-cancellation',
        ['reason' => 'cancellation scopes fixture λ', 'cleanup_timeout_seconds' => $budget]);
    // The claim a cancellation request leaves for the workflow worker.
    $claimed = static function (array $task) use ($after): array {
        return ['resume_source_kind' => $task['resume_source_kind'] ?? '{absent}', 'resume_source_id' => $task['resume_source_id'] ?? '{absent}',
            'workflow_wait_kind' => $task['workflow_wait_kind'] ?? '{absent}', 'open_wait_id' => $task['open_wait_id'] ?? '{absent}',
            'workflow_task_attempt' => $task['workflow_task_attempt'] ?? null, 'cancellation_request' => $task['cancellation_request'] ?? null,
            'lease_ten_seconds_from_poll' => $after($task['lease_expires_at'] ?? null, $task['_polled_at'], 10.0)];
    };
    $boundary = static fn (array $task, string $scope, string $request, int $sequence, string $kind, array $extra = []): array => $own($task)
        + ['scope_id' => $scope, 'request_id' => $request, 'sequence' => $sequence, 'call_kind' => $kind] + $extra;
    $heartbeat = static fn (array $task): int => $raw('POST', '/api/worker/workflow-tasks/'.$task['task_id'].'/heartbeat', $own($task))['status'];

    $state = ['applicable' => true, 'namespace' => $namespace];
    $cluster = $ok($raw('GET', '/api/cluster/info', null), 200, 'cluster info');
    $capabilities = $cluster['worker_protocol']['server_capabilities'] ?? [];
    $state['discovery'] = ['cooperative_cancellation' => $capabilities['cooperative_cancellation'] ?? 'absent',
        'scope_capabilities' => array_values(array_filter(array_keys($capabilities), static fn ($key): bool => str_contains((string) $key, 'scope')))];

    // ---- R1: the scope tree, its members, a request and S1's delivery ----
    $queue = $workflowId.'-r1-queue';
    $worker = $workflowId.'-r1-worker';
    $activityQueue = $workflowId.'-r1-activities';
    $activityWorker = $workflowId.'-r1-activity-worker';
    $register($worker, $queue, ['cooperative_cancellation']);
    $register($activityWorker, $activityQueue, ['cooperative_cancellation']);
    $r1 = $start('R1', $queue);
    $t1 = $poll($worker, $queue, 'R1.T1');
    $owned = $own($t1);

    // Gates and validation, in the controller's order.
    $state['open_protocol_missing'] = $route('open', $t1, $owned + ['sequence' => 1], null);
    $state['open_protocol_1_19'] = $route('open', $t1, [], '1.19');
    $state['open_protocol_1_21'] = $route('open', $t1, $owned + ['sequence' => 1], '1.21');
    $state['open_missing_fields'] = $route('open', $t1, []);
    $state['open_invalid_fields'] = $route('open', $t1, ['lease_owner' => 5, 'workflow_task_attempt' => 0, 'sequence' => 'x',
        'parent_scope_id' => '', 'shield_parent' => 'yes']);
    $state['open_null_parent'] = $route('open', $t1, $owned + ['sequence' => 1, 'parent_scope_id' => null, 'shield_parent' => null]);
    $state['open_unknown_task'] = $route('open', $t1, $owned + ['sequence' => 1], taskId: 'no-such-task');
    $state['open_unknown_namespace'] = $route('open', $t1, $owned + ['sequence' => 1], space: $namespace.'-missing');
    $state['open_foreign_owner'] = $route('open', $t1, ['lease_owner' => 'someone-else', 'workflow_task_attempt' => 1, 'sequence' => 1]);
    $state['open_wrong_attempt'] = $route('open', $t1, ['lease_owner' => $t1['lease_owner'], 'workflow_task_attempt' => 2, 'sequence' => 1]);
    $state['open_sequence_ahead'] = $route('open', $t1, $owned + ['sequence' => 2]);
    $state['open_unknown_parent'] = $route('open', $t1, $owned + ['sequence' => 1, 'parent_scope_id' => 'NO-SUCH-SCOPE']);

    // The tree. Digit strings pass Laravel's integer rule, and the parent is
    // trimmed. Replays return the recorded scope; another shield does not.
    $state['open_s1'] = $route('open', $t1, ['lease_owner' => $t1['lease_owner'], 'workflow_task_attempt' => '1', 'sequence' => '1',
        'parent_scope_id' => ' root ']);
    $s1 = $state['open_s1']['response']['scope_id'] ?? 'missing-s1';
    $name($s1, 'R1.S1');
    $state['open_s1_again'] = $route('open', $t1, $owned + ['sequence' => 1]);
    $state['open_s1_explicit_root'] = $route('open', $t1, $owned + ['sequence' => 1, 'parent_scope_id' => 'root', 'shield_parent' => false]);
    $state['open_s1_shielded_replay'] = $route('open', $t1, $owned + ['sequence' => 1, 'shield_parent' => true]);
    $state['open_s2'] = $route('open', $t1, $owned + ['sequence' => 2, 'parent_scope_id' => $s1]);
    $s2 = $state['open_s2']['response']['scope_id'] ?? 'missing-s2';
    $name($s2, 'R1.S2');
    $state['open_s3'] = $route('open', $t1, $owned + ['sequence' => 3, 'parent_scope_id' => $s1, 'shield_parent' => true]);
    $s3 = $state['open_s3']['response']['scope_id'] ?? 'missing-s3';
    $name($s3, 'R1.S3');
    $state['open_s4'] = $route('open', $t1, $owned + ['sequence' => 4, 'parent_scope_id' => $s2]);
    $name($state['open_s4']['response']['scope_id'] ?? null, 'R1.S4');

    // Completion admission by scope, then the members: S1's activity at 5,
    // S2's timer at 6 and shielded S3's timer at 7.
    $state['complete_scoped_side_effect'] = $complete($t1, [['type' => 'record_side_effect', 'result' => null, 'cancellation_scope_id' => $s1]]);
    $state['complete_unknown_scope'] = $complete($t1, [$timer('NO-SUCH-SCOPE')]);
    $state['complete_scope_protocol_1_19'] = $complete($t1, [$timer($s2)], '1.19');
    $state['complete_members'] = $complete($t1, [
        ['type' => 'schedule_activity', 'activity_type' => $activityType, 'arguments' => $empty, 'queue' => $activityQueue,
            'start_to_close_timeout' => 600, 'cancellation_scope_id' => $s1],
        $timer($s2), $timer($s3)]);
    $activity = $ok($raw('POST', '/api/worker/activity-tasks/poll', ['worker_id' => $activityWorker, 'task_queue' => $activityQueue,
        'timeout_seconds' => 0]), 200, 'activity claim')['task'] ?? [];
    $name($activity['task_id'] ?? null, 'R1.activity.task');
    $name($activity['activity_attempt_id'] ?? null, 'R1.activity.attempt');
    $name($activity['activity_execution_id'] ?? null, 'R1.activity');
    $attempt = ['activity_attempt_id' => $activity['activity_attempt_id'] ?? null, 'lease_owner' => $activity['lease_owner'] ?? null];
    $state['history_after_members'] = $history($r1);

    // The run request asks every unshielded scope, S3 excepted, and leaves a
    // scope-request task.
    $alive($worker, $activityWorker);
    $state['request'] = $cancel($r1);
    $runRequest = $state['request']['response']['cancellation_request'] ?? [];
    $name($runRequest['request_id'] ?? null, 'R1.request');
    $name($runRequest['requested_at'] ?? null, 'R1.requested_at');
    $name($runRequest['cleanup_deadline_at'] ?? null, 'R1.cleanup_deadline_at');
    $state['request_budget_seconds'] = $seconds($runRequest['cleanup_deadline_at'] ?? null, $runRequest['requested_at'] ?? null);
    $scopeRequests = $requests($r1);
    $name($scopeRequests[$s1] ?? null, 'R1.S1.request');
    $name($scopeRequests[$s2] ?? null, 'R1.S2.request');
    $name($scopeRequests[$state['open_s4']['response']['scope_id'] ?? ''] ?? null, 'R1.S4.request');
    $state['history_after_request'] = $history($r1);
    $t2 = $poll($worker, $queue, 'R1.T2');
    $state['claim_after_request'] = $claimed($t2);
    $rq1 = $scopeRequests[$s1] ?? 'missing-request';
    $rq2 = $scopeRequests[$s2] ?? 'missing-request';

    // Refusals on the new claim. The root delivery cannot reach a scoped
    // member; scope routes refuse the protocol, validation, ownership,
    // request and boundary faults.
    $state['root_delivery_at_scoped_activity'] = $raw('POST', '/api/worker/workflow-tasks/'.$t2['task_id'].'/deliver-cancellation',
        $own($t2) + ['request_id' => $runRequest['request_id'] ?? 'missing', 'sequence' => 5, 'call_kind' => 'activity']);
    $state['prepare_protocol_1_19'] = $route('prepare', $t2, [], '1.19');
    $state['deliver_protocol_1_19'] = $route('deliver', $t2, [], '1.19');
    $state['prepare_missing_fields'] = $route('prepare', $t2, []);
    $state['prepare_invalid_fields'] = $route('prepare', $t2, ['lease_owner' => 1, 'workflow_task_attempt' => 0, 'scope_id' => [],
        'request_id' => 2, 'sequence' => 0, 'call_kind' => 'service_operation', 'sequence_span' => null, 'operation_sequence' => 0,
        'operation_sequence_span' => 0]);
    $state['prepare_unknown_task'] = $route('prepare', $t2, $boundary($t2, $s1, $rq1, 5, 'activity'), taskId: 'no-such-task');
    $state['prepare_completed_claim'] = $route('prepare', $t1, $boundary($t1, $s1, $rq1, 5, 'activity'));
    $state['prepare_foreign_owner'] = $route('prepare', $t2, ['lease_owner' => 'someone-else'] + $boundary($t2, $s1, $rq1, 5, 'activity'));
    $state['prepare_wrong_request'] = $route('prepare', $t2, $boundary($t2, $s1, 'NO-SUCH-REQUEST', 5, 'activity'));
    $state['prepare_unknown_scope'] = $route('prepare', $t2, $boundary($t2, 'NO-SUCH-SCOPE', $rq1, 5, 'activity'));
    $state['prepare_shielded_scope'] = $route('prepare', $t2, $boundary($t2, $s3, $rq1, 7, 'timer'));
    $state['prepare_root_scope'] = $route('prepare', $t2, $boundary($t2, 'root', $rq1, 5, 'activity'));
    $state['deliver_root_scope'] = $route('deliver', $t2, $boundary($t2, 'root', $rq1, 5, 'activity'));
    $state['deliver_before_prepare'] = $route('deliver', $t2, $boundary($t2, $s1, $rq1, 5, 'activity'));
    $state['prepare_sequence_ahead'] = $route('prepare', $t2, $boundary($t2, $s1, $rq1, 99, 'activity'));
    $state['prepare_shape_mismatch'] = $route('prepare', $t2, $boundary($t2, $s1, $rq1, 5, 'timer'));
    $state['prepare_activity_span'] = $route('prepare', $t2, $boundary($t2, $s1, $rq1, 5, 'activity', ['sequence_span' => 2]));
    $state['open_after_request'] = $route('open', $t2, $own($t2) + ['sequence' => 8]);
    $state['open_replay_after_request'] = $route('open', $t2, $own($t2) + ['sequence' => 1]);
    $state['claim_heartbeat'] = $heartbeat($t2);

    // S1 prepared at its activity: its own activity, and S2 and S4 frozen as
    // descendants. A replay returns the same preparation; another boundary
    // does not.
    $state['prepare_s1'] = $route('prepare', $t2, $boundary($t2, $s1, $rq1, 5, 'activity'));
    $state['prepare_s1_again'] = $route('prepare', $t2, $boundary($t2, $s1, $rq1, 5, 'activity'));
    $state['prepare_s1_other_boundary'] = $route('prepare', $t2, $boundary($t2, $s1, $rq1, 6, 'timer'));
    $state['history_after_prepare'] = $history($r1);

    // S1 delivered: the activity and S2's timer are fenced, then the barrier.
    $state['deliver_s1_other_boundary'] = $route('deliver', $t2, $boundary($t2, $s1, $rq1, 6, 'timer'));
    $state['deliver_s1'] = $route('deliver', $t2, $boundary($t2, $s1, $rq1, 5, 'activity'));
    $state['history_after_delivery'] = $taskHistory($t2);
    $state['deliver_s1_again'] = $route('deliver', $t2, $boundary($t2, $s1, $rq1, 5, 'activity'));
    $state['history_after_redelivery'] = $history($r1);
    $state['prepare_s1_after_delivery'] = $route('prepare', $t2, $boundary($t2, $s1, $rq1, 5, 'activity'));
    $state['prepare_s2_after_effect'] = $route('prepare', $t2, $boundary($t2, $s2, $rq2, 6, 'timer'));

    // The activity worker sees the fence; the run request cannot acknowledge
    // it, S1's request can, once.
    $activityTask = '/api/worker/activity-tasks/'.($activity['task_id'] ?? 'missing');
    $state['activity_status_after_delivery'] = $raw('POST', $activityTask.'/status', $attempt);
    $state['activity_heartbeat_after_delivery'] = $raw('POST', $activityTask.'/heartbeat', $attempt);
    $state['activity_renew_after_delivery'] = $raw('POST', $activityTask.'/renew-lease', $attempt);
    $state['acknowledge_with_run_request'] = $raw('POST', $activityTask.'/acknowledge-cancellation',
        $attempt + ['request_id' => $runRequest['request_id'] ?? 'missing']);
    $state['acknowledge_with_scope_request'] = $raw('POST', $activityTask.'/acknowledge-cancellation', $attempt + ['request_id' => $rq1]);
    $state['acknowledge_with_scope_request_again'] = $raw('POST', $activityTask.'/acknowledge-cancellation', $attempt + ['request_id' => $rq1]);
    $state['activity_status_after_acknowledgement'] = $raw('POST', $activityTask.'/status', $attempt);

    // Completion admission after the preparation: S1 and its unshielded
    // descendant S2 are closed to new members; shielded S3 is not.
    $state['claim_heartbeat_after_delivery'] = $heartbeat($t2);
    $state['complete_in_prepared_scope'] = $complete($t2, [$timer($s1)]);
    $state['complete_in_prepared_descendant'] = $complete($t2, [$timer($s2)]);
    $state['complete_in_shielded_scope'] = $complete($t2, [$timer($s3)]);
    $state['history_final'] = $history($r1);

    // ---- R2: a descendant prepared before its parent ----
    $queue2 = $workflowId.'-r2-queue';
    $worker2 = $workflowId.'-r2-worker';
    $register($worker2, $queue2, ['cooperative_cancellation']);
    $r2 = $start('R2', $queue2);
    $u1 = $poll($worker2, $queue2, 'R2.T1');
    $state['r2_open_s1'] = $route('open', $u1, $own($u1) + ['sequence' => 1]);
    $p1 = $state['r2_open_s1']['response']['scope_id'] ?? 'missing-s1';
    $name($p1, 'R2.S1');
    $state['r2_open_s2'] = $route('open', $u1, $own($u1) + ['sequence' => 2, 'parent_scope_id' => $p1]);
    $p2 = $state['r2_open_s2']['response']['scope_id'] ?? 'missing-s2';
    $name($p2, 'R2.S2');
    $state['r2_complete_members'] = $complete($u1, [$timer($p1), $timer($p2)]);
    $alive($worker2);
    $state['r2_request'] = $cancel($r2);
    $r2Request = $state['r2_request']['response']['cancellation_request'] ?? [];
    $name($r2Request['request_id'] ?? null, 'R2.request');
    $name($r2Request['requested_at'] ?? null, 'R2.requested_at');
    $name($r2Request['cleanup_deadline_at'] ?? null, 'R2.cleanup_deadline_at');
    $r2Requests = $requests($r2);
    $name($r2Requests[$p1] ?? null, 'R2.S1.request');
    $name($r2Requests[$p2] ?? null, 'R2.S2.request');
    $u2 = $poll($worker2, $queue2, 'R2.T2');
    $state['r2_claim_after_request'] = $claimed($u2);
    $pq1 = $r2Requests[$p1] ?? 'missing-request';
    $pq2 = $r2Requests[$p2] ?? 'missing-request';
    // S2 at its timer, then S1 at the next, unscheduled boundary.
    $state['r2_prepare_s2'] = $route('prepare', $u2, $boundary($u2, $p2, $pq2, 4, 'timer'));
    $state['r2_prepare_s1'] = $route('prepare', $u2, $boundary($u2, $p1, $pq1, 5, 'timer'));
    $state['r2_complete_at_reserved_boundary'] = $complete($u2, [$timer(null)]);
    $state['r2_deliver_s1_pending'] = $route('deliver', $u2, $boundary($u2, $p1, $pq1, 5, 'timer'));
    $state['r2_history_after_pending'] = $history($r2);
    $state['r2_deliver_s2'] = $route('deliver', $u2, $boundary($u2, $p2, $pq2, 4, 'timer'));
    $state['r2_deliver_s1'] = $route('deliver', $u2, $boundary($u2, $p1, $pq1, 5, 'timer'));
    $state['r2_history_after_deliveries'] = $taskHistory($u2);
    // The delivered boundary consumed sequence 5: the next command is 6.
    $state['r2_complete_after_delivery'] = $complete($u2, [$timer(null)]);
    $state['r2_history'] = $history($r2);

    // ---- R3 and R4: claims that cannot open or prepare scopes ----
    $queue3 = $workflowId.'-r3-queue';
    $worker3 = $workflowId.'-r3-worker';
    $register($worker3, $queue3, []);
    $start('R3', $queue3);
    $v = $poll($worker3, $queue3, 'R3.T1');
    $state['plain_claim_open'] = $route('open', $v, $own($v) + ['sequence' => 1]);
    $state['plain_claim_prepare'] = $route('prepare', $v, $boundary($v, 'NO-SUCH-SCOPE', 'NO-SUCH-REQUEST', 1, 'timer'));
    $queue4 = $workflowId.'-r4-queue';
    $worker4 = $workflowId.'-r4-worker';
    $register($worker4, $queue4, ['cooperative_cancellation']);
    $start('R4', $queue4);
    $w = $poll($worker4, $queue4, 'R4.T1', '1.19');
    $state['legacy_claim_open'] = $route('open', $w, $own($w) + ['sequence' => 1]);
    $state['legacy_claim_prepare'] = $route('prepare', $w, $boundary($w, 'NO-SUCH-SCOPE', 'NO-SUCH-REQUEST', 1, 'timer'));

    return cancellationScopesNormalize($state, $names);
}

/**
 * Replaces run-varying values with labels: known identities and instants by
 * name, other ULIDs and SHA-256 digests by first appearance (object keys
 * in sorted order), and other instants by their format.
 *
 * @param array<string, string> $names
 */
function cancellationScopesNormalize(array $state, array $names): array
{
    $counters = ['ulid' => 0, 'sha256' => 0];
    $labels = $names;
    $label = static function (string $kind, string $key) use (&$labels, &$counters): string {
        if (! isset($labels[$key])) {
            $labels[$key] = '{'.$kind.':'.(++$counters[$kind]).'}';
        }

        return $labels[$key];
    };
    $ulid = '[0-9A-HJKMNP-TV-Z]{26}';
    $walk = static function (mixed $value) use (&$walk, &$labels, $label, $ulid): mixed {
        if (is_array($value)) {
            // Object keys are visited in sorted order, so labels do not
            // depend on the key order a server answers in.
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $walk($item);
            }

            return $out;
        }
        if (! is_string($value)) {
            return $value;
        }
        $upper = strtoupper($value);
        if (isset($labels[$upper])) {
            return $labels[$upper];
        }
        if (preg_match('/^'.$ulid.'$/', $upper)) {
            return $label('ulid', $upper);
        }
        if (preg_match('/^[0-9a-f]{64}$/', $value)) {
            return $label('sha256', $value);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.(\d+))?(Z|[+-]\d{2}:\d{2})$/', $value, $match)) {
            return '{time:'.($match[2] === 'Z' ? 'Z' : 'offset').strlen($match[1] ?? '').'}';
        }
        // `<kind>:<ULID>` identities, such as wait ids.
        if (preg_match('/^([a-z_]+):('.$ulid.')$/i', $value, $match)) {
            return $match[1].':'.$label('ulid', strtoupper($match[2]));
        }

        return $value;
    };

    return $walk($state);
}
