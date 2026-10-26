<?php

namespace Tests\Feature;

use App\Models\WorkflowDurableStream;
use App\Models\WorkflowDurableStreamItem;
use App\Support\StreamFullException;
use App\Support\WorkflowStreamRetention;
use App\Support\WorkflowStreamService;
use Closure;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ForkedDatabaseActors;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowRun;

/**
 * Two near-cap appends to one stream on two real database connections
 * (Server #774), on MySQL, MariaDB, PostgreSQL and file-backed SQLite. The
 * first is paused inside its transaction, holding the run's stream lock,
 * after it opens or locks the stream row; the second must wait for it, then
 * decide its whole batch against the committed stream. Accepted items never
 * exceed the cap, and a refused batch writes nothing.
 *
 * MySQL, MariaDB and PostgreSQL show the second append waiting on a row
 * lock. SQLite has no row locks: its second append waits for the database
 * write lock, which shows as it not finishing while the first is paused.
 */
class WorkflowStreamCapacityRaceTest extends TestCase
{
    use ForkedDatabaseActors;

    private const CAP = 5;

    public static function serverDrivers(): array
    {
        return ['mysql' => ['mysql'], 'mariadb' => ['mariadb'], 'pgsql' => ['pgsql']];
    }

    #[DataProvider('drivers')]
    public function test_concurrent_first_appends_open_one_stream_and_never_exceed_the_cap(string $driver): void
    {
        $this->initializeActorDatabase($driver);
        $runId = $this->createRun('wf-stream-race-open');

        // The first append pauses after inserting the stream row, before it
        // commits; the second append's insert waits on that row.
        [$first, $second] = $this->race($runId, pause: 'insert', firstItems: 3, secondItems: 3);

        $this->assertSame(['outcome' => 'accepted', 'accepted' => 3], $first);
        $this->assertSame(['outcome' => 'full'], $second);
        $this->assertSame(1, WorkflowDurableStream::query()->where('workflow_run_id', $runId)->count());
        $this->assertSame(3, WorkflowDurableStreamItem::query()->where('workflow_run_id', $runId)->count());
        $this->assertSame(3, (int) WorkflowDurableStream::query()->where('workflow_run_id', $runId)->value('pending_items'));
    }

    public static function contended(): array
    {
        $cases = [];
        foreach (array_keys(self::drivers()) as $driver) {
            // 1 stored + 3 + 3 would pass the cap: the second batch is refused whole.
            $cases[$driver.' over'] = [$driver, 3, ['outcome' => 'full'], 4];
            // 1 stored + 3 + 1 meets the cap exactly: the second batch is admitted.
            $cases[$driver.' at'] = [$driver, 1, ['outcome' => 'accepted', 'accepted' => 1], 5];
        }

        return $cases;
    }

    #[DataProvider('contended')]
    public function test_contended_appends_decide_capacity_against_the_committed_stream(string $driver, int $secondItems, array $expected, int $total): void
    {
        $this->initializeActorDatabase($driver);
        $runId = $this->createRun('wf-stream-race-locked');
        $run = WorkflowRun::query()->findOrFail($runId);
        app(WorkflowStreamService::class)->append($run, 'default', 'tokens', [['payload' => 0]], self::CAP);

        // The first append pauses holding the stream row's lock; the second
        // waits for it.
        [$first, $second] = $this->race($runId, pause: 'lock', firstItems: 3, secondItems: $secondItems);

        $this->assertSame(['outcome' => 'accepted', 'accepted' => 3], $first);
        $this->assertSame($expected, $second);
        $this->assertSame($total, WorkflowDurableStreamItem::query()->where('workflow_run_id', $runId)->count());
        $this->assertSame($total, (int) WorkflowDurableStream::query()->where('workflow_run_id', $runId)->value('pending_items'));
        $this->assertSame(range(0, $total - 1), WorkflowDurableStreamItem::query()->where('workflow_run_id', $runId)
            ->orderBy('offset')->pluck('offset')->map(static fn ($offset): int => (int) $offset)->all());
    }

