<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use App\Support\RuntimeExternalPayloadReference;
use App\Support\WorkerProtocol;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;
use Tests\TestCase;
use Workflow\Serializers\Serializer;

/** Codec regression extension for route-scoped outgoing payload envelopes. */
final class ServerCodecRegressionBoundaryV8
{
    public static function exerciseActivityResult(TestCase $test): void
    {
        if (self::proofInputCodec() === 'avro') {
            self::exerciseActivityResultSentinel($test);

            return;
        }

        $externalStorageDirectory = storage_path(
            'framework/testing/codec-activity-result-external-storage',
        );
        File::deleteDirectory($externalStorageDirectory);

        try {
            WorkflowNamespace::query()->where('name', 'default')->update([
                'external_payload_storage' => [
                    'driver' => 'local',
                    'enabled' => true,
                    'threshold_bytes' => 32,
                    'config' => [
                        'uri' => 'file://'.$externalStorageDirectory,
                    ],
                ],
            ]);

            self::registerWorker();

            $start = $test->withHeaders(self::apiHeaders())->postJson('/api/activities', [
                'activity_id' => 'codec-activity-result-external-payload',
                'activity_type' => 'tests.external-greeting-activity',
                'task_queue' => 'codec-activity-results',
                'input' => [str_repeat('A', 128)],
            ])->assertCreated();

            $workerHeaders = self::workerHeaders();
            $poll = $test->withHeaders($workerHeaders)
                ->postJson('/api/worker/activity-tasks/poll', [
                    'worker_id' => 'codec-activity-result-worker',
                    'task_queue' => 'codec-activity-results',
                ])
                ->assertOk();

            $test->withHeaders($workerHeaders)
                ->postJson('/api/worker/activity-tasks/'.$poll->json('task.task_id').'/heartbeat', [
                    'activity_attempt_id' => $poll->json('task.activity_attempt_id'),
                    'lease_owner' => $poll->json('task.lease_owner'),
                    'details' => [
                        'external_payload' => 'business-payload-label',
                        'external_storage' => 'business-storage-label',
                    ],
                ])
                ->assertOk();
            $result = ['message' => str_repeat('B', 128)];

            $test->withHeaders($workerHeaders)
                ->postJson('/api/worker/activity-tasks/'.$poll->json('task.task_id').'/complete', [
                    'activity_attempt_id' => $poll->json('task.activity_attempt_id'),
                    'lease_owner' => $poll->json('task.lease_owner'),
                    'result' => [
                        'codec' => 'avro',
                        'blob' => Serializer::serializeWithCodec('avro', $result),
                    ],
                ])
                ->assertOk();

            $show = $test->withHeaders(self::apiHeaders())
                ->getJson('/api/activities/'.$start->json('activity_id'))
                ->assertOk()
                ->assertJsonPath(
                    'result.external_payload.schema',
                    RuntimeExternalPayloadReference::SCHEMA,
                )
                ->assertJsonPath(
                    'current_attempt.last_heartbeat_progress.details.external_payload',
                    'business-payload-label',
                )
                ->assertJsonPath(
                    'current_attempt.last_heartbeat_progress.details.external_storage',
                    'business-storage-label',
                )
                ->assertJsonMissingPath('result.external_storage');

            $test->assertStringNotContainsString(
                'file://',
                json_encode($show->json('result'), JSON_THROW_ON_ERROR),
            );
        } finally {
            File::deleteDirectory($externalStorageDirectory);
        }
    }

    private static function exerciseActivityResultSentinel(TestCase $test): void
    {
        $test->withHeaders(self::apiHeaders())
            ->getJson('/api/activities/codec-activity-result-sentinel')
            ->assertNotFound()
            ->assertJsonPath('reason', 'activity_not_found');
    }

    private static function proofInputCodec(): string
    {
        $configuredCodec = getenv('SERVER_CODEC_PROOF_INPUT_CODEC');
        $codec = is_string($configuredCodec) ? $configuredCodec : 'json';
        Assert::assertContains($codec, ['avro', 'json']);

        return $codec;
    }

    private static function registerWorker(): void
    {
        WorkerRegistration::query()->updateOrCreate(
            [
                'worker_id' => 'codec-activity-result-worker',
                'namespace' => 'default',
            ],
            [
                'task_queue' => 'codec-activity-results',
                'runtime' => 'php',
                'supported_workflow_types' => [],
                'supported_activity_types' => ['tests.external-greeting-activity'],
                'capabilities' => [],
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ],
        );
    }

    /** @return array<string, string> */
    private static function apiHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.config('server.api_token'),
            'X-Namespace' => 'default',
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ];
    }

    /** @return array<string, string> */
    private static function workerHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.config('server.worker_token'),
            'X-Namespace' => 'default',
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ];
    }
}
