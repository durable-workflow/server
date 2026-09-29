<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Server251ValidationBenchmark extends TestCase
{
    use RefreshDatabase;

    public function test_batch(): void
    {
        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Benchmark namespace', 'retention_days' => 30, 'status' => 'active'],
        );

        $count = (int) getenv('QUALIFICATION_COMMAND_COUNT');
        $shape = getenv('QUALIFICATION_COMMAND_SHAPE') ?: 'flat';
        $commands = [];
        for ($index = 0; $index < $count; $index++) {
            $command = ['type' => 'record_side_effect', 'result' => $index];
            if ($shape === 'nested' && $index % 10 === 0) {
                $command['workflow_stream'] = [
                    'operation' => 'append',
                    'stream_name' => 'benchmark-stream',
                    'command_identity' => 'benchmark-'.$index,
                    'command_ordinal' => 0,
                    'items' => [['payload' => ['index' => $index]]],
                ];
            }
            $commands[] = $command;
        }

        $started = hrtime(true);
        $response = $this->withHeaders([
            'X-Namespace' => 'default',
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
            'Authorization' => 'Bearer worker-token',
        ])->postJson('/api/worker/workflow-tasks/missing-task/complete', [
            'lease_owner' => 'benchmark-worker',
            'workflow_task_attempt' => 1,
            'commands' => $commands,
        ]);
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;
        fwrite(STDERR, json_encode([
            'count' => $count,
            'shape' => $shape,
            'elapsed_seconds' => round($elapsed, 4),
            'peak_php_allocated_bytes' => memory_get_peak_usage(true),
            'status' => $response->status(),
            'reason' => $response->json('reason'),
        ], JSON_THROW_ON_ERROR)."\n");

        $response->assertNotFound()->assertJsonPath('reason', 'task_not_found');
    }
}
