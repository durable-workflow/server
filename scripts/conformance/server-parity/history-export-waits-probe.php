<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Worker\CapabilityManifest;
use GuzzleHttp\Client as HistoryExportWaitsHttp;
use GuzzleHttp\HandlerStack;

// The waits and timeline sections of a history export (Workflow
// HistoryExport::forRun, SelectedRunSnapshot: RunWaitView and
// HistoryTimeline), over raw worker HTTP on protocol 1.20 in its own
// namespace. Each shape is one run on its own queue: an activity that
// completes, an activity that fails and fails the run, a timer, a signal
// wait, a condition wait whose timeout fires, a child workflow and an
// update. Each run is exported while it waits and again once closed.
//
// Identifiers become labels by first appearance within each export, in
// sorted key order (`{id:N}`), and every non-null instant becomes `<time>`. A timeline point's
// command keeps no caller, principal or request context: native records none
// (docs/rust-server-port.md, history export), so those fields are dropped.
function finishHttpHistoryExportWaits(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['history_export_waits'];
    $namespace = strtolower($workflowId.'-hxw');
    $admin->createNamespace($namespace, 'history export waits λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    // The SQLite reference shares its database with a one-second maintenance
    // loop; retry its retryable lock-pressure refusals, as the other probes do.
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new HistoryExportWaitsHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $headers = array_filter([
        'Authorization' => $token === null ? null : 'Bearer '.$token,
        'Accept' => 'application/json', 'X-Namespace' => $namespace,
        'X-Durable-Workflow-Control-Plane-Version' => '2', 'X-Durable-Workflow-Protocol-Version' => '1.20',
    ]);
    $raw = static function (string $method, string $path, ?array $body = null) use ($http, $headers): array {
        $response = $http->request($method, $path, ['headers' => $headers,
            ...($body === null ? [] : ['json' => $body === [] ? (object) [] : $body])]);

        return ['status' => $response->getStatusCode(),
            'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    };
    $ok = static function (array $answer, int $status, string $step): array {
        if ($answer['status'] !== $status) {
            throw new RuntimeException("History export waits {$step}: ".json_encode($answer));
        }

        return $answer['response'];
    };
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $type = $definition['workflow_type'];
    $childType = $definition['child_type'];
    $activityType = $definition['activity_type'];
    $update = $definition['update'];
    $capabilities = ['query_tasks', 'workflow_updates'];

    $start = static function (string $shape) use ($raw, $ok, $workflowId, $type, $childType, $activityType, $update,
        $empty, $capabilities): array {
        $id = $workflowId.'-hxw-'.$shape;
        $queue = $id.'-queue';
        $worker = $id.'-worker';
        $ok($raw('POST', 'worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
            'sdk_version' => 'raw-http', 'supported_workflow_types' => [$type, $childType],
            'supported_activity_types' => [$activityType],
            'workflow_command_contracts' => [$type => ['signals' => ['go'], 'queries' => [], 'updates' => [$update],
                'update_validators' => [], 'update_contracts' => [['name' => $update, 'parameters' => []]]]],
            'capabilities' => $capabilities,
            'capability_manifest' => CapabilityManifest::forCapabilities($capabilities)]), 201, $shape.' registration');
        $started = $ok($raw('POST', 'workflows', ['workflow_id' => $id, 'workflow_type' => $type,
            'task_queue' => $queue, 'input' => $empty]), 201, $shape.' start');

        return ['shape' => $shape, 'workflow_id' => $id, 'run_id' => $started['run_id'], 'queue' => $queue,
            'worker' => $worker];
    };
    $poll = static function (array $run, string $kind = 'workflow', ?string $workflowType = null) use ($raw, $ok): array {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $task = $ok($raw('POST', "worker/{$kind}-tasks/poll", ['worker_id' => $run['worker'],
                'task_queue' => $run['queue'], 'timeout_seconds' => 1]), 200, $run['shape'].' '.$kind.' poll')['task'] ?? null;
            if (is_array($task)) {
                if ($workflowType !== null && ($task['workflow_type'] ?? null) !== $workflowType) {
                    throw new RuntimeException("History export waits {$run['shape']}: expected a {$workflowType} task");
                }

                return $task;
            }
        }
        throw new RuntimeException("History export waits {$run['shape']}: no {$kind} task");
    };
    $complete = static function (array $run, array $task, array $commands) use ($raw, $ok): array {
        return $ok($raw('POST', 'worker/workflow-tasks/'.$task['task_id'].'/complete', [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => $commands]), 200, $run['shape'].' completion');
    };
    $activity = static function (array $run, array $task, string $outcome, array $body) use ($raw, $ok): array {
        return $ok($raw('POST', 'worker/activity-tasks/'.$task['task_id'].'/'.$outcome, [
            'activity_attempt_id' => $task['activity_attempt_id'], 'lease_owner' => $task['lease_owner'], ...$body]),
            200, $run['shape'].' activity '.$outcome);
    };
    $export = static function (array $run) use ($raw, $ok): array {
        $bundle = $ok($raw('GET', 'workflows/'.rawurlencode($run['workflow_id']).'/runs/'.$run['run_id'].'/history/export'),
            200, $run['shape'].' export');

        return historyExportWaitsNormalized([
            'selected_run' => array_intersect_key($bundle['selected_run'] ?? [],
                array_flip(['waits_projection_source', 'timeline_projection_source'])),
            'waits' => $bundle['waits'] ?? null,
            'timeline' => $bundle['timeline'] ?? null,
        ], $run['workflow_id']);
    };
    $complete_workflow = ['type' => 'complete_workflow', 'result' => $empty];
    $state = ['applicable' => true];

    // An activity that completes.
    $run = $start('activity');
    $complete($run, $poll($run), [['type' => 'schedule_activity', 'activity_type' => $activityType, 'arguments' => $empty]]);
    $task = $poll($run, 'activity');
    $state['activity']['waiting'] = $export($run);
    $activity($run, $task, 'complete', ['result' => $empty]);
    $complete($run, $poll($run), [$complete_workflow]);
    $state['activity']['closed'] = $export($run);

    // An activity that fails without retry, then the run fails.
    $run = $start('activity_failure');
    $complete($run, $poll($run), [['type' => 'schedule_activity', 'activity_type' => $activityType, 'arguments' => $empty]]);
    $task = $poll($run, 'activity');
    $activity($run, $task, 'fail', ['failure' => ['message' => 'activity failed', 'type' => 'Boom', 'non_retryable' => true]]);
    $complete($run, $poll($run), [['type' => 'fail_workflow', 'message' => 'run failed', 'exception_type' => 'Boom']]);
    $state['activity_failure']['closed'] = $export($run);

    // A timer that fires.
    $run = $start('timer');
    $complete($run, $poll($run), [['type' => 'start_timer', 'delay_seconds' => $definition['timer_seconds']]]);
    $state['timer']['waiting'] = $export($run);
    $complete($run, $poll($run), [$complete_workflow]);
    $state['timer']['closed'] = $export($run);

    // A signal wait resumed by its signal.
    $run = $start('signal');
    $complete($run, $poll($run), [['type' => 'open_signal_wait', 'signal_name' => 'go']]);
    $state['signal']['waiting'] = $export($run);
    $ok($raw('POST', 'workflows/'.rawurlencode($run['workflow_id']).'/signal/go', ['arguments' => $empty]), 202, 'signal');
    $complete($run, $poll($run), [$complete_workflow]);
    $state['signal']['closed'] = $export($run);

    // A condition wait whose timeout fires.
    $run = $start('condition');
    $complete($run, $poll($run), [['type' => 'open_condition_wait', 'condition_key' => 'ready',
        'timeout_seconds' => $definition['timer_seconds']]]);
    $state['condition']['waiting'] = $export($run);
    $complete($run, $poll($run), [$complete_workflow]);
    $state['condition']['closed'] = $export($run);

    // A child workflow.
    $run = $start('child');
    $complete($run, $poll($run, 'workflow', $type), [['type' => 'start_child_workflow', 'workflow_type' => $childType,
        'arguments' => $empty]]);
    $child = $poll($run, 'workflow', $childType);
    $state['child']['waiting'] = $export($run);
    $complete($run, $child, [$complete_workflow]);
    $complete($run, $poll($run, 'workflow', $type), [$complete_workflow]);
    $state['child']['closed'] = $export($run);

    // An update completed while the run waits for a signal.
    $run = $start('update');
    $complete($run, $poll($run), [['type' => 'open_signal_wait', 'signal_name' => 'go']]);
    $accepted = $ok($raw('POST', 'workflows/'.rawurlencode($run['workflow_id']).'/update/'.$update,
        ['input' => [], 'request_id' => 'hxw-update', 'wait_for' => 'accepted']), 202, 'update');
    $state['update']['accepted'] = $export($run);
    $complete($run, $poll($run), [['type' => 'complete_update', 'update_id' => $accepted['update_id'] ?? null,
        'result' => $empty]]);
    $ok($raw('POST', 'workflows/'.rawurlencode($run['workflow_id']).'/signal/go', ['arguments' => $empty]), 202, 'update signal');
    $complete($run, $poll($run), [$complete_workflow]);
    $state['update']['closed'] = $export($run);

    return $state;
}

/**
 * Labels every identifier and masks every instant of an exported section.
 *
 * @param array<string, mixed> $value
 * @return array<string, mixed>
 */
function historyExportWaitsNormalized(array $value, string $workflowId): array
{
    $labels = [];
    $label = static function (string $id) use (&$labels): string {
        $key = strtolower($id);

        return $labels[$key] ??= '{id:'.(count($labels) + 1).'}';
    };
    $identifier = '/(?<![0-9a-z])(?:[0-9a-z]{26}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?![0-9a-z])/i';
    $walk = static function (mixed $value, ?string $key) use (&$walk, $label, $identifier, $workflowId): mixed {
        if (is_array($value)) {
            // Keys in sorted order, so labels follow values, not key order.
            if (! array_is_list($value)) {
                ksort($value);
            }
            $out = [];
            foreach ($value as $nested => $item) {
                $out[$nested] = $walk($item, is_string($nested) ? $nested : $key);
            }

            return $out;
        }
        if (! is_string($value)) {
            return $value;
        }
        if ($key !== null && (str_ends_with($key, '_at') || $key === 'fire_at')) {
            return '<time>';
        }
        $value = str_replace($workflowId, '{workflow}', $value);

        return preg_replace_callback($identifier, static fn (array $match): string => $label($match[0]), $value);
    };

    foreach ($value['timeline'] ?? [] as $index => $entry) {
        if (is_array($entry['command'] ?? null)) {
            foreach (['context', 'caller_label', 'principal_type', 'principal_id', 'principal_label', 'auth_status',
                'auth_method', 'request_method', 'request_path', 'request_route_name', 'request_fingerprint'] as $field) {
                unset($value['timeline'][$index]['command'][$field]);
            }
        }
    }

    return $walk($value, null);
}
