<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class CliReadmeReleaseContractTest extends TestCase
{
    public function test_readme_cli_install_is_generated_from_an_independent_cli_release_authority(): void
    {
        $authority = $this->readJson('resources/release/cli-readme-release.json');

        $this->assertSame('durable-workflow.cli-readme-release/v1', $authority['schema'] ?? null);
        $this->assertSame('durable-workflow/cli', $authority['repository'] ?? null);
        $this->assertSame('2.0-rc', $authority['channel'] ?? null);
        $this->assertMatchesRegularExpression('/^2\.0\.0-rc\.(?:0|[1-9][0-9]*)$/', $authority['version'] ?? '');
        $this->assertSame($authority['version'] ?? null, $authority['tag'] ?? null);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $authority['commit'] ?? '');
        $this->assertSame(
            'https://github.com/durable-workflow/cli/releases/tag/'.($authority['version'] ?? ''),
            $authority['release_url'] ?? null,
        );

        $process = new Process([
            'node',
            'scripts/ci/sync-cli-readme-release.mjs',
            '--check',
            '--offline',
        ], $this->repositoryRoot());
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    public function test_public_release_drift_workflow_runs_the_structural_and_install_checks(): void
    {
        $workflow = Yaml::parse($this->read('.github/workflows/cli-readme-release.yml'));
        $this->assertIsArray($workflow);
        $this->assertArrayHasKey('pull_request', $workflow['on'] ?? []);
        $this->assertNull($workflow['on']['pull_request']);
        $this->assertNotEmpty($workflow['on']['schedule'] ?? null);
        $this->assertContains('product-train-release', $workflow['on']['repository_dispatch']['types'] ?? []);

        $job = $workflow['jobs']['release'] ?? null;
        $this->assertIsArray($job);
        $commands = array_values(array_filter(array_map(
            static fn (mixed $step): ?string => is_array($step) && is_string($step['run'] ?? null)
                ? $step['run']
                : null,
            $job['steps'] ?? [],
        )));

        $this->assertContains('node scripts/ci/sync-cli-readme-release.mjs --check', $commands);
        $this->assertContains('scripts/ci/verify-cli-readme-release.sh', $commands);
    }

    public function test_generated_pin_keeps_the_generic_and_versioned_installer_channels_distinct(): void
    {
        $authority = $this->readJson('resources/release/cli-readme-release.json');
        $version = $authority['version'] ?? '';

        $installer = $this->runSyncScript('--print', 'installer-url');
        $this->assertSame("https://durable-workflow.com/install.sh\n", $installer->getOutput());

        $releaseInstaller = $this->runSyncScript('--print', 'release-installer-url');
        $this->assertSame(
            "https://github.com/durable-workflow/cli/releases/download/{$version}/install.sh\n",
            $releaseInstaller->getOutput(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $value = json_decode($this->read($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($value);

        return $value;
    }

    private function read(string $path): string
    {
        $source = file_get_contents($this->repositoryRoot().'/'.$path);
        $this->assertNotFalse($source, "{$path} must be readable");

        return $source;
    }

    private function runSyncScript(string ...$arguments): Process
    {
        $process = new Process([
            'node',
            'scripts/ci/sync-cli-readme-release.mjs',
            ...$arguments,
        ], $this->repositoryRoot());
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return $process;
    }

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
