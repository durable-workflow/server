<?php

namespace Tests\Feature;

use App\Models\RuntimeExternalPayload;
use App\Models\WorkflowNamespace;
use App\Support\NamespaceExternalPayloadStorage;
use App\Support\RuntimeExternalPayloadException;
use App\Support\RuntimeExternalPayloadRegistry;
use App\Support\RuntimeExternalPayloadWriteClaims;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Workflow\Serializers\Serializer;

class RuntimeExternalPayloadWriteClaimsTest extends TestCase
{
    use DatabaseMigrations;

    public function test_a_failed_final_claim_rolls_back_the_writer_and_discards_its_commit_callbacks(): void
    {
        $claims = app(RuntimeExternalPayloadWriteClaims::class);
        $committed = false;
        try {
            $claims->transport(function () use ($claims, &$committed): void {
                $claims->verified('default', 'fixture://already-pruned', 1, hash('sha256', 'x'));
                DB::transaction(function () use (&$committed): void {
                    WorkflowNamespace::query()->create(['name' => 'uncommitted-reference', 'retention_days' => 30]);
                    DB::afterCommit(static function () use (&$committed): void {
                        $committed = true;
                    });
                });
            });
            $this->fail('A writer must not commit after cleanup removed its reference.');
        } catch (RuntimeExternalPayloadException) {
            $this->assertFalse($committed);
            $this->assertSame(0, DB::connection()->transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertFalse(WorkflowNamespace::query()->where('name', 'uncommitted-reference')->exists());
        }

        // Failed-request state cannot poison the next request or transaction.
        DB::transaction(fn () => WorkflowNamespace::query()->create(['name' => 'next-request', 'retention_days' => 30]));
        $this->assertTrue(WorkflowNamespace::query()->where('name', 'next-request')->exists());
    }

    public function test_preparation_commits_do_not_take_claims_but_the_reference_writer_does(): void
    {
        $registry = Mockery::mock(RuntimeExternalPayloadRegistry::class);
        $registry->shouldReceive('claimVerified')->once()->with('default', 'fixture://verified', 1, hash('sha256', 'x'))
            ->andReturnUsing(function (): void {
                $this->assertSame(1, DB::connection()->transactionLevel());
                $this->assertTrue(WorkflowNamespace::query()->where('name', 'reference-writer')->exists());
            });
        $this->app->instance(RuntimeExternalPayloadRegistry::class, $registry);
        $claims = app(RuntimeExternalPayloadWriteClaims::class);
        $claims->transport(function () use ($claims): void {
            $claims->verified('default', 'fixture://verified', 1, hash('sha256', 'x'));
            $claims->prepare(fn () => DB::transaction(fn () => WorkflowNamespace::query()->count()));
            DB::transaction(fn () => WorkflowNamespace::query()->create(['name' => 'reference-writer', 'retention_days' => 30]));
        });
        $this->assertTrue(WorkflowNamespace::query()->where('name', 'reference-writer')->exists());
    }

    public function test_temporary_deletion_keeps_other_objects_and_namespaces_fenced_until_their_writer_commits(): void
    {
        $registry = Mockery::mock(RuntimeExternalPayloadRegistry::class);
        $sha256 = hash('sha256', 'x');
        $registry->shouldReceive('claimVerified')->once()->with('billing', 'fixture://writer', 1, $sha256)->andReturnNull();
        $registry->shouldReceive('claimVerified')->once()->with('foreign', 'fixture://temporary', 1, $sha256)->andReturnNull();
        $this->app->instance(RuntimeExternalPayloadRegistry::class, $registry);
        $claims = app(RuntimeExternalPayloadWriteClaims::class);
        $claims->transport(function () use ($claims, $sha256): void {
            $claims->verified('billing', 'fixture://temporary', 1, $sha256);
            $claims->verified('foreign', 'fixture://temporary', 1, $sha256);
            $claims->verified('billing', 'fixture://writer', 1, $sha256);
            $claims->deleteTemporary('billing', 'fixture://temporary', function (): void {
                DB::transaction(fn () => WorkflowNamespace::query()->count());
            });
            DB::transaction(fn () => WorkflowNamespace::query()->create(['name' => 'reference-writer', 'retention_days' => 30]));
        });
        $this->assertTrue(WorkflowNamespace::query()->where('name', 'reference-writer')->exists());
    }

    public function test_a_failed_temporary_deletion_preserves_the_reference_claim(): void
    {
        $registry = Mockery::mock(RuntimeExternalPayloadRegistry::class);
        $registry->shouldReceive('claimVerified')->once()->with('default', 'fixture://retained', 1, hash('sha256', 'x'))->andReturnNull();
        $this->app->instance(RuntimeExternalPayloadRegistry::class, $registry);
        $claims = app(RuntimeExternalPayloadWriteClaims::class);
        $claims->transport(function () use ($claims): void {
            $claims->verified('default', 'fixture://retained', 1, hash('sha256', 'x'));
            try {
                $claims->deleteTemporary('default', 'fixture://retained', static function (): void {
                    throw new RuntimeException('Provider deletion failed.');
                });
                $this->fail('A failed deletion must propagate.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Provider deletion failed.', $exception->getMessage());
            }
            DB::transaction(fn () => WorkflowNamespace::query()->create(['name' => 'retained-reference', 'retention_days' => 30]));
        });
        $this->assertTrue(WorkflowNamespace::query()->where('name', 'retained-reference')->exists());
    }

    public function test_an_earlier_commit_does_not_consume_a_later_writers_reference_claim(): void
    {
        $registry = Mockery::mock(RuntimeExternalPayloadRegistry::class);
        $registry->shouldReceive('claimVerified')->once()->ordered()->andReturnNull();
        $registry->shouldReceive('claimVerified')->once()->ordered()->andThrow(new RuntimeExternalPayloadException(
            'external_payload_not_found', 404, false, 'Cleanup won before the actual reference writer.',
        ));
        $this->app->instance(RuntimeExternalPayloadRegistry::class, $registry);
        $claims = app(RuntimeExternalPayloadWriteClaims::class);
        try {
            $claims->transport(function () use ($claims): void {
                $claims->verified('default', 'fixture://verified', 1, hash('sha256', 'x'));
                DB::transaction(fn () => WorkflowNamespace::query()->create(['name' => 'earlier-metadata', 'retention_days' => 30]));
                DB::transaction(fn () => WorkflowNamespace::query()->create(['name' => 'later-reference', 'retention_days' => 30]));
            });
            $this->fail('Every later writer must recheck the prepared reference.');
        } catch (RuntimeExternalPayloadException) {
            $this->assertTrue(WorkflowNamespace::query()->where('name', 'earlier-metadata')->exists());
            $this->assertFalse(WorkflowNamespace::query()->where('name', 'later-reference')->exists());
            $this->assertSame(0, DB::connection()->transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        }
    }

    public function test_savepoint_rollback_drops_only_its_own_claims_and_the_final_batch_uses_bucket_order(): void
    {
        $seen = [];
        $registry = Mockery::mock(RuntimeExternalPayloadRegistry::class);
        $registry->shouldReceive('claimVerified')->twice()->andReturnUsing(static function ($namespace, $uri) use (&$seen): void {
            $seen[] = $uri;
        });
        $this->app->instance(RuntimeExternalPayloadRegistry::class, $registry);
        $claims = app(RuntimeExternalPayloadWriteClaims::class);
        $claims->transport(function () use ($claims): void {
            // The first URI hashes to lock bucket255; the second to bucket0.
            $claims->verified('default', 'fixture://guard-163', 1, hash('sha256', 'x'));
            DB::transaction(function () use ($claims): void {
                DB::transaction(fn () => $claims->verified('default', 'fixture://guard-78', 1, hash('sha256', 'x')));
                try {
                    DB::transaction(function () use ($claims): void {
                        $claims->verified('default', 'fixture://rolled-back-registration', 1, hash('sha256', 'x'));
                        throw new RuntimeException('Rollback only this savepoint.');
                    });
                } catch (RuntimeException) {
                    // The previous sibling's committed registration is now
                    // owned by the outer transaction and must still be claimed.
                }
                WorkflowNamespace::query()->create(['name' => 'savepoint-writer', 'retention_days' => 30]);
            });
        });
        $this->assertSame(['fixture://guard-78', 'fixture://guard-163'], $seen);
    }

    public function test_preflight_and_a_failed_writer_leave_the_verified_upload_unclaimed(): void
    {
        $directory = storage_path('framework/testing/write-claims-'.bin2hex(random_bytes(4)));
        try {
            WorkflowNamespace::query()->create([
                'name' => 'claim-preflight', 'retention_days' => 30,
                'external_payload_storage' => [
                    'enabled' => true, 'driver' => 'local', 'threshold_bytes' => 1,
                    'config' => ['uri' => 'file://'.$directory],
                ],
            ]);
            $bytes = Serializer::serializeWithCodec('avro', ['verified before mutation']);
            $registry = app(RuntimeExternalPayloadRegistry::class);
            $reference = $registry->upload('claim-preflight', $bytes, 'avro', hash('sha256', $bytes));
            $row = RuntimeExternalPayload::query()->findOrFail($reference['reference_id']);
            $envelope = $registry->resolveAndClaim('claim-preflight', $reference);
            $storage = app(NamespaceExternalPayloadStorage::class)->driverFor('claim-preflight');
            app(RuntimeExternalPayloadWriteClaims::class)->transport(function () use ($storage, $row, $envelope, $bytes): void {
                $this->assertSame($bytes, $storage->get($row->storage_uri));
                $storage->retainEnvelope($envelope);
                $this->assertNull($row->fresh()->retained_at, 'Verification must not commit a claim.');
                try {
                    DB::transaction(function (): void {
                        WorkflowNamespace::query()->create(['name' => 'failed-preflight-writer', 'retention_days' => 30]);
                        throw new RuntimeException('The reference writer rolled back.');
                    });
                } catch (RuntimeException) {
                    $this->assertNull($row->fresh()->retained_at);
                    $this->assertNotNull($row->fresh()->expires_at);
                }
            });
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
