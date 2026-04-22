<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ExternalPayloadStorageTest extends TestCase
{
    use RefreshDatabase;

    private string $storageDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageDirectory = storage_path('framework/testing/external-payload-storage');
        File::deleteDirectory($this->storageDirectory);

        $this->withHeaders([
            'X-Durable-Workflow-Control-Plane-Version' => ControlPlaneProtocol::VERSION,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storageDirectory);

        parent::tearDown();
    }

    public function test_namespace_external_storage_policy_can_be_persisted(): void
    {
        $this->createNamespace('billing');

        $response = $this->putJson('/api/namespaces/billing/external-storage', [
            'driver' => 's3',
            'enabled' => true,
            'threshold_bytes' => 2097152,
            'config' => [
                'bucket' => 'dw-payloads',
                'prefix' => 'billing/',
                'region' => 'us-east-1',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('name', 'billing')
            ->assertJsonPath('external_payload_storage.driver', 's3')
            ->assertJsonPath('external_payload_storage.enabled', true)
            ->assertJsonPath('external_payload_storage.threshold_bytes', 2097152)
            ->assertJsonPath('external_payload_storage.config.bucket', 'dw-payloads');

        $policy = WorkflowNamespace::where('name', 'billing')->firstOrFail()->external_payload_storage;

        $this->assertSame('s3', $policy['driver']);
        $this->assertSame('billing/', $policy['config']['prefix']);

        $this->getJson('/api/namespaces/billing')
            ->assertOk()
            ->assertJsonPath('external_payload_storage.driver', 's3');
    }

    public function test_namespace_external_storage_policy_validates_driver_and_threshold(): void
    {
        $this->createNamespace('billing');

        $this->putJson('/api/namespaces/billing/external-storage', [
            'driver' => 'ftp',
            'threshold_bytes' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['driver', 'threshold_bytes']);
    }

    public function test_local_storage_diagnostic_round_trips_small_and_large_payloads(): void
    {
        $this->createNamespace('billing', [
            'driver' => 'local',
            'enabled' => true,
            'threshold_bytes' => 32,
            'config' => [
                'uri' => 'file://'.$this->storageDirectory,
            ],
        ]);

        $response = $this->postJson('/api/storage/test', [
            'small_payload_bytes' => 16,
            'large_payload_bytes' => 64,
        ], ['X-Namespace' => 'billing']);

        $response->assertOk()
            ->assertJsonPath('status', 'passed')
            ->assertJsonPath('namespace', 'billing')
            ->assertJsonPath('driver', 'local')
            ->assertJsonPath('small_payload.status', 'passed')
            ->assertJsonPath('small_payload.bytes', 16)
            ->assertJsonPath('small_payload.sha256', hash('sha256', str_repeat('s', 16)))
            ->assertJsonPath('large_payload.status', 'passed')
            ->assertJsonPath('large_payload.bytes', 64)
            ->assertJsonPath('large_payload.sha256', hash('sha256', str_repeat('l', 64)));

        $this->assertSame([], glob($this->storageDirectory.'/storage-test-*.bin') ?: []);
    }

    public function test_storage_diagnostic_reports_unconfigured_and_unavailable_drivers(): void
    {
        $this->createNamespace('default');

        $this->postJson('/api/storage/test', [
            'small_payload_bytes' => 16,
            'large_payload_bytes' => 64,
        ])->assertStatus(422)
            ->assertJsonPath('reason', 'external_storage_not_configured');

        WorkflowNamespace::where('name', 'default')->update([
            'external_payload_storage' => [
                'driver' => 's3',
                'enabled' => true,
                'config' => ['bucket' => 'dw-payloads'],
            ],
        ]);

        $this->postJson('/api/storage/test', [
            'small_payload_bytes' => 16,
            'large_payload_bytes' => 64,
        ])->assertStatus(422)
            ->assertJsonPath('reason', 'storage_driver_unavailable')
            ->assertJsonPath('driver', 's3')
            ->assertJsonPath('supported_diagnostic_drivers.0', 'local');
    }

    public function test_storage_diagnostic_reports_disabled_policy(): void
    {
        $this->createNamespace('default', [
            'driver' => 'local',
            'enabled' => false,
            'config' => [
                'uri' => 'file://'.$this->storageDirectory,
            ],
        ]);

        $this->postJson('/api/storage/test', [
            'small_payload_bytes' => 16,
            'large_payload_bytes' => 64,
        ])->assertStatus(422)
            ->assertJsonPath('reason', 'external_storage_disabled')
            ->assertJsonPath('driver', 'local');
    }

    private function createNamespace(string $name, ?array $externalStorage = null): void
    {
        WorkflowNamespace::create([
            'name' => $name,
            'description' => 'Test namespace',
            'retention_days' => 30,
            'status' => 'active',
            'external_payload_storage' => $externalStorage,
        ]);
    }
}
