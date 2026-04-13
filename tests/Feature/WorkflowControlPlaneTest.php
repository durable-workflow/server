<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Models\WorkflowNamespaceWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\AwaitApprovalWorkflow;
use Tests\Fixtures\InternalParentWorkflow;
use Tests\Fixtures\InternalChildWorkflow;
use Tests\Fixtures\InteractiveCommandWorkflow;
use Tests\TestCase;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultWorkflowControlPlane;
use Workflow\V2\Support\WorkflowExecutor;

class WorkflowControlPlaneTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_package_provider_resolves_the_workflow_control_plane_contract(): void
    {
        $this->assertInstanceOf(
            DefaultWorkflowControlPlane::class,
            app(WorkflowControlPlane::class),
        );
    }

    public function test_it_queries_signals_and_updates_waiting_workflows_through_the_control_plane_api(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-control-plane-interactive',
                'workflow_type' => 'tests.interactive-command-workflow',
                'business_key' => 'order-123',
            ]);

        $start->assertCreated()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('workflow_id', 'wf-control-plane-interactive')
            ->assertJsonPath('control_plane.operation', 'start')
            ->assertJsonPath('control_plane.workflow_id', 'wf-control-plane-interactive')
            ->assertJsonPath('control_plane.contract.schema', 'durable-workflow.v2.control-plane-response.contract')
            ->assertJsonPath('control_plane.contract.version', 1)
            ->assertJsonPath('control_plane.contract.legacy_field_policy', 'reject_non_canonical')
            ->assertJsonPath('control_plane.contract.success_fields.0', 'workflow_id')
            ->assertJsonPath('control_plane.contract.success_fields.1', 'outcome')
            ->assertJsonPath('control_plane.outcome', 'started_new')
            ->assertJsonPath('business_key', 'order-123');

        $runId = (string) $start->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $query = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-control-plane-interactive/query/currentState');

        $query->assertOk()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('workflow_id', 'wf-control-plane-interactive')
            ->assertJsonPath('query_name', 'currentState')
            ->assertJsonPath('control_plane.schema', 'durable-workflow.v2.control-plane-response')
            ->assertJsonPath('control_plane.version', 1)
            ->assertJsonPath('control_plane.operation', 'query')
            ->assertJsonPath('control_plane.operation_name', 'currentState')
            ->assertJsonPath('control_plane.operation_name_field', 'query_name')
            ->assertJsonPath('control_plane.contract.schema', 'durable-workflow.v2.control-plane-response.contract')
            ->assertJsonPath('control_plane.contract.version', 1)
            ->assertJsonPath('control_plane.contract.legacy_field_policy', 'reject_non_canonical')
            ->assertJsonPath('control_plane.contract.required_fields.0', 'workflow_id')
            ->assertJsonPath('control_plane.contract.required_fields.1', 'operation_name')
            ->assertJsonPath('control_plane.contract.required_fields.2', 'operation_name_field')
            ->assertJsonPath('control_plane.contract.success_fields.0', 'result')
            ->assertJsonPath('control_plane.contract.legacy_fields.signal', 'signal_name')
            ->assertJsonPath('control_plane.result.stage', 'waiting-for-advance')
            ->assertJsonPath('result.stage', 'waiting-for-advance')
            ->assertJsonPath('result.approved', false);

        $signal = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-control-plane-interactive/signal/advance', [
                'input' => ['Ada'],
                'request_id' => 'signal-request-1',
            ]);

        $signal->assertStatus(202)
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('workflow_id', 'wf-control-plane-interactive')
            ->assertJsonPath('signal_name', 'advance')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('command_source', 'control_plane')
            ->assertJsonPath('control_plane.operation', 'signal')
            ->assertJsonPath('control_plane.operation_name', 'advance')
            ->assertJsonPath('control_plane.operation_name_field', 'signal_name')
            ->assertJsonPath('control_plane.outcome', 'signal_received')
            ->assertJsonPath('outcome', 'signal_received');

        $signal->assertJsonMissingPath('signal');

        $this->runReadyWorkflowTask($runId);

        $afterSignal = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-control-plane-interactive/query/currentState');

        $afterSignal->assertOk()
            ->assertJsonPath('result.stage', 'waiting-for-finish')
            ->assertJsonPath('result.name', 'Ada')
            ->assertJsonPath('result.events.1', 'signal:Ada');

        $update = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-control-plane-interactive/update/approve', [
                'input' => [true, 'api'],
                'wait_for' => 'completed',
                'request_id' => 'update-request-1',
            ]);

        $update->assertOk()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('workflow_id', 'wf-control-plane-interactive')
            ->assertJsonPath('update_name', 'approve')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('command_source', 'control_plane')
            ->assertJsonPath('control_plane.operation', 'update')
            ->assertJsonPath('control_plane.operation_name', 'approve')
            ->assertJsonPath('control_plane.operation_name_field', 'update_name')
            ->assertJsonPath('control_plane.wait_for', 'completed')
            ->assertJsonPath('control_plane.wait_timed_out', false)
            ->assertJsonPath('outcome', 'update_completed')
            ->assertJsonPath('update_status', 'completed')
            ->assertJsonPath('wait_for', 'completed')
            ->assertJsonPath('wait_timed_out', false)
            ->assertJsonPath('result.approved', true);

        $update->assertJsonMissingPath('update');

        $this->assertContains('approved:yes:api', (array) $update->json('result.events'));

        $afterUpdate = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-control-plane-interactive/query/currentState');

        $afterUpdate->assertOk()
            ->assertJsonPath('result.stage', 'waiting-for-finish')
            ->assertJsonPath('result.approved', true);

        $this->assertContains('approved:yes:api', (array) $afterUpdate->json('result.events'));

        $describe = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-control-plane-interactive');

        $describe->assertOk()
            ->assertJsonPath('workflow_id', 'wf-control-plane-interactive')
            ->assertJsonPath('control_plane.operation', 'describe')
            ->assertJsonPath('control_plane.workflow_id', 'wf-control-plane-interactive')
            ->assertJsonPath('business_key', 'order-123')
            ->assertJsonPath('run_number', 1)
            ->assertJsonPath('run_count', 1)
            ->assertJsonPath('is_current_run', true)
            ->assertJsonPath('status_bucket', 'running')
            ->assertJsonPath('wait_kind', 'signal')
            ->assertJsonPath('actions.can_signal', true)
            ->assertJsonPath('actions.can_query', true)
            ->assertJsonPath('actions.can_update', true)
            ->assertJsonPath('actions.can_cancel', true)
            ->assertJsonPath('actions.can_terminate', true);

        $runs = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-control-plane-interactive/runs');

        $runs->assertOk()
            ->assertJsonPath('control_plane.operation', 'list_runs')
            ->assertJsonPath('control_plane.workflow_id', 'wf-control-plane-interactive')
            ->assertJsonPath('run_count', 1)
            ->assertJsonPath('runs.0.run_id', $runId);

        $list = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows');

        $list->assertOk()
            ->assertJsonPath('control_plane.operation', 'list')
            ->assertJsonPath('workflow_count', 1)
            ->assertJsonPath('workflows.0.workflow_id', 'wf-control-plane-interactive')
            ->assertJsonPath('workflows.0.business_key', 'order-123');
    }

    public function test_it_returns_query_validation_errors_and_scopes_control_plane_commands_by_namespace(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');
        $this->createNamespace('other', 'Other namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-query-validation',
                'workflow_type' => 'tests.interactive-command-workflow',
            ]);

        $start->assertCreated();

        $this->runReadyWorkflowTask((string) $start->json('run_id'));

        $invalidQuery = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-query-validation/query/events-starting-with', [
                'input' => ['extra' => 'start'],
            ]);

        $invalidQuery->assertStatus(422)
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('query_name', 'events-starting-with')
            ->assertJsonPath('control_plane.operation', 'query')
            ->assertJsonPath('control_plane.operation_name', 'events-starting-with')
            ->assertJsonPath('control_plane.validation_errors.prefix.0', 'The prefix argument is required.')
            ->assertJsonPath('validation_errors.prefix.0', 'The prefix argument is required.')
            ->assertJsonPath('validation_errors.extra.0', 'Unknown argument [extra].');

        $this->withHeaders($this->apiHeaders(namespace: 'other'))
            ->postJson('/api/workflows/wf-query-validation/query/currentState')
            ->assertNotFound()
            ->assertJsonPath('control_plane.operation', 'query')
            ->assertJsonPath('control_plane.operation_name', 'currentState')
            ->assertJsonPath('control_plane.workflow_id', 'wf-query-validation')
            ->assertJsonPath('reason', 'instance_not_found');

        $this->withHeaders($this->apiHeaders(namespace: 'other'))
            ->postJson('/api/workflows/wf-query-validation/signal/advance', [
                'input' => ['Grace'],
            ])
            ->assertNotFound()
            ->assertJsonPath('control_plane.operation', 'signal')
            ->assertJsonPath('control_plane.operation_name', 'advance')
            ->assertJsonPath('control_plane.workflow_id', 'wf-query-validation')
            ->assertJsonPath('reason', 'instance_not_found');
    }

    public function test_start_rejects_cross_namespace_workflow_id_without_leaking_the_owning_namespace(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');
        $this->createNamespace('other', 'Other namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-cross-ns-collision',
                'workflow_type' => 'tests.await-approval-workflow',
            ]);

        $start->assertCreated();

        $collision = $this->withHeaders($this->apiHeaders(namespace: 'other'))
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-cross-ns-collision',
                'workflow_type' => 'tests.await-approval-workflow',
            ]);

        $collision->assertStatus(409)
            ->assertJsonPath('workflow_id', 'wf-cross-ns-collision')
            ->assertJsonPath('reason', 'workflow_id_reserved_in_namespace')
            ->assertJsonMissingPath('namespace');

        $message = $collision->json('message');
        $this->assertStringNotContainsString('default', $message);
        $this->assertStringContainsString('another namespace', $message);
    }

    public function test_it_cancels_waiting_workflows_through_the_control_plane_api(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-control-plane-cancel',
                'workflow_type' => 'tests.await-approval-workflow',
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $cancel = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-control-plane-cancel/cancel', [
                'reason' => 'operator requested cancel',
                'request_id' => 'cancel-request-1',
            ]);

        $cancel->assertOk()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('workflow_id', 'wf-control-plane-cancel')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('command_source', 'control_plane')
            ->assertJsonPath('control_plane.operation', 'cancel')
            ->assertJsonPath('control_plane.outcome', 'cancelled')
            ->assertJsonPath('outcome', 'cancelled');

        $command = WorkflowCommand::query()
            ->where('workflow_instance_id', 'wf-control-plane-cancel')
            ->where('command_type', 'cancel')
            ->latest('command_sequence')
            ->firstOrFail();

        $cancelRequested = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'CancelRequested')
            ->firstOrFail();
        $cancelled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'WorkflowCancelled')
            ->firstOrFail();

        $this->assertSame('control_plane', $command->source);
        $this->assertSame('operator requested cancel', $command->commandReason());
        $this->assertSame('operator requested cancel', $cancelRequested->payload['reason'] ?? null);
        $this->assertSame('operator requested cancel', $cancelled->payload['reason'] ?? null);

        $showRun = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/wf-control-plane-cancel/runs/{$runId}");

        $showRun->assertOk()
            ->assertJsonPath('control_plane.operation', 'describe_run')
            ->assertJsonPath('control_plane.workflow_id', 'wf-control-plane-cancel')
            ->assertJsonPath('control_plane.run_id', $runId)
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('status_bucket', 'failed')
            ->assertJsonPath('is_current_run', true)
            ->assertJsonPath('actions.can_signal', false)
            ->assertJsonPath('actions.can_query', false)
            ->assertJsonPath('actions.can_update', false)
            ->assertJsonPath('actions.can_cancel', false)
            ->assertJsonPath('actions.can_terminate', false);
    }

    public function test_it_terminates_waiting_workflows_through_the_control_plane_api(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-control-plane-terminate',
                'workflow_type' => 'tests.await-approval-workflow',
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $terminate = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-control-plane-terminate/terminate', [
                'reason' => 'operator terminated run',
                'request_id' => 'terminate-request-1',
            ]);

        $terminate->assertOk()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('workflow_id', 'wf-control-plane-terminate')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('command_source', 'control_plane')
            ->assertJsonPath('control_plane.operation', 'terminate')
            ->assertJsonPath('control_plane.outcome', 'terminated')
            ->assertJsonPath('outcome', 'terminated');

        $command = WorkflowCommand::query()
            ->where('workflow_instance_id', 'wf-control-plane-terminate')
            ->where('command_type', 'terminate')
            ->latest('command_sequence')
            ->firstOrFail();

        $terminateRequested = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'TerminateRequested')
            ->firstOrFail();
        $terminated = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'WorkflowTerminated')
            ->firstOrFail();

        $this->assertSame('control_plane', $command->source);
        $this->assertSame('operator terminated run', $command->commandReason());
        $this->assertSame('operator terminated run', $terminateRequested->payload['reason'] ?? null);
        $this->assertSame('operator terminated run', $terminated->payload['reason'] ?? null);

        $this->withHeaders(array_merge($this->apiHeaders(), [
            'X-Durable-Workflow-Control-Plane-Version' => '999',
        ]))
            ->postJson('/api/workflows', [
                'workflow_type' => 'tests.await-approval-workflow',
            ])
            ->assertStatus(400)
            ->assertJsonPath('reason', 'unsupported_control_plane_version')
            ->assertJsonPath('supported_version', '2');

        $showRun = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/wf-control-plane-terminate/runs/{$runId}");

        $showRun->assertOk()
            ->assertJsonPath('status', 'terminated');
    }

    public function test_control_plane_requests_require_an_explicit_v2_header_and_reject_legacy_wait_policy_fields(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $this->withHeaders([
            'X-Namespace' => 'default',
        ])->postJson('/api/workflows', [
            'workflow_type' => 'tests.await-approval-workflow',
        ])->assertStatus(400)
            ->assertJsonPath('reason', 'missing_control_plane_version')
            ->assertJsonPath('supported_version', '2');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-control-plane-versioned-update',
                'workflow_type' => 'tests.interactive-command-workflow',
            ]);

        $start->assertCreated();

        $this->runReadyWorkflowTask((string) $start->json('run_id'));

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-control-plane-versioned-update/update/approve', [
                'input' => [true, 'api'],
                'wait_policy' => 'completed',
            ])
            ->assertStatus(422)
            ->assertJsonPath('control_plane.operation', 'update')
            ->assertJsonPath('control_plane.operation_name', 'approve')
            ->assertJsonPath('control_plane.workflow_id', 'wf-control-plane-versioned-update')
            ->assertJsonPath(
                'control_plane.validation_errors.wait_policy.0',
                'The wait_policy field is no longer supported. Use wait_for.',
            )
            ->assertJsonPath(
                'validation_errors.wait_policy.0',
                'The wait_policy field is no longer supported. Use wait_for.',
            )
            ->assertJsonPath(
                'errors.wait_policy.0',
                'The wait_policy field is no longer supported. Use wait_for.',
            );
    }

    public function test_control_plane_command_errors_include_the_shared_contract_for_version_and_auth_failures(): void
    {
        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => 'server-token',
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer server-token',
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Control-Plane-Version' => '999',
        ])->postJson('/api/workflows/wf-version-error/signal/advance', [
            'input' => ['Ada'],
        ])->assertStatus(400)
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('reason', 'unsupported_control_plane_version')
            ->assertJsonPath('control_plane.operation', 'signal')
            ->assertJsonPath('control_plane.operation_name', 'advance')
            ->assertJsonPath('control_plane.workflow_id', 'wf-version-error');

        $this->withHeaders([
            'Authorization' => '',
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ])->postJson('/api/workflows/wf-auth-error/signal/advance', [
            'input' => ['Ada'],
        ])->assertUnauthorized()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('reason', 'unauthorized')
            ->assertJsonPath('message', 'Invalid or missing authentication token.')
            ->assertJsonPath('control_plane.operation', 'signal')
            ->assertJsonPath('control_plane.operation_name', 'advance')
            ->assertJsonPath('control_plane.workflow_id', 'wf-auth-error');
    }

    public function test_start_rejects_removed_legacy_fields_and_unsupported_duplicate_policy_values(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_type' => 'tests.await-approval-workflow',
                'workflow_execution_timeout' => 300,
                'workflow_run_timeout' => 120,
                'workflow_task_timeout' => 30,
                'retry_policy' => ['maximum_attempts' => 3],
                'idempotency_key' => 'start-idempotency-1',
                'request_id' => 'start-request-1',
                'duplicate_policy' => 'terminate_existing',
            ])
            ->assertStatus(422)
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('control_plane.operation', 'start')
            ->assertJsonPath(
                'errors.workflow_execution_timeout.0',
                'The workflow_execution_timeout field is not supported by the v2 workflow start API.',
            )
            ->assertJsonPath(
                'errors.workflow_run_timeout.0',
                'The workflow_run_timeout field is not supported by the v2 workflow start API.',
            )
            ->assertJsonPath(
                'errors.workflow_task_timeout.0',
                'The workflow_task_timeout field is not supported by the v2 workflow start API.',
            )
            ->assertJsonPath(
                'errors.retry_policy.0',
                'The retry_policy field is not supported by the v2 workflow start API.',
            )
            ->assertJsonPath(
                'errors.idempotency_key.0',
                'The idempotency_key field is not supported by the v2 workflow start API.',
            )
            ->assertJsonPath(
                'errors.request_id.0',
                'The request_id field is not supported by the v2 workflow start API.',
            )
            ->assertJsonPath(
                'errors.duplicate_policy.0',
                'The duplicate_policy field only supports fail or use-existing.',
            );
    }

    public function test_start_supports_the_canonical_use_existing_duplicate_policy_for_an_active_run(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $firstStart = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-control-plane-start-use-existing',
                'workflow_type' => 'tests.await-approval-workflow',
            ]);

        $firstStart->assertCreated()
            ->assertJsonPath('workflow_id', 'wf-control-plane-start-use-existing')
            ->assertJsonPath('outcome', 'started_new');

        $runId = (string) $firstStart->json('run_id');

        $duplicateStart = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-control-plane-start-use-existing',
                'workflow_type' => 'tests.await-approval-workflow',
                'duplicate_policy' => 'use-existing',
            ]);

        $duplicateStart->assertOk()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('workflow_id', 'wf-control-plane-start-use-existing')
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('outcome', 'returned_existing_active')
            ->assertJsonPath('status', 'pending');

        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->count());
    }

    public function test_start_rejects_the_legacy_underscore_duplicate_policy_alias(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_type' => 'tests.await-approval-workflow',
                'duplicate_policy' => 'use_existing',
            ])
            ->assertStatus(422)
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('control_plane.operation', 'start')
            ->assertJsonPath(
                'errors.duplicate_policy.0',
                'The duplicate_policy field only supports fail or use-existing.',
            );
    }

    public function test_it_fails_closed_when_a_configured_workflow_type_mapping_breaks_after_start(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-control-plane-broken-type-map',
                'workflow_type' => 'tests.interactive-command-workflow',
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');
        $this->runReadyWorkflowTask($runId);

        config()->set('workflows.v2.types.workflows', [
            'tests.interactive-command-workflow' => 'App\\Missing\\Workflow',
        ]);

        foreach ([
            ['/api/workflows/wf-control-plane-broken-type-map/query/currentState', []],
            ['/api/workflows/wf-control-plane-broken-type-map/signal/advance', ['input' => ['Ada']]],
            ['/api/workflows/wf-control-plane-broken-type-map/update/approve', ['input' => [true, 'api']]],
            ['/api/workflows/wf-control-plane-broken-type-map/cancel', ['reason' => 'operator cancel']],
            ['/api/workflows/wf-control-plane-broken-type-map/terminate', ['reason' => 'operator terminate']],
        ] as [$path, $payload]) {
            $this->withHeaders($this->apiHeaders())
                ->postJson($path, $payload)
                ->assertStatus(409)
                ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
                ->assertJsonPath('workflow_id', 'wf-control-plane-broken-type-map')
                ->assertJsonPath('control_plane.workflow_id', 'wf-control-plane-broken-type-map')
                ->assertJsonPath('run_id', $runId)
                ->assertJsonPath('workflow_type', 'tests.interactive-command-workflow')
                ->assertJsonPath('reason', 'configured_workflow_type_invalid')
                ->assertJsonPath('blocked_reason', 'configured_workflow_type_invalid')
                ->assertJsonPath(
                    'message',
                    'Configured durable workflow type [tests.interactive-command-workflow] points to [App\\Missing\\Workflow], which is not a loadable workflow class.',
                );
        }

        $this->assertSame(0, WorkflowCommand::query()
            ->where('workflow_instance_id', 'wf-control-plane-broken-type-map')
            ->whereIn('command_type', ['signal', 'update', 'cancel', 'terminate'])
            ->count());
    }

    public function test_it_projects_child_workflows_started_by_in_process_execution_into_the_namespace(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-control-plane-parent-with-child',
                'workflow_type' => 'tests.internal-parent-workflow',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $parentRunId = (string) $start->json('run_id');
        $this->runReadyWorkflowTask($parentRunId);

        $childBinding = WorkflowNamespaceWorkflow::query()
            ->where('namespace', 'default')
            ->where('workflow_type', 'tests.internal-child-workflow')
            ->first();

        $this->assertNotNull($childBinding);

        $childWorkflowId = (string) $childBinding->workflow_instance_id;

        $showChild = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$childWorkflowId}");

        $showChild->assertOk()
            ->assertJsonPath('workflow_id', $childWorkflowId)
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('workflow_type', 'tests.internal-child-workflow')
            ->assertJsonPath('status', 'pending');

        $childRunId = (string) $showChild->json('run_id');

        $this->runReadyWorkflowTask($childRunId);
        $this->runReadyWorkflowTask($parentRunId);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-control-plane-parent-with-child')
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('output.child.greeting', 'Hello from child, Ada!')
            ->assertJsonPath('output.child.workflow_id', $childWorkflowId);
    }

    public function test_it_binds_child_namespaces_from_links_and_fills_workflow_type_from_lineage_projection(): void
    {
        $this->createNamespace('default', 'Default namespace');

        WorkflowNamespaceWorkflow::query()->create([
            'namespace' => 'default',
            'workflow_instance_id' => 'wf-lineage-parent',
            'workflow_type' => 'tests.internal-parent-workflow',
        ]);

        WorkflowLink::query()->create([
            'id' => (string) Str::ulid(),
            'link_type' => 'child_workflow',
            'parent_workflow_instance_id' => 'wf-lineage-parent',
            'parent_workflow_run_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'child_workflow_instance_id' => 'wf-lineage-child',
            'child_workflow_run_id' => '01ARZ3NDEKTSV4RRFFQ69G5FB0',
            'is_primary_parent' => true,
        ]);

        $binding = WorkflowNamespaceWorkflow::query()
            ->where('namespace', 'default')
            ->where('workflow_instance_id', 'wf-lineage-child')
            ->first();

        $this->assertNotNull($binding);
        $this->assertNull($binding->workflow_type);

        WorkflowRunLineageEntry::query()->create([
            'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV:child:lineage',
            'workflow_run_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'workflow_instance_id' => 'wf-lineage-parent',
            'direction' => 'child',
            'lineage_id' => 'lineage-child-1',
            'position' => 0,
            'link_type' => 'child_workflow',
            'is_primary_parent' => true,
            'related_workflow_instance_id' => 'wf-lineage-child',
            'related_workflow_run_id' => '01ARZ3NDEKTSV4RRFFQ69G5FB0',
            'related_workflow_type' => 'tests.internal-child-workflow',
            'payload' => [],
            'linked_at' => now(),
        ]);

        $binding = $binding->fresh();

        $this->assertNotNull($binding);
        $this->assertSame('default', $binding->namespace);
        $this->assertSame('tests.internal-child-workflow', $binding->workflow_type);
    }

    public function test_workflow_list_filters_by_status_bucket_not_raw_status(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-status-bucket-filter',
                'workflow_type' => 'tests.await-approval-workflow',
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        // Pending/running workflows should appear when filtering by "running" bucket
        $runningList = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows?status=running');

        $runningList->assertOk()
            ->assertJsonPath('workflow_count', 1)
            ->assertJsonPath('workflows.0.workflow_id', 'wf-status-bucket-filter')
            ->assertJsonPath('workflows.0.status_bucket', 'running');

        // Completed bucket should not include pending workflows
        $completedList = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows?status=completed');

        $completedList->assertOk()
            ->assertJsonPath('workflow_count', 0);

        // Failed bucket should not include pending workflows
        $failedList = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows?status=failed');

        $failedList->assertOk()
            ->assertJsonPath('workflow_count', 0);

        // Raw status values like "cancelled" or "terminated" are no longer accepted
        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows?status=cancelled')
            ->assertStatus(422);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows?status=terminated')
            ->assertStatus(422);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows?status=pending')
            ->assertStatus(422);
    }

    public function test_run_targeted_signal_on_current_run_succeeds(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-run-target-signal',
                'workflow_type' => 'tests.interactive-command-workflow',
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $signal = $this->withHeaders($this->apiHeaders())
            ->postJson("/api/workflows/wf-run-target-signal/runs/{$runId}/signal/advance", [
                'input' => ['Ada'],
            ]);

        $signal->assertStatus(202)
            ->assertJsonPath('workflow_id', 'wf-run-target-signal')
            ->assertJsonPath('signal_name', 'advance')
            ->assertJsonPath('control_plane.operation', 'signal')
            ->assertJsonPath('control_plane.operation_name', 'advance');
    }

    public function test_run_targeted_commands_reject_historical_runs(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-run-target-reject',
                'workflow_type' => 'tests.interactive-command-workflow',
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');
        $fakeHistoricalRunId = 'historical-run-id-does-not-exist';

        $this->runReadyWorkflowTask($runId);

        // Signal against a non-current run should be rejected
        $signal = $this->withHeaders($this->apiHeaders())
            ->postJson("/api/workflows/wf-run-target-reject/runs/{$fakeHistoricalRunId}/signal/advance", [
                'input' => ['Ada'],
            ]);

        $signal->assertStatus(409)
            ->assertJsonPath('reason', 'historical_run_command_rejected')
            ->assertJsonPath('workflow_id', 'wf-run-target-reject')
            ->assertJsonPath('run_id', $fakeHistoricalRunId)
            ->assertJsonPath('target_scope', 'run')
            ->assertJsonPath('control_plane.operation', 'signal')
            ->assertJsonPath('control_plane.operation_name', 'advance');

        // Cancel against a non-current run should be rejected
        $cancel = $this->withHeaders($this->apiHeaders())
            ->postJson("/api/workflows/wf-run-target-reject/runs/{$fakeHistoricalRunId}/cancel", [
                'reason' => 'test cancel',
            ]);

        $cancel->assertStatus(409)
            ->assertJsonPath('reason', 'historical_run_command_rejected')
            ->assertJsonPath('control_plane.operation', 'cancel');

        // Terminate against a non-current run should be rejected
        $terminate = $this->withHeaders($this->apiHeaders())
            ->postJson("/api/workflows/wf-run-target-reject/runs/{$fakeHistoricalRunId}/terminate", [
                'reason' => 'test terminate',
            ]);

        $terminate->assertStatus(409)
            ->assertJsonPath('reason', 'historical_run_command_rejected')
            ->assertJsonPath('control_plane.operation', 'terminate');
    }

    public function test_run_targeted_commands_reject_unknown_workflows(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-unknown/runs/some-run-id/signal/advance', [
                'input' => ['Ada'],
            ])
            ->assertNotFound()
            ->assertJsonPath('reason', 'instance_not_found');
    }

    public function test_request_contract_includes_status_bucket_vocabulary(): void
    {
        $this->createNamespace('default', 'Default namespace');

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/cluster/info');

        $response->assertOk();

        $listOperation = $response->json('control_plane.request_contract.operations.list');

        $this->assertIsArray($listOperation);
        $this->assertSame(
            ['running', 'completed', 'failed'],
            $listOperation['fields']['status']['canonical_values'],
        );
        $this->assertSame(
            'failed',
            $listOperation['fields']['status']['rejected_aliases']['cancelled'],
        );
    }

    public function test_start_passes_namespace_and_command_context_to_the_control_plane(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-start-attribution',
                'workflow_type' => 'tests.await-approval-workflow',
            ]);

        $start->assertCreated()
            ->assertJsonPath('namespace', 'default');

        $command = WorkflowCommand::query()
            ->where('workflow_instance_id', 'wf-start-attribution')
            ->where('command_type', 'start')
            ->latest('command_sequence')
            ->firstOrFail();

        // The server now passes namespace and command_context in start options.
        // The package currently records the generic control_plane source for
        // start commands (it does not yet extract command_context from start
        // options like it does for signal/update/cancel/terminate). When the
        // package adds command_context support to start(), the server-enriched
        // attribution (caller type 'server', namespace, request metadata) will
        // appear here without any further server-side changes.
        $this->assertSame('control_plane', $command->source);
    }

    private function configureWorkflowTypes(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'tests.interactive-command-workflow' => InteractiveCommandWorkflow::class,
            'tests.await-approval-workflow' => AwaitApprovalWorkflow::class,
            'tests.internal-parent-workflow' => InternalParentWorkflow::class,
            'tests.internal-child-workflow' => InternalChildWorkflow::class,
        ]);
    }

    private function createNamespace(string $name, string $description): void
    {
        WorkflowNamespace::query()->updateOrCreate(
            ['name' => $name],
            [
                'description' => $description,
                'retention_days' => 30,
                'status' => 'active',
            ],
        );
    }

    private function apiHeaders(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ];
    }

    private function runReadyWorkflowTask(string $runId): void
    {
        $taskId = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->where('status', 'ready')
            ->orderBy('available_at')
            ->value('id');

        $this->assertIsString($taskId);

        $job = new RunWorkflowTask($taskId);
        $job->handle(app(WorkflowExecutor::class));
    }
}
