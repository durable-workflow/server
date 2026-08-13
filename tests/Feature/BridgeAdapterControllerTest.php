<?php

namespace Tests\Feature;

use App\Models\WorkerBuildIdRollout;
use App\Models\WorkerRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\InteractiveCommandWorkflow;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkerCompatibilityFleet;

class BridgeAdapterControllerTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
        $this->configureWorkflowTypes([
            'tests.interactive-command-workflow' => InteractiveCommandWorkflow::class,
        ]);
        WorkerCompatibilityFleet::clear();
    }

    protected function tearDown(): void
    {
        WorkerCompatibilityFleet::clear();

        parent::tearDown();
    }

    public function test_webhook_bridge_starts_workflow_and_dedupes_by_provider_event(): void
    {
        Queue::fake();

        $payload = [
            'action' => 'start_workflow',
            'idempotency_key' => 'stripe-event-1001',
            'target' => [
                'workflow_type' => 'tests.interactive-command-workflow',
                'task_queue' => 'external-workflows',
                'business_key' => 'invoice-1001',
            ],
            'correlation' => [
                'provider' => 'stripe',
                'event_type' => 'invoice.paid',
            ],
        ];

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/bridge-adapters/webhook/stripe', $payload);

        $start->assertStatus(202)
            ->assertJsonPath('schema', 'durable-workflow.v2.bridge-adapter-outcome.contract')
            ->assertJsonPath('version', 1)
            ->assertJsonPath('adapter', 'stripe')
            ->assertJsonPath('action', 'start_workflow')
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('outcome', 'accepted')
            ->assertJsonPath('idempotency_key', 'stripe-event-1001')
            ->assertJsonPath('target.workflow_type', 'tests.interactive-command-workflow')
            ->assertJsonPath('target.task_queue', 'external-workflows')
            ->assertJsonPath('target.business_key', 'invoice-1001')
            ->assertJsonPath('workflow_type', 'tests.interactive-command-workflow')
            ->assertJsonPath('control_plane_outcome', 'started_new')
            ->assertJsonMissingPath('raw_payload');

        $workflowId = (string) $start->json('workflow_id');

        $this->assertStringStartsWith('bridge-stripe-', $workflowId);

        $duplicate = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/bridge-adapters/webhook/stripe', $payload);

        $duplicate->assertOk()
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('outcome', 'duplicate')
            ->assertJsonPath('reason', 'duplicate_start')
            ->assertJsonPath('workflow_id', $workflowId)
            ->assertJsonPath('control_plane_outcome', 'returned_existing_active');
    }

    public function test_webhook_bridge_signals_workflow_with_idempotency_context(): void
    {
        Queue::fake();

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-bridge-signal',
                'workflow_type' => 'tests.interactive-command-workflow',
            ]);

        $start->assertCreated();
        $this->runReadyWorkflowTask((string) $start->json('run_id'));

        $signal = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/bridge-adapters/webhook/shopify', [
                'action' => 'signal_workflow',
                'idempotency_key' => 'shopify-event-2002',
                'target' => [
                    'workflow_id' => 'wf-bridge-signal',
                    'signal_name' => 'advance',
                ],
                'input' => ['Ada'],
            ]);

        $signal->assertStatus(202)
            ->assertJsonPath('adapter', 'shopify')
            ->assertJsonPath('action', 'signal_workflow')
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('outcome', 'accepted')
            ->assertJsonPath('workflow_id', 'wf-bridge-signal')
            ->assertJsonPath('target.signal_name', 'advance');

        $command = WorkflowCommand::query()
            ->where('workflow_instance_id', 'wf-bridge-signal')
            ->where('command_type', 'signal')
            ->firstOrFail();

        $context = $command->context ?? [];

        $this->assertSame('shopify', $context['server']['metadata']['adapter'] ?? null);
        $this->assertSame('signal_workflow', $context['server']['metadata']['action'] ?? null);
        $this->assertSame('shopify-event-2002', $context['server']['metadata']['idempotency_key'] ?? null);
        $this->assertSame('shopify-event-2002', $context['server']['metadata']['request_id'] ?? null);

        $duplicate = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/bridge-adapters/webhook/shopify', [
                'action' => 'signal_workflow',
                'idempotency_key' => 'shopify-event-2002',
                'target' => [
                    'workflow_id' => 'wf-bridge-signal',
                    'signal_name' => 'advance',
                ],
                'input' => ['Grace'],
            ]);

        $duplicate->assertOk()
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('outcome', 'duplicate')
            ->assertJsonPath('control_plane_outcome', 'deduped_existing_command')
            ->assertJsonPath('workflow_id', 'wf-bridge-signal');

        $this->assertSame(1, WorkflowCommand::query()
            ->where('workflow_instance_id', 'wf-bridge-signal')
            ->where('command_type', 'signal')
            ->count());
    }

    public function test_webhook_bridge_dedupes_update_commands_by_provider_event(): void
    {
        Queue::fake();

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-bridge-update',
                'workflow_type' => 'tests.interactive-command-workflow',
            ]);

        $start->assertCreated();
        $this->runReadyWorkflowTask((string) $start->json('run_id'));

        $payload = [
            'action' => 'update_workflow',
            'idempotency_key' => 'pagerduty-event-3003',
            'target' => [
                'workflow_id' => 'wf-bridge-update',
                'update_name' => 'approve',
            ],
            'input' => [true, 'pagerduty'],
        ];

        $update = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/bridge-adapters/webhook/pagerduty', $payload);

        $update->assertStatus(202)
            ->assertJsonPath('adapter', 'pagerduty')
            ->assertJsonPath('action', 'update_workflow')
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('outcome', 'accepted')
            ->assertJsonPath('workflow_id', 'wf-bridge-update')
            ->assertJsonPath('target.update_name', 'approve');

        $duplicate = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/bridge-adapters/webhook/pagerduty', [
                ...$payload,
                'input' => [false, 'duplicate'],
            ]);

        $duplicate->assertOk()
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('outcome', 'duplicate')
            ->assertJsonPath('control_plane_outcome', 'deduped_existing_command')
            ->assertJsonPath('workflow_id', 'wf-bridge-update');

        $command = WorkflowCommand::query()
            ->where('workflow_instance_id', 'wf-bridge-update')
            ->where('command_type', 'update')
            ->firstOrFail();

        $context = $command->context ?? [];

        $this->assertSame('pagerduty-event-3003', $context['server']['metadata']['idempotency_key'] ?? null);
        $this->assertSame('pagerduty-event-3003', $context['server']['metadata']['request_id'] ?? null);
        $this->assertSame(1, WorkflowCommand::query()
            ->where('workflow_instance_id', 'wf-bridge-update')
            ->where('command_type', 'update')
            ->count());
    }

    public function test_webhook_bridge_rejects_json_tagged_update_before_returning_duplicate_outcome(): void
    {
        Queue::fake();

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-bridge-update-codec-guard',
                'workflow_type' => 'tests.interactive-command-workflow',
            ]);

        $start->assertCreated();
        $this->runReadyWorkflowTask((string) $start->json('run_id'));

        $payload = [
            'action' => 'update_workflow',
            'idempotency_key' => 'pagerduty-event-codec-guard',
            'target' => [
                'workflow_id' => 'wf-bridge-update-codec-guard',
                'update_name' => 'approve',
            ],
            'input' => [true, 'pagerduty'],
        ];

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/bridge-adapters/webhook/pagerduty', $payload)
            ->assertStatus(202);

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/bridge-adapters/webhook/pagerduty', [
                ...$payload,
                'input' => ['codec' => 'json', 'blob' => '[false,"duplicate"]'],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('unsupported_payload_codec', $response->getContent());
        $this->assertSame(1, WorkflowCommand::query()
            ->where('workflow_instance_id', 'wf-bridge-update-codec-guard')
            ->where('command_type', 'update')
            ->count());
    }

    public function test_webhook_bridge_uses_named_rejections(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/bridge-adapters/webhook/github', [
                'action' => 'signal_workflow',
                'idempotency_key' => 'github-event-3003',
                'target' => [
                    'workflow_id' => 'wf-missing',
                    'signal_name' => 'advance',
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('outcome', 'rejected')
            ->assertJsonPath('reason', 'unknown_target');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/bridge-adapters/webhook/github', [
                'action' => 'not_supported',
                'idempotency_key' => 'github-event-3004',
                'target' => ['workflow_id' => 'wf-missing'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('outcome', 'rejected')
            ->assertJsonPath('reason', 'unsupported_action')
            ->assertJsonPath('action', 'not_supported');
    }

    public function test_webhook_bridge_blocks_drained_task_queue_starts_without_an_active_worker_cohort(): void
    {
        Queue::fake();

        WorkerRegistration::query()->create([
            'worker_id' => 'draining-worker',
            'namespace' => 'default',
            'task_queue' => 'drain-queue',
            'runtime' => 'php',
            'sdk_version' => '1.0.0',
            'build_id' => 'build-draining',
            'supported_workflow_types' => ['tests.interactive-command-workflow'],
            'workflow_definition_fingerprints' => [],
            'supported_activity_types' => [],
            'max_concurrent_workflow_tasks' => 100,
            'max_concurrent_activity_tasks' => 100,
            'last_heartbeat_at' => now(),
            'status' => 'draining',
        ]);

        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'drain-queue',
            'build_id' => 'build-draining',
            'drain_intent' => 'draining',
            'drained_at' => now(),
        ]);

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/bridge-adapters/webhook/stripe', [
                'action' => 'start_workflow',
                'idempotency_key' => 'stripe-event-drain-1',
                'target' => [
                    'workflow_id' => 'wf-bridge-drained-start',
                    'workflow_type' => 'tests.interactive-command-workflow',
                    'task_queue' => 'drain-queue',
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('adapter', 'stripe')
            ->assertJsonPath('action', 'start_workflow')
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('outcome', 'rejected')
            ->assertJsonPath('reason', 'task_queue_draining')
            ->assertJsonPath('rejection_reason', 'task_queue_draining')
            ->assertJsonPath('control_plane_outcome', 'rejected_task_queue_draining')
            ->assertJsonPath('workflow_id', 'wf-bridge-drained-start')
            ->assertJsonPath('workflow_type', 'tests.interactive-command-workflow')
            ->assertJsonPath('task_queue', 'drain-queue')
            ->assertJsonPath('routing_status', 'draining')
            ->assertJsonPath('active_worker_count', 0)
            ->assertJsonPath('draining_worker_count', 1)
            ->assertJsonPath('stale_worker_count', 0)
            ->assertJsonPath('draining_build_ids.0', 'build-draining')
            ->assertJsonPath('drain_intent', 'draining')
            ->assertJsonPath(
                'message',
                'Task queue [drain-queue] is draining and cannot accept new workflow starts until an active worker cohort is available.',
            );

        $this->assertFalse(WorkflowRun::query()->exists());
    }

    public function test_webhook_bridge_rejects_start_when_the_implicit_default_queue_is_draining(): void
    {
        Queue::fake();

        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('queue.connections.redis.queue', 'default');

        WorkerRegistration::query()->create([
            'worker_id' => 'worker-default-draining',
            'namespace' => 'default',
            'task_queue' => 'default',
            'runtime' => 'php',
            'sdk_version' => '1.0.0',
            'build_id' => 'build-default-draining',
            'supported_workflow_types' => [],
            'workflow_definition_fingerprints' => [],
            'supported_activity_types' => [],
            'max_concurrent_workflow_tasks' => 100,
            'max_concurrent_activity_tasks' => 100,
            'last_heartbeat_at' => now(),
            'status' => 'draining',
        ]);

        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'default',
            'build_id' => 'build-default-draining',
            'drain_intent' => 'draining',
            'drained_at' => now(),
        ]);

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/bridge-adapters/webhook/stripe', [
                'action' => 'start_workflow',
                'idempotency_key' => 'stripe-event-default-drain-1',
                'target' => [
                    'workflow_id' => 'wf-bridge-drained-default-start',
                    'workflow_type' => 'tests.interactive-command-workflow',
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('adapter', 'stripe')
            ->assertJsonPath('action', 'start_workflow')
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('outcome', 'rejected')
            ->assertJsonPath('reason', 'task_queue_draining')
            ->assertJsonPath('rejection_reason', 'task_queue_draining')
            ->assertJsonPath('control_plane_outcome', 'rejected_task_queue_draining')
            ->assertJsonPath('workflow_id', 'wf-bridge-drained-default-start')
            ->assertJsonPath('workflow_type', 'tests.interactive-command-workflow')
            ->assertJsonPath('task_queue', 'default')
            ->assertJsonPath('routing_status', 'draining')
            ->assertJsonPath('active_worker_count', 0)
            ->assertJsonPath('draining_worker_count', 1)
            ->assertJsonPath('stale_worker_count', 0)
            ->assertJsonPath('draining_build_ids.0', 'build-default-draining')
            ->assertJsonPath('drain_intent', 'draining')
            ->assertJsonPath(
                'message',
                'Task queue [default] is draining and cannot accept new workflow starts until an active worker cohort is available.',
            );

        $this->assertFalse(WorkflowRun::query()->exists());
    }

    public function test_webhook_bridge_surfaces_fail_closed_start_rejection_detail(): void
    {
        Queue::fake();

        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-a']);
        config()->set('workflows.v2.fleet.validation_mode', 'fail');

        WorkerCompatibilityFleet::record(['build-b'], 'redis', 'default', 'worker-build-b');

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/bridge-adapters/webhook/stripe', [
                'action' => 'start_workflow',
                'idempotency_key' => 'stripe-event-compat-1',
                'target' => [
                    'workflow_id' => 'wf-bridge-compatibility-blocked',
                    'workflow_type' => 'tests.interactive-command-workflow',
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('adapter', 'stripe')
            ->assertJsonPath('action', 'start_workflow')
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('outcome', 'rejected')
            ->assertJsonPath('reason', 'compatibility_blocked')
            ->assertJsonPath('rejection_reason', 'compatibility_blocked')
            ->assertJsonPath('workflow_id', 'wf-bridge-compatibility-blocked')
            ->assertJsonPath('run_id', null)
            ->assertJsonPath('workflow_type', 'tests.interactive-command-workflow')
            ->assertJsonPath('control_plane_outcome', 'rejected_compatibility_blocked')
            ->assertJsonPath(
                'message',
                'Workflow instance [wf-bridge-compatibility-blocked] cannot start. Start blocked under fail validation mode. '
                .'No active worker heartbeat for queue [default] advertises compatibility [build-a]. '
                .'Active workers there advertise [build-b].',
            );

        $this->assertSame(0, WorkflowRun::query()->count());
    }
}
