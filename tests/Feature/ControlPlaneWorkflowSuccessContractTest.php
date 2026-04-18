<?php

namespace Tests\Feature;

use App\Support\ControlPlaneProtocol;
use App\Support\ControlPlaneResponseContract;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\AwaitApprovalWorkflow;
use Tests\Fixtures\InteractiveCommandWorkflow;
use Tests\TestCase;

class ControlPlaneWorkflowSuccessContractTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.await-approval-workflow' => AwaitApprovalWorkflow::class,
            'tests.interactive-command-workflow' => InteractiveCommandWorkflow::class,
        ]);

        $this->createNamespace('default');
    }

    public function test_workflow_read_success_responses_use_control_plane_contract(): void
    {
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-contract-read',
            'workflow_type' => 'tests.await-approval-workflow',
            'business_key' => 'contract-read',
            'search_attributes' => ['ContractCase' => 'read'],
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $this->assertControlPlaneSuccess($start, 201, 'start');
        $start->assertJsonPath('workflow_id', 'wf-contract-read')
            ->assertJsonPath('workflow_type', 'tests.await-approval-workflow')
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('outcome', 'started_new');

        $runId = (string) $start->json('run_id');
        $this->runReadyWorkflowTask($runId);

        $query = $this->postJson(
            '/api/workflows/wf-contract-read/query/currentState',
            [],
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $this->assertControlPlaneSuccess($query, 200, 'query', 'currentState');
        $query->assertJsonPath('workflow_id', 'wf-contract-read')
            ->assertJsonPath('query_name', 'currentState')
            ->assertJsonPath('result.stage', 'waiting-for-approval');

        $list = $this->getJson('/api/workflows?query=contract-read', $this->controlPlaneHeadersWithWorkerProtocol());

        $this->assertControlPlaneSuccess($list, 200, 'list');
        $list->assertJsonPath('workflow_count', 1)
            ->assertJsonPath('workflows.0.workflow_id', 'wf-contract-read')
            ->assertJsonPath('workflows.0.run_id', $runId)
            ->assertJsonPath('workflows.0.search_attributes.ContractCase', 'read');

        $describe = $this->getJson('/api/workflows/wf-contract-read', $this->controlPlaneHeadersWithWorkerProtocol());

        $this->assertControlPlaneSuccess($describe, 200, 'describe');
        $describe->assertJsonPath('workflow_id', 'wf-contract-read')
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('status_bucket', 'running')
            ->assertJsonPath('is_terminal', false)
            ->assertJsonPath('actions.can_query', true)
            ->assertJsonStructure([
                'workflow_id',
                'run_id',
                'namespace',
                'workflow_type',
                'business_key',
                'status',
                'status_bucket',
                'is_terminal',
                'payload_codec',
                'input_envelope',
                'output_envelope',
                'actions',
                'control_plane',
            ]);

        $runs = $this->getJson('/api/workflows/wf-contract-read/runs', $this->controlPlaneHeadersWithWorkerProtocol());

        $this->assertControlPlaneSuccess($runs, 200, 'list_runs');
        $runs->assertJsonPath('workflow_id', 'wf-contract-read')
            ->assertJsonPath('run_count', 1)
            ->assertJsonPath('runs.0.run_id', $runId)
            ->assertJsonPath('runs.0.workflow_type', 'tests.await-approval-workflow');

        $showRun = $this->getJson(
            "/api/workflows/wf-contract-read/runs/{$runId}",
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $this->assertControlPlaneSuccess($showRun, 200, 'describe_run');
        $showRun->assertJsonPath('workflow_id', 'wf-contract-read')
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('run_number', 1)
            ->assertJsonPath('is_current_run', true);

        $history = $this->getJson(
            "/api/workflows/wf-contract-read/runs/{$runId}/history?page_size=1",
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $this->assertControlPlaneSuccess($history, 200, 'history');
        $history->assertJsonPath('workflow_id', 'wf-contract-read')
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('control_plane.workflow_id', 'wf-contract-read')
            ->assertJsonPath('control_plane.run_id', $runId)
            ->assertJsonStructure([
                'events' => [
                    ['sequence', 'event_type', 'timestamp', 'payload'],
                ],
                'next_page_token',
            ]);

        $export = $this->getJson(
            "/api/workflows/wf-contract-read/runs/{$runId}/history/export",
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $export->assertOk()
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertHeaderMissing(WorkerProtocol::HEADER)
            ->assertJsonMissingPath('control_plane')
            ->assertJsonMissingPath('protocol_version')
            ->assertJsonPath('schema', 'durable-workflow.v2.history-export')
            ->assertJsonPath('schema_version', 1)
            ->assertJsonPath('workflow.instance_id', 'wf-contract-read')
            ->assertJsonPath('workflow.run_id', $runId)
            ->assertJsonStructure([
                'schema',
                'schema_version',
                'exported_at',
                'workflow',
                'history_events',
                'codec_schemas',
                'integrity',
            ]);
    }

    public function test_workflow_command_success_responses_use_control_plane_contract(): void
    {
        $interactiveRunId = $this->startAndRunWorkflow(
            workflowId: 'wf-contract-commands',
            workflowType: 'tests.interactive-command-workflow',
        );

        $signal = $this->postJson('/api/workflows/wf-contract-commands/signal/advance', [
            'input' => ['Ada'],
            'request_id' => 'signal-contract-1',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $this->assertControlPlaneSuccess($signal, 202, 'signal', 'advance');
        $signal->assertJsonPath('workflow_id', 'wf-contract-commands')
            ->assertJsonPath('signal_name', 'advance')
            ->assertJsonPath('outcome', 'signal_received')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonMissingPath('signal');

        $this->runReadyWorkflowTask($interactiveRunId);

        $update = $this->postJson('/api/workflows/wf-contract-commands/update/approve', [
            'input' => [true, 'contract'],
            'wait_for' => 'completed',
            'request_id' => 'update-contract-1',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $this->assertControlPlaneSuccess($update, 200, 'update', 'approve');
        $update->assertJsonPath('workflow_id', 'wf-contract-commands')
            ->assertJsonPath('update_name', 'approve')
            ->assertJsonPath('outcome', 'update_completed')
            ->assertJsonPath('update_status', 'completed')
            ->assertJsonPath('result.approved', true)
            ->assertJsonMissingPath('update');

        $runTargetedSignal = $this->postJson("/api/workflows/wf-contract-commands/runs/{$interactiveRunId}/signal/finish", [
            'request_id' => 'finish-contract-1',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $this->assertControlPlaneSuccess($runTargetedSignal, 202, 'signal', 'finish');
        $runTargetedSignal->assertJsonPath('workflow_id', 'wf-contract-commands')
            ->assertJsonPath('signal_name', 'finish')
            ->assertJsonPath('outcome', 'signal_received');

        $this->startAndRunWorkflow('wf-contract-cancel');

        $cancel = $this->postJson('/api/workflows/wf-contract-cancel/cancel', [
            'reason' => 'operator cancel contract',
            'request_id' => 'cancel-contract-1',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $this->assertControlPlaneSuccess($cancel, 200, 'cancel');
        $cancel->assertJsonPath('workflow_id', 'wf-contract-cancel')
            ->assertJsonPath('outcome', 'cancelled')
            ->assertJsonPath('reason', 'operator cancel contract')
            ->assertJsonMissingPath('accepted');

        $this->startAndRunWorkflow('wf-contract-terminate');

        $terminate = $this->postJson('/api/workflows/wf-contract-terminate/terminate', [
            'reason' => 'operator terminate contract',
            'request_id' => 'terminate-contract-1',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $this->assertControlPlaneSuccess($terminate, 200, 'terminate');
        $terminate->assertJsonPath('workflow_id', 'wf-contract-terminate')
            ->assertJsonPath('outcome', 'terminated')
            ->assertJsonPath('reason', 'operator terminate contract')
            ->assertJsonMissingPath('accepted');

        $this->startAndRunWorkflow('wf-contract-repair');

        $repair = $this->postJson('/api/workflows/wf-contract-repair/repair', [
            'request_id' => 'repair-contract-1',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $this->assertControlPlaneSuccess($repair, 200, 'repair');
        $repair->assertJsonPath('workflow_id', 'wf-contract-repair')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('command_source', 'control_plane')
            ->assertJsonMissingPath('accepted');

        $this->startAndRunWorkflow('wf-contract-archive');

        $this->postJson('/api/workflows/wf-contract-archive/cancel', [
            'reason' => 'prepare archive contract',
            'request_id' => 'archive-cancel-contract-1',
        ], $this->controlPlaneHeadersWithWorkerProtocol())->assertOk();

        $archive = $this->postJson('/api/workflows/wf-contract-archive/archive', [
            'reason' => 'archive contract',
            'request_id' => 'archive-contract-1',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $this->assertControlPlaneSuccess($archive, 200, 'archive');
        $archive->assertJsonPath('workflow_id', 'wf-contract-archive')
            ->assertJsonPath('outcome', 'archived')
            ->assertJsonPath('reason', 'archive contract')
            ->assertJsonMissingPath('accepted');

    }

    private function assertControlPlaneSuccess(
        TestResponse $response,
        int $status,
        string $operation,
        ?string $operationName = null,
    ): void {
        $response->assertStatus($status)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertHeaderMissing(WorkerProtocol::HEADER)
            ->assertJsonPath('control_plane.schema', ControlPlaneResponseContract::SCHEMA)
            ->assertJsonPath('control_plane.version', ControlPlaneResponseContract::VERSION)
            ->assertJsonPath('control_plane.operation', $operation)
            ->assertJsonPath('control_plane.contract.schema', ControlPlaneResponseContract::CONTRACT_SCHEMA)
            ->assertJsonPath('control_plane.contract.version', ControlPlaneResponseContract::CONTRACT_VERSION)
            ->assertJsonPath('control_plane.contract.legacy_field_policy', ControlPlaneResponseContract::LEGACY_FIELD_POLICY)
            ->assertJsonMissingPath('protocol_version')
            ->assertJsonMissingPath('server_capabilities');

        if ($operationName === null) {
            $response->assertJsonMissingPath('control_plane.operation_name')
                ->assertJsonMissingPath('control_plane.operation_name_field');

            return;
        }

        $operationNameField = match ($operation) {
            'query' => 'query_name',
            'signal' => 'signal_name',
            'update' => 'update_name',
            default => null,
        };

        $response->assertJsonPath('control_plane.operation_name', $operationName)
            ->assertJsonPath('control_plane.operation_name_field', $operationNameField);
    }

    private function startAndRunWorkflow(
        string $workflowId,
        string $workflowType = 'tests.await-approval-workflow',
    ): string {
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => $workflowId,
            'workflow_type' => $workflowType,
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $start->assertCreated();

        $runId = (string) $start->json('run_id');
        $this->runReadyWorkflowTask($runId);

        return $runId;
    }

    /**
     * Include both protocol headers to prove control-plane routes keep the
     * control-plane response envelope on success when clients send extra
     * worker-plane metadata.
     *
     * @return array<string, string>
     */
    private function controlPlaneHeadersWithWorkerProtocol(): array
    {
        return $this->apiHeaders() + [
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ];
    }
}
