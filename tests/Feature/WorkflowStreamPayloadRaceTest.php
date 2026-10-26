<?php

namespace Tests\Feature;

use App\Models\RuntimeExternalPayload;
use App\Models\WorkflowDurableStream;
use App\Models\WorkflowDurableStreamItem;
use App\Models\WorkflowNamespace;
use App\Support\HistoryRetentionEnforcer;
use App\Support\NamespaceExternalPayloadStorage;
use App\Support\RuntimeExternalPayloadException;
use App\Support\RuntimeExternalPayloadObjectLock;
use App\Support\RuntimeExternalPayloadRegistry;
use App\Support\RuntimeExternalPayloadWriteClaims;
use App\Support\WorkflowRunStreamLock;
use App\Support\WorkflowStreamCommandProcessor;
use App\Support\WorkflowStreamRetention;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ForkedDatabaseActors;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\RunSummaryProjector;

/**
 * Stream writers and external payload cleanup decide each object under that
 * object's lock (Server #774), on MySQL, MariaDB, PostgreSQL and file-backed
 * SQLite. A writer claims an object, inside the transaction that writes the
 * item referencing it; stream reclamation and run retention ask again,
 * under the same lock, whether any retained row references the object
 * before they delete it. Whichever takes the lock first decides: either the
 * object goes and the writer's claim fails, writing nothing, or the
 * writer's item commits and the object stays.
 *
 * Each case holds one side paused at a chosen statement and shows the other
 * waiting on its lock: on the object lock row for MySQL, MariaDB and
 * PostgreSQL, and on the database write lock for SQLite.
 */
class WorkflowStreamPayloadRaceTest extends TestCase
{
    use ForkedDatabaseActors;

    private const OBJECT_LOCKS = 'runtime_external_payload_object_locks';

    private ?string $storageDirectory = null;

    public static function interleavings(): array
    {
        $cases = [];
        foreach (array_keys(self::drivers()) as $driver) {
            foreach ([
                'http append, reclamation first' => ['reclaim', 'http', 'cleanup'],
                'http append first, reclamation' => ['reclaim', 'http', 'writer'],
                'command append, reclamation first' => ['reclaim', 'command', 'cleanup'],
                'command append first, reclamation' => ['reclaim', 'command', 'writer'],
                'http append, run prune first' => ['prune', 'http', 'cleanup'],
                'http append first, run prune' => ['prune', 'http', 'writer'],
            ] as $name => $case) {
                $cases[$driver.': '.$name] = [$driver, ...$case];
            }
        }

        return $cases;
    }

