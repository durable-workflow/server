<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PublishedConformanceVersionIdentityTest extends TestCase
{
    public function test_waterline_installers_root_the_complete_prerelease_tuple(): void
    {
        foreach ([
            'namespaces-published-artifacts.sh',
            'principal-attribution-published-artifacts.sh',
            'replay-published-artifacts.sh',
            'sagas-published-artifacts.sh',
            'signals-queries-published-artifacts.sh',
            'skew-published-artifacts.sh',
            'worker-versioning-published-artifacts.sh',
            'workflow-updates-published-artifacts.sh',
        ] as $runner) {
            $source = (string) file_get_contents(
                dirname(__DIR__, 2).'/scripts/conformance/'.$runner,
            );

            foreach (['waterline', 'workflow', 'sdk'] as $package) {
                $this->assertMatchesRegularExpression(
                    '#durable-workflow/'.$package.':[^{"\'\s]*\{?[^"\'\s]+@beta#',
                    $source,
                    "{$runner} must root the exact {$package} prerelease with an explicit stability flag.",
                );
            }
        }
    }

    public function test_node_runner_identity_regressions(): void
    {
        $node = $this->commandPath('node');
        $result = $this->runCommand([$node, '--test', __DIR__.'/PublishedVersionIdentitiesTest.mjs']);

        $this->assertSame(0, $result['exit'], $result['output']);
    }

    public function test_python_runner_identity_regressions(): void
    {
        $python = $this->commandPath('python3');
        $result = $this->runCommand([$python, '-m', 'unittest', __DIR__.'/test_version_identities.py']);

        $this->assertSame(0, $result['exit'], $result['output']);
    }

    private function commandPath(string $command): string
    {
        $path = trim((string) shell_exec('command -v '.escapeshellarg($command).' 2>/dev/null'));
        if ($path === '') {
            $this->markTestSkipped("{$command} is required to exercise published version identities.");
        }

        return $path;
    }

    /**
     * @param  list<string>  $command
     * @return array{exit: int, output: string}
     */
    private function runCommand(array $command): array
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
        );
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'output' => $output];
    }
}
