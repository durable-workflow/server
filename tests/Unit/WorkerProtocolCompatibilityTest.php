<?php

namespace Tests\Unit;

use App\Support\WorkerProtocol;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the protocol-version compatibility window the server enforces on
 * incoming worker registrations. Per workflow:v2's WorkerProtocolVersion
 * contract, MINOR bumps are additive — older workers must still be able
 * to talk to newer servers, otherwise every existing SDK breaks the
 * moment the server upgrades.
 */
class WorkerProtocolCompatibilityTest extends TestCase
{
    /**
     * @return array<string, array{worker: string, server: string, expected: bool}>
     */
    public static function compatibilityCases(): array
    {
        return [
            'exact match accepted' => ['worker' => '1.2', 'server' => '1.2', 'expected' => true],
            'current published PHP worker protocol accepted' => ['worker' => '1.6', 'server' => WorkerProtocol::VERSION, 'expected' => true],
            'one minor behind accepted (additive forward-compat)' => ['worker' => '1.1', 'server' => '1.2', 'expected' => true],
            'two minors behind accepted' => ['worker' => '1.0', 'server' => '1.2', 'expected' => true],
            'minor 0 against minor 0 accepted' => ['worker' => '1.0', 'server' => '1.0', 'expected' => true],
            'worker minor ahead rejected' => ['worker' => '1.3', 'server' => '1.2', 'expected' => false],
            'major ahead rejected' => ['worker' => '2.0', 'server' => '1.2', 'expected' => false],
            'major behind rejected' => ['worker' => '0.9', 'server' => '1.2', 'expected' => false],
            'malformed worker (no dot) rejected' => ['worker' => '999', 'server' => '1.2', 'expected' => false],
            'malformed worker (non-int minor) rejected' => ['worker' => '1.x', 'server' => '1.2', 'expected' => false],
            'empty worker rejected' => ['worker' => '', 'server' => '1.2', 'expected' => false],
            'malformed server falls back to strict equality' => ['worker' => '1.2', 'server' => 'oops', 'expected' => false],
        ];
    }

    /**
     * @param array{worker: string, server: string, expected: bool} $case
     */
    #[DataProvider('compatibilityCases')]
    public function test_is_compatible_protocol_version(string $worker, string $server, bool $expected): void
    {
        $this->assertSame(
            $expected,
            WorkerProtocol::isCompatibleProtocolVersion($worker, $server),
            sprintf('worker=%s server=%s expected=%s', $worker, $server, $expected ? 'compatible' : 'incompatible'),
        );
    }
}
