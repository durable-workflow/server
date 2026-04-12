<?php

namespace App\Support;

class LongPoller
{
    public function __construct(
        private readonly LongPollSignalStore $signals,
    ) {}

    /**
     * Re-run the probe until the result satisfies the ready check or timeout expires.
     *
     * @param  list<string>  $wakeChannels
     */
    public function until(
        callable $probe,
        callable $ready,
        ?int $timeoutSeconds = null,
        ?int $intervalMilliseconds = null,
        array $wakeChannels = [],
        ?callable $nextProbeAt = null,
    ): mixed {
        $timeoutSeconds ??= max(0, (int) config('server.polling.timeout', 30));
        $intervalMilliseconds ??= max(1, (int) config('server.polling.interval_ms', 1000));
        $signalCheckIntervalMilliseconds = max(
            1,
            min(
                $intervalMilliseconds,
                (int) config('server.polling.signal_check_interval_ms', 100),
            ),
        );

        $wakeSnapshot = $this->signals->snapshot($wakeChannels);
        $value = $probe();

        if ($ready($value)) {
            return $value;
        }

        if ($wakeChannels !== [] && $this->signals->changed($wakeSnapshot)) {
            $wakeSnapshot = $this->signals->snapshot($wakeChannels);
            $value = $probe();

            if ($ready($value)) {
                return $value;
            }
        }

        if ($timeoutSeconds === 0) {
            return $value;
        }

        $deadline = microtime(true) + $timeoutSeconds;
        $nextForcedProbeAt = $this->nextProbeDeadline(
            $nextProbeAt,
            microtime(true) + ($intervalMilliseconds / 1000),
            $deadline,
        );

        while (microtime(true) < $deadline) {
            $now = microtime(true);
            $sleepUntil = min(
                $deadline,
                $nextForcedProbeAt,
                $now + ($signalCheckIntervalMilliseconds / 1000),
            );
            $sleepMilliseconds = max(1, (int) ceil(max(0, $sleepUntil - $now) * 1000));

            $this->pause($sleepMilliseconds);

            $now = microtime(true);
            $wakeChanged = $wakeChannels !== [] && $this->signals->changed($wakeSnapshot);

            if (! $wakeChanged && $now < $nextForcedProbeAt) {
                continue;
            }

            if ($wakeChanged) {
                $wakeSnapshot = $this->signals->snapshot($wakeChannels);
            }

            $nextForcedProbeAt = $this->nextProbeDeadline(
                $nextProbeAt,
                $now + ($intervalMilliseconds / 1000),
                $deadline,
            );

            $value = $probe();

            if ($ready($value)) {
                return $value;
            }
        }

        return $value;
    }

    protected function pause(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }

    private function nextProbeDeadline(?callable $nextProbeAt, float $fallback, float $deadline): float
    {
        $hint = $this->hintTimestamp($nextProbeAt);

        if ($hint === null) {
            return min($fallback, $deadline);
        }

        return min(max(0.0, $hint), $fallback, $deadline);
    }

    private function hintTimestamp(?callable $nextProbeAt): ?float
    {
        if (! is_callable($nextProbeAt)) {
            return null;
        }

        $hint = $nextProbeAt();

        if ($hint instanceof \DateTimeInterface) {
            return (float) $hint->format('U.u');
        }

        if (is_int($hint) || is_float($hint)) {
            return (float) $hint;
        }

        if (! is_string($hint) || trim($hint) === '') {
            return null;
        }

        try {
            return (float) (new \DateTimeImmutable($hint))->format('U.u');
        } catch (\Throwable) {
            return null;
        }
    }
}
