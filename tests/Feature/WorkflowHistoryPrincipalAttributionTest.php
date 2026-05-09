<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\HeaderAuthProvider;
use Tests\Fixtures\InteractiveCommandWorkflow;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;

/**
 * Contract test for server-derived principal attribution on workflow
 * history events (Replay 2026 Temporal-parity).
 *
 * The principal id, type, and label recorded in the command snapshot of
 * every mutation event (start, signal, update, cancel, terminate) MUST
 * be derived from the server-authenticated Principal, not from request
 * input or forwarded attribution headers. This test pins both halves of
 * the contract — the field is present on each event class, AND a client
 * cannot override it by sending forged headers.
 */
class WorkflowHistoryPrincipalAttributionTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureWorkflowTypes([
            'tests.interactive-command-workflow' => InteractiveCommandWorkflow::class,
        ]);

        config(['server.auth.provider' => HeaderAuthProvider::class]);

        $this->createNamespace('default');
    }

    public function test_each_mutating_command_records_the_server_principal_in_its_command_context(): void
    {
        Queue::fake();

        $start = $this->withHeaders($this->principalHeaders('user-901', 'operator', 'tenant-x'))
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-principal-attribution',
                'workflow_type' => 'tests.interactive-command-workflow',
            ]);

        $start->assertCreated();
        $runId = (string) $start->json('run_id');
        $this->runReadyWorkflowTask($runId);

        $this->withHeaders($this->principalHeaders('user-902', 'operator', 'tenant-x'))
            ->postJson('/api/workflows/wf-principal-attribution/signal/advance', [
                'input' => ['Ada'],
            ])->assertAccepted();

        $this->runReadyWorkflowTask($runId);

        $this->withHeaders($this->principalHeaders('user-903', 'admin'))
            ->postJson('/api/workflows/wf-principal-attribution/update/approve', [
                'input' => [true, 'audit-test'],
                'wait_for' => 'completed',
            ]);

        $this->withHeaders($this->principalHeaders('user-904', 'admin'))
            ->postJson('/api/workflows/wf-principal-attribution/cancel', [
                'reason' => 'audit cancel',
            ]);

        $expected = [
            'start' => 'user-901',
            'signal' => 'user-902',
            'update' => 'user-903',
            'cancel' => 'user-904',
        ];

        foreach ($expected as $type => $principalId) {
            $command = WorkflowCommand::query()
                ->where('workflow_instance_id', 'wf-principal-attribution')
                ->where('command_type', $type)
                ->latest('command_sequence')
                ->firstOrFail();

            $this->assertSame(
                $principalId,
                $command->principalId(),
                sprintf('command_type=%s did not record the server-derived principal id', $type),
            );
            $this->assertSame(
                'auth:test-header',
                $command->principalType(),
                sprintf('command_type=%s did not record the server-derived principal type', $type),
            );
        }
    }

    public function test_history_events_carry_the_server_principal_from_the_originating_command(): void
    {
        Queue::fake();

        $start = $this->withHeaders($this->principalHeaders('user-history-1', 'operator'))
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-principal-history',
                'workflow_type' => 'tests.interactive-command-workflow',
            ]);

        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        $startCommand = WorkflowCommand::query()
            ->where('workflow_instance_id', 'wf-principal-history')
            ->where('command_type', 'start')
            ->latest('command_sequence')
            ->firstOrFail();

        $events = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('workflow_command_id', $startCommand->id)
            ->get();

        $this->assertNotEmpty(
            $events,
            'Expected at least one history event linked to the start command.',
        );

        foreach ($events as $event) {
            $snapshot = $event->payload['command'] ?? null;
            $this->assertIsArray($snapshot, sprintf(
                'event %s missing command snapshot',
                $event->event_type?->value,
            ));
            $this->assertSame(
                'user-history-1',
                $snapshot['principal_id'] ?? null,
                sprintf(
                    'event %s missing server-derived principal_id',
                    $event->event_type?->value,
                ),
            );
            $this->assertSame(
                'auth:test-header',
                $snapshot['principal_type'] ?? null,
                sprintf(
                    'event %s missing server-derived principal_type',
                    $event->event_type?->value,
                ),
            );
        }
    }

    public function test_terminate_records_the_server_principal_on_the_command_context(): void
    {
        Queue::fake();

        $start = $this->withHeaders($this->principalHeaders('user-term-start', 'admin'))
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-principal-terminate',
                'workflow_type' => 'tests.interactive-command-workflow',
            ]);

        $start->assertCreated();
        $runId = (string) $start->json('run_id');
        $this->runReadyWorkflowTask($runId);

        $this->withHeaders($this->principalHeaders('user-term-2', 'admin'))
            ->postJson('/api/workflows/wf-principal-terminate/terminate', [
                'reason' => 'audit terminate',
            ]);

        $command = WorkflowCommand::query()
            ->where('workflow_instance_id', 'wf-principal-terminate')
            ->where('command_type', 'terminate')
            ->latest('command_sequence')
            ->firstOrFail();

        $this->assertSame('user-term-2', $command->principalId());
        $this->assertSame('auth:test-header', $command->principalType());
    }

    public function test_forwarded_principal_headers_cannot_override_the_server_principal(): void
    {
        Queue::fake();

        // Even with header trust opted in for caller/auth metadata, the
        // top-level principal MUST still be server-derived. A malicious
        // caller forging X-Workflow-Principal-Id (or anything else)
        // cannot override the authenticated subject.
        config(['server.command_attribution.trust_forwarded_headers' => true]);

        $start = $this->withHeaders($this->principalHeaders('user-real', 'operator', null, [
            // Spoof attempts — none of these may end up in the history event.
            'X-Workflow-Principal-Id' => 'attacker-id',
            'X-Workflow-Principal-Type' => 'attacker-type',
            'X-Workflow-Principal-Label' => 'Attacker',
        ]))->postJson('/api/workflows', [
            'workflow_id' => 'wf-principal-spoof',
            'workflow_type' => 'tests.interactive-command-workflow',
        ]);

        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        $command = WorkflowCommand::query()
            ->where('workflow_instance_id', 'wf-principal-spoof')
            ->where('command_type', 'start')
            ->latest('command_sequence')
            ->firstOrFail();

        $this->assertSame('user-real', $command->principalId());
        $this->assertSame('auth:test-header', $command->principalType());
        $this->assertNotSame('attacker-id', $command->principalId());
        $this->assertNotSame('attacker-type', $command->principalType());
        $this->assertNotSame('Attacker', $command->principalLabel());

        $events = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('workflow_command_id', $command->id)
            ->get();

        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $this->assertSame('user-real', $event->payload['command']['principal_id'] ?? null);
            $this->assertSame('auth:test-header', $event->payload['command']['principal_type'] ?? null);
        }
    }

    public function test_history_api_response_surfaces_the_principal_at_event_top_level(): void
    {
        Queue::fake();

        $start = $this->withHeaders($this->principalHeaders('user-api-1', 'operator'))
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-principal-api',
                'workflow_type' => 'tests.interactive-command-workflow',
            ]);

        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        $response = $this->withHeaders($this->principalHeaders('user-api-1', 'operator'))
            ->getJson("/api/workflows/wf-principal-api/runs/{$runId}/history");

        $response->assertOk();

        $events = $response->json('events');
        $this->assertIsArray($events);
        $this->assertNotEmpty($events);

        $startEvent = collect($events)
            ->first(static fn (array $event): bool => ($event['event_type'] ?? null) === 'WorkflowStarted');

        $this->assertNotNull($startEvent, 'Expected a WorkflowStarted event in the history response.');
        $this->assertIsArray($startEvent['principal'] ?? null);
        $this->assertSame('user-api-1', $startEvent['principal']['id'] ?? null);
        $this->assertSame('auth:test-header', $startEvent['principal']['type'] ?? null);
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function principalHeaders(string $subject, string $role = 'operator', ?string $tenant = null, array $extra = []): array
    {
        $headers = [
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Control-Plane-Version' => '2',
            'X-Test-Subject' => $subject,
            'X-Test-Roles' => $role,
        ];

        if ($tenant !== null) {
            $headers['X-Test-Tenant'] = $tenant;
        }

        return array_merge($headers, $extra);
    }
}
