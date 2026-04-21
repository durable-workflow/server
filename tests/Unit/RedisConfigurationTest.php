<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

class RedisConfigurationTest extends TestCase
{
    public function test_phpredis_ports_are_normalized_to_integers(): void
    {
        $this->assertIsInt(config('database.redis.default.port'));
        $this->assertIsInt(config('database.redis.cache.port'));
    }
}
