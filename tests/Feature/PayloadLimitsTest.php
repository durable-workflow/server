<?php

namespace Tests\Feature;

use App\Models\SearchAttributeDefinition;
use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;

class PayloadLimitsTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
    }

    // ── Cluster info limits ────────────────────────────────────────

    public function test_cluster_info_exposes_server_limits(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonStructure([
                'limits' => [
                    'max_payload_bytes',
                    'max_memo_bytes',
                    'max_search_attributes',
                    'max_pending_activities',
                    'max_pending_children',
                ],
            ])
            ->assertJsonPath('limits.max_payload_bytes', 2 * 1024 * 1024)
            ->assertJsonPath('limits.max_memo_bytes', 256 * 1024)
            ->assertJsonPath('limits.max_search_attributes', 100);
    }

    public function test_cluster_info_reflects_custom_limit_configuration(): void
    {
        config(['server.limits.max_payload_bytes' => 512 * 1024]);
        config(['server.limits.max_memo_bytes' => 64 * 1024]);
        config(['server.limits.max_search_attributes' => 25]);

        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('limits.max_payload_bytes', 512 * 1024)
            ->assertJsonPath('limits.max_memo_bytes', 64 * 1024)
            ->assertJsonPath('limits.max_search_attributes', 25);
    }

    // ── Payload size enforcement ───────────────────────────────────

    public function test_requests_within_payload_limit_are_accepted(): void
    {
        $this->postJson('/api/namespaces', [
            'name' => 'small-payload',
            'description' => 'within limits',
        ], $this->apiHeaders())
            ->assertCreated();
    }

    public function test_requests_exceeding_payload_limit_are_rejected(): void
    {
        config(['server.limits.max_payload_bytes' => 100]);

        $this->postJson('/api/namespaces', [
            'name' => 'large-payload',
            'description' => str_repeat('x', 200),
        ], $this->apiHeaders())
            ->assertStatus(413)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertHeaderMissing(WorkerProtocol::HEADER)
            ->assertJsonPath('reason', 'payload_too_large')
            ->assertJsonStructure(['message', 'reason', 'limit']);
    }

    public function test_worker_requests_exceeding_payload_limit_use_worker_protocol_contract(): void
    {
        config(['server.limits.max_payload_bytes' => 100]);

        $this->postJson('/api/worker/register', [
            'worker_id' => 'large-worker-payload',
            'task_queue' => 'default',
            'runtime' => 'python',
            'metadata' => ['description' => str_repeat('x', 200)],
        ], $this->workerHeaders())
            ->assertStatus(413)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('reason', 'payload_too_large')
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonMissingPath('control_plane');
    }

    public function test_control_plane_non_json_request_bodies_use_control_plane_contract(): void
    {
        $body = '<workflow><type>Demo</type></workflow>';

        $this->withHeaders($this->apiHeaders())->call(
            'POST',
            '/api/workflows',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/xml',
                'CONTENT_LENGTH' => strlen($body),
            ],
            $body,
        )
            ->assertStatus(415)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertHeaderMissing(WorkerProtocol::HEADER)
            ->assertJsonPath('reason', 'unsupported_media_type')
            ->assertJsonPath('message', 'Request bodies must use a JSON media type.')
            ->assertJsonPath('accepted_content_types.0', 'application/json')
            ->assertJsonPath('control_plane.operation', 'start')
            ->assertJsonMissingPath('protocol_version')
            ->assertJsonMissingPath('server_capabilities');
    }

    public function test_worker_non_json_request_bodies_use_worker_protocol_contract(): void
    {
        $body = '<worker><id>xml-worker</id></worker>';

        $this->withHeaders($this->workerHeaders())->call(
            'POST',
            '/api/worker/register',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/xml',
                'CONTENT_LENGTH' => strlen($body),
            ],
            $body,
        )
            ->assertStatus(415)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('reason', 'unsupported_media_type')
            ->assertJsonPath('message', 'Request bodies must use a JSON media type.')
            ->assertJsonPath('accepted_content_types.0', 'application/json')
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonMissingPath('control_plane');
    }

    public function test_control_plane_malformed_json_request_bodies_use_control_plane_contract(): void
    {
        $body = '{"workflow_id":"bad-json",';

        $this->withHeaders($this->controlPlaneHeadersWithWorkerProtocol())->call(
            'POST',
            '/api/workflows',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'CONTENT_LENGTH' => strlen($body),
            ],
            $body,
        )
            ->assertStatus(400)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertHeaderMissing(WorkerProtocol::HEADER)
            ->assertJsonPath('reason', 'malformed_json')
            ->assertJsonPath('message', 'Request bodies must contain valid JSON.')
            ->assertJsonPath('json_error', static fn (mixed $error): bool => is_string($error) && $error !== '')
            ->assertJsonPath('control_plane.operation', 'start')
            ->assertJsonMissingPath('protocol_version')
            ->assertJsonMissingPath('server_capabilities');
    }

    public function test_worker_malformed_json_request_bodies_use_worker_protocol_contract(): void
    {
        $body = '{"worker_id":"bad-json",';

        $this->withHeaders($this->workerHeaders())->call(
            'POST',
            '/api/worker/register',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'CONTENT_LENGTH' => strlen($body),
            ],
            $body,
        )
            ->assertStatus(400)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('reason', 'malformed_json')
            ->assertJsonPath('message', 'Request bodies must contain valid JSON.')
            ->assertJsonPath('json_error', static fn (mixed $error): bool => is_string($error) && $error !== '')
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonMissingPath('control_plane');
    }

    public function test_get_requests_are_not_affected_by_payload_limit(): void
    {
        $this->getJson('/api/health')
            ->assertOk();
    }

    // ── Memo size enforcement ──────────────────────────────────────

    public function test_workflow_start_rejects_oversized_memo(): void
    {
        config(['server.limits.max_memo_bytes' => 50]);

        $this->configureWorkflowTypes([
            ExternalGreetingWorkflow::class,
        ]);

        $this->postJson('/api/workflows', [
            'workflow_type' => 'ExternalGreetingWorkflow',
            'memo' => ['data' => str_repeat('x', 100)],
        ], $this->apiHeaders())
            ->assertStatus(422)
            ->assertJsonPath('validation_errors.memo.0', fn (string $msg) => str_contains($msg, 'memo'));
    }

    public function test_workflow_start_accepts_memo_within_limit(): void
    {
        $this->configureWorkflowTypes([
            ExternalGreetingWorkflow::class,
        ]);

        $this->postJson('/api/workflows', [
            'workflow_type' => 'ExternalGreetingWorkflow',
            'memo' => ['note' => 'small'],
        ], $this->apiHeaders())
            ->assertSuccessful();
    }

    public function test_schedule_create_rejects_oversized_memo(): void
    {
        config(['server.limits.max_memo_bytes' => 50]);

        $this->postJson('/api/schedules', [
            'spec' => ['cron_expressions' => ['0 * * * *']],
            'action' => ['workflow_type' => 'SomeWorkflow'],
            'memo' => ['data' => str_repeat('x', 100)],
        ], $this->apiHeaders())
            ->assertStatus(422)
            ->assertJsonPath('reason', 'memo_too_large');
    }

    public function test_schedule_create_accepts_memo_within_limit(): void
    {
        $this->postJson('/api/schedules', [
            'spec' => ['cron_expressions' => ['0 * * * *']],
            'action' => ['workflow_type' => 'SomeWorkflow'],
            'memo' => ['note' => 'small'],
        ], $this->apiHeaders())
            ->assertCreated();
    }

    // ── Search attribute count enforcement ─────────────────────────

    public function test_search_attribute_creation_is_rejected_at_limit(): void
    {
        config(['server.limits.max_search_attributes' => 2]);

        // Create two attributes to reach the limit
        SearchAttributeDefinition::create(['namespace' => 'default', 'name' => 'Attr1', 'type' => 'keyword']);
        SearchAttributeDefinition::create(['namespace' => 'default', 'name' => 'Attr2', 'type' => 'int']);

        $this->postJson('/api/search-attributes', [
            'name' => 'Attr3',
            'type' => 'text',
        ], $this->apiHeaders())
            ->assertStatus(422)
            ->assertJsonPath('reason', 'search_attribute_limit_reached')
            ->assertJsonStructure(['message', 'reason', 'limit']);
    }

    public function test_search_attribute_creation_succeeds_below_limit(): void
    {
        config(['server.limits.max_search_attributes' => 5]);

        $this->postJson('/api/search-attributes', [
            'name' => 'MyAttr',
            'type' => 'keyword',
        ], $this->apiHeaders())
            ->assertCreated();
    }

    public function test_search_attribute_limit_is_scoped_to_namespace(): void
    {
        config(['server.limits.max_search_attributes' => 1]);

        $this->createNamespace('other');

        // Fill namespace "other" to capacity
        SearchAttributeDefinition::create(['namespace' => 'other', 'name' => 'Attr1', 'type' => 'keyword']);

        // Default namespace should still accept a new attribute
        $this->postJson('/api/search-attributes', [
            'name' => 'Attr1',
            'type' => 'keyword',
        ], $this->apiHeaders('default'))
            ->assertCreated();
    }

    // ── Per-attribute SA validation at request boundary ─────────────

    public function test_workflow_start_rejects_oversized_search_attribute_value(): void
    {
        config(['server.limits.max_search_attribute_value_bytes' => 64]);

        $this->configureWorkflowTypes([
            ExternalGreetingWorkflow::class,
        ]);

        $this->postJson('/api/workflows', [
            'workflow_type' => 'ExternalGreetingWorkflow',
            'search_attributes' => ['Region' => str_repeat('x', 200)],
        ], $this->apiHeaders())
            ->assertStatus(422)
            ->assertJsonPath(
                'validation_errors.search_attributes.0',
                fn (string $msg): bool => str_contains($msg, 'Region') && str_contains($msg, '64'),
            );
    }

    public function test_workflow_start_rejects_oversized_search_attribute_key(): void
    {
        config(['server.limits.max_search_attribute_key_length' => 8]);

        $this->configureWorkflowTypes([
            ExternalGreetingWorkflow::class,
        ]);

        $longKey = str_repeat('K', 32);

        $this->postJson('/api/workflows', [
            'workflow_type' => 'ExternalGreetingWorkflow',
            'search_attributes' => [$longKey => 'small'],
        ], $this->apiHeaders())
            ->assertStatus(422)
            ->assertJsonPath(
                'validation_errors.search_attributes.0',
                fn (string $msg): bool => str_contains($msg, $longKey) && str_contains($msg, '8'),
            );
    }

    public function test_workflow_start_rejects_malformed_search_attribute_key(): void
    {
        $this->configureWorkflowTypes([
            ExternalGreetingWorkflow::class,
        ]);

        $this->postJson('/api/workflows', [
            'workflow_type' => 'ExternalGreetingWorkflow',
            'search_attributes' => ['123invalid' => 'small'],
        ], $this->apiHeaders())
            ->assertStatus(422)
            ->assertJsonPath(
                'validation_errors.search_attributes.0',
                fn (string $msg): bool => str_contains($msg, '123invalid'),
            );
    }

    public function test_workflow_start_rejects_search_attribute_array_with_oversized_element(): void
    {
        config(['server.limits.max_search_attribute_value_bytes' => 32]);

        $this->configureWorkflowTypes([
            ExternalGreetingWorkflow::class,
        ]);

        $this->postJson('/api/workflows', [
            'workflow_type' => 'ExternalGreetingWorkflow',
            'search_attributes' => ['Tags' => ['short', str_repeat('y', 200)]],
        ], $this->apiHeaders())
            ->assertStatus(422)
            ->assertJsonPath(
                'validation_errors.search_attributes.0',
                fn (string $msg): bool => str_contains($msg, 'Tags') && str_contains($msg, '32'),
            );
    }

    public function test_workflow_start_accepts_valid_search_attributes(): void
    {
        $this->configureWorkflowTypes([
            ExternalGreetingWorkflow::class,
        ]);

        $this->postJson('/api/workflows', [
            'workflow_type' => 'ExternalGreetingWorkflow',
            'search_attributes' => [
                'Region' => 'us-east-1',
                'Priority' => 5,
                'Tags' => ['alpha', 'beta'],
            ],
        ], $this->apiHeaders())
            ->assertSuccessful();
    }

    // ── Signal / update / query name length validation ─────────────

    public function test_workflow_signal_rejects_oversized_name(): void
    {
        config(['server.limits.max_operation_name_length' => 16]);

        $longName = str_repeat('A', 64);

        $this->postJson(
            "/api/workflows/wf-any/signal/{$longName}",
            ['input' => []],
            $this->apiHeaders(),
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'validation_errors.signal_name.0',
                fn (string $msg): bool => str_contains($msg, '16'),
            );
    }

    public function test_workflow_query_rejects_oversized_name(): void
    {
        config(['server.limits.max_operation_name_length' => 16]);

        $longName = str_repeat('Q', 64);

        $this->postJson(
            "/api/workflows/wf-any/query/{$longName}",
            ['input' => []],
            $this->apiHeaders(),
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'validation_errors.query_name.0',
                fn (string $msg): bool => str_contains($msg, '16'),
            );
    }

    public function test_workflow_update_rejects_oversized_name(): void
    {
        config(['server.limits.max_operation_name_length' => 16]);

        $longName = str_repeat('U', 64);

        $this->postJson(
            "/api/workflows/wf-any/update/{$longName}",
            ['input' => []],
            $this->apiHeaders(),
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'validation_errors.update_name.0',
                fn (string $msg): bool => str_contains($msg, '16'),
            );
    }

    public function test_workflow_signal_rejects_control_characters_in_name(): void
    {
        $badName = rawurlencode("my\x01signal");

        $this->postJson(
            "/api/workflows/wf-any/signal/{$badName}",
            ['input' => []],
            $this->apiHeaders(),
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'validation_errors.signal_name.0',
                fn (string $msg): bool => str_contains($msg, 'control characters'),
            );
    }

    public function test_workflow_signal_name_validation_runs_before_workflow_lookup(): void
    {
        // Even when the workflow does not exist, the name-validation
        // failure must surface as 422 (not 404) so clients learn the
        // name itself is the problem.
        config(['server.limits.max_operation_name_length' => 8]);

        $longName = str_repeat('A', 16);

        $this->postJson(
            "/api/workflows/does-not-exist/signal/{$longName}",
            ['input' => []],
            $this->apiHeaders(),
        )
            ->assertStatus(422);
    }
}
