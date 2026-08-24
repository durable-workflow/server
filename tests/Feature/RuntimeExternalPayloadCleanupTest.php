<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RuntimeExternalPayload;
use App\Models\WorkflowNamespace;
use App\Support\FilesystemExternalPayloadStorage;
use App\Support\NamespaceExternalPayloadStorage;
use App\Support\RuntimeExternalPayloadCleanup;
use App\Support\RuntimeExternalPayloadException;
use App\Support\RuntimeExternalPayloadObjectLock;
use App\Support\RuntimeExternalPayloadRegistry;
use App\Support\RuntimeExternalPayloadStorageDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;

class RuntimeExternalPayloadCleanupTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    private string $storageDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageDirectory = storage_path('framework/testing/runtime-external-payload-cleanup');
        File::deleteDirectory($this->storageDirectory);
        config([
            'server.external_payload_transport.max_payload_bytes' => 4096,
            'server.external_payload_transport.abandoned_upload_expiry_seconds' => 60,
        ]);

        $this->createPayloadNamespace('default', $this->storageDirectory.'/default');
        $this->createPayloadNamespace('other', $this->storageDirectory.'/other');
    }

    protected function tearDown(): void
    {
        RuntimeExternalPayload::flushEventListeners();
        File::deleteDirectory($this->storageDirectory);

        parent::tearDown();
    }

    public function test_cleanup_is_bounded_idempotent_and_exposes_aggregate_operator_diagnostics(): void
    {
        $registry = app(RuntimeExternalPayloadRegistry::class);
        $first = $registry->upload('default', 'first expired object', 'avro', hash('sha256', 'first expired object'));
        $second = $registry->upload('default', 'second expired object', 'avro', hash('sha256', 'second expired object'));
        $future = $registry->upload('default', 'future object', 'avro', hash('sha256', 'future object'));

        RuntimeExternalPayload::query()
            ->whereIn('id', [$first['reference_id'], $second['reference_id']])
            ->update(['expires_at' => now()->subSecond()]);
        $firstRow = RuntimeExternalPayload::query()->findOrFail($first['reference_id']);
        $firstPath = rawurldecode((string) parse_url($firstRow->storage_uri, PHP_URL_PATH));

        $cleanup = app(RuntimeExternalPayloadCleanup::class);
        $bounded = $cleanup->runPass('default', 1);

        $this->assertSame(1, $bounded['processed']);
        $this->assertSame(1, $bounded['deleted_references']);
        $this->assertSame(1, $bounded['deleted_backing_objects']);
        $this->assertSame(1, $bounded['backlog']);
        $this->assertTrue($bounded['scan_pressure']);
        $this->assertFileDoesNotExist($firstPath);
        $this->assertDatabaseHas('runtime_external_payloads', ['id' => $future['reference_id']]);

        $drained = $cleanup->runPass('default', 100);
        $this->assertSame(1, $drained['deleted_references']);
        $this->assertSame(0, $drained['backlog']);

        $idempotent = $cleanup->runPass('default', 100);
        $this->assertSame(0, $idempotent['processed']);
        $this->assertSame(0, $idempotent['deleted_references']);

        $this->artisan('external-payloads:cleanup', [
            '--namespace' => 'default',
            '--limit' => 100,
            '--json' => true,
        ])->expectsOutputToContain('durable-workflow.v2.runtime-external-payload-cleanup-report.v1')
            ->assertExitCode(0);

        $response = $this->getJson(
            '/api/system/external-payload-cleanup',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );
        $response->assertOk()
            ->assertJsonPath('cleanup.backlog.expired_unclaimed', 0)
            ->assertJsonPath('cleanup.totals.deleted_references', 2)
            ->assertJsonPath('cleanup.totals.deleted_backing_objects', 2)
            ->assertJsonPath('cleanup.identity_policy.provider_credentials_exposed', false)
            ->assertJsonPath('cleanup.identity_policy.reusable_reference_identities_exposed', false);

        $encoded = json_encode($response->json(), JSON_UNESCAPED_SLASHES);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('storage_uri', $encoded);
        $this->assertStringNotContainsString($first['reference_id'], $encoded);
    }

    public function test_cleanup_preserves_shared_content_while_another_namespace_retains_it(): void
    {
        $sharedRoot = $this->storageDirectory.'/shared';
        $this->createPayloadNamespace('default', $sharedRoot);
        $this->createPayloadNamespace('other', $sharedRoot);
        $registry = app(RuntimeExternalPayloadRegistry::class);
        $data = 'shared cleanup object';
        $sha256 = hash('sha256', $data);
        $default = $registry->upload('default', $data, 'avro', $sha256);
        $other = $registry->upload('other', $data, 'avro', $sha256);
        $otherRow = RuntimeExternalPayload::query()->findOrFail($other['reference_id']);
        $registry->trackRetained('other', $otherRow->storage_uri, 'avro', $sha256, strlen($data));

        RuntimeExternalPayload::query()->whereKey($default['reference_id'])->update([
            'expires_at' => now()->subSecond(),
        ]);
        $sharedPath = rawurldecode((string) parse_url($otherRow->storage_uri, PHP_URL_PATH));

        $report = app(RuntimeExternalPayloadCleanup::class)->runPass('default');

        $this->assertSame(1, $report['deleted_references']);
        $this->assertSame(0, $report['deleted_backing_objects']);
        $this->assertSame(1, $report['shared_objects_preserved']);
        $this->assertFileExists($sharedPath);
        $this->assertSame($data, app(NamespaceExternalPayloadStorage::class)->untrackedDriverFor('other')?->get($otherRow->storage_uri));
        $this->assertDatabaseHas('runtime_external_payloads', [
            'id' => $other['reference_id'],
            'upload_status' => RuntimeExternalPayload::UPLOAD_READY,
        ]);
    }

    public function test_cleanup_rechecks_a_state_bearing_claim_after_candidate_discovery(): void
    {
        $data = 'claim race object';
        $reference = app(RuntimeExternalPayloadRegistry::class)->upload(
            'default',
            $data,
            'avro',
            hash('sha256', $data),
        );
        RuntimeExternalPayload::query()->whereKey($reference['reference_id'])->update([
            'expires_at' => now()->subSecond(),
        ]);
        $armed = true;

        RuntimeExternalPayload::retrieved(function (RuntimeExternalPayload $row) use (&$armed, $reference): void {
            if ($armed && $row->id === $reference['reference_id']) {
                $armed = false;
                RuntimeExternalPayload::query()->whereKey($row->id)->update([
                    'retained_at' => now(),
                    'expires_at' => null,
                ]);
            }
        });

        $report = app(RuntimeExternalPayloadCleanup::class)->runPass('default');

        $this->assertSame(1, $report['processed']);
        $this->assertSame(0, $report['deleted_references']);
        $this->assertSame(1, $report['skipped']);
        $row = RuntimeExternalPayload::query()->findOrFail($reference['reference_id']);
        $this->assertNotNull($row->retained_at);
        $this->assertSame($data, app(NamespaceExternalPayloadStorage::class)->untrackedDriverFor('default')?->get($row->storage_uri));
    }

    public function test_failed_post_write_registration_remains_tracked_and_is_reclaimed(): void
    {
        $failFinalRegistration = true;
        RuntimeExternalPayload::updating(function (RuntimeExternalPayload $row) use (&$failFinalRegistration): void {
            if ($failFinalRegistration && $row->isDirty('upload_status')) {
                $failFinalRegistration = false;
                throw new RuntimeException('simulated registry finalization failure');
            }
        });
        $data = 'recoverable registration object';

        try {
            app(RuntimeExternalPayloadRegistry::class)->upload(
                'default',
                $data,
                'avro',
                hash('sha256', $data),
            );
            $this->fail('The simulated final registration failure was not raised.');
        } catch (RuntimeExternalPayloadException $exception) {
            $this->assertSame('external_payload_unavailable', $exception->reason);
        }

        $writing = RuntimeExternalPayload::query()->firstOrFail();
        $this->assertSame(RuntimeExternalPayload::UPLOAD_WRITING, $writing->upload_status);
        $path = rawurldecode((string) parse_url($writing->storage_uri, PHP_URL_PATH));
        $this->assertFileExists($path);

        $writing->forceFill(['expires_at' => now()->subSecond()])->save();
        $report = app(RuntimeExternalPayloadCleanup::class)->runPass('default');

        $this->assertSame(1, $report['deleted_references']);
        $this->assertSame(1, $report['deleted_backing_objects']);
        $this->assertFileDoesNotExist($path);
        $this->assertDatabaseCount('runtime_external_payloads', 0);
    }

    public function test_cleanup_reclaims_expired_writing_reference_when_storage_commit_never_started(): void
    {
        $driver = app(NamespaceExternalPayloadStorage::class)->untrackedDriverFor('default');
        $this->assertNotNull($driver);
        $data = 'interrupted pre-write object';
        $sha256 = hash('sha256', $data);
        $uri = $driver->uriFor($sha256, 'avro');
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        $this->assertDirectoryDoesNotExist(dirname($path));

        RuntimeExternalPayload::query()->create([
            'id' => 'ep_interrupted_pre_write',
            'namespace' => 'default',
            'storage_uri' => $uri,
            'storage_uri_sha256' => hash('sha256', $uri),
            'codec' => 'avro',
            'sha256' => $sha256,
            'size_bytes' => strlen($data),
            'upload_status' => RuntimeExternalPayload::UPLOAD_WRITING,
            'expires_at' => now()->subSecond(),
        ]);

        $report = app(RuntimeExternalPayloadCleanup::class)->runPass('default');

        $this->assertSame(1, $report['deleted_references']);
        $this->assertSame(1, $report['deleted_backing_objects']);
        $this->assertSame(0, $report['blocked']);
        $this->assertSame(0, $report['storage_driver_failures']);
        $this->assertSame(0, $report['backlog']);
        $this->assertDatabaseMissing('runtime_external_payloads', ['id' => 'ep_interrupted_pre_write']);
        $this->assertFileDoesNotExist($path);
    }

    public function test_storage_driver_failure_is_aggregate_only_and_retryable(): void
    {
        $uri = 'object://payloads/avro/secret-provider-location';
        RuntimeExternalPayload::query()->create([
            'id' => 'ep_cleanup_retry',
            'namespace' => 'default',
            'storage_uri' => $uri,
            'storage_uri_sha256' => hash('sha256', $uri),
            'codec' => 'avro',
            'sha256' => hash('sha256', 'retry bytes'),
            'size_bytes' => strlen('retry bytes'),
            'upload_status' => RuntimeExternalPayload::UPLOAD_READY,
            'expires_at' => now()->subSecond(),
        ]);
        $deleteCalls = 0;
        $driver = \Mockery::mock(RuntimeExternalPayloadStorageDriver::class);
        $driver->shouldReceive('delete')->twice()->andReturnUsing(function () use (&$deleteCalls): void {
            $deleteCalls++;
            if ($deleteCalls === 1) {
                throw new RuntimeException('credential=secret-provider-credential');
            }
        });
        $policy = \Mockery::mock(NamespaceExternalPayloadStorage::class);
        $policy->shouldReceive('untrackedDriverFor')->twice()->with('default')->andReturn($driver);
        $cleanup = new RuntimeExternalPayloadCleanup(app(RuntimeExternalPayloadObjectLock::class), $policy);
        Log::spy();

        $failed = $cleanup->runPass('default');
        $this->assertSame(1, $failed['blocked']);
        $this->assertSame(1, $failed['storage_driver_failures']);
        $this->assertDatabaseHas('runtime_external_payloads', ['id' => 'ep_cleanup_retry']);
        Log::shouldHaveReceived('warning')->withArgs(static function (string $message, array $context): bool {
            $encoded = json_encode($context, JSON_UNESCAPED_SLASHES);

            return $message === 'Runtime external payload cleanup storage operation failed.'
                && is_string($encoded)
                && ! str_contains($encoded, 'secret-provider')
                && ! array_key_exists('storage_uri', $context);
        })->once();

        $retried = $cleanup->runPass('default');
        $this->assertSame(1, $retried['deleted_references']);
        $this->assertDatabaseMissing('runtime_external_payloads', ['id' => 'ep_cleanup_retry']);

        $metrics = $this->getJson(
            '/api/system/external-payload-cleanup',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );
        $metrics->assertOk()
            ->assertJsonPath('cleanup.totals.blocked_outcomes', 1)
            ->assertJsonPath('cleanup.totals.storage_driver_failures', 1)
            ->assertJsonPath('cleanup.totals.deleted_references', 1);
    }

    public function test_false_object_storage_delete_keeps_the_reference_for_retry(): void
    {
        $uri = 'object://payloads/avro/delete-failure';
        RuntimeExternalPayload::query()->create([
            'id' => 'ep_false_delete',
            'namespace' => 'default',
            'storage_uri' => $uri,
            'storage_uri_sha256' => hash('sha256', $uri),
            'codec' => 'avro',
            'sha256' => hash('sha256', 'delete failure bytes'),
            'size_bytes' => strlen('delete failure bytes'),
            'upload_status' => RuntimeExternalPayload::UPLOAD_READY,
            'expires_at' => now()->subSecond(),
        ]);
        $disk = \Mockery::mock();
        $disk->shouldReceive('exists')->once()->with('avro/delete-failure')->andReturnTrue();
        $disk->shouldReceive('delete')->once()->with('avro/delete-failure')->andReturnFalse();
        Storage::shouldReceive('disk')->twice()->with('failing-object-storage')->andReturn($disk);
        $driver = new FilesystemExternalPayloadStorage(
            disk: 'failing-object-storage',
            scheme: 'object',
            bucket: 'payloads',
        );
        $policy = \Mockery::mock(NamespaceExternalPayloadStorage::class);
        $policy->shouldReceive('untrackedDriverFor')->once()->with('default')->andReturn($driver);

        $report = (new RuntimeExternalPayloadCleanup(
            app(RuntimeExternalPayloadObjectLock::class),
            $policy,
        ))->runPass('default');

        $this->assertSame(1, $report['blocked']);
        $this->assertSame(1, $report['storage_driver_failures']);
        $this->assertDatabaseHas('runtime_external_payloads', ['id' => 'ep_false_delete']);
    }

    public function test_configured_object_storage_bytes_are_deleted_with_the_registry_row(): void
    {
        config([
            'filesystems.disks.cleanup-object-storage' => [
                'driver' => 'local',
                'root' => $this->storageDirectory.'/object-storage',
            ],
        ]);
        Storage::fake('cleanup-object-storage');
        WorkflowNamespace::query()->where('name', 'default')->update([
            'external_payload_storage' => [
                'driver' => 'custom',
                'enabled' => true,
                'threshold_bytes' => 32,
                'config' => [
                    'disk' => 'cleanup-object-storage',
                    'name' => 'payloads',
                    'scheme' => 'object',
                ],
            ],
        ]);
        $data = 'configured object storage cleanup';
        $reference = app(RuntimeExternalPayloadRegistry::class)->upload(
            'default',
            $data,
            'avro',
            hash('sha256', $data),
        );
        $row = RuntimeExternalPayload::query()->findOrFail($reference['reference_id']);
        $key = ltrim(rawurldecode((string) parse_url($row->storage_uri, PHP_URL_PATH)), '/');
        Storage::disk('cleanup-object-storage')->assertExists($key);
        $row->forceFill(['expires_at' => now()->subSecond()])->save();

        $this->withHeaders($this->controlPlaneHeadersWithWorkerProtocol())
            ->postJson('/api/system/external-payload-cleanup/pass', ['limit' => 1])
            ->assertOk()
            ->assertJsonPath('cleanup.processed', 1)
            ->assertJsonPath('cleanup.deleted_backing_objects', 1)
            ->assertJsonPath('cleanup.storage_driver_failures', 0);
        Storage::disk('cleanup-object-storage')->assertMissing($key);
        $this->assertDatabaseMissing('runtime_external_payloads', ['id' => $reference['reference_id']]);
    }

    public function test_default_maintenance_surfaces_schedule_bounded_cleanup(): void
    {
        foreach (['docker-compose.yml', 'docker-compose.small-cluster.yml'] as $file) {
            $compose = Yaml::parseFile(base_path($file));
            $this->assertStringContainsString(
                'external-payloads:cleanup --limit=100 --json',
                (string) ($compose['services']['scheduler']['command'] ?? ''),
            );
        }

        $manifest = Yaml::parseFile(base_path('k8s/scheduler-cronjob.yaml'));
        $manifestCommand = implode(' ', $manifest['spec']['jobTemplate']['spec']['template']['spec']['containers'][0]['args'] ?? []);
        $this->assertStringContainsString('artisan list --raw', $manifestCommand);
        $this->assertStringContainsString('external-payloads:cleanup --limit=100 --json', $manifestCommand);

        $values = Yaml::parseFile(base_path('k8s/helm/durable-workflow/values.yaml'));
        $chartCommand = implode(' ', $values['scheduler']['args'] ?? []);
        $this->assertStringContainsString('artisan list --raw', $chartCommand);
        $this->assertStringContainsString('external-payloads:cleanup --limit=100 --json', $chartCommand);
    }

    private function createPayloadNamespace(string $name, string $root): void
    {
        WorkflowNamespace::query()->updateOrCreate(['name' => $name], [
            'description' => 'Runtime payload cleanup test namespace',
            'retention_days' => 30,
            'status' => 'active',
            'external_payload_storage' => [
                'driver' => 'local',
                'enabled' => true,
                'threshold_bytes' => 32,
                'config' => ['uri' => 'file://'.$root],
            ],
        ]);
    }
}
