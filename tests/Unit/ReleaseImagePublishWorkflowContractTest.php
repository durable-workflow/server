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
            'scripts/ci/select-compatible-workflow-package-ref.sh',
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

    public function test_release_workflow_selects_compatible_workflow_package_before_docker_work(): void
    {
        $workflow = $this->read('.github/workflows/release.yml');

        $this->assertStringContainsString('Select compatible workflow package version', $workflow);
        $this->assertStringContainsString('id: workflow', $workflow);
        $this->assertStringContainsString('scripts/ci/select-compatible-workflow-package-ref.sh', $workflow);
        $this->assertStringContainsString('WORKFLOW_PACKAGE_REF=${{ steps.workflow.outputs.tag }}', $workflow);
        $this->assertStringNotContainsString('Get latest workflow package version', $workflow);
        $this->assertStringNotContainsString('LATEST_TAG=$(git ls-remote', $workflow);

        $selectorOffset = strpos($workflow, 'Select compatible workflow package version');
        $qemuOffset = strpos($workflow, 'Set up QEMU');
        $buildxOffset = strpos($workflow, 'Set up Docker Buildx');
        $buildOffset = strpos($workflow, 'Build and push exact image tags');

        $this->assertIsInt($selectorOffset);
        $this->assertIsInt($qemuOffset);
        $this->assertIsInt($buildxOffset);
        $this->assertIsInt($buildOffset);
        $this->assertLessThan($qemuOffset, $selectorOffset);
        $this->assertLessThan($buildxOffset, $selectorOffset);
        $this->assertLessThan($buildOffset, $selectorOffset);
    }

    public function test_release_workflow_passes_workflow_package_commit_to_image_and_evidence(): void
    {
        $workflow = $this->read('.github/workflows/release.yml');

        foreach ([
            'dev.durable-workflow.workflow.package=durable-workflow/workflow',
            'dev.durable-workflow.release.tag=${{ steps.release_publish.outputs.tag }}',
            'dev.durable-workflow.release.run-id=${{ github.run_id }}',
            'dev.durable-workflow.release.run-attempt=${{ github.run_attempt }}',
            'dev.durable-workflow.workflow.version=${{ steps.workflow.outputs.tag }}',
            'dev.durable-workflow.workflow.commit=${{ steps.workflow.outputs.commit }}',
            'WORKFLOW_PACKAGE_REF=${{ steps.workflow.outputs.tag }}',
            'WORKFLOW_PACKAGE_COMMIT=${{ steps.workflow.outputs.commit }}',
            'WORKFLOW_PACKAGE_REF: ${{ steps.workflow.outputs.tag }}',
            'WORKFLOW_PACKAGE_COMMIT: ${{ steps.workflow.outputs.commit }}',
        ] as $needle) {
            $this->assertStringContainsString($needle, $workflow);
        }

        $this->assertStringNotContainsString('WORKFLOW_PACKAGE_REF: 2.0.0-alpha.218', $workflow);
        $this->assertStringNotContainsString('WORKFLOW_PACKAGE_COMMIT: 289421c3e5ca65f9c8e3baaa2b8e0ff4f5836b1f', $workflow);
    }

    public function test_dockerfile_refreshes_composer_metadata_for_selected_workflow_package(): void
    {
        $dockerfile = $this->read('Dockerfile');
        $metadataScript = $this->read('scripts/ci/prepare-release-workflow-composer-metadata.php');

        foreach ([
            'ARG WORKFLOW_PACKAGE_REF=2.0.0-alpha.236',
            'ARG WORKFLOW_PACKAGE_COMMIT=35b8ea0dc5e189b392240b5fef96f2ee0295ebde',
            'prepare-release-workflow-composer-metadata.php',
            'composer update durable-workflow/workflow',
            'cp composer.json /tmp/release-composer.json',
            'cp composer.lock /tmp/release-composer.lock',
            'cp /tmp/release-composer.json composer.json',
            'cp /tmp/release-composer.lock composer.lock',
            'dev.durable-workflow.package.commit="${WORKFLOW_PACKAGE_COMMIT}"',
        ] as $needle) {
            $this->assertStringContainsString($needle, $dockerfile);
        }

        $prepareOffset = strpos($dockerfile, 'php scripts/ci/prepare-release-workflow-composer-metadata.php');
        $copySourceOffset = strpos($dockerfile, "COPY . .\n");
        $restoreOffset = strpos($dockerfile, 'cp /tmp/release-composer.json composer.json');
        $autoloadOffset = strpos($dockerfile, 'composer dump-autoload --optimize');

        $this->assertIsInt($prepareOffset);
        $this->assertIsInt($copySourceOffset);
        $this->assertIsInt($restoreOffset);
        $this->assertIsInt($autoloadOffset);
        $this->assertLessThan($copySourceOffset, $prepareOffset);
        $this->assertLessThan($restoreOffset, $copySourceOffset);
        $this->assertLessThan($autoloadOffset, $restoreOffset);

        $this->assertStringContainsString('$composer[\'require\'][$packageName] = $composerVersion;', $metadataScript);
        $this->assertStringContainsString('$repository[\'options\'][\'versions\'][$packageName] = $composerVersion;', $metadataScript);
        $this->assertStringContainsString('$repository[\'options\'][\'reference\'] = \'auto\';', $metadataScript);
        $this->assertStringContainsString('WORKFLOW_PACKAGE_COMMIT', $metadataScript);
    }

    public function test_dockerfile_installs_redis_extension_from_pinned_phpredis_source(): void
    {
        $dockerfile = $this->read('Dockerfile');

        foreach ([
            'FROM composer:2 AS phpredis-source',
            'ARG PHPREDIS_VERSION=6.3.0',
            'ARG PHPREDIS_COMMIT=df4fab2de7fc327c54c94a13af2b9542e4fbd720',
            'git clone --depth 1 --branch "${PHPREDIS_VERSION}" https://github.com/phpredis/phpredis.git /phpredis',
            'RESOLVED_COMMIT="$(git rev-parse HEAD)"',
            'COPY --from=phpredis-source /phpredis /usr/src/php/ext/redis',
            'docker-php-ext-install redis pdo pdo_mysql pdo_pgsql pcntl zip bcmath',
        ] as $needle) {
            $this->assertStringContainsString($needle, $dockerfile);
        }

        $this->assertStringNotContainsString('pecl install redis', $dockerfile);
        $this->assertStringNotContainsString('pecl.php.net/redis', $dockerfile);
    }

    public function test_dockerfile_installs_node_for_published_conformance_handoffs(): void
    {
        $dockerfile = $this->read('Dockerfile');
        $activitiesRunner = $this->read('scripts/conformance/activities-published-artifacts.sh');

        $this->assertStringContainsString('FROM php:8.3-cli AS base', $dockerfile);
        $this->assertStringContainsString('nodejs', $dockerfile);
        $this->assertStringContainsString('if ! require_command node; then', $activitiesRunner);
        $this->assertStringContainsString("required command not found: node", $activitiesRunner);

        $baseOffset = strpos($dockerfile, 'FROM php:8.3-cli AS base');
        $nodeOffset = strpos($dockerfile, 'nodejs');
        $vendorOffset = strpos($dockerfile, 'FROM base AS vendor');
        $productionOffset = strpos($dockerfile, 'FROM base AS production');

        $this->assertIsInt($baseOffset);
        $this->assertIsInt($nodeOffset);
        $this->assertIsInt($vendorOffset);
        $this->assertIsInt($productionOffset);
        $this->assertLessThan($nodeOffset, $baseOffset);
        $this->assertLessThan($vendorOffset, $nodeOffset);
        $this->assertLessThan($productionOffset, $nodeOffset);
    }

    public function test_dockerfile_installs_python_for_focused_activity_sdk_cells(): void
    {
        $dockerfile = $this->read('Dockerfile');
        $activitiesRunner = $this->read('scripts/conformance/activities-published-artifacts.sh');

        $this->assertStringContainsString('FROM php:8.3-cli AS base', $dockerfile);
        $this->assertStringContainsString('python3', $dockerfile);
        $this->assertStringContainsString('python3-venv', $dockerfile);
        $this->assertStringContainsString('prepare_focused_python_sdk', $activitiesRunner);
        $this->assertStringContainsString('python3 -m venv "$venv"', $activitiesRunner);
        $this->assertStringContainsString('"durable-workflow==${DW_PYTHON_SDK_VERSION}"', $activitiesRunner);
        $this->assertStringContainsString('run_python_activity_executor', $activitiesRunner);
        $this->assertStringContainsString('activity_host_evidence missing passing ${requiredMode}/sdk-python cell', $activitiesRunner);

        $baseOffset = strpos($dockerfile, 'FROM php:8.3-cli AS base');
        $pythonOffset = strpos($dockerfile, 'python3');
        $vendorOffset = strpos($dockerfile, 'FROM base AS vendor');
        $productionOffset = strpos($dockerfile, 'FROM base AS production');

        $this->assertIsInt($baseOffset);
        $this->assertIsInt($pythonOffset);
        $this->assertIsInt($vendorOffset);
        $this->assertIsInt($productionOffset);
        $this->assertLessThan($pythonOffset, $baseOffset);
        $this->assertLessThan($vendorOffset, $pythonOffset);
        $this->assertLessThan($productionOffset, $pythonOffset);
    }

    public function test_docker_build_docs_compose_and_ci_defaults_match_workflow_package_fallback(): void
    {
        $fallback = '2.0.0-alpha.236';
        $commit = '35b8ea0dc5e189b392240b5fef96f2ee0295ebde';

        foreach ([
            'Dockerfile',
            'docker-compose.yml',
            'docker-compose.small-cluster.yml',
            '.github/workflows/server-perf.yml',
            '.github/workflows/phpunit-feature.yml',
        ] as $path) {
            $source = $this->read($path);

            $this->assertStringContainsString($fallback, $source, "{$path} must use the current workflow package fallback.");
            $this->assertStringNotContainsString('2.0.0-alpha.200', $source, "{$path} must not keep the stale workflow package fallback.");
        }

        foreach ([
            'Dockerfile',
            'docker-compose.yml',
            'docker-compose.small-cluster.yml',
        ] as $path) {
            $this->assertStringContainsString(
                $commit,
                $this->read($path),
                "{$path} must use the current workflow package commit.",
            );
        }

        $readme = $this->read('README.md');

        $this->assertStringContainsString('WORKFLOW_PACKAGE_REF=2.0.0-alpha.236', $readme);
        $this->assertStringContainsString('The Dockerfile clones the `durable-workflow/workflow` `2.0.0-alpha.236` tag', $readme);
        $this->assertStringContainsString('Composer package metadata', $readme);
        $this->assertStringNotContainsString('The Dockerfile clones the `durable-workflow/workflow` `2.0.0-alpha.200` tag', $readme);
        $this->assertStringNotContainsString('The image build fetches the `durable-workflow/workflow` `2.0.0-alpha.200`', $readme);
    }

    public function test_release_workflow_promotes_rolling_aliases_only_after_current_tag_guard(): void
    {
        $workflow = $this->read('.github/workflows/release.yml');

        $this->assertStringContainsString('Extract exact image metadata', $workflow);
        $this->assertStringContainsString('Build and push exact image tags', $workflow);
        $this->assertStringContainsString('continue-on-error: true', $workflow);
        $this->assertStringContainsString('cache-to: type=gha,mode=max,ignore-error=true', $workflow);
        $this->assertStringContainsString('Verify exact image publication', $workflow);
        $this->assertStringContainsString('BUILT_IMAGE_DIGEST: ${{ steps.build.outputs.digest }}', $workflow);
        $this->assertStringContainsString('BUILT_IMAGE_METADATA: ${{ steps.build.outputs.metadata }}', $workflow);
        $this->assertStringContainsString('RELEASE_COMMIT: ${{ github.sha }}', $workflow);
        $this->assertStringContainsString('RELEASE_RUN_ID: ${{ github.run_id }}', $workflow);
        $this->assertStringContainsString('RELEASE_RUN_ATTEMPT: ${{ github.run_attempt }}', $workflow);
        $this->assertStringContainsString('WORKFLOW_PACKAGE_REF: ${{ steps.workflow.outputs.tag }}', $workflow);
        $this->assertStringContainsString('WORKFLOW_PACKAGE_COMMIT: ${{ steps.workflow.outputs.commit }}', $workflow);
        $this->assertStringContainsString('scripts/ci/verify-release-exact-images.sh', $workflow);
        $this->assertStringContainsString('scripts/ci/resolve-release-image-rolling-tags.sh', $workflow);
        $this->assertStringContainsString("steps.exact.outputs.exact_publish_outcome == 'success'", $workflow);
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
        $exactOffset = strpos($workflow, 'Verify exact image publication');
        $resolveOffset = strpos($workflow, 'Resolve rolling image aliases');
        $promoteOffset = strpos($workflow, 'Promote rolling image aliases');
        $evidenceOffset = strpos($workflow, 'Write release image publish evidence');

        $this->assertIsInt($buildOffset);
        $this->assertIsInt($exactOffset);
        $this->assertIsInt($resolveOffset);
        $this->assertIsInt($promoteOffset);
        $this->assertIsInt($evidenceOffset);
        $this->assertLessThan($exactOffset, $buildOffset);
        $this->assertLessThan($resolveOffset, $exactOffset);
        $this->assertLessThan($promoteOffset, $resolveOffset);
        $this->assertLessThan($evidenceOffset, $promoteOffset);
    }

    public function test_release_workflow_records_docs_audit_evidence_after_image_publish(): void
    {
        $workflow = $this->read('.github/workflows/release.yml');
        $auditor = $this->read('scripts/ci/check-docs-release-audit.sh');

        foreach ([
            'Verify live docs release audit after public images',
            "if: \${{ steps.exact.outputs.exact_publish_outcome == 'success' }}",
            'DOCS_RELEASE_AUDIT_ARTIFACT: server',
            'DOCS_RELEASE_AUDIT_VERSION: ${{ steps.release_publish.outputs.tag || github.event.inputs.tag || github.ref_name }}',
            'DOCS_RELEASE_AUDIT_EVIDENCE: docs-release-audit-evidence.json',
            'DOCS_RELEASE_AUDIT_HANDOFF: docs-release-audit-handoff.json',
            'scripts/ci/check-docs-release-audit.sh',
            'docs-release-audit-evidence.json',
            'docs-release-audit-handoff.json',
        ] as $needle) {
            $this->assertStringContainsString($needle, $workflow);
        }

        $this->assertStringContainsString('contents: read', $workflow);
        $this->assertStringNotContainsString('contents: write', $workflow);
        $this->assertStringContainsString('durable-workflow.release.docs-release-audit-evidence', $auditor);
        $this->assertStringContainsString('durable-workflow.release.docs-artifact-tuple-handoff', $auditor);
        $this->assertStringContainsString('DOCS_RELEASE_AUDIT_HANDOFF', $auditor);
        $this->assertStringContainsString("schema: 'durable-workflow.docs.refresh-request'", $auditor);
        $this->assertStringContainsString("repository: 'durable-workflow.github.io'", $auditor);
        $this->assertStringContainsString("refresh_command: 'npm run refresh:public-artifact-versions'", $auditor);
        $this->assertStringContainsString('refresh_files: refreshFiles', $auditor);
        $this->assertStringContainsString("'static/quickstart-execution-contract.json'", $auditor);
        $this->assertStringContainsString('const refreshFileList = refreshFiles.join(\', \');', $auditor);
        $this->assertStringNotContainsString('scripts/public-artifact-versions.json plus docs/compatibility.md', $auditor);
        $this->assertStringContainsString('docs_artifact_tuple_handoff: handoff', $auditor);
        $this->assertStringContainsString('observed_artifact_versions: versions', $auditor);

        $buildOffset = strpos($workflow, 'Build and push exact image tags');
        $exactOffset = strpos($workflow, 'Verify exact image publication');
        $writeEvidenceOffset = strpos($workflow, 'Write release image publish evidence');
        $docsAuditOffset = strpos($workflow, 'Verify live docs release audit after public images');
        $uploadOffset = strpos($workflow, 'Upload release image publish evidence');

        $this->assertIsInt($buildOffset);
        $this->assertIsInt($exactOffset);
        $this->assertIsInt($writeEvidenceOffset);
        $this->assertIsInt($docsAuditOffset);
        $this->assertIsInt($uploadOffset);
        $this->assertLessThan($exactOffset, $buildOffset);
        $this->assertLessThan($writeEvidenceOffset, $exactOffset);
        $this->assertLessThan($docsAuditOffset, $writeEvidenceOffset);
        $this->assertLessThan($uploadOffset, $docsAuditOffset);
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

    public function test_exact_image_verifier_accepts_verified_manifests_after_build_step_failure(): void
    {
        $tmpDir = sys_get_temp_dir().'/release-image-docker-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($tmpDir));
        $dockerBin = $tmpDir.'/docker';
        $outputFile = $tmpDir.'/outputs';
        $digest = 'sha256:'.str_repeat('c', 64);
        $dockerScript = <<<SH
#!/usr/bin/env sh
if [ "\$1" = "buildx" ] && [ "\$2" = "imagetools" ] && [ "\$3" = "inspect" ]; then
    printf 'Name: %s\\nMediaType: application/vnd.oci.image.index.v1+json\\nDigest: {$digest}\\n\\nManifests:\\n  Platform: linux/amd64\\n  Platform: linux/arm64\\n' "\$4"
    exit 0
fi
exit 1
SH;
        file_put_contents($dockerBin, $dockerScript);
        chmod($dockerBin, 0755);

        try {
            $result = $this->runScript('scripts/ci/verify-release-exact-images.sh', [
                'RELEASE_TAG' => '0.2.396',
                'DOCKER' => $dockerBin,
                'DOCKER_BUILD_OUTCOME' => 'failure',
                'BUILT_IMAGE_DIGEST' => $digest,
                'GITHUB_OUTPUT' => $outputFile,
            ]);

            $this->assertSame(0, $result['exitCode']);
            $outputs = file_get_contents($outputFile);
            $this->assertNotFalse($outputs);
            $this->assertStringContainsString("exact_publish_outcome=success\n", $outputs);
            $this->assertStringContainsString("exact_publish_reason=exact_manifests_verified_after_build_step_failure\n", $outputs);
            $this->assertStringContainsString("image_digest={$digest}\n", $outputs);
            $this->assertStringContainsString('durableworkflow/server:0.2.396', $outputs);
            $this->assertStringContainsString('ghcr.io/durable-workflow/server:0.2.396', $outputs);
            $this->assertStringContainsString('Docker build step reported failure, but exact image manifests match this release build digest', $result['stdout']);
        } finally {
            @unlink($dockerBin);
            @unlink($outputFile);
            @rmdir($tmpDir);
        }
    }

    public function test_exact_image_verifier_accepts_build_metadata_digest_when_direct_digest_is_missing(): void
    {
        $tmpDir = sys_get_temp_dir().'/release-image-docker-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($tmpDir));
        $dockerBin = $tmpDir.'/docker';
        $outputFile = $tmpDir.'/outputs';
        $digest = 'sha256:'.str_repeat('f', 64);
        $metadata = json_encode(['containerimage.digest' => $digest], JSON_THROW_ON_ERROR);
        $dockerScript = <<<SH
#!/usr/bin/env sh
if [ "\$1" = "buildx" ] && [ "\$2" = "imagetools" ] && [ "\$3" = "inspect" ]; then
    printf 'Name: %s\\nMediaType: application/vnd.oci.image.index.v1+json\\nDigest: {$digest}\\n\\nManifests:\\n  Platform: linux/amd64\\n  Platform: linux/arm64\\n' "\$4"
    exit 0
fi
exit 1
SH;
        file_put_contents($dockerBin, $dockerScript);
        chmod($dockerBin, 0755);

        try {
            $result = $this->runScript('scripts/ci/verify-release-exact-images.sh', [
                'RELEASE_TAG' => '0.2.396',
                'DOCKER' => $dockerBin,
                'DOCKER_BUILD_OUTCOME' => 'failure',
                'BUILT_IMAGE_METADATA' => $metadata,
                'GITHUB_OUTPUT' => $outputFile,
            ]);

            $this->assertSame(0, $result['exitCode']);
            $outputs = file_get_contents($outputFile);
            $this->assertNotFalse($outputs);
            $this->assertStringContainsString("exact_publish_outcome=success\n", $outputs);
            $this->assertStringContainsString("image_digest={$digest}\n", $outputs);
        } finally {
            @unlink($dockerBin);
            @unlink($outputFile);
            @rmdir($tmpDir);
        }
    }

    public function test_exact_image_verifier_accepts_release_metadata_identity_when_build_digest_is_missing(): void
    {
        $tmpDir = sys_get_temp_dir().'/release-image-docker-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($tmpDir));
        $dockerBin = $tmpDir.'/docker';
        $outputFile = $tmpDir.'/outputs';
        $digest = 'sha256:'.str_repeat('c', 64);
        $releaseCommit = str_repeat('b', 40);
        $runId = '27420890537';
        $runAttempt = '2';
        $workflowRef = '2.0.0-alpha.236';
        $workflowCommit = '35b8ea0dc5e189b392240b5fef96f2ee0295ebde';
        $imageConfig = json_encode([
            'config' => [
                'Labels' => [
                    'org.opencontainers.image.revision' => $releaseCommit,
                    'dev.durable-workflow.release.tag' => '0.2.396',
                    'dev.durable-workflow.release.run-id' => $runId,
                    'dev.durable-workflow.release.run-attempt' => $runAttempt,
                    'dev.durable-workflow.workflow.version' => $workflowRef,
                    'dev.durable-workflow.workflow.commit' => $workflowCommit,
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $dockerScript = <<<SH
#!/usr/bin/env sh
if [ "\$1" = "buildx" ] && [ "\$2" = "imagetools" ] && [ "\$3" = "inspect" ]; then
    if [ "\${4:-}" = "--format" ]; then
        printf '%s\\n' '{$imageConfig}'
        exit 0
    fi
    printf 'Name: %s\\nMediaType: application/vnd.oci.image.index.v1+json\\nDigest: {$digest}\\n\\nManifests:\\n  Platform: linux/amd64\\n  Platform: linux/arm64\\n' "\$4"
    exit 0
fi
exit 1
SH;
        file_put_contents($dockerBin, $dockerScript);
        chmod($dockerBin, 0755);

        try {
            $result = $this->runScript('scripts/ci/verify-release-exact-images.sh', [
                'RELEASE_TAG' => '0.2.396',
                'DOCKER' => $dockerBin,
                'DOCKER_BUILD_OUTCOME' => 'failure',
                'RELEASE_COMMIT' => $releaseCommit,
                'RELEASE_RUN_ID' => $runId,
                'RELEASE_RUN_ATTEMPT' => $runAttempt,
                'WORKFLOW_PACKAGE_REF' => $workflowRef,
                'WORKFLOW_PACKAGE_COMMIT' => $workflowCommit,
                'GITHUB_OUTPUT' => $outputFile,
            ]);

            $this->assertSame(0, $result['exitCode']);
            $outputs = file_get_contents($outputFile);
            $this->assertNotFalse($outputs);
            $this->assertStringContainsString("exact_publish_outcome=success\n", $outputs);
            $this->assertStringContainsString("image_digest={$digest}\n", $outputs);
            $this->assertStringContainsString('carry this release run metadata and use the same manifest digest', $result['stdout']);
        } finally {
            @unlink($dockerBin);
            @unlink($outputFile);
            @rmdir($tmpDir);
        }
    }

    public function test_exact_image_verifier_fails_when_public_tags_cannot_be_matched_to_build_digest(): void
    {
        $tmpDir = sys_get_temp_dir().'/release-image-docker-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($tmpDir));
        $dockerBin = $tmpDir.'/docker';
        $outputFile = $tmpDir.'/outputs';
        $dockerScript = <<<'SH'
#!/usr/bin/env sh
if [ "$1" = "buildx" ] && [ "$2" = "imagetools" ] && [ "$3" = "inspect" ]; then
    printf 'Name: %s\nMediaType: application/vnd.oci.image.index.v1+json\nDigest: sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc\n\nManifests:\n  Platform: linux/amd64\n  Platform: linux/arm64\n' "$4"
    exit 0
fi
exit 1
SH;
        file_put_contents($dockerBin, $dockerScript);
        chmod($dockerBin, 0755);

        try {
            $result = $this->runScript('scripts/ci/verify-release-exact-images.sh', [
                'RELEASE_TAG' => '0.2.396',
                'DOCKER' => $dockerBin,
                'DOCKER_BUILD_OUTCOME' => 'failure',
                'GITHUB_OUTPUT' => $outputFile,
            ]);

            $this->assertSame(1, $result['exitCode']);
            $outputs = file_get_contents($outputFile);
            $this->assertNotFalse($outputs);
            $this->assertStringContainsString("exact_publish_outcome=failure\n", $outputs);
            $this->assertStringContainsString("exact_publish_reason=exact_build_metadata_digest_missing\n", $outputs);
            $this->assertStringContainsString('did not expose a digest or containerimage.digest metadata', $result['stderr']);
        } finally {
            @unlink($dockerBin);
            @unlink($outputFile);
            @rmdir($tmpDir);
        }
    }

    public function test_exact_image_verifier_fails_when_registry_digests_do_not_match(): void
    {
        $tmpDir = sys_get_temp_dir().'/release-image-docker-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($tmpDir));
        $dockerBin = $tmpDir.'/docker';
        $outputFile = $tmpDir.'/outputs';
        $dockerHubDigest = 'sha256:'.str_repeat('c', 64);
        $ghcrDigest = 'sha256:'.str_repeat('d', 64);
        $dockerScript = <<<SH
#!/usr/bin/env sh
if [ "\$1" = "buildx" ] && [ "\$2" = "imagetools" ] && [ "\$3" = "inspect" ]; then
    digest="{$dockerHubDigest}"
    case "\$4" in
        ghcr.io/*) digest="{$ghcrDigest}" ;;
    esac
    printf 'Name: %s\\nMediaType: application/vnd.oci.image.index.v1+json\\nDigest: %s\\n\\nManifests:\\n  Platform: linux/amd64\\n  Platform: linux/arm64\\n' "\$4" "\$digest"
    exit 0
fi
exit 1
SH;
        file_put_contents($dockerBin, $dockerScript);
        chmod($dockerBin, 0755);

        try {
            $result = $this->runScript('scripts/ci/verify-release-exact-images.sh', [
                'RELEASE_TAG' => '0.2.396',
                'DOCKER' => $dockerBin,
                'BUILT_IMAGE_DIGEST' => $dockerHubDigest,
                'GITHUB_OUTPUT' => $outputFile,
            ]);

            $this->assertSame(1, $result['exitCode']);
            $outputs = file_get_contents($outputFile);
            $this->assertNotFalse($outputs);
            $this->assertStringContainsString("exact_publish_outcome=failure\n", $outputs);
            $this->assertStringContainsString("exact_publish_reason=exact_manifest_digest_mismatch\n", $outputs);
            $this->assertStringContainsString('not identical across registries', $result['stderr']);
        } finally {
            @unlink($dockerBin);
            @unlink($outputFile);
            @rmdir($tmpDir);
        }
    }

    public function test_exact_image_verifier_fails_when_public_digest_does_not_match_build_digest(): void
    {
        $tmpDir = sys_get_temp_dir().'/release-image-docker-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($tmpDir));
        $dockerBin = $tmpDir.'/docker';
        $outputFile = $tmpDir.'/outputs';
        $buildDigest = 'sha256:'.str_repeat('c', 64);
        $publicDigest = 'sha256:'.str_repeat('d', 64);
        $dockerScript = <<<SH
#!/usr/bin/env sh
if [ "\$1" = "buildx" ] && [ "\$2" = "imagetools" ] && [ "\$3" = "inspect" ]; then
    printf 'Name: %s\\nMediaType: application/vnd.oci.image.index.v1+json\\nDigest: {$publicDigest}\\n\\nManifests:\\n  Platform: linux/amd64\\n  Platform: linux/arm64\\n' "\$4"
    exit 0
fi
exit 1
SH;
        file_put_contents($dockerBin, $dockerScript);
        chmod($dockerBin, 0755);

        try {
            $result = $this->runScript('scripts/ci/verify-release-exact-images.sh', [
                'RELEASE_TAG' => '0.2.396',
                'DOCKER' => $dockerBin,
                'BUILT_IMAGE_DIGEST' => $buildDigest,
                'GITHUB_OUTPUT' => $outputFile,
            ]);

            $this->assertSame(1, $result['exitCode']);
            $outputs = file_get_contents($outputFile);
            $this->assertNotFalse($outputs);
            $this->assertStringContainsString("exact_publish_outcome=failure\n", $outputs);
            $this->assertStringContainsString("exact_publish_reason=exact_manifest_build_digest_mismatch\n", $outputs);
            $this->assertStringContainsString('this release build produced', $result['stderr']);
        } finally {
            @unlink($dockerBin);
            @unlink($outputFile);
            @rmdir($tmpDir);
        }
    }

    public function test_exact_image_verifier_fails_when_required_platform_is_missing(): void
    {
        $tmpDir = sys_get_temp_dir().'/release-image-docker-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($tmpDir));
        $dockerBin = $tmpDir.'/docker';
        $outputFile = $tmpDir.'/outputs';
        $dockerScript = <<<'SH'
#!/usr/bin/env sh
if [ "$1" = "buildx" ] && [ "$2" = "imagetools" ] && [ "$3" = "inspect" ]; then
    printf 'Name: %s\nMediaType: application/vnd.oci.image.index.v1+json\nDigest: sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd\n\nManifests:\n  Platform: linux/amd64\n' "$4"
    exit 0
fi
exit 1
SH;
        file_put_contents($dockerBin, $dockerScript);
        chmod($dockerBin, 0755);

        try {
            $result = $this->runScript('scripts/ci/verify-release-exact-images.sh', [
                'RELEASE_TAG' => '0.2.396',
                'DOCKER' => $dockerBin,
                'BUILT_IMAGE_DIGEST' => 'sha256:'.str_repeat('d', 64),
                'GITHUB_OUTPUT' => $outputFile,
            ]);

            $this->assertSame(1, $result['exitCode']);
            $outputs = file_get_contents($outputFile);
            $this->assertNotFalse($outputs);
            $this->assertStringContainsString("exact_publish_outcome=failure\n", $outputs);
            $this->assertStringContainsString("exact_publish_reason=exact_manifest_platform_missing\n", $outputs);
            $this->assertStringContainsString('linux/arm64', $result['stderr']);
        } finally {
            @unlink($dockerBin);
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
            $this->assertSame('success', $decoded['exact_publish']['outcome']);
            $this->assertContains('durableworkflow/server:0.2.177', $decoded['exact_refs']);
            $this->assertSame('0.2.178', $decoded['rolling']['superseded_by']);
            $this->assertSame('superseded_by_newer_release', $decoded['rolling']['reason']);
            $this->assertSame([], $decoded['rolling']['refs']);
            $this->assertContains('durableworkflow/server:0.2', $decoded['rolling']['skipped_refs']);
            $this->assertContains('ghcr.io/durable-workflow/server:latest', $decoded['rolling']['skipped_refs']);
        } finally {
            @unlink($evidenceFile);
        }
    }

    public function test_evidence_does_not_advertise_artifact_versions_when_exact_publish_failed(): void
    {
        $evidenceFile = tempnam(sys_get_temp_dir(), 'release-image-evidence-');
        $this->assertIsString($evidenceFile);

        try {
            $result = $this->runScript('scripts/ci/write-release-image-publish-evidence.sh', [
                'RELEASE_IMAGE_EVIDENCE_PATH' => $evidenceFile,
                'RELEASE_TAG' => '0.2.396',
                'VALIDATION_OUTCOME' => 'success',
                'EXACT_PUBLISH_OUTCOME' => 'failure',
                'EXACT_PUBLISH_REASON' => 'exact_manifest_missing',
                'EXACT_VERIFY_OUTCOME' => 'failure',
                'DOCKER_BUILD_OUTCOME' => 'failure',
                'ROLLING_GUARD_OUTCOME' => 'skipped',
                'ROLLING_PROMOTE_OUTCOME' => 'skipped',
                'ROLLING_SHOULD_PROMOTE' => 'false',
                'RELEASE_COMMIT' => str_repeat('b', 40),
                'RELEASE_RUN_ID' => '27420890537',
                'RELEASE_RUN_ATTEMPT' => '1',
            ]);

            $this->assertSame(0, $result['exitCode']);
            $decoded = json_decode((string) file_get_contents($evidenceFile), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('failed', $decoded['status']);
            $this->assertSame('exact_manifest_missing', $decoded['reason']);
            $this->assertSame([], $decoded['artifact_versions']);
            $this->assertContains('durableworkflow/server:0.2.396', $decoded['expected_exact_refs']);
            $this->assertContains('ghcr.io/durable-workflow/server:0.2.396', $decoded['expected_exact_refs']);
            $this->assertSame([], $decoded['exact_refs']);
            $this->assertSame('failure', $decoded['exact_publish']['outcome']);
            $this->assertSame('failure', $decoded['exact_publish']['build_step_outcome']);
            $this->assertSame('failure', $decoded['exact_publish']['verification_outcome']);
            $this->assertSame('exact_image_publish_not_verified', $decoded['rolling']['reason']);
            $this->assertSame([], $decoded['rolling']['refs']);
        } finally {
            @unlink($evidenceFile);
        }
    }

    public function test_evidence_records_success_when_exact_manifests_are_verified_after_build_failure(): void
    {
        $evidenceFile = tempnam(sys_get_temp_dir(), 'release-image-evidence-');
        $this->assertIsString($evidenceFile);
        $digest = 'sha256:'.str_repeat('e', 64);

        try {
            $result = $this->runScript('scripts/ci/write-release-image-publish-evidence.sh', [
                'RELEASE_IMAGE_EVIDENCE_PATH' => $evidenceFile,
                'RELEASE_TAG' => '0.2.396',
                'VALIDATION_OUTCOME' => 'success',
                'EXACT_PUBLISH_OUTCOME' => 'success',
                'EXACT_PUBLISH_REASON' => 'exact_manifests_verified_after_build_step_failure',
                'EXACT_VERIFY_OUTCOME' => 'success',
                'DOCKER_BUILD_OUTCOME' => 'failure',
                'ROLLING_GUARD_OUTCOME' => 'success',
                'ROLLING_PROMOTE_OUTCOME' => 'success',
                'ROLLING_ARTIFACT_STATUS' => 'current',
                'ROLLING_SHOULD_PROMOTE' => 'true',
                'IMAGE_DIGEST' => $digest,
                'RELEASE_COMMIT' => str_repeat('b', 40),
                'RELEASE_RUN_ID' => '27420890537',
                'RELEASE_RUN_ATTEMPT' => '2',
            ]);

            $this->assertSame(0, $result['exitCode']);
            $decoded = json_decode((string) file_get_contents($evidenceFile), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('current', $decoded['status']);
            $this->assertNull($decoded['reason']);
            $this->assertSame(['server' => 'durableworkflow/server:0.2.396'], $decoded['artifact_versions']);
            $this->assertSame($digest, $decoded['digest']);
            $this->assertSame('success', $decoded['exact_publish']['outcome']);
            $this->assertSame('failure', $decoded['exact_publish']['build_step_outcome']);
            $this->assertSame('success', $decoded['exact_publish']['verification_outcome']);
            $this->assertSame('exact_manifests_verified_after_build_step_failure', $decoded['exact_publish']['reason']);
            $this->assertSame(['linux/amd64', 'linux/arm64'], $decoded['exact_publish']['required_platforms']);
            $this->assertContains('durableworkflow/server:0.2.396', $decoded['exact_refs']);
            $this->assertContains('ghcr.io/durable-workflow/server:0.2.396', $decoded['exact_refs']);
            $this->assertNull($decoded['rolling']['reason']);
            $this->assertContains('durableworkflow/server:latest', $decoded['rolling']['refs']);
            $this->assertContains('ghcr.io/durable-workflow/server:latest', $decoded['rolling']['refs']);
        } finally {
            @unlink($evidenceFile);
        }
    }

    public function test_evidence_records_selected_workflow_package_metadata(): void
    {
        $evidenceFile = tempnam(sys_get_temp_dir(), 'release-image-evidence-');
        $this->assertIsString($evidenceFile);
        $workflowCommit = '35b8ea0dc5e189b392240b5fef96f2ee0295ebde';

        try {
            $result = $this->runScript('scripts/ci/write-release-image-publish-evidence.sh', [
                'RELEASE_IMAGE_EVIDENCE_PATH' => $evidenceFile,
                'RELEASE_TAG' => '0.2.372',
                'VALIDATION_OUTCOME' => 'success',
                'EXACT_PUBLISH_OUTCOME' => 'success',
                'ROLLING_GUARD_OUTCOME' => 'success',
                'ROLLING_PROMOTE_OUTCOME' => 'skipped',
                'ROLLING_ARTIFACT_STATUS' => 'current',
                'IMAGE_DIGEST' => 'sha256:'.str_repeat('a', 64),
                'RELEASE_COMMIT' => str_repeat('b', 40),
                'RELEASE_RUN_ID' => '12345',
                'RELEASE_RUN_ATTEMPT' => '2',
                'WORKFLOW_PACKAGE_REF' => '2.0.0-alpha.236',
                'WORKFLOW_PACKAGE_COMMIT' => $workflowCommit,
            ]);

            $this->assertSame(0, $result['exitCode']);
            $decoded = json_decode((string) file_get_contents($evidenceFile), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(
                'durable-workflow/workflow:2.0.0-alpha.236',
                $decoded['artifact_versions']['workflow-php'],
            );
            $this->assertSame('durable-workflow/workflow', $decoded['workflow_package']['name']);
            $this->assertSame('https://github.com/durable-workflow/workflow.git', $decoded['workflow_package']['source']);
            $this->assertSame('2.0.0-alpha.236', $decoded['workflow_package']['version']);
            $this->assertSame($workflowCommit, $decoded['workflow_package']['commit']);
        } finally {
            @unlink($evidenceFile);
        }
    }

    public function test_workflow_package_selector_picks_newest_prerelease_matching_server_protocol(): void
    {
        $outputFile = tempnam(sys_get_temp_dir(), 'workflow-package-output-');
        $this->assertIsString($outputFile);
        $selectedCommit = '35b8ea0dc5e189b392240b5fef96f2ee0295ebde';

        try {
            $result = $this->runScript('scripts/ci/select-compatible-workflow-package-ref.sh', [
                'WORKFLOW_PACKAGE_KNOWN_TAGS' => implode("\n", [
                    'refs/tags/2.0.0-alpha.196',
                    'refs/tags/2.0.0-alpha.198',
                    'refs/tags/2.0.0-alpha.199',
                    'refs/tags/2.0.0-alpha.200',
                    'refs/tags/2.0.0-alpha.217',
                    'refs/tags/2.0.0-alpha.218',
                    'refs/tags/2.0.0-alpha.236',
                    'refs/tags/2.0.0-alpha.be7ddbc37b41',
                    'refs/tags/1.0.0-alpha.1',
                ]),
                'WORKFLOW_PACKAGE_PROTOCOL_VERSIONS' => implode("\n", [
                    '2.0.0-alpha.196=1.9',
                    '2.0.0-alpha.198=1.9',
                    '2.0.0-alpha.199=1.10',
                    '2.0.0-alpha.200=1.10',
                    '2.0.0-alpha.217=1.10',
                    '2.0.0-alpha.218=1.10',
                    '2.0.0-alpha.236=1.12',
                ]),
                'WORKFLOW_PACKAGE_TAG_COMMITS' => implode("\n", [
                    '2.0.0-alpha.236='.$selectedCommit,
                ]),
                'GITHUB_OUTPUT' => $outputFile,
            ]);

            $this->assertSame(0, $result['exitCode']);
            $outputs = file_get_contents($outputFile);
            $this->assertNotFalse($outputs);
            $this->assertStringContainsString("tag=2.0.0-alpha.236\n", $outputs);
            $this->assertStringContainsString("protocol=1.12\n", $outputs);
            $this->assertStringContainsString("server_protocol=1.12\n", $outputs);
            $this->assertStringContainsString("commit={$selectedCommit}\n", $outputs);
            $this->assertStringContainsString(
                'Using workflow package version: 2.0.0-alpha.236 (worker protocol 1.12, server requires 1.12)',
                $result['stdout'],
            );
            $this->assertStringContainsString($selectedCommit, $result['stdout']);
        } finally {
            @unlink($outputFile);
        }
    }

    public function test_workflow_package_selector_honors_pinned_ref_and_commit(): void
    {
        $outputFile = tempnam(sys_get_temp_dir(), 'workflow-package-output-');
        $this->assertIsString($outputFile);
        $selectedCommit = '35b8ea0dc5e189b392240b5fef96f2ee0295ebde';

        try {
            $result = $this->runScript('scripts/ci/select-compatible-workflow-package-ref.sh', [
                'WORKFLOW_PACKAGE_REF' => '2.0.0-alpha.236',
                'WORKFLOW_PACKAGE_COMMIT' => $selectedCommit,
                'WORKFLOW_PACKAGE_PROTOCOL_VERSIONS' => '2.0.0-alpha.236=1.12',
                'WORKFLOW_PACKAGE_TAG_COMMITS' => '2.0.0-alpha.236='.$selectedCommit,
                'GITHUB_OUTPUT' => $outputFile,
            ]);

            $this->assertSame(0, $result['exitCode']);
            $outputs = file_get_contents($outputFile);
            $this->assertNotFalse($outputs);
            $this->assertStringContainsString("tag=2.0.0-alpha.236\n", $outputs);
            $this->assertStringContainsString("protocol=1.12\n", $outputs);
            $this->assertStringContainsString("server_protocol=1.12\n", $outputs);
            $this->assertStringContainsString("commit={$selectedCommit}\n", $outputs);
            $this->assertStringContainsString(
                'Using workflow package version: 2.0.0-alpha.236 (worker protocol 1.12, server requires 1.12)',
                $result['stdout'],
            );
        } finally {
            @unlink($outputFile);
        }
    }

    public function test_workflow_package_selector_fails_when_no_compatible_prerelease_exists(): void
    {
        $result = $this->runScript('scripts/ci/select-compatible-workflow-package-ref.sh', [
            'WORKFLOW_PACKAGE_KNOWN_TAGS' => implode("\n", [
                'refs/tags/2.0.0-alpha.198',
            ]),
            'WORKFLOW_PACKAGE_PROTOCOL_VERSIONS' => implode("\n", [
                '2.0.0-alpha.198=1.9',
            ]),
        ]);

        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString(
            'No compatible durable-workflow/workflow prerelease tag found for server worker protocol 1.12',
            $result['stderr'],
        );
        $this->assertStringContainsString('2.0.0-alpha.198 advertises worker protocol 1.9', $result['stderr']);
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
