<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class PublishedConformanceCliInstallPathContractTest extends TestCase
{
    public function test_every_private_cli_installer_prepends_its_install_directory_to_path(): void
    {
        $repositoryRoot = dirname(__DIR__, 2);
        $sourceRoots = [
            $repositoryRoot.'/.github',
            $repositoryRoot.'/scripts',
        ];
        $shapeCounts = [
            'shell' => 0,
            'python' => 0,
            'node' => 0,
        ];
        $invocationCount = 0;

        foreach ($sourceRoots as $sourceRoot) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());
                $privateInstallCount = substr_count($source, 'DURABLE_WORKFLOW_INSTALL_DIR');
                if ($privateInstallCount === 0) {
                    continue;
                }

                $relativePath = substr($file->getPathname(), strlen($repositoryRoot) + 1);
                $matchedCount = 0;
                foreach ($this->pathCorrectInstallerPatterns() as $shape => $pattern) {
                    $matches = preg_match_all($pattern, $source);
                    $this->assertNotFalse($matches, "invalid {$shape} installer contract pattern");
                    $shapeCounts[$shape] += $matches;
                    $matchedCount += $matches;
                }

                $this->assertSame(
                    $privateInstallCount,
                    $matchedCount,
                    "{$relativePath} must prepend each private CLI install directory to the installer subprocess PATH",
                );
                $invocationCount += $privateInstallCount;
            }
        }

        $this->assertGreaterThan(0, $invocationCount, 'the Server-owned sources must retain private CLI installer coverage');
        foreach ($shapeCounts as $shape => $count) {
            $this->assertGreaterThan(0, $count, "the contract must exercise the {$shape} installer call shape");
        }
    }

    /**
     * @return array{shell: string, python: string, node: string}
     */
    private function pathCorrectInstallerPatterns(): array
    {
        return [
            'shell' => <<<'REGEX'
~PATH="(?P<shell_dir>[^"\r\n]+?)(?:\$\{PATH:\+:\$PATH\}|:\$PATH)".{0,400}?DURABLE_WORKFLOW_INSTALL_DIR="(?P=shell_dir)".{0,400}?sh "(?:[^"\r\n]*install\.sh|\$installer)"~s
REGEX,
            'python' => <<<'REGEX'
~(?P<python_env>[a-z_][a-z0-9_]*)\.update\(\s*\{.{0,300}?"DURABLE_WORKFLOW_INSTALL_DIR": str\((?P<python_dir>[a-z_][a-z0-9_]*)\).{0,300}?\}\s*\).{0,100}?(?P=python_env)\["PATH"\] = os\.pathsep\.join\(\s*part for part in \(str\((?P=python_dir)\), (?P=python_env)\.get\("PATH", ""\)\) if part\s*\).{0,300}?(?:run|run_command)\(\["sh", str\(installer\)\]~s
REGEX,
            'node' => <<<'REGEX'
~env: \{.{0,200}?PATH: \[(?P<node_dir>[a-zA-Z_][a-zA-Z0-9_]*), process\.env\.PATH \?\? ''\]\.filter\(Boolean\)\.join\(path\.delimiter\),.{0,300}?DURABLE_WORKFLOW_INSTALL_DIR: (?P=node_dir),~s
REGEX,
        ];
    }
}
