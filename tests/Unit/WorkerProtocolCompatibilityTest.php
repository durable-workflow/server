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
            'current published PHP worker protocol accepted' => ['worker' => WorkerProtocol::VERSION, 'server' => WorkerProtocol::VERSION, 'expected' => true],
            'released Rust worker protocol accepted by current server' => ['worker' => '1.16', 'server' => WorkerProtocol::VERSION, 'expected' => true],
            'previous published PHP worker protocol accepted' => ['worker' => '1.14', 'server' => WorkerProtocol::VERSION, 'expected' => true],
            'previous minor accepted' => ['worker' => '1.11', 'server' => WorkerProtocol::VERSION, 'expected' => true],
            'one minor behind accepted (additive forward-compat)' => ['worker' => '1.1', 'server' => '1.2', 'expected' => true],
            'two minors behind accepted' => ['worker' => '1.0', 'server' => '1.2', 'expected' => true],
            'minor 0 against minor 0 accepted' => ['worker' => '1.0', 'server' => '1.0', 'expected' => true],
            'worker minor ahead rejected' => ['worker' => '1.3', 'server' => '1.2', 'expected' => false],
            'worker ahead of current server rejected' => ['worker' => self::nextMinor(WorkerProtocol::VERSION), 'server' => WorkerProtocol::VERSION, 'expected' => false],
            'major ahead rejected' => ['worker' => '2.0', 'server' => '1.2', 'expected' => false],
            'different major rejected by current server' => ['worker' => '2.0', 'server' => WorkerProtocol::VERSION, 'expected' => false],
            'major behind rejected' => ['worker' => '0.9', 'server' => '1.2', 'expected' => false],
            'malformed worker (no dot) rejected' => ['worker' => '999', 'server' => '1.2', 'expected' => false],
            'malformed worker rejected by current server' => ['worker' => '1.x', 'server' => WorkerProtocol::VERSION, 'expected' => false],
            'malformed worker (non-int minor) rejected' => ['worker' => '1.x', 'server' => '1.2', 'expected' => false],
            'empty worker rejected' => ['worker' => '', 'server' => '1.2', 'expected' => false],
            'malformed server falls back to strict equality' => ['worker' => '1.2', 'server' => 'oops', 'expected' => false],
        ];
    }

    /**
     * @param  array{worker: string, server: string, expected: bool}  $case
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

    private static function nextMinor(string $version): string
    {
        [$major, $minor] = array_map('intval', explode('.', $version, 2));

        return sprintf('%d.%d', $major, $minor + 1);
    }

    public function test_message_streams_require_the_feature_minor_on_the_same_protocol_major(): void
    {
        $this->assertFalse(WorkerProtocol::messageStreamsSupported('1.14'));
        $this->assertTrue(WorkerProtocol::messageStreamsSupported('1.15'));
        $this->assertTrue(WorkerProtocol::messageStreamsSupported('1.16'));
        $this->assertFalse(WorkerProtocol::messageStreamsSupported('2.0'));
        $this->assertFalse(WorkerProtocol::messageStreamsSupported('invalid'));
    }

    public function test_workflow_memo_updates_require_protocol_1_14_or_newer_on_the_same_major(): void
    {
        $this->assertFalse(WorkerProtocol::workflowMemoUpdatesSupported('1.13'));
        $this->assertTrue(WorkerProtocol::workflowMemoUpdatesSupported('1.14'));
        $this->assertTrue(WorkerProtocol::workflowMemoUpdatesSupported('1.15'));
        $this->assertFalse(WorkerProtocol::workflowMemoUpdatesSupported('2.0'));
        $this->assertFalse(WorkerProtocol::workflowMemoUpdatesSupported('invalid'));
    }
}
