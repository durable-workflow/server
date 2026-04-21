<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SmallClusterContractTest extends TestCase
{
    public function test_validation_note_records_the_narrow_proceed_decision(): void
    {
        $source = $this->read('docs/small-cluster-validation.md');

        foreach ([
            'Proceed with a narrow small-cluster contract.',
            'External MySQL or external PostgreSQL',
            'Shared Redis',
            'One scheduler or maintenance process',
            'Stop-the-world upgrades',
            'without sticky sessions',
            'Redis-less multi-node mode',
            'Duplicate scheduler or maintenance runners',
            'Rolling upgrades',
            'Multi-region deployments',
            'Helm charts',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
    }

    public function test_readme_links_to_the_validation_note(): void
    {
        $source = $this->read('README.md');

        foreach ([
            '### Small Cluster Status',
            'docs/small-cluster-validation.md',
            'external MySQL or PostgreSQL',
            'shared Redis',
            'exactly one scheduler or maintenance runner',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
    }

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
            'scheduler:',
            'redis:',
            'mysql:',
            'pgsql:',
            'DW_SERVER_ID: server-a',
            'DW_SERVER_ID: server-b',
            'CACHE_STORE: redis',
            'QUEUE_CONNECTION: redis',
        ] as $needle) {
            $this->assertStringContainsString($needle, $compose);
        }

        foreach ([
            'DW_SMALL_CLUSTER_DATABASES:-mysql,pgsql',
            '/api/health',
            '/api/ready',
            '/api/cluster/info',
            '/api/worker/register',
            '/api/workflows',
            '/api/worker/workflow-tasks/poll',
            '/api/worker/workflow-tasks/${task_id}/complete',
            'server_a_port',
            'server_b_port',
            'Small cluster smoke passed',
        ] as $needle) {
            $this->assertStringContainsString($needle, $script);
        }

        foreach ([
            'name: Small Cluster Smoke',
            'scripts/smoke-small-cluster.sh',
            'docker-compose.small-cluster.yml',
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
