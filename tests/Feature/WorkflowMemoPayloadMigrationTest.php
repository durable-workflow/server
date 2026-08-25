<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Workflow\V2\Support\MemoPayload;

class WorkflowMemoPayloadMigrationTest extends TestCase
{
    public function test_existing_json_memos_round_trip_through_the_portable_payload_migration(): void
    {
        $originalConnection = DB::getDefaultConnection();
        $connection = 'memo-payload-migration-test';

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

        $legacyValues = [
            'string' => 'legacy memo',
            'map' => ['stage' => 'waiting', 'attempt' => 3],
            'envelope-looking business value' => ['codec' => 'avro', 'blob' => 'customer data'],
        ];

        try {
            Schema::create('workflow_memos', function (Blueprint $table): void {
                $table->id();
                $table->string('key', 191);
                $table->json('value');
            });

            foreach ($legacyValues as $key => $value) {
                DB::table('workflow_memos')->insert([
                    'key' => $key,
                    'value' => json_encode($value, JSON_THROW_ON_ERROR),
                ]);
            }

            $migration = require database_path(
                'migrations/2026_08_25_000100_encode_workflow_memos_for_portable_runtime.php',
            );
            $migration->up();

            $portable = DB::table('workflow_memos')->orderBy('id')->get();
            foreach ($portable as $index => $row) {
                $envelope = json_decode((string) $row->value, true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('avro', $envelope['codec'] ?? null);
                $this->assertEquals(
                    array_values($legacyValues)[$index],
                    MemoPayload::decode($envelope),
                );
            }

            $migration->down();

            $this->assertEquals(
                array_values($legacyValues),
                DB::table('workflow_memos')
                    ->orderBy('id')
                    ->pluck('value')
                    ->map(static fn (string $value): mixed => json_decode(
                        $value,
                        true,
                        flags: JSON_THROW_ON_ERROR,
                    ))
                    ->all(),
            );
        } finally {
            DB::setDefaultConnection($originalConnection);
            DB::purge($connection);
        }
    }
}
