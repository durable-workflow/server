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
        $this->assertSame(2, PrincipalAttributionContract::VERSION);
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
        $this->assertContains(
            'waterline-principal-attribution-execution.json',
            $manifest['host_runner_contract']['result_files'],
        );
        $this->assertTrue($manifest['host_runner_contract']['must_execute_against_published_artifacts']);
        $this->assertTrue($manifest['host_runner_contract']['must_record_runner_blocked_false_for_product_evidence']);
        $this->assertTrue($manifest['host_runner_contract']['must_attempt_spoofing_payloads_and_headers']);
        $this->assertTrue($manifest['host_runner_contract']['must_record_spoofing_matrix']);
        $this->assertContains(
            'runner_blocked_false_for_product_evidence',
            $manifest['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertContains(
            'spoofing_matrix_records_exact_requested_values_and_observed_principals',
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

        foreach (['server', 'cli', 'workflow', 'sdk-php', 'sdk-python', 'waterline'] as $artifact) {
            $this->assertArrayHasKey($artifact, $manifest['artifact_policy']['install_channels']);
        }

        $this->assertSame(
            'sdk-python',
            $manifest['finding_policy']['root_cause_owners']['python_sdk_visibility_failure'],
        );
        $this->assertSame(
            'sdk-php',
            $manifest['finding_policy']['root_cause_owners']['php_client_visibility_failure'],
        );
        $this->assertSame(
            'server_or_protocol',
            $manifest['finding_policy']['root_cause_owners']['shared_attribution_shape_failure'],
        );
        $this->assertContains(
            'sdk_principal_attribution_parity',
            $manifest['artifact_policy']['required_run_record_fields'],
        );
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
        $this->assertContains(
            'action_credentials',
            $manifest['scenario_requirements']['named_token_actor_matrix']['required_fields'],
        );
        $this->assertContains(
            'credential_rotation',
            $manifest['scenario_requirements']['named_token_actor_matrix']['required_fields'],
        );
        $this->assertContains(
            'action_credentials',
            $manifest['scenario_requirements']['start_signal_cancel_spoofing']['required_fields'],
        );
        $this->assertContains(
            'spoofing_matrix',
            $manifest['scenario_requirements']['start_signal_cancel_spoofing']['required_fields'],
        );
        $this->assertContains(
            'spoofing_attempts',
            $manifest['scenario_requirements']['query_attribution']['required_fields'],
        );
        $this->assertContains(
            'spoofing_matrix',
            $manifest['scenario_requirements']['query_attribution']['required_fields'],
        );
        foreach (['recorded_principals', 'spoofing_attempts', 'spoofing_matrix', 'anonymous_auth_driver'] as $requiredField) {
            $this->assertContains(
                $requiredField,
                $manifest['scenario_requirements']['anonymous_attribution']['required_fields'],
            );
        }

        foreach (['python_sdk_visibility', 'php_client_visibility'] as $sdkScenario) {
            foreach ([
                'sdk_package_version',
                'credential_used',
                'expected_principal',
                'raw_http_reference_principal',
                'history_api_principal_samples',
                'operation_outputs',
                'operation_output_sample',
            ] as $requiredField) {
                $this->assertContains(
                    $requiredField,
                    $manifest['scenario_requirements'][$sdkScenario]['required_fields'],
                );
            }
        }
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
        $this->assertContains(
            'rotated_credential_actions_record_before_after_labels_and_observed_principals',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'python_php_sdk_principals_match_expected_ids_and_raw_http_shape',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'anonymous_no_auth_topology_reported',
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
        foreach (['cli', 'workflow', 'sdk-php', 'sdk-python', 'waterline'] as $artifact) {
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
            $manifest['scenario_requirements'],
            $scenarioManifest['scenario_requirements'],
        );
        $this->assertSame(
            $manifest['worker_terminal_event_policy'],
            $scenarioManifest['worker_terminal_event_policy'],
        );
        $this->assertSame(
            $manifest['spoofing_guards'],
            $scenarioManifest['spoofing_guards'],
        );
        $this->assertSame(
            $manifest['host_runner_contract'],
            $scenarioManifest['host_runner_contract'],
        );
        $this->assertContains(
            'anonymous-no-auth-topology',
            $scenarioManifest['host_runner_contract']['required_execution_scopes'],
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
        $this->assertStringContainsString('"Authorization-Override": "Bearer mallory"', $script);
        $this->assertStringContainsString('def spoofing_matrix_row(', $script);
        $this->assertStringContainsString('"requested_spoof_values": {', $script);
        $this->assertStringContainsString('"caller_controlled_value_hits": caller_controlled_principal_hits(observed_principal)', $script);
        $this->assertStringContainsString('spoofing_matrix=main_spoofing_matrix', $script);
        $this->assertStringContainsString('spoofing_matrix=query_spoofing_matrix', $script);
        $this->assertStringContainsString('main_linked_findings: list[dict[str, Any]] = []', $script);
        $this->assertStringContainsString('linked_findings=main_linked_findings', $script);
        $this->assertStringContainsString('start/signal/cancel attribution failures', $script);
        $this->assertStringContainsString('action_credentials = {', $script);
        $this->assertStringContainsString('"credential_ref": "alice-token-v1"', $script);
        $this->assertStringContainsString('"credential_label": "alice credential v1"', $script);
        $this->assertStringContainsString('"credential_ref": "bob-token"', $script);
        $this->assertStringContainsString('"credential_ref": "alice-token-v2"', $script);
        $this->assertStringContainsString('"observed_principal": main_principals.get("WorkflowStarted")', $script);
        $this->assertStringContainsString('"observed_principal": main_principals.get("WorkflowCancelled")', $script);
        $this->assertStringContainsString('credential_rotation = {', $script);
        $this->assertStringContainsString('credential_rotation=credential_rotation', $script);
        $this->assertStringContainsString('credential rotation attribution failures', $script);
        $this->assertStringContainsString('fix token principal resolution across credential rotation', $script);
        $this->assertStringContainsString('"credential_material_recorded_as_principal": principal_id(main_principals.get("WorkflowStarted")) == "alice-token-v1"', $script);
        $this->assertStringContainsString('action_credentials=action_credentials', $script);
        $this->assertStringContainsString('"WorkflowStarted": {"type": "auth:token", "id": "alice"}', $script);
        $this->assertStringContainsString('"SignalReceived": {"type": "auth:token", "id": "bob"}', $script);
        $this->assertStringContainsString('principal_matches(actual, expected)', $script);
        $this->assertStringContainsString('ANONYMOUS_SERVER_URL="$anonymous_server_base_url"', $script);
        $this->assertStringContainsString('anonymous_auth_driver": "none"', $script);
        $this->assertStringContainsString('DW_AUTH_DRIVER: none', $script);
        $this->assertStringContainsString('caller-generated anonymous history event leaked null/undefined principal', $script);
        $this->assertStringContainsString('anonymous_linked_findings: list[dict[str, Any]] = []', $script);
        $this->assertStringContainsString('recorded_principals=anonymous_principals', $script);
        $this->assertStringContainsString('cancel_workflow(', $script);
        $this->assertStringContainsString('extra=ADVERSARIAL_BODY_FIELDS', $script);
        $this->assertStringContainsString('body={"reason": "anonymous principal attribution", **ADVERSARIAL_BODY_FIELDS}', $script);
        $this->assertStringContainsString('spoofing_attempts=spoofing_attempt_catalog(["start", "signal", "cancel"])', $script);
        $this->assertStringContainsString('spoofing_matrix=anonymous_spoofing_matrix', $script);
        $this->assertStringContainsString('anonymous_auth_driver="none"', $script);
        $this->assertStringContainsString('linked_findings=anonymous_linked_findings', $script);
        $this->assertStringContainsString('run_python_sdk_client_operation', $script);
        $this->assertStringContainsString('python_operation = run_python_sdk_client_operation(python_client_id)', $script);
        $this->assertStringContainsString('python_linked_findings: list[dict[str, Any]] = []', $script);
        $this->assertStringContainsString('linked_findings=python_linked_findings', $script);
        $this->assertStringContainsString('"python_sdk_visibility", "sdk-python"', $script);
        $this->assertStringContainsString('def sdk_operation_outputs(', $script);
        $this->assertStringContainsString('"source": "normalized_from_fire_and_forget_sdk_result"', $script);
        $this->assertStringContainsString('python_operation_outputs = sdk_operation_outputs(python_operation, python_client_id, "sdk-python")', $script);
        $this->assertStringContainsString('operation_outputs=python_operation_outputs', $script);
        $this->assertStringContainsString('run_php_client_operation', $script);
        $this->assertStringContainsString('php_operation = run_php_client_operation(php_client_id)', $script);
        $this->assertStringContainsString('php_linked_findings: list[dict[str, Any]] = []', $script);
        $this->assertStringContainsString('linked_findings=php_linked_findings', $script);
        $this->assertStringContainsString('"php_client_visibility", "sdk-php"', $script);
        $this->assertStringContainsString('php_operation_outputs = sdk_operation_outputs(php_operation, php_client_id, "sdk-php")', $script);
        $this->assertStringContainsString('operation_outputs=php_operation_outputs', $script);
        $this->assertStringContainsString('sdk_principal_attribution_parity = {', $script);
        $this->assertStringContainsString('"sdk_principal_attribution_parity": sdk_principal_attribution_parity', $script);
        $this->assertStringContainsString('"sdkPrincipalAttributionParity": {', $script);
        $this->assertStringNotContainsString('Python SDK client operation was not exercised by this runner revision', $script);
        $this->assertStringNotContainsString('PHP client operation was not exercised by this runner revision', $script);
        $this->assertStringContainsString('waterline:principal-attribution-conformance', $script);
        $this->assertStringContainsString('WATERLINE_PRINCIPAL_RESULT="$waterline_result_path"', $script);
        $this->assertStringContainsString('load_waterline_principal_shard', $script);
        $this->assertStringContainsString('def aggregate_waterline_evidence()', $script);
        $this->assertStringContainsString('def write_principal_attribution_aggregate(', $script);
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
        $this->assertStringContainsString('"waterline": {"status": waterline_evidence["status"]', $script);
        $this->assertStringContainsString('"waterlineOperatorVisibility": waterline_scenario', $script);
        $this->assertStringContainsString('"waterlineShardExecution": waterline_execution', $script);
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
            $this->assertSame(
                'runner_blocked',
                $record['sdkPrincipalAttributionParity']['python_sdk_visibility']['status'],
            );
            $this->assertSame(
                'runner_blocked',
                $record['sdkPrincipalAttributionParity']['php_client_visibility']['status'],
            );
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

    public function test_result_gate_requires_expected_action_credential_mapping_for_alice_bob_matrix(): void
    {
        foreach (['named_token_actor_matrix', 'start_signal_cancel_spoofing'] as $scenarioId) {
            $result = $this->completePrincipalAttributionResult();
            $result['scenario_results'][$scenarioId]['action_credentials']['signal']['credential_ref'] = 'alice-token-v1';

            $evaluation = PrincipalAttributionResultGate::evaluate($result);
            $credentialFailures = array_values(array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['code'] ?? null) === 'action_credential_ref_mismatch',
            ));

            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertContains(
                [
                    'code' => 'action_credential_ref_mismatch',
                    'scenario_id' => $scenarioId,
                    'action' => 'signal',
                    'expected_credential_ref' => 'bob-token',
                    'actual_credential_ref' => 'alice-token-v1',
                ],
                $credentialFailures,
            );
        }
    }

    public function test_result_gate_requires_observed_action_principals_for_alice_rotation(): void
    {
        foreach (['named_token_actor_matrix', 'start_signal_cancel_spoofing'] as $scenarioId) {
            $result = $this->completePrincipalAttributionResult();
            unset($result['scenario_results'][$scenarioId]['action_credentials']['cancel']['observed_principal']);

            $evaluation = PrincipalAttributionResultGate::evaluate($result);

            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertContains(
                'action_credential_observed_principal_mismatch',
                array_column($evaluation['gate_failures'], 'code'),
            );
        }
    }

    public function test_result_gate_requires_exact_adversarial_spoofing_matrix_for_pass(): void
    {
        $result = $this->completePrincipalAttributionResult();
        unset($result['scenario_results']['start_signal_cancel_spoofing']['spoofing_matrix']);

        $evaluation = PrincipalAttributionResultGate::evaluate($result);
        $codes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('missing_scenario_evidence', $codes);
        $this->assertContains('missing_spoofing_matrix', $codes);

        $result = $this->completePrincipalAttributionResult();
        unset($result['scenario_results']['query_attribution']['spoofing_matrix'][0]['requested_spoof_values']['headers']['Authorization-Override']);

        $evaluation = PrincipalAttributionResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            [
                'code' => 'missing_spoofing_matrix_header_value',
                'scenario_id' => 'query_attribution',
                'action' => 'query',
                'header' => 'Authorization-Override',
                'expected_value' => 'Bearer mallory',
                'actual_value' => null,
            ],
            $evaluation['gate_failures'],
        );
    }

    public function test_result_gate_routes_caller_controlled_principal_as_security_failure(): void
    {
        $result = $this->completePrincipalAttributionResult();
        $result['scenario_results']['start_signal_cancel_spoofing']['spoofing_matrix'][0]['observed_principal'] = [
            'type' => 'auth:token',
            'id' => 'mallory',
        ];

        $evaluation = PrincipalAttributionResultGate::evaluate($result);
        $codes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('spoofing_matrix_observed_principal_mismatch', $codes);
        $this->assertContains('caller_controlled_principal_recorded', $codes);

        $securityFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'caller_controlled_principal_recorded',
        ));
        $this->assertSame('P0', $securityFailures[0]['security_severity']);
        $this->assertSame('server', $securityFailures[0]['owning_surface']);
    }

    public function test_result_gate_rejects_credential_ref_as_observed_principal(): void
    {
        $result = $this->completePrincipalAttributionResult();
        $result['scenario_results']['named_token_actor_matrix']['action_credentials']['start']['observed_principal'] = [
            'type' => 'auth:token',
            'id' => 'alice-token-v1',
        ];
        $result['scenario_results']['named_token_actor_matrix']['action_credentials']['start']['credential_material_recorded_as_principal'] = true;
        $result['scenario_results']['named_token_actor_matrix']['credential_rotation']['before']['observed_principal'] = [
            'type' => 'auth:token',
            'id' => 'alice-token-v1',
        ];
        $result['scenario_results']['named_token_actor_matrix']['credential_rotation']['credential_material_recorded_as_principal'] = true;

        $evaluation = PrincipalAttributionResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'credential_ref_recorded_as_principal',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertContains(
            'credential_rotation_material_recorded_as_principal_not_false',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_anonymous_start_signal_cancel_principals_for_pass(): void
    {
        $result = $this->completePrincipalAttributionResult();
        $result['scenario_results']['anonymous_attribution']['documented_value'] = ['type' => 'server', 'id' => 'guest'];
        $result['scenario_results']['anonymous_attribution']['anonymous_auth_driver'] = 'token';
        $result['scenario_results']['anonymous_attribution']['history_events'] = ['WorkflowStarted', 'SignalReceived'];
        $result['scenario_results']['anonymous_attribution']['recorded_principals']['SignalReceived'] = null;

        $evaluation = PrincipalAttributionResultGate::evaluate($result);
        $codes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('documented_anonymous_principal_mismatch', $codes);
        $this->assertContains('anonymous_auth_driver_not_none', $codes);
        $this->assertContains('missing_anonymous_history_event', $codes);
        $this->assertContains('anonymous_event_principal_mismatch', $codes);
    }

    public function test_result_gate_rejects_spoofed_or_unexercised_anonymous_evidence_for_pass(): void
    {
        $result = $this->completePrincipalAttributionResult();
        $result['scenario_results']['anonymous_attribution']['recorded_principals']['WorkflowStarted'] = [
            'type' => 'gateway',
            'id' => 'mallory',
        ];
        $result['scenario_results']['anonymous_attribution']['spoofing_attempts'] = [
            'payload_fields' => [],
            'headers' => ['X-Workflow-Principal-Id'],
            'executed' => false,
        ];

        $evaluation = PrincipalAttributionResultGate::evaluate($result);
        $codes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('anonymous_event_principal_mismatch', $codes);
        $this->assertContains('anonymous_spoofed_principal_recorded', $codes);
        $this->assertContains('missing_anonymous_spoofing_payload_fields', $codes);
        $this->assertContains('missing_anonymous_spoofing_gateway_header', $codes);
        $this->assertContains('missing_anonymous_spoofing_action', $codes);
        $this->assertContains('anonymous_spoofing_attempts_not_executed', $codes);
    }

    public function test_result_gate_requires_anonymous_no_auth_topology_for_pass(): void
    {
        $result = $this->completePrincipalAttributionResult();
        unset($result['topology']['anonymous_server_url']);
        $result['topology']['anonymous_auth_driver'] = 'token';

        $evaluation = PrincipalAttributionResultGate::evaluate($result);
        $codes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('anonymous_topology_auth_driver_not_none', $codes);
        $this->assertContains('anonymous_topology_server_url_missing', $codes);
    }

    public function test_result_gate_accepts_routed_anonymous_null_leakage_as_non_passing_product_evidence(): void
    {
        $finding = $this->structuredPrincipalFinding(
            'anonymous_attribution',
            'Caller-generated anonymous WorkflowStarted history event leaked a null principal.',
            'server',
            'Auth-disabled start, signal, and cancel history records type=server id=anonymous.',
            'Thread the anonymous server principal into caller-generated no-auth events.',
        );
        $result = $this->completePrincipalAttributionResult();
        $result['outcome'] = 'fail';
        $result['scenario_results']['anonymous_attribution']['status'] = 'fail';
        $result['scenario_results']['anonymous_attribution']['recorded_principals']['WorkflowStarted'] = null;
        $result['scenario_results']['anonymous_attribution']['linked_findings'] = [$finding];
        $result['scenario_results']['anonymous_attribution']['findings'] = [$finding];
        $result['findings'] = [$finding];

        $evaluation = PrincipalAttributionResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('anonymous_attribution', $evaluation['non_pass_scenarios']);
        $this->assertNotContains(
            'missing_focused_linked_finding',
            array_column($evaluation['gate_failures'], 'code'),
        );
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

    public function test_result_gate_accepts_routed_sdk_failures_as_non_passing_product_evidence(): void
    {
        foreach ([
            'python_sdk_visibility' => 'sdk-python',
            'php_client_visibility' => 'sdk-php',
        ] as $scenarioId => $owningSurface) {
            $finding = $this->structuredPrincipalFinding(
                $scenarioId,
                "{$scenarioId} failed against the published SDK package.",
                $owningSurface,
                "{$scenarioId} records the same server-derived principal shape as raw HTTP.",
                "Fix {$owningSurface} credential propagation or the shared attribution shape before marking this cell pass.",
            );

            $result = $this->completePrincipalAttributionResult();
            $result['outcome'] = 'fail';
            $result['scenario_results'][$scenarioId]['status'] = 'fail';
            $result['scenario_results'][$scenarioId]['linked_findings'] = [$finding];
            $result['scenario_results'][$scenarioId]['findings'] = [$finding];
            $result['findings'] = [$finding];

            $evaluation = PrincipalAttributionResultGate::evaluate($result);

            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertContains($scenarioId, $evaluation['non_pass_scenarios']);
            $this->assertNotContains(
                'missing_focused_linked_finding',
                array_column($evaluation['gate_failures'], 'code'),
            );
        }
    }

    public function test_result_gate_requires_sdk_principal_values_and_shape_for_pass(): void
    {
        $result = $this->completePrincipalAttributionResult();
        $result['scenario_results']['python_sdk_visibility']['client_operation']['status'] = 'fail';
        $result['scenario_results']['python_sdk_visibility']['credential_used']['actor'] = 'alice';
        $result['scenario_results']['python_sdk_visibility']['credential_used']['credential_ref'] = 'alice-token-v1';
        $result['scenario_results']['python_sdk_visibility']['expected_principal'] = [
            'type' => 'auth:token',
            'id' => 'alice',
        ];
        $result['scenario_results']['python_sdk_visibility']['recorded_principal'] = [
            'type' => 'auth:token',
            'id' => 'alice',
        ];
        $result['scenario_results']['python_sdk_visibility']['raw_http_reference_principal'] = [
            'type' => 'auth:token',
            'id' => 'bob',
            'label' => 'Bob',
        ];
        $result['scenario_results']['python_sdk_visibility']['history_api_principal_samples']['SignalReceived'] = [
            'type' => 'auth:token',
            'id' => 'alice',
        ];
        unset($result['scenario_results']['python_sdk_visibility']['operation_outputs']);
        $result['scenario_results']['python_sdk_visibility']['operation_output_sample'] = '';
        $result['scenario_results']['python_sdk_visibility']['shape_matches_http'] = false;

        $evaluation = PrincipalAttributionResultGate::evaluate($result);
        $codes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('sdk_client_operation_not_passed', $codes);
        $this->assertContains('sdk_credential_actor_mismatch', $codes);
        $this->assertContains('sdk_credential_ref_mismatch', $codes);
        $this->assertContains('sdk_expected_principal_mismatch', $codes);
        $this->assertContains('sdk_recorded_principal_mismatch', $codes);
        $this->assertContains('sdk_history_api_principal_sample_mismatch', $codes);
        $this->assertContains('missing_sdk_operation_outputs', $codes);
        $this->assertContains('missing_sdk_operation_output_sample', $codes);
        $this->assertContains('sdk_shape_matches_http_not_true', $codes);
        $this->assertContains('sdk_principal_shape_mismatch', $codes);
    }

    public function test_result_gate_applies_php_client_sdk_principal_expectations(): void
    {
        $result = $this->completePrincipalAttributionResult();
        $result['scenario_results']['php_client_visibility']['credential_used'] = [
            'actor' => 'bob',
            'credential_ref' => 'bob-token',
        ];
        $result['scenario_results']['php_client_visibility']['recorded_principal'] = [
            'type' => 'auth:token',
            'id' => 'bob',
        ];
        $result['scenario_results']['php_client_visibility']['history_api_principal_samples']['WorkflowStarted'] = [
            'type' => 'auth:token',
            'id' => 'bob',
        ];
        unset($result['scenario_results']['php_client_visibility']['operation_outputs']['signal_workflow']);

        $evaluation = PrincipalAttributionResultGate::evaluate($result);
        $codes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('sdk_credential_actor_mismatch', $codes);
        $this->assertContains('sdk_credential_ref_mismatch', $codes);
        $this->assertContains('sdk_recorded_principal_mismatch', $codes);
        $this->assertContains('sdk_history_api_principal_sample_mismatch', $codes);
        $this->assertContains('missing_sdk_operation_output', $codes);
    }

    public function test_result_gate_accepts_explicit_fire_and_forget_sdk_signal_evidence(): void
    {
        $result = $this->completePrincipalAttributionResult();
        foreach (['python_sdk_visibility', 'php_client_visibility'] as $scenarioId) {
            $result['scenario_results'][$scenarioId]['operation_outputs']['signal_workflow'] = [
                'workflow_id' => 'pa-sdk',
                'signal_name' => 'nudge',
                'accepted' => true,
                'raw_response' => null,
                'source' => 'normalized_from_fire_and_forget_sdk_result',
            ];
        }

        $evaluation = PrincipalAttributionResultGate::evaluate($result);

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['gate_failures']);
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
            'workflow' => '2.0.0-alpha.187',
            'sdk-php' => '0.1.1',
            'sdk-python' => '0.4.84',
            'waterline' => '2.0.0-alpha.69',
        ];

        $alice = ['type' => 'auth:token', 'id' => 'alice'];
        $bob = ['type' => 'auth:token', 'id' => 'bob'];
        $worker = ['type' => 'auth:token', 'id' => 'worker:principal-attribution'];
        $anonymous = ['type' => 'server', 'id' => 'anonymous'];
        $anonymousRecorded = [
            'WorkflowStarted' => $anonymous,
            'SignalReceived' => $anonymous,
            'WorkflowCancelled' => $anonymous,
        ];
        $actionCredentials = $this->actionCredentials();

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
                'workflow' => 'composer package durable-workflow/workflow',
                'sdk-php' => 'composer package durable-workflow/sdk',
                'sdk-python' => 'PyPI durable-workflow',
                'waterline' => 'published Waterline package',
            ],
            'topology' => [
                'server_url' => 'http://127.0.0.1:18080',
                'auth_driver' => 'token',
                'anonymous_auth_driver' => 'none',
                'anonymous_server_url' => 'http://127.0.0.1:18081',
            ],
            'actor_matrix' => [
                'alice' => ['credentials' => ['alice-token-v1', 'alice-token-v2']],
                'bob' => ['credentials' => ['bob-token']],
                'action_credentials' => $actionCredentials,
                'credential_rotation' => $this->credentialRotationEvidence(),
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
            'spoofing_matrix' => $this->spoofingMatrix(),
            'operator_visibility' => [
                'cli_history_json_principal_visible' => true,
                'waterline' => ['principal_visible' => true],
            ],
            'anonymous_observations' => [
                'status' => 'pass',
                'anonymous_principal' => $anonymous,
                'documented_value' => $anonymous,
                'history_events' => ['WorkflowStarted', 'SignalReceived', 'WorkflowCancelled'],
                'recorded_principals' => $anonymousRecorded,
                'spoofing_attempts' => [
                    'payload_fields' => ['principal' => 'mallory'],
                    'headers' => ['X-Workflow-Caller-Type', 'X-Workflow-Auth-Method', 'X-Forwarded-User'],
                    'actions' => ['start', 'signal', 'cancel'],
                    'executed' => true,
                ],
                'spoofing_matrix' => $this->spoofingMatrix(['anonymous_start', 'anonymous_signal', 'anonymous_cancel']),
                'anonymous_auth_driver' => 'none',
            ],
            'scenario_results' => [
                'published_artifact_install_only' => [
                    'status' => 'pass',
                    'resolved_artifact_versions' => $versions,
                    'artifact_sources' => [
                        'server' => 'docker image durableworkflow/server:0.2.228',
                        'cli' => 'official release install asset',
                        'sdk-php' => 'composer package durable-workflow/sdk',
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
                    'credential_rotation' => $this->credentialRotationEvidence(),
                    'action_credentials' => $actionCredentials,
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
                    'spoofing_matrix' => $this->spoofingMatrix(['start', 'signal', 'cancel']),
                    'action_credentials' => $actionCredentials,
                ],
                'query_attribution' => [
                    'status' => 'pass',
                    'query_result' => ['principal' => $bob],
                    'recorded_principal' => $bob,
                    'history_or_query_task_surface' => ['command_context' => ['context' => ['principal' => $bob]]],
                    'spoofing_attempts' => [
                        'payload_values' => ['mallory'],
                        'headers' => ['X-Workflow-Principal-Id', 'X-Workflow-Caller-Type', 'X-Forwarded-User'],
                    ],
                    'spoofing_matrix' => $this->spoofingMatrix(['query']),
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
                    'recorded_principals' => $anonymousRecorded,
                    'spoofing_attempts' => [
                        'payload_fields' => ['principal' => 'mallory'],
                        'headers' => ['X-Workflow-Caller-Type', 'X-Workflow-Auth-Method', 'X-Forwarded-User'],
                        'actions' => ['start', 'signal', 'cancel'],
                        'executed' => true,
                    ],
                    'spoofing_matrix' => $this->spoofingMatrix(['anonymous_start', 'anonymous_signal', 'anonymous_cancel']),
                    'anonymous_auth_driver' => 'none',
                ],
                'python_sdk_visibility' => [
                    'status' => 'pass',
                    'client_operation' => ['status' => 'pass', 'client' => 'sdk-python'],
                    'sdk_package_version' => $versions['sdk-python'],
                    'credential_used' => ['actor' => 'bob', 'credential_ref' => 'bob-token'],
                    'expected_principal' => $bob,
                    'raw_http_reference_principal' => $bob,
                    'history_api_principal_samples' => [
                        'WorkflowStarted' => $bob,
                        'SignalReceived' => $bob,
                    ],
                    'operation_outputs' => [
                        'start_workflow' => ['workflow_id' => 'pa-python', 'run_id' => 'run-python'],
                        'signal_workflow' => ['workflow_id' => 'pa-python', 'signal_name' => 'nudge'],
                    ],
                    'operation_output_sample' => '{"workflow_id":"pa-python","operation":"start+signal"}',
                    'recorded_principal' => $bob,
                    'shape_matches_http' => true,
                ],
                'php_client_visibility' => [
                    'status' => 'pass',
                    'client_operation' => ['status' => 'pass', 'client' => 'sdk-php'],
                    'sdk_package_version' => $versions['sdk-php'],
                    'credential_used' => ['actor' => 'alice', 'credential_ref' => 'alice-token-v1'],
                    'expected_principal' => $alice,
                    'raw_http_reference_principal' => $alice,
                    'history_api_principal_samples' => [
                        'WorkflowStarted' => $alice,
                        'SignalReceived' => $alice,
                    ],
                    'operation_outputs' => [
                        'start_workflow' => ['workflow_id' => 'pa-php', 'run_id' => 'run-php'],
                        'signal_workflow' => ['workflow_id' => 'pa-php', 'signal_name' => 'nudge'],
                    ],
                    'operation_output_sample' => '{"workflow_id":"pa-php","operation":"DurableWorkflow\\Client::startWorkflow+signalWorkflow"}',
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
    ): array
    {
        return [
            'scenario_id' => $scenarioId,
            'owning_surface' => $owner,
            'artifact_versions' => [
                'server' => '0.2.228',
                'cli' => '0.1.75',
                'workflow' => '2.0.0-alpha.187',
                'sdk-php' => '0.1.1',
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
                'workflow' => 'composer',
                'sdk-php' => 'composer',
                'sdk-python' => 'pypi',
                'waterline' => 'npm',
            ],
            'topology' => [
                'server_url' => 'http://127.0.0.1:18080',
                'auth_driver' => 'token',
                'anonymous_auth_driver' => 'none',
                'anonymous_server_url' => 'http://127.0.0.1:18081',
            ],
            'actor_matrix' => [
                'alice' => ['credentials' => ['alice-token-v1', 'alice-token-v2']],
                'bob' => ['credentials' => ['bob-token']],
                'action_credentials' => $this->actionCredentials(),
                'credential_rotation' => $this->credentialRotationEvidence(),
            ],
            'history_dumps' => ['main' => ['events' => []]],
            'spoofing_attempts' => ['payload_values' => ['mallory'], 'headers' => ['X-Forwarded-User']],
            'spoofing_matrix' => $this->spoofingMatrix(),
            'operator_visibility' => ['cli_history_json_principal_visible' => true],
            'sdk_principal_attribution_parity' => [
                'python_sdk_visibility' => [
                    'status' => 'pass',
                    ...$this->scenarioEvidence('python_sdk_visibility'),
                ],
                'php_client_visibility' => [
                    'status' => 'pass',
                    ...$this->scenarioEvidence('php_client_visibility'),
                ],
            ],
            'anonymous_observations' => [
                'status' => 'pass',
                'anonymous_principal' => ['type' => 'server', 'id' => 'anonymous'],
                'documented_value' => ['type' => 'server', 'id' => 'anonymous'],
                'history_events' => ['WorkflowStarted', 'SignalReceived', 'WorkflowCancelled'],
                'recorded_principals' => [
                    'WorkflowStarted' => ['type' => 'server', 'id' => 'anonymous'],
                    'SignalReceived' => ['type' => 'server', 'id' => 'anonymous'],
                    'WorkflowCancelled' => ['type' => 'server', 'id' => 'anonymous'],
                ],
                'spoofing_attempts' => [
                    'payload_fields' => ['principal' => 'mallory'],
                    'headers' => ['X-Workflow-Caller-Type', 'X-Workflow-Auth-Method', 'X-Forwarded-User'],
                    'actions' => ['start', 'signal', 'cancel'],
                    'executed' => true,
                ],
                'spoofing_matrix' => $this->spoofingMatrix(['anonymous_start', 'anonymous_signal', 'anonymous_cancel']),
                'anonymous_auth_driver' => 'none',
            ],
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
            'sdk-php' => '0.1.1',
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
            'workflow' => 'composer',
            'sdk-php' => 'composer',
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
        $anonymousRecorded = [
            'WorkflowStarted' => $anonymous,
            'SignalReceived' => $anonymous,
            'WorkflowCancelled' => $anonymous,
        ];
        $versions = $this->artifactVersions();

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
                'credential_rotation' => $this->credentialRotationEvidence(),
                'action_credentials' => $this->actionCredentials(),
            ],
            'start_signal_cancel_spoofing' => [
                'history_events' => ['WorkflowStarted', 'SignalReceived', 'WorkflowCancelled'],
                'recorded_principals' => ['WorkflowStarted' => $alice, 'SignalReceived' => $bob, 'WorkflowCancelled' => $alice],
                'spoofing_attempts' => ['payload_fields' => ['principal' => 'mallory'], 'headers' => ['X-Forwarded-User']],
                'spoofing_matrix' => $this->spoofingMatrix(['start', 'signal', 'cancel']),
                'action_credentials' => $this->actionCredentials(),
            ],
            'query_attribution' => [
                'query_result' => ['status' => 'ready'],
                'recorded_principal' => $bob,
                'history_or_query_task_surface' => ['query_task' => ['principal' => $bob]],
                'spoofing_attempts' => [
                    'payload_fields' => ['principal' => 'mallory'],
                    'headers' => ['X-Forwarded-User'],
                ],
                'spoofing_matrix' => $this->spoofingMatrix(['query']),
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
                'recorded_principals' => $anonymousRecorded,
                'spoofing_attempts' => [
                    'payload_fields' => ['principal' => 'mallory'],
                    'headers' => ['X-Workflow-Caller-Type', 'X-Workflow-Auth-Method', 'X-Forwarded-User'],
                    'actions' => ['start', 'signal', 'cancel'],
                    'executed' => true,
                ],
                'spoofing_matrix' => $this->spoofingMatrix(['anonymous_start', 'anonymous_signal', 'anonymous_cancel']),
                'anonymous_auth_driver' => 'none',
            ],
            'python_sdk_visibility' => [
                'client_operation' => ['status' => 'pass'],
                'sdk_package_version' => $versions['sdk-python'],
                'credential_used' => ['actor' => 'bob', 'credential_ref' => 'bob-token'],
                'expected_principal' => $bob,
                'raw_http_reference_principal' => $bob,
                'history_api_principal_samples' => [
                    'WorkflowStarted' => $bob,
                    'SignalReceived' => $bob,
                ],
                'operation_outputs' => [
                    'start_workflow' => ['workflow_id' => 'pa-python', 'run_id' => 'run-python'],
                    'signal_workflow' => ['workflow_id' => 'pa-python', 'signal_name' => 'nudge'],
                ],
                'operation_output_sample' => '{"workflow_id":"pa-python","operation":"start+signal"}',
                'recorded_principal' => $bob,
                'shape_matches_http' => true,
            ],
            'php_client_visibility' => [
                'client_operation' => ['status' => 'pass'],
                'sdk_package_version' => $versions['sdk-php'],
                'credential_used' => ['actor' => 'alice', 'credential_ref' => 'alice-token-v1'],
                'expected_principal' => $alice,
                'raw_http_reference_principal' => $alice,
                'history_api_principal_samples' => [
                    'WorkflowStarted' => $alice,
                    'SignalReceived' => $alice,
                ],
                'operation_outputs' => [
                    'start_workflow' => ['workflow_id' => 'pa-php', 'run_id' => 'run-php'],
                    'signal_workflow' => ['workflow_id' => 'pa-php', 'signal_name' => 'nudge'],
                ],
                'operation_output_sample' => '{"workflow_id":"pa-php","operation":"DurableWorkflow\\Client::startWorkflow+signalWorkflow"}',
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
     * @return array<string, array<string, mixed>>
     */
    private function actionCredentials(): array
    {
        return [
            'start' => [
                'actor' => 'alice',
                'credential_ref' => 'alice-token-v1',
                'credential_label' => 'alice credential v1',
                'expected_principal' => ['type' => 'auth:token', 'id' => 'alice'],
                'observed_principal' => ['type' => 'auth:token', 'id' => 'alice'],
                'credential_material_recorded_as_principal' => false,
                'event_type' => 'WorkflowStarted',
            ],
            'signal' => [
                'actor' => 'bob',
                'credential_ref' => 'bob-token',
                'credential_label' => 'bob credential',
                'expected_principal' => ['type' => 'auth:token', 'id' => 'bob'],
                'observed_principal' => ['type' => 'auth:token', 'id' => 'bob'],
                'credential_material_recorded_as_principal' => false,
                'event_type' => 'SignalReceived',
            ],
            'cancel' => [
                'actor' => 'alice',
                'credential_ref' => 'alice-token-v2',
                'credential_label' => 'alice credential v2',
                'previous_credential_ref' => 'alice-token-v1',
                'previous_credential_label' => 'alice credential v1',
                'expected_principal' => ['type' => 'auth:token', 'id' => 'alice'],
                'observed_principal' => ['type' => 'auth:token', 'id' => 'alice'],
                'credential_material_recorded_as_principal' => false,
                'event_type' => 'WorkflowCancelled',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function credentialRotationEvidence(): array
    {
        return [
            'actor' => 'alice',
            'before' => [
                'action' => 'start',
                'credential_ref' => 'alice-token-v1',
                'credential_label' => 'alice credential v1',
                'observed_principal' => ['type' => 'auth:token', 'id' => 'alice'],
            ],
            'after' => [
                'action' => 'cancel',
                'credential_ref' => 'alice-token-v2',
                'credential_label' => 'alice credential v2',
                'observed_principal' => ['type' => 'auth:token', 'id' => 'alice'],
            ],
            'stable_principal_id' => 'alice',
            'credential_material_recorded_as_principal' => false,
        ];
    }

    /**
     * @param list<string> $actions
     *
     * @return list<array<string, mixed>>
     */
    private function spoofingMatrix(array $actions = [
        'start',
        'signal',
        'cancel',
        'query',
        'anonymous_start',
        'anonymous_signal',
        'anonymous_cancel',
    ]): array
    {
        $alice = ['type' => 'auth:token', 'id' => 'alice'];
        $bob = ['type' => 'auth:token', 'id' => 'bob'];
        $anonymous = ['type' => 'server', 'id' => 'anonymous'];
        $actionCredentials = $this->actionCredentials();
        $rows = [
            'start' => [
                'action' => 'start',
                'surface' => 'history_api',
                'auth_driver' => 'token',
                'credential_used' => $actionCredentials['start'],
                'event_type' => 'WorkflowStarted',
                'requested_spoof_values' => $this->spoofingRequestedValues(),
                'expected_principal' => $alice,
                'observed_principal' => $alice,
                'caller_controlled_value_hits' => [],
            ],
            'signal' => [
                'action' => 'signal',
                'surface' => 'history_api',
                'auth_driver' => 'token',
                'credential_used' => $actionCredentials['signal'],
                'event_type' => 'SignalReceived',
                'requested_spoof_values' => $this->spoofingRequestedValues(),
                'expected_principal' => $bob,
                'observed_principal' => $bob,
                'caller_controlled_value_hits' => [],
            ],
            'cancel' => [
                'action' => 'cancel',
                'surface' => 'history_api',
                'auth_driver' => 'token',
                'credential_used' => $actionCredentials['cancel'],
                'event_type' => 'WorkflowCancelled',
                'requested_spoof_values' => $this->spoofingRequestedValues(),
                'expected_principal' => $alice,
                'observed_principal' => $alice,
                'caller_controlled_value_hits' => [],
            ],
            'query' => [
                'action' => 'query',
                'surface' => 'query_task_or_response',
                'auth_driver' => 'token',
                'credential_used' => [
                    'actor' => 'bob',
                    'credential_ref' => 'bob-token',
                    'credential_label' => 'bob credential',
                ],
                'requested_spoof_values' => $this->spoofingRequestedValues(),
                'expected_principal' => $bob,
                'observed_principal' => $bob,
                'caller_controlled_value_hits' => [],
            ],
            'anonymous_start' => [
                'action' => 'anonymous_start',
                'surface' => 'history_api',
                'auth_driver' => 'none',
                'credential_used' => null,
                'event_type' => 'WorkflowStarted',
                'requested_spoof_values' => $this->spoofingRequestedValues(),
                'expected_principal' => $anonymous,
                'observed_principal' => $anonymous,
                'caller_controlled_value_hits' => [],
            ],
            'anonymous_signal' => [
                'action' => 'anonymous_signal',
                'surface' => 'history_api',
                'auth_driver' => 'none',
                'credential_used' => null,
                'event_type' => 'SignalReceived',
                'requested_spoof_values' => $this->spoofingRequestedValues(),
                'expected_principal' => $anonymous,
                'observed_principal' => $anonymous,
                'caller_controlled_value_hits' => [],
            ],
            'anonymous_cancel' => [
                'action' => 'anonymous_cancel',
                'surface' => 'history_api',
                'auth_driver' => 'none',
                'credential_used' => null,
                'event_type' => 'WorkflowCancelled',
                'requested_spoof_values' => $this->spoofingRequestedValues(),
                'expected_principal' => $anonymous,
                'observed_principal' => $anonymous,
                'caller_controlled_value_hits' => [],
            ],
        ];

        return array_values(array_intersect_key($rows, array_flip($actions)));
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function spoofingRequestedValues(): array
    {
        return [
            'body_fields' => [
                'principal' => 'mallory',
                'principal_id' => 'mallory',
                'principal_type' => 'attacker',
                'actor' => 'mallory',
                'user' => 'mallory',
            ],
            'headers' => [
                'X-Workflow-Principal-Id' => 'mallory',
                'X-Workflow-Principal-Type' => 'attacker',
                'X-Workflow-Principal-Label' => 'Mallory',
                'X-Workflow-Caller-Type' => 'spoofed-gateway',
                'X-Workflow-Caller-Label' => 'Mallory Gateway',
                'X-Workflow-Auth-Status' => 'trusted_elsewhere',
                'X-Workflow-Auth-Method' => 'gateway_token',
                'X-Forwarded-User' => 'mallory',
                'X-Forwarded-Email' => 'mallory@example.invalid',
                'X-Remote-User' => 'mallory',
                'Authorization-Override' => 'Bearer mallory',
            ],
        ];
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
