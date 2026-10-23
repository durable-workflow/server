<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\BundleIntegrityVerifier;
use Workflow\V2\Support\MemoPayload;

class HistoryExportNumericKindsTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    public static function runStates(): iterable
    {
        yield 'waiting' => [false];
        yield 'completed' => [true];
    }

    #[DataProvider('runStates')]
    public function test_http_export_preserves_numeric_kinds(bool $complete): void
    {
        Queue::fake();
        $this->createNamespace('default');
        $this->createNamespace('other');
        config(['workflows.v2.types.workflows' => [
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]]);
        $memo = [
            'nested' => ['list' => [3.0, -0.0, 1.5, 4], 'signed_zero' => -0.0, 'whole' => 2.0],
            'signed_zero' => -0.0,
            'whole' => 1.0,
        ];
        $id = 'numeric-export-'.Str::ulid();
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => $id,
            'workflow_type' => 'tests.external-greeting-workflow',
            'input' => ['Ada'],
            'memo' => $memo,
            'run_timeout_seconds' => 30,
            'execution_timeout_seconds' => 30,
        ], $this->apiHeaders(), JSON_PRESERVE_ZERO_FRACTION);
        $start->assertCreated();
        $runId = (string) $start->json('run_id');
        $this->runReadyWorkflowTask($runId);
        if ($complete) {
            $task = WorkflowTask::query()->where('workflow_run_id', $runId)
                ->where('status', TaskStatus::Ready->value)
                ->where('task_type', TaskType::Activity->value)->sole();
            $this->app->call([new RunActivityTask($task->id), 'handle']);
            $this->runReadyWorkflowTask($runId);
        }

        $route = "/api/workflows/{$id}/runs/{$runId}/history/export";
        $response = $this->withHeaders($this->apiHeaders())->getJson($route);
        $response->assertOk()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonMissingPath('control_plane')
            ->assertJsonPath('workflow.status', $complete ? 'completed' : 'waiting');
        $raw = $response->getContent();
        $bundle = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $projection = $bundle['workflow']['memo'];
        $this->assertSame($memo, $projection);
        $this->assertSame(-INF, fdiv(1.0, $projection['signed_zero']));
        $this->assertSame(-INF, fdiv(1.0, $projection['nested']['signed_zero']));
        $this->assertSame(-INF, fdiv(1.0, $projection['nested']['list'][1]));
        $this->assertSame($memo, MemoPayload::decodeEntries($bundle['workflow']['memo_payload']));
        $report = BundleIntegrityVerifier::verifyJson($raw);
        $this->assertSame(BundleIntegrityVerifier::STATUS_OK, $report['status'], json_encode($report, JSON_THROW_ON_ERROR));

        $path = sys_get_temp_dir().'/server-numeric-export-'.Str::ulid().'.json';
        file_put_contents($path, $raw);
        try {
            $hash = hash_file('sha256', $path);
            $this->artisan('workflow:v2:replay-verify', [
                'bundle' => $path, '--skip-replay' => true, '--json' => true,
            ])->assertSuccessful();
            $this->assertSame($hash, hash_file('sha256', $path));
        } finally {
            unlink($path);
        }
        $lossy = BundleIntegrityVerifier::verifyJson(json_encode($bundle, JSON_THROW_ON_ERROR));
        $this->assertSame(BundleIntegrityVerifier::STATUS_FAILED, $lossy['status']);
        $this->assertContains('workflow.memo_projection_mismatch', array_column($lossy['findings'], 'rule'));
        $this->assertContains('integrity.checksum_mismatch', array_column($lossy['findings'], 'rule'));

        $this->withHeaders($this->apiHeaders('other'))->getJson($route)->assertNotFound();
    }
}
