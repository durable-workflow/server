<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
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

    public function test_worker_role_can_use_worker_plane_but_not_control_plane_or_admin_plane(): void
    {
        $this->configureRoleTokens();

        $this->withHeaders($this->workerHeaders('worker-token'))
            ->postJson('/api/worker/register', [
                'worker_id' => 'worker-authz',
                'task_queue' => 'default',
                'runtime' => 'python',
            ])
            ->assertCreated();

        $this->withHeaders($this->controlHeaders('worker-token'))
            ->getJson('/api/cluster/info')
            ->assertOk();

        $this->withHeaders($this->controlHeaders('worker-token'))
            ->getJson('/api/workflows')
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonPath('role', 'worker');

        $this->withHeaders($this->controlHeaders('worker-token'))
            ->getJson('/api/system/retention')
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonPath('role', 'worker');
    }

    public function test_operator_role_can_use_control_plane_but_not_worker_or_admin_plane(): void
    {
        $this->configureRoleTokens();

        $this->withHeaders($this->controlHeaders('operator-token'))
            ->getJson('/api/workflows')
            ->assertOk();

        $this->withHeaders($this->controlHeaders('operator-token'))
            ->getJson('/api/namespaces')
            ->assertOk();

        $this->withHeaders($this->workerHeaders('operator-token'))
            ->postJson('/api/worker/register', [
                'worker_id' => 'operator-should-not-work',
                'task_queue' => 'default',
                'runtime' => 'python',
            ])
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonPath('role', 'operator');

        $this->withHeaders($this->controlHeaders('operator-token'))
            ->postJson('/api/namespaces', [
                'name' => 'operator-created',
            ])
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonPath('role', 'operator');
    }

    public function test_admin_role_can_use_admin_and_operator_planes_but_not_worker_plane(): void
    {
        $this->configureRoleTokens();

        $this->withHeaders($this->controlHeaders('admin-token'))
            ->getJson('/api/workflows')
            ->assertOk();

        $this->withHeaders($this->controlHeaders('admin-token'))
            ->getJson('/api/system/retention')
            ->assertOk();

        $this->withHeaders($this->workerHeaders('admin-token'))
            ->postJson('/api/worker/register', [
                'worker_id' => 'admin-should-not-work',
                'task_queue' => 'default',
                'runtime' => 'python',
            ])
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonPath('role', 'admin');
    }

    public function test_legacy_token_keeps_full_access_when_role_tokens_are_absent(): void
    {
        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => 'legacy-token',
            'server.auth.role_tokens' => [
                'worker' => null,
                'operator' => null,
                'admin' => null,
            ],
            'server.auth.backward_compatible' => true,
        ]);

        $this->withHeaders($this->workerHeaders('legacy-token'))
            ->postJson('/api/worker/register', [
                'worker_id' => 'legacy-worker',
                'task_queue' => 'default',
                'runtime' => 'python',
            ])
            ->assertCreated();

        $this->withHeaders($this->controlHeaders('legacy-token'))
            ->getJson('/api/system/retention')
            ->assertOk();
    }

    public function test_legacy_token_becomes_admin_scoped_when_role_tokens_are_configured(): void
    {
        $this->configureRoleTokens(legacyToken: 'legacy-token');

        $this->withHeaders($this->controlHeaders('legacy-token'))
            ->getJson('/api/system/retention')
            ->assertOk();

        $this->withHeaders($this->workerHeaders('legacy-token'))
            ->postJson('/api/worker/register', [
                'worker_id' => 'legacy-admin-not-worker',
                'task_queue' => 'default',
                'runtime' => 'python',
            ])
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonPath('role', 'admin');
    }

    // ── TD-S049: namespace existence must not leak through role-gated endpoints ──

    public function test_wrong_role_token_cannot_observe_namespace_existence_through_workflows(): void
    {
        $this->configureRoleTokens();

        // A worker-role token hitting an operator-gated endpoint gets 403 whether
        // the namespace exists or not — the namespace check must not run before
        // the role check.
        $this->withHeaders($this->controlHeaders('worker-token', 'default'))
            ->getJson('/api/workflows')
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonMissing(['reason' => 'namespace_not_found']);

        $this->withHeaders($this->controlHeaders('worker-token', 'ghost-namespace'))
            ->getJson('/api/workflows')
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonMissing(['reason' => 'namespace_not_found']);
    }

    public function test_wrong_role_token_cannot_observe_namespace_existence_through_worker_register(): void
    {
        $this->configureRoleTokens();

        // An operator-role token hitting a worker-gated endpoint gets 403 whether
        // the namespace exists or not.
        $this->withHeaders($this->workerHeadersFor('operator-token', 'default'))
            ->postJson('/api/worker/register', [
                'worker_id' => 'w-probe',
                'task_queue' => 'default',
                'runtime' => 'python',
            ])
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonMissing(['reason' => 'namespace_not_found']);

        $this->withHeaders($this->workerHeadersFor('operator-token', 'ghost-namespace'))
            ->postJson('/api/worker/register', [
                'worker_id' => 'w-probe',
                'task_queue' => 'default',
                'runtime' => 'python',
            ])
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonMissing(['reason' => 'namespace_not_found']);
    }

    public function test_wrong_role_token_cannot_observe_namespace_existence_through_system_routes(): void
    {
        $this->configureRoleTokens();

        // An operator token hitting admin-only /system/* gets 403 for any namespace.
        $this->withHeaders($this->controlHeaders('operator-token', 'default'))
            ->getJson('/api/system/retention')
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonMissing(['reason' => 'namespace_not_found']);

        $this->withHeaders($this->controlHeaders('operator-token', 'ghost-namespace'))
            ->getJson('/api/system/retention')
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonMissing(['reason' => 'namespace_not_found']);
    }

    public function test_signature_role_keys_enforce_role_boundaries_without_legacy_key(): void
    {
        config([
            'server.auth.driver' => 'signature',
            'server.auth.signature_key' => null,
            'server.auth.role_signature_keys' => [
                'worker' => 'worker-signature-key',
                'operator' => 'operator-signature-key',
                'admin' => 'admin-signature-key',
            ],
            'server.auth.backward_compatible' => true,
        ]);

        $this->withHeaders($this->signedHeaders('operator-signature-key', controlPlane: true))
            ->get('/api/workflows')
            ->assertOk();

        $this->withHeaders($this->signedHeaders('worker-signature-key', controlPlane: true))
            ->get('/api/workflows')
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonPath('role', 'worker');
    }

    private function configureRoleTokens(?string $legacyToken = null): void
    {
        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => $legacyToken,
            'server.auth.role_tokens' => [
                'worker' => 'worker-token',
                'operator' => 'operator-token',
                'admin' => 'admin-token',
            ],
            'server.auth.backward_compatible' => true,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function workerHeaders(string $token): array
    {
        return $this->workerHeadersFor($token, 'default');
    }

    /**
     * @return array<string, string>
     */
    private function workerHeadersFor(string $token, string $namespace): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'X-Namespace' => $namespace,
            'X-Durable-Workflow-Protocol-Version' => '1.0',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function controlHeaders(string $token, string $namespace = 'default'): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function signedHeaders(string $key, bool $controlPlane = false): array
    {
        $headers = [
            'Accept' => 'application/json',
            'X-Signature' => hash_hmac('sha256', '', $key),
            'X-Namespace' => 'default',
        ];

        if ($controlPlane) {
            $headers['X-Durable-Workflow-Control-Plane-Version'] = '2';
        }

        return $headers;
    }
}
