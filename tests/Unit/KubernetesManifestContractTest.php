<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class KubernetesManifestContractTest extends TestCase
{
    public function test_public_manifests_use_pinned_published_server_images(): void
    {
        $serverImage = $this->onboardingServerImage();

        foreach ($this->kubernetesYamlFiles() as $path) {
            $source = $this->read($path);

            $this->assertStringNotContainsString(
                ':latest',
                $source,
                "{$path} must not reference a mutable latest image tag",
            );

            preg_match_all('/^\s*image:\s*([^\s#]+)/m', $source, $matches);

            foreach ($matches[1] ?? [] as $image) {
                if (str_contains($image, '/server:') || str_contains($image, 'server@')) {
                    $this->assertSame(
                        $serverImage,
                        $image,
                        "{$path} must use the public pinned server image unless an overlay patches it",
                    );
                }
            }
        }
    }

    public function test_public_manifests_do_not_require_registry_secret_for_public_images(): void
    {
        foreach ($this->kubernetesYamlFiles() as $path) {
            $this->assertStringNotContainsString(
                'imagePullSecrets',
                $this->read($path),
                "{$path} should be directly usable with public images; overlays may add registry secrets",
            );
        }
    }

    public function test_server_deployment_keeps_distinct_liveness_and_readiness_probes(): void
    {
        $source = $this->read('k8s/server-deployment.yaml');

        $this->assertStringContainsString('path: /api/health', $source);
        $this->assertStringContainsString('path: /api/ready', $source);
    }

    public function test_kubernetes_validation_workflow_runs_static_schema_and_kind_smoke(): void
    {
        $source = $this->read('.github/workflows/kubernetes-validation.yml');

        foreach ([
            'workflow_dispatch:',
            'ghcr.io/yannh/kubeconform:v0.6.7',
            'scripts/k8s-kind-smoke.sh',
            'kind.sigs.k8s.io/dl/v0.23.0/kind-linux-amd64',
            'K8S_SMOKE_KIND_NODE_IMAGE',
            'K8S_SMOKE_ARTIFACT_DIR',
            'actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
    }

    public function test_kind_smoke_script_verifies_readiness_cluster_info_and_worker_registration(): void
    {
        $source = $this->read('scripts/k8s-kind-smoke.sh');

        foreach ([
            'build -t "${image}" "${repo_root}"',
            'kindest/node:v1.29.4',
            'load docker-image "${image}"',
            'wait_for_kubernetes_api',
            'rollout status deploy/durable-workflow-mysql',
            '/api/ready',
            '/api/cluster/info',
            '/api/worker/register',
            'collect_artifacts',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
    }

    public function test_kind_smoke_replaces_the_pinned_manifest_image_with_the_local_image(): void
    {
        $source = $this->read('scripts/k8s-kind-smoke.sh');

        preg_match('/^manifest_image="([^"]+)"$/m', $source, $matches);

        $this->assertSame($this->onboardingServerImage(), $matches[1] ?? null);
        $this->assertStringContainsString('sed -i "s#${manifest_image}#${image}#g"', $source);
        $this->assertStringContainsString('grep -R -Fq "${manifest_image}" "${rendered_dir}"', $source);
        $this->assertStringContainsString('grep -R -Fq "${image}" "${rendered_dir}"', $source);
        $this->assertStringNotContainsString('durableworkflow/server:0.2', $source);
    }

    /**
     * @return list<string>
     */
    private function kubernetesYamlFiles(): array
    {
        $paths = glob(dirname(__DIR__, 2).'/k8s/*.yaml') ?: [];
        $relative = array_map(
            static fn (string $path): string => substr($path, strlen(dirname(__DIR__, 2)) + 1),
            $paths,
        );
        sort($relative);

        return $relative;
    }

    private function read(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        $this->assertNotFalse($source, "{$path} must be readable");

        return $source;
    }

    private function onboardingServerImage(): string
    {
        $source = json_decode(
            $this->read('resources/release/source-release.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $version = $source['server']['version'] ?? null;
        $this->assertIsString($version);

        return 'durableworkflow/server:'.$version;
    }
}
