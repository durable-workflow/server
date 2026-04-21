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

    public function test_database_config_casts_phpredis_ports_before_cache_warmup(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/config/database.php');
        $this->assertNotFalse($source);

        $this->assertStringContainsString("'port' => (int) env('REDIS_PORT', 6379)", $source);
    }
}
