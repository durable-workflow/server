<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as SearchAttributeDatetimesHttp;
use GuzzleHttp\HandlerStack;

// Datetime search attribute values and the per-run attribute limits, over raw
// HTTP on worker protocol 1.20 in a fresh namespace that registers one
// datetime attribute (published Server SearchAttributeValueValidator, Workflow
// WorkflowSearchAttribute::setDatetimeValue and validateAttributeSet). Each
// datetime case is written once on a workflow start and once by the first
// workflow task's upsert_search_attributes command; each records the answer,
// the stored value as described and the recorded event value. A visibility
// query then filters the namespace by one instant. Each limit case upserts
// generated attributes at a per-run limit.
function finishHttpSearchAttributeDatetimes(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['search_attribute_datetimes'];
    // One namespace registers the datetime attribute; the other has no
    // definitions, so the limit cases' generated attributes are inferred.
    $namespace = strtolower($workflowId.'-sad');
    $open = strtolower($workflowId.'-sad-open');
    foreach ([$namespace, $open] as $created) {
        $admin->createNamespace($created, 'search attribute datetimes λ', 30);
    }
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new SearchAttributeDatetimesHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $headers = array_filter([
        'Authorization' => $token === null ? null : 'Bearer '.$token,
        'Accept' => 'application/json',
        'X-Durable-Workflow-Control-Plane-Version' => '2', 'X-Durable-Workflow-Protocol-Version' => '1.20',
    ]);
    $target = $namespace;
    $raw = static function (string $method, string $path, ?array $body = null) use ($http, $headers, &$target): array {
        $response = $http->request($method, $path, ['headers' => [...$headers, 'X-Namespace' => $target],
            ...($body === null ? [] : ['json' => $body === [] ? (object) [] : $body])]);

        return ['status' => $response->getStatusCode(),
            'response' => json_decode((string) $response->getBody(), true) ?? ['unparsed' => true]];
    };
    $attribute = $definition['attribute'];
    $registered = $raw('POST', 'search-attributes', ['name' => $attribute, 'type' => 'datetime']);
    if ($registered['status'] !== 201) {
        throw new RuntimeException('Search attribute datetimes definition: '.json_encode($registered));
    }
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $described = static function (string $id) use ($raw): mixed {
        $response = $raw('GET', 'workflows/'.rawurlencode($id))['response'];

        return $response['search_attributes'] ?? 'absent';
    };
    $upserted = static function (string $id, string $runId) use ($raw): mixed {
        foreach ($raw('GET', 'workflows/'.rawurlencode($id).'/runs/'.$runId.'/history')['response']['events'] ?? [] as $event) {
            if (($event['event_type'] ?? null) === 'SearchAttributesUpserted') {
                return $event['payload']['attributes'] ?? $event['payload'];
            }
        }

        return 'absent';
    };
    $answer = static fn (array $result): array => ['status' => $result['status'],
        'reason' => $result['response']['reason'] ?? null, 'message' => $result['response']['message'] ?? null,
        'error' => $result['response']['error'] ?? null, 'errors' => $result['response']['errors'] ?? null];
    // Starts a run, completes its first workflow task with the commands and
    // records the completion answer, the description and the upsert event.
    $upsert = static function (string $id, array $commands) use ($raw, $definition, $empty, $described, $upserted, $answer): array {
        $queue = $id.'-queue';
        $worker = $id.'-worker';
        $registered = $raw('POST', 'worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
            'sdk_version' => 'raw-http', 'supported_workflow_types' => [$definition['workflow_type']],
            'supported_activity_types' => [], 'capabilities' => ['typed_search_attributes'],
            'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
                ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http'])]);
        $started = $raw('POST', 'workflows', ['workflow_id' => $id, 'workflow_type' => $definition['workflow_type'],
            'task_queue' => $queue, 'input' => $empty]);
        if ($registered['status'] !== 201 || $started['status'] !== 201) {
            throw new RuntimeException('Search attribute datetimes setup: '.json_encode([$registered, $started]));
        }
        $task = $raw('POST', 'worker/workflow-tasks/poll', ['worker_id' => $worker, 'task_queue' => $queue,
            'timeout_seconds' => 5])['response']['task'] ?? null;
        if (! is_array($task)) {
            throw new RuntimeException('Search attribute datetimes: no workflow task for '.$id);
        }
        $completed = $raw('POST', 'worker/workflow-tasks/'.$task['task_id'].'/complete', ['lease_owner' => $task['lease_owner'],
            'workflow_task_attempt' => $task['workflow_task_attempt'], 'commands' => $commands]);

        return ['run_id' => $started['response']['run_id'], 'observed' => ['completed' => $answer($completed),
            'described' => $described($id), 'event' => $upserted($id, $started['response']['run_id'])]];
    };
    $state = ['applicable' => true];
    $ended = [];
    foreach ($definition['values'] as $case => $value) {
        $id = $workflowId.'-sad-start-'.$case;
        $started = $raw('POST', 'workflows', ['workflow_id' => $id, 'workflow_type' => $definition['workflow_type'],
            'task_queue' => $id.'-queue', 'input' => $empty, 'search_attributes' => [$attribute => $value]]);
        $observed = ['started' => $answer($started)];
        if ($started['status'] === 201) {
            $observed['described'] = $described($id);
            $ended[] = $id;
        }
        $state['start'][$case] = $observed;
        $id = $workflowId.'-sad-upsert-'.$case;
        $run = $upsert($id, [['type' => 'upsert_search_attributes', 'attributes' => [$attribute => $value]]]);
        $state['upsert'][$case] = $run['observed'];
        $ended[] = $id;
    }
    $listed = $raw('GET', 'workflows?query='.rawurlencode($attribute.' = "'.$definition['filter_instant'].'"'));
    $ids = array_map(static fn (array $row): ?string => $row['workflow_id'] ?? null, $listed['response']['workflows'] ?? []);
    sort($ids);
    $state['filtered'] = ['status' => $listed['status'],
        'workflow_ids' => array_map(static fn (?string $id): ?string => $id === null ? null : substr($id, strlen($workflowId)), $ids)];
    $target = $open;
    foreach ($definition['limits'] as $case => $shape) {
        $attributes = [];
        for ($index = 0; $index < $shape['count']; $index++) {
            $attributes[sprintf('Attr%03d', $index)] = isset($shape['value_bytes']) ? str_repeat('v', $shape['value_bytes']) : $index;
        }
        $id = $workflowId.'-sad-limit-'.$case;
        $run = $upsert($id, [['type' => 'upsert_search_attributes', 'attributes' => $attributes]]);
        $observed = $run['observed'];
        $observed['described'] = is_array($observed['described']) ? count($observed['described']) : $observed['described'];
        $observed['event'] = is_array($observed['event']) ? count($observed['event']) : $observed['event'];
        $state['limits'][$case] = $observed;
        $ended[] = $id;
    }
    // End every run, so their tasks do not stay for later fixtures.
    foreach ($ended as $id) {
        $target = str_contains($id, '-sad-limit-') ? $open : $namespace;
        $raw('POST', 'workflows/'.rawurlencode($id).'/terminate', ['reason' => 'search attribute datetimes recorded']);
    }

    return $state;
}
