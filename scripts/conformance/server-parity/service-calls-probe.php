<?php

declare(strict_types=1);

use DurableWorkflow\Client;

// Nexus service calls, raw and in order, in a fresh namespace: the fixture
// registers an endpoint, a service and its operations, then executes them,
// reads and cancels the calls, and replays idempotency keys. The requests run
// through serviceCatalogRequests (service-endpoints-probe.php).
function finishHttpServiceCalls(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $own = strtolower($workflowId.'-calls');
    $admin->createNamespace($own, 'service calls λ', 30);

    return ['applicable' => true, 'namespace' => $own,
        'responses' => serviceCatalogRequests($fixture['service_calls']['requests'], $own, $url)];
}
