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
}
