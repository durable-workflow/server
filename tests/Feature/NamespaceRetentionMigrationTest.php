<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NamespaceRetentionMigrationTest extends TestCase
{
    public function test_existing_namespace_days_migrate_to_bounded_retention_unchanged(): void
    {
        $originalConnection = DB::getDefaultConnection();
        $connection = 'retention-migration-test';

        config([
            "database.connections.{$connection}" => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        DB::purge($connection);
        DB::setDefaultConnection($connection);

        try {
            Schema::create('workflow_namespaces', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 128)->unique();
                $table->string('description', 1000)->nullable();
                $table->unsignedInteger('retention_days')->default(30);
                $table->string('status', 32)->default('active');
                $table->timestamps();
            });

            DB::table('workflow_namespaces')->insert([
                [
                    'name' => 'default',
                    'retention_days' => 30,
                    'status' => 'active',
                ],
                [
                    'name' => 'long-lived',
                    'retention_days' => 365,
                    'status' => 'active',
                ],
            ]);

            $migration = require database_path(
                'migrations/2026_07_28_000100_add_retention_mode_to_workflow_namespaces.php',
            );
            $migration->up();

            $this->assertSame(
                [
                    ['name' => 'default', 'retention_mode' => 'bounded', 'retention_days' => 30],
                    ['name' => 'long-lived', 'retention_mode' => 'bounded', 'retention_days' => 365],
                ],
                DB::table('workflow_namespaces')
                    ->orderBy('id')
                    ->get(['name', 'retention_mode', 'retention_days'])
                    ->map(static fn (object $row): array => (array) $row)
                    ->all(),
            );

            DB::table('workflow_namespaces')
                ->where('name', 'long-lived')
                ->update([
                    'retention_mode' => 'forever',
                    'retention_days' => null,
                ]);

            $this->assertNull(
                DB::table('workflow_namespaces')
                    ->where('name', 'long-lived')
                    ->value('retention_days'),
            );

            $migration->down();

            $this->assertFalse(Schema::hasColumn('workflow_namespaces', 'retention_mode'));
            $this->assertSame(
                30,
                DB::table('workflow_namespaces')
                    ->where('name', 'long-lived')
                    ->value('retention_days'),
            );
        } finally {
            DB::setDefaultConnection($originalConnection);
            DB::purge($connection);
        }
    }
}
