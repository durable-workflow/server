<?php

namespace Tests\Unit;

use App\Support\HeartbeatRuntimeContract;
use PHPUnit\Framework\TestCase;

final class RustSdkOwnershipContractTest extends TestCase
{
    public function test_server_release_has_no_rust_crate_publication_surface(): void
    {
        $root = dirname(__DIR__, 2);
        $workflow = $this->read('.github/workflows/release.yml');

        $this->assertDirectoryDoesNotExist($root.'/sdk-rust');
        $this->assertFileDoesNotExist($root.'/.github/workflows/rust-sdk.yml');
        $this->assertFileDoesNotExist($root.'/scripts/ci/publish-rust-sdk.sh');

        foreach ([
            'publish-rust-sdk',
            'CARGO_REGISTRY_TOKEN',
            'cargo publish',
            'crates.io',
            'rust-sdk-release-evidence',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $workflow);
        }

        foreach ([
            'name: Build and push Docker image',
            'docker/build-push-action@53b7df96c91f9c12dcc8a07bcb9ccacbed38856a',
            'scripts/ci/verify-release-exact-images.sh',
            'name: release-image-publish-evidence',
        ] as $imageReleaseContract) {
            $this->assertStringContainsString($imageReleaseContract, $workflow);
        }
    }

    public function test_server_exports_the_source_free_published_rust_heartbeat_contract(): void
    {
        $manifest = HeartbeatRuntimeContract::manifest();
        $runner = $manifest['host_runner_contract']['focused_runners']['sdk-rust-heartbeat-loop'];
        $shell = $this->read('scripts/conformance/heartbeats-rust-published-artifacts.sh');
        $source = $this->read('scripts/conformance/heartbeats-published-artifacts.mjs');
        $dockerfile = $this->read('Dockerfile');
        $dockerignore = $this->read('.dockerignore');

        $this->assertSame('server', $runner['runner_repository']);
        $this->assertSame(
            'scripts/conformance/heartbeats-rust-published-artifacts.sh',
            $runner['runner_path'],
        );
        $this->assertSame(['server', 'cli', 'sdk-rust'], $runner['required_artifact_pins']);
        $this->assertTrue($runner['must_execute_against_published_artifacts']);
        $this->assertStringContainsString('COPY . .', $dockerfile);
        $this->assertStringNotContainsString('scripts/conformance', $dockerignore);

        foreach ([
            'DW_HEARTBEATS_CELL=rust',
            'DW_RUST_SDK_VERSION',
            'rust-sdk-heartbeat-loop-evidence.json',
        ] as $handoffContract) {
            $this->assertStringContainsString($handoffContract, $shell);
        }

        foreach ([
            "const SDK_RUST_VERSION = env('DW_RUST_SDK_VERSION');",
            '`crates.io://durable-workflow@${SDK_RUST_VERSION}`',
            'durable-workflow = "=${SDK_RUST_VERSION}"',
            'publish = false',
            "installedPackage.repository !== 'https://github.com/durable-workflow/sdk-rust'",
            '.on_worker_heartbeat(|observation|',
            '.poll_workflow_task_response(&arguments[4], &arguments[3]',
        ] as $publishedArtifactContract) {
            $this->assertStringContainsString($publishedArtifactContract, $source);
        }
    }

    public function test_public_rust_sdk_references_use_the_independent_product_surfaces(): void
    {
        $references = $this->read('README.md').$this->read('docs/temporal-compatibility-parity.md');

        $this->assertStringContainsString(
            'https://github.com/durable-workflow/sdk-rust',
            $references,
        );
        $this->assertStringContainsString('https://rust.durable-workflow.com/', $references);
        $this->assertStringNotContainsString('sdk-rust/examples/', $references);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }
}
