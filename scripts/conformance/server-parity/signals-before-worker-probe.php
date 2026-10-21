<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as SignalsBeforeWorkerHttp;

// Signals to a run that no worker has picked up, in a fresh namespace whose
// task queue has no registered worker: the run has no recorded command
// contract yet. The fixture's steps are sent in order; "@workflow", "@run",
// "@queue" and "@input" are replaced by the run's identities, the unserved
// queue and the fixture's payload. Responses are recorded raw; the contract
// normalizes generated identities and times.
function finishHttpSignalsBeforeWorker(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['signals_before_worker'];
    $namespace = strtolower($workflowId.'-unregistered');
    $admin->createNamespace($namespace, 'signals before a worker λ', 30);
    $queue = strtolower($workflowId.'-unserved');
    $workflow = strtolower($workflowId.'-before-worker');
    $input = ['codec' => 'avro', 'blob' => $definition['input_blob']];
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $http = new SignalsBeforeWorkerHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30]);
    $run = '';
    $fill = static function (mixed $value) use (&$fill, &$run, $queue, $input, $workflow): mixed {
        if (is_string($value)) {
            return match ($value) {
                '@queue' => $queue,
                '@input' => $input,
                default => str_replace(['@workflow', '@run'], [$workflow, $run], $value),
            };
        }

        return is_array($value) ? array_map($fill, $value) : $value;
    };
    $steps = [];
    foreach ($definition['steps'] as $step) {
        $path = $fill($step[1]);
        $body = array_key_exists(2, $step) ? $fill($step[2]) : null;
        $response = $http->request($step[0], $path, ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ]), ...($body === null ? [] : ['json' => $body === [] ? (object) [] : $body])]);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if ($run === '' && is_string($decoded['run_id'] ?? null)) {
            $run = $decoded['run_id'];
        }
        $steps[] = ['request' => $step[0].' '.$step[1], 'status' => $response->getStatusCode(), 'response' => $decoded];
    }

    return ['applicable' => true, 'namespace' => $namespace, 'queue' => $queue, 'workflow_id' => $workflow,
        'run_id' => $run, 'steps' => $steps];
}
