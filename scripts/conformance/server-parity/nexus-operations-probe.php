<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as NexusOperationsHttp;

// Caller-indexed Nexus operation reads (ServiceCatalogController::
// nexusOperationsForWorkflow and nexusOperationsForRun): the list for the
// probe's own workflow and run, for an unknown workflow and run, in another
// namespace, and every form the `limit` query takes. Nothing here starts a
// Nexus operation, so each list is empty; the shape, the namespace, the
// count and the resolved limit are the contract. The run ID is a label.
function finishHttpNexusOperations(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $namespace = strtolower($workflowId.'-nxo');
    $admin->createNamespace($namespace, 'nexus operations λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $http = new NexusOperationsHttp(['base_uri' => rtrim($url, '/'), 'http_errors' => false, 'connect_timeout' => 1, 'timeout' => 30]);
    $raw = static function (string $path, ?string $tenant = null) use ($http, $token): array {
        $headers = array_filter(['Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $tenant,
            'X-Durable-Workflow-Control-Plane-Version' => '2']);
        $response = $http->request('GET', $path, ['headers' => $headers]);
        $decoded = json_decode((string) $response->getBody(), true);
        if (is_array($decoded)) {
            unset($decoded['server_capabilities'], $decoded['control_plane'], $decoded['protocol_version']);
        }

        return ['status' => $response->getStatusCode(), 'response' => $decoded];
    };
    $describe = $raw('/api/workflows/'.rawurlencode($workflowId));
    $runId = $describe['response']['run_id'] ?? null;
    if (! is_string($runId) || $runId === '') {
        throw new RuntimeException('Nexus operations: the probe workflow has no run: '.json_encode($describe));
    }
    $label = static function (array $recorded) use ($runId): array {
        return json_decode(str_replace(json_encode($runId), '"{run}"', json_encode($recorded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), true);
    };
    $workflow = '/api/workflows/'.rawurlencode($workflowId).'/nexus-operations';
    $run = '/api/workflows/'.rawurlencode($workflowId).'/runs/'.rawurlencode($runId).'/nexus-operations';
    $state = ['applicable' => true, 'namespace' => $namespace];
    $state['workflow'] = $label($raw($workflow));
    $state['run'] = $label($raw($run));
    $state['unknown_workflow'] = $raw('/api/workflows/'.rawurlencode($workflowId.'-nxo-missing').'/nexus-operations');
    $state['unknown_run'] = $label($raw('/api/workflows/'.rawurlencode($workflowId).'/runs/01NOSUCHRUN000000000000000/nexus-operations'));
    $state['other_namespace'] = $label($raw($workflow, $namespace));
    $state['other_namespace_run'] = $label($raw($run, $namespace));
    foreach (['5' => 'five', '0' => 'zero', '-3' => 'negative', 'abc' => 'text', '1000' => 'above_cap', '2.7' => 'fraction',
        '' => 'empty', '1e2' => 'exponent', ' 7 ' => 'padded'] as $limit => $name) {
        $state['limit_'.$name] = $label($raw($workflow.'?limit='.rawurlencode((string) $limit)));
    }
    $state['run_limit_five'] = $label($raw($run.'?limit=5'));

    return $state;
}
