<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as NamespaceDeletePayloadsHttp;
use GuzzleHttp\HandlerStack;

// The external payloads of a deleted namespace, over raw HTTP. The namespace
// and a peer share one local storage root, so equal bytes are one object.
// The namespace holds a payload only its run references, one its peer also
// registers and references, and one uploaded but never referenced. A third
// namespace holds only an unreferenced upload. The first delete is tried
// without storage, then with it; every step records its answer in order.
// (Without storage, the third namespace's delete would fail inside PHP's
// registry pass, where the SQLite reference's object lock can meet the
// maintenance loop's writer and answer a different message; native tests
// cover that refusal.)
function finishHttpNamespaceDeletePayloads(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $namespace = strtolower($workflowId.'-nsdelete');
    $peer = $namespace.'-peer';
    $idle = $namespace.'-idle';
    foreach ([$namespace, $peer, $idle] as $name) {
        $admin->createNamespace($name, 'namespace delete payloads λ', 30);
    }
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new NamespaceDeletePayloadsHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $headers = static fn (string $in, array $extra = []): array => array_filter([
        'Authorization' => $token === null ? null : 'Bearer '.$token,
        'Accept' => 'application/json', 'X-Namespace' => $in,
        'X-Durable-Workflow-Control-Plane-Version' => '2', ...$extra,
    ]);
    $json = static function (string $in, string $method, string $path, ?array $body = null) use ($http, $headers): array {
        $response = $http->request($method, $path, ['headers' => $headers($in), ...($body === null ? [] : ['json' => $body])]);

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    };
    // Each target writes under its own root, as the retention probe does.
    $host = preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) parse_url($url, PHP_URL_HOST)));
    $root = 'file:///state/namespace-delete-payloads-'.$host.'/'.$namespace;
    $policy = static fn (string $in, bool $enabled): int => $json($in, 'PUT', 'namespaces/'.$in.'/external-storage', [
        'driver' => 'local', 'enabled' => $enabled, 'config' => ['uri' => $root]])[0];
    $state = ['applicable' => true, 'namespace' => $namespace, 'peer' => $peer, 'idle' => $idle, 'storage_root' => $root,
        'setup' => [], 'steps' => []];
    $state['setup']['policies'] = [$policy($namespace, true), $policy($peer, true), $policy($idle, true)];
    $upload = static function (string $in, string $text) use ($http, $headers, $admin, &$state): array {
        $blob = $admin->payloadCodec()->encode([$text]);
        $response = $http->post('external-payloads/v1', ['headers' => $headers($in, [
            'Content-Type' => 'application/octet-stream', 'X-Durable-Workflow-Payload-Codec' => 'avro',
            'X-Durable-Workflow-Payload-Size' => (string) strlen($blob),
            'X-Durable-Workflow-Payload-SHA256' => hash('sha256', $blob)]), 'body' => $blob]);
        $state['setup']['uploads'][] = $response->getStatusCode();

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['reference'] ?? [];
    };
    $run = static function (string $in, string $label, array $reference) use ($json, $workflowId, &$state): void {
        // Instance ids are global, so each run's id names the case.
        $id = $workflowId.'-nsdelete-'.$label;
        [$status] = $json($in, 'POST', 'workflows', ['workflow_id' => $id, 'workflow_type' => 'parity.v1.echo',
            'task_queue' => $id, 'input' => ['codec' => 'avro', 'external_payload' => $reference]]);
        $state['setup']['runs'][$label] = $status;
    };
    $delete = static function (string $step, string $name) use ($json, &$state): void {
        // The SQLite reference meeting its maintenance loop's writer refuses
        // the delete, retryable: PHP's registry pass with its own message
        // under the object lock, or the control plane's backend lock pressure.
        // The delete rolled back, so it is asked again, as the probes retry
        // other lock refusals.
        for ($attempt = 1; ; $attempt++) {
            [$status, $body] = $json('default', 'DELETE', 'namespaces/'.$name);
            $lockRefusal = ($body['message'] ?? null) === 'External payload registry could not complete namespace cleanup.'
                || ($body['reason'] ?? null) === 'backend_lock_pressure';
            if ($attempt === 5 || $status !== 503 || ($body['retryable'] ?? null) !== true || ! $lockRefusal) {
                break;
            }
            fwrite(STDERR, json_encode(['namespace_delete_registry_retry' => $step], JSON_THROW_ON_ERROR).PHP_EOL);
            usleep(1_000_000);
        }
        // The namespace's name is per run, so only its match is recorded.
        $named = array_key_exists('namespace', $body) ? ['namespace_matches' => $body['namespace'] === $name] : [];
        unset($body['namespace']);
        $state['steps'][$step] = $status === 200
            ? ['status' => 200, 'name_matches' => ($body['name'] ?? null) === $name, 'outcome' => $body['status'] ?? null,
                'payloads' => array_intersect_key($body['deleted'] ?? [],
                    array_flip(['external_payloads_deleted', 'runtime_external_payload_references']))]
            : ['status' => $status, 'body' => $body, ...$named];
    };
    $described = static function (string $step, string $name) use ($json, &$state): void {
        $state['steps'][$step] = ['status' => $json('default', 'GET', 'namespaces/'.$name)[0]];
    };
    $fetch = static function (string $step, string $in, array $reference) use ($http, $headers, &$state): void {
        $response = $http->get('external-payloads/v1/'.rawurlencode((string) ($reference['reference_id'] ?? '')), [
            'headers' => $headers($in, ['X-Durable-Workflow-Payload-Codec' => (string) ($reference['codec'] ?? ''),
                'X-Durable-Workflow-Payload-Size' => (string) ($reference['size_bytes'] ?? ''),
                'X-Durable-Workflow-Payload-SHA256' => (string) ($reference['sha256'] ?? '')])]);
        $body = (string) $response->getBody();
        $state['steps'][$step] = $response->getStatusCode() === 200
            ? ['status' => 200, 'bytes_match' => hash('sha256', $body) === ($reference['sha256'] ?? null)]
            : ['status' => $response->getStatusCode(), 'reason' => json_decode($body, true)['reason'] ?? null];
    };

    $run($namespace, 'sole', $upload($namespace, 'namespace delete sole λ'));
    $mine = $upload($namespace, 'namespace delete shared λ');
    $theirs = $upload($peer, 'namespace delete shared λ');
    $state['setup']['peer_references_differ'] = ($mine['reference_id'] ?? null) !== ($theirs['reference_id'] ?? null);
    $run($namespace, 'mine', $mine);
    $run($peer, 'theirs', $theirs);
    $upload($namespace, 'namespace delete unused λ');
    $upload($idle, 'namespace delete idle λ');

    // An object only the namespace holds needs its storage: nothing changes.
    $state['setup']['storage_off'] = $policy($namespace, false);
    $delete('storage off: refused', $namespace);
    $described('storage off: namespace remains', $namespace);
    $state['setup']['storage_on'] = $policy($namespace, true);
    // The sole object goes with its reference. The peer's run holds the
    // shared object, so this namespace's registration goes with the unused
    // one in the registry's own pass.
    $delete('deleted', $namespace);
    $described('deleted: namespace gone', $namespace);
    $fetch('peer fetched', $peer, $theirs);
    $delete('peer deleted', $peer);
    // An unreferenced upload alone goes in the registry's pass.
    $delete('idle deleted', $idle);

    return $state;
}
