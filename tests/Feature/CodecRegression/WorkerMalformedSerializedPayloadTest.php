<?php

declare(strict_types=1);

namespace Tests\Feature\CodecRegression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Support\ServerCodecRegressionFixtureExecutor;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowHistoryEvent;

final class WorkerMalformedSerializedPayloadTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    public function test_malformed_worker_result_cannot_commit_history(): void
    {
        Queue::fake();
        $this->createNamespace('default');
        $this->registerWorker('codec-worker', 'codec-queue');
        $this->postJson('/api/workflows', [
            'workflow_id' => 'malformed-result',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'codec-queue',
        ], $this->apiHeaders())->assertCreated();
        $task = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'codec-worker', 'task_queue' => 'codec-queue',
        ], $this->workerHeaders())->assertOk()->assertJsonPath('poll_status', 'leased')->json('task');
        $history = WorkflowHistoryEvent::query()->count();

        ServerCodecRegressionFixtureExecutor::exercise(function () use ($task, $history): void {
            $body = [
                'lease_owner' => $task['lease_owner'],
                'workflow_task_attempt' => $task['workflow_task_attempt'],
                'commands' => [['type' => 'complete_workflow', 'result' => [
                    'codec' => 'avro', 'blob' => 'bm90LWFuLWF2cm8tZnJhbWU=',
                ]]],
            ];
            $url = '/api/worker/workflow-tasks/'.$task['task_id'].'/complete';
            $this->postJson($url, $body, $this->workerHeaders())
                ->assertUnprocessable()->assertJsonValidationErrors('commands.0.result.blob');
            $body['commands'][0]['result'] = 'bm90LWFuLWF2cm8tZnJhbWU=';
            $this->postJson($url, $body, $this->workerHeaders())
                ->assertUnprocessable()->assertJsonValidationErrors('commands.0.result');
            $this->assertSame($history, WorkflowHistoryEvent::query()->count());
            $this->assertDatabaseHas('workflow_tasks', ['id' => $task['task_id'], 'status' => 'leased']);
        });
    }
}
