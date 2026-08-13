<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class PhpFiberConsumerContractTest extends TestCase
{
    #[DataProvider('serviceModePhpSourceProvider')]
    public function test_current_service_mode_php_sources_reject_generator_workflow_syntax(string $path): void
    {
        $source = (string) file_get_contents($path);
        $relativePath = str_replace(dirname(__DIR__, 2).'/', '', $path);

        $this->assertDoesNotMatchRegularExpression(
            '/(?<![A-Za-z0-9_\\\\])Generator\b/',
            $source,
            "{$relativePath} must use ordinary Fiber workflow return values.",
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\byield(?:\s+from)?\s+\$[A-Za-z_][A-Za-z0-9_]*->/',
            $source,
            "{$relativePath} must call WorkflowContext operations directly.",
        );
    }

    /** @return iterable<string, array{string}> */
    public static function serviceModePhpSourceProvider(): iterable
    {
        $repoRoot = dirname(__DIR__, 2);
        $conformanceRoot = $repoRoot.'/scripts/conformance';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($conformanceRoot));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'sh', 'mjs'], true)) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (! str_contains($source, 'WorkflowContext')) {
                continue;
            }

            $relativePath = str_replace($repoRoot.'/', '', $file->getPathname());
            yield $relativePath => [$file->getPathname()];
        }
    }
}
