<?php

namespace Tests\Unit;

use App\Support\ExternalExecutorConfigContract;
use PHPUnit\Framework\TestCase;

class ExternalExecutorConfigContractTest extends TestCase
{
    public function test_manifest_publishes_cli_schema_and_runtime_validation_contract(): void
    {
        $manifest = ExternalExecutorConfigContract::manifest();

        $this->assertSame('durable-workflow.v2.external-executor-config.contract', $manifest['schema']);
        $this->assertSame(1, $manifest['version']);
        $this->assertSame('durable-workflow.external-executor.config', $manifest['config_schema']['schema']);
        $this->assertSame(1, $manifest['config_schema']['version']);
        $this->assertSame(
            'https://durable-workflow.com/schemas/cli/config/external-executor.schema.json',
            $manifest['config_schema']['json_schema_id'],
        );
        $this->assertSame('config_file', $manifest['steady_state_surface']);
        $this->assertSame('DW_EXTERNAL_EXECUTOR_CONFIG_PATH', $manifest['server_runtime']['config_path_env']);
        $this->assertSame('DW_EXTERNAL_EXECUTOR_CONFIG_OVERLAY', $manifest['server_runtime']['overlay_env']);
        $this->assertContains('unknown_carrier', $manifest['validation']['named_errors']);
        $this->assertContains('unknown_handler', $manifest['validation']['named_errors']);
        $this->assertContains('unsupported_carrier_capability', $manifest['validation']['named_errors']);
    }

    public function test_named_errors_cover_config_file_and_mapping_admission_failures(): void
    {
        $this->assertSame([
            'unreadable_config',
            'invalid_json',
            'invalid_schema',
            'unsupported_version',
            'unknown_overlay',
            'unknown_carrier',
            'unknown_auth_ref',
            'unknown_handler',
            'duplicate_mapping_name',
            'invalid_queue_binding',
            'missing_handler_target',
            'unsupported_carrier_capability',
        ], ExternalExecutorConfigContract::namedErrors());
    }
}
