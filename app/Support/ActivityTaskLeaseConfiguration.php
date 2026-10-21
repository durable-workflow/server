<?php

namespace App\Support;

use Workflow\V2\Support\ActivityLease;

final class ActivityTaskLeaseConfiguration
{
    public static function apply(): int
    {
        $configured = config('server.lease.activity_task_timeout', 300);
        $seconds = is_int($configured) || is_string($configured)
            ? filter_var($configured, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 86_400],
            ]) : false;
        if ($seconds === false) {
            throw new ActivityTaskLeaseConfigurationException('DW_ACTIVITY_TASK_TIMEOUT must be an integer between 1 and 86400 seconds.');
        }

        config(['server.lease.activity_task_timeout' => $seconds]);
        if (defined(ActivityLease::class.'::CONFIG_KEY') && method_exists(ActivityLease::class, 'seconds')) {
            config([ActivityLease::CONFIG_KEY => $seconds]);
            if (ActivityLease::seconds() !== $seconds) {
                throw new ActivityTaskLeaseConfigurationException('The configured activity-task lease does not match the Workflow runtime.');
            }
        }

        return $seconds;
    }
}
