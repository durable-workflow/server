<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ServerBootstrapCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_runs_server_bootstrap_idempotently(): void
    {
        $this->artisan('server:bootstrap --force')
            ->assertExitCode(0);

        $this->assertSame(1, WorkflowNamespace::query()->where('name', 'default')->count());
        $this->assertTrue(Schema::hasTable('jobs'));

        $this->artisan('server:bootstrap --force')
            ->assertExitCode(0);

        $this->assertSame(1, WorkflowNamespace::query()->where('name', 'default')->count());
        $this->assertTrue(Schema::hasTable('jobs'));
    }

    public function test_it_creates_database_queue_storage_during_bootstrap(): void
    {
        Schema::drop('jobs');
        DB::table('migrations')
            ->where('migration', '0001_01_01_000006_create_jobs_table')
            ->delete();

        $this->assertFalse(Schema::hasTable('jobs'));

        $this->artisan('server:bootstrap --force')
            ->assertExitCode(0);

        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasColumns('jobs', [
            'id',
            'queue',
            'payload',
            'attempts',
            'reserved_at',
            'available_at',
            'created_at',
        ]));
    }

    public function test_it_resumes_when_schedule_history_table_exists_without_migration_record(): void
    {
        $migration = '2026_04_16_000180_create_workflow_schedule_history_events_table';

        $this->assertTrue(Schema::hasTable('workflow_schedule_history_events'));

        DB::table('migrations')
            ->where('migration', $migration)
            ->delete();

        $this->artisan('server:bootstrap --force')
            ->assertExitCode(0);

        $this->assertTrue(
            DB::table('migrations')
                ->where('migration', $migration)
                ->exists()
        );
    }
}
