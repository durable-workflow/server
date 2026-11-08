<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2).'/scripts/conformance/server-parity/ready-age-claim.php';

// Server #1096: every ready-age claim refusal keeps bounded diagnostics, the
// predicate itself is unchanged, and no task value other than the two
// ready-age facts reaches the message.
final class ServerParityReadyAgeRestartDiagnosticsTest extends TestCase
{
    private const PREFIX = 'Native successful claim lacks its declared literal ready-age value. ';

    private function claim(): array
    {
        return ['poll_status' => 'leased', 'server_capabilities' => ['claimed_task_ready_age' => true],
            'task' => ['task_id' => 'task-1', 'run_id' => 'run-1', 'workflow_task_attempt' => 1, 'ready_age_ms' => 42,
                'payload' => 'SECRET-PAYLOAD', 'lease_owner' => 'SECRET-OWNER']];
    }

    private function refusal(array $response, array $context = []): array
    {
        $GLOBALS['readyAgeLastHttpStatus'] = 'HTTP/1.1 200 OK';
        try {
            readyAgeClaim($response, $context);
        } catch (RuntimeException $error) {
            $message = $error->getMessage();
            self::assertStringStartsWith(self::PREFIX, $message);
            self::assertStringNotContainsString('SECRET', $message);

            return json_decode(substr($message, strlen(self::PREFIX)), true, flags: JSON_THROW_ON_ERROR);
        }
        self::fail('The refused claim was accepted.');
    }

    public function test_a_valid_claim_is_unchanged_and_ignores_diagnostic_context(): void
    {
        $expected = ['ready_age_ms' => 42, 'run_id' => 'run-1', 'task_id' => 'task-1', 'workflow_task_attempt' => 1];
        self::assertSame($expected, readyAgeClaim($this->claim()));
        self::assertSame($expected, readyAgeClaim($this->claim(), ['phase' => 'finish', 'saved_task_id' => 'task-1']));
    }

    public function test_an_empty_answer_records_its_status_and_shape(): void
    {
        $shape = $this->refusal(['poll_status' => 'empty', 'task' => null,
            'server_capabilities' => ['claimed_task_ready_age' => true]],
            ['phase' => 'finish', 'kind' => 'workflow', 'seconds_since_prepare' => 31.5, 'saved_task_id' => 'task-1']);
        self::assertSame('HTTP/1.1 200 OK', $shape['http_status']);
        self::assertSame('empty', $shape['poll_status']);
        self::assertSame('null', $shape['task_type']);
        self::assertNull($shape['task_keys']);
        self::assertSame(['poll_status', 'task', 'server_capabilities'], $shape['response_keys']);
        self::assertFalse($shape['same_task_id']);
        self::assertSame(['finish', 'workflow', 31.5], [$shape['phase'], $shape['kind'], $shape['seconds_since_prepare']]);
    }

    public function test_a_missing_or_false_capability_is_recorded(): void
    {
        $response = $this->claim();
        unset($response['server_capabilities']['claimed_task_ready_age']);
        $shape = $this->refusal($response);
        self::assertSame(['present' => false, 'type' => 'null', 'value' => null], $shape['claimed_task_ready_age']);
        $response['server_capabilities']['claimed_task_ready_age'] = false;
        self::assertSame(['present' => true, 'type' => 'bool', 'value' => false], $this->refusal($response)['claimed_task_ready_age']);
    }

    public function test_a_missing_or_malformed_ready_age_is_recorded_without_other_task_values(): void
    {
        foreach ([
            'missing' => [null, ['present' => false, 'type' => 'null', 'value' => null]],
            'string' => ['42', ['present' => true, 'type' => 'string', 'value' => null]],
            'negative' => [-1, ['present' => true, 'type' => 'int', 'value' => -1]],
        ] as $case => [$value, $expected]) {
            $response = $this->claim();
            if ($value === null) {
                unset($response['task']['ready_age_ms']);
            } else {
                $response['task']['ready_age_ms'] = $value;
            }
            $shape = $this->refusal($response, ['saved_task_id' => 'task-1']);
            self::assertSame($expected, $shape['ready_age_ms'], $case);
            self::assertTrue($shape['same_task_id'], $case);
            self::assertContains('payload', $shape['task_keys'], $case);
        }
    }
}
