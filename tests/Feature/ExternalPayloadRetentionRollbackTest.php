<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkflowDurableStream;
use App\Models\WorkflowDurableStreamItem;
use App\Models\WorkflowNamespace;
use App\Support\ExternalPayloadBackupHold;
use App\Support\ExternalPayloadObjectMissing;
use App\Support\NamespaceExternalPayloadStorage;
use App\Support\RuntimeExternalPayloadCleanup;
use App\Support\RuntimeExternalPayloadDeletions;
use App\Support\RuntimeExternalPayloadRegistry;
use App\Support\RuntimeExternalPayloadStorageDriver;
use App\Support\RuntimeLocalExternalPayloadStorage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceCallDispatch;
use Workflow\V2\Models\WorkflowServiceOperation;

final class ExternalPayloadRetentionRollbackTest extends TestCase
{
    use DatabaseMigrations;
    use ServerTestHelpers;

    private string $directory;

    private object $provider;

    /** @var array<string, string> */
    private array $objects;

    private WorkflowRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/retention-rollback-'.Str::uuid();
        $this->createNamespace('default');
        WorkflowNamespace::query()->where('name', 'default')->update(['external_payload_storage' => [
            'driver' => 'local', 'enabled' => true, 'config' => ['uri' => 'file://'.$this->directory],
        ]]);
        $this->provider = new class(new RuntimeLocalExternalPayloadStorage($this->directory)) implements RuntimeExternalPayloadStorageDriver
        {
            public int $attempts = 0;

            public int $failAt = 0;

            public bool $unavailable = false;

            public function __construct(private RuntimeLocalExternalPayloadStorage $inner) {}

            public function put(string $data, string $sha256, string $codec): string
            {
                return $this->inner->put($data, $sha256, $codec);
            }

            public function uriFor(string $sha256, string $codec): string
            {
                return $this->inner->uriFor($sha256, $codec);
            }

            public function get(string $uri): string
            {
                return $this->inner->get($uri);
            }

            public function delete(string $uri): void
            {
                $this->attempts++;
                if ($this->unavailable || $this->attempts === $this->failAt) {
                    throw new \RuntimeException('Synthetic provider failure');
                }
                $this->inner->delete($uri);
            }
        };
        app()->instance(NamespaceExternalPayloadStorage::class, new class($this->provider) extends NamespaceExternalPayloadStorage
        {
            public function __construct(private RuntimeExternalPayloadStorageDriver $provider) {}

            public function untrackedDriverFor(?string $namespace): ?RuntimeExternalPayloadStorageDriver
            {
                return $this->provider;
            }
        });
        $this->createClosedRun();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_successful_pruning_reclaims_both_objects(): void
    {
        $this->prune()->assertOk()->assertJsonPath('pruned', 1)
            ->assertJsonPath('results.0.external_payloads_deleted', 2)
            ->assertJsonPath('external_payload_cleanup.pending', 0);
        $this->assertTrue($this->run->fresh()->details_pruned_at !== null);
        $this->assertDatabaseCount('runtime_external_payloads', 0);
        $this->assertDatabaseCount('workflow_durable_stream_items', 0);
        $this->assertSame(0, $this->readableObjects());
    }

    public static function failingDeletions(): array
    {
        return ['first' => [1], 'second' => [2]];
    }

    #[DataProvider('failingDeletions')]
    public function test_provider_failure_keeps_committed_pruning_and_durable_retry(int $failAt): void
    {
        $this->provider->failAt = $failAt;
        $this->prune()->assertOk()->assertJsonPath('pruned', 1)->assertJsonPath('failed', 0)
            ->assertJsonPath('external_payload_cleanup.pending', 1)
            ->assertJsonPath('external_payload_cleanup.failed', 1);
        $this->assertNotNull($this->run->fresh()->details_pruned_at);
        $this->assertDatabaseCount('runtime_external_payloads', 0);
        $this->assertDatabaseCount('workflow_durable_stream_items', 0);
        $this->assertSame(1, $this->readableObjects());

        $this->provider->failAt = 0;
        $this->prune()->assertOk()->assertJsonPath('external_payload_cleanup.pending', 0);
        $this->assertSame(0, $this->readableObjects());
    }

