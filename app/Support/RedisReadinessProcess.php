<?php

namespace App\Support;

use Closure;
use RuntimeException;
use Throwable;

final class RedisReadinessProcess
{
    /** Safe for readiness responses and logs; child diagnostics are untrusted. */
    public const FAILURE_MESSAGE = 'Redis readiness transport check failed.';

    /** Leaves 500 ms of the public two-second response budget for other checks. */
    private const TIMEOUT_SECONDS = 1.5;

    private const MAX_INPUT_BYTES = 65_536;

    private const MAX_OUTPUT_BYTES = 16_384;

    /** @var list<string>|null */
    private readonly ?array $command;

    /** @var Closure(array<string, int|string|null>): void|null */
    private readonly ?Closure $diagnosticSink;

    /**
     * @param  list<string>|null  $command
     */
    public function __construct(?array $command = null, ?Closure $diagnosticSink = null)
    {
        $this->command = $command;
        $this->diagnosticSink = $diagnosticSink;
    }

    public function run(string $input): string
    {
        if (strlen($input) > self::MAX_INPUT_BYTES) {
            throw new RuntimeException('Redis readiness child input exceeds the safety limit.');
        }

        $pipes = [];
        $spawnStartedAt = hrtime(true);
        $process = proc_open(
            $this->command ?? [$this->phpCliBinary(), base_path('bin/redis-readiness-probe.php')],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            base_path(),
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start Redis readiness child process.');
        }
        $spawnElapsedMilliseconds = intdiv(hrtime(true) - $spawnStartedAt, 1_000_000);

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $stdout = '';
        $stderr = '';
        $inputOffset = 0;
        $runStartedAt = microtime(true);
        $deadline = $runStartedAt + self::TIMEOUT_SECONDS;
        $timedOut = false;
        $exitCode = null;

        try {
            while (true) {
                $status = proc_get_status($process);

                if (! ($status['running'] ?? false)) {
                    $exitCode = is_int($status['exitcode'] ?? null) ? $status['exitcode'] : null;
                    $this->drain($pipes[1], $stdout);
                    $this->drain($pipes[2], $stderr);

                    break;
                }

                if (microtime(true) >= $deadline) {
                    $timedOut = true;
                    proc_terminate($process);
                    usleep(10_000);

                    if ((proc_get_status($process)['running'] ?? false) === true) {
                        proc_terminate($process, 9);
                    }

                    $this->drain($pipes[2], $stderr);

                    break;
                }

                $read = array_values(array_filter(
                    [$pipes[1] ?? null, $pipes[2] ?? null],
                    static fn (mixed $pipe): bool => is_resource($pipe),
                ));
                $write = $inputOffset < strlen($input) && is_resource($pipes[0] ?? null)
                    ? [$pipes[0]]
                    : [];
                $except = null;
                $seconds = 0;
                $microseconds = 20_000;

                if ($read !== [] || $write !== []) {
                    @stream_select($read, $write, $except, $seconds, $microseconds);
                } else {
                    usleep($microseconds);
                }

                foreach ($read as $pipe) {
                    $chunk = fread($pipe, 8192);

                    if (! is_string($chunk) || $chunk === '') {
                        continue;
                    }

                    if ($pipe === $pipes[1]) {
                        $this->appendBounded($stdout, $chunk);
                    } else {
                        $this->appendBounded($stderr, $chunk);
                    }
                }

                if ($write !== []) {
                    $written = @fwrite($pipes[0], substr($input, $inputOffset, 8192));

                    if (is_int($written) && $written > 0) {
                        $inputOffset += $written;
                    }
                }

                if ($inputOffset >= strlen($input) && is_resource($pipes[0] ?? null)) {
                    fclose($pipes[0]);
                    unset($pipes[0]);
                }
            }
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            $closedExitCode = proc_close($process);

            if ($exitCode === null && $closedExitCode >= 0) {
                $exitCode = $closedExitCode;
            }
        }

        if ($timedOut) {
            $this->recordFailureDiagnostic(
                RedisReadinessProbeFailure::TIMEOUT,
                $stderr,
                $runStartedAt,
                $spawnElapsedMilliseconds,
            );
            throw new RedisReadinessProbeFailure(
                RedisReadinessProbeFailure::TIMEOUT,
                'Redis readiness child exceeded its 1.5 second deadline.',
            );
        }

        if ($exitCode !== 0) {
            // Connector diagnostics may contain the selected URL, credentials,
            // or endpoint. Keep draining and bounding both streams so a failed
            // child cannot block, but never project their contents across the
            // process boundary.
            $this->recordFailureDiagnostic(
                RedisReadinessProbeFailure::CHILD_FAILURE,
                $stderr,
                $runStartedAt,
                $spawnElapsedMilliseconds,
            );
            throw new RedisReadinessProbeFailure(
                RedisReadinessProbeFailure::CHILD_FAILURE,
                self::FAILURE_MESSAGE,
            );
        }

        return $stdout;
    }

    private function recordFailureDiagnostic(
        string $reason,
        string $stderr,
        float $runStartedAt,
        int $spawnElapsedMilliseconds,
    ): void {
        if ($this->diagnosticSink === null && getenv('DW_REDIS_READINESS_DIAGNOSTICS') !== '1') {
            return;
        }

        // The child stream is untrusted. Admit only fixed stage names and a
        // bounded number; never log its raw output or selected Redis settings.
        preg_match_all(
            '/^DW_REDIS_READY_STAGE (entry|autoloaded|bootstrapped|input_decoded|connecting|connected|setex|get|del) ([0-9]{1,5})\r?$/m',
            $stderr,
            $matches,
            PREG_SET_ORDER,
        );
        $lastStage = 'not_reported';
        $stageElapsedMilliseconds = null;
        foreach ($matches as $match) {
            $elapsed = (int) $match[2];
            if ($elapsed > 10_000) {
                continue;
            }
            $lastStage = $match[1];
            $stageElapsedMilliseconds = $elapsed;
        }

        $details = [
            'reason' => $reason,
            'last_stage' => $lastStage,
            'child_stage_elapsed_ms' => $stageElapsedMilliseconds,
            'parent_elapsed_ms' => (int) round((microtime(true) - $runStartedAt) * 1000),
            'spawn_elapsed_ms' => $spawnElapsedMilliseconds,
        ];
        try {
            if ($this->diagnosticSink !== null) {
                ($this->diagnosticSink)($details);
            } else {
                @error_log('DW redis readiness diagnostic '.json_encode($details));
            }
        } catch (Throwable) {
            // Diagnostics must not change the bounded public readiness result.
        }
    }

    private function phpCliBinary(): string
    {
        // PHP_BINARY can name php-fpm in an HTTP process. PHP_BINDIR contains
        // the companion CLI executable installed in the server image.
        $binary = PHP_BINDIR.DIRECTORY_SEPARATOR.'php';

        if (! is_executable($binary)) {
            throw new RuntimeException('The PHP CLI executable is unavailable for Redis readiness.');
        }

        return $binary;
    }

    /** @param resource $pipe */
    private function drain($pipe, string &$output): void
    {
        while (is_resource($pipe) && ! feof($pipe)) {
            $chunk = fread($pipe, 8192);

            if (! is_string($chunk) || $chunk === '') {
                break;
            }

            $this->appendBounded($output, $chunk);
        }
    }

    private function appendBounded(string &$output, string $chunk): void
    {
        $remaining = self::MAX_OUTPUT_BYTES - strlen($output);

        if ($remaining > 0) {
            $output .= substr($chunk, 0, $remaining);
        }
    }
}
