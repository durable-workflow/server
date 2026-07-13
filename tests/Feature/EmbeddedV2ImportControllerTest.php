<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\HistoryExport;

class EmbeddedV2ImportControllerTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    public function test_cluster_info_advertises_embedded_v2_import_contract_when_available(): void
    {
        if (! class_exists('Workflow\\V2\\Support\\EmbeddedV2ImportContract')) {
            $this->markTestSkipped('Installed workflow package does not expose embedded v2 import yet.');
        }

        $this->createNamespace('default');

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/cluster/info');

        $response->assertOk()
            ->assertJsonPath('capabilities.embedded_v2_import', true)
            ->assertJsonPath('embedded_v2_import_contract.schema', 'durable-workflow.v2.embedded-v2-import.contract')
            ->assertJsonPath('embedded_v2_import_contract.import.server_endpoint', 'POST /api/workflows/import/embedded-v2');
    }

    public function test_it_imports_an_embedded_v2_history_bundle_into_the_request_namespace(): void
    {
        if (! class_exists('Workflow\\V2\\Support\\EmbeddedV2HistoryImport')) {
            $this->markTestSkipped('Installed workflow package does not expose embedded v2 import yet.');
        }

        $this->createNamespace('default');

        $bundle = $this->completedBundle();
        $runId = $bundle['workflow']['run_id'];
        $this->clearWorkflowState();

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/import/embedded-v2', [
                'bundle' => $bundle,
                'import_id' => 'server-import-001',
            ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'imported')
            ->assertJsonPath('workflow.run_id', $runId)
            ->assertJsonPath('workflow.namespace', 'default')
            ->assertJsonPath('import_id', 'server-import-001');

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        $this->assertSame('default', $run->namespace);
        $this->assertSame('embedded_v2', $run->import_source);
        $this->assertSame('server-import-001', $run->import_id);

        /** @var WorkflowRunSummary $summary */
        $summary = WorkflowRunSummary::query()->findOrFail($runId);
        $this->assertSame('embedded_v2_import', $summary->engine_source);
        $this->assertSame('default', $summary->namespace);
    }

    /**
     * @return array<string, mixed>
     */
    private function completedBundle(): array
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'server-embedded-import-' . Str::lower((string) Str::ulid()),
            'workflow_class' => 'App\\Workflows\\ServerEmbeddedImportWorkflow',
            'workflow_type' => 'orders.import',
            'namespace' => 'embedded-source',
            'run_count' => 1,
            'started_at' => now()
                ->subMinutes(10),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\ServerEmbeddedImportWorkflow',
            'workflow_type' => 'orders.import',
            'namespace' => 'embedded-source',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'payload_codec' => config('workflows.serializer'),
            'output_payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize(['order-123']),
            'output' => Serializer::serialize(['ok' => true]),
            'connection' => 'redis',
            'queue' => 'orders',
            'started_at' => now()
                ->subMinutes(10),
            'closed_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
            'workflow_class' => 'App\\Workflows\\ServerEmbeddedImportWorkflow',
            'workflow_type' => 'orders.import',
        ]);
        WorkflowHistoryEvent::record($run->refresh(), HistoryEventType::WorkflowCompleted, [
            'output' => ['ok' => true],
            'payload_codec' => config('workflows.serializer'),
        ]);

        $shape = config('server.topology.shape');
        $processClass = config('server.topology.process_class');

        config([
            'server.topology.shape' => 'embedded',
            'server.topology.process_class' => 'application_process',
        ]);

        try {
            return HistoryExport::forRun($run->fresh());
        } finally {
            config([
                'server.topology.shape' => $shape,
                'server.topology.process_class' => $processClass,
            ]);
        }
    }

    private function clearWorkflowState(): void
    {
        foreach ([
            'workflow_run_summaries',
            'workflow_run_waits',
            'workflow_run_timeline_entries',
            'workflow_run_timer_entries',
            'workflow_run_lineage_entries',
            'workflow_search_attributes',
            'workflow_memos',
            'workflow_history_events',
            'workflow_tasks',
            'activity_attempts',
            'activity_executions',
            'workflow_run_timers',
            'workflow_failures',
            'workflow_links',
            'workflow_signal_records',
            'workflow_updates',
            'workflow_commands',
            'workflow_runs',
            'workflow_instances',
        ] as $table) {
            DB::table($table)->delete();
        }
    }
}
