<?php

namespace Tests\Feature;

use App\Support\NamespaceWorkflowScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\StickyExecution;

class LegacyImportedTaskOwnershipTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    #[DataProvider('instanceOwners')]
    public function test_task_helpers_require_the_canonical_instance_owner(?string $owner): void
    {
        $task = $this->prepareTask('workflow');
        $this->setInstanceOwner($owner);
        $before = $this->workflowRows();
        $expected = $owner === 'default' ? $task->id : null;

        $this->assertSame($expected, NamespaceWorkflowScope::taskQuery('default')->value('id'));
        $this->assertSame($expected, NamespaceWorkflowScope::task('default', $task->id)?->id);
        $this->assertSame($expected, DB::transaction(
            fn () => NamespaceWorkflowScope::lockTaskForMutation('default', $task->id)?->id,
        ));
        $this->assertSame($before, $this->workflowRows());
    }

    #[DataProvider('taskOwners')]
    public function test_polling_requires_the_canonical_instance_owner(string $kind, ?string $owner): void
    {
        $task = $this->prepareTask($kind);
        $this->setInstanceOwner($owner);
        $before = $this->workflowRows();

        $response = $this->poll($kind)->assertOk();

        if ($owner === 'default') {
            $response->assertJsonPath('task.task_id', $task->id);
            $this->assertSame('leased', $task->fresh()->status->value);
        } else {
            $response->assertJsonPath('task', null);
            $this->assertSame($before, $this->workflowRows());
        }
    }

    #[DataProvider('mutationOwners')]
    public function test_worker_mutations_require_the_canonical_instance_owner(string $kind, ?string $owner, string $operation): void
    {
        $task = $this->prepareTask($kind);
        $claim = $this->poll($kind)->assertOk()->json('task');
        $this->assertSame($task->id, $claim['task_id']);
        $this->setInstanceOwner($owner);
        $before = $this->workflowRows();
        $body = ['lease_owner' => $claim['lease_owner']];
        if ($kind === 'workflow') {
            $body['workflow_task_attempt'] = $claim['workflow_task_attempt'];
            if ($operation === 'complete') {
                $body['commands'] = [['type' => 'complete_workflow', 'result' => Serializer::serializeWithCodec('avro', 'done')]];
            }
        } else {
            $body['activity_attempt_id'] = $claim['activity_attempt_id'];
            if ($operation === 'complete') {
                $body['result'] = 'done';
            }
        }
        if ($operation === 'fail') {
            $body['failure'] = ['message' => 'worker failure'];
        }

        $response = $this->postJson('/api/worker/'.$kind.'-tasks/'.$task->id.'/'.$operation, $body, $this->workerHeaders());

        if ($owner === 'default') {
            $response->assertOk();
        } else {
            $response->assertNotFound()->assertJsonPath('reason', 'task_not_found');
            $this->assertSame($before, $this->workflowRows());
        }
    }

    #[DataProvider('taskOwners')]
    public function test_worker_replacement_only_releases_tasks_of_the_canonical_owner(string $kind, ?string $owner): void
    {
        $task = $this->prepareTask($kind);
        $claim = $this->poll($kind)->assertOk()->json('task');
        $this->assertSame($task->id, $claim['task_id']);
        DB::table('workflow_runs')->where('id', $task->workflow_run_id)->update([
            'sticky_worker_id' => 'legacy-worker', 'sticky_until' => now()->addMinute(),
        ]);
        DB::table('workflow_tasks')->where('id', $task->id)->update([
            'sticky_worker_id' => 'legacy-worker', 'sticky_until' => now()->addMinute(),
        ]);
        $this->setInstanceOwner($owner);
        $before = $this->workflowRows();

        $this->replaceWorker($kind);

        if ($owner !== 'default') {
            $this->assertSame($before, $this->workflowRows());

            return;
        }

        $task->refresh();
        $this->assertSame('ready', $task->status->value);
        $this->assertNull($task->lease_owner);
        $this->assertNull($task->sticky_worker_id);
        $this->assertSame(StickyExecution::MODE_FORCED_COLD_REPLAY, $task->sticky_replay_mode);
        $this->assertNull($task->run->sticky_worker_id);
        if ($kind === 'activity') {
            $attempt = ActivityAttempt::query()->findOrFail($claim['activity_attempt_id']);
            $this->assertSame('expired', $attempt->status->value);
            $this->assertNull($attempt->lease_expires_at);
        }
    }

    #[DataProvider('copiedActivityReferences')]
    public function test_worker_replacement_does_not_expire_an_attempt_from_another_run(string $table): void
    {
        $task = $this->prepareTask('activity');
        $claim = $this->poll('activity')->assertOk()->json('task');
        $otherRun = $task->run->replicate();
        $otherRun->id = 'other-run';
        $otherRun->run_number = 2;
        $otherRun->save();
        DB::table($table)->update(['workflow_run_id' => $otherRun->id]);
        $before = DB::table('activity_attempts')->where('id', $claim['activity_attempt_id'])->first();

        $this->replaceWorker('activity');

        $this->assertEquals($before, DB::table('activity_attempts')->where('id', $claim['activity_attempt_id'])->first());
        $this->assertSame('ready', $task->fresh()->status->value);
    }

    public static function instanceOwners(): iterable
    {
        foreach (['other', 'DEFAULT', null, 'default'] as $owner) {
            yield $owner ?? 'embedded null' => [$owner];
        }
    }

    public static function taskOwners(): iterable
    {
        foreach (['workflow', 'activity'] as $kind) {
            foreach (self::instanceOwners() as $label => [$owner]) {
                yield $kind.' / '.$label => [$kind, $owner];
            }
        }
    }

    public static function mutationOwners(): iterable
    {
        foreach (self::taskOwners() as $label => [$kind, $owner]) {
            foreach (['heartbeat', 'complete', 'fail'] as $operation) {
                yield $label.' / '.$operation => [$kind, $owner, $operation];
            }
        }
    }

    public static function copiedActivityReferences(): iterable
    {
        yield 'execution run' => ['activity_executions'];
        yield 'attempt run' => ['activity_attempts'];
    }

    private function prepareTask(string $kind): WorkflowTask
    {
        Queue::fake();
        $this->createNamespace('default');
        $this->configureWorkflowTypes(['tests.external-greeting-workflow' => ExternalGreetingWorkflow::class]);
        $this->postJson('/api/worker/register', $this->registration($kind), $this->workerHeaders())->assertCreated();
        $runId = $this->postJson('/api/workflows', [
            'workflow_id' => 'legacy-instance', 'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'legacy-workflows', 'input' => ['Ada'],
        ], $this->apiHeaders())->assertCreated()->json('run_id');
        if ($kind === 'activity') {
            $this->runReadyWorkflowTask($runId);
        }

        return WorkflowTask::query()->where('workflow_run_id', $runId)
            ->where('task_type', $kind)->where('status', 'ready')->firstOrFail();
    }

    private function setInstanceOwner(?string $owner): void
    {
        // The old importer attached a default run to an existing foreign instance.
        DB::table('workflow_instances')->where('id', 'legacy-instance')->update(['namespace' => $owner]);
    }

    private function poll(string $kind): TestResponse
    {
        return $this->postJson('/api/worker/'.$kind.'-tasks/poll', [
            'worker_id' => 'legacy-worker', 'task_queue' => $this->queue($kind),
        ], $this->workerHeaders());
    }

    private function registration(string $kind): array
    {
        return [
            'worker_id' => 'legacy-worker', 'task_queue' => $this->queue($kind), 'runtime' => 'php',
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'supported_activity_types' => ['tests.external-greeting-activity'],
        ];
    }

    private function replaceWorker(string $kind): void
    {
        $this->postJson('/api/worker/register', $this->registration($kind), $this->workerHeaders())->assertCreated();
    }

    private function queue(string $kind): string
    {
        return $kind === 'activity' ? 'external-activities' : 'legacy-workflows';
    }

    private function workflowRows(): array
    {
        $rows = [];
        foreach (['workflow_instances', 'workflow_runs', 'workflow_run_summaries', 'workflow_tasks', 'workflow_history_events', 'activity_executions', 'activity_attempts'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all();
        }

        return $rows;
    }
}
