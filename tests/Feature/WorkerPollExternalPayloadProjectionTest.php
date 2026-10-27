<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ActivityTaskController;
use App\Http\Controllers\Api\WorkerController;
use App\Support\LongPollResponseProgress;
use App\Support\RuntimeExternalPayloadException;
use App\Support\RuntimeExternalPayloadReference;
use App\Support\RuntimeExternalPayloadRegistry;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;

class WorkerPollExternalPayloadProjectionTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    #[DataProvider('pollKinds')]
    public function test_progress_frames_project_external_input_once_without_reencoding_numbers(string $kind, string $controller, string $method, string $field, bool $deferred, bool $framed): void
    {
        $this->createNamespace('default');
        $reference = [
            'schema' => RuntimeExternalPayloadReference::SCHEMA,
            'reference_id' => '01m5x000000000000000000001',
            'codec' => 'avro', 'sha256' => hash('sha256', 'fixture'), 'size_bytes' => 7,
        ];
        $envelope = ['codec' => 'avro', 'external_storage' => [
            'uri' => 'file:///fixture/provider-object.avro',
            'sha256' => hash('sha256', 'fixture'), 'size_bytes' => 7,
        ]];
        $projections = 0;
        $registry = Mockery::mock(RuntimeExternalPayloadRegistry::class);
        $registry->shouldReceive('referenceForInternal')->andReturnUsing(function ($namespace, $stored) use (&$projections, $reference, $envelope) {
            $this->assertSame('default', $namespace);
            $this->assertSame($envelope['external_storage'], $stored);
            $projections++;

            return $reference;
        });
        $this->app->instance(RuntimeExternalPayloadRegistry::class, $registry);
        $mock = Mockery::mock($controller);
        $mock->shouldReceive($method)->andReturnUsing(static function () use ($field, $envelope, $deferred): JsonResponse {
            if ($deferred) {
                LongPollResponseProgress::current()->beginWait(1);
            }
            $raw = '{"task":{'.json_encode($field).':'.json_encode($envelope).'},'
                .'"decimal":1.0,"large":9223372036854775808,"scientific":1.2300e+4,"negative_zero":-0,'
                .'"text":"literal 1.0 and 9223372036854775808"}';

            return JsonResponse::fromJsonString($raw, 200, [WorkerProtocol::HEADER => WorkerProtocol::VERSION]);
        });
        $this->app->instance($controller, $mock);
        $response = $this->withHeaders($this->workerHeaders())->postJson('/api/worker/'.$kind.'-tasks/poll', [
            'worker_id' => 'fixture-worker', 'task_queue' => 'fixture-queue', 'timeout_seconds' => 0,
        ], ['Accept' => $framed ? LongPollResponseProgress::MEDIA_TYPE : 'application/json'])->assertOk();
        $wire = $framed ? $response->streamedContent() : $response->getContent();
        $body = json_decode($wire, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $projections, 'A terminal response must be projected exactly once.');
        if ($framed) {
            $this->assertSame(LongPollResponseProgress::SCHEMA, $body['schema']);
            $this->assertSame(200, $body['status']);
            $this->assertSame(WorkerProtocol::VERSION, $body['headers'][strtolower(WorkerProtocol::HEADER)]);
            $body = $body['body'];
        }
        $this->assertSame($reference, $body['task'][$field]['external_payload']);
        $this->assertArrayNotHasKey('external_storage', $body['task'][$field]);
        $this->assertStringNotContainsString('file://', $wire);
        foreach (['"decimal":1.0', '"large":9223372036854775808', '"scientific":1.2300e+4', '"negative_zero":-0'] as $literal) {
            $this->assertStringContainsString($literal, $wire);
        }
        $this->assertSame('literal 1.0 and 9223372036854775808', $body['text']);
    }

    #[DataProvider('projectionErrors')]
    public function test_projection_failure_has_the_same_typed_error_before_or_after_wait(bool $deferred, bool $framed): void
    {
        $this->createNamespace('default');
        $registry = Mockery::mock(RuntimeExternalPayloadRegistry::class);
        $registry->shouldReceive('referenceForInternal')->once()->andThrow(new RuntimeExternalPayloadException(
            'external_payload_storage_unavailable', 503, true, 'Fixture storage is unavailable.',
        ));
        $this->app->instance(RuntimeExternalPayloadRegistry::class, $registry);
        $controller = Mockery::mock(ActivityTaskController::class);
        $controller->shouldReceive('poll')->andReturnUsing(static function () use ($deferred): JsonResponse {
            if ($deferred) {
                LongPollResponseProgress::current()->beginWait(1);
            }

            return new JsonResponse(['task' => ['arguments' => ['codec' => 'avro', 'external_storage' => ['uri' => 'file:///private-fixture']]]]);
        });
        $this->app->instance(ActivityTaskController::class, $controller);
        $response = $this->withHeaders($this->workerHeaders())->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'fixture-worker', 'task_queue' => 'fixture-queue', 'timeout_seconds' => 0,
        ], ['Accept' => $framed ? LongPollResponseProgress::MEDIA_TYPE : 'application/json']);
        $response->assertStatus($deferred ? 200 : 503);
        $wire = $framed ? $response->streamedContent() : $response->getContent();
        $body = json_decode($wire, true, flags: JSON_THROW_ON_ERROR);
        if ($framed) {
            $this->assertSame(503, $body['status']);
            $this->assertSame(WorkerProtocol::VERSION, $body['headers'][strtolower(WorkerProtocol::HEADER)]);
            $body = $body['body'];
        }
        $this->assertSame('external_payload_storage_unavailable', $body['reason']);
        $this->assertTrue($body['retryable']);
        $this->assertSame(503, $body['status']);
        $this->assertStringNotContainsString('file://', $wire);
    }

    public static function projectionErrors(): iterable
    {
        yield 'plain' => [false, false];
        yield 'immediate' => [false, true];
        yield 'deferred' => [true, true];
    }

    public static function pollKinds(): iterable
    {
        foreach ([
            ['workflow', WorkerController::class, 'pollWorkflowTasks', 'arguments'],
            ['activity', ActivityTaskController::class, 'poll', 'arguments'],
            ['query', WorkerController::class, 'pollQueryTasks', 'query_arguments'],
            ['update-validation', WorkerController::class, 'pollUpdateValidationTasks', 'update_arguments'],
        ] as $case) {
            foreach ([false, true] as $deferred) {
                yield $case[0].'-'.(int) $deferred => [...$case, $deferred, true];
            }
            yield $case[0].'-plain' => [...$case, false, false];
        }
    }
}
