<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\RuntimeExternalPayloadException;
use App\Support\RuntimeExternalPayloadRegistry;
use App\Support\RuntimeExternalPayloadWriteClaims;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

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
}