    /**
     * Another run's expired stream (reclaim), or another expired run (prune),
     * holds the only reference to an object a writer references again.
     */
    #[DataProvider('interleavings')]
    public function test_a_writer_and_payload_cleanup_decide_a_shared_object_under_its_lock(
        string $driver,
        string $cleanup,
        string $writer,
        string $first,
    ): void {
        $this->initializeDatabase($driver);
        $object = $this->registerObject('shared');
        $oldRunId = $this->summarizedRun('wf-payload-race-old', $cleanup === 'prune' ? RunStatus::Completed : RunStatus::Running);
        $this->addStream($oldRunId, 'old', [$object['uri']], expired: $cleanup === 'reclaim');
        $runId = $this->summarizedRun('wf-payload-race-writer');
        $taskId = $writer === 'command' ? $this->createWorkflowTask($runId) : null;

        $cleanupWork = static fn (): array => $cleanup === 'reclaim'
            ? WorkflowStreamRetention::pruneExpired('default', 10, 1000)
            : HistoryRetentionEnforcer::pruneRun('default', $oldRunId);
        $writerWork = fn (): array => $writer === 'http'
            ? $this->httpAppend('wf-payload-race-writer', $runId, [$object['reference']])
            : $this->commandAppend($runId, $taskId, [$object['uri']]);
        // Cleanup pauses holding the object's lock, before it decides.
        $cleanupHoldsTheObject = self::after('update', self::OBJECT_LOCKS, minLevel: 2);

        if ($first === 'cleanup') {
            // The HTTP append has fetched and verified the object, outside
            // its transaction; the command append's runtime transport
            // resolved it before the completion.
            $writerActor = $this->startActor($writerWork, $writer === 'http' ? [self::afterVerification()] : []);
            if ($writer === 'http') {
                $this->runUntilPaused($writerActor);
            }
            $cleanupActor = $this->startActor($cleanupWork, $cleanupHoldsTheObject);
            $this->runUntilPaused($cleanupActor);
            if ($writer === 'http') {
                $this->resume($writerActor);
            } else {
                $this->release($writerActor);
            }
            $this->awaitWait($writerActor, self::OBJECT_LOCKS);
            $this->resume($cleanupActor);

            $cleaned = $this->finishActor($cleanupActor);
            $written = $this->finishActor($writerActor);

            // The object went first; the writer's claim found no
            // registration, and its append wrote nothing.
            $this->assertSame($writer === 'http'
                ? ['status' => 422, 'reason' => 'external_payload_unsupported']
                : ['outcome' => 'refused', 'reason' => 'external_payload_not_found'], $written);
            $this->assertCleanup($cleanup, $cleaned, objectDeleted: true);
            $this->assertObjectDeleted($object);
            $this->assertSame(0, WorkflowDurableStreamItem::query()->where('workflow_run_id', $runId)->count());
            $this->assertSame(0, WorkflowDurableStreamItem::query()->where('payload_reference', $object['uri'])->count());

            return;
        }

        // The writer has claimed the object and written its item, holding
        // the object's lock until it commits.
        $writerActor = $this->startActor($writerWork, self::after('insert', 'workflow_durable_stream_items'));
        $this->runUntilPaused($writerActor);
        $cleanupActor = $this->startActor($cleanupWork);
        $this->release($cleanupActor);
        $this->awaitWait($cleanupActor, self::OBJECT_LOCKS);
        $this->resume($writerActor);

        $written = $this->finishActor($writerActor);
        $cleaned = $this->finishActor($cleanupActor);

        // The writer's item committed first; cleanup found it under the
        // object's lock and kept the object.
        $this->assertSame($writer === 'http' ? ['status' => 200, 'accepted' => 1] : ['outcome' => 'accepted'], $written);
        $this->assertCleanup($cleanup, $cleaned, objectDeleted: false);
        $this->assertObjectKept($object);
        $this->assertSame([$object['uri']], WorkflowDurableStreamItem::query()->where('workflow_run_id', $runId)
            ->pluck('payload_reference')->all());
        $this->assertSame(0, WorkflowDurableStreamItem::query()->where('workflow_run_id', $oldRunId)->count());
    }

    /**
     * Run retention takes the run's lock before it deletes anything, so an
     * append to the same run that comes while it deletes the run's objects
     * waits, then finds the run's details pruned and writes nothing. Were
     * the append admitted then, retention would delete its item with the
     * run's rows and leave the object it claimed unreferenced.
     */
    #[DataProvider('drivers')]
    public function test_a_late_append_to_a_run_being_pruned_writes_nothing(string $driver): void
    {
        $this->initializeDatabase($driver);
        $old = $this->registerObject('pruned');
        $late = $this->registerObject('late', claimed: false);
        $runId = $this->summarizedRun('wf-payload-race-pruned', RunStatus::Completed);
        $this->addStream($runId, 'old', [$old['uri']]);

        // The append has verified its object; then retention deletes the
        // run's object and pauses before it deletes the run's rows.
        $append = $this->startActor(
            fn (): array => $this->httpAppend('wf-payload-race-pruned', $runId, [$late['reference']]),
            self::afterVerification(),
        );
        $this->runUntilPaused($append);
        $prune = $this->startActor(
            static fn (): array => HistoryRetentionEnforcer::pruneRun('default', $runId),
            self::after('delete', 'runtime_external_payloads', minLevel: 2),
        );
        $this->runUntilPaused($prune);
        $this->resume($append);
        $this->awaitWait($append, 'workflow_runs');
        $this->resume($prune);

        $this->assertCleanup('prune', $this->finishActor($prune), objectDeleted: true);
        $this->assertSame(['status' => 404, 'reason' => 'stream_not_found'], $this->finishActor($append));
        $this->assertSame(0, WorkflowDurableStream::query()->where('workflow_run_id', $runId)->count());
        $this->assertSame(0, WorkflowDurableStreamItem::query()->where('workflow_run_id', $runId)->count());
        $this->assertObjectDeleted($old);
        // The refused append claimed nothing; its upload still expires.
        $row = RuntimeExternalPayload::query()->where('storage_uri', $late['uri'])->sole();
        $this->assertNull($row->retained_at);
        $this->assertNotNull($row->expires_at);
    }

