<?php

namespace App\Support;

use RuntimeException;

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

    /**
     * @param  list<string>|null  $command
     */
    public function __construct(?array $command = null)
    {
        $this->command = $command;
    }

    public function run(string $input): string
    {
        if (strlen($input) > self::MAX_INPUT_BYTES) {
            throw new RuntimeException('Redis readiness child input exceeds the safety limit.');
        }

        $pipes = [];
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

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $stdout = '';
        $stderr = '';
        $inputOffset = 0;
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
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
            throw new RuntimeException('Redis readiness child exceeded its 1.5 second deadline.');
        }

        if ($exitCode !== 0) {
            // Connector diagnostics may contain the selected URL, credentials,
            // or endpoint. Keep draining and bounding both streams so a failed
            // child cannot block, but never project their contents across the
            // process boundary.
            throw new RuntimeException(self::FAILURE_MESSAGE);
        }

        return $stdout;
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
