<?php

namespace Tests\Unit;

use App\Support\AuthCompositionContract;
use PHPUnit\Framework\TestCase;

class AuthCompositionContractTest extends TestCase
{
    public function test_manifest_defines_carrier_auth_and_effective_config_rules(): void
    {
        $manifest = AuthCompositionContract::manifest();

        $this->assertSame('durable-workflow.v2.auth-composition.contract', $manifest['schema']);
        $this->assertSame(1, $manifest['version']);
        $this->assertSame('external_execution_carriers', $manifest['scope']);
        $this->assertSame(
            ['flag', 'environment', 'selected_profile', 'default'],
            $manifest['precedence']['connection_values'],
        );
        $this->assertSame('DURABLE_WORKFLOW_AUTH_TOKEN', $manifest['canonical_environment']['auth_token']);
        $this->assertSame('supported', $manifest['auth_material']['token']['status']);
        $this->assertSame('reserved', $manifest['auth_material']['mtls']['status']);
        $this->assertContains('auth', $manifest['effective_config']['required_fields']);
        $this->assertContains('tls', $manifest['effective_config']['required_fields']);
        $this->assertContains('bearer_tokens', $manifest['redaction']['never_echo']);
        $this->assertContains('environment_variable_name', $manifest['redaction']['allowed_diagnostics']);
    }
}
