<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Support\WorkerProtocol;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Support\OpenApiSchema;
use Tests\TestCase;

class WorkerDatabaseUnavailableTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    private bool $databaseUnavailable = false;

    private string $failureQuery = 'workflow_worker_registrations';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createNamespace('default');
        $this->registerWorker(workerId: 'database-worker', taskQueue: 'database-queue',
            supportedWorkflowTypes: ['ExampleWorkflow'], supportedActivityTypes: ['ExampleActivity']);

        DB::connection()->beforeExecuting(function (string $query): void {
            if ($this->databaseUnavailable && str_contains($query, $this->failureQuery)) {
                $exception = new PDOException('SQLSTATE[HY000] [2002] Connection refused');
                $exception->errorInfo = ['HY000', 2002, 'private database connection refused'];
                throw $exception;
            }
        });
    }

    #[DataProvider('pollPaths')]
    public function test_database_loss_preserves_the_logical_poll_identity(string $path): void
    {
        $this->databaseUnavailable = true;
        $response = $this->postJson($path, [
            'worker_id' => 'database-worker', 'task_queue' => 'database-queue',
            'poll_request_id' => 'same-logical-poll', 'timeout_seconds' => 0,
        ], $this->workerHeaders());

        $response->assertStatus(503)->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeader('Retry-After', '1')->assertJsonPath('reason', 'backend_unavailable')
            ->assertJsonPath('poll_status', 'backend_unavailable')->assertJsonPath('task', null)
            ->assertJsonPath('outcome', 'unknown')->assertJsonPath('retryable', true)
            ->assertJsonPath('poll_request_id', 'same-logical-poll')
            ->assertJsonPath('retry_same_poll_request_id', true);
        $this->assertStringNotContainsString('private database', $response->getContent());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        OpenApiSchema::fromFile(base_path('resources/platform-protocol-specs/worker-protocol-api.openapi.yaml'))
            ->assertReferenceMatches('#/components/schemas/WorkerBackendUnavailable', json_decode($response->getContent()));

        $this->databaseUnavailable = false;
        $this->assertSame(1, WorkerRegistration::query()->where('worker_id', 'database-worker')->count());
    }

    public static function pollPaths(): array
    {
        return [
            ['/api/worker/workflow-tasks/poll'],
            ['/api/worker/activity-tasks/poll'],
            ['/api/worker/query-tasks/poll'],
            ['/api/worker/update-validation-tasks/poll'],
        ];
    }

    public function test_worker_heartbeat_does_not_claim_an_acknowledgement_during_database_loss(): void
    {
        $this->databaseUnavailable = true;
        $this->postJson('/api/worker/heartbeat', ['worker_id' => 'database-worker'], $this->workerHeaders())
            ->assertStatus(503)->assertJsonPath('reason', 'backend_unavailable')
            ->assertJsonPath('operation', 'heartbeat_worker')->assertJsonPath('worker_id', 'database-worker')
            ->assertJsonPath('retryable', true)->assertJsonPath('outcome', 'unknown')
            ->assertJsonMissingPath('acknowledged');
    }

    public function test_connection_loss_in_a_bootstrap_query_keeps_the_retry_contract(): void
    {
        $this->failureQuery = 'migrations';
        $this->databaseUnavailable = true;

        $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'database-worker', 'task_queue' => 'database-queue',
            'poll_request_id' => 'same-logical-poll', 'timeout_seconds' => 0,
        ], $this->workerHeaders())->assertStatus(503)
            ->assertJsonPath('reason', 'backend_unavailable')
            ->assertJsonPath('poll_request_id', 'same-logical-poll');
    }

    public function test_invalid_authentication_remains_unauthorized_during_database_loss(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'test-secret-token']);
        $this->databaseUnavailable = true;

        $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'database-worker', 'task_queue' => 'database-queue',
            'poll_request_id' => 'same-logical-poll', 'timeout_seconds' => 0,
        ], $this->workerHeaders() + ['Authorization' => 'Bearer invalid-token'])
            ->assertStatus(401)->assertJsonMissingPath('retryable');
    }

    public function test_registration_outcome_is_unknown_not_falsely_rejected(): void
    {
        $this->databaseUnavailable = true;
        $this->postJson('/api/worker/register', [
            'worker_id' => 'database-worker', 'task_queue' => 'database-queue', 'runtime' => 'php',
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
        ], $this->workerHeaders())
            ->assertStatus(503)->assertJsonPath('reason', 'backend_unavailable')
            ->assertJsonPath('operation', 'register_worker')->assertJsonPath('worker_id', 'database-worker')
            ->assertJsonPath('retryable', true)->assertJsonPath('outcome', 'unknown')
            ->assertJsonMissingPath('registered');
    }

    #[DataProvider('bootstrapErrors')]
    public function test_bootstrap_admission_distinguishes_connection_loss_from_configuration_failure(int $code, string $reason): void
    {
        $exception = new PDOException('private database diagnostic');
        $exception->errorInfo = ['HY000', $code, 'private database diagnostic'];
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getPdo')->andThrow($exception);
        $manager = DB::getFacadeRoot();
        DB::shouldReceive('connection')->andReturn($connection);

        try {
            $response = $this->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'database-worker', 'task_queue' => 'database-queue',
                'poll_request_id' => 'bootstrap-poll', 'timeout_seconds' => 0,
            ], $this->workerHeaders());
            $response->assertStatus(503)->assertJsonPath('reason', $reason);
            $this->assertStringNotContainsString('private database', $response->getContent());
            if ($reason === 'backend_unavailable') {
                $response->assertJsonPath('poll_request_id', 'bootstrap-poll')
                    ->assertJsonPath('retry_same_poll_request_id', true);
            } else {
                $response->assertJsonMissingPath('retryable');
            }
        } finally {
            DB::swap($manager);
        }
    }

    public static function bootstrapErrors(): array
    {
        return [
            [2002, 'backend_unavailable'],
            [1045, 'workflow_v2_blocked'],
            [1049, 'workflow_v2_blocked'],
        ];
    }
}
