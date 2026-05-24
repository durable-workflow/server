<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ReleaseImagePublishWorkflowContractTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function test_release_publish_job_is_guarded_before_registry_login(): void
    {
        $workflow = $this->read('.github/workflows/release.yml');

        foreach ([
            "if: github.event_name == 'workflow_dispatch' || startsWith(github.ref, 'refs/tags/')",
            'scripts/ci/validate-release-image-publish.sh',
            'DOCKERHUB_USERNAME: ${{ secrets.DOCKERHUB_USERNAME }}',
            'DOCKERHUB_TOKEN: ${{ secrets.DOCKERHUB_TOKEN }}',
            'GHCR_TOKEN: ${{ secrets.GITHUB_TOKEN }}',
            'push: true',
        ] as $needle) {
            $this->assertStringContainsString($needle, $workflow);
        }

        $guardOffset = strpos($workflow, 'Validate release publish context and credentials');
        $dockerHubLoginOffset = strpos($workflow, 'Log in to Docker Hub');
        $ghcrLoginOffset = strpos($workflow, 'Log in to GHCR');
        $pushOffset = strpos($workflow, '- name: Build and push');

        $this->assertIsInt($guardOffset);
        $this->assertIsInt($dockerHubLoginOffset);
        $this->assertIsInt($ghcrLoginOffset);
        $this->assertIsInt($pushOffset);
        $this->assertLessThan($dockerHubLoginOffset, $guardOffset);
        $this->assertLessThan($ghcrLoginOffset, $guardOffset);
        $this->assertLessThan($pushOffset, $guardOffset);
    }

    public function test_release_guard_rejects_pull_request_publish_context(): void
    {
        $result = $this->runGuard([
            'GITHUB_EVENT_NAME' => 'pull_request',
            'GITHUB_REF' => 'refs/pull/123/merge',
        ]);

        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('Docker image publication is restricted to release events', $result['stderr']);
        $this->assertStringContainsString('pull_request', $result['stderr']);
        $this->assertStringNotContainsString('Username and password required', $result['stderr']);
    }

    public function test_release_guard_reports_missing_credentials_with_artifact_names(): void
    {
        $result = $this->runGuard([
            'GITHUB_EVENT_NAME' => 'push',
            'GITHUB_REF' => 'refs/tags/0.2.167',
        ]);

        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('Release blocked: cannot publish durableworkflow/server:0.2.167', $result['stderr']);
        $this->assertStringContainsString('ghcr.io/durable-workflow/server:0.2.167', $result['stderr']);
        $this->assertStringContainsString('DOCKERHUB_USERNAME', $result['stderr']);
        $this->assertStringContainsString('DOCKERHUB_TOKEN', $result['stderr']);
        $this->assertStringContainsString('GHCR_TOKEN', $result['stderr']);
        $this->assertStringContainsString('pull-request validation must not run this publish path', $result['stderr']);
    }

    public function test_release_guard_outputs_manual_semver_tag_for_metadata_action(): void
    {
        $outputFile = tempnam(sys_get_temp_dir(), 'release-image-publish-output-');
        $this->assertIsString($outputFile);

        try {
            $result = $this->runGuard([
                'GITHUB_EVENT_NAME' => 'workflow_dispatch',
                'GITHUB_REF' => 'refs/heads/main',
                'INPUT_TAG' => '0.2.167',
                'DOCKERHUB_USERNAME' => 'durableworkflow',
                'DOCKERHUB_TOKEN' => 'docker-token',
                'GHCR_TOKEN' => 'ghcr-token',
                'GITHUB_OUTPUT' => $outputFile,
            ]);

            $this->assertSame(0, $result['exitCode']);
            $outputs = file_get_contents($outputFile);
            $this->assertNotFalse($outputs);
            $this->assertStringContainsString("tag=0.2.167\n", $outputs);
            $this->assertStringContainsString("is_semver=true\n", $outputs);
            $this->assertStringContainsString('Release image publish context validated', $result['stdout']);
        } finally {
            @unlink($outputFile);
        }
    }

    /**
     * @param array<string, string> $env
     * @return array{exitCode:int, stdout:string, stderr:string}
     */
    private function runGuard(array $env): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $this->repoRoot.'/scripts/ci/validate-release-image-publish.sh',
            $descriptorSpec,
            $pipes,
            $this->repoRoot,
            ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'] + $env,
        );

        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    }

    private function read(string $path): string
    {
        $source = file_get_contents($this->repoRoot.'/'.$path);
        $this->assertNotFalse($source, "{$path} must be readable");

        return $source;
    }
}
