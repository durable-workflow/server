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

        foreach (['bootstrap', 'worker', 'scheduler'] as $service) {
            $this->assertTrue(
                $compose['services'][$service]['healthcheck']['disable'] ?? false,
                "{$service} must not inherit the HTTP server healthcheck.",
            );
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
