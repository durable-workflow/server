<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\WorkerProtocol;
use App\Support\WorkflowMetadataCapabilityPolicy;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowExecutor;

trait ServerTestHelpers
{
    protected function createNamespace(string $name, string $description = 'Test namespace'): void
    {
        WorkflowNamespace::query()->updateOrCreate(
            ['name' => $name],
            [
                'description' => $description,
                'retention_days' => 30,
                'status' => 'active',
            ],
        );
    }

    protected function apiHeaders(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ];
    }

    protected function workerHeaders(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ];
    }

    /**
     * Include worker-plane metadata on control-plane requests to lock route
     * precedence for mixed clients and prevent response-envelope drift.
     *
     * @return array<string, string>
     */
    protected function controlPlaneHeadersWithWorkerProtocol(string $namespace = 'default'): array
    {
        return $this->apiHeaders($namespace) + [
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ];
    }

    /**
     * @param  array<string>|null  $supportedWorkflowTypes
     * @param  array<string>|null  $supportedActivityTypes
     */
    protected function registerWorker(
        string $workerId,
        string $taskQueue,
        string $namespace = 'default',
        ?array $supportedWorkflowTypes = null,
        ?array $supportedActivityTypes = null,
        ?array $capabilities = null,
    ): void {
        // Default to declaring the standard test workflow and activity
        // types so feature tests that don't care about capability filtering
        // still receive tasks under the registered-capability-authoritative
        // routing rule. Tests that exercise the no-capability path pass an
        // explicit [] for the relevant array.
        $supportedWorkflowTypes ??= ['tests.external-greeting-workflow'];
        $supportedActivityTypes ??= ['tests.external-greeting-activity'];
        $capabilities ??= [
            WorkflowMetadataCapabilityPolicy::MEMO_UPSERTS,
            WorkflowMetadataCapabilityPolicy::TYPED_SEARCH_ATTRIBUTES,
        ];

        WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => $workerId, 'namespace' => $namespace],
            [
                'task_queue' => $taskQueue,
                'runtime' => 'php',
                'supported_workflow_types' => $supportedWorkflowTypes,
                'supported_activity_types' => $supportedActivityTypes,
                'capabilities' => $capabilities,
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ],
        );
    }

    protected function configureWorkflowTypes(array $types): void
    {
        config()->set('workflows.v2.types.workflows', $types);
    }

    protected function runReadyWorkflowTask(string $runId): void
    {
        $taskId = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->where('status', 'ready')
            ->orderBy('available_at')
            ->value('id');

        $this->assertIsString($taskId);

        $job = new RunWorkflowTask($taskId);
        $job->handle(app(WorkflowExecutor::class));
    }
}
