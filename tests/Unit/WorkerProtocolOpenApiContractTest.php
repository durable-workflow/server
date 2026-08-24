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
        $this->assertSame('11', $this->spec['info']['version']);
        $this->assertSame('1.15', $this->spec['x-durable-workflow-worker-protocol-negotiation']['default_advertised_version']);
        $this->assertSame(
            ['1.0', '1.1', '1.2', '1.3', '1.4', '1.5', '1.6', '1.7', '1.8', '1.9', '1.10', '1.11', '1.12', '1.13', '1.14', '1.15'],
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
