<?php

namespace Tests\Unit;

use App\Support\ExternalTaskInputContract;
use App\Support\ExternalTaskResultContract;
use App\Support\InvocableCarrierContract;
use PHPUnit\Framework\TestCase;

class InvocableCarrierContractTest extends TestCase
{
    public function test_manifest_freezes_activity_only_http_invocation_contract(): void
    {
        $manifest = InvocableCarrierContract::manifest();

        $this->assertSame('durable-workflow.v2.invocable-carrier.contract', $manifest['schema']);
        $this->assertSame(1, $manifest['version']);
        $this->assertSame('invocable_http', $manifest['carrier_type']);
        $this->assertSame(['activity_task'], $manifest['scope']['task_kinds']);
        $this->assertContains('workflow_task_execution', $manifest['scope']['explicit_non_goals']);
        $this->assertSame(['https', 'http_loopback'], $manifest['target_fields']['url']['allowed_schemes']);
        $this->assertSame(['userinfo'], $manifest['target_fields']['url']['forbidden']);
        $this->assertSame('POST', $manifest['request']['method']);
        $this->assertSame(ExternalTaskInputContract::SCHEMA, $manifest['request']['body_schema']);
        $this->assertSame(ExternalTaskResultContract::SCHEMA, $manifest['response']['body_schema']);
        $this->assertSame('failure.kind=timeout classification=deadline_exceeded', $manifest['failure_mapping']['transport_timeout']);
        $this->assertSame(5, $manifest['target_fields']['retry_policy']['fields']['max_attempts']['maximum']);
        $this->assertSame([408, 429, '5xx'], $manifest['target_fields']['retry_policy']['fields']['retryable_status_codes']['default']);
        $this->assertSame('carriers.<name>.retry_policy', $manifest['rollout_safety']['retry_policy_path']);
    }
}
