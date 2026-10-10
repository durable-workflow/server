<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingActivity;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

class ServiceModeQueueExecutionTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    public function createApplication()
    {
        $savedEnv = $_ENV;
        $savedServer = $_SERVER;
        $_ENV['DW_MODE'] = $_SERVER['DW_MODE'] = 'service';
        $_ENV['DW_TASK_DISPATCH_MODE'] = $_SERVER['DW_TASK_DISPATCH_MODE'] = 'queue';

        try {
            return parent::createApplication();
        } finally {
            $_ENV = $savedEnv;
            $_SERVER = $savedServer;
        }
    }

    public function test_explicit_queue_override_completes_a_local_workflow_and_activity(): void
    {
        $this->assertSame('service', config('server.mode'));
        $this->assertSame('queue', config('workflows.v2.task_dispatch_mode'));
        $this->assertSame('database', config('queue.default'));
        $this->configureWorkflowTypes(['tests.external-greeting-workflow' => ExternalGreetingWorkflow::class]);
        config(['workflows.v2.types.activities' => ['tests.external-greeting-activity' => ExternalGreetingActivity::class]]);
        $this->createNamespace('default');

        $start = $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'local-queue-override',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'default',
            'input' => ['Queue'],
        ])->assertCreated();
        $runId = $start->json('run_id');
        $this->assertGreaterThan(0, DB::table('jobs')->count(), 'Explicit queue dispatch must enqueue the ready workflow task');

        $exitCode = $this->artisan('queue:work', [
            'connection' => 'database',
            '--queue' => 'default,external-activities',
            '--stop-when-empty' => true,
            '--sleep' => 0,
            '--tries' => 1,
            '--max-jobs' => 10,
            // The full suite already occupies more than the worker's 128 MiB
            // default because this worker runs inside the PHPUnit process.
            '--memory' => 512,
        ])->run();
        $this->assertSame(0, $exitCode);

        $this->assertSame(
            RunStatus::Completed,
            WorkflowRun::query()->findOrFail($runId)->status,
            DB::table('workflow_failures')->where('workflow_run_id', $runId)->pluck('message')->implode("\n"),
        );
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $runId)->where('task_type', 'activity')->count());
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $runId)->where('status', '!=', 'completed')->count());
        $this->assertSame([
            'greeting' => 'Hello, Queue!',
            'workflow_id' => 'local-queue-override',
            'run_id' => $runId,
        ], WorkflowRun::query()->findOrFail($runId)->workflowOutput());
    }
}