    public function test_later_database_failure_rolls_back_without_deleting_provider_bytes(): void
    {
        $armed = true;
        DB::listen(static function (QueryExecuted $event) use (&$armed): void {
            $sql = strtolower(ltrim($event->sql));
            if ($armed && str_starts_with($sql, 'update')
                && str_contains($sql, 'workflow_runs') && str_contains($sql, 'details_pruned_at')) {
                $armed = false;
                throw new \RuntimeException('Synthetic failure after the prune database update');
            }
        });

        $this->prune()->assertStatus(207)->assertJsonPath('pruned', 0)->assertJsonPath('failed', 1);
        $this->assertNull($this->run->fresh()->details_pruned_at);
        $this->assertDatabaseCount('runtime_external_payloads', 2);
        $this->assertDatabaseCount('workflow_durable_stream_items', 2);
        $this->assertDatabaseCount('runtime_external_payload_deletions', 0);
        $this->assertSame(0, $this->provider->attempts);
        $this->assertSame(2, $this->readableObjects());
    }

    public function test_saved_policy_recovers_pending_deletion_after_namespace_and_process_state_are_gone(): void
    {
        $this->provider->unavailable = true;
        $this->deleteJson('/api/namespaces/default', [], $this->apiHeaders())
            ->assertStatus(207)->assertJsonPath('status', 'deleted')
            ->assertJsonPath('external_payload_cleanup.pending', 2)
            ->assertJsonPath('external_payload_cleanup.failed', 2)
            ->assertJsonPath('external_payload_cleanup.blocked', 0);
        $this->assertDatabaseCount('runtime_external_payload_deletions', 2);
        $this->assertDatabaseMissing('workflow_namespaces', ['name' => 'default']);
        $this->assertSame(2, $this->readableObjects());
        app()->forgetInstance(NamespaceExternalPayloadStorage::class);

        $report = app(RuntimeExternalPayloadCleanup::class)->runPass();
        $this->assertSame(0, $report['retention_cleanup']['pending']);
        $this->assertSame(0, $this->readableObjects());
        $this->assertDatabaseCount('runtime_external_payload_deletions', 0);
    }

    public function test_namespace_database_failure_preserves_all_registrations_and_bytes(): void
    {
        $armed = true;
        DB::listen(static function (QueryExecuted $event) use (&$armed): void {
            if ($armed && str_starts_with(strtolower(ltrim($event->sql)), 'delete')
                && str_contains($event->sql, 'runtime_external_payloads')) {
                $armed = false;
                throw new \RuntimeException('Synthetic namespace registry deletion failure');
            }
        });

        $this->deleteJson('/api/namespaces/default', [], $this->apiHeaders())->assertStatus(500);
        $this->assertDatabaseHas('workflow_namespaces', ['name' => 'default']);
        $this->assertDatabaseCount('runtime_external_payloads', 2);
        $this->assertDatabaseCount('runtime_external_payload_deletions', 0);
        $this->assertDatabaseCount('workflow_durable_stream_items', 2);
        $this->assertSame(0, $this->provider->attempts);
        $this->assertSame(2, $this->readableObjects());
    }

