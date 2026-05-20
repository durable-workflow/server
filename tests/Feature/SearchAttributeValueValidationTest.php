<?php

namespace Tests\Feature;

use App\Models\SearchAttributeDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;

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
