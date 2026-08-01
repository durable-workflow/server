<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class ServerDiscoveryTest extends TestCase
{
    public function test_root_discovers_the_server_without_authentication(): void
    {
        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => 'configured-token',
        ]);

        $this->getJson('/')
            ->assertOk()
            ->assertJsonPath('service', 'Durable Workflow Server')
            ->assertJsonPath('links.health', '/api/health')
            ->assertJsonPath('links.readiness', '/api/ready')
            ->assertJsonPath('links.cluster_info', '/api/cluster/info')
            ->assertJsonPath('links.setup', 'https://durable-workflow.com/docs/2.0/quickstart/');
    }
}
