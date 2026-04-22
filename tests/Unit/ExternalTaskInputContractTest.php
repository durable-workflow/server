<?php

namespace Tests\Unit;

use App\Support\ExternalTaskInputContract;
use PHPUnit\Framework\TestCase;

class ExternalTaskInputContractTest extends TestCase
{
    public function test_manifest_defines_carrier_neutral_workflow_and_activity_envelopes(): void
    {
        $manifest = ExternalTaskInputContract::manifest();

        $this->assertSame('durable-workflow.v2.external-task-input.contract', $manifest['schema']);
        $this->assertSame(1, $manifest['version']);
        $this->assertSame('ignore_additive_reject_unknown_required', $manifest['unknown_field_policy']);
        $this->assertArrayHasKey('workflow_task', $manifest['envelopes']);
        $this->assertArrayHasKey('activity_task', $manifest['envelopes']);
        $this->assertSame('workflow_task', $manifest['envelopes']['workflow_task']['kind']);
        $this->assertSame('activity_task', $manifest['envelopes']['activity_task']['kind']);
        $this->assertContains('lease', $manifest['envelopes']['workflow_task']['required_fields']);
        $this->assertContains('deadlines', $manifest['envelopes']['activity_task']['required_fields']);
        $this->assertArrayHasKey('external_storage', $manifest['payload_support']);
        $this->assertSame(
            'durable-workflow.v2.external-task-input.workflow-task.v1',
            $manifest['fixtures']['workflow_task']['artifact'],
        );
        $this->assertSame(
            'application/vnd.durable-workflow.external-task-input+json',
            $manifest['fixtures']['activity_task']['media_type'],
        );
        $this->assertStringNotContainsString('tests/Fixtures', json_encode($manifest['fixtures']));
    }

    /**
     * @dataProvider fixtureProvider
     */
    public function test_fixtures_match_declared_required_fields(string $kind): void
    {
        $manifest = ExternalTaskInputContract::manifest();
        $fixturePath = dirname(__DIR__, 2).'/tests/Fixtures/contracts/external-task-input/'.str_replace('_', '-', $kind).'.v1.json';
        $fixture = json_decode((string) file_get_contents($fixturePath), true);

        $this->assertIsArray($fixture);
        $this->assertSame($fixture, $manifest['fixtures'][$kind]['example']);
        $this->assertSame(
            hash('sha256', (string) json_encode($fixture, JSON_UNESCAPED_SLASHES)),
            $manifest['fixtures'][$kind]['sha256'],
        );
        $this->assertSame('durable-workflow.v2.external-task-input', $fixture['schema']);
        $this->assertSame(1, $fixture['version']);
        $this->assertSame($fixture['schema'], $manifest['fixtures'][$kind]['schema']);
        $this->assertSame($fixture['version'], $manifest['fixtures'][$kind]['version']);
        $this->assertSame($manifest['envelopes'][$kind]['kind'], $fixture['task']['kind']);

        foreach ($manifest['envelopes'][$kind]['required_fields'] as $field) {
            $this->assertArrayHasKey($field, $fixture, "Fixture [{$kind}] is missing [{$field}].");
        }

        foreach (['id', 'attempt', 'task_queue', 'handler', 'idempotency_key'] as $field) {
            $this->assertArrayHasKey($field, $fixture['task'], "Fixture [{$kind}] task is missing [{$field}].");
        }

        $this->assertArrayHasKey('owner', $fixture['lease']);
        $this->assertArrayHasKey('expires_at', $fixture['lease']);
        $this->assertArrayHasKey('arguments', $fixture['payloads']);
        $this->assertArrayHasKey('traceparent', $fixture['headers']);
    }

    public static function fixtureProvider(): array
    {
        return [
            'workflow task' => ['workflow_task'],
            'activity task' => ['activity_task'],
        ];
    }
}
