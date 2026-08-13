<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\ActivityTaskPoller;
use App\Support\AvroPayloadEnvelopeResolver;
use App\Support\ControlPlaneRequestContract;
use App\Support\ExternalPayloadEnvelopeService;
use App\Support\ExternalTaskResultContract;
use App\Support\ExternalWorkflowUpdateAdmission;
use App\Support\InvocableCarrierResultMapper;
use App\Support\NamespaceWorkflowScope;
use App\Support\PayloadCodecContract;
use App\Support\PayloadCodecDeploymentPreflight;
use App\Support\ServerWorkflowControlPlane;
use App\Support\WorkerProtocol;
use App\Support\WorkflowQueryTaskBroker;
use App\Support\WorkflowTaskPoller;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Avro;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\CommandContext;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\PayloadEnvelopeResolver;
use Workflow\V2\Support\WorkflowExecutor;
use Workflow\V2\WorkflowStub;

/**
 * Trusted codec-contract proxy for Avro-only counterfactual fixtures.
 *
 * This version adds contract-level codec selection to the immutable v1
 * serialization proxy without changing the executor used by existing proofs.
 */
final class ServerCodecRegressionBoundaryV2
{
    public static function attestBoundary(
        string $encodedFixture,
        string $boundaryPath,
        string $evidence,
    ): void {
        self::fixtureContract($encodedFixture);
        self::recordEvidence($boundaryPath, $evidence);
    }

    public static function exerciseClaimedBoundary(TestCase $test): void
    {
        $configuredBoundary = getenv('SERVER_CODEC_CLAIMED_BOUNDARY');
        $boundary = is_string($configuredBoundary)
            ? $configuredBoundary
            : 'app/Support/WorkflowStartService.php';

        match ($boundary) {
            'app/Http/Controllers/Api/ActivityController.php' => self::exerciseActivityController($test),
            'app/Http/Controllers/Api/ActivityTaskController.php' => self::exerciseActivityTaskController($test),
            'app/Http/Controllers/Api/BridgeAdapterController.php' => self::exerciseBridgeAdapterController($test),
            'app/Http/Controllers/Api/ServiceCatalogController.php' => self::exerciseServiceCatalogController($test),
            'app/Http/Controllers/Api/WorkerController.php' => self::exerciseWorkerController($test),
            'app/Http/Controllers/Api/WorkflowController.php' => self::exerciseWorkflowController($test),
            'app/Http/Controllers/Api/WorkflowStreamController.php' => self::exerciseWorkflowStreamController($test),
            'app/Support/ActivityTaskPoller.php' => self::exerciseActivityTaskPoller(),
            'app/Support/AvroPayloadEnvelopeResolver.php' => self::exerciseAvroPayloadEnvelopeResolver(),
            'app/Http/Controllers/Api/HealthController.php' => self::exerciseClusterInfo($test),
            'app/Support/ControlPlaneRequestContract.php' => self::exerciseControlPlaneRequestContract(),
            'app/Support/ExternalTaskResultContract.php' => self::exerciseExternalTaskResultContract(),
            'app/Support/ExternalPayloadEnvelopeService.php' => self::exerciseExternalPayloadEnvelopeService(),
            'app/Support/ExternalWorkflowUpdateAdmission.php' => self::exerciseExternalWorkflowUpdateAdmission($test),
            'app/Support/InvocableCarrierResultMapper.php' => self::exerciseInvocableCarrierResultMapper(),
            'app/Support/PayloadCodecContract.php' => self::exercisePayloadCodecContract(),
            'app/Support/PayloadCodecDeploymentPreflight.php' => self::exercisePayloadCodecDeploymentPreflight($test),
            'app/Support/ServerWorkflowControlPlane.php' => self::exerciseServerWorkflowControlPlane($test),
            'app/Support/WorkflowQueryTaskBroker.php' => self::exerciseWorkflowQueryTaskBroker($test),
            'app/Support/WorkflowTaskPoller.php' => self::exerciseWorkflowTaskPoller($test),
            default => self::exerciseWorkflowStart($test, $boundary),
        };
    }

    private static function exerciseActivityController(TestCase $test): void
    {
        $response = $test->withHeaders(self::apiHeaders())->postJson('/api/activities', [
            'activity_id' => 'json-codec-activity-controller',
            'activity_type' => 'tests.external-greeting-activity',
            'input' => self::proofEnvelope(),
        ]);
        self::assertHttpContract($response);
        self::assertNoJsonCodec($response->json());
        Assert::assertFalse(WorkflowRun::query()
            ->where('payload_codec', 'json')->exists());
    }

