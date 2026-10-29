<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/scripts/conformance/server-parity/many-updates-probe.php';

final class ServerParityManyUpdatesRetryTest extends TestCase
{
    private const BODY = ['input' => [['v' => 7]], 'request_id' => 'many-7', 'wait_for' => 'accepted'];

    /**
     * Sends with answers in order; returns the answer and every request sent.
     *
     * @param list<int> $statuses
     * @return array{0: array{status: int, response: mixed}, 1: list<array{0: string, 1: string, 2: array<string, mixed>}>, 2: list<int>}
     */
    private function submit(array $statuses): array
    {
        $sent = [];
        $slept = [];
        $raw = function (string $method, string $path, array $body) use (&$sent, &$statuses): array {
            $sent[] = [$method, $path, $body];

            return ['status' => array_shift($statuses), 'response' => ['message' => 'Server Error']];
        };
        $answer = \manyUpdatesSubmit($raw, 'workflows/w/update/set', self::BODY, 7,
            function (int $microseconds) use (&$slept): void {
                $slept[] = $microseconds;
            });

        return [$answer, $sent, $slept];
    }

    public function test_a_500_is_sent_again_with_the_same_request_id(): void
    {
        [$answer, $sent, $slept] = $this->submit([500, 500, 202]);

        self::assertSame(202, $answer['status']);
        self::assertCount(3, $sent);
        foreach ($sent as $request) {
            self::assertSame(['POST', 'workflows/w/update/set', self::BODY], $request);
        }
        self::assertSame([200000, 400000], $slept);
    }

    public function test_other_statuses_return_at_once(): void
    {
        foreach ([202, 409, 422, 503] as $status) {
            [$answer, $sent, $slept] = $this->submit([$status, 202]);
            self::assertSame([$status, 1, []], [$answer['status'], count($sent), $slept], "status {$status}");
        }
    }

    public function test_a_third_500_is_returned(): void
    {
        [$answer, $sent] = $this->submit([500, 500, 500, 202]);

        self::assertSame([500, 3], [$answer['status'], count($sent)]);
    }
}
