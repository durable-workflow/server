<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\NamespaceWorkflowScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowMessage;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowRunTimerEntry;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\WorkflowStub;

class HistoryRetentionTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    // ── Retention Status Endpoint ──────────────────────────────────

    public function test_retention_status_returns_empty_when_no_expired_runs(): void
    {
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/system/retention')
            ->assertOk()
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('retention_days', 30)
            ->assertJsonPath('expired_run_count', 0)
            ->assertJsonPath('expired_run_ids', [])
            ->assertJsonPath('scan_pressure', false);
    }

    public function test_retention_status_detects_expired_runs(): void
    {
        Queue::fake();

        $this->createNamespace('default');
        $runId = $this->createExpiredClosedRun('default', 'wf-retention-detect');

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/system/retention');

        $response->assertOk()
            ->assertJsonPath('expired_run_count', 1)
            ->assertJsonPath('scan_pressure', false);

        $this->assertContains($runId, $response->json('expired_run_ids'));
    }

    public function test_retention_status_respects_limit_query(): void
    {
        Queue::fake();

        $this->createNamespace('default');
        $this->createExpiredClosedRun('default', 'wf-retention-limit-a');
        $this->createExpiredClosedRun('default', 'wf-retention-limit-b');

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/system/retention?limit=1');

        $response->assertOk()
            ->assertJsonPath('expired_run_count', 1)
            ->assertJsonPath('scan_limit', 1)
            ->assertJsonPath('scan_pressure', true);

        $this->assertCount(1, $response->json('expired_run_ids'));
    }

    public function test_retention_status_respects_namespace_retention_days(): void
    {
        Queue::fake();

        $this->createNamespaceWithRetention('short-retention', 7);
        $runId = $this->createExpiredClosedRun('short-retention', 'wf-short-ret', daysAgo: 10);

        $response = $this->withHeaders($this->apiHeaders('short-retention'))
            ->getJson('/api/system/retention');

        $response->assertOk()
            ->assertJsonPath('namespace', 'short-retention')
            ->assertJsonPath('retention_days', 7)
            ->assertJsonPath('expired_run_count', 1);
    }

    public function test_forever_history_survives_repeated_scheduled_api_and_inline_retention_passes(): void
    {
        Queue::fake();
        Cache::flush();

        WorkflowNamespace::query()->create([
            'name' => 'protected',
            'description' => 'Protected history',
            'retention_mode' => WorkflowNamespace::RETENTION_MODE_FOREVER,
            'retention_days' => null,
            'status' => 'active',
        ]);
        $this->createNamespaceWithRetention('bounded', 7);

        $protectedRunId = $this->createExpiredClosedRun('protected', 'wf-protected-retention', daysAgo: 60);
        $boundedRunId = $this->createExpiredClosedRun('bounded', 'wf-bounded-retention', daysAgo: 60);
        $protectedEventCount = WorkflowHistoryEvent::where('workflow_run_id', $protectedRunId)->count();

        $this->artisan('history:prune')->assertExitCode(0);
        $this->artisan('history:prune')->assertExitCode(0);

        $this->assertNotNull(WorkflowRunSummary::find($protectedRunId));
        $this->assertNull(WorkflowRunSummary::find($boundedRunId));

        for ($pass = 0; $pass < 2; $pass++) {
            $this->withHeaders($this->apiHeaders('protected'))
                ->postJson('/api/system/retention/pass', [
                    'run_ids' => [$protectedRunId],
                ])
                ->assertOk()
                ->assertJsonPath('retention_mode', 'forever')
                ->assertJsonPath('retention_days', null)
                ->assertJsonPath('processed', 1)
                ->assertJsonPath('pruned', 0)
                ->assertJsonPath('skipped', 1)
                ->assertJsonPath('results.0.reason', 'namespace_retention_forever');
        }

        $this->registerWorker('protected-retention-worker', 'default', 'protected');

        for ($pass = 0; $pass < 2; $pass++) {
            $this->withHeaders($this->workerHeaders('protected'))
                ->postJson('/api/worker/heartbeat', [
                    'worker_id' => 'protected-retention-worker',
                ])
                ->assertOk()
                ->assertJsonPath('retention.throttled', false)
                ->assertJsonPath('retention.processed', 0)
                ->assertJsonPath('retention.pruned', 0);
        }

        $this->withHeaders($this->apiHeaders('protected'))
            ->getJson('/api/system/retention')
            ->assertOk()
            ->assertJsonPath('retention_mode', 'forever')
            ->assertJsonPath('retention_days', null)
            ->assertJsonPath('cutoff', null)
            ->assertJsonPath('expired_run_count', 0);

        $this->withHeaders($this->apiHeaders('protected'))
            ->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('namespace.retention_mode', 'forever')
            ->assertJsonPath('namespace.retention_days', null);

        $this->assertNotNull(WorkflowRunSummary::find($protectedRunId));
        $this->assertSame(
            $protectedEventCount,
            WorkflowHistoryEvent::where('workflow_run_id', $protectedRunId)->count(),
        );
    }

    public function test_retention_status_does_not_include_running_workflows(): void
    {
        Queue::fake();

        $this->createNamespace('default');

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-still-running');
        $workflow->start('Ada');
        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/system/retention')
            ->assertOk()
            ->assertJsonPath('expired_run_count', 0);
    }

    public function test_retention_status_namespace_scoping(): void
    {
        Queue::fake();

        $this->createNamespace('ns-a');
        $this->createNamespace('ns-b');
        $this->createExpiredClosedRun('ns-a', 'wf-a-retention');

        $this->withHeaders($this->apiHeaders('ns-b'))
            ->getJson('/api/system/retention')
            ->assertOk()
            ->assertJsonPath('expired_run_count', 0);

        $this->withHeaders($this->apiHeaders('ns-a'))
            ->getJson('/api/system/retention')
            ->assertOk()
            ->assertJsonPath('expired_run_count', 1);
    }

    // ── Retention Enforce Pass Endpoint ─────────────────────────────

    public function test_retention_pass_with_no_expired_runs(): void
    {
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/retention/pass')
            ->assertOk()
            ->assertJsonPath('processed', 0)
            ->assertJsonPath('pruned', 0)
            ->assertJsonPath('skipped', 0)
            ->assertJsonPath('failed', 0)
            ->assertJsonPath('results', []);
    }

    public function test_retention_pass_prunes_expired_run(): void
    {
        Queue::fake();

        $this->createNamespace('default');
        $runId = $this->createExpiredClosedRun('default', 'wf-prune-test');

        $this->assertGreaterThan(0, WorkflowHistoryEvent::where('workflow_run_id', $runId)->count());

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/retention/pass');

        $response->assertOk()
            ->assertJsonPath('processed', 1)
            ->assertJsonPath('pruned', 1)
            ->assertJsonPath('skipped', 0)
            ->assertJsonPath('failed', 0);

        $results = $response->json('results');
        $this->assertCount(1, $results);
        $this->assertSame($runId, $results[0]['run_id']);
        $this->assertSame('pruned', $results[0]['outcome']);
        $this->assertGreaterThanOrEqual(0, $results[0]['history_events_deleted']);

        $this->assertSame(0, WorkflowHistoryEvent::where('workflow_run_id', $runId)->count());
        $this->assertNull(WorkflowRunSummary::find($runId));
    }

    public function test_retention_pass_prunes_extended_run_detail_rows_transactionally(): void
    {
        Queue::fake();

        $this->createNamespace('default');
        $runId = $this->createExpiredClosedRun('default', 'wf-prune-detail-rows');
        $run = WorkflowRun::findOrFail($runId);

        WorkflowMemo::query()->create([
            'workflow_run_id' => $runId,
            'workflow_instance_id' => $run->workflow_instance_id,
            'key' => 'customer',
            'value' => ['id' => 'cust-123'],
            'upserted_at_sequence' => 1,
        ]);

        WorkflowSearchAttribute::query()->create([
            'workflow_run_id' => $runId,
            'workflow_instance_id' => $run->workflow_instance_id,
            'key' => 'plan',
            'type' => WorkflowSearchAttribute::TYPE_KEYWORD,
            'value_keyword' => 'enterprise',
            'upserted_at_sequence' => 1,
        ]);

        WorkflowMessage::query()->create([
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $runId,
            'direction' => 'inbound',
            'channel' => 'signal',
            'stream_key' => 'signal:approve',
            'sequence' => 999,
            'consume_state' => 'pending',
        ]);

        WorkflowRunTimerEntry::query()->create([
            'id' => 'retention-timer-'.$runId,
            'workflow_run_id' => $runId,
            'workflow_instance_id' => $run->workflow_instance_id,
            'timer_id' => 'retention-timer',
            'position' => 1,
            'status' => 'scheduled',
        ]);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/retention/pass')
            ->assertOk()
            ->assertJsonPath('processed', 1)
            ->assertJsonPath('pruned', 1)
            ->assertJsonPath('results.0.deleted.memos_deleted', 1)
            ->assertJsonPath('results.0.deleted.search_attributes_deleted', 1)
            ->assertJsonPath('results.0.deleted.messages_deleted', 1)
            ->assertJsonPath('results.0.deleted.run_timer_entries_deleted', 1);

        $this->assertSame(0, WorkflowMemo::where('workflow_run_id', $runId)->count());
        $this->assertSame(0, WorkflowSearchAttribute::where('workflow_run_id', $runId)->count());
        $this->assertSame(0, WorkflowMessage::where('workflow_run_id', $runId)->count());
        $this->assertSame(0, WorkflowRunTimerEntry::where('workflow_run_id', $runId)->count());
        $this->assertNull(WorkflowRunSummary::find($runId));
    }

    public function test_retention_pass_deletes_local_external_payload_references(): void
    {
        Queue::fake();

        $storageDirectory = storage_path('framework/testing/retention-external-payloads');
        File::deleteDirectory($storageDirectory);

        $this->createNamespace('default');
        WorkflowNamespace::where('name', 'default')->update([
            'external_payload_storage' => [
                'driver' => 'local',
                'enabled' => true,
                'config' => [
                    'uri' => 'file://'.$storageDirectory,
                ],
            ],
        ]);

        $runId = $this->createExpiredClosedRun('default', 'wf-prune-external-payload');
        $path = $storageDirectory.'/payloads/external-result.bin';
        $payload = 'large encoded payload bytes';
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $payload);

        WorkflowHistoryEvent::query()->create([
            'workflow_run_id' => $runId,
            'sequence' => 999,
            'event_type' => HistoryEventType::ActivityCompleted->value,
            'payload' => [
                'result' => [
                    'external_storage' => $this->externalStorageReference('file://'.$path, $payload),
                ],
            ],
            'recorded_at' => now(),
        ]);

        $this->assertFileExists($path);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/retention/pass')
            ->assertOk()
            ->assertJsonPath('processed', 1)
            ->assertJsonPath('pruned', 1)
            ->assertJsonPath('results.0.external_payloads_deleted', 1);

        $this->assertFileDoesNotExist($path);
        $this->assertNull(WorkflowRunSummary::find($runId));

        File::deleteDirectory($storageDirectory);
    }

    public function test_retention_pass_blocks_external_payload_prune_when_driver_is_unavailable(): void
    {
        Queue::fake();

        $this->createNamespace('default');
        WorkflowNamespace::where('name', 'default')->update([
            'external_payload_storage' => [
                'driver' => 's3',
                'enabled' => true,
                'config' => [
                    'bucket' => 'dw-payloads',
                    'prefix' => 'retention/',
                ],
            ],
        ]);

        $runId = $this->createExpiredClosedRun('default', 'wf-prune-external-s3');
        $payload = 'large encoded payload bytes';

        WorkflowHistoryEvent::query()->create([
            'workflow_run_id' => $runId,
            'sequence' => 999,
            'event_type' => HistoryEventType::ActivityCompleted->value,
            'payload' => [
                'result' => [
                    'external_storage' => $this->externalStorageReference('s3://dw-payloads/retention/result.bin', $payload),
                ],
            ],
            'recorded_at' => now(),
        ]);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/retention/pass')
            ->assertOk()
            ->assertJsonPath('processed', 1)
            ->assertJsonPath('pruned', 0)
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('results.0.outcome', 'skipped')
            ->assertJsonPath('results.0.reason', 'external_payload_storage_driver_unavailable');

        $this->assertNotNull(WorkflowRunSummary::find($runId));
    }

    public function test_retention_pass_deletes_configured_object_storage_references(): void
    {
        Queue::fake();
        config([
            'filesystems.disks.retention-object-payloads' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/retention-object-payloads'),
            ],
        ]);
        Storage::fake('retention-object-payloads');

        $this->createNamespace('default');
        WorkflowNamespace::where('name', 'default')->update([
            'external_payload_storage' => [
                'driver' => 's3',
                'enabled' => true,
                'config' => [
                    'disk' => 'retention-object-payloads',
                    'bucket' => 'dw-payloads',
                    'prefix' => 'retention/',
                ],
            ],
        ]);

        $runId = $this->createExpiredClosedRun('default', 'wf-prune-external-s3-configured');
        $payload = 'large encoded payload bytes';
        $key = 'retention/avro/'.substr(hash('sha256', $payload), 0, 2).'/'.hash('sha256', $payload);

        Storage::disk('retention-object-payloads')->put($key, $payload);

        WorkflowHistoryEvent::query()->create([
            'workflow_run_id' => $runId,
            'sequence' => 999,
            'event_type' => HistoryEventType::ActivityCompleted->value,
            'payload' => [
                'result' => [
                    'external_storage' => $this->externalStorageReference('s3://dw-payloads/'.$key, $payload),
                ],
            ],
            'recorded_at' => now(),
        ]);

        Storage::disk('retention-object-payloads')->assertExists($key);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/retention/pass')
            ->assertOk()
            ->assertJsonPath('processed', 1)
            ->assertJsonPath('pruned', 1)
            ->assertJsonPath('results.0.external_payloads_deleted', 1);

        Storage::disk('retention-object-payloads')->assertMissing($key);
        $this->assertNull(WorkflowRunSummary::find($runId));
    }

    public function test_retention_pass_keeps_external_payload_referenced_by_retained_run(): void
    {
        Queue::fake();
        config([
            'filesystems.disks.retention-shared-object-payloads' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/retention-shared-object-payloads'),
            ],
        ]);
        Storage::fake('retention-shared-object-payloads');

        $this->createNamespace('default');
        WorkflowNamespace::where('name', 'default')->update([
            'external_payload_storage' => [
                'driver' => 's3',
                'enabled' => true,
                'config' => [
                    'disk' => 'retention-shared-object-payloads',
                    'bucket' => 'dw-payloads',
                    'prefix' => 'retention/',
                ],
            ],
        ]);

        $expiredRunId = $this->createExpiredClosedRun('default', 'wf-prune-shared-external-payload');
        $retainedRunId = $this->createExpiredClosedRun('default', 'wf-retain-shared-external-payload', daysAgo: 1);
        $payload = 'shared large encoded payload bytes';
        $key = 'retention/avro/'.substr(hash('sha256', $payload), 0, 2).'/'.hash('sha256', $payload);
        $reference = $this->externalStorageReference('s3://dw-payloads/'.$key, $payload);

        Storage::disk('retention-shared-object-payloads')->put($key, $payload);

        foreach ([$expiredRunId, $retainedRunId] as $runId) {
            WorkflowHistoryEvent::query()->create([
                'workflow_run_id' => $runId,
                'sequence' => 999,
                'event_type' => HistoryEventType::ActivityCompleted->value,
                'payload' => [
                    'result' => [
                        'external_storage' => $reference,
                    ],
                ],
                'recorded_at' => now(),
            ]);
        }

        Storage::disk('retention-shared-object-payloads')->assertExists($key);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/retention/pass')
            ->assertOk()
            ->assertJsonPath('processed', 1)
            ->assertJsonPath('pruned', 1)
            ->assertJsonPath('results.0.external_payloads_deleted', 0);

        Storage::disk('retention-shared-object-payloads')->assertExists($key);
        $this->assertNull(WorkflowRunSummary::find($expiredRunId));
        $this->assertNotNull(WorkflowRunSummary::find($retainedRunId));
    }

    public function test_retention_pass_preserves_cross_namespace_service_call_payload_for_caller_run(): void
    {
        Queue::fake();

        $storageDirectory = storage_path('framework/testing/retention-cross-namespace-service-call-payloads');
        $storageRoots = [
            'tenant-a' => $storageDirectory.'/tenant-a',
            'tenant-b' => $storageDirectory.'/tenant-b',
        ];
        File::deleteDirectory($storageDirectory);

        foreach ($storageRoots as $namespace => $root) {
            $this->createNamespace($namespace);
            WorkflowNamespace::where('name', $namespace)->update([
                'external_payload_storage' => [
                    'driver' => 'local',
                    'enabled' => true,
                    'config' => [
                        'uri' => 'file://'.$root,
                    ],
                ],
            ]);
        }

        $callerRunId = $this->createExpiredClosedRun(
            'tenant-a',
            'wf-prune-cross-namespace-service-caller',
        );
        $path = $storageRoots['tenant-b'].'/payloads/target-output.bin';
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, 'target-owned service call output');

        $serviceCallId = $this->addServiceCall([
            'namespace' => 'tenant-b',
            'caller_namespace' => 'tenant-a',
            'target_namespace' => 'tenant-b',
            'caller_workflow_run_id' => $callerRunId,
            'output_payload_reference' => 'file://'.$path,
        ]);

        $this->withHeaders($this->apiHeaders('tenant-a'))
            ->postJson('/api/system/retention/pass')
            ->assertOk()
            ->assertJsonPath('processed', 1)
            ->assertJsonPath('pruned', 1)
            ->assertJsonPath('results.0.external_payloads_deleted', 0);

        $this->assertFileExists($path);
        $this->assertDatabaseHas('workflow_service_calls', ['id' => $serviceCallId]);
        $this->assertNull(WorkflowRunSummary::find($callerRunId));

        File::deleteDirectory($storageDirectory);
    }

    public function test_retention_pass_deletes_stored_reference_payload_strings_from_run_and_activity_columns(): void
    {
        Queue::fake();

        $storageDirectory = storage_path('framework/testing/retention-stored-reference-payloads');
        File::deleteDirectory($storageDirectory);

        $this->createNamespace('default');
        WorkflowNamespace::where('name', 'default')->update([
            'external_payload_storage' => [
                'driver' => 'local',
                'enabled' => true,
                'config' => [
                    'uri' => 'file://'.$storageDirectory,
                ],
            ],
        ]);

        $runId = $this->createExpiredClosedRun('default', 'wf-prune-stored-reference-payloads');
        $references = [];
        $paths = [];

        foreach ([
            'workflow-arguments',
            'workflow-output',
            'activity-arguments',
            'activity-result',
            'activity-exception',
        ] as $name) {
            $path = $storageDirectory.'/payloads/'.$name.'.bin';
            $payload = 'large encoded payload bytes for '.$name;
            File::ensureDirectoryExists(dirname($path));
            file_put_contents($path, $payload);

            $paths[] = $path;
            $references[$name] = $this->storedExternalPayloadReference('file://'.$path, $payload);
        }

        WorkflowRun::where('id', $runId)->update([
            'arguments' => $references['workflow-arguments'],
            'output' => $references['workflow-output'],
            'payload_codec' => 'avro',
        ]);

        ActivityExecution::query()->create([
            'workflow_run_id' => $runId,
            'sequence' => 999,
            'activity_class' => 'retention.external-payload-test',
            'activity_type' => 'retention.external-payload-test',
            'status' => ActivityStatus::Completed->value,
            'payload_codec' => 'avro',
            'arguments' => $references['activity-arguments'],
            'result' => $references['activity-result'],
            'exception' => $references['activity-exception'],
            'attempt_count' => 1,
            'closed_at' => now()->subDays(60),
        ]);

        foreach ($paths as $path) {
            $this->assertFileExists($path);
        }

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/retention/pass')
            ->assertOk()
            ->assertJsonPath('processed', 1)
            ->assertJsonPath('pruned', 1)
            ->assertJsonPath('results.0.external_payloads_deleted', 5);

        foreach ($paths as $path) {
            $this->assertFileDoesNotExist($path);
        }

        $this->assertNull(WorkflowRunSummary::find($runId));

        File::deleteDirectory($storageDirectory);
    }

    public function test_retention_pass_with_specific_run_ids(): void
    {
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/retention/pass', [
                'run_ids' => ['non-existent-id'],
            ])
            ->assertOk()
            ->assertJsonPath('processed', 1)
            ->assertJsonPath('pruned', 0)
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('results.0.outcome', 'skipped')
            ->assertJsonPath('results.0.reason', 'run_not_found');
    }

    public function test_retention_pass_skips_non_terminal_runs(): void
    {
        Queue::fake();

        $this->createNamespace('default');

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-running-prune');
        $start = $workflow->start('Ada');
        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $summary = WorkflowRunSummary::find($start->runId());

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/retention/pass', [
                'run_ids' => [$start->runId()],
            ])
            ->assertOk()
            ->assertJsonPath('processed', 1)
            ->assertJsonPath('pruned', 0)
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('results.0.reason', 'run_not_terminal');
    }

    public function test_retention_pass_respects_namespace_scoping(): void
    {
        Queue::fake();

        $this->createNamespace('ns-a');
        $this->createNamespace('ns-b');
        $runId = $this->createExpiredClosedRun('ns-a', 'wf-scoped-prune');

        $this->withHeaders($this->apiHeaders('ns-b'))
            ->postJson('/api/system/retention/pass', [
                'run_ids' => [$runId],
            ])
            ->assertOk()
            ->assertJsonPath('pruned', 0)
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('results.0.reason', 'run_not_found');
    }

    // ── Artisan Command ────────────────────────────────────────────

    public function test_artisan_prune_reports_no_expired_runs(): void
    {
        $this->createNamespace('default');

        $this->artisan('history:prune')
            ->assertExitCode(0)
            ->expectsOutputToContain('No expired runs to prune.');
    }

    public function test_artisan_prune_prunes_expired_runs(): void
    {
        Queue::fake();

        $this->createNamespace('default');
        $runId = $this->createExpiredClosedRun('default', 'wf-artisan-prune');

        $this->artisan('history:prune')
            ->assertExitCode(0)
            ->expectsOutputToContain('Done: 1 pruned, 0 skipped, 0 failed.');

        $this->assertNull(WorkflowRunSummary::find($runId));
    }

    public function test_artisan_prune_respects_namespace_filter(): void
    {
        Queue::fake();

        $this->createNamespace('ns-a');
        $this->createNamespace('ns-b');
        $this->createExpiredClosedRun('ns-a', 'wf-ns-a-prune');

        $this->artisan('history:prune', ['--namespace' => 'ns-b'])
            ->assertExitCode(0)
            ->expectsOutputToContain('No expired runs to prune.');
    }

    public function test_worker_heartbeat_runs_bounded_inline_retention_pass(): void
    {
        Queue::fake();
        Cache::flush();

        $this->createNamespace('default');
        $this->registerWorker('retention-worker', 'default');
        $runId = $this->createExpiredClosedRun('default', 'wf-heartbeat-retention');

        $this->assertNotNull(WorkflowRunSummary::find($runId));

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'retention-worker',
            ])
            ->assertOk()
            ->assertJsonPath('acknowledged', true)
            ->assertJsonPath('retention.throttled', false)
            ->assertJsonPath('retention.processed', 1)
            ->assertJsonPath('retention.pruned', 1);

        $this->assertNull(WorkflowRunSummary::find($runId));
        $this->assertSame(0, WorkflowHistoryEvent::where('workflow_run_id', $runId)->count());
    }

    public function test_worker_heartbeat_retention_pass_is_throttled_per_namespace(): void
    {
        Queue::fake();
        Cache::flush();

        $this->createNamespace('default');
        $this->registerWorker('retention-worker', 'default');
        $this->createExpiredClosedRun('default', 'wf-heartbeat-retention-a');
        $secondRunId = $this->createExpiredClosedRun('default', 'wf-heartbeat-retention-b');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'retention-worker',
            ])
            ->assertOk()
            ->assertJsonPath('retention.throttled', false)
            ->assertJsonPath('retention.pruned', 1);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'retention-worker',
            ])
            ->assertOk()
            ->assertJsonPath('retention.throttled', true)
            ->assertJsonPath('retention.processed', 0);

        $this->assertNotNull(WorkflowRunSummary::find($secondRunId));
    }

    // ── Cluster Info ───────────────────────────────────────────────

    public function test_cluster_info_advertises_history_retention_capability(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('capabilities.history_retention', true);
    }

    // ── Auth ───────────────────────────────────────────────────────

    public function test_retention_endpoints_require_auth(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'test-token']);

        $this->getJson('/api/system/retention')
            ->assertUnauthorized();

        $this->postJson('/api/system/retention/pass')
            ->assertUnauthorized();
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function createNamespaceWithRetention(string $name, int $retentionDays): void
    {
        WorkflowNamespace::query()->updateOrCreate(
            ['name' => $name],
            [
                'description' => 'Test namespace',
                'retention_days' => $retentionDays,
                'status' => 'active',
            ],
        );
    }

    private function createExpiredClosedRun(string $namespace, string $workflowId, int $daysAgo = 60): string
    {
        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, $workflowId);
        $start = $workflow->start('Ada');
        NamespaceWorkflowScope::bind($namespace, $workflow->id(), ExternalGreetingWorkflow::class);

        // Force namespace on all related models (WorkflowStub defaults to 'default')
        WorkflowRun::where('id', $start->runId())->update(['namespace' => $namespace]);
        WorkflowRunSummary::where('id', $start->runId())->update(['namespace' => $namespace]);
        WorkflowTask::where('workflow_run_id', $start->runId())->update(['namespace' => $namespace]);

        $this->runReadyWorkflowTask($start->runId());

        $run = WorkflowRun::find($start->runId());
        $run->forceFill([
            'status' => RunStatus::Completed->value,
            'closed_at' => now()->subDays($daysAgo),
            'namespace' => $namespace,
        ])->save();

        $summary = WorkflowRunSummary::find($start->runId());
        if ($summary) {
            $summary->forceFill([
                'status' => RunStatus::Completed->value,
                'status_bucket' => 'completed',
                'closed_at' => now()->subDays($daysAgo),
                'namespace' => $namespace,
            ])->save();
        }

        return $start->runId();
    }

    /**
     * @return array{schema: string, uri: string, sha256: string, size_bytes: int, codec: string}
     */
    private function externalStorageReference(string $uri, string $payload): array
    {
        return [
            'schema' => 'durable-workflow.v2.external-payload-reference.v1',
            'uri' => $uri,
            'sha256' => hash('sha256', $payload),
            'size_bytes' => strlen($payload),
            'codec' => 'avro',
        ];
    }

    private function storedExternalPayloadReference(string $uri, string $payload): string
    {
        return ExternalPayloads::encodeStoredEnvelope([
            'codec' => 'avro',
            'external_storage' => $this->externalStorageReference($uri, $payload),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function addServiceCall(array $overrides): string
    {
        $now = now();
        $id = (string) Str::ulid();

        DB::table('workflow_service_calls')->insert(array_merge([
            'id' => $id,
            'namespace' => 'default',
            'endpoint_name' => 'billing',
            'service_name' => 'ledger',
            'operation_name' => 'capture',
            'caller_namespace' => 'default',
            'target_namespace' => 'default',
            'status' => 'completed',
            'operation_mode' => 'async',
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        return $id;
    }
}
