<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class KubernetesManifestContractTest extends TestCase
{
    private const SERVER_IMAGE = 'durableworkflow/server:0.2';

    public function test_public_manifests_use_pinned_published_server_images(): void
    {
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
                        self::SERVER_IMAGE,
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

    public function test_kubernetes_readme_documents_support_boundary(): void
    {
        $source = $this->read('k8s/README.md');

        foreach ([
            'durableworkflow/server:0.2',
            'ghcr.io/durable-workflow/server:0.2',
            'Digest pinning is preferred',
            'Helm charts',
            'managed-Kubernetes provider validation',
            'support-led',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
    }

    public function test_kubernetes_validation_workflow_runs_static_schema_and_kind_smoke(): void
    {
        $source = $this->read('.github/workflows/kubernetes-validation.yml');

        foreach ([
            'app/**',
            'config/**',
            'ghcr.io/yannh/kubeconform:v0.6.7',
            'routes/**',
            'scripts/k8s-kind-smoke.sh',
            'kind.sigs.k8s.io/dl/v0.23.0/kind-linux-amd64',
            'K8S_SMOKE_KIND_NODE_IMAGE',
            'K8S_SMOKE_ARTIFACT_DIR',
            'actions/upload-artifact@v4',
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
}
