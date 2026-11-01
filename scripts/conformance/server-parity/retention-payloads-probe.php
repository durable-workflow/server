<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as RetentionPayloadsHttp;
use GuzzleHttp\HandlerStack;

// The external payloads of runs a retention pass prunes, over raw HTTP. Two
// namespaces share one local storage root, so equal bytes are one object.
// Each run's input is an uploaded payload; each run is terminated, then
// pruned by id. Every step records the pass's result for its run, or a
// fetch of the payload, in order.
function finishHttpRetentionPayloads(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $namespace = strtolower($workflowId.'-retention');
    $peer = $namespace.'-peer';
    $admin->createNamespace($namespace, 'retention payloads λ', 30);
    $admin->createNamespace($peer, 'retention payloads peer λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new RetentionPayloadsHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $headers = static fn (string $in, array $extra = []): array => array_filter([
        'Authorization' => $token === null ? null : 'Bearer '.$token,
        'Accept' => 'application/json', 'X-Namespace' => $in,
        'X-Durable-Workflow-Control-Plane-Version' => '2', ...$extra,
    ]);
    $json = static function (string $in, string $method, string $path, array $body) use ($http, $headers): array {
        $response = $http->request($method, $path, ['headers' => $headers($in), 'json' => $body]);

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    };
    // Each target writes under its own root: the servers can run as different
    // users, and an object one target wrote must not satisfy another.
    $host = preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) parse_url($url, PHP_URL_HOST)));
    $root = 'file:///state/retention-payloads-'.$host.'/'.$namespace;
    $policy = static fn (string $in, bool $enabled): int => $json($in, 'PUT', 'namespaces/'.$in.'/external-storage', [
        'driver' => 'local', 'enabled' => $enabled, 'config' => ['uri' => $root]])[0];
    $state = ['applicable' => true, 'namespace' => $namespace, 'peer' => $peer, 'storage_root' => $root,
        'setup' => [], 'steps' => []];
    $state['setup']['policies'] = [$policy($namespace, true), $policy($peer, true)];
    $upload = static function (string $in, string $text) use ($http, $headers, $admin, &$state): array {
        $blob = $admin->payloadCodec()->encode([$text]);
        $response = $http->post('external-payloads/v1', ['headers' => $headers($in, [
            'Content-Type' => 'application/octet-stream', 'X-Durable-Workflow-Payload-Codec' => 'avro',
            'X-Durable-Workflow-Payload-Size' => (string) strlen($blob),
            'X-Durable-Workflow-Payload-SHA256' => hash('sha256', $blob)]), 'body' => $blob]);
        $state['setup']['uploads'][] = $response->getStatusCode();

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['reference'] ?? [];
    };
    $runs = [];
    $run = static function (string $in, string $label, array $reference) use ($json, $workflowId, &$state, &$runs): void {
        // Instance ids are global, so each run's id names the case.
        $id = $workflowId.'-retention-'.$label;
        [$status, $started] = $json($in, 'POST', 'workflows', ['workflow_id' => $id, 'workflow_type' => 'parity.v1.echo',
            'task_queue' => $id, 'input' => ['codec' => 'avro', 'external_payload' => $reference]]);
        [$terminated] = $json($in, 'POST', 'workflows/'.$id.'/terminate', ['reason' => 'retention']);
        $state['setup']['runs'][$label] = [$status, $terminated];
        $runs[$label] = [$in, (string) ($started['run_id'] ?? '')];
    };
    $prune = static function (string $step, string $label) use ($json, &$state, &$runs): void {
        [$in, $runId] = $runs[$label];
        [$status, $report] = $json($in, 'POST', 'system/retention/pass', ['run_ids' => [$runId]]);
        $result = $report['results'][0] ?? [];
        $state['steps'][$step] = ['status' => $status, 'processed' => $report['processed'] ?? null,
            'run' => ($result['run_id'] ?? null) === $runId ? $label : ($result['run_id'] ?? null),
            'result' => array_intersect_key($result, array_flip(['outcome', 'reason', 'external_payloads_deleted'])),
            // 2.11.8's post-commit provider deletions still pending (#911).
            'cleanup' => $report['external_payload_cleanup'] ?? null];
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

    $sole = $upload($namespace, 'retention sole λ');
    $run($namespace, 'sole', $sole);
    $shared = $upload($namespace, 'retention shared λ');
    $run($namespace, 'shared-1', $shared);
    $run($namespace, 'shared-2', $shared);
    $mine = $upload($namespace, 'retention peer λ');
    $theirs = $upload($peer, 'retention peer λ');
    $state['setup']['peer_references_differ'] = ($mine['reference_id'] ?? null) !== ($theirs['reference_id'] ?? null);
    $run($namespace, 'mine', $mine);
    $run($peer, 'theirs', $theirs);
    $off = $upload($namespace, 'retention without storage λ');
    $run($namespace, 'off-1', $off);
    $run($namespace, 'off-2', $off);

    // A payload only the pruned run holds goes with it.
    $prune('sole pruned', 'sole');
    $fetch('sole fetched', $namespace, $sole);
    // A payload a retained run still holds stays until that run goes.
    $prune('shared first pruned', 'shared-1');
    $fetch('shared fetched while held', $namespace, $shared);
    $prune('shared last pruned', 'shared-2');
    $fetch('shared fetched', $namespace, $shared);
    // The peer namespace's retained run holds the same object; once it goes,
    // this namespace's registration still keeps the object.
    $prune('peer-held pruned', 'mine');
    $prune('peer pruned', 'theirs');
    $fetch('peer-held fetched', $namespace, $mine);
    // Without storage, a held payload needs no deletion; a sole one does.
    $state['setup']['storage_off'] = $policy($namespace, false);
    $prune('storage off: held pruned', 'off-1');
    $prune('storage off: sole skipped', 'off-2');
    $state['setup']['storage_on'] = $policy($namespace, true);
    $prune('storage on: sole pruned', 'off-2');
    $fetch('storage on: sole fetched', $namespace, $off);

    return $state;
}
