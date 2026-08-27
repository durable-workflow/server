<?php

namespace Tests\Feature;

use App\Support\LegacyV1Projection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

class LegacyV1ProjectionControllerTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    public function test_it_projects_completed_waterline_v1_state_into_describe_and_typed_history_export(): void
    {
        $this->createNamespace('migration');

        $response = $this->withHeaders($this->apiHeaders('migration'))
            ->postJson('/api/workflows/import/waterline-v1', [
                'source_id' => 'legacy-prod',
                'workflow' => $this->completedProjection(),
            ]);

        $response->assertCreated()
            ->assertJsonPath('schema', 'durable-workflow.v2.waterline-v1-projection.report')
            ->assertJsonPath('status', 'projected')
            ->assertJsonPath('projection_only', true)
            ->assertJsonPath('identity.waterline.qualified_workflow_id', 'v1:42')
            ->assertJsonPath('identity.standalone.namespace', 'migration')
            ->assertJsonPath('identity.relationship', 'deterministic_source_qualified_projection')
            ->assertJsonPath('unsupported_fields.0.reason', 'v1_history_not_replayable_as_v2');

        $workflowId = (string) $response->json('identity.standalone.workflow_id');
        $runId = (string) $response->json('identity.standalone.run_id');

        $this->withHeaders($this->apiHeaders('migration'))
            ->getJson('/api/workflows/'.rawurlencode($workflowId))
            ->assertOk()
            ->assertJsonPath('workflow_id', $workflowId)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('status_bucket', 'completed')
            ->assertJsonPath('task_queue', 'orders')
            ->assertJsonPath('actions.can_query', false)
            ->assertJsonPath('action_blocked_reason', 'v1_projection_read_only')
            ->assertJsonPath('migration_projection.origin.engine_source', 'v1')
            ->assertJsonPath('migration_projection.task_queue_context.execution_owner', 'v1');

        $this->withHeaders($this->apiHeaders('migration'))
            ->getJson('/api/workflows/'.rawurlencode($workflowId).'/runs')
            ->assertOk()
            ->assertJsonPath('runs.0.run_id', $runId)
            ->assertJsonMissingPath('runs.0.memo');

        $history = $this->withHeaders($this->apiHeaders('migration'))
            ->getJson('/api/workflows/'.rawurlencode($workflowId).'/runs/'.$runId.'/history');

        $history->assertOk()
            ->assertJsonPath('migration_projection.identity.waterline.qualified_workflow_id', 'v1:42');
        $this->assertSame(
            ['WorkflowStarted', 'ActivityCompleted', 'SignalReceived', 'WorkflowCompleted'],
            array_column($history->json('events'), 'event_type'),
        );

        $export = $this->withHeaders($this->apiHeaders('migration'))
            ->getJson('/api/workflows/'.rawurlencode($workflowId).'/runs/'.$runId.'/history/export');

        $export->assertOk()
            ->assertJsonPath('schema', 'durable-workflow.v2.history-export')
            ->assertJsonPath('workflow.instance_id', $workflowId)
            ->assertJsonPath('workflow.run_id', $runId)
            ->assertJsonPath('migration_projection.identity.waterline.qualified_workflow_id', 'v1:42')
            ->assertJsonPath('migration_projection.unsupported_fields.0.reason', 'v1_history_not_replayable_as_v2');
        $this->assertNotEmpty($export->json('history_events'));
    }

    public function test_repeating_a_projection_is_idempotent_and_changed_source_state_is_rejected(): void
    {
        $this->createNamespace('default');
        $request = [
            'source_id' => 'legacy-prod',
            'workflow' => $this->completedProjection(),
        ];

        $first = $this->withHeaders($this->apiHeaders())->postJson('/api/workflows/import/waterline-v1', $request);
        $first->assertCreated();

        $runId = (string) $first->json('identity.standalone.run_id');
        $eventCount = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->count();

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/import/waterline-v1', $request)
            ->assertOk()
            ->assertJsonPath('status', 'already_projected');

        $this->assertSame(1, WorkflowRun::query()->whereKey($runId)->count());
        $this->assertSame($eventCount, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->count());
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $runId)->count());

        $request['workflow']['output'] = 'a:1:{s:6:"result";s:7:"changed";}';

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/import/waterline-v1', $request)
            ->assertConflict()
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('reason', 'v1_projection_changed');

        $this->assertSame($eventCount, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->count());
    }

    public function test_repeating_a_projection_in_another_namespace_is_rejected_with_the_stored_identity(): void
    {
        $this->createNamespace('default');
        $this->createNamespace('migration');
        $request = [
            'source_id' => 'legacy-prod',
            'workflow' => $this->completedProjection(),
        ];

        $first = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/import/waterline-v1', $request)
            ->assertCreated();

        $workflowId = (string) $first->json('identity.standalone.workflow_id');
        $runId = (string) $first->json('identity.standalone.run_id');
        $eventCount = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->count();

        $this->withHeaders($this->apiHeaders('migration'))
            ->postJson('/api/workflows/import/waterline-v1', $request)
            ->assertConflict()
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('reason', 'v1_projection_namespace_collision')
            ->assertJsonPath('identity.standalone.namespace', 'default')
            ->assertJsonPath('requested_namespace', 'migration')
            ->assertJsonStructure(['message', 'remediation']);

        $this->assertSame('default', WorkflowInstance::query()->findOrFail($workflowId)->namespace);
        $this->assertSame('default', WorkflowRun::query()->findOrFail($runId)->namespace);
        $this->assertSame(1, WorkflowRun::query()->whereKey($runId)->count());
        $this->assertSame($eventCount, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->count());

        $this->withHeaders($this->apiHeaders('migration'))
            ->getJson('/api/workflows/'.rawurlencode($workflowId))
            ->assertNotFound();

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/'.rawurlencode($workflowId))
            ->assertOk()
            ->assertJsonPath('run_id', $runId);
    }

    public function test_normalization_rejections_include_the_advertised_top_level_fields(): void
    {
        $this->createNamespace('default');
        $workflow = $this->completedProjection();
        $workflow['id'] = '42';
        $workflow['operator_id'] = '42';

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/import/waterline-v1', [
                'source_id' => 'legacy-prod',
                'workflow' => $workflow,
            ])
            ->assertConflict()
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('reason', 'unqualified_v1_identity')
            ->assertJsonPath('reason', fn (string $reason): bool => $reason !== '')
            ->assertJsonPath('message', fn (string $message): bool => $message !== '')
            ->assertJsonPath('remediation', fn (string $remediation): bool => $remediation !== '')
            ->assertJsonPath('errors.0.reason', 'unqualified_v1_identity');
    }

    public function test_idempotency_rejects_inconsistent_stored_instance_and_run_namespaces(): void
    {
        $this->createNamespace('default');
        $request = [
            'source_id' => 'legacy-prod',
            'workflow' => $this->completedProjection(),
        ];

        $first = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/import/waterline-v1', $request)
            ->assertCreated();
        $runId = (string) $first->json('identity.standalone.run_id');
        WorkflowRun::query()->whereKey($runId)->update(['namespace' => 'migration']);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/import/waterline-v1', $request)
            ->assertConflict()
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('reason', 'v1_projection_storage_inconsistent')
            ->assertJsonPath('identity.standalone.namespace', null)
            ->assertJsonStructure(['message', 'remediation']);

        $this->assertSame(1, WorkflowRun::query()->whereKey($runId)->count());
    }

    public function test_all_nested_opaque_values_have_stable_unsupported_field_remediation(): void
    {
        $this->createNamespace('default');
        $workflow = $this->completedProjection();
        $workflow['exceptions'] = [[
            'id' => 701,
            'class' => 'RuntimeException',
            'exception' => 'O:16:"RuntimeException":7:{...}',
            'created_at' => '2026-07-10T10:04:00Z',
        ]];

        $project = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/import/waterline-v1', [
                'source_id' => 'legacy-prod',
                'workflow' => $workflow,
            ])
            ->assertCreated();

        $remediation = 'Treat the preserved value as opaque; export decoded JSON from the source application if portable payload access is required.';
        $unsupported = collect($project->json('unsupported_fields'))->keyBy('field');
        foreach (['payloads.activity_results', 'payloads.signal_arguments', 'payloads.exception_values'] as $field) {
            $this->assertSame('legacy_php_serialization_not_portable', $unsupported->get($field)['reason'] ?? null);
            $this->assertSame($remediation, $unsupported->get($field)['remediation'] ?? null);
        }

        $workflowId = (string) $project->json('identity.standalone.workflow_id');
        $runId = (string) $project->json('identity.standalone.run_id');
        $history = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/'.rawurlencode($workflowId).'/runs/'.$runId.'/history')
            ->assertOk();
        $this->assertSame(
            ['payloads.activity_results', 'payloads.signal_arguments', 'payloads.exception_values'],
            collect($history->json('migration_projection.unsupported_fields'))
                ->pluck('field')
                ->intersect(['payloads.activity_results', 'payloads.signal_arguments', 'payloads.exception_values'])
                ->values()
                ->all(),
        );
        $events = collect($history->json('events'))->keyBy('event_type');
        $opaqueValues = [
            [$events->get('ActivityCompleted')['payload']['activity']['result'] ?? null, 'a:1:{s:7:"charged";b:1;}'],
            [$events->get('SignalReceived')['payload']['arguments'] ?? null, 'a:1:{s:2:"by";s:3:"Ada";}'],
            [$events->get('ActivityFailed')['payload']['exception']['value'] ?? null, 'O:16:"RuntimeException":7:{...}'],
        ];

        foreach ($opaqueValues as [$opaque, $sourceValue]) {
            $this->assertIsArray($opaque);
            $this->assertTrue($opaque['available'] ?? false);
            $this->assertSame('php-serialize', $opaque['encoding'] ?? null);
            $this->assertSame($sourceValue, $opaque['value'] ?? null);
            $this->assertFalse($opaque['portable'] ?? true);
            $this->assertSame('legacy_php_serialization_not_portable', $opaque['unsupported_reason'] ?? null);
            $this->assertSame($remediation, $opaque['remediation'] ?? null);
        }
    }

    public function test_open_projection_is_visible_but_cannot_dispatch_or_accept_commands(): void
    {
        $this->createNamespace('default');
        $workflow = $this->completedProjection();
        $workflow['id'] = 'v1:84';
        $workflow['legacy_id'] = 84;
        $workflow['operator_id'] = 'v1:84';
        $workflow['status'] = 'waiting';
        $workflow['output'] = null;

        $project = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/import/waterline-v1', [
                'source_id' => 'legacy-prod',
                'workflow' => $workflow,
            ]);

        $project->assertCreated();
        $workflowId = (string) $project->json('identity.standalone.workflow_id');
        $runId = (string) $project->json('identity.standalone.run_id');

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/'.rawurlencode($workflowId))
            ->assertOk()
            ->assertJsonPath('status', 'waiting')
            ->assertJsonPath('status_bucket', 'running')
            ->assertJsonPath('is_terminal', false)
            ->assertJsonPath('migration_projection.projection.execution_owner', 'v1');

        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $runId)->count());

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/'.rawurlencode($workflowId).'/signal/approve', ['input' => []])
            ->assertConflict()
            ->assertJsonPath('reason', 'v1_projection_read_only')
            ->assertJsonPath('execution_owner', 'v1');

        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $runId)->count());
    }

    public function test_it_never_overwrites_a_colliding_native_v2_identity(): void
    {
        $this->createNamespace('default');
        $mapped = LegacyV1Projection::mappedIdentity('legacy-prod', 'v1:42');

        WorkflowInstance::query()->create([
            'id' => $mapped['workflow_id'],
            'workflow_class' => 'App\\Workflows\\NativeWorkflow',
            'workflow_type' => 'native.workflow',
            'namespace' => 'default',
            'run_count' => 0,
        ]);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/import/waterline-v1', [
                'source_id' => 'legacy-prod',
                'workflow' => $this->completedProjection(),
            ])
            ->assertConflict()
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('reason', 'native_v2_identity_collision')
            ->assertJsonPath('identity.collision_policy', 'reject_without_overwrite');

        $this->assertSame('native.workflow', WorkflowInstance::query()->findOrFail($mapped['workflow_id'])->workflow_type);
        $this->assertFalse(WorkflowRun::query()->whereKey($mapped['run_id'])->exists());
    }

    public function test_cluster_info_advertises_the_public_v1_projection_contract(): void
    {
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('capabilities.waterline_v1_projection', true)
            ->assertJsonPath('waterline_v1_projection_contract.schema', 'durable-workflow.v2.waterline-v1-projection.contract')
            ->assertJsonPath('waterline_v1_projection_contract.operation.server_endpoint', 'POST /api/workflows/import/waterline-v1')
            ->assertJsonPath(
                'waterline_v1_projection_contract.identity.namespace_binding',
                'the first projection binds the deterministic identity to one standalone namespace',
            );
    }

    /** @return array<string, mixed> */
    private function completedProjection(): array
    {
        return [
            'id' => 'v1:42',
            'legacy_id' => 42,
            'operator_id' => 'v1:42',
            'engine_source' => 'v1',
            'engine_version' => '1.x',
            'execution_engine' => 'finish-on-v1',
            'class' => 'App\\Workflows\\LegacyOrderWorkflow',
            'arguments' => 'a:1:{s:8:"order_id";i:123;}',
            'connection' => 'redis',
            'queue' => 'orders',
            'output' => 'a:1:{s:6:"result";s:2:"ok";}',
            'status' => 'completed',
            'created_at' => '2026-07-10T10:00:00Z',
            'updated_at' => '2026-07-10T10:05:00Z',
            'logs' => [[
                'id' => 501,
                'index' => 1,
                'now' => '2026-07-10T10:02:00Z',
                'class' => 'App\\Activities\\ChargeCard',
                'result' => 'a:1:{s:7:"charged";b:1;}',
                'created_at' => '2026-07-10T10:02:00Z',
            ]],
            'signals' => [[
                'id' => 601,
                'name' => 'approved',
                'arguments' => 'a:1:{s:2:"by";s:3:"Ada";}',
                'created_at' => '2026-07-10T10:03:00Z',
            ]],
            'exceptions' => [],
        ];
    }
}
