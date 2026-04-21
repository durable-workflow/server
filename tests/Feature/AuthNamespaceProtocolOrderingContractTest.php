<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ResourceAwareAuthProvider;
use Tests\TestCase;

class AuthNamespaceProtocolOrderingContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        WorkflowNamespace::query()->firstOrCreate(
            ['name' => 'default'],
            ['description' => 'Default namespace', 'retention_days' => 30, 'status' => 'active'],
        );
    }

    /**
     * @return array<string, array{
     *     plane: string,
     *     method: string,
     *     path: string,
     *     body: array<string, mixed>,
     *     headers: array<string, string>
     * }>
     */
    public static function authenticationFailureProvider(): array
    {
        return [
            'control-plane missing token beats missing version and unknown namespace' => [
                'plane' => 'control',
                'method' => 'post',
                'path' => '/api/workflows/wf-auth-order/signal/advance',
                'body' => ['input' => ['go']],
                'headers' => [
                    'X-Namespace' => 'ghost-namespace',
                    WorkerProtocol::HEADER => WorkerProtocol::VERSION,
                ],
            ],
            'control-plane bad token beats unsupported version and unknown namespace' => [
                'plane' => 'control',
                'method' => 'get',
                'path' => '/api/system/retention',
                'body' => [],
                'headers' => [
                    'Authorization' => 'Bearer wrong-token',
                    'X-Namespace' => 'ghost-namespace',
                    ControlPlaneProtocol::HEADER => '999',
                    WorkerProtocol::HEADER => WorkerProtocol::VERSION,
                ],
            ],
            'worker-plane missing token beats missing version and unknown namespace' => [
                'plane' => 'worker',
                'method' => 'post',
                'path' => '/api/worker/register',
                'body' => [
                    'worker_id' => 'worker-auth-order',
                    'task_queue' => 'default',
                    'runtime' => 'python',
                ],
                'headers' => [
                    'X-Namespace' => 'ghost-namespace',
                    ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
                ],
            ],
            'worker-plane bad token beats unsupported version and unknown namespace' => [
                'plane' => 'worker',
                'method' => 'post',
                'path' => '/api/worker/workflow-tasks/poll',
                'body' => [
                    'worker_id' => 'worker-auth-order',
                    'task_queue' => 'default',
                ],
                'headers' => [
                    'Authorization' => 'Bearer wrong-token',
                    'X-Namespace' => 'ghost-namespace',
                    WorkerProtocol::HEADER => '999',
                    ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
                ],
            ],
        ];
    }

    /**
     * Authentication runs before protocol-version and namespace resolution.
     * That keeps unauthenticated callers from learning namespace existence or
     * chasing protocol errors before credentials are valid.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    #[DataProvider('authenticationFailureProvider')]
    public function test_authentication_failures_keep_plane_envelope_before_version_and_namespace_checks(
        string $plane,
        string $method,
        string $path,
        array $body,
        array $headers,
    ): void {
        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => 'correct-token',
            'server.auth.role_tokens' => [
                'worker' => null,
                'operator' => null,
                'admin' => null,
            ],
            'server.auth.backward_compatible' => true,
        ]);

        $response = $this->sendJson($method, $path, $body, $headers);

        $response->assertUnauthorized()
            ->assertJsonPath('reason', 'unauthorized')
            ->assertJsonPath('message', 'Invalid or missing authentication token.');

        $this->assertPlaneEnvelope($response, $plane);
        $this->assertNoProtocolOrNamespaceReasonLeaked($response);
    }

    /**
     * @return array<string, array{
     *     plane: string,
     *     token: string,
     *     method: string,
     *     path: string,
     *     body: array<string, mixed>,
     *     headers: array<string, string>,
     *     role: string,
     *     allowedRoles: list<string>
     * }>
     */
    public static function authorizationFailureProvider(): array
    {
        return [
            'control-plane worker role beats missing version and unknown namespace' => [
                'plane' => 'control',
                'token' => 'worker-token',
                'method' => 'get',
                'path' => '/api/workflows',
                'body' => [],
                'headers' => [
                    'X-Namespace' => 'ghost-namespace',
                    WorkerProtocol::HEADER => WorkerProtocol::VERSION,
                ],
                'role' => 'worker',
                'allowedRoles' => ['operator', 'admin'],
            ],
            'control-plane operator role beats unsupported version and unknown namespace' => [
                'plane' => 'control',
                'token' => 'operator-token',
                'method' => 'post',
                'path' => '/api/system/repair/pass',
                'body' => [],
                'headers' => [
                    'X-Namespace' => 'ghost-namespace',
                    ControlPlaneProtocol::HEADER => '999',
                    WorkerProtocol::HEADER => WorkerProtocol::VERSION,
                ],
                'role' => 'operator',
                'allowedRoles' => ['admin'],
            ],
            'worker-plane operator role beats missing version and unknown namespace' => [
                'plane' => 'worker',
                'token' => 'operator-token',
                'method' => 'post',
                'path' => '/api/worker/register',
                'body' => [
                    'worker_id' => 'worker-role-order',
                    'task_queue' => 'default',
                    'runtime' => 'python',
                ],
                'headers' => [
                    'X-Namespace' => 'ghost-namespace',
                    ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
                ],
                'role' => 'operator',
                'allowedRoles' => ['worker'],
            ],
            'worker-plane admin role beats unsupported version and unknown namespace' => [
                'plane' => 'worker',
                'token' => 'admin-token',
                'method' => 'post',
                'path' => '/api/worker/activity-tasks/poll',
                'body' => [
                    'worker_id' => 'worker-role-order',
                    'task_queue' => 'default',
                ],
                'headers' => [
                    'X-Namespace' => 'ghost-namespace',
                    WorkerProtocol::HEADER => '999',
                    ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
                ],
                'role' => 'admin',
                'allowedRoles' => ['worker'],
            ],
        ];
    }

    /**
     * Role checks run before protocol-version and namespace resolution once
     * credentials are known. Wrong-role callers should get a stable 403 in the
     * endpoint plane, without namespace existence or version-skew details.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @param  list<string>  $allowedRoles
     */
    #[DataProvider('authorizationFailureProvider')]
    public function test_authorization_failures_keep_plane_envelope_before_version_and_namespace_checks(
        string $plane,
        string $token,
        string $method,
        string $path,
        array $body,
        array $headers,
        string $role,
        array $allowedRoles,
    ): void {
        $this->configureRoleTokens();

        $response = $this->sendJson($method, $path, $body, [
            'Authorization' => "Bearer {$token}",
        ] + $headers);

        $response->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonPath('role', $role)
            ->assertJsonPath('allowed_roles', $allowedRoles);

        $this->assertPlaneEnvelope($response, $plane);
        $this->assertNoProtocolOrNamespaceReasonLeaked($response);
    }

    /**
     * @return array<string, array{
     *     plane: string,
     *     token: string,
     *     method: string,
     *     path: string,
     *     body: array<string, mixed>,
     *     headers: array<string, string>,
     *     expectedReason: string
     * }>
     */
    public static function authenticatedProtocolFailureProvider(): array
    {
        return [
            'control-plane version beats unknown namespace even with worker header' => [
                'plane' => 'control',
                'token' => 'operator-token',
                'method' => 'get',
                'path' => '/api/workflows',
                'body' => [],
                'headers' => [
                    'X-Namespace' => 'ghost-namespace',
                    WorkerProtocol::HEADER => WorkerProtocol::VERSION,
                ],
                'expectedReason' => 'missing_control_plane_version',
            ],
            'worker protocol version beats unknown namespace even with control-plane header' => [
                'plane' => 'worker',
                'token' => 'worker-token',
                'method' => 'post',
                'path' => '/api/worker/workflow-tasks/poll',
                'body' => [
                    'worker_id' => 'worker-protocol-order',
                    'task_queue' => 'default',
                ],
                'headers' => [
                    'X-Namespace' => 'ghost-namespace',
                    ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
                ],
                'expectedReason' => 'missing_protocol_version',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    #[DataProvider('authenticatedProtocolFailureProvider')]
    public function test_protocol_failures_keep_plane_envelope_before_namespace_checks_for_authenticated_callers(
        string $plane,
        string $token,
        string $method,
        string $path,
        array $body,
        array $headers,
        string $expectedReason,
    ): void {
        $this->configureRoleTokens();

        $response = $this->sendJson($method, $path, $body, [
            'Authorization' => "Bearer {$token}",
        ] + $headers);

        $response->assertStatus(400)
            ->assertJsonPath('reason', $expectedReason);

        $this->assertPlaneEnvelope($response, $plane);
        $response->assertJsonMissing(['reason' => 'namespace_not_found']);
    }

    /**
     * @return array<string, array{
     *     plane: string,
     *     token: string,
     *     method: string,
     *     path: string,
     *     body: array<string, mixed>,
     *     headers: array<string, string>
     * }>
     */
    public static function authenticatedNamespaceFailureProvider(): array
    {
        return [
            'control-plane namespace error keeps control envelope with worker header present' => [
                'plane' => 'control',
                'token' => 'operator-token',
                'method' => 'get',
                'path' => '/api/workflows',
                'body' => [],
                'headers' => [
                    'X-Namespace' => 'ghost-namespace',
                    ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
                    WorkerProtocol::HEADER => WorkerProtocol::VERSION,
                ],
            ],
            'worker-plane namespace error keeps worker envelope with control header present' => [
                'plane' => 'worker',
                'token' => 'worker-token',
                'method' => 'post',
                'path' => '/api/worker/workflow-tasks/poll',
                'body' => [
                    'worker_id' => 'worker-namespace-order',
                    'task_queue' => 'default',
                ],
                'headers' => [
                    'X-Namespace' => 'ghost-namespace',
                    WorkerProtocol::HEADER => WorkerProtocol::VERSION,
                    ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    #[DataProvider('authenticatedNamespaceFailureProvider')]
    public function test_namespace_failures_keep_plane_envelope_after_auth_and_protocol_checks(
        string $plane,
        string $token,
        string $method,
        string $path,
        array $body,
        array $headers,
    ): void {
        $this->configureRoleTokens();

        $response = $this->sendJson($method, $path, $body, [
            'Authorization' => "Bearer {$token}",
        ] + $headers);

        $response->assertNotFound()
            ->assertJsonPath('reason', 'namespace_not_found')
            ->assertJsonPath('namespace', 'ghost-namespace');

        $this->assertPlaneEnvelope($response, $plane);
    }

    public function test_custom_provider_can_authorize_by_requested_namespace_before_namespace_resolution(): void
    {
        ResourceAwareAuthProvider::reset();

        config(['server.auth.provider' => ResourceAwareAuthProvider::class]);

        $response = $this->withHeaders([
            'X-Test-Subject' => 'tenant-operator',
            'X-Test-Roles' => 'operator',
            'X-Test-Allow-Namespace' => 'tenant-a',
            'X-Namespace' => 'ghost-namespace',
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ])->getJson('/api/workflows');

        $response->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonMissing(['reason' => 'namespace_not_found']);

        $this->assertSame('server.route.access', ResourceAwareAuthProvider::$lastAction);
        $this->assertSame('ghost-namespace', ResourceAwareAuthProvider::$lastResource['requested_namespace'] ?? null);
        $this->assertSame('ghost-namespace', ResourceAwareAuthProvider::$lastResource['namespace'] ?? null);
        $this->assertSame('default', ResourceAwareAuthProvider::$lastResource['default_namespace'] ?? null);
        $this->assertSame('workflow', ResourceAwareAuthProvider::$lastResource['operation_family'] ?? null);
        $this->assertSame('list', ResourceAwareAuthProvider::$lastResource['operation_name'] ?? null);
    }

    public function test_custom_provider_can_authorize_by_workflow_command_resource_without_path_parsing(): void
    {
        ResourceAwareAuthProvider::reset();

        config(['server.auth.provider' => ResourceAwareAuthProvider::class]);

        $response = $this->withHeaders([
            'X-Test-Subject' => 'workflow-operator',
            'X-Test-Roles' => 'operator',
            'X-Test-Deny-Operation-Family' => 'workflow',
            'X-Test-Deny-Operation-Name' => 'signal',
            'X-Test-Deny-Workflow-Id' => 'wf-secret',
            'X-Namespace' => 'default',
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ])->postJson('/api/workflows/wf-secret/signal/advance', [
            'input' => ['approved' => true],
        ]);

        $response->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonMissing(['reason' => 'instance_not_found']);

        $resource = ResourceAwareAuthProvider::$lastResource;

        $this->assertSame('workflow', $resource['operation_family'] ?? null);
        $this->assertSame('signal', $resource['operation_name'] ?? null);
        $this->assertSame('wf-secret', $resource['workflow_id'] ?? null);
        $this->assertSame('advance', $resource['signal_name'] ?? null);
        $this->assertSame([
            'workflow_id' => 'wf-secret',
            'signal_name' => 'advance',
        ], $resource['route_parameters'] ?? null);
    }

    public function test_custom_provider_can_authorize_worker_task_queue_before_namespace_resolution(): void
    {
        ResourceAwareAuthProvider::reset();

        config(['server.auth.provider' => ResourceAwareAuthProvider::class]);

        $response = $this->withHeaders([
            'X-Test-Subject' => 'queue-worker',
            'X-Test-Roles' => 'worker',
            'X-Test-Deny-Task-Queue' => 'restricted-queue',
            'X-Namespace' => 'ghost-namespace',
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ])->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-authz',
            'task_queue' => 'restricted-queue',
        ]);

        $response->assertForbidden()
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonMissing(['reason' => 'namespace_not_found']);

        $this->assertPlaneEnvelope($response, 'worker');

        $resource = ResourceAwareAuthProvider::$lastResource;

        $this->assertSame('ghost-namespace', $resource['requested_namespace'] ?? null);
        $this->assertSame('worker', $resource['operation_family'] ?? null);
        $this->assertSame('poll_workflow_tasks', $resource['operation_name'] ?? null);
        $this->assertSame('worker-authz', $resource['worker_id'] ?? null);
        $this->assertSame('restricted-queue', $resource['task_queue'] ?? null);
    }

    private function configureRoleTokens(): void
    {
        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => null,
            'server.auth.role_tokens' => [
                'worker' => 'worker-token',
                'operator' => 'operator-token',
                'admin' => 'admin-token',
            ],
            'server.auth.backward_compatible' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function sendJson(string $method, string $path, array $body, array $headers): TestResponse
    {
        return match ($method) {
            'get' => $this->getJson($path, $headers),
            'post' => $this->postJson($path, $body, $headers),
            default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };
    }

    private function assertPlaneEnvelope(TestResponse $response, string $plane): void
    {
        if ($plane === 'worker') {
            $response->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
                ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
                ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
                ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
                ->assertJsonMissingPath('control_plane');

            return;
        }

        $response->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertHeaderMissing(WorkerProtocol::HEADER)
            ->assertJsonMissingPath('protocol_version')
            ->assertJsonMissingPath('server_capabilities');
    }

    private function assertNoProtocolOrNamespaceReasonLeaked(TestResponse $response): void
    {
        $response->assertJsonMissing(['reason' => 'namespace_not_found'])
            ->assertJsonMissing(['reason' => 'missing_protocol_version'])
            ->assertJsonMissing(['reason' => 'unsupported_protocol_version'])
            ->assertJsonMissing(['reason' => 'missing_control_plane_version'])
            ->assertJsonMissing(['reason' => 'unsupported_control_plane_version']);
    }
}
