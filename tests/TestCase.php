<?php

namespace Tests;

use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'server.auth.driver' => 'none',
            'server.polling.timeout' => 0,
            'server.polling.interval_ms' => 1,
            'server.worker_protocol.version' => WorkerProtocol::VERSION,
            'server.polling.cache_path' => $this->pollingCachePath(),
        ]);

        // Ensure package models created via WorkflowStub (used in tests)
        // get a default namespace, matching production behavior where
        // all workflows start through the HTTP API control plane which
        // always sets namespace.
        WorkflowInstance::creating(static function ($model): void {
            if ($model->namespace === null) {
                $model->namespace = 'default';
            }
        });

        WorkflowRun::creating(static function ($model): void {
            if ($model->namespace === null) {
                $model->namespace = 'default';
            }
        });

        WorkflowTask::creating(static function ($model): void {
            if ($model->namespace === null) {
                $model->namespace = 'default';
            }
        });

        WorkflowRunSummary::creating(static function ($model): void {
            if ($model->namespace === null) {
                $model->namespace = 'default';
            }
        });
    }

    protected function tearDown(): void
    {
        $this->cleanPollingCache();

        parent::tearDown();
    }

    /**
     * @return array<string, array{supported: bool, minimum_protocol_version: string, reason: string}>
     */
    protected function portableWorkerAffinityRefusalManifest(): array
    {
        return array_fill_keys(WorkerProtocol::PORTABLE_WORKER_AFFINITY_CAPABILITIES, [
            'supported' => false,
            'minimum_protocol_version' => WorkerProtocol::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION,
            'reason' => 'not_implemented',
        ]);
    }

    protected function assertRecoverableHostingClaim(array $before, string $taskId): void
    {
        $claim = WorkflowTask::query()->findOrFail($taskId);
        $after = $claim->getRawOriginal();
        $this->assertTrue($claim->lease_expires_at->gt(now()));
        $this->assertTrue($claim->lease_expires_at->lte(now()->addSeconds(10)));
        $this->assertTrue($claim->lease_expires_at->lt(Carbon::parse($before['lease_expires_at'], 'UTC')));
        unset($before['lease_expires_at'], $before['updated_at'], $after['lease_expires_at'], $after['updated_at']);
        $this->assertSame($before, $after);
    }

    private function pollingCachePath(): string
    {
        return sys_get_temp_dir().'/dw-server-test-polling-'.getmypid();
    }

    private function cleanPollingCache(): void
    {
        $path = $this->pollingCachePath();

        if (is_dir($path)) {
            $this->removeDirectoryRecursive($path);
        }
    }

    private function removeDirectoryRecursive(string $directory): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }

        @rmdir($directory);
    }
}
