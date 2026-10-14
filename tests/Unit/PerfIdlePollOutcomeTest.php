<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/scripts/perf/idle-poll-outcome.php';

final class PerfIdlePollOutcomeTest extends TestCase
{
    public function test_explicit_empty_poll_statuses_and_malformed_replies(): void
    {
        foreach (['empty', 'timeout'] as $status) {
            self::assertSame('empty', \idlePollOutcome(['task' => null, 'poll_status' => $status]));
        }

        foreach ([
            [],
            ['task' => null],
            ['poll_status' => 'empty'],
            ['task' => null, 'poll_status' => 'unknown'],
            ['task' => 'invalid', 'poll_status' => 'empty'],
        ] as $response) {
            self::assertSame('unexpected', \idlePollOutcome($response));
        }

        self::assertSame('task', \idlePollOutcome(['task' => ['id' => 'actual-task'], 'poll_status' => 'leased']));
    }
}
