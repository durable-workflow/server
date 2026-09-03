<?php

namespace Tests\Feature;

use App\Models\RuntimeCredential;
use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class RuntimeCredentialAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_TOKEN = 'server-static-admin-token';

    private const CLIENT_TOKEN = 'dwr_client_abcdefghijklmnopqrstuvwxyz0123456789';

    private const WORKER_TOKEN = 'dwr_worker_abcdefghijklmnopqrstuvwxyz0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['default', 'tenant-a', 'tenant-b'] as $namespace) {
            WorkflowNamespace::query()->updateOrCreate(
                ['name' => $namespace],
                [
                    'description' => $namespace,
                    'retention_days' => 30,
                    'status' => 'active',
                ],
            );
        }

        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => null,
            'server.auth.principal_tokens' => null,
            'server.auth.role_tokens' => [
                'worker' => null,
                'operator' => null,
                'admin' => self::ADMIN_TOKEN,
            ],
            'server.auth.runtime_credentials.enabled' => true,
            'server.auth.backward_compatible' => false,
        ]);
    }

    public function test_admin_can_idempotently_provision_a_hash_only_runtime_credential(): void
    {
        $first = $this->putCredential('client-a', self::CLIENT_TOKEN, 'operator');

        $first->assertCreated()
            ->assertJsonPath('id', 'client-a')
            ->assertJsonPath('subject', 'customer:tenant-a:operator')
            ->assertJsonPath('roles.0', 'operator')
            ->assertJsonPath('tenant', 'tenant-a')
            ->assertJsonPath('created', true)
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('token_hash');

        $this->assertDatabaseHas('runtime_credentials', [
            'id' => 'client-a',
            'token_hash' => RuntimeCredential::hashToken(self::CLIENT_TOKEN),
            'token_prefix' => RuntimeCredential::prefixFor(self::CLIENT_TOKEN),
        ]);
        $this->assertStringNotContainsString(self::CLIENT_TOKEN, $first->getContent());

        $this->putCredential('client-a', self::CLIENT_TOKEN, 'operator')
            ->assertOk()
            ->assertJsonPath('created', false);

        $this->assertDatabaseCount('runtime_credentials', 1);

        $this->putCredential('client-a', self::CLIENT_TOKEN, 'worker')
            ->assertConflict()
            ->assertJsonPath('reason', 'runtime_credential_conflict');
    }

    public function test_tenant_bound_operator_can_use_only_its_namespace_and_cannot_list_namespaces(): void
    {
        $this->putCredential('client-a', self::CLIENT_TOKEN, 'operator')->assertCreated();

        $this->withHeaders($this->controlHeaders(self::CLIENT_TOKEN, 'tenant-a'))
            ->getJson('/api/workflows')
            ->assertOk();

        $this->withHeaders($this->controlHeaders(self::CLIENT_TOKEN, 'tenant-b'))
            ->getJson('/api/workflows')
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonMissing(['reason' => 'namespace_not_found']);

        $this->withHeaders($this->controlHeaders(self::CLIENT_TOKEN, 'tenant-a'))
            ->getJson('/api/namespaces')
            ->assertForbidden();

        $this->withHeaders($this->controlHeaders(self::CLIENT_TOKEN, 'tenant-a'))
            ->getJson('/api/namespaces/tenant-a')
            ->assertOk()
            ->assertJsonPath('name', 'tenant-a');

        $this->withHeaders($this->controlHeaders(self::CLIENT_TOKEN, 'tenant-a'))
            ->getJson('/api/namespaces/tenant-b')
            ->assertForbidden()
            ->assertJsonMissing(['reason' => 'namespace_not_found']);
    }

    public function test_tenant_bound_operator_cannot_forge_a_cross_namespace_service_caller(): void
    {
        $this->putCredential('client-a', self::CLIENT_TOKEN, 'operator')->assertCreated();

        $this->withHeaders($this->controlHeaders(self::CLIENT_TOKEN, 'tenant-a'))
            ->postJson('/api/service-endpoints/missing/services/missing/operations/missing/execute', [
                'caller_namespace' => 'tenant-b',
            ])
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonMissing(['reason' => 'service_endpoint_not_found']);
    }

    public function test_tenant_bound_worker_can_register_only_in_its_namespace_and_has_no_operator_access(): void
    {
        $this->putCredential('worker-a', self::WORKER_TOKEN, 'worker')->assertCreated();

        $this->withHeaders($this->workerHeaders(self::WORKER_TOKEN, 'tenant-a'))
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'tenant-a-worker',
                'task_queue' => 'tenant-a-queue',
                'runtime' => 'php',
            ])
            ->assertCreated();

        $this->withHeaders($this->workerHeaders(self::WORKER_TOKEN, 'tenant-b'))
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'tenant-b-worker',
                'task_queue' => 'tenant-b-queue',
                'runtime' => 'php',
            ])
            ->assertForbidden();

        $this->withHeaders($this->controlHeaders(self::WORKER_TOKEN, 'tenant-a'))
            ->getJson('/api/workflows')
            ->assertForbidden()
            ->assertJsonPath('role', 'worker');
    }

    public function test_revocation_expiry_and_rotation_take_effect_without_restart(): void
    {
        $this->putCredential('client-a', self::CLIENT_TOKEN, 'operator')->assertCreated();

        $this->withHeaders($this->controlHeaders(self::CLIENT_TOKEN, 'tenant-a'))
            ->getJson('/api/workflows')
            ->assertOk();

        $this->withHeaders($this->adminHeaders())
            ->deleteJson('/api/runtime-credentials/client-a')
            ->assertOk()
            ->assertJsonPath('id', 'client-a');

        $this->withHeaders($this->controlHeaders(self::CLIENT_TOKEN, 'tenant-a'))
            ->getJson('/api/workflows')
            ->assertUnauthorized();

        $rotatedToken = 'dwr_rotated_abcdefghijklmnopqrstuvwxyz0123456789';

        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/runtime-credentials/client-a/rotate', [
                'token' => $rotatedToken,
            ])
            ->assertOk()
            ->assertJsonPath('revoked_at', null);

        $rotatedAt = RuntimeCredential::query()->findOrFail('client-a')->rotated_at;

        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/runtime-credentials/client-a/rotate', [
                'token' => $rotatedToken,
            ])
            ->assertOk();

        $this->assertTrue($rotatedAt->equalTo(RuntimeCredential::query()->findOrFail('client-a')->rotated_at));

        $this->withHeaders($this->controlHeaders($rotatedToken, 'tenant-a'))
            ->getJson('/api/workflows')
            ->assertOk();

        $expiredToken = 'dwr_expired_abcdefghijklmnopqrstuvwxyz0123456789';
        $this->putCredential('expired-a', $expiredToken, 'operator', now()->subMinute()->toIso8601String())
            ->assertCreated();

        $this->withHeaders($this->controlHeaders($expiredToken, 'tenant-a'))
            ->getJson('/api/workflows')
            ->assertUnauthorized();
    }

    public function test_static_auth_is_unchanged_when_runtime_credentials_are_disabled(): void
    {
        $this->putCredential('client-a', self::CLIENT_TOKEN, 'operator')->assertCreated();

        config(['server.auth.runtime_credentials.enabled' => false]);

        $this->withHeaders($this->controlHeaders(self::CLIENT_TOKEN, 'tenant-a'))
            ->getJson('/api/workflows')
            ->assertUnauthorized();

        $this->withHeaders($this->adminHeaders())
            ->getJson('/api/system/health')
            ->assertOk();
    }

    public function test_readiness_requires_runtime_credential_persistence_when_enabled(): void
    {
        $this->getJson('/api/ready')
            ->assertOk()
            ->assertJsonPath('checks.auth.status', 'ok')
            ->assertJsonPath('checks.auth.runtime_credentials', 'enabled');

        Schema::drop('runtime_credentials');

        $this->getJson('/api/ready')
            ->assertStatus(503)
            ->assertJsonPath('checks.auth.status', 'missing')
            ->assertJsonPath('checks.auth.runtime_credentials', 'enabled');
    }

    private function putCredential(string $id, string $token, string $role, ?string $expiresAt = null)
    {
        return $this->withHeaders($this->adminHeaders())
            ->putJson("/api/runtime-credentials/{$id}", array_filter([
                'token' => $token,
                'name' => ucfirst($role).' credential',
                'subject' => "customer:tenant-a:{$role}",
                'roles' => [$role],
                'tenant' => 'tenant-a',
                'claims' => ['environment' => 'test'],
                'expires_at' => $expiresAt,
            ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.self::ADMIN_TOKEN,
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function controlHeaders(string $token, string $namespace): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'X-Namespace' => $namespace,
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function workerHeaders(string $token, string $namespace): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'X-Namespace' => $namespace,
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ];
    }
}
