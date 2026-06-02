<?php

namespace Tests\Unit;

use App\Support\PrincipalAttributionContract;
use App\Support\PrincipalAttributionResultGate;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;

class PrincipalAttributionContractTest extends TestCase
{
    public function test_manifest_names_published_artifact_runner_handoff(): void
    {
        $manifest = PrincipalAttributionContract::manifest();

        $this->assertSame('durable-workflow.v2.principal-attribution.contract', $manifest['schema']);
        $this->assertSame(1, PrincipalAttributionContract::VERSION);
        $this->assertSame(PrincipalAttributionContract::VERSION, $manifest['version']);
        $this->assertSame(
            'durable-workflow.v2.principal-attribution-conformance.result',
            $manifest['result_schema'],
        );
        $this->assertSame('principal_attribution_contract', $manifest['fixture_category']);
        $this->assertSame(
            PlatformConformanceSuite::SCHEMA,
            $manifest['platform_conformance_suite_authority'],
        );
        $this->assertSame(
            PlatformConformanceSuite::VERSION,
            $manifest['scenario_manifest']['suite_version'],
        );
        $this->assertSame(
            'scripts/conformance/principal-attribution-published-artifacts.sh',
            $manifest['host_runner_contract']['runner_path'],
        );
        $this->assertTrue($manifest['host_runner_contract']['must_execute_against_published_artifacts']);
        $this->assertTrue($manifest['host_runner_contract']['must_record_runner_blocked_false_for_product_evidence']);
        $this->assertTrue($manifest['host_runner_contract']['must_attempt_spoofing_payloads_and_headers']);
        $this->assertContains(
            'runner_blocked_false_for_product_evidence',
            $manifest['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertSame(
            ['WorkflowCompleted', 'WorkflowFailed'],
            $manifest['worker_terminal_event_policy']['events'],
        );
        $this->assertSame(
            ['type' => 'auth:token', 'id' => 'worker:principal-attribution'],
            $manifest['worker_terminal_event_policy']['expected_authenticated_worker_principal'],
        );
        $this->assertSame([], $manifest['worker_terminal_event_policy']['documented_system_principals']);

        foreach (['server', 'cli', 'workflow-php', 'sdk-python', 'waterline'] as $artifact) {
            $this->assertArrayHasKey($artifact, $manifest['artifact_policy']['install_channels']);
        }
    }

    public function test_manifest_names_required_audit_scenarios(): void
    {
        $manifest = PrincipalAttributionContract::manifest();

        foreach ([
            'published_artifact_install_only',
            'named_token_actor_matrix',
            'start_signal_cancel_spoofing',
            'query_attribution',
            'completion_failure_attribution',
            'server_originated_events',
            'anonymous_attribution',
            'python_sdk_visibility',
            'php_client_visibility',
            'cli_operator_visibility',
            'waterline_operator_visibility',
        ] as $scenario) {
            $this->assertContains($scenario, $manifest['required_scenarios']);
            $this->assertArrayHasKey($scenario, $manifest['scenario_requirements']);
        }

        $this->assertSame(
            $manifest['required_scenarios'],
            array_keys($manifest['scenario_requirements']),
            'every required principal-attribution scenario must declare evidence fields',
        );

        $this->assertContains(
            'expected_worker_principal',
            $manifest['scenario_requirements']['completion_failure_attribution']['required_fields'],
        );
        $this->assertContains(
            'documented_system_principals',
            $manifest['scenario_requirements']['completion_failure_attribution']['required_fields'],
        );
    }

    public function test_manifest_publishes_enforceable_principal_attribution_result_gate(): void
    {
        $manifest = PrincipalAttributionContract::manifest();

        $this->assertSame(PrincipalAttributionResultGate::SCHEMA, $manifest['result_gate']['schema']);
        $this->assertSame(
            PrincipalAttributionContract::RESULT_SCHEMA,
            $manifest['result_gate']['evaluates_result_schema'],
        );
        $this->assertContains(
            'each_non_pass_scenario_has_focused_linked_findings',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'omitted_required_scenarios_link_focused_findings',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'resolved_artifact_versions_are_recorded_and_pinned',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'published_artifact_install_sources_are_complete',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'published_artifact_install_local_product_source_checkouts_used_false',
            $manifest['result_gate']['pass_requires'],
        );
    }

    public function test_result_gate_rejects_role_token_smoke_subset_as_complete_evidence(): void
    {
        $result = $this->principalAttributionResult([
            'outcome' => 'pass',
            'scenario_results' => [
                'start_signal_cancel_spoofing' => $this->scenario(
                    'start_signal_cancel_spoofing',
                    'pass',
                    $this->scenarioEvidence('start_signal_cancel_spoofing'),
                ),
            ],
        ]);

        $evaluation = PrincipalAttributionResultGate::evaluate($result);
        $codes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('missing_required_scenario', $codes);
        $this->assertContains('declared_pass_with_non_passing_evidence', $codes);
        $this->assertContains('smoke_subset_cannot_pass', $codes);
        $this->assertContains('named_token_actor_matrix', $evaluation['missing_scenarios']);
        $this->assertContains('query_attribution', $evaluation['missing_scenarios']);
    }

    public function test_result_gate_requires_focused_findings_for_non_pass_principal_cells(): void
    {
        $result = $this->principalAttributionResult([
            'outcome' => 'fail',
            'scenario_results' => [
                ...$this->passingScenarioResults(),
                'waterline_operator_visibility' => $this->scenario(
                    'waterline_operator_visibility',
                    'unsupported',
                    $this->scenarioEvidence('waterline_operator_visibility'),
                ),
            ],
        ]);

        $evaluation = PrincipalAttributionResultGate::evaluate($result);
        $focusedFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_focused_linked_finding',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertSame(['waterline_operator_visibility'], array_column($focusedFailures, 'scenario_id'));
    }

    public function test_result_gate_accepts_complete_non_passing_evidence_when_uncovered_cell_is_routed(): void
    {
        $finding = $this->focusedFinding('waterline_operator_visibility', 'waterline');
        $result = $this->principalAttributionResult([
            'outcome' => 'fail',
            'scenario_results' => [
                ...$this->passingScenarioResults(),
                'waterline_operator_visibility' => $this->scenario(
                    'waterline_operator_visibility',
                    'unsupported',
                    [
                        ...$this->scenarioEvidence('waterline_operator_visibility'),
                        'linked_findings' => [$finding],
                    ],
                ),
            ],
            'findings' => [$finding],
        ]);

        $evaluation = PrincipalAttributionResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertSame(['waterline_operator_visibility'], $evaluation['non_pass_scenarios']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_accepts_complete_passing_principal_attribution_matrix(): void
    {
        $evaluation = PrincipalAttributionResultGate::evaluate($this->principalAttributionResult());

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['missing_scenarios']);
        $this->assertSame([], $evaluation['non_pass_scenarios']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_rejects_complete_pass_with_non_passing_declared_outcome_tokens(): void
    {
        foreach ([
            ['outcome', 'fail'],
            ['outcome', 'non_passing'],
            ['status', 'fail'],
            ['status', 'non_passing'],
            ['verdict', 'fail'],
            ['verdict', 'non_passing'],
        ] as [$field, $outcome]) {
            $result = $this->principalAttributionResult([$field => $outcome]);

            $evaluation = PrincipalAttributionResultGate::evaluate($result);
            $failureCodes = array_column($evaluation['gate_failures'], 'code');
            $mismatchFailures = array_values(array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['code'] ?? null) === 'declared_outcome_status_mismatch',
            ));

            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertContains('declared_outcome_status_mismatch', $failureCodes);
            $this->assertContains(
                [
                    'code' => 'declared_outcome_status_mismatch',
                    'field' => $field,
                    'outcome' => $outcome,
                    'declared_status' => 'non_passing',
                    'evaluated_status' => 'pass',
                ],
                $mismatchFailures,
            );
        }
    }

    public function test_result_gate_rejects_runner_blocked_complete_matrix_as_passing_evidence(): void
    {
        foreach (['runner_blocked', 'runnerBlocked'] as $field) {
            $result = $this->principalAttributionResult();
            unset($result['runner_blocked']);
            $result[$field] = true;

            $evaluation = PrincipalAttributionResultGate::evaluate($result);

            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertContains(
                'runner_blocked_result_is_not_product_evidence',
                array_column($evaluation['gate_failures'], 'code'),
            );
        }
    }

    public function test_result_gate_requires_separate_published_and_resolved_artifact_version_fields(): void
    {
        $result = $this->principalAttributionResult([
            'artifact_versions' => $this->artifactVersions(),
        ]);
        unset($result['resolved_artifact_versions']);

        $evaluation = PrincipalAttributionResultGate::evaluate($result);
        $missingRunRecordFields = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_run_record_field',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            [
                'code' => 'missing_run_record_field',
                'field' => 'resolved_artifact_versions',
            ],
            $missingRunRecordFields,
        );

        $result = $this->principalAttributionResult([
            'artifact_versions' => $this->artifactVersions(),
        ]);
        unset($result['published_artifact_versions']);

        $evaluation = PrincipalAttributionResultGate::evaluate($result);
        $missingRunRecordFields = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_run_record_field',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            [
                'code' => 'missing_run_record_field',
                'field' => 'published_artifact_versions',
            ],
            $missingRunRecordFields,
        );
    }

    public function test_result_gate_rejects_published_install_scenario_local_source_policy_violations(): void
    {
        $result = $this->principalAttributionResult();
        $result['scenario_results']['published_artifact_install_only']['local_product_source_checkouts_used'] = true;
        $result['scenario_results']['published_artifact_install_only']['artifact_sources']['server'] =
            'workspace_repo_as_artifact_under_test';

        $evaluation = PrincipalAttributionResultGate::evaluate($result);
        $localSourceFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'local_product_source_checkout_used',
        ));
        $forbiddenSourceFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'forbidden_artifact_source',
        ));
        $explicitFalseFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'local_product_source_checkouts_used_must_be_false',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            [
                'code' => 'local_product_source_checkout_used',
                'field' => 'local_product_source_checkouts_used',
                'scenario_id' => 'published_artifact_install_only',
            ],
            $localSourceFailures,
        );
        $this->assertContains(
            [
                'code' => 'forbidden_artifact_source',
                'artifact' => 'server',
                'source' => 'workspace_repo_as_artifact_under_test',
                'field' => 'artifact_sources',
                'scenario_id' => 'published_artifact_install_only',
            ],
            $forbiddenSourceFailures,
        );
        $this->assertContains(
            [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'scenario_id' => 'published_artifact_install_only',
                'field' => 'local_product_source_checkouts_used',
                'value' => true,
            ],
            $explicitFalseFailures,
        );
    }

    public function test_result_gate_requires_complete_published_install_scenario_sources_and_versions(): void
    {
        $result = $this->principalAttributionResult();
        $result['scenario_results']['published_artifact_install_only']['artifact_sources'] = [
            'server' => 'docker-image',
        ];
        $result['scenario_results']['published_artifact_install_only']['resolved_artifact_versions'] = [
            'server' => '0.2.228',
        ];

        $evaluation = PrincipalAttributionResultGate::evaluate($result);
        $missingSourceFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_published_artifact_install_source',
        ));
        $missingVersionFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_published_artifact_install_version',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        foreach (['cli', 'workflow-php', 'sdk-python', 'waterline'] as $artifact) {
            $this->assertContains(
                [
                    'code' => 'missing_published_artifact_install_source',
                    'scenario_id' => 'published_artifact_install_only',
                    'artifact' => $artifact,
                ],
                $missingSourceFailures,
            );
            $this->assertContains(
                [
                    'code' => 'missing_published_artifact_install_version',
                    'scenario_id' => 'published_artifact_install_only',
                    'field' => 'resolved_artifact_versions',
                    'artifact' => $artifact,
                ],
                $missingVersionFailures,
            );
        }
    }

    public function test_scenario_manifest_source_path_matches_contract(): void
    {
        $manifest = PrincipalAttributionContract::manifest();
        $scenarioManifestPath = dirname(__DIR__, 2).'/'.$manifest['scenario_manifest']['source_path'];

        $this->assertFileExists($scenarioManifestPath);

        $scenarioManifest = json_decode(
            (string) file_get_contents($scenarioManifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame($manifest['scenario_manifest']['schema'], $scenarioManifest['schema']);
        $this->assertSame($manifest['scenario_manifest']['category'], $scenarioManifest['category']);
        $this->assertSame($manifest['scenario_manifest']['suite_schema'], $scenarioManifest['suite_schema']);
        $this->assertSame($manifest['scenario_manifest']['suite_version'], $scenarioManifest['suite_version']);
        $this->assertSame($manifest['scenario_statuses'], $scenarioManifest['result_statuses']);
        $this->assertSame($manifest['required_scenarios'], array_column($scenarioManifest['scenarios'], 'id'));
        $this->assertSame(
            array_keys($manifest['scenario_requirements']),
            array_keys($scenarioManifest['scenario_requirements']),
        );
        $this->assertSame(
            $manifest['worker_terminal_event_policy'],
            $scenarioManifest['worker_terminal_event_policy'],
        );
        $this->assertSame(
            $manifest['spoofing_guards'],
            $scenarioManifest['spoofing_guards'],
        );
    }

    public function test_published_artifact_runner_fails_closed_for_required_evidence(): void
    {
        $manifest = PrincipalAttributionContract::manifest();
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/'.$manifest['host_runner_contract']['runner_path']);

        $this->assertStringContainsString('artifact-install-evidence.json', $script);
        $this->assertStringContainsString('install_status_and_findings', $script);
        $this->assertStringContainsString('scenario(install_status, "published_artifact_install_only"', $script);
        $this->assertStringNotContainsString('scenario("pass", "published_artifact_install_only"', $script);

        $this->assertStringContainsString('recorded_query_principal = principal_from_query_observation(query_observation)', $script);
        $this->assertStringContainsString('principal_id(recorded_query_principal) != "bob"', $script);
        $this->assertStringContainsString('recorded_principal=recorded_query_principal', $script);
        $this->assertStringContainsString('["command_context", "context", "principal"]', $script);
        $this->assertStringNotContainsString('recorded_principal=None', $script);
        $this->assertStringContainsString('DW_TRUST_FORWARDED_ATTRIBUTION_HEADERS: "true"', $script);
        $this->assertStringContainsString('ADVERSARIAL_BODY_FIELDS', $script);
        $this->assertStringContainsString('ADVERSARIAL_HEADERS', $script);
        $this->assertStringContainsString('"X-Workflow-Caller-Type": "spoofed-gateway"', $script);
        $this->assertStringContainsString('"X-Workflow-Auth-Method": "gateway_token"', $script);
        $this->assertStringContainsString('"X-Remote-User": "mallory"', $script);
        $this->assertStringContainsString('main_linked_findings: list[dict[str, Any]] = []', $script);
        $this->assertStringContainsString('linked_findings=main_linked_findings', $script);
        $this->assertStringContainsString('start/signal/cancel attribution failures', $script);
        $this->assertStringContainsString('ANONYMOUS_SERVER_URL="$anonymous_server_base_url"', $script);
        $this->assertStringContainsString('anonymous_auth_driver": "none"', $script);
        $this->assertStringContainsString('run_python_sdk_client_operation', $script);
        $this->assertStringContainsString('python_operation = run_python_sdk_client_operation(python_client_id)', $script);
        $this->assertStringContainsString('run_php_client_operation', $script);
        $this->assertStringContainsString('php_operation = run_php_client_operation(php_client_id)', $script);
        $this->assertStringNotContainsString('Python SDK client operation was not exercised by this runner revision', $script);
        $this->assertStringNotContainsString('PHP client operation was not exercised by this runner revision', $script);
        $this->assertStringContainsString('waterline:principal-attribution-conformance', $script);
        $this->assertStringContainsString('WATERLINE_PRINCIPAL_RESULT="$waterline_result_path"', $script);
        $this->assertStringContainsString('load_waterline_principal_shard', $script);
        $this->assertStringContainsString('waterline_status = waterline_item.get("status") if isinstance(waterline_item, dict) else "unsupported"', $script);
        $this->assertStringContainsString('waterline_output_sample_missing = True', $script);
        $this->assertStringContainsString('if isinstance(waterline_item, dict) and "output_sample" in waterline_item:', $script);
        $this->assertStringContainsString('raw_output_sample.strip() == ""', $script);
        $this->assertStringContainsString('waterline_claimed_pass = waterline_status == "pass"', $script);
        $this->assertStringContainsString('waterline_missing_required_pass_evidence = False', $script);
        $this->assertStringContainsString('waterline_claimed_pass and waterline_principal_visible is not True', $script);
        $this->assertStringContainsString('waterline_claimed_pass and waterline_output_sample_missing', $script);
        $this->assertStringContainsString('if waterline_missing_required_pass_evidence:', $script);
        $this->assertStringContainsString('scenario_results.append(scenario(', $script);
        $this->assertStringContainsString('"waterline": {"status": waterline_status', $script);
        $this->assertStringNotContainsString('Waterline operator surface was not exercised by this runner revision', $script);
        $this->assertStringNotContainsString('waterline_output_sample = json.dumps(waterline_payload', $script);
        $this->assertStringNotContainsString('waterline_principal_visible = True', $script);

        $this->assertSame(
            1,
            preg_match(
                '/\[\s*str\(DW_BIN\),\s*"workflow:history",(?P<command>.*?)\],\s*check=False,/s',
                $script,
                $cliHistoryCommandMatch,
            ),
            'principal-attribution runner must invoke dw workflow:history through the CLI command array',
        );
        $cliHistoryCommand = $cliHistoryCommandMatch['command'];
        $this->assertStringContainsString('"--output=json"', $cliHistoryCommand);
        $this->assertStringContainsString('--output=json', $script);
        $this->assertStringNotContainsString('"--json"', $cliHistoryCommand);

        $this->assertStringContainsString('expected_worker_principal = {"id": "worker:principal-attribution", "type": "auth:token"}', $script);
        $this->assertStringContainsString('documented_system_principals: list[dict[str, str]] = []', $script);
        $this->assertStringContainsString('principal_matches(completion_event_principal, expected_worker_principal)', $script);
        $this->assertStringContainsString('principal_matches(failure_event_principal, expected_worker_principal)', $script);
        $this->assertStringContainsString('worker_principal=expected_worker_principal', $script);
        $this->assertStringContainsString('documented_system_principals=documented_system_principals', $script);
        $this->assertStringContainsString('"claims":{}', $script);
        $this->assertStringContainsString('COMPLETE_TASK_QUEUE = f"{TASK_QUEUE_BASE}-complete"', $script);
        $this->assertStringContainsString('FAIL_TASK_QUEUE = f"{TASK_QUEUE_BASE}-fail"', $script);
        $this->assertStringContainsString('register_worker(COMPLETE_WORKER_ID, COMPLETE_TASK_QUEUE)', $script);
        $this->assertStringContainsString('poll_workflow_task(COMPLETE_WORKER_ID, COMPLETE_TASK_QUEUE, expected_workflow_id=complete_id)', $script);
        $this->assertStringContainsString('poll_workflow_task(FAIL_WORKER_ID, FAIL_TASK_QUEUE, expected_workflow_id=fail_id)', $script);
        $this->assertStringNotContainsString(
            'completion_failure_status = "pass" if isinstance(completion_event_principal, dict) and isinstance(failure_event_principal, dict) else "fail"',
            $script,
        );
        $this->assertStringContainsString('"findings": findings,', $script);
        $this->assertStringNotContainsString('"findings": [item["observed_behavior"] for item in findings]', $script);
    }

    public function test_result_gate_requires_true_waterline_principal_visibility_for_pass(): void
    {
        foreach ([false, null] as $principalVisible) {
            $result = $this->completePrincipalAttributionResult();
            if ($principalVisible === null) {
                unset($result['scenario_results']['waterline_operator_visibility']['principal_visible']);
            } else {
                $result['scenario_results']['waterline_operator_visibility']['principal_visible'] = $principalVisible;
            }

            $evaluation = PrincipalAttributionResultGate::evaluate($result);

            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertContains(
                'waterline_principal_visibility_not_true',
                array_column($evaluation['gate_failures'], 'code'),
            );
        }
    }

    public function test_result_gate_requires_non_empty_waterline_operator_output_sample_for_pass(): void
    {
        foreach (['', '   ', []] as $outputSample) {
            $result = $this->completePrincipalAttributionResult();
            $result['scenario_results']['waterline_operator_visibility']['output_sample'] = $outputSample;

            $evaluation = PrincipalAttributionResultGate::evaluate($result);

            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertContains(
                'missing_waterline_operator_output_sample',
                array_column($evaluation['gate_failures'], 'code'),
            );
        }
    }

    public function test_published_artifact_runner_reports_current_suite_version_from_scenario_manifest(): void
    {
        $manifest = PrincipalAttributionContract::manifest();
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/'.$manifest['host_runner_contract']['runner_path']);

        $this->assertStringContainsString('principal_scenario_manifest=', $script);
        $this->assertStringContainsString('principal_suite_version="$(read_principal_suite_version)"', $script);
        $this->assertStringContainsString('"suite_version": $principal_suite_version', $script);
        $this->assertStringContainsString('"suite_version": SUITE_VERSION', $script);
        $this->assertStringContainsString('PRINCIPAL_ATTRIBUTION_SUITE_VERSION="$principal_suite_version"', $script);
        $this->assertStringNotContainsString('"suite_version": 12', $script);
    }

    public function test_published_artifact_runner_blocked_result_preserves_required_result_shape(): void
    {
        if (! is_file('/bin/bash')) {
            $this->markTestSkipped('bash is required to exercise the conformance runner handoff.');
        }

        $manifest = PrincipalAttributionContract::manifest();
        $repoRoot = dirname(__DIR__, 2);
        $scriptPath = $repoRoot.'/'.$manifest['host_runner_contract']['runner_path'];
        $scenarioManifestPath = $repoRoot.'/'.$manifest['scenario_manifest']['source_path'];
        $scenarioManifest = json_decode(
            (string) file_get_contents($scenarioManifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $tempRoot = sys_get_temp_dir().'/dw-principal-attribution-blocked-'.bin2hex(random_bytes(6));
        $binDir = $tempRoot.'/bin';
        $resultDir = $tempRoot.'/result';
        $runRoot = $tempRoot.'/run';

        try {
            mkdir($binDir, 0777, true);
            mkdir($resultDir, 0777, true);

            foreach (['basename', 'cat', 'date', 'dirname', 'head', 'mkdir', 'pwd', 'sed', 'tr'] as $command) {
                $this->linkSystemCommand($binDir, $command);
            }

            $process = proc_open(
                ['/bin/bash', $scriptPath, '--result-dir', $resultDir],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                [
                    'PATH' => $binDir,
                    'DW_PRINCIPAL_ATTRIBUTION_RUN_ROOT' => $runRoot,
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(1, $exitCode, $stdout.$stderr);

            $resultPath = $resultDir.'/principal-attribution-result.json';
            $this->assertFileExists($resultPath);

            $result = json_decode(
                (string) file_get_contents($resultPath),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('error', $result['outcome']);
            $this->assertTrue($result['runner_blocked']);

            foreach ($scenarioManifest['required_top_level_fields'] as $requiredField) {
                $this->assertArrayHasKey($requiredField, $result);
            }

            $scenarioResults = array_column($result['scenario_results'], null, 'scenario_id');
            $topLevelFindings = array_column($result['findings'], null, 'scenario_id');
            foreach ($scenarioManifest['scenario_requirements'] as $scenarioId => $requirements) {
                $this->assertArrayHasKey($scenarioId, $scenarioResults);
                $this->assertSame('runner_blocked', $scenarioResults[$scenarioId]['status']);
                $this->assertArrayHasKey($scenarioId, $topLevelFindings);
                $this->assertFocusedPrincipalFinding($scenarioId, $topLevelFindings[$scenarioId]);

                foreach ($requirements['required_fields'] as $requiredField) {
                    $this->assertArrayHasKey($requiredField, $scenarioResults[$scenarioId]);
                }

                foreach (['linked_findings', 'findings'] as $findingField) {
                    $this->assertArrayHasKey($findingField, $scenarioResults[$scenarioId]);
                    $this->assertIsArray($scenarioResults[$scenarioId][$findingField]);
                    $this->assertNotEmpty($scenarioResults[$scenarioId][$findingField]);
                    $this->assertFocusedPrincipalFinding(
                        $scenarioId,
                        $scenarioResults[$scenarioId][$findingField][0],
                    );
                }
            }

            $this->assertFalse($scenarioResults['published_artifact_install_only']['local_product_source_checkouts_used']);
            $this->assertSame([], $result['history_dumps']);
            $this->assertSame([], $result['spoofing_attempts']['payload_values']);
            $this->assertFalse($result['spoofing_attempts']['executed']);
            $this->assertSame('runner_blocked', $result['anonymous_observations']['status']);

            $evaluation = PrincipalAttributionResultGate::evaluate($result);
            $failureCodes = array_column($evaluation['gate_failures'], 'code');
            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertNotContains('missing_focused_linked_finding', $failureCodes);
            $this->assertContains('runner_blocked_result_is_not_product_evidence', $failureCodes);

            $recordPath = $resultDir.'/principal-attribution-record.json';
            $this->assertFileExists($recordPath);
            $record = json_decode(
                (string) file_get_contents($recordPath),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $this->assertTrue($record['runnerBlocked']);
            $recordFindings = array_column($record['findings'], null, 'scenario_id');
            foreach (array_keys($scenarioManifest['scenario_requirements']) as $scenarioId) {
                $this->assertArrayHasKey($scenarioId, $recordFindings);
                $this->assertFocusedPrincipalFinding($scenarioId, $recordFindings[$scenarioId]);
            }
        } finally {
            $this->removeTree($tempRoot);
        }
    }

    public function test_published_artifact_runner_non_pass_findings_include_versioned_routing_fields(): void
    {
        $manifest = PrincipalAttributionContract::manifest();
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/'.$manifest['host_runner_contract']['runner_path']);

        $this->assertStringContainsString(
            'def current_artifact_versions()',
            $script,
            'principal-attribution findings must resolve the published artifact tuple under test',
        );
        $this->assertStringContainsString(
            '"scenario_id": scenario_id',
            $script,
            'principal-attribution findings must preserve the scenario identity',
        );
        $this->assertStringContainsString(
            '"owning_surface": surface',
            $script,
            'principal-attribution findings must route to the owning public surface',
        );
        $this->assertStringContainsString(
            '"artifact_versions": current_artifact_versions()',
            $script,
            'principal-attribution findings must carry the published artifact tuple under test',
        );
        $this->assertStringContainsString(
            '"observed_behavior": observed',
            $script,
            'principal-attribution findings must describe the observed behavior',
        );
        $this->assertStringContainsString(
            '"next_acceptance_criterion": next_acceptance',
            $script,
            'principal-attribution findings must name the next criterion for turning the scenario green',
        );
    }

    public function test_result_gate_accepts_complete_passing_matrix(): void
    {
        $evaluation = PrincipalAttributionResultGate::evaluate($this->completePrincipalAttributionResult());

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['gate_failures']);
        $this->assertFalse($evaluation['smoke_subset_detected']);
    }

    public function test_result_gate_rejects_role_token_smoke_subset_even_when_declared_pass(): void
    {
        $result = $this->completePrincipalAttributionResult();
        $result['scenario_results'] = [
            'published_artifact_install_only' => $result['scenario_results']['published_artifact_install_only'],
            'start_signal_cancel_spoofing' => $result['scenario_results']['start_signal_cancel_spoofing'],
            'cli_operator_visibility' => $result['scenario_results']['cli_operator_visibility'],
        ];

        $evaluation = PrincipalAttributionResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertTrue($evaluation['smoke_subset_detected']);
        $this->assertContains('query_attribution', $evaluation['missing_scenarios']);
        $this->assertContains('python_sdk_visibility', $evaluation['missing_scenarios']);
        $this->assertContains('waterline_operator_visibility', $evaluation['missing_scenarios']);
        $this->assertContains(
            'smoke_subset_cannot_pass',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertContains(
            'declared_pass_with_non_passing_evidence',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_structured_findings_for_non_pass_scenarios(): void
    {
        $result = $this->completePrincipalAttributionResult();
        $result['outcome'] = 'fail';
        $result['scenario_results']['waterline_operator_visibility'] = [
            'status' => 'unsupported',
            'findings' => [
                'Waterline operator surface was not exercised.',
            ],
        ];
        $result['findings'] = [
            'Waterline operator surface was not exercised.',
        ];

        $evaluation = PrincipalAttributionResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('waterline_operator_visibility', $evaluation['non_pass_scenarios']);
        $this->assertContains(
            'missing_focused_linked_finding',
            array_column($evaluation['gate_failures'], 'code'),
        );

        $finding = $this->structuredPrincipalFinding(
            'waterline_operator_visibility',
            'Waterline operator surface was not exercised by this runner revision.',
            'waterline',
            'Waterline selected-run history exposes event principal.',
            'Extend the host topology to boot Waterline against the published server and capture selected-run history.',
        );
        $result['scenario_results']['waterline_operator_visibility']['findings'] = [$finding];
        $result['scenario_results']['waterline_operator_visibility']['linked_findings'] = [$finding];
        $result['findings'] = [$finding];

        $evaluation = PrincipalAttributionResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('waterline_operator_visibility', $evaluation['non_pass_scenarios']);
        $this->assertNotContains(
            'missing_focused_linked_finding',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_bare_string_links_for_non_pass_scenarios(): void
    {
        $result = $this->completePrincipalAttributionResult();
        $result['outcome'] = 'fail';
        $result['scenario_results']['waterline_operator_visibility'] = [
            'scenario_id' => 'waterline_operator_visibility',
            'status' => 'unsupported',
            'linked_findings' => ['waterline-operator-gap'],
        ];
        $result['finding_links'] = [
            'waterline_operator_visibility' => ['waterline-operator-gap'],
        ];
        $result['findings'] = ['waterline-operator-gap'];

        $evaluation = PrincipalAttributionResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('waterline_operator_visibility', $evaluation['non_pass_scenarios']);
        $this->assertContains(
            'missing_focused_linked_finding',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_generic_structured_findings_without_matching_scenario_id(): void
    {
        foreach ([
            null,
            'cli_operator_visibility',
        ] as $linkedScenarioId) {
            $finding = $this->structuredPrincipalFinding(
                'waterline_operator_visibility',
                'Waterline operator surface was not exercised by this runner revision.',
                'waterline',
                'Waterline selected-run history exposes event principal.',
                'Extend the host topology to boot Waterline against the published server and capture selected-run history.',
            );

            if ($linkedScenarioId === null) {
                unset($finding['scenario_id']);
            } else {
                $finding['scenario_id'] = $linkedScenarioId;
            }

            $result = $this->completePrincipalAttributionResult();
            $result['outcome'] = 'fail';
            $result['scenario_results']['waterline_operator_visibility'] = [
                'scenario_id' => 'waterline_operator_visibility',
                'status' => 'unsupported',
                'linked_findings' => [$finding],
            ];
            $result['findings'] = [$finding];

            $evaluation = PrincipalAttributionResultGate::evaluate($result);

            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertContains('waterline_operator_visibility', $evaluation['non_pass_scenarios']);
            $this->assertContains(
                'missing_focused_linked_finding',
                array_column($evaluation['gate_failures'], 'code'),
            );
        }
    }

    public function test_result_gate_rejects_bare_string_and_generic_findings_for_omitted_scenarios(): void
    {
        foreach ([
            'bare_string' => [
                'finding_links' => ['waterline_operator_visibility' => ['waterline-operator-gap']],
                'findings' => ['waterline-operator-gap'],
            ],
            'generic_structured' => [
                'finding_links' => ['waterline_operator_visibility' => [[
                    'id' => 'waterline-operator-gap',
                    'owning_surface' => 'waterline',
                    'artifact_versions' => $this->artifactVersions(),
                    'observed_behavior' => 'Waterline operator surface was not exercised.',
                    'expected_behavior' => 'Waterline selected-run history exposes event principal.',
                    'next_acceptance_criterion' => 'Exercise Waterline operator history against published artifacts.',
                ]]],
                'findings' => [[
                    'id' => 'waterline-operator-gap',
                    'owning_surface' => 'waterline',
                    'artifact_versions' => $this->artifactVersions(),
                    'observed_behavior' => 'Waterline operator surface was not exercised.',
                    'expected_behavior' => 'Waterline selected-run history exposes event principal.',
                    'next_acceptance_criterion' => 'Exercise Waterline operator history against published artifacts.',
                ]],
            ],
        ] as $case) {
            $result = $this->completePrincipalAttributionResult();
            unset($result['scenario_results']['waterline_operator_visibility']);
            $result = [
                ...$result,
                ...$case,
            ];

            $evaluation = PrincipalAttributionResultGate::evaluate($result);

            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertContains('waterline_operator_visibility', $evaluation['missing_scenarios']);
            $this->assertContains(
                'missing_focused_finding_for_omitted_scenario',
                array_column($evaluation['gate_failures'], 'code'),
            );
        }
    }

    public function test_result_gate_resolves_string_links_to_structured_matching_scenario_findings(): void
    {
        $finding = $this->structuredPrincipalFinding(
            'waterline_operator_visibility',
            'Waterline operator surface was not exercised by this runner revision.',
            'waterline',
            'Waterline selected-run history exposes event principal.',
            'Extend the host topology to boot Waterline against the published server and capture selected-run history.',
        );
        $finding['id'] = 'waterline-operator-gap';

        $result = $this->completePrincipalAttributionResult();
        $result['outcome'] = 'fail';
        $result['scenario_results']['waterline_operator_visibility'] = [
            'scenario_id' => 'waterline_operator_visibility',
            'status' => 'unsupported',
            'linked_findings' => ['waterline-operator-gap'],
        ];
        $result['findings'] = [$finding];
        $result['finding_links'] = [
            'waterline_operator_visibility' => ['waterline-operator-gap'],
        ];

        $evaluation = PrincipalAttributionResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('waterline_operator_visibility', $evaluation['non_pass_scenarios']);
        $this->assertNotContains(
            'missing_focused_linked_finding',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function completePrincipalAttributionResult(): array
    {
        $versions = [
            'server' => '0.2.228',
            'cli' => '0.1.75',
            'workflow-php' => '2.0.0-alpha.187',
            'sdk-python' => '0.4.84',
            'waterline' => '2.0.0-alpha.69',
        ];

        $alice = ['type' => 'auth:token', 'id' => 'alice'];
        $bob = ['type' => 'auth:token', 'id' => 'bob'];
        $worker = ['type' => 'auth:token', 'id' => 'worker:principal-attribution'];
        $anonymous = ['type' => 'server', 'id' => 'anonymous'];

        return [
            'schema' => PrincipalAttributionContract::RESULT_SCHEMA,
            'outcome' => 'pass',
            'runner_blocked' => false,
            'started_at' => '2026-06-01T21:00:00Z',
            'finished_at' => '2026-06-01T21:05:00Z',
            'generated_at' => '2026-06-01T21:05:00Z',
            'published_artifact_versions' => $versions,
            'resolved_artifact_versions' => $versions,
            'artifact_sources' => [
                'server' => 'docker image durableworkflow/server:0.2.228',
                'cli' => 'official release install asset',
                'workflow-php' => 'composer package durable-workflow/workflow',
                'sdk-python' => 'PyPI durable-workflow',
                'waterline' => 'published Waterline package',
            ],
            'topology' => [
                'auth_driver' => 'token',
                'anonymous_auth_driver' => 'none',
            ],
            'actor_matrix' => [
                'alice' => ['credentials' => ['alice-token-v1', 'alice-token-v2']],
                'bob' => ['credentials' => ['bob-token']],
            ],
            'history_dumps' => [
                'main' => [
                    'events' => [
                        ['type' => 'WorkflowStarted', 'principal' => $alice],
                        ['type' => 'SignalReceived', 'principal' => $bob],
                        ['type' => 'WorkflowCancelled', 'principal' => $alice],
                    ],
                ],
            ],
            'spoofing_attempts' => [
                'payload_values' => ['mallory'],
                'headers' => ['X-Workflow-Principal-Id', 'X-Workflow-Caller-Type', 'X-Forwarded-User'],
            ],
            'operator_visibility' => [
                'cli_history_json_principal_visible' => true,
                'waterline' => ['principal_visible' => true],
            ],
            'anonymous_observations' => [
                'status' => 'pass',
                'anonymous_principal' => $anonymous,
            ],
            'scenario_results' => [
                'published_artifact_install_only' => [
                    'status' => 'pass',
                    'resolved_artifact_versions' => $versions,
                    'artifact_sources' => [
                        'server' => 'docker image durableworkflow/server:0.2.228',
                        'cli' => 'official release install asset',
                        'workflow-php' => 'composer package durable-workflow/workflow',
                        'sdk-python' => 'PyPI durable-workflow',
                        'waterline' => 'published Waterline package',
                    ],
                    'local_product_source_checkouts_used' => false,
                ],
                'named_token_actor_matrix' => [
                    'status' => 'pass',
                    'actors' => ['alice', 'bob'],
                    'credentials' => ['alice' => ['alice-token-v1', 'alice-token-v2'], 'bob' => ['bob-token']],
                    'rotation_observations' => ['alice_v1_start' => 'alice', 'alice_v2_cancel' => 'alice'],
                ],
                'start_signal_cancel_spoofing' => [
                    'status' => 'pass',
                    'history_events' => ['WorkflowStarted', 'SignalReceived', 'WorkflowCancelled'],
                    'recorded_principals' => [
                        'WorkflowStarted' => $alice,
                        'SignalReceived' => $bob,
                        'WorkflowCancelled' => $alice,
                    ],
                    'spoofing_attempts' => [
                        'payload_values' => ['mallory'],
                        'headers' => ['X-Workflow-Principal-Id', 'X-Workflow-Caller-Type', 'X-Forwarded-User'],
                    ],
                ],
                'query_attribution' => [
                    'status' => 'pass',
                    'query_result' => ['principal' => $bob],
                    'recorded_principal' => $bob,
                    'history_or_query_task_surface' => ['command_context' => ['context' => ['principal' => $bob]]],
                ],
                'completion_failure_attribution' => [
                    'status' => 'pass',
                    'completion_event_principal' => $worker,
                    'failure_event_principal' => $worker,
                    'worker_principal' => $worker,
                    'expected_worker_principal' => $worker,
                    'documented_system_principals' => [],
                ],
                'server_originated_events' => [
                    'status' => 'pass',
                    'event_types' => ['TimerFired'],
                    'principal_values' => ['TimerFired' => null],
                    'classification' => 'explicit_null_for_events_without_originating_control_plane_command',
                ],
                'anonymous_attribution' => [
                    'status' => 'pass',
                    'anonymous_principal' => $anonymous,
                    'documented_value' => $anonymous,
                    'history_events' => ['WorkflowStarted', 'SignalReceived', 'WorkflowCancelled'],
                ],
                'python_sdk_visibility' => [
                    'status' => 'pass',
                    'client_operation' => ['status' => 'pass', 'client' => 'sdk-python'],
                    'recorded_principal' => $bob,
                    'shape_matches_http' => true,
                ],
                'php_client_visibility' => [
                    'status' => 'pass',
                    'client_operation' => ['status' => 'pass', 'client' => 'workflow-php'],
                    'recorded_principal' => $alice,
                    'shape_matches_http' => true,
                ],
                'cli_operator_visibility' => [
                    'status' => 'pass',
                    'command' => 'dw workflow:history pa-main run --output=json',
                    'output_sample' => '{"events":[{"principal":{"type":"auth:token","id":"alice"}}]}',
                    'principal_visible' => true,
                ],
                'waterline_operator_visibility' => [
                    'status' => 'pass',
                    'surface' => 'selected-run history',
                    'output_sample' => '{"events":[{"principal":{"type":"auth:token","id":"alice"}}]}',
                    'principal_visible' => true,
                ],
            ],
            'findings' => [],
            'local_product_source_checkouts_used' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function structuredPrincipalFinding(
        string $scenarioId,
        string $observed,
        string $owner,
        string $expected,
        string $nextAcceptance,
    ): array {
        return [
            'scenario_id' => $scenarioId,
            'owning_surface' => $owner,
            'artifact_versions' => [
                'server' => '0.2.228',
                'cli' => '0.1.75',
                'workflow-php' => '2.0.0-alpha.187',
                'sdk-python' => '0.4.84',
                'waterline' => '2.0.0-alpha.69',
            ],
            'observed_behavior' => $observed,
            'expected_behavior' => $expected,
            'next_acceptance_criterion' => $nextAcceptance,
        ];
    }

    private function assertFocusedPrincipalFinding(string $scenarioId, mixed $finding): void
    {
        $this->assertIsArray($finding);
        $this->assertSame($scenarioId, $finding['scenario_id'] ?? null);
        $this->assertNotEmpty($finding['owning_surface'] ?? null);
        $this->assertIsArray($finding['artifact_versions'] ?? null);
        $this->assertNotEmpty($finding['artifact_versions']);
        $this->assertNotEmpty($finding['observed_behavior'] ?? null);
        $this->assertNotEmpty($finding['expected_behavior'] ?? null);
        $this->assertNotEmpty($finding['next_acceptance_criterion'] ?? null);
    }

    private function linkSystemCommand(string $binDir, string $command): void
    {
        foreach (['/usr/bin', '/bin', '/usr/local/bin'] as $prefix) {
            $candidate = $prefix.'/'.$command;
            if (is_file($candidate) && is_executable($candidate)) {
                symlink($candidate, $binDir.'/'.$command);

                return;
            }
        }

        $this->markTestSkipped("required command {$command} is not available.");
    }

    private function removeTree(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function principalAttributionResult(array $overrides = []): array
    {
        return [
            'schema' => PrincipalAttributionContract::RESULT_SCHEMA,
            'outcome' => 'pass',
            'runner_blocked' => false,
            'started_at' => '2026-06-01T00:00:00Z',
            'finished_at' => '2026-06-01T00:01:00Z',
            'generated_at' => '2026-06-01T00:01:00Z',
            'published_artifact_versions' => $this->artifactVersions(),
            'resolved_artifact_versions' => $this->artifactVersions(),
            'artifact_sources' => [
                'server' => 'docker-image',
                'cli' => 'release-asset',
                'workflow-php' => 'composer',
                'sdk-python' => 'pypi',
                'waterline' => 'npm',
            ],
            'topology' => ['auth_driver' => 'token'],
            'actor_matrix' => ['alice' => ['credentials' => ['alice-token-v1', 'alice-token-v2']], 'bob' => ['credentials' => ['bob-token']]],
            'history_dumps' => ['main' => ['events' => []]],
            'spoofing_attempts' => ['payload_values' => ['mallory'], 'headers' => ['X-Forwarded-User']],
            'operator_visibility' => ['cli_history_json_principal_visible' => true],
            'anonymous_observations' => ['status' => 'pass', 'anonymous_principal' => ['type' => 'server', 'id' => 'anonymous']],
            'scenario_results' => $this->passingScenarioResults(),
            'findings' => [],
            ...$overrides,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function artifactVersions(): array
    {
        return [
            'server' => '0.2.228',
            'cli' => '0.1.75',
            'workflow' => '2.0.0-alpha.187',
            'workflow-php' => '2.0.0-alpha.187',
            'sdk-python' => '0.4.84',
            'waterline' => '2.0.0-alpha.69',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function artifactSources(): array
    {
        return [
            'server' => 'docker-image',
            'cli' => 'release-asset',
            'workflow-php' => 'composer',
            'sdk-python' => 'pypi',
            'waterline' => 'npm',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function passingScenarioResults(): array
    {
        $scenarios = [];
        foreach (PrincipalAttributionContract::manifest()['required_scenarios'] as $scenarioId) {
            $scenarios[$scenarioId] = $this->scenario(
                $scenarioId,
                'pass',
                $this->scenarioEvidence($scenarioId),
            );
        }

        return $scenarios;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function scenario(string $scenarioId, string $status, array $fields = []): array
    {
        return [
            'scenario_id' => $scenarioId,
            'status' => $status,
            ...$fields,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scenarioEvidence(string $scenarioId): array
    {
        $alice = ['type' => 'auth:token', 'id' => 'alice'];
        $bob = ['type' => 'auth:token', 'id' => 'bob'];
        $worker = ['type' => 'auth:token', 'id' => 'worker:principal-attribution'];
        $anonymous = ['type' => 'server', 'id' => 'anonymous'];

        return match ($scenarioId) {
            'published_artifact_install_only' => [
                'resolved_artifact_versions' => $this->artifactVersions(),
                'artifact_sources' => $this->artifactSources(),
                'local_product_source_checkouts_used' => false,
            ],
            'named_token_actor_matrix' => [
                'actors' => ['alice', 'bob'],
                'credentials' => ['alice' => ['alice-token-v1', 'alice-token-v2'], 'bob' => ['bob-token']],
                'rotation_observations' => ['alice_v1_start' => 'alice', 'alice_v2_cancel' => 'alice'],
            ],
            'start_signal_cancel_spoofing' => [
                'history_events' => ['WorkflowStarted', 'SignalReceived', 'WorkflowCancelled'],
                'recorded_principals' => ['WorkflowStarted' => $alice, 'SignalReceived' => $bob, 'WorkflowCancelled' => $alice],
                'spoofing_attempts' => ['payload_fields' => ['principal' => 'mallory'], 'headers' => ['X-Forwarded-User']],
            ],
            'query_attribution' => [
                'query_result' => ['status' => 'ready'],
                'recorded_principal' => $bob,
                'history_or_query_task_surface' => ['query_task' => ['principal' => $bob]],
            ],
            'completion_failure_attribution' => [
                'completion_event_principal' => $worker,
                'failure_event_principal' => $worker,
                'worker_principal' => $worker,
                'expected_worker_principal' => $worker,
                'documented_system_principals' => [],
            ],
            'server_originated_events' => [
                'event_types' => [],
                'principal_values' => [],
                'classification' => 'explicit_null_for_events_without_originating_control_plane_command',
            ],
            'anonymous_attribution' => [
                'anonymous_principal' => $anonymous,
                'documented_value' => $anonymous,
                'history_events' => ['WorkflowStarted', 'SignalReceived', 'WorkflowCancelled'],
            ],
            'python_sdk_visibility' => [
                'client_operation' => ['status' => 'pass'],
                'recorded_principal' => $bob,
                'shape_matches_http' => true,
            ],
            'php_client_visibility' => [
                'client_operation' => ['status' => 'pass'],
                'recorded_principal' => $alice,
                'shape_matches_http' => true,
            ],
            'cli_operator_visibility' => [
                'command' => 'dw workflow:history pa-main run --output=json',
                'output_sample' => '{"events":[{"principal":{"id":"alice","type":"auth:token"}}]}',
                'principal_visible' => true,
            ],
            'waterline_operator_visibility' => [
                'surface' => 'selected-run-history',
                'output_sample' => '{"principal":{"id":"alice","type":"auth:token"}}',
                'principal_visible' => true,
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function focusedFinding(string $scenarioId, string $surface): array
    {
        return [
            'id' => "{$scenarioId}-{$surface}",
            'scenario_id' => $scenarioId,
            'owning_surface' => $surface,
            'artifact_versions' => $this->artifactVersions(),
            'observed_behavior' => "{$scenarioId} did not pass against published artifacts.",
            'expected_behavior' => "{$scenarioId} records server-derived principal attribution.",
            'next_acceptance_criterion' => "record passing {$scenarioId} evidence against published artifacts",
        ];
    }
}
