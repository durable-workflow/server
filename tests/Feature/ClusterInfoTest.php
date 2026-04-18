<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClusterInfoTest extends TestCase
{
    use RefreshDatabase;

    private ?string $provenanceFixturePath = null;

    protected function tearDown(): void
    {
        if ($this->provenanceFixturePath !== null && is_file($this->provenanceFixturePath)) {
            @unlink($this->provenanceFixturePath);
        }

        $this->provenanceFixturePath = null;

        parent::tearDown();
    }

    /**
     * Allocate a per-test provenance fixture outside the repo root, point
     * server.package_provenance_path at it, and write the supplied lines.
     * tearDown() removes the fixture.
     *
     * @param  array<int, string>  $lines
     */
    private function useProvenanceFixture(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dw-provenance-');

        if ($path === false) {
            $this->fail('Could not allocate a tempfile for the provenance fixture.');
        }

        file_put_contents($path, implode("\n", $lines));

        config(['server.package_provenance_path' => $path]);

        return $this->provenanceFixturePath = $path;
    }

    public function test_it_publishes_a_versioned_control_plane_request_contract_manifest(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath(
                'control_plane.request_contract.schema',
                'durable-workflow.v2.control-plane-request.contract',
            )
            ->assertJsonPath('control_plane.request_contract.version', 1)
            ->assertJsonPath(
                'control_plane.request_contract.operations.start.fields.duplicate_policy.canonical_values.1',
                'use-existing',
            )
            ->assertJsonPath(
                'control_plane.request_contract.operations.update.removed_fields.wait_policy',
                'Use wait_for.',
            );
    }

    public function test_it_advertises_response_compression_in_capabilities(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('capabilities.response_compression', ['gzip', 'deflate']);
    }

    public function test_it_advertises_response_compression_in_worker_protocol(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('worker_protocol.server_capabilities.response_compression', ['gzip', 'deflate']);
    }

    public function test_it_advertises_worker_command_option_capabilities_in_worker_protocol(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('worker_protocol.server_capabilities.activity_retry_policy', true)
            ->assertJsonPath('worker_protocol.server_capabilities.activity_timeouts', true)
            ->assertJsonPath('worker_protocol.server_capabilities.child_workflow_retry_policy', true)
            ->assertJsonPath('worker_protocol.server_capabilities.child_workflow_timeouts', true)
            ->assertJsonPath('worker_protocol.server_capabilities.parent_close_policy', true)
            ->assertJsonPath('worker_protocol.server_capabilities.non_retryable_failures', true);
    }

    public function test_it_advertises_empty_compression_when_disabled(): void
    {
        config(['server.compression.enabled' => false]);

        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('capabilities.response_compression', [])
            ->assertJsonPath('worker_protocol.server_capabilities.response_compression', []);
    }

    public function test_it_omits_package_provenance_when_the_provenance_file_does_not_exist(): void
    {
        // Point at a guaranteed-missing location so the controller exercises
        // the "file not present" branch regardless of repo-root state.
        $missingPath = sys_get_temp_dir().'/dw-provenance-missing-'.bin2hex(random_bytes(6));
        config([
            'server.expose_package_provenance' => true,
            'server.package_provenance_path' => $missingPath,
        ]);

        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonMissing(['package_provenance']);
    }

    public function test_it_omits_package_provenance_by_default_even_when_file_exists(): void
    {
        $this->useProvenanceFixture([
            'https://github.com/durable-workflow/workflow.git',
            'v2',
            'abc123def456',
        ]);

        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonMissing(['package_provenance']);
    }

    public function test_it_advertises_only_universal_payload_codecs_publicly(): void
    {
        $response = $this->getJson('/api/cluster/info')->assertOk();

        $this->assertSame(['avro'], $response->json('capabilities.payload_codecs'));
        $this->assertSame(
            ['workflow-serializer-y', 'workflow-serializer-base64'],
            $response->json('capabilities.payload_codecs_engine_specific.php'),
        );
    }

    public function test_it_rejects_requests_when_token_auth_is_enabled_but_token_is_not_configured(): void
    {
        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => null,
        ]);

        $this->getJson('/api/cluster/info')
            ->assertStatus(500)
            ->assertSee('WORKFLOW_SERVER_AUTH_TOKEN is not configured');
    }

    public function test_it_rejects_requests_when_signature_auth_is_enabled_but_key_is_not_configured(): void
    {
        config([
            'server.auth.driver' => 'signature',
            'server.auth.signature_key' => null,
        ]);

        $this->getJson('/api/cluster/info')
            ->assertStatus(500)
            ->assertSee('WORKFLOW_SERVER_SIGNATURE_KEY is not configured');
    }

    public function test_it_includes_structural_limits_from_the_package(): void
    {
        $response = $this->getJson('/api/cluster/info');

        $response->assertOk()
            ->assertJsonStructure([
                'structural_limits' => [
                    'pending_activity_count',
                    'pending_child_count',
                    'pending_timer_count',
                    'pending_signal_count',
                    'pending_update_count',
                    'command_batch_size',
                    'payload_size_bytes',
                    'memo_size_bytes',
                    'search_attribute_size_bytes',
                    'history_transaction_size',
                    'warning_threshold_percent',
                ],
            ]);

        $limits = $response->json('structural_limits');

        $this->assertIsInt($limits['pending_activity_count']);
        $this->assertIsInt($limits['history_transaction_size']);
        $this->assertGreaterThan(0, $limits['pending_activity_count']);
        $this->assertGreaterThan(0, $limits['history_transaction_size']);
    }

    public function test_structural_limits_reflect_custom_configuration(): void
    {
        config([
            'workflows.v2.structural_limits.pending_activity_count' => 500,
            'workflows.v2.structural_limits.history_transaction_size' => 1000,
        ]);

        $response = $this->getJson('/api/cluster/info');

        $response->assertOk()
            ->assertJsonPath('structural_limits.pending_activity_count', 500)
            ->assertJsonPath('structural_limits.history_transaction_size', 1000);
    }

    public function test_it_includes_package_provenance_when_exposure_is_enabled_and_file_exists(): void
    {
        config(['server.expose_package_provenance' => true]);

        $this->useProvenanceFixture([
            'https://github.com/durable-workflow/workflow.git',
            'v2',
            'abc123def456',
        ]);

        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('package_provenance.source', 'https://github.com/durable-workflow/workflow.git')
            ->assertJsonPath('package_provenance.ref', 'v2')
            ->assertJsonPath('package_provenance.commit', 'abc123def456');
    }

    public function test_package_provenance_is_admin_only_when_role_tokens_are_configured(): void
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
            'server.expose_package_provenance' => true,
        ]);

        $this->useProvenanceFixture([
            'https://github.com/durable-workflow/workflow.git',
            'v2',
            'fedcba987654',
        ]);

        $this->getJson('/api/cluster/info', $this->bearerHeaders('worker-token'))
            ->assertOk()
            ->assertJsonMissingPath('package_provenance');

        $this->getJson('/api/cluster/info', $this->bearerHeaders('operator-token'))
            ->assertOk()
            ->assertJsonMissingPath('package_provenance');

        $this->getJson('/api/cluster/info', $this->bearerHeaders('admin-token'))
            ->assertOk()
            ->assertJsonPath('package_provenance.source', 'https://github.com/durable-workflow/workflow.git')
            ->assertJsonPath('package_provenance.ref', 'v2')
            ->assertJsonPath('package_provenance.commit', 'fedcba987654');
    }

    public function test_tests_do_not_mutate_the_repo_root_provenance_file(): void
    {
        // TD-S041 regression: verify the test fixture never touches
        // base_path('.package-provenance'). Capture its state, run a full
        // provenance-exposing flow, then confirm the repo-root file is
        // unchanged (present-with-same-contents, or still absent).
        $repoProvenance = base_path('.package-provenance');
        $existedBefore = is_file($repoProvenance);
        $beforeContents = $existedBefore ? file_get_contents($repoProvenance) : null;

        config(['server.expose_package_provenance' => true]);
        $this->useProvenanceFixture([
            'https://github.com/durable-workflow/workflow.git',
            'v2',
            'deadbeef12345',
        ]);

        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('package_provenance.commit', 'deadbeef12345');

        $existedAfter = is_file($repoProvenance);
        $this->assertSame(
            $existedBefore,
            $existedAfter,
            'Provenance tests must not change whether the repo-root .package-provenance file exists.',
        );

        if ($existedBefore) {
            $this->assertSame(
                $beforeContents,
                file_get_contents($repoProvenance),
                'Provenance tests must not overwrite the repo-root .package-provenance file.',
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function bearerHeaders(string $token): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ];
    }
}
