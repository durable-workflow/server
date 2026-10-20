<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;

// Worker listing through the unchanged published SDK: registration and
// heartbeat facts, list filters, description and fingerprint refusals.
function finishHttpWorkerListing(Client $client, array $fixture, string $workflowId): array
{
    $definition = $fixture['worker_listing'];
    $receipt = static function (callable $request): array {
        try {
            return ['status' => 200, 'response' => $request()];
        } catch (ServerException $error) {
            return ['status' => $error->status, 'response' => $error->details];
        }
    };
    $first = $workflowId.'-listing-first';
    $second = $workflowId.'-listing-second';
    $queues = [$workflowId.'-listing-a', $workflowId.'-listing-b'];
    $type = $definition['workflow_type'];
    $capacity = $definition['capacity'];
    $registerFirst = static fn (?array $fingerprints): array => $client->registerWorker($first, $queues[0], [$type],
        [$definition['activity_type']], maxConcurrentWorkflowTasks: $capacity['workflow'],
        maxConcurrentActivityTasks: $capacity['activity'], buildId: $definition['build_id'],
        maxConcurrentWorkerSessions: $capacity['sessions'], workflowDefinitionFingerprints: $fingerprints);
    // The namespace is shared with other fixtures' workers; keep only these two.
    $ours = static fn (array $listing): array => ['stale_after_seconds' => $listing['stale_after_seconds'] ?? null,
        'workers' => array_values(array_filter($listing['workers'] ?? [],
            static fn (array $worker): bool => in_array($worker['worker_id'] ?? null, [$first, $second], true)))];
    $state = ['applicable' => true, 'first' => $first, 'second' => $second, 'queues' => $queues, 'probe_pid' => getmypid()];
    $state['first_registration'] = $registerFirst([$type => $definition['fingerprint']]);
    $state['second_registration'] = $client->registerWorker($second, $queues[1], [$type], []);
    $state['heartbeat'] = $client->heartbeatWorker($first, $definition['task_slots']);
    $state['listed'] = $ours($client->listWorkers());
    $state['first_queue'] = $ours($client->listWorkers($queues[0]));
    $state['active'] = $ours($client->listWorkers(null, 'active'));
    $state['stale'] = $ours($client->listWorkers(null, 'stale'));
    $state['described'] = $client->describeWorker($first);
    $state['missing'] = $receipt(static fn (): array => $client->describeWorker($workflowId.'-listing-missing'));
    $state['changed_definition'] = $receipt(static fn (): array => $registerFirst([$type => $definition['changed_fingerprint']]));
    $state['missing_fingerprint'] = $receipt(static fn (): array => $registerFirst([$definition['other_workflow_type'] => $definition['fingerprint']]));
    $state['after_refusals'] = $client->describeWorker($first);
    // Omitting the fingerprints keeps the advertised one.
    $state['reregistration'] = $registerFirst(null);
    $state['after_reregistration'] = $client->describeWorker($first);
    foreach ([$first, $second] as $workerId) {
        $client->deregisterWorker($workerId);
    }
    $state['after_deregistration'] = $ours($client->listWorkers());

    return $state;
}
