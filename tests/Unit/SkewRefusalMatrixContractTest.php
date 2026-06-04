<?php

namespace Tests\Unit;

use App\Support\SkewRefusalMatrixContract;
use App\Support\SkewRefusalMatrixResultGate;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;

final class SkewRefusalMatrixContractTest extends TestCase
{
    public function test_manifest_advertises_identity_and_artifact_policy(): void
    {
        $manifest = SkewRefusalMatrixContract::manifest();

        $this->assertSame('durable-workflow.v2.skew-refusal-matrix.contract', $manifest['schema']);
        $this->assertSame(1, $manifest['version']);
        $this->assertSame('durable-workflow.v2.skew-refusal-matrix.result', $manifest['result_schema']);
        $this->assertSame(1, $manifest['result_version']);
        $this->assertSame(
            PlatformConformanceSuite::SCHEMA,
            $manifest['platform_conformance_suite_authority'],
        );
        $this->assertSame(
            PlatformConformanceSuite::SCHEMA,
            $manifest['scenario_manifest']['suite_schema'],
        );
        $this->assertSame(
            PlatformConformanceSuite::VERSION,
            $manifest['scenario_manifest']['suite_version'],
        );
        $this->assertSame(
            'static/platform-conformance/skew-refusal-matrix-scenarios.json',
            $manifest['scenario_manifest']['source_path'],
        );
        $this->assertSame(
            [
                'published_artifact_install_only',
                'cli_version_pair_matrix',
                'sdk_python_version_pair_matrix',
                'workflow_worker_version_pair_matrix',
                'waterline_version_pair_matrix',
                'future_version_boundary_matrix',
                'request_response_capture_for_skewed_operations',
                'focused_finding_routing',
            ],
            $manifest['required_scenarios'],
        );

        foreach (['server', 'cli', 'sdk-python', 'workflow', 'waterline'] as $artifact) {
            $this->assertContains($artifact, $manifest['artifact_policy']['required_artifacts']);
            $this->assertArrayHasKey($artifact, $manifest['artifact_policy']['install_channels']);
        }

        foreach ([
            'artifact_versions',
            'runner_blocked',
            'surface_results',
            'pairing_results',
            'operation_evidence',
            'request_response_captures',
            'finding_links',
        ] as $field) {
            $this->assertContains($field, $manifest['artifact_policy']['required_run_record_fields']);
        }
    }

