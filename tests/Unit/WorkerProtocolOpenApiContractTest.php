<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class WorkerProtocolOpenApiContractTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $spec;

    protected function setUp(): void
    {
        parent::setUp();

        $specPath = dirname(__DIR__, 2).'/resources/platform-protocol-specs/worker-protocol-api.openapi.yaml';
        $this->assertFileExists($specPath);

        $spec = Yaml::parseFile($specPath);
        $this->assertIsArray($spec);
        $this->spec = $spec;
    }

    public function test_cached_poll_conflict_shape_has_a_distinct_document_version(): void
    {
        $this->assertSame('13', $this->spec['info']['version']);
        $this->assertSame('1.17', $this->spec['x-durable-workflow-worker-protocol-negotiation']['default_advertised_version']);
        $this->assertSame(
            ['1.0', '1.1', '1.2', '1.3', '1.4', '1.5', '1.6', '1.7', '1.8', '1.9', '1.10', '1.11', '1.12', '1.13', '1.14', '1.15', '1.16', '1.17'],
            $this->spec['x-durable-workflow-worker-protocol-negotiation']['accepted_request_versions_by_default'],
        );
    }

    public function test_message_stream_completion_metadata_is_machine_described(): void
    {
        $contract = $this->spec['x-durable-workflow-message-streams-contract'];
        $this->assertSame('1.15', $contract['minimum_protocol_version']);
        $this->assertSame('message_streams', $contract['worker_capability']);
        $this->assertSame(
            ['message_stream_cursors', 'message_stream_waits'],
            $contract['completion_fields'],
        );

        $request = $this->spec['components']['schemas']['WorkflowTaskCompleteRequest'];
        $this->assertSame(
            '#/components/schemas/MessageStreamCursorAdvance',
            $request['properties']['message_stream_cursors']['items']['$ref'],
        );
        $this->assertSame(
            '#/components/schemas/MessageStreamWait',
            $request['properties']['message_stream_waits']['items']['$ref'],
        );
        $this->assertContains(
            'message_streams',
            $this->spec['components']['schemas']['WorkerServerCapabilities']['required'],
        );
    }

    public function test_workflow_memo_update_command_and_capability_are_machine_described(): void
    {
        $contract = $this->spec['x-durable-workflow-workflow-memo-update-contract'];
        $this->assertSame('1.14', $contract['minimum_protocol_version']);
        $this->assertSame('memo_upserts', $contract['worker_capability']);
        $this->assertSame(
            'workflow_metadata_capability_not_advertised',
            $contract['capability_enforcement']['completion_without_capability']['reason'],
        );
        $this->assertSame('upsert_memo', $contract['command']['type']);
        $this->assertSame(['sequence', 'entries'], $contract['history_event']['replay_identity']);
        $this->assertSame('runtime', $contract['external_payload_resolution_owner']);
        $this->assertFalse($contract['sdk_storage_drivers']);
        $this->assertSame(
            ['php', 'python', 'rust'],
            $contract['published_artifact_conformance']['worker_languages'],
        );
        $this->assertSame(
            ['standalone_server', 'managed_cloud'],
            $contract['published_artifact_conformance']['runtime_targets'],
        );

        $capabilities = $this->spec['components']['schemas']['WorkerServerCapabilities'];
        $this->assertContains('workflow_memo_updates', $capabilities['required']);
        $this->assertSame(
            '#/components/schemas/WorkflowMemoUpdateCapability',
            $capabilities['properties']['workflow_memo_updates']['$ref'],
        );

        $commands = $this->spec['components']['schemas']['WorkflowTaskCompleteRequest']['properties']['commands'];
        $this->assertSame('#/components/schemas/WorkflowCommand', $commands['items']['$ref']);
        $memoCondition = $this->spec['components']['schemas']['WorkflowCommand']['allOf'][0];
        $this->assertSame('upsert_memo', $memoCondition['if']['properties']['type']['const']);
        $this->assertSame(['entries'], $memoCondition['then']['required']);
        $this->assertSame(
            '#/components/schemas/MemoEntriesPayloadEnvelope',
            $memoCondition['then']['properties']['entries']['$ref'],
        );
        $this->assertSame(
            'avro',
            $this->spec['components']['schemas']['InlineMemoEntriesPayloadEnvelope']['properties']['codec']['const'],
        );
        $this->assertSame(
            [
                ['$ref' => '#/components/schemas/InlineMemoEntriesPayloadEnvelope'],
                ['$ref' => './external-payload-transport.openapi.yaml#/components/schemas/RuntimeExternalPayloadEnvelope'],
            ],
            $this->spec['components']['schemas']['MemoEntriesPayloadEnvelope']['oneOf'],
        );
    }

    public function test_typed_search_attribute_identity_and_version_gate_are_machine_described(): void
    {
        $contract = $this->spec['x-durable-workflow-typed-search-attributes-contract'];
        $this->assertSame('1.16', $contract['minimum_protocol_version']);
        $this->assertSame('typed_search_attributes', $contract['worker_capability']);
        $this->assertSame(
            'requires_active_lease_owner_registration_capability',
            $contract['capability_enforcement']['typed_history_routing'],
        );
        $this->assertSame(
            ['string', 'keyword', 'keyword_list', 'int', 'float', 'bool', 'datetime'],
            $contract['canonical_type_names'],
        );
        $this->assertSame('typed_search_attributes_unavailable', $contract['version_gate']['rejection_reason']);
        $this->assertSame(
            ['sequence', 'attributes', 'attribute_types'],
            $contract['history']['replay_identity'],
        );
        $this->assertSame(
            'unknown_type_identity_compare_value_only',
            $contract['history']['legacy_missing_metadata'],
        );

        $command = $this->spec['components']['schemas']['WorkflowCommand']['allOf'][1]['then']['properties'];
        $this->assertSame('1.16', $command['attribute_types']['x-durable-workflow-minimum-protocol-version']);
        $this->assertSame(
            ['string', 'keyword', 'keyword_list', 'int', 'float', 'bool', 'datetime'],
            $this->spec['components']['schemas']['SearchAttributeType']['enum'],
        );
        $this->assertContains(
            'typed_search_attributes',
            $this->spec['components']['schemas']['WorkerServerCapabilities']['required'],
        );
    }

    public function test_condition_wait_occurrence_identity_floor_and_replay_gate_are_machine_described(): void
    {
        $contract = $this->spec['x-durable-workflow-condition-wait-occurrence-identity-contract'];
        $this->assertSame('1.17', $contract['minimum_protocol_version']);
        $this->assertSame('condition_wait_occurrence_identity_unavailable', $contract['authoring_gate']['rejection_reason']);
        $this->assertFalse($contract['authoring_gate']['recorded']);
        $this->assertSame(
            ['cold_replay', 'cached_poll', 'redelivery'],
            $contract['replay_gate']['applies_to'],
        );
        $this->assertTrue(
            $contract['version_independence']['server_at_minimum_accepts_worker_1_16_for_unaffected_history'],
        );

        $capabilities = $this->spec['components']['schemas']['WorkerServerCapabilities'];
        $this->assertContains('condition_wait_occurrence_identity', $capabilities['required']);
        $conditionCapability = $this->spec['components']['schemas']['ConditionWaitOccurrenceIdentityCapability'];
        $this->assertSame('1.17', $conditionCapability['properties']['minimum_worker_protocol_version']['const']);

        $command = $this->spec['components']['schemas']['WorkflowCommand']['allOf'][2]['then']['properties'];
        $this->assertSame(
            '1.17',
            $command['condition_wait_occurrence_id']['x-durable-workflow-minimum-protocol-version'],
        );
        $this->assertSame(
            '#/components/responses/WorkerRegistrationFailure',
            $this->spec['paths']['/worker/register']['post']['responses']['409']['$ref'],
        );
        $this->assertSame(
            '#/components/responses/WorkflowTaskCompletionFailure',
            $this->spec['paths']['/worker/workflow-tasks/{taskId}/complete']['post']['responses']['409']['$ref'],
        );
    }

    #[DataProvider('pollRequestConflictTaskKindSetProvider')]
    public function test_same_poll_request_conflict_rejects_scalar_task_kind_sets(
        string $field,
        string $scalarValue,
    ): void {
        $conflict = $this->spec['components']['schemas']['PollRequestTaskKindsConflict'];
        $required = $conflict['allOf'][1]['required'];
        $properties = $conflict['allOf'][1]['properties'];

        $this->assertContains($field, $required);
        $this->assertArrayHasKey($field, $properties);
        $this->assertSame([
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => 2,
            'uniqueItems' => true,
            'items' => [
                'type' => 'string',
                'enum' => ['workflow', 'update_validation'],
            ],
        ], $properties[$field]);

        $this->assertIsString($scalarValue);
        $this->assertNotSame(
            get_debug_type($scalarValue),
            $properties[$field]['type'],
            "A scalar {$field} must not match the published array type.",
        );
    }

    /**
     * @return array<string, array{field: string, scalarValue: string}>
     */
    public static function pollRequestConflictTaskKindSetProvider(): array
    {
        return [
            'requested task kinds' => [
                'field' => 'requested_task_kinds',
                'scalarValue' => 'workflow',
            ],
            'bound task kinds' => [
                'field' => 'bound_task_kinds',
                'scalarValue' => 'update_validation',
            ],
        ];
    }

    public function test_task_kind_sets_belong_to_the_poll_request_conflict_union_branch(): void
    {
        $response = $this->spec['components']['responses']['WorkflowTaskPollConflict'];
        $this->assertSame([
            ['$ref' => '#/components/schemas/PollRequestTaskKindsConflict'],
            ['$ref' => '#/components/schemas/CachedPollTaskKindConflict'],
            ['$ref' => '#/components/schemas/UpdateValidationCapabilityConflict'],
        ], $response['content']['application/json']['schema']['oneOf']);

        $capabilityProperties = $this->spec['components']['schemas']['UpdateValidationCapabilityConflict']['allOf'][1]['properties'];
        $this->assertArrayNotHasKey('requested_task_kinds', $capabilityProperties);
        $this->assertArrayNotHasKey('bound_task_kinds', $capabilityProperties);
    }

    public function test_cached_task_kind_conflict_represents_legacy_discriminators_with_null(): void
    {
        $conflict = $this->spec['components']['schemas']['CachedPollTaskKindConflict']['allOf'][1];

        $this->assertContains('requested_task_kinds', $conflict['required']);
        $this->assertContains('cached_task_kind', $conflict['required']);
        $this->assertContains('cached_task_kind_state', $conflict['required']);
        $this->assertSame(
            ['type' => ['string', 'null'], 'minLength' => 1],
            $conflict['properties']['cached_task_kind'],
        );
        $this->assertSame([
            ['properties' => [
                'cached_task_kind' => ['type' => 'null'],
                'cached_task_kind_state' => ['const' => 'legacy_missing_discriminator'],
            ]],
            ['properties' => [
                'cached_task_kind' => ['type' => 'string', 'minLength' => 1],
                'cached_task_kind_state' => ['const' => 'unrequested_discriminator'],
            ]],
        ], $conflict['oneOf']);
    }
}
