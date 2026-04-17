<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

/**
 * Regression coverage for TD-S042.
 *
 * AppServiceProvider defaults workflows.v2.task_dispatch_mode to "poll" when
 * the server runs in service mode and no operator override exists. The check
 * must read the override from cached config, not env(): once
 * `php artisan config:cache` bakes the config in, Laravel stops loading .env
 * at runtime and env() returns null for anything not promoted into $_ENV.
 * Reading env() at boot time would therefore silently overwrite an operator's
 * WORKFLOW_V2_TASK_DISPATCH_MODE=queue choice that came from a .env file.
 */
class ServiceModeTaskDispatchDefaultTest extends TestCase
{
    public function test_service_mode_defaults_to_poll_when_no_override_is_set(): void
    {
        config([
            'server.mode' => 'service',
            'server.task_dispatch_mode_override' => null,
            'workflows.v2.task_dispatch_mode' => 'queue',
        ]);

        $this->rebootAppServiceProvider();

        $this->assertSame('poll', config('workflows.v2.task_dispatch_mode'));
    }

    public function test_service_mode_preserves_explicit_queue_override(): void
    {
        config([
            'server.mode' => 'service',
            'server.task_dispatch_mode_override' => 'queue',
            'workflows.v2.task_dispatch_mode' => 'queue',
        ]);

        $this->rebootAppServiceProvider();

        $this->assertSame('queue', config('workflows.v2.task_dispatch_mode'));
    }

    public function test_service_mode_preserves_explicit_poll_override(): void
    {
        config([
            'server.mode' => 'service',
            'server.task_dispatch_mode_override' => 'poll',
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);

        $this->rebootAppServiceProvider();

        $this->assertSame('poll', config('workflows.v2.task_dispatch_mode'));
    }

    public function test_embedded_mode_does_not_apply_service_default(): void
    {
        config([
            'server.mode' => 'embedded',
            'server.task_dispatch_mode_override' => null,
            'workflows.v2.task_dispatch_mode' => 'queue',
        ]);

        $this->rebootAppServiceProvider();

        $this->assertSame('queue', config('workflows.v2.task_dispatch_mode'));
    }

    public function test_task_dispatch_mode_override_config_reflects_env_at_load_time(): void
    {
        // Simulates the path taken when `php artisan config:cache` runs with
        // WORKFLOW_V2_TASK_DISPATCH_MODE=queue in .env. The loader evaluates
        // env() once and bakes the result into the cached config array.
        putenv('WORKFLOW_V2_TASK_DISPATCH_MODE=queue');
        $_ENV['WORKFLOW_V2_TASK_DISPATCH_MODE'] = 'queue';

        try {
            $config = require __DIR__.'/../../config/server.php';

            $this->assertSame('queue', $config['task_dispatch_mode_override']);
        } finally {
            putenv('WORKFLOW_V2_TASK_DISPATCH_MODE');
            unset($_ENV['WORKFLOW_V2_TASK_DISPATCH_MODE']);
        }
    }

    public function test_task_dispatch_mode_override_config_is_null_when_env_unset(): void
    {
        putenv('WORKFLOW_V2_TASK_DISPATCH_MODE');
        unset($_ENV['WORKFLOW_V2_TASK_DISPATCH_MODE']);

        $config = require __DIR__.'/../../config/server.php';

        $this->assertNull($config['task_dispatch_mode_override']);
    }

    private function rebootAppServiceProvider(): void
    {
        (new AppServiceProvider($this->app))->boot();
    }
}
