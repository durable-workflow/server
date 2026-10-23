<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as SchedulePoliciesHttp;

// The HTTP schedule surface of a fresh namespace: the fixture's request
// steps, with "@policy" expanded to a schedule of that overlap policy, three
// manual triggers and a description, and "{token}" in a path replaced by the
// last continuation token a response returned. Responses are recorded raw;
// the contract normalizes generated identities and times.
//
// Published PHP on SQLite can lose a write race with its own maintenance
// roles: "database is locked", or from Server 2.10 the structured, retryable
// 503 backend_lock_pressure refusal. A read is simply sent again. A write may
// already have committed when it reports the lock, so it is sent again only
// when its schedule reads the same as before it; otherwise, and when a
// backfill records a lost occurrence as a failure, the whole sequence is
// replayed in a new namespace. Native never reports this lock, so it never
// retries or replays. Each is recorded.
function finishHttpSchedulePolicies(Client $admin, array $fixture, string $workflowId, string $url, string $key = 'schedule_policies'): array
{
    $definition = $fixture[$key];
    $base = strtolower($workflowId.($key === 'schedule_policies' ? '-schedules' : '-catalog'));
    $queue = strtolower($workflowId.'-queue');
    $input = ['codec' => 'avro', 'blob' => $definition['input_blob']];
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $http = new SchedulePoliciesHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30]);
    $fill = static function (mixed $value) use (&$fill, $queue, $input): mixed {
        if ($value === '@queue') {
            return $queue;
        }
        if ($value === '@input') {
            return $input;
        }

        return is_array($value) ? array_map($fill, $value) : $value;
    };
    $retries = 0;
    for ($attempt = 1; ; $attempt++) {
        $namespace = $attempt === 1 ? $base : "$base-r$attempt";
        $admin->createNamespace($namespace, 'schedule policies λ', 30);
        $pageToken = '';
        $request = static function (string $method, string $target, mixed $body = null) use ($http, $token, $namespace): array {
            $response = $http->request($method, $target, ['headers' => array_filter([
                'Authorization' => $token === null ? null : 'Bearer '.$token,
                'Accept' => 'application/json', 'X-Namespace' => $namespace,
                'X-Durable-Workflow-Control-Plane-Version' => '2',
            ]), ...($body === null ? [] : ['json' => $body === [] ? (object) [] : $body])]);

            return [$response->getStatusCode(), (string) $response->getBody()];
        };
        $lockFailure = static fn (int $status, string $text): bool => $status === 500 && str_contains($text, 'database is locked')
            || $status === 503 && str_contains($text, '"reason":"backend_lock_pressure"');
        // The schedule as it reads now, to tell a lock failure that rolled
        // back (unchanged) from one reported after its commit.
        $snapshot = static function (?string $schedule) use ($request, $lockFailure, &$retries): ?string {
            for ($try = 1; $schedule !== null && $try <= 5; $try++) {
                [$status, $text] = $request('GET', 'schedules/'.rawurlencode($schedule));
                if (! $lockFailure($status, $text)) {
                    return "$status $text";
                }
                $retries++;
                usleep(200000 * $try);
            }

            return null;
        };
        $send = static function (string $method, string $path, mixed $body = null) use ($request, $lockFailure, $snapshot, &$retries, &$pageToken): array {
            $target = str_replace('{token}', rawurlencode($pageToken), $path);
            $schedule = null;
            if ($method !== 'GET') {
                $schedule = preg_match('#^schedules/([^/?]+)#', $path, $match) === 1
                    ? rawurldecode($match[1])
                    : (is_array($body) && is_string($body['schedule_id'] ?? null) ? $body['schedule_id'] : null);
            }
            $before = $method === 'GET' ? null : $snapshot($schedule);
            for ($try = 1; ; $try++) {
                [$status, $text] = $request($method, $target, $body);
                // A read is sent again; a write only when the schedule shows
                // it rolled back. Otherwise the failure is recorded, and the
                // recorded lock replays the sequence.
                if ($lockFailure($status, $text) && $try < 5
                    && ($method === 'GET' || ($before !== null && $snapshot($schedule) === $before))) {
                    $retries++;
                    usleep(200000 * $try);

                    continue;
                }
                $decoded = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
                if (is_string($decoded['next_page_token'] ?? null)) {
                    $pageToken = $decoded['next_page_token'];
                }

                return ['request' => "$method $path", 'status' => $status, 'response' => $decoded];
            }
        };
        $steps = [];
        $locked = false;
        // Records a step; true once a response reports the lock.
        $record = static function (array $step) use (&$steps, &$locked): bool {
            $steps[] = $step;
            $text = json_encode($step['response']);
            $locked = str_contains($text, 'database is locked') || str_contains($text, '"reason":"backend_lock_pressure"');

            return $locked;
        };
        foreach ($definition['steps'] as $step) {
            if ($step[0] === '@policy') {
                $policy = $step[1];
                $id = 'policy-'.str_replace('_', '-', $policy);
                $requests = [['POST', 'schedules', ['schedule_id' => $id, 'spec' => ['cron_expressions' => ['0 0 1 1 *']],
                    'action' => ['workflow_type' => $fixture['workflow_type'], 'task_queue' => $queue, 'input' => $input],
                    'overlap_policy' => $policy]]];
                for ($trigger = 0; $trigger < 3; $trigger++) {
                    $requests[] = ['POST', "schedules/$id/trigger", []];
                }
                $requests[] = ['GET', "schedules/$id", null];
                foreach ($requests as [$method, $path, $body]) {
                    if ($record($send($method, $path, $body))) {
                        break 2;
                    }
                }

                continue;
            }
            if ($record($send($step[0], $step[1], array_key_exists(2, $step) ? $fill($step[2]) : null))) {
                break;
            }
        }
        if (! $locked || $attempt === 5) {
            return ['applicable' => true, 'namespace' => $namespace, 'queue' => $queue, 'retries' => $retries,
                'attempts' => $attempt, 'steps' => $steps];
        }
    }
}
