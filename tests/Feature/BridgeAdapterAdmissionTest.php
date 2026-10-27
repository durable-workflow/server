<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\BridgeCommandIdentityWorkflow;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowInstance;

class BridgeAdapterAdmissionTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->createNamespace('default');
        $this->configureWorkflowTypes(['tests.bridge-command-identity' => BridgeCommandIdentityWorkflow::class]);
    }

    public function test_adapter_names_are_bounded_before_creating_an_instance_or_command(): void
    {
        $event = ['action' => 'start_workflow', 'idempotency_key' => 'provider-event',
            'target' => ['workflow_type' => 'tests.bridge-command-identity']];
        $accepted = $this->withHeaders($this->apiHeaders())->postJson('/api/bridge-adapters/webhook/'.str_repeat('a', 64), $event)->assertStatus(202);
        $this->assertSame(104, strlen($accepted->json('workflow_id')));
        $before = WorkflowCommand::query()->count();
        $instances = WorkflowInstance::query()->count();
        $this->withHeaders($this->apiHeaders())->postJson('/api/bridge-adapters/webhook/'.str_repeat('a', 65), $event)
            ->assertUnprocessable()->assertJsonPath('reason', 'unsupported_routing');
        $this->assertSame($before, WorkflowCommand::query()->count());
        $this->assertSame($instances, WorkflowInstance::query()->count());
    }

    public function test_foreign_start_target_refusal_does_not_disclose_its_reserved_type(): void
    {
        $this->createNamespace('private-fixture');
        $foreign = WorkflowInstance::query()->create([
            'id' => 'foreign-bridge', 'namespace' => 'private-fixture', 'workflow_type' => 'private.hidden.type',
            'workflow_class' => BridgeCommandIdentityWorkflow::class, 'reserved_at' => now(), 'run_count' => 0,
        ]);
        $before = $foreign->fresh()->getRawOriginal();
        $response = $this->withHeaders($this->apiHeaders())->postJson('/api/bridge-adapters/webhook/stripe', [
            'action' => 'start_workflow', 'idempotency_key' => 'provider-event',
            'target' => ['workflow_id' => 'foreign-bridge', 'workflow_type' => 'tests.bridge-command-identity'],
        ])->assertUnprocessable()->assertJsonPath('reason', 'unknown_target')
            ->assertJsonPath('message', 'Workflow instance [foreign-bridge] is unavailable.');
        $this->assertStringNotContainsString('private.hidden.type', $response->getContent());
        $this->assertStringNotContainsString('private-fixture', $response->getContent());
        $this->assertSame($before, $foreign->fresh()->getRawOriginal());
        $this->assertDatabaseCount('workflow_commands', 0);
    }

    public function test_update_identity_scopes_adapter_target_name_and_opaque_provider_key(): void
    {
        $start = $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'bridge-scoped-update', 'workflow_type' => 'tests.bridge-command-identity',
        ])->assertCreated();
        $this->runReadyWorkflowTask($start->json('run_id'));
        foreach ([['stripe', 'approve', 'event'], ['shopify', 'approve', 'event'],
            ['shopify', 'reject', 'event'], ['shopify', 'reject', 'Event'], ['shopify', 'reject', ' event ']] as [$adapter, $name, $key]) {
            $this->withHeaders(array_merge($this->apiHeaders(), ['X-Request-Id' => 'shared-proxy-correlation']))->postJson('/api/bridge-adapters/webhook/'.$adapter, [
                'action' => 'update_workflow', 'idempotency_key' => $key,
                'target' => ['workflow_id' => 'bridge-scoped-update', 'update_name' => $name], 'input' => ['first'],
            ])->assertStatus(202)->assertJsonPath('idempotency_key', $key);
        }
        $commands = WorkflowCommand::query()->where('command_type', 'update')->get();
        $this->assertCount(5, $commands);
        $this->assertCount(5, $commands->unique('request_id'));
        foreach ($commands as $command) {
            $this->assertMatchesRegularExpression('/^dw-bridge:v1:[a-f0-9]{64}$/D', $command->request_id);
        }
        $before = $commands->map->getRawOriginal()->all();
        $this->withHeaders($this->apiHeaders())->postJson('/api/bridge-adapters/webhook/stripe', [
            'action' => 'update_workflow', 'idempotency_key' => 'event',
            'target' => ['workflow_id' => 'bridge-scoped-update', 'update_name' => 'approve'], 'input' => ['ignored'],
        ])->assertOk()->assertJsonPath('outcome', 'duplicate')->assertJsonPath('idempotency_key', 'event');
        $this->assertSame($before, WorkflowCommand::query()->where('command_type', 'update')->get()->map->getRawOriginal()->all());
    }

    public function test_bridge_inputs_preserve_whitespace_and_empty_strings(): void
    {
        $start = $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'bridge-raw-input', 'workflow_type' => 'tests.bridge-command-identity',
        ])->assertCreated();
        $this->runReadyWorkflowTask($start->json('run_id'));
        foreach (['  spaced  ', ''] as $index => $input) {
            $key = ' event-'.$index.' ';
            $this->withHeaders($this->apiHeaders())->postJson('/api/bridge-adapters/webhook/stripe', [
                'action' => 'signal_workflow', 'idempotency_key' => $key,
                'target' => ['workflow_id' => 'bridge-raw-input', 'signal_name' => 'advance'], 'input' => [$input],
            ])->assertStatus(202)->assertJsonPath('idempotency_key', $key);
        }
        $this->assertSame([['  spaced  '], ['']], WorkflowCommand::query()->where('command_type', 'signal')->orderBy('command_sequence')->get()
            ->map(static fn (WorkflowCommand $command): array => $command->payloadArguments())->all());
    }

    public function test_conflicting_retained_bridge_tuple_is_refused_without_replacing_the_receipt(): void
    {
        $start = $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'bridge-conflict', 'workflow_type' => 'tests.bridge-command-identity',
        ])->assertCreated();
        $this->runReadyWorkflowTask($start->json('run_id'));
        $event = ['action' => 'signal_workflow', 'idempotency_key' => 'same-event',
            'target' => ['workflow_id' => 'bridge-conflict', 'signal_name' => 'advance'], 'input' => ['first']];
        $path = '/api/bridge-adapters/webhook/stripe';
        $this->withHeaders($this->apiHeaders())->postJson($path, $event)->assertStatus(202);
        $command = WorkflowCommand::query()->where('command_type', 'signal')->sole();
        $context = $command->context;
        $context['server']['metadata']['idempotency_key'] = 'different-retained-event';
        $command->update(['context' => $context]);
        $before = $command->fresh()->getRawOriginal();
        $this->withHeaders($this->apiHeaders())->postJson($path, $event)
            ->assertStatus(409)->assertJsonPath('reason', 'bridge_identity_conflict');
        $this->assertSame(1, WorkflowCommand::query()->where('command_type', 'signal')->count());
        $this->assertDatabaseCount('workflow_signal_records', 1);
        $this->assertSame($before, $command->fresh()->getRawOriginal());
    }

    public function test_accepted_legacy_bridge_receipt_is_reused_without_rewriting_it(): void
    {
        $start = $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'bridge-legacy', 'workflow_type' => 'tests.bridge-command-identity',
        ])->assertCreated();
        $this->runReadyWorkflowTask($start->json('run_id'));
        $event = ['action' => 'signal_workflow', 'idempotency_key' => 'legacy-event',
            'target' => ['workflow_id' => 'bridge-legacy', 'signal_name' => 'advance'], 'input' => ['first']];
        $path = '/api/bridge-adapters/webhook/stripe';
        $this->withHeaders($this->apiHeaders())->postJson($path, $event)->assertStatus(202);
        $command = WorkflowCommand::query()->where('command_type', 'signal')->sole();
        $context = $command->context;
        $context['server']['metadata']['request_id'] = 'legacy-event';
        $context['request']['request_id'] = 'legacy-proxy-request';
        $command->update(['request_id' => null, 'context' => $context]);
        $before = $command->fresh()->getRawOriginal();
        $this->withHeaders($this->apiHeaders())->postJson($path, array_replace($event, ['input' => ['ignored']]))
            ->assertOk()->assertJsonPath('outcome', 'duplicate')->assertJsonPath('idempotency_key', 'legacy-event');
        $this->assertSame(1, WorkflowCommand::query()->where('command_type', 'signal')->count());
        $this->assertDatabaseCount('workflow_signal_records', 1);
        $this->assertSame($before, $command->fresh()->getRawOriginal());
    }
}
