<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

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
            'scripts/ci/sync-qualified-onboarding-release.mjs',
            '--check',
            '--offline',
        ], dirname(__DIR__, 2));
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertMatchesRegularExpression(
            '/^Compose, Kubernetes, and Helm onboarding select Server \S+\.\n$/',
            $process->getOutput(),
        );
    }

    public function test_public_ci_refreshes_the_qualified_authority_while_forgejo_checks_offline(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/.github/workflows/qualified-onboarding-release.yml',
        );
        self::assertIsString($source);

        $workflow = Yaml::parse($source);
        $job = $workflow['jobs']['release'] ?? null;
        self::assertIsArray($job);
        self::assertSame(['contents' => 'read'], $workflow['permissions'] ?? null);

        $github = $this->workflowStep($job, 'Check generated onboarding defaults against the public authority');
        self::assertSame("\${{ github.server_url == 'https://github.com' }}", $github['if'] ?? null);
        self::assertSame('node scripts/ci/sync-qualified-onboarding-release.mjs --check', $github['run'] ?? null);

        $forgejo = $this->workflowStep($job, 'Check generated onboarding defaults against checked-in authority');
        self::assertSame("\${{ github.server_url != 'https://github.com' }}", $forgejo['if'] ?? null);
        self::assertSame(
            'node scripts/ci/sync-qualified-onboarding-release.mjs --check --offline',
            $forgejo['run'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    private function workflowStep(array $job, string $name): array
    {
        foreach ($job['steps'] ?? [] as $step) {
            if (($step['name'] ?? null) === $name) {
                self::assertIsArray($step);

                return $step;
            }
        }

        self::fail("Missing workflow step {$name}");
    }
}
