<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\CooperativeCancellationPolicy;
use App\Support\PreparedLocalActivityPolicy;
use App\Support\PreparedLocalRetryAdmission;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Support\OpenApiSchema;
use PHPUnit\Framework\AssertionFailedError;
use Workflow\V2\Contracts\PreparedLocalActivityTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\WorkflowTask;

final class PreparedLocalRetryAdmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['server.worker_protocol.version' => '1.20']);
        WorkflowNamespace::query()->create(['name' => 'default', 'description' => 'Test',
            'retention_days' => 30, 'status' => 'active']);
    }

    public function test_policy_and_report_presence_are_only_untrusted_intent(): void
    {
        $this->assertFalse(PreparedLocalRetryAdmission::descriptorIntent(['retry_policy' => ['max_attempts' => 2, 'backoff_seconds' => [0]]]));
        foreach (['contract_version', 'snapshot_version', 'mode', 'maximum_interval_seconds'] as $field) {
            foreach ([null, false, '2', 2.0, 2] as $value) {
                $this->assertTrue(PreparedLocalRetryAdmission::descriptorIntent(['retry_policy' => [$field => $value]]));
            }
        }
        $this->assertFalse(PreparedLocalRetryAdmission::reportIntent(['outcome' => 'failed', 'message' => 'failed']));
        foreach ([null, false, '0', 0.0, -1, 0] as $value) {
            $this->assertTrue(PreparedLocalRetryAdmission::reportIntent(['next_retry_delay_seconds' => $value]));
        }
        $this->assertFalse(PreparedLocalRetryAdmission::backendSupported());
    }

    public function test_original_capability_conjunction_keeps_literal_types(): void
    {
        $claim = ['protocol_version' => '1.20', 'capabilities' => [CooperativeCancellationPolicy::CAPABILITY,
            PreparedLocalActivityPolicy::CAPABILITY, PreparedLocalRetryAdmission::CAPABILITY]];
        $this->assertTrue(PreparedLocalRetryAdmission::claimSupports($claim));
        foreach ($claim['capabilities'] as $capability) {
            $this->assertFalse(PreparedLocalRetryAdmission::claimSupports([...$claim,
                'capabilities' => array_values(array_diff($claim['capabilities'], [$capability]))]));
        }
        foreach ([null, false, true, 1.20, 120, '1.19', '2.0', 'invalid'] as $version) {
            $this->assertFalse(PreparedLocalRetryAdmission::claimSupports([...$claim, 'protocol_version' => $version]));
        }
        foreach ([null, true, PreparedLocalRetryAdmission::CAPABILITY] as $capabilities) {
            $this->assertFalse(PreparedLocalRetryAdmission::claimSupports([...$claim, 'capabilities' => $capabilities]));
        }
    }

    public function test_versioned_wire_schema_preserves_legacy_and_failed_only_override(): void
    {
        $schema = OpenApiSchema::fromFile(base_path('resources/platform-protocol-specs/worker-protocol-api.openapi.yaml'));
        foreach (['explicit', 'generated'] as $mode) {
            $schema->assertReferenceMatches('#/components/schemas/PreparedLocalRetryPolicyV2', (object) [
                'contract_version' => 2, 'mode' => $mode, 'backoff_seconds' => array_fill(0, 1024, 0),
            ]);
        }
        foreach ([[], ['next_retry_delay_seconds' => 0], ['next_retry_delay_seconds' => 9007199254740991]] as $extra) {
            $schema->assertReferenceMatches('#/components/schemas/PreparedLocalOutcomeRequest', (object) [
                'lease_owner' => 'worker', 'workflow_task_attempt' => 1,
                'report' => (object) ['outcome' => 'failed', 'message' => 'failed', ...$extra],
            ]);
        }
        foreach ([null, true, '0', 0.0, -1, 9007199254740992] as $value) {
            try {
                $schema->assertReferenceMatches('#/components/schemas/PreparedLocalOutcomeRequest', (object) [
                    'lease_owner' => 'worker', 'workflow_task_attempt' => 1,
                    'report' => (object) ['outcome' => 'failed', 'message' => 'failed', 'next_retry_delay_seconds' => $value],
                ]);
            } catch (AssertionFailedError) {
                continue;
            }
            $this->fail('A nonliteral or out-of-range override matched the wire schema.');
        }
        foreach (['completed', 'timed_out'] as $outcome) {
            try {
                $schema->assertReferenceMatches('#/components/schemas/PreparedLocalOutcomeRequest', (object) [
                    'lease_owner' => 'worker', 'workflow_task_attempt' => 1,
                    'report' => (object) ['outcome' => $outcome, ...($outcome === 'completed' ? ['result' => 'encoded'] : []), 'next_retry_delay_seconds' => 0],
                ]);
            } catch (AssertionFailedError) {
                continue;
            }
            $this->fail('A nonfailed report accepted an override.');
        }
    }

    public function test_a_base_prepared_bridge_does_not_acquire_the_optional_role(): void
    {
        $this->app->instance(WorkflowTaskBridge::class, \Mockery::mock(PreparedLocalActivityTaskBridge::class));
        $this->assertFalse(PreparedLocalRetryAdmission::backendSupported());
    }

    public function test_default_unavailable_role_precedes_override_and_durable_lookup(): void
    {
        DB::enableQueryLog();
        foreach ([null, true, '0', 0.0, -1, 0, 9007199254740992] as $delay) {
            $this->withHeaders(['X-Namespace' => 'default', WorkerProtocol::HEADER => '1.20'])
                ->postJson('/api/worker/workflow-tasks/missing/local-activities/absent/outcome', [
                    'lease_owner' => 'worker', 'workflow_task_attempt' => 1,
                    'report' => ['outcome' => 'failed', 'message' => 'failed', 'next_retry_delay_seconds' => $delay],
                ])->assertStatus(409)->assertJsonPath('reason', 'prepared_local_activity_not_supported');
        }
        $lookups = array_filter(DB::getQueryLog(), static fn (array $row): bool =>
            preg_match('/\b(workflow_tasks|activity_attempts|activity_executions|workflow_history_events)\b/i', $row['query']) === 1);
        DB::disableQueryLog();
        $this->assertSame([], array_values($lookups));
    }

    public function test_a_v1_receipt_is_preserved_and_cannot_supply_retry_authority(): void
    {
        $task = new WorkflowTask;
        $task->forceFill(['id' => 'task', 'workflow_run_id' => 'run', 'namespace' => 'default', 'payload' => [
            '_server_prepared_local_activity_claims' => ['attempt' => [
                'schema' => 'durable-workflow.server.prepared-local-authority/v1', 'claim' => ['attempt' => 1],
            ]],
        ]]);
        $attempt = new ActivityAttempt;
        $attempt->forceFill(['id' => 'attempt', 'workflow_task_id' => 'task', 'workflow_run_id' => 'run']);
        $before = $task->getAttributes();
        $this->assertFalse(PreparedLocalRetryAdmission::retainedIntent($task, $attempt));
        $this->assertNull(PreparedLocalRetryAdmission::originalClaim($task, $attempt, 'worker', 1));
        $this->assertSame($before, $task->getAttributes());
    }
}
