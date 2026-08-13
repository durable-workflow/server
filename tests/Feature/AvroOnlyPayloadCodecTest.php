<?php

namespace Tests\Feature;

use App\Support\PayloadCodecDeploymentPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

class AvroOnlyPayloadCodecTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        config(['server.polling.timeout' => 0]);
        $this->createNamespace('default');
    }

    public function test_json_tagged_workflow_start_fails_closed_with_actionable_diagnostic(): void
    {
        $response = $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'json-codec-must-fail',
            'workflow_type' => 'tests.external-greeting-workflow',
            'input' => ['codec' => 'json', 'blob' => '["Ada"]'],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('unsupported_payload_codec:', $response->getContent());
        $this->assertStringContainsString('avro', $response->getContent());
        $this->assertStringContainsString('HTTP document transport', $response->getContent());
    }

    public function test_json_top_level_payload_codec_is_rejected_before_a_workflow_run_is_created(): void
    {
        $response = $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'json-top-level-codec-must-fail',
            'workflow_type' => 'tests.external-greeting-workflow',
            'input' => ['Ada'],
            'payload_codec' => 'json',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('payload_codec');
        $this->assertStringContainsString('unsupported_payload_codec:', $response->getContent());
        $this->assertStringContainsString('use codec=\"avro\"', $response->getContent());
        $this->assertSame(0, WorkflowRun::query()->count());
    }

    public function test_json_tagged_persisted_task_is_rejected_before_worker_delivery(): void
    {
        Queue::fake();
        $run = $this->startRemoteWorkflow('json-codec-worker-boundary');
        $run->forceFill(['payload_codec' => 'json', 'arguments' => '["Ada"]'])->save();

        $this->registerWorker(
            'avro-worker',
            'python-workflows',
            supportedWorkflowTypes: ['python.codec-workflow'],
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'avro-worker',
                'task_queue' => 'python-workflows',
            ])
            ->assertStatus(422)
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'rejected')
            ->assertJsonPath('reason', 'unsupported_payload_codec');

        $this->assertSame('json', WorkflowRun::query()->findOrFail($run->id)->payload_codec);
    }

    public function test_avro_payload_still_round_trips_at_the_public_start_boundary(): void
    {
        $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'avro-codec-roundtrip',
            'workflow_type' => 'tests.external-greeting-workflow',
            'input' => [
                'codec' => 'avro',
                'blob' => Serializer::serializeWithCodec('avro', ['Ada']),
            ],
        ])->assertCreated()->assertJsonPath('payload_codec', 'avro');
    }

    public function test_json_tagged_workflow_task_completion_fails_before_history_is_written(): void
    {
        Queue::fake();
        $run = $this->startRemoteWorkflow('json-codec-completion-must-fail');
        $this->registerWorker(
            'avro-completion-worker',
            'python-workflows',
            supportedWorkflowTypes: ['python.codec-workflow'],
        );

        $poll = $this->withHeaders($this->workerHeaders())->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'avro-completion-worker',
            'task_queue' => 'python-workflows',
        ])->assertOk()->assertJsonPath('poll_status', 'leased');

        $response = $this->withHeaders($this->workerHeaders())->postJson(
            "/api/worker/workflow-tasks/{$poll->json('task.task_id')}/complete",
            [
                'lease_owner' => $poll->json('task.lease_owner'),
                'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                'commands' => [[
                    'type' => 'complete_workflow',
                    'result' => ['codec' => 'json', 'blob' => '{"stale":true}'],
                ]],
            ],
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('unsupported_payload_codec', $response->getContent());
        $this->assertStringContainsString('HTTP document transport', $response->getContent());
        $this->assertFalse($run->refresh()->status->isTerminal());
    }

    public function test_deployment_preflight_inventories_tags_and_actual_avro_frames_without_deleting_history(): void
    {
        $run = $this->startRemoteWorkflow('preflight-avro-run');
        $report = app(PayloadCodecDeploymentPreflight::class)->assertReady();

        $this->assertGreaterThan(0, $report['inspected_frames']);
        $this->assertSame('avro', $run->refresh()->payload_codec);

        $run->forceFill(['payload_codec' => null])->save();

        try {
            app(PayloadCodecDeploymentPreflight::class)->assertReady();
            $this->fail('Expected a durable payload without an explicit codec to block.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('requires explicit payload_codec=avro', $exception->getMessage());
            $this->assertStringContainsString('Do not delete history', $exception->getMessage());
        }

        $this->assertDatabaseHas('workflow_runs', ['id' => $run->id, 'payload_codec' => null]);

        $run->forceFill(['payload_codec' => 'json'])->save();

        try {
            app(PayloadCodecDeploymentPreflight::class)->assertReady();
            $this->fail('Expected the Avro-only deployment preflight to block.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unsupported_payload_codec', $exception->getMessage());
            $this->assertStringContainsString('Do not delete history', $exception->getMessage());
            $this->assertStringContainsString('workflow_runs.payload_codec=json', $exception->getMessage());
        }

        $this->assertDatabaseHas('workflow_runs', ['id' => $run->id, 'payload_codec' => 'json']);

        $obsoleteFrame = base64_encode('{"type":"prerelease-json-wrapper","value":{"stale":true}}');
        $run->forceFill([
            'payload_codec' => 'avro',
            'arguments' => $obsoleteFrame,
        ])->save();

        try {
            app(PayloadCodecDeploymentPreflight::class)->assertReady();
            $this->fail('Expected the Avro frame fingerprint inventory to block.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('invalid_single_object_magic', $exception->getMessage());
            $this->assertStringContainsString('Do not delete history', $exception->getMessage());
        }

        $this->assertDatabaseHas('workflow_runs', ['id' => $run->id, 'arguments' => $obsoleteFrame]);

        $run->forceFill([
            'arguments' => Serializer::serializeWithCodec('avro', []),
        ])->save();
        $historyEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();
        $historyEvent->forceFill(['payload' => [
            'payload_codec' => 'avro',
            'arguments' => $obsoleteFrame,
        ]])->save();

        try {
            app(PayloadCodecDeploymentPreflight::class)->assertReady();
            $this->fail('Expected the nested history frame fingerprint inventory to block.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('workflow_history_events', $exception->getMessage());
            $this->assertStringContainsString('invalid_single_object_magic', $exception->getMessage());
        }

        $historyEvent->forceFill(['payload' => [
            'result' => [
                'blob' => Serializer::serializeWithCodec('avro', ['untagged']),
            ],
        ]])->save();

        try {
            app(PayloadCodecDeploymentPreflight::class)->assertReady();
            $this->fail('Expected an untagged nested history envelope to block.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('requires explicit payload_codec=avro', $exception->getMessage());
            $this->assertStringContainsString('Do not delete history', $exception->getMessage());
        }
    }

    public function test_deployment_preflight_scopes_codec_inventory_to_machine_owned_history_payloads(): void
    {
        $run = $this->startRemoteWorkflow('preflight-customer-metadata');
        $historyEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();
        $historyEvent->forceFill(['payload' => [
            'memo' => [
                'codec' => 'json',
                'payload_codec' => 'json',
                'blob' => 'customer memo text',
            ],
            'search_attributes' => [
                'codec' => 'json',
                'payload_codec' => 'json',
                'blob' => 'customer search metadata',
            ],
        ]])->save();

        $report = app(PayloadCodecDeploymentPreflight::class)->assertReady();

        $this->assertIsArray($report['codec_counts']);
        $this->assertDatabaseHas('workflow_history_events', ['id' => $historyEvent->id]);

        $historyEvent->forceFill(['payload' => [
            'memo' => ['codec' => 'json', 'blob' => 'customer memo text'],
            'result' => ['codec' => 'json', 'blob' => '{"stale":true}'],
        ]])->save();

        try {
            app(PayloadCodecDeploymentPreflight::class)->assertReady();
            $this->fail('Expected a JSON-tagged machine-owned history payload to block.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('.payload.result.codec=json', $exception->getMessage());
            $this->assertStringNotContainsString('.payload.memo.codec=json', $exception->getMessage());
        }
    }

    private function startRemoteWorkflow(string $workflowId): WorkflowRun
    {
        $response = $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => $workflowId,
            'workflow_type' => 'python.codec-workflow',
            'task_queue' => 'python-workflows',
        ]);

        $response->assertCreated();

        return WorkflowRun::query()->findOrFail((string) $response->json('run_id'));
    }
}