    public function test_scenario_manifest_source_path_is_published_and_matches_contract(): void
    {
        $manifest = SkewRefusalMatrixContract::manifest();
        $scenarioManifestPath = dirname(__DIR__, 2) . '/' . $manifest['scenario_manifest']['source_path'];

        $this->assertFileExists(
            $scenarioManifestPath,
            'cluster info must not advertise a skew-refusal scenario manifest source path that is missing from the release tree',
        );

        $scenarioManifest = json_decode(
            (string) file_get_contents($scenarioManifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame($manifest['scenario_manifest']['schema'], $scenarioManifest['schema']);
        $this->assertSame($manifest['scenario_manifest']['version'], $scenarioManifest['version']);
        $this->assertSame($manifest['scenario_manifest']['category'], $scenarioManifest['category']);
        $this->assertSame($manifest['scenario_manifest']['suite_schema'], $scenarioManifest['suite_schema']);
        $this->assertSame($manifest['scenario_manifest']['suite_version'], $scenarioManifest['suite_version']);
        $this->assertSame(PlatformConformanceSuite::VERSION, $scenarioManifest['suite_version']);
        $this->assertSame($manifest['result_schema'], $scenarioManifest['result_schema']);
        $this->assertSame($manifest['scenario_statuses'], $scenarioManifest['result_statuses']);
        $this->assertSame(
            $manifest['required_scenarios'],
            array_column($scenarioManifest['scenarios'], 'id'),
        );
        $this->assertSame(
            $manifest['artifact_policy']['required_artifacts'],
            $scenarioManifest['artifact_policy']['required_artifacts'],
        );
        $this->assertSame(
            $manifest['artifact_policy']['required_run_record_fields'],
            $scenarioManifest['artifact_policy']['required_run_record_fields'],
        );

        foreach ($manifest['artifact_policy']['required_run_record_fields'] as $field) {
            $this->assertContains(
                $field,
                $scenarioManifest['common_result_evidence'],
                sprintf('public skew scenario manifest must advertise run record field %s', $field),
            );
        }

        $this->assertNotContains('linked_findings', $scenarioManifest['common_result_evidence']);
        $this->assertContains(
            'finding_links',
            $scenarioManifest['scenario_requirements']['focused_finding_routing']['required_fields'],
        );
        $this->assertNotContains(
            'linked_findings',
            $scenarioManifest['scenario_requirements']['focused_finding_routing']['required_fields'],
        );
        $this->assertSame(array_keys($manifest['required_surfaces']), $scenarioManifest['required_matrix']['surfaces']);
        $this->assertSame(
            $manifest['required_surfaces']['cli']['required_pairing_classes'],
            $scenarioManifest['required_matrix']['pairing_classes'],
        );

        foreach ($manifest['required_surfaces'] as $surface => $surfaceContract) {
            $this->assertSame(
                $surfaceContract['operation_groups'],
                $scenarioManifest['required_matrix']['operation_groups'][$surface],
                sprintf('public skew scenario manifest operation groups drifted for %s', $surface),
            );
        }

        $this->assertSame(
            $manifest['worker_skew_classification']['allowed'],
            $scenarioManifest['required_matrix']['worker_skew_classifications'],
        );
        $this->assertSame(
            $manifest['waterline_skew_classification']['allowed'],
            $scenarioManifest['required_matrix']['waterline_skew_classifications'],
        );
        $this->assertSame(
            [
                ...$manifest['worker_skew_classification']['blocking'],
                ...$manifest['waterline_skew_classification']['blocking'],
            ],
            $scenarioManifest['required_matrix']['blocking_classifications'],
        );
        $this->assertSame(
            $manifest['host_runner_contract'],
            $scenarioManifest['host_runner_contract'],
        );
    }

    public function test_required_surfaces_cover_full_skew_matrix(): void
    {
        $manifest = SkewRefusalMatrixContract::manifest();
        $requiredClasses = ['compatible', 'backward_skew', 'forward_skew', 'outside_window'];

        foreach (['cli', 'sdk-python', 'workflow-worker', 'waterline'] as $surface) {
            $this->assertArrayHasKey($surface, $manifest['required_surfaces']);
            $this->assertSame(
                $requiredClasses,
                $manifest['required_surfaces'][$surface]['required_pairing_classes'],
                "$surface must cover compatible, backward, forward, and outside-window pairings",
            );
            $this->assertContains(
                'cluster_info_probe',
                $manifest['required_surfaces'][$surface]['operation_groups'],
                "$surface must prove cluster-info compatibility discovery",
            );
            $this->assertContains(
                'suggests_upgrade_or_pin_next_step',
                $manifest['required_surfaces'][$surface]['refusal_requirements'],
                "$surface refusals must tell users the next step",
            );
        }

        $this->assertContains(
            'worker_lifecycle',
            $manifest['required_surfaces']['workflow-worker']['operation_groups'],
        );
        $this->assertContains(
            'waterline_render',
            $manifest['required_surfaces']['waterline']['operation_groups'],
        );
    }

    public function test_blocking_classifications_and_smoke_gate_are_explicit(): void
    {
        $manifest = SkewRefusalMatrixContract::manifest();

        $this->assertSame(
            ['register_and_drop'],
            $manifest['worker_skew_classification']['blocking'],
            'a worker that registers and drops work must block the release',
        );
        $this->assertSame(
            ['stale_render'],
            $manifest['waterline_skew_classification']['blocking'],
            'Waterline stale render must route as a blocking product finding',
        );

        $gate = $manifest['coverage_gate'];
        $this->assertTrue($gate['full_matrix_required']);
        $this->assertSame('non_passing_smoke_only', $gate['smoke_only_outcome']);
        $this->assertTrue($gate['all_required_surfaces_required']);
        $this->assertTrue($gate['all_pairing_classes_required_per_surface']);
        $this->assertTrue($gate['all_advertised_requests_required_per_operation_group']);
        $this->assertTrue($gate['outside_window_pairs_must_loud_refuse']);
        $this->assertTrue($gate['silent_success_is_blocking']);
        $this->assertTrue($gate['silent_failure_is_blocking']);
        $this->assertTrue($gate['corrupt_is_blocking']);

        $this->assertSame(SkewRefusalMatrixResultGate::SCHEMA, $manifest['result_gate']['schema']);
        $this->assertContains(
            'every_required_operation_group_has_evidence_for_every_pairing_class',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'every_advertised_operation_request_has_matching_evidence',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'smoke_only_results_remain_non_passing',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'each_non_pass_cell_has_a_focused_finding_link',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'operation_capture_ids_resolve_to_attached_request_response_captures',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'next_step',
            $manifest['operation_groups']['workflow_control_plane']['evidence'],
            'wire evidence must carry the skew-contract next step alongside version and compatibility-window context',
        );
        $this->assertContains(
            'next_step',
            $manifest['operation_groups']['cluster_info_probe']['evidence'],
            'cluster-info skew evidence must carry the same next-step context as mutating rows',
        );
    }

    public function test_manifest_publishes_host_runner_contract_for_full_skew_matrix(): void
    {
        $manifest = SkewRefusalMatrixContract::manifest();
        $hostRunner = $manifest['host_runner_contract'];

        $this->assertSame('required_for_passing_skew_refusal_matrix_conformance', $hostRunner['status']);
        $this->assertSame('server', $hostRunner['runner_repository']);
        $this->assertSame('scripts/conformance/skew-published-artifacts.sh', $hostRunner['runner_path']);
        $this->assertSame(
            'scripts/conformance/skew-published-artifacts.sh --result-dir <result-dir>',
            $hostRunner['runner_command'],
        );
        $this->assertSame(SkewRefusalMatrixContract::RESULT_SCHEMA, $hostRunner['result_schema']);
        $this->assertSame(
            [
                'pins.json',
                'run-metadata.json',
                'skew-result.json',
                'skew-record.json',
                'request-response-captures.json',
            ],
            $hostRunner['result_files'],
        );
        $this->assertTrue($hostRunner['must_execute_against_published_artifacts']);
        $this->assertSame($manifest['required_scenarios'], $hostRunner['required_scenarios']);
        $this->assertTrue($hostRunner['must_record_runner_blocked_false_for_product_evidence']);
        $this->assertTrue($hostRunner['must_emit_result_for_every_required_surface_pairing_operation_group']);
        $this->assertTrue($hostRunner['must_capture_request_response_for_every_skewed_operation']);
        $this->assertSame('non_passing_smoke_only', $hostRunner['smoke_summary_only_outcome']);
        $this->assertSame('not_covered', $hostRunner['unexecuted_required_cell_status']);
        $this->assertSame('conformance_runner_coverage_gap', $hostRunner['coverage_gap_finding_type']);
        $this->assertSame('conformance_harness', $hostRunner['coverage_gap_owner']);

        foreach ([
            'published-artifact-install',
            'cli-skew-surface-shard',
            'sdk-python-skew-surface-shard',
            'workflow-worker-skew-surface-shard',
            'waterline-skew-surface-shard',
            'future-version-boundary-shard',
            'request-response-evidence-shard',
        ] as $scope) {
            $this->assertContains($scope, $hostRunner['required_execution_scopes']);
        }

        foreach (['cli', 'sdk-python', 'workflow-worker', 'waterline'] as $surface) {
            $this->assertArrayHasKey($surface, $hostRunner['runtime_shards']);
            $this->assertSame(
                ['compatible', 'backward_skew', 'forward_skew', 'outside_window'],
                $hostRunner['runtime_shards'][$surface]['must_cover_pairing_classes'],
            );
            $this->assertSame('not_covered', $hostRunner['runtime_shards'][$surface]['fallback_status_when_surface_missing']);
            $this->assertSame(
                'conformance_runner_coverage_gap',
                $hostRunner['runtime_shards'][$surface]['fallback_finding_type'],
            );
        }

        $this->assertContains(
            'workflow_control_plane',
            $hostRunner['runtime_shards']['cli']['must_cover_operation_groups'],
        );
        $this->assertContains(
            'worker_lifecycle',
            $hostRunner['runtime_shards']['workflow-worker']['must_cover_operation_groups'],
        );
        $this->assertSame(
            'register_and_drop',
            $hostRunner['runtime_shards']['workflow-worker']['blocking_classification'],
        );
        $this->assertContains(
            'waterline_render',
            $hostRunner['runtime_shards']['waterline']['must_cover_operation_groups'],
        );
        $this->assertSame(
            'stale_render',
            $hostRunner['runtime_shards']['waterline']['blocking_classification'],
        );
        $this->assertSame(
            'conformance_runner_coverage_gap',
            $hostRunner['routing_policy']['missing_required_cell']['finding_type'],
        );
        $this->assertSame(
            'durable-workflow/waterline',
            $hostRunner['routing_policy']['waterline_stale_render']['owner'],
        );
    }

    public function test_published_artifact_runner_handoff_covers_full_matrix_outputs(): void
    {
        $shell = $this->read('scripts/conformance/skew-published-artifacts.sh');
        $runner = $this->read('scripts/conformance/skew-published-artifacts.mjs');

        $this->assertStringContainsString(
            'Usage: skew-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]',
            $shell,
            'the skew runner must document the host handoff flag forms',
        );
        $this->assertStringContainsString(
            'DW_SKEW_SERVER_URL',
            $shell,
            'host runners must be able to attach the skew matrix to an already running published server',
        );
        $this->assertStringContainsString(
            'DW_SERVER_IMAGE must use an exact patch semver tag or an image digest',
            $shell,
            'the skew runner must not record rolling server image tags as published-artifact evidence',
        );
        $this->assertStringContainsString(
            'docker image pull "$server_image"',
            $shell,
            'the skew runner must pull the exact published server image before compose startup so stale local tags cannot be recorded as current artifact evidence',
        );
        $this->assertStringContainsString(
            "tr -c 'a-z0-9_-' '-'",
            $shell,
            'the skew runner must replace dots and other invalid characters before deriving the Docker Compose project name',
        );
        $this->assertStringNotContainsString(
            "tr -c 'a-z0-9_." . "-' '-'",
            $shell,
            'the default mktemp basename contains a dot, which is not valid in Docker Compose project names',
        );
        $this->assertStringContainsString(
            'docker-image-pull.log',
            $shell,
            'server image pull failures must leave diagnostics before the runner writes a blocked result',
        );
        $this->assertStringContainsString(
            'docker-image-inspect.json',
            $shell,
            'server image resolution evidence should be attached for compose-backed skew runs',
        );
        $this->assertStringContainsString(
            'probed published server version mismatch',
            $runner,
            'the Node runner must refuse to record skew evidence when DW_SKEW_SERVER_URL points at a server that does not match DW_SERVER_VERSION',
        );
        $this->assertStringContainsString(
            'did not report a server version from GET /api/cluster/info',
            $runner,
            'the Node runner must fail closed when cluster-info cannot prove the probed server artifact version',
        );
        $this->assertStringContainsString(
            'skew conformance requires exact published artifact semver pins',
            $runner,
            'the result recorder must reject floating package constraints before emitting published-artifact evidence',
        );
        $this->assertStringContainsString(
            'isExactSemverVersion',
            $runner,
            'the result recorder must have a concrete semver guard beyond placeholder-string checks',
        );
        $this->assertStringNotContainsString(
            'extractServerVersion(clusterInfo.body) ?? artifactVersions.server',
            $runner,
            'the skew runner must not fall back to the requested server pin when the probed server did not advertise that version',
        );
        $this->assertStringContainsString(
            'const operationGroups = {',
            $runner,
            'the skew runner must carry operation-group request templates instead of reporting cluster-info smoke only',
        );
        $this->assertStringContainsString(
            "'workflow_control_plane'",
            $runner,
            'CLI and Python coverage must include workflow control-plane operations',
        );
        $this->assertStringContainsString(
            "'schedule_control_plane'",
            $runner,
            'CLI and Python coverage must include schedule operations',
        );
        $this->assertStringContainsString(
            "'worker_lifecycle'",
            $runner,
            'Python and PHP worker skew coverage must include worker lifecycle operations',
        );
        $this->assertStringContainsString(
            "'waterline_render'",
            $runner,
            'Waterline skew coverage must include render probes with DOM evidence',
        );
        $this->assertStringContainsString(
            'request-response-captures.json',
            $runner,
            'every skewed operation must be attachable as request/response evidence',
        );
        $this->assertStringContainsString(
            'DURABLE_WORKFLOW_INSTALL_DIR',
            $shell,
            'the skew runner must install the CLI through the official published installer before reporting CLI evidence',
        );
        $this->assertStringContainsString(
            'python3 -m venv',
            $shell,
            'the skew runner must isolate and install the published Python SDK artifact before reporting SDK evidence',
        );
        $this->assertStringContainsString(
            'durable-workflow==${DW_PYTHON_SDK_VERSION}',
            $shell,
            'the skew runner must pin the Python SDK to the published artifact version under test',
        );
        $this->assertStringContainsString(
            'durable-workflow/workflow:${workflow_version}',
            $shell,
            'the skew runner must install the PHP workflow package from Packagist before worker-shard evidence can pass',
        );
        $this->assertStringContainsString(
            'Workflow install requires an exact durable-workflow/workflow version',
            $shell,
            'the skew runner must not install floating workflow package constraints as published-artifact evidence',
        );
        $this->assertStringContainsString(
            'durable-workflow/waterline:${DW_WATERLINE_VERSION}',
            $shell,
            'the skew runner must install Waterline from Packagist before Waterline-shard evidence can pass',
        );
        $this->assertStringContainsString(
            'Waterline install requires an exact durable-workflow/waterline version',
            $shell,
            'the skew runner must not install floating Waterline package constraints as published-artifact evidence',
        );
        $this->assertStringContainsString(
            'DW_WORKFLOW_PHP_VERSION or DW_WORKFLOW_VERSION is required as an exact workflow pin before installing Waterline',
            $shell,
            'the Waterline install check must require a concrete workflow package pin before composer can resolve dependencies',
        );
        $this->assertStringContainsString(
            'DW_SKEW_WATERLINE_URL',
            $shell,
            'Waterline render evidence must require a running Waterline HTTP surface in addition to Composer package install',
        );
        $this->assertStringContainsString(
            'surface_url: env.WATERLINE_SURFACE_URL',
            $shell,
            'the artifact handoff must carry the Waterline surface URL that was actually rendered through',
        );
        $this->assertStringNotContainsString(
            '${workflow_version:-^2.0.0-alpha@alpha}',
            $shell,
            'the Waterline install check must not fall back to a floating workflow alpha constraint',
        );
        $this->assertStringContainsString(
            'DW_SKEW_ARTIFACTS_JSON',
            $shell,
            'the shell handoff must tell the Node runner which published artifacts were actually installed',
        );
        $this->assertStringContainsString(
            'published-artifact-invocation-recording-proxy',
            $runner,
            'the Node runner must use an artifact invocation path with recorded proxy evidence rather than direct server-only probes',
        );
        $this->assertStringContainsString(
            'invokeCliOperation',
            $runner,
            'CLI matrix cells must invoke the installed dw artifact',
        );
        $this->assertStringContainsString(
            "return [...global, 'server:info', '--output=json'];",
            $runner,
            'CLI cluster-info probes must use the shared output option because server:info does not define --json',
        );
        $this->assertStringNotContainsString(
            "return [...global, 'server:info', '--json'];",
            $runner,
            'the runner must not invoke server:info with an unsupported command-local --json option',
        );
        $this->assertStringContainsString(
            'invokePythonSdkOperation',
            $runner,
            'Python matrix cells must invoke the installed durable-workflow package',
        );
        $this->assertStringContainsString(
            'invokeWorkflowWorkerOperation',
            $runner,
            'PHP worker matrix cells must execute the Composer-installed durable-workflow/workflow artifact instead of remaining installed-only evidence',
        );
        $this->assertStringContainsString(
            'invokeWaterlineOperation',
            $runner,
            'Waterline matrix cells must execute the Composer-installed durable-workflow/waterline artifact instead of remaining installed-only evidence',
        );
        $this->assertStringContainsString(
            'workflow-worker-skew-probe.php',
            $runner,
            'the workflow worker shard must generate a PHP probe that requires the published package autoload file',
        );
        $this->assertStringContainsString(
            'waterline-skew-probe.php',
            $runner,
            'the Waterline shard must generate a PHP probe that requires the published package autoload file',
        );
        $this->assertStringContainsString(
            'WorkflowPackageApiFloor::findMissing()',
            $runner,
            'Waterline evidence must include the published package API-floor detector output',
        );
        $this->assertStringContainsString(
            'waterlineSurfaceUrlFor(record)',
            $runner,
            'Waterline render evidence must distinguish installed package metadata from a running Waterline surface',
        );
        $this->assertStringContainsString(
            'Composer package install alone is not Waterline render evidence.',
            $runner,
            'the runner must mark Waterline render rows not_covered instead of attributing direct server responses to Waterline',
        );
        $this->assertStringContainsString(
            "'--add-host'",
            $runner,
            'Dockerized PHP probes must route through an explicit host-gateway recording proxy instead of depending on Docker host networking',
        );
        $this->assertStringContainsString(
            "'host.docker.internal'",
            $runner,
            'Dockerized PHP probes must default to a host-gateway name that resolves the host-side recording proxy from inside the container',
        );
        $this->assertStringContainsString(
            ':host-gateway',
            $runner,
            'Dockerized PHP probes must install a host-gateway mapping for the recording proxy',
        );
        $this->assertStringContainsString(
            'artifactProxyHost',
            $runner,
            'the recording proxy URL handed to Dockerized PHP probes must use the container-reachable host-gateway name',
        );
        $this->assertStringContainsString(
            "'DW_SKEW_AUTH_TOKEN'",
            $runner,
            'Dockerized PHP probes must pass auth through the environment rather than serialized argv payloads',
        );
        $this->assertStringNotContainsString(
            'this runner does not yet boot a PHP worker process through the package API',
            $runner,
            'the workflow package shard must no longer report installed artifacts as not_covered without executing a probe',
        );
        $this->assertStringNotContainsString(
            'this runner does not yet boot a Waterline app and capture DOM evidence',
            $runner,
            'the Waterline package shard must no longer report installed artifacts as not_covered without executing a probe',
        );
        $this->assertStringContainsString(
            'DW_SKEW_AUTH_TOKEN: process.env.DW_SKEW_AUTH_TOKEN',
            $runner,
            'Python SDK probes must pass auth outside recorded JSON argv payloads',
        );
        $this->assertStringContainsString(
            'DURABLE_WORKFLOW_CONTROL_PLANE_VERSION: pairing.controlPlaneVersion',
            $runner,
            'CLI and Python artifact invocations must receive the row control-plane version when the published artifact supports conformance overrides',
        );
        $this->assertStringContainsString(
            'DURABLE_WORKFLOW_WORKER_PROTOCOL_VERSION: pairing.workerProtocolVersion',
            $runner,
            'CLI and Python artifact invocations must receive the row worker protocol version when the published artifact supports conformance overrides',
        );
        $this->assertStringContainsString(
            'token=os.environ.get("DW_SKEW_AUTH_TOKEN")',
            $runner,
            'the generated Python probe must read auth from its environment instead of argv JSON',
        );
        $this->assertStringContainsString(
            'f"/workflows/{workflow_id}/runs/{run_id}/signal/advance"',
            $runner,
            'Python SDK run-specific signal rows must produce exact advertised request evidence',
        );
        $this->assertStringContainsString(
            'f"/workflows/{workflow_id}/runs/{run_id}/query/currentState"',
            $runner,
            'Python SDK run-specific query rows must produce exact advertised request evidence',
        );
        $this->assertStringContainsString(
            'f"/workflows/{workflow_id}/runs/{run_id}/update/approve"',
            $runner,
            'Python SDK run-specific update rows must produce exact advertised request evidence',
        );
        $this->assertStringNotContainsString(
            'token: process.env.DW_SKEW_AUTH_TOKEN',
            $runner,
            'Python SDK probes must not serialize auth into artifact_invocation.args',
        );
        $this->assertStringContainsString(
            'redactJsonSecrets(parsed)',
            $runner,
            'artifact argv redaction must sanitize JSON payload tokens before writing evidence files',
        );
        $this->assertStringContainsString(
            'isSensitiveKey(key)',
            $runner,
            'JSON argv redaction must identify token-like fields rather than only --token= flags',
        );
        $this->assertStringContainsString(
            'notCoveredProbe',
            $runner,
            'unimplemented shards must emit explicit not_covered evidence instead of pretending public artifacts were exercised',
        );
        $this->assertStringContainsString(
            'workflowWorkerDependentRequests',
            $runner,
            'CLI and Python query/update probes must be distinguishable from worker-independent workflow control-plane probes',
        );
        $this->assertStringContainsString(
            'requires a live compatible published workflow worker for skew_conformance_workflow',
            $runner,
            'worker-backed CLI and Python probes must stay not_covered until the published worker shard is booted',
        );
        $this->assertStringContainsString(
            'DW_SKEW_LIVE_WORKFLOW_WORKER_READY',
            $runner,
            'worker-backed CLI and Python probes must require an explicit live-worker coordination signal, not only an installed package',
        );
        $this->assertStringContainsString(
            'Workflow package availability alone is not live worker coordination.',
            $runner,
            'the skew runner must not treat a Composer-installed workflow package as proof that query/update probes can be served',
        );
        $this->assertStringContainsString(
            "surfaceName !== 'sdk-python'",
            $runner,
            'compatible CLI query/update probes must still reach the CLI and record structured server interop evidence when no live worker is coordinated',
        );
        $this->assertStringContainsString(
            'isCompatibleCliControlPlaneInterop',
            $runner,
            'compatible CLI control-plane domain responses must classify as inside-window interop rather than silent_failure',
        );
        $this->assertStringContainsString(
            'structured_control_plane_domain_response',
            $runner,
            'compatible CLI rows must label structured control-plane domain responses as interop evidence',
        );
        $this->assertStringContainsString(
            'next_step: nextStep',
            $runner,
            'skew operation evidence must record next-step text alongside the version and compatibility window fields',
        );
        $this->assertStringContainsString(
            'requires a workflow task id obtained from a successful fixture poll before completing or failing an inside-window task',
            $runner,
            'complete/fail probes must stay not_covered until poll returns a real task id',
        );
        $this->assertStringContainsString(
            'Protocol-refusal rows may use the advertised task placeholder only when the server must reject before task lookup.',
            $runner,
            'worker lifecycle probes must distinguish inside-window task interop from unsupported protocol refusal',
        );
        $this->assertStringNotContainsString(
            'task-skew-conformance',
            $runner,
            'published-artifact worker complete/fail probes must use task ids obtained from poll rather than a synthetic fixture id',
        );
        $this->assertStringContainsString(
            "if (pairingClass !== 'compatible')",
            $runner,
            'skewed query/update rows must reach the artifact so unsupported control-plane versions can refuse before worker dispatch',
        );
        $this->assertStringContainsString(
            'workerProtocolCompatible(',
            $runner,
            'worker task-id prerequisites must be based on the worker protocol compatibility window',
        );
        $this->assertStringContainsString(
            'prepareWorkerTaskFixture',
            $runner,
            'inside-window worker lifecycle probes must prepare real queued workflow tasks before polling, completing, or failing tasks',
        );
        $this->assertStringContainsString(
            'workflowTaskAttemptFromBody(pollResponse.body) ?? 1',
            $runner,
            'worker fixture polling must preserve the leased workflow task attempt for completion and failure evidence',
        );
        $this->assertStringContainsString(
            'fixture.workflowTaskAttempt = workflowTaskAttempt',
            $runner,
            'published-artifact complete/fail probes must use the attempt returned by the fixture poll',
        );
        $this->assertStringContainsString(
            'workflow_task_attempt: state.workflowTaskAttempt ?? 1',
            $runner,
            'generated worker probe payloads must carry the leased workflow task attempt',
        );
        $this->assertStringContainsString(
            'evidence.worker_version = surfaceVersion',
            $runner,
            'workflow-worker evidence rows must explicitly name the published workflow package version under test',
        );
        $this->assertStringContainsString(
            'evidence.worker_protocol_version = pairing.workerProtocolVersion',
            $runner,
            'workflow-worker evidence rows must explicitly name the worker protocol version used for the pairing',
        );
        $this->assertStringContainsString(
            'capture.worker_version = surfaceVersion',
            $runner,
            'workflow-worker request/response captures must carry the published workflow package version under test',
        );
        $this->assertStringContainsString(
            'result.worker_protocol_version = pairing.workerProtocolVersion',
            $runner,
            'workflow-worker pairing summaries must carry the pairing worker protocol version',
        );
        $this->assertStringContainsString(
            "supported_workflow_types: ['skew_conformance_workflow']",
            $runner,
            'worker task fixtures must register workflow-capable workers so poll evidence can lease tasks',
        );
        $this->assertStringContainsString(
            'supported_workflow_types=["skew_conformance_workflow"]',
            $runner,
            'the Python worker lifecycle probe must advertise workflow capability before polling',
        );
        $this->assertStringContainsString(
            'failure_type="SkewConformanceFailure"',
            $runner,
            'the generated Python worker probe must use the SDK fail_workflow_task failure_type keyword',
        );
        $this->assertStringContainsString(
            'commands=[{"type": "complete_workflow", "result": None}]',
            $runner,
            'inside-window Python complete probes must send a server-valid workflow completion command',
        );
        $this->assertStringContainsString(
            'workflow_task_attempt=workflow_task_attempt',
            $runner,
            'inside-window Python complete/fail probes must use the leased task attempt instead of a hardcoded attempt',
        );
        $this->assertStringNotContainsString(
            'workflow_task_attempt=1',
            $runner,
            'inside-window Python complete/fail probes must not hardcode the workflow task attempt',
        );
        $this->assertStringNotContainsString(
            'commands=[]',
            $runner,
            'inside-window Python complete probes must not send an empty command list',
        );
        $this->assertStringContainsString(
            <<<'PHP'
$workflowTaskAttempt = (int) ($payload['workflow_task_attempt'] ?? 1);
PHP,
            $runner,
            'inside-window PHP worker probes must decode the leased workflow task attempt',
        );
        $this->assertStringContainsString(
            <<<'PHP'
$client->completeWorkflowTask(
            $taskId,
            [[
                'type' => 'complete_workflow',
                'result' => null,
            ]],
PHP,
            $runner,
            'inside-window PHP worker complete probes must send a server-valid workflow completion command',
        );
        $this->assertStringContainsString(
            <<<'PHP'
            $workflowTaskAttempt,
        )),
PHP,
            $runner,
            'inside-window PHP worker complete/fail probes must pass the leased workflow task attempt',
        );
        $this->assertStringNotContainsString(
            <<<'PHP'
$client->completeWorkflowTask(
            $taskId,
            [],
PHP,
            $runner,
            'inside-window PHP worker complete probes must not send an empty command list',
        );
        $this->assertStringNotContainsString(
            'exception_type="SkewConformanceFailure"',
            $runner,
            'the generated Python worker probe must not use the legacy exception_type keyword',
        );
        $this->assertStringContainsString(
            'futureVersionBoundary',
            $runner,
            'future-version boundary evidence must be emitted for client, worker, observer, and server surfaces',
        );
        $this->assertStringContainsString(
            'register_and_drop',
            $runner,
            'worker skew must classify register-and-drop as a blocking product finding',
        );
        $this->assertStringContainsString(
            'stale_render',
            $runner,
            'Waterline stale render must classify as a blocking product finding',
        );
        $this->assertMatchesRegularExpression(
            "/const pairingStatusPriority = \\[\\s*'corrupt',\\s*'silent_success',\\s*'silent_failure',\\s*'not_covered',\\s*'runner_blocked',\\s*\\];/s",
            $runner,
            'product blocker statuses must outrank not_covered and runner_blocked when a pairing mixes product and coverage gaps',
        );
        $this->assertStringContainsString(
            'const prioritizedStatus = pairingStatusPriority.find((value) => statuses.includes(value));',
            $runner,
            'pairing summaries must use the explicit status priority instead of observed row order',
        );
        $this->assertStringNotContainsString(
            "statuses.find((value) => ['corrupt', 'silent_success', 'silent_failure', 'not_covered', 'runner_blocked'].includes(value))",
            $runner,
            'observed row order must not decide the blocking status for a mixed pairing',
        );
        $this->assertStringContainsString(
            'row.worker_skew_classification === findingStatus',
            $runner,
            'worker product-gap findings should attach the capture for the row that produced the blocking classification',
        );
        $this->assertStringContainsString(
            'row.waterline_skew_classification === findingStatus',
            $runner,
            'Waterline product-gap findings should attach the capture for the row that produced the blocking classification',
        );
        $this->assertStringContainsString(
            "if (status === 'not_covered' || status === 'runner_blocked')",
            $runner,
            'missing-cell coverage gaps and host-environment runner gaps must route to the conformance harness',
        );
        $this->assertStringContainsString(
            "return 'conformance_harness';",
            $runner,
            'runner-owned skew gaps must be owned by the conformance harness rather than artifact repositories',
        );
        $this->assertStringContainsString(
            'server_artifact_source="published_server_url"',
            $shell,
            'existing published server URLs must be recorded as URL-backed server artifacts',
        );
        $this->assertStringContainsString(
            'server_artifact_source="docker"',
            $shell,
            'server artifacts started from a resolved Docker image must be recorded as Docker-backed artifacts',
        );
        $this->assertStringContainsString(
            'SERVER_ARTIFACT_SOURCE="$server_artifact_source"',
            $shell,
            'the artifact manifest must use the resolved server artifact source, not only the original DW_SERVER_IMAGE env',
        );
        $this->assertStringContainsString(
            'docker-compose-up.log',
            $shell,
            'server image pull or startup failures must still write blocked result files with compose diagnostics',
        );
        $this->assertStringContainsString(
            'published server failed to start from ${server_image}',
            $shell,
            'docker compose startup failures must be wrapped with write_blocked_result instead of exiting before result files are written',
        );
        $this->assertStringContainsString(
            'compose_cleanup_needed=1',
            $shell,
            'the skew runner must clean up compose resources after any attempted startup, even before server readiness is confirmed',
        );
        $this->assertStringContainsString(
            '"$server_started" == "1" || "$compose_cleanup_needed" == "1"',
            $shell,
            'compose cleanup must not depend only on a successfully started server',
        );
        $this->assertStringNotContainsString(
            'published_release_version',
            $runner,
            'artifact sources must come from actual installation handoff records, not version environment variables alone',
        );
    }

    public function test_skew_runner_does_not_attribute_waterline_render_to_proxy_response(): void
    {
        $runner = $this->read('scripts/conformance/skew-published-artifacts.mjs');

        $this->assertStringContainsString(
            "const artifactOutputAuthoritative = surfaceName === 'waterline'",
            $runner,
            'Waterline render evidence must use the Composer-installed artifact probe output as the response authority',
        );
        $this->assertStringContainsString(
            "&& operationGroup === 'waterline_render'",
            $runner,
        );
        $this->assertStringContainsString(
            'artifactOutputResponse(surfaceName, operationGroup, stdoutJson)',
            $runner,
            'the runner must parse Waterline response and DOM evidence from artifact stdout',
        );
        $this->assertStringContainsString(
            'artifact_did_not_report_waterline_render_response',
            $runner,
            'missing Waterline artifact output must be non-pass instead of falling back to proxy-selected output',
        );
        $this->assertStringContainsString(
            'targetUrl: availability.surfaceUrl',
            $runner,
            'Waterline render probes must send the recording proxy to the running Waterline HTTP surface, not directly to the server',
        );
        $this->assertStringContainsString(
            'targetUrl = null',
            $runner,
            'non-Waterline probes may still default the recording proxy to the published server URL',
        );
        $this->assertStringContainsString(
            'artifact_did_not_contact_surface',
            $runner,
            'missing Waterline surface traffic must not be described as a successful server refusal',
        );
        $this->assertStringContainsString(
            "source: 'published_waterline_artifact'",
            $runner,
            'DOM snapshots must be attributed to the published Waterline artifact output',
        );
        $this->assertStringNotContainsString(
            'const response = selectedCapture?.response ?? {',
            $runner,
            'Waterline render rows must not blindly prefer the recording proxy response',
        );
    }

    public function test_skew_runner_uses_published_php_clients_for_worker_protocol_rows(): void
    {
        $runner = $this->read('scripts/conformance/skew-published-artifacts.mjs');

        $this->assertStringContainsString(
            'new \Workflow\V2\Client\ControlPlaneClient',
            $runner,
            'workflow-worker cluster-info probes must use the Composer-installed control-plane client',
        );
        $this->assertStringContainsString(
            '$controlPlaneVersion',
            $runner,
            'workflow-worker cluster-info probes must send the row control-plane version from the artifact client',
        );
        $this->assertStringContainsString(
            '$client->clusterInfo()',
            $runner,
            'workflow-worker cluster-info probes must execute the package API rather than a hand-written request',
        );
        $this->assertStringContainsString(
            'new \Workflow\V2\Worker\WorkerProtocolClient',
            $runner,
            'worker lifecycle probes must use the Composer-installed worker protocol client',
        );
        $this->assertStringContainsString(
            '$workerProtocolVersion',
            $runner,
            'worker lifecycle probes must send the row worker protocol version from the artifact client',
        );
        $this->assertStringNotContainsString(
            "function skew_worker_body",
            $runner,
            'worker lifecycle probes must not assemble hand-written worker HTTP payloads',
        );
        $this->assertStringNotContainsString(
            "headers['x-durable-workflow-protocol-version'] = workerProtocolVersion",
            $runner,
            'the recording proxy must preserve artifact-sent worker protocol headers instead of manufacturing skew',
        );
        $this->assertStringNotContainsString(
            "headers['x-durable-workflow-control-plane-version'] = controlPlaneVersion",
            $runner,
            'the recording proxy must preserve artifact-sent control-plane headers instead of manufacturing skew',
        );
    }

    public function test_skew_runner_tracks_artifact_returned_ids_for_follow_on_rows(): void
    {
        $runner = $this->read('scripts/conformance/skew-published-artifacts.mjs');

        $this->assertStringContainsString(
            'function firstStringValue(...values)',
            $runner,
            'compatible-cell workflow and schedule follow-on probes must reuse IDs returned by the artifact response',
        );
        $this->assertStringContainsString(
            "firstArrayObjectStringValue(body.runs, ['run_id', 'runId', 'id'])",
            $runner,
            'list-runs responses must be able to seed later run-specific probes',
        );
        foreach ([
            'body.workflow_instance_id',
            'body.result?.workflow_instance_id',
            'body.workflow?.id',
            'body.execution?.workflow_run_id',
            'body.result?.schedule?.id',
            'body.workflowTask?.taskId',
        ] as $responseShape) {
            $this->assertStringContainsString(
                $responseShape,
                $runner,
                sprintf('the skew runner must preserve response ID shape %s for dependent operation evidence', $responseShape),
            );
        }
    }

    public function test_skew_runner_rejects_silent_outside_window_and_failed_waterline_requests(): void
    {
        $runner = $this->read('scripts/conformance/skew-published-artifacts.mjs');

        $this->assertStringContainsString(
            "if (pairingClass === 'outside_window')",
            $runner,
            'outside-window cluster-info rows must not be allowed to pass silently',
        );
        $this->assertStringContainsString(
            "return 'silent_success';",
            $runner,
            'a non-refusing outside-window cluster-info response must be blocking evidence',
        );
        $this->assertStringContainsString(
            'isWaterlineTransportFailure(response)',
            $runner,
            'Waterline 0/5xx/proxy failures must be classified before render_refused can pass a skewed row',
        );
        $this->assertStringContainsString(
            'isWaterlineSurfaceMissing(response)',
            $runner,
            'missing Waterline routes must stay coverage gaps instead of counting as loud render refusals',
        );
        $this->assertStringContainsString(
            'route-missing responses are not valid render_refused evidence',
            $runner,
            'missing Waterline route findings must explain why the row is a coverage gap',
        );
        $this->assertStringContainsString(
            "reason === 'skew_proxy_upstream_error'",
            $runner,
            'proxy upstream failures must stay non-pass for Waterline render evidence',
        );
    }

    public function test_skew_runner_records_only_matched_proxy_wire_evidence(): void
    {
        $runner = $this->read('scripts/conformance/skew-published-artifacts.mjs');

        $this->assertStringContainsString(
            'matched_proxy_capture: matchedCapture',
            $runner,
            'request/response evidence must be anchored to a selected recording-proxy capture',
        );
        $this->assertStringContainsString(
            'wire_evidence_gap',
            $runner,
            'cells without a matched artifact request must be recorded as coverage gaps',
        );
        $this->assertStringContainsString(
            'protocolEvidenceGap(operationGroup, pairing, wireRequest)',
            $runner,
            'matched artifact requests must prove the row protocol version instead of inheriting the runner template',
        );
        $this->assertStringContainsString(
            'request_headers: wireRequest.headers',
            $runner,
            'operation evidence must report artifact-sent headers from the matched proxy request',
        );
        $this->assertStringNotContainsString(
            'request_headers: redactHeaders(headers)',
            $runner,
            'operation evidence must not synthesize skew headers from the runner template',
        );
        $this->assertStringNotContainsString(
            'body: body ?? null,',
            $runner,
            'request-response captures must not synthesize request bodies from the runner template',
        );
    }

    public function test_skew_runner_counts_guarded_preflight_refusals_for_advertised_operations(): void
    {
        $runner = $this->read('scripts/conformance/skew-published-artifacts.mjs');

        $this->assertStringContainsString(
            'artifact_compatibility_refusal',
            $runner,
            'client-side compatibility refusals must be typed so refusal evidence names the skew context',
        );
        $this->assertStringContainsString(
            "const artifactRefusal = pairingClass !== 'compatible'",
            $runner,
            'artifact-side compatibility refusals must be detected even when cluster-info was the advertised operation',
        );
        $this->assertStringContainsString(
            "operationGroup === 'cluster_info_probe' && exactCapture !== null",
            $runner,
            'cluster-info probes that contact the server and then refuse compatibility must be loud refusal evidence, not silent success',
        );
        $this->assertStringContainsString(
            'artifact_refusal_after_advertised_operation',
            $runner,
            'artifact refusals after an advertised cluster-info call must be distinguishable from server response success',
        );
        $this->assertStringContainsString(
            'selectCompatibilityGuardCapture',
            $runner,
            'a refused artifact invocation must attach the guard request/response it actually sent',
        );
        $this->assertStringContainsString(
            "selected_proxy_capture_kind: guardCaptureUsed ? 'guard_refusal_preflight' : 'advertised_operation'",
            $runner,
            'guarded refusals must be distinguishable from exact advertised-operation captures',
        );
        $this->assertStringContainsString(
            'evidence.advertised_request',
            $runner,
            'guarded refusal rows must still name the advertised operation that was invoked',
        );
        $this->assertStringContainsString(
            'evidence.guard_request',
            $runner,
            'guarded refusal rows must expose the real preflight request used as request/response evidence',
        );
    }

    public function test_skew_runner_prepares_independent_fixtures_for_dependent_operations(): void
    {
        $runner = $this->read('scripts/conformance/skew-published-artifacts.mjs');

        $this->assertStringContainsString(
            'prepareOperationFixture',
            $runner,
            'dependent operation rows must not reuse workflow or schedule state mutated by earlier rows',
        );
        $this->assertStringContainsString(
            'prepareWorkflowFixture',
            $runner,
            'workflow describe, signal, cancel, and terminate rows need independent active workflow fixtures',
        );
        $this->assertStringContainsString(
            'prepareScheduleFixture',
            $runner,
            'schedule describe and trigger rows need independent schedule fixtures',
        );
        $this->assertStringContainsString(
            'Compatible fixture setup returned HTTP',
            $runner,
            'fixture setup failures must be explicit coverage evidence instead of silent compatible-cell failures',
        );
    }

    public function test_skewed_operations_require_wire_evidence(): void
    {
        $manifest = SkewRefusalMatrixContract::manifest();

        $workflowRequests = $manifest['operation_groups']['workflow_control_plane']['requests'];
        $this->assertContains('GET /api/workflows/{workflowId}/runs/{runId}/history', $workflowRequests);
        $this->assertContains('POST /api/workflows/{workflowId}/signal/{signalName}', $workflowRequests);
        $this->assertContains('POST /api/workflows/{workflowId}/query/{queryName}', $workflowRequests);
        $this->assertContains('POST /api/workflows/{workflowId}/update/{updateName}', $workflowRequests);
        $this->assertNotContains('GET /api/workflows/{id}/history', $workflowRequests);
        $this->assertNotContains('POST /api/workflows/{id}/signals', $workflowRequests);
        $this->assertNotContains('POST /api/workflows/{id}/queries', $workflowRequests);
        $this->assertNotContains('POST /api/workflows/{id}/updates', $workflowRequests);
        $this->assertContains('request', $manifest['operation_groups']['cluster_info_probe']['evidence']);
        $this->assertContains('status', $manifest['operation_groups']['cluster_info_probe']['evidence']);
        $this->assertContains('request_response_capture_id', $manifest['operation_groups']['cluster_info_probe']['evidence']);

        foreach ([
            'workflow_control_plane',
            'worker_lifecycle',
            'schedule_control_plane',
        ] as $group) {
            foreach ([
                'request_method',
                'request_path',
                'request_headers',
                'request_body',
                'response_status',
                'response_headers',
                'response_body',
                'client_or_worker_version',
                'server_version',
                'compatibility_window',
                'status',
                'request_response_capture_id',
            ] as $field) {
                $this->assertContains($field, $manifest['operation_groups'][$group]['evidence']);
            }
        }

        $this->assertContains(
            'status',
            $manifest['operation_groups']['waterline_render']['evidence'],
        );
        $this->assertContains(
            'waterline_skew_classification',
            $manifest['operation_groups']['waterline_render']['evidence'],
        );
        $this->assertContains(
            'screenshot_or_dom_snapshot',
            $manifest['operation_groups']['waterline_render']['evidence'],
        );
        $this->assertContains(
            'request_response_capture_id',
            $manifest['operation_groups']['waterline_render']['evidence'],
        );
        $this->assertNotContains(
            'classification',
            $manifest['operation_groups']['waterline_render']['evidence'],
        );
    }

    public function test_result_gate_rejects_cluster_info_smoke_as_passing_evidence(): void
    {
        $result = $this->completeSkewResult();
        $result['operation_evidence'] = [];

        foreach (SkewRefusalMatrixContract::manifest()['required_surfaces'] as $surface => $surfaceContract) {
            foreach ($surfaceContract['required_pairing_classes'] as $pairingClass) {
                $result['operation_evidence'][$surface][$pairingClass]['cluster_info_probe'][] = $this->operationEvidence(
                    $surface,
                    $pairingClass,
                    'cluster_info_probe',
                    $pairingClass === 'compatible' ? 'pass' : 'loud_refuse',
                );
            }
        }

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertTrue($evaluation['smoke_subset_detected']);
        $this->assertContains('smoke_only', $evaluation['non_pass_cells']);
        $this->assertContains(
            'declared_outcome_status_mismatch',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_conflicting_outcome_status_and_verdict_aliases(): void
    {
        $result = $this->completeSkewResult();
        $result['outcome'] = 'pass';
        $result['status'] = 'non_passing';
        $result['verdict'] = 'non_passing';

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');
        $conflictFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'conflicting_outcome_tokens',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('declared_outcome_status_mismatch', $failureCodes);
        $this->assertContains('conflicting_outcome_tokens', $failureCodes);
        $this->assertCount(1, $conflictFailures);
        $this->assertSame([
            'outcome' => 'pass',
            'status' => 'non_passing',
            'verdict' => 'non_passing',
        ], $conflictFailures[0]['declared_outcomes']);
        $this->assertSame([
            'outcome' => 'pass',
            'status' => 'non_passing',
            'verdict' => 'non_passing',
        ], $conflictFailures[0]['declared_statuses']);
    }

    public function test_result_gate_rejects_forbidden_artifact_sources_and_source_paths(): void
    {
        $result = $this->completeSkewResult();
        $result['artifact_sources'] = [
            'server' => 'workspace_repo_as_artifact_under_test',
        ];
        $result['operation_evidence']['cli']['compatible']['cluster_info_probe'][0]['source_paths'] = [
            'cli' => 'local_product_source_checkout',
        ];

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);
        $sourceFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'forbidden_artifact_source',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertCount(2, $sourceFailures);
        $this->assertSame(['artifact_sources', 'source_paths'], array_column($sourceFailures, 'field'));
    }

    public function test_result_gate_requires_linked_findings_for_uncovered_matrix_cells(): void
    {
        $result = $this->completeSkewResult();
        unset($result['operation_evidence']['sdk-python']['outside_window']['worker_lifecycle']);
        $result['outcome'] = 'fail';
        $result['finding_links'] = [];

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'missing_operation_evidence',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertContains(
            'missing_linked_findings_for_non_pass_cells',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_focused_findings_for_each_uncovered_matrix_cell(): void
    {
        $result = $this->completeSkewResult();
        unset($result['operation_evidence']['sdk-python']['outside_window']['worker_lifecycle']);
        $result['outcome'] = 'fail';
        $result['finding_links'] = [
            'cli.compatible.cluster_info_probe' => 'https://durable-workflow.github.io/conformance/findings/cli-cluster-info-skew',
        ];

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);
        $focusedFindingFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_focused_findings_for_non_pass_cells',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertCount(1, $focusedFindingFailures);
        $this->assertContains(
            'sdk-python.outside_window.worker_lifecycle',
            $focusedFindingFailures[0]['non_pass_cells'],
        );
    }

    public function test_result_gate_accepts_surface_scoped_findings_for_uncovered_matrix_cells(): void
    {
        $result = $this->completeSkewResult();
        unset($result['operation_evidence']['sdk-python']['outside_window']['worker_lifecycle']);
        $result['outcome'] = 'fail';
        $result['finding_links'] = [
            'sdk-python.outside_window' => 'https://durable-workflow.github.io/conformance/findings/sdk-python-worker-skew',
        ];

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotContains(
            'missing_focused_findings_for_non_pass_cells',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_each_advertised_request_in_an_operation_group(): void
    {
        $result = $this->completeSkewResult();
        array_pop($result['operation_evidence']['cli']['compatible']['schedule_control_plane']);
        $result['outcome'] = 'fail';
        $result['finding_links'] = [
            'cli.compatible.schedule_control_plane' => 'https://durable-workflow.github.io/conformance/findings/cli-schedule-skew',
        ];

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);
        $missingRequestFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_operation_request_evidence',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($missingRequestFailures);
        $this->assertContains(
            'POST /api/schedules/{id}/trigger',
            array_column($missingRequestFailures, 'advertised_request'),
        );
    }

    public function test_result_gate_requires_operation_rows_to_attach_request_response_captures(): void
    {
        $result = $this->completeSkewResult();
        $result['outcome'] = 'fail';
        $missingCaptureId = $result['operation_evidence']['cli']['outside_window']['workflow_control_plane'][0]['request_response_capture_id'];
        unset($result['operation_evidence']['cli']['outside_window']['workflow_control_plane'][0]['request_response_capture_id']);
        $result['operation_evidence']['sdk-python']['outside_window']['schedule_control_plane'][0]['request_response_capture_id'] = 'missing-capture-id';
        $result['finding_links'] = [
            'cli.outside_window.workflow_control_plane' => 'https://durable-workflow.github.io/conformance/findings/cli-workflow-skew',
            'sdk-python.outside_window.schedule_control_plane' => 'https://durable-workflow.github.io/conformance/findings/sdk-python-schedule-skew',
        ];

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);
        $codes = array_column($evaluation['gate_failures'], 'code');
        $missingCaptureFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_request_response_capture',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('missing_request_response_capture_id', $codes);
        $this->assertContains('missing_request_response_capture', $codes);
        $this->assertSame('missing-capture-id', $missingCaptureFailures[0]['request_response_capture_id']);
        $this->assertIsString($missingCaptureId);
    }

    public function test_result_gate_rejects_operation_evidence_for_the_wrong_advertised_request_group(): void
    {
        $result = $this->completeSkewResult();
        $result['operation_evidence']['workflow-worker']['outside_window']['worker_lifecycle'] = [
            $this->operationEvidence(
                'workflow-worker',
                'outside_window',
                'worker_lifecycle',
                'loud_refuse',
                'POST /api/workflows',
            ),
        ];
        $result['outcome'] = 'fail';
        $result['finding_links'] = [
            'workflow-worker.outside_window.worker_lifecycle' => 'https://durable-workflow.github.io/conformance/findings/workflow-worker-skew',
        ];

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);
        $codes = array_column($evaluation['gate_failures'], 'code');
        $unexpectedRequestFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'unexpected_operation_request',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('unexpected_operation_request', $codes);
        $this->assertContains('missing_operation_request_evidence', $codes);
        $this->assertSame('POST /api/workflows', $unexpectedRequestFailures[0]['request']);
        $this->assertContains(
            'POST /api/worker/register',
            $unexpectedRequestFailures[0]['advertised_requests'],
        );
    }

    public function test_result_gate_requires_status_for_cluster_info_operation_evidence(): void
    {
        $result = $this->completeSkewResult();
        unset($result['operation_evidence']['cli']['compatible']['cluster_info_probe'][0]['status']);
        $result['outcome'] = 'fail';
        $result['finding_links'] = [
            'cli.compatible.cluster_info_probe' => 'https://durable-workflow.github.io/conformance/findings/cli-cluster-info-skew',
        ];

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);
        $codes = array_column($evaluation['gate_failures'], 'code');
        $missingFields = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_operation_evidence_field',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('missing_operation_evidence_status', $codes);
        $this->assertContains('status', array_column($missingFields, 'field'));
    }

    public function test_result_gate_rejects_ambiguous_waterline_classification_evidence(): void
    {
        $result = $this->completeSkewResult();
        $row = &$result['operation_evidence']['waterline']['outside_window']['waterline_render'][0];
        unset($row['status'], $row['waterline_skew_classification']);
        $row['classification'] = 'render_refused';
        unset($row);
        $result['outcome'] = 'fail';
        $result['finding_links'] = [
            'waterline.outside_window.waterline_render' => 'https://durable-workflow.github.io/conformance/findings/waterline-skew',
        ];

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);
        $codes = array_column($evaluation['gate_failures'], 'code');
        $missingFields = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_operation_evidence_field',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('missing_operation_evidence_status', $codes);
        $this->assertContains('missing_waterline_skew_classification', $codes);
        $this->assertContains('status', array_column($missingFields, 'field'));
        $this->assertContains('waterline_skew_classification', array_column($missingFields, 'field'));
    }

    public function test_result_gate_routes_not_covered_waterline_render_without_product_classification(): void
    {
        $result = $this->completeSkewResult();
        $result['outcome'] = 'fail';
        $result['pairing_results']['waterline']['outside_window']['status'] = 'not_covered';
        unset($result['pairing_results']['waterline']['outside_window']['waterline_skew_classification']);

        foreach ($result['operation_evidence']['waterline']['outside_window']['waterline_render'] as &$row) {
            $row['status'] = 'not_covered';
            $row['response_status'] = 0;
            $row['response_body'] = [
                'coverage_gap' => true,
                'reason' => 'Waterline published-artifact invoker is not available in this runner.',
            ];
            $row['screenshot_or_dom_snapshot'] = [
                'type' => 'not_covered',
                'reason' => 'Waterline published-artifact invoker is not available in this runner.',
            ];
            $row['coverage_gap_reason'] = 'Waterline published-artifact invoker is not available in this runner.';
            unset($row['waterline_skew_classification']);
        }
        unset($row);

        $result['finding_links'] = [
            'waterline.outside_window' => 'https://durable-workflow.github.io/conformance/findings/waterline-skew-coverage-gap',
        ];

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);
        $codes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('blocking_pairing_status', $codes);
        $this->assertContains('blocking_operation_status', $codes);
        $this->assertNotContains('unexpected_pairing_status', $codes);
        $this->assertNotContains('unexpected_operation_status', $codes);
        $this->assertNotContains('missing_operation_evidence_field', $codes);
        $this->assertNotContains('missing_waterline_skew_classification', $codes);
        $this->assertNotContains('missing_focused_findings_for_non_pass_cells', $codes);
    }

