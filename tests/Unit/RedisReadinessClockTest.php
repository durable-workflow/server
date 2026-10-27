<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RedisReadinessClockTest extends TestCase
{
    public function test_wall_clock_corrections_cannot_shorten_or_extend_the_child_deadline(): void
    {
        $root = dirname(__DIR__, 2);
        $process = new Process([PHP_BINARY, $root.'/tests/Fixtures/redis_readiness_clock_control.php'], $root);
        $process->setTimeout(15);
        $process->mustRun();
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([
            'normal_short', 'normal_long', 'forward_step_short', 'backward_step_long', 'normal_short_again',
        ], array_column($results, 'case'));
        foreach ($results as $index => $result) {
            $short = in_array($index, [0, 2, 4], true);
            $this->assertSame($short ? 'ok' : 'probe_timeout', $result['outcome'], $result['case']);
            $this->assertSame($short, $result['returned_complete'], $result['case']);
            $this->assertLessThan(2.0, $result['elapsed_seconds'], $result['case']);
        }
    }
}