    private static function exerciseWorkflowStart(TestCase $test, string $boundary): void
    {
        $response = $test->withHeaders(self::apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'json-codec-'.substr(hash('sha256', $boundary), 0, 16),
            'workflow_type' => 'tests.external-greeting-workflow',
            'input' => self::proofEnvelope(),
        ]);
        self::assertHttpContract($response);
        self::assertNoJsonCodec($response->json());
        Assert::assertFalse(WorkflowRun::query()
            ->where('payload_codec', 'json')->exists());
    }

    private static function exerciseClusterInfo(TestCase $test): void
    {
        $response = $test->getJson('/api/cluster/info');
        $response->assertOk();
        self::assertNoJsonCodec($response->json());
        Assert::assertSame(['avro'], $response->json('capabilities.payload_codecs'));
    }

    private static function exerciseControlPlaneRequestContract(): void
    {
        $manifest = ControlPlaneRequestContract::manifest();
        Assert::assertSame(
            'durable-workflow.v2.control-plane-request.contract',
            $manifest['schema'] ?? null,
        );
        if (self::proofInputCodec() === 'json') {
            self::assertNoJsonCodec($manifest);
            Assert::assertSame(
                ['avro'],
                $manifest['operations']['start']['fields']['payload_codec']['canonical_values'] ?? null,
            );
        }
    }

    private static function exerciseExternalTaskResultContract(): void
    {
        $manifest = ExternalTaskResultContract::manifest();
        Assert::assertSame(
            'durable-workflow.v2.external-task-result.contract',
            $manifest['schema'] ?? null,
        );
        if (self::proofInputCodec() === 'json') {
            Assert::assertSame(['avro'], $manifest['payload_support']['codecs'] ?? null);
            self::assertNoJsonCodec($manifest['payload_support'] ?? null);
        }
    }

    private static function exerciseExternalPayloadEnvelopeService(): void
    {
        try {
            $envelope = app(ExternalPayloadEnvelopeService::class)
                ->workerEnvelope(null, self::proofInputCodec(), '{"stale":true}');
            Assert::assertSame('avro', self::proofInputCodec());
            self::assertNoJsonCodec($envelope);
        } catch (InvalidArgumentException $exception) {
            Assert::assertSame('json', self::proofInputCodec());
            Assert::assertStringContainsString('unsupported_payload_codec', $exception->getMessage());
        }
    }

    private static function exercisePayloadCodecContract(): void
    {
        try {
            Assert::assertNotSame('json', PayloadCodecContract::canonicalize(self::proofInputCodec()));
            Assert::assertSame('avro', self::proofInputCodec());
        } catch (InvalidArgumentException $exception) {
            Assert::assertSame('json', self::proofInputCodec());
            Assert::assertStringContainsString('unsupported_payload_codec', $exception->getMessage());
        }
    }

    private static function exerciseAvroPayloadEnvelopeResolver(): void
    {
        try {
            $resolved = AvroPayloadEnvelopeResolver::resolve(self::proofEnvelope());
            Assert::assertSame('avro', self::proofInputCodec());
            self::assertNoJsonCodec($resolved);
        } catch (ValidationException $exception) {
            Assert::assertSame('json', self::proofInputCodec());
            Assert::assertStringContainsString('unsupported_payload_codec', $exception->getMessage());
        }
    }

    private static function exerciseExternalWorkflowUpdateAdmission(TestCase $test): void
    {
        Queue::fake();
        WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => 'codec-update-worker', 'namespace' => 'default'],
            [
                'task_queue' => 'python-workflows',
                'runtime' => 'python',
                'supported_workflow_types' => ['python.codec-workflow'],
                'workflow_command_contracts' => [
                    'python.codec-workflow' => [
                        'queries' => [],
                        'query_contracts' => [],
                        'signals' => [],
                        'signal_contracts' => [],
                        'updates' => ['advance'],
                        'update_validators' => [],
                        'update_contracts' => [[
                            'name' => 'advance',
                            'parameters' => [],
                        ]],
                    ],
                ],
                'supported_activity_types' => [],
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ],
        );
        $start = $test->withHeaders(self::apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'json-codec-external-update-admission',
            'workflow_type' => 'python.codec-workflow',
            'task_queue' => 'python-workflows',
        ]);
        $start->assertCreated();
        $run = WorkflowRun::query()
            ->findOrFail((string) $start->json('run_id'));
        $run->forceFill(['payload_codec' => self::proofInputCodec()])->save();

        try {
            $result = app(ExternalWorkflowUpdateAdmission::class)->admit(
                'default',
                'json-codec-external-update-admission',
                'advance',
                [],
                CommandContext::controlPlane(),
            );
            Assert::assertSame('avro', self::proofInputCodec());
            self::assertNoJsonCodec($result);
        } catch (InvalidArgumentException $exception) {
            Assert::assertSame('json', self::proofInputCodec());
            Assert::assertStringContainsString('unsupported_payload_codec', $exception->getMessage());
        }
    }

    private static function exerciseInvocableCarrierResultMapper(): void
    {
        try {
            $result = app(InvocableCarrierResultMapper::class)->map([
                'schema' => InvocableCarrierResultMapper::RESULT_SCHEMA,
                'version' => ExternalTaskResultContract::VERSION,
                'task' => [
                    'kind' => 'activity_task',
                    'id' => 'codec-task',
                    'idempotency_key' => 'codec-attempt',
                ],
                'outcome' => ['status' => 'succeeded'],
                'result' => ['payload' => self::proofEnvelope()],
            ], 'codec-task', 'codec-attempt', 'codec-worker');
            Assert::assertSame('avro', self::proofInputCodec());
            self::assertNoJsonCodec($result);
        } catch (InvalidArgumentException $exception) {
            Assert::assertSame('json', self::proofInputCodec());
            Assert::assertStringContainsString('unsupported_payload_codec', $exception->getMessage());
        }
    }

    private static function exercisePayloadCodecDeploymentPreflight(TestCase $test): void
    {
        Queue::fake();
        $start = $test->withHeaders(self::apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'json-codec-deployment-preflight',
            'workflow_type' => 'tests.external-greeting-workflow',
        ]);
        $start->assertCreated();
        $run = WorkflowRun::query()
            ->findOrFail((string) $start->json('run_id'));
        $run->forceFill(['payload_codec' => self::proofInputCodec()])->save();

        try {
            $report = app(PayloadCodecDeploymentPreflight::class)->assertReady();
            Assert::assertSame('avro', self::proofInputCodec());
            Assert::assertIsArray($report['codec_counts'] ?? null);
        } catch (RuntimeException $exception) {
            Assert::assertSame('json', self::proofInputCodec());
            Assert::assertStringContainsString('unsupported_payload_codec', $exception->getMessage());
            Assert::assertStringContainsString('Do not delete history', $exception->getMessage());
        }
    }

    private static function exerciseServerWorkflowControlPlane(TestCase $test): void
    {
        Queue::fake();
        $start = $test->withHeaders(self::apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'json-codec-server-control-plane',
            'workflow_type' => 'tests.external-greeting-workflow',
        ]);
        $start->assertCreated();
        $run = WorkflowRun::query()
            ->findOrFail((string) $start->json('run_id'));
        $run->forceFill(['payload_codec' => self::proofInputCodec()])->save();

        try {
            $envelope = app(ServerWorkflowControlPlane::class)
                ->encodeArgumentsForRun($run, ['stale']);
            Assert::assertSame('avro', self::proofInputCodec());
            self::assertNoJsonCodec(['codec' => $envelope[0] ?? null]);
        } catch (InvalidArgumentException $exception) {
            Assert::assertSame('json', self::proofInputCodec());
            Assert::assertStringContainsString('unsupported_payload_codec', $exception->getMessage());
        }
    }

    private static function exerciseWorkflowQueryTaskBroker(TestCase $test): void
    {
        Queue::fake();
        $start = $test->withHeaders(self::apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'json-codec-query-broker',
            'workflow_type' => 'tests.external-greeting-workflow',
        ]);
        $start->assertCreated();
        $run = WorkflowRun::query()
            ->findOrFail((string) $start->json('run_id'));
        $run->forceFill(['payload_codec' => self::proofInputCodec()])->save();

        try {
            $task = app(WorkflowQueryTaskBroker::class)->enqueue(
                'default',
                $run,
                'status',
                self::proofEnvelope(),
            );
            Assert::assertSame('avro', self::proofInputCodec());
            self::assertNoJsonCodec($task);
        } catch (InvalidArgumentException $exception) {
            Assert::assertSame('json', self::proofInputCodec());
            Assert::assertStringContainsString('unsupported_payload_codec', $exception->getMessage());
        }
    }

    private static function exerciseWorkflowTaskPoller(TestCase $test): void
    {
        Queue::fake();
        $start = $test->withHeaders(self::apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'json-codec-workflow-task-poller',
            'workflow_type' => 'tests.external-greeting-workflow',
        ]);
        $start->assertCreated();
        $run = WorkflowRun::query()
            ->findOrFail((string) $start->json('run_id'));
        $run->forceFill(['payload_codec' => self::proofInputCodec()])->save();
        $taskId = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', 'workflow')
            ->value('id');
        Assert::assertIsString($taskId);

        try {
            $history = app(WorkflowTaskPoller::class)->historyPage(
                'default',
                $taskId,
                0,
                100,
                null,
            );
            Assert::assertSame('avro', self::proofInputCodec());
            self::assertNoJsonCodec($history);
        } catch (InvalidArgumentException $exception) {
            Assert::assertSame('json', self::proofInputCodec());
            Assert::assertStringContainsString('unsupported_payload_codec', $exception->getMessage());
        }
    }

    private static function exerciseBridgeAdapterController(TestCase $test): void
    {
        Queue::fake();
        $start = $test->withHeaders(self::apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'json-codec-bridge-controller',
            'workflow_type' => 'tests.external-greeting-workflow',
        ]);
        $start->assertCreated();
        $controlPlane = \Mockery::mock(WorkflowControlPlane::class);
        $controlPlane->shouldReceive('signal')->zeroOrMoreTimes()->andReturn([
            'accepted' => true,
            'workflow_instance_id' => 'json-codec-bridge-controller',
            'workflow_command_id' => '01JCODECPROOFCOMMAND000000',
            'reason' => null,
            'status' => 202,
            'workflow_id' => 'json-codec-bridge-controller',
            'run_id' => $start->json('run_id'),
            'command_id' => '01JCODECPROOFCOMMAND000000',
            'command_status' => 'accepted',
            'command_source' => 'control_plane',
            'target_scope' => 'instance',
            'signal_name' => 'advance',
            'outcome' => 'accepted',
        ]);
        app()->instance(WorkflowControlPlane::class, $controlPlane);
        $response = $test->withHeaders(self::apiHeaders())
            ->postJson('/api/bridge-adapters/webhook/codec-proof', [
                'action' => 'signal_workflow',
                'idempotency_key' => 'codec-proof-event',
                'target' => [
                    'workflow_id' => 'json-codec-bridge-controller',
                    'signal_name' => 'advance',
                ],
                'input' => self::proofEnvelope(),
            ]);
        self::assertHttpContract($response);
        self::assertNoJsonCodec($response->json());
    }

    private static function exerciseWorkerController(TestCase $test): void
    {
        Queue::fake();
        $start = $test->withHeaders(self::apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'json-codec-worker-controller',
            'workflow_type' => 'tests.external-greeting-workflow',
        ]);
        $start->assertCreated();
        WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => 'codec-workflow-worker', 'namespace' => 'default'],
            [
                'task_queue' => 'default',
                'runtime' => 'php',
                'supported_workflow_types' => ['tests.external-greeting-workflow'],
                'supported_activity_types' => [],
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ],
        );
        $headers = self::workerHeaders();
        $poll = $test->withHeaders($headers)->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'codec-workflow-worker',
            'task_queue' => 'default',
        ]);
        $poll->assertOk();
        $response = $test->withHeaders($headers)->postJson(
            '/api/worker/workflow-tasks/'.$poll->json('task.task_id').'/complete',
            [
                'lease_owner' => $poll->json('task.lease_owner'),
                'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                'commands' => [[
                    'type' => 'complete_workflow',
                    'result' => self::proofEnvelope(),
                ]],
            ],
        );
        self::assertHttpContract($response);
        self::assertNoJsonCodec($response->json());
    }

    private static function exerciseServiceCatalogController(TestCase $test): void
    {
        $headers = self::apiHeaders();
        $test->withHeaders($headers)->postJson('/api/service-endpoints', [
            'endpoint_name' => 'codec-proof',
        ])->assertCreated();
        $test->withHeaders($headers)->postJson('/api/service-endpoints/codec-proof/services', [
            'service_name' => 'codec-proof',
        ])->assertCreated();
        $test->withHeaders($headers)->postJson(
            '/api/service-endpoints/codec-proof/services/codec-proof/operations',
            [
                'operation_name' => 'execute-proof',
                'operation_mode' => 'async',
                'handler_binding_kind' => 'start_workflow',
                'handler_target_reference' => 'tests.external-greeting-workflow',
                'handler_binding' => ['workflow_type' => 'tests.external-greeting-workflow'],
            ],
        )->assertCreated();

        $controlPlane = \Mockery::mock(ServiceControlPlane::class);
        $controlPlane->shouldReceive('execute')->zeroOrMoreTimes()->andReturn([
            'accepted' => true,
            'service_call_id' => '01JCODECPROOFSERVICE000000',
            'namespace' => 'default',
            'endpoint_name' => 'codec-proof',
            'service_name' => 'codec-proof',
            'operation_name' => 'execute-proof',
            'operation_mode' => 'async',
            'resolved_binding_kind' => 'workflow_run',
            'resolved_target_reference' => 'tests.external-greeting-workflow',
            'status' => 'accepted',
            'linked_workflow_instance_id' => 'codec-proof-service-run',
            'linked_workflow_run_id' => null,
            'linked_workflow_update_id' => null,
            'reason' => null,
        ]);
        app()->instance(ServiceControlPlane::class, $controlPlane);

        $response = $test->withHeaders($headers)->postJson(
            '/api/service-endpoints/codec-proof/services/codec-proof/operations/execute-proof/execute',
            [
                'arguments' => ['stale'],
                'payload_codec' => self::proofInputCodec(),
            ],
        );
        self::assertHttpContract($response);
        self::assertNoJsonCodec($response->json());
    }

    private static function exerciseWorkflowStreamController(TestCase $test): void
    {
        Queue::fake();
        $start = $test->withHeaders(self::apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'json-codec-stream-controller',
            'workflow_type' => 'tests.external-greeting-workflow',
        ]);
        $start->assertCreated();
        $response = $test->withHeaders(self::apiHeaders())->postJson(
            '/api/workflows/json-codec-stream-controller/runs/'.$start->json('run_id').'/streams/proof/items',
            ['items' => [[
                'payload' => ['stale' => true],
                'payload_codec' => self::proofInputCodec(),
            ]]],
        );
        self::assertHttpContract($response);
        self::assertNoJsonCodec($response->json());
    }

    private static function exerciseWorkflowController(TestCase $test): void
    {
        Queue::fake();
        $start = $test->withHeaders(self::apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'json-codec-workflow-controller',
            'workflow_type' => 'tests.external-greeting-workflow',
        ]);
        $start->assertCreated();

        $controlPlane = \Mockery::mock(WorkflowControlPlane::class);
        $controlPlane->shouldReceive('signal')->zeroOrMoreTimes()->andReturn([
            'accepted' => true,
            'workflow_instance_id' => 'json-codec-workflow-controller',
            'workflow_command_id' => '01JCODECWORKFLOWCOMMAND000',
            'reason' => null,
            'status' => 202,
            'workflow_id' => 'json-codec-workflow-controller',
            'run_id' => $start->json('run_id'),
            'command_id' => '01JCODECWORKFLOWCOMMAND000',
            'command_status' => 'accepted',
            'command_source' => 'control_plane',
            'target_scope' => 'instance',
            'signal_name' => 'advance',
            'outcome' => 'accepted',
        ]);
        app()->instance(WorkflowControlPlane::class, $controlPlane);

        $response = $test->withHeaders(self::apiHeaders())->postJson(
            '/api/workflows/json-codec-workflow-controller/signal/advance',
            ['input' => self::proofEnvelope()],
        );
        self::assertHttpContract($response);
        self::assertNoJsonCodec($response->json());
    }

    /** @return array{codec: string, blob: string} */
    private static function proofEnvelope(): array
    {
        $codec = self::proofInputCodec();

        return [
            'codec' => $codec,
            'blob' => $codec === 'avro'
                ? Serializer::serializeWithCodec('avro', ['sentinel'])
                : '{"stale":true}',
        ];
    }

    private static function proofInputCodec(): string
    {
        $configuredCodec = getenv('SERVER_CODEC_PROOF_INPUT_CODEC');
        $codec = is_string($configuredCodec) ? $configuredCodec : 'json';
        Assert::assertContains($codec, ['avro', 'json']);

        return $codec;
    }

    /** @return array<string, string> */
    private static function apiHeaders(): array
    {
        return [
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ];
    }

    /** @return array<string, string> */
    private static function workerHeaders(): array
    {
        return [
            'X-Namespace' => 'default',
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ];
    }

    private static function assertHttpContract(TestResponse $response): void
    {
        if (self::proofInputCodec() === 'json') {
            $response->assertStatus(422);
            Assert::assertStringContainsString('unsupported_payload_codec', $response->getContent());

            return;
        }

        Assert::assertTrue($response->isSuccessful(), $response->getContent());
    }

    private static function assertNoJsonCodec(mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            if (in_array($key, ['codec', 'payload_codec'], true)) {
                Assert::assertNotSame('json', $item);
            }
            if ($key === 'payload_codecs' && is_array($item)) {
                Assert::assertNotContains('json', $item);
            }
            self::assertNoJsonCodec($item);
        }
    }

    public static function exerciseActivityTaskController(TestCase $test): void
    {
        Queue::fake();
        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Codec proof', 'retention_days' => 30, 'status' => 'active'],
        );
        $workflow = WorkflowStub::make(
            ExternalGreetingWorkflow::class,
            'json-codec-activity-task-controller',
        );
        $start = $workflow->start('Ada');
        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);
        $taskId = WorkflowTask::query()
            ->where('workflow_run_id', $start->runId())
            ->where('task_type', 'workflow')
            ->where('status', 'ready')
            ->orderBy('available_at')
            ->value('id');
        Assert::assertIsString($taskId);
        (new RunWorkflowTask($taskId))->handle(app(WorkflowExecutor::class));
        ActivityExecution::query()->update([
            'payload_codec' => self::proofInputCodec(),
        ]);
        WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => 'codec-activity-worker', 'namespace' => 'default'],
            [
                'task_queue' => 'external-activities',
                'runtime' => 'php',
                'supported_workflow_types' => ['tests.external-greeting-workflow'],
                'supported_activity_types' => ['tests.external-greeting-activity'],
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ],
        );
        $headers = [
            'X-Namespace' => 'default',
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ];
        $poll = $test->withHeaders($headers)->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'codec-activity-worker',
            'task_queue' => 'external-activities',
        ]);
        self::assertHttpContract($poll);
        self::assertNoJsonCodec($poll->json());
        if ($poll->json('poll_status') === 'rejected') {
            Assert::assertSame('unsupported_payload_codec', $poll->json('reason'));

            return;
        }
        $activityTaskId = $poll->json('task.task_id');
        $attemptId = $poll->json('task.activity_attempt_id');
        $leaseOwner = $poll->json('task.lease_owner');
        Assert::assertIsString($activityTaskId);
        Assert::assertIsString($attemptId);
        Assert::assertIsString($leaseOwner);
        $test->withHeaders($headers)->postJson(
            "/api/worker/activity-tasks/{$activityTaskId}/complete",
            [
                'activity_attempt_id' => $attemptId,
                'lease_owner' => $leaseOwner,
                'result' => ['codec' => 'avro', 'blob' => 'wwHioz3/VYAiNwQA'],
            ],
        );
        foreach (WorkflowHistoryEvent::query()->get() as $event) {
            Assert::assertFalse(self::containsJsonCodec($event->payload));
        }
    }

    private static function exerciseActivityTaskPoller(): void
    {
        Queue::fake();
        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Codec proof', 'retention_days' => 30, 'status' => 'active'],
        );
        $workflow = WorkflowStub::make(
            ExternalGreetingWorkflow::class,
            'json-codec-activity-task-poller',
        );
        $start = $workflow->start('Ada');
        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);
        $taskId = WorkflowTask::query()
            ->where('workflow_run_id', $start->runId())
            ->where('task_type', 'workflow')
            ->where('status', 'ready')
            ->orderBy('available_at')
            ->value('id');
        Assert::assertIsString($taskId);
        (new RunWorkflowTask($taskId))->handle(app(WorkflowExecutor::class));
        ActivityExecution::query()->update(['payload_codec' => self::proofInputCodec()]);
        Assert::assertSame(
            self::proofInputCodec(),
            ActivityExecution::query()->value('payload_codec'),
        );
        $taskQueue = (string) ActivityExecution::query()->value('queue');
        Assert::assertNotSame('', $taskQueue);
        $worker = WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => 'codec-poller-worker', 'namespace' => 'default'],
            [
                'task_queue' => $taskQueue,
                'runtime' => 'php',
                'supported_workflow_types' => ['tests.external-greeting-workflow'],
                'supported_activity_types' => ['tests.external-greeting-activity'],
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ],
        );

        try {
            $poller = app(ActivityTaskPoller::class);
            $poller->poll(
                'default',
                $taskQueue,
                'codec-poller-worker',
                null,
                $worker,
                'codec-poller-request',
                supportedActivityTypes: ['tests.external-greeting-activity'],
                timeoutSeconds: 0,
            );
            $result = $poller->poll(
                'default',
                $taskQueue,
                'codec-poller-worker',
                null,
                $worker,
                'codec-poller-request',
                supportedActivityTypes: ['tests.external-greeting-activity'],
                timeoutSeconds: 0,
            );
            Assert::assertIsArray($result['task']);
            Assert::assertSame('avro', self::proofInputCodec());
            self::assertNoJsonCodec($result);
        } catch (InvalidArgumentException $exception) {
            Assert::assertSame('json', self::proofInputCodec());
            Assert::assertStringContainsString('unsupported_payload_codec', $exception->getMessage());
        }
    }

    private static function containsJsonCodec(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $key => $item) {
            if (in_array($key, ['codec', 'payload_codec'], true) && $item === 'json') {
                return true;
            }
            if (self::containsJsonCodec($item)) {
                return true;
            }
        }

        return false;
    }

    public static function defaultCodec(
        string $encodedFixture,
        string $boundaryPath,
        string $evidence,
    ): string {
        self::fixtureContract($encodedFixture);
        self::recordEvidence($boundaryPath, $evidence);

        return CodecRegistry::defaultCodec();
    }

    public static function canonicalize(
        string $encodedFixture,
        string $boundaryPath,
        string $evidence,
        mixed $codec,
    ): string {
        [$fixtureCodec] = self::fixtureContract($encodedFixture);
        self::recordEvidence($boundaryPath, $evidence);

        return PayloadCodecContract::canonicalize(
            $fixtureCodec === PayloadCodecContract::CODEC ? $fixtureCodec : $codec,
        );
    }

    public static function legacyCanonicalize(
        string $encodedFixture,
        string $boundaryPath,
        string $evidence,
        mixed $codec,
    ): string {
        [$fixtureCodec] = self::fixtureContract($encodedFixture);
        self::recordEvidence($boundaryPath, $evidence);

        return CodecRegistry::canonicalize(
            $fixtureCodec === PayloadCodecContract::CODEC ? $fixtureCodec : $codec,
        );
    }

    /** @return list<string> */
    public static function legacyUniversal(
        string $encodedFixture,
        string $boundaryPath,
        string $evidence,
    ): array {
        [$fixtureCodec] = self::fixtureContract($encodedFixture);
        self::recordEvidence($boundaryPath, $evidence);

        return $fixtureCodec === PayloadCodecContract::CODEC
            ? [PayloadCodecContract::CODEC]
            : CodecRegistry::universal();
    }

    /** @return list<string> */
    public static function universal(
        string $encodedFixture,
        string $boundaryPath,
        string $evidence,
    ): array {
        [$fixtureCodec] = self::fixtureContract($encodedFixture);
        self::recordEvidence($boundaryPath, $evidence);

        return $fixtureCodec === PayloadCodecContract::CODEC
            ? [PayloadCodecContract::CODEC]
            : PayloadCodecContract::universal();
    }

    public static function legacyResolve(
        string $encodedFixture,
        string $boundaryPath,
        string $evidence,
        mixed $input,
        string $field = 'input',
        mixed $externalStorage = null,
    ): array {
        self::recordEvidence($boundaryPath, $evidence);

        return PayloadEnvelopeResolver::resolve(
            self::delegatedEnvelope($encodedFixture, $input),
            $field,
            $externalStorage,
        );
    }

    public static function avroResolve(
        string $encodedFixture,
        string $boundaryPath,
        string $evidence,
        mixed $input,
        string $field = 'input',
        mixed $externalStorage = null,
    ): array {
        self::recordEvidence($boundaryPath, $evidence);

        return AvroPayloadEnvelopeResolver::resolve(
            self::delegatedEnvelope($encodedFixture, $input),
            $field,
            $externalStorage,
        );
    }

    public static function legacyResolveToArray(
        string $encodedFixture,
        string $boundaryPath,
        string $evidence,
        mixed $input,
        string $field = 'input',
        mixed $externalStorage = null,
    ): array {
        self::recordEvidence($boundaryPath, $evidence);

        return PayloadEnvelopeResolver::resolveToArray(
            self::delegatedEnvelope($encodedFixture, $input),
            $field,
            $externalStorage,
        );
    }

    public static function avroResolveToArray(
        string $encodedFixture,
        string $boundaryPath,
        string $evidence,
        mixed $input,
        string $field = 'input',
        mixed $externalStorage = null,
    ): array {
        self::recordEvidence($boundaryPath, $evidence);

        return AvroPayloadEnvelopeResolver::resolveToArray(
            self::delegatedEnvelope($encodedFixture, $input),
            $field,
            $externalStorage,
        );
    }

    public static function legacyResolveCommandPayloadWithCodec(
        string $encodedFixture,
        string $boundaryPath,
        string $evidence,
        mixed $input,
        string $field = 'result',
        mixed $externalStorage = null,
    ): array {
        self::recordEvidence($boundaryPath, $evidence);

        return PayloadEnvelopeResolver::resolveCommandPayloadWithCodec(
            self::delegatedEnvelope($encodedFixture, $input),
            $field,
            $externalStorage,
        );
    }

    public static function avroResolveCommandPayloadWithCodec(
        string $encodedFixture,
        string $boundaryPath,
        string $evidence,
        mixed $input,
        string $field = 'result',
        mixed $externalStorage = null,
    ): array {
        self::recordEvidence($boundaryPath, $evidence);

        return AvroPayloadEnvelopeResolver::resolveCommandPayloadWithCodec(
            self::delegatedEnvelope($encodedFixture, $input),
            $field,
            $externalStorage,
        );
    }

    /** @return array{codec: string, blob: string} */
    private static function delegatedEnvelope(string $encodedFixture, mixed $input): mixed
    {
        $fixtureJson = base64_decode($encodedFixture, true);
        Assert::assertIsString($fixtureJson);
        $fixture = json_decode($fixtureJson, true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($fixture);
        $codec = $fixture['protocol']['codec'] ?? null;
        $wire = $fixture['framing']['wire_base64'] ?? null;
        Assert::assertIsString($codec);
        Assert::assertIsString($wire);

        return $input;
    }

    public static function serializeWithCodec(
        string $encodedFixture,
        string $boundaryPath,
        string $evidence,
        ?string $codec,
        mixed $data,
    ): string {
        self::fixtureContract($encodedFixture);
        self::recordEvidence($boundaryPath, $evidence);

        return Serializer::serializeWithCodec($codec, $data);
    }

    public static function unserializeWithCodec(
        string $encodedFixture,
        string $boundaryPath,
        string $evidence,
        ?string $codec,
        string $data,
    ): mixed {
        self::fixtureContract($encodedFixture);
        self::recordEvidence($boundaryPath, $evidence);

        return Serializer::unserializeWithCodec($codec, $data);
    }

    public static function serialize(
        string $encodedFixture,
        string $boundaryPath,
        string $evidence,
        mixed $data,
    ): string {
        self::fixtureContract($encodedFixture);
        self::recordEvidence($boundaryPath, $evidence);

        return Avro::serialize($data);
    }

    public static function unserialize(
        string $encodedFixture,
        string $boundaryPath,
        string $evidence,
        string $data,
    ): mixed {
        self::fixtureContract($encodedFixture);
        self::recordEvidence($boundaryPath, $evidence);

        return Avro::unserialize($data);
    }

    /** @return array{0: string, 1: string, 2: ?string} */
    private static function fixtureContract(string $encodedFixture): array
    {
        $fixtureJson = base64_decode($encodedFixture, true);
        if (! is_string($fixtureJson)) {
            throw new InvalidArgumentException('Invalid encoded server codec fixture.');
        }

        $fixture = json_decode($fixtureJson, true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($fixture);
        Assert::assertSame(
            'durable-workflow.codec-regression/v1',
            $fixture['fixture_schema'] ?? null,
        );
        Assert::assertContains('php', $fixture['bindings'] ?? []);

        $codec = $fixture['protocol']['codec'] ?? null;
        $operation = $fixture['failure_policy']['operation'] ?? null;
        $error = $fixture['failure_policy']['error'] ?? null;
        Assert::assertIsString($codec);
        Assert::assertIsString($operation);
        Assert::assertTrue($error === null || is_string($error));

        return [$codec, $operation, $error];
    }

    private static function recordEvidence(string $boundaryPath, string $evidence): void
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['file'] ?? null) === $boundaryPath) {
                if (
                    preg_match(
                        '/\Adurable-workflow-codec-boundary\/v1:[a-f0-9]{64}\z/D',
                        $evidence,
                    ) !== 1
                ) {
                    throw new RuntimeException('Invalid server codec boundary evidence token.');
                }
                fwrite(STDERR, $evidence.PHP_EOL);

                return;
            }
        }

        throw new RuntimeException(
            "The counted fixture was invoked outside the claimed codec boundary {$boundaryPath}.",
        );
    }
}
