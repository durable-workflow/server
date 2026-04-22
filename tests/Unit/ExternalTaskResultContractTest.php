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
        $this->assertSame(
            'durable-workflow.v2.external-task-result.success.v1',
            $manifest['fixtures']['success']['artifact'],
        );
        $this->assertSame(
            'application/vnd.durable-workflow.external-task-result+json',
            $manifest['fixtures']['failure']['media_type'],
        );
        $this->assertStringNotContainsString('tests/Fixtures', json_encode($manifest['fixtures']));
    }

    /**
     * @dataProvider fixtureProvider
     */
    public function test_fixtures_match_declared_required_fields(
        string $kind,
        string $status,
        string $file,
        ?string $failureKind = null,
        ?string $classification = null,
    ): void {
        $manifest = ExternalTaskResultContract::manifest();
        $fixturePath = dirname(__DIR__, 2).'/tests/Fixtures/contracts/external-task-result/'.$file;
        $fixture = json_decode((string) file_get_contents($fixturePath), true);

        $this->assertIsArray($fixture);
        $this->assertSame($fixture, $manifest['fixtures'][$kind]['example']);
        $this->assertSame(
            hash('sha256', (string) json_encode($fixture, JSON_UNESCAPED_SLASHES)),
            $manifest['fixtures'][$kind]['sha256'],
        );
        $this->assertSame('durable-workflow.v2.external-task-result', $fixture['schema']);
        $this->assertSame(1, $fixture['version']);
        $this->assertSame($fixture['schema'], $manifest['fixtures'][$kind]['schema']);
        $this->assertSame($fixture['version'], $manifest['fixtures'][$kind]['version']);
        $this->assertSame($status, $fixture['outcome']['status']);

        $envelopeKind = $kind === 'success' ? 'success' : ($kind === 'malformed_output' ? 'malformed_output' : 'failure');

        foreach ($manifest['envelopes'][$envelopeKind]['required_fields'] as $field) {
            $this->assertArrayHasKey($field, $fixture, "Fixture [{$kind}] is missing [{$field}].");
        }

        foreach (['id', 'kind', 'attempt', 'idempotency_key'] as $field) {
            $this->assertArrayHasKey($field, $fixture['task'], "Fixture [{$kind}] task is missing [{$field}].");
        }

        if ($failureKind !== null) {
            $this->assertSame($failureKind, $fixture['failure']['kind']);
            $this->assertSame($classification, $fixture['failure']['classification']);
        }

        $this->assertArrayHasKey('handler', $fixture['metadata']);
        $this->assertArrayHasKey('carrier', $fixture['metadata']);
    }

    public static function fixtureProvider(): array
    {
        return [
            'success' => ['success', 'succeeded', 'success.v1.json'],
            'failure' => ['failure', 'failed', 'failure.v1.json', 'timeout', 'deadline_exceeded'],
            'malformed output' => ['malformed_output', 'failed', 'malformed-output.v1.json', 'malformed_output', 'malformed_output'],
            'cancellation' => ['cancellation', 'failed', 'cancellation.v1.json', 'cancellation', 'cancelled'],
            'handler crash' => ['handler_crash', 'failed', 'handler-crash.v1.json', 'handler_crash', 'handler_crash'],
            'decode failure' => ['decode_failure', 'failed', 'decode-failure.v1.json', 'decode_failure', 'decode_failure'],
            'unsupported payload codec' => ['unsupported_payload_codec', 'failed', 'unsupported-payload-codec.v1.json', 'unsupported_payload', 'unsupported_payload_codec'],
            'unsupported payload reference' => ['unsupported_payload_reference', 'failed', 'unsupported-payload-reference.v1.json', 'unsupported_payload', 'unsupported_payload_reference'],
        ];
    }
}
