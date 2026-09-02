<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\NamespaceDurableStateQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;

final class NamespaceDurableStateQuotaTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'file']);
        $this->createNamespace('default');
        $this->createNamespace('tenant-b');
        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);
    }

    public function test_workflow_lifetime_and_open_run_limits_are_namespace_scoped(): void
    {
        config(['server.namespace_durable_state.limits' => [
            'max_workflow_instances' => 1,
            'max_workflow_runs' => 1,
            'max_open_workflow_runs' => 1,
        ]]);

        $this->startWorkflow('default', 'default-one')->assertCreated();

        $this->withHeaders($this->apiHeaders('default'))
            ->postJson('/api/workflows', [
                'workflow_id' => 'default-one',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'default',
                'duplicate_policy' => 'use-existing',
            ])
            ->assertOk()
            ->assertJsonPath('outcome', 'returned_existing_active');

        $this->startWorkflow('default', 'default-two')
            ->assertStatus(429)
            ->assertJsonPath('reason', 'namespace_workflow_runs_exhausted')
            ->assertJsonPath('resource', NamespaceDurableStateQuota::WORKFLOW_RUNS)
            ->assertJsonPath('current_value', 2)
            ->assertJsonPath('configured_limit', 1)
            ->assertJsonPath('retryable', false);

        $this->assertFalse(WorkflowInstance::query()->whereKey('default-two')->exists());
        $this->assertSame(1, WorkflowRun::query()->where('namespace', 'default')->count());

        $this->startWorkflow('tenant-b', 'tenant-b-one')->assertCreated();
    }

    public function test_open_run_capacity_recovers_when_a_run_closes(): void
    {
        config(['server.namespace_durable_state.limits' => [
            'max_open_workflow_runs' => 1,
        ]]);

        $first = $this->startWorkflow('default', 'open-one')->assertCreated();

        $this->startWorkflow('default', 'open-two')
            ->assertStatus(429)
            ->assertHeader('Retry-After', '60')
            ->assertJsonPath('reason', 'namespace_open_workflow_runs_exhausted')
            ->assertJsonPath('retryable', true);

        WorkflowRun::query()->whereKey($first->json('run_id'))->update([
            'status' => 'completed',
            'closed_at' => now(),
        ]);

        $this->startWorkflow('default', 'open-two')->assertCreated();
    }

    public function test_schedule_rows_are_bounded_without_affecting_another_namespace(): void
    {
        config(['server.namespace_durable_state.limits' => [
            'max_schedules' => 1,
            'max_schedule_history_events' => 10,
        ]]);

        $this->createSchedule('default', 'default-schedule-one')->assertCreated();

        $this->createSchedule('default', 'default-schedule-two')
            ->assertStatus(429)
            ->assertJsonPath('reason', 'namespace_schedules_exhausted')
            ->assertJsonPath('configured_limit', 1);

        $this->assertDatabaseMissing('workflow_schedules', [
            'namespace' => 'default',
            'schedule_id' => 'default-schedule-two',
        ]);

        $this->createSchedule('tenant-b', 'tenant-b-schedule-one')->assertCreated();
    }

    public function test_schedule_history_limit_rolls_back_the_schedule_mutation(): void
    {
        config(['server.namespace_durable_state.limits' => [
            'max_schedule_history_events' => 1,
        ]]);

        $this->createSchedule('default', 'history-bounded')->assertCreated();

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/schedules/history-bounded/pause')
            ->assertStatus(429)
            ->assertJsonPath('reason', 'namespace_schedule_history_events_exhausted')
            ->assertJsonPath('retryable', false);

        $this->assertSame(
            'active',
            WorkflowSchedule::query()->where('schedule_id', 'history-bounded')->firstOrFail()->status->value,
        );
        $this->assertSame(
            1,
            WorkflowScheduleHistoryEvent::query()->where('namespace', 'default')->count(),
        );
    }

    public function test_continue_as_new_cannot_bypass_the_run_limit(): void
    {
        config(['server.namespace_durable_state.limits' => [
            'max_workflow_runs' => 1,
        ]]);

        $start = $this->startWorkflow('default', 'continue-bounded')->assertCreated();
        $this->registerWorker('continue-worker', 'default');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'continue-worker',
                'task_queue' => 'default',
            ])
            ->assertOk();

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/'.$poll->json('task.task_id').'/complete', [
                'lease_owner' => 'continue-worker',
                'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                'commands' => [[
                    'type' => 'continue_as_new',
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'arguments' => Serializer::serializeWithCodec('avro', ['again']),
                ]],
            ])
            ->assertStatus(429)
            ->assertJsonPath('reason', 'namespace_workflow_runs_exhausted');

        $this->assertSame(1, WorkflowRun::query()->where('namespace', 'default')->count());
        $this->assertSame('pending', WorkflowRun::query()->findOrFail($start->json('run_id'))->status->value);
    }

    public function test_child_workflow_cannot_bypass_the_instance_limit(): void
    {
        config(['server.namespace_durable_state.limits' => [
            'max_workflow_instances' => 1,
        ]]);

        $this->startWorkflow('default', 'parent-bounded')->assertCreated();
        $this->registerWorker('parent-worker', 'default');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'parent-worker',
                'task_queue' => 'default',
            ])
            ->assertOk();

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/'.$poll->json('task.task_id').'/complete', [
                'lease_owner' => 'parent-worker',
                'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                'commands' => [[
                    'type' => 'start_child_workflow',
                    'workflow_type' => 'tests.external-child-workflow',
                    'queue' => 'default',
                    'arguments' => Serializer::serializeWithCodec('avro', ['child']),
                ]],
            ])
            ->assertStatus(429)
            ->assertJsonPath('reason', 'namespace_workflow_instances_exhausted');

        $this->assertSame(1, WorkflowInstance::query()->where('namespace', 'default')->count());
        $this->assertSame(1, WorkflowRun::query()->where('namespace', 'default')->count());
    }

    public function test_standalone_activity_host_cannot_bypass_the_instance_limit(): void
    {
        config(['server.namespace_durable_state.limits' => [
            'max_workflow_instances' => 1,
        ]]);

        $this->startWorkflow('default', 'workflow-capacity-owner')->assertCreated();

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/activities', [
                'activity_id' => 'standalone-over-capacity',
                'activity_type' => 'tests.external-greeting-activity',
                'task_queue' => 'default',
                'input' => ['Hello'],
            ])
            ->assertStatus(429)
            ->assertJsonPath('reason', 'namespace_workflow_instances_exhausted');

        $this->assertFalse(WorkflowInstance::query()->whereKey('standalone-over-capacity')->exists());
        $this->assertSame(1, WorkflowInstance::query()->where('namespace', 'default')->count());
    }

    public function test_worker_registration_limit_allows_refresh_but_rejects_new_identity(): void
    {
        config(['server.namespace_durable_state.limits' => [
            'max_worker_registrations' => 1,
        ]]);

        $this->registerWorkerThroughApi('default', 'worker-one')->assertCreated();
        $this->registerWorkerThroughApi('default', 'worker-one')->assertCreated();

        $this->registerWorkerThroughApi('default', 'worker-two')
            ->assertStatus(429)
            ->assertJsonPath('reason', 'namespace_worker_registrations_exhausted')
            ->assertJsonPath('retryable', true);

        $this->registerWorkerThroughApi('tenant-b', 'worker-one')->assertCreated();
    }

    public function test_override_cannot_exceed_hard_limit_and_metrics_report_usage(): void
    {
        config([
            'server.namespace_durable_state.limits' => ['max_workflow_runs' => 10],
            'server.namespace_durable_state.hard_limits' => ['max_workflow_runs' => 2],
            'server.namespace_durable_state.overrides' => [
                'default' => ['max_workflow_runs' => 100],
            ],
        ]);

        $this->assertSame(
            2,
            app(NamespaceDurableStateQuota::class)->resourceLimits('default')[
                NamespaceDurableStateQuota::WORKFLOW_RUNS
            ],
        );

        $this->startWorkflow('default', 'metrics-one')->assertCreated();

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/system/metrics')
            ->assertOk()
            ->assertJsonPath(
                'metrics.'.NamespaceDurableStateQuota::METRIC_NAME.'.limits.workflow_runs',
                2,
            )
            ->assertJsonPath(
                'metrics.'.NamespaceDurableStateQuota::METRIC_NAME.'.usage.workflow_runs',
                1,
            )
            ->assertJsonPath(
                'metrics.'.NamespaceDurableStateQuota::METRIC_NAME.'.remaining.workflow_runs',
                1,
            );
    }

    public function test_invalid_configured_policy_fails_closed(): void
    {
        config(['server.namespace_durable_state.limits' => 'invalid']);

        $this->startWorkflow('default', 'invalid-policy')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '1')
            ->assertJsonPath('reason', 'namespace_durable_state_quota_unavailable')
            ->assertJsonPath('retryable', true);

        $this->assertFalse(WorkflowInstance::query()->whereKey('invalid-policy')->exists());
    }

    public function test_namespace_override_keys_are_normalized(): void
    {
        config(['server.namespace_durable_state.overrides' => [
            ' DEFAULT ' => ['max_workflow_runs' => 1],
        ]]);

        $this->startWorkflow('default', 'normalized-override-one')->assertCreated();

        $this->startWorkflow('default', 'normalized-override-two')
            ->assertStatus(429)
            ->assertJsonPath('reason', 'namespace_workflow_runs_exhausted');
    }

    public function test_invalid_override_for_another_namespace_fails_the_policy_closed(): void
    {
        config(['server.namespace_durable_state.overrides' => [
            'tenant-b' => ['max_workflow_runs' => 'not-a-limit'],
        ]]);

        $this->startWorkflow('default', 'foreign-invalid-override')
            ->assertStatus(503)
            ->assertJsonPath('reason', 'namespace_durable_state_quota_unavailable');

        $this->assertFalse(WorkflowInstance::query()->whereKey('foreign-invalid-override')->exists());
    }

    public function test_duplicate_normalized_override_names_fail_the_policy_closed(): void
    {
        config(['server.namespace_durable_state.overrides' => [
            'default' => ['max_workflow_runs' => 1],
            ' DEFAULT ' => ['max_workflow_runs' => 2],
        ]]);

        $this->startWorkflow('default', 'duplicate-override')
            ->assertStatus(503)
            ->assertJsonPath('reason', 'namespace_durable_state_quota_unavailable');

        $this->assertFalse(WorkflowInstance::query()->whereKey('duplicate-override')->exists());
    }

    private function startWorkflow(string $namespace, string $workflowId): TestResponse
    {
        return $this->withHeaders($this->apiHeaders($namespace))
            ->postJson('/api/workflows', [
                'workflow_id' => $workflowId,
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'default',
                'input' => ['Hello'],
            ]);
    }

    private function createSchedule(string $namespace, string $scheduleId): TestResponse
    {
        return $this->withHeaders($this->apiHeaders($namespace))
            ->postJson('/api/schedules', [
                'schedule_id' => $scheduleId,
                'spec' => ['cron_expressions' => ['0 * * * *'], 'timezone' => 'UTC'],
                'action' => [
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'task_queue' => 'default',
                ],
            ]);
    }

    private function registerWorkerThroughApi(
        string $namespace,
        string $workerId,
    ): TestResponse {
        return $this->withHeaders($this->workerHeaders($namespace))
            ->postJson('/api/worker/register', [
                'worker_id' => $workerId,
                'task_queue' => 'default',
                'runtime' => 'php',
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            ]);
    }
}
