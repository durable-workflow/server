<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RustSdkReleaseContractTest extends TestCase
{
    public function test_crate_metadata_and_guidance_publish_an_exact_compatible_release(): void
    {
        $manifest = $this->read('sdk-rust/Cargo.toml');
        $readme = $this->read('sdk-rust/README.md');
        $workflow = $this->read('.github/workflows/rust-sdk.yml');
        $heartbeatRunner = $this->read('scripts/conformance/heartbeats-published-artifacts.mjs');

        foreach ([
            'name = "durable-workflow"',
            'version = "0.1.0"',
            'repository = "https://github.com/durable-workflow/server"',
            'documentation = "https://docs.rs/durable-workflow/0.1.0"',
            'rust-version = "1.86"',
            '[package.metadata.durable-workflow]',
            'supported-server-versions = ">=0.2,<0.3"',
            'worker-protocol-version = "1.2"',
            'control-plane-version = "2"',
        ] as $needle) {
            $this->assertStringContainsString($needle, $manifest);
        }

        foreach ([
            'cargo add durable-workflow@0.1.0 --exact',
            'durable-workflow = "=0.1.0"',
            'Version `0.1.0` requires Rust `1.86` or newer and supports Durable Workflow',
            'server `0.2.x`',
            '`Worker::on_worker_heartbeat`',
        ] as $needle) {
            $this->assertStringContainsString($needle, $readme);
        }

        $this->assertStringNotContainsString('Until the crate is published', $readme);
        $this->assertStringNotContainsString('path dependency', strtolower($readme));
        $this->assertStringNotContainsString('git dependency', strtolower($readme));
        $this->assertStringContainsString('dtolnay/rust-toolchain@1.86.0', $workflow);
        $this->assertStringContainsString('rust:1.86.0-slim-bookworm', $heartbeatRunner);
        $this->assertStringNotContainsString('rust-toolchain@1.85', $workflow);
        $this->assertStringNotContainsString('rust:1.85', $heartbeatRunner);
    }

    public function test_release_workflow_publishes_and_verifies_the_registry_artifact(): void
    {
        $workflow = $this->read('.github/workflows/release.yml');
        $publisher = $this->read('scripts/ci/publish-rust-sdk.sh');

        foreach ([
            'Publish exact Rust SDK crate',
            'CARGO_REGISTRY_TOKEN: ${{ secrets.CARGO_REGISTRY_TOKEN }}',
            'scripts/ci/publish-rust-sdk.sh',
            'name: rust-sdk-release-evidence',
            'if-no-files-found: error',
        ] as $needle) {
            $this->assertStringContainsString($needle, $workflow);
        }

        foreach ([
            'cargo publish --manifest-path "$manifest_path" --registry crates-io --allow-dirty',
            'https://crates.io/api/v1/crates/${package_name}',
            'published_repository_provenance_mismatch',
            'published_checksum',
            'published_source_archive_mismatch',
            'source_archive_matches_release_checkout',
            'supported_server_versions',
            'registry_verified',
            'crates.io://${package_name}@${package_version}',
        ] as $needle) {
            $this->assertStringContainsString($needle, $publisher);
        }

        $this->assertStringNotContainsString('--token', $publisher);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }
}
