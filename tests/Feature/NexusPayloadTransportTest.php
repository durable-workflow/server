<?php

namespace Tests\Feature;

use App\Models\RuntimeExternalPayload;
use App\Models\WorkflowNamespace;
use App\Support\RuntimeExternalPayloadRegistry;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\ExternalPayloads;

class NexusPayloadTransportTest extends TestCase
{
    use DatabaseMigrations;
    use ServerTestHelpers;

    private const EXECUTE_PATH = '/api/service-endpoints/greeter/services/shared/operations/greet/execute';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, DB::connection()->transactionLevel(), 'HTTP writers must reach real commits.');
        $this->createNamespace('default');
        $this->createNamespace('other');
        foreach (['default', 'other'] as $namespace) {
            WorkflowNamespace::query()->where('name', $namespace)->update([
                'external_payload_storage' => [
                    'enabled' => true,
                    'driver' => 'local',
                    'threshold_bytes' => 1,
                    'config' => ['uri' => 'file://'.storage_path('framework/testing/nexus-payloads')],
                ],
            ]);
        }
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'default', 'endpoint_name' => 'greeter',
        ]);
        $service = WorkflowService::query()->create([
            'namespace' => 'default', 'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'shared',
        ]);
        WorkflowServiceOperation::query()->create([
            'namespace' => 'default', 'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id, 'operation_name' => 'greet',
            'operation_mode' => 'async', 'handler_binding_kind' => 'start_workflow',
            'handler_target_reference' => 'php.greeter',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/nexus-payloads'));
        parent::tearDown();
    }

    public static function inlineFormats(): array
    {
        return [['serialized'], ['envelope']];
    }

    #[DataProvider('inlineFormats')]
    public function test_inline_avro_reaches_service_dispatch_unchanged(string $format): void
    {
        $blob = Serializer::serializeWithCodec('avro', ['Ada', ['nested' => true]]);
        $this->expectDispatch($blob);
        $this->withHeaders($this->apiHeaders())->postJson(self::EXECUTE_PATH, [
            'arguments' => $format === 'serialized' ? $blob : ['codec' => 'avro', 'blob' => $blob],
            'payload_codec' => 'avro',
        ])->assertOk();
    }

    public function test_runtime_reference_is_retained_without_another_upload(): void
    {
        $blob = Serializer::serializeWithCodec('avro', [str_repeat('Ada', 128)]);
        $reference = app(RuntimeExternalPayloadRegistry::class)->upload('default', $blob, 'avro', hash('sha256', $blob));
        $internal = app(RuntimeExternalPayloadRegistry::class)->resolveAndClaim('default', $reference);
        $stored = ExternalPayloads::encodeStoredEnvelope($internal);
        $this->expectDispatch($stored);
        $this->withHeaders($this->apiHeaders())->postJson(self::EXECUTE_PATH, [
            'arguments' => ['codec' => 'avro', 'external_payload' => $reference],
            'payload_codec' => 'avro',
        ])->assertOk();

        $this->assertDatabaseCount('runtime_external_payloads', 1);
        $row = RuntimeExternalPayload::query()->sole();
        $this->assertNotNull($row->retained_at);
        $this->assertNull($row->expires_at);
        $this->assertSame($blob, app(RuntimeExternalPayloadRegistry::class)->fetch('default', $reference)['data']);
    }

    public function test_other_namespace_reference_is_refused_before_dispatch(): void
    {
        $blob = Serializer::serializeWithCodec('avro', ['Ada']);
        $reference = app(RuntimeExternalPayloadRegistry::class)->upload('other', $blob, 'avro', hash('sha256', $blob));
        $this->mock(ServiceControlPlane::class)->shouldNotReceive('execute');
        $this->withHeaders($this->apiHeaders())->postJson(self::EXECUTE_PATH, [
            'arguments' => ['codec' => 'avro', 'external_payload' => $reference],
            'payload_codec' => 'avro',
        ])->assertStatus(404);
        $this->assertNull(RuntimeExternalPayload::query()->sole()->retained_at);
    }

    public function test_malformed_serialized_arguments_are_refused_before_dispatch(): void
    {
        $this->mock(ServiceControlPlane::class)->shouldNotReceive('execute');
        $this->withHeaders($this->apiHeaders())->postJson(self::EXECUTE_PATH, [
            'arguments' => 'not Avro', 'payload_codec' => 'avro',
        ])->assertUnprocessable()->assertJsonValidationErrors('arguments');
    }

    public function test_malformed_envelope_is_refused_before_dispatch(): void
    {
        $this->mock(ServiceControlPlane::class)->shouldNotReceive('execute');
        $this->withHeaders($this->apiHeaders())->postJson(self::EXECUTE_PATH, [
            'arguments' => ['codec' => 'avro', 'blob' => Serializer::serializeWithCodec('avro', ['Ada']), 'extra' => true],
            'payload_codec' => 'avro',
        ])->assertUnprocessable()->assertJsonValidationErrors('arguments');
    }

    private function expectDispatch(string $blob): void
    {
        $this->mock(ServiceControlPlane::class)->shouldReceive('execute')->once()
            ->withArgs(function (string $endpoint, string $service, string $operation, array $options) use ($blob): bool {
                $this->assertSame(['greeter', 'shared', 'greet'], [$endpoint, $service, $operation]);
                $this->assertSame('default', $options['namespace']);
                $this->assertSame($blob, $options['arguments']);
                $this->assertSame($blob, $options['payload_blob']);
                $this->assertSame('avro', $options['payload_codec']);

                return true;
            })->andReturnUsing(static fn (): array => DB::transaction(
                // The dispatch double models the real admission transaction,
                // including its commit-time input claim.
                static fn (): array => ['accepted' => true, 'status' => 'started'],
            ));
    }
}
