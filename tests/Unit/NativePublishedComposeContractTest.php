<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * From 3.0.0 the published Compose file runs the native server (Server #325):
 * one process with MySQL, no PHP bootstrap, worker, scheduler or Redis. The
 * PHP 2.x stack stays in docker-compose.php.yml.
 */
class NativePublishedComposeContractTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function test_published_compose_runs_only_the_native_server_and_mysql(): void
    {
        $compose = Yaml::parse($this->read('docker-compose.published.yml'));

        $this->assertSame(['server', 'mysql'], array_keys($compose['services']));
        $this->assertSame(['mysql_data', 'server_storage'], array_keys($compose['volumes']));
    }

    public function test_default_image_is_a_native_image_pinned_by_digest_or_exact_release(): void
    {
        $source = $this->read('docker-compose.published.yml');

        $this->assertMatchesRegularExpression(
            '/^x-server-image: &server-image \$\{DW_SERVER_IMAGE:-(ghcr\.io\/durable-workflow\/server-native@sha256:[0-9a-f]{64}|durableworkflow\/server:3\.\d+\.\d+)\}$/m',
            $source,
        );
    }

    public function test_server_environment_matches_what_native_accepts(): void
    {
        $server = Yaml::parse($this->read('docker-compose.published.yml'))['services']['server'];
        $environment = $server['environment'];

        // Native refuses DW_AUTH_BACKWARD_COMPATIBLE=false and principal tokens.
        $this->assertArrayNotHasKey('DW_AUTH_BACKWARD_COMPATIBLE', $environment);
        $this->assertArrayNotHasKey('DW_PRINCIPAL_TOKENS', $environment);
        $this->assertSame('mysql', $environment['DB_CONNECTION']);
        $this->assertSame('mysql', $environment['DB_HOST']);
        foreach (['DW_AUTH_TOKEN', 'DW_WORKER_TOKEN', 'DW_OPERATOR_TOKEN', 'DW_ADMIN_TOKEN'] as $token) {
            $this->assertArrayHasKey($token, $environment);
        }
        foreach (array_keys($environment) as $name) {
            $this->assertDoesNotMatchRegularExpression('/^(REDIS_|QUEUE_|CACHE_|APP_)/', $name);
        }
    }

    public function test_healthcheck_reads_readiness_without_curl(): void
    {
        $server = Yaml::parse($this->read('docker-compose.published.yml'))['services']['server'];
        $test = $server['healthcheck']['test'];

        $this->assertSame(['CMD', 'bash', '-c'], array_slice($test, 0, 3));
        $this->assertStringContainsString('/dev/tcp/127.0.0.1/8080', $test[3]);
        $this->assertStringContainsString('GET /api/ready', $test[3]);
        $this->assertSame('service_healthy', $server['depends_on']['mysql']['condition']);
    }

    public function test_php_stack_stays_available_and_release_smoke_uses_it(): void
    {
        $php = Yaml::parse($this->read('docker-compose.php.yml'));
        foreach (['bootstrap', 'server', 'worker', 'scheduler', 'mysql', 'redis'] as $service) {
            $this->assertArrayHasKey($service, $php['services']);
        }

        $release = $this->read('.github/workflows/release.yml');
        $this->assertStringContainsString('DW_PUBLISHED_COMPOSE_FILE: docker-compose.php.yml', $release);
    }

    public function test_published_compose_smoke_runs_on_pull_requests_that_change_it(): void
    {
        $workflow = Yaml::parse($this->read('.github/workflows/published-compose.yml'));
        // YAML 1.1 reads the "on" key as true.
        $on = $workflow['on'] ?? $workflow[true];

        $this->assertContains('docker-compose.published.yml', $on['pull_request']['paths']);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($this->repoRoot.'/'.$path);
        $this->assertIsString($contents);

        return $contents;
    }
}
