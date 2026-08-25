<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\MemoPayload;
use Workflow\V2\Support\MemoUpsertService;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\UpsertMemosCall;

final class WorkflowMemoRollingCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_predecessor_and_successor_read_write_and_continue_one_logical_value(): void
    {
        $run = $this->createRun('memo-rolling-parent', 1);
        $service = new MemoUpsertService;
        $envelopeLookingValue = [
            'codec' => 'avro',
            'blob' => MemoPayload::envelope('business value')['blob'],
        ];

        $service->upsert($run, new UpsertMemosCall([
            'scalar' => 'first',
            'list' => ['one', 2, 3.5],
            'map' => ['stage' => 'ready'],
            'float' => 7.0,
            'envelope_looking' => $envelopeLookingValue,
        ]), 1);

        $row = WorkflowMemo::query()
            ->where('workflow_run_id', $run->id)
            ->where('key', 'scalar')
            ->firstOrFail();
        $stored = DB::table('workflow_memos')->where('id', $row->id)->first();

        $this->assertSame(
            'first',
            json_decode((string) $stored->value, true, flags: JSON_THROW_ON_ERROR),
        );
        $this->assertSame(1, (int) $stored->portable_value_sequence);
        $this->assertIsFloat(json_decode((string) DB::table('workflow_memos')
            ->where('workflow_run_id', $run->id)
            ->where('key', 'float')
            ->value('value')));

        // The predecessor updates only the compatibility value and workflow
        // history sequence. The successor must reject the now-stale payload.
        DB::table('workflow_memos')
            ->where('id', $row->id)
            ->update([
                'value' => json_encode('written-by-predecessor', JSON_THROW_ON_ERROR),
                'upserted_at_sequence' => 2,
            ]);

        $this->assertSame(
            'written-by-predecessor',
            $run->fresh()->typedMemos()['scalar'],
        );
        $this->assertSame(
            'written-by-predecessor',
            RunDetailView::forRun($run->fresh())['memo']['scalar'],
        );

        DB::table('workflow_memos')->insert([
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'key' => 'created_by_predecessor',
            'value' => json_encode($envelopeLookingValue, JSON_THROW_ON_ERROR),
            'portable_value' => null,
            'portable_value_sequence' => null,
            'upserted_at_sequence' => 2,
            'inherited_from_parent' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertEquals(
            $envelopeLookingValue,
            $run->fresh()->typedMemos()['created_by_predecessor'],
        );

        $service->upsert($run, new UpsertMemosCall([
            'scalar' => 'written-by-successor',
        ]), 3);

        $stored = DB::table('workflow_memos')->where('id', $row->id)->first();
        $this->assertSame(
            'written-by-successor',
            json_decode((string) $stored->value, true, flags: JSON_THROW_ON_ERROR),
        );
        $this->assertSame(3, (int) $stored->portable_value_sequence);
        $this->assertSame(
            'written-by-successor',
            WorkflowMemo::query()->findOrFail($row->id)->getValue(),
        );

        $child = $this->createRun('memo-rolling-child', 2);
        $service->inheritFromParent($run, $child, 1);

        $this->assertSame($run->fresh()->typedMemos(), $child->fresh()->typedMemos());
        $this->assertEquals(
            $envelopeLookingValue,
            $child->fresh()->typedMemos()['envelope_looking'],
        );
    }

    private function createRun(string $instanceId, int $runNumber): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'namespace' => 'default',
            'workflow_type' => 'MemoRollingWorkflow',
            'workflow_class' => 'Tests\\MemoRollingWorkflow',
        ]);

        return WorkflowRun::query()->create([
            'id' => $instanceId.'-run',
            'workflow_instance_id' => $instance->id,
            'namespace' => 'default',
            'run_number' => $runNumber,
            'workflow_class' => 'Tests\\MemoRollingWorkflow',
            'workflow_type' => 'MemoRollingWorkflow',
            'status' => 'running',
        ]);
    }
}
