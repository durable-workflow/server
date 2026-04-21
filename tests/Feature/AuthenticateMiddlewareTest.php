<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\HeaderAuthProvider;
use Tests\TestCase;

class AuthenticateMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            [
                'description' => 'Default namespace',
                'retention_days' => 30,
                'status' => 'active',
            ],
        );
    }

    // ── Driver: none ────────────────────────────────────────────────

    public function test_none_driver_allows_unauthenticated_requests(): void
    {
        config(['server.auth.driver' => 'none']);

        $this->getJson('/api/cluster/info')
            ->assertOk();
    }

    public function test_none_driver_ignores_bearer_token(): void
    {
        config(['server.auth.driver' => 'none']);

        $this->withHeaders(['Authorization' => 'Bearer any-random-token'])
            ->getJson('/api/cluster/info')
            ->assertOk();
    }

    // ── Driver: token ───────────────────────────────────────────────

    public function test_token_driver_accepts_valid_token(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'test-secret-token']);

        $this->withHeaders(['Authorization' => 'Bearer test-secret-token'])
            ->getJson('/api/cluster/info')
            ->assertOk();
    }

    public function test_token_driver_rejects_missing_token(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'test-secret-token']);

        $this->getJson('/api/cluster/info')
            ->assertUnauthorized()
            ->assertJson(['reason' => 'unauthorized']);
    }

    public function test_token_driver_rejects_wrong_token(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'test-secret-token']);

        $this->withHeaders(['Authorization' => 'Bearer wrong-token'])
            ->getJson('/api/cluster/info')
            ->assertUnauthorized()
            ->assertJson(['reason' => 'unauthorized']);
    }

    public function test_token_driver_rejects_empty_authorization_header(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'test-secret-token']);

        $this->withHeaders(['Authorization' => ''])
            ->getJson('/api/cluster/info')
            ->assertUnauthorized();
    }

    public function test_token_driver_returns_500_when_token_not_configured(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => null]);

        $this->withHeaders(['Authorization' => 'Bearer anything'])
            ->getJson('/api/cluster/info')
            ->assertStatus(500);
    }

    public function test_token_driver_returns_500_when_token_is_empty_string(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => '']);

        $this->withHeaders(['Authorization' => 'Bearer anything'])
            ->getJson('/api/cluster/info')
            ->assertStatus(500);
    }

    // ── Driver: signature ───────────────────────────────────────────

    public function test_signature_driver_accepts_valid_signature_on_post(): void
    {
        $key = 'test-signature-key';
        config(['server.auth.driver' => 'signature', 'server.auth.signature_key' => $key]);

        $body = json_encode(['name' => 'sig-test-ns', 'description' => 'Test']);
        $signature = hash_hmac('sha256', $body, $key);

        $response = $this->call(
            'POST',
            '/api/namespaces',
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => $signature,
            ],
            $body,
        );

        // Auth should pass (we may get 201 or 422 depending on validation,
        // but not 401)
        $this->assertNotEquals(401, $response->status());
        $this->assertNotEquals(500, $response->status());
    }

    public function test_signature_driver_rejects_missing_signature(): void
    {
        config(['server.auth.driver' => 'signature', 'server.auth.signature_key' => 'test-key']);

        $this->getJson('/api/cluster/info')
            ->assertUnauthorized();
    }

    public function test_signature_driver_rejects_wrong_signature(): void
    {
        config(['server.auth.driver' => 'signature', 'server.auth.signature_key' => 'test-key']);

        $this->withHeaders(['X-Signature' => 'invalid-signature-value'])
            ->getJson('/api/cluster/info')
            ->assertUnauthorized();
    }

    public function test_signature_driver_returns_500_when_key_not_configured(): void
    {
        config(['server.auth.driver' => 'signature', 'server.auth.signature_key' => null]);

        $this->withHeaders(['X-Signature' => 'anything'])
            ->getJson('/api/cluster/info')
            ->assertStatus(500);
    }

    public function test_signature_driver_returns_500_when_key_is_empty_string(): void
    {
        config(['server.auth.driver' => 'signature', 'server.auth.signature_key' => '']);

        $this->withHeaders(['X-Signature' => 'anything'])
            ->getJson('/api/cluster/info')
            ->assertStatus(500);
    }

    // ── JSON error body without Accept header ─────────────────────────

    public function test_token_rejection_returns_json_without_accept_header(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'test-secret-token']);

        $response = $this->get('/api/cluster/info');

        $response->assertUnauthorized();
        $response->assertHeader('content-type', 'application/json');
        $response->assertJson([
            'reason' => 'unauthorized',
            'message' => 'Invalid or missing authentication token.',
        ]);
    }

    public function test_signature_rejection_returns_json_without_accept_header(): void
    {
        config(['server.auth.driver' => 'signature', 'server.auth.signature_key' => 'test-key']);

        $response = $this->get('/api/cluster/info');

        $response->assertUnauthorized();
        $response->assertHeader('content-type', 'application/json');
        $response->assertJson([
            'reason' => 'unauthorized',
            'message' => 'Missing request signature.',
        ]);
    }

    // ── Unknown driver ──────────────────────────────────────────────

    public function test_unknown_driver_returns_500(): void
    {
        config(['server.auth.driver' => 'kerberos']);

        $this->getJson('/api/cluster/info')
            ->assertStatus(500);
    }

    // ── Custom providers ────────────────────────────────────────────

    public function test_custom_auth_provider_can_be_configured_without_replacing_middleware(): void
    {
        config([
            'server.auth.provider' => HeaderAuthProvider::class,
            'server.auth.driver' => 'token',
            'server.auth.token' => null,
        ]);

        $this->withHeaders($this->customProviderHeaders('operator'))
            ->getJson('/api/workflows')
            ->assertOk();

        $this->withHeaders($this->customProviderHeaders('worker'))
            ->getJson('/api/workflows')
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonPath('role', 'worker')
            ->assertJsonPath('allowed_roles', ['operator', 'admin']);
    }

    public function test_custom_auth_provider_can_return_protocol_json_auth_errors(): void
    {
        config(['server.auth.provider' => HeaderAuthProvider::class]);

        $this->getJson('/api/cluster/info')
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'unauthorized')
            ->assertJsonPath('message', 'Missing test principal.');
    }

    // ── Health endpoint bypasses auth ───────────────────────────────

    public function test_health_endpoint_is_not_behind_auth(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'secret']);

        $this->getJson('/api/health')
            ->assertOk();
    }

    // ── Control-plane protocol enrichment on auth errors ─────────────

    public function test_auth_error_on_workflow_endpoint_includes_control_plane_header_and_metadata(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'secret']);

        $this->withHeaders([
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ])->postJson('/api/workflows/wf-auth-test/signal/my-signal', [
            'input' => ['test'],
        ])->assertUnauthorized()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('reason', 'unauthorized')
            ->assertJsonPath('control_plane.operation', 'signal')
            ->assertJsonPath('control_plane.operation_name', 'my-signal')
            ->assertJsonPath('control_plane.workflow_id', 'wf-auth-test');
    }

    public function test_auth_error_on_non_workflow_endpoint_includes_control_plane_header(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'secret']);

        $this->getJson('/api/cluster/info')
            ->assertUnauthorized()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('reason', 'unauthorized');
    }

    // ── Auth applies across endpoint types ──────────────────────────

    public function test_token_auth_applies_to_workflow_endpoints(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'secret']);

        $this->getJson('/api/workflows')
            ->assertUnauthorized();

        $this->withHeaders(['Authorization' => 'Bearer secret', 'X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2'])
            ->getJson('/api/workflows')
            ->assertOk();
    }

    public function test_token_auth_applies_to_namespace_endpoints(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'secret']);

        $this->withHeaders($this->controlPlaneHeaders())
            ->getJson('/api/namespaces')
            ->assertUnauthorized();

        $this->withHeaders($this->controlPlaneHeaders(['Authorization' => 'Bearer secret']))
            ->getJson('/api/namespaces')
            ->assertOk();
    }

    public function test_token_auth_applies_to_schedule_endpoints(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'secret']);

        $this->withHeaders($this->controlPlaneHeaders(['X-Namespace' => 'default']))
            ->getJson('/api/schedules')
            ->assertUnauthorized();

        $this->withHeaders($this->controlPlaneHeaders([
            'Authorization' => 'Bearer secret',
            'X-Namespace' => 'default',
        ]))
            ->getJson('/api/schedules')
            ->assertOk();
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function controlPlaneHeaders(array $extra = []): array
    {
        return ['X-Durable-Workflow-Control-Plane-Version' => '2'] + $extra;
    }

    /**
     * @return array<string, string>
     */
    private function customProviderHeaders(string $roles): array
    {
        return $this->controlPlaneHeaders([
            'X-Namespace' => 'default',
            'X-Test-Subject' => 'user-123',
            'X-Test-Roles' => $roles,
            'X-Test-Tenant' => 'tenant-a',
        ]);
    }
}
