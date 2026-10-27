<?php

namespace Tests\Unit;

use App\Support\RedisReadinessProbeFailure;
use App\Support\RedisReadinessProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class RedisReadinessDiagnosticTest extends TestCase
{
    #[DataProvider('metadataCases')]
    public function test_failure_metadata_admits_only_bounded_clocks_and_literal_booleans(
        string $entry,
        string $valueMatch,
        bool $hasOffset,
        ?bool $expectedMatch,
    ): void {
        $diagnostic = null;
        $process = new RedisReadinessProcess(
            [PHP_BINARY, base_path('tests/Fixtures/redis_readiness_diagnostic_metadata.php'), $entry, $valueMatch],
            static function (array $details) use (&$diagnostic): void {
                $diagnostic = $details;
            },
        );
        $secret = 'redis://private-user:private-password@private-endpoint/7';
        try {
            $process->run($secret);
            $this->fail('The diagnostic fixture must fail.');
        } catch (RedisReadinessProbeFailure $failure) {
            $this->assertSame(RedisReadinessProbeFailure::CHILD_FAILURE, $failure->reason);
            $this->assertSame(RedisReadinessProcess::FAILURE_MESSAGE, $failure->getMessage());
        }

        $this->assertIsArray($diagnostic);
        $this->assertArrayHasKey('child_entry_offset_ms', $diagnostic);
        if ($hasOffset) {
            $this->assertIsInt($diagnostic['child_entry_offset_ms']);
            $this->assertGreaterThanOrEqual(0, $diagnostic['child_entry_offset_ms']);
            $this->assertLessThanOrEqual($diagnostic['parent_elapsed_ms'], $diagnostic['child_entry_offset_ms']);
        } else {
            $this->assertNull($diagnostic['child_entry_offset_ms']);
        }
        $this->assertSame($expectedMatch, $diagnostic['value_matches_expected']);
        $this->assertSame(['encoded' => 13], $diagnostic['stages_ms']);
        $this->assertSame('encoded', $diagnostic['last_stage']);
        $this->assertStringNotContainsString('private-', json_encode($diagnostic, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('child_entry_ns', $diagnostic);
    }

    public static function metadataCases(): array
    {
        return [
            'matching' => ['valid', '1', true, true],
            'mismatching' => ['valid', '0', true, false],
            'before parent' => ['past', '1', false, true],
            'after observation' => ['future', '0', false, false],
            'future within numeric cap' => ['near_future', '1', false, true],
            'zero' => ['0', '1', false, true],
            'negative' => ['-1', '1', false, true],
            'overflow' => ['9223372036854775808', '1', false, true],
            'overlong' => ['99999999999999999999', '1', false, true],
            'noncanonical' => ['leading_zero', '1', false, true],
            'decimal' => ['1.5', '1', false, true],
            'boolean word' => ['valid', 'true', true, null],
            'boolean padded' => ['valid', '01', true, null],
            'boolean out of range' => ['valid', '2', true, null],
            'boolean negative' => ['valid', '-1', true, null],
            'boolean suffix' => ['valid', '1 private-endpoint', true, null],
        ];
    }

    #[DataProvider('childCases')]
    public function test_actual_child_lifecycle_keeps_diagnostics_opt_in_and_values_private(
        string $mode,
        bool $enabled,
        string $expectedValue,
        string $expectedMatch,
    ): void {
        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/redis_readiness_lifecycle.php'), $mode]);
        $process->setEnv(['DW_REDIS_READINESS_DIAGNOSTICS' => $enabled ? '1' : '0']);
        $process->setInput($this->input());
        $process->mustRun();
        $this->assertSame(['ok' => true, 'value' => $expectedValue], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        $stderr = $process->getErrorOutput();
        if (! $enabled) {
            $this->assertSame('', $stderr);

            return;
        }

        $this->assertMatchesRegularExpression('/^DW_REDIS_READY_ENTRY_NS [0-9]+$/m', $stderr);
        $this->assertStringContainsString('DW_REDIS_READY_VALUE_MATCH '.$expectedMatch."\n", $stderr);
        preg_match_all('/^DW_REDIS_READY_STAGE (\w+) [0-9]+$/m', $stderr, $stages);
        $this->assertSame([
            'entry', 'autoloaded', 'bootstrapped', 'input_decoded', 'connecting', 'connected',
            'setex', 'get', 'del', 'encoded', 'closing', $mode === 'close_failure' ? 'close_failed' : 'closed', 'written',
        ], $stages[1]);
        $this->assertStringNotContainsString('readiness-value', $stderr);
        $this->assertStringNotContainsString('private-', $stderr);
    }

    public static function childCases(): array
    {
        return [
            ['success', true, 'readiness-value', '1'],
            ['mismatch', true, 'different-readiness-value', '0'],
            ['close_failure', true, 'readiness-value', '1'],
            ['success', false, 'readiness-value', '1'],
        ];
    }

    #[DataProvider('stalledCases')]
    public function test_timeout_distinguishes_entry_delay_close_and_result_delivery(string $mode, string $stage, bool $matches): void
    {
        $previous = getenv('DW_REDIS_READINESS_DIAGNOSTICS');
        putenv('DW_REDIS_READINESS_DIAGNOSTICS=1');
        $diagnostic = null;
        $process = new RedisReadinessProcess(
            [PHP_BINARY, base_path('tests/Fixtures/redis_readiness_lifecycle.php'), $mode],
            static function (array $details) use (&$diagnostic): void {
                $diagnostic = $details;
            },
        );
        $started = hrtime(true);
        try {
            $process->run($this->input());
            $this->fail('The lifecycle fixture must time out.');
        } catch (RedisReadinessProbeFailure $failure) {
            $this->assertSame(RedisReadinessProbeFailure::TIMEOUT, $failure->reason);
        } finally {
            putenv($previous === false ? 'DW_REDIS_READINESS_DIAGNOSTICS' : 'DW_REDIS_READINESS_DIAGNOSTICS='.$previous);
        }

        $this->assertLessThan(2.0, (hrtime(true) - $started) / 1_000_000_000);
        $this->assertSame($stage, $diagnostic['last_stage']);
        $this->assertSame($matches, $diagnostic['value_matches_expected']);
        $this->assertIsInt($diagnostic['child_entry_offset_ms']);
        $this->assertArrayHasKey('encoded', $diagnostic['stages_ms']);
        if ($mode === 'late_entry_written_timeout') {
            $this->assertGreaterThanOrEqual(150, $diagnostic['child_entry_offset_ms']);
            $this->assertArrayHasKey('closed', $diagnostic['stages_ms']);
        } else {
            $this->assertArrayNotHasKey('closed', $diagnostic['stages_ms']);
            $this->assertArrayNotHasKey('written', $diagnostic['stages_ms']);
        }
    }

    public static function stalledCases(): array
    {
        return [
            ['late_entry_written_timeout', 'written', true],
            ['closing_timeout', 'closing', true],
            ['mismatch_closing_timeout', 'closing', false],
        ];
    }

    private function input(): string
    {
        return serialize([
            'version' => 1,
            'connection_name' => 'default',
            'configuration' => ['client' => 'phpredis'],
            'key' => 'readiness-fixture',
            'value' => 'readiness-value',
            'ttl_seconds' => 10,
        ]);
    }
}
