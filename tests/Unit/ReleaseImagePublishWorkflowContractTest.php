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

    public function test_release_workflow_promotes_rolling_aliases_only_after_current_tag_guard(): void
    {
        $workflow = $this->read('.github/workflows/release.yml');

        $this->assertStringContainsString('Extract exact image metadata', $workflow);
        $this->assertStringContainsString('Build and push exact image tags', $workflow);
        $this->assertStringContainsString('scripts/ci/resolve-release-image-rolling-tags.sh', $workflow);
        $this->assertStringContainsString('steps.rolling.outputs.rolling_should_promote == \'true\'', $workflow);
        $this->assertStringContainsString('scripts/ci/promote-release-image-rolling-tags.sh', $workflow);
        $this->assertStringContainsString('scripts/ci/write-release-image-publish-evidence.sh', $workflow);
        $this->assertStringContainsString('name: release-image-publish-evidence', $workflow);
        $this->assertStringContainsString('if-no-files-found: error', $workflow);

        $this->assertStringContainsString('type=semver,pattern={{version}}', $workflow);
        $this->assertStringContainsString('latest=false', $workflow);
        $this->assertStringNotContainsString('type=semver,pattern={{major}}.{{minor}}', $workflow);
        $this->assertStringNotContainsString('type=semver,pattern={{major}}', $workflow);
        $this->assertStringNotContainsString('type=raw,value=latest', $workflow);

        $buildOffset = strpos($workflow, 'Build and push exact image tags');
        $resolveOffset = strpos($workflow, 'Resolve rolling image aliases');
        $promoteOffset = strpos($workflow, 'Promote rolling image aliases');
        $evidenceOffset = strpos($workflow, 'Write release image publish evidence');

        $this->assertIsInt($buildOffset);
        $this->assertIsInt($resolveOffset);
        $this->assertIsInt($promoteOffset);
        $this->assertIsInt($evidenceOffset);
        $this->assertLessThan($resolveOffset, $buildOffset);
        $this->assertLessThan($promoteOffset, $resolveOffset);
        $this->assertLessThan($evidenceOffset, $promoteOffset);
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

    public function test_rolling_resolver_marks_older_patch_as_superseded(): void
    {
        $outputFile = tempnam(sys_get_temp_dir(), 'release-image-rolling-output-');
        $this->assertIsString($outputFile);

        try {
            $result = $this->runScript('scripts/ci/resolve-release-image-rolling-tags.sh', [
                'RELEASE_TAG' => '0.2.177',
                'RELEASE_IMAGE_KNOWN_TAGS' => "0.2.176\n0.2.177\n0.2.178",
                'GITHUB_OUTPUT' => $outputFile,
            ]);

            $this->assertSame(0, $result['exitCode']);
            $outputs = file_get_contents($outputFile);
            $this->assertNotFalse($outputs);
            $this->assertStringContainsString("rolling_should_promote=false\n", $outputs);
            $this->assertStringContainsString("artifact_status=superseded\n", $outputs);
            $this->assertStringContainsString("superseded_by=0.2.178\n", $outputs);
            $this->assertStringContainsString('rolling aliases will not move backward', $result['stdout']);
        } finally {
            @unlink($outputFile);
        }
    }

    public function test_rolling_resolver_marks_newest_patch_current(): void
    {
        $outputFile = tempnam(sys_get_temp_dir(), 'release-image-rolling-output-');
        $this->assertIsString($outputFile);

        try {
            $result = $this->runScript('scripts/ci/resolve-release-image-rolling-tags.sh', [
                'RELEASE_TAG' => '0.2.178',
                'RELEASE_IMAGE_KNOWN_TAGS' => "refs/tags/0.2.176\nrefs/tags/0.2.177\nrefs/tags/0.2.178",
                'GITHUB_OUTPUT' => $outputFile,
            ]);

            $this->assertSame(0, $result['exitCode']);
            $outputs = file_get_contents($outputFile);
            $this->assertNotFalse($outputs);
            $this->assertStringContainsString("rolling_should_promote=true\n", $outputs);
            $this->assertStringContainsString("artifact_status=current\n", $outputs);
            $this->assertStringContainsString("minor_alias=0.2\n", $outputs);
            $this->assertStringContainsString("major_alias=0\n", $outputs);
            $this->assertStringContainsString('durableworkflow/server:latest', $outputs);
            $this->assertStringContainsString('ghcr.io/durable-workflow/server:latest', $outputs);
        } finally {
            @unlink($outputFile);
        }
    }

    public function test_promote_script_aliases_exact_manifest_for_both_registries(): void
    {
        $tmpDir = sys_get_temp_dir().'/release-image-docker-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($tmpDir));
        $dockerBin = $tmpDir.'/docker';
        $logFile = $tmpDir.'/docker.log';
        file_put_contents($dockerBin, "#!/usr/bin/env sh\nprintf '%s\\n' \"\$*\" >> \"{$logFile}\"\n");
        chmod($dockerBin, 0755);

        try {
            $result = $this->runScript('scripts/ci/promote-release-image-rolling-tags.sh', [
                'RELEASE_TAG' => '0.2.178',
                'DOCKER' => $dockerBin,
            ]);

            $this->assertSame(0, $result['exitCode']);
            $log = file_get_contents($logFile);
            $this->assertNotFalse($log);
            $this->assertStringContainsString('buildx imagetools create --tag durableworkflow/server:0.2 --tag durableworkflow/server:0 --tag durableworkflow/server:latest durableworkflow/server:0.2.178', $log);
            $this->assertStringContainsString('buildx imagetools create --tag ghcr.io/durable-workflow/server:0.2 --tag ghcr.io/durable-workflow/server:0 --tag ghcr.io/durable-workflow/server:latest ghcr.io/durable-workflow/server:0.2.178', $log);
        } finally {
            @unlink($dockerBin);
            @unlink($logFile);
            @rmdir($tmpDir);
        }
    }

    public function test_promote_script_refuses_when_newer_tag_appears_after_resolver(): void
    {
        $tmpDir = sys_get_temp_dir().'/release-image-docker-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($tmpDir));
        $dockerBin = $tmpDir.'/docker';
        $logFile = $tmpDir.'/docker.log';
        $outputFile = $tmpDir.'/outputs';
        file_put_contents($dockerBin, "#!/usr/bin/env sh\nprintf '%s\\n' \"\$*\" >> \"{$logFile}\"\n");
        chmod($dockerBin, 0755);

        try {
            $result = $this->runScript('scripts/ci/promote-release-image-rolling-tags.sh', [
                'RELEASE_TAG' => '0.2.177',
                'RELEASE_IMAGE_KNOWN_TAGS' => "0.2.177\n0.2.178",
                'ROLLING_SHOULD_PROMOTE' => 'true',
                'DOCKER' => $dockerBin,
                'GITHUB_OUTPUT' => $outputFile,
            ]);

            $this->assertSame(0, $result['exitCode']);
            $this->assertFileDoesNotExist($logFile);
            $outputs = file_get_contents($outputFile);
            $this->assertNotFalse($outputs);
            $this->assertStringContainsString("rolling_should_promote=false\n", $outputs);
            $this->assertStringContainsString("artifact_status=superseded\n", $outputs);
            $this->assertStringContainsString("superseded_by=0.2.178\n", $outputs);
            $this->assertStringContainsString('rolling aliases were not changed', $result['stdout']);
        } finally {
            @unlink($dockerBin);
            @unlink($logFile);
            @unlink($outputFile);
            @rmdir($tmpDir);
        }
    }

    public function test_evidence_records_superseded_release_without_current_rolling_refs(): void
    {
        $evidenceFile = tempnam(sys_get_temp_dir(), 'release-image-evidence-');
        $this->assertIsString($evidenceFile);

        try {
            $result = $this->runScript('scripts/ci/write-release-image-publish-evidence.sh', [
                'RELEASE_IMAGE_EVIDENCE_PATH' => $evidenceFile,
                'RELEASE_TAG' => '0.2.177',
                'VALIDATION_OUTCOME' => 'success',
                'EXACT_PUBLISH_OUTCOME' => 'success',
                'ROLLING_GUARD_OUTCOME' => 'success',
                'ROLLING_PROMOTE_OUTCOME' => 'skipped',
                'ROLLING_ARTIFACT_STATUS' => 'superseded',
                'ROLLING_SHOULD_PROMOTE' => 'false',
                'ROLLING_SUPERSEDED_BY' => '0.2.178',
                'IMAGE_DIGEST' => 'sha256:'.str_repeat('a', 64),
                'RELEASE_COMMIT' => str_repeat('b', 40),
                'RELEASE_RUN_ID' => '12345',
                'RELEASE_RUN_ATTEMPT' => '2',
            ]);

            $this->assertSame(0, $result['exitCode']);
            $decoded = json_decode((string) file_get_contents($evidenceFile), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('durable-workflow.release-image-publish-evidence.v1', $decoded['schema']);
            $this->assertSame('superseded', $decoded['status']);
            $this->assertSame(['pending', 'current', 'superseded', 'failed'], $decoded['status_values']);
            $this->assertSame(['server' => 'durableworkflow/server:0.2.177'], $decoded['artifact_versions']);
            $this->assertSame('0.2.178', $decoded['rolling']['superseded_by']);
            $this->assertSame([], $decoded['rolling']['refs']);
            $this->assertContains('durableworkflow/server:0.2', $decoded['rolling']['skipped_refs']);
            $this->assertContains('ghcr.io/durable-workflow/server:latest', $decoded['rolling']['skipped_refs']);
        } finally {
            @unlink($evidenceFile);
        }
    }

    /**
     * @param array<string, string> $env
     * @return array{exitCode:int, stdout:string, stderr:string}
     */
    private function runGuard(array $env): array
    {
        return $this->runScript('scripts/ci/validate-release-image-publish.sh', $env);
    }

    /**
     * @param array<string, string> $env
     * @return array{exitCode:int, stdout:string, stderr:string}
     */
    private function runScript(string $path, array $env): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $this->repoRoot.'/'.$path,
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
