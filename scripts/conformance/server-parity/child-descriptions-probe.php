<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as ChildDescriptionsHttp;
use GuzzleHttp\HandlerStack;

// A parent run's description while its children are open, over raw worker
// HTTP on protocol 1.20 in a fresh namespace (published Server
// WorkflowController::show, Workflow RunWaitView). Each case starts a
// parent, completes its first workflow task with the case's child and timer
// commands, and records the completion answer and the parent's description.
function finishHttpChildDescriptions(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['child_descriptions'];
    $namespace = strtolower($workflowId.'-cd');
    $admin->createNamespace($namespace, 'child descriptions λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new ChildDescriptionsHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
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
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $state = ['applicable' => true];
    foreach ($definition['cases'] as $case => $commands) {
        $id = $workflowId.'-cd-'.$case;
        $queue = $id.'-queue';
        $worker = $id.'-worker';
        $registered = $raw('POST', 'worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
            'sdk_version' => 'raw-http', 'supported_workflow_types' => [$definition['workflow_type'], $definition['child_type']],
            'supported_activity_types' => [],
            'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
                ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http'])]);
        $started = $raw('POST', 'workflows', ['workflow_id' => $id, 'workflow_type' => $definition['workflow_type'],
            'task_queue' => $queue, 'input' => $empty]);
        if ($registered['status'] !== 201 || $started['status'] !== 201) {
            throw new RuntimeException('Child descriptions setup: '.json_encode([$registered, $started]));
        }
        $task = $raw('POST', 'worker/workflow-tasks/poll', ['worker_id' => $worker, 'task_queue' => $queue,
            'timeout_seconds' => 5])['response']['task'] ?? null;
        if (! is_array($task)) {
            throw new RuntimeException('Child descriptions: no workflow task for '.$case);
        }
        $completed = $raw('POST', 'worker/workflow-tasks/'.$task['task_id'].'/complete', ['lease_owner' => $task['lease_owner'],
            'workflow_task_attempt' => $task['workflow_task_attempt'], 'commands' => $commands]);
        $described = $raw('GET', 'workflows/'.rawurlencode($id))['response'];
        $state[$case] = [
            'completed' => ['status' => $completed['status'], 'reason' => $completed['response']['reason'] ?? null,
                'run_status' => $completed['response']['run_status'] ?? null],
            'described' => array_intersect_key($described, array_flip($definition['described_fields'])),
        ];
        // End the children and then the parent, so their never-polled tasks
        // do not stay for the maintenance passes of later fixtures.
        $events = $raw('GET', 'workflows/'.rawurlencode($id).'/runs/'.$started['response']['run_id'].'/history')['response']['events'] ?? [];
        $ended = [];
        foreach ($events as $event) {
            if ($event['event_type'] === 'ChildWorkflowScheduled' && is_string($event['payload']['child_workflow_instance_id'] ?? null)) {
                $ended[] = $event['payload']['child_workflow_instance_id'];
            }
        }
        foreach ([...$ended, $id] as $instance) {
            $terminated = $raw('POST', 'workflows/'.rawurlencode($instance).'/terminate', ['reason' => 'child descriptions recorded']);
            if (! in_array($terminated['status'], [200, 202], true)) {
                throw new RuntimeException('Child descriptions cleanup: '.json_encode($terminated));
            }
        }
    }

    return $state;
}
