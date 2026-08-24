<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class VersionValidationWorkflowContractTest extends TestCase
{
    public function test_validation_workflow_uses_rc_2_server_with_rc_1_clients(): void
    {
        $source = $this->read('.github/workflows/version-validation.yml');
        $workflow = Yaml::parse($source);

        $this->assertIsArray($workflow);
        $job = $workflow['jobs']['version-validation'] ?? null;
        $this->assertIsArray($job);
        $this->assertSame([
            'SERVER_VERSION' => '2.0.0-rc.41',
            'PYTHON_SDK_VERSION' => '2.0.0rc1',
            'COMPOSE_PROJECT_NAME' => 'version-validation-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}',
        ], $job['env'] ?? null);

        $server = $this->step($job, 'Start rc.2 server');
        $this->assertSame('${{ env.SERVER_VERSION }}', $server['env']['APP_VERSION'] ?? null);
        $this->assertStringContainsString('if [ "$VERSION" != "${SERVER_VERSION}" ]; then', $server['run'] ?? '');

        $cli = $this->step($job, 'Install CLI');
        $this->assertStringContainsString('sync-cli-readme-release.mjs --print installer-url', $cli['run'] ?? '');
        $this->assertStringNotContainsString('sync-cli-readme-release.mjs --print version', $cli['run'] ?? '');
        $this->assertStringNotContainsString('VERSION="$cli_version"', $cli['run'] ?? '');
        $this->assertStringContainsString('reported_version="$("$install_dir/dw" --version)"', $cli['run'] ?? '');
        $this->assertStringContainsString('^2\.0\.0-rc\.(0|[1-9][0-9]*)$', $cli['run'] ?? '');

        $python = $this->step($job, 'Install Python SDK');
        $this->assertStringContainsString('pip install "durable-workflow==${PYTHON_SDK_VERSION}"', $python['run'] ?? '');
        $this->assertStringContainsString('version("durable-workflow")', $python['run'] ?? '');

        $refusal = $this->step($job, 'Test Python SDK with incompatible worker protocol (should fail with clear error)');
        $this->assertStringContainsString('"incompatible worker_protocol.version"', $refusal['run'] ?? '');
        $this->assertStringContainsString('"sdk-python requires major-equal"', $refusal['run'] ?? '');
        $this->assertStringNotContainsString('sdk-python 0.2.x', $refusal['run'] ?? '');

        $this->assertStringNotContainsString('git+https://github.com/durable-workflow/sdk-python.git@main', $source);
        $this->assertStringNotContainsString('if [ "$VERSION" != "2.0.0" ]; then', $source);
        $this->assertStringContainsString('port server 8080', $source);
        $this->assertSame(4, substr_count($source, '- "0:8080"'));
        $this->assertSame(8, substr_count($source, 'ports: !override []'));
        $this->assertStringContainsString('os.environ["SERVER_BASE_URL"]', $source);
        $this->assertStringNotContainsString('http://localhost:8080', $source);
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    private function step(array $job, string $name): array
    {
        foreach ($job['steps'] ?? [] as $step) {
            if (is_array($step) && ($step['name'] ?? null) === $name) {
                return $step;
            }
        }

        $this->fail("Workflow step {$name} is missing.");
    }

    private function read(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        $this->assertNotFalse($source, "{$path} must be readable");

        return $source;
    }
}
