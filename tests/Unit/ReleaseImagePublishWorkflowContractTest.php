<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Workflow\V2\Support\PlatformProtocolSpecs;

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
            'scripts/ci/validate-release-image-publish.sh',
            'scripts/ci/sync-source-release.mjs --root release-source --check',
            'scripts/ci/resolve-workflow-package-authority.php',
            'DOCKERHUB_USERNAME: ${{ secrets.DOCKERHUB_USERNAME }}',
            'DOCKERHUB_TOKEN: ${{ secrets.DOCKERHUB_TOKEN }}',
            'GHCR_TOKEN: ${{ secrets.GITHUB_TOKEN }}',
            'push: true',
        ] as $needle) {
            $this->assertStringContainsString($needle, $workflow);
        }

        $parsedWorkflow = Yaml::parse($workflow);
        $this->assertIsArray($parsedWorkflow);
        $publishCondition = $parsedWorkflow['jobs']['publish']['if'] ?? null;
        $this->assertIsString($publishCondition);
        $this->assertSame(
            "(github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main') "
            ."|| (github.event_name == 'push' && startsWith(github.ref, 'refs/tags/'))",
            preg_replace('/\s+/', ' ', trim($publishCondition)),
        );

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

    public function test_release_workflow_resolves_locked_workflow_package_before_docker_work(): void
    {
        $workflow = $this->read('.github/workflows/release.yml');

        $this->assertStringContainsString('Resolve locked workflow package authority', $workflow);
        $this->assertStringContainsString('id: workflow', $workflow);
        $this->assertStringContainsString('scripts/ci/resolve-workflow-package-authority.php', $workflow);
        $this->assertStringContainsString('COMPOSER_JSON_PATH: release-source/composer.json', $workflow);
        $this->assertStringContainsString('WORKFLOW_PACKAGE_REF=${{ steps.workflow.outputs.ref }}', $workflow);
        $this->assertStringNotContainsString('Get latest workflow package version', $workflow);
        $this->assertStringNotContainsString('LATEST_TAG=$(git ls-remote', $workflow);
        $this->assertStringNotContainsString('Select compatible workflow package version', $workflow);

        $selectorOffset = strpos($workflow, 'Resolve locked workflow package authority');
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
            'dev.durable-workflow.workflow.source=${{ steps.workflow.outputs.source }}',
            'dev.durable-workflow.workflow.version=${{ steps.workflow.outputs.ref }}',
            'dev.durable-workflow.workflow.commit=${{ steps.workflow.outputs.commit }}',
            'WORKFLOW_PACKAGE_SOURCE=${{ steps.workflow.outputs.source }}',
            'WORKFLOW_PACKAGE_REF=${{ steps.workflow.outputs.ref }}',
            'WORKFLOW_PACKAGE_COMMIT=${{ steps.workflow.outputs.commit }}',
            'WORKFLOW_PACKAGE_SOURCE: ${{ steps.workflow.outputs.source }}',
            'WORKFLOW_PACKAGE_REF: ${{ steps.workflow.outputs.ref }}',
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
            'ARG WORKFLOW_PACKAGE_REF',
            'ARG WORKFLOW_PACKAGE_COMMIT',
            'ARG WORKFLOW_PACKAGE_QUALIFICATION_REF',
            'resolve-workflow-package-authority.php --format=shell',
            'git -C /workflow fetch --depth 1 origin "${WORKFLOW_PACKAGE_COMMIT}"',
            'if [ "${RESOLVED_COMMIT}" != "${WORKFLOW_PACKAGE_COMMIT}" ]',
            'git -C /workflow diff --quiet HEAD --',
            'prepare-release-workflow-composer-metadata.php',
            'composer update durable-workflow/workflow',
            'cp composer.json /tmp/release-composer.json',
            'cp composer.lock /tmp/release-composer.lock',
            'cp /tmp/release-composer.json composer.json',
            'cp /tmp/release-composer.lock composer.lock',
            'cmp /workflow/.package-provenance vendor/durable-workflow/workflow/.package-provenance',
            'cp vendor/durable-workflow/workflow/.package-provenance /app/.package-provenance',
            'dev.durable-workflow.package.authority="composer.lock"',
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

        $this->assertStringContainsString('WorkflowPackageAuthority::resolve', $metadataScript);
        $this->assertStringContainsString('$composer[\'require\'][$packageName] = $composerVersion;', $metadataScript);
        $this->assertStringContainsString("'url' => \$workflowPath", $metadataScript);
        $this->assertStringContainsString('$packageName => $composerVersion', $metadataScript);
        $this->assertStringContainsString("'reference' => 'auto'", $metadataScript);
        $this->assertStringContainsString('WORKFLOW_PACKAGE_COMMIT', $metadataScript);
        $this->assertStringContainsString('Workflow package provenance {$provenancePath} does not exist.', $metadataScript);
    }

    public function test_source_admission_can_fetch_the_landed_commit_without_weakening_release_provenance(): void
    {
        $dockerfile = $this->read('Dockerfile');
        $releaseWorkflow = $this->read('.github/workflows/release.yml');
        $replayWorkflow = $this->read('.github/workflows/replay-query-concurrent-http.yml');
        $helmWorkflow = $this->read('.github/workflows/helm-chart-checks.yml');
        $helmSmoke = $this->read('scripts/helm-chart-kind-smoke.sh');

        foreach ([
            'ARG WORKFLOW_PACKAGE_QUALIFICATION_REF',
            'resolve-workflow-package-authority.php --format=shell',
            'git -C /workflow fetch --depth 1 origin "${WORKFLOW_PACKAGE_COMMIT}"',
            'git -C /workflow checkout --detach FETCH_HEAD',
            'if [ "${RESOLVED_COMMIT}" != "${WORKFLOW_PACKAGE_COMMIT}" ]',
        ] as $needle) {
            $this->assertStringContainsString($needle, $dockerfile);
        }

        foreach ([$replayWorkflow, $helmWorkflow] as $workflow) {
            $this->assertStringContainsString('ACTIONS_SERVER_URL: ${{ github.server_url }}', $workflow);
            $this->assertStringContainsString('WORKFLOW_PACKAGE_COMMIT: ${{ steps.workflow.outputs.commit }}', $workflow);
            $this->assertStringContainsString('[[ "$ACTIONS_SERVER_URL" != "https://github.com" ]]', $workflow);
            $this->assertStringContainsString('WORKFLOW_PACKAGE_QUALIFICATION_REF', $workflow);
        }

        $this->assertStringContainsString(
            '--build-arg "WORKFLOW_PACKAGE_QUALIFICATION_REF=${WORKFLOW_PACKAGE_QUALIFICATION_REF}"',
            $helmSmoke,
        );
        $this->assertStringNotContainsString('WORKFLOW_PACKAGE_QUALIFICATION_REF', $releaseWorkflow);
    }

    public function test_dockerfile_cannot_replace_workflow_checkout_with_forged_provenance(): void
    {
        $dockerfile = $this->read('Dockerfile');

        $this->assertStringNotContainsString('AS workflow-source', $dockerfile);
        $this->assertStringNotContainsString('AS vendor', $dockerfile);
        $this->assertStringNotContainsString('COPY --from=workflow-source', $dockerfile);
        $this->assertStringNotContainsString('COPY --from=vendor', $dockerfile);
        $this->assertStringNotContainsString('--build-context workflow-source=', $dockerfile);
        $this->assertStringNotContainsString('--build-context vendor=', $dockerfile);
        $this->assertDoesNotMatchRegularExpression(
            '/^COPY\s+--from=\S+\s+\/(?:workflow|app)(?:\/\S*)?\s+/m',
            $dockerfile,
            'Verified Workflow source and the installed application must not cross a named-stage boundary.',
        );

        $productionOffset = strpos($dockerfile, 'FROM base AS production');
        $cloneOffset = strpos($dockerfile, 'git -C /workflow fetch --depth 1 origin "${WORKFLOW_PACKAGE_COMMIT}"');
        $verifyOffset = strpos($dockerfile, 'git -C /workflow rev-parse HEAD');
        $cleanOffset = strpos($dockerfile, 'git -C /workflow diff --quiet HEAD --');
        $provenanceOffset = strpos($dockerfile, '> /workflow/.package-provenance');
        $metadataOffset = strpos($dockerfile, 'RUN php scripts/ci/prepare-release-workflow-composer-metadata.php');
        $composerUpdateOffset = strpos($dockerfile, 'composer update durable-workflow/workflow');
        $copyApplicationOffset = strpos($dockerfile, "COPY . .\n");
        $compareInstalledProvenanceOffset = strpos(
            $dockerfile,
            'cmp /workflow/.package-provenance vendor/durable-workflow/workflow/.package-provenance',
        );
        $publishProvenanceOffset = strpos(
            $dockerfile,
            'cp vendor/durable-workflow/workflow/.package-provenance /app/.package-provenance',
        );
        $removeGitOffset = strpos($dockerfile, 'rm -rf /workflow/.git');

        $this->assertIsInt($productionOffset);
        $this->assertIsInt($cloneOffset);
        $this->assertIsInt($verifyOffset);
        $this->assertIsInt($cleanOffset);
        $this->assertIsInt($provenanceOffset);
        $this->assertIsInt($metadataOffset);
        $this->assertIsInt($composerUpdateOffset);
        $this->assertIsInt($copyApplicationOffset);
        $this->assertIsInt($compareInstalledProvenanceOffset);
        $this->assertIsInt($publishProvenanceOffset);
        $this->assertIsInt($removeGitOffset);
        $this->assertStringNotContainsString(
            'COPY --from=',
            substr($dockerfile, $productionOffset),
            'The final stage must build its verified Workflow package and application without a named-stage copy.',
        );
        $lastStageOffset = strrpos($dockerfile, "\nFROM ");
        $this->assertIsInt($lastStageOffset);
        $this->assertSame($productionOffset, $lastStageOffset + 1);
        $this->assertLessThan($cloneOffset, $productionOffset);
        $this->assertLessThan($verifyOffset, $cloneOffset);
        $this->assertLessThan($cleanOffset, $verifyOffset);
        $this->assertLessThan($provenanceOffset, $cleanOffset);
        $this->assertLessThan($metadataOffset, $provenanceOffset);
        $this->assertLessThan($composerUpdateOffset, $metadataOffset);
        $this->assertLessThan($removeGitOffset, $composerUpdateOffset);
        $this->assertLessThan($copyApplicationOffset, $removeGitOffset);
        $this->assertLessThan($compareInstalledProvenanceOffset, $copyApplicationOffset);
        $this->assertLessThan($publishProvenanceOffset, $compareInstalledProvenanceOffset);
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
            'docker-php-ext-install opcache redis pdo pdo_mysql pdo_pgsql pcntl zip bcmath',
        ] as $needle) {
            $this->assertStringContainsString($needle, $dockerfile);
        }

        $this->assertStringNotContainsString('pecl install redis', $dockerfile);
        $this->assertStringNotContainsString('pecl.php.net/redis', $dockerfile);
    }

    public function test_standalone_apache_and_cli_processes_share_compiled_application_bytecode(): void
    {
        $dockerfile = $this->read('Dockerfile');
        $phpConfig = $this->read('docker/php-custom.ini');

        $this->assertStringContainsString('FROM php:8.3-apache AS base', $dockerfile);
        $this->assertStringContainsString('docker-php-ext-install opcache', $dockerfile);
        $this->assertStringContainsString('opcache.enable = 1', $phpConfig);
        $this->assertStringContainsString('opcache.enable_cli = 1', $phpConfig);
        $this->assertStringContainsString('opcache.max_accelerated_files = 20000', $phpConfig);
    }

    public function test_standalone_image_uses_a_bounded_concurrent_apache_runtime(): void
    {
        $dockerfile = $this->read('Dockerfile');
        $apacheMpm = $this->read('docker/apache-mpm-prefork.conf');
        $apacheVhost = $this->read('docker/apache-vhost.conf');
        $regression = $this->read('scripts/regression/replay-query-concurrent-http.sh');

        $this->assertStringContainsString('CMD ["apache2-foreground"]', $dockerfile);
        $this->assertStringNotContainsString('CMD ["php", "artisan", "serve"', $dockerfile);
        $this->assertStringNotContainsString('php -S', $dockerfile);
        $this->assertStringContainsString('DW_WORKER_LONG_POLL_MAX_CONCURRENT=2', $dockerfile);
        $this->assertStringContainsString('DW_QUERY_TASK_POLL_MAX_CONCURRENT=1', $dockerfile);
        $this->assertStringContainsString('groupmod --gid 1000 www-data', $dockerfile);
        $this->assertStringContainsString('usermod --uid 1000 --gid 1000 www-data', $dockerfile);
        $this->assertStringContainsString('chown -R www-data:www-data', $dockerfile);
        $this->assertStringContainsString('database \\', $dockerfile);
        $this->assertStringContainsString('/var/run/apache2', $dockerfile);
        $this->assertStringContainsString('StartServers             2', $apacheMpm);
        $this->assertStringContainsString('MinSpareServers          2', $apacheMpm);
        $this->assertStringContainsString('MaxSpareServers          4', $apacheMpm);
        $this->assertStringContainsString('MaxRequestWorkers       24', $apacheMpm);
        $this->assertStringContainsString('<VirtualHost *:8080>', $apacheVhost);
        $this->assertStringContainsString('DocumentRoot /app/public', $apacheVhost);
        $this->assertStringContainsString('FallbackResource /index.php', $apacheVhost);
        $this->assertStringContainsString('query-tasks)/poll$" dontlog', $apacheVhost);
        $this->assertStringContainsString('combined env=!dontlog', $apacheVhost);
        $this->assertStringContainsString('</proc/1/cmdline', $regression);
        $this->assertStringContainsString("*apache2*'-DFOREGROUND'*", $regression);
        $this->assertStringContainsString('source "$ROOT_DIR/scripts/regression/apache-module-preflight.sh"', $regression);
        $this->assertStringContainsString('verify_apache_mod_php compose exec -T server apache2ctl -M', $regression);
        $this->assertStringNotContainsString("apache2ctl -M 2>/dev/null | grep -q 'php_module'", $regression);
        $this->assertStringContainsString('compose exec -T --user 1000:1000 server', $regression);
        $this->assertStringContainsString('--user 1000:1000', $regression);
        $this->assertStringContainsString('--cap-drop ALL', $regression);
        $this->assertStringContainsString('curl -fsS http://127.0.0.1:8080/api/ready', $regression);
        $this->assertStringContainsString('-X POST http://127.0.0.1:8080/api/namespaces', $regression);
        $this->assertStringContainsString('"/api/service-endpoints"', $regression);
        $this->assertStringContainsString('"/api/service-endpoints/shared-greeter/services"', $regression);
        $this->assertStringContainsString('"/api/service-endpoints/shared-greeter/services/Greeter/operations"', $regression);
        $this->assertStringContainsString('apache_service_registration_completed', $regression);
        $this->assertStringContainsString('compose logs --no-color --tail 160 server', $regression);
        $this->assertStringContainsString('redact_diagnostics', $regression);
        $this->assertStringContainsString('test -w database/database.sqlite', $regression);
        $this->assertStringContainsString('test "$(id -u)" = 1000', $regression);
        $this->assertStringContainsString('test "$(id -g)" = 1000', $regression);
    }

    public function test_dockerfile_installs_node_for_published_conformance_handoffs(): void
    {
        $dockerfile = $this->read('Dockerfile');
        $activitiesRunner = $this->read('scripts/conformance/activities-published-artifacts.sh');

        $this->assertStringContainsString('FROM php:8.3-apache AS base', $dockerfile);
        $this->assertStringContainsString('nodejs', $dockerfile);
        $this->assertStringContainsString('if ! require_command node; then', $activitiesRunner);
        $this->assertStringContainsString('required command not found: node', $activitiesRunner);

        $baseOffset = strpos($dockerfile, 'FROM php:8.3-apache AS base');
        $nodeOffset = strpos($dockerfile, 'nodejs');
        $productionOffset = strpos($dockerfile, 'FROM base AS production');

        $this->assertIsInt($baseOffset);
        $this->assertIsInt($nodeOffset);
        $this->assertIsInt($productionOffset);
        $this->assertLessThan($nodeOffset, $baseOffset);
        $this->assertLessThan($productionOffset, $nodeOffset);
    }

    public function test_dockerfile_installs_python_for_focused_activity_sdk_cells(): void
    {
        $dockerfile = $this->read('Dockerfile');
        $activitiesRunner = $this->read('scripts/conformance/activities-published-artifacts.sh');

        $this->assertStringContainsString('FROM php:8.3-apache AS base', $dockerfile);
        $this->assertStringContainsString('python3', $dockerfile);
        $this->assertStringContainsString('python3-venv', $dockerfile);
        $this->assertStringContainsString('prepare_focused_python_sdk', $activitiesRunner);
        $this->assertStringContainsString('python3 -m venv "$venv"', $activitiesRunner);
        $this->assertStringContainsString('"durable-workflow==${DW_PYTHON_SDK_VERSION}"', $activitiesRunner);
        $this->assertStringContainsString('run_python_activity_executor', $activitiesRunner);
        $this->assertStringContainsString('activity_host_evidence missing passing ${requiredMode}/${runtime} cell', $activitiesRunner);

        $baseOffset = strpos($dockerfile, 'FROM php:8.3-apache AS base');
        $pythonOffset = strpos($dockerfile, 'python3');
        $productionOffset = strpos($dockerfile, 'FROM base AS production');

        $this->assertIsInt($baseOffset);
        $this->assertIsInt($pythonOffset);
        $this->assertIsInt($productionOffset);
        $this->assertLessThan($pythonOffset, $baseOffset);
        $this->assertLessThan($productionOffset, $pythonOffset);
    }

    public function test_docker_build_compose_and_ci_derive_the_locked_workflow_package_authority(): void
    {
        foreach ([
            'Dockerfile',
            '.github/workflows/server-perf.yml',
            '.github/workflows/phpunit-feature.yml',
        ] as $path) {
            $source = $this->read($path);

            $this->assertStringContainsString(
                'resolve-workflow-package-authority.php',
                $source,
                "{$path} must resolve the locked Workflow package authority.",
            );
        }

        foreach ([
            'docker-compose.yml',
            'docker-compose.small-cluster.yml',
        ] as $path) {
            $source = $this->read($path);
            foreach (['SOURCE', 'REF', 'COMMIT'] as $field) {
                $this->assertStringContainsString(
                    "WORKFLOW_PACKAGE_{$field}: \${WORKFLOW_PACKAGE_{$field}:-}",
                    $source,
                    "{$path} must pass only an optional Workflow package {$field} override.",
                );
            }
        }
    }

    public function test_feature_ci_verifies_the_exact_workflow_source_before_removing_git_metadata(): void
    {
        $workflow = $this->read('.github/workflows/phpunit-feature.yml');

        foreach ([
            'Resolve locked workflow package authority',
            'ref: ${{ steps.workflow.outputs.commit }}',
            'WORKFLOW_PACKAGE_SOURCE: ${{ steps.workflow.outputs.source }}',
            'WORKFLOW_PACKAGE_REF: ${{ steps.workflow.outputs.ref }}',
            'WORKFLOW_PACKAGE_COMMIT: ${{ steps.workflow.outputs.commit }}',
            'git -C workflow-package rev-parse HEAD',
            'if [[ "$resolved_commit" != "$WORKFLOW_PACKAGE_COMMIT" ]]',
            '> workflow-package/.package-provenance',
            'rm -rf workflow-package/.git',
        ] as $needle) {
            $this->assertStringContainsString($needle, $workflow);
        }

        $verifyOffset = strpos($workflow, 'git -C workflow-package rev-parse HEAD');
        $removeOffset = strpos($workflow, 'rm -rf workflow-package/.git');
        $prepareOffset = strpos($workflow, 'php scripts/ci/prepare-release-workflow-composer-metadata.php');
        $updateOffset = strpos($workflow, 'composer update durable-workflow/workflow');
        $installOffset = strpos($workflow, 'composer install --no-interaction');

        $this->assertIsInt($verifyOffset);
        $this->assertIsInt($removeOffset);
        $this->assertIsInt($prepareOffset);
        $this->assertIsInt($updateOffset);
        $this->assertIsInt($installOffset);
        $this->assertLessThan($removeOffset, $verifyOffset);
        $this->assertLessThan($prepareOffset, $removeOffset);
        $this->assertLessThan($updateOffset, $prepareOffset);
        $this->assertLessThan($installOffset, $updateOffset);
        $this->assertLessThan($installOffset, $removeOffset);
    }

    public function test_release_workflow_pins_the_composer_authority_ref_and_commit(): void
    {
        $workflow = $this->read('.github/workflows/release.yml');

        $this->assertStringContainsString('WORKFLOW_PACKAGE_SOURCE: ${{ steps.workflow.outputs.source }}', $workflow);
        $this->assertStringContainsString('WORKFLOW_PACKAGE_REF: ${{ steps.workflow.outputs.ref }}', $workflow);
        $this->assertStringContainsString('WORKFLOW_PACKAGE_COMMIT: ${{ steps.workflow.outputs.commit }}', $workflow);
        $this->assertStringContainsString('scripts/ci/resolve-workflow-package-authority.php', $workflow);
        $this->assertStringNotContainsString('Select compatible workflow package version', $workflow);
    }

    public function test_manual_release_rerun_retains_the_requested_commit_at_each_publication_boundary(): void
    {
        $workflow = $this->read('.github/workflows/release.yml');

        foreach ([
            'release_commit:',
            "description: 'Full source commit for the existing immutable release tag'",
            'REQUESTED_COMMIT: ${{ github.event_name == \'workflow_dispatch\' && inputs.release_commit || \'\' }}',
            'Verify immutable release tag at publication boundary',
            '&& inputs.release_commit || steps.release_source.outputs.commit }}',
            'scripts/ci/verify-release-tag-source.sh',
            'Create the source GitHub Release',
        ] as $needle) {
            $this->assertStringContainsString($needle, $workflow);
        }

        $tagGuardOffset = strpos($workflow, 'Verify immutable release tag at publication boundary');
        $buildOffset = strpos($workflow, 'Build and push exact image tags');
        $releaseOffset = strpos($workflow, 'Create the source GitHub Release');

        $this->assertIsInt($tagGuardOffset);
        $this->assertIsInt($buildOffset);
        $this->assertIsInt($releaseOffset);
        $this->assertLessThan($buildOffset, $tagGuardOffset);
        $this->assertLessThan($releaseOffset, $buildOffset);
    }

    public function test_release_publisher_retries_an_initial_transient_view_before_creating_an_absent_release(): void
    {
        $result = $this->runGithubReleasePublisher([
            ['exitCode' => 1, 'stderr' => 'HTTP 502: Bad Gateway'],
            ['exitCode' => 1, 'stderr' => 'release not found'],
            ['exitCode' => 0, 'stdout' => 'https://github.com/durable-workflow/server/releases/tag/2.0.0-rc.36'],
        ]);

        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertSame([
            'release view 2.0.0-rc.36 --json tagName',
            'release view 2.0.0-rc.36 --json tagName',
            'release create 2.0.0-rc.36 --verify-tag --generate-notes --title 2.0.0-rc.36 --prerelease',
        ], $result['calls']);
    }

    public function test_release_publisher_retries_a_transient_post_create_view_and_accepts_the_existing_release(): void
    {
        $result = $this->runGithubReleasePublisher([
            ['exitCode' => 1, 'stderr' => 'HTTP 404: release not found'],
            ['exitCode' => 1, 'stderr' => 'HTTP 503: Service Unavailable'],
            ['exitCode' => 1, 'stderr' => 'HTTP 429: rate limit exceeded'],
            ['exitCode' => 0, 'stdout' => '{"tagName":"2.0.0-rc.36"}'],
        ]);

        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertSame([
            'release view 2.0.0-rc.36 --json tagName',
            'release create 2.0.0-rc.36 --verify-tag --generate-notes --title 2.0.0-rc.36 --prerelease',
            'release view 2.0.0-rc.36 --json tagName',
            'release view 2.0.0-rc.36 --json tagName',
        ], $result['calls']);
        $this->assertSame(1, count(array_filter(
            $result['calls'],
            static fn (string $call): bool => str_starts_with($call, 'release create '),
        )));
        $this->assertStringContainsString('already exists; no retry is needed', $result['stdout']);
    }

    public function test_release_publisher_fails_after_exhausting_transient_view_attempts_without_creating(): void
    {
        $result = $this->runGithubReleasePublisher([
            ['exitCode' => 1, 'stderr' => 'HTTP 500: Internal Server Error'],
            ['exitCode' => 1, 'stderr' => 'HTTP 502: Bad Gateway'],
            ['exitCode' => 1, 'stderr' => 'HTTP 503: Service Unavailable'],
        ], [
            'GITHUB_RELEASE_MAX_ATTEMPTS' => '3',
        ]);

        $this->assertSame(1, $result['exitCode']);
        $this->assertSame([
            'release view 2.0.0-rc.36 --json tagName',
            'release view 2.0.0-rc.36 --json tagName',
            'release view 2.0.0-rc.36 --json tagName',
        ], $result['calls']);
        $this->assertStringContainsString(
            'Transient GitHub API failure persisted for 3 attempts while checking exact tag 2.0.0-rc.36',
            $result['stderr'],
        );
    }

    public function test_release_publisher_keeps_non_transient_view_and_create_failures_terminal(): void
    {
        $viewRejected = $this->runGithubReleasePublisher([
            ['exitCode' => 1, 'stderr' => 'HTTP 401: Bad credentials'],
        ]);

        $this->assertSame(1, $viewRejected['exitCode']);
        $this->assertSame([
            'release view 2.0.0-rc.36 --json tagName',
        ], $viewRejected['calls']);

        $createRejected = $this->runGithubReleasePublisher([
            ['exitCode' => 1, 'stderr' => 'release not found'],
            ['exitCode' => 1, 'stderr' => 'HTTP 422: immutable tag validation failed'],
        ]);

        $this->assertSame(1, $createRejected['exitCode']);
        $this->assertSame([
            'release view 2.0.0-rc.36 --json tagName',
            'release create 2.0.0-rc.36 --verify-tag --generate-notes --title 2.0.0-rc.36 --prerelease',
        ], $createRejected['calls']);
    }

    public function test_published_release_recovery_verification_is_bound_to_exact_read_only_public_artifacts(): void
    {
        $source = $this->read('.github/workflows/published-release-recovery.yml');
        $workflow = Yaml::parse($source);
        $this->assertIsArray($workflow);

        $inputs = $workflow['on']['workflow_dispatch']['inputs'];
        $this->assertTrue($inputs['release_tag']['required']);
        $this->assertTrue($inputs['release_commit']['required']);

        $job = $workflow['jobs']['verify'];
        $this->assertSame(
            "github.repository == 'durable-workflow/server' && github.ref == 'refs/heads/main'",
            preg_replace('/\s+/', ' ', trim($job['if'])),
        );
        $this->assertSame(['contents' => 'read', 'packages' => 'read'], $job['permissions']);

        $steps = [];
        foreach ($job['steps'] as $step) {
            if (isset($step['name'])) {
                $steps[$step['name']] = $step;
            }
        }

        $checkout = $steps['Check out trusted default-branch verification tooling'];
        $verify = $steps['Verify requested immutable release artifacts'];
        $evidence = $steps['Retain bounded verification evidence'];

        $this->assertSame('${{ github.sha }}', $checkout['with']['ref']);
        $this->assertFalse($checkout['with']['persist-credentials']);
        $this->assertSame('${{ inputs.release_tag }}', $verify['env']['RELEASE_TAG']);
        $this->assertSame('${{ inputs.release_commit }}', $verify['env']['RELEASE_COMMIT']);
        $this->assertSame('scripts/ci/verify-published-release-recovery.sh', $verify['run']);
        $this->assertSame('14', (string) $evidence['with']['retention-days']);
        $this->assertSame('error', $evidence['with']['if-no-files-found']);
        $this->assertStringNotContainsString('docs-release-audit', $source);
        $this->assertStringNotContainsString('check-docs-release-audit.sh', $source);

        $verifier = $this->read('scripts/ci/verify-published-release-recovery.sh');
        $this->assertSame(2, substr_count($verifier, 'scripts/ci/verify-release-tag-source.sh'));
        $this->assertStringContainsString('RELEASE_IMAGE_VERIFICATION_ONLY=true', $verifier);
        $this->assertStringContainsString('releases/tags/$release_tag', $verifier);
        $this->assertStringContainsString('published-release-recovery-evidence.json', $verifier);

        foreach ([
            'docker/build-push-action',
            'docker/login-action',
            'push: true',
            'contents: write',
            'packages: write',
            'gh release create',
            'gh api --method',
            'gh workflow run',
            'git tag ',
            'git push ',
            'create-github-release.sh',
            'promote-release-image-rolling-tags.sh',
        ] as $mutation) {
            $this->assertStringNotContainsString($mutation, Yaml::dump($job)."\n".$verifier);
        }
    }

    public function test_published_release_recovery_verifies_existing_release_and_fails_when_it_is_missing(): void
    {
        $tmpDir = sys_get_temp_dir().'/published-release-recovery-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($tmpDir));
        $fakeGh = $tmpDir.'/gh';
        $fakeDocker = $tmpDir.'/docker';
        $evidencePath = $tmpDir.'/evidence.json';
        $releaseCommit = str_repeat('8', 40);
        $digest = 'sha256:'.str_repeat('a', 64);

        file_put_contents($fakeGh, <<<'SH'
#!/usr/bin/env sh
set -eu
case "$2" in
    */git/ref/tags/*)
        printf 'commit %s\n' "$FAKE_RELEASE_COMMIT"
        ;;
    */releases/tags/*)
        if [ "${FAKE_RELEASE_MISSING:-false}" = true ]; then
            printf 'release not found\n' >&2
            exit 1
        fi
        printf '{"tag_name":"%s","draft":false,"prerelease":true,"html_url":"https://github.com/durable-workflow/server/releases/tag/%s","target_commitish":"main","id":33}\n' \
            "$FAKE_RELEASE_TAG" "$FAKE_RELEASE_TAG"
        ;;
    *)
        exit 1
        ;;
esac
SH);
        file_put_contents($fakeDocker, <<<'SH'
#!/usr/bin/env sh
set -eu
if [ "$1" = buildx ] && [ "$2" = imagetools ] && [ "$3" = inspect ]; then
    if [ "${4:-}" = --format ]; then
        printf '{"config":{"Labels":{"org.opencontainers.image.revision":"%s","dev.durable-workflow.release.tag":"%s"}}}\n' \
            "$FAKE_RELEASE_COMMIT" "$FAKE_RELEASE_TAG"
        exit 0
    fi
    printf 'Name: %s\nMediaType: application/vnd.oci.image.index.v1+json\nDigest: %s\n\nManifests:\n  Platform: linux/amd64\n  Platform: linux/arm64\n' \
        "$4" "$FAKE_IMAGE_DIGEST"
    exit 0
fi
exit 1
SH);
        $this->assertTrue(chmod($fakeGh, 0755));
        $this->assertTrue(chmod($fakeDocker, 0755));

        $environment = [
            'GH_CLI' => $fakeGh,
            'DOCKER' => $fakeDocker,
            'GITHUB_REPOSITORY' => 'durable-workflow/server',
            'GITHUB_SERVER_URL' => 'https://github.com',
            'GITHUB_SHA' => str_repeat('9', 40),
            'GITHUB_RUN_ID' => '31760000000',
            'GITHUB_RUN_ATTEMPT' => '1',
            'RELEASE_TAG' => '2.0.0-rc.33',
            'RELEASE_COMMIT' => $releaseCommit,
            'RELEASE_RECOVERY_EVIDENCE' => $evidencePath,
            'RUNNER_TEMP' => $tmpDir,
            'FAKE_RELEASE_TAG' => '2.0.0-rc.33',
            'FAKE_RELEASE_COMMIT' => $releaseCommit,
            'FAKE_IMAGE_DIGEST' => $digest,
        ];

        try {
            $passing = $this->runScript('scripts/ci/verify-published-release-recovery.sh', $environment);
            $this->assertSame(0, $passing['exitCode'], $passing['stderr']);
            $evidence = json_decode(
                (string) file_get_contents($evidencePath),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $this->assertSame('pass', $evidence['outcome']);
            $this->assertSame('verified', $evidence['source_tag']['status']);
            $this->assertSame('verified', $evidence['images']['status']);
            $this->assertSame($digest, $evidence['images']['digest']);
            $this->assertSame('verified', $evidence['github_release']['status']);
            $this->assertFalse($evidence['mutation_policy']['image_mutation']);
            $this->assertFalse($evidence['mutation_policy']['tag_mutation']);
            $this->assertFalse($evidence['mutation_policy']['github_release_mutation']);

            $missing = $this->runScript('scripts/ci/verify-published-release-recovery.sh', $environment + [
                'FAKE_RELEASE_MISSING' => 'true',
            ]);
            $this->assertSame(1, $missing['exitCode']);
            $failedEvidence = json_decode(
                (string) file_get_contents($evidencePath),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $this->assertSame('fail', $failedEvidence['outcome']);
            $this->assertSame('github_release_missing', $failedEvidence['failure_kind']);
        } finally {
            @unlink($fakeGh);
            @unlink($fakeDocker);
            @unlink($evidencePath);
            @rmdir($tmpDir);
        }
    }

    public function test_release_uses_protected_tooling_with_the_immutable_source_tree(): void
    {
        $workflow = Yaml::parseFile($this->repoRoot.'/.github/workflows/release.yml');
        $steps = [];
        foreach ($workflow['jobs']['publish']['steps'] as $step) {
            if (isset($step['name'])) {
                $steps[$step['name']] = $step;
            }
        }

        $tooling = $steps['Check out trusted release tooling'];
        $source = $steps['Check out immutable release source'];
        $identity = $steps['Resolve exact source identity'];
        $sourceRelease = $steps['Resolve source release version'];
        $selector = $steps['Resolve locked workflow package authority'];
        $cache = $steps['Resolve shared release image cache'];
        $build = $steps['Build and push exact image tags'];
        $metadata = $steps['Extract exact image metadata'];

        $this->assertSame('${{ github.sha }}', $tooling['with']['ref']);
        $this->assertArrayNotHasKey('path', $tooling['with']);
        $this->assertSame('release-source', $source['with']['path']);
        $this->assertSame(
            '${{ github.event_name == \'workflow_dispatch\' && format(\'refs/tags/{0}\', inputs.tag) || github.ref }}',
            $source['with']['ref'],
        );
        $this->assertStringContainsString('git -C release-source rev-parse HEAD', $identity['run']);
        $this->assertStringContainsString('git -C release-source rev-list', $identity['run']);
        $this->assertStringContainsString('resources/release/source-release.json', $sourceRelease['run']);
        $this->assertStringContainsString('sync-source-release.mjs --root release-source --check', $sourceRelease['run']);
        $this->assertStringContainsString(
            'sync-source-release.mjs --root release-source --print-legacy-server-version',
            $sourceRelease['run'],
        );
        $this->assertStringNotContainsString('php -r', $sourceRelease['run']);
        $this->assertSame(
            '${{ steps.source_release.outputs.version }}',
            $steps['Validate release publish context and credentials']['env']['SOURCE_RELEASE_VERSION'],
        );
        $this->assertSame('release-source/composer.json', $selector['env']['COMPOSER_JSON_PATH']);
        $this->assertSame('release-source/composer.lock', $selector['env']['COMPOSER_LOCK_PATH']);
        $this->assertSame('release-source', $cache['env']['RELEASE_IMAGE_CACHE_ROOT']);
        $this->assertSame('release-source', $build['with']['context']);
        $this->assertStringContainsString(
            'org.opencontainers.image.revision=${{ steps.release_source.outputs.commit }}',
            $metadata['with']['labels'],
        );
    }

    public function test_release_tag_source_guard_rejects_missing_wrong_and_moved_refs(): void
    {
        $plannedCommit = str_repeat('a', 40);
        $wrongCommit = str_repeat('b', 40);
        $movedCommit = str_repeat('c', 40);
        $tagObject = str_repeat('d', 40);
        $tmpDir = sys_get_temp_dir().'/release-tag-source-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($tmpDir));
        $fakeGh = $tmpDir.'/gh';
        file_put_contents($fakeGh, <<<'SH'
#!/usr/bin/env sh
set -eu
case "${FAKE_TAG_MODE:-commit}" in
    missing)
        exit 1
        ;;
    annotated)
        case "$2" in
            */git/ref/tags/*) printf 'tag %s\n' "$FAKE_TAG_OBJECT" ;;
            */git/tags/*) printf 'commit %s\n' "$FAKE_TAG_SHA" ;;
        esac
        ;;
    commit)
        printf 'commit %s\n' "$FAKE_TAG_SHA"
        ;;
esac
SH);
        $this->assertTrue(chmod($fakeGh, 0755));
        $baseEnvironment = [
            'GH_CLI' => $fakeGh,
            'GITHUB_REPOSITORY' => 'durable-workflow/server',
            'RELEASE_TAG' => '1.2.3-alpha.4',
            'RELEASE_COMMIT' => $plannedCommit,
            'FAKE_TAG_OBJECT' => $tagObject,
        ];

        try {
            $exact = $this->runScript('scripts/ci/verify-release-tag-source.sh', $baseEnvironment + [
                'FAKE_TAG_MODE' => 'commit',
                'FAKE_TAG_SHA' => $plannedCommit,
            ]);
            $this->assertSame(0, $exact['exitCode'], $exact['stderr']);

            $annotated = $this->runScript('scripts/ci/verify-release-tag-source.sh', $baseEnvironment + [
                'FAKE_TAG_MODE' => 'annotated',
                'FAKE_TAG_SHA' => $plannedCommit,
            ]);
            $this->assertSame(0, $annotated['exitCode'], $annotated['stderr']);

            $missing = $this->runScript('scripts/ci/verify-release-tag-source.sh', $baseEnvironment + [
                'FAKE_TAG_MODE' => 'missing',
                'FAKE_TAG_SHA' => $plannedCommit,
            ]);
            $this->assertSame(1, $missing['exitCode']);
            $this->assertStringContainsString('does not exist', $missing['stderr']);

            $wrong = $this->runScript('scripts/ci/verify-release-tag-source.sh', $baseEnvironment + [
                'FAKE_TAG_MODE' => 'commit',
                'FAKE_TAG_SHA' => $wrongCommit,
            ]);
            $this->assertSame(1, $wrong['exitCode']);
            $this->assertStringContainsString('not planned commit', $wrong['stderr']);

            // The same immutable identity is checked again at dispatch/publication time.
            $moved = $this->runScript('scripts/ci/verify-release-tag-source.sh', $baseEnvironment + [
                'FAKE_TAG_MODE' => 'commit',
                'FAKE_TAG_SHA' => $movedCommit,
            ]);
            $this->assertSame(1, $moved['exitCode']);
            $this->assertStringContainsString($movedCommit, $moved['stderr']);
            $this->assertStringContainsString($plannedCommit, $moved['stderr']);
        } finally {
            @unlink($fakeGh);
            @rmdir($tmpDir);
        }
    }

    public function test_composer_metadata_identifies_the_exact_workflow_source(): void
    {
        $expectedVersion = '2.0.0-rc.55';
        $expectedCommit = '720512eefa0a5a97afeb410797cff8803ec76864';
        $composer = json_decode($this->read('composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $lock = json_decode($this->read('composer.lock'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($expectedVersion, $composer['require']['durable-workflow/workflow']);
        $this->assertArrayNotHasKey('repositories', $composer);

        $workflowPackages = array_values(array_filter(
            $lock['packages'],
            static fn (array $package): bool => ($package['name'] ?? null) === 'durable-workflow/workflow',
        ));

        $this->assertCount(1, $workflowPackages);
        $this->assertSame($expectedVersion, $workflowPackages[0]['version']);
        $this->assertSame($expectedCommit, $workflowPackages[0]['source']['reference']);
        $this->assertSame(
            "https://api.github.com/repos/durable-workflow/workflow/zipball/{$expectedCommit}",
            $workflowPackages[0]['dist']['url'],
        );
        $this->assertSame($expectedCommit, $workflowPackages[0]['dist']['reference']);
    }

    public function test_release_metadata_preparer_adds_the_verified_workflow_source_to_a_transient_manifest(): void
    {
        $tmpDir = sys_get_temp_dir().'/release-workflow-composer-'.bin2hex(random_bytes(4));
        $workflowPath = $tmpDir.'/workflow';
        $composerPath = $tmpDir.'/composer.json';
        $lockPath = $tmpDir.'/composer.lock';
        $this->assertTrue(mkdir($workflowPath, recursive: true));
        file_put_contents($composerPath, json_encode([
            'require' => [
                'durable-workflow/workflow' => '2.0.0-rc.13',
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($lockPath, json_encode([
            'packages' => [[
                'name' => 'durable-workflow/workflow',
                'version' => '2.0.0-rc.13',
                'source' => [
                    'type' => 'git',
                    'url' => 'https://github.com/durable-workflow/workflow.git',
                    'reference' => 'fc28432a82a2433959c6690505c52eabea4aca8c',
                ],
                'dist' => [
                    'url' => 'https://api.github.com/repos/durable-workflow/workflow/zipball/fc28432a82a2433959c6690505c52eabea4aca8c',
                    'reference' => 'fc28432a82a2433959c6690505c52eabea4aca8c',
                ],
            ]],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents(
            $workflowPath.'/.package-provenance',
            "https://github.com/durable-workflow/workflow.git\n"
                ."2.0.0-rc.13\n"
                ."fc28432a82a2433959c6690505c52eabea4aca8c\n",
        );

        try {
            $result = $this->runScript('scripts/ci/prepare-release-workflow-composer-metadata.php', [
                'COMPOSER_JSON_PATH' => $composerPath,
                'COMPOSER_LOCK_PATH' => $lockPath,
                'WORKFLOW_PACKAGE_PATH' => $workflowPath,
                'WORKFLOW_PACKAGE_REF' => '2.0.0-rc.13',
                'WORKFLOW_PACKAGE_COMMIT' => 'fc28432a82a2433959c6690505c52eabea4aca8c',
            ]);

            $this->assertSame(0, $result['exitCode'], $result['stderr']);
            $composer = json_decode((string) file_get_contents($composerPath), true, flags: JSON_THROW_ON_ERROR);
            $repository = $composer['repositories'][0] ?? null;
            $this->assertIsArray($repository);
            $this->assertSame('workflow', $repository['name'] ?? null);
            $this->assertSame('path', $repository['type'] ?? null);
            $this->assertSame($workflowPath, $repository['url'] ?? null);
            $this->assertFalse($repository['options']['symlink'] ?? true);
            $this->assertSame(
                '2.0.0-rc.13',
                $repository['options']['versions']['durable-workflow/workflow'] ?? null,
            );
        } finally {
            @unlink($workflowPath.'/.package-provenance');
            @unlink($composerPath);
            @unlink($lockPath);
            @rmdir($workflowPath);
            @rmdir($tmpDir);
        }
    }

    public function test_commonmark_lock_uses_a_patched_release(): void
    {
        $lock = json_decode($this->read('composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        $commonmarkPackages = array_values(array_filter(
            $lock['packages'],
            static fn (array $package): bool => ($package['name'] ?? null) === 'league/commonmark',
        ));

        $this->assertCount(1, $commonmarkPackages);
        $version = $commonmarkPackages[0]['version'] ?? null;
        $this->assertIsString($version);
        $this->assertTrue(version_compare($version, '2.9.0', '>='));
    }

    public function test_release_surfaces_are_rendered_from_the_source_release_manifest(): void
    {
        $release = json_decode(
            $this->read('resources/release/source-release.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $expectedVersion = $release['server']['version'] ?? null;
        $expectedChartVersion = $release['helm_chart']['version'] ?? null;
        $this->assertIsString($expectedVersion);
        $this->assertIsString($expectedChartVersion);
        $composer = json_decode($this->read('composer.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            $expectedVersion,
            $composer['extra']['durable-workflow']['product-train'] ?? null,
        );

        $this->assertStringNotContainsString(
            "APP_VERSION={$expectedVersion}",
            $this->read('.env.example'),
        );
        $this->assertStringContainsString(
            "env('APP_VERSION', SourceRelease::version())",
            $this->read('app/Http/Controllers/Api/HealthController.php'),
        );

        $compose = Yaml::parse($this->read('docker-compose.yml'));
        $this->assertIsArray($compose);
        foreach (['bootstrap', 'server', 'worker', 'scheduler'] as $service) {
            $this->assertSame(
                "\${APP_VERSION:-{$expectedVersion}}",
                $compose['services'][$service]['environment']['APP_VERSION'] ?? null,
            );
        }

        $smallCluster = Yaml::parse($this->read('docker-compose.small-cluster.yml'));
        $this->assertIsArray($smallCluster);
        $this->assertSame(
            "\${APP_VERSION:-{$expectedVersion}}",
            $smallCluster['x-server-environment']['APP_VERSION'] ?? null,
        );

        $chart = Yaml::parse($this->read('k8s/helm/durable-workflow/Chart.yaml'));
        $this->assertIsArray($chart);
        $this->assertSame($expectedChartVersion, $chart['version'] ?? null);
        $this->assertSame($expectedVersion, $chart['appVersion'] ?? null);
        $this->assertSame(
            "docker.io/durableworkflow/server:{$expectedVersion}",
            $chart['annotations']['dev.durable-workflow.image-reference'] ?? null,
        );
    }

    public function test_release_workflow_promotes_rolling_aliases_only_after_current_tag_guard(): void
    {
        $workflow = $this->read('.github/workflows/release.yml');

        $this->assertStringContainsString('Extract exact image metadata', $workflow);
        $this->assertStringContainsString('Build and push exact image tags', $workflow);
        $this->assertStringContainsString('continue-on-error: true', $workflow);
        $this->assertStringContainsString('Verify exact image publication', $workflow);
        $this->assertStringContainsString('BUILT_IMAGE_DIGEST: ${{ steps.build.outputs.digest }}', $workflow);
        $this->assertStringContainsString('BUILT_IMAGE_METADATA: ${{ steps.build.outputs.metadata }}', $workflow);
        $this->assertStringContainsString('RELEASE_COMMIT: ${{ steps.release_source.outputs.commit }}', $workflow);
        $this->assertStringContainsString('RELEASE_RUN_ID: ${{ github.run_id }}', $workflow);
        $this->assertStringContainsString('RELEASE_RUN_ATTEMPT: ${{ github.run_attempt }}', $workflow);
        $this->assertStringContainsString('WORKFLOW_PACKAGE_REF: ${{ steps.workflow.outputs.ref }}', $workflow);
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

    public function test_release_image_cache_is_shared_across_tags_and_invalidates_behavior_inputs(): void
    {
        $tmpDir = sys_get_temp_dir().'/release-image-cache-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($tmpDir.'/scripts/ci', recursive: true));

        $dockerfile = <<<'DOCKERFILE'
FROM composer:2 AS source
ARG PHPREDIS_VERSION=6.3.0
ARG PHPREDIS_COMMIT=df4fab2de7fc327c54c94a13af2b9542e4fbd720
FROM php:8.3-apache AS base
ARG WORKFLOW_PACKAGE_SOURCE=https://github.com/durable-workflow/workflow.git
ARG WORKFLOW_PACKAGE_REF=2.0.0-alpha.291
ARG WORKFLOW_PACKAGE_COMMIT=518a27492d38bd92bca3e2bb91b9ccf82da9589b
RUN printf '%s' "$WORKFLOW_PACKAGE_REF"
FROM base AS production
DOCKERFILE;
        $dependencyFiles = [
            'composer.json' => '{"require":{"php":"^8.2"}}',
            'composer.lock' => '{"packages":[]}',
            'scripts/ci/WorkflowPackageAuthority.php' => '<?php echo "authority";',
            'scripts/ci/prepare-release-workflow-composer-metadata.php' => '<?php echo "metadata";',
            'scripts/ci/resolve-workflow-package-authority.php' => '<?php echo "resolver";',
        ];
        file_put_contents($tmpDir.'/Dockerfile', $dockerfile);
        foreach ($dependencyFiles as $path => $contents) {
            file_put_contents($tmpDir.'/'.$path, $contents);
        }

        $dockerBin = $tmpDir.'/docker';
        $dockerScript = <<<'SH'
#!/usr/bin/env sh
set -eu
image="$4"
case "$image" in
    composer:2) prefix="1" ;;
    php:8.3-apache) prefix="2" ;;
    *) exit 1 ;;
esac
manifest_change="${MOCK_MANIFEST_CHANGE:-0}"
amd64_change="${MOCK_AMD64_CHANGE:-0}"
arm64_change="${MOCK_ARM64_CHANGE:-0}"
printf '{"digest":"sha256:%s","manifests":[{"digest":"sha256:%s","platform":{"os":"linux","architecture":"amd64"}},{"digest":"sha256:%s","platform":{"os":"linux","architecture":"arm64"}}]}\n' \
    "${prefix}${manifest_change}$(printf 'a%.0s' $(seq 1 62))" \
    "${prefix}${amd64_change}$(printf 'b%.0s' $(seq 1 62))" \
    "${prefix}${arm64_change}$(printf 'c%.0s' $(seq 1 62))"
SH;
        file_put_contents($dockerBin, $dockerScript);
        chmod($dockerBin, 0755);

        $baseEnv = [
            'RELEASE_IMAGE_CACHE_ROOT' => $tmpDir,
            'RELEASE_IMAGE_PLATFORMS' => 'linux/amd64,linux/arm64',
            'RELEASE_IMAGE_CACHE_IMAGE' => 'ghcr.io/durable-workflow/server',
            'DOCKER' => $dockerBin,
            'RELEASE_SOURCE_COMMIT' => str_repeat('a', 40),
            'PHPREDIS_VERSION' => '6.3.0',
            'PHPREDIS_COMMIT' => 'df4fab2de7fc327c54c94a13af2b9542e4fbd720',
            'WORKFLOW_PACKAGE_SOURCE' => 'https://github.com/durable-workflow/workflow.git',
            'WORKFLOW_PACKAGE_REF' => '2.0.0-alpha.291',
            'WORKFLOW_PACKAGE_COMMIT' => '518a27492d38bd92bca3e2bb91b9ccf82da9589b',
        ];

        try {
            $first = $this->resolveReleaseImageCache($baseEnv + ['RELEASE_TAG' => '0.2.676']);
            $sibling = $this->resolveReleaseImageCache($baseEnv + ['RELEASE_TAG' => '0.2.677']);

            $this->assertSame($first['identity'], $sibling['identity']);
            $this->assertSame($first['ref'], $sibling['ref']);
            $this->assertSame($first['cache_from'], $sibling['cache_from']);
            $this->assertSame($first['cache_to'], $sibling['cache_to']);
            $this->assertSame('linux/amd64,linux/arm64', $first['platforms']);
            $this->assertSame('type=registry,ref='.$first['ref'], $first['cache_from']);
            $this->assertSame(
                'type=registry,ref='.$first['ref'].',mode=max,ignore-error=true',
                $first['cache_to'],
            );

            $applicationSibling = $this->resolveReleaseImageCache([
                'RELEASE_TAG' => '0.2.678',
                'RELEASE_SOURCE_COMMIT' => str_repeat('d', 40),
            ] + $baseEnv);
            $this->assertNotSame($first['identity'], $applicationSibling['identity']);
            $this->assertSame($first['ref'], $applicationSibling['ref']);
            $this->assertSame($first['cache_from'], $applicationSibling['cache_from']);
            $this->assertSame($first['cache_to'], $applicationSibling['cache_to']);

            $platformChange = $this->resolveReleaseImageCache(
                ['RELEASE_IMAGE_PLATFORMS' => 'linux/amd64'] + $baseEnv,
            );
            $this->assertNotSame($first['identity'], $platformChange['identity']);
            $this->assertNotSame($first['ref'], $platformChange['ref']);

            foreach ([
                ['MOCK_MANIFEST_CHANGE' => '9'],
                ['MOCK_AMD64_CHANGE' => '8'],
                ['MOCK_ARM64_CHANGE' => '7'],
                ['WORKFLOW_PACKAGE_SOURCE' => 'https://example.invalid/workflow.git'],
                ['WORKFLOW_PACKAGE_REF' => '2.0.0-alpha.292'],
                ['WORKFLOW_PACKAGE_COMMIT' => str_repeat('9', 40)],
                ['PHPREDIS_VERSION' => '6.3.1'],
                ['PHPREDIS_COMMIT' => str_repeat('8', 40)],
            ] as $change) {
                $changed = $this->resolveReleaseImageCache($change + $baseEnv);
                $this->assertNotSame($first['identity'], $changed['identity']);
                $this->assertSame($first['ref'], $changed['ref']);
                $this->assertSame($first['cache_from'], $changed['cache_from']);
                $this->assertSame($first['cache_to'], $changed['cache_to']);
            }

            file_put_contents($tmpDir.'/Dockerfile', str_replace(
                'RUN printf',
                'RUN echo cache-contract && printf',
                $dockerfile,
            ));
            $dockerfileChange = $this->resolveReleaseImageCache($baseEnv);
            $this->assertNotSame($first['identity'], $dockerfileChange['identity']);
            $this->assertSame($first['ref'], $dockerfileChange['ref']);
            file_put_contents($tmpDir.'/Dockerfile', $dockerfile);

            foreach ($dependencyFiles as $path => $contents) {
                file_put_contents($tmpDir.'/'.$path, $contents."\nchanged");
                $dependencyChange = $this->resolveReleaseImageCache($baseEnv);
                $this->assertNotSame($first['identity'], $dependencyChange['identity'], $path);
                $this->assertSame($first['ref'], $dependencyChange['ref'], $path);
                $this->assertSame($first['cache_from'], $dependencyChange['cache_from'], $path);
                $this->assertSame($first['cache_to'], $dependencyChange['cache_to'], $path);
                file_put_contents($tmpDir.'/'.$path, $contents);
            }
        } finally {
            foreach (array_keys($dependencyFiles) as $path) {
                @unlink($tmpDir.'/'.$path);
            }
            @unlink($tmpDir.'/Dockerfile');
            @unlink($dockerBin);
            @rmdir($tmpDir.'/scripts/ci');
            @rmdir($tmpDir.'/scripts');
            @rmdir($tmpDir);
        }
    }

    public function test_release_image_cache_preserves_the_real_optional_qualification_argument(): void
    {
        $tmpDir = sys_get_temp_dir().'/release-image-cache-real-dockerfile-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($tmpDir));
        $dockerBin = $tmpDir.'/docker';
        file_put_contents($dockerBin, <<<'SH'
#!/usr/bin/env sh
set -eu
image="$4"
case "$image" in
    composer:2) prefix="1" ;;
    php:8.3-apache) prefix="2" ;;
    *) exit 1 ;;
esac
printf '{"digest":"sha256:%s","manifests":[{"digest":"sha256:%s","platform":{"os":"linux","architecture":"amd64"}},{"digest":"sha256:%s","platform":{"os":"linux","architecture":"arm64"}}]}\n' \
    "${prefix}0$(printf 'a%.0s' $(seq 1 62))" \
    "${prefix}0$(printf 'b%.0s' $(seq 1 62))" \
    "${prefix}0$(printf 'c%.0s' $(seq 1 62))"
SH);
        chmod($dockerBin, 0755);

        $baseEnv = [
            'RELEASE_IMAGE_CACHE_ROOT' => $this->repoRoot,
            'RELEASE_IMAGE_PLATFORMS' => 'linux/amd64,linux/arm64',
            'RELEASE_IMAGE_CACHE_IMAGE' => 'ghcr.io/durable-workflow/server',
            'DOCKER' => $dockerBin,
            'RELEASE_SOURCE_COMMIT' => str_repeat('a', 40),
            'PHPREDIS_VERSION' => '6.3.0',
            'PHPREDIS_COMMIT' => 'df4fab2de7fc327c54c94a13af2b9542e4fbd720',
            'WORKFLOW_PACKAGE_SOURCE' => 'https://github.com/durable-workflow/workflow.git',
            'WORKFLOW_PACKAGE_REF' => '2.0.0-rc.31',
            'WORKFLOW_PACKAGE_COMMIT' => 'a4ce321a31ba5f4d9c25964cde81109bf253c5aa',
        ];

        try {
            $omitted = $this->resolveReleaseImageCache($baseEnv);
            $explicitlyEmpty = $this->resolveReleaseImageCache([
                'WORKFLOW_PACKAGE_QUALIFICATION_REF' => '',
            ] + $baseEnv);
            $qualified = $this->resolveReleaseImageCache([
                'WORKFLOW_PACKAGE_QUALIFICATION_REF' => str_repeat('b', 40),
            ] + $baseEnv);

            $this->assertSame($omitted['identity'], $explicitlyEmpty['identity']);
            $this->assertSame($omitted['ref'], $explicitlyEmpty['ref']);
            $this->assertNotSame($omitted['identity'], $qualified['identity']);

            foreach ([
                'PHPREDIS_VERSION',
                'PHPREDIS_COMMIT',
                'WORKFLOW_PACKAGE_SOURCE',
                'WORKFLOW_PACKAGE_REF',
                'WORKFLOW_PACKAGE_COMMIT',
            ] as $requiredArgument) {
                $dockerfilePath = $tmpDir.'/Dockerfile-'.$requiredArgument;
                $dockerfile = preg_replace(
                    '/^ARG '.preg_quote($requiredArgument, '/').'=.*$/m',
                    'ARG '.$requiredArgument,
                    $this->read('Dockerfile'),
                );
                $this->assertIsString($dockerfile);
                file_put_contents($dockerfilePath, $dockerfile);
                $requiredEnv = $baseEnv;
                unset($requiredEnv[$requiredArgument]);
                $failed = $this->runScript('scripts/ci/resolve-release-image-cache.mjs', [
                    'RELEASE_IMAGE_DOCKERFILE' => $dockerfilePath,
                ] + $requiredEnv);
                $this->assertNotSame(0, $failed['exitCode'], $requiredArgument);
                $this->assertStringContainsString(
                    "Docker build argument {$requiredArgument} must have an effective value.",
                    $failed['stderr'],
                    $requiredArgument,
                );
            }
        } finally {
            foreach (glob($tmpDir.'/Dockerfile-*') ?: [] as $dockerfilePath) {
                @unlink($dockerfilePath);
            }
            @unlink($dockerBin);
            @rmdir($tmpDir);
        }
    }

    public function test_release_workflow_consumes_the_shared_cache_contract(): void
    {
        $workflow = Yaml::parseFile($this->repoRoot.'/.github/workflows/release.yml');
        $publish = $workflow['jobs']['publish'];
        $steps = [];
        foreach ($publish['steps'] as $offset => $step) {
            if (isset($step['id'])) {
                $steps[$step['id']] = $step + ['offset' => $offset];
            }
        }

        $this->assertSame(
            ['linux/amd64', 'linux/arm64'],
            explode(',', $workflow['env']['RELEASE_IMAGE_PLATFORMS']),
        );
        $this->assertSame('node scripts/ci/resolve-release-image-cache.mjs', $steps['cache']['run']);
        $this->assertSame(
            '${{ steps.release_source.outputs.commit }}',
            $steps['cache']['env']['RELEASE_SOURCE_COMMIT'],
        );
        $this->assertLessThan($steps['build']['offset'], $steps['cache']['offset']);
        $this->assertSame('${{ env.RELEASE_IMAGE_PLATFORMS }}', $steps['build']['with']['platforms']);
        $this->assertTrue($steps['build']['with']['pull']);
        $this->assertSame('${{ steps.cache.outputs.cache_from }}', $steps['build']['with']['cache-from']);
        $this->assertSame('${{ steps.cache.outputs.cache_to }}', $steps['build']['with']['cache-to']);

        preg_match_all(
            '/^ARG\s+([A-Za-z_][A-Za-z0-9_]*)/m',
            $this->read('Dockerfile'),
            $dockerfileArgs,
        );
        preg_match_all(
            '/^([A-Za-z_][A-Za-z0-9_]*)=/m',
            $steps['build']['with']['build-args'],
            $workflowArgs,
        );
        $this->assertContains('WORKFLOW_PACKAGE_QUALIFICATION_REF', $dockerfileArgs[1]);
        $releaseDockerfileArgs = array_values(array_diff(
            $dockerfileArgs[1],
            ['WORKFLOW_PACKAGE_QUALIFICATION_REF'],
        ));
        sort($releaseDockerfileArgs);
        sort($workflowArgs[1]);

        $this->assertSame($releaseDockerfileArgs, $workflowArgs[1]);
    }

    public function test_release_workflows_do_not_depend_on_the_live_docs_site(): void
    {
        foreach ([
            '.github/workflows/release.yml',
            '.github/workflows/published-release-recovery.yml',
        ] as $path) {
            $workflow = $this->read($path);
            $this->assertStringNotContainsString('docs-release-audit', $workflow);
            $this->assertStringNotContainsString('check-docs-release-audit.sh', $workflow);
            $this->assertStringNotContainsString('Classify live docs release readiness', $workflow);
        }
    }

    public function test_release_workflow_verifies_public_catalog_convergence_before_advertising_the_image(): void
    {
        $workflow = $this->read('.github/workflows/release.yml');
        $runner = $this->read('scripts/ci/verify-release-protocol-catalog.sh');
        $verifier = $this->read('scripts/ci/verify-release-protocol-catalog.mjs');

        foreach ([
            'Verify published protocol catalog convergence',
            'id: protocol_catalog',
            'scripts/ci/verify-release-protocol-catalog.sh',
            'PUBLIC_CATALOG_URL: https://durable-workflow.github.io/platform-protocol-specs.json',
            'PROTOCOL_CATALOG_CONFORMANCE_EVIDENCE: release-protocol-catalog-conformance.json',
            "steps.protocol_catalog.outputs.protocol_catalog_conformance_outcome == 'success'",
            'release-protocol-catalog-conformance.json',
            'release-protocol-catalog-bootstrap.log',
            'release-protocol-catalog-server.log',
        ] as $needle) {
            $this->assertStringContainsString($needle, $workflow);
        }

        foreach ([
            'DW_EXPOSE_PACKAGE_PROVENANCE=1',
            'server-bootstrap',
            'RELEASE_PROTOCOL_CATALOG_BOOTSTRAP_TIMEOUT',
            'server_bootstrap_failed',
            'server_bootstrap_timed_out',
            '/api/cluster/info',
            'platform-protocol-specs.json',
            'verify-release-protocol-catalog.mjs',
        ] as $needle) {
            $this->assertStringContainsString($needle, $runner);
        }

        foreach ([
            'validateConsumerSafeCatalog(publicCatalog',
            'validateConsumerSafeCatalog(serverCatalog',
            'compareCatalogs(publicCatalog, serverCatalog',
            "kind: 'field_set_mismatch'",
            "kind: 'repository_local_authority_field'",
            "kind: 'workflow_package_provenance_mismatch'",
        ] as $needle) {
            $this->assertStringContainsString($needle, $verifier);
        }

        $exactOffset = strpos($workflow, 'Verify exact image publication');
        $catalogOffset = strpos($workflow, 'Verify published protocol catalog convergence');
        $rollingOffset = strpos($workflow, 'Resolve rolling image aliases');

        $this->assertIsInt($exactOffset);
        $this->assertIsInt($catalogOffset);
        $this->assertIsInt($rollingOffset);
        $this->assertLessThan($catalogOffset, $exactOffset);
        $this->assertLessThan($rollingOffset, $catalogOffset);
    }

    public function test_release_protocol_catalog_runner_bootstraps_before_discovery_with_shared_storage(): void
    {
        $tmpDir = sys_get_temp_dir().'/release-protocol-catalog-lifecycle-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($tmpDir));
        $dockerBin = $tmpDir.'/docker';
        $curlBin = $tmpDir.'/curl';
        $nodeBin = $tmpDir.'/node';
        $eventLog = $tmpDir.'/events.log';
        $bootstrapLog = $tmpDir.'/bootstrap.log';
        $serverLog = $tmpDir.'/server.log';

        file_put_contents($dockerBin, <<<'SH'
#!/usr/bin/env sh
printf 'docker' >> "$DW_FAKE_EVENT_LOG"
for argument in "$@"; do
    printf '\t%s' "$argument" >> "$DW_FAKE_EVENT_LOG"
done
printf '\n' >> "$DW_FAKE_EVENT_LOG"

if [ "$1" = "pull" ]; then
    exit 0
fi
if [ "$1" = "volume" ] && [ "$2" = "create" ]; then
    printf '%s\n' "$3"
    exit 0
fi
if [ "$1" = "volume" ] && [ "$2" = "rm" ]; then
    exit 0
fi
if [ "$1" = "run" ]; then
    case " $* " in
        *" server-bootstrap "*) printf 'bootstrap complete\n'; exit 0 ;;
        *) printf 'container-id\n'; exit 0 ;;
    esac
fi
if [ "$1" = "logs" ]; then
    printf 'api server log\n'
    exit 0
fi
if [ "$1" = "port" ]; then
    printf '127.0.0.1:49152\n'
    exit 0
fi
if [ "$1" = "rm" ]; then
    exit 0
fi

exit 1
SH);
        file_put_contents($curlBin, <<<'SH'
#!/usr/bin/env sh
output=''
url=''
while [ "$#" -gt 0 ]; do
    case "$1" in
        --output) shift; output="$1" ;;
        http://*|https://*) url="$1" ;;
    esac
    shift
done
printf 'curl\t%s\n' "$url" >> "$DW_FAKE_EVENT_LOG"
printf '{}\n' > "$output"
SH);
        file_put_contents($nodeBin, <<<'SH'
#!/usr/bin/env sh
printf 'node\t%s\n' "$*" >> "$DW_FAKE_EVENT_LOG"
SH);
        chmod($dockerBin, 0755);
        chmod($curlBin, 0755);
        chmod($nodeBin, 0755);

        try {
            $result = $this->runScript('scripts/ci/verify-release-protocol-catalog.sh', [
                'RELEASE_TAG' => '0.2.653',
                'SERVER_IMAGE' => 'durableworkflow/server:0.2.653',
                'WORKFLOW_PACKAGE_SOURCE' => 'https://github.com/durable-workflow/workflow.git',
                'WORKFLOW_PACKAGE_REF' => '2.0.0-alpha.291',
                'WORKFLOW_PACKAGE_COMMIT' => '518a27492d38bd92bca3e2bb91b9ccf82da9589b',
                'DOCKER' => $dockerBin,
                'CURL' => $curlBin,
                'NODE' => $nodeBin,
                'DW_FAKE_EVENT_LOG' => $eventLog,
                'RUNNER_TEMP' => $tmpDir,
                'PROTOCOL_CATALOG_BOOTSTRAP_LOG' => $bootstrapLog,
                'PROTOCOL_CATALOG_SERVER_LOG' => $serverLog,
                'PROTOCOL_CATALOG_CONFORMANCE_EVIDENCE' => $tmpDir.'/evidence.json',
                'RELEASE_PROTOCOL_CATALOG_ATTEMPTS' => '1',
                'RELEASE_PROTOCOL_CATALOG_RETRY_SLEEP' => '0',
                'RELEASE_PROTOCOL_CATALOG_BOOTSTRAP_TIMEOUT' => '5',
                'RELEASE_PROTOCOL_CATALOG_PORT' => '0',
            ]);

            $this->assertSame(0, $result['exitCode'], $result['stderr']);
            $events = file($eventLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->assertIsArray($events);

            $volumeName = null;
            $bootstrapIndex = null;
            $serverIndex = null;
            $discoveryIndex = null;
            foreach ($events as $index => $event) {
                if (preg_match('/^docker\tvolume\tcreate\t([^\t]+)$/', $event, $matches) === 1) {
                    $volumeName = $matches[1];
                }
                if (str_contains($event, "\tdurableworkflow/server:0.2.653\tserver-bootstrap")) {
                    $bootstrapIndex = $index;
                }
                if (str_starts_with($event, "docker\trun\t--detach")) {
                    $serverIndex = $index;
                }
                if (str_contains($event, '/api/cluster/info')) {
                    $discoveryIndex = $index;
                }
            }

            $this->assertIsString($volumeName);
            $this->assertIsInt($bootstrapIndex);
            $this->assertIsInt($serverIndex);
            $this->assertIsInt($discoveryIndex);
            $this->assertLessThan($serverIndex, $bootstrapIndex);
            $this->assertLessThan($discoveryIndex, $serverIndex);
            $mount = "\t--volume\t{$volumeName}:/app/database";
            $this->assertStringContainsString($mount, $events[$bootstrapIndex]);
            $this->assertStringContainsString($mount, $events[$serverIndex]);
            $this->assertStringContainsString("\tdurableworkflow/server:0.2.653", $events[$bootstrapIndex]);
            $this->assertStringContainsString("\tdurableworkflow/server:0.2.653", $events[$serverIndex]);
            $this->assertStringContainsString("\t--publish\t127.0.0.1::8080", $events[$serverIndex]);
            $this->assertContains('curl	http://127.0.0.1:49152/api/cluster/info', $events);
        } finally {
            foreach (glob($tmpDir.'/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tmpDir);
        }
    }

    public function test_release_protocol_catalog_runner_classifies_bootstrap_failure_with_diagnostics(): void
    {
        $tmpDir = sys_get_temp_dir().'/release-protocol-catalog-bootstrap-failure-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($tmpDir));
        $dockerBin = $tmpDir.'/docker';
        $eventLog = $tmpDir.'/events.log';
        $bootstrapLog = $tmpDir.'/bootstrap.log';
        $serverLog = $tmpDir.'/server.log';
        $evidencePath = $tmpDir.'/evidence.json';

        file_put_contents($dockerBin, <<<'SH'
#!/usr/bin/env sh
printf 'docker' >> "$DW_FAKE_EVENT_LOG"
for argument in "$@"; do
    printf '\t%s' "$argument" >> "$DW_FAKE_EVENT_LOG"
done
printf '\n' >> "$DW_FAKE_EVENT_LOG"

if [ "$1" = "pull" ]; then
    exit 0
fi
if [ "$1" = "volume" ]; then
    exit 0
fi
if [ "$1" = "run" ]; then
    case " $* " in
        *" server-bootstrap "*) printf 'migration failed for workflow_namespaces\n' >&2; exit 9 ;;
        *) exit 98 ;;
    esac
fi

exit 1
SH);
        chmod($dockerBin, 0755);

        try {
            $result = $this->runScript('scripts/ci/verify-release-protocol-catalog.sh', [
                'RELEASE_TAG' => '0.2.653',
                'SERVER_IMAGE' => 'durableworkflow/server:0.2.653',
                'WORKFLOW_PACKAGE_SOURCE' => 'https://github.com/durable-workflow/workflow.git',
                'WORKFLOW_PACKAGE_REF' => '2.0.0-alpha.291',
                'WORKFLOW_PACKAGE_COMMIT' => '518a27492d38bd92bca3e2bb91b9ccf82da9589b',
                'DOCKER' => $dockerBin,
                'DW_FAKE_EVENT_LOG' => $eventLog,
                'RUNNER_TEMP' => $tmpDir,
                'PROTOCOL_CATALOG_BOOTSTRAP_LOG' => $bootstrapLog,
                'PROTOCOL_CATALOG_SERVER_LOG' => $serverLog,
                'PROTOCOL_CATALOG_CONFORMANCE_EVIDENCE' => $evidencePath,
                'RELEASE_PROTOCOL_CATALOG_BOOTSTRAP_TIMEOUT' => '5',
            ]);

            $this->assertSame(1, $result['exitCode']);
            $this->assertStringContainsString('failed server-bootstrap with exit code 9', $result['stderr']);
            $this->assertStringContainsString('migration failed for workflow_namespaces', $result['stderr']);
            $evidence = json_decode((string) file_get_contents($evidencePath), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('fail', $evidence['outcome']);
            $this->assertSame('server_bootstrap', $evidence['lifecycle']['failed_stage']);
            $this->assertSame('server_bootstrap_failed', $evidence['findings'][0]['kind']);
            $this->assertContains(
                'migration failed for workflow_namespaces',
                $evidence['diagnostics']['bootstrap_log']['tail'],
            );
            $events = (string) file_get_contents($eventLog);
            $this->assertStringNotContainsString("docker\trun\t--detach", $events);
            $this->assertStringContainsString("docker\tvolume\trm\t-f", $events);
        } finally {
            foreach (glob($tmpDir.'/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tmpDir);
        }
    }

    public function test_release_protocol_catalog_comparator_rejects_stale_provenance_and_catalog_drift(): void
    {
        $catalog = PlatformProtocolSpecs::manifest();
        $provenance = [
            'source' => 'https://github.com/durable-workflow/workflow.git',
            'ref' => '2.0.0-rc.13',
            'commit' => 'fc28432a82a2433959c6690505c52eabea4aca8c',
        ];

        $passing = $this->runProtocolCatalogComparator($catalog, [
            'platform_protocol_specs' => $catalog,
            'package_provenance' => $provenance,
        ]);

        $this->assertSame(0, $passing['exitCode']);
        $this->assertSame('pass', $passing['evidence']['outcome']);
        $this->assertSame(16, $passing['evidence']['observations']['public_catalog']['version']);
        $this->assertSame(
            $passing['evidence']['observations']['public_catalog']['sha256'],
            $passing['evidence']['observations']['server_catalog']['sha256'],
        );
        $this->assertSame($provenance, $passing['evidence']['observations']['package_provenance']);

        $staleProvenance = $provenance;
        $staleProvenance['source'] = 'https://example.invalid/workflow.git';
        $staleProvenance['ref'] = '2.0.0-alpha.291';
        $staleProvenance['commit'] = '518a27492d38bd92bca3e2bb91b9ccf82da9589b';
        $stale = $this->runProtocolCatalogComparator($catalog, [
            'platform_protocol_specs' => $catalog,
            'package_provenance' => $staleProvenance,
        ]);

        $this->assertSame(1, $stale['exitCode']);
        $this->assertSame('fail', $stale['evidence']['outcome']);
        $this->assertNotEmpty(array_filter(
            $stale['evidence']['findings'],
            static fn (array $finding): bool => ($finding['kind'] ?? null) === 'workflow_package_provenance_mismatch'
                && ($finding['path'] ?? null) === '$.package_provenance.source'
                && ($finding['expected'] ?? null) === 'https://github.com/durable-workflow/workflow.git'
                && ($finding['actual'] ?? null) === 'https://example.invalid/workflow.git',
        ));
        $this->assertNotEmpty(array_filter(
            $stale['evidence']['findings'],
            static fn (array $finding): bool => ($finding['kind'] ?? null) === 'workflow_package_provenance_mismatch'
                && ($finding['path'] ?? null) === '$.package_provenance.ref'
                && ($finding['expected'] ?? null) === '2.0.0-rc.13'
                && ($finding['actual'] ?? null) === '2.0.0-alpha.291',
        ));
        $this->assertStringContainsString(
            'Workflow package provenance ref expected "2.0.0-rc.13", got "2.0.0-alpha.291".',
            $stale['stderr'],
        );

        $staleCatalog = $catalog;
        $staleCatalog['version'] = 15;
        $catalogDrift = $this->runProtocolCatalogComparator($catalog, [
            'platform_protocol_specs' => $staleCatalog,
            'package_provenance' => $provenance,
        ]);

        $this->assertSame(1, $catalogDrift['exitCode']);
        $this->assertSame('fail', $catalogDrift['evidence']['outcome']);
        $this->assertNotEmpty(array_filter(
            $catalogDrift['evidence']['findings'],
            static fn (array $finding): bool => ($finding['kind'] ?? null) === 'value_mismatch'
                && ($finding['path'] ?? null) === '$.version'
                && ($finding['public_value'] ?? null) === 16
                && ($finding['server_value'] ?? null) === 15,
        ));
        $this->assertStringContainsString(
            'Catalog drift at $.version: public 16, server 15.',
            $catalogDrift['stderr'],
        );

        $unsafeCatalog = $catalog;
        $unsafeCatalog['specs']['control_plane_api']['spec_path'] = 'tests/Feature/ControlPlaneTest.php';
        $unsafe = $this->runProtocolCatalogComparator($catalog, [
            'platform_protocol_specs' => $unsafeCatalog,
            'package_provenance' => $provenance,
        ]);

        $this->assertSame(1, $unsafe['exitCode']);
        $this->assertNotEmpty(array_filter(
            $unsafe['evidence']['findings'],
            static fn (array $finding): bool => ($finding['kind'] ?? null) === 'field_set_mismatch'
                && ($finding['path'] ?? null) === '$.specs.control_plane_api'
                && ($finding['unexpected_server_fields'] ?? null) === ['spec_path'],
        ));
        $this->assertNotEmpty(array_filter(
            $unsafe['evidence']['findings'],
            static fn (array $finding): bool => ($finding['kind'] ?? null) === 'repository_local_authority_field'
                && ($finding['path'] ?? null) === '$.specs.control_plane_api.spec_path',
        ));
        $this->assertStringContainsString(
            'Catalog field set drift at $.specs.control_plane_api',
            $unsafe['stderr'],
        );
    }

    public function test_docs_audit_keeps_valid_expected_tuple_lag_non_blocking(): void
    {
        $result = $this->runDocsReleaseAudit(
            json_encode($this->validDocsReleaseAudit('0.2.619'), JSON_THROW_ON_ERROR),
            '0.2.620',
        );

        $this->assertSame(0, $result['exitCode']);
        $this->assertStringContainsString('The image publication remains successful', $result['stdout']);
        $this->assertStringContainsString('::warning title=Docs release readiness pending', $result['stderr']);
        $this->assertSame('downstream_pending', $result['evidence']['outcome']);
        $this->assertSame('success', $result['evidence']['status']);
        $this->assertSame('handoff', $result['evidence']['classification']);
        $this->assertSame('docs_tuple_refresh_required', $result['evidence']['release_readiness']);
        $this->assertSame('pass', $result['evidence']['public_safety']['outcome']);
        $this->assertSame(4, $result['evidence']['public_safety']['route_inventory']['schema_version']);
        $this->assertSame(
            'route-and-public-artifact-inventory-v4',
            $result['evidence']['public_safety']['route_inventory']['classifier'],
        );
        $this->assertSame(6, $result['evidence']['public_safety']['route_inventory']['inventoried_routes']);
        $this->assertSame('durable-workflow.release.docs-artifact-tuple-handoff', $result['handoff']['schema']);
        $this->assertSame('0.2.620', $result['handoff']['stale_artifact']['expected_version']);
        $this->assertSame('0.2.619', $result['handoff']['stale_artifact']['live_version']);
        $this->assertStringContainsString('Public images published; docs tuple refresh pending', $result['summary']);
    }

    public function test_docs_audit_accepts_current_schema_v4_tuple(): void
    {
        $result = $this->runDocsReleaseAudit(
            json_encode($this->validDocsReleaseAudit('0.2.620'), JSON_THROW_ON_ERROR),
            '0.2.620',
        );

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame('pass', $result['evidence']['outcome']);
        $this->assertSame('ready', $result['evidence']['classification']);
        $this->assertSame('fully_surfaced', $result['evidence']['release_readiness']);
        $this->assertSame('pass', $result['evidence']['public_safety']['outcome']);
        $this->assertNull($result['handoff']);
    }

    public function test_docs_audit_accepts_only_the_canonical_php_api_reference_route(): void
    {
        $canonical = $this->runDocsReleaseAudit(
            json_encode($this->validDocsReleaseAudit('0.2.620'), JSON_THROW_ON_ERROR),
            '0.2.620',
        );

        $this->assertSame(0, $canonical['exitCode']);
        $this->assertSame('ready', $canonical['evidence']['classification']);

        foreach ([
            'https://php.durable-workflow.com/',
            'https://php.durable-workflow.com/reference/',
            'https://example.com/api/',
        ] as $unexpectedUrl) {
            $audit = $this->validDocsReleaseAudit('0.2.620');
            $audit['artifact_distribution_surfaces']['sdk-php'][2]['url'] = $unexpectedUrl;

            $result = $this->runDocsReleaseAudit(
                json_encode($audit, JSON_THROW_ON_ERROR),
                '0.2.620',
            );

            $this->assertSame(1, $result['exitCode'], $unexpectedUrl);
            $this->assertSame('public_safety_failure', $result['evidence']['outcome']);
            $this->assertSame('mixed_artifact_tuple', $result['evidence']['failure_kind']);
            $this->assertStringContainsString($unexpectedUrl, $result['stderr']);
            $this->assertStringContainsString(
                'expected https://php.durable-workflow.com/api/',
                $result['stderr'],
            );
        }
    }

    public function test_docs_audit_can_bind_recovery_evidence_to_an_immutable_release_source(): void
    {
        $releaseCommit = str_repeat('8', 40);
        $result = $this->runDocsReleaseAudit(
            json_encode($this->validDocsReleaseAudit('0.2.620'), JSON_THROW_ON_ERROR),
            '0.2.620',
            null,
            [
                'GITHUB_REF_NAME' => 'main',
                'GITHUB_SHA' => str_repeat('9', 40),
                'DOCS_RELEASE_AUDIT_SOURCE_REF' => '0.2.620',
                'DOCS_RELEASE_AUDIT_SOURCE_SHA' => $releaseCommit,
            ],
        );

        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertSame('0.2.620', $result['evidence']['source_release_check']['ref']);
        $this->assertSame($releaseCommit, $result['evidence']['source_release_check']['sha']);
    }

    public function test_docs_audit_v5_stale_tuple_handoff_covers_complete_public_artifact_tuple(): void
    {
        $expectedRefreshFiles = [
            'scripts/public-artifact-versions.json',
            'scripts/published-artifact-versions.json',
            'static/public-artifact-compatibility-evidence.json',
            'static/quickstart-execution-contract.json',
            'static/compatibility-contract.json',
            'static/sdk-neutrality-contract.json',
            'scripts/workflow-sdk-neutrality-authority-lock.json',
        ];
        $expectedPublicBoundary = [
            'allowed_paths' => $expectedRefreshFiles,
            'forbidden_paths' => [
                'docusaurus.config.js',
                'sidebars.js',
                'versioned_docs/version-1.x',
                'versioned_sidebars/version-1.x-sidebars.json',
            ],
        ];
        $expectedReleaseStatusGuard = [
            'stable_default_docs_line' => '1.x',
            'prerelease_docs_line' => '2.0',
            'no_default_docs_cutover' => true,
            'live_release_audit_assertions' => [
                'compatible public route inventory contract',
                'internally consistent artifact tuple',
                'stable default 1.x',
                'explicit prerelease 2.0',
                'public-reference cleanliness',
            ],
        ];
        $expectedCompatibility = [
            'additive_fields' => 'ignore',
            'compatible_handoff_schema_versions' => [1],
            'unsupported_schema_version' => 'reject_with_supported_versions',
        ];
        $result = $this->runDocsReleaseAudit(
            json_encode(
                $this->validPublishedDocsReleaseAudit('2.0.0-rc.5', '2.0.0-rc.5'),
                JSON_THROW_ON_ERROR,
            ),
            '2.0.0-rc.6',
        );

        $this->assertSame(0, $result['exitCode']);
        $this->assertStringContainsString('The image publication remains successful', $result['stdout']);
        $this->assertSame('downstream_pending', $result['evidence']['outcome']);
        $this->assertSame('success', $result['evidence']['status']);
        $this->assertSame('docs_tuple_refresh_required', $result['evidence']['release_readiness']);
        $handoff = $result['handoff'];
        $refreshRequest = $result['evidence']['docs_refresh_request'];
        $this->assertSame(1, $handoff['schema_version']);
        $this->assertSame(1, $refreshRequest['schema_version']);
        $this->assertIsInt($refreshRequest['schema_version']);
        $this->assertSame($handoff['schema'], $refreshRequest['handoff_schema']);
        $this->assertSame($handoff['schema_version'], $refreshRequest['handoff_schema_version']);
        $this->assertSame($expectedCompatibility, $refreshRequest['compatibility']);
        $this->assertSame([
            'schema',
            'schema_version',
            'reason',
            'repository',
            'target_branch',
            'integration',
            'refresh_command',
            'refresh_files',
            'stale_artifact',
            'observed_artifact_versions',
            'source_release_check',
            'public_boundary',
            'release_status_guard',
            'ready_item',
            'handoff_schema',
            'handoff_schema_version',
            'compatibility',
        ], array_keys($refreshRequest));
        $this->assertSame(
            'durable-workflow.docs.published-artifact-versions',
            $result['evidence']['public_safety']['component_publication_state']['source_schema'],
        );
        $this->assertSame('2.0.0-rc.6', $result['handoff']['stale_artifact']['expected_version']);
        $this->assertSame('2.0.0-rc.5', $result['handoff']['stale_artifact']['live_version']);
        $this->assertSame(
            'npm run refresh:public-artifact-versions',
            $result['handoff']['refresh_command'],
        );
        $this->assertSame($expectedRefreshFiles, $result['handoff']['refresh_files']);
        $this->assertSame(
            $result['handoff']['refresh_files'],
            $result['handoff']['public_boundary']['allowed_paths'],
        );
        $this->assertSame(
            $result['handoff']['refresh_files'],
            $result['evidence']['docs_refresh_request']['refresh_files'],
        );
        $this->assertSame(
            $result['handoff']['refresh_command'],
            $result['evidence']['docs_refresh_request']['refresh_command'],
        );
        $this->assertSame($expectedPublicBoundary, $result['handoff']['public_boundary']);
        $this->assertSame($expectedPublicBoundary, $refreshRequest['public_boundary']);
        $this->assertSame($expectedReleaseStatusGuard, $result['handoff']['release_status_guard']);
        $this->assertSame($expectedReleaseStatusGuard, $refreshRequest['release_status_guard']);
        $this->assertSame('1.x', $result['handoff']['release_status_guard']['stable_default_docs_line']);
        $this->assertSame('2.0', $result['handoff']['release_status_guard']['prerelease_docs_line']);
        $this->assertTrue($result['handoff']['release_status_guard']['no_default_docs_cutover']);
        $this->assertStringNotContainsString(
            'docs/compatibility.md',
            $result['handoff']['ready_item']['body'],
        );
        foreach ($expectedRefreshFiles as $refreshFile) {
            $this->assertStringContainsString(
                $refreshFile,
                $result['handoff']['ready_item']['body'],
            );
        }
    }

    public function test_docs_audit_accepts_current_publication_independently_from_aggregate_qualification(): void
    {
        $result = $this->runDocsReleaseAudit(
            json_encode(
                $this->validPublishedDocsReleaseAudit('2.0.0-rc.6', '2.0.0-rc.5'),
                JSON_THROW_ON_ERROR,
            ),
            '2.0.0-rc.6',
        );

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame('pass', $result['evidence']['outcome']);
        $this->assertSame('ready', $result['evidence']['classification']);
        $this->assertSame('fully_surfaced', $result['evidence']['release_readiness']);
        $this->assertSame(
            'durable-workflow.docs.published-artifact-versions',
            $result['evidence']['public_safety']['component_publication_state']['source_schema'],
        );
        $this->assertSame(
            '2.0.0-rc.6',
            $result['evidence']['public_safety']['component_publication_state']['artifact_versions']['server'],
        );
        $aggregateCompatibility = $result['evidence']['public_safety']['aggregate_compatibility_evidence'];
        $this->assertSame(
            '2.0.0-rc.5',
            $aggregateCompatibility['qualified_artifact_versions']['server'],
        );
        $this->assertSame(
            'pass',
            $aggregateCompatibility['outcome'],
        );
        $this->assertNull($result['handoff']);
    }

    public function test_docs_audit_rejects_invalid_aggregate_compatibility_evidence(): void
    {
        $audit = $this->validPublishedDocsReleaseAudit('2.0.0-rc.6', '2.0.0-rc.5');
        $audit['artifact_compatibility_evidence']['outcome'] = 'failure';

        $result = $this->runDocsReleaseAudit(
            json_encode($audit, JSON_THROW_ON_ERROR),
            '2.0.0-rc.6',
        );

        $this->assertSame(1, $result['exitCode']);
        $this->assertSame('public_safety_failure', $result['evidence']['outcome']);
        $this->assertSame('aggregate_compatibility_evidence', $result['evidence']['failure_kind']);
        $this->assertStringContainsString(
            'artifact_compatibility_evidence.outcome must be pass',
            $result['stderr'],
        );
    }

    public function test_docs_audit_accepts_compatible_additive_contract_revision(): void
    {
        $audit = $this->validDocsReleaseAudit('0.2.620');
        $audit['schema_version'] = 5;
        $audit['compatible_extension'] = [
            'description' => 'additional producer metadata',
            'public_reference' => '/docs-page-release-audit.json',
        ];
        $audit['artifact_versions']['future-artifact'] = 'release-train-next';
        $audit['artifact_version_source']['synchronized_fields'][] = 'compatible_extension';

        $result = $this->runDocsReleaseAudit(
            json_encode($audit, JSON_THROW_ON_ERROR),
            '0.2.620',
        );

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame('pass', $result['evidence']['outcome']);
        $this->assertSame('ready', $result['evidence']['classification']);
        $this->assertSame(5, $result['evidence']['public_safety']['route_inventory']['schema_version']);
        $this->assertSame(
            'route-and-public-artifact-inventory-v4',
            $result['evidence']['public_safety']['route_inventory']['classifier'],
        );
        $this->assertSame(
            ['cli', 'sdk-php', 'sdk-python', 'sdk-rust', 'server', 'waterline', 'workflow'],
            $result['evidence']['public_safety']['validated_artifacts'],
        );

        $versionedClassifierAudit = $audit;
        $versionedClassifierAudit['classifier'] = 'route-and-public-artifact-inventory-v5';
        $versionedClassifierResult = $this->runDocsReleaseAudit(
            json_encode($versionedClassifierAudit, JSON_THROW_ON_ERROR),
            '0.2.620',
        );

        $this->assertSame(0, $versionedClassifierResult['exitCode']);
        $this->assertSame('ready', $versionedClassifierResult['evidence']['classification']);
    }

    public function test_docs_audit_does_not_treat_a_newer_live_tuple_as_expected_lag(): void
    {
        $result = $this->runDocsReleaseAudit(
            json_encode($this->validDocsReleaseAudit('0.2.621'), JSON_THROW_ON_ERROR),
            '0.2.620',
        );

        $this->assertSame(1, $result['exitCode']);
        $this->assertSame('release_readiness_failure', $result['evidence']['outcome']);
        $this->assertSame('live_docs_version_not_behind_publication', $result['evidence']['failure_kind']);
        $this->assertStringContainsString('newer than the published version 0.2.620', $result['stderr']);
        $this->assertNull($result['handoff']);
    }

    public function test_docs_audit_classifies_prerelease_channel_progression(): void
    {
        $stale = $this->runDocsReleaseAudit(
            json_encode($this->validDocsReleaseAudit('2.0.0-beta.21'), JSON_THROW_ON_ERROR),
            '2.0.0-rc.2',
        );

        $this->assertSame(0, $stale['exitCode']);
        $this->assertSame('downstream_pending', $stale['evidence']['outcome']);
        $this->assertSame('handoff', $stale['evidence']['classification']);
        $this->assertSame('2.0.0-rc.2', $stale['handoff']['stale_artifact']['expected_version']);
        $this->assertSame('2.0.0-beta.21', $stale['handoff']['stale_artifact']['live_version']);

        $same = $this->runDocsReleaseAudit(
            json_encode($this->validDocsReleaseAudit('2.0.0-rc.2'), JSON_THROW_ON_ERROR),
            '2.0.0-rc.2',
        );

        $this->assertSame(0, $same['exitCode']);
        $this->assertSame('pass', $same['evidence']['outcome']);
        $this->assertSame('ready', $same['evidence']['classification']);
        $this->assertNull($same['handoff']);

        $newer = $this->runDocsReleaseAudit(
            json_encode($this->validDocsReleaseAudit('2.0.0-rc.6'), JSON_THROW_ON_ERROR),
            '2.0.0-rc.2',
        );

        $this->assertSame(1, $newer['exitCode']);
        $this->assertSame('release_readiness_failure', $newer['evidence']['outcome']);
        $this->assertSame(
            'live_docs_version_not_behind_publication',
            $newer['evidence']['failure_kind'],
        );
        $this->assertNull($newer['handoff']);
    }

    public function test_docs_audit_rejects_incompatible_schema_with_actionable_evidence(): void
    {
        $audit = $this->validDocsReleaseAudit('0.2.619');
        $audit['schema_version'] = 3;

        $result = $this->runDocsReleaseAudit(
            json_encode($audit, JSON_THROW_ON_ERROR),
            '0.2.620',
        );

        $this->assertSame(1, $result['exitCode']);
        $this->assertSame('malformed', $result['evidence']['outcome']);
        $this->assertSame('incompatible', $result['evidence']['classification']);
        $this->assertSame('malformed_audit', $result['evidence']['failure_kind']);
        $this->assertSame(3, $result['evidence']['observed_schema_version']);
        $this->assertSame(4, $result['evidence']['minimum_schema_version']);
        $this->assertStringContainsString('not a compatible public contract revision (minimum 4)', $result['stderr']);
    }

    public function test_docs_audit_rejects_removed_required_public_references(): void
    {
        $missingSource = $this->validDocsReleaseAudit('0.2.620');
        unset($missingSource['artifact_version_source']['source_url']);

        $sourceResult = $this->runDocsReleaseAudit(
            json_encode($missingSource, JSON_THROW_ON_ERROR),
            '0.2.620',
        );

        $this->assertSame(1, $sourceResult['exitCode']);
        $this->assertSame('malformed', $sourceResult['evidence']['outcome']);
        $this->assertStringContainsString('artifact_version_source.source_url must resolve', $sourceResult['stderr']);

        $missingRoute = $this->validDocsReleaseAudit('0.2.620');
        unset($missingRoute['page_inventory'][0]['artifact_route']);

        $routeResult = $this->runDocsReleaseAudit(
            json_encode($missingRoute, JSON_THROW_ON_ERROR),
            '0.2.620',
        );

        $this->assertSame(1, $routeResult['exitCode']);
        $this->assertSame('malformed', $routeResult['evidence']['outcome']);
        $this->assertStringContainsString('artifact_route <missing>', $routeResult['stderr']);
    }

    public function test_docs_audit_rejects_repo_local_reference_leaks(): void
    {
        $audit = $this->validDocsReleaseAudit('0.2.620');
        $audit['page_inventory'][0]['build_artifact'] = 'build/index.html';

        $result = $this->runDocsReleaseAudit(
            json_encode($audit, JSON_THROW_ON_ERROR),
            '0.2.620',
        );

        $this->assertSame(1, $result['exitCode']);
        $this->assertSame('malformed', $result['evidence']['outcome']);
        $this->assertSame(
            '$.page_inventory[0].build_artifact',
            $result['evidence']['observed_repo_local_reference']['path'],
        );
        $this->assertStringContainsString('exposes repo-local reference "build/index.html"', $result['stderr']);
    }

    public function test_docs_audit_rejects_structurally_malformed_route_inventory(): void
    {
        $audit = $this->validDocsReleaseAudit('0.2.619');
        $audit['page_inventory'][1]['route_kind'] = 'public_artifact';

        $result = $this->runDocsReleaseAudit(
            json_encode($audit, JSON_THROW_ON_ERROR),
            '0.2.620',
        );

        $this->assertSame(1, $result['exitCode']);
        $this->assertSame('malformed', $result['evidence']['outcome']);
        $this->assertSame('malformed_audit', $result['evidence']['failure_kind']);
        $this->assertStringContainsString(
            'expected stable_default_docs',
            $result['stderr'],
        );
    }

    public function test_docs_audit_rejects_internally_mixed_server_tuple(): void
    {
        $audit = $this->validDocsReleaseAudit('0.2.619');
        $audit['artifact_distribution_surfaces']['server'][0]['tag'] = '0.2.618';
        $audit['artifact_distribution_surfaces']['server'][0]['reference'] = 'durableworkflow/server:0.2.618';

        $result = $this->runDocsReleaseAudit(
            json_encode($audit, JSON_THROW_ON_ERROR),
            '0.2.620',
        );

        $this->assertSame(1, $result['exitCode']);
        $this->assertSame('public_safety_failure', $result['evidence']['outcome']);
        $this->assertSame('mixed', $result['evidence']['classification']);
        $this->assertSame('mixed_artifact_tuple', $result['evidence']['failure_kind']);
        $this->assertStringContainsString('mixes artifact_versions.server=0.2.619', $result['stderr']);
    }

    public function test_docs_audit_rejects_internally_mixed_rust_distribution_surfaces(): void
    {
        $audit = $this->validDocsReleaseAudit('0.2.619');
        $audit['artifact_distribution_surfaces']['sdk-rust'][0]['url'] = 'https://crates.io/crates/stale-package';

        $result = $this->runDocsReleaseAudit(
            json_encode($audit, JSON_THROW_ON_ERROR),
            '0.2.620',
        );

        $this->assertSame(1, $result['exitCode']);
        $this->assertSame('public_safety_failure', $result['evidence']['outcome']);
        $this->assertSame('mixed_artifact_tuple', $result['evidence']['failure_kind']);
        $this->assertStringContainsString('mixes artifact_versions.sdk-rust=0.1.0', $result['stderr']);
    }

    public function test_docs_audit_rejects_default_version_policy_drift(): void
    {
        $audit = $this->validDocsReleaseAudit('0.2.619');
        $audit['release_status_guardrail']['stable_default_docs_version'] = '2.0';

        $result = $this->runDocsReleaseAudit(
            json_encode($audit, JSON_THROW_ON_ERROR),
            '0.2.620',
        );

        $this->assertSame(1, $result['exitCode']);
        $this->assertSame('public_safety_failure', $result['evidence']['outcome']);
        $this->assertSame('default_version_policy', $result['evidence']['failure_kind']);
        $this->assertStringContainsString('stable_default_docs_version=1.x', $result['stderr']);
    }

    public function test_docs_audit_accepts_only_contract_defined_generated_route_null_versions(): void
    {
        $audit = $this->validDocsReleaseAudit('0.2.620');

        $stableNullPaths = array_column(array_filter(
            $audit['page_inventory'],
            static fn (array $entry): bool => $entry['route_kind'] === 'stable_default_docs'
                && $entry['docusaurus_version'] === null,
        ), 'path');
        sort($stableNullPaths);

        $this->assertSame([
            '/docs/',
            '/docs/platform-conformance/',
        ], $stableNullPaths);

        $result = $this->runDocsReleaseAudit(
            json_encode($audit, JSON_THROW_ON_ERROR),
            '0.2.620',
        );

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame('ready', $result['evidence']['classification']);
    }

    public function test_docs_audit_rejects_stable_content_route_version_drift(): void
    {
        $audit = $this->validDocsReleaseAudit('0.2.620');
        $audit['page_inventory'][2]['docusaurus_version'] = '2.0';

        $result = $this->runDocsReleaseAudit(
            json_encode($audit, JSON_THROW_ON_ERROR),
            '0.2.620',
        );

        $this->assertSame(1, $result['exitCode']);
        $this->assertSame('public_safety_failure', $result['evidence']['outcome']);
        $this->assertSame('incompatible', $result['evidence']['classification']);
        $this->assertSame('default_version_policy', $result['evidence']['failure_kind']);
        $this->assertStringContainsString('/docs/category/configuration/', $result['stderr']);
        $this->assertStringContainsString('docusaurus_version=2.0; expected 1.x', $result['stderr']);
    }

    public function test_docs_audit_rejects_prerelease_content_route_version_drift(): void
    {
        $audit = $this->validDocsReleaseAudit('0.2.620');
        $audit['page_inventory'][4]['docusaurus_version'] = '1.x';

        $result = $this->runDocsReleaseAudit(
            json_encode($audit, JSON_THROW_ON_ERROR),
            '0.2.620',
        );

        $this->assertSame(1, $result['exitCode']);
        $this->assertSame('public_safety_failure', $result['evidence']['outcome']);
        $this->assertSame('default_version_policy', $result['evidence']['failure_kind']);
        $this->assertStringContainsString('/docs/2.0/introduction/', $result['stderr']);
        $this->assertStringContainsString('docusaurus_version=1.x', $result['stderr']);
    }

    public function test_docs_audit_rejects_null_version_for_ordinary_stable_content_route(): void
    {
        $audit = $this->validDocsReleaseAudit('0.2.620');
        $audit['page_inventory'][2]['docusaurus_version'] = null;

        $result = $this->runDocsReleaseAudit(
            json_encode($audit, JSON_THROW_ON_ERROR),
            '0.2.620',
        );

        $this->assertSame(1, $result['exitCode']);
        $this->assertSame('default_version_policy', $result['evidence']['failure_kind']);
        $this->assertStringContainsString('/docs/category/configuration/', $result['stderr']);
        $this->assertStringContainsString('docusaurus_version=null; expected 1.x', $result['stderr']);
    }

    public function test_docs_audit_rejects_malformed_and_unreachable_surfaces_with_evidence(): void
    {
        $malformed = $this->runDocsReleaseAudit('{not-json', '0.2.620');

        $this->assertSame(1, $malformed['exitCode']);
        $this->assertSame('malformed', $malformed['evidence']['outcome']);
        $this->assertSame('malformed_audit', $malformed['evidence']['failure_kind']);
        $this->assertStringContainsString('did not return parseable JSON', $malformed['stderr']);

        $unreachable = $this->runDocsReleaseAudit(
            json_encode($this->validDocsReleaseAudit('0.2.619'), JSON_THROW_ON_ERROR),
            '0.2.620',
            'file:///does-not-exist/docs-page-release-audit.json',
        );

        $this->assertSame(1, $unreachable['exitCode']);
        $this->assertSame('unavailable', $unreachable['evidence']['outcome']);
        $this->assertSame('unreachable_audit', $unreachable['evidence']['failure_kind']);
        $this->assertStringContainsString('Could not fetch', $unreachable['stderr']);
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

    public function test_release_guard_rejects_a_tag_that_differs_from_source_authority(): void
    {
        $result = $this->runGuard([
            'GITHUB_EVENT_NAME' => 'workflow_dispatch',
            'GITHUB_REF' => 'refs/heads/main',
            'INPUT_TAG' => '0.2.167',
            'SOURCE_RELEASE_VERSION' => '0.2.168',
            'DOCKERHUB_USERNAME' => 'durableworkflow',
            'DOCKERHUB_TOKEN' => 'docker-token',
            'GHCR_TOKEN' => 'ghcr-token',
        ]);

        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString(
            "Release tag '0.2.167' does not match authoritative Server source release '0.2.168'",
            $result['stderr'],
        );
        $this->assertStringContainsString('sync-source-release.mjs --write', $result['stderr']);
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
        $workflowRef = '2.0.0-alpha.250';
        $workflowCommit = 'cdb59bc5e27401be6749c893b28636a24b1f6530';
        $workflowSource = 'https://github.com/durable-workflow/workflow.git';
        $imageConfig = json_encode([
            'config' => [
                'Labels' => [
                    'org.opencontainers.image.revision' => $releaseCommit,
                    'dev.durable-workflow.release.tag' => '0.2.396',
                    'dev.durable-workflow.release.run-id' => $runId,
                    'dev.durable-workflow.release.run-attempt' => $runAttempt,
                    'dev.durable-workflow.workflow.source' => $workflowSource,
                    'dev.durable-workflow.workflow.version' => $workflowRef,
                    'dev.durable-workflow.workflow.commit' => $workflowCommit,
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
                'WORKFLOW_PACKAGE_SOURCE' => $workflowSource,
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

    public function test_exact_image_verifier_supports_source_bound_verification_without_build_identity(): void
    {
        $result = $this->runExactImageVerifierWithConfigFixture(
            'buildx-v0.29.1-pretty-valid.json',
            [
                'RELEASE_IMAGE_VERIFICATION_ONLY' => 'true',
                'DOCKER_BUILD_OUTCOME' => '',
                'BUILT_IMAGE_DIGEST' => '',
                'RELEASE_RUN_ID' => '',
                'RELEASE_RUN_ATTEMPT' => '',
                'WORKFLOW_PACKAGE_SOURCE' => '',
                'WORKFLOW_PACKAGE_REF' => '',
                'WORKFLOW_PACKAGE_COMMIT' => '',
            ],
        );

        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertStringContainsString("exact_publish_outcome=success\n", $result['outputs']);
        $this->assertSame(4, substr_count($result['dockerLog'], '--format {{json (index .Image'));
    }

    public function test_exact_image_verifier_accepts_pretty_buildx_platform_config_json(): void
    {
        $result = $this->runExactImageVerifierWithConfigFixture('buildx-v0.29.1-pretty-valid.json');

        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertStringContainsString("exact_publish_outcome=success\n", $result['outputs']);
        $this->assertSame(
            2,
            substr_count($result['dockerLog'], '--format {{json (index .Image "linux/amd64")}}'),
        );
        $this->assertSame(
            2,
            substr_count($result['dockerLog'], '--format {{json (index .Image "linux/arm64")}}'),
        );
    }

    public function test_exact_image_verifier_rejects_pretty_buildx_config_with_missing_source_label(): void
    {
        $result = $this->runExactImageVerifierWithConfigFixture('buildx-v0.29.1-pretty-missing-source.json');

        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString(
            "exact_publish_reason=exact_manifest_metadata_mismatch\n",
            $result['outputs'],
        );
        $this->assertStringContainsString(
            'dev.durable-workflow.workflow.source=https://github.com/durable-workflow/workflow.git',
            $result['stderr'],
        );
    }

    public function test_exact_image_verifier_rejects_pretty_buildx_config_with_wrong_run_label(): void
    {
        $result = $this->runExactImageVerifierWithConfigFixture('buildx-v0.29.1-pretty-wrong-run.json');

        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString(
            "exact_publish_reason=exact_manifest_metadata_mismatch\n",
            $result['outputs'],
        );
        $this->assertStringContainsString(
            'dev.durable-workflow.release.run-id=31707164656',
            $result['stderr'],
        );
    }

    public function test_exact_image_verifier_rejects_pretty_buildx_config_with_wrong_workflow_label(): void
    {
        $result = $this->runExactImageVerifierWithConfigFixture('buildx-v0.29.1-pretty-wrong-workflow.json');

        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString(
            "exact_publish_reason=exact_manifest_metadata_mismatch\n",
            $result['outputs'],
        );
        $this->assertStringContainsString(
            'dev.durable-workflow.workflow.commit=a4ce321a31ba5f4d9c25964cde81109bf253c5aa',
            $result['stderr'],
        );
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
                'BUILD_CACHE_IDENTITY' => 'sha256:'.str_repeat('f', 64),
                'BUILD_CACHE_REF' => 'ghcr.io/durable-workflow/server:buildcache-'.str_repeat('f', 64),
                'BUILD_DURATION_SECONDS' => '432',
                'WARM_CACHE_TARGET_SECONDS' => '600',
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
            $this->assertSame('sha256:'.str_repeat('f', 64), $decoded['exact_publish']['cache']['identity']);
            $this->assertSame(
                'ghcr.io/durable-workflow/server:buildcache-'.str_repeat('f', 64),
                $decoded['exact_publish']['cache']['ref'],
            );
            $this->assertSame(432, $decoded['exact_publish']['timing']['duration_seconds']);
            $this->assertSame(600, $decoded['exact_publish']['timing']['warm_cache_target_seconds']);
            $this->assertTrue($decoded['exact_publish']['timing']['warm_cache_target_met']);
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
        $workflowCommit = 'cdb59bc5e27401be6749c893b28636a24b1f6530';

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
                'WORKFLOW_PACKAGE_REF' => '2.0.0-alpha.250',
                'WORKFLOW_PACKAGE_COMMIT' => $workflowCommit,
            ]);

            $this->assertSame(0, $result['exitCode']);
            $decoded = json_decode((string) file_get_contents($evidenceFile), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(
                'durable-workflow/workflow:2.0.0-alpha.250',
                $decoded['artifact_versions']['workflow-php'],
            );
            $this->assertSame('durable-workflow/workflow', $decoded['workflow_package']['name']);
            $this->assertSame('https://github.com/durable-workflow/workflow.git', $decoded['workflow_package']['source']);
            $this->assertSame('2.0.0-alpha.250', $decoded['workflow_package']['version']);
            $this->assertSame($workflowCommit, $decoded['workflow_package']['commit']);
        } finally {
            @unlink($evidenceFile);
        }
    }

    public function test_workflow_package_resolver_follows_the_composer_authority(): void
    {
        $outputFile = tempnam(sys_get_temp_dir(), 'workflow-package-output-');
        $this->assertIsString($outputFile);

        try {
            $result = $this->runScript('scripts/ci/select-compatible-workflow-package-ref.sh', [
                'GITHUB_OUTPUT' => $outputFile,
            ]);

            $this->assertSame(0, $result['exitCode']);
            $outputs = file_get_contents($outputFile);
            $this->assertNotFalse($outputs);
            $this->assertStringContainsString("source=https://github.com/durable-workflow/workflow.git\n", $outputs);
            $this->assertStringContainsString("ref=2.0.0-rc.55\n", $outputs);
            $this->assertStringContainsString("tag=2.0.0-rc.55\n", $outputs);
            $this->assertStringContainsString("commit=720512eefa0a5a97afeb410797cff8803ec76864\n", $outputs);
            $this->assertStringContainsString(
                'Workflow package authority: 2.0.0-rc.55 at 720512eefa0a5a97afeb410797cff8803ec76864',
                $result['stdout'],
            );
        } finally {
            @unlink($outputFile);
        }
    }

    public function test_workflow_package_resolver_accepts_only_matching_caller_identity(): void
    {
        $outputFile = tempnam(sys_get_temp_dir(), 'workflow-package-output-');
        $this->assertIsString($outputFile);

        try {
            $result = $this->runScript('scripts/ci/select-compatible-workflow-package-ref.sh', [
                'WORKFLOW_PACKAGE_SOURCE' => 'https://github.com/durable-workflow/workflow.git',
                'WORKFLOW_PACKAGE_REF' => '2.0.0-rc.55',
                'WORKFLOW_PACKAGE_COMMIT' => '720512eefa0a5a97afeb410797cff8803ec76864',
                'GITHUB_OUTPUT' => $outputFile,
            ]);

            $this->assertSame(0, $result['exitCode']);
            $outputs = file_get_contents($outputFile);
            $this->assertNotFalse($outputs);
            $this->assertStringContainsString("tag=2.0.0-rc.55\n", $outputs);
        } finally {
            @unlink($outputFile);
        }
    }

    public function test_workflow_package_resolver_rejects_a_stale_protocol_compatible_override(): void
    {
        $result = $this->runScript('scripts/ci/select-compatible-workflow-package-ref.sh', [
            'WORKFLOW_PACKAGE_REF' => '2.0.0-rc.13',
            'WORKFLOW_PACKAGE_COMMIT' => 'fc28432a82a2433959c6690505c52eabea4aca8c',
        ]);

        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString(
            'disagrees with the Composer Workflow package authority 2.0.0-rc.55',
            $result['stderr'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validDocsReleaseAudit(string $serverVersion): array
    {
        $docsRevision = str_repeat('a', 40);
        $versions = [
            'cli' => '0.1.86',
            'sdk-php' => '0.1.1',
            'sdk-python' => '0.4.98',
            'sdk-rust' => '0.1.0',
            'server' => $serverVersion,
            'waterline' => '2.0.0-alpha.123',
            'workflow' => '2.0.0-alpha.259',
        ];
        $serverReferences = [
            "durableworkflow/server:{$serverVersion}",
            "ghcr.io/durable-workflow/server:{$serverVersion}",
        ];

        return [
            'schema' => 'durable-workflow.docs.page-release-audit',
            'schema_version' => 4,
            'generated_at' => '2026-07-13T18:30:00.000Z',
            'generated_from' => 'production sitemap and build artifact inventory',
            'classifier' => 'route-and-public-artifact-inventory-v4',
            'docs_revision' => $docsRevision,
            'artifact_versions' => $versions,
            'artifact_version_source' => [
                'schema' => 'durable-workflow.docs.public-artifact-versions',
                'source_url' => 'https://github.com/durable-workflow/durable-workflow.github.io/blob/'
                    .$docsRevision.'/scripts/public-artifact-versions.json',
                'synchronized_fields' => [
                    'artifact_versions',
                    'artifact_distribution_surfaces.sdk-php',
                    'artifact_distribution_surfaces.server',
                    'artifact_distribution_surfaces.sdk-rust',
                ],
                'current_server_artifact' => [
                    'version' => $serverVersion,
                    'references' => $serverReferences,
                ],
            ],
            'artifact_distribution_surfaces' => [
                'sdk-php' => [
                    [
                        'surface' => 'packagist_package',
                        'package' => 'durable-workflow/sdk',
                        'version' => '0.1.1',
                        'url' => 'https://packagist.org/packages/durable-workflow/sdk',
                    ],
                    [
                        'surface' => 'source_repository',
                        'repository' => 'durable-workflow/sdk-php',
                        'url' => 'https://github.com/durable-workflow/sdk-php',
                    ],
                    [
                        'surface' => 'api_documentation',
                        'url' => 'https://php.durable-workflow.com/api/',
                    ],
                ],
                'server' => [
                    [
                        'surface' => 'docker_hub_container_image',
                        'registry' => 'docker_hub',
                        'image' => 'durableworkflow/server',
                        'tag' => $serverVersion,
                        'reference' => $serverReferences[0],
                    ],
                    [
                        'surface' => 'ghcr_container_image',
                        'registry' => 'ghcr',
                        'image' => 'ghcr.io/durable-workflow/server',
                        'tag' => $serverVersion,
                        'reference' => $serverReferences[1],
                    ],
                ],
                'sdk-rust' => [
                    [
                        'surface' => 'crates_io_package',
                        'package' => 'durable-workflow',
                        'version' => '0.1.0',
                        'url' => 'https://crates.io/crates/durable-workflow',
                    ],
                    [
                        'surface' => 'source_repository',
                        'repository' => 'durable-workflow/sdk-rust',
                        'url' => 'https://github.com/durable-workflow/sdk-rust',
                    ],
                    [
                        'surface' => 'api_documentation',
                        'url' => 'https://rust.durable-workflow.com/',
                    ],
                ],
            ],
            'release_status_guardrail' => [
                'stable_default_docs_version' => '1.x',
                'explicit_prerelease_docs_version' => '2.0',
            ],
            'summary' => [
                'stable_default_docs_pages' => 3,
                'explicit_prerelease_2_0_pages' => 2,
                'inventoried_routes' => 6,
            ],
            'page_inventory' => [
                [
                    'path' => '/',
                    'route_kind' => 'homepage',
                    'artifact_route' => '/',
                    'docusaurus_version' => null,
                ],
                [
                    'path' => '/docs/',
                    'route_kind' => 'stable_default_docs',
                    'artifact_route' => '/docs/',
                    'docusaurus_version' => null,
                ],
                [
                    'path' => '/docs/category/configuration/',
                    'route_kind' => 'stable_default_docs',
                    'artifact_route' => '/docs/category/configuration/',
                    'docusaurus_version' => '1.x',
                ],
                [
                    'path' => '/docs/platform-conformance/',
                    'route_kind' => 'stable_default_docs',
                    'artifact_route' => '/docs/platform-conformance/',
                    'docusaurus_version' => null,
                ],
                [
                    'path' => '/docs/2.0/introduction/',
                    'route_kind' => 'explicit_prerelease_2_0_docs',
                    'artifact_route' => '/docs/2.0/introduction/',
                    'docusaurus_version' => 'current',
                ],
                [
                    'path' => '/docs/2.0/tags/reference/',
                    'route_kind' => 'explicit_prerelease_2_0_docs',
                    'artifact_route' => '/docs/2.0/tags/reference/',
                    'docusaurus_version' => null,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validPublishedDocsReleaseAudit(
        string $serverVersion,
        string $qualifiedServerVersion,
    ): array {
        $audit = $this->validDocsReleaseAudit($serverVersion);
        $qualifiedVersions = array_fill_keys(
            ['cli', 'sdk-php', 'sdk-python', 'sdk-rust', 'server', 'waterline', 'workflow'],
            '2.0.0-rc.5',
        );
        $qualifiedVersions['server'] = $qualifiedServerVersion;

        $audit['schema_version'] = 5;
        $audit['classifier'] = 'route-and-public-artifact-inventory-v5';
        $audit['artifact_version_source']['schema'] = 'durable-workflow.docs.published-artifact-versions';
        $audit['artifact_version_source']['role'] = 'current_published_component_artifacts';
        $audit['artifact_version_source']['source_url'] =
            'https://github.com/durable-workflow/durable-workflow.github.io/blob/'
            .$audit['docs_revision'].'/scripts/published-artifact-versions.json';
        $audit['artifact_version_source']['synchronized_fields'][] =
            'artifact_distribution_surfaces.waterline';
        $audit['artifact_compatibility_evidence'] = [
            'role' => 'qualified_aggregate_recommendation',
            'source_url' => '/public-artifact-compatibility-evidence.json',
            'schema' => 'durable-workflow.docs.public-artifact-compatibility-evidence',
            'schema_version' => 2,
            'outcome' => 'pass',
            'qualified_artifact_versions' => $qualifiedVersions,
            'release_plan' => [
                'tag' => 'release-plan/coherent-2-0-rc-5',
                'sha256' => str_repeat('b', 64),
            ],
            'sdk_server_qualification' => [
                'source_url' => 'https://raw.githubusercontent.com/durable-workflow/.github/'
                    .'main/product-train/sdk-server-qualification.json',
                'sha256' => str_repeat('c', 64),
                'evidence_source' => 'https://github.com/durable-workflow/.github/releases/'
                    .'download/beta-conformance/rc-coherent-2-0-rc-5/suite-result.json',
                'evidence_sha256' => str_repeat('d', 64),
                'outcome' => 'pass',
            ],
        ];

        return $audit;
    }

    /**
     * @return array{exitCode:int, stdout:string, stderr:string, evidence:?array<string, mixed>, handoff:?array<string, mixed>, summary:string}
     */
    private function runDocsReleaseAudit(
        string $auditSource,
        string $expectedVersion,
        ?string $auditUrl = null,
        array $environment = [],
    ): array {
        $tmpDir = sys_get_temp_dir().'/docs-release-audit-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($tmpDir));
        $auditFile = $tmpDir.'/audit.json';
        $evidenceFile = $tmpDir.'/evidence.json';
        $handoffFile = $tmpDir.'/handoff.json';
        $summaryFile = $tmpDir.'/summary.md';
        file_put_contents($auditFile, $auditSource);

        try {
            $result = $this->runScript('scripts/ci/check-docs-release-audit.sh', array_merge([
                'DOCS_RELEASE_AUDIT_ARTIFACT' => 'server',
                'DOCS_RELEASE_AUDIT_VERSION' => $expectedVersion,
                'DOCS_RELEASE_AUDIT_URL' => $auditUrl ?? 'file://'.$auditFile,
                'DOCS_RELEASE_AUDIT_ATTEMPTS' => '1',
                'DOCS_RELEASE_AUDIT_RETRY_SLEEP' => '0',
                'DOCS_RELEASE_AUDIT_EVIDENCE' => $evidenceFile,
                'DOCS_RELEASE_AUDIT_HANDOFF' => $handoffFile,
                'GITHUB_STEP_SUMMARY' => $summaryFile,
                'RUNNER_TEMP' => $tmpDir,
            ], $environment));

            $evidence = is_file($evidenceFile)
                ? json_decode((string) file_get_contents($evidenceFile), true, flags: JSON_THROW_ON_ERROR)
                : null;
            $handoff = is_file($handoffFile)
                ? json_decode((string) file_get_contents($handoffFile), true, flags: JSON_THROW_ON_ERROR)
                : null;
            $summary = is_file($summaryFile) ? (string) file_get_contents($summaryFile) : '';

            return $result + [
                'evidence' => $evidence,
                'handoff' => $handoff,
                'summary' => $summary,
            ];
        } finally {
            @unlink($auditFile);
            @unlink($evidenceFile);
            @unlink($handoffFile);
            @unlink($summaryFile);
            @rmdir($tmpDir);
        }
    }

    /**
     * @param  array<string, mixed>  $publicCatalog
     * @param  array<string, mixed>  $serverDiscovery
     * @return array{exitCode:int, stdout:string, stderr:string, evidence:array<string, mixed>}
     */
    private function runProtocolCatalogComparator(array $publicCatalog, array $serverDiscovery): array
    {
        $tmpDir = sys_get_temp_dir().'/release-protocol-catalog-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($tmpDir));
        $publicCatalogPath = $tmpDir.'/public.json';
        $serverDiscoveryPath = $tmpDir.'/server.json';
        $evidencePath = $tmpDir.'/evidence.json';
        file_put_contents($publicCatalogPath, json_encode($publicCatalog, JSON_THROW_ON_ERROR));
        file_put_contents($serverDiscoveryPath, json_encode($serverDiscovery, JSON_THROW_ON_ERROR));

        try {
            $result = $this->runScript('scripts/ci/verify-release-protocol-catalog.mjs', [
                'SERVER_DISCOVERY_PATH' => $serverDiscoveryPath,
                'PUBLIC_CATALOG_PATH' => $publicCatalogPath,
                'PROTOCOL_CATALOG_CONFORMANCE_EVIDENCE' => $evidencePath,
                'RELEASE_TAG' => '0.2.651',
                'SERVER_IMAGE' => 'durableworkflow/server:0.2.651',
                'WORKFLOW_PACKAGE_SOURCE' => 'https://github.com/durable-workflow/workflow.git',
                'WORKFLOW_PACKAGE_REF' => '2.0.0-rc.13',
                'WORKFLOW_PACKAGE_COMMIT' => 'fc28432a82a2433959c6690505c52eabea4aca8c',
            ]);

            $this->assertFileExists($evidencePath);

            return $result + [
                'evidence' => json_decode(
                    (string) file_get_contents($evidencePath),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                ),
            ];
        } finally {
            @unlink($publicCatalogPath);
            @unlink($serverDiscoveryPath);
            @unlink($evidencePath);
            @rmdir($tmpDir);
        }
    }

    /**
     * @param  array<string, string>  $env
     * @return array{exitCode:int, stdout:string, stderr:string}
     */
    private function runGuard(array $env): array
    {
        return $this->runScript('scripts/ci/validate-release-image-publish.sh', $env);
    }

    /**
     * @param  list<array{exitCode:int, stdout?:string, stderr?:string}>  $responses
     * @param  array<string, string>  $environment
     * @return array{exitCode:int, stdout:string, stderr:string, calls:list<string>}
     */
    private function runGithubReleasePublisher(array $responses, array $environment = []): array
    {
        $tmpDir = sys_get_temp_dir().'/github-release-publisher-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($tmpDir));
        $fakeGh = $tmpDir.'/gh';
        $statePath = $tmpDir.'/state';
        $logPath = $tmpDir.'/calls.log';
        $responsesPath = $tmpDir.'/responses';
        $this->assertTrue(mkdir($responsesPath));

        file_put_contents($fakeGh, <<<'SH'
#!/usr/bin/env bash
set -euo pipefail

attempt=0
if [ -f "$FAKE_GH_STATE" ]; then
    read -r attempt < "$FAKE_GH_STATE"
fi
attempt=$((attempt + 1))
printf '%s\n' "$attempt" > "$FAKE_GH_STATE"
printf '%s\n' "$*" >> "$FAKE_GH_LOG"

response="$FAKE_GH_RESPONSES/$attempt"
[ -f "$response.status" ] || exit 97
[ ! -s "$response.stdout" ] || cat "$response.stdout"
[ ! -s "$response.stderr" ] || cat "$response.stderr" >&2
read -r status < "$response.status"
exit "$status"
SH);
        $this->assertTrue(chmod($fakeGh, 0755));

        foreach ($responses as $index => $response) {
            $prefix = $responsesPath.'/'.($index + 1);
            file_put_contents($prefix.'.status', $response['exitCode']."\n");
            file_put_contents($prefix.'.stdout', isset($response['stdout']) ? $response['stdout']."\n" : '');
            file_put_contents($prefix.'.stderr', isset($response['stderr']) ? $response['stderr']."\n" : '');
        }

        try {
            $result = $this->runScript('scripts/ci/create-github-release.sh', array_merge([
                'RELEASE_TAG' => '2.0.0-rc.36',
                'GH_CLI' => $fakeGh,
                'GITHUB_RELEASE_MAX_ATTEMPTS' => '4',
                'GITHUB_RELEASE_RETRY_INITIAL_DELAY_SECONDS' => '0',
                'GITHUB_RELEASE_RETRY_MAX_DELAY_SECONDS' => '0',
                'FAKE_GH_STATE' => $statePath,
                'FAKE_GH_LOG' => $logPath,
                'FAKE_GH_RESPONSES' => $responsesPath,
            ], $environment));

            return $result + [
                'calls' => file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
            ];
        } finally {
            foreach (glob($responsesPath.'/*') ?: [] as $path) {
                @unlink($path);
            }
            @unlink($fakeGh);
            @unlink($statePath);
            @unlink($logPath);
            @rmdir($responsesPath);
            @rmdir($tmpDir);
        }
    }

    /**
     * @param  array<string, string>  $env
     * @return array{identity:string, transport_scope:string, ref:string, platforms:string, cache_from:string, cache_to:string}
     */
    private function resolveReleaseImageCache(array $env): array
    {
        $outputFile = tempnam(sys_get_temp_dir(), 'release-image-cache-output-');
        $this->assertIsString($outputFile);

        try {
            $result = $this->runScript('scripts/ci/resolve-release-image-cache.mjs', [
                'GITHUB_OUTPUT' => $outputFile,
            ] + $env);

            $this->assertSame(0, $result['exitCode'], $result['stderr']);
            $outputs = [];
            foreach (file($outputFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                [$name, $value] = explode('=', $line, 2);
                $outputs[$name] = $value;
            }

            $this->assertArrayHasKey('identity', $outputs);
            $this->assertArrayHasKey('transport_scope', $outputs);
            $this->assertArrayHasKey('ref', $outputs);
            $this->assertArrayHasKey('platforms', $outputs);
            $this->assertArrayHasKey('cache_from', $outputs);
            $this->assertArrayHasKey('cache_to', $outputs);

            return [
                'identity' => $outputs['identity'],
                'transport_scope' => $outputs['transport_scope'],
                'ref' => $outputs['ref'],
                'platforms' => $outputs['platforms'],
                'cache_from' => $outputs['cache_from'],
                'cache_to' => $outputs['cache_to'],
            ];
        } finally {
            @unlink($outputFile);
        }
    }

    /**
     * @return array{exitCode:int, stdout:string, stderr:string, outputs:string, dockerLog:string}
     */
    private function runExactImageVerifierWithConfigFixture(string $fixture, array $environment = []): array
    {
        $tmpDir = sys_get_temp_dir().'/release-image-config-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($tmpDir));
        $dockerBin = $tmpDir.'/docker';
        $dockerLog = $tmpDir.'/docker.log';
        $outputFile = $tmpDir.'/outputs';
        $fixturePath = $this->repoRoot.'/tests/Fixtures/ReleaseImageConfig/'.$fixture;
        $this->assertFileExists($fixturePath);

        $digest = 'sha256:96c63e55221e5aa6f82527f8369df7a986840eeb2cfb85276f0f415021fff757';
        $fixtureArgument = escapeshellarg($fixturePath);
        $logArgument = escapeshellarg($dockerLog);
        $dockerScript = <<<SH
#!/usr/bin/env sh
set -eu
printf '%s\n' "\$*" >> {$logArgument}
if [ "\$1" = "buildx" ] && [ "\$2" = "imagetools" ] && [ "\$3" = "inspect" ]; then
    if [ "\${4:-}" = "--format" ]; then
        cat {$fixtureArgument}
        exit 0
    fi
    printf 'Name: %s\nMediaType: application/vnd.oci.image.index.v1+json\nDigest: {$digest}\n\nManifests:\n  Platform: linux/amd64\n  Platform: linux/arm64\n' "\$4"
    exit 0
fi
exit 1
SH;
        file_put_contents($dockerBin, $dockerScript);
        chmod($dockerBin, 0755);

        try {
            $result = $this->runScript('scripts/ci/verify-release-exact-images.sh', array_merge([
                'RELEASE_TAG' => '2.0.0-rc.31',
                'DOCKER' => $dockerBin,
                'DOCKER_BUILD_OUTCOME' => 'success',
                'BUILT_IMAGE_DIGEST' => $digest,
                'RELEASE_COMMIT' => '6ee1245056ee25d4fead8688aa0056f952c838dc',
                'RELEASE_RUN_ID' => '31707164656',
                'RELEASE_RUN_ATTEMPT' => '2',
                'WORKFLOW_PACKAGE_SOURCE' => 'https://github.com/durable-workflow/workflow.git',
                'WORKFLOW_PACKAGE_REF' => '2.0.0-rc.31',
                'WORKFLOW_PACKAGE_COMMIT' => 'a4ce321a31ba5f4d9c25964cde81109bf253c5aa',
                'GITHUB_OUTPUT' => $outputFile,
            ], $environment));
            $outputs = file_get_contents($outputFile);
            $log = file_get_contents($dockerLog);
            $this->assertNotFalse($outputs);
            $this->assertNotFalse($log);

            return $result + [
                'outputs' => $outputs,
                'dockerLog' => $log,
            ];
        } finally {
            @unlink($dockerBin);
            @unlink($dockerLog);
            @unlink($outputFile);
            @rmdir($tmpDir);
        }
    }

    /**
     * @param  array<string, string>  $env
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
