<?php

namespace Tests\Unit;

use App\Support\ActivityTaskLeaseConfiguration;
use App\Support\ActivityTaskLeaseConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\Support\ActivityLease;

class ActivityTaskLeaseConfigurationTest extends TestCase
{
    public function test_configured_server_lease_reaches_the_runtime_authority(): void
    {
        config(['server.lease.activity_task_timeout' => '17']);
        $this->assertSame(17, ActivityTaskLeaseConfiguration::apply());
        $this->assertSame(17, config('server.lease.activity_task_timeout'));
        if (method_exists(ActivityLease::class, 'seconds')) {
            $this->assertSame(17, ActivityLease::seconds());
        }
    }

    public static function invalidLeases(): array
    {
        return [[0], [-1], [86_401], ['invalid'], ['1.5'], [1.5], [true], [null]];
    }

    #[DataProvider('invalidLeases')]
    public function test_invalid_lease_is_refused_instead_of_silently_replaced(mixed $value): void
    {
        config(['server.lease.activity_task_timeout' => $value]);
        $this->expectException(ActivityTaskLeaseConfigurationException::class);
        $this->expectExceptionMessage('DW_ACTIVITY_TASK_TIMEOUT');
        ActivityTaskLeaseConfiguration::apply();
    }
}