    public function test_backup_hold_acquired_after_staging_blocks_bytes_and_is_visible_to_maintenance(): void
    {
        $owner = (string) Str::uuid();
        $armed = true;
        DB::listen(static function (QueryExecuted $event) use (&$armed, $owner): void {
            $sql = strtolower(ltrim($event->sql));
            if ($armed && str_starts_with($sql, 'update')
                && str_contains($sql, 'workflow_runs') && str_contains($sql, 'details_pruned_at')) {
                $armed = false;
                app(ExternalPayloadBackupHold::class)->acquire($owner, 600);
            }
        });

        $this->prune()->assertOk()->assertJsonPath('pruned', 1)
            ->assertJsonPath('external_payload_cleanup.pending', 2)
            ->assertJsonPath('external_payload_cleanup.blocked', 2);
        $this->assertSame(2, $this->readableObjects());
        $this->assertSame(0, $this->provider->attempts);
        $this->getJson('/api/system/external-payload-cleanup', $this->apiHeaders())
            ->assertOk()->assertJsonPath('retention_cleanup.pending', 2);
        $this->postJson('/api/system/external-payload-cleanup/pass', [], $this->apiHeaders())
            ->assertStatus(207)->assertJsonPath('cleanup.retention_cleanup.pending', 2);
        $this->artisan('external-payloads:cleanup', ['--namespace' => 'default', '--json' => true])
            ->assertExitCode(1);

        app(ExternalPayloadBackupHold::class)->release($owner);
        $this->artisan('external-payloads:cleanup', ['--namespace' => 'default', '--json' => true])
            ->assertExitCode(0);
        $this->assertSame(0, $this->readableObjects());
        $this->assertDatabaseCount('runtime_external_payload_deletions', 0);
    }

