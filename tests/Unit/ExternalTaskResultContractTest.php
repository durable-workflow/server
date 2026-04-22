<?php

namespace Tests\Unit;

use App\Support\ExternalTaskResultContract;
use PHPUnit\Framework\TestCase;

class ExternalTaskResultContractTest extends TestCase
{
    public function test_manifest_defines_carrier_neutral_result_envelopes(): void
    {
        $manifest = ExternalTaskResultContract::manifest();

        $this->assertSame('durable-workflow.v2.external-task-result.contract', $manifest['schema']);
        $this->assertSame(1, $manifest['version']);
        $this->assertSame('ignore_additive_reject_unknown_required', $manifest['unknown_field_policy']);
        $this->assertSame('logs_only_no_machine_meaning', $manifest['stderr_policy']);
        $this->assertArrayHasKey('success', $manifest['envelopes']);
        $this->assertArrayHasKey('failure', $manifest['envelopes']);
        $this->assertArrayHasKey('malformed_output', $manifest['envelopes']);
        $this->assertContains('unsupported_payload_codec', $manifest['envelopes']['failure']['failure_fields']['classification']['values']);
        $this->assertContains('unsupported_payload_reference', $manifest['envelopes']['failure']['failure_fields']['classification']['values']);
    }

    /**
     * @dataProvider fixtureProvider
     */
    public function test_fixtures_match_declared_required_fields(string $kind, string $status): void
    {
        $manifest = ExternalTaskResultContract::manifest();
        $fixturePath = dirname(__DIR__, 2).'/'.$manifest['fixtures'][$kind];
        $fixture = json_decode((string) file_get_contents($fixturePath), true);

        $this->assertIsArray($fixture);
        $this->assertSame('durable-workflow.v2.external-task-result', $fixture['schema']);
        $this->assertSame(1, $fixture['version']);
        $this->assertSame($status, $fixture['outcome']['status']);

        foreach ($manifest['envelopes'][$kind]['required_fields'] as $field) {
            $this->assertArrayHasKey($field, $fixture, "Fixture [{$kind}] is missing [{$field}].");
        }

        foreach (['id', 'kind', 'attempt', 'idempotency_key'] as $field) {
            $this->assertArrayHasKey($field, $fixture['task'], "Fixture [{$kind}] task is missing [{$field}].");
        }

        $this->assertArrayHasKey('handler', $fixture['metadata']);
        $this->assertArrayHasKey('carrier', $fixture['metadata']);
    }

    public static function fixtureProvider(): array
    {
        return [
            'success' => ['success', 'succeeded'],
            'failure' => ['failure', 'failed'],
            'malformed output' => ['malformed_output', 'failed'],
        ];
    }
}
