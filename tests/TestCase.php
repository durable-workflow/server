<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'server.auth.driver' => 'none',
            'server.polling.timeout' => 0,
            'server.polling.interval_ms' => 1,
            'server.worker_protocol.version' => '1',
        ]);
    }
}
