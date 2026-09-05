<?php

namespace Tests\Feature;

use App\Models\RuntimeExternalPayload;
use App\Models\WorkflowNamespace;
use App\Support\ExternalPayloadBackupHold;
use App\Support\ExternalPayloadBackupInProgress;
use App\Support\GuardedExternalPayloadStorage;
use App\Support\NamespaceExternalPayloadStorage;
use App\Support\RuntimeExternalPayloadCleanup;
use App\Support\RuntimeExternalPayloadException;
use App\Support\RuntimeExternalPayloadRegistry;
use App\Support\RuntimeLocalExternalPayloadStorage;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class ExternalPayloadBackupHoldTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/dw-backup-hold-'.Str::uuid();
        WorkflowNamespace::query()->create([
            'name' => 'default',
            'status' => 'active',
            'retention_days' => 1,
            'external_payload_storage' => ['driver' => 'local', 'config' => ['uri' => 'file://'.$this->directory]],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_acquire_is_persistent_and_idempotent_without_extending_the_lease(): void
    {
        $owner = (string) Str::uuid();
        $first = (new ExternalPayloadBackupHold)->acquire($owner, 600);
        $again = (new ExternalPayloadBackupHold)->acquire($owner, 1200);
        $this->assertTrue($first['active']);
        $this->assertSame($first['expires_at'], $again['expires_at']);
        $this->assertSame($owner, (new ExternalPayloadBackupHold)->status($owner)['owner']);
        $this->assertDatabaseCount('runtime_external_payload_backup_hold', 1);
    }

    public function test_other_owners_cannot_acquire_renew_release_or_validate_an_active_hold(): void
    {
        $hold = new ExternalPayloadBackupHold;
        $owner = (string) Str::uuid();
        $hold->acquire($owner, 600);
        $other = (string) Str::uuid();
        foreach (['acquire', 'renew', 'release', 'status'] as $method) {
            $this->assertFailure(fn () => $hold->{$method}($other, 600));
            $this->assertTrue($hold->status($owner)['active']);
        }
    }

    public function test_expired_hold_cannot_be_renewed_or_reacquired_and_stale_release_cannot_clear_a_new_owner(): void
    {
        $hold = new ExternalPayloadBackupHold;
        $owner = (string) Str::uuid();
        $hold->acquire($owner, 600);
        DB::table('runtime_external_payload_backup_hold')->update(['expires_at' => '2000-01-01 00:00:00']);
        foreach (['acquire', 'renew', 'status'] as $method) {
            $this->assertFailure(fn () => $hold->{$method}($owner, 600));
            $this->assertFalse($hold->status()['active']);
        }
        $called = false;
        $hold->deleting(function () use (&$called): void {
            $called = true;
        });
        $this->assertTrue($called);
        $next = (string) Str::uuid();
        $hold->acquire($next, 600);
        $this->assertFailure(fn () => $hold->release($owner));
        $this->assertTrue($hold->status($next)['active']);
    }

    public function test_release_is_idempotent_and_does_not_allow_resurrection(): void
    {
        $hold = new ExternalPayloadBackupHold;
        $owner = (string) Str::uuid();
        $hold->acquire($owner, 600);
        $this->assertFalse($hold->release($owner)['active']);
        $this->assertFalse($hold->release($owner)['active']);
        $this->expectException(RuntimeException::class);
        $hold->acquire($owner, 600);
    }

    public function test_renewal_is_bounded_by_the_original_absolute_deadline(): void
    {
        $hold = new ExternalPayloadBackupHold;
        $owner = (string) Str::uuid();
        $first = $hold->acquire($owner, 600);
        $now = CarbonImmutable::parse($first['database_time'], 'UTC');
        $started = $now->subSeconds(ExternalPayloadBackupHold::MAX_DURATION_SECONDS - 300);
        DB::table('runtime_external_payload_backup_hold')->update(['acquired_at' => $started->toDateTimeString()]);
        $renewed = $hold->renew($owner, 3600);
        $this->assertSame($now->addSeconds(300)->toDateTimeString(), $renewed['expires_at']);
    }

    public function test_input_bounds_do_not_modify_coordination_state(): void
    {
        foreach ([0, -1, 3601] as $ttl) {
            $this->assertFailure(fn () => (new ExternalPayloadBackupHold)->acquire((string) Str::uuid(), $ttl), InvalidArgumentException::class);
            $this->assertFalse((new ExternalPayloadBackupHold)->status()['active']);
        }
        $this->expectException(InvalidArgumentException::class);
        (new ExternalPayloadBackupHold)->acquire('not-a-backup-uuid', 600);
    }

    public function test_missing_coordination_row_fails_closed_before_any_provider_delete(): void
    {
        DB::table('runtime_external_payload_backup_hold')->delete();
        $called = false;
        $this->assertFailure(function () use (&$called): void {
            (new ExternalPayloadBackupHold)->deleting(function () use (&$called): void {
                $called = true;
            });
        });
        $this->assertFalse($called);
    }

    public function test_cleanup_blocks_deletion_but_uploads_and_reads_remain_available(): void
    {
        $registry = app(RuntimeExternalPayloadRegistry::class);
        $reference = $registry->upload('default', 'old', 'avro', hash('sha256', 'old'));
        RuntimeExternalPayload::query()->whereKey($reference['reference_id'])->update(['expires_at' => '2000-01-01 00:00:00']);
        $row = RuntimeExternalPayload::query()->findOrFail($reference['reference_id']);
        $owner = (string) Str::uuid();
        $hold = new ExternalPayloadBackupHold;
        $hold->acquire($owner, 600);
        $report = app(RuntimeExternalPayloadCleanup::class)->runPass('default');
        $this->assertSame(1, $report['blocked']);
        $this->assertSame(0, $report['storage_driver_failures']);
        $this->assertSame(0, $report['deleted_backing_objects']);
        $driver = app(NamespaceExternalPayloadStorage::class)->untrackedDriverFor('default');
        $this->assertSame('old', $driver->get($row->storage_uri));
        $new = $registry->upload('default', 'new', 'avro', hash('sha256', 'new'));
        $this->assertSame('new', $registry->fetch('default', $new)['data']);
        $hold->release($owner);
        $this->assertSame(1, app(RuntimeExternalPayloadCleanup::class)->runPass('default')['deleted_backing_objects']);
    }

    public function test_namespace_deletion_preserves_objects_and_registry_until_release(): void
    {
        $registry = app(RuntimeExternalPayloadRegistry::class);
        $reference = $registry->upload('default', 'retained', 'avro', hash('sha256', 'retained'));
        $registry->fetch('default', $reference);
        $owner = (string) Str::uuid();
        $hold = new ExternalPayloadBackupHold;
        $hold->acquire($owner, 600);
        $this->assertFailure(fn () => $registry->deleteForNamespace('default'), RuntimeExternalPayloadException::class);
        $this->assertDatabaseHas('runtime_external_payloads', ['id' => $reference['reference_id']]);
        $this->assertSame('retained', $registry->fetch('default', $reference)['data']);
        $hold->release($owner);
        $this->assertSame(1, $registry->deleteForNamespace('default'));
    }

    public function test_guard_also_protects_unregistered_backing_objects(): void
    {
        $driver = new GuardedExternalPayloadStorage(new RuntimeLocalExternalPayloadStorage($this->directory));
        $uri = $driver->put('orphan', hash('sha256', 'orphan'), 'avro');
        (new ExternalPayloadBackupHold)->acquire((string) Str::uuid(), 600);
        $this->assertFailure(fn () => $driver->delete($uri), ExternalPayloadBackupInProgress::class);
        $this->assertSame('orphan', $driver->get($uri));
    }

    public function test_operator_command_reports_failure_for_inactive_owner_and_invalid_input(): void
    {
        $owner = (string) Str::uuid();
        $this->artisan('external-payloads:backup-hold', ['action' => 'status'])
            ->expectsOutputToContain('"active":false')->assertSuccessful();
        $this->artisan('external-payloads:backup-hold', ['action' => 'acquire', '--owner' => $owner])
            ->expectsOutputToContain('"active":true')->assertSuccessful();
        $this->artisan('external-payloads:backup-hold', ['action' => 'release', '--owner' => $owner])->assertSuccessful();
        $this->artisan('external-payloads:backup-hold', ['action' => 'status', '--owner' => $owner])->assertFailed();
        $this->artisan('external-payloads:backup-hold', ['action' => 'acquire', '--owner' => (string) Str::uuid(), '--ttl' => '1.5'])->assertFailed();
        $this->artisan('external-payloads:backup-hold', ['action' => 'unknown'])->assertFailed();
    }

    private function assertFailure(Closure $callback, string $expected = RuntimeException::class): void
    {
        $caught = null;
        try {
            $callback();
        } catch (Throwable $exception) {
            $caught = $exception;
        }
        $this->assertInstanceOf($expected, $caught);
    }
}
