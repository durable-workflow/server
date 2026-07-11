<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\HeaderAuthProvider;
use Tests\Fixtures\InteractiveCommandWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;

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

    public function test_named_principal_tokens_preserve_actor_identity_across_rotation_and_spoof_attempts(): void
    {
        Queue::fake();

        config([
            'server.auth.provider' => null,
            'server.auth.driver' => 'token',
            'server.auth.token' => null,
            'server.auth.backward_compatible' => false,
            'server.auth.principal_tokens' => json_encode([
                [
                    'token' => 'alice-token-v1',
                    'subject' => 'alice',
                    'roles' => ['operator'],
                    'label' => 'Alice',
                ],
                [
                    'token' => 'alice-token-v2',
                    'subject' => 'alice',
                    'roles' => ['operator'],
                    'label' => 'Alice',
                ],
                [
                    'token' => 'bob-token',
                    'subject' => 'bob',
                    'roles' => ['operator'],
                    'label' => 'Bob',
                ],
            ]),
        ]);

        $start = $this->withHeaders($this->bearerHeaders('alice-token-v1'))
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-principal-token-map',
                'workflow_type' => 'tests.interactive-command-workflow',
                'principal' => 'mallory',
            ]);

        $start->assertCreated();

        $this->withHeaders($this->bearerHeaders('bob-token', [
            'X-Workflow-Principal-Id' => 'mallory',
            'X-Workflow-Principal-Type' => 'attacker',
        ]))->postJson('/api/workflows/wf-principal-token-map/signal/advance', [
            'input' => ['Ada'],
            'principal' => 'mallory',
        ])->assertAccepted();

        $this->withHeaders($this->bearerHeaders('alice-token-v2'))
            ->postJson('/api/workflows/wf-principal-token-map/cancel', [
                'reason' => 'credential rotated',
            ])->assertOk();

        $expected = [
            'start' => 'alice',
            'signal' => 'bob',
            'cancel' => 'alice',
        ];

        foreach ($expected as $type => $principalId) {
            $command = WorkflowCommand::query()
                ->where('workflow_instance_id', 'wf-principal-token-map')
                ->where('command_type', $type)
                ->latest('command_sequence')
                ->firstOrFail();

            $this->assertSame($principalId, $command->principalId());
            $this->assertSame('auth:token', $command->principalType());
            $this->assertNotSame('mallory', $command->principalId());
        }
    }

    public function test_update_control_plane_response_projects_the_authenticated_principal(): void
    {
        Queue::fake();

        config([
            'server.auth.provider' => null,
            'server.auth.driver' => 'token',
            'server.auth.token' => null,
            'server.auth.backward_compatible' => false,
            'server.auth.principal_tokens' => json_encode([
                [
                    'token' => 'alice-token',
                    'subject' => 'alice',
                    'roles' => ['operator'],
                    'label' => 'Alice',
                ],
            ]),
        ]);

        $start = $this->withHeaders($this->bearerHeaders('alice-token'))
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-principal-update-response',
                'workflow_type' => 'tests.interactive-command-workflow',
            ]);

        $start->assertCreated();
        $this->runReadyWorkflowTask((string) $start->json('run_id'));

        $response = $this->withHeaders($this->bearerHeaders('alice-token'))
            ->postJson('/api/workflows/wf-principal-update-response/update/approve', [
                'input' => [true, 'response-principal'],
                'request_id' => 'update-principal-response',
                'wait_for' => 'accepted',
                'principal_id' => 'mallory',
            ]);

        $response->assertAccepted()
            ->assertJsonPath('principal.type', 'auth:token')
            ->assertJsonPath('principal.id', 'alice')
            ->assertJsonPath('principal.label', 'Alice')
            ->assertJsonPath('control_plane.principal.type', 'auth:token')
            ->assertJsonPath('control_plane.principal.id', 'alice');
        $this->assertNotSame('mallory', $response->json('principal.id'));
    }

    public function test_run_detail_commands_surface_body_request_id_and_principal_for_update_paths(): void
    {
        Queue::fake();

        $start = $this->withHeaders($this->principalHeaders('user-run-detail-start', 'operator'))
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-principal-run-detail',
                'workflow_type' => 'tests.interactive-command-workflow',
            ]);

        $start->assertCreated();
        $runId = (string) $start->json('run_id');
        $this->runReadyWorkflowTask($runId);

        $accepted = $this->withHeaders($this->principalHeaders('user-run-detail-accepted', 'admin'))
            ->postJson('/api/workflows/wf-principal-run-detail/update/approve', [
                'input' => [true, 'run-detail'],
                'request_id' => 'run-detail-accepted',
                'wait_for' => 'accepted',
            ]);

        $accepted->assertAccepted();

        $unknown = $this->withHeaders($this->principalHeaders('user-run-detail-unknown', 'admin'))
            ->postJson('/api/workflows/wf-principal-run-detail/update/missing_update', [
                'input' => [],
                'request_id' => 'run-detail-unknown',
            ]);

        $unknown->assertNotFound()
            ->assertJsonPath('principal.id', 'user-run-detail-unknown');

        $invalid = $this->withHeaders($this->principalHeaders('user-run-detail-invalid', 'admin'))
            ->postJson('/api/workflows/wf-principal-run-detail/update/approve', [
                'input' => ['not-a-bool'],
                'request_id' => 'run-detail-invalid',
            ]);

        $invalid->assertUnprocessable()
            ->assertJsonPath('principal.id', 'user-run-detail-invalid');

        $detail = $this->withHeaders($this->principalHeaders('user-run-detail-viewer', 'operator'))
            ->getJson("/api/workflows/wf-principal-run-detail/runs/{$runId}");

        $detail->assertOk();
        $commands = collect($detail->json('commands'));

        $this->assertUpdateCommandPrincipal($commands->firstWhere('request_id', 'run-detail-accepted'), [
            'principal_id' => 'user-run-detail-accepted',
            'status' => 'accepted',
            'update_status' => 'accepted',
        ]);
        $this->assertUpdateCommandPrincipal($commands->firstWhere('request_id', 'run-detail-unknown'), [
            'principal_id' => 'user-run-detail-unknown',
            'status' => 'rejected',
            'rejection_reason' => 'unknown_update',
        ]);
        $this->assertUpdateCommandPrincipal($commands->firstWhere('request_id', 'run-detail-invalid'), [
            'principal_id' => 'user-run-detail-invalid',
            'status' => 'rejected',
            'rejection_reason' => 'invalid_update_arguments',
        ]);

        $terminalStart = $this->withHeaders($this->principalHeaders('user-run-detail-terminal-start', 'operator'))
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-principal-run-detail-terminal',
                'workflow_type' => 'tests.interactive-command-workflow',
            ]);

        $terminalStart->assertCreated();
        $terminalRunId = (string) $terminalStart->json('run_id');
        $this->runReadyWorkflowTask($terminalRunId);

        $this->withHeaders($this->principalHeaders('user-run-detail-terminal-signal', 'operator'))
            ->postJson('/api/workflows/wf-principal-run-detail-terminal/signal/advance', [
                'input' => ['Ada'],
            ])->assertAccepted();
        $this->runReadyWorkflowTask($terminalRunId);

        $this->withHeaders($this->principalHeaders('user-run-detail-terminal-finish', 'operator'))
            ->postJson('/api/workflows/wf-principal-run-detail-terminal/signal/finish')
            ->assertAccepted();
        $this->runReadyWorkflowTask($terminalRunId);

        $terminal = $this->withHeaders($this->principalHeaders('user-run-detail-terminal', 'admin'))
            ->postJson('/api/workflows/wf-principal-run-detail-terminal/update/approve', [
                'input' => [true, 'terminal'],
                'request_id' => 'run-detail-terminal',
            ]);

        $terminal->assertStatus(409)
            ->assertJsonPath('principal.id', 'user-run-detail-terminal');

        $terminalDetail = $this->withHeaders($this->principalHeaders('user-run-detail-viewer', 'operator'))
            ->getJson("/api/workflows/wf-principal-run-detail-terminal/runs/{$terminalRunId}");

        $terminalDetail->assertOk();
        $terminalCommands = collect($terminalDetail->json('commands'));

        $this->assertUpdateCommandPrincipal($terminalCommands->firstWhere('request_id', 'run-detail-terminal'), [
            'principal_id' => 'user-run-detail-terminal',
            'status' => 'rejected',
            'rejection_reason' => 'run_not_active',
        ]);
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

    public function test_worker_terminal_events_use_the_authenticated_worker_principal(): void
    {
        Queue::fake();

        config([
            'server.auth.provider' => null,
            'server.auth.driver' => 'token',
            'server.auth.token' => null,
            'server.auth.backward_compatible' => false,
            'server.auth.principal_tokens' => json_encode([
                [
                    'token' => 'operator-token',
                    'subject' => 'workflow-operator',
                    'roles' => ['operator'],
                    'label' => 'Workflow Operator',
                ],
                [
                    'token' => 'worker-token',
                    'subject' => 'worker:principal-attribution',
                    'roles' => ['worker'],
                    'label' => 'Worker',
                ],
            ]),
        ]);

        $cases = [
            [
                'workflow_id' => 'wf-worker-principal-completed',
                'event_type' => HistoryEventType::WorkflowCompleted,
                'command' => [
                    'type' => 'complete_workflow',
                    'result' => Serializer::serializeWithCodec(
                        (string) config('workflows.serializer'),
                        ['status' => 'completed'],
                    ),
                    'principal' => ['type' => 'attacker', 'id' => 'mallory'],
                ],
                'run_status' => 'completed',
            ],
            [
                'workflow_id' => 'wf-worker-principal-failed',
                'event_type' => HistoryEventType::WorkflowFailed,
                'command' => [
                    'type' => 'fail_workflow',
                    'message' => 'expected worker failure',
                    'principal_id' => 'mallory',
                    'principal_type' => 'attacker',
                ],
                'run_status' => 'failed',
            ],
        ];

        foreach ($cases as $case) {
            $start = $this->withHeaders($this->bearerHeaders('operator-token'))
                ->postJson('/api/workflows', [
                    'workflow_id' => $case['workflow_id'],
                    'workflow_type' => 'tests.interactive-command-workflow',
                ]);

            $start->assertCreated();
            $runId = (string) $start->json('run_id');

            /** @var WorkflowTask $task */
            $task = WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', 'workflow')
                ->where('status', 'ready')
                ->firstOrFail();

            $this->registerWorker(
                workerId: 'worker-principal-attribution',
                taskQueue: (string) $task->queue,
                supportedWorkflowTypes: ['tests.interactive-command-workflow'],
            );

            /** @var WorkflowTaskBridge $bridge */
            $bridge = app(WorkflowTaskBridge::class);
            $claim = $bridge->claim($task->id, 'worker-principal-attribution');
            $this->assertIsArray($claim);

            $task->refresh();

            $response = $this->withHeaders($this->workerPrincipalHeaders('worker-token', [
                'X-Workflow-Principal-Id' => 'mallory',
                'X-Workflow-Principal-Type' => 'attacker',
                'X-Forwarded-User' => 'mallory',
            ]))->postJson("/api/worker/workflow-tasks/{$task->id}/complete", [
                'lease_owner' => 'worker-principal-attribution',
                'workflow_task_attempt' => (int) $task->attempt_count,
                'principal' => ['type' => 'attacker', 'id' => 'mallory'],
                'principal_id' => 'mallory',
                'principal_type' => 'attacker',
                'commands' => [$case['command']],
            ]);

            $response->assertOk()
                ->assertJsonPath('recorded', true)
                ->assertJsonPath('run_status', $case['run_status']);

            $event = WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runId)
                ->where('event_type', $case['event_type']->value)
                ->firstOrFail();
            $principal = $event->payload['command'] ?? null;

            $this->assertIsArray($principal);
            $this->assertSame('auth:token', $principal['principal_type'] ?? null);
            $this->assertSame('worker:principal-attribution', $principal['principal_id'] ?? null);
            $this->assertSame('Worker', $principal['principal_label'] ?? null);
            $this->assertNotSame('mallory', $principal['principal_id'] ?? null);

            $history = $this->withHeaders($this->bearerHeaders('operator-token'))
                ->getJson("/api/workflows/{$case['workflow_id']}/runs/{$runId}/history");

            $history->assertOk();
            $terminalEvent = collect($history->json('events'))
                ->first(static fn (array $item): bool => ($item['event_type'] ?? null) === $case['event_type']->value);

            $this->assertIsArray($terminalEvent);
            $this->assertSame('auth:token', $terminalEvent['principal']['type'] ?? null);
            $this->assertSame('worker:principal-attribution', $terminalEvent['principal']['id'] ?? null);
            $this->assertSame('Worker', $terminalEvent['principal']['label'] ?? null);
        }
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

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function bearerHeaders(string $token, array $extra = []): array
    {
        return array_merge([
            'Authorization' => 'Bearer '.$token,
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ], $extra);
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function workerPrincipalHeaders(string $token, array $extra = []): array
    {
        return array_merge([
            'Authorization' => 'Bearer '.$token,
            'X-Namespace' => 'default',
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ], $extra);
    }

    /**
     * @param  array<string, string>  $expected
     */
    private function assertUpdateCommandPrincipal(mixed $command, array $expected): void
    {
        $this->assertIsArray($command);
        $this->assertSame('update', $command['type'] ?? null);
        $this->assertSame($expected['principal_id'], $command['principal_id'] ?? null);
        $this->assertSame('auth:test-header', $command['principal_type'] ?? null);
        $this->assertSame($expected['principal_id'], $command['principal']['id'] ?? null);
        $this->assertSame('auth:test-header', $command['principal']['type'] ?? null);

        if (isset($expected['status'])) {
            $this->assertSame($expected['status'], $command['status'] ?? null);
        }

        if (isset($expected['update_status'])) {
            $this->assertSame($expected['update_status'], $command['update_status'] ?? null);
        }

        if (isset($expected['rejection_reason'])) {
            $this->assertSame($expected['rejection_reason'], $command['rejection_reason'] ?? null);
        }
    }
}
