<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as ClusterCapabilitiesHttp;
use GuzzleHttp\HandlerStack;

// The parts of /api/cluster/info that describe the server itself (published
// HealthController::clusterInfo), and the activity result read
// `capabilities.workflow_activity_result_inspection` advertises. Over raw HTTP
// in a fresh namespace, the probe reads cluster info, then runs one workflow
// whose only activity it completes, reading the activity result before and
// after, and for an activity the run does not have.
function finishHttpClusterCapabilities(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['cluster_capabilities'];
    $namespace = strtolower($workflowId.'-cc');
    $admin->createNamespace($namespace, 'cluster capabilities λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new ClusterCapabilitiesHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
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
            throw new RuntimeException("Cluster capabilities {$step}: ".json_encode($answer));
        }

        return $answer['response'];
    };

    $info = $ok($raw('GET', 'cluster/info'), 200, 'cluster info');
    $state = ['applicable' => true, 'implementation' => $info['implementation'] ?? null,
        'version_is_release' => is_string($info['version'] ?? null)
            && preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/', $info['version']) === 1,
        'capabilities' => $info['capabilities'] ?? 'absent',
        'structural_limits' => $info['structural_limits'] ?? 'absent',
        'discovery' => $info['discovery'] ?? 'absent',
        'present' => array_combine($definition['php_only_members'], array_map(
            static fn (string $member): bool => array_key_exists($member, $info), $definition['php_only_members'])),
        'native_unqualified' => array_keys($info['native_unqualified'] ?? [])];
    sort($state['native_unqualified']);

    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $id = $workflowId.'-cc-run';
    $queue = $id.'-queue';
    $worker = $id.'-worker';
    $ok($raw('POST', 'worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
        'sdk_version' => 'raw-http', 'supported_workflow_types' => [$definition['workflow_type']],
        'supported_activity_types' => [$definition['activity_type']],
        'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
            ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http'])]), 201, 'registration');
    $run = $ok($raw('POST', 'workflows', ['workflow_id' => $id, 'workflow_type' => $definition['workflow_type'],
        'task_queue' => $queue, 'input' => $empty]), 201, 'start')['run_id'];
    $poll = static function (string $kind) use ($raw, $worker, $queue): array {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $task = $raw('POST', "worker/{$kind}-tasks/poll", ['worker_id' => $worker, 'task_queue' => $queue,
                'timeout_seconds' => 1])['response']['task'] ?? null;
            if (is_array($task)) {
                return $task;
            }
        }
        throw new RuntimeException("Cluster capabilities: no {$kind} task");
    };
    $task = $poll('workflow');
    $ok($raw('POST', 'worker/workflow-tasks/'.$task['task_id'].'/complete', ['lease_owner' => $task['lease_owner'],
        'workflow_task_attempt' => $task['workflow_task_attempt'],
        'commands' => [['type' => 'schedule_activity', 'activity_type' => $definition['activity_type'], 'arguments' => $empty]]]),
        200, 'schedule activity');
    $activity = $poll('activity');
    $base = 'workflows/'.rawurlencode($id).'/runs/'.$run.'/activities/';
    $read = static function (string $activityId) use ($raw, $base, $id, $run, $activity): array {
        $answer = $raw('GET', $base.rawurlencode($activityId).'/result');
        $labels = [$id => '{workflow}', $run => '{run}', $activity['activity_execution_id'] => '{activity}'];

        return ['status' => $answer['status'],
            'response' => json_decode(strtr(json_encode($answer['response'], JSON_THROW_ON_ERROR), $labels), true)];
    };
    $state['activity_result'] = ['pending' => $read($activity['activity_execution_id'])];
    $ok($raw('POST', 'worker/activity-tasks/'.$activity['task_id'].'/complete', [
        'activity_attempt_id' => $activity['activity_attempt_id'], 'lease_owner' => $activity['lease_owner'],
        'result' => $empty]), 200, 'activity completion');
    $state['activity_result']['available'] = $read($activity['activity_execution_id']);
    $state['activity_result']['unknown'] = $read('missing-activity');
    // End the run so nothing stays for later fixtures.
    $raw('POST', 'workflows/'.rawurlencode($id).'/terminate', ['reason' => 'cluster capabilities recorded']);

    return $state;
}
