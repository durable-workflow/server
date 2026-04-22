<?php

namespace Tests\Unit;

use App\Support\BridgeAdapterOutcomeContract;
use PHPUnit\Framework\TestCase;

class BridgeAdapterOutcomeContractTest extends TestCase
{
    public function test_manifest_defines_bounded_bridge_patterns_and_named_outcomes(): void
    {
        $manifest = BridgeAdapterOutcomeContract::manifest();

        $this->assertSame('durable-workflow.v2.bridge-adapter-outcome.contract', $manifest['schema']);
        $this->assertSame(1, $manifest['version']);
        $this->assertTrue($manifest['boundary']['not_a_workflow_runtime']);
        $this->assertContains('workflow_replay', $manifest['boundary']['forbidden_responsibilities']);
        $this->assertArrayHasKey('webhook_receiver', $manifest['patterns']);
        $this->assertArrayHasKey('eventbridge_handoff', $manifest['patterns']);
        $this->assertArrayHasKey('queue_backed_adapter', $manifest['patterns']);
        $this->assertContains('handoff_external_task', $manifest['patterns']['queue_backed_adapter']['allowed_actions']);
        $this->assertTrue($manifest['idempotency']['required']);
        $this->assertContains('provider_event_id', $manifest['idempotency']['key_sources']);
        $this->assertArrayHasKey('accepted', $manifest['outcomes']);
        $this->assertArrayHasKey('duplicate', $manifest['outcomes']);
        $this->assertContains('unknown_target', $manifest['rejection_reasons']);
        $this->assertContains('unsupported_routing', $manifest['rejection_reasons']);
    }

    public function test_manifest_requires_redacted_visibility_fields(): void
    {
        $manifest = BridgeAdapterOutcomeContract::manifest();

        foreach (['schema', 'version', 'adapter', 'action', 'accepted', 'outcome', 'reason', 'idempotency_key', 'target'] as $field) {
            $this->assertContains($field, $manifest['visibility']['outcome_fields']);
        }

        $this->assertContains('raw_payload', $manifest['visibility']['redaction']['never_echo']);
        $this->assertContains('credential_ref', $manifest['visibility']['redaction']['safe_references']);
    }
}
