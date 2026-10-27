<?php

declare(strict_types=1);

namespace Illuminate\Redis {
    // Exercise the real child lifecycle without a network service or credentials.
    final class RedisManager
    {
        public function __construct(mixed ...$configuration) {}

        public function connection(string $name): object
        {
            return new class
            {
                public function client(): object
                {
                    return new \RedisReadinessLifecycleClient;
                }
            };
        }
    }
}

namespace {
    final class RedisReadinessLifecycleClient
    {
        private string $value = '';

        public function setex(string $key, int $ttl, string $value): void
        {
            $this->value = $value;
        }

        public function get(string $key): string
        {
            return str_starts_with($GLOBALS['readiness_fixture_mode'], 'mismatch')
                ? 'different-readiness-value'
                : $this->value;
        }

        public function del(string $key): void {}

        public function close(): void
        {
            if (str_ends_with($GLOBALS['readiness_fixture_mode'], 'closing_timeout')) {
                usleep(3_000_000);
            }
            if ($GLOBALS['readiness_fixture_mode'] === 'close_failure') {
                throw new RuntimeException('redis://private-user:private-password@private-endpoint/7');
            }
        }
    }

    $GLOBALS['readiness_fixture_mode'] = $argv[1];
    if ($argv[1] === 'late_entry_written_timeout') {
        usleep(200_000);
        register_shutdown_function(static fn () => usleep(3_000_000));
    }
    require dirname(__DIR__, 2).'/bin/redis-readiness-probe.php';
}
