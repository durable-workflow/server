<?php

declare(strict_types=1);

namespace Tests\Support;

use InvalidArgumentException;
use Workflow\Serializers\Avro;
use Workflow\Serializers\Base64;
use Workflow\Serializers\Json;
use Workflow\Serializers\SerializerInterface;
use Workflow\Serializers\Y;

/**
 * Test-only registry for counterfactuals against the pre-Avro-only package.
 *
 * Counterfactual Server snapshots must prove their own boundary behavior even
 * after the installed Workflow package starts rejecting stale codec tags.
 * This class is aliased only inside the isolated proof bootstrap.
 */
final class ServerCodecRegressionLegacyRegistry
{
    /** @var array<string, class-string<SerializerInterface>> */
    private const CODECS = [
        'avro' => Avro::class,
        'json' => Json::class,
        'workflow-serializer-y' => Y::class,
        'workflow-serializer-base64' => Base64::class,
    ];

    /** @return class-string<SerializerInterface> */
    public static function resolve(?string $codec): string
    {
        $canonical = self::canonicalize($codec);

        return self::CODECS[$canonical];
    }

    public static function canonicalize(?string $codec): string
    {
        if ($codec === null || $codec === '') {
            return self::defaultCodec();
        }

        $canonical = match (ltrim($codec, '\\')) {
            Y::class => 'workflow-serializer-y',
            Base64::class => 'workflow-serializer-base64',
            default => $codec,
        };
        if (! isset(self::CODECS[$canonical])) {
            throw new InvalidArgumentException(sprintf('Unknown payload codec "%s".', $codec));
        }

        return $canonical;
    }

    public static function defaultCodec(): string
    {
        return 'avro';
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::CODECS);
    }

    /** @return list<string> */
    public static function universal(): array
    {
        return ['avro', 'json'];
    }

    /** @return array<string, list<string>> */
    public static function engineSpecific(): array
    {
        return [
            'php' => ['workflow-serializer-y', 'workflow-serializer-base64'],
        ];
    }
}
