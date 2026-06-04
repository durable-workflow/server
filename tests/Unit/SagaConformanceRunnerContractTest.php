<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;

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

    public function test_runner_accepts_equals_form_result_dir(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            'Usage: sagas-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]',
            $source,
            'the published runner contract must document both result directory flag forms',
        );
        $this->assertStringContainsString(
            '--result-dir=*)',
            $source,
            'host runners may pass --result-dir=<dir>; this must not exit before sagas-result.json can be written',
        );
        $this->assertStringContainsString(
            'result_dir="${1#--result-dir=}"',
            $source,
            'the equals-form result directory must be parsed before prerequisite checks run',
        );
        $this->assertStringContainsString(
            '--keep-run-root=*)',
            $source,
            'host runners may pass boolean runner flags in equals form without blocking before evidence can be written',
        );
        $this->assertStringContainsString(
            'if [[ "$keep_run_root" == "true" ]]; then',
            $source,
            'true-valued equals-form runner flags must preserve the run root instead of parsing as false',
        );
    }

    public function test_generated_php_saga_workflows_pass_type_before_options(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            "Workflow::activity(\n                    \$step['action'],\n                    new ActivityOptions(queue: runtime_queue((string) (\$payload['forward_runtime'] ?? 'workflow-php'))),\n                    \$payload\n                );",
            $source,
            'forward activity calls must pass the activity type before activity options',
        );
        $this->assertStringContainsString(
            "Workflow::activity(\n                        'saga_planned_failure',\n                        new ActivityOptions(queue: runtime_queue((string) (\$payload['forward_runtime'] ?? 'workflow-php'))),\n                        \$payload\n                    );",
            $source,
            'planned saga failures should be activity failures so compensation scenarios exercise the activity/compensation contract',
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
            "Workflow::activity(new ActivityOptions(queue: runtime_queue((string) (\$payload['forward_runtime'] ?? 'workflow-php'))), 'saga_planned_failure', \$payload);",
            $source,
            'generated planned-failure activity calls must not use the pre-v2 options-first order',
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

    public function test_artifact_metadata_uses_manifest_php_workflow_key(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            '"workflow-php": workflow_version',
            $source,
            'resolved pins must use the saga manifest artifact key for the PHP workflow package',
        );
        $this->assertStringContainsString(
            '"workflow": workflow_version',
            $source,
            'resolved pins must also publish the platform release artifact key used by coverage comparison',
        );
        $this->assertStringContainsString(
            '"workflow-php": "packagist"',
            $source,
            'artifact sources must use the same manifest key as published artifact versions',
        );
        $this->assertStringContainsString(
            '"workflow": "packagist"',
            $source,
            'artifact sources must include the platform release artifact alias for coverage comparison',
        );
        $this->assertStringContainsString(
            '["workflow-php"])',
            $source,
            'the installer handoff must read the PHP workflow package through the manifest artifact key',
        );
        $this->assertStringContainsString(
            '"workflow-php": pins["workflow-php"]',
            $source,
            'run metadata must emit workflow-php in published_artifact_versions',
        );
        $this->assertStringContainsString(
            '"workflow": pins["workflow"]',
            $source,
            'run metadata must also emit workflow in published_artifact_versions for release coverage',
        );
        $this->assertStringContainsString(
            '("server","cli","workflow","workflow-php","sdk-python","waterline")',
            $source,
            'blocked results must preserve both the platform release key and saga runtime key for the PHP package',
        );
    }

    public function test_runner_reports_suite_version_from_saga_scenario_manifest(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');
        $manifest = json_decode(
            $this->read('static/platform-conformance/saga-runtime-scenarios.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertStringContainsString(
            'saga_scenario_manifest="${DW_SAGAS_SCENARIO_MANIFEST:-$repo_root/static/platform-conformance/saga-runtime-scenarios.json}"',
            $source,
            'the runner must use the advertised saga scenario manifest as its suite-version source',
        );
        $this->assertSame(
            PlatformConformanceSuite::VERSION,
            $manifest['suite_version'],
            'the shipped saga runner handoff must stay aligned with the current public saga suite version',
        );
        $this->assertStringContainsString(
            'saga_suite_version="$(read_saga_suite_version)"',
            $source,
            'the runner must resolve suite_version before writing result metadata',
        );
        $this->assertStringContainsString(
            '"suite_version": $saga_suite_version',
            $source,
            'blocked saga results must report the manifest suite version instead of a hardcoded value',
        );
        $this->assertStringContainsString(
            '"suite_version": metadata["suite_version"]',
            $source,
            'completed saga results must carry the manifest suite version through run metadata',
        );
        $this->assertStringNotContainsString(
            '"suite_version": ' . PlatformConformanceSuite::VERSION,
            $source,
            'the saga runner must not hardcode a suite version that can drift from the public manifest',
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

    public function test_after_forward_charge_card_scenarios_use_after_forward_expectations(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            "\"failure_at_c_after_forward_compensation\": {\n        \"forward\": [\"reserve_flight\", \"reserve_hotel\", \"charge_card\"],\n        \"compensation\": [\"refund_card\", \"cancel_hotel\", \"cancel_flight\"],",
            $source,
            'after-forward charge_card failures must expect the charge and refund rows',
        );
        $this->assertStringContainsString(
            'expected_id: str | None = None',
            $source,
            'shared scenario checks must allow callers to use scenario-specific evidence with different row expectations',
        );
        $this->assertStringContainsString(
            'expected_id="failure_at_c_after_forward_compensation"',
            $source,
            'cross-language compensation scenarios must validate after-forward charge_card evidence',
        );
        $this->assertStringContainsString(
            'EXPECTED["failure_at_c_after_forward_compensation"]',
            $source,
            'mid-compensation recovery must validate after-forward charge_card evidence',
        );
        $this->assertStringContainsString(
            '"resumed_compensation_step": "cancel_hotel"',
            $source,
            'restart recovery must report the step resumed after refund_card',
        );
        $this->assertStringNotContainsString(
            '"resumed_compensation_step": "cancel_flight"',
            $source,
            'restart recovery must not skip over cancel_hotel in its evidence',
        );
        $this->assertStringNotContainsString(
            'compensation != EXPECTED["failure_at_c_reverse_compensation"]["compensation"]',
            $source,
            'restart recovery must not reuse before-forward charge_card compensation expectations',
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
        $this->assertStringContainsString(
            'status = scenario_status(failures)',
            $source,
            'a Waterline-only observer gap must not make otherwise passing saga product behavior non-passing',
        );
        $this->assertStringContainsString(
            '"routed_operator_surface_findings": routed_findings',
            $source,
            'Waterline observer gaps must stay routed in the scenario evidence for separate coverage work',
        );
        $this->assertStringNotContainsString(
            'status = "unsupported" if unsupported_findings and not failures else scenario_status(failures)',
            $source,
            'the server-only Waterline topology gap must not force operator visibility to unsupported when server and CLI evidence passed',
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

    public function test_waterline_install_verification_pins_matching_workflow_artifact(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            "\"durable-workflow/workflow:\$workflow_version\" \\\n    \"durable-workflow/waterline:\$waterline_version\"",
            $source,
            'the Waterline install check must root-pin the matching alpha workflow artifact instead of leaving it as a transitive unstable dependency',
        );
        $this->assertStringNotContainsString(
            "composer require --no-interaction --no-progress \"durable-workflow/waterline:\$waterline_version\"",
            $source,
            'the Waterline install check must not require Waterline alone in a fresh Composer root',
        );
    }

    public function test_runner_uses_per_run_server_endpoint_and_worker_container(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            'DW_SAGAS_SERVER_PORT          Host port for the published server. Defaults to a free port.',
            $source,
            'published-artifact saga runs must be able to avoid fixed host port collisions',
        );
        $this->assertStringContainsString(
            'DW_SAGAS_SERVER_BIND_HOST     Docker host interface for the server port. Defaults to 0.0.0.0.',
            $source,
            'the server port must be publishable beyond loopback for containerized host-runner topologies',
        );
        $this->assertStringContainsString(
            'DW_SAGAS_SERVER_CONNECT_HOST  First host/address to probe. Defaults to 127.0.0.1.',
            $source,
            'host runners must retain a localhost-first probe while allowing automatic fallbacks',
        );
        $this->assertStringContainsString(
            'DW_SAGAS_SERVER_URL           Exact server URL to use; disables automatic endpoint probing.',
            $source,
            'operators must still be able to pin an explicit server URL when the host topology needs one',
        );
        $this->assertStringContainsString(
            'choose_free_port()',
            $source,
            'the saga runner must choose a per-run host port when no override is supplied',
        );
        $this->assertStringContainsString(
            'server_bind_host="${DW_SAGAS_SERVER_BIND_HOST:-0.0.0.0}"',
            $source,
            'the default compose publish address must not be loopback-only when the host runner may execute inside a container',
        );
        $this->assertStringContainsString(
            'server_url_candidates=()',
            $source,
            'the saga runner must probe multiple candidate server URLs before declaring the server unreachable',
        );
        $this->assertStringContainsString(
            'default_route_gateway()',
            $source,
            'containerized host runners need a default-gateway fallback for ports published on the Docker host',
        );
        $this->assertStringContainsString(
            'docker_bridge_gateway()',
            $source,
            'the runner should also try Docker bridge gateway discovery when localhost is not the right namespace',
        );
        $this->assertStringContainsString(
            'server_base_url="${server_url_candidates[0]}"',
            $source,
            'generated workers and the orchestrator must start from the first resolved endpoint candidate',
        );
        $this->assertStringContainsString(
            '- "${server_bind_host}:${server_port}:8080"',
            $source,
            'the compose server must bind the resolved per-run host port instead of hardcoding 8080',
        );
        $this->assertStringContainsString(
            'wait_for_server_ready',
            $source,
            'host reachability must be checked before scenario failures are counted as product evidence',
        );
        $this->assertStringContainsString(
            'export DW_SAGAS_SERVER_URL="$server_base_url"',
            $source,
            'the PHP worker, Python worker, CLI, and orchestrator must share the endpoint that actually answered readiness',
        );
        $this->assertStringContainsString(
            'update_run_metadata_server_url',
            $source,
            'run metadata must record the actual reachable endpoint instead of a failed first probe',
        );
        $this->assertStringContainsString(
            'server-url-candidates.txt',
            $source,
            'unreachable-server findings must leave the probed endpoints as diagnostic evidence',
        );
        $this->assertStringContainsString(
            'define(\'BASE_URL\', getenv(\'DW_SAGAS_SERVER_API_URL\') ?: \'http://127.0.0.1:8080/api\');',
            $source,
            'the generated PHP worker must use the resolved endpoint handed to its container',
        );
        $this->assertStringContainsString(
            'SERVER_URL = os.environ.get("DW_SAGAS_SERVER_URL", "http://127.0.0.1:8080").rstrip("/")',
            $source,
            'the Python worker and orchestrator must use the resolved endpoint rather than localhost:8080',
        );
        $this->assertStringContainsString(
            'php_worker_container="${DW_SAGAS_PHP_WORKER_CONTAINER:-dw-sagas-php-worker-${run_label}}"',
            $source,
            'parallel saga runs must not share one global PHP worker container name',
        );
        $this->assertStringContainsString(
            'docker run -d --name "$php_worker_container" --network host',
            $source,
            'the PHP worker launch must use the per-run container name',
        );
        $this->assertStringNotContainsString(
            '- "8080:8080"',
            $source,
            'published-artifact sagas must not require exclusive ownership of host port 8080',
        );
        $this->assertStringNotContainsString(
            'docker run -d --name dw-sagas-php-worker',
            $source,
            'parallel saga runs must not collide on a fixed PHP worker container',
        );
        $this->assertStringNotContainsString(
            'Client("http://localhost:8080"',
            $source,
            'host Python clients must not be pinned to localhost:8080',
        );
    }

    public function test_non_pass_findings_include_routable_contract_fields(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            'emit_structured_findings_array()',
            $source,
            'blocked saga results must emit structured scenario findings',
        );
        $this->assertStringContainsString(
            '"scenario_id": scenario_id',
            $source,
            'runtime findings must preserve the scenario identity',
        );
        $this->assertStringContainsString(
            '"owning_surface": surface',
            $source,
            'runtime findings must route to the owning public surface',
        );
        $this->assertStringContainsString(
            '"artifact_versions": current_artifact_versions()',
            $source,
            'runtime findings must carry the published artifact tuple under test',
        );
        $this->assertStringContainsString(
            '"observed_behavior": observed_behavior or summary',
            $source,
            'runtime findings must describe the observed behavior',
        );
        $this->assertStringContainsString(
            '"next_acceptance_criterion": next_acceptance_criterion',
            $source,
            'runtime findings must name the next criterion for turning the scenario green',
        );
        $this->assertStringNotContainsString(
            '"findings": ["scenario did not execute"]',
            $source,
            'missing scenario findings must not be plain strings',
        );
    }

    public function test_orchestrator_records_scenario_exceptions_before_exiting(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            'async def capture_scenario(',
            $source,
            'scenario-level errors must be converted into conformance findings instead of aborting the whole runner',
        );
        $this->assertStringContainsString(
            'return scenario_exception_result(scenario_id, label, exc, language=language)',
            $source,
            'captured scenario exceptions must retain scenario and runtime identity',
        );
        $this->assertStringContainsString(
            'output envelope decode failed',
            $source,
            'workflow output decode failures must be reported as scenario evidence rather than crashing before sagas-result.json is written',
        );
        $this->assertStringContainsString(
            'describe failed while waiting for terminal state',
            $source,
            'control-plane read failures must be reported as scenario evidence rather than crashing before sagas-result.json is written',
        );
        $this->assertStringContainsString(
            '"runnerBlocked": False',
            $source,
            'once the orchestrator reaches scenario execution, failures should be product or focused scenario evidence rather than runner-blocked noise',
        );
    }

    public function test_php_runner_uses_published_workflow_fiber_runner(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            'use Workflow\V2\Worker\WorkflowFiberRunner;',
            $source,
            'the PHP saga worker must use the published worker-protocol replay runner instead of a partial local replay loop',
        );
        $this->assertStringContainsString(
            'WorkflowFiberRunner::forClass(',
            $source,
            'PHP workflow tasks must be replayed by the package runner that understands persisted command sequences',
        );
        $this->assertStringContainsString(
            'complete_workflow_task($task, $step->commands);',
            $source,
            'the generated PHP worker must submit the package runner command envelope directly',
        );
        $this->assertStringNotContainsString(
            'function complete_current_call(',
            $source,
            'the saga handoff must not keep the ad hoc PHP command replay loop that can re-emit completed steps',
        );
    }

    public function test_planned_saga_failures_are_activity_failures_with_bounded_waits(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            "saga_planned_failure = define_activity(\"saga_planned_failure\")",
            $source,
            'Python planned failures must be registered as activities rather than child workflows',
        );
        $this->assertStringContainsString(
            "except ActivityFailed:",
            $source,
            'Python saga workflows must compensate planned activity failures without replaying through child failure paths',
        );
        $this->assertStringContainsString(
            'WAIT_RESULT_TIMEOUT_SECONDS = float(os.environ.get("DW_SAGAS_WAIT_RESULT_TIMEOUT", "45"))',
            $source,
            'scenario waits must be short enough to record focused product evidence before the host runner timeout',
        );
        $this->assertStringNotContainsString(
            'python.book-trip.failure',
            $source,
            'the saga runner should not use child workflows to inject planned step failures',
        );
    }

    private function read(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        $this->assertNotFalse($source, "{$path} must be readable");

        return $source;
    }
}
