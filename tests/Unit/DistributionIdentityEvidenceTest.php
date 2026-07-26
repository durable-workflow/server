<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DistributionIdentityEvidenceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/dw-distribution-identity-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
        parent::tearDown();
    }

    public function test_records_the_sha256_of_the_consumed_bytes_in_the_native_shape(): void
    {
        $artifact = $this->root.'/install.sh';
        file_put_contents($artifact, "executed release bytes\n");

        $result = $this->runCommand('record-file', $this->root.'/identities.json', 'cli', '0.1.2', $artifact);

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $identities = json_decode(
            (string) file_get_contents($this->root.'/identities.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame('github-release', $identities['cli']['kind']);
        $this->assertSame('github-release:durable-workflow/cli@0.1.2', $identities['cli']['locator']);
        $this->assertSame('install.sh', $identities['cli']['artifacts'][0]['name']);
        $this->assertSame(hash_file('sha256', $artifact), $identities['cli']['artifacts'][0]['sha256']);
    }

    public function test_same_version_with_different_consumed_bytes_has_a_different_identity(): void
    {
        $first = $this->root.'/first/durable-workflow-0.4.0.whl';
        $second = $this->root.'/second/durable-workflow-0.4.0.whl';
        mkdir(dirname($first));
        mkdir(dirname($second));
        file_put_contents($first, 'first wheel bytes');
        file_put_contents($second, 'different wheel bytes');

        $this->assertSame(0, $this->runCommand('record-file', $this->root.'/first.json', 'sdk-python', '0.4.0', $first)['exit']);
        $this->assertSame(0, $this->runCommand('record-file', $this->root.'/second.json', 'sdk-python', '0.4.0', $second)['exit']);

        $firstIdentity = json_decode((string) file_get_contents($this->root.'/first.json'), true, 512, JSON_THROW_ON_ERROR);
        $secondIdentity = json_decode((string) file_get_contents($this->root.'/second.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotSame(
            $firstIdentity['sdk-python']['artifacts'][0]['sha256'],
            $secondIdentity['sdk-python']['artifacts'][0]['sha256'],
        );

        $sharedStore = $this->root.'/shared.json';
        $this->assertSame(0, $this->runCommand('record-file', $sharedStore, 'sdk-python', '0.4.0', $first)['exit']);
        $conflict = $this->runCommand('record-file', $sharedStore, 'sdk-python', '0.4.0', $second);
        $this->assertSame(1, $conflict['exit']);
        $this->assertStringContainsString('conflicting consumed bytes', $conflict['stderr']);
    }

    public function test_python_semver_and_pep440_spellings_share_one_byte_identity(): void
    {
        $wheel = $this->root.'/durable_workflow-2.0.0b18-py3-none-any.whl';
        file_put_contents($wheel, 'beta four wheel bytes');
        $store = $this->root.'/python-prerelease.json';

        $semver = $this->runCommand('record-file', $store, 'sdk-python', '2.0.0-beta.18', $wheel);
        $pep440 = $this->runCommand('record-file', $store, 'sdk-python', '2.0.0b18', $wheel);

        $this->assertSame(0, $semver['exit'], $semver['stderr']);
        $this->assertSame(0, $pep440['exit'], $pep440['stderr']);
        $identities = json_decode((string) file_get_contents($store), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pypi:durable-workflow@2.0.0b18', $identities['sdk-python']['locator']);
        $this->assertSame(hash_file('sha256', $wheel), $identities['sdk-python']['artifacts'][0]['sha256']);

        $differentPrerelease = $this->runCommand('record-file', $store, 'sdk-python', '2.0.0b3', $wheel);
        $this->assertSame(1, $differentPrerelease['exit']);
        $this->assertStringContainsString('conflicting executed distribution locator', $differentPrerelease['stderr']);
    }

    public function test_missing_consumed_bytes_are_rejected_without_writing_evidence(): void
    {
        $store = $this->root.'/missing.json';
        $result = $this->runCommand(
            'record-file',
            $store,
            'sdk-rust',
            '0.3.0',
            $this->root.'/durable-workflow-0.3.0.crate',
        );

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('artifact is missing', $result['stderr']);
        $this->assertFileDoesNotExist($store);

        $validation = $this->runCommand('validate', $store, 'sdk-rust');
        $this->assertSame(1, $validation['exit']);
        $this->assertStringContainsString('missing executed distribution evidence', $validation['stderr']);
    }

    /** @return array{exit: int, stdout: string, stderr: string} */
    private function runCommand(string ...$arguments): array
    {
        $python = trim((string) shell_exec('command -v python3 2>/dev/null'));
        if ($python === '') {
            $this->markTestSkipped('python3 is required to exercise executed distribution evidence.');
        }

        $process = proc_open(
            [$python, dirname(__DIR__, 2).'/scripts/conformance/distribution_identities.py', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
        );
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
