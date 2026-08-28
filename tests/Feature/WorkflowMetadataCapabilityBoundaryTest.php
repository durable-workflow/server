<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Support\WorkerProtocol;
use App\Support\WorkflowMetadataCapabilityPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\Support\OpenApiSchema;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\MemoPayload;

class WorkflowMetadataCapabilityBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['server.polling.timeout' => 0]);
        $this->createNamespace('default');
        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);
    }

    /**
     * @return array<string, array{capability: string, protocolVersion: string, minimum: string}>
     */
    public static function capabilityFloorProvider(): array
    {
        return [
            'memo upserts' => [
                'capability' => WorkflowMetadataCapabilityPolicy::MEMO_UPSERTS,
                'protocolVersion' => '1.13',
                'minimum' => WorkflowMetadataCapabilityPolicy::MEMO_UPSERTS_MINIMUM_PROTOCOL_VERSION,
            ],
            'typed search attributes' => [
                'capability' => WorkflowMetadataCapabilityPolicy::TYPED_SEARCH_ATTRIBUTES,
                'protocolVersion' => '1.15',
                'minimum' => WorkflowMetadataCapabilityPolicy::TYPED_SEARCH_ATTRIBUTES_MINIMUM_PROTOCOL_VERSION,
            ],
        ];
    }

    /**
     * @return array<string, array{capability: string}>
     */
    public static function metadataCapabilityProvider(): array
    {
        return [
            'memo upserts' => [
                'capability' => WorkflowMetadataCapabilityPolicy::MEMO_UPSERTS,
            ],
            'typed search attributes' => [
                'capability' => WorkflowMetadataCapabilityPolicy::TYPED_SEARCH_ATTRIBUTES,
            ],
        ];
    }

    #[DataProvider('capabilityFloorProvider')]
    public function test_registration_rejects_metadata_capabilities_below_their_protocol_floor(
        string $capability,
        string $protocolVersion,
        string $minimum,
    ): void {
        $response = $this->registerThroughProtocol(
            workerId: 'worker-below-floor-'.$capability,
            runtime: 'php',
            capabilities: [$capability],
            protocolVersion: $protocolVersion,
        );

        $response->assertStatus(409)
            ->assertJsonPath('registered', false)
            ->assertJsonPath('reason', 'workflow_metadata_capability_protocol_mismatch')
            ->assertJsonPath('capability', $capability)
            ->assertJsonPath('requested_version', $protocolVersion)
            ->assertJsonPath('minimum_protocol_version', $minimum);

        $this->assertDatabaseMissing('workflow_worker_registrations', [
            'worker_id' => 'worker-below-floor-'.$capability,
        ]);
        $this->openApi()->assertReferenceMatches(
            '#/components/schemas/WorkflowMetadataCapabilityFailure',
            json_decode((string) $response->getContent(), flags: JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, array{runtime: string}>
     */
    public static function capableRuntimeProvider(): array
    {
        return [
            'PHP worker' => ['runtime' => 'php'],
            'Python worker' => ['runtime' => 'python'],
            'Rust worker' => ['runtime' => 'rust'],
        ];
    }

    #[DataProvider('capableRuntimeProvider')]
    public function test_current_polyglot_workers_can_register_both_metadata_capabilities(string $runtime): void
    {
        $capabilities = array_keys(WorkflowMetadataCapabilityPolicy::definitions());
        $response = $this->registerThroughProtocol(
            workerId: 'worker-current-'.$runtime,
            runtime: $runtime,
            capabilities: $capabilities,
        );

        $response->assertCreated()
            ->assertJsonPath('registered', true)
            ->assertJsonPath('runtime', $runtime)
            ->assertJsonPath('capabilities', $capabilities);

        $this->assertSame(
            $capabilities,
            WorkerRegistration::query()
                ->where('worker_id', 'worker-current-'.$runtime)
                ->firstOrFail()
                ->capabilities,
        );
    }

    /**
     * @return array<string, array{capability: string, command: array<string, mixed>}>
     */
    public static function metadataCommandProvider(): array
    {
        return [
            'memo upsert' => [
                'capability' => WorkflowMetadataCapabilityPolicy::MEMO_UPSERTS,
                'command' => [
                    'type' => 'upsert_memo',
                    'entries' => ['codec' => 'avro', 'blob' => 'placeholder'],
                ],
            ],
            'explicit typed search-attribute upsert' => [
                'capability' => WorkflowMetadataCapabilityPolicy::TYPED_SEARCH_ATTRIBUTES,
                'command' => [
                    'type' => 'upsert_search_attributes',
                    'attributes' => ['priority' => 3],
                    'attribute_types' => ['priority' => 'int'],
                ],
            ],
        ];
    }

    #[DataProvider('metadataCommandProvider')]
    public function test_completion_rejects_unadvertised_metadata_commands_without_mutation(
        string $capability,
        array $command,
    ): void {
        $run = $this->startWorkflow('wf-completion-'.$capability, 'completion-queue');
        $this->registerThroughProtocol('worker-completion-'.$capability, 'php', [])->assertCreated();
        $poll = $this->poll('worker-completion-'.$capability, 'completion-queue');
        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $beforeHistoryCount = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->count();
        $beforeRun = $run->only(['status', 'memo', 'search_attributes', 'last_history_sequence']);

        $response = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-completion-'.$capability,
            'workflow_task_attempt' => $attempt,
            'commands' => [$command],
        ], $this->workerHeaders());

        $response->assertStatus(409)
            ->assertJsonPath('outcome', 'rejected')
            ->assertJsonPath('recorded', false)
            ->assertJsonPath('reason', 'workflow_metadata_capability_not_advertised')
            ->assertJsonPath('capability', $capability);
        $this->openApi()->assertReferenceMatches(
            '#/components/schemas/WorkflowMetadataCapabilityFailure',
            json_decode((string) $response->getContent(), flags: JSON_THROW_ON_ERROR),
        );

        $task = WorkflowTask::query()->findOrFail($taskId);
        $run->refresh();
        $this->assertSame(TaskStatus::Leased, $task->status);
        $this->assertSame('worker-completion-'.$capability, $task->lease_owner);
        $this->assertSame($beforeHistoryCount, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->count());
        $this->assertSame($beforeRun, $run->only(['status', 'memo', 'search_attributes', 'last_history_sequence']));
    }

    #[DataProvider('capabilityFloorProvider')]
    public function test_mixed_fleet_routes_required_history_only_to_capable_workers(
        string $capability,
        string $protocolVersion,
        string $minimum,
    ): void {
        $blockedRun = $this->startWorkflow('wf-blocked-'.$capability, 'mixed-queue');
        $this->recordCapabilityHistory($blockedRun, $capability);
        $safeRun = $this->startWorkflow('wf-safe-'.$capability, 'mixed-queue');
        $this->registerThroughProtocol('worker-legacy-'.$capability, 'php', [], $protocolVersion)->assertCreated();

        $legacyPoll = $this->poll('worker-legacy-'.$capability, 'mixed-queue', $protocolVersion);
        $legacyPoll->assertOk()
            ->assertJsonPath('task.run_id', $safeRun->id);
        $this->assertSame(TaskStatus::Ready, WorkflowTask::query()
            ->where('workflow_run_id', $blockedRun->id)
            ->sole()
            ->status);

        $this->registerThroughProtocol('worker-capable-'.$capability, 'rust', [$capability], $minimum)
            ->assertCreated();
        $capablePoll = $this->poll('worker-capable-'.$capability, 'mixed-queue', $minimum);
        $capablePoll->assertOk()
            ->assertJsonPath('task.run_id', $blockedRun->id);
        $this->openApi()->assertReferenceMatches(
            '#/components/schemas/WorkflowTaskPollResponse',
            json_decode((string) $capablePoll->getContent(), flags: JSON_THROW_ON_ERROR),
        );
    }

    #[DataProvider('metadataCapabilityProvider')]
    public function test_unadvertised_worker_can_lease_safe_history_beyond_a_full_incompatible_candidate_window(
        string $capability,
    ): void {
        config(['server.polling.max_tasks_per_poll' => 1]);

        $blockedRuns = collect(range(1, 11))->map(function (int $index) use ($capability): WorkflowRun {
            $run = $this->startWorkflow("wf-window-blocked-{$capability}-{$index}", 'mixed-queue');
            $this->recordCapabilityHistory($run, $capability);

            return $run;
        });
        $safeRun = $this->startWorkflow('wf-window-safe-'.$capability, 'mixed-queue');
        $this->registerThroughProtocol('worker-legacy-'.$capability.'-window', 'php', [])
            ->assertCreated();

        $this->poll('worker-legacy-'.$capability.'-window', 'mixed-queue')
            ->assertOk()
            ->assertJsonPath('task.run_id', $safeRun->id);

        $this->assertSame(11, WorkflowTask::query()
            ->whereIn('workflow_run_id', $blockedRuns->pluck('id'))
            ->where('status', TaskStatus::Ready->value)
            ->count());
    }

    public function test_protocol_1_16_worker_can_lease_safe_history_beyond_a_full_occurrence_identity_window(): void
    {
        config(['server.polling.max_tasks_per_poll' => 1]);

        $blockedRuns = collect(range(1, 11))->map(function (int $index): WorkflowRun {
            $run = $this->startWorkflow("wf-condition-window-blocked-{$index}", 'condition-replay-queue');
            $this->recordConditionWaitOccurrenceHistory($run);

            return $run;
        });
        $safeRun = $this->startWorkflow('wf-condition-window-safe', 'condition-replay-queue');
        $this->registerThroughProtocol('worker-condition-legacy-window', 'php', [], '1.16')
            ->assertCreated();

        $this->poll('worker-condition-legacy-window', 'condition-replay-queue', '1.16')
            ->assertOk()
            ->assertJsonPath('task.run_id', $safeRun->id);

        $this->assertSame(11, WorkflowTask::query()
            ->whereIn('workflow_run_id', $blockedRuns->pluck('id'))
            ->where('status', TaskStatus::Ready->value)
            ->count());
    }

    public function test_advertised_capability_cannot_bypass_the_poll_protocol_floor(): void
    {
        $run = $this->startWorkflow('wf-poll-protocol-floor', 'protocol-floor-queue');
        $this->recordCapabilityHistory($run, WorkflowMetadataCapabilityPolicy::MEMO_UPSERTS);
        $this->registerThroughProtocol(
            'worker-protocol-floor',
            'python',
            [WorkflowMetadataCapabilityPolicy::MEMO_UPSERTS],
        )->assertCreated();

        $this->poll('worker-protocol-floor', 'protocol-floor-queue', '1.13')
            ->assertOk()
            ->assertJsonPath('task', null);
        $this->poll(
            'worker-protocol-floor',
            'protocol-floor-queue',
            WorkflowMetadataCapabilityPolicy::MEMO_UPSERTS_MINIMUM_PROTOCOL_VERSION,
        )->assertOk()
            ->assertJsonPath('task.run_id', $run->id);
    }

    public function test_reregistration_without_capability_invalidates_cached_poll_and_allows_redelivery(): void
    {
        $run = $this->startWorkflow('wf-cached-capability', 'cached-capability-queue');
        $this->recordCapabilityHistory($run, WorkflowMetadataCapabilityPolicy::MEMO_UPSERTS);
        $this->registerThroughProtocol(
            'worker-cached-capability',
            'php',
            [WorkflowMetadataCapabilityPolicy::MEMO_UPSERTS],
        )->assertCreated();

        $first = $this->poll(
            'worker-cached-capability',
            'cached-capability-queue',
            WorkerProtocol::VERSION,
            'cached-capability-poll',
        );
        $first->assertJsonPath('task.run_id', $run->id);

        $this->registerThroughProtocol('worker-cached-capability', 'php', [])->assertCreated();
        $this->assertSame(TaskStatus::Ready, WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->sole()
            ->status);
        $this->poll(
            'worker-cached-capability',
            'cached-capability-queue',
            WorkerProtocol::VERSION,
            'cached-capability-poll',
        )->assertOk()
            ->assertJsonPath('task', null);

        $this->registerThroughProtocol(
            'worker-redelivery-capability',
            'rust',
            [WorkflowMetadataCapabilityPolicy::MEMO_UPSERTS],
        )->assertCreated();
        $this->poll('worker-redelivery-capability', 'cached-capability-queue')
            ->assertJsonPath('task.run_id', $run->id);
    }

    public function test_legacy_untyped_search_attribute_history_remains_replayable(): void
    {
        $run = $this->startWorkflow('wf-legacy-search-history', 'legacy-search-queue');
        WorkflowHistoryEvent::record($run, HistoryEventType::SearchAttributesUpserted, [
            'attributes' => ['tenant' => 'acme'],
            'merged' => ['tenant' => 'acme'],
        ]);
        $this->registerThroughProtocol('worker-legacy-search-history', 'php', [])->assertCreated();

        $this->poll('worker-legacy-search-history', 'legacy-search-queue')
            ->assertOk()
            ->assertJsonPath('task.run_id', $run->id);
    }

    public function test_protocol_1_16_worker_remains_accepted_for_unaffected_history(): void
    {
        $run = $this->startWorkflow('wf-condition-safe-legacy', 'condition-safe-queue');
        $this->registerThroughProtocol('worker-condition-safe-legacy', 'php', [], '1.16')
            ->assertCreated();

        $this->poll('worker-condition-safe-legacy', 'condition-safe-queue', '1.16')
            ->assertOk()
            ->assertJsonPath('task.run_id', $run->id);
    }

    public function test_server_protocol_1_16_rejects_ahead_1_17_worker(): void
    {
        config(['server.worker_protocol.version' => '1.16']);

        $this->registerThroughProtocol('worker-ahead-condition', 'rust', [], '1.17')
            ->assertStatus(400)
            ->assertJsonPath('registered', null)
            ->assertJsonPath('reason', 'unsupported_protocol_version')
            ->assertJsonPath('supported_version', '1.16')
            ->assertJsonPath('requested_version', '1.17');

        $this->assertDatabaseMissing('workflow_worker_registrations', [
            'worker_id' => 'worker-ahead-condition',
        ]);
    }

    public function test_protocol_1_16_completion_cannot_author_condition_wait_occurrence_identity(): void
    {
        $run = $this->startWorkflow('wf-condition-author-floor', 'condition-author-queue');
        $this->registerThroughProtocol('worker-condition-author-floor', 'php', [], '1.16')
            ->assertCreated();
        $poll = $this->poll('worker-condition-author-floor', 'condition-author-queue', '1.16');
        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $beforeHistoryCount = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->count();
        $beforeRun = $run->only(['status', 'last_history_sequence']);

        $response = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-condition-author-floor',
            'workflow_task_attempt' => $attempt,
            'commands' => [[
                'type' => 'open_condition_wait',
                'condition_wait_occurrence_id' => 'condition-occurrence-below-floor',
                'condition_key' => 'approval.ready',
                'condition_definition_fingerprint' => 'approval-ready-v1',
                'timeout_seconds' => 60,
            ]],
        ], $this->workerHeadersForVersion('1.16'));

        $response->assertStatus(409)
            ->assertJsonPath('outcome', 'rejected')
            ->assertJsonPath('recorded', false)
            ->assertJsonPath('reason', 'condition_wait_occurrence_identity_unavailable')
            ->assertJsonPath('requested_version', '1.16')
            ->assertJsonPath('minimum_protocol_version', '1.17')
            ->assertJsonPath('command_field', 'condition_wait_occurrence_id');
        $this->openApi()->assertReferenceMatches(
            '#/components/schemas/ConditionWaitOccurrenceIdentityFailure',
            json_decode((string) $response->getContent(), flags: JSON_THROW_ON_ERROR),
        );

        $task = WorkflowTask::query()->findOrFail($taskId);
        $run->refresh();
        $this->assertSame(TaskStatus::Leased, $task->status);
        $this->assertSame($beforeHistoryCount, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->count());
        $this->assertSame($beforeRun, $run->only(['status', 'last_history_sequence']));
    }

    public function test_condition_wait_occurrence_history_routes_only_to_protocol_1_17_workers(): void
    {
        $blockedRun = $this->startWorkflow('wf-condition-cold-replay', 'condition-replay-queue');
        $this->recordConditionWaitOccurrenceHistory($blockedRun);
        $safeRun = $this->startWorkflow('wf-condition-unaffected', 'condition-replay-queue');
        $this->registerThroughProtocol('worker-condition-legacy', 'php', [], '1.16')->assertCreated();

        $this->poll('worker-condition-legacy', 'condition-replay-queue', '1.16')
            ->assertOk()
            ->assertJsonPath('task.run_id', $safeRun->id);
        $this->assertSame(TaskStatus::Ready, WorkflowTask::query()
            ->where('workflow_run_id', $blockedRun->id)
            ->sole()
            ->status);

        $this->registerThroughProtocol('worker-condition-current', 'rust', [], '1.17')->assertCreated();
        $currentPoll = $this->poll('worker-condition-current', 'condition-replay-queue', '1.17');
        $currentPoll->assertOk()
            ->assertJsonPath('task.run_id', $blockedRun->id);
        $opened = collect((array) $currentPoll->json('task.history_events'))
            ->firstWhere('event_type', HistoryEventType::ConditionWaitOpened->value);
        $this->assertIsArray($opened);
        $this->assertSame(
            'condition-occurrence-history-1',
            $opened['payload']['condition_wait_occurrence_id'] ?? null,
        );
    }

    public function test_cached_poll_and_redelivery_recheck_condition_wait_occurrence_protocol(): void
    {
        $run = $this->startWorkflow('wf-condition-cached-replay', 'condition-cached-queue');
        $this->recordConditionWaitOccurrenceHistory($run);
        $this->registerThroughProtocol('worker-condition-cached', 'python', [], '1.17')->assertCreated();

        $this->poll(
            'worker-condition-cached',
            'condition-cached-queue',
            '1.17',
            'condition-cached-poll',
        )->assertJsonPath('task.run_id', $run->id);
        $this->poll(
            'worker-condition-cached',
            'condition-cached-queue',
            '1.16',
            'condition-cached-poll',
        )->assertOk()->assertJsonPath('task', null);

        $this->registerThroughProtocol('worker-condition-cached', 'python', [], '1.16')->assertCreated();
        $this->poll('worker-condition-cached', 'condition-cached-queue', '1.16')
            ->assertOk()
            ->assertJsonPath('task', null);

        $this->registerThroughProtocol('worker-condition-redelivery', 'rust', [], '1.17')->assertCreated();
        $this->poll('worker-condition-redelivery', 'condition-cached-queue', '1.17')
            ->assertOk()
            ->assertJsonPath('task.run_id', $run->id);
    }

    public function test_registration_poll_completion_and_error_envelopes_match_openapi(): void
    {
        $registration = $this->registerThroughProtocol(
            'worker-envelope-capable',
            'php',
            [WorkflowMetadataCapabilityPolicy::MEMO_UPSERTS],
        )->assertCreated();
        $this->openApi()->assertReferenceMatches(
            '#/components/schemas/WorkerRegistrationEnvelope',
            json_decode((string) $registration->getContent(), flags: JSON_THROW_ON_ERROR),
        );

        $run = $this->startWorkflow('wf-envelope-capability', 'envelope-capability-queue');
        $poll = $this->poll('worker-envelope-capable', 'envelope-capability-queue');
        $this->openApi()->assertReferenceMatches(
            '#/components/schemas/WorkflowTaskPollResponse',
            json_decode((string) $poll->getContent(), flags: JSON_THROW_ON_ERROR),
        );
        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $completion = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-envelope-capable',
            'workflow_task_attempt' => $attempt,
            'commands' => [[
                'type' => 'upsert_memo',
                'entries' => MemoPayload::mapEnvelope(['phase' => 'complete']),
            ]],
        ], $this->workerHeaders())->assertOk();
        $this->openApi()->assertReferenceMatches(
            '#/components/schemas/WorkflowTaskCompletionResult',
            json_decode((string) $completion->getContent(), flags: JSON_THROW_ON_ERROR),
        );
        $this->assertSame($run->id, $completion->json('run_id'));
    }

    private function registerThroughProtocol(
        string $workerId,
        string $runtime,
        array $capabilities,
        string $protocolVersion = WorkerProtocol::VERSION,
    ): TestResponse {
        return $this->postJson('/api/worker/register', [
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'worker_id' => $workerId,
            'task_queue' => str_contains($workerId, 'completion-')
                ? 'completion-queue'
                : $this->taskQueueForWorker($workerId),
            'runtime' => $runtime,
            'sdk_version' => '2.0.0-current',
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'capabilities' => $capabilities,
        ], $this->workerHeadersForVersion($protocolVersion));
    }

    private function poll(
        string $workerId,
        string $taskQueue,
        string $protocolVersion = WorkerProtocol::VERSION,
        ?string $pollRequestId = null,
    ): TestResponse {
        return $this->postJson('/api/worker/workflow-tasks/poll', array_filter([
            'worker_id' => $workerId,
            'task_queue' => $taskQueue,
            'poll_request_id' => $pollRequestId,
            'timeout_seconds' => 0,
        ], static fn (mixed $value): bool => $value !== null), $this->workerHeadersForVersion($protocolVersion));
    }

    private function startWorkflow(string $workflowId, string $taskQueue): WorkflowRun
    {
        $response = $this->postJson('/api/workflows', [
            'workflow_id' => $workflowId,
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => $taskQueue,
            'input' => ['Ada'],
        ], $this->apiHeaders())->assertCreated();

        return WorkflowRun::query()->findOrFail((string) $response->json('run_id'));
    }

    private function recordCapabilityHistory(WorkflowRun $run, string $capability): void
    {
        if ($capability === WorkflowMetadataCapabilityPolicy::MEMO_UPSERTS) {
            WorkflowHistoryEvent::record($run, HistoryEventType::MemoUpserted, [
                'entries' => MemoPayload::mapEnvelope(['phase' => 'replay']),
                'merged' => MemoPayload::mapEnvelope(['phase' => 'replay']),
            ]);

            return;
        }

        WorkflowHistoryEvent::record($run, HistoryEventType::SearchAttributesUpserted, [
            'attributes' => ['priority' => 3],
            'merged' => ['priority' => 3],
            'attribute_types' => ['priority' => 'int'],
        ]);
    }

    private function recordConditionWaitOccurrenceHistory(WorkflowRun $run): void
    {
        WorkflowHistoryEvent::record($run, HistoryEventType::ConditionWaitOpened, [
            'condition_wait_id' => 'condition-wait-history-1',
            'condition_wait_occurrence_id' => 'condition-occurrence-history-1',
            'condition_key' => 'approval.ready',
            'condition_definition_fingerprint' => 'approval-ready-v1',
            'timeout_seconds' => 60,
        ]);
    }

    private function taskQueueForWorker(string $workerId): string
    {
        return match (true) {
            str_contains($workerId, 'mixed'),
            str_contains($workerId, 'legacy-memo_upserts'),
            str_contains($workerId, 'legacy-typed_search_attributes'),
            str_contains($workerId, 'capable-memo_upserts'),
            str_contains($workerId, 'capable-typed_search_attributes') => 'mixed-queue',
            str_contains($workerId, 'protocol-floor') => 'protocol-floor-queue',
            str_contains($workerId, 'cached-capability'),
            str_contains($workerId, 'redelivery-capability') => 'cached-capability-queue',
            str_contains($workerId, 'legacy-search-history') => 'legacy-search-queue',
            str_contains($workerId, 'condition-safe') => 'condition-safe-queue',
            str_contains($workerId, 'condition-author') => 'condition-author-queue',
            str_contains($workerId, 'condition-legacy'),
            str_contains($workerId, 'condition-current') => 'condition-replay-queue',
            str_contains($workerId, 'condition-cached'),
            str_contains($workerId, 'condition-redelivery') => 'condition-cached-queue',
            str_contains($workerId, 'envelope-capable') => 'envelope-capability-queue',
            default => 'registration-queue',
        };
    }

    /**
     * @return array<string, string>
     */
    private function workerHeadersForVersion(string $protocolVersion): array
    {
        return [
            'X-Namespace' => 'default',
            WorkerProtocol::HEADER => $protocolVersion,
        ];
    }

    private function openApi(): OpenApiSchema
    {
        return OpenApiSchema::fromFile(
            dirname(__DIR__, 2).'/resources/platform-protocol-specs/worker-protocol-api.openapi.yaml',
        );
    }
}