    public function test_a_new_unregistered_legacy_reference_cancels_the_old_deletion(): void
    {
        $this->provider->unavailable = true;
        $this->prune()->assertOk()->assertJsonPath('external_payload_cleanup.pending', 2);
        $instance = WorkflowInstance::query()->create([
            'id' => (string) Str::ulid(), 'namespace' => 'default',
            'workflow_class' => 'LegacyKeeperWorkflow', 'workflow_type' => 'synthetic.legacy-keeper',
        ]);
        $keeper = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(), 'workflow_instance_id' => $instance->id,
            'run_number' => 1, 'namespace' => 'default', 'status' => 'running',
            'workflow_class' => 'LegacyKeeperWorkflow', 'workflow_type' => 'synthetic.legacy-keeper',
        ]);
        $uri = array_key_first($this->objects);
        $stream = WorkflowDurableStream::query()->create([
            'namespace' => 'default', 'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $keeper->id, 'stream_name' => 'legacy-keeper', 'status' => 'open',
            'last_offset' => 0, 'total_items' => 1, 'opened_at' => now(),
        ]);
        WorkflowDurableStreamItem::query()->create([
            'stream_id' => $stream->id, 'namespace' => 'default', 'workflow_run_id' => $keeper->id,
            'stream_name' => 'legacy-keeper', 'offset' => 0, 'payload_reference' => $uri,
            'payload_codec' => 'avro', 'emitted_at' => now(),
        ]);
        $this->assertDatabaseCount('runtime_external_payloads', 0);
        $this->provider->unavailable = false;

        app(RuntimeExternalPayloadDeletions::class)->retryPending('default', 10);
        $this->assertSame($this->objects[$uri], $this->provider->get($uri));
        $this->assertSame(1, $this->readableObjects());
        $this->assertDatabaseCount('runtime_external_payload_deletions', 0);
        $this->assertDatabaseHas('workflow_durable_stream_items', ['stream_id' => $stream->id, 'payload_reference' => $uri]);
    }

    public static function scheduleFields(): array
    {
        $fields = ['spec', 'action', 'memo', 'search_attributes', 'visibility_labels', 'recent_actions', 'buffered_actions'];

        return array_combine($fields, array_map(static fn (string $field): array => [$field], $fields));
    }

    #[DataProvider('scheduleFields')]
    public function test_pending_deletion_keeps_legacy_payloads_in_every_schedule_field(string $field): void
    {
        $this->provider->unavailable = true;
        $this->prune()->assertOk()->assertJsonPath('external_payload_cleanup.pending', 2);
        $uri = array_key_first($this->objects);
        $schedule = WorkflowSchedule::query()->create([
            'namespace' => 'default', 'schedule_id' => 'legacy-'.$field,
            'spec' => ['interval_seconds' => 3600],
            'action' => ['workflow_type' => 'synthetic.recovery'],
            $field => ['payload' => [
                'schema' => 'durable-workflow.v2.external-payload-reference.v1',
                'uri' => $uri, 'codec' => 'avro', 'size_bytes' => strlen($this->objects[$uri]),
                'sha256' => hash('sha256', $this->objects[$uri]),
            ]],
        ]);
        $this->assertDatabaseCount('runtime_external_payloads', 0);
        $this->provider->unavailable = false;

        app(RuntimeExternalPayloadDeletions::class)->retryPending('default', 10);
        $this->assertSame($this->objects[$uri], $this->provider->get($uri));
        $this->assertSame(1, $this->readableObjects());
        $this->assertDatabaseCount('runtime_external_payload_deletions', 0);
        $this->assertNotNull($schedule->fresh());
    }

    public function test_a_new_registration_in_another_namespace_cancels_the_old_deletion(): void
    {
        $this->provider->unavailable = true;
        $this->prune()->assertOk()->assertJsonPath('external_payload_cleanup.pending', 2);
        $this->createNamespace('other');
        $uri = array_key_first($this->objects);
        $bytes = $this->objects[$uri];
        app(RuntimeExternalPayloadRegistry::class)->storeRetained('other', $this->provider, $bytes, 'avro', hash('sha256', $bytes));
        $this->provider->unavailable = false;

        app(RuntimeExternalPayloadDeletions::class)->retryPending('default', 10);
        $this->assertSame($bytes, $this->provider->get($uri));
        $this->assertSame(1, $this->readableObjects());
        $this->assertDatabaseCount('runtime_external_payload_deletions', 0);
        $this->assertDatabaseHas('runtime_external_payloads', ['namespace' => 'other', 'storage_uri' => $uri]);
    }

    public static function retainedOwners(): array
    {
        $cases = [];
        foreach (['instance_memo', 'instance_visibility', 'schedule_history', 'operation_binding', 'dispatch_snapshot'] as $owner) {
            foreach ([false, true] as $pending) {
                $cases[$owner.($pending ? '_retry' : '_prune')] = [$owner, $pending];
            }
        }

        return $cases;
    }

    #[DataProvider('retainedOwners')]
    public function test_retained_metadata_owns_bytes_before_pruning_and_during_pending_retry(string $owner, bool $pending): void
    {
        $this->createNamespace('other');
        WorkflowNamespace::query()->where('name', 'other')->update(['external_payload_storage' => [
            'driver' => 'local', 'enabled' => true, 'config' => ['uri' => 'file://'.$this->directory],
        ]]);
        $uri = array_key_first($this->objects);
        $reference = [
            'schema' => 'durable-workflow.v2.external-payload-reference.v1',
            'uri' => $uri, 'codec' => 'avro', 'size_bytes' => strlen($this->objects[$uri]),
            'sha256' => hash('sha256', $this->objects[$uri]),
        ];
        if ($pending) {
            $this->provider->unavailable = true;
            $this->prune()->assertOk()->assertJsonPath('external_payload_cleanup.pending', 2);
            $this->provider->unavailable = false;
        }
        $keeper = match ($owner) {
            'instance_memo', 'instance_visibility' => WorkflowInstance::query()->create([
                'namespace' => 'other', 'workflow_class' => 'RetainedWorkflow', 'workflow_type' => 'synthetic.retained',
                $owner === 'instance_memo' ? 'memo' : 'visibility_labels' => ['payload' => $reference],
            ]),
            'schedule_history' => WorkflowScheduleHistoryEvent::query()->create([
                'namespace' => 'other', 'workflow_schedule_id' => (string) Str::ulid(),
                'schedule_id' => 'before-first-run', 'sequence' => 1, 'event_type' => 'ScheduleCreated',
                'workflow_run_id' => null, 'payload' => ['memo' => $reference],
            ]),
            'operation_binding' => WorkflowServiceOperation::query()->create([
                'namespace' => 'other', 'workflow_service_endpoint_id' => (string) Str::ulid(),
                'workflow_service_id' => (string) Str::ulid(), 'operation_name' => 'retained',
                'operation_mode' => 'async', 'handler_binding_kind' => 'start_workflow',
                'handler_binding' => ['arguments' => ['payload_blob' => $reference]],
            ]),
            'dispatch_snapshot' => $this->createRetainedDispatch($reference),
        };
        if ($pending) {
            app(RuntimeExternalPayloadDeletions::class)->retryPending('default', 10);
        } else {
            $this->prune()->assertOk()->assertJsonPath('pruned', 1)
                ->assertJsonPath('external_payload_cleanup.pending', 0);
        }
        $this->assertNotNull($this->run->fresh()->details_pruned_at);
        $this->assertNotNull($keeper->fresh());
        $this->assertSame($this->objects[$uri], $this->provider->get($uri));
        $this->assertSame(1, $this->readableObjects());
        $this->assertDatabaseCount('runtime_external_payload_deletions', 0);

        // Namespace cleanup consumes the same inventory, including dispatch's
        // target ownership through the call rather than the caller namespace.
        $this->deleteJson('/api/namespaces/other', [], $this->apiHeaders())
            ->assertOk()->assertJsonPath('external_payload_cleanup.pending', 0);
        $this->assertNull($keeper->fresh());
        if (! $pending) {
            // The pre-prune case kept the original registration as another
            // independent owner. Removing the foreign namespace cannot revoke it.
            $this->assertDatabaseHas('runtime_external_payloads', ['namespace' => 'default', 'storage_uri' => $uri]);
            $this->assertSame($this->objects[$uri], $this->provider->get($uri));
            $this->deleteJson('/api/namespaces/default', [], $this->apiHeaders())
                ->assertOk()->assertJsonPath('external_payload_cleanup.pending', 0);
        }
        $this->assertSame(0, $this->readableObjects());
        $this->assertDatabaseCount('runtime_external_payload_deletions', 0);
    }

    private function createRetainedDispatch(array $reference): WorkflowServiceCallDispatch
    {
        $call = WorkflowServiceCall::query()->create([
            'namespace' => 'other', 'target_namespace' => 'other', 'caller_namespace' => 'default',
            'endpoint_name' => 'retained', 'service_name' => 'retained', 'operation_name' => 'retained',
            'status' => 'accepted', 'operation_mode' => 'async',
        ]);

        return WorkflowServiceCallDispatch::query()->create([
            'service_call_id' => $call->id, 'state' => 'pending', 'available_at' => now(),
            'dispatch_options' => ['accepted_handler_binding' => ['arguments' => ['payload_blob' => $reference]]],
        ]);
    }

    public function test_lost_cleanup_acknowledgement_is_retried_without_restoring_pruned_references(): void
    {
        $armed = true;
        DB::listen(static function (QueryExecuted $event) use (&$armed): void {
            if ($armed && str_starts_with(strtolower(ltrim($event->sql)), 'delete')
                && str_contains($event->sql, 'runtime_external_payload_deletions')) {
                $armed = false;
                throw new \RuntimeException('Synthetic cleanup acknowledgement failure');
            }
        });

        $this->prune()->assertOk()->assertJsonPath('pruned', 1)
            ->assertJsonPath('external_payload_cleanup.pending', 1);
        $this->assertNotNull($this->run->fresh()->details_pruned_at);
        $this->assertDatabaseCount('runtime_external_payloads', 0);
        $this->assertSame(0, $this->readableObjects());
        app(RuntimeExternalPayloadDeletions::class)->retryPending('default', 10);
        $this->assertDatabaseCount('runtime_external_payload_deletions', 0);
    }

    public function test_custom_driver_replay_confirms_an_already_absent_object_after_lost_acknowledgement(): void
    {
        $state = (object) ['objects' => [], 'attempts' => 0];
        $driver = new class($state) implements RuntimeExternalPayloadStorageDriver
        {
            public function __construct(private object $state) {}

            public function put(string $data, string $sha256, string $codec): string
            {
                $uri = $this->uriFor($sha256, $codec);
                $this->state->objects[$uri] = $data;

                return $uri;
            }

            public function uriFor(string $sha256, string $codec): string
            {
                return 'custom://fixture/'.$sha256;
            }

            public function get(string $uri): string
            {
                return $this->state->objects[$uri] ?? throw new ExternalPayloadObjectMissing('Already absent');
            }

            public function delete(string $uri): void
            {
                $this->state->attempts++;
                if (! array_key_exists($uri, $this->state->objects)) {
                    throw new ExternalPayloadObjectMissing('Already absent');
                }
                unset($this->state->objects[$uri]);
                throw new \RuntimeException('Synthetic lost custom-provider acknowledgement');
            }
        };
        app()->instance(NamespaceExternalPayloadStorage::class, new class($driver) extends NamespaceExternalPayloadStorage
        {
            public function __construct(private RuntimeExternalPayloadStorageDriver $driver) {}

            public function untrackedDriverForPolicy(array $policy, string $namespace): ?RuntimeExternalPayloadStorageDriver
            {
                return $this->driver;
            }
        });
        $registry = app(RuntimeExternalPayloadRegistry::class);
        $bytes = 'custom-provider payload';
        $uri = $registry->storeRetained('default', $driver, $bytes, 'avro', hash('sha256', $bytes));
        $registry->deleteUriUnlessReferenced('default', $uri, $driver, static fn (): bool => false);
        $this->assertSame([], $state->objects);
        $this->assertDatabaseCount('runtime_external_payload_deletions', 1);
        $this->assertDatabaseMissing('runtime_external_payloads', ['storage_uri' => $uri]);

        app(RuntimeExternalPayloadDeletions::class)->retryPending('default', 10);
        $this->assertSame(2, $state->attempts);
        $this->assertDatabaseCount('runtime_external_payload_deletions', 0);
        $this->assertDatabaseMissing('runtime_external_payloads', ['storage_uri' => $uri]);
    }

    private function prune(): TestResponse
    {
        return $this->postJson('/api/system/retention/pass', ['run_ids' => [$this->run->id]], $this->apiHeaders());
    }

    private function readableObjects(): int
    {
        $count = 0;
        foreach ($this->objects as $uri => $bytes) {
            try {
                $count += (int) ($this->provider->get($uri) === $bytes);
            } catch (\Throwable) {
            }
        }

        return $count;
    }

    private function createClosedRun(): void
    {
        $instance = WorkflowInstance::query()->create([
            'id' => (string) Str::ulid(), 'namespace' => 'default',
            'workflow_class' => 'SyntheticRetentionWorkflow', 'workflow_type' => 'synthetic.retention',
        ]);
        $this->run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(), 'workflow_instance_id' => $instance->id,
            'run_number' => 1, 'namespace' => 'default', 'status' => 'completed',
            'workflow_class' => 'SyntheticRetentionWorkflow', 'workflow_type' => 'synthetic.retention',
            'started_at' => now()->subDays(61), 'closed_at' => now()->subDays(60),
        ]);
        $instance->forceFill(['current_run_id' => $this->run->id, 'run_count' => 1])->save();
        WorkflowRunSummary::query()->create([
            'id' => $this->run->id, 'workflow_instance_id' => $instance->id, 'run_number' => 1,
            'namespace' => 'default', 'class' => 'SyntheticRetentionWorkflow',
            'workflow_type' => 'synthetic.retention', 'status' => 'completed', 'status_bucket' => 'completed',
            'closed_at' => now()->subDays(60),
        ]);
        $stream = WorkflowDurableStream::query()->create([
            'namespace' => 'default', 'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $this->run->id, 'stream_name' => 'synthetic', 'status' => 'closed',
            'last_offset' => 1, 'total_items' => 2, 'opened_at' => now()->subDays(61),
            'closed_at' => now()->subDays(60), 'retention_seconds' => 3600,
        ]);
        $this->objects = [];
        foreach (['synthetic payload A', 'synthetic payload B'] as $offset => $bytes) {
            $uri = app(RuntimeExternalPayloadRegistry::class)->storeRetained('default', $this->provider, $bytes, 'avro', hash('sha256', $bytes));
            $this->objects[$uri] = $bytes;
            WorkflowDurableStreamItem::query()->create([
                'stream_id' => $stream->id, 'namespace' => 'default', 'workflow_run_id' => $this->run->id,
                'stream_name' => 'synthetic', 'offset' => $offset, 'payload_reference' => $uri,
                'payload_codec' => 'avro', 'emitted_at' => now()->subDays(61),
            ]);
        }
    }
}
