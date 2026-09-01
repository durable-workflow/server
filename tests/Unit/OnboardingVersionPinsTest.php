<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class OnboardingVersionPinsTest extends TestCase
{
    public function test_readme_derives_exact_artifacts_instead_of_copying_rc_numbers(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 2).'/README.md');

        self::assertIsString($readme);
        self::assertDoesNotMatchRegularExpression(
            '/\bv?\d+\.\d+\.\d+-(?:alpha|beta|rc)\.\d+\b|\b\d+\.\d+\.\d+(?:a|b|rc)\d+\b/i',
            $readme,
        );
    }

    public function test_compose_kubernetes_and_helm_defaults_are_generated_from_the_selected_release(): void
    {
        $process = new Process([
            'node',
            'scripts/ci/sync-source-release.mjs',
            '--check',
        ], dirname(__DIR__, 2));
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertMatchesRegularExpression(
            '/^Server \S+ and Helm chart \S+ source release consumers are synchronized\.\n$/',
            $process->getOutput(),
        );
    }
}
