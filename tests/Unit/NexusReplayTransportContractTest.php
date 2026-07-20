<?php

namespace Tests\Unit;

use App\Support\NexusContract;
use PHPUnit\Framework\TestCase;

final class NexusReplayTransportContractTest extends TestCase
{
    public function test_replay_scenario_requires_structured_transport_evidence(): void
    {
        $requirements = NexusContract::manifest()['scenario_evidence_requirements']['worker_restart_replay_does_not_reissue_call'];

        $this->assertContains('replay_transport', $requirements);
    }

    public function test_replay_transport_recovers_from_a_closed_pool_connection(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise Nexus replay transport recovery.');
        }

        $process = proc_open(
            [
                $nodeBinary,
                '--test',
                __DIR__.'/NexusReplayTransportRegression.cjs',
            ],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2),
        );

        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, $stderr ?: $stdout);
    }
}
