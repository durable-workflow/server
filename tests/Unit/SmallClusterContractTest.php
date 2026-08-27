<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SmallClusterContractTest extends TestCase
{
    public function test_compose_harness_proves_the_narrow_cluster_shape(): void
    {
        $compose = $this->read('docker-compose.small-cluster.yml');
        $script = $this->read('scripts/smoke-small-cluster.sh');
        $workflow = $this->read('.github/workflows/small-cluster.yml');

        foreach ([
            'server-a:',
            'server-b:',
            'load-balancer:',
            'bootstrap:',
            'queue-worker:',
            'scheduler:',
            'redis:',
            'mysql:',
            'pgsql:',
            'DW_SERVER_ID: server-a',
            'DW_SERVER_ID: server-b',
            'DW_SERVER_TOPOLOGY_SHAPE: standalone_server',
            'DW_SERVER_PROCESS_CLASS: server_http_node',
            'DW_SERVER_PROCESS_CLASS: worker_node',
            'DW_SERVER_PROCESS_CLASS: scheduler_node',
            'CACHE_STORE: redis',
            'QUEUE_CONNECTION: redis',
            'command: ["php", "artisan", "queue:work", "redis"',
            'condition: service_completed_successfully',
            'condition: service_healthy',
        ] as $needle) {
            $this->assertStringContainsString($needle, $compose);
        }

        foreach ([
            'DW_SMALL_CLUSTER_DATABASES:-mysql,pgsql',
            'docker-compose.small-cluster.yml',
            '/api/health',
            '/api/ready',
            '/api/cluster/info',
            '/api/worker/register',
            '/api/workflows',
            '/message-streams/orders/messages',
            'message-stream-diagnostics',
            'overlong_message_id',
            '/api/worker/workflow-tasks/poll',
            '/api/worker/workflow-tasks/${task_id}/complete',
            'server_a_port',
            'server_b_port',
            "grep -Fxq 'queue-worker'",
            'Small cluster smoke passed',
        ] as $needle) {
            $this->assertStringContainsString($needle, $script);
        }

        foreach ([
            'name: Small Cluster Smoke',
            'scripts/smoke-small-cluster.sh',
            'DW_SMALL_CLUSTER_DATABASES',
        ] as $needle) {
            $this->assertStringContainsString($needle, $workflow);
        }
    }

    private function read(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        $this->assertNotFalse($source, "{$path} must be readable");

        return $source;
    }
}
