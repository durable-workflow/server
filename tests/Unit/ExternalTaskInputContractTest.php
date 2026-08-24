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
        $this->assertSame(['activity_task'], $manifest['scope']['activity_grade_external_execution']['task_kinds']);
        $this->assertSame(['workflow_task'], $manifest['scope']['worker_protocol_runtime']['task_kinds']);
        $this->assertSame('workflow_task', $manifest['envelopes']['workflow_task']['kind']);
        $this->assertSame('activity_task', $manifest['envelopes']['activity_task']['kind']);
        $this->assertSame('worker_protocol_runtime', $manifest['envelopes']['workflow_task']['scope']);
        $this->assertSame(
            'not_activity_grade_handler_input',
            $manifest['envelopes']['workflow_task']['external_execution_role'],
        );
        $this->assertSame('activity_grade_external_execution', $manifest['envelopes']['activity_task']['scope']);
        $this->assertSame(
            'activity_grade_handler_input',
            $manifest['envelopes']['activity_task']['external_execution_role'],
        );
        $this->assertContains('lease', $manifest['envelopes']['workflow_task']['required_fields']);
        $this->assertContains('deadlines', $manifest['envelopes']['activity_task']['required_fields']);
        $this->assertArrayHasKey('external_payload', $manifest['payload_support']);
        $this->assertSame(
            'durable-workflow.v2.external-task-input.workflow-task.v1',
            $manifest['fixtures']['workflow_task']['artifact'],
        );
        $this->assertSame(
            'durable-workflow.v2.external-task-input.condition-timeout-history.v1',
            $manifest['fixtures']['condition_timeout_history']['artifact'],
        );
        $this->assertSame(
            'application/vnd.durable-workflow.external-task-input+json',
            $manifest['fixtures']['activity_task']['media_type'],
        );
        $this->assertStringNotContainsString('tests/Fixtures', json_encode($manifest['fixtures']));
    }

    public function test_condition_timeout_history_contract_is_self_identifying_and_cursor_neutral(): void
    {
        $manifest = ExternalTaskInputContract::manifest();
        $contract = $manifest['envelopes']['workflow_task']['history_event_contracts']['condition_timeout'];

        $this->assertSame('condition_timeout', $contract['timer_kind']);
        $this->assertSame('condition_wait_id', $contract['condition_identity_field']);
        $this->assertSame(['timer_id', 'condition_wait_id'], $contract['correlation']['match_fields']);
        $this->assertTrue($contract['correlation']['condition_and_timer_sequences_may_differ']);
        $this->assertFalse($contract['correlation']['event_adjacency_required']);
        $this->assertFalse($contract['replay']['advances_ordinary_command_cursor']);
        $this->assertSame(
            ['sdk-php', 'sdk-python', 'sdk-rust'],
            $contract['conformance']['required_worker_runtimes'],
        );
        $this->assertSame(
            ['UpdateApplied', 'SignalReceived'],
            $contract['conformance']['required_interleaved_event_types'],
        );

        foreach (['TimerScheduled', 'TimerFired', 'TimerCancelled'] as $eventType) {
            $fields = $contract['timer_events'][$eventType]['required_payload_fields'];
            $this->assertContains('timer_kind', $fields);
            $this->assertContains('condition_wait_id', $fields);
        }
    }

    public function test_workflow_fixture_correlates_interleaved_condition_timeout_rows_without_adjacency(): void
    {
        $events = ExternalTaskInputContract::manifest()['fixtures']['condition_timeout_history']['example']['history']['events'];
        $eventsByType = [];
        foreach ($events as $event) {
            $eventsByType[$event['event_type']][] = $event;
        }

        $this->assertArrayHasKey('UpdateApplied', $eventsByType);
        $this->assertArrayHasKey('SignalReceived', $eventsByType);

        $opened = $eventsByType['ConditionWaitOpened'][0];
        $scheduled = $eventsByType['TimerScheduled'][0];
        $fired = $eventsByType['TimerFired'][0];
        $cancelled = $eventsByType['TimerCancelled'][0];

        $this->assertNotSame($opened['payload']['sequence'], $scheduled['payload']['sequence']);
        $this->assertSame($scheduled['payload']['timer_id'], $fired['payload']['timer_id']);
        $this->assertSame($scheduled['payload']['condition_wait_id'], $fired['payload']['condition_wait_id']);

        foreach ($events as $event) {
            if (! in_array($event['event_type'], ['TimerScheduled', 'TimerFired', 'TimerCancelled'], true)) {
                continue;
            }

            $this->assertSame('condition_timeout', $event['payload']['timer_kind'] ?? null);
            $this->assertIsString($event['payload']['condition_wait_id'] ?? null);
        }

        $cancelledTimer = array_values(array_filter(
            $eventsByType['TimerScheduled'],
            static fn (array $event): bool => $event['payload']['timer_id'] === $cancelled['payload']['timer_id'],
        ))[0];
        $this->assertSame($cancelledTimer['payload']['condition_wait_id'], $cancelled['payload']['condition_wait_id']);
    }

    /**
     * @dataProvider fixtureProvider
     */
    public function test_fixtures_match_declared_required_fields(string $fixtureKey, string $envelopeKind): void
    {
        $manifest = ExternalTaskInputContract::manifest();
        $fixturePath = dirname(__DIR__, 2).'/tests/Fixtures/contracts/external-task-input/'.str_replace('_', '-', $fixtureKey).'.v1.json';
        $fixture = json_decode((string) file_get_contents($fixturePath), true);

        $this->assertIsArray($fixture);
        $this->assertSame($fixture, $manifest['fixtures'][$fixtureKey]['example']);
        $this->assertSame(
            hash('sha256', (string) json_encode($fixture, JSON_UNESCAPED_SLASHES)),
            $manifest['fixtures'][$fixtureKey]['sha256'],
        );
        $this->assertSame('durable-workflow.v2.external-task-input', $fixture['schema']);
        $this->assertSame(1, $fixture['version']);
        $this->assertSame($fixture['schema'], $manifest['fixtures'][$fixtureKey]['schema']);
        $this->assertSame($fixture['version'], $manifest['fixtures'][$fixtureKey]['version']);
        $this->assertSame($manifest['envelopes'][$envelopeKind]['kind'], $fixture['task']['kind']);

        foreach ($manifest['envelopes'][$envelopeKind]['required_fields'] as $field) {
            $this->assertArrayHasKey($field, $fixture, "Fixture [{$fixtureKey}] is missing [{$field}].");
        }

        foreach (['id', 'attempt', 'task_queue', 'handler', 'idempotency_key'] as $field) {
            $this->assertArrayHasKey($field, $fixture['task'], "Fixture [{$fixtureKey}] task is missing [{$field}].");
        }

        $this->assertArrayHasKey('owner', $fixture['lease']);
        $this->assertArrayHasKey('expires_at', $fixture['lease']);
        $this->assertArrayHasKey('arguments', $fixture['payloads']);
        $this->assertArrayHasKey('traceparent', $fixture['headers']);
    }

    public static function fixtureProvider(): array
    {
        return [
            'workflow task' => ['workflow_task', 'workflow_task'],
            'condition timeout history' => ['condition_timeout_history', 'workflow_task'],
            'activity task' => ['activity_task', 'activity_task'],
        ];
    }
}
