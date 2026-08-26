<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\WorkflowMemoPayloadMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;
use Workflow\V2\Support\MemoPayload;

class WorkflowMemoPayloadMigrationTest extends TestCase
{
    private string $originalConnection;

    private string $connection = 'memo-payload-migration-test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();
        config([
            "database.connections.{$this->connection}" => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        DB::purge($this->connection);
        DB::setDefaultConnection($this->connection);

        Schema::create('workflow_memos', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 191);
            $table->json('value');
            $table->unsignedInteger('upserted_at_sequence');
        });
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalConnection);
        DB::purge($this->connection);

        parent::tearDown();
    }

    public function test_interrupted_backfill_resumes_without_double_encoding(): void
    {
        $envelopeLookingValue = [
            'codec' => 'avro',
            'blob' => MemoPayload::envelope('unrelated business value')['blob'],
        ];
        $legacyValues = [
            'scalar' => 'legacy memo',
            'list' => ['first', 2, 3.5],
            'map' => ['stage' => 'waiting', 'attempt' => 3],
            'float' => 7.0,
            'envelope-looking business value' => $envelopeLookingValue,
        ];

        $this->insertValues($legacyValues);
        WorkflowMemoPayloadMigration::ensureExpandedSchema();

        $this->assertSame(2, WorkflowMemoPayloadMigration::backfillBatch(2));
        $this->assertSame(
            2,
            DB::table('workflow_memos')->whereNotNull('portable_value_sequence')->count(),
        );

        WorkflowMemoPayloadMigration::backfillAll();
        $once = DB::table('workflow_memos')->orderBy('id')->pluck('portable_value')->all();

        WorkflowMemoPayloadMigration::backfillAll();
        $this->assertSame(
            $once,
            DB::table('workflow_memos')->orderBy('id')->pluck('portable_value')->all(),
        );
        $this->assertLogicalValues($legacyValues);
        $this->assertIsFloat(json_decode((string) DB::table('workflow_memos')
            ->where('key', 'float')
            ->value('value')));
    }

    public function test_published_envelope_migration_is_recovered_into_the_expand_representation(): void
    {
        Schema::create('migrations', function (Blueprint $table): void {
            $table->id();
            $table->string('migration');
            $table->unsignedInteger('batch');
        });
        DB::table('migrations')->insert([
            'migration' => '2026_08_25_000100_encode_workflow_memos_for_portable_runtime',
            'batch' => 1,
        ]);

        $logicalValues = [
            'scalar' => 'already converted',
            'envelope-looking business value' => [
                'codec' => 'avro',
                'blob' => MemoPayload::envelope('business bytes')['blob'],
            ],
        ];
        $storedValues = array_map(MemoPayload::envelope(...), $logicalValues);
        $this->insertValues($storedValues);

        $migration = require database_path(
            'migrations/2026_08_25_000040_expand_workflow_memo_payload_storage.php',
        );
        $migration->up();

        $this->assertLogicalValues($logicalValues);
    }

    public function test_unrecorded_partial_published_migration_recovers_only_the_confirmed_id_prefix(): void
    {
        $convertedEnvelopeLookingValue = [
            'codec' => 'avro',
            'blob' => MemoPayload::envelope('converted business bytes')['blob'],
        ];
        $rawEnvelopeLookingValue = [
            'codec' => 'avro',
            'blob' => MemoPayload::envelope('raw business bytes')['blob'],
        ];
        $logicalValues = [
            'converted scalar' => 'already converted',
            'converted list' => ['first', 2, 3.5],
            'converted envelope-looking business value' => $convertedEnvelopeLookingValue,
            'raw map' => ['stage' => 'waiting', 'attempt' => 3],
            'raw float' => 7.0,
            'raw envelope-looking business value' => $rawEnvelopeLookingValue,
        ];
        $this->insertValues($logicalValues);

        $convertedRows = DB::table('workflow_memos')->orderBy('id')->limit(3)->get();
        foreach ($convertedRows as $row) {
            $value = json_decode((string) $row->value, true, flags: JSON_THROW_ON_ERROR);
            DB::table('workflow_memos')->where('id', $row->id)->update([
                'value' => json_encode(MemoPayload::envelope($value), JSON_THROW_ON_ERROR),
            ]);
        }

        $lastConvertedId = (int) $convertedRows->last()->id;
        WorkflowMemoPayloadMigration::ensureExpandedSchema();
        WorkflowMemoPayloadMigration::backfillAll($lastConvertedId);

        $once = DB::table('workflow_memos')
            ->orderBy('id')
            ->get(['value', 'portable_value', 'portable_value_sequence'])
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        WorkflowMemoPayloadMigration::backfillAll($lastConvertedId);

        $this->assertSame(
            $once,
            DB::table('workflow_memos')
                ->orderBy('id')
                ->get(['value', 'portable_value', 'portable_value_sequence'])
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
        );
        $this->assertLogicalValues($logicalValues);
    }

    public function test_malformed_row_fails_with_bounded_content_free_diagnostic_and_can_retry(): void
    {
        DB::table('workflow_memos')->insert([
            'key' => 'secret-customer-memo',
            'value' => '{malformed-secret-value',
            'upserted_at_sequence' => 9,
        ]);
        $id = (int) DB::table('workflow_memos')->value('id');
        WorkflowMemoPayloadMigration::ensureExpandedSchema();

        try {
            WorkflowMemoPayloadMigration::backfillAll();
            $this->fail('The malformed memo row should fail the migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("row id {$id}", $exception->getMessage());
            $this->assertStringNotContainsString('malformed-secret-value', $exception->getMessage());
        }

        $this->assertNull(
            DB::table('workflow_memos')->where('id', $id)->value('portable_value_sequence'),
        );

        DB::table('workflow_memos')->where('id', $id)->update([
            'value' => json_encode('repaired', JSON_THROW_ON_ERROR),
        ]);
        WorkflowMemoPayloadMigration::backfillAll();

        $this->assertLogicalValues(['secret-customer-memo' => 'repaired']);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function insertValues(array $values): void
    {
        foreach ($values as $key => $value) {
            DB::table('workflow_memos')->insert([
                'key' => $key,
                'value' => json_encode(
                    $value,
                    JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
                ),
                'upserted_at_sequence' => 1,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function assertLogicalValues(array $expected): void
    {
        $rows = DB::table('workflow_memos')->orderBy('id')->get();

        foreach ($rows as $index => $row) {
            $projection = json_decode((string) $row->value, true, flags: JSON_THROW_ON_ERROR);
            $payload = json_decode((string) $row->portable_value, true, flags: JSON_THROW_ON_ERROR);
            $logical = array_values($expected)[$index];

            $this->assertEquals($logical, $projection);
            $this->assertEquals($logical, MemoPayload::decode($payload));
            $this->assertSame(
                (int) $row->upserted_at_sequence,
                (int) $row->portable_value_sequence,
            );
        }
    }
}
