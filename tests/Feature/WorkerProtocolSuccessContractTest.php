<?php

namespace Tests\Feature;

use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;

class WorkerProtocolSuccessContractTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        config(['server.polling.timeout' => 0]);

        $this->createNamespace('default');
    }

    /**
     * @return array<string, array{
     *     case: string,
     *     path: string,
     *     body: array<string, mixed>,
     *     status: int,
     *     structure: array<int|string, mixed>,
     *     paths: array<string, mixed>
     * }>
     */
    public static function workerSuccessProvider(): array
    {
        return [
            'worker.register' => [
                'case' => 'worker.register',
                'path' => '/api/worker/register',
                'body' => [
                    'worker_id' => 'worker-success',
                    'task_queue' => 'contract-queue',
                    'runtime' => 'python',
                    'sdk_version' => '1.0.0',
                    'supported_workflow_types' => ['ContractWorkflow'],
                    'supported_activity_types' => ['ContractActivity'],
                ],
                'status' => 201,
                'structure' => ['worker_id', 'registered', 'protocol_version', 'server_capabilities'],
                'paths' => ['worker_id' => 'worker-success', 'registered' => true],
            ],
            'worker.heartbeat' => [
                'case' => 'worker.heartbeat',
                'path' => '/api/worker/heartbeat',
                'body' => ['worker_id' => 'worker-success'],
                'status' => 200,
                'structure' => ['worker_id', 'acknowledged', 'protocol_version', 'server_capabilities'],
                'paths' => ['worker_id' => 'worker-success', 'acknowledged' => true],
            ],
            'workflow-tasks.poll_empty' => [
                'case' => 'workflow-tasks.poll_empty',
                'path' => '/api/worker/workflow-tasks/poll',
                'body' => [
                    'worker_id' => 'worker-success',
                    'task_queue' => 'contract-queue',
                    'poll_request_id' => 'poll-contract-1',
                ],
                'status' => 200,
                'structure' => ['task', 'protocol_version', 'server_capabilities'],
                'paths' => ['task' => null],
            ],
            'activity-tasks.poll_empty' => [
                'case' => 'activity-tasks.poll_empty',
                'path' => '/api/worker/activity-tasks/poll',
                'body' => [
                    'worker_id' => 'worker-success',
                    'task_queue' => 'contract-queue',
                ],
                'status' => 200,
                'structure' => ['task', 'protocol_version', 'server_capabilities'],
                'paths' => ['task' => null],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<int|string, mixed>  $structure
     * @param  array<string, mixed>  $paths
     */
    #[DataProvider('workerSuccessProvider')]
    public function test_worker_success_responses_use_worker_protocol_contract(
        string $case,
        string $path,
        array $body,
        int $status,
        array $structure,
        array $paths,
    ): void {
        $this->prepareWorkerCase($case);

        $response = $this->postJson($path, $body, $this->workerProtocolHeaders());

        $response->assertStatus($status)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonPath('server_capabilities.supported_workflow_task_commands.0', 'complete_workflow')
            ->assertJsonMissingPath('control_plane')
            ->assertJsonStructure($structure);

        foreach ($paths as $jsonPath => $expected) {
            $response->assertJsonPath($jsonPath, $expected);
        }
    }

    private function prepareWorkerCase(string $case): void
    {
        if ($case === 'worker.register') {
            return;
        }

        $this->registerWorker(
            workerId: 'worker-success',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['ContractWorkflow'],
            supportedActivityTypes: ['ContractActivity'],
        );
    }

    /**
     * Include both protocol headers to prove worker-plane routes keep the
     * worker envelope on success when mixed clients send extra metadata.
     *
     * @return array<string, string>
     */
    private function workerProtocolHeaders(): array
    {
        return $this->workerHeaders() + [
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ];
    }
}
