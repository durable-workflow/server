<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Support\NamespaceDurableStateQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultWorkflowControlPlane;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;

final class WorkflowRedriveEndpointTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->createNamespace('default');
        $this->createNamespace('other');
        $this->registerWorker(
            'redrive-worker',
            'redrive-queue',
            supportedWorkflowTypes: ['remote.redrive'],
            supportedActivityTypes: ['remote.first', 'remote.second'],
        );
        WorkerRegistration::query()->where('worker_id', 'redrive-worker')->firstOrFail()
            ->forceFill([
                'build_id' => 'redrive-build',
                'workflow_definition_fingerprints' => ['remote.redrive' => 'sha256:definition-1'],
            ])->save();
    }

    public function test_failed_run_redrives_once_with_visible_reused_history_and_provenance(): void
    {
        $this->requireRedrivePackage();
        $source = $this->failedSource('redrive-http-1');

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson("/api/workflows/redrive-http-1/runs/{$source->id}/redrive", [
                'request_id' => 'redrive-request-1',
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('workflow_id', 'redrive-http-1')
            ->assertJsonPath('continued_from_run_id', $source->id)
            ->assertJsonPath('resume_step_sequence', 2)
            ->assertJsonPath('control_plane.operation', 'redrive');

        $successorId = (string) $response->json('run_id');
        $this->assertNotSame($source->id, $successorId);
        $this->assertSame(RunStatus::Failed, $source->fresh()->status);
        $this->assertSame(2, WorkflowRun::query()->where('workflow_instance_id', 'redrive-http-1')->count());

        $reused = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $successorId)
            ->where('event_type', HistoryEventType::ActivityCompleted)
            ->firstOrFail();
        $this->assertSame($source->id, $reused->payload['reused_from_run_id']);
        $this->assertSame(1, $reused->payload['sequence']);

        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/redrive-http-1/runs/{$successorId}")
            ->assertOk()
            ->assertJsonPath('continued_from_run_id', $source->id)
            ->assertJsonPath('resume_step_sequence', 2)
            ->assertJsonPath('recovery_kind', 'redrive');

        $this->withHeaders($this->apiHeaders())
            ->postJson("/api/workflows/redrive-http-1/runs/{$source->id}/redrive", [
                'request_id' => 'redrive-request-1',
            ])
            ->assertOk()
            ->assertJsonPath('run_id', $successorId);
        $this->assertSame(2, WorkflowRun::query()->where('workflow_instance_id', 'redrive-http-1')->count());

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'redrive-worker',
                'task_queue' => 'redrive-queue',
            ]);
        $poll->assertOk()->assertJsonPath('task.run_id', $successorId);
        $history = (array) $poll->json('task.history_events');
        $this->assertSame(
            ['StartAccepted', 'WorkflowStarted', 'ActivityCompleted'],
            array_column($history, 'event_type'),
        );
        $this->assertSame($source->id, $history[2]['payload']['reused_from_run_id']);
        $this->assertSame(1, $history[2]['payload']['sequence']);
        $this->assertIsArray($history[2]['payload']['result']);

        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/redrive-http-1/runs/{$successorId}/activities")
            ->assertOk()
            ->assertJsonPath('activities.0.reused_from_run_id', $source->id)
            ->assertJsonPath('activities.0.sequence', 1);
    }

    public function test_redrive_rejects_cross_namespace_and_changed_worker_definition(): void
    {
        $this->requireRedrivePackage();
        $source = $this->failedSource('redrive-http-2');

        $this->withHeaders($this->apiHeaders('other'))
            ->postJson("/api/workflows/redrive-http-2/runs/{$source->id}/redrive")
            ->assertNotFound();

        WorkerRegistration::query()->where('worker_id', 'redrive-worker')->firstOrFail()
            ->forceFill([
                'workflow_definition_fingerprints' => ['remote.redrive' => 'sha256:definition-2'],
            ])->save();

        $this->withHeaders($this->apiHeaders())
            ->postJson("/api/workflows/redrive-http-2/runs/{$source->id}/redrive")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'workflow_definition_unavailable_or_changed');
        $this->assertSame(1, WorkflowRun::query()->where('workflow_instance_id', 'redrive-http-2')->count());
    }

    public function test_redrive_refuses_a_run_that_did_not_fail(): void
    {
        $this->requireRedrivePackage();
        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'redrive-http-open',
                'workflow_type' => 'remote.redrive',
                'task_queue' => 'redrive-queue',
            ]);
        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        $this->withHeaders($this->apiHeaders())
            ->postJson("/api/workflows/redrive-http-open/runs/{$runId}/redrive")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'run_not_failed');
    }

    public function test_redrive_cannot_bypass_namespace_run_quota(): void
    {
        $this->requireRedrivePackage();
        $source = $this->failedSource('redrive-http-quota');
        config(['server.namespace_durable_state.limits' => [
            'max_workflow_runs' => 1,
        ]]);

        $this->withHeaders($this->apiHeaders())
            ->postJson("/api/workflows/redrive-http-quota/runs/{$source->id}/redrive")
            ->assertStatus(429)
            ->assertJsonPath('reason', 'namespace_workflow_runs_exhausted')
            ->assertJsonPath('resource', NamespaceDurableStateQuota::WORKFLOW_RUNS);

        $this->assertSame(1, WorkflowRun::query()->where('workflow_instance_id', 'redrive-http-quota')->count());
        $this->assertSame(RunStatus::Failed, $source->fresh()->status);
    }

    public function test_redrive_route_hides_another_namespaces_run_before_dispatch(): void
    {
        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'redrive-http-hidden',
                'workflow_type' => 'remote.redrive',
                'task_queue' => 'redrive-queue',
            ]);
        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        $this->withHeaders($this->apiHeaders('other'))
            ->postJson("/api/workflows/redrive-http-hidden/runs/{$runId}/redrive")
            ->assertNotFound();
        $this->withHeaders($this->apiHeaders('other'))
            ->getJson("/api/workflows/redrive-http-hidden/runs/{$runId}/activities")
            ->assertNotFound();
    }

    private function requireRedrivePackage(): void
    {
        if (! method_exists(DefaultWorkflowControlPlane::class, 'redrive')) {
            $this->markTestSkipped('Requires the next published Workflow release with failed-run redrive.');
        }
    }

    private function failedSource(string $workflowId): WorkflowRun
    {
        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => $workflowId,
                'workflow_type' => 'remote.redrive',
                'task_queue' => 'redrive-queue',
            ]);
        $start->assertCreated();
        $run = WorkflowRun::query()->findOrFail((string) $start->json('run_id'));

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => 'first-activity',
            'activity_type' => 'remote.first',
            'sequence' => 1,
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityCompleted, [
            'activity_execution_id' => 'first-activity',
            'activity_type' => 'remote.first',
            'sequence' => 1,
            'result' => Serializer::serializeWithCodec('avro', 'first-result'),
            'payload_codec' => 'avro',
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => 'second-activity',
            'activity_type' => 'remote.second',
            'sequence' => 2,
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityFailed, [
            'activity_execution_id' => 'second-activity',
            'activity_type' => 'remote.second',
            'sequence' => 2,
            'message' => 'temporary failure',
        ]);

        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->firstOrFail();
        $task->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'redrive-worker',
            'lease_expires_at' => now()->addMinute(),
        ])->save();
        $run->forceFill(['status' => RunStatus::Waiting])->save();

        $completion = app(DefaultWorkflowTaskBridge::class)->complete($task->id, [[
            'type' => 'fail_workflow',
            'message' => 'temporary failure',
            'failed_step_sequence' => 2,
            'failed_activity_execution_id' => 'second-activity',
        ]]);
        $this->assertTrue($completion['completed']);

        return $run->fresh();
    }
}
