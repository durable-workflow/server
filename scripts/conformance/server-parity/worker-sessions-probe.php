<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as WorkerSessionHttp;
use GuzzleHttp\HandlerStack;

// Worker sessions (WorkerSessionController, WorkerSessionRegistry) over raw
// HTTP in a fresh namespace: the protocol and validation gates, registration
// and routing refusals, create, reuse, heartbeat, the session limit, lease
// expiry on read, reacquire, close, the reacquire refusals, TTL expiry, a
// holder's deregistration, and the visibility list. Timestamps are recorded
// as the spans the contract compares, since PHP keeps session times in
// whole seconds; everything else is recorded as answered, without the
// protocol's capability map.
function finishHttpWorkerSessions(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['worker_sessions'];
    $namespace = strtolower($workflowId.'-wss');
    $admin->createNamespace($namespace, 'worker sessions λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new WorkerSessionHttp(['base_uri' => rtrim($url, '/'), 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    // A request on the worker plane (protocol header, null for none) or the
    // control plane, in this namespace unless another is named.
    $raw = static function (string $method, string $path, ?array $body = null, ?string $protocol = '1.20',
        bool $control = false, ?string $as = null, ?string $json = null) use ($http, $token, $namespace): array {
        $headers = array_filter(['Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $as ?? $namespace,
            'X-Durable-Workflow-Protocol-Version' => $control ? null : $protocol,
            'X-Durable-Workflow-Control-Plane-Version' => $control ? '2' : null,
            'Content-Type' => $json === null ? null : 'application/json']);
        $payload = $json !== null ? ['body' => $json] : ($body === null ? [] : ['json' => $body]);
        $response = $http->request($method, $path, ['headers' => $headers] + $payload);
        $decoded = json_decode((string) $response->getBody(), true);
        if (is_array($decoded)) {
            unset($decoded['server_capabilities'], $decoded['control_plane']);
        }

        return ['status' => $response->getStatusCode(), 'response' => $decoded];
    };
    $at = static fn (?string $value): ?int => $value === null ? null : (new DateTimeImmutable($value))->getTimestamp();
    // A session snapshot with its times as spans and presence flags.
    $snapshot = static function (array $session) use ($at): array {
        $times = ['lease_expires_at', 'ttl_expires_at', 'closed_at', 'last_heartbeat_at', 'created_at', 'updated_at'];
        $plain = array_diff_key($session, array_flip($times));
        if (is_array($plain['holder'] ?? null)) {
            $plain['holder']['last_heartbeat_at'] = $plain['holder']['last_heartbeat_at'] === null ? null : 'present';
        }
        $heartbeat = $at($session['last_heartbeat_at'] ?? null);

        return $plain + [
            'lease_span' => isset($session['lease_expires_at']) && $heartbeat !== null ? $at($session['lease_expires_at']) - $heartbeat : null,
            'ttl_span' => isset($session['ttl_expires_at'], $session['created_at']) ? $at($session['ttl_expires_at']) - $at($session['created_at']) : null,
            'closed' => ($session['closed_at'] ?? null) !== null,
            'times' => array_map(static fn (string $key): string => ($session[$key] ?? null) === null ? 'null' : 'present', array_combine($times, $times)),
        ];
    };
    $answer = static function (array $recorded) use ($snapshot): array {
        $body = $recorded['response'];
        if (is_array($body)) {
            if (is_array($body['session'] ?? null)) {
                $body['session'] = $snapshot($body['session']);
            }
            if (is_array($body['sessions'] ?? null)) {
                $body['sessions'] = array_map($snapshot, $body['sessions']);
            }
        }

        return ['status' => $recorded['status'], 'response' => $body];
    };
    $ok = static function (array $recorded, int $status, string $operation): array {
        if ($recorded['status'] !== $status) {
            throw new RuntimeException('Worker sessions '.$operation.': '.json_encode($recorded));
        }

        return $recorded['response'];
    };
    $id = static fn (string $name): string => $workflowId.'-'.$name;
    $register = static function (string $worker, string $queue, array $capabilities = [], ?int $sessions = null) use ($raw, $ok, $definition): void {
        $ok($raw('POST', '/api/worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
            'sdk_version' => 'raw-http', 'supported_workflow_types' => [$definition['workflow_type']],
            'supported_activity_types' => [$definition['activity_type']], 'capabilities' => $capabilities,
            'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
                ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http'])]
            + ($sessions === null ? [] : ['max_concurrent_worker_sessions' => $sessions])), 201, 'registration '.$worker);
    };
    $beat = static function (string ...$workers) use ($raw): void {
        foreach ($workers as $worker) {
            $raw('POST', '/api/worker/heartbeat', ['worker_id' => $worker]);
        }
    };
    $create = static fn (array $body, ?string $protocol = '1.20'): array => $raw('POST', '/api/worker/sessions', $body, $protocol);
    $heartbeat = static fn (string $session, array $body): array => $raw('POST', '/api/worker/sessions/'.rawurlencode($session).'/heartbeat', $body);
    $close = static fn (string $session, array $body = [], string $query = ''): array => $raw('DELETE',
        '/api/worker/sessions/'.rawurlencode($session).$query, $body === [] ? null : $body);
    $show = static fn (string $session): array => $raw('GET', '/api/worker-sessions/'.rawurlencode($session), null, null, true);

    [$w1, $w2, $w3] = [$id('wss-w1'), $id('wss-w2'), $id('wss-w3')];
    [$q1, $q2] = [$id('wss-q1'), $id('wss-q2')];
    $register($w1, $q1, ['gpu'], 2);
    $register($w2, $q1);
    $register($w3, $q2);
    [$s1, $s2, $s3] = [$id('wss-s1'), $id('wss-s2'), $id('wss-s3')];
    $state = ['applicable' => true, 'namespace' => $namespace, 'workers' => [$w1, $w2, $w3], 'queues' => [$q1, $q2]];

    // 1. Gates.
    $state['gate_no_protocol'] = $answer($create(['worker_id' => $w1, 'session_id' => $s1], null));
    $state['gate_protocol_1_7'] = $answer($create(['worker_id' => $w1, 'session_id' => $s1], '1.7'));
    $state['gate_protocol_1_21'] = $answer($create(['worker_id' => $w1, 'session_id' => $s1], '1.21'));
    $state['gate_protocol_1_8_empty'] = $answer($create([], '1.8'));
    $state['gate_unknown_namespace'] = $answer($raw('POST', '/api/worker/sessions', ['worker_id' => $w1, 'session_id' => $s1], '1.20', false, 'no-such-namespace-wss'));
    $state['gate_list_without_control_plane'] = $answer($raw('GET', '/api/worker-sessions', null, '1.20'));
    $state['gate_list_unknown_namespace'] = $answer($raw('GET', '/api/worker-sessions', null, null, true, 'no-such-namespace-wss'));

    // 2. Validation.
    $state['invalid_empty'] = $answer($create([]));
    $state['invalid_types'] = $answer($create(['worker_id' => 5, 'session_id' => '   ', 'requirements' => 'x', 'lease_seconds' => 0,
        'ttl_seconds' => 'a', 'max_concurrent_activities' => 1.5, 'create_if_missing' => 'yes', 'allow_reacquire_after_failure' => 2,
        'queue' => [], 'connection' => 7]));
    $state['invalid_heartbeat'] = $answer($heartbeat($s1, ['lease_seconds' => 0]));
    $state['invalid_close'] = $answer($close($s1));

    // 3. Registration and routing.
    $state['unregistered'] = $answer($create(['worker_id' => $id('wss-nobody'), 'session_id' => $s1]));
    $state['queue_mismatch'] = $answer($create(['worker_id' => $w1, 'session_id' => $s1, 'queue' => $q2]));
    $state['requirements_missing'] = $answer($create(['worker_id' => $w1, 'session_id' => $s1, 'requirements' => ['gpu', 'tpu', ' tpu ', 'fpga']]));
    $state['missing_without_create'] = $answer($create(['worker_id' => $w1, 'session_id' => $s1, 'create_if_missing' => false]));

    // 4-6. Create, a float lease, reuse.
    $state['created'] = $answer($create(['worker_id' => $w1, 'session_id' => ' '.$s1.' ', 'requirements' => ['gpu', ' gpu '],
        'lease_seconds' => '60', 'ttl_seconds' => 600, 'connection' => 'c1']));
    // A float lease passes validation as an integer; sent as JSON 7.0, since
    // json_encode writes 7.0 as 7.
    $state['float_lease'] = $answer($raw('POST', '/api/worker/sessions', [], '1.20', false, null,
        json_encode(['worker_id' => $w1, 'session_id' => $s2, 'lease_seconds' => 7.0], JSON_PRESERVE_ZERO_FRACTION)));
    $state['reused'] = $answer($create(['worker_id' => $w1, 'session_id' => $s1, 'ttl_seconds' => 9999, 'lease_seconds' => 30,
        'max_concurrent_activities' => 3, 'create_if_missing' => false]));

    // 7. Another worker.
    $state['foreign_create'] = $answer($create(['worker_id' => $w2, 'session_id' => $s1]));
    $state['foreign_heartbeat'] = $answer($heartbeat($s1, ['worker_id' => $w2]));
    $state['foreign_close'] = $answer($close($s1, ['worker_id' => $w2]));

    // 8. Heartbeat.
    $state['heartbeat_missing'] = $answer($heartbeat($id('wss-none'), ['worker_id' => $w1]));
    $state['heartbeat'] = $answer($heartbeat($s1, ['worker_id' => $w1, 'lease_seconds' => 5]));

    // 9. The limit: S1 and S2 are held, so a third is refused.
    $state['limit'] = $answer($create(['worker_id' => $w1, 'session_id' => $s3]));

    // 10. Lease expiry, on read. S1's lease is 5 seconds from the heartbeat.
    $beat($w1, $w2, $w3);
    $short = $id('wss-short');
    $state['short_created'] = $answer($create(['worker_id' => $w2, 'session_id' => $short, 'lease_seconds' => 2]));
    usleep(3200000);
    $state['short_expired'] = $answer($show($short));

    // 11. Reacquire by another worker, with no options.
    $state['reacquired'] = $answer($create(['worker_id' => $w3, 'session_id' => $short]));
    $state['reacquired_heartbeat'] = $answer($heartbeat($short, ['worker_id' => $w3]));

    // 12. Close.
    $state['close_by_old_owner'] = $answer($close($short, ['worker_id' => $w2]));
    $state['closed'] = $answer($close($short, [], '?worker_id='.rawurlencode($w3)));
    $state['closed_again'] = $answer($close($short, ['worker_id' => $w3]));
    $state['closed_by_other'] = $answer($close($short, ['worker_id' => $w2]));
    $state['closed_heartbeat'] = $answer($heartbeat($short, ['worker_id' => $w3]));
    $state['closed_create'] = $answer($create(['worker_id' => $w3, 'session_id' => $short]));
    $state['closed_create_unmet'] = $answer($create(['worker_id' => $w3, 'session_id' => $short, 'requirements' => ['gpu']]));

    // 13. Reacquire refused, by the stored option and by the request's.
    $beat($w1, $w2, $w3);
    [$terminal, $optional] = [$id('wss-terminal'), $id('wss-optional')];
    $create(['worker_id' => $w2, 'session_id' => $terminal, 'lease_seconds' => 2, 'allow_reacquire_after_failure' => false]);
    $create(['worker_id' => $w2, 'session_id' => $optional, 'lease_seconds' => 2]);
    usleep(3200000);
    $state['reacquire_terminal'] = $answer($create(['worker_id' => $w3, 'session_id' => $terminal]));
    $state['reacquire_disallowed'] = $answer($create(['worker_id' => $w3, 'session_id' => $optional, 'allow_reacquire_after_failure' => false]));

    // 14. TTL expiry is terminal; the owner can still close.
    $beat($w1, $w2, $w3);
    $ttl = $id('wss-ttl');
    $create(['worker_id' => $w2, 'session_id' => $ttl, 'ttl_seconds' => 2, 'lease_seconds' => 60]);
    usleep(3200000);
    $state['ttl_heartbeat'] = $answer($heartbeat($ttl, ['worker_id' => $w2]));
    $state['ttl_create'] = $answer($create(['worker_id' => $w2, 'session_id' => $ttl]));
    $state['ttl_close'] = $answer($close($ttl, ['worker_id' => $w2]));

    // 15. A holder deregistered: orphaned on read, then reacquired.
    $beat($w1, $w2, $w3);
    $held = $id('wss-held');
    $state['held_created'] = $answer($create(['worker_id' => $w3, 'session_id' => $held]));
    $state['holder_deregistered'] = $raw('DELETE', '/api/worker/registrations/'.rawurlencode($w3))['status'];
    $state['held_orphaned'] = $answer($show($held));
    $state['deregistered_create'] = $answer($create(['worker_id' => $w3, 'session_id' => $held]));
    $state['orphan_reacquired'] = $answer($create(['worker_id' => $w2, 'session_id' => $held]));
    $state['orphan_queue_mismatch'] = $answer($create(['worker_id' => $w2, 'session_id' => $held, 'queue' => $q2]));

    // Visibility: one session, and the namespace's list and metrics.
    $state['show_missing'] = $answer($show($id('wss-none')));
    $list = $answer($raw('GET', '/api/worker-sessions', null, null, true));
    $state['list_metrics'] = $list['response']['metrics'] ?? null;
    $state['list_namespace'] = $list['response']['namespace'] ?? null;
    // Order ties within a second break by session ID; record membership.
    $sessions = $list['response']['sessions'] ?? [];
    usort($sessions, static fn (array $a, array $b): int => strcmp($a['session_id'], $b['session_id']));
    $state['list_sessions'] = $sessions;

    return $state;
}