    /**
     * Two appends that reference the same two objects, listed in opposite
     * orders, claim them in one canonical order, so the second waits for the
     * first instead of each holding the lock the other needs. Both commit.
     * Each run's stream is already open, so the appends share no lock but
     * the objects'.
     */
    #[DataProvider('drivers')]
    public function test_appends_listing_shared_objects_in_opposite_orders_both_commit(string $driver): void
    {
        $this->initializeDatabase($driver);
        [$a, $b] = $this->objectsInTwoLockBuckets();
        $abRunId = $this->summarizedRun('wf-payload-race-ab');
        $baRunId = $this->summarizedRun('wf-payload-race-ba');
        $this->addStream($abRunId, 'live', []);
        $this->addStream($baRunId, 'live', []);
        $claimsFirst = self::after('update', self::OBJECT_LOCKS, minLevel: 2);

        $ab = fn (): array => $this->httpAppend('wf-payload-race-ab', $abRunId, [$a['reference'], $b['reference']]);
        $ba = fn (): array => $this->httpAppend('wf-payload-race-ba', $baRunId, [$b['reference'], $a['reference']]);

        if ($driver === 'sqlite') {
            // One writer at a time: the second append waits for the first's
            // write lock.
            $first = $this->startActor($ab, $claimsFirst);
            $this->runUntilPaused($first);
            $second = $this->startActor($ba);
            $this->release($second);
            $this->awaitWait($second, self::OBJECT_LOCKS);
        } else {
            // Both appends verify their objects and lock their runs; then the
            // first claims its first object and holds it.
            $first = $this->startActor($ab, [self::afterRunLock(), $claimsFirst]);
            $this->runUntilPaused($first);
            $second = $this->startActor($ba, [self::afterRunLock()]);
            $this->runUntilPaused($second);
            $this->resumeUntilPaused($first);
            $this->resume($second);
            $this->awaitWait($second, self::OBJECT_LOCKS);
        }
        $this->resume($first);

        $this->assertSame(['status' => 200, 'accepted' => 2], $this->finishActor($first));
        $this->assertSame(['status' => 200, 'accepted' => 2], $this->finishActor($second));
        foreach ([$abRunId, $baRunId] as $runId) {
            $this->assertEqualsCanonicalizing([$a['uri'], $b['uri']], WorkflowDurableStreamItem::query()
                ->where('workflow_run_id', $runId)->pluck('payload_reference')->all());
        }
        $this->assertObjectKept($a);
        $this->assertObjectKept($b);
    }

    /**
     * A workflow start previously claimed its external input
     * in its own committed step, before the transaction that writes the run
     * referencing it. Run retention that runs between the two finds no
     * retained reference and deletes the object, and the start then commits
     * a run whose arguments reference a deleted object. The final claim must
     * now refuse that start and roll back all its reference-writing state.
     */
    #[DataProvider('drivers')]
    public function test_a_workflow_start_and_run_pruning_decide_a_shared_object(string $driver): void
    {
        $this->initializeDatabase($driver);
        $object = $this->registerObject('start-input');
        $oldRunId = $this->summarizedRun('wf-claim-race-old', RunStatus::Completed);
        $this->addStream($oldRunId, 'old', [$object['uri']]);

        // The start has verified and claimed the object under its lock and
        // committed that step; it has not yet written its run.
        $start = $this->startActor(fn (): array => $this->httpStart('wf-claim-race-new', $object['reference']), self::afterVerification());
        $this->runUntilPaused($start);
        $pruned = HistoryRetentionEnforcer::pruneRun('default', $oldRunId);
        $this->assertTrue($pruned['pruned'], json_encode($pruned));
        $this->resume($start);
        $started = $this->finishActor($start);

        // Either the start is refused, or the object its run references
        // still exists.
        if ($started['status'] === 201) {
            $arguments = (string) WorkflowRun::query()->where('workflow_instance_id', 'wf-claim-race-new')->value('arguments');
            $this->assertSame($object['uri'], ExternalPayloads::storedEnvelope($arguments)['external_storage']['uri']);
            $this->assertObjectKept($object);
        } else {
            $this->assertContains($started['status'], [404, 410, 422], json_encode($started));
            $this->assertContains($started['reason'], [
                'external_payload_not_found', 'external_payload_expired', 'external_payload_unsupported',
            ]);
            $this->assertFalse(WorkflowRun::query()->where('workflow_instance_id', 'wf-claim-race-new')->exists());
            $this->assertFalse(WorkflowInstance::query()->whereKey('wf-claim-race-new')->exists());
            $this->assertObjectDeleted($object);
        }
    }

