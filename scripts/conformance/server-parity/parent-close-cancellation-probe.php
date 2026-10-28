<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as ParentCloseCancellationHttp;
use GuzzleHttp\HandlerStack;

// A parent that closes while a child it started under the
// request_cancellation parent-close policy is still open, over raw worker
// HTTP on protocol 1.20 in a fresh namespace (Workflow
// ParentClosePolicyEnforcer, ParentCloseCancellation::ensureOrigin,
// WorkflowStub::attemptRequestCancellationFromParent).
//
// Without its own cooperative cancellation request, the closed parent
// records one policy-owned origin, ParentCloseCancellationRequested, after
// its terminal event, and each open child takes a descendant request under
// it. Each case closes a parent differently: it completes in the batch that
// starts its child, it fails in that batch, or it is terminated over HTTP
// with two such children open.
//
// Ids become labels by first appearance ({run:N}, {instance:N},
// {request:N}); instants become relations to the closure event.
function finishHttpParentCloseCancellation(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['parent_close_cancellation'];
    $namespace = strtolower($workflowId.'-pcc');
    $admin->createNamespace($namespace, 'parent close cancellation λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new ParentCloseCancellationHttp(['base_uri' => rtrim($url, '/'), 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $headers = array_filter([
        'Authorization' => $token === null ? null : 'Bearer '.$token,
        'Accept' => 'application/json', 'X-Namespace' => $namespace,
        'X-Durable-Workflow-Control-Plane-Version' => '2', 'X-Durable-Workflow-Protocol-Version' => '1.20',
    ]);
    $raw = static function (string $method, string $path, ?array $body = []) use ($http, $headers): array {
        $options = ['headers' => $headers];
        if ($body !== null) {
            $options['json'] = $body === [] ? (object) [] : $body;
        }
        $response = $http->request($method, $path, $options);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return ['status' => $response->getStatusCode(), 'response' => $decoded];
    };
    $ok = static function (array $recorded, int $status, string $operation): array {
        if ($recorded['status'] !== $status) {
            throw new RuntimeException('Parent close cancellation '.$operation.': '.json_encode($recorded));
        }

        return $recorded['response'];
    };
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $type = $definition['workflow_type'];
    $register = static function (string $worker, string $queue) use ($raw, $ok, $type): void {
        $ok($raw('POST', '/api/worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
            'sdk_version' => 'raw-http', 'supported_workflow_types' => [$type], 'supported_activity_types' => [],
            'capabilities' => ['cooperative_cancellation'], 'capability_manifest' => array_fill_keys(['local_activities',
                'worker_sessions', 'sticky_execution'], ['supported' => false, 'minimum_protocol_version' => '1.18',
                'reason' => 'raw_http'])]), 201, 'registration');
    };
    $claim = static function (string $worker, string $queue) use ($raw, $ok): ?array {
        return $ok($raw('POST', '/api/worker/workflow-tasks/poll', ['worker_id' => $worker,
            'task_queue' => $queue, 'timeout_seconds' => 2]), 200, 'workflow claim')['task'] ?? null;
    };
    $history = static fn (string $instance, string $run): array => $ok($raw('GET',
        '/api/workflows/'.rawurlencode($instance).'/runs/'.rawurlencode($run).'/history', null), 200, 'history')['events'] ?? [];

    // Ids become labels by first appearance, whatever their case.
    $labels = [];
    $label = static function (mixed $id, string $kind) use (&$labels): mixed {
        if (! is_string($id)) {
            return $id;
        }
        $key = $kind.':'.strtolower($id);
        if (! isset($labels[$key])) {
            $labels[$key] = '{'.$kind.':'.(count(array_filter(array_keys($labels),
                static fn (string $known): bool => str_starts_with($known, $kind.':'))) + 1).'}';
        }

        return $labels[$key];
    };
    // A cancellation context with labeled ids and instants as relations.
    $context = static function (mixed $context, callable $instant) use ($label): mixed {
        if (! is_array($context)) {
            return $context;
        }
        foreach (['request_id', 'root_request_id', 'parent_request_id'] as $field) {
            $context[$field] = $label($context[$field] ?? null, 'request');
        }
        $context['root_workflow_instance_id'] = $label($context['root_workflow_instance_id'] ?? null, 'instance');
        $context['root_workflow_run_id'] = $label($context['root_workflow_run_id'] ?? null, 'run');
        $context['requester']['id'] = $label($context['requester']['id'] ?? null, 'instance');
        $context['requested_at'] = $instant($context['requested_at'] ?? null);
        $context['cleanup_deadline_at'] = $instant($context['cleanup_deadline_at'] ?? null);
        $context['lineage'] = array_map(static fn (array $entry): array => [
            'request_id' => $label($entry['request_id'] ?? null, 'request'),
            'workflow_instance_id' => $label($entry['workflow_instance_id'] ?? null, 'instance'),
            'workflow_run_id' => $label($entry['workflow_run_id'] ?? null, 'run')], $context['lineage'] ?? []);

        return $context;
    };

    // The request's command snapshot: who recorded it and how. Its payload
    // encodes generated ids, so only its codec is kept.
    $command = static function (mixed $command) use ($label): mixed {
        if (! is_array($command)) {
            return $command;
        }
        $command['id'] = $label($command['id'] ?? null, 'request');
        $command['resolved_run_id'] = $label($command['resolved_run_id'] ?? null, 'run');
        unset($command['payload'], $command['request_path'], $command['request_route_name'], $command['request_fingerprint']);
        foreach (['accepted_at', 'applied_at', 'rejected_at'] as $field) {
            if (array_key_exists($field, $command)) {
                $command[$field] = is_string($command[$field]);
            }
        }
        if (is_array($command['context']['workflow'] ?? null)) {
            $workflow = &$command['context']['workflow'];
            $workflow['parent_instance_id'] = $label($workflow['parent_instance_id'] ?? null, 'instance');
            $workflow['parent_run_id'] = $label($workflow['parent_run_id'] ?? null, 'run');
            $workflow['child_call_id'] = $label($workflow['child_call_id'] ?? null, 'call');
            unset($workflow);
        }
        ksort($command);

        return $command;
    };

    // A command as the run description lists it: who recorded it and its
    // outcome. Request fingerprints and route names are server-specific.
    $describedCommand = static function (array $command) use ($label): array {
        $command['id'] = $label($command['id'] ?? null, 'request');
        $command['resolved_run_id'] = $label($command['resolved_run_id'] ?? null, 'run');
        foreach (['accepted_at', 'applied_at', 'rejected_at'] as $field) {
            if (array_key_exists($field, $command)) {
                $command[$field] = is_string($command[$field]);
            }
        }
        unset($command['request_path'], $command['request_route_name'], $command['request_fingerprint']);
        ksort($command);

        return $command;
    };

    $state = ['applicable' => true];
    foreach ($definition['cases'] as $case => $shape) {
        $id = $workflowId.'-'.$case;
        $queue = $id.'-queue';
        $worker = $id.'-worker';
        $register($worker, $queue);
        $started = $ok($raw('POST', '/api/workflows', ['workflow_id' => $id, 'workflow_type' => $type,
            'task_queue' => $queue, 'input' => $empty]), 201, 'start');
        $label($id, 'instance');
        $label($started['run_id'], 'run');
        $task = $claim($worker, $queue);
        $commands = [];
        for ($child = 0; $child < $shape['children']; $child++) {
            $commands[] = ['type' => 'start_child_workflow', 'workflow_type' => $type, 'arguments' => $empty,
                'parent_close_policy' => 'request_cancellation'];
        }
        if ($shape['close'] === 'complete') {
            $commands[] = ['type' => 'complete_workflow', 'result' => $empty];
        } elseif ($shape['close'] === 'fail') {
            $commands[] = ['type' => 'fail_workflow', 'message' => 'parent failed'];
        }
        $completed = $raw('POST', '/api/worker/workflow-tasks/'.$task['task_id'].'/complete', ['lease_owner' => $task['lease_owner'],
            'workflow_task_attempt' => $task['workflow_task_attempt'], 'commands' => $commands]);
        $observed = ['completed' => ['status' => $completed['status'], 'outcome' => $completed['response']['outcome'] ?? null,
            'reason' => $completed['response']['reason'] ?? null, 'run_status' => $completed['response']['run_status'] ?? null,
            'created_tasks' => count($completed['response']['created_task_ids'] ?? [])]];
        // A child that already has its own request, under another root.
        if ($shape['child_request'] ?? false) {
            foreach ($history($id, $started['run_id']) as $event) {
                if (($event['event_type'] ?? null) === 'ChildWorkflowScheduled') {
                    $requested = $raw('POST', '/api/workflows/'.rawurlencode($event['payload']['child_workflow_instance_id'])
                        .'/request-cancellation', ['reason' => 'child asked first']);
                    $observed['child_requested'] = ['status' => $requested['status'],
                        'outcome' => $requested['response']['outcome'] ?? null];
                }
            }
        }
        if ($shape['close'] === 'terminate') {
            $terminated = $raw('POST', '/api/workflows/'.$id.'/terminate', ['reason' => 'parent close fixture']);
            $observed['terminated'] = ['status' => $terminated['status'], 'outcome' => $terminated['response']['outcome'] ?? null,
                'reason' => $terminated['response']['reason'] ?? null];
        }
        $observed['parent_status'] = $raw('GET', '/api/workflows/'.$id, null)['response']['status'] ?? null;
        $events = $history($id, $started['run_id']);
        $observed['parent_events'] = array_map(static fn (array $event): ?string => $event['event_type'] ?? null, $events);

        // The closure is the parent's latest terminal event; the origin's
        // request time is its timestamp and its deadline 600 seconds later.
        $closure = null;
        $origin = null;
        foreach ($events as $event) {
            if (in_array($event['event_type'] ?? null, ['WorkflowCompleted', 'WorkflowFailed', 'WorkflowCancelled',
                'WorkflowTerminated', 'WorkflowTimedOut'], true)) {
                $closure = $event;
            }
            if (($event['event_type'] ?? null) === 'ParentCloseCancellationRequested' && $origin === null) {
                $origin = $event;
            }
        }
        $requestedAt = $origin['payload']['cancellation']['requested_at'] ?? null;
        $deadline = $origin['payload']['cancellation']['cleanup_deadline_at'] ?? null;
        $instant = static fn (mixed $value): mixed => match (true) {
            ! is_string($value) => $value,
            $value === $requestedAt => '{origin_requested_at}',
            $value === $deadline => '{origin_cleanup_deadline_at}',
            default => '{instant}',
        };
        $observed['origin'] = $origin === null ? null : [
            'parent_terminal_history_event_id' => is_string($origin['payload']['parent_terminal_history_event_id'] ?? null),
            'parent_terminal_event_type' => $origin['payload']['parent_terminal_event_type'] ?? null,
            'requested_at_is_closure' => is_string($requestedAt) && $requestedAt === ($closure['timestamp'] ?? null),
            'deadline_is_requested_plus_600_seconds' => is_string($requestedAt) && is_string($deadline)
                && (new DateTimeImmutable($requestedAt))->modify('+600 seconds')->format('Y-m-d\\TH:i:s.u\\Z') === $deadline,
            'cancellation' => $context($origin['payload']['cancellation'] ?? null, $instant)];
        $observed['policy_events'] = array_values(array_map(static function (array $event) use ($label, $context, $instant): array {
            $payload = $event['payload'] ?? [];
            foreach (['request_id' => 'request', 'child_instance_id' => 'instance', 'child_run_id' => 'run'] as $field => $kind) {
                if (array_key_exists($field, $payload)) {
                    $payload[$field] = $label($payload[$field], $kind);
                }
            }
            if (array_key_exists('cancellation', $payload)) {
                $payload['cancellation'] = $context($payload['cancellation'], $instant);
            }
            if (is_array($payload['request_diagnostics'] ?? null)) {
                foreach ($payload['request_diagnostics'] as $field => $value) {
                    $payload['request_diagnostics'][$field] = str_ends_with((string) $field, '_at')
                        ? $instant($value) : $label($value, 'request');
                }
            }

            return ['event_type' => $event['event_type'], 'payload' => $payload];
        }, array_filter($events, static fn (array $event): bool => str_starts_with((string) ($event['event_type'] ?? ''),
            'ParentClosePolicy'))));

        $children = [];
        foreach ($events as $event) {
            if (($event['event_type'] ?? null) !== 'ChildWorkflowScheduled') {
                continue;
            }
            $childId = $event['payload']['child_workflow_instance_id'];
            $childRun = $event['payload']['child_workflow_run_id'];
            $childEvents = $history($childId, $childRun);
            $requested = array_values(array_filter($childEvents, static fn (array $row): bool =>
                ($row['event_type'] ?? null) === 'CooperativeCancellationRequested'));
            $request = $requested[0]['payload'] ?? null;
            $described = $raw('GET', '/api/workflows/'.rawurlencode($childId), null)['response'];
            $children[] = ['instance' => $label($childId, 'instance'), 'run' => $label($childRun, 'run'),
                'status' => $described['status'] ?? null,
                'commands' => array_map($describedCommand, is_array($described['commands'] ?? null) ? $described['commands'] : []),
                'events' => array_map(static fn (array $row): ?string => $row['event_type'] ?? null, $childEvents),
                'request' => $request === null ? null : [
                    'workflow_command_id' => $label($request['workflow_command_id'] ?? null, 'request'),
                    'workflow_instance_id' => $label($request['workflow_instance_id'] ?? null, 'instance'),
                    'workflow_run_id' => $label($request['workflow_run_id'] ?? null, 'run'),
                    'command_type' => $request['command_type'] ?? null, 'reason' => $request['reason'] ?? null,
                    'cleanup_deadline_at' => $instant($request['cleanup_deadline_at'] ?? null),
                    'cancellation' => $context($request['cancellation'] ?? null, $instant),
                    'command' => $command($request['command'] ?? null),
                    'fields' => (static function (array $fields): array {
                        sort($fields);

                        return $fields;
                    })(array_keys($request))]];
        }
        $observed['children'] = $children;

        // Each child's first task carries its request; then the queue is empty.
        $tasks = [];
        for ($child = 0; $child <= $shape['children']; $child++) {
            $childTask = $claim($worker, $queue);
            $request = $childTask['cancellation_request'] ?? null;
            $tasks[] = $childTask === null ? null : ['run_id' => $label($childTask['run_id'] ?? null, 'run'),
                'history_events' => array_map(static fn (array $row): ?string => $row['event_type'] ?? null,
                    $childTask['history_events'] ?? []),
                'resume_source_kind' => $childTask['resume_source_kind'] ?? null,
                'cancellation_request' => is_array($request) ? [
                    'request_id' => $label($request['request_id'] ?? null, 'request'),
                    'requested_at' => is_string($request['requested_at'] ?? null),
                    'cleanup_deadline_at' => $instant($request['cleanup_deadline_at'] ?? null),
                    'delivery_sequence' => $request['delivery_sequence'] ?? null,
                    'delivered_at' => $request['delivered_at'] ?? null,
                    'history_refresh_page_token' => $request['history_refresh_page_token'] ?? null] : $request];
        }
        $observed['child_tasks'] = $tasks;
        $state[$case] = $observed;
    }

    return $state;
}
