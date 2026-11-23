<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * From 3.0.0 the published Compose file runs the native server (Server #325):
 * one long-lived process with MySQL, plus two one-shot schema services.
 * The PHP 2.x stack stays in docker-compose.php.yml.
 */
class NativePublishedComposeContractTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function test_published_compose_runs_the_native_server_and_mysql_after_schema_setup(): void
    {
        $compose = Yaml::parse($this->read('docker-compose.published.yml'));
        $services = $compose['services'];
        $server = $services['server'];
        $plan = $services['schema-plan'];
        $install = $services['schema-install'];

        $this->assertSame(['server', 'schema-plan', 'schema-install', 'mysql'], array_keys($services));
        $this->assertSame(['mysql_data', 'server_storage', 'schema_plan'], array_keys($compose['volumes']));
        $this->assertSame('service_healthy', $plan['depends_on']['mysql']['condition']);
        $this->assertSame('service_completed_successfully', $install['depends_on']['schema-plan']['condition']);
        $this->assertSame('service_completed_successfully', $server['depends_on']['schema-install']['condition']);
        $this->assertSame($server['image'], $plan['image']);
        $this->assertSame($services['mysql']['image'], $install['image']);
        foreach ([$plan, $install] as $step) {
            $this->assertSame('no', $step['restart'] ?? 'no');
            $this->assertArrayNotHasKey('ports', $step);
        }
    }

    public function test_schema_install_keeps_the_database_admin_credential_outside_the_native_image(): void
    {
        $services = Yaml::parse($this->read('docker-compose.published.yml'))['services'];
        $plan = $services['schema-plan'];
        $install = $services['schema-install'];

        foreach ([$services['server'], $plan] as $native) {
            $this->assertArrayNotHasKey('MYSQL_PWD', $native['environment']);
            $this->assertArrayNotHasKey('MYSQL_ROOT_PASSWORD', $native['environment']);
            $this->assertSame($services['mysql']['environment']['MYSQL_USER'], $native['environment']['DB_USERNAME']);
            $this->assertSame($services['mysql']['environment']['MYSQL_PASSWORD'], $native['environment']['DB_PASSWORD']);
        }
        $this->assertSame('service:mysql', $install['network_mode']);
        $this->assertSame('1000:1000', $install['user']);
        $this->assertSame(['schema_plan:/app/storage'], $plan['volumes']);
        $this->assertSame(['schema_plan:/plan:ro'], $install['volumes']);
        $this->assertSame($services['mysql']['environment']['MYSQL_ROOT_PASSWORD'], $install['environment']['MYSQL_PWD']);
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

    public function test_published_compose_fails_closed_without_a_token_and_binds_loopback(): void
    {
        $source = $this->read('docker-compose.published.yml');
        $compose = Yaml::parse($source);

        // No implicit shared token: Compose refuses to render until one is set.
        $this->assertMatchesRegularExpression('/^      DW_AUTH_TOKEN: \$\{DW_AUTH_TOKEN:\?[^}]+\}$/m', $source);
        $this->assertStringNotContainsString('DW_AUTH_TOKEN:-', $source);

        // The API is published on loopback unless an interface is chosen.
        $this->assertSame(
            ['${SERVER_BIND_ADDRESS:-127.0.0.1}:${SERVER_PORT:-8080}:8080'],
            $compose['services']['server']['ports'],
        );
        $this->assertArrayNotHasKey('ports', $compose['services']['mysql']);
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
