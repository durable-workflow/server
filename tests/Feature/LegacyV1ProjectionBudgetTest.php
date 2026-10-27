<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\LegacyV1Projection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use JsonSerializable;
use LogicException;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\StructuralLimits;

class LegacyV1ProjectionBudgetTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    public function test_the_default_budgets_match_the_shared_completion_contract(): void
    {
        $this->assertSame(5000, StructuralLimits::historyTransactionSizeLimit());
        $this->assertSame(16 * 1024 * 1024, StructuralLimits::historyTransactionBytesLimit());
    }

    public function test_the_exact_event_limit_is_admitted_and_one_over_is_refused_before_queries(): void
    {
        $this->createNamespace('default');
        $source = $this->source();
        $source['logs'] = [[], 'not a row', null];
        $source['signals'] = [[]];
        $source['exceptions'] = [[]];
        config(['workflows.v2.structural_limits.history_transaction_size' => 4]);

        foreach ([false, true] as $dryRun) {
            $this->assertQueryFreeRefusal($source, 'history_transaction_size', 5, 4, $dryRun);
        }
        $this->assertDatabaseCount('workflow_runs', 0);
        $this->assertDatabaseCount('workflow_history_events', 0);

        config(['workflows.v2.structural_limits.history_transaction_size' => 5]);
        $report = LegacyV1Projection::import('budget-test', $source, 'default');
        $this->assertSame('projected', $report['status']);
        $this->assertDatabaseCount('workflow_history_events', 5);
        $this->assertDatabaseCount('workflow_tasks', 0);
    }

    public function test_count_refusal_precedes_source_serialization_and_event_building(): void
    {
        $source = $this->source();
        $source['arguments'] = $this->unserializable();
        $source['logs'] = array_fill(0, 10, []);
        config(['workflows.v2.structural_limits.history_transaction_size' => 2]);

        $this->assertQueryFreeRefusal($source, 'history_transaction_size', 12, 2);
    }

    public function test_the_exact_encoded_byte_limit_is_admitted_and_one_byte_over_is_refused(): void
    {
        $this->createNamespace('default');
        $source = $this->source();
        $source['arguments'] = "Unicode: 雪😀\u{2028}\u{2029}, slash: /, quote: \"";
        $source['logs'] = [['result' => 'a:1:{s:5:"value";i:42;}', 'created_at' => '2026-07-10T10:01:00+03:00']];
        config(['workflows.v2.structural_limits.history_transaction_bytes' => 0]);
        DB::beginTransaction();
        $initial = LegacyV1Projection::import('budget-test', $source, 'default');
        $runId = $initial['identity']['standalone']['run_id'];
        $bytes = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->get()->sum(
            static fn (WorkflowHistoryEvent $event): int => strlen($event->event_type->value)
                + strlen(json_encode($event->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        );
        $summaryBytes = (int) WorkflowRunSummary::query()->findOrFail($runId)->history_size_bytes;
        DB::rollBack();
        $this->assertSame($bytes, $summaryBytes);

        config(['workflows.v2.structural_limits.history_transaction_bytes' => $bytes - 1]);
        $this->assertQueryFreeRefusal($source, 'history_transaction_bytes', $bytes, $bytes - 1);
        $this->assertDatabaseCount('workflow_history_events', 0);

        config(['workflows.v2.structural_limits.history_transaction_bytes' => $bytes]);
        $exact = LegacyV1Projection::import('budget-test', $source, 'default');
        $this->assertSame('projected', $exact['status']);
        $this->assertSame($bytes, (int) WorkflowRunSummary::query()->findOrFail($exact['identity']['standalone']['run_id'])->history_size_bytes);
        $this->assertDatabaseCount('workflow_tasks', 0);
    }

    public function test_an_early_byte_refusal_does_not_serialize_later_source_payloads(): void
    {
        $source = $this->source();
        $source['arguments'] = str_repeat('x', 1000);
        $source['logs'] = [['result' => $this->unserializable()]];
        config(['workflows.v2.structural_limits.history_transaction_bytes' => 1]);

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $report = LegacyV1Projection::import('budget-test', $source, 'default');
        $this->assertSame('rejected', $report['status']);
        $this->assertSame('v1_projection_too_large', $report['reason']);
        $this->assertSame('history_transaction_bytes', $report['limit']['dimension']);
        $this->assertGreaterThan(1000, $report['limit']['used']);
        $this->assertSame([], $queries);
    }

    public function test_zero_disables_both_dimensions(): void
    {
        $this->createNamespace('default');
        config([
            'workflows.v2.structural_limits.history_transaction_size' => 0,
            'workflows.v2.structural_limits.history_transaction_bytes' => 0,
        ]);
        $source = $this->source();
        $source['logs'] = array_fill(0, 10, ['result' => str_repeat('x', 1000)]);

        $report = LegacyV1Projection::import('budget-test', $source, 'default');
        $this->assertSame('projected', $report['status']);
        $this->assertSame(12, WorkflowHistoryEvent::query()->count());
    }

    public function test_disabling_one_dimension_preserves_the_other_limit(): void
    {
        config([
            'workflows.v2.structural_limits.history_transaction_size' => 0,
            'workflows.v2.structural_limits.history_transaction_bytes' => 1,
        ]);
        $report = LegacyV1Projection::import('budget-test', $this->source(), 'default');
        $this->assertSame('rejected', $report['status']);
        $this->assertSame('history_transaction_bytes', $report['limit']['dimension']);

        config([
            'workflows.v2.structural_limits.history_transaction_size' => 1,
            'workflows.v2.structural_limits.history_transaction_bytes' => 0,
        ]);
        $this->assertQueryFreeRefusal($this->source(), 'history_transaction_size', 2, 1);
    }

    public function test_the_http_report_exposes_the_rejected_dimension_and_zero_writes(): void
    {
        $this->createNamespace('default');
        config(['workflows.v2.structural_limits.history_transaction_size' => 1]);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/import/waterline-v1', [
                'source_id' => 'budget-test',
                'workflow' => $this->source(),
            ])
            ->assertConflict()
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('reason', 'v1_projection_too_large')
            ->assertJsonPath('limit', ['dimension' => 'history_transaction_size', 'used' => 2, 'limit' => 1]);

        foreach (['workflow_instances', 'workflow_runs', 'workflow_history_events', 'workflow_run_summaries', 'workflow_tasks'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    private function assertQueryFreeRefusal(array $source, string $dimension, int $used, int $limit, bool $dryRun = false): void
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $report = LegacyV1Projection::import('budget-test', $source, 'default', $dryRun);
        $this->assertSame('rejected', $report['status']);
        $this->assertSame('v1_projection_too_large', $report['reason']);
        $this->assertSame(compact('dimension', 'used', 'limit'), $report['limit']);
        $this->assertSame([], $queries);
    }

    private function source(): array
    {
        return [
            'id' => 'v1:42',
            'engine_source' => 'v1',
            'class' => 'LegacyOrderWorkflow',
            'status' => 'completed',
            'created_at' => '2026-07-10T10:00:00Z',
            'updated_at' => '2026-07-10T10:05:00Z',
        ];
    }

    private function unserializable(): JsonSerializable
    {
        return new class implements JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                throw new LogicException('A later source value must not be serialized after a budget refusal.');
            }
        };
    }
}
