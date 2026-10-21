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
// roles ("database is locked"). A 500 for that rolls the whole operation
// back, so the step is sent again. A backfill instead records the lost
// occurrence as a failure and goes on, which changes the schedule, so the
// whole sequence is replayed once more in a new namespace. Each is recorded.
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
        $send = static function (string $method, string $path, mixed $body = null) use ($http, $token, $namespace, &$retries, &$pageToken): array {
            $target = str_replace('{token}', rawurlencode($pageToken), $path);
            for ($try = 1; ; $try++) {
                $response = $http->request($method, $target, ['headers' => array_filter([
                    'Authorization' => $token === null ? null : 'Bearer '.$token,
                    'Accept' => 'application/json', 'X-Namespace' => $namespace,
                    'X-Durable-Workflow-Control-Plane-Version' => '2',
                ]), ...($body === null ? [] : ['json' => $body === [] ? (object) [] : $body])]);
                $text = (string) $response->getBody();
                if ($response->getStatusCode() === 500 && str_contains($text, 'database is locked') && $try < 5) {
                    $retries++;
                    usleep(200000 * $try);

                    continue;
                }
                $decoded = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
                if (is_string($decoded['next_page_token'] ?? null)) {
                    $pageToken = $decoded['next_page_token'];
                }

                return ['request' => "$method $path", 'status' => $response->getStatusCode(), 'response' => $decoded];
            }
        };
        $steps = [];
        $locked = false;
        foreach ($definition['steps'] as $step) {
            if ($step[0] === '@policy') {
                $policy = $step[1];
                $id = 'policy-'.str_replace('_', '-', $policy);
                $steps[] = $send('POST', 'schedules', ['schedule_id' => $id, 'spec' => ['cron_expressions' => ['0 0 1 1 *']],
                    'action' => ['workflow_type' => $fixture['workflow_type'], 'task_queue' => $queue, 'input' => $input],
                    'overlap_policy' => $policy]);
                for ($trigger = 0; $trigger < 3; $trigger++) {
                    $steps[] = $send('POST', "schedules/$id/trigger", []);
                }
                $steps[] = $send('GET', "schedules/$id");

                continue;
            }
            $steps[] = $send($step[0], $step[1], array_key_exists(2, $step) ? $fill($step[2]) : null);
            $last = end($steps);
            if ($last['status'] !== 500 && str_contains(json_encode($last['response']), 'database is locked')) {
                $locked = true;

                break;
            }
        }
        if (! $locked || $attempt === 3) {
            return ['applicable' => true, 'namespace' => $namespace, 'queue' => $queue, 'retries' => $retries,
                'attempts' => $attempt, 'steps' => $steps];
        }
    }
}
