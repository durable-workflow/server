<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\WorkerRegistration;
use App\Support\WorkerProtocol;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Assert;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowSearchAttribute;

/** Worker search-attribute type extension for the immutable codec boundary helpers. */
final class ServerCodecRegressionBoundaryV6
{
    public static function exerciseWorkerSearchAttributeType(TestCase $test): void
    {
        Queue::fake();
        $start = $test->withHeaders(self::apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'codec-worker-search-attribute-type',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'codec-search-attribute',
        ]);
        $start->assertCreated();

        WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => 'codec-search-attribute-worker', 'namespace' => 'default'],
            [
                'task_queue' => 'codec-search-attribute',
                'runtime' => 'rust',
                'supported_workflow_types' => ['tests.external-greeting-workflow'],
                'supported_activity_types' => [],
                'capabilities' => ['typed_search_attributes'],
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ],
        );

        $headers = self::workerHeaders();
        $poll = $test->withHeaders($headers)->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'codec-search-attribute-worker',
            'task_queue' => 'codec-search-attribute',
        ]);
        $poll->assertOk();

        $codec = self::proofInputCodec();
        $command = [
            'type' => 'upsert_search_attributes',
            'attributes' => [
                'OrderStatus' => 'waiting',
            ],
        ];
        if ($codec === 'json') {
            $command['attribute_types'] = [
                'OrderStatus' => WorkflowSearchAttribute::TYPE_STRING,
            ];
        }

        $taskId = (string) $poll->json('task.task_id');
        $response = $test->withHeaders($headers)->postJson(
            "/api/worker/workflow-tasks/{$taskId}/complete",
            [
                'lease_owner' => $poll->json('task.lease_owner'),
                'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                'commands' => [$command],
            ],
        );
        Assert::assertTrue($response->isSuccessful(), $response->getContent());

        $attribute = WorkflowSearchAttribute::query()
            ->where('workflow_run_id', (string) $start->json('run_id'))
            ->where('key', 'OrderStatus')
            ->firstOrFail();
        Assert::assertSame(
            $codec === 'json'
                ? WorkflowSearchAttribute::TYPE_STRING
                : WorkflowSearchAttribute::TYPE_KEYWORD,
            $attribute->type,
        );
    }

    private static function proofInputCodec(): string
    {
        $configuredCodec = getenv('SERVER_CODEC_PROOF_INPUT_CODEC');
        $codec = is_string($configuredCodec) ? $configuredCodec : 'json';
        Assert::assertContains($codec, ['avro', 'json']);

        return $codec;
    }

    /** @return array<string, string> */
    private static function apiHeaders(): array
    {
        return [
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ];
    }

    /** @return array<string, string> */
    private static function workerHeaders(): array
    {
        return [
            'X-Namespace' => 'default',
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ];
    }
}
