<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\WorkerRegistration;
use App\Support\WorkerProtocol;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Assert;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

/** Condition-wait occurrence extension for the immutable codec boundary helpers. */
final class ServerCodecRegressionBoundaryV7
{
    public static function exerciseConditionWaitOccurrenceRouting(TestCase $test): void
    {
        Queue::fake();
        $start = $test->withHeaders(self::apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'codec-condition-wait-occurrence-history',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'codec-condition-wait-occurrence',
        ]);
        $start->assertCreated();
        $run = WorkflowRun::query()->findOrFail((string) $start->json('run_id'));
        $requiresOccurrenceIdentity = self::proofInputCodec() === 'json';

        if ($requiresOccurrenceIdentity) {
            WorkflowHistoryEvent::record($run, HistoryEventType::ConditionWaitOpened, [
                'condition_wait_id' => 'condition-wait-history-1',
                'condition_wait_occurrence_id' => 'condition-occurrence-history-1',
                'condition_key' => 'approval.ready',
                'condition_definition_fingerprint' => 'approval-ready-v1',
                'timeout_seconds' => 60,
            ]);
        }

        WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => 'codec-condition-wait-worker', 'namespace' => 'default'],
            [
                'task_queue' => 'codec-condition-wait-occurrence',
                'runtime' => 'rust',
                'supported_workflow_types' => ['tests.external-greeting-workflow'],
                'supported_activity_types' => [],
                'capabilities' => [],
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ],
        );

        $poll = $test->withHeaders(self::workerHeaders())->postJson(
            '/api/worker/workflow-tasks/poll',
            [
                'worker_id' => 'codec-condition-wait-worker',
                'task_queue' => 'codec-condition-wait-occurrence',
            ],
        );
        $poll->assertOk();

        if ($requiresOccurrenceIdentity) {
            $poll->assertJsonPath('task', null);

            return;
        }

        Assert::assertSame($run->id, $poll->json('task.run_id'));
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
            WorkerProtocol::HEADER => '1.16',
        ];
    }
}
