<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SagaConformanceRunnerContractTest extends TestCase
{
    public function test_server_artifact_resolution_rejects_rolling_docker_tags(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            'SERVER_PATCH_TAG_RE = re.compile(r"^\d+\.\d+\.\d+',
            $source,
            'saga conformance must only record exact patch server tags as artifact versions',
        );
        $this->assertStringContainsString(
            'DW_SERVER_IMAGE must use an exact patch semver tag or an image digest',
            $source,
            'explicit saga server images must be exact tags or digest-pinned references',
        );
        $this->assertStringContainsString(
            'DW_SERVER_VERSION {version!r} does not match DW_SERVER_IMAGE tag {exact_image_tag!r}',
            $source,
            'saga conformance must not record a different server version than the image tag it runs',
        );
        $this->assertStringNotContainsString(
            '^\d+\.\d+(?:\.\d+)?(?:[-A-Za-z0-9.]+)?$',
            $source,
            'saga conformance must not accept rolling minor or major Docker tags from Docker Hub',
        );
    }

    public function test_generated_php_saga_workflows_pass_type_before_options(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            "Workflow::child('php.book-trip.failure', new ChildWorkflowOptions(queue: PHP_QUEUE), \$payload);",
            $source,
            'child workflow calls must pass the workflow type before child options',
        );
        $this->assertStringContainsString(
            "Workflow::activity(\n                    \$step['action'],\n                    new ActivityOptions(queue: runtime_queue((string) (\$payload['forward_runtime'] ?? 'workflow-php'))),\n                    \$payload\n                );",
            $source,
            'forward activity calls must pass the activity type before activity options',
        );
        $this->assertStringContainsString(
            'Workflow::activity($compensation, $options, $payload);',
            $source,
            'compensation activity calls must pass the activity type before activity options',
        );
        $this->assertStringContainsString(
            "Workflow::activity('pause_after_refund', new ActivityOptions(queue: runtime_queue(\$compensationRuntime)), \$payload);",
            $source,
            'pause activity calls must pass the activity type before activity options',
        );
        $this->assertStringNotContainsString(
            "Workflow::child(new ChildWorkflowOptions(queue: PHP_QUEUE), 'php.book-trip.failure', \$payload);",
            $source,
            'generated child workflow calls must not use the pre-v2 options-first order',
        );
        $this->assertStringNotContainsString(
            'Workflow::activity($options, $compensation, $payload);',
            $source,
            'generated activity calls must not use the pre-v2 options-first order',
        );
        $this->assertStringNotContainsString(
            "Workflow::activity(new ActivityOptions(queue: runtime_queue(\$compensationRuntime)), 'pause_after_refund', \$payload);",
            $source,
            'generated pause activity calls must not use the pre-v2 options-first order',
        );
    }

    public function test_cli_artifact_resolution_requires_downloadable_installer_asset(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            'github_release_with_downloadable_asset(',
            $source,
            'CLI artifact resolution must choose a release only after checking its required installer asset',
        );
        $this->assertStringContainsString(
            'https://api.github.com/repos/{repo}/releases?per_page=100&page={page}',
            $source,
            'default CLI artifact resolution must scan releases rather than trusting the latest redirect',
        );
        $this->assertStringContainsString(
            'asset_download_url(release, required_asset_name)',
            $source,
            'CLI artifact resolution must inspect release assets before recording the tag',
        );
        $this->assertStringContainsString(
            'url_is_downloadable(asset_url)',
            $source,
            'CLI artifact resolution must prove the installer asset is downloadable before recording the tag',
        );
        $this->assertStringContainsString(
            '"cli_installer_url": cli_installer_url',
            $source,
            'the verified installer URL must be preserved for the install step',
        );
        $this->assertStringContainsString(
            'published artifact pin resolution failed: $pin_resolution_error',
            $source,
            'incomplete release artifacts must surface as a focused pin-resolution blocker',
        );
        $this->assertStringNotContainsString(
            'releases/latest',
            $source,
            'CLI artifact resolution must not record the latest release before proving it has downloadable assets',
        );
        $this->assertStringNotContainsString(
            'https://github.com/durable-workflow/cli/releases/download/${cli_version#v}/install.sh',
            $source,
            'the install step must use the verified release asset URL rather than reconstructing one from an unchecked tag',
        );
    }

    public function test_restarted_python_worker_stays_available_for_later_scenarios(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            'ACTIVE_PYTHON_WORKER_PID = PYTHON_WORKER_PID',
            $source,
            'the saga orchestrator must track the currently live Python worker across recovery scenarios',
        );
        $this->assertStringContainsString(
            'RESTARTED_PYTHON_WORKERS.append(process)',
            $source,
            'the replacement Python worker must be retained until orchestrator cleanup',
        );
        $this->assertStringContainsString(
            'atexit.register(stop_restarted_python_workers)',
            $source,
            'replacement Python workers must be cleaned up when the orchestrator exits',
        );
        $this->assertStringNotContainsString(
            "if restarted is not None:\n        restarted.terminate()",
            $source,
            'the mid-compensation recovery scenario must not stop the replacement before cross-language and typed-error scenarios run',
        );
    }

    public function test_operator_visibility_does_not_probe_unbooted_waterline_routes(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');
        $unbootedWaterlineRoute = '/waterline/'.'api/instances';
        $oldEndpointProbeFinding = 'Waterline run-detail visibility endpoint '.'was unavailable';

        $this->assertStringContainsString(
            'def waterline_not_exercised_snapshot()',
            $source,
            'the saga runner must represent Waterline as an explicit unexercised surface unless it boots Waterline',
        );
        $this->assertStringContainsString(
            '"status": "not_exercised"',
            $source,
            'Waterline visibility must be reported as an unsupported coverage surface, not a server route failure',
        );
        $this->assertStringContainsString(
            'no Waterline route is probed on the server-only image',
            $source,
            'the saga runner evidence must explain that no Waterline app is present in this topology',
        );
        $this->assertStringNotContainsString(
            $unbootedWaterlineRoute,
            $source,
            'the server-only saga runner must not probe Waterline routes that it does not start or register',
        );
        $this->assertStringNotContainsString(
            $oldEndpointProbeFinding,
            $source,
            'Waterline coverage gaps must be recorded as topology support findings instead of failed endpoint probes',
        );
    }

    private function read(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        $this->assertNotFalse($source, "{$path} must be readable");

        return $source;
    }
}
