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
            'SERVER_PATCH_TAG_RE = re.compile(',
            $source,
            'saga conformance must validate exact server release tags before recording artifact versions',
        );
        $this->assertStringContainsString(
            'DW_SERVER_IMAGE must use an exact SemVer tag or an image digest',
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
            '("server","cli","workflow","workflow-php","sdk-php","sdk-python","waterline")',
            $source,
            'blocked results must preserve the complete Composer artifact tuple',
        );
        $this->assertStringContainsString('"sdk-php": pins["sdk-php"]', $source);
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
            '"suite_version": '.PlatformConformanceSuite::VERSION,
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
            'PYTHON_WORKER_ID = "python-sagas-worker"',
            $source,
            'the runner must check the durable Python worker registration, not only the process id',
        );
        $this->assertStringContainsString(
            'def wait_for_python_worker_registration(',
            $source,
            'Python restarts must wait for a registered active worker before later scenarios run',
        );
        $this->assertStringContainsString(
            'control_plane_get(f"/workers/{PYTHON_WORKER_ID}", timeout=5)',
            $source,
            'Python worker readiness must use the public worker-management control plane surface',
        );
        $this->assertStringContainsString(
            'if "python.book-trip" not in workflow_types:',
            $source,
            'the Python worker must advertise the saga workflow type before Python scenarios begin',
        );
        $this->assertStringContainsString(
            'SAGA_ACTIVITY_TYPES = [',
            $source,
            'worker readiness checks must share the full saga activity surface',
        );
        $this->assertStringContainsString(
            'if not process_alive(ACTIVE_PYTHON_WORKER_PID):',
            $source,
            'a stale Python worker registration must not be accepted after the process dies',
        );
        $this->assertStringContainsString(
            'missing.append("process_alive")',
            $source,
            'Python readiness evidence must name a dead replacement process explicitly',
        );
        $this->assertStringContainsString(
            'wait_for_python_worker_registration("mid_compensation_worker_restart", not_before=restarted_at)',
            $source,
            'the mid-compensation Python restart must require a post-restart registration heartbeat before later scenarios run',
        );
        $this->assertStringContainsString(
            'os.kill(ACTIVE_PYTHON_WORKER_PID, signal.SIGTERM)',
            $source,
            'an alive but unregistered Python worker must be replaced instead of leaving later scenarios pending',
        );
        $this->assertStringContainsString(
            'python_worker_required: bool = False',
            $source,
            'terminal waits must be able to monitor Python worker liveness for Python-dependent scenarios',
        );
        $this->assertStringContainsString(
            'ensure_python_worker_running(wait_label or workflow_id)',
            $source,
            'Python worker liveness must be checked during terminal waits, not only before workflow start',
        );
        $this->assertStringContainsString(
            'atexit.register(stop_restarted_python_workers)',
            $source,
            'replacement Python workers must be cleaned up when the orchestrator exits',
        );
        $this->assertStringContainsString(
            '"python_worker_restart_observations": PYTHON_WORKER_RESTART_OBSERVATIONS',
            $source,
            'the completed saga report must expose Python worker restarts in machine-readable evidence',
        );
        $this->assertStringContainsString(
            '"python_worker_ready_observations": PYTHON_WORKER_READY_OBSERVATIONS',
            $source,
            'the completed saga report must expose active Python worker registration checks as machine-readable evidence',
        );
        $this->assertStringNotContainsString(
            "if restarted is not None:\n        restarted.terminate()",
            $source,
            'the mid-compensation recovery scenario must not stop the replacement before cross-language and typed-error scenarios run',
        );
    }

    public function test_php_dependent_saga_scenarios_restart_stopped_php_worker(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            'PHP_WORKER_RESTART_OBSERVATIONS: list[dict[str, Any]] = []',
            $source,
            'the saga runner must retain PHP worker restarts as conformance evidence',
        );
        $this->assertStringContainsString(
            'def ensure_php_worker_running(reason: str) -> None:',
            $source,
            'PHP-dependent scenarios must verify the PHP worker before relying on it for terminal state',
        );
        $this->assertStringContainsString(
            'PHP_WORKER_ID = "php-sagas-worker"',
            $source,
            'the runner must check the durable worker registration, not only the Docker container name',
        );
        $this->assertStringContainsString(
            'control_plane_get(f"/workers/{PHP_WORKER_ID}", timeout=5)',
            $source,
            'PHP worker readiness must use the public worker-management control plane surface',
        );
        $this->assertStringContainsString(
            "\"X-Durable-Workflow-Protocol-Version\": \"1.7\",\n            \"X-Durable-Workflow-Control-Plane-Version\": \"2\",",
            $source,
            'PHP worker readiness must include the control-plane version required by worker-management routes',
        );
        $this->assertStringContainsString(
            'function send_worker_heartbeat(): array',
            $source,
            'the generated PHP worker must refresh its worker-management liveness after registration',
        );
        $this->assertStringContainsString(
            "request_json('POST', '/worker/heartbeat', worker_status_payload(), 10, [404])",
            $source,
            'the generated PHP worker must call the public worker heartbeat endpoint',
        );
        $this->assertStringContainsString(
            'maybe_send_worker_heartbeat($nextHeartbeatAt, $heartbeatEverySeconds);',
            $source,
            'the generated PHP worker must emit heartbeats during its long-poll loop',
        );
        $this->assertStringContainsString(
            "'process_started_at' => WORKER_STARTED_AT",
            $source,
            'PHP worker heartbeats must include process identity so restarted workers are distinguishable',
        );
        $this->assertStringContainsString(
            'if "php.book-trip" not in workflow_types:',
            $source,
            'the PHP worker must advertise the saga workflow type before PHP scenarios begin',
        );
        $this->assertStringContainsString(
            'for activity in SAGA_ACTIVITY_TYPES:',
            $source,
            'the PHP worker must advertise the complete forward, compensation, marker, and failure handler set before scenarios rely on it',
        );
        $this->assertStringContainsString(
            '"refund_card",',
            $source,
            'readiness checks must cover the first cross-language compensation activity',
        );
        $this->assertStringContainsString(
            '"saga_planned_failure",',
            $source,
            'readiness checks must cover the planned failure activity that enters compensation',
        );
        $this->assertStringContainsString(
            'wait_for_php_worker_registration(reason)',
            $source,
            'a running PHP worker container is not enough; it must be registered and active',
        );
        $this->assertStringContainsString(
            'missing.append("fresh_heartbeat")',
            $source,
            'PHP worker restarts must wait for a heartbeat from the replacement process instead of accepting a stale registration',
        );
        $this->assertStringContainsString(
            'wait_for_php_worker_registration(reason, not_before=restarted_at)',
            $source,
            'the normal PHP worker restart path must require a post-restart registration heartbeat',
        );
        $this->assertStringContainsString(
            'wait_for_php_worker_registration("mid_compensation_worker_restart", not_before=restarted_at)',
            $source,
            'the mid-compensation PHP restart must also require a post-restart registration heartbeat before resuming assertions',
        );
        $this->assertStringContainsString(
            'restart_php_worker(reason)',
            $source,
            'a stopped PHP worker must be restarted instead of leaving workflows stuck pending',
        );
        $this->assertStringContainsString(
            'restart_php_worker(f"{reason}-stale-registration")',
            $source,
            'a running PHP container with stale or incomplete registration must be replaced before waiting for terminal state',
        );
        $this->assertStringContainsString(
            'capture_php_worker_logs(reason)',
            $source,
            'PHP worker replacement must preserve the stopped container logs as request/history-adjacent evidence',
        );
        $this->assertStringContainsString(
            'php_worker_required: bool = False',
            $source,
            'terminal waits must be able to monitor PHP worker liveness for PHP-dependent scenarios',
        );
        $this->assertStringContainsString(
            'ensure_php_worker_running(wait_label or workflow_id)',
            $source,
            'PHP worker liveness must be checked during terminal waits, not only before workflow start',
        );
        $this->assertStringContainsString(
            'ensure_php_worker_running(f"{workflow_type} payload worker startup")',
            $source,
            'generic payload worker checks must use the same registration-aware PHP readiness gate',
        );
        $this->assertStringNotContainsString(
            'def ensure_php_worker_running() -> None:',
            $source,
            'the merged runner must not leave a no-argument PHP readiness helper shadowed by the registration-aware helper',
        );
        $this->assertStringContainsString(
            'php_worker_required = uses_php_worker(language, payload)',
            $source,
            'scenario topology must drive PHP worker liveness checks for PHP workflows and PHP-routed activities',
        );
        $this->assertStringContainsString(
            '"php_worker_restart_observations": PHP_WORKER_RESTART_OBSERVATIONS',
            $source,
            'the completed saga report must expose PHP worker restarts in machine-readable evidence',
        );
        $this->assertStringContainsString(
            '"php_worker_ready_observations": PHP_WORKER_READY_OBSERVATIONS',
            $source,
            'the completed saga report must expose active PHP worker registration checks as machine-readable evidence',
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

    public function test_operator_visibility_boots_and_probes_published_waterline_app(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            'composer create-project --no-interaction --no-progress laravel/laravel .',
            $source,
            'the saga runner must create a real Laravel host app for the published Waterline package',
        );
        $this->assertStringContainsString(
            'durable-workflow/waterline:${waterline_version}@beta',
            $source,
            'the Waterline host app must install the published Waterline package version under test',
        );
        $this->assertStringContainsString(
            'WATERLINE_ENGINE_SOURCE: v2',
            $source,
            'the generated Waterline host app must be pinned to the v2 operator bridge',
        );
        $this->assertStringContainsString(
            'WATERLINE_HEALTH_TASK_DISPATCH_MODE: poll',
            $source,
            'the generated Waterline host app must evaluate readiness as a read-only observer instead of requiring a local async queue',
        );
        $this->assertStringContainsString(
            'DW_V2_TASK_DISPATCH_MODE: poll',
            $source,
            'the generated Waterline host app must demote sync queue capability notes while the server and queue worker own task execution',
        );
        $this->assertStringContainsString(
            '- "$run_root/waterline-app:/app"',
            $source,
            'the compose topology must boot the generated host app, not only install the package',
        );
        $this->assertStringContainsString(
            '- server-db:/app/database',
            $source,
            'Waterline must connect to the same saga run database as the published server',
        );
        $this->assertStringContainsString(
            'wait_for_waterline_ready',
            $source,
            'the runner must prove the Waterline app is reachable before scenario evidence is counted',
        );
        $this->assertStringContainsString(
            'def waterline_operator_evidence(workflow_id: str, run_id: str) -> dict[str, Any]:',
            $source,
            'the orchestrator must collect Waterline operator evidence for the selected saga run',
        );
        $this->assertStringContainsString(
            '"/waterline/api/instances/{encoded_workflow_id}/runs/{encoded_run_id}?history_limit=all"',
            $source,
            'Waterline selected-run detail must be captured for the paused compensation workflow',
        );
        $this->assertStringContainsString(
            'GET /waterline/api/flows/running',
            $source,
            'Waterline list evidence must be captured alongside selected-run detail evidence',
        );
        $this->assertStringContainsString(
            '"waterline_operator_evidence": waterline_evidence',
            $source,
            'operator_visible_mid_compensation_status must emit the Waterline evidence object as a required scenario field',
        );
        $this->assertStringContainsString(
            'Waterline current compensation marker expected pause_after_refund',
            $source,
            'the scenario must fail when Waterline cannot expose the current compensation marker',
        );
        $this->assertStringNotContainsString(
            'waterline_not_exercised_snapshot',
            $source,
            'the saga runner must not keep reporting Waterline as an unexercised server-only gap',
        );
        $this->assertStringNotContainsString(
            '"routed_operator_surface_findings": routed_findings',
            $source,
            'Waterline observer failures must no longer be routed away as a passing product scenario',
        );
    }

    public function test_waterline_install_verification_pins_matching_workflow_artifact(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            "'durable-workflow/waterline:\${waterline_version}@beta' \\\n    'durable-workflow/workflow:\${workflow_version}@beta' \\\n    'durable-workflow/sdk:\${php_sdk_version}@beta'",
            $source,
            'the Waterline host app install must root-pin the complete matching prerelease tuple',
        );
        $this->assertStringNotContainsString(
            'composer require --no-interaction --no-progress "durable-workflow/waterline:$waterline_version"',
            $source,
            'the Waterline host app install must not require Waterline alone in a fresh Composer root',
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

    public function test_nonzero_runner_exit_downgrades_passing_record_before_returning(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            'finalize_saga_record_for_exit()',
            $source,
            'the shell runner must reconcile generated evidence with the final process exit status',
        );
        $this->assertStringContainsString(
            'finalize_saga_record_for_exit "$code"',
            $source,
            'the EXIT trap cleanup must run the record finalizer before returning an original non-zero status',
        );
        $this->assertStringContainsString(
            'finalize_saga_record_for_exit 1',
            $source,
            'cleanup-induced non-zero exits after a passing run must also downgrade the record',
        );
        $this->assertStringContainsString(
            'exit "$code"',
            $source,
            'the cleanup trap must preserve the runner exit status after finalizing the record',
        );
        $this->assertStringContainsString(
            'if not declares_pass(result) and not declares_pass(record):',
            $source,
            'non-passing saga records should retain their existing focused scenario findings',
        );
        $this->assertStringContainsString(
            '"id": "sagas-runner-exit-status-mismatch"',
            $source,
            'a passing record paired with a non-zero process exit must emit a routable mismatch finding',
        );
        $this->assertStringContainsString(
            '"scenario_id": "runner_exit_status"',
            $source,
            'the mismatch finding must name the runner-exit diagnostic scenario',
        );
        $this->assertStringContainsString(
            '"owning_surface": "conformance_harness"',
            $source,
            'exit-status mismatches route to the conformance harness rather than a product surface',
        );
        $this->assertStringContainsString(
            'result["outcome"] = "error"',
            $source,
            'sagas-result.json must not keep outcome=pass when the runner returns a non-zero status',
        );
        $this->assertStringContainsString(
            'record["outcome"] = "error"',
            $source,
            'sagas-record.json must not keep outcome=pass when the runner returns a non-zero status',
        );
        $this->assertStringContainsString(
            'replace_pass_aliases(record)',
            $source,
            'pass-valued record aliases must be cleared when the final runner exit is non-zero',
        );
        $this->assertStringContainsString(
            'record["runnerExitStatus"] = exit_code',
            $source,
            'the ledger record must preserve the observed runner exit status',
        );
    }

    public function test_success_and_scenario_failure_records_include_runner_exit_status(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            'runner_exit_status = 0 if outcome == "pass" else 1',
            $source,
            'the orchestrator must derive result exit-status evidence from the outcome it is about to return',
        );
        $this->assertStringContainsString(
            '"runner_exit_status": runner_exit_status',
            $source,
            'the normal sagas-result.json path must record runner_exit_status=0 for pass records',
        );
        $this->assertStringContainsString(
            '"runnerExitStatus": runner_exit_status',
            $source,
            'the normal sagas-record.json path must preserve the runner exit status for ledger ingestion',
        );
        $this->assertStringContainsString(
            'local exit_status="${3:-1}"',
            $source,
            'runner-blocked records must default to a non-zero status while allowing the error trap to preserve a concrete status',
        );
        $this->assertStringContainsString(
            '"runner_exit_status": $exit_status',
            $source,
            'runner-blocked records must also preserve their non-zero process exit status',
        );
        $this->assertStringContainsString(
            '"runnerExitStatus": $exit_status',
            $source,
            'runner-blocked ledger records must expose their non-zero process exit status',
        );
    }

    public function test_php_runner_uses_published_workflow_fiber_runner(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            'use Workflow\V2\Support\WorkflowFiberRunner;',
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

    public function test_generated_php_worker_survives_rejected_workflow_task_completion(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            'function fail_protocol_workflow_task(array $task, \Throwable $throwable, string $prefix): void',
            $source,
            'the PHP saga worker must be able to report workflow-task failures separately from terminal workflow failures',
        );
        $this->assertStringContainsString(
            "'/worker/workflow-tasks/'.\$task['task_id'].'/fail'",
            $source,
            'rejected command completions must go to the workflow-task fail endpoint instead of recursively completing the task',
        );
        $this->assertStringContainsString(
            "'workflow task completion failed after commands were produced'",
            $source,
            'command completion failures must surface with the same replay-blocking reason as official workers',
        );
        $this->assertStringContainsString(
            "'terminal workflow failure command was rejected'",
            $source,
            'terminal compensation failure command rejections must become workflow-task evidence without killing the PHP worker',
        );
        $this->assertStringContainsString(
            'PHP saga workflow poll loop error',
            $source,
            'one PHP workflow-task handling error must not terminate the worker before later saga scenarios run',
        );
        $this->assertStringContainsString(
            'PHP saga activity poll loop error',
            $source,
            'one PHP activity-task handling error must not terminate the worker before later saga scenarios run',
        );
    }

    public function test_generated_php_worker_reports_waiting_replay_as_history_wait(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            'use Workflow\V2\Support\WorkflowStep;',
            $source,
            'the generated PHP worker must inspect package runner wait steps explicitly',
        );
        $this->assertStringContainsString(
            'function report_waiting_workflow_task(array $task, WorkflowStep $step): void',
            $source,
            'a package runner wait must be reported as a typed workflow-task wait, not a workflow terminal failure',
        );
        $this->assertStringContainsString(
            "'type' => 'WorkflowTaskWaitingForHistory'",
            $source,
            'waiting for a cross-runtime compensation activity must produce a stable protocol wait type',
        );
        $this->assertStringContainsString(
            "if (\$step->commands === []) {\n            report_waiting_workflow_task(\$task, \$step);\n            return;\n        }",
            $source,
            'the PHP saga worker must not complete an empty command list or terminally fail the workflow while history is still open',
        );
    }

    public function test_orchestrator_restarts_dead_workers_before_dependency_scenarios(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            'def ensure_workers_for_payload(workflow_type: str, payload: dict[str, Any]) -> None:',
            $source,
            'the saga orchestrator must verify worker liveness before starting workflows that depend on them',
        );
        $this->assertStringContainsString(
            'ensure_workers_for_payload(workflow_type, payload)',
            $source,
            'every scenario start must pass through the worker liveness gate',
        );
        $this->assertStringContainsString(
            'def php_worker_container_running() -> bool:',
            $source,
            'the orchestrator must inspect the generated PHP worker container after restart-sensitive scenarios',
        );
        $this->assertStringContainsString(
            'restart_php_worker(reason)',
            $source,
            'dead PHP workers must be restarted before PHP workflows or PHP compensation activities are expected to run',
        );
        $this->assertStringContainsString(
            'start_replacement_python_worker("python-worker-auto-restart.log")',
            $source,
            'dead Python workers must be restarted before Python workflows or Python compensation activities are expected to run',
        );
        $this->assertStringContainsString(
            'str(payload.get("compensation_runtime") or ("workflow-php" if workflow_type.startswith("php.") else "sdk-python"))',
            $source,
            'cross-language compensation scenarios must keep the compensation runtime worker alive, not just the workflow runtime',
        );
    }

    public function test_planned_saga_failures_are_activity_failures_with_bounded_waits(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            'saga_planned_failure = define_activity("saga_planned_failure")',
            $source,
            'Python planned failures must be registered as activities rather than child workflows',
        );
        $this->assertStringContainsString(
            'except ActivityFailed:',
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

    public function test_definitive_compensation_failures_do_not_retry_forever(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            "if (\$activity === 'cancel_flight' && (\$payload['cancel_flight_fail'] ?? false)) {\n        return 1;\n    }",
            $source,
            'PHP compensation failure visibility scenarios must make cancel_flight definitive instead of leaving workflows pending on retries',
        );
        $this->assertStringContainsString(
            'elif compensation == "cancel_flight" and payload.get("cancel_flight_fail"):',
            $source,
            'Python typed compensation error scenarios must make cancel_flight definitive before collecting terminal evidence',
        );
        $this->assertStringContainsString(
            'retry_policy = {"max_attempts": 1, "backoff_seconds": [0]}',
            $source,
            'definitive compensation failures must use one attempt so the runner records request and history evidence without timing out pending',
        );
    }

    public function test_generated_saga_side_store_business_effects_are_idempotent(): void
    {
        $source = $this->read('scripts/conformance/sagas-published-artifacts.sh');

        $this->assertStringContainsString(
            'function business_effect_key(array $row): ?string',
            $source,
            'the generated PHP saga worker must derive a stable idempotency key for business-effect rows',
        );
        $this->assertStringContainsString(
            'business_effect_key($decoded) === $effectKey',
            $source,
            'the generated PHP saga worker must suppress duplicate side-store business effects after task redelivery',
        );
        $this->assertStringContainsString(
            "'idempotency_key' => \$task['idempotency_key'] ?? \$task['activity_execution_id'] ?? null,",
            $source,
            'PHP activity rows must prefer the server-provided activity idempotency key',
        );
        $this->assertStringContainsString(
            'def business_effect_key(row: dict[str, Any]) -> str | None:',
            $source,
            'the generated Python saga worker must also derive stable business-effect keys',
        );
        $this->assertStringContainsString(
            'if effect_key is not None and effect_key in existing_business_effect_keys(handle):',
            $source,
            'the generated Python saga worker must suppress duplicate side-store business effects after task redelivery',
        );
        $this->assertStringContainsString(
            'metadata = activity_metadata()',
            $source,
            'Python activity rows must include current activity attempt metadata for duplicate-delivery diagnostics',
        );
    }

    private function read(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        $this->assertNotFalse($source, "{$path} must be readable");

        return $source;
    }
}
