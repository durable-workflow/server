<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\WorkerController;
use App\Models\SearchAttributeDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowSearchAttribute;

class SearchAttributeValueValidationTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);
    }

    public function test_workflow_start_rejects_unregistered_search_attribute(): void
    {
        Queue::fake();

        SearchAttributeDefinition::create([
            'namespace' => 'default',
            'name' => 'known_key',
            'type' => 'keyword',
        ]);

        $response = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-search-attr-unknown-start',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'search-attr-queue',
            'input' => ['Ada'],
            'search_attributes' => [
                'unknown_key' => 'x',
            ],
        ], $this->apiHeaders());

        $response->assertStatus(422)
            ->assertJsonPath('validation_errors.search_attributes.0', 'Search attribute [unknown_key] is not registered for this namespace.');
    }

    public function test_worker_search_attribute_update_accepts_registered_keyword_list_value(): void
    {
        Queue::fake();

        SearchAttributeDefinition::create([
            'namespace' => 'default',
            'name' => 'Tags',
            'type' => 'keyword_list',
        ]);

        [$workflowId, $runId, $taskId, $attempt] = $this->startAndPollWorkflowTask(
            'wf-search-attr-list-update',
        );

        $complete = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-search-attrs',
            'workflow_task_attempt' => $attempt,
            'commands' => [
                [
                    'type' => 'upsert_search_attributes',
                    'attributes' => [
                        'Tags' => ['alpha', 'beta'],
                    ],
                ],
                [
                    'type' => 'complete_workflow',
                    'result' => Serializer::serializeWithCodec((string) config('workflows.serializer'), [
                        'ok' => true,
                    ]),
                ],
            ],
        ], $this->workerHeaders());

        $complete->assertOk()
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('run_status', 'completed');

        $this->getJson("/api/workflows/{$workflowId}/runs/{$runId}", $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('search_attributes.Tags', ['alpha', 'beta']);
    }

    public function test_worker_search_attribute_update_rejects_registered_type_mismatch(): void
    {
        Queue::fake();

        SearchAttributeDefinition::create([
            'namespace' => 'default',
            'name' => 'CustomerAge',
            'type' => 'int',
        ]);

        [, , $taskId, $attempt] = $this->startAndPollWorkflowTask(
            'wf-search-attr-int-update',
        );

        $response = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-search-attrs',
            'workflow_task_attempt' => $attempt,
            'commands' => [
                [
                    'type' => 'upsert_search_attributes',
                    'attributes' => [
                        'CustomerAge' => 'not-an-int',
                    ],
                ],
            ],
        ], $this->workerHeaders());

        $response->assertStatus(422)
            ->assertJsonPath('reason', 'validation_failed');

        $message = $response->json('validation_errors')['commands.0.attributes'][0] ?? null;

        $this->assertIsString($message);
        $this->assertStringContainsString('CustomerAge', $message);
        $this->assertStringContainsString('registered as int', $message);
    }

    public function test_worker_search_attribute_update_rejects_unregistered_key(): void
    {
        Queue::fake();

        SearchAttributeDefinition::create([
            'namespace' => 'default',
            'name' => 'known_key',
            'type' => 'keyword',
        ]);

        [, , $taskId, $attempt] = $this->startAndPollWorkflowTask(
            'wf-search-attr-unknown-update',
        );

        $response = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-search-attrs',
            'workflow_task_attempt' => $attempt,
            'commands' => [
                [
                    'type' => 'upsert_search_attributes',
                    'attributes' => [
                        'unknown_key' => 'x',
                    ],
                ],
            ],
        ], $this->workerHeaders());

        $response->assertStatus(422)
            ->assertJsonPath('reason', 'validation_failed');

        $message = $response->json('validation_errors')['commands.0.attributes'][0] ?? null;

        $this->assertSame('Search attribute [unknown_key] is not registered for this namespace.', $message);
    }

    public function test_worker_search_attribute_update_persists_an_explicit_canonical_type(): void
    {
        Queue::fake();

        [, $runId, $taskId, $attempt] = $this->startAndPollWorkflowTask(
            'wf-search-attr-explicit-type',
        );

        $response = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-search-attrs',
            'workflow_task_attempt' => $attempt,
            'commands' => [[
                'type' => 'upsert_search_attributes',
                'attributes' => [
                    'CustomerTier' => 'gold',
                    'AttemptCount' => 3,
                ],
                'attribute_types' => [
                    'CustomerTier' => 'string',
                    'AttemptCount' => 'int',
                ],
            ]],
        ], $this->workerHeaders());

        $response->assertOk()
            ->assertJsonPath('outcome', 'completed');
        $this->assertSame(
            'string',
            WorkflowSearchAttribute::query()
                ->where('workflow_run_id', $runId)
                ->where('key', 'CustomerTier')
                ->value('type'),
        );
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::SearchAttributesUpserted->value)
            ->firstOrFail();
        $this->assertSame(
            [
                'AttemptCount' => 'int',
                'CustomerTier' => 'string',
            ],
            $event->payload['attribute_types'] ?? null,
        );
    }

    public function test_persisted_search_attribute_map_identity_ignores_key_order_but_preserves_value_identity(): void
    {
        $method = new \ReflectionMethod(WorkerController::class, 'searchAttributeMapsMatch');

        $this->assertTrue($method->invoke(null, [
            'tags' => ['php', 'upserted'],
            'priority_tier' => 'platinum',
        ], [
            'priority_tier' => 'platinum',
            'tags' => ['php', 'upserted'],
        ]));
        $this->assertFalse($method->invoke(null, ['value' => 7], ['value' => 7.0]));
        $this->assertFalse($method->invoke(
            null,
            ['tags' => ['php', 'upserted']],
            ['tags' => ['upserted', 'php']],
        ));
    }

    public function test_worker_search_attribute_update_rejects_unknown_declared_type(): void
    {
        Queue::fake();

        [, , $taskId, $attempt] = $this->startAndPollWorkflowTask(
            'wf-search-attr-unknown-type',
        );

        $response = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-search-attrs',
            'workflow_task_attempt' => $attempt,
            'commands' => [[
                'type' => 'upsert_search_attributes',
                'attributes' => ['CustomerTier' => 'gold'],
                'attribute_types' => ['CustomerTier' => 'opaque'],
            ]],
        ], $this->workerHeaders());

        $response->assertStatus(422)
            ->assertJsonPath('reason', 'validation_failed');
        $this->assertStringContainsString(
            'declares unsupported type [opaque]',
            (string) ($response->json('validation_errors')['commands.0.attributes'][0] ?? ''),
        );
    }

    public function test_worker_search_attribute_update_rejects_registered_type_conflict(): void
    {
        Queue::fake();
        SearchAttributeDefinition::create([
            'namespace' => 'default',
            'name' => 'CustomerTier',
            'type' => 'keyword',
        ]);
        [, , $taskId, $attempt] = $this->startAndPollWorkflowTask(
            'wf-search-attr-type-conflict',
        );

        $response = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-search-attrs',
            'workflow_task_attempt' => $attempt,
            'commands' => [[
                'type' => 'upsert_search_attributes',
                'attributes' => ['CustomerTier' => 'gold'],
                'attribute_types' => ['CustomerTier' => 'string'],
            ]],
        ], $this->workerHeaders());

        $response->assertStatus(422)
            ->assertJsonPath('reason', 'validation_failed');
        $this->assertSame(
            'Search attribute [CustomerTier] declares type [string] but is registered as [keyword].',
            $response->json('validation_errors')['commands.0.attributes'][0] ?? null,
        );
    }

    public function test_old_protocol_completion_cannot_submit_typed_search_attributes(): void
    {
        Queue::fake();
        [, , $taskId, $attempt] = $this->startAndPollWorkflowTask(
            'wf-search-attr-old-protocol',
        );

        $response = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-search-attrs',
            'workflow_task_attempt' => $attempt,
            'commands' => [[
                'type' => 'upsert_search_attributes',
                'attributes' => ['CustomerTier' => 'gold'],
                'attribute_types' => ['CustomerTier' => 'keyword'],
            ]],
        ], [
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Protocol-Version' => '1.15',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('reason', 'typed_search_attributes_unavailable')
            ->assertJsonPath('recorded', false)
            ->assertJsonPath('minimum_protocol_version', '1.16');
    }

    public function test_new_typed_worker_is_rejected_by_an_old_server_node(): void
    {
        Queue::fake();
        [, , $taskId, $attempt] = $this->startAndPollWorkflowTask(
            'wf-search-attr-old-server-node',
        );
        config(['server.worker_protocol.version' => '1.15']);

        $response = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-search-attrs',
            'workflow_task_attempt' => $attempt,
            'commands' => [[
                'type' => 'upsert_search_attributes',
                'attributes' => ['CustomerTier' => 'gold'],
                'attribute_types' => ['CustomerTier' => 'keyword'],
            ]],
        ], [
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Protocol-Version' => '1.16',
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('reason', 'unsupported_protocol_version')
            ->assertJsonPath('supported_version', '1.15')
            ->assertJsonPath('requested_version', '1.16');
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: int}
     */
    private function startAndPollWorkflowTask(string $workflowId): array
    {
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => $workflowId,
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'search-attr-queue',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $this->registerWorker(
            workerId: 'worker-search-attrs',
            taskQueue: 'search-attr-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-search-attrs',
            'task_queue' => 'search-attr-queue',
        ], $this->workerHeaders());

        $poll->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId);

        return [
            (string) $start->json('workflow_id'),
            (string) $start->json('run_id'),
            (string) $poll->json('task.task_id'),
            (int) $poll->json('task.workflow_task_attempt'),
        ];
    }
}
