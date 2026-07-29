<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use App\Support\WorkflowTaskLeaseConfiguration;
use RuntimeException;
use Tests\TestCase;
use Workflow\V2\Support\WorkflowTaskLease;

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
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);

        $this->rebootAppServiceProvider();

        $this->assertSame('queue', config('workflows.v2.task_dispatch_mode'));
    }

    public function test_service_mode_preserves_explicit_poll_override(): void
    {
        config([
            'server.mode' => 'service',
            'server.task_dispatch_mode_override' => 'poll',
            'workflows.v2.task_dispatch_mode' => 'queue',
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

    public function test_dw_task_dispatch_mode_alias_reaches_the_workflow_package_authority(): void
    {
        putenv('DW_TASK_DISPATCH_MODE=poll');
        $_ENV['DW_TASK_DISPATCH_MODE'] = 'poll';

        try {
            $serverConfig = require __DIR__.'/../../config/server.php';
            config([
                'server.mode' => 'service',
                'server.task_dispatch_mode_override' => $serverConfig['task_dispatch_mode_override'],
                'workflows.v2.task_dispatch_mode' => 'queue',
            ]);

            $this->rebootAppServiceProvider();

            $this->assertSame('poll', config('workflows.v2.task_dispatch_mode'));
        } finally {
            putenv('DW_TASK_DISPATCH_MODE');
            unset($_ENV['DW_TASK_DISPATCH_MODE']);
        }
    }

    public function test_task_dispatch_mode_override_config_is_null_when_env_unset(): void
    {
        putenv('WORKFLOW_V2_TASK_DISPATCH_MODE');
        unset($_ENV['WORKFLOW_V2_TASK_DISPATCH_MODE']);

        $config = require __DIR__.'/../../config/server.php';

        $this->assertNull($config['task_dispatch_mode_override']);
    }

    public function test_cached_standalone_lease_config_maps_to_the_package_authority(): void
    {
        config(['server.lease.workflow_task_timeout' => 8]);

        $this->assertSame(8, WorkflowTaskLeaseConfiguration::apply());
        $this->assertSame(8, config(WorkflowTaskLease::CONFIG_KEY));
        $this->assertSame(8, WorkflowTaskLease::seconds());
    }

    public function test_unusable_standalone_lease_config_fails_closed(): void
    {
        config(['server.lease.workflow_task_timeout' => 0]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not resolve to the Workflow package lease');

        WorkflowTaskLeaseConfiguration::apply();
    }

    public function test_workflow_task_timeout_config_reflects_the_dw_environment_at_load_time(): void
    {
        putenv('DW_WORKFLOW_TASK_TIMEOUT=8');
        $_ENV['DW_WORKFLOW_TASK_TIMEOUT'] = '8';

        try {
            $config = require __DIR__.'/../../config/server.php';

            $this->assertSame(8, $config['lease']['workflow_task_timeout']);
        } finally {
            putenv('DW_WORKFLOW_TASK_TIMEOUT');
            unset($_ENV['DW_WORKFLOW_TASK_TIMEOUT']);
        }
    }

    private function rebootAppServiceProvider(): void
    {
        (new AppServiceProvider($this->app))->boot();
    }
}
