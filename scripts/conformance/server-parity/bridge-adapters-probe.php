<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Worker\CapabilityManifest;
use GuzzleHttp\Client as BridgeAdapterHttp;
use GuzzleHttp\HandlerStack;

// Bridge adapter webhooks (POST /api/bridge-adapters/webhook/{adapter}) over
// raw HTTP in a fresh namespace: the routing, payload and action refusals;
// start_workflow new, duplicate under each policy and with a derived ID;
// signal_workflow and update_workflow accepted, deduplicated by their
// idempotency key, and refused for an unknown workflow. A raw worker declares
// the signal and update and leaves the started run idle on a signal wait.
// Run identities are recorded as labels, since they differ by run.
function finishHttpBridgeAdapters(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['bridge_adapters'];
    $namespace = strtolower($workflowId.'-bra');
    $admin->createNamespace($namespace, 'bridge adapters λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new BridgeAdapterHttp(['base_uri' => rtrim($url, '/'), 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $raw = static function (string $method, string $path, ?array $body = null, bool $worker = false) use ($http, $token, $namespace): array {
        $headers = array_filter(['Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $namespace,
            $worker ? 'X-Durable-Workflow-Protocol-Version' : 'X-Durable-Workflow-Control-Plane-Version' => $worker ? '1.20' : '2']);
        $response = $http->request($method, $path, ['headers' => $headers] + ($body === null ? [] : ['json' => $body]));
        $decoded = json_decode((string) $response->getBody(), true);
        if (is_array($decoded)) {
            unset($decoded['server_capabilities'], $decoded['control_plane'], $decoded['protocol_version']);
        }

        return ['status' => $response->getStatusCode(), 'response' => $decoded];
    };
    $ok = static function (array $recorded, int $status, string $operation): array {
        if ($recorded['status'] !== $status) {
            throw new RuntimeException('Bridge adapters '.$operation.': '.json_encode($recorded));
        }

        return $recorded['response'];
    };
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $queue = $workflowId.'-bra-queue';
    $worker = $workflowId.'-bra-worker';
    $type = $definition['workflow_type'];
    $contract = ['signals' => ['ping'], 'signal_contracts' => [['name' => 'ping', 'parameters' => []]],
        'queries' => [], 'updates' => ['bump'], 'update_contracts' => [['name' => 'bump', 'parameters' => []]]];
    $capabilities = ['query_tasks', 'workflow_updates'];
    $ok($raw('POST', '/api/worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'python',
        'supported_workflow_types' => [$type], 'supported_activity_types' => [],
        'workflow_command_contracts' => [$type => $contract], 'capabilities' => $capabilities,
        'capability_manifest' => CapabilityManifest::forCapabilities($capabilities)], true), 201, 'registration');
    $webhook = static fn (string $adapter, array $body): array => $raw('POST', '/api/bridge-adapters/webhook/'.rawurlencode($adapter), $body);
    // Run identities become labels, in order of first appearance.
    $labels = [];
    $label = static function (?string $runId) use (&$labels): ?string {
        if ($runId === null) {
            return null;
        }
        $labels[$runId] ??= 'run-'.(count($labels) + 1);

        return $labels[$runId];
    };
    $answer = static function (array $recorded) use ($label): array {
        $body = $recorded['response'];
        if (is_array($body) && array_key_exists('run_id', $body)) {
            $body['run_id'] = $label($body['run_id']);
        }

        return ['status' => $recorded['status'], 'response' => $body];
    };
    $adapter = 'acme.crm:v1';
    $id = $workflowId.'-bra-order';
    $correlation = ['trace' => 'λ-1'];
    $state = ['applicable' => true, 'namespace' => $namespace];

    // Refusals before any action.
    $state['bad_adapter'] = $answer($webhook('bad adapter!', ['action' => 'start_workflow', 'idempotency_key' => 'k', 'target' => ['workflow_type' => $type]]));
    $state['malformed'] = $answer($webhook($adapter, ['action' => 5, 'target' => 'x']));
    $state['unsupported_action'] = $answer($webhook($adapter, ['action' => 'cancel_workflow', 'idempotency_key' => 'k-0',
        'target' => ['workflow_id' => $id, 'secret' => 'not echoed'], 'correlation' => $correlation]));

    // start_workflow.
    $state['start_bad_target'] = $answer($webhook($adapter, ['action' => 'start_workflow', 'idempotency_key' => 'k-1',
        'target' => ['workflow_type' => $type, 'workflow_id' => 'bad id!', 'duplicate_policy' => 'maybe']]));
    // Derived workflow IDs are global, so their keys are unique to the run.
    $unknownKey = $workflowId.'-k-2';
    $derivedKey = $workflowId.'-derive-λ';
    $state['start_unknown_type'] = $answer($webhook($adapter, ['action' => 'start_workflow', 'idempotency_key' => $unknownKey,
        'target' => ['workflow_type' => 'parity.v1.unconfigured']]));
    $state['start'] = $answer($webhook($adapter, ['action' => 'start_workflow', 'idempotency_key' => 'k-3', 'input' => $empty,
        'target' => ['workflow_type' => $type, 'workflow_id' => $id, 'task_queue' => $queue, 'business_key' => 'order-1'],
        'correlation' => $correlation]));
    $state['start_again'] = $answer($webhook($adapter, ['action' => 'start_workflow', 'idempotency_key' => 'k-4', 'input' => $empty,
        'target' => ['workflow_type' => $type, 'workflow_id' => $id, 'task_queue' => $queue]]));
    $state['start_reject_duplicate'] = $answer($webhook($adapter, ['action' => 'start_workflow', 'idempotency_key' => 'k-5', 'input' => $empty,
        'target' => ['workflow_type' => $type, 'workflow_id' => $id, 'task_queue' => $queue, 'duplicate_policy' => 'reject_duplicate']]));
    $state['start_derived'] = $answer($webhook($adapter, ['action' => 'start_workflow', 'idempotency_key' => $derivedKey, 'input' => $empty,
        'target' => ['workflow_type' => $type, 'task_queue' => $queue]]));

    // The started run idles on a signal wait, so signals and updates apply.
    $task = $ok($raw('POST', '/api/worker/workflow-tasks/poll', ['worker_id' => $worker, 'task_queue' => $queue, 'timeout_seconds' => 5], true),
        200, 'workflow task')['task'] ?? [];
    $state['idled'] = $raw('POST', '/api/worker/workflow-tasks/'.($task['task_id'] ?? 'missing').'/complete', [
        'lease_owner' => $task['lease_owner'] ?? null, 'workflow_task_attempt' => $task['workflow_task_attempt'] ?? null,
        'commands' => [['type' => 'open_signal_wait', 'signal_name' => 'ping']]], true)['status'];

    // signal_workflow.
    $state['signal_unknown'] = $answer($webhook($adapter, ['action' => 'signal_workflow', 'idempotency_key' => 's-0',
        'target' => ['workflow_id' => $workflowId.'-bra-missing', 'signal_name' => 'ping']]));
    $state['signal_bad_target'] = $answer($webhook($adapter, ['action' => 'signal_workflow', 'idempotency_key' => 's-1',
        'target' => ['workflow_id' => $id]]));
    $state['signal'] = $answer($webhook($adapter, ['action' => 'signal_workflow', 'idempotency_key' => 's-2', 'input' => $empty,
        'target' => ['workflow_id' => $id, 'signal_name' => 'ping'], 'correlation' => $correlation]));
    $state['signal_again'] = $answer($webhook($adapter, ['action' => 'signal_workflow', 'idempotency_key' => 's-2', 'input' => $empty,
        'target' => ['workflow_id' => $id, 'signal_name' => 'ping']]));
    $state['signal_other_adapter'] = $answer($webhook('other', ['action' => 'signal_workflow', 'idempotency_key' => 's-2', 'input' => $empty,
        'target' => ['workflow_id' => $id, 'signal_name' => 'ping']]));

    // update_workflow.
    $state['update_unknown'] = $answer($webhook($adapter, ['action' => 'update_workflow', 'idempotency_key' => 'u-0',
        'target' => ['workflow_id' => $workflowId.'-bra-missing', 'update_name' => 'bump']]));
    $state['update_bad_target'] = $answer($webhook($adapter, ['action' => 'update_workflow', 'idempotency_key' => 'u-1',
        'target' => ['workflow_id' => $id, 'update_name' => 'bump', 'wait_for' => 'never', 'wait_timeout_seconds' => 0]]));
    $state['update'] = $answer($webhook($adapter, ['action' => 'update_workflow', 'idempotency_key' => 'u-2', 'input' => $empty,
        'target' => ['workflow_id' => $id, 'update_name' => 'bump', 'wait_for' => 'accepted']]));
    $state['update_again'] = $answer($webhook($adapter, ['action' => 'update_workflow', 'idempotency_key' => 'u-2', 'input' => $empty,
        'target' => ['workflow_id' => $id, 'update_name' => 'bump']]));
    $state['update_undeclared'] = $answer($webhook($adapter, ['action' => 'update_workflow', 'idempotency_key' => 'u-3', 'input' => $empty,
        'target' => ['workflow_id' => $id, 'update_name' => 'not_declared']]));

    // The bridge's command context on the run's history.
    $history = $ok($raw('GET', '/api/workflows/'.rawurlencode($id).'/runs/'.($task['run_id'] ?? 'missing').'/history'), 200, 'history');
    $state['history_event_types'] = array_column($history['events'] ?? [], 'event_type');

    // PHP's derivation, `bridge-<adapter slug>-<sha256(key) prefix>`, computed
    // here independently: an ID derived any other way stays in the record.
    $derive = static fn (string $key): string => 'bridge-acme.crm:v1-'.substr(hash('sha256', $key), 0, 32);

    return json_decode(strtr(json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), [
        $derive($unknownKey) => '{derived-k-2}', $derive($derivedKey) => '{derived-derive-λ}',
    ]), true, 512, JSON_THROW_ON_ERROR);
}
