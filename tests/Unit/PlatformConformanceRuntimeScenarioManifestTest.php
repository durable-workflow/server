<?php

namespace Tests\Unit;

use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;

final class PlatformConformanceRuntimeScenarioManifestTest extends TestCase
{
    /**
     * @param array<string, mixed> $manifest
     */
    #[DataProvider('runtimeScenarioManifestProvider')]
    public function test_runtime_scenario_manifests_use_the_canonical_suite_schema(
        string $filename,
        array $manifest,
    ): void {
        $this->assertSame(
            PlatformConformanceSuite::SCHEMA,
            $manifest['suite_schema'] ?? null,
            "$filename must reference the canonical platform conformance suite schema",
        );
        $this->assertNotSame(
            'durable-workflow.v2.platform-conformance-suite',
            $manifest['suite_schema'] ?? null,
            "$filename must not use the legacy hyphenated suite schema",
        );
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     *
     * @throws JsonException
     */
    public static function runtimeScenarioManifestProvider(): iterable
    {
        $paths = glob(dirname(__DIR__, 2).'/static/platform-conformance/*.json') ?: [];

        foreach ($paths as $path) {
            $manifest = json_decode(
                (string) file_get_contents($path),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            if (($manifest['schema'] ?? null) !== 'durable-workflow.v2.platform-conformance.runtime-scenarios') {
                continue;
            }

            $filename = basename($path);
            yield $filename => [$filename, $manifest];
        }
    }
}
