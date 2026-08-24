<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\RuntimeExternalPayload;
use App\Support\ExternalPayloadObjectMissing;
use App\Support\NamespaceExternalPayloadStorage;
use App\Support\RuntimeExternalPayloadException;
use App\Support\RuntimeExternalPayloadRegistry;
use App\Support\RuntimeExternalPayloadStorageDriver;
use App\Support\RuntimeLocalExternalPayloadStorage;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;

/** Registration-recovery extensions for runtime external payload storage. */
final class ServerCodecRegressionBoundaryV5
{
    public static function exerciseClaimedBoundary(): void
    {
        $configuredBoundary = getenv('SERVER_CODEC_CLAIMED_BOUNDARY');
        $boundary = is_string($configuredBoundary)
            ? $configuredBoundary
            : 'app/Support/RuntimeExternalPayloadRegistry.php';

        match ($boundary) {
            'app/Support/RuntimeExternalPayloadRegistry.php' => self::exerciseRegistrationRecovery(),
            'app/Support/RuntimeLocalExternalPayloadStorage.php' => self::exerciseStableLocalIdentity(),
            default => Assert::fail("Unsupported runtime external-payload recovery boundary {$boundary}."),
        };
    }

    private static function exerciseRegistrationRecovery(): void
    {
        $driver = new ServerCodecRegressionStorageDriverV5;
        $policy = \Mockery::mock(NamespaceExternalPayloadStorage::class);
        $policy->shouldReceive('untrackedDriverFor')
            ->once()
            ->with('default')
            ->andReturn($driver);
        app()->instance(NamespaceExternalPayloadStorage::class, $policy);
        $data = 'recoverable registration object';

        if (self::proofInputCodec() === 'avro') {
            $reference = app(RuntimeExternalPayloadRegistry::class)->upload(
                'default',
                $data,
                'avro',
                hash('sha256', $data),
            );

            Assert::assertSame('avro', $reference['codec'] ?? null);
            Assert::assertTrue($driver->hasStoredBytes());

            return;
        }

        $failFinalRegistration = true;
        RuntimeExternalPayload::updating(
            static function (RuntimeExternalPayload $row) use (&$failFinalRegistration): void {
                if ($failFinalRegistration && $row->isDirty('upload_status')) {
                    $failFinalRegistration = false;
                    throw new RuntimeException('simulated registry finalization failure');
                }
            },
        );
        $failure = null;

        try {
            try {
                app(RuntimeExternalPayloadRegistry::class)->upload(
                    'default',
                    $data,
                    'avro',
                    hash('sha256', $data),
                );
            } catch (RuntimeExternalPayloadException $exception) {
                $failure = $exception;
            }

            Assert::assertInstanceOf(RuntimeExternalPayloadException::class, $failure);
            Assert::assertSame('external_payload_unavailable', $failure->reason);
            Assert::assertSame(
                'writing',
                RuntimeExternalPayload::query()->firstOrFail()->upload_status,
            );
            Assert::assertTrue($driver->hasStoredBytes());
        } finally {
            RuntimeExternalPayload::flushEventListeners();
        }
    }

    private static function exerciseStableLocalIdentity(): void
    {
        $directory = storage_path('framework/testing/runtime-external-payload-codec-recovery');
        File::deleteDirectory($directory);

        try {
            $driver = new RuntimeLocalExternalPayloadStorage($directory);

            if (self::proofInputCodec() === 'avro') {
                Assert::assertInstanceOf(ExternalPayloadStorageDriver::class, $driver);

                return;
            }

            Assert::assertInstanceOf(RuntimeExternalPayloadStorageDriver::class, $driver);
            $sha256 = hash('sha256', 'stable local identity');
            Assert::assertSame($driver->uriFor($sha256, 'avro'), $driver->put(
                'stable local identity',
                $sha256,
                'avro',
            ));
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private static function proofInputCodec(): string
    {
        $configuredCodec = getenv('SERVER_CODEC_PROOF_INPUT_CODEC');
        $codec = is_string($configuredCodec) ? $configuredCodec : 'json';
        Assert::assertContains($codec, ['avro', 'json']);

        return $codec;
    }
}

final class ServerCodecRegressionStorageDriverV5 implements RuntimeExternalPayloadStorageDriver
{
    /** @var array<string, string> */
    private array $objects = [];

    public function uriFor(string $sha256, string $codec): string
    {
        return "memory://payloads/{$codec}/{$sha256}";
    }

    public function put(string $data, string $sha256, string $codec): string
    {
        $uri = $this->uriFor($sha256, $codec);
        $this->objects[$uri] = $data;

        return $uri;
    }

    public function get(string $uri): string
    {
        return $this->objects[$uri]
            ?? throw new ExternalPayloadObjectMissing('External payload object does not exist.');
    }

    public function delete(string $uri): void
    {
        unset($this->objects[$uri]);
    }

    public function hasStoredBytes(): bool
    {
        return $this->objects !== [];
    }
}