    public function test_result_gate_blocks_register_and_drop_and_stale_render(): void
    {
        $result = $this->completeSkewResult();
        $result['outcome'] = 'fail';
        $result['finding_links'] = [
            'workflow-worker.outside_window' => 'https://durable-workflow.github.io/conformance/findings/workflow-worker-skew',
            'waterline.outside_window' => 'https://durable-workflow.github.io/conformance/findings/waterline-skew',
        ];
        $result['pairing_results']['workflow-worker']['outside_window']['worker_skew_classification'] = 'register_and_drop';
        $result['pairing_results']['waterline']['outside_window']['waterline_skew_classification'] = 'stale_render';

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $codes = array_column($evaluation['gate_failures'], 'code');
        $this->assertContains('blocking_worker_skew_classification', $codes);
        $this->assertContains('blocking_waterline_skew_classification', $codes);
    }

    public function test_result_gate_accepts_complete_passing_matrix(): void
    {
        $evaluation = SkewRefusalMatrixResultGate::evaluate($this->completeSkewResult());

        $this->assertSame('pass', $evaluation['status']);
        $this->assertFalse($evaluation['smoke_subset_detected']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_matches_concrete_paths_to_advertised_request_templates(): void
    {
        $result = $this->completeSkewResult();
        $result['operation_evidence']['cli']['compatible']['schedule_control_plane'][1]['request_path'] = '/api/schedules/nightly-cutover';

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_parses_nested_surface_pairings_without_operation_evidence_leakage(): void
    {
        $result = $this->completeSkewResult();
        foreach ($result['surface_results'] as $surface => $surfaceResult) {
            $result['surface_results'][$surface] = [
                ...$surfaceResult,
                'pairings' => $result['pairing_results'][$surface],
            ];
        }
        $result['pairing_results'] = [
            'format' => ['source' => 'surface_scoped_pairings'],
        ];

        $evaluation = SkewRefusalMatrixResultGate::evaluate($result);

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    /**
     * @return array<string, mixed>
     */
    private function completeSkewResult(): array
    {
        $contract = SkewRefusalMatrixContract::manifest();
        $result = [
            'artifact_versions' => [
                'server' => '0.2.191',
                'cli' => '0.1.67',
                'sdk-python' => '0.4.78',
                'workflow' => '2.0.0-alpha.177',
                'waterline' => '2.0.0-alpha.64',
            ],
            'started_at' => '2026-05-25T05:00:00Z',
            'finished_at' => '2026-05-25T05:10:00Z',
            'outcome' => 'pass',
            'runner_blocked' => false,
            'surface_results' => [],
            'pairing_results' => [],
            'operation_evidence' => [],
            'request_response_captures' => [],
            'findings' => [],
            'finding_links' => [],
        ];

        foreach ($contract['required_surfaces'] as $surface => $surfaceContract) {
            $result['surface_results'][$surface] = ['status' => 'pass'];

            foreach ($surfaceContract['required_pairing_classes'] as $pairingClass) {
                $status = $pairingClass === 'compatible' ? 'pass' : 'loud_refuse';
                $result['pairing_results'][$surface][$pairingClass] = $this->pairingResult(
                    $surface,
                    $pairingClass,
                    $status,
                );

                foreach ($surfaceContract['operation_groups'] as $operationGroup) {
                    foreach ($contract['operation_groups'][$operationGroup]['requests'] as $request) {
                        $evidence = $this->operationEvidence(
                            $surface,
                            $pairingClass,
                            $operationGroup,
                            $status,
                            $request,
                        );
                        $result['operation_evidence'][$surface][$pairingClass][$operationGroup][] = $evidence;
                        $result['request_response_captures'][] = $this->requestResponseCapture($evidence);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function pairingResult(string $surface, string $pairingClass, string $status): array
    {
        $result = [
            'status' => $status,
        ];

        if ($status === 'loud_refuse') {
            $manifest = SkewRefusalMatrixContract::manifest();
            $result['refusal_requirements_met'] = $manifest['required_surfaces'][$surface]['refusal_requirements'];
        }

        if ($surface === 'workflow-worker') {
            $result['worker_skew_classification'] = $pairingClass === 'compatible'
                ? 'register_and_serve'
                : 'register_refused';
        }

        if ($surface === 'waterline') {
            $result['waterline_skew_classification'] = $pairingClass === 'compatible'
                ? 'banner'
                : 'render_refused';
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function operationEvidence(
        string $surface,
        string $pairingClass,
        string $operationGroup,
        string $status,
        ?string $request = null,
    ): array {
        $request ??= SkewRefusalMatrixContract::manifest()['operation_groups'][$operationGroup]['requests'][0];
        [$method, $path] = explode(' ', $request, 2);
        $compatibilityWindow = '>=0.2,<1.0';
        $nextStep = $pairingClass === 'compatible'
            ? 'No compatibility remediation is required for this inside-window pair. If the '.$surface.' command returns a domain error, use the captured response reason as the next operational step.'
            : 'Upgrade the older side, pin the client to the advertised range, or connect to a server that supports the requested protocol.';

        $row = match ($operationGroup) {
            'cluster_info_probe' => [
                'request' => $request,
                'status_code' => 200,
                'response_body' => ['server_version' => '0.2.191'],
                'client_or_observer_version' => '0.1.67',
                'server_version' => '0.2.191',
                'protocol_manifest_versions' => ['control_plane' => '2'],
                'compatibility_window' => $compatibilityWindow,
                'next_step' => $nextStep,
            ],
            'waterline_render' => [
                'request' => $request,
                'response_status' => 200,
                'response_body' => ['ok' => true],
                'screenshot_or_dom_snapshot' => '<main data-compatibility-banner="visible"></main>',
                'server_version' => '0.2.191',
                'waterline_version' => '2.0.0-alpha.64',
                'compatibility_window' => $compatibilityWindow,
                'next_step' => $nextStep,
            ],
            default => [
                'request_method' => $method,
                'request_path' => $path,
                'request_headers' => ['X-Durable-Workflow-Control-Plane-Version' => '2'],
                'request_body' => ['workflow_type' => 'Conformance'],
                'response_status' => 200,
                'response_headers' => ['X-Durable-Workflow-Control-Plane-Version' => '2'],
                'response_body' => ['outcome' => 'accepted'],
                'client_or_worker_version' => $surface === 'sdk-python' ? '0.4.78' : '0.1.67',
                'server_version' => '0.2.191',
                'compatibility_window' => $compatibilityWindow,
                'next_step' => $nextStep,
            ],
        };

        $row['status'] = $status;
        $row['request_response_capture_id'] = 'capture-'.md5($surface.'|'.$pairingClass.'|'.$operationGroup.'|'.$request);

        if ($status === 'loud_refuse') {
            $row['refusal_requirements_met'] = SkewRefusalMatrixContract::manifest()['required_surfaces'][$surface]['refusal_requirements'];
        }

        if ($surface === 'workflow-worker') {
            $row['worker_skew_classification'] = $pairingClass === 'compatible'
                ? 'register_and_serve'
                : 'register_refused';
        }

        if ($surface === 'waterline') {
            $row['waterline_skew_classification'] = $pairingClass === 'compatible'
                ? 'banner'
                : 'render_refused';
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function requestResponseCapture(array $evidence): array
    {
        $request = $evidence['request'] ?? null;
        if (! is_string($request)) {
            $request = trim((string) ($evidence['request_method'] ?? 'GET')).' '.trim((string) ($evidence['request_path'] ?? '/'));
        }

        return [
            'id' => $evidence['request_response_capture_id'],
            'request' => $request,
            'response' => [
                'status' => $evidence['response_status'] ?? $evidence['status_code'] ?? 200,
                'body' => $evidence['response_body'] ?? [],
            ],
        ];
    }

    private function read(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        $this->assertNotFalse($source, "{$path} must be readable");

        return $source;
    }
}
