<?php

declare(strict_types=1);

namespace App\Support {
    function microtime(bool $asFloat = false): string|float
    {
        if (! $asFloat || ! isset($GLOBALS['redis_readiness_clock_control'])) {
            return \microtime($asFloat);
        }

        $state = &$GLOBALS['redis_readiness_clock_control'];
        $now = \hrtime(true);
        $state['first_tick_ns'] ??= $now;
        $shift = $now - $state['first_tick_ns'] >= 20_000_000 ? $state['shift_seconds'] : 0.0;

        return \microtime(true) + $shift;
    }
}

namespace {
    use App\Support\RedisReadinessProbeFailure;
    use App\Support\RedisReadinessProcess;

    // Isolate the namespaced clock fault from PHPUnit and the real host clock.
    if (($argv[1] ?? '') === '--child') {
        stream_get_contents(STDIN);
        usleep((int) $argv[2]);
        echo 'complete';
        exit(0);
    }

    require dirname(__DIR__, 2).'/vendor/autoload.php';
    require dirname(__DIR__, 2).'/bootstrap/app.php';

    $results = [];
    foreach ([
        ['normal_short', 200_000, 0.0],
        ['normal_long', 2_200_000, 0.0],
        ['forward_step_short', 200_000, 2.0],
        ['backward_step_long', 2_200_000, -2.0],
        ['normal_short_again', 200_000, 0.0],
    ] as [$name, $delay, $shift]) {
        $GLOBALS['redis_readiness_clock_control'] = ['shift_seconds' => $shift, 'first_tick_ns' => null];
        $process = new RedisReadinessProcess([PHP_BINARY, __FILE__, '--child', (string) $delay]);
        $started = hrtime(true);
        $outcome = 'ok';
        $output = null;
        try {
            $output = $process->run('fixture-input');
        } catch (RedisReadinessProbeFailure $failure) {
            $outcome = $failure->reason;
        }
        $results[] = [
            'case' => $name,
            'outcome' => $outcome,
            'returned_complete' => $output === 'complete',
            'elapsed_seconds' => (hrtime(true) - $started) / 1_000_000_000,
        ];
        unset($GLOBALS['redis_readiness_clock_control']);
    }

    echo json_encode($results, JSON_THROW_ON_ERROR);
}
