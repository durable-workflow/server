<?php

namespace Tests\Feature;

use App\Support\LongPoller;
use App\Support\LongPollSignalStore;
use App\Support\LongPollWaitSlotStore;
use App\Support\WorkerProtocol;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Feature\Concerns\StoragePressureFixture;
use Tests\Support\OpenApiSchema;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowTask;

class StorageAdmissionTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;
    use StoragePressureFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createNamespace('default');
        $this->configureStoragePressure();
    }

    protected function tearDown(): void
    {
        $this->removeStoragePressure();
        parent::tearDown();
    }

    #[DataProvider('producerPaths')]
    public function test_draining_rejects_producers_without_database_mutation(string $path): void
    {
        $this->observeStoragePressure('draining');
        $writes = $this->watchWrites();
        $this->postJson($path, [], $this->apiHeaders())
            ->assertStatus(503)->assertHeader('Retry-After', '5')
            ->assertJsonPath('reason', 'storage_pressure')->assertJsonPath('request_admitted', false);
        $this->assertSame([], $writes->queries);
        $this->assertDatabaseCount('workflow_instances', 0);
    }

    public static function producerPaths(): array
    {
        return array_map(static fn (string $path): array => [$path], [
            '/api/workflows', '/api/activities', '/api/workflows/example/signal/continue',
            '/api/workflows/example/query/current', '/api/workflows/example/update/approve',
            '/api/workflows/example/message-streams/input/messages', '/api/schedules',
            '/api/external-payloads/v1', '/api/namespaces',
        ]);
    }

    #[DataProvider('pollPaths')]
    public function test_poll_pressure_response_does_not_claim_new_work(string $path): void
    {
        $this->observeStoragePressure('draining');
        $writes = $this->watchWrites();
        $response = $this->postJson($path, ['worker_id' => 'worker', 'task_queue' => 'queue', 'poll_request_id' => 'same-poll'], $this->workerHeaders())
            ->assertStatus(503)->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('task', null)->assertJsonPath('poll_status', 'storage_pressure')
            ->assertJsonPath('poll_request_id', 'same-poll')->assertJsonPath('retry_same_poll_request_id', true)
            ->assertJsonMissingPath('outcome');
        OpenApiSchema::fromFile(base_path('resources/platform-protocol-specs/worker-protocol-api.openapi.yaml'))
            ->assertReferenceMatches('#/components/schemas/WorkerStorageAdmissionRefusal', json_decode($response->getContent()));
        $this->assertSame([], $writes->queries);
    }

    public static function pollPaths(): array
    {
        return array_map(static fn (string $path): array => ['/api/worker/'.$path.'/poll'], [
            'workflow-tasks', 'activity-tasks', 'query-tasks', 'update-validation-tasks',
        ]);
    }

    public function test_authentication_protocol_and_namespace_validation_precede_admission(): void
    {
        $this->observeStoragePressure('fenced');
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'disposable-secret']);
        $this->postJson('/api/workflows', [], $this->apiHeaders())->assertUnauthorized();
        $this->postJson('/api/workflows', [], ['Authorization' => 'Bearer disposable-secret'])->assertStatus(400);
        $this->postJson('/api/workflows', [], $this->apiHeaders('missing') + ['Authorization' => 'Bearer disposable-secret'])
            ->assertNotFound()->assertJsonPath('reason', 'namespace_not_found');
    }

    public function test_wrong_role_and_worker_protocol_are_not_hidden_by_pressure(): void
    {
        $this->observeStoragePressure('fenced');
        config(['server.auth.driver' => 'token', 'server.auth.token' => null, 'server.auth.role_tokens.worker' => 'disposable-worker']);
        $headers = ['Authorization' => 'Bearer disposable-worker'];
        $this->postJson('/api/workflows', [], $this->apiHeaders() + $headers)->assertForbidden();
        $this->postJson('/api/worker/workflow-tasks/poll', [], $headers)->assertStatus(400);
    }

    public function test_existing_lease_survives_pressure_and_can_complete_during_draining(): void
    {
        $this->registerWorker('worker', 'queue');
        $started = $this->postJson('/api/workflows', [
            'workflow_id' => 'storage-example', 'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'queue', 'input' => ['input'],
        ], $this->apiHeaders())->assertCreated();
        $poll = ['worker_id' => 'worker', 'task_queue' => 'queue', 'poll_request_id' => 'same-poll', 'timeout_seconds' => 0];
        $task = $this->postJson('/api/worker/workflow-tasks/poll', $poll, $this->workerHeaders())
            ->assertOk()->json('task');
        $this->assertIsArray($task);
        $before = WorkflowTask::query()->findOrFail($task['task_id'])->getAttributes();

        $this->observeStoragePressure('fenced');
        $completion = [
            'lease_owner' => 'worker', 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => [['type' => 'complete_workflow', 'result' => null]],
        ];
        $path = '/api/worker/workflow-tasks/'.$task['task_id'].'/complete';
        $this->postJson($path, $completion, $this->workerHeaders())->assertStatus(503)->assertJsonPath('request_admitted', false);
        $this->assertSame($before, WorkflowTask::query()->findOrFail($task['task_id'])->getAttributes());
        $this->getJson('/api/workflows/storage-example/runs/'.$started->json('run_id').'/history', $this->apiHeaders())->assertOk();

        $this->observeStoragePressure('draining');
        $this->postJson($path, $completion, $this->workerHeaders())->assertOk()->assertJsonPath('recorded', true);
        $this->observeStoragePressure('fenced');
        $this->getJson('/api/workflows/storage-example/runs/'.$started->json('run_id'), $this->apiHeaders())
            ->assertOk()->assertJsonPath('status', 'completed');
    }

    public function test_configured_observation_loss_fences_without_exposing_the_file_or_identity(): void
    {
        unlink($this->pressureFile);
        $response = $this->postJson('/api/workflows', [], $this->apiHeaders())
            ->assertStatus(503)->assertJsonPath('reason', 'storage_admission_unavailable')
            ->assertJsonPath('storage_state', 'fenced');
        $this->assertStringNotContainsString($this->pressureFile, $response->getContent());
        $this->assertStringNotContainsString('test-storage', $response->getContent());
        $this->getJson('/api/ready')->assertStatus(503)->assertJsonPath('checks.storage_admission.state', 'fenced');
        $this->getJson('/api/health')->assertOk();
    }

    public function test_queue_daemon_pauses_claims_and_resumes_on_a_fresh_normal_observation(): void
    {
        foreach (['draining', 'fenced'] as $state) {
            $this->observeStoragePressure($state);
            $this->assertFalse(Event::until(new Looping('database', 'default')));
        }
        $this->observeStoragePressure('normal');
        $this->assertNotSame(false, Event::until(new Looping('database', 'default')));
        Event::listen(Looping::class, static fn (): bool => false);
        $this->assertFalse(Event::until(new Looping('database', 'default')));
    }

    #[DataProvider('durablePollPaths')]
    public function test_already_waiting_poll_stops_before_its_next_claim(string $path): void
    {
        $this->registerWorker('worker', 'queue');
        $poller = new class(app(LongPollSignalStore::class), app(LongPollWaitSlotStore::class)) extends LongPoller
        {
            public $afterPause;

            protected function pause(int $milliseconds): void
            {
                ($this->afterPause)();
                usleep(1000);
            }
        };
        $poller->afterPause = fn () => $this->observeStoragePressure('draining');
        $this->app->instance(LongPoller::class, $poller);
        $this->postJson($path, [
            'worker_id' => 'worker', 'task_queue' => 'queue', 'poll_request_id' => 'already-waiting', 'timeout_seconds' => 1,
        ], $this->workerHeaders())->assertStatus(503)->assertJsonPath('reason', 'storage_pressure')
            ->assertJsonPath('claim_admitted', false)->assertJsonPath('poll_request_id', 'already-waiting')
            ->assertJsonMissingPath('request_admitted');
        $this->assertDatabaseCount('workflow_tasks', 0);
    }

    public static function durablePollPaths(): array
    {
        return [['/api/worker/workflow-tasks/poll'], ['/api/worker/activity-tasks/poll']];
    }

    #[DataProvider('maintenanceCommands')]
    public function test_maintenance_pass_does_not_start_during_pressure(string $command): void
    {
        $this->observeStoragePressure('draining');
        $writes = $this->watchWrites();
        $this->artisan($command)->assertExitCode(1);
        $this->assertSame([], $writes->queries);
    }

    public static function maintenanceCommands(): array
    {
        return [['schedule:evaluate --json'], ['activity:timeout-enforce'], ['history:prune'], ['external-payloads:cleanup --json']];
    }

    private function watchWrites(): object
    {
        $writes = new class
        {
            public array $queries = [];
        };
        DB::listen(static function (QueryExecuted $query) use ($writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query->sql)) {
                $writes->queries[] = $query->sql;
            }
        });

        return $writes;
    }
}
