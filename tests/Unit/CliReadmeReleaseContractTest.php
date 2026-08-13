<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class CliReadmeReleaseContractTest extends TestCase
{
    public function test_readme_cli_channel_is_machine_owned_without_an_exact_release_pin(): void
    {
        $authority = $this->readJson('resources/release/cli-readme-channel.json');

        $this->assertSame([
            'schema',
            'repository',
            'channel',
            'installer_url',
        ], array_keys($authority));
        $this->assertSame('durable-workflow.cli-readme-channel/v1', $authority['schema'] ?? null);
        $this->assertSame('durable-workflow/cli', $authority['repository'] ?? null);
        $this->assertSame('2.0-rc', $authority['channel'] ?? null);
        $this->assertSame('https://durable-workflow.com/install.sh', $authority['installer_url'] ?? null);
        $this->assertArrayNotHasKey('version', $authority);
        $this->assertArrayNotHasKey('tag', $authority);
        $this->assertArrayNotHasKey('commit', $authority);
        $this->assertArrayNotHasKey('release_url', $authority);

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
        $this->assertSame([
            'GH_TOKEN' => '${{ github.token }}',
            'CLI_RELEASE_EVIDENCE_PATH' => '${{ runner.temp }}/cli-channel-resolution.json',
        ], $github['env'] ?? null);
        $this->assertSame('scripts/ci/check-cli-readme-public-release.sh', $github['run'] ?? null);
        $this->assertArrayNotHasKey('continue-on-error', $github);

        $forgejo = $this->workflowStep($job, 'Check generated README channel and checked-in channel authority');
        $this->assertSame("\${{ github.server_url != 'https://github.com' }}", $forgejo['if'] ?? null);
        $this->assertArrayNotHasKey('env', $forgejo);
        $this->assertSame('node scripts/ci/sync-cli-readme-release.mjs --check --offline', $forgejo['run'] ?? null);
        $this->assertArrayNotHasKey('continue-on-error', $forgejo);

        $assets = $this->workflowStep($job, 'Verify public release assets and installed binary identity');
        $this->assertSame("\${{ github.server_url == 'https://github.com' }}", $assets['if'] ?? null);
        $this->assertSame([
            'CLI_RELEASE_EVIDENCE_PATH' => '${{ runner.temp }}/cli-channel-resolution.json',
        ], $assets['env'] ?? null);
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

    public function test_public_release_entrypoint_requires_trusted_evidence_destination(): void
    {
        $process = new Process([
            'scripts/ci/check-cli-readme-public-release.sh',
        ], $this->repositoryRoot());
        $process->setEnv([
            'CLI_RELEASE_EVIDENCE_PATH' => false,
        ]);
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(
            'CLI_RELEASE_EVIDENCE_PATH must identify trusted public channel evidence',
            $process->getErrorOutput(),
        );
    }

    public function test_offline_channel_check_fails_closed_on_invalid_authority(): void
    {
        $temporaryRoot = sys_get_temp_dir().'/dw-cli-readme-contract-'.bin2hex(random_bytes(8));
        $scriptDirectory = $temporaryRoot.'/scripts/ci';
        $authorityDirectory = $temporaryRoot.'/resources/release';
        $this->assertTrue(mkdir($scriptDirectory, 0700, true));
        $this->assertTrue(mkdir($authorityDirectory, 0700, true));

        $script = $scriptDirectory.'/sync-cli-readme-release.mjs';
        $authorityPath = $authorityDirectory.'/cli-readme-channel.json';
        $readmePath = $temporaryRoot.'/README.md';
        $authority = $this->readJson('resources/release/cli-readme-channel.json');
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
            $this->removeTree($temporaryRoot);
        }

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(
            'checked-in CLI channel authority must select durable-workflow/cli',
            $process->getErrorOutput(),
        );
    }

    public function test_newer_complete_public_release_produces_ephemeral_immutable_evidence(): void
    {
        [$process, $evidence] = $this->runPublicChannelFixture();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame('durable-workflow.cli-channel-resolution/v1', $evidence['schema'] ?? null);
        $this->assertSame('2.0.0-rc.999', $evidence['version'] ?? null);
        $this->assertSame('2.0.0-rc.999', $evidence['tag'] ?? null);
        $this->assertSame(str_repeat('a', 40), $evidence['commit'] ?? null);
        $this->assertSame(
            'https://github.com/durable-workflow/cli/releases/download/2.0.0-rc.999/install.sh',
            $evidence['release_installer_url'] ?? null,
        );
        $this->assertNotEmpty($evidence['required_assets'] ?? []);
    }

    public function test_public_channel_check_fails_closed_on_an_incomplete_current_release(): void
    {
        [$process, $evidence] = $this->runPublicChannelFixture(false);

        $this->assertFalse($process->isSuccessful());
        $this->assertSame([], $evidence);
        $this->assertStringContainsString('is missing required assets', $process->getErrorOutput());
    }

    public function test_generated_channel_exposes_only_static_channel_fields(): void
    {
        $command = $this->runSyncScript('--print', 'installer-command');
        $this->assertSame("curl -fsSL https://durable-workflow.com/install.sh | sh\n", $command->getOutput());

        $installer = $this->runSyncScript('--print', 'installer-url');
        $this->assertSame("https://durable-workflow.com/install.sh\n", $installer->getOutput());

        $version = $this->newSyncScriptProcess(['--print', 'version']);
        $version->run();
        $this->assertFalse($version->isSuccessful());

        $verification = $this->read('scripts/ci/verify-cli-readme-release.sh');
        $this->assertStringContainsString('--print-evidence "$evidence_path" version', $verification);
        $this->assertStringNotContainsString('--print version', $verification);
    }

    /**
     * @return array{Process, array<string, mixed>}
     */
    private function runPublicChannelFixture(bool $complete = true): array
    {
        $temporaryRoot = sys_get_temp_dir().'/dw-cli-public-channel-'.bin2hex(random_bytes(8));
        $scriptDirectory = $temporaryRoot.'/scripts/ci';
        $authorityDirectory = $temporaryRoot.'/resources/release';
        $this->assertTrue(mkdir($scriptDirectory, 0700, true));
        $this->assertTrue(mkdir($authorityDirectory, 0700, true));

        $script = $scriptDirectory.'/sync-cli-readme-release.mjs';
        $authorityPath = $authorityDirectory.'/cli-readme-channel.json';
        $readmePath = $temporaryRoot.'/README.md';
        $runnerPath = $temporaryRoot.'/mock-public-channel.mjs';
        $evidencePath = $temporaryRoot.'/channel-resolution.json';
        $assets = explode("\n", trim($this->runSyncScript('--print', 'assets')->getOutput()));
        if (! $complete) {
            array_pop($assets);
        }

        $release = json_encode([
            'draft' => false,
            'tag_name' => '2.0.0-rc.999',
            'assets' => array_map(static fn (string $name): array => ['name' => $name], $assets),
        ], JSON_THROW_ON_ERROR);
        $reference = json_encode([
            'object' => [
                'type' => 'commit',
                'sha' => str_repeat('a', 40),
            ],
        ], JSON_THROW_ON_ERROR);
        $runner = <<<JS
import {pathToFileURL} from 'node:url';

const release = {$release};
const reference = {$reference};

globalThis.fetch = async (url, options) => {
  if (options?.headers?.authorization !== 'Bearer fixture-token') {
    throw new Error('fixture expected the GitHub token');
  }

  let value;
  if (url.endsWith('/releases?per_page=100')) {
    value = [release];
  } else if (url.endsWith('/git/ref/tags/2.0.0-rc.999')) {
    value = reference;
  } else {
    return {ok: false, status: 404, arrayBuffer: async () => new ArrayBuffer(0)};
  }

  const bytes = Buffer.from(JSON.stringify(value));
  return {ok: true, status: 200, arrayBuffer: async () => bytes};
};

const [script, evidence] = process.argv.slice(2);
process.argv = [process.execPath, script, '--check', '--evidence', evidence];
await import(pathToFileURL(script).href);
JS;

        $this->assertTrue(copy($this->repositoryRoot().'/scripts/ci/sync-cli-readme-release.mjs', $script));
        $this->assertTrue(copy($this->repositoryRoot().'/resources/release/cli-readme-channel.json', $authorityPath));
        $this->assertNotFalse(file_put_contents($readmePath, $this->read('README.md')));
        $this->assertNotFalse(file_put_contents($runnerPath, $runner));

        try {
            $process = new Process(['node', $runnerPath, $script, $evidencePath], $temporaryRoot);
            $process->setEnv([
                'GITHUB_SERVER_URL' => 'https://github.com',
                'GH_TOKEN' => 'fixture-token',
                'GITHUB_TOKEN' => false,
            ]);
            $process->run();
            $evidence = is_file($evidencePath)
                ? json_decode((string) file_get_contents($evidencePath), true, 512, JSON_THROW_ON_ERROR)
                : [];
            $this->assertIsArray($evidence);
        } finally {
            $this->removeTree($temporaryRoot);
        }

        return [$process, $evidence];
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
        return new Process([
            'node',
            'scripts/ci/sync-cli-readme-release.mjs',
            ...$arguments,
        ], $this->repositoryRoot());
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

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        $this->assertIsArray($entries);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.'/'.$entry;
            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
