<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as SchedulePoliciesHttp;

// The HTTP schedule surface of a fresh namespace: the fixture's request
// steps, with "@policy" expanded to a schedule of that overlap policy, three
// manual triggers and a description. Responses are recorded raw; the
// contract normalizes generated identities and times.
function finishHttpSchedulePolicies(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['schedule_policies'];
    $namespace = strtolower($workflowId.'-schedules');
    $admin->createNamespace($namespace, 'schedule policies λ', 30);
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
    $send = static function (string $method, string $path, mixed $body = null) use ($http, $token, $namespace, &$retries): array {
        for ($attempt = 1; ; $attempt++) {
            $response = $http->request($method, $path, ['headers' => array_filter([
                'Authorization' => $token === null ? null : 'Bearer '.$token,
                'Accept' => 'application/json', 'X-Namespace' => $namespace,
                'X-Durable-Workflow-Control-Plane-Version' => '2',
            ]), ...($body === null ? [] : ['json' => $body === [] ? (object) [] : $body])]);
            $text = (string) $response->getBody();
            // Published PHP on SQLite can lose a write race with its own
            // maintenance roles. The whole operation rolls back first.
            if ($response->getStatusCode() === 500 && str_contains($text, 'database is locked') && $attempt < 5) {
                $retries++;
                usleep(200000 * $attempt);

                continue;
            }

            return ['request' => "$method $path", 'status' => $response->getStatusCode(),
                'response' => json_decode($text, true, flags: JSON_THROW_ON_ERROR)];
        }
    };
    $steps = [];
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
    }

    return ['applicable' => true, 'namespace' => $namespace, 'queue' => $queue, 'retries' => $retries, 'steps' => $steps];
}