    #[DataProvider('drivers')]
    public function test_a_workflow_start_holds_its_final_claim_until_its_reference_is_visible(string $driver): void
    {
        $this->initializeDatabase($driver);
        $object = $this->registerObject('start-wins');
        $oldRunId = $this->summarizedRun('wf-claim-wins-old', RunStatus::Completed);
        $this->addStream($oldRunId, 'old', [$object['uri']]);
        $written = false;
        $finalClaim = static function (string $sql, string $driver, int $level) use (&$written): bool {
            if (str_starts_with($sql, 'insert') && str_contains($sql, 'workflow_runs')) {
                $written = true;
            }

            return $written && $level >= 1 && str_starts_with($sql, 'select')
                && str_contains($sql, 'runtime_external_payloads');
        };

        $start = $this->startActor(fn (): array => $this->httpStart('wf-claim-wins-new', $object['reference']), $finalClaim);
        $this->runUntilPaused($start);
        $cleanup = $this->startActor(fn (): array => HistoryRetentionEnforcer::pruneRun('default', $oldRunId));
        $this->release($cleanup);
        $this->awaitWait($cleanup, 'runtime_external_payload_object_locks');
        $this->resume($start);
        $this->assertSame(201, $this->finishActor($start)['status']);
        $this->assertTrue($this->finishActor($cleanup)['pruned']);
        DB::purge();
        DB::reconnect();
        $arguments = (string) WorkflowRun::query()->where('workflow_instance_id', 'wf-claim-wins-new')->value('arguments');
        $this->assertSame($object['uri'], ExternalPayloads::storedEnvelope($arguments)['external_storage']['uri']);
        $this->assertObjectKept($object);
    }

