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
        $this->assertArrayHasKey('incident_webhook_signals_workflow', $manifest['reference_journeys']);
        $this->assertArrayHasKey('commerce_event_starts_workflow', $manifest['reference_journeys']);
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

    public function test_reference_journeys_pin_request_and_outcome_shapes(): void
    {
        $manifest = BridgeAdapterOutcomeContract::manifest();

        $incident = $manifest['reference_journeys']['incident_webhook_signals_workflow'];

        $this->assertSame('webhook_receiver', $incident['pattern']);
        $this->assertSame('/api/bridge-adapters/webhook/{adapter}', $incident['request']['path_template']);
        $this->assertSame('signal_workflow', $incident['request']['action']);
        $this->assertSame('provider_event_id', $incident['request']['idempotency_key_source']);
        $this->assertSame(202, $incident['expected_outcomes']['first_delivery']['http_status']);
        $this->assertSame('deduped_existing_command', $incident['expected_outcomes']['redelivery']['control_plane_outcome']);
        $this->assertSame('unknown_target', $incident['expected_outcomes']['missing_workflow']['reason']);
        $this->assertContains('request_id', $incident['visibility']['command_context_metadata']);

        $commerce = $manifest['reference_journeys']['commerce_event_starts_workflow'];

        $this->assertSame('start_workflow', $commerce['request']['action']);
        $this->assertContains('use_existing', $commerce['request']['target']['duplicate_policy']);
        $this->assertSame('started_new', $commerce['expected_outcomes']['first_delivery']['control_plane_outcome']);
        $this->assertSame('duplicate_start', $commerce['expected_outcomes']['redelivery']['reason']);
        $this->assertContains('business_key', $commerce['visibility']['redacted_target_fields']);
    }
}
