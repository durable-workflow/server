<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ServerCodecRegressionBoundary;
use Tests\Support\ServerCodecRegressionFixtureExecutor;
use Tests\Support\ServerCodecRegressionFixtureExecutorV3;

final class CodecRegressionCorpusTest extends TestCase
{
    private const FIXTURE_FORMAT = 'codec-regression-v1';

    private const SHARED_EXECUTOR_FIXTURE = 'tests/Fixtures/CodecRegression/avro-value-v1-long-zero.json';

    public function test_checked_in_codec_regression_corpus_uses_the_official_php_binding(): void
    {
        foreach (self::fixturePaths() as $path) {
            ServerCodecRegressionFixtureExecutorV3::exercisePath($path);
        }
    }

    public function test_shared_executor_returns_the_verified_fixture(): void
    {
        $fixture = ServerCodecRegressionFixtureExecutor::exercisePath(
            self::sharedExecutorFixturePath(),
        );

        self::assertSame('avro-value-v1-long-zero', $fixture->id);
        self::assertSame('round_trip', $fixture->operation);
        self::assertIsString($fixture->wire);
    }

    public function test_boundary_proxy_substitutes_the_verified_fixture_at_the_call_site(): void
    {
        $fixturePath = self::sharedExecutorFixturePath();
        $fixture = ServerCodecRegressionFixtureExecutor::exercisePath($fixturePath);
        $evidence = 'durable-workflow-codec-boundary/v1:'.str_repeat('a', 64);

        $encoded = ServerCodecRegressionBoundary::serializeWithCodec(
            base64_encode((string) file_get_contents($fixturePath)),
            __FILE__,
            $evidence,
            'avro',
            999,
        );

        self::assertSame($fixture->wire, $encoded);
    }

    public function test_boundary_proxy_has_no_candidate_mutable_static_state(): void
    {
        $reflection = new ReflectionClass(ServerCodecRegressionBoundary::class);

        self::assertSame([], $reflection->getStaticProperties());
    }

    /** @return list<string> */
    private static function fixturePaths(): array
    {
        $root = dirname(__DIR__, 2);
        $policy = json_decode(
            (string) file_get_contents($root.'/regression-corpus-policy.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($policy);
        self::assertSame('server', $policy['repository'] ?? null);
        self::assertSame('php', $policy['binding'] ?? null);

        $selectors = $policy['categories']['codec']['fixtures'] ?? null;
        self::assertIsArray($selectors);
        self::assertNotSame([], $selectors);

        $paths = [];
        foreach ($selectors as $selector) {
            self::assertIsArray($selector);
            self::assertSame(
                self::FIXTURE_FORMAT,
                $selector['format'] ?? null,
                'The server codec policy contains a format without an official PHP executor.',
            );
            $glob = $selector['glob'] ?? null;
            self::assertIsString($glob);
            self::assertMatchesRegularExpression(
                '/\A(?:[A-Za-z0-9_-][A-Za-z0-9._-]*\/)*(?:[A-Za-z0-9_-][A-Za-z0-9._-]*|\*)\.json\z/D',
                $glob,
                'The codec fixture selector is not portable to the official PHP runner.',
            );
            array_push($paths, ...(glob($root.'/'.$glob) ?: []));
        }

        self::assertNotSame([], $paths);
        self::assertSame(
            count($paths),
            count(array_unique($paths)),
            'A codec fixture is selected more than once by the server policy.',
        );
        sort($paths);

        return $paths;
    }

    private static function sharedExecutorFixturePath(): string
    {
        return dirname(__DIR__, 2).'/'.self::SHARED_EXECUTOR_FIXTURE;
    }
}
