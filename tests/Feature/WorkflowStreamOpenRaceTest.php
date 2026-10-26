<?php

namespace Tests\Feature;

use App\Models\WorkflowDurableStream;
use App\Models\WorkflowDurableStreamItem;
use App\Support\WorkflowStreamService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ForkedDatabaseActors;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowRun;

class WorkflowStreamOpenRaceTest extends TestCase
{
    use ForkedDatabaseActors;

    public static function innodbDrivers(): array
    {
        return ['mysql' => ['mysql'], 'mariadb' => ['mariadb']];
    }

    #[DataProvider('innodbDrivers')]
    public function test_first_appends_to_different_runs_both_commit_after_both_missing_reads(string $driver): void
    {
        $this->initializeActorDatabase($driver);
        $firstRun = $this->createRun('wf-open-first');
        $secondRun = $this->createRun('wf-open-second');

        try {
            $first = $this->startActor(fn (): int => $this->append($firstRun), self::missingRead(...));
            $this->runUntilPaused($first);
            $second = $this->startActor(fn (): int => $this->append($secondRun), self::missingRead(...));
            $this->runUntilPaused($second);

            // Both reads finish before either insert. Missing-key FOR UPDATE
            // reads hold compatible gap locks; the two inserts then deadlock.
            $this->resume($first);
            $this->resume($second);
            $this->assertSame(1, $this->finishActor($first));
            $this->assertSame(1, $this->finishActor($second));
        } finally {
            DB::purge();
            DB::reconnect();
        }

        $this->assertSame(2, WorkflowDurableStream::query()->count());
        $this->assertSame(2, WorkflowDurableStreamItem::query()->count());
    }

    public static function staleSnapshots(): array
    {
        $cases = [];
        foreach (['mysql', 'mariadb'] as $driver) {
            foreach (['unlimited' => null, 'full' => 1, 'over-limit' => 0] as $name => $limit) {
                $cases[$driver.' '.$name] = [$driver, $limit];
            }
        }

        return $cases;
    }

    #[DataProvider('staleSnapshots')]
    public function test_an_older_command_snapshot_uses_the_stream_committed_before_its_run_lock(string $driver, ?int $limit): void
    {
        $this->initializeActorDatabase($driver);
        $runId = $this->createRun('wf-open-snapshot');
        config(['server.namespace_durable_state.overrides' => [
            'default' => ['max_workflow_streams' => $limit],
        ]]);

        try {
            $older = $this->startActor(fn (): int => DB::transaction(function () use ($runId): int {
                // A command transaction may have established its repeatable
                // snapshot before it takes the stream's run lock.
                $this->assertSame(0, WorkflowDurableStream::query()->count());

                return $this->append($runId);
            }), self::missingRead(...));
            $this->runUntilPaused($older);
            $opener = $this->startActor(function () use ($runId): int {
                // A lower quota may be deployed while an existing stream is
                // still writable. Its append must not require another slot.
                config(['server.namespace_durable_state.overrides' => []]);

                return $this->append($runId);
            });
            $this->release($opener);
            $this->assertSame(1, $this->finishActor($opener));
            $this->resume($older);
            $this->assertSame(1, $this->finishActor($older));
        } finally {
            DB::purge();
            DB::reconnect();
        }

        $this->assertSame(1, WorkflowDurableStream::query()->count());
        $this->assertSame([0, 1], WorkflowDurableStreamItem::query()->orderBy('offset')->pluck('offset')->map(intval(...))->all());
        $this->assertSame(2, (int) WorkflowDurableStream::query()->value('pending_items'));
    }

    private static function missingRead(string $sql, string $driver, int $level): bool
    {
        return str_starts_with($sql, 'select') && str_contains($sql, 'workflow_durable_streams')
            && ! str_contains($sql, 'workflow_durable_stream_items');
    }

    private function append(string $runId): int
    {
        return app(WorkflowStreamService::class)->append(
            WorkflowRun::query()->findOrFail($runId), 'default', 'tokens', [['payload' => 'item']],
        )['accepted'];
    }
}
