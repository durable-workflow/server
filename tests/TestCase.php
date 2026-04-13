<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
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
            'server.worker_protocol.version' => '1.0',
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
