<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class PublishedImageFirstRunContractTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function test_image_healthcheck_uses_readiness_instead_of_liveness(): void
    {
        $dockerfile = $this->read('Dockerfile');
        $healthcheck = $this->read('docker/healthcheck.sh');

        $this->assertStringContainsString('CMD ["server-healthcheck"]', $dockerfile);
        $this->assertStringContainsString('/tmp/dw-server-http-process', $healthcheck);
        $this->assertStringContainsString('--max-time 4', $healthcheck);
        $this->assertStringContainsString('http://127.0.0.1:8080/api/ready', $healthcheck);
        $this->assertStringNotContainsString('/api/health', $healthcheck);
        $this->assertStringContainsString('"blockers" => $blockers', $healthcheck);
    }

    public function test_default_server_process_logs_guidance_without_migrating(): void
    {
        $entrypoint = $this->read('docker/entrypoint.sh');

        $this->assertStringContainsString('[ "$1" = "apache2-foreground" ]', $entrypoint);
        $this->assertStringContainsString('touch /tmp/dw-server-http-process', $entrypoint);
        $this->assertStringContainsString('server-bootstrap', $entrypoint);
        $this->assertStringContainsString('authentication', $entrypoint);
        $this->assertStringContainsString('/api/ready', $entrypoint);
        $this->assertStringNotContainsString('php artisan migrate', $entrypoint);
        $this->assertStringNotContainsString('php artisan server:bootstrap', $entrypoint);
    }

    public function test_published_image_smoke_exercises_unhealthy_then_bootstrapped_lifecycle(): void
    {
        $smoke = $this->read('scripts/smoke-published-first-run.sh');

        foreach ([
            '/api/health',
            '/api/ready',
            'wait_for_container_health unhealthy',
            'server-bootstrap',
            'DW_AUTH_DRIVER=token',
            'wait_for_container_health healthy',
        ] as $requiredOperation) {
            $this->assertStringContainsString($requiredOperation, $smoke);
        }
    }

    public function test_published_compose_keeps_automatic_bootstrap_dependency(): void
    {
        $compose = Yaml::parse($this->read('docker-compose.published.yml'));

        $this->assertSame(
            ['server-bootstrap'],
            $compose['services']['bootstrap']['command'] ?? null,
        );
        $this->assertSame(
            'service_completed_successfully',
            $compose['services']['server']['depends_on']['bootstrap']['condition'] ?? null,
        );

        $this->assertTrue(
            $compose['services']['bootstrap']['healthcheck']['disable'] ?? false,
            'The successful one-shot bootstrap must not be modeled as a long-running healthy service.',
        );
        $this->assertSame(
            ['CMD', 'server-process-healthcheck', 'worker'],
            $compose['services']['worker']['healthcheck']['test'] ?? null,
        );
        $this->assertSame(
            ['CMD', 'server-process-healthcheck', 'scheduler'],
            $compose['services']['scheduler']['healthcheck']['test'] ?? null,
        );
    }

    public function test_compose_wait_can_observe_every_long_running_cli_process(): void
    {
        $contracts = [
            'docker-compose.yml' => [
                'worker' => 'worker',
                'scheduler' => 'scheduler',
            ],
            'docker-compose.published.yml' => [
                'worker' => 'worker',
                'scheduler' => 'scheduler',
            ],
            'docker-compose.small-cluster.yml' => [
                'queue-worker' => 'worker',
                'scheduler' => 'scheduler',
            ],
            'docker-compose.failover-rehearsal.yml' => [
                'queue-worker' => 'worker',
                'scheduler' => 'scheduler',
            ],
            'docker-compose.dedicated-matching.yml' => [
                'matching' => 'matching',
            ],
        ];

        foreach ($contracts as $path => $services) {
            $compose = Yaml::parse($this->read($path));

            foreach ($services as $service => $role) {
                $this->assertSame(
                    ['CMD', 'server-process-healthcheck', $role],
                    $compose['services'][$service]['healthcheck']['test'] ?? null,
                    "{$path} must expose a process healthcheck for {$service} so docker compose --wait can complete.",
                );
                $this->assertFalse(
                    $compose['services'][$service]['healthcheck']['disable'] ?? false,
                    "{$path} must not disable healthchecks for long-running {$service}.",
                );
            }
        }
    }

    public function test_process_healthcheck_is_shipped_for_cli_compose_roles(): void
    {
        $dockerfile = $this->read('Dockerfile');
        $healthcheck = $this->read('docker/process-healthcheck.sh');

        $this->assertStringContainsString(
            'COPY docker/process-healthcheck.sh /usr/local/bin/server-process-healthcheck',
            $dockerfile,
        );
        foreach (['artisan queue:work', 'artisan schedule:evaluate', 'artisan workflow:v2:repair-pass --loop'] as $command) {
            $this->assertStringContainsString($command, $healthcheck);
        }
    }

    public function test_release_verifies_bare_and_compose_first_run_before_promotion(): void
    {
        $workflow = $this->read('.github/workflows/release.yml');
        $bareOffset = strpos($workflow, 'Verify bare image first-run readiness');
        $composeOffset = strpos($workflow, 'Verify source-free Compose bootstrap');
        $promotionOffset = strpos($workflow, 'Resolve rolling image aliases');

        $this->assertIsInt($bareOffset);
        $this->assertIsInt($composeOffset);
        $this->assertIsInt($promotionOffset);
        $this->assertLessThan($composeOffset, $bareOffset);
        $this->assertLessThan($promotionOffset, $composeOffset);
        $this->assertSame(2, substr_count($workflow, 'working-directory: release-source'));
        $this->assertStringContainsString('run: scripts/smoke-published-first-run.sh', $workflow);
        $this->assertStringContainsString('run: scripts/smoke-published-compose.sh', $workflow);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($this->repoRoot.'/'.$path);
        $this->assertIsString($contents);

        return $contents;
    }
}
