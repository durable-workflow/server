<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\MigrationAdoption;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationAdoptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_adopts_workflow_package_migration_when_table_exists_without_record(): void
    {
        $migration = '2026_04_16_000180_create_workflow_schedule_history_events_table';

        $this->assertTrue(Schema::hasTable('workflow_schedule_history_events'));

        DB::table('migrations')->where('migration', $migration)->delete();

        $adopted = (new MigrationAdoption($this->app->make(Migrator::class)))->adopt();

        $this->assertContains($migration, $adopted);
        $this->assertTrue(
            DB::table('migrations')->where('migration', $migration)->exists()
        );
    }

    public function test_does_not_adopt_migrations_that_already_have_records(): void
    {
        $adopted = (new MigrationAdoption($this->app->make(Migrator::class)))->adopt();

        $this->assertSame([], $adopted);
    }

    public function test_does_not_adopt_when_target_table_is_missing(): void
    {
        $migration = '2026_04_16_000180_create_workflow_schedule_history_events_table';

        Schema::drop('workflow_schedule_history_events');
        DB::table('migrations')->where('migration', $migration)->delete();

        $adopted = (new MigrationAdoption($this->app->make(Migrator::class)))->adopt();

        $this->assertNotContains($migration, $adopted);
        $this->assertFalse(
            DB::table('migrations')->where('migration', $migration)->exists()
        );
    }

    public function test_skips_alter_style_migrations_with_no_create_table(): void
    {
        $migration = '2026_04_21_000300_add_workflow_definition_fingerprints_to_worker_registrations';

        $this->assertTrue(Schema::hasColumn('workflow_worker_registrations', 'workflow_definition_fingerprints'));

        DB::table('migrations')->where('migration', $migration)->delete();

        $adopted = (new MigrationAdoption($this->app->make(Migrator::class)))->adopt();

        $this->assertNotContains($migration, $adopted);
    }

    public function test_creates_migrations_table_when_missing(): void
    {
        Schema::drop('migrations');

        $this->assertFalse(Schema::hasTable('migrations'));

        (new MigrationAdoption($this->app->make(Migrator::class)))->adopt();

        $this->assertTrue(Schema::hasTable('migrations'));
    }

    public function test_adopts_only_create_migrations_with_all_target_tables_present(): void
    {
        Schema::create('adoption_synthetic_one', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('adoption_synthetic_two', function (Blueprint $table): void {
            $table->id();
        });

        $fixture = sys_get_temp_dir() . '/adoption-' . uniqid();
        mkdir($fixture);

        file_put_contents(
            $fixture . '/2099_01_01_000001_create_synthetic_tables.php',
            '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { '
            . 'public function up(): void { \\Illuminate\\Support\\Facades\\Schema::create(\'adoption_synthetic_one\', fn ($t) => $t->id()); '
            . '\\Illuminate\\Support\\Facades\\Schema::create(\'adoption_synthetic_two\', fn ($t) => $t->id()); } '
            . 'public function down(): void {} };'
        );

        $migrator = $this->app->make(Migrator::class);
        $migrator->path($fixture);

        try {
            $adopted = (new MigrationAdoption($migrator))->adopt();
        } finally {
            unlink($fixture . '/2099_01_01_000001_create_synthetic_tables.php');
            rmdir($fixture);
        }

        $this->assertContains('2099_01_01_000001_create_synthetic_tables', $adopted);
    }
}
