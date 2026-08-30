<?php

namespace Tests\Feature;

use App\Support\PayloadCodecDeploymentPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloads;

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

    public function test_deployment_preflight_accepts_canonical_avro_external_payload_references(): void
    {
        $run = $this->startRemoteWorkflow('preflight-external-avro-run');
        $storedReference = ExternalPayloads::encodeStoredEnvelope([
            'codec' => 'avro',
            'external_storage' => [
                'schema' => ExternalPayloadReference::SCHEMA,
                'uri' => 'file:///retained-payloads/preflight-external-avro-run.bin',
                'sha256' => str_repeat('a', 64),
                'size_bytes' => 128,
                'codec' => 'avro',
            ],
        ]);
        $externalEnvelope = ExternalPayloads::storedEnvelope($storedReference);
        $this->assertIsArray($externalEnvelope);

        $run->forceFill(['arguments' => $storedReference])->save();
        WorkflowCommand::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail()
            ->forceFill(['payload' => $storedReference])
            ->save();
        WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail()
            ->forceFill(['payload' => [
                'payload_codec' => 'avro',
                'arguments' => $externalEnvelope,
                'result' => $externalEnvelope,
                'output' => $externalEnvelope,
                'value' => $externalEnvelope,
                'request_payload' => $externalEnvelope,
                'response_payload' => $externalEnvelope,
                'details' => $externalEnvelope,
                'command' => [
                    'payload_codec' => 'avro',
                    'payload' => $externalEnvelope,
                ],
                'activity' => [
                    'payload_codec' => 'avro',
                    'arguments' => $externalEnvelope,
                    'result' => $externalEnvelope,
                    'exception' => $externalEnvelope,
                ],
                'exception' => [
                    'details_payload_codec' => 'avro',
                    'details' => $externalEnvelope,
                ],
            ]])
            ->save();

        $report = app(PayloadCodecDeploymentPreflight::class)->assertReady();

        $this->assertIsArray($report['codec_counts']);
        $this->assertDatabaseHas('workflow_runs', [
            'id' => $run->id,
            'arguments' => $storedReference,
        ]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidExternalPayloadReferenceProvider(): iterable
    {
        $validExternalStorage = [
            'schema' => ExternalPayloadReference::SCHEMA,
            'uri' => 'file:///retained-payloads/counterfactual.bin',
            'sha256' => str_repeat('b', 64),
            'size_bytes' => 128,
            'codec' => 'avro',
        ];
        $stored = static function (array $envelope): string {
            $json = json_encode($envelope, JSON_UNESCAPED_SLASHES);

            return ExternalPayloads::STORED_REFERENCE_PREFIX.base64_encode((string) $json);
        };

        yield 'malformed official prefix payload' => [
            ExternalPayloads::STORED_REFERENCE_PREFIX.'***',
            'not valid base64',
        ];
        yield 'unsupported prefix version' => [
            'dw-external-payload:v2:'.base64_encode('{}'),
            'invalid_base64',
        ];
        yield 'arbitrary JSON' => [
            '{"codec":"avro"}',
            'json_bytes_labeled_avro',
        ];
        yield 'missing external storage envelope' => [
            $stored(['codec' => 'avro']),
            'external_storage object',
        ];
        yield 'unsupported external reference schema' => [
            $stored([
                'codec' => 'avro',
                'external_storage' => [...$validExternalStorage, 'schema' => 'unsupported'],
            ]),
            'Unsupported external payload reference schema',
        ];
        yield 'missing external reference identity' => [
            $stored([
                'codec' => 'avro',
                'external_storage' => [...$validExternalStorage, 'uri' => ''],
            ]),
            'URI must be a non-empty string',
        ];
        yield 'missing external reference integrity digest' => [
            $stored([
                'codec' => 'avro',
                'external_storage' => [...$validExternalStorage, 'sha256' => null],
            ]),
            'sha256 must be a hex digest',
        ];
        yield 'missing external reference size' => [
            $stored([
                'codec' => 'avro',
                'external_storage' => [...$validExternalStorage, 'size_bytes' => null],
            ]),
            'size_bytes must be a non-negative integer',
        ];
        yield 'non Avro payload codec' => [
            $stored([
                'codec' => 'json',
                'external_storage' => $validExternalStorage,
            ]),
            'unsupported_payload_codec',
        ];
        yield 'non Avro external storage codec' => [
            $stored([
                'codec' => 'avro',
                'external_storage' => [...$validExternalStorage, 'codec' => 'json'],
            ]),
            'unsupported_payload_codec',
        ];
    }

    #[DataProvider('invalidExternalPayloadReferenceProvider')]
    public function test_deployment_preflight_rejects_malformed_external_payload_references(
        string $storedReference,
        string $expectedDiagnostic,
    ): void {
        $run = $this->startRemoteWorkflow('preflight-invalid-external-reference');
        $run->forceFill(['arguments' => $storedReference])->save();

        try {
            app(PayloadCodecDeploymentPreflight::class)->assertReady();
            $this->fail('Expected the malformed external payload reference to block bootstrap.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($expectedDiagnostic, $exception->getMessage());
            $this->assertStringContainsString('Do not delete history', $exception->getMessage());
        }

        $this->assertDatabaseHas('workflow_runs', [
            'id' => $run->id,
            'arguments' => $storedReference,
        ]);
    }

    public function test_deployment_preflight_rejects_malformed_external_envelopes_in_history(): void
    {
        $run = $this->startRemoteWorkflow('preflight-invalid-history-external-envelope');
        $historyEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();
        $historyEvent->forceFill(['payload' => [
            'payload_codec' => 'avro',
            'result' => [
                'codec' => 'avro',
                'external_storage' => [
                    'schema' => ExternalPayloadReference::SCHEMA,
                    'uri' => 'file:///retained-payloads/malformed-history.bin',
                    'size_bytes' => 128,
                    'codec' => 'avro',
                ],
            ],
        ]])->save();

        try {
            app(PayloadCodecDeploymentPreflight::class)->assertReady();
            $this->fail('Expected the malformed history external payload envelope to block bootstrap.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('workflow_history_events', $exception->getMessage());
            $this->assertStringContainsString('sha256 must be a hex digest', $exception->getMessage());
        }

        $this->assertDatabaseHas('workflow_history_events', ['id' => $historyEvent->id]);
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