    /**
     * The retention pass reads a namespace's due streams from the
     * (namespace, reclaim_at, id) index: a range scan in deadline order with
     * no sort, which stops at the limit. Each namespace here mostly holds
     * rows the pass must not read: open streams and reclaimed terminal rows
     * (no deadline) and streams not yet due. MySQL and MariaDB show the rows
     * read in their handler counters. PostgreSQL's planner prefers a
     * sequential scan of a small table, so its plan is read with sequential
     * scans disabled, which shows the index serves predicate and order.
     */
    #[DataProvider('serverDrivers')]
    public function test_due_stream_discovery_reads_the_deadline_index(string $driver): void
    {
        $this->initializeActorDatabase($driver);
        $now = now()->startOfSecond();
        foreach (['default', 'elsewhere'] as $namespace) {
            $rows = [];
            for ($n = 0; $n < 2400; $n++) {
                // 1800 without a deadline, 570 not yet due, 30 due.
                $reclaimAt = match (true) {
                    $n < 1800 => null,
                    $n < 2370 => $now->copy()->addSeconds($n),
                    default => $now->copy()->subSeconds($n),
                };
                $rows[] = [
                    'namespace' => $namespace,
                    'workflow_instance_id' => 'plan-'.$namespace,
                    'workflow_run_id' => sprintf('%s%08d', $namespace === 'default' ? 'D' : 'E', $n),
                    'stream_name' => 'stream',
                    'status' => $reclaimAt === null && $n % 2 === 0 ? 'open' : 'closed',
                    'closed_at' => $reclaimAt === null && $n % 2 === 0 ? null : $now,
                    'reclaim_at' => $reclaimAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 400) as $chunk) {
                DB::table('workflow_durable_streams')->insert($chunk);
            }
        }

        $query = WorkflowStreamRetention::dueStreamsQuery('default', 10, $now)->toBase();
        $sql = $query->toSql();

        if ($driver === 'pgsql') {
            DB::statement('ANALYZE workflow_durable_streams');
            $plan = DB::transaction(static function () use ($sql, $query): string {
                DB::statement('SET LOCAL enable_seqscan = off');

                return collect(DB::select('EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF) '.$sql, $query->getBindings()))
                    ->map(static fn (object $row): string => (string) $row->{'QUERY PLAN'})->implode("\n");
            });
            $this->assertMatchesRegularExpression('/Index (Only )?Scan using wf_durable_streams_ns_reclaim_idx .*\(actual rows=10 loops=1\)/', $plan, $plan);
            $this->assertStringNotContainsString('Sort', $plan, $plan);
            $this->assertStringNotContainsString('Rows Removed', $plan, $plan);
        } else {
            DB::statement('ANALYZE TABLE workflow_durable_streams');
            $plan = DB::select('EXPLAIN '.$sql, $query->getBindings());
            $this->assertCount(1, $plan, json_encode($plan));
            $this->assertSame('wf_durable_streams_ns_reclaim_idx', $plan[0]->key, json_encode($plan));
            $this->assertSame('range', $plan[0]->type, json_encode($plan));
            $this->assertStringNotContainsString('filesort', (string) ($plan[0]->Extra ?? ''), json_encode($plan));
            // One PDO handle, so the counters are this session's own. MySQL
            // also counts the data-dictionary reads of a table's or a status
            // view's first use, so both run once before the counted read.
            $pdo = DB::connection()->getPdo();
            $statement = $pdo->prepare($sql);
            $statement->execute(DB::connection()->prepareBindings($query->getBindings()));
            $statement->fetchAll();
            $pdo->query("SHOW SESSION STATUS LIKE 'Handler_read%'")->fetchAll();
            $pdo->exec('FLUSH STATUS');
            $statement->execute(DB::connection()->prepareBindings($query->getBindings()));
            $this->assertCount(10, $statement->fetchAll());
            $reads = collect($pdo->query("SHOW SESSION STATUS LIKE 'Handler_read%'")->fetchAll(PDO::FETCH_KEY_PAIR))
                ->map(static fn (mixed $value): int => (int) $value);
            // One seek and nine index steps: the limit, not the namespace.
            $this->assertLessThanOrEqual(11, $reads['Handler_read_key'] + $reads['Handler_read_next'], json_encode($reads));
            $this->assertSame(0, $reads['Handler_read_rnd_next'], json_encode($reads));
            $plan = json_encode(['explain' => $plan, 'handler_reads' => $reads], JSON_PRETTY_PRINT);
        }
        fwrite(STDERR, sprintf("[%s due-stream plan]\n%s\n", $driver, $plan));

        $this->assertCount(10, $query->get());
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function race(string $runId, string $pause, int $firstItems, int $secondItems): array
    {
        try {
            $first = $this->startActor(fn (): array => $this->append($runId, $firstItems), self::streamPause($pause));
            $this->runUntilPaused($first);

            $second = $this->startActor(fn (): array => $this->append($runId, $secondItems));
            $this->release($second);
            $this->awaitWait($second, 'workflow_runs');

            $this->resume($first);

            return [$this->finishActor($first), $this->finishActor($second)];
        } finally {
            DB::purge();
            DB::reconnect();
        }
    }

    /**
     * Pauses an append right after it inserts the stream row ('insert') or
     * locks it ('lock'). SQLite drops FOR UPDATE; its append holds the write
     * lock from the run lock's no-op write on.
     */
    private static function streamPause(string $pause): Closure
    {
        return static function (string $sql, string $driver, int $level) use ($pause): bool {
            $stream = str_contains($sql, 'workflow_durable_streams') && ! str_contains($sql, 'workflow_durable_stream_items');
            $matches = $pause === 'insert'
                ? str_starts_with($sql, 'insert')
                : ($driver === 'sqlite'
                    ? str_starts_with($sql, 'select')
                    : str_contains($sql, 'for update') || str_contains($sql, 'for no key update'));

            return $stream && $matches;
        };
    }

    /** @return array<string, mixed> */
    private function append(string $runId, int $count): array
    {
        $run = WorkflowRun::query()->findOrFail($runId);
        $items = array_map(static fn (int $n): array => ['payload' => $n], range(1, $count));

        try {
            $result = app(WorkflowStreamService::class)->append($run, 'default', 'tokens', $items, self::CAP);

            return ['outcome' => 'accepted', 'accepted' => $result['accepted']];
        } catch (StreamFullException) {
            return ['outcome' => 'full'];
        }
    }
}