    private function httpStart(string $workflowId, array $reference): array
    {
        $response = $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => $workflowId,
            'workflow_type' => 'remote.claim-race',
            'task_queue' => 'claim-race',
            'input' => ['codec' => 'avro', 'external_payload' => $reference],
        ]);

        return ['status' => $response->status(), 'reason' => $response->json('reason')];
    }

    #[DataProvider('drivers')]
    public function test_materialized_input_cannot_bypass_its_commit_time_reference_claim(string $driver): void
    {
        $this->initializeDatabase($driver);
        $object = $this->registerObject('materialized-input');
        $oldRunId = $this->summarizedRun('wf-inline-old', RunStatus::Completed);
        $this->addStream($oldRunId, 'old', [$object['uri']]);

        $writer = $this->startActor(function () use ($object): array {
            try {
                $runId = app(RuntimeExternalPayloadWriteClaims::class)->transport(function () use ($object): string {
                    $bytes = app(NamespaceExternalPayloadStorage::class)->driverFor('default')->get($object['uri']);

                    return DB::transaction(function () use ($bytes): string {
                        $runId = $this->createRun('wf-inline-new');
                        WorkflowRun::query()->whereKey($runId)->update(['arguments' => $bytes, 'payload_codec' => 'avro']);

                        return $runId;
                    });
                });

                return ['status' => 201, 'run_id' => $runId];
            } catch (RuntimeExternalPayloadException $exception) {
                return ['status' => $exception->status, 'reason' => $exception->reason];
            }
        }, self::afterVerification());
        $this->runUntilPaused($writer);
        $this->assertTrue(HistoryRetentionEnforcer::pruneRun('default', $oldRunId)['pruned']);
        $this->resume($writer);
        $this->assertSame(['status' => 422, 'reason' => 'external_payload_unsupported'], $this->finishActor($writer));
        $this->assertFalse(WorkflowRun::query()->where('workflow_instance_id', 'wf-inline-new')->exists());
        $this->assertObjectDeleted($object);
    }

    #[DataProvider('drivers')]
    public function test_a_retained_schedule_protects_input_shared_with_a_pruned_run(string $driver): void
    {
        $this->initializeDatabase($driver);
        $object = $this->registerObject('scheduled-input');
        $oldRunId = $this->summarizedRun('wf-scheduled-old', RunStatus::Completed);
        $this->addStream($oldRunId, 'old', [$object['uri']]);

        $this->withHeaders($this->apiHeaders())->postJson('/api/schedules', [
            'schedule_id' => 'shared-input-schedule',
            'spec' => ['cron_expressions' => ['0 * * * *']],
            'action' => [
                'workflow_type' => 'remote.claim-race',
                'input' => ['codec' => 'avro', 'external_payload' => $object['reference']],
            ],
        ])->assertCreated();
        $this->assertTrue(HistoryRetentionEnforcer::pruneRun('default', $oldRunId)['pruned']);
        $this->assertObjectKept($object);
    }

    private function initializeDatabase(string $driver): void
    {
        $this->initializeActorDatabase($driver);
        $this->storageDirectory = storage_path('framework/testing/stream-payload-race-'.bin2hex(random_bytes(4)));
        File::deleteDirectory($this->storageDirectory);
        WorkflowNamespace::query()->where('name', 'default')->update([
            'external_payload_storage' => [
                'driver' => 'local',
                'enabled' => true,
                'config' => ['uri' => 'file://'.$this->storageDirectory],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownActors();
        if ($this->storageDirectory !== null) {
            File::deleteDirectory($this->storageDirectory);
        }
        parent::tearDown();
    }

    /**
     * A run with its summary, as every started run has. Payload cleanup
     * counts a reference as retained while its run's summary remains.
     */
    private function summarizedRun(string $workflowId, RunStatus $status = RunStatus::Running): string
    {
        $runId = $this->createRun($workflowId, $status);
        RunSummaryProjector::project(WorkflowRun::query()->findOrFail($runId));

        return $runId;
    }

    /**
     * An uploaded object; claimed, as one a stored item references is, unless
     * the test uploaded it for an append still to come.
     */
    private function registerObject(string $value, bool $claimed = true): array
    {
        $bytes = Serializer::serializeWithCodec('avro', [$value]);
        $registry = app(RuntimeExternalPayloadRegistry::class);
        $reference = $registry->upload('default', $bytes, 'avro', hash('sha256', $bytes));
        $uri = (string) RuntimeExternalPayload::query()->whereKey($reference['reference_id'])->value('storage_uri');
        if ($claimed) {
            $registry->claimRegistered('default', $uri);
        }

        return ['uri' => $uri, 'reference' => $reference];
    }

    /** @return array{0: array, 1: array} Two objects in different lock buckets, in lock order. */
    private function objectsInTwoLockBuckets(): array
    {
        $first = $this->registerObject('object-0');
        for ($n = 1; $n < 64; $n++) {
            $second = $this->registerObject('object-'.$n);
            if (RuntimeExternalPayloadObjectLock::bucketFor($second['uri']) !== RuntimeExternalPayloadObjectLock::bucketFor($first['uri'])) {
                $ordered = RuntimeExternalPayloadObjectLock::inLockOrder([$first['uri'], $second['uri']]);

                return $ordered[0] === $first['uri'] ? [$first, $second] : [$second, $first];
            }
        }
        $this->fail('No two objects in different lock buckets.');
    }

    /** @param list<string> $uris */
    private function addStream(string $runId, string $name, array $uris, bool $expired = false): void
    {
        $run = WorkflowRun::query()->findOrFail($runId);
        $at = now()->subDay();
        DB::transaction(static function () use ($run, $name, $uris, $expired, $at): void {
            $stream = WorkflowDurableStream::query()->create([
                'namespace' => 'default',
                'workflow_instance_id' => $run->workflow_instance_id,
                'workflow_run_id' => $run->id,
                'stream_name' => $name,
                'status' => $expired ? WorkflowDurableStream::STATUS_CLOSED : WorkflowDurableStream::STATUS_OPEN,
                'last_offset' => count($uris) - 1,
                'total_items' => count($uris),
                'pending_items' => count($uris),
                'opened_at' => $at,
                'closed_at' => $expired ? $at : null,
                'retention_seconds' => 3600,
                'reclaim_at' => $expired ? $at->copy()->addHour() : null,
            ]);
            foreach ($uris as $offset => $uri) {
                WorkflowDurableStreamItem::query()->create([
                    'stream_id' => $stream->id,
                    'namespace' => 'default',
                    'workflow_run_id' => $run->id,
                    'stream_name' => $name,
                    'offset' => $offset,
                    'payload_reference' => $uri,
                    'payload_codec' => 'avro',
                    'emitted_at' => $at,
                ]);
            }
        });
    }

    private function createWorkflowTask(string $runId): string
    {
        return (string) WorkflowTask::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $runId,
            'namespace' => 'default',
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'queue' => 'default',
            'payload' => ['workflow_command_id' => 'race-command'],
            'available_at' => now(),
        ])->id;
    }

    /** @param list<array<string, mixed>> $references Opaque runtime references. */
    private function httpAppend(string $workflowId, string $runId, array $references): array
    {
        $response = $this->withHeaders($this->apiHeaders())->postJson(
            sprintf('/api/workflows/%s/runs/%s/streams/live/items', $workflowId, $runId),
            ['items' => array_map(static fn (array $reference): array => [
                'payload_reference' => $reference,
                'payload_codec' => 'avro',
            ], $references)],
        );

        return $response->status() === 200
            ? ['status' => 200, 'accepted' => $response->json('accepted')]
            : ['status' => $response->status(), 'reason' => $response->json('reason')];
    }

    /**
     * A workflow command's append, inside a transaction that already holds
     * the run, as a worker completion's does. The runtime transport has
     * resolved each reference to its provider URI.
     *
     * @param  list<string>  $uris
     */
    private function commandAppend(string $runId, string $taskId, array $uris): array
    {
        try {
            DB::transaction(static function () use ($runId, $taskId, $uris): void {
                WorkflowRunStreamLock::acquire($runId);
                app(WorkflowStreamCommandProcessor::class)->process($taskId, 'default', [[
                    'type' => 'record_side_effect',
                    'result' => ['codec' => 'avro', 'blob' => 'recorded-null'],
                    'workflow_stream' => [
                        'operation' => 'append',
                        'stream_name' => 'live',
                        'command_identity' => 'race-command',
                        'command_ordinal' => 0,
                        'items' => array_map(static fn (string $uri, int $index): array => [
                            'payload_reference' => $uri,
                            'payload_codec' => 'avro',
                            'idempotency_key' => 'dw-stream:race-command:0:'.$index,
                        ], $uris, array_keys($uris)),
                    ],
                ]]);
            });

            return ['outcome' => 'accepted'];
        } catch (RuntimeExternalPayloadException $exception) {
            return ['outcome' => 'refused', 'reason' => $exception->reason];
        }
    }

    private function assertCleanup(string $cleanup, array $result, bool $objectDeleted): void
    {
        if ($cleanup === 'reclaim') {
            $this->assertSame(['streams_reclaimed' => 1, 'items_removed' => 1, 'budget_exhausted' => false, 'blocked_reason' => null], $result);

            return;
        }
        $this->assertTrue($result['pruned'], json_encode($result));
        $this->assertSame($objectDeleted ? 1 : 0, $result['external_payloads_deleted'], json_encode($result));
    }

    private function assertObjectDeleted(array $object): void
    {
        $this->assertFileDoesNotExist(rawurldecode((string) parse_url($object['uri'], PHP_URL_PATH)));
        $this->assertSame(0, RuntimeExternalPayload::query()->where('storage_uri', $object['uri'])->count());
    }

    private function assertObjectKept(array $object): void
    {
        $this->assertFileExists(rawurldecode((string) parse_url($object['uri'], PHP_URL_PATH)));
        $row = RuntimeExternalPayload::query()->where('storage_uri', $object['uri'])->sole();
        $this->assertNotNull($row->retained_at);
        $this->assertNull($row->expires_at);
    }

    /** Right after a $verb statement on $table at transaction level $minLevel or deeper. */
    private static function after(string $verb, string $table, int $minLevel = 0): Closure
    {
        return static fn (string $sql, string $driver, int $level): bool => $level >= $minLevel
            && str_starts_with($sql, $verb)
            && str_contains($sql, $table);
    }

    /**
     * Right after an append takes its run's stream lock: a locking read of
     * the run row, or on SQLite the no-op write that takes the write lock.
     */
    private static function afterRunLock(): Closure
    {
        return static fn (string $sql, string $driver, int $level): bool => $level >= 1
            && str_contains($sql, 'workflow_runs')
            && ($driver === 'sqlite' ? str_starts_with($sql, 'update') : str_contains($sql, 'for update'));
    }

    /**
     * Right after an HTTP append's verification commits: it has read the
     * object's registration under the object's lock and released it, and
     * holds no lock.
     */
    private static function afterVerification(): Closure
    {
        $verified = false;

        return static function (string $sql, string $driver, int $level) use (&$verified): bool {
            if ($level >= 1 && str_starts_with($sql, 'select') && str_contains($sql, 'runtime_external_payloads')) {
                $verified = true;
            }

            return $verified && $sql === 'commit' && $level === 0;
        };
    }
}
