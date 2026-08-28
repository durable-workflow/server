<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\WorkerProtocol;
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
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
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

    public function test_operator_role_can_use_control_plane_and_register_diagnostic_workers_but_not_polling_plane(): void
    {
        $this->configureRoleTokens();

        $this->withHeaders($this->controlHeaders('operator-token'))
            ->getJson('/api/workflows')
            ->assertOk();

        $this->withHeaders($this->controlHeaders('operator-token'))
            ->getJson('/api/namespaces')
            ->assertOk();

        $this->withHeaders($this->workerHeaders('worker-token'))
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'sdk-worker-v1',
                'task_queue' => 'default',
                'runtime' => 'python',
                'build_id' => 'build-v1',
            ])
            ->assertCreated();

        $this->withHeaders($this->workerHeaders('operator-token'))
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'operator-diagnostic-worker',
                'task_queue' => 'default',
                'runtime' => 'external',
                'build_id' => 'build-v2',
            ])
            ->assertCreated()
            ->assertJsonPath('worker_id', 'operator-diagnostic-worker')
            ->assertJsonPath('runtime', 'external')
            ->assertJsonPath('build_id', 'build-v2');

        $workers = $this->withHeaders($this->controlHeaders('operator-token'))
            ->getJson('/api/workers?task_queue=default');

        $workers->assertOk()
            ->assertJsonCount(2, 'workers');

        $diagnostic = collect($workers->json('workers'))->firstWhere('worker_id', 'operator-diagnostic-worker');
        self::assertSame('build-v2', $diagnostic['build_id'] ?? null);

        $buildIds = $this->withHeaders($this->controlHeaders('operator-token'))
            ->getJson('/api/task-queues/default/build-ids');

        $buildIds->assertOk()
            ->assertJsonCount(2, 'build_ids');

        $byBuild = collect($buildIds->json('build_ids'))->keyBy('build_id');
        $v1 = $byBuild->get('build-v1');
        $v2 = $byBuild->get('build-v2');
        self::assertIsArray($v1);
        self::assertIsArray($v2);
        self::assertSame('active', $v1['rollout_status'] ?? null);
        self::assertSame('active', $v2['rollout_status'] ?? null);
        self::assertSame(1, $v2['active_worker_count'] ?? null);

        $this->withHeaders($this->workerHeaders('operator-token'))
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'operator-diagnostic-worker',
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

    public function test_admin_role_can_use_admin_and_operator_planes_and_register_diagnostic_workers(): void
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
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'admin-diagnostic-worker',
                'task_queue' => 'default',
                'runtime' => 'external',
                'build_id' => 'build-v2',
            ])
            ->assertCreated()
            ->assertJsonPath('worker_id', 'admin-diagnostic-worker')
            ->assertJsonPath('runtime', 'external')
            ->assertJsonPath('build_id', 'build-v2');

        $this->withHeaders($this->workerHeaders('admin-token'))
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'admin-diagnostic-worker',
            ])
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonPath('role', 'admin');
    }

    public function test_only_admin_can_change_namespace_retention_to_forever(): void
    {
        $this->configureRoleTokens();

        $this->withHeaders($this->controlHeaders('operator-token'))
            ->putJson('/api/namespaces/default', [
                'retention_mode' => 'forever',
            ])
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonPath('role', 'operator');

        $this->assertDatabaseHas('workflow_namespaces', [
            'name' => 'default',
            'retention_mode' => 'bounded',
            'retention_days' => 30,
        ]);

        $this->withHeaders($this->controlHeaders('admin-token'))
            ->putJson('/api/namespaces/default', [
                'retention_mode' => 'forever',
            ])
            ->assertOk()
            ->assertJsonPath('retention_mode', 'forever')
            ->assertJsonPath('retention_days', null);
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
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
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
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'legacy-admin-diagnostic',
                'task_queue' => 'default',
                'runtime' => 'external',
                'build_id' => 'build-v2',
            ])
            ->assertCreated();

        $this->withHeaders($this->workerHeaders('legacy-token'))
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'legacy-admin-diagnostic',
            ])
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonPath('role', 'admin');
    }

    public function test_legacy_token_becomes_admin_scoped_when_principal_tokens_are_configured(): void
    {
        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => 'legacy-token',
            'server.auth.role_tokens' => [
                'worker' => null,
                'operator' => null,
                'admin' => null,
            ],
            'server.auth.principal_tokens' => json_encode([
                [
                    'token' => 'alice-token',
                    'subject' => 'alice',
                    'roles' => ['operator'],
                ],
            ]),
            'server.auth.backward_compatible' => true,
        ]);

        $this->withHeaders($this->controlHeaders('alice-token'))
            ->getJson('/api/workflows')
            ->assertOk();

        $this->withHeaders($this->workerHeaders('alice-token'))
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'alice-worker',
            ])
            ->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonPath('role', 'operator');

        $this->withHeaders($this->controlHeaders('legacy-token'))
            ->getJson('/api/system/retention')
            ->assertOk();

        $this->withHeaders($this->workerHeaders('legacy-token'))
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'legacy-admin-diagnostic',
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

    public function test_operator_diagnostic_worker_register_reports_namespace_errors_after_authorization(): void
    {
        $this->configureRoleTokens();

        $this->withHeaders($this->workerHeadersFor('operator-token', 'default'))
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'w-probe',
                'task_queue' => 'default',
                'runtime' => 'python',
            ])
            ->assertCreated();

        $this->withHeaders($this->workerHeadersFor('operator-token', 'ghost-namespace'))
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'w-probe',
                'task_queue' => 'default',
                'runtime' => 'python',
            ])
            ->assertNotFound()
            ->assertJsonPath('reason', 'namespace_not_found')
            ->assertJsonPath('namespace', 'ghost-namespace');
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
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
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
