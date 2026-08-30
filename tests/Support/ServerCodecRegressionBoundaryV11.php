<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Assert;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloads;

/** Deployment-preflight extension for canonical stored external payload references. */
final class ServerCodecRegressionBoundaryV11
{
    private const OFFLINE_OBJECT_PATH = '/unavailable/bootstrap-proof.avro';

    public static function exerciseCanonicalStoredReference(TestCase $test): void
    {
        Queue::fake();

        if (self::proofInputCodec() === 'avro') {
            $test->artisan('server:bootstrap --force')
                ->assertExitCode(0);

            return;
        }

        $start = $test->withHeaders(self::apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'payload-preflight-external-reference-proof',
            'workflow_type' => 'tests.external-greeting-workflow',
        ]);
        $start->assertCreated();

        $storedReference = ExternalPayloads::encodeStoredEnvelope([
            'codec' => 'avro',
            'external_storage' => [
                'schema' => ExternalPayloadReference::SCHEMA,
                'uri' => 'file://'.self::OFFLINE_OBJECT_PATH,
                'sha256' => str_repeat('a', 64),
                'size_bytes' => 128,
                'codec' => 'avro',
            ],
        ]);
        $run = WorkflowRun::query()->findOrFail((string) $start->json('run_id'));
        $run->forceFill([
            'payload_codec' => 'avro',
            'arguments' => $storedReference,
        ])->save();

        Assert::assertFileDoesNotExist(self::OFFLINE_OBJECT_PATH);

        $test->artisan('server:bootstrap --force')
            ->expectsOutputToContain('Avro-only payload preflight passed')
            ->assertExitCode(0);

        Assert::assertSame($storedReference, $run->refresh()->arguments);
        Assert::assertFileDoesNotExist(self::OFFLINE_OBJECT_PATH);
    }

    /** @return array<string, string> */
    private static function apiHeaders(): array
    {
        return [
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ];
    }

    private static function proofInputCodec(): string
    {
        $configuredCodec = getenv('SERVER_CODEC_PROOF_INPUT_CODEC');
        $codec = is_string($configuredCodec) ? $configuredCodec : 'json';
        Assert::assertContains($codec, ['avro', 'json']);

        return $codec;
    }
}
