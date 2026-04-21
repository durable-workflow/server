<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\HeaderAuthProvider;
use Tests\Fixtures\InteractiveCommandWorkflow;
use Tests\TestCase;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowExecutor;

class WorkflowCommandAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_trusted_forwarded_caller_and_auth_headers_in_command_context(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->configureTokenAuth();
        config(['server.command_attribution.trust_forwarded_headers' => true]);
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-command-attribution-trusted',
                'workflow_type' => 'tests.interactive-command-workflow',
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');
        $this->runReadyWorkflowTask($runId);

        $this->withHeaders($this->apiHeaders([
            'X-Workflow-Caller-Type' => 'operator',
            'X-Workflow-Caller-Label' => 'Ada Operator',
            'X-Workflow-Auth-Status' => 'authorized',
            'X-Workflow-Auth-Method' => 'gateway_token',
            'X-Request-Id' => 'signal-request-1',
            'X-Correlation-Id' => 'correlation-1',
        ]))->postJson('/api/workflows/wf-command-attribution-trusted/signal/advance', [
            'input' => ['Ada'],
            'request_id' => 'signal-request-1',
        ])->assertAccepted();

        $command = WorkflowCommand::query()
            ->where('workflow_instance_id', 'wf-command-attribution-trusted')
            ->where('command_type', 'signal')
            ->latest('command_sequence')
            ->firstOrFail();

        $this->assertSame('control_plane', $command->source);
        $this->assertSame('operator', $command->commandContext()['caller']['type'] ?? null);
        $this->assertSame('Ada Operator', $command->callerLabel());
        $this->assertSame('authorized', $command->authStatus());
        $this->assertSame('gateway_token', $command->authMethod());
        $this->assertSame('signal-request-1', $command->requestId());
        $this->assertSame('correlation-1', $command->correlationId());
    }

    public function test_it_ignores_forwarded_attribution_headers_when_trust_is_disabled(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->configureTokenAuth();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-command-attribution-default',
                'workflow_type' => 'tests.interactive-command-workflow',
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');
        $this->runReadyWorkflowTask($runId);

        $this->withHeaders($this->apiHeaders([
            'X-Workflow-Caller-Type' => 'operator',
            'X-Workflow-Caller-Label' => 'Spoofed Operator',
            'X-Workflow-Auth-Status' => 'trusted_elsewhere',
            'X-Workflow-Auth-Method' => 'gateway_token',
        ]))->postJson('/api/workflows/wf-command-attribution-default/signal/advance', [
            'input' => ['Grace'],
        ])->assertAccepted();

        $command = WorkflowCommand::query()
            ->where('workflow_instance_id', 'wf-command-attribution-default')
            ->where('command_type', 'signal')
            ->latest('command_sequence')
            ->firstOrFail();

        $this->assertSame('control_plane', $command->source);
        $this->assertSame('server', $command->commandContext()['caller']['type'] ?? null);
        $this->assertSame('Standalone Server', $command->callerLabel());
        $this->assertSame('authorized', $command->authStatus());
        $this->assertSame('token', $command->authMethod());
    }

    public function test_it_records_custom_provider_principal_in_command_context(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        config(['server.auth.provider' => HeaderAuthProvider::class]);
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->customAuthHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-custom-provider-principal',
                'workflow_type' => 'tests.interactive-command-workflow',
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');
        $this->runReadyWorkflowTask($runId);

        $this->withHeaders($this->customAuthHeaders([
            'X-Test-Trace' => 'trace-signal-1',
        ]))->postJson('/api/workflows/wf-custom-provider-principal/signal/advance', [
            'input' => ['Lin'],
        ])->assertAccepted();

        $command = WorkflowCommand::query()
            ->where('workflow_instance_id', 'wf-custom-provider-principal')
            ->where('command_type', 'signal')
            ->latest('command_sequence')
            ->firstOrFail();

        $auth = $command->commandContext()['auth'] ?? null;

        $this->assertIsArray($auth);
        $this->assertSame('authorized', $auth['status'] ?? null);
        $this->assertSame('test-header', $auth['method'] ?? null);
        $this->assertSame('operator', $auth['role'] ?? null);
        $this->assertSame(['operator'], $auth['roles'] ?? null);
        $this->assertSame('user-456', $auth['principal']['subject'] ?? null);
        $this->assertSame('tenant-a', $auth['principal']['tenant'] ?? null);
        $this->assertSame('trace-signal-1', $auth['principal']['claims']['trace_id'] ?? null);
    }

    private function configureWorkflowTypes(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'tests.interactive-command-workflow' => InteractiveCommandWorkflow::class,
        ]);
    }

    private function configureTokenAuth(): void
    {
        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => 'server-token',
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

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function apiHeaders(array $extra = []): array
    {
        return array_merge([
            'Authorization' => 'Bearer server-token',
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ], $extra);
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function customAuthHeaders(array $extra = []): array
    {
        return array_merge([
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Control-Plane-Version' => '2',
            'X-Test-Subject' => 'user-456',
            'X-Test-Roles' => 'operator',
            'X-Test-Tenant' => 'tenant-a',
        ], $extra);
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
