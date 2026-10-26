<?php

namespace Tests\Feature;

use App\Support\UpdateWaitConfiguration;
use Tests\TestCase;
use Workflow\V2\Support\UpdateWaitPolicy;

// Server #775: the operator's default completion wait is bounded by the same
// 60-second ceiling as a caller's wait_timeout_seconds.
class UpdateWaitConfigurationTest extends TestCase
{
    public function test_a_configured_default_above_the_ceiling_is_clamped(): void
    {
        config([UpdateWaitConfiguration::CONFIG_KEY => 600]);

        $this->assertSame(60, UpdateWaitConfiguration::apply());
        $this->assertSame(60, UpdateWaitPolicy::completionTimeoutSeconds());
    }

    public function test_a_configured_default_within_the_ceiling_is_kept(): void
    {
        foreach ([1, 25, 60] as $seconds) {
            config([UpdateWaitConfiguration::CONFIG_KEY => $seconds]);

            $this->assertSame($seconds, UpdateWaitConfiguration::apply());
            $this->assertSame($seconds, UpdateWaitPolicy::completionTimeoutSeconds());
        }
    }

    public function test_the_server_applies_the_ceiling_to_the_environment_setting_at_boot(): void
    {
        $previous = getenv('DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS');
        putenv('DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS=600');
        $_ENV['DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS'] = '600';
        $_SERVER['DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS'] = '600';

        try {
            $this->refreshApplication();

            $this->assertSame(60, UpdateWaitPolicy::completionTimeoutSeconds());
        } finally {
            if ($previous === false) {
                putenv('DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS');
                unset($_ENV['DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS'], $_SERVER['DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS']);
            } else {
                putenv('DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS='.$previous);
                $_ENV['DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS'] = $previous;
                $_SERVER['DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS'] = $previous;
            }

            $this->refreshApplication();
        }
    }
}
