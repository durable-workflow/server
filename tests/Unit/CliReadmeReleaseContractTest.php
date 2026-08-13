<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class CliReadmeReleaseContractTest extends TestCase
{
    public function test_readme_cli_channel_and_release_authority_are_machine_owned(): void
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

    public function test_release_workflow_selects_fail_closed_github_and_non_github_paths(): void
    {
        $workflow = Yaml::parse($this->read('.github/workflows/cli-readme-release.yml'));
        $this->assertIsArray($workflow);
        $this->assertArrayHasKey('pull_request', $workflow['on'] ?? []);
        $this->assertNull($workflow['on']['pull_request']);
        $this->assertNotEmpty($workflow['on']['schedule'] ?? null);
        $this->assertContains('product-train-release', $workflow['on']['repository_dispatch']['types'] ?? []);

        $job = $workflow['jobs']['release'] ?? null;
        $this->assertIsArray($job);
        $this->assertSame(['contents' => 'read'], $workflow['permissions'] ?? null);

        $github = $this->workflowStep($job, 'Check generated README channel and public release authority on GitHub');
        $this->assertSame("\${{ github.server_url == 'https://github.com' }}", $github['if'] ?? null);
        $this->assertSame(['GH_TOKEN' => '${{ github.token }}'], $github['env'] ?? null);
        $this->assertSame('node scripts/ci/sync-cli-readme-release.mjs --check', $github['run'] ?? null);
        $this->assertArrayNotHasKey('continue-on-error', $github);

        $forgejo = $this->workflowStep($job, 'Check generated README channel and checked-in release authority');
        $this->assertSame("\${{ github.server_url != 'https://github.com' }}", $forgejo['if'] ?? null);
        $this->assertArrayNotHasKey('env', $forgejo);
        $this->assertSame('node scripts/ci/sync-cli-readme-release.mjs --check --offline', $forgejo['run'] ?? null);
        $this->assertArrayNotHasKey('continue-on-error', $forgejo);

        $assets = $this->workflowStep($job, 'Verify public release assets and installed binary identity');
        $this->assertArrayNotHasKey('if', $assets);
        $this->assertArrayNotHasKey('env', $assets);
        $this->assertSame('scripts/ci/verify-cli-readme-release.sh', $assets['run'] ?? null);
        $this->assertArrayNotHasKey('continue-on-error', $assets);
    }

    public function test_public_release_check_refuses_cross_provider_credentials(): void
    {
        $process = $this->runSyncScriptWithEnvironment([
            'GITHUB_SERVER_URL' => 'https://forgejo.example.test',
            'GH_TOKEN' => 'forgejo-token-must-not-be-used',
            'GITHUB_TOKEN' => false,
        ], '--check');

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(
            'public GitHub API release checks are unavailable outside GitHub Actions',
            $process->getErrorOutput(),
        );
        $this->assertStringNotContainsString('forgejo-token-must-not-be-used', $process->getErrorOutput());
    }

    public function test_public_release_check_requires_the_read_only_github_token(): void
    {
        $process = $this->runSyncScriptWithEnvironment([
            'GITHUB_SERVER_URL' => 'https://github.com',
            'GH_TOKEN' => false,
            'GITHUB_TOKEN' => false,
        ], '--check');

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(
            'public GitHub API release checks require a read-only GH_TOKEN or GITHUB_TOKEN',
            $process->getErrorOutput(),
        );
    }

    public function test_offline_release_check_fails_closed_on_invalid_authority(): void
    {
        $temporaryRoot = sys_get_temp_dir().'/dw-cli-readme-contract-'.bin2hex(random_bytes(8));
        $scriptDirectory = $temporaryRoot.'/scripts/ci';
        $authorityDirectory = $temporaryRoot.'/resources/release';
        $this->assertTrue(mkdir($scriptDirectory, 0700, true));
        $this->assertTrue(mkdir($authorityDirectory, 0700, true));

        $script = $scriptDirectory.'/sync-cli-readme-release.mjs';
        $authorityPath = $authorityDirectory.'/cli-readme-release.json';
        $readmePath = $temporaryRoot.'/README.md';
        $authority = $this->readJson('resources/release/cli-readme-release.json');
        $authority['repository'] = 'untrusted/cli';

        $this->assertTrue(copy($this->repositoryRoot().'/scripts/ci/sync-cli-readme-release.mjs', $script));
        $this->assertNotFalse(file_put_contents($authorityPath, json_encode($authority, JSON_THROW_ON_ERROR)));
        $this->assertNotFalse(file_put_contents($readmePath, $this->read('README.md')));

        try {
            $process = new Process(['node', $script, '--check', '--offline'], $temporaryRoot);
            $process->setEnv([
                'GITHUB_SERVER_URL' => 'https://forgejo.example.test',
                'GH_TOKEN' => false,
                'GITHUB_TOKEN' => false,
            ]);
            $process->run();
        } finally {
            foreach ([$script, $authorityPath, $readmePath] as $path) {
                unlink($path);
            }
            foreach ([$scriptDirectory, dirname($scriptDirectory), $authorityDirectory, dirname($authorityDirectory), $temporaryRoot] as $path) {
                rmdir($path);
            }
        }

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(
            'checked-in CLI release authority must select durable-workflow/cli',
            $process->getErrorOutput(),
        );
    }

    public function test_generated_channel_keeps_the_generic_and_versioned_installers_distinct(): void
    {
        $authority = $this->readJson('resources/release/cli-readme-release.json');
        $version = $authority['version'] ?? '';

        $command = $this->runSyncScript('--print', 'installer-command');
        $this->assertSame("curl -fsSL https://durable-workflow.com/install.sh | sh\n", $command->getOutput());

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
        $process = $this->newSyncScriptProcess($arguments);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return $process;
    }

    /**
     * @param  array<string, string|false>  $environment
     */
    private function runSyncScriptWithEnvironment(array $environment, string ...$arguments): Process
    {
        $process = $this->newSyncScriptProcess($arguments);
        $process->setEnv($environment);
        $process->run();

        return $process;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function newSyncScriptProcess(array $arguments): Process
    {
        $process = new Process([
            'node',
            'scripts/ci/sync-cli-readme-release.mjs',
            ...$arguments,
        ], $this->repositoryRoot());

        return $process;
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    private function workflowStep(array $job, string $name): array
    {
        foreach ($job['steps'] ?? [] as $step) {
            if (is_array($step) && ($step['name'] ?? null) === $name) {
                return $step;
            }
        }

        $this->fail("Workflow step {$name} is missing.");
    }

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
