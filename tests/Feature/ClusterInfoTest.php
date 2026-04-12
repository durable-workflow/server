<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClusterInfoTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_it_omits_package_provenance_when_the_provenance_file_does_not_exist(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonMissing(['package_provenance']);
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

    public function test_it_includes_package_provenance_when_the_provenance_file_exists(): void
    {
        $provenancePath = base_path('.package-provenance');
        $existed = is_file($provenancePath);

        try {
            file_put_contents($provenancePath, implode("\n", [
                'https://github.com/durable-workflow/workflow.git',
                'v2',
                'abc123def456',
            ]));

            $response = $this->getJson('/api/cluster/info');

            $response->assertOk()
                ->assertJsonPath('package_provenance.source', 'https://github.com/durable-workflow/workflow.git')
                ->assertJsonPath('package_provenance.ref', 'v2')
                ->assertJsonPath('package_provenance.commit', 'abc123def456');
        } finally {
            if (! $existed) {
                @unlink($provenancePath);
            }
        }
    }
}
