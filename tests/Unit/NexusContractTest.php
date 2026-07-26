<?php

namespace Tests\Unit;

use App\Support\NexusContract;
use PHPUnit\Framework\TestCase;

class NexusContractTest extends TestCase
{
    public function test_manifest_publishes_schema_version_and_authority(): void
    {
        $manifest = NexusContract::manifest();

        $this->assertSame('durable-workflow.v2.nexus.contract', $manifest['schema']);
        $this->assertSame(2, $manifest['version']);
        $this->assertSame('docs/contracts/nexus.md', $manifest['authority_document']);
        $this->assertSame('nexus_contract', $manifest['cluster_info_key']);
        $this->assertSame('nexus', $manifest['capability_flag']);
        $this->assertSame('durable-workflow.v2.nexus-runtime.result', $manifest['result_schema']);
    }

    public function test_manifest_names_the_temporal_parity_target_and_underlying_contract(): void
    {
        $manifest = NexusContract::manifest();

        $this->assertSame('Nexus', $manifest['parity_target']['name']);
        $this->assertTrue($manifest['parity_target']['replaces_per_pair_integration']);
        $this->assertSame(
            'durable-workflow.v2.service-execution.contract',
            $manifest['underlying_execution_contract'],
        );
    }

    public function test_manifest_lists_the_addressing_fields_callers_use(): void
    {
        $manifest = NexusContract::manifest();

        $this->assertSame('endpoint_name', $manifest['addressing']['endpoint_field']);
        $this->assertSame('service_name', $manifest['addressing']['service_field']);
        $this->assertSame('operation_name', $manifest['addressing']['operation_field']);
        $this->assertSame('caller_namespace', $manifest['addressing']['caller_namespace_field']);
        $this->assertSame('target_namespace', $manifest['addressing']['target_namespace_field']);
        $this->assertSame('caller_workflow_instance_id', $manifest['addressing']['caller_workflow_instance_field']);
        $this->assertSame('caller_workflow_run_id', $manifest['addressing']['caller_workflow_run_field']);
        $this->assertSame('idempotency_key', $manifest['addressing']['idempotency_field']);
        $this->assertSame('service_call_id', $manifest['addressing']['durable_call_id_field']);
    }

    public function test_manifest_publishes_the_wire_surface_routes_sdks_target(): void
    {
        $wire = NexusContract::manifest()['wire_surface'];

        $this->assertStringContainsString(
            '/api/service-endpoints/{endpoint}/services/{service}/operations/{operation}/execute',
            $wire['invoke_operation'],
        );
        $this->assertStringContainsString(
            '/api/service-endpoints/{endpoint}/services/{service}/operations/{operation}/service-calls/{serviceCallId}',
            $wire['describe_call'],
        );
        $this->assertStringContainsString(
            '/api/service-endpoints/{endpoint}/services/{service}/operations/{operation}/service-calls/{serviceCallId}/cancel',
            $wire['cancel_call'],
        );
        $this->assertStringContainsString(
            '/api/workflows/{workflowId}/runs/{runId}/nexus-operations',
            $wire['caller_history'],
        );
    }

    public function test_manifest_locks_the_lifecycle_outcome_and_mode_enumerations(): void
    {
        $manifest = NexusContract::manifest();

        $this->assertSame(['sync', 'async', 'sync_with_durable_reference'], $manifest['operation_modes']);
        $this->assertSame(
            ['pending', 'accepted', 'started', 'completed', 'failed', 'cancelled'],
            $manifest['lifecycle_statuses'],
        );

        foreach ([
            'accepted',
            'completed',
            'cancelled',
            'timed_out',
            'rejected_not_found',
            'rejected_forbidden',
            'rejected_throttled',
            'rejected_concurrency_limited',
            'rejected_circuit_open',
            'degraded',
            'handler_failed',
        ] as $outcome) {
            $this->assertContains($outcome, $manifest['outcomes']);
        }

        foreach ([
            'workflow_run',
            'workflow_update',
            'workflow_signal',
            'workflow_query',
            'activity_execution',
            'invocable_carrier_request',
        ] as $kind) {
            $this->assertContains($kind, $manifest['handler_binding_kinds']);
        }
    }

    public function test_manifest_describes_activity_style_retry_durability(): void
    {
        $retry = NexusContract::manifest()['retry_durability'];

        $this->assertSame('activity_style', $retry['retry_policy_shape']);
        $this->assertSame('durable_record_keyed_by_service_call_id', $retry['caller_recovery']);
        $this->assertSame('caller_replays_with_same_idempotency_key', $retry['idempotent_resume']);
        $this->assertSame(
            'caller_worker_resumes_by_service_call_id_after_restart',
            $retry['crash_recovery'],
        );
    }

    public function test_manifest_freezes_namespace_acl_enforcement_points(): void
    {
        $acl = NexusContract::manifest()['namespace_acl_enforcement'];

        $this->assertSame('authenticated_request_principal', $acl['principal_source']);
        $this->assertSame('App\\Support\\ServiceCallBoundary', $acl['admission_gate']);
        $this->assertSame('rejected_forbidden', $acl['rejection_outcome']);
        $this->assertSame('before_handler_dispatch', $acl['enforcement_phase']);
        $this->assertSame(
            'rejected_forbidden_when_principal_disallows',
            $acl['forging_caller_namespace'],
        );
        $this->assertSame(
            'workflow_service_calls.caller_principal_subject',
            $acl['audit_trail'],
        );
    }

    public function test_manifest_promises_multi_namespace_caller_pattern_without_per_caller_registration(): void
    {
        $pattern = NexusContract::manifest()['multi_namespace_caller_pattern'];

        $this->assertFalse($pattern['per_caller_registration_required']);
        $this->assertTrue($pattern['caller_namespaces_recorded_independently']);
        $this->assertTrue($pattern['fanout_supported']);
    }

    public function test_manifest_caller_history_surface_lists_debug_fields(): void
    {
        $surface = NexusContract::manifest()['caller_history_surface'];

        $this->assertStringContainsString(
            '/api/workflows/{workflowId}/runs/{runId}/nexus-operations',
            $surface['route'],
        );

        foreach ([
            'service_call_id',
            'caller_namespace',
            'target_namespace',
            'endpoint_name',
            'service_name',
            'operation_name',
            'status',
            'outcome',
            'outcome_metadata',
            'service_error_type',
            'caller_observed_error_type',
            'typed_error_message',
            'resolved_binding_kind',
            'resolved_target_reference',
            'retry_policy',
            'service_call_attempts',
            'retry_attempt_count',
            'failure_message',
            'caller_principal_subject',
            'accepted_at',
            'completed_at',
            'failed_at',
        ] as $field) {
            $this->assertContains($field, $surface['response_fields']);
        }
    }

    public function test_manifest_documents_out_of_scope_surfaces(): void
    {
        $manifest = NexusContract::manifest();

        $this->assertArrayHasKey('general_service_mesh', $manifest['out_of_scope']);
        $this->assertArrayHasKey('arbitrary_external_http', $manifest['out_of_scope']);
    }

    public function test_manifest_publishes_host_runner_contract_for_nexus_coverage(): void
    {
        $manifest = NexusContract::manifest();

        $this->assertContains('sdk-php', $manifest['required_matrix']['caller_runtimes']);
        $this->assertContains('sdk-python', $manifest['required_matrix']['service_runtimes']);
        $this->assertContains('tenant_a_calls_shared_service', $manifest['required_scenarios']);
        $this->assertContains('worker_restart_replay_does_not_reissue_call', $manifest['required_scenarios']);
        $this->assertContains('endpoint_permission_denied_without_information_leak', $manifest['required_scenarios']);
        $this->assertSame(
            $manifest['required_scenarios'],
            array_keys($manifest['scenario_evidence_requirements']),
            'every required Nexus scenario must declare pass evidence requirements',
        );
        $this->assertContains(
            'retry_attempts',
            $manifest['scenario_evidence_requirements']['transient_failure_retries_with_policy'],
        );
        $this->assertContains(
            'caller_history_attempts',
            $manifest['scenario_evidence_requirements']['caller_history_attempt_visibility'],
        );
        $this->assertContains(
            'request',
            $manifest['scenario_evidence_requirements']['tenant_a_calls_shared_service'],
        );
        $this->assertContains(
            'response',
            $manifest['scenario_evidence_requirements']['tenant_a_calls_shared_service'],
        );
        $this->assertContains(
            'service_call_record',
            $manifest['scenario_evidence_requirements']['tenant_b_calls_shared_service'],
        );
        $this->assertContains(
            'caller_history_evidence',
            $manifest['scenario_evidence_requirements']['tenant_b_calls_shared_service'],
        );
        $this->assertContains(
            'duplicate_call_assertion',
            $manifest['scenario_evidence_requirements']['worker_restart_replay_does_not_reissue_call'],
        );
        $this->assertContains(
            'published_artifact_worker_execution',
            $manifest['scenario_evidence_requirements']['worker_restart_replay_does_not_reissue_call'],
        );
        $this->assertContains(
            'service_invocation_count',
            $manifest['scenario_evidence_requirements']['worker_restart_replay_does_not_reissue_call'],
        );
        $this->assertContains(
            'caller_history_rows',
            $manifest['scenario_evidence_requirements']['caller_cancellation_propagates_to_service'],
        );
        $this->assertContains(
            'within_propagation_window',
            $manifest['scenario_evidence_requirements']['caller_cancellation_propagates_to_service'],
        );
        $this->assertContains(
            'published_artifact_worker_execution',
            $manifest['scenario_evidence_requirements']['caller_cancellation_propagates_to_service'],
        );
        foreach ([
            'php_caller_python_service',
            'python_caller_php_service',
        ] as $crossLanguageScenario) {
            foreach ([
                'caller_workflow_instance_id',
                'caller_workflow_run_id',
                'caller_sdk_language',
                'service_sdk_language',
                'operation_name',
                'request_payload',
                'response_or_failure_surface',
                'service_call_id',
                'artifact_tuple',
                'published_artifact_worker_execution',
                'service_health',
                'service_probe_succeeded',
                'service_response_payload',
                'payload_round_trip',
                'typed_error_probe_succeeded',
                'typed_error_round_trip',
            ] as $requiredField) {
                $this->assertContains(
                    $requiredField,
                    $manifest['scenario_evidence_requirements'][$crossLanguageScenario],
                );
            }
        }
        $this->assertContains(
            'authorization_refusal_disclosed_endpoint_existence',
            $manifest['scenario_evidence_requirements']['endpoint_permission_denied_without_information_leak'],
        );
        foreach ([
            'endpoint_permission_denied_without_information_leak',
            'malformed_payload_refused_before_dispatch',
            'nonexistent_endpoint_typed_not_found',
        ] as $refusalScenario) {
            $this->assertContains(
                'caller_history_evidence',
                $manifest['scenario_evidence_requirements'][$refusalScenario],
            );
            $this->assertContains(
                'caller_history_query_succeeded',
                $manifest['scenario_evidence_requirements'][$refusalScenario],
            );
            $this->assertContains(
                'caller_history_state_proven',
                $manifest['scenario_evidence_requirements'][$refusalScenario],
            );
            $this->assertContains(
                'service_invoked',
                $manifest['scenario_evidence_requirements'][$refusalScenario],
            );
        }
        $this->assertContains(
            'request',
            $manifest['scenario_evidence_requirements']['endpoint_permission_denied_without_information_leak'],
        );
        $this->assertContains(
            'response',
            $manifest['scenario_evidence_requirements']['malformed_payload_refused_before_dispatch'],
        );
        $this->assertContains(
            'dispatch_evidence',
            $manifest['scenario_evidence_requirements']['nonexistent_endpoint_typed_not_found'],
        );
        $this->assertContains(
            'artifact_source_verification',
            $manifest['scenario_evidence_requirements']['published_artifact_install_only'],
        );
        $this->assertContains(
            'artifact_install_evidence',
            $manifest['scenario_evidence_requirements']['published_artifact_install_only'],
        );
        $this->assertContains(
            'runner_blocked_false_for_product_evidence',
            $manifest['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertSame(
            ['server', 'cli', 'workflow', 'sdk-php', 'sdk-python', 'waterline'],
            $manifest['artifact_policy']['required_artifacts'],
        );
        $this->assertContains(
            'local_product_source_checkouts_used',
            $manifest['artifact_policy']['required_run_record_fields'],
        );
        $this->assertContains(
            'artifact_sources',
            $manifest['artifact_policy']['required_run_record_fields'],
        );
        $this->assertContains(
            'artifact_source_verification',
            $manifest['artifact_policy']['required_run_record_fields'],
        );
        $this->assertContains(
            'artifact_install_evidence',
            $manifest['artifact_policy']['required_run_record_fields'],
        );
        $this->assertSame(
            'https://github.com/durable-workflow/cli/releases/download/<exact tag>/<release asset>',
            $manifest['artifact_policy']['source_channel_policy']['cli'],
        );
        $this->assertSame(
            'packagist://durable-workflow/waterline@<exact version>',
            $manifest['artifact_policy']['source_channel_policy']['waterline'],
        );
        $this->assertContains(
            'artifact_sources_recorded_for_every_required_artifact',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'artifact_source_verification_proves_each_source_resolves',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'published_artifact_install_evidence_reported',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'install_artifact_tuple_matches_top_level_resolved_tuple',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'source_free_published_artifact_evidence_is_explicit',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'no_artifact_policy_failures',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'local_product_source_checkouts_used_false',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'shared_service_tenant_cells_attach_request_response_service_call_and_history',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'replay_and_cancellation_cells_attach_published_worker_execution',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'php_python_service_shards_return_valid_health',
            $manifest['result_gate']['pass_requires'],
        );

        $hostRunner = $manifest['host_runner_contract'];
        $this->assertSame('required_for_passing_nexus_conformance', $hostRunner['status']);
        $this->assertSame('scripts/conformance/nexus-published-artifacts.sh', $hostRunner['runner_path']);
        $this->assertContains('nexus-conformance-record.json', $hostRunner['result_files']);
        $this->assertTrue($hostRunner['must_execute_against_published_artifacts']);
        $this->assertTrue($hostRunner['must_record_runner_blocked_false_for_product_evidence']);
        $this->assertContains('php-python-runtime-matrix', $hostRunner['required_execution_scopes']);
        $this->assertContains(
            'worker_restart_replay_does_not_reissue_call',
            $hostRunner['runtime_shards']['workflow-php']['must_cover_scenarios'],
        );
        $this->assertContains(
            'worker_restart_replay_does_not_reissue_call',
            $hostRunner['runtime_shards']['server']['must_cover_scenarios'],
        );
        $this->assertContains(
            'caller_cancellation_propagates_to_service',
            $hostRunner['runtime_shards']['server']['must_cover_scenarios'],
        );
        $this->assertSame(
            'conformance_runner_coverage_gap',
            $hostRunner['routing_policy']['missing_required_scenario']['finding_type'],
        );
    }

    public function test_host_runner_script_is_present_and_records_non_blocked_coverage_evidence(): void
    {
        $manifest = NexusContract::manifest();
        $script = dirname(__DIR__, 2).'/'.$manifest['host_runner_contract']['runner_path'];

        $this->assertFileExists($script);
        $contents = (string) file_get_contents($script);
        $this->assertStringContainsString('DW_NEXUS_EVIDENCE_JSON', $contents);
        $this->assertStringContainsString('DW_NEXUS_ARTIFACT_INSTALL_EVIDENCE', $contents);
        $this->assertStringContainsString('runner_blocked: runnerBlocked', $contents);
        $this->assertStringContainsString('runnerBlocked,', $contents);
        $this->assertStringContainsString('conformance_runner_coverage_gap', $contents);
        $this->assertStringContainsString('DW_NEXUS_SKIP_SHARED_SERVICE_PROBE', $contents);
        $this->assertStringContainsString('should_probe_shared_service', $contents);
        $this->assertStringContainsString('merged-shared-service-evidence.json', $contents);
        $this->assertStringContainsString('supplied_evidence_path', $contents);
        $this->assertStringContainsString('setupSharedService', $contents);
        $this->assertStringContainsString('invokeSharedService', $contents);
        $this->assertStringContainsString('probeTransientFailureRetries', $contents);
        $this->assertStringContainsString('greet-retry', $contents);
        $this->assertStringContainsString('TransientGreetingFailure', $contents);
        $this->assertStringContainsString('history_attempt_visibility_includes_retry_attempts', $contents);
        $this->assertStringContainsString('final_successful_result', $contents);
        $this->assertStringContainsString('probePermanentFailurePreservesTypedError', $contents);
        $this->assertStringContainsString('greet-permanent', $contents);
        $this->assertStringContainsString('SharedGreeterUnavailable', $contents);
        $this->assertStringContainsString('probeWorkerRestartReplay', $contents);
        $this->assertStringContainsString('probeCallerCancellation', $contents);
        $this->assertStringContainsString('duplicate_call_issue_count', $contents);
        $this->assertStringContainsString('cancellation_propagation_ms', $contents);
        $this->assertStringContainsString('caller_history_recorded: true', $contents);
        $this->assertStringContainsString('caller_history_query_succeeded', $contents);
        $this->assertStringContainsString('caller_history_state_proven', $contents);
        $this->assertStringContainsString('handler_failed', $contents);
        $this->assertStringContainsString('verifyGithubReleaseAsset', $contents);
        $this->assertStringContainsString('verifyPackagistPackage', $contents);
        $this->assertStringContainsString('verifyPypiPackage', $contents);
        $this->assertStringContainsString('DW_NEXUS_SKIP_PHP_PYTHON_SERVICE_SHARD', $contents);
        $this->assertStringContainsString('probePublishedPhpPythonServiceCalls', $contents);
        $this->assertStringContainsString('published_php_python_service_call_shard', $contents);
        $this->assertStringContainsString('nexus-python-sdk-service', $contents);
        $this->assertStringContainsString('nexus-php-workflow-service', $contents);
        $this->assertStringContainsString('validServiceHealthResponse', $contents);
        $this->assertStringContainsString('service_health', $contents);
        $this->assertStringContainsString('nexus_published_service_health_failed', $contents);
        $this->assertStringContainsString('startServiceOperation', $contents);
        $this->assertStringContainsString('executeServiceOperation', $contents);
        $this->assertStringContainsString('serviceOperation', $contents);
        $this->assertStringContainsString('call_nexus_service', $contents);
        $this->assertStringContainsString('start_nexus_operation', $contents);
        $this->assertStringContainsString('reflectedPublicServiceCallSurface', $contents);
        $this->assertStringContainsString('service_call_methods', $contents);
        $this->assertStringContainsString('callerWorkflowInvocationEvidence', $contents);
        $this->assertStringContainsString('dockerExecJson', $contents);
        $this->assertStringContainsString('reflectPublishedPythonSdkSurface', $contents);
        $this->assertStringContainsString('reflectPublishedPhpWorkflowSurface', $contents);
        $this->assertStringContainsString('caller_reflection_response', $contents);
        $this->assertStringContainsString('container_reflection', $contents);
        $this->assertStringContainsString('crossLanguageRuntimeObject', $contents);
        $this->assertStringContainsString('crossLanguageRuntimeArray', $contents);
        $this->assertStringContainsString('values.find(publicSurfaceAvailable)', $contents);
        $this->assertStringContainsString('values.find((value) => value.length > 0)', $contents);
        $this->assertStringContainsString("crossLanguageRuntimeObject('service_runtime_surface', pythonHealth, pythonProbe, pythonReflection)", $contents);
        $this->assertStringContainsString("crossLanguageRuntimeObject('service_runtime_surface', phpHealth, phpProbe, phpReflection)", $contents);
        $this->assertStringContainsString("crossLanguageRuntimeObject('public_service_call_surface', pythonHealth, pythonProbe, pythonReflection)", $contents);
        $this->assertStringContainsString("crossLanguageRuntimeObject('public_service_call_surface', phpHealth, phpProbe, phpReflection)", $contents);
        $this->assertStringContainsString("crossLanguageRuntimeArray('service_call_methods', phpHealth, phpProbe, phpReflection)", $contents);
        $this->assertStringContainsString('InvocableHttpAdapter', $contents);
        $this->assertStringContainsString('InvocableActivityHandler', $contents);
        $this->assertStringContainsString("'headers' => (object) [],", $contents);
        $this->assertStringContainsString('const serviceRuntimeAvailable = serviceHealthSucceeded', $contents);
        $this->assertStringContainsString('durableServiceResponseObserved = serviceRuntimeAvailable', $contents);
        $this->assertStringContainsString('service_probe_succeeded: serviceProbeSucceeded', $contents);
        $this->assertStringContainsString('service_response_payload: responsePayload', $contents);
        $this->assertStringContainsString('typed_error_probe_succeeded: typedErrorProbeSucceeded', $contents);
        $this->assertStringContainsString('nexus_published_service_invocation_failed', $contents);
        $this->assertStringNotContainsString('payload_round_trip: true', $contents);
        $this->assertStringNotContainsString('typed_error_round_trip: true', $contents);
        $this->assertStringContainsString('service_runtime_available: serviceRuntimeAvailable', $contents);
        $this->assertStringContainsString('nexus_unsupported_surface', $contents);

        $this->assertMatchesRegularExpression(
            "/if node - \\\"\\\$result_dir\\\" \\\"\\\$generated_evidence_path\\\" \\\"\\\$supplied_evidence_path\\\" \\\"\\\$script_dir\/nexus-replay-transport\.cjs\\\" <<'NODE'\\n(?P<block>.*?)\\nNODE/s",
            $contents,
        );
        preg_match(
            "/if node - \\\"\\\$result_dir\\\" \\\"\\\$generated_evidence_path\\\" \\\"\\\$supplied_evidence_path\\\" \\\"\\\$script_dir\/nexus-replay-transport\.cjs\\\" <<'NODE'\\n(?P<block>.*?)\\nNODE/s",
            $contents,
            $sharedServiceBlock,
        );
        $this->assertStringContainsString('function stringValue(value)', $sharedServiceBlock['block']);
        $this->assertStringContainsString('reflectedPublicServiceCallSurface', $sharedServiceBlock['block']);
        $this->assertStringContainsString('probePermanentFailurePreservesTypedError', $sharedServiceBlock['block']);

        preg_match(
            "/node - \\\"\\\$supplied_evidence_path\\\" \\\"\\\$generated_evidence_path\\\" \\\"\\\$merged_evidence_path\\\" <<'NODE'\\n(?P<block>.*?)\\nNODE/s",
            $contents,
            $mergeBlock,
        );
        $this->assertStringContainsString("'permanent_failure_preserves_typed_error'", $mergeBlock['block']);
        $this->assertStringNotContainsString('version_pin_recorded', $contents);
    }

    public function test_host_runner_allows_pass_when_all_artifacts_are_pinned_and_source_free(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $result = $this->runNexusEvidence($this->completeRunnerEvidence(), 'dw-nexus-pass-');

        $this->assertSame('pass', $result['outcome']);
        $this->assertFalse($result['runner_blocked']);
        $this->assertFalse($result['local_product_source_checkouts_used']);
        $this->assertSame([], $result['artifact_policy_failures']);
        $this->assertSame('pass', $result['scenario_results'][0]['status']);
        $installChannels = array_column(
            $result['artifact_install_evidence']['artifacts'],
            'install_channel',
            'artifact',
        );
        $this->assertSame(
            'packagist',
            $installChannels['sdk-php'],
        );
    }

    public function test_host_runner_accepts_equivalent_python_release_identities_in_pypi_sources(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $cases = [
            'semver expected with PEP 440 distribution URL' => [
                '2.0.0-beta.18',
                'https://files.pythonhosted.org/packages/ab/cd/durable_workflow-2.0.0b18-py3-none-any.whl',
            ],
            'PEP 440 expected with semver project URL' => [
                '2.0.0b18',
                'https://pypi.org/project/durable-workflow/2.0.0-beta.18/',
            ],
            'exact stable identity' => [
                '2.0.0',
                'https://pypi.io/packages/ab/cd/durable-workflow-2.0.0.tar.gz',
            ],
        ];

        foreach ($cases as $case => [$pythonVersion, $pythonSource]) {
            $result = $this->runNexusEvidence(
                $this->completeRunnerEvidence($pythonVersion, $pythonSource),
                'dw-nexus-python-source-identity-',
            );

            $this->assertSame(
                'pass',
                $result['outcome'],
                $case.': '.json_encode([
                    'artifact_policy_failures' => $result['artifact_policy_failures'],
                    'non_pass_scenarios' => array_values(array_filter(
                        $result['scenario_results'],
                        static fn (array $scenario): bool => $scenario['status'] !== 'pass',
                    )),
                ], JSON_THROW_ON_ERROR),
            );
            $this->assertSame([], $result['artifact_policy_failures'], $case);
        }
    }

    public function test_host_runner_accepts_equivalent_python_identity_across_resolution_install_and_runtime_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $declaredVersion = '2.0.0-beta.18';
        $observedVersion = '2.0.0b18';
        $pythonSource = 'https://files.pythonhosted.org/packages/ab/cd/durable_workflow-2.0.0b18-py3-none-any.whl';
        $evidence = $this->withObservedPythonArtifactVersion(
            $this->completeRunnerEvidence($declaredVersion, $pythonSource),
            $observedVersion,
        );

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-python-cross-layer-identity-');

        $this->assertSame(
            'pass',
            $result['outcome'],
            json_encode([
                'artifact_policy_failures' => $result['artifact_policy_failures'],
                'non_pass_scenarios' => array_values(array_filter(
                    $result['scenario_results'],
                    static fn (array $scenario): bool => $scenario['status'] !== 'pass',
                )),
            ], JSON_THROW_ON_ERROR),
        );
        $this->assertSame([], $result['artifact_policy_failures']);
        $this->assertSame(
            'pass',
            $this->scenarioResult($result, 'published_artifact_install_only')['status'],
        );
        $this->assertSame(
            'pass',
            $this->scenarioResult($result, 'php_caller_python_service')['status'],
        );
    }

    public function test_host_runner_rejects_distinct_python_identities_across_resolution_install_and_runtime_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $declaredVersion = '2.0.0-beta.18';
        $pythonSource = 'https://files.pythonhosted.org/packages/ab/cd/durable_workflow-2.0.0b18-py3-none-any.whl';
        $cases = [
            'different beta number' => ['2.0.0b11', 'published_artifact_install_evidence_version_mismatch'],
            'post release' => ['2.0.0b18.post1', 'invalid_published_artifact_install_evidence_version'],
            'development release' => ['2.0.0b18.dev1', 'invalid_published_artifact_install_evidence_version'],
            'local release' => ['2.0.0b18+local', 'invalid_published_artifact_install_evidence_version'],
        ];

        foreach ($cases as $case => [$observedVersion, $installFailureCode]) {
            $evidence = $this->withObservedPythonArtifactVersion(
                $this->completeRunnerEvidence($declaredVersion, $pythonSource),
                $observedVersion,
            );

            $result = $this->runNexusEvidence(
                $evidence,
                'dw-nexus-invalid-python-cross-layer-',
            );
            $pythonServiceScenario = $this->scenarioResult($result, 'php_caller_python_service');

            $this->assertSame('fail', $result['outcome'], $case);
            $this->assertTrue($this->hasArtifactPolicyFailure(
                $result,
                'sdk-python',
                'artifact_source_verification',
                'published_artifact_resolution_version_mismatch',
                $observedVersion,
            ), $case);
            $this->assertTrue($this->hasArtifactPolicyFailure(
                $result,
                'sdk-python',
                'artifact_install_evidence.artifacts.version',
                $installFailureCode,
                $observedVersion,
            ), $case);
            $this->assertTrue($this->hasArtifactPolicyFailure(
                $result,
                'sdk-python',
                'artifact_versions',
                'install_artifact_version_mismatch',
                $observedVersion,
                '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_versions',
            ), $case);
            $this->assertTrue($this->hasArtifactPolicyFailure(
                $result,
                'sdk-python',
                'artifact_source_verification',
                'install_artifact_source_verification_version_mismatch',
                $observedVersion,
                '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_source_verification',
            ), $case);
            $this->assertSame('fail', $pythonServiceScenario['status'], $case);
            $this->assertContains(
                'service_health',
                array_column($pythonServiceScenario['observed_outputs']['scenario_evidence_failures'], 'field'),
                $case,
            );
        }
    }

    public function test_host_runner_preserves_literal_version_identity_for_non_python_resolution_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        $evidence['artifact_source_verification']['workflow']['version'] = '2.0.0a190';

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-non-python-literal-identity-');

        $this->assertSame('fail', $result['outcome']);
        $this->assertTrue($this->hasArtifactPolicyFailure(
            $result,
            'workflow',
            'artifact_source_verification',
            'published_artifact_resolution_version_mismatch',
            '2.0.0a190',
        ));
    }

    public function test_host_runner_rejects_distinct_or_unrelated_python_artifact_sources(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $cases = [
            'mismatched prerelease number' => [
                '2.0.0-beta.18',
                'https://files.pythonhosted.org/packages/ab/cd/durable_workflow-2.0.0b11-py3-none-any.whl',
            ],
            'post release wheel' => [
                '2.0.0-beta.18',
                'https://files.pythonhosted.org/packages/ab/cd/durable_workflow-2.0.0b18.post1-py3-none-any.whl',
            ],
            'development release sdist' => [
                '2.0.0-beta.18',
                'https://files.pythonhosted.org/packages/ab/cd/durable_workflow-2.0.0b18.dev1.tar.gz',
            ],
            'local release wheel' => [
                '2.0.0-beta.18',
                'https://files.pythonhosted.org/packages/ab/cd/durable_workflow-2.0.0b18+local-py3-none-any.whl',
            ],
            'unrelated package source' => [
                '2.0.0-beta.18',
                'https://files.pythonhosted.org/packages/ab/cd/unrelated-2.0.0b18-py3-none-any.whl',
            ],
        ];

        foreach ($cases as $case => [$pythonVersion, $pythonSource]) {
            $result = $this->runNexusEvidence(
                $this->completeRunnerEvidence($pythonVersion, $pythonSource),
                'dw-nexus-invalid-python-source-',
            );

            $this->assertSame('fail', $result['outcome'], $case);
            $this->assertTrue($this->hasArtifactPolicyFailure(
                $result,
                'sdk-python',
                'artifact_sources',
                'invalid_published_artifact_source',
                $pythonSource,
            ), $case);
        }
    }

    public function test_host_runner_derives_caller_history_attempt_visibility_from_retry_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        $evidence['scenario_results'] = array_values(array_filter(
            $evidence['scenario_results'],
            static fn (array $scenario): bool => ($scenario['scenario_id'] ?? null) !== 'caller_history_attempt_visibility',
        ));
        foreach ($evidence['scenario_results'] as &$scenario) {
            if (($scenario['scenario_id'] ?? null) !== 'transient_failure_retries_with_policy') {
                continue;
            }

            $scenario['observed_outputs'] += [
                'caller_workflow_instance_id' => 'tenant-a-retry-proof',
                'caller_workflow_run_id' => '01JRETRYPROOF0000000000',
                'caller_history_attempts' => [
                    ['attempt' => 1, 'outcome' => 'handler_failed'],
                    ['attempt' => 2, 'outcome' => 'handler_failed'],
                    ['attempt' => 3, 'outcome' => 'completed'],
                ],
                'service_call_detail_attempts' => [
                    ['attempt' => 1, 'outcome' => 'handler_failed'],
                    ['attempt' => 2, 'outcome' => 'handler_failed'],
                    ['attempt' => 3, 'outcome' => 'completed'],
                ],
                'final_successful_result' => 'hello, world after retry',
            ];
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-derived-caller-history-');
        $scenario = $this->scenarioResult($result, 'caller_history_attempt_visibility');

        $this->assertSame('pass', $result['outcome']);
        $this->assertSame('pass', $scenario['status']);
        $this->assertSame(
            'transient_failure_retries_with_policy',
            $scenario['observed_outputs']['derived_from_scenario'],
        );
        $this->assertSame('tenant-a-retry-proof', $scenario['observed_outputs']['caller_workflow_instance_id']);
    }

    public function test_host_runner_does_not_probe_complete_aliased_shared_service_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        $fieldAliases = [
            'caller_namespace' => 'callerNamespace',
            'target_namespace' => 'targetNamespace',
            'endpoint_name' => 'endpointName',
            'service_name' => 'serviceName',
            'operation_name' => 'operationName',
            'service_call_id' => 'serviceCallId',
            'workflow_result' => 'workflowResult',
            'request' => 'requestEvidence',
            'response' => 'responseEvidence',
            'service_call_record' => 'serviceCallRecord',
            'caller_history_evidence' => 'callerHistoryEvidence',
            'caller_history_recorded' => 'callerHistoryRecorded',
        ];

        foreach ($evidence['scenario_results'] as &$scenario) {
            if (! in_array($scenario['scenario_id'] ?? null, [
                'tenant_a_calls_shared_service',
                'tenant_b_calls_shared_service',
            ], true)) {
                continue;
            }

            foreach ($fieldAliases as $canonical => $alias) {
                $scenario['observed_outputs'][$alias] = $scenario['observed_outputs'][$canonical];
                unset($scenario['observed_outputs'][$canonical]);
            }
        }
        unset($scenario);

        $record = null;
        $resultFiles = null;
        $result = $this->runNexusEvidence(
            $evidence,
            'dw-nexus-aliased-shared-service-',
            null,
            null,
            $record,
            $resultFiles,
            false,
        );

        $this->assertSame('pass', $result['outcome']);
        $this->assertFalse($result['runner_blocked']);
        $this->assertIsArray($resultFiles);
        $this->assertNotContains('shared-service-evidence.json', $resultFiles);
        $this->assertNotContains('merged-shared-service-evidence.json', $resultFiles);
    }

    public function test_host_runner_accepts_canonical_cross_language_response_or_failure_surface(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        foreach ($evidence['scenario_results'] as &$scenario) {
            if (! in_array($scenario['scenario_id'] ?? null, [
                'php_caller_python_service',
                'python_caller_php_service',
            ], true)) {
                continue;
            }

            unset(
                $scenario['observed_outputs']['response'],
                $scenario['observed_outputs']['responseEvidence'],
                $scenario['observed_outputs']['invocation_response'],
                $scenario['observed_outputs']['invocationResponse'],
                $scenario['observed_outputs']['failure_surface'],
                $scenario['observed_outputs']['failureSurface'],
            );
            $scenario['observed_outputs']['response_or_failure_surface'] = [
                'status' => 'completed',
                'body' => [
                    'service_call_id' => $scenario['observed_outputs']['service_call_id'],
                    'round_trip' => true,
                ],
            ];
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-cross-language-canonical-response-');

        $this->assertSame('pass', $result['outcome']);
        foreach ([
            'php_caller_python_service',
            'python_caller_php_service',
        ] as $scenarioId) {
            $scenario = $this->scenarioResult($result, $scenarioId);

            $this->assertSame('pass', $scenario['status']);
            $this->assertArrayHasKey('response_or_failure_surface', $scenario['observed_outputs']);
            $this->assertArrayHasKey('artifact_tuple', $scenario['observed_outputs']);
        }
    }

    public function test_host_runner_rejects_cross_language_cell_without_both_published_workers(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        foreach ($evidence['scenario_results'] as &$scenario) {
            if (($scenario['scenario_id'] ?? null) !== 'php_caller_python_service') {
                continue;
            }

            $scenario['observed_outputs']['published_artifact_worker_execution'] = $this->publishedWorkerExecution(
                $evidence['artifact_versions'],
                $evidence['artifact_sources'],
            );
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-cross-language-php-only-worker-');
        $scenario = $this->scenarioResult($result, 'php_caller_python_service');

        $this->assertSame('fail', $result['outcome']);
        $this->assertSame('fail', $scenario['status']);
        $this->assertContains(
            'published_artifact_worker_execution',
            array_column($scenario['observed_outputs']['scenario_evidence_failures'], 'field'),
        );
    }

    public function test_host_runner_reports_missing_cross_language_response_surface_as_coverage_gap(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        foreach ($evidence['scenario_results'] as &$scenario) {
            if (($scenario['scenario_id'] ?? null) !== 'php_caller_python_service') {
                continue;
            }

            unset(
                $scenario['observed_outputs']['response_or_failure_surface'],
                $scenario['observed_outputs']['responseOrFailureSurface'],
                $scenario['observed_outputs']['response'],
                $scenario['observed_outputs']['responseEvidence'],
                $scenario['observed_outputs']['invocation_response'],
                $scenario['observed_outputs']['invocationResponse'],
                $scenario['observed_outputs']['failure_surface'],
                $scenario['observed_outputs']['failureSurface'],
            );
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-cross-language-missing-response-');
        $scenario = $this->scenarioResult($result, 'php_caller_python_service');

        $this->assertSame('fail', $result['outcome']);
        $this->assertSame('not_covered', $scenario['status']);
        $this->assertContains(
            'response_or_failure_surface',
            array_column($scenario['observed_outputs']['scenario_evidence_failures'], 'field'),
        );
        $this->assertContains(
            'conformance_runner_coverage_gap',
            array_column($scenario['linked_findings'], 'finding_type'),
        );
    }

    public function test_host_runner_rejects_php_service_cell_without_valid_health_response(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        foreach ($evidence['scenario_results'] as &$scenario) {
            if (($scenario['scenario_id'] ?? null) !== 'python_caller_php_service') {
                continue;
            }

            $scenario['observed_outputs']['service_health'] = [
                'sdk_language' => 'workflow-php',
                'endpoint' => '/health',
                'health_succeeded' => false,
                'package_version' => $evidence['artifact_versions']['workflow'],
                'health_response' => [
                    'ok' => false,
                    'status' => 500,
                    'body' => [
                        'runtime' => 'workflow-php',
                        'package_imported' => false,
                        'service_started' => false,
                        'package_version' => $evidence['artifact_versions']['workflow'],
                        'error' => 'PHP parse error before health response',
                    ],
                ],
                'local_product_source_checkouts_used' => false,
            ];
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-php-service-health-failed-');
        $scenario = $this->scenarioResult($result, 'python_caller_php_service');

        $this->assertSame('fail', $result['outcome']);
        $this->assertSame('fail', $scenario['status']);
        $this->assertContains(
            'service_health',
            array_column($scenario['observed_outputs']['scenario_evidence_failures'], 'field'),
        );
        $this->assertContains(
            'nexus_published_service_health_failed',
            array_column($scenario['linked_findings'], 'finding_type'),
        );
    }

    public function test_host_runner_rejects_non_successful_published_php_service_invocation(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        foreach ($evidence['scenario_results'] as &$scenario) {
            if (($scenario['scenario_id'] ?? null) !== 'python_caller_php_service') {
                continue;
            }

            $failedProbe = [
                'ok' => false,
                'status' => 400,
                'body' => [
                    'error' => 'invalid_invocable_request',
                    'message' => 'External task input field [headers] must be an object.',
                ],
            ];
            $scenario['observed_outputs']['service_probe_succeeded'] = false;
            $scenario['observed_outputs']['response_or_failure_surface']['status'] = 'failed';
            $scenario['observed_outputs']['response_or_failure_surface']['service_probe_response'] = $failedProbe;
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-php-service-invocation-failed-');
        $scenario = $this->scenarioResult($result, 'python_caller_php_service');

        $this->assertSame('fail', $result['outcome']);
        $this->assertSame('fail', $scenario['status']);
        $this->assertContains(
            'service_probe_succeeded',
            array_column($scenario['observed_outputs']['scenario_evidence_failures'], 'field'),
        );
        $this->assertContains(
            'nexus_published_service_invocation_failed',
            array_column($scenario['linked_findings'], 'finding_type'),
        );
        $this->assertContains('workflow', array_column($scenario['linked_findings'], 'owning_surface'));
    }

    public function test_host_runner_preserves_cross_language_attempted_call_unsupported_findings(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        $unsupportedSurfaces = [
            'php_caller_python_service' => [
                'owner' => 'sdk-php',
                'message' => 'published sdk-php lacks the public DurableWorkflow\\Client Nexus service-operation API',
            ],
            'python_caller_php_service' => [
                'owner' => 'sdk-python',
                'message' => 'published sdk-python lacks a public workflow-safe Nexus service-call caller API on durable_workflow.client.Client or durable_workflow.workflow.WorkflowContext',
            ],
        ];

        foreach ($evidence['scenario_results'] as &$scenario) {
            $scenarioId = $scenario['scenario_id'] ?? '';
            if (! isset($unsupportedSurfaces[$scenarioId])) {
                continue;
            }

            $message = $unsupportedSurfaces[$scenarioId]['message'];
            $serviceCallId = 'svc-'.$scenarioId;
            $executeResponse = [
                'status' => 202,
                'body' => [
                    'accepted' => true,
                    'service_call_id' => $serviceCallId,
                ],
            ];
            $probeResponse = [
                'status' => 200,
                'body' => [
                    'service_started' => true,
                    'package_imported' => true,
                ],
            ];

            $scenario['status'] = 'unsupported';
            $scenario['observed_outputs']['service_call_id'] = $serviceCallId;
            $scenario['observed_outputs']['response_or_failure_surface'] = [
                'status' => 'unsupported',
                'execute_response' => $executeResponse,
                'service_probe_response' => $probeResponse,
                'missing_public_surface' => $message,
            ];
            $scenario['observed_outputs']['attempted_call_evidence'] = [
                'execute_request' => [
                    'method' => 'POST',
                    'path' => '/api/service-endpoints/published-service/services/PublishedGreeter/operations/greet/execute',
                ],
                'execute_response' => $executeResponse,
                'service_probe_response' => $probeResponse,
                'durable_service_call_id_observed' => true,
                'missing_public_surface' => $message,
            ];
            $workerExecution = $this->publishedCrossLanguageWorkerExecution(
                $evidence['artifact_versions'],
                $evidence['artifact_sources'],
            );
            $workerExecution['worker_execution_mode'] = 'published_php_python_service_call_shard';
            $scenario['observed_outputs']['published_artifact_worker_execution'] = $workerExecution;
            $scenario['linked_findings'] = [
                [
                    'scenario_id' => $scenarioId,
                    'type' => 'nexus_unsupported_surface',
                    'finding_type' => 'nexus_unsupported_surface',
                    'owning_surface' => $unsupportedSurfaces[$scenarioId]['owner'],
                    'artifact_versions' => $evidence['artifact_versions'],
                    'observed_behavior' => $message.' after a concrete published-artifact call attempt.',
                    'expected_behavior' => 'The published caller SDK exposes a workflow-safe Nexus service-call API and the published service runtime handles the operation.',
                    'next_acceptance_criterion' => 'publish the missing Nexus service-call surface and rerun the published PHP/Python Nexus shard',
                ],
            ];
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-cross-language-unsupported-attempt-');

        $this->assertSame('fail', $result['outcome']);
        foreach (array_keys($unsupportedSurfaces) as $scenarioId) {
            $scenario = $this->scenarioResult($result, $scenarioId);

            $this->assertSame('unsupported', $scenario['status']);
            $this->assertArrayHasKey('attempted_call_evidence', $scenario['observed_outputs']);
            $this->assertSame(
                $unsupportedSurfaces[$scenarioId]['message'],
                $scenario['observed_outputs']['attempted_call_evidence']['missing_public_surface'],
            );
            $this->assertContains(
                'nexus_unsupported_surface',
                array_column($scenario['linked_findings'], 'finding_type'),
            );
            $this->assertTrue($result['non_pass_routes'][$scenarioId]['routed']);
        }
    }

    public function test_host_runner_preserves_runner_blocked_evidence_as_non_passing(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner blocked gate.');
        }

        foreach ([
            ['runner_blocked', 'blocked_reason'],
            ['runnerBlocked', 'runnerBlockedReason'],
            [null, 'blocked_reason'],
        ] as [$flagField, $reasonField]) {
            $evidence = $this->completeRunnerEvidence();
            $evidence['outcome'] = 'pass';
            if ($flagField !== null) {
                $evidence[$flagField] = true;
            }
            $evidence[$reasonField] = 'Nexus host execution did not reach published artifact behavior.';

            $result = $this->runNexusEvidence($evidence, 'dw-nexus-runner-blocked-');
            $scenario = $this->scenarioResult($result, 'published_artifact_install_only');

            $this->assertSame('non_passing_runner_blocked', $result['outcome']);
            $this->assertTrue($result['runner_blocked']);
            $this->assertSame($evidence[$reasonField], $result['blocked_reason']);
            $this->assertSame('runner_blocked', $scenario['status']);
            $this->assertSame($evidence[$reasonField], $scenario['observed_outputs']['blocked_reason']);
            $this->assertContains('runner_gap', array_column($scenario['linked_findings'], 'finding_type'));
        }
    }

    public function test_host_runner_rejects_placeholder_artifacts_and_local_source_before_passing(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        $evidence['artifact_versions']['server'] = 'current';
        $evidence['artifact_versions']['workflow'] = 'head';
        $evidence['artifact_sources']['server'] = 'local_product_source_checkout';
        $evidence['local_product_source_checkouts_used'] = true;

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-gate-');

        $this->assertSame(
            'fail',
            $result['outcome'],
            'supplied pass scenarios must not allow Nexus to pass with placeholder artifacts or local product source usage',
        );
        $this->assertFalse($result['runner_blocked']);
        $this->assertTrue($result['local_product_source_checkouts_used']);
        $this->assertContains(
            [
                'artifact' => 'server',
                'field' => 'artifact_versions',
                'code' => 'placeholder_published_artifact_version',
                'value' => 'current',
            ],
            $result['artifact_policy_failures'],
        );
        $this->assertContains(
            [
                'artifact' => 'workflow',
                'field' => 'artifact_versions',
                'code' => 'placeholder_published_artifact_version',
                'value' => 'head',
            ],
            $result['artifact_policy_failures'],
        );
        $this->assertSame('fail', $result['scenario_results'][0]['status']);
        $this->assertTrue($result['scenario_results'][0]['observed_outputs']['result_gate_failed']);

        $findingTypes = array_column($result['scenario_results'][0]['linked_findings'], 'finding_type');
        $this->assertContains('missing_or_invalid_published_nexus_artifact', $findingTypes);
        $this->assertContains('local_product_source_checkout_used', $findingTypes);
    }

    public function test_host_runner_rejects_local_checkout_paths_as_artifact_sources(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        $evidence['artifact_sources']['server'] = '~/repos/server';
        $evidence['artifact_sources']['cli'] = '${HOME}/repos/cli';
        $evidence['artifact_sources']['workflow'] = 'file:///workspace/repos/workflow';
        $evidence['artifact_sources']['sdk-python'] = './repos/sdk-python';
        $evidence['artifact_sources']['waterline'] = '/workspace/repos/waterline';

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-local-path-sources-');

        $this->assertSame(
            'fail',
            $result['outcome'],
            'supplied pass scenarios must not allow Nexus to pass with local checkout paths as artifact sources',
        );
        $this->assertFalse($result['runner_blocked']);
        $this->assertFalse($result['local_product_source_checkouts_used']);
        $this->assertContains(
            [
                'artifact' => 'server',
                'field' => 'artifact_sources',
                'code' => 'forbidden_published_artifact_source',
                'value' => '~/repos/server',
            ],
            $result['artifact_policy_failures'],
        );
        $this->assertContains(
            [
                'artifact' => 'cli',
                'field' => 'artifact_sources',
                'code' => 'forbidden_published_artifact_source',
                'value' => '${HOME}/repos/cli',
            ],
            $result['artifact_policy_failures'],
        );
        $this->assertContains(
            [
                'artifact' => 'workflow',
                'field' => 'artifact_sources',
                'code' => 'forbidden_published_artifact_source',
                'value' => 'file:///workspace/repos/workflow',
            ],
            $result['artifact_policy_failures'],
        );
        $this->assertContains(
            [
                'artifact' => 'sdk-python',
                'field' => 'artifact_sources',
                'code' => 'forbidden_published_artifact_source',
                'value' => './repos/sdk-python',
            ],
            $result['artifact_policy_failures'],
        );
        $this->assertContains(
            [
                'artifact' => 'waterline',
                'field' => 'artifact_sources',
                'code' => 'forbidden_published_artifact_source',
                'value' => '/workspace/repos/waterline',
            ],
            $result['artifact_policy_failures'],
        );
        $this->assertSame('fail', $result['scenario_results'][0]['status']);
        $this->assertTrue($result['scenario_results'][0]['observed_outputs']['result_gate_failed']);
        $this->assertContains(
            'missing_or_invalid_published_nexus_artifact',
            array_column($result['scenario_results'][0]['linked_findings'], 'finding_type'),
        );
    }

    public function test_host_runner_rejects_rolling_artifact_source_refs_before_passing(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        foreach ([
            'durableworkflow/server:latest',
            'durableworkflow/server:current',
            'durableworkflow/server:head',
            'https://github.com/durable-workflow/server/releases/latest/download/server.tar.gz',
        ] as $source) {
            $evidence = $this->completeRunnerEvidence();
            $evidence['artifact_sources']['server'] = $source;

            $result = $this->runNexusEvidence($evidence, 'dw-nexus-rolling-source-');

            $this->assertSame(
                'fail',
                $result['outcome'],
                sprintf('Nexus must not pass with rolling artifact source %s.', $source),
            );
            $this->assertFalse($result['runner_blocked']);
            $this->assertFalse($result['local_product_source_checkouts_used']);
            $this->assertTrue($this->hasArtifactPolicyFailure(
                $result,
                'server',
                'artifact_sources',
                'forbidden_published_artifact_source',
                $source,
            ));
            $this->assertSame('fail', $result['scenario_results'][0]['status']);
            $this->assertTrue($result['scenario_results'][0]['observed_outputs']['result_gate_failed']);
        }
    }

    public function test_host_runner_rejects_unpublished_artifact_source_channels_before_passing(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $badCliSource = 'https://github.com/durable-workflow/cli/releases/download/'
            .'v0.1.75'
            .'/dw-linux-amd'
            .'64';
        $badWaterlineSource = 'npm://'
            .'@durable-workflow/'
            .'waterline'
            .'@2.0.0-alpha.77';

        $evidence = $this->completeRunnerEvidence();
        $evidence['artifact_sources']['cli'] = $badCliSource;
        $evidence['artifact_sources']['waterline'] = $badWaterlineSource;

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-unpublished-source-channel-');

        $this->assertSame(
            'fail',
            $result['outcome'],
            'Nexus must not pass with artifact source channels that do not resolve to the published tuple artifacts.',
        );
        $this->assertFalse($result['runner_blocked']);
        $this->assertTrue($this->hasArtifactPolicyFailure(
            $result,
            'cli',
            'artifact_sources',
            'invalid_published_artifact_source',
            $badCliSource,
        ));
        $this->assertTrue($this->hasArtifactPolicyFailure(
            $result,
            'waterline',
            'artifact_sources',
            'invalid_published_artifact_source',
            $badWaterlineSource,
        ));
        $this->assertSame('fail', $result['scenario_results'][0]['status']);
        $this->assertTrue($result['scenario_results'][0]['observed_outputs']['result_gate_failed']);
    }

    public function test_host_runner_rejects_cli_release_assets_that_are_not_public_downloads(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $missingCliAsset = 'https://github.com/durable-workflow/cli/releases/download/'
            .'0.1.75'
            .'/SHA256SUMS.sig';

        $evidence = $this->completeRunnerEvidence();
        $evidence['artifact_sources']['cli'] = $missingCliAsset;

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-missing-cli-asset-');

        $this->assertSame(
            'fail',
            $result['outcome'],
            'Nexus must not pass when CLI evidence points at a release URL that is not a downloadable public asset.',
        );
        $this->assertFalse($result['runner_blocked']);
        $this->assertTrue($this->hasArtifactPolicyFailure(
            $result,
            'cli',
            'artifact_sources',
            'invalid_published_artifact_source',
            $missingCliAsset,
        ));
        $this->assertSame('fail', $result['scenario_results'][0]['status']);
        $this->assertTrue($result['scenario_results'][0]['observed_outputs']['result_gate_failed']);
    }

    public function test_host_runner_rejects_syntactically_valid_sources_without_resolution_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $fakeVersions = [
            'server' => '99.99.99',
            'cli' => '99.99.99',
            'workflow' => '99.99.99',
            'sdk-php' => '99.99.99',
            'sdk-python' => '99.99.99',
            'waterline' => '99.99.99',
        ];
        $fakeSources = [
            'server' => 'docker://durableworkflow/server:99.99.99',
            'cli' => 'https://github.com/durable-workflow/cli/releases/download/99.99.99/dw-linux-x86_64',
            'workflow' => 'packagist://durable-workflow/workflow@99.99.99',
            'sdk-php' => 'packagist://durable-workflow/sdk@99.99.99',
            'sdk-python' => 'pypi://durable-workflow==99.99.99',
            'waterline' => 'packagist://durable-workflow/waterline@99.99.99',
        ];

        $evidence = $this->completeRunnerEvidence();
        $evidence['artifact_versions'] = $fakeVersions;
        $evidence['published_artifact_versions'] = $fakeVersions;
        $evidence['resolved_artifact_versions'] = $fakeVersions;
        $evidence['artifact_sources'] = $fakeSources;
        unset($evidence['artifact_source_verification']);

        foreach ($evidence['scenario_results'] as &$scenario) {
            if (($scenario['scenario_id'] ?? null) === 'published_artifact_install_only') {
                $scenario['observed_outputs']['artifact_versions'] = $fakeVersions;
                $scenario['observed_outputs']['artifact_sources'] = $fakeSources;
                unset($scenario['observed_outputs']['artifact_source_verification']);
            }
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-missing-resolution-proof-');

        $this->assertSame(
            'fail',
            $result['outcome'],
            'Nexus must not pass with source strings that look valid but have no host proof of a downloadable artifact.',
        );
        $this->assertFalse($result['runner_blocked']);
        $this->assertTrue($this->hasArtifactPolicyFailure(
            $result,
            'server',
            'artifact_source_verification',
            'missing_published_artifact_resolution_evidence',
        ));
        $this->assertTrue($this->hasArtifactPolicyFailure(
            $result,
            'cli',
            'artifact_source_verification',
            'missing_published_artifact_resolution_evidence',
        ));
        $this->assertSame('not_covered', $result['scenario_results'][0]['status']);
        $this->assertTrue($result['scenario_results'][0]['observed_outputs']['result_gate_failed']);
        $this->assertContains(
            'conformance_runner_coverage_gap',
            array_column($result['scenario_results'][0]['linked_findings'], 'finding_type'),
        );
    }

    public function test_host_runner_rejects_mismatched_source_resolution_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        $evidence['artifact_source_verification']['cli']['source'] =
            'https://github.com/durable-workflow/cli/releases/download/0.1.75/install.sh';

        foreach ($evidence['scenario_results'] as &$scenario) {
            if (($scenario['scenario_id'] ?? null) === 'published_artifact_install_only') {
                $scenario['observed_outputs']['artifact_source_verification']['cli']['source'] =
                    'https://github.com/durable-workflow/cli/releases/download/0.1.75/install.sh';
            }
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-mismatched-resolution-proof-');

        $this->assertSame('fail', $result['outcome']);
        $this->assertTrue($this->hasArtifactPolicyFailure(
            $result,
            'cli',
            'artifact_source_verification',
            'published_artifact_resolution_source_mismatch',
            'https://github.com/durable-workflow/cli/releases/download/0.1.75/install.sh',
        ));
    }

    public function test_host_runner_validates_install_scenario_artifact_maps_before_passing(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        foreach ($evidence['scenario_results'] as &$scenario) {
            if ($scenario['scenario_id'] === 'published_artifact_install_only') {
                $scenario['observed_outputs']['artifact_versions']['workflow'] = 'banana';
                $scenario['observed_outputs']['artifact_sources']['server'] = 'file:///workspace/repos/server';
            }
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-install-map-policy-');
        $installScenario = $this->scenarioResult($result, 'published_artifact_install_only');

        $this->assertSame(
            'fail',
            $result['outcome'],
            'valid top-level artifact maps must not hide invalid install-scenario artifact evidence',
        );
        $this->assertSame('docker://durableworkflow/server:0.2.247', $result['artifact_sources']['server']);
        $this->assertSame('2.0.0-alpha.190', $result['artifact_versions']['workflow']);
        $this->assertSame('fail', $installScenario['status']);
        $this->assertTrue($installScenario['observed_outputs']['result_gate_failed']);
        $this->assertTrue($this->hasArtifactPolicyFailure(
            $result,
            'server',
            'artifact_sources',
            'forbidden_published_artifact_source',
            'file:///workspace/repos/server',
            '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_sources',
        ));
        $this->assertTrue($this->hasArtifactPolicyFailure(
            $result,
            'workflow',
            'artifact_versions',
            'invalid_published_artifact_version',
            'banana',
            '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_versions',
        ));
    }

    public function test_host_runner_rejects_install_scenario_artifact_tuple_mismatch(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        foreach ($evidence['scenario_results'] as &$scenario) {
            if ($scenario['scenario_id'] === 'published_artifact_install_only') {
                $scenario['observed_outputs']['artifact_versions']['server'] = '0.2.246';
                $scenario['observed_outputs']['artifact_sources']['server'] = 'docker://durableworkflow/server:0.2.246';
                $scenario['observed_outputs']['artifact_source_verification']['server'] = [
                    'version' => '0.2.246',
                    'source' => 'docker://durableworkflow/server:0.2.246',
                    'downloadable' => true,
                    'verified_at' => '2026-06-02T12:00:00Z',
                ];
            }
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-install-tuple-mismatch-');
        $installScenario = $this->scenarioResult($result, 'published_artifact_install_only');

        $this->assertSame(
            'fail',
            $result['outcome'],
            'install-only evidence must not pass with a valid but different published artifact tuple',
        );
        $this->assertSame('fail', $installScenario['status']);
        $this->assertTrue($installScenario['observed_outputs']['result_gate_failed']);
        $this->assertTrue($this->hasArtifactPolicyFailure(
            $result,
            'server',
            'artifact_versions',
            'install_artifact_version_mismatch',
            '0.2.246',
            '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_versions',
        ));
        $this->assertTrue($this->hasArtifactPolicyFailure(
            $result,
            'server',
            'artifact_sources',
            'install_artifact_source_mismatch',
            'docker://durableworkflow/server:0.2.246',
            '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_sources',
        ));
        $this->assertTrue($this->hasArtifactPolicyFailure(
            $result,
            'server',
            'artifact_source_verification',
            'install_artifact_source_verification_version_mismatch',
            '0.2.246',
            '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_source_verification',
        ));
        $this->assertTrue($this->hasArtifactPolicyFailure(
            $result,
            'server',
            'artifact_source_verification',
            'install_artifact_source_verification_source_mismatch',
            'docker://durableworkflow/server:0.2.246',
            '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_source_verification',
        ));
    }

    public function test_host_runner_synthesizes_install_only_and_result_routing_envelopes(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        $evidence['scenario_results'] = array_values(array_filter(
            $evidence['scenario_results'],
            static fn (array $scenario): bool => ! in_array(
                $scenario['scenario_id'] ?? null,
                ['published_artifact_install_only', 'result_record_and_product_finding_routing'],
                true,
            ),
        ));

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-synthesized-envelope-');
        $installScenario = $this->scenarioResult($result, 'published_artifact_install_only');
        $routingScenario = $this->scenarioResult($result, 'result_record_and_product_finding_routing');

        $this->assertSame('pass', $result['outcome']);
        $this->assertSame([], $result['artifact_policy_failures']);
        $this->assertSame('pass', $installScenario['status']);
        $this->assertSame($evidence['artifact_versions'], $installScenario['observed_outputs']['artifact_versions']);
        $this->assertSame($evidence['artifact_sources'], $installScenario['observed_outputs']['artifact_sources']);
        $this->assertSame(
            $evidence['artifact_source_verification'],
            $installScenario['observed_outputs']['artifact_source_verification'],
        );
        $this->assertFalse($installScenario['observed_outputs']['local_product_source_checkouts_used']);
        $this->assertTrue($installScenario['observed_outputs']['install_channels_verified']);
        $this->assertTrue($installScenario['observed_outputs']['published_install_tuple_proven']);
        $this->assertCount(6, $installScenario['observed_outputs']['artifact_install_evidence']['artifacts']);

        $this->assertSame('pass', $routingScenario['status']);
        $this->assertSame([], $routingScenario['linked_findings']);
        $this->assertSame(
            'pass',
            $routingScenario['observed_outputs']['scenario_statuses']['published_artifact_install_only'],
        );
        $this->assertSame(
            'pass',
            $routingScenario['observed_outputs']['scenario_statuses']['result_record_and_product_finding_routing'],
        );
        $this->assertSame([], $routingScenario['observed_outputs']['non_pass_routes']);
        $this->assertSame(
            count(NexusContract::manifest()['required_scenarios']),
            $routingScenario['observed_outputs']['status_counts']['pass'],
        );
    }

    public function test_host_runner_record_attaches_scenario_results_for_gate_ingestion(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        $evidence['conformance_run_id'] = 'nexus-run-6886';
        $record = null;
        $result = $this->runNexusEvidence(
            $evidence,
            'dw-nexus-record-scenario-results-',
            null,
            null,
            $record,
        );

        $this->assertIsArray($record);
        $this->assertSame('nexus', $record['experiment']);
        $this->assertSame('nexus-run-6886', $result['conformance_run_id']);
        $this->assertSame('nexus-run-6886', $record['conformance_run_id']);
        $this->assertSame('nexus-run-6886', $record['conformanceRunId']);
        $this->assertSame($result['scenario_results'], $record['scenario_results']);
        $this->assertSame($result['scenario_results'], $record['scenarioResults']);
        $this->assertSame($result['finding_links'], $record['finding_links']);
        $this->assertSame($result['finding_links'], $record['findingLinks']);
        $this->assertSame(
            'pass',
            $result['scenario_statuses']['published_artifact_install_only'],
        );
        $this->assertSame(
            'pass',
            $record['scenario_statuses']['published_artifact_install_only'],
        );
        $this->assertSame($result['scenario_statuses'], $record['scenario_statuses']);
        $this->assertContains('runner_blocked', $result['scenario_status_values']);
        $this->assertContains('not_covered', $result['non_passing_status_values']);
        $this->assertContains(
            'result_record_and_product_finding_routing',
            $record['reported_scenarios'],
        );
        $this->assertSame([], $record['non_pass_scenarios']);
        $this->assertSame([], $result['non_pass_scenarios']);
        $this->assertSame([], $result['non_pass_routes']);
    }

    public function test_host_runner_promotes_dedicated_install_evidence_into_existing_legacy_install_scenario(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        $installEvidence = $evidence['artifact_install_evidence'];
        unset($evidence['artifact_install_evidence']);
        foreach ($evidence['scenario_results'] as &$scenario) {
            if (($scenario['scenario_id'] ?? null) === 'published_artifact_install_only') {
                unset($scenario['observed_outputs']['artifact_install_evidence']);
            }
        }
        unset($scenario);

        $result = $this->runNexusEvidence(
            $evidence,
            'dw-nexus-dedicated-install-promotion-',
            $installEvidence,
        );
        $installScenario = $this->scenarioResult($result, 'published_artifact_install_only');

        $this->assertSame('pass', $result['outcome']);
        $this->assertSame('pass', $installScenario['status']);
        $this->assertSame([], $result['artifact_policy_failures']);
        $this->assertCount(6, $installScenario['observed_outputs']['artifact_install_evidence']['artifacts']);
        $this->assertSame(
            'DW_NEXUS_ARTIFACT_INSTALL_EVIDENCE',
            $result['artifact_install_evidence']['supplied_install_evidence_source'],
        );
        $this->assertTrue($result['artifact_install_evidence']['supplied_install_evidence']);
        $this->assertFalse($result['artifact_install_evidence']['derived_install_evidence']);
    }

    public function test_host_runner_repairs_not_covered_legacy_install_scenario_with_dedicated_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        $installEvidence = $evidence['artifact_install_evidence'];
        unset($evidence['artifact_install_evidence']);
        foreach ($evidence['scenario_results'] as &$scenario) {
            if (($scenario['scenario_id'] ?? null) === 'published_artifact_install_only') {
                $scenario['status'] = 'not_covered';
                unset($scenario['observed_outputs']['artifact_install_evidence']);
                $scenario['linked_findings'] = [
                    [
                        'scenario_id' => 'published_artifact_install_only',
                        'type' => 'conformance_runner_coverage_gap',
                        'finding_type' => 'conformance_runner_coverage_gap',
                        'owning_surface' => 'conformance_harness',
                        'observed_behavior' => 'legacy runner did not attach install evidence',
                        'expected_behavior' => 'published artifact install evidence is attached',
                        'next_acceptance_criterion' => 'rerun Nexus install-only evidence capture',
                    ],
                ];
            }
        }
        unset($scenario);

        $result = $this->runNexusEvidence(
            $evidence,
            'dw-nexus-not-covered-install-promotion-',
            $installEvidence,
        );
        $installScenario = $this->scenarioResult($result, 'published_artifact_install_only');

        $this->assertSame('pass', $installScenario['status']);
        $this->assertSame([], $installScenario['linked_findings']);
        $this->assertSame($evidence['artifact_versions'], $installScenario['observed_outputs']['artifact_versions']);
        $this->assertSame($evidence['artifact_sources'], $installScenario['observed_outputs']['artifact_sources']);
        $this->assertCount(6, $installScenario['observed_outputs']['artifact_install_evidence']['artifacts']);
        $this->assertSame([], $result['artifact_policy_failures']);
    }

    public function test_host_runner_repairs_empty_top_level_install_evidence_from_install_scenario(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        $evidence['artifact_install_evidence'] = [
            'schema' => 'durable-workflow.v2.nexus-runtime.install-evidence',
            'local_product_source_checkouts_used' => false,
            'artifacts' => [],
        ];

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-repair-top-install-evidence-');
        $installScenario = $this->scenarioResult($result, 'published_artifact_install_only');

        $this->assertSame('pass', $result['outcome']);
        $this->assertSame('pass', $installScenario['status']);
        $this->assertCount(6, $result['artifact_install_evidence']['artifacts']);
        $this->assertSame(
            'scenario_results.published_artifact_install_only.observed_outputs.artifact_install_evidence',
            $result['artifact_install_evidence']['supplied_install_evidence_source'],
        );
    }

    public function test_host_runner_rejects_empty_top_level_install_evidence_without_install_scenario(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        $evidence['artifact_install_evidence'] = [
            'schema' => 'durable-workflow.v2.nexus-runtime.install-evidence',
            'local_product_source_checkouts_used' => false,
            'artifacts' => [],
        ];
        $evidence['scenario_results'] = array_values(array_filter(
            $evidence['scenario_results'],
            static fn (array $scenario): bool => ($scenario['scenario_id'] ?? null)
                !== 'published_artifact_install_only',
        ));

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-empty-install-evidence-');
        $installScenario = $this->scenarioResult($result, 'published_artifact_install_only');

        $this->assertSame('fail', $result['outcome']);
        $this->assertSame('not_covered', $installScenario['status']);
        $this->assertTrue($this->hasArtifactPolicyFailure(
            $result,
            'server',
            'artifact_install_evidence.artifacts',
            'missing_published_artifact_install_evidence_artifact',
            null,
            '$.artifact_install_evidence.artifacts',
        ));
    }

    public function test_host_runner_synthesizes_install_evidence_from_verified_published_tuple_when_explicit_install_evidence_missing(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        unset($evidence['artifact_install_evidence']);
        foreach ($evidence['scenario_results'] as &$scenario) {
            if (($scenario['scenario_id'] ?? null) === 'published_artifact_install_only') {
                unset($scenario['observed_outputs']['artifact_install_evidence']);
            }
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-synthesized-verified-install-evidence-');
        $installScenario = $this->scenarioResult($result, 'published_artifact_install_only');

        $this->assertSame('pass', $result['outcome']);
        $this->assertSame('pass', $installScenario['status']);
        $this->assertSame([], $result['artifact_policy_failures']);
        $this->assertTrue($result['artifact_install_evidence']['synthesized_from_published_artifact_tuple']);
        $this->assertFalse($result['artifact_install_evidence']['supplied_install_evidence']);
        $this->assertTrue($result['artifact_install_evidence']['derived_install_evidence']);
        $this->assertSame('published_artifact_tuple', $result['artifact_install_evidence']['install_evidence_source']);
        $this->assertSame('published_artifact_tuple', $result['artifact_install_evidence']['derived_install_evidence_source']);
        $this->assertArrayNotHasKey('supplied_install_evidence_source', $result['artifact_install_evidence']);
        $this->assertCount(6, $installScenario['observed_outputs']['artifact_install_evidence']['artifacts']);
        $installChannels = array_column(
            $result['artifact_install_evidence']['artifacts'],
            'install_channel',
            'artifact',
        );
        $this->assertSame('packagist', $installChannels['sdk-php']);
        $this->assertFalse(
            $installScenario['observed_outputs']['artifact_install_evidence']['supplied_install_evidence'],
        );
        $this->assertTrue(
            $installScenario['observed_outputs']['artifact_install_evidence']['derived_install_evidence'],
        );
        $this->assertSame(
            $evidence['artifact_source_verification'],
            $installScenario['observed_outputs']['artifact_source_verification'],
        );
    }

    public function test_host_runner_ignores_stale_result_dir_install_evidence_when_deriving_install_proof(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        $staleInstallEvidence = $evidence['artifact_install_evidence'];
        unset($evidence['artifact_install_evidence']);
        foreach ($evidence['scenario_results'] as &$scenario) {
            if (($scenario['scenario_id'] ?? null) === 'published_artifact_install_only') {
                unset($scenario['observed_outputs']['artifact_install_evidence']);
            }
        }
        unset($scenario);

        $result = $this->runNexusEvidence(
            $evidence,
            'dw-nexus-stale-install-evidence-',
            null,
            $staleInstallEvidence,
        );

        $this->assertSame('pass', $result['outcome']);
        $this->assertFalse($result['artifact_install_evidence']['supplied_install_evidence']);
        $this->assertTrue($result['artifact_install_evidence']['derived_install_evidence']);
        $this->assertSame('published_artifact_tuple', $result['artifact_install_evidence']['derived_install_evidence_source']);
        $this->assertArrayNotHasKey('supplied_install_evidence_source', $result['artifact_install_evidence']);
        $this->assertCount(6, $result['artifact_install_evidence']['artifacts']);
    }

    public function test_host_runner_does_not_pass_from_reachability_and_artifact_pins_alone(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $complete = $this->completeRunnerEvidence();
        $evidence = [
            'outcome' => 'pass',
            'started_at' => $complete['started_at'],
            'finished_at' => $complete['finished_at'],
            'artifact_versions' => $complete['artifact_versions'],
            'published_artifact_versions' => $complete['published_artifact_versions'],
            'resolved_artifact_versions' => $complete['resolved_artifact_versions'],
            'artifact_sources' => $complete['artifact_sources'],
            'local_product_source_checkouts_used' => false,
            'findings' => [],
        ];

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-reachability-only-');
        $installScenario = $this->scenarioResult($result, 'published_artifact_install_only');
        $tenantScenario = $this->scenarioResult($result, 'tenant_a_calls_shared_service');
        $routingScenario = $this->scenarioResult($result, 'result_record_and_product_finding_routing');

        $this->assertSame('fail', $result['outcome']);
        $this->assertSame('not_covered', $installScenario['status']);
        $this->assertSame('not_covered', $tenantScenario['status']);
        $this->assertSame('pass', $routingScenario['status']);
        $this->assertTrue($this->hasArtifactPolicyFailure(
            $result,
            'server',
            'artifact_source_verification',
            'missing_published_artifact_resolution_evidence',
        ));
        $this->assertTrue($this->hasArtifactPolicyFailure(
            $result,
            'product-artifacts',
            'artifact_install_evidence',
            'missing_published_artifact_install_evidence',
            null,
            '$.artifact_install_evidence',
        ));
        $this->assertSame(
            'not_covered',
            $result['scenario_statuses']['published_artifact_install_only'],
        );
        $this->assertSame(
            'not_covered',
            $result['scenario_statuses']['tenant_a_calls_shared_service'],
        );
        $this->assertContains('tenant_a_calls_shared_service', $result['non_pass_scenarios']);
        $this->assertSame(
            'not_covered',
            $routingScenario['observed_outputs']['scenario_statuses']['tenant_a_calls_shared_service'],
        );
        $this->assertTrue($result['non_pass_routes']['tenant_a_calls_shared_service']['routed']);
        $this->assertTrue(
            $routingScenario['observed_outputs']['non_pass_routes']['tenant_a_calls_shared_service']['routed'],
        );
    }

    public function test_host_runner_without_server_image_keeps_missing_cells_uncovered(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $result = $this->runNexusWithoutEvidence('dw-nexus-no-image-');
        $tenantScenario = $this->scenarioResult($result, 'tenant_a_calls_shared_service');
        $callerHistoryScenario = $this->scenarioResult($result, 'caller_history_attempt_visibility');

        $this->assertSame('fail', $result['outcome']);
        $this->assertFalse($result['runner_blocked']);
        $this->assertSame('not_covered', $tenantScenario['status']);
        $this->assertContains(
            'conformance_runner_coverage_gap',
            array_column($tenantScenario['linked_findings'], 'finding_type'),
        );
        $this->assertSame('not_covered', $callerHistoryScenario['status']);
        $this->assertNotContains(
            'retry_attempt_visibility_gap',
            array_column($callerHistoryScenario['linked_findings'], 'finding_type'),
        );
        $this->assertContains(
            'conformance_runner_coverage_gap',
            array_column($callerHistoryScenario['linked_findings'], 'finding_type'),
        );
    }

    public function test_host_runner_result_record_routes_every_non_pass_status_to_focused_findings(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        foreach ($evidence['scenario_results'] as &$scenario) {
            if (($scenario['scenario_id'] ?? null) === 'result_record_and_product_finding_routing') {
                $scenario['status'] = 'pass';
                $scenario['observed_outputs'] = [];
                $scenario['linked_findings'] = [];
            }
            if (($scenario['scenario_id'] ?? null) === 'tenant_a_calls_shared_service') {
                $scenario['status'] = 'fail';
                $scenario['linked_findings'] = [
                    $this->focusedFinding('tenant_a_calls_shared_service', 'nexus_product_failure'),
                ];
            }
            if (($scenario['scenario_id'] ?? null) === 'php_caller_python_service') {
                $scenario['status'] = 'unsupported';
                $scenario['linked_findings'] = [
                    $this->focusedFinding('php_caller_python_service', 'nexus_unsupported_surface'),
                ];
            }
            if (($scenario['scenario_id'] ?? null) === 'python_caller_php_service') {
                $scenario['status'] = 'not_covered';
                $scenario['linked_findings'] = [];
            }
            if (($scenario['scenario_id'] ?? null) === 'caller_cancellation_propagates_to_service') {
                $scenario['status'] = 'runner_blocked';
                $scenario['linked_findings'] = [
                    $this->focusedFinding('caller_cancellation_propagates_to_service', 'nexus_runner_gap'),
                ];
            }
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-status-routing-');
        $routingScenario = $this->scenarioResult($result, 'result_record_and_product_finding_routing');

        $this->assertSame('fail', $result['outcome']);
        $this->assertSame('pass', $routingScenario['status']);
        $this->assertSame('fail', $routingScenario['observed_outputs']['scenario_statuses']['tenant_a_calls_shared_service']);
        $this->assertSame('unsupported', $routingScenario['observed_outputs']['scenario_statuses']['php_caller_python_service']);
        $this->assertSame('not_covered', $routingScenario['observed_outputs']['scenario_statuses']['python_caller_php_service']);
        $this->assertSame('runner_blocked', $routingScenario['observed_outputs']['scenario_statuses']['caller_cancellation_propagates_to_service']);
        $this->assertTrue($routingScenario['observed_outputs']['non_pass_findings_routed']);
        $this->assertSame(
            $routingScenario['observed_outputs']['scenario_statuses'],
            $result['scenario_statuses'],
        );
        $this->assertSame(
            $routingScenario['observed_outputs']['non_pass_routes'],
            $result['non_pass_routes'],
        );

        foreach ([
            'tenant_a_calls_shared_service',
            'php_caller_python_service',
            'python_caller_php_service',
            'caller_cancellation_propagates_to_service',
        ] as $scenarioId) {
            $this->assertTrue($routingScenario['observed_outputs']['non_pass_routes'][$scenarioId]['routed']);
            $this->assertGreaterThanOrEqual(
                1,
                $routingScenario['observed_outputs']['non_pass_routes'][$scenarioId]['focused_finding_count'],
            );
        }
    }

    public function test_host_runner_result_record_fails_when_non_pass_finding_is_unfocused(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        foreach ($evidence['scenario_results'] as &$scenario) {
            if (($scenario['scenario_id'] ?? null) === 'tenant_a_calls_shared_service') {
                $scenario['status'] = 'fail';
                $scenario['linked_findings'] = [
                    [
                        'scenario_id' => 'tenant_a_calls_shared_service',
                        'finding_type' => 'nexus_product_failure',
                    ],
                ];
            }
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-unfocused-routing-');
        $routingScenario = $this->scenarioResult($result, 'result_record_and_product_finding_routing');

        $this->assertSame('fail', $result['outcome']);
        $this->assertSame('fail', $routingScenario['status']);
        $this->assertFalse(
            $routingScenario['observed_outputs']['non_pass_routes']['tenant_a_calls_shared_service']['routed'],
        );
        $this->assertContains(
            'nexus_result_record_routing_gap',
            array_column($routingScenario['linked_findings'], 'finding_type'),
        );
    }

    public function test_host_runner_records_unreadable_evidence_path_as_typed_coverage_gap(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $result = $this->runNexusWithEvidencePath(
            sys_get_temp_dir().'/dw-nexus-missing-evidence-'.bin2hex(random_bytes(6)).'.json',
            'dw-nexus-unreadable-evidence-',
        );
        $installScenario = $this->scenarioResult($result, 'published_artifact_install_only');

        $this->assertSame('fail', $result['outcome']);
        $this->assertFalse($result['runner_blocked']);
        $this->assertContains(
            'conformance_runner_coverage_gap',
            array_column($result['findings'], 'finding_type'),
        );
        $this->assertContains(
            'conformance_runner_coverage_gap',
            array_column($installScenario['linked_findings'], 'finding_type'),
        );
    }

    public function test_host_runner_rejects_non_version_artifact_pins_before_passing(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        $evidence['artifact_versions']['server'] = 'not-a-version';
        $evidence['artifact_versions']['workflow'] = 'banana';

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-invalid-version-');

        $this->assertSame(
            'fail',
            $result['outcome'],
            'supplied pass scenarios must not allow Nexus to pass with arbitrary non-version artifact pins',
        );
        $this->assertFalse($result['runner_blocked']);
        $this->assertContains(
            [
                'artifact' => 'server',
                'field' => 'artifact_versions',
                'code' => 'invalid_published_artifact_version',
                'value' => 'not-a-version',
            ],
            $result['artifact_policy_failures'],
        );
        $this->assertContains(
            [
                'artifact' => 'workflow',
                'field' => 'artifact_versions',
                'code' => 'invalid_published_artifact_version',
                'value' => 'banana',
            ],
            $result['artifact_policy_failures'],
        );
        $this->assertSame('fail', $result['scenario_results'][0]['status']);
        $this->assertTrue($result['scenario_results'][0]['observed_outputs']['result_gate_failed']);
        $this->assertContains(
            'missing_or_invalid_published_nexus_artifact',
            array_column($result['scenario_results'][0]['linked_findings'], 'finding_type'),
        );
    }

    public function test_host_runner_rejects_pass_when_source_free_evidence_is_omitted(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        unset($evidence['artifact_sources'], $evidence['local_product_source_checkouts_used']);
        unset($evidence['artifact_install_evidence']['local_product_source_checkouts_used']);
        foreach ($evidence['artifact_install_evidence']['artifacts'] as &$artifact) {
            unset($artifact['local_product_source_checkout_used_as_artifact']);
        }
        unset($artifact);
        foreach ($evidence['scenario_results'] as &$scenario) {
            if (($scenario['scenario_id'] ?? null) === 'published_artifact_install_only') {
                unset($scenario['observed_outputs']['local_product_source_checkouts_used']);
                unset($scenario['observed_outputs']['artifact_install_evidence']['local_product_source_checkouts_used']);
                foreach ($scenario['observed_outputs']['artifact_install_evidence']['artifacts'] as &$artifact) {
                    unset($artifact['local_product_source_checkout_used_as_artifact']);
                }
                unset($artifact);
            }
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-missing-source-evidence-');

        $this->assertSame(
            'fail',
            $result['outcome'],
            'supplied pass scenarios must not allow Nexus to pass without explicit published artifact source evidence',
        );
        $this->assertFalse($result['runner_blocked']);
        $this->assertFalse($result['local_product_source_checkouts_used']);
        $this->assertContains(
            [
                'artifact' => 'server',
                'field' => 'artifact_sources',
                'code' => 'missing_published_artifact_source',
            ],
            $result['artifact_policy_failures'],
        );
        $this->assertContains(
            [
                'artifact' => 'product-artifacts',
                'field' => 'local_product_source_checkouts_used',
                'code' => 'missing_explicit_source_free_evidence',
            ],
            $result['artifact_policy_failures'],
        );

        $findingTypes = array_column($result['scenario_results'][0]['linked_findings'], 'finding_type');
        $this->assertContains('missing_or_invalid_published_nexus_artifact', $findingTypes);
        $this->assertContains('missing_explicit_source_free_published_artifact_evidence', $findingTypes);
    }

    public function test_host_runner_rejects_pass_with_thin_scenario_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        foreach ($evidence['scenario_results'] as &$scenario) {
            $scenario['observed_outputs'] = [
                'service_call_id' => 'svc-'.$scenario['scenario_id'],
            ];
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-thin-evidence-');
        $tenantScenario = $this->scenarioResult($result, 'tenant_a_calls_shared_service');

        $this->assertSame(
            'fail',
            $result['outcome'],
            'Nexus pass scenarios must not pass with generic non-empty observed_outputs',
        );
        $this->assertSame('not_covered', $tenantScenario['status']);
        $this->assertSame(
            'missing_scenario_specific_evidence',
            $tenantScenario['observed_outputs']['scenario_evidence_failures'][0]['code'],
        );
        $this->assertContains(
            'conformance_runner_coverage_gap',
            array_column($tenantScenario['linked_findings'], 'finding_type'),
        );
    }

    public function test_host_runner_requires_shared_service_invocation_evidence_for_each_tenant(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        foreach ([
            'tenant_a_calls_shared_service' => ['request', 'response'],
            'tenant_b_calls_shared_service' => ['service_call_record', 'caller_history_evidence'],
        ] as $scenarioId => $missingFields) {
            $evidence = $this->completeRunnerEvidence();
            foreach ($evidence['scenario_results'] as &$scenario) {
                if (($scenario['scenario_id'] ?? null) === $scenarioId) {
                    foreach ($missingFields as $field) {
                        unset($scenario['observed_outputs'][$field]);
                    }
                }
            }
            unset($scenario);

            $result = $this->runNexusEvidence($evidence, 'dw-nexus-shared-service-evidence-');
            $scenario = $this->scenarioResult($result, $scenarioId);

            $this->assertSame('fail', $result['outcome']);
            $this->assertSame('not_covered', $scenario['status']);
            $this->assertSame(
                $missingFields[0],
                $scenario['observed_outputs']['scenario_evidence_failures'][0]['field'],
            );
            $this->assertContains(
                'conformance_runner_coverage_gap',
                array_column($scenario['linked_findings'], 'finding_type'),
            );
        }
    }

    public function test_host_runner_requires_replay_and_cancellation_timing_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        foreach ([
            'worker_restart_replay_does_not_reissue_call' => [
                'caller_history_rows',
                'service_logs',
                'caller_worker_restarted_at',
                'replay_transport',
                'duplicate_call_assertion',
            ],
            'caller_cancellation_propagates_to_service' => [
                'caller_history_rows',
                'service_logs',
                'cancellation_propagation_ms',
                'within_propagation_window',
            ],
        ] as $scenarioId => $missingFields) {
            $evidence = $this->completeRunnerEvidence();
            foreach ($evidence['scenario_results'] as &$scenario) {
                if (($scenario['scenario_id'] ?? null) === $scenarioId) {
                    foreach ($missingFields as $field) {
                        unset($scenario['observed_outputs'][$field]);
                    }
                }
            }
            unset($scenario);

            $result = $this->runNexusEvidence($evidence, 'dw-nexus-replay-cancel-evidence-');
            $scenario = $this->scenarioResult($result, $scenarioId);

            $this->assertSame('fail', $result['outcome']);
            $this->assertSame('not_covered', $scenario['status']);
            $this->assertSame(
                $missingFields[0],
                $scenario['observed_outputs']['scenario_evidence_failures'][0]['field'],
            );
            $this->assertContains(
                'conformance_runner_coverage_gap',
                array_column($scenario['linked_findings'], 'finding_type'),
            );
        }
    }

    public function test_host_runner_requires_published_worker_execution_for_replay_and_cancellation_cells(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        foreach ([
            'worker_restart_replay_does_not_reissue_call',
            'caller_cancellation_propagates_to_service',
        ] as $scenarioId) {
            $evidence = $this->completeRunnerEvidence();
            foreach ($evidence['scenario_results'] as &$scenario) {
                if (($scenario['scenario_id'] ?? null) === $scenarioId) {
                    unset($scenario['observed_outputs']['published_artifact_worker_execution']);
                }
            }
            unset($scenario);

            $result = $this->runNexusEvidence($evidence, 'dw-nexus-worker-evidence-');
            $scenario = $this->scenarioResult($result, $scenarioId);

            $this->assertSame('fail', $result['outcome']);
            $this->assertSame('not_covered', $scenario['status']);
            $this->assertSame(
                'published_artifact_worker_execution',
                $scenario['observed_outputs']['scenario_evidence_failures'][0]['field'],
            );
            $this->assertContains(
                'conformance_runner_coverage_gap',
                array_column($scenario['linked_findings'], 'finding_type'),
            );
        }
    }

    public function test_host_runner_accepts_published_server_worker_execution_for_built_in_replay_and_cancellation_cells(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        $serverWorkerExecution = [
            'local_product_source_checkouts_used' => false,
            'artifacts' => [
                [
                    'artifact' => 'server',
                    'version' => $evidence['artifact_versions']['server'],
                    'source' => $evidence['artifact_sources']['server'],
                    'status' => 'pass',
                    'execution_context' => 'published_server_image_worker_service',
                    'local_product_source_checkout_used_as_artifact' => false,
                ],
            ],
        ];

        foreach ($evidence['scenario_results'] as &$scenario) {
            if (in_array($scenario['scenario_id'], [
                'worker_restart_replay_does_not_reissue_call',
                'caller_cancellation_propagates_to_service',
            ], true)) {
                $scenario['observed_outputs']['published_artifact_worker_execution'] = $serverWorkerExecution;
            }
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-server-worker-evidence-');

        $this->assertSame('pass', $result['outcome']);
        $this->assertSame(
            'pass',
            $this->scenarioResult($result, 'worker_restart_replay_does_not_reissue_call')['status'],
        );
        $this->assertSame(
            'pass',
            $this->scenarioResult($result, 'caller_cancellation_propagates_to_service')['status'],
        );
    }

    public function test_host_runner_rejects_duplicate_replay_invocation_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        foreach ($evidence['scenario_results'] as &$scenario) {
            if ($scenario['scenario_id'] === 'worker_restart_replay_does_not_reissue_call') {
                $scenario['observed_outputs']['service_invocation_count'] = 2;
                $scenario['observed_outputs']['duplicate_call_issue_count'] = 1;
                $scenario['observed_outputs']['duplicate_call_assertion']['observed_service_invocations'] = 2;
                $scenario['observed_outputs']['duplicate_call_assertion']['duplicate_call_issue_count'] = 1;
            }
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-duplicate-replay-');
        $scenario = $this->scenarioResult($result, 'worker_restart_replay_does_not_reissue_call');

        $this->assertSame('fail', $result['outcome']);
        $this->assertSame('fail', $scenario['status']);
        $this->assertSame(
            'invalid_scenario_specific_evidence',
            $scenario['observed_outputs']['scenario_evidence_failures'][0]['code'],
        );
        $this->assertContains(
            'nexus_scenario_evidence_mismatch',
            array_column($scenario['linked_findings'], 'finding_type'),
        );
    }

    public function test_host_runner_rejects_more_than_one_replay_transport_retry(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        foreach ($evidence['scenario_results'] as &$scenario) {
            if ($scenario['scenario_id'] !== 'worker_restart_replay_does_not_reissue_call') {
                continue;
            }

            $scenario['observed_outputs']['replay_transport']['retry_count'] = 2;
            $scenario['observed_outputs']['replay_transport']['attempts'][] = [
                'attempt' => 2,
                'connection' => 'fresh',
                'outcome' => 'http_success',
                'http_status' => 200,
                'request_body_sha256' => str_repeat('a', 64),
                'idempotency_key_sha256' => str_repeat('b', 64),
            ];
            $scenario['observed_outputs']['replay_transport']['attempts'][] = [
                'attempt' => 3,
                'connection' => 'fresh',
                'outcome' => 'http_success',
                'http_status' => 200,
                'request_body_sha256' => str_repeat('a', 64),
                'idempotency_key_sha256' => str_repeat('b', 64),
            ];
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-replay-transport-retries-');
        $scenario = $this->scenarioResult($result, 'worker_restart_replay_does_not_reissue_call');

        $this->assertSame('fail', $result['outcome']);
        $this->assertSame('fail', $scenario['status']);
        $this->assertSame(
            'replay_transport',
            $scenario['observed_outputs']['scenario_evidence_failures'][0]['field'],
        );
    }

    public function test_host_runner_rejects_cancellation_outside_propagation_window(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        foreach ($evidence['scenario_results'] as &$scenario) {
            if ($scenario['scenario_id'] === 'caller_cancellation_propagates_to_service') {
                $scenario['observed_outputs']['within_propagation_window'] = false;
            }
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-cancel-window-');
        $scenario = $this->scenarioResult($result, 'caller_cancellation_propagates_to_service');

        $this->assertSame('fail', $result['outcome']);
        $this->assertSame('fail', $scenario['status']);
        $this->assertSame(
            'invalid_scenario_specific_evidence',
            $scenario['observed_outputs']['scenario_evidence_failures'][0]['code'],
        );
        $this->assertContains(
            'nexus_scenario_evidence_mismatch',
            array_column($scenario['linked_findings'], 'finding_type'),
        );
    }

    public function test_host_runner_rejects_pass_when_retry_attempt_visibility_is_false(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        foreach ($evidence['scenario_results'] as &$scenario) {
            if ($scenario['scenario_id'] === 'transient_failure_retries_with_policy') {
                $scenario['observed_outputs']['history_attempt_visibility_includes_retry_attempts'] = false;
            }
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-retry-visibility-');
        $scenario = $this->scenarioResult($result, 'transient_failure_retries_with_policy');

        $this->assertSame('fail', $result['outcome']);
        $this->assertSame('fail', $scenario['status']);
        $this->assertContains(
            'retry_attempt_visibility_gap',
            array_column($scenario['linked_findings'], 'finding_type'),
        );
    }

    public function test_host_runner_rejects_pass_when_authorization_refusal_leaks_endpoint_existence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        foreach ($evidence['scenario_results'] as &$scenario) {
            if ($scenario['scenario_id'] === 'endpoint_permission_denied_without_information_leak') {
                $scenario['observed_outputs']['authorization_refusal_disclosed_endpoint_existence'] = true;
            }
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-auth-leak-');
        $scenario = $this->scenarioResult($result, 'endpoint_permission_denied_without_information_leak');

        $this->assertSame('fail', $result['outcome']);
        $this->assertSame('fail', $scenario['status']);
        $this->assertContains(
            'permission_denied_information_leak',
            array_column($scenario['linked_findings'], 'finding_type'),
        );
    }

    public function test_host_runner_rejects_pass_when_refusal_no_dispatch_history_query_failed(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        foreach ($evidence['scenario_results'] as &$scenario) {
            if ($scenario['scenario_id'] === 'endpoint_permission_denied_without_information_leak') {
                $scenario['observed_outputs']['caller_history_query_succeeded'] = false;
                $scenario['observed_outputs']['dispatch_evidence']['caller_history_query_succeeded'] = false;
                $scenario['observed_outputs']['dispatch_evidence']['caller_history_response'] = [
                    'status' => 500,
                    'body' => ['reason' => 'history_unavailable'],
                ];
            }
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-refusal-history-');
        $scenario = $this->scenarioResult($result, 'endpoint_permission_denied_without_information_leak');

        $this->assertSame('fail', $result['outcome']);
        $this->assertSame('fail', $scenario['status']);
        $this->assertContains(
            'nexus_refusal_no_dispatch_evidence_gap',
            array_column($scenario['linked_findings'], 'finding_type'),
        );
    }

    public function test_host_runner_rejects_pass_when_refusal_history_row_is_handler_failed(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the Nexus runner result gate.');
        }

        $evidence = $this->completeRunnerEvidence();
        foreach ($evidence['scenario_results'] as &$scenario) {
            if ($scenario['scenario_id'] !== 'endpoint_permission_denied_without_information_leak') {
                continue;
            }

            $handlerFailedRow = [
                'service_call_id' => 'svc-endpoint-permission-denied',
                'status' => 'failed',
                'outcome' => 'handler_failed',
            ];
            $scenario['observed_outputs']['dispatch_evidence']['matching_rejected_history_count'] = 1;
            $scenario['observed_outputs']['dispatch_evidence']['caller_history_rows'] = [$handlerFailedRow];
            $scenario['observed_outputs']['dispatch_evidence']['caller_history_response']['body']['nexus_operations'] = [$handlerFailedRow];
            $scenario['observed_outputs']['caller_history_evidence']['nexus_operations'] = [$handlerFailedRow];
        }
        unset($scenario);

        $result = $this->runNexusEvidence($evidence, 'dw-nexus-refusal-handler-failed-');
        $scenario = $this->scenarioResult($result, 'endpoint_permission_denied_without_information_leak');

        $this->assertSame('fail', $result['outcome']);
        $this->assertSame('fail', $scenario['status']);
        $this->assertContains(
            'nexus_refusal_no_dispatch_evidence_gap',
            array_column($scenario['linked_findings'], 'finding_type'),
        );
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>|null  $installEvidence
     * @param  array<string, mixed>|null  $staleResultInstallEvidence
     * @param  array<string, mixed>|null  $record
     * @return array<string, mixed>
     */
    private function runNexusEvidence(
        array $evidence,
        string $tempPrefix,
        ?array $installEvidence = null,
        ?array $staleResultInstallEvidence = null,
        ?array &$record = null,
        ?array &$resultFiles = null,
        bool $skipSharedServiceProbe = true,
    ): array {
        $repoRoot = dirname(__DIR__, 2);
        $tempRoot = sys_get_temp_dir().'/'.$tempPrefix.bin2hex(random_bytes(6));
        $resultDir = $tempRoot.'/result';
        $evidencePath = $tempRoot.'/nexus-evidence.json';
        $installEvidencePath = $tempRoot.'/nexus-artifact-install-evidence.json';

        try {
            mkdir($resultDir, 0777, true);
            if ($staleResultInstallEvidence !== null) {
                file_put_contents(
                    $resultDir.'/nexus-artifact-install-evidence.json',
                    json_encode($staleResultInstallEvidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
                );
            }
            file_put_contents(
                $evidencePath,
                json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
            );
            $environment = [
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                'DW_NEXUS_EVIDENCE_JSON' => $evidencePath,
            ];
            if ($skipSharedServiceProbe) {
                $environment['DW_NEXUS_SKIP_SHARED_SERVICE_PROBE'] = '1';
            }
            if ($installEvidence !== null) {
                file_put_contents(
                    $installEvidencePath,
                    json_encode($installEvidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
                );
                $environment['DW_NEXUS_ARTIFACT_INSTALL_EVIDENCE'] = $installEvidencePath;
            }

            $process = proc_open(
                [$repoRoot.'/scripts/conformance/nexus-published-artifacts.sh', '--result-dir', $resultDir],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                $environment,
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, ($stdout === false ? '' : $stdout).($stderr === false ? '' : $stderr));

            $resultPath = $resultDir.'/nexus-conformance-result.json';
            $this->assertFileExists($resultPath);
            $recordPath = $resultDir.'/nexus-conformance-record.json';
            $this->assertFileExists($recordPath);
            $record = json_decode((string) file_get_contents($recordPath), true, 512, JSON_THROW_ON_ERROR);
            $resultFiles = array_values(array_diff(scandir($resultDir) ?: [], ['.', '..']));
            sort($resultFiles);

            return json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            $this->removeTree($tempRoot);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runNexusWithEvidencePath(string $evidencePath, string $tempPrefix): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $tempRoot = sys_get_temp_dir().'/'.$tempPrefix.bin2hex(random_bytes(6));
        $resultDir = $tempRoot.'/result';

        try {
            mkdir($resultDir, 0777, true);

            $process = proc_open(
                [$repoRoot.'/scripts/conformance/nexus-published-artifacts.sh', '--result-dir', $resultDir],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                [
                    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                    'DW_NEXUS_EVIDENCE_JSON' => $evidencePath,
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, ($stdout === false ? '' : $stdout).($stderr === false ? '' : $stderr));

            $resultPath = $resultDir.'/nexus-conformance-result.json';
            $this->assertFileExists($resultPath);

            return json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            $this->removeTree($tempRoot);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runNexusWithoutEvidence(string $tempPrefix): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $tempRoot = sys_get_temp_dir().'/'.$tempPrefix.bin2hex(random_bytes(6));
        $resultDir = $tempRoot.'/result';

        try {
            mkdir($resultDir, 0777, true);

            $process = proc_open(
                [$repoRoot.'/scripts/conformance/nexus-published-artifacts.sh', '--result-dir', $resultDir],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                [
                    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                    'DW_SERVER_IMAGE' => '',
                    'DW_SERVER_VERSION' => '',
                ],
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, ($stdout === false ? '' : $stdout).($stderr === false ? '' : $stderr));

            $resultPath = $resultDir.'/nexus-conformance-result.json';
            $this->assertFileExists($resultPath);

            return json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            $this->removeTree($tempRoot);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function completeRunnerEvidence(
        string $pythonVersion = '0.4.84',
        ?string $pythonSource = null,
    ): array {
        $pythonSource ??= 'pypi://durable-workflow=='.$pythonVersion;
        $artifactVersions = [
            'server' => '0.2.247',
            'cli' => '0.1.75',
            'workflow' => '2.0.0-alpha.190',
            'sdk-php' => '0.1.1',
            'sdk-python' => $pythonVersion,
            'waterline' => '2.0.0-alpha.77',
        ];
        $artifactSources = [
            'server' => 'docker://durableworkflow/server:0.2.247',
            'cli' => 'https://github.com/durable-workflow/cli/releases/download/0.1.75/dw-linux-x86_64',
            'workflow' => 'packagist://durable-workflow/workflow@2.0.0-alpha.190',
            'sdk-php' => 'packagist://durable-workflow/sdk@0.1.1',
            'sdk-python' => $pythonSource,
            'waterline' => 'packagist://durable-workflow/waterline@2.0.0-alpha.77',
        ];
        $artifactSourceVerification = $this->artifactSourceVerification($artifactVersions, $artifactSources);
        $artifactInstallEvidence = $this->artifactInstallEvidence(
            $artifactVersions,
            $artifactSources,
            $artifactSourceVerification,
        );
        $scenarioResults = [];

        foreach (NexusContract::manifest()['required_scenarios'] as $scenarioId) {
            $scenarioResults[] = [
                'scenario_id' => $scenarioId,
                'status' => 'pass',
                'observed_outputs' => $this->completeScenarioOutputs(
                    $scenarioId,
                    $artifactVersions,
                    $artifactSources,
                    $artifactSourceVerification,
                ),
            ];
        }

        return [
            'outcome' => 'pass',
            'started_at' => '2026-06-02T12:00:00Z',
            'finished_at' => '2026-06-02T12:02:00Z',
            'artifact_versions' => $artifactVersions,
            'published_artifact_versions' => $artifactVersions,
            'resolved_artifact_versions' => $artifactVersions,
            'artifact_sources' => $artifactSources,
            'artifact_source_verification' => $artifactSourceVerification,
            'artifact_install_evidence' => $artifactInstallEvidence,
            'local_product_source_checkouts_used' => false,
            'findings' => [],
            'scenario_results' => $scenarioResults,
        ];
    }

    /**
     * @param  array<string, string>  $artifactVersions
     * @param  array<string, string>  $artifactSources
     * @return array<string, mixed>
     */
    private function completeScenarioOutputs(
        string $scenarioId,
        array $artifactVersions,
        array $artifactSources,
        array $artifactSourceVerification,
    ): array {
        $base = [
            'service_call_id' => 'svc-'.$scenarioId,
        ];

        return match ($scenarioId) {
            'published_artifact_install_only' => [
                'artifact_versions' => $artifactVersions,
                'artifact_sources' => $artifactSources,
                'artifact_source_verification' => $artifactSourceVerification,
                'local_product_source_checkouts_used' => false,
                'install_channels_verified' => true,
                'published_install_tuple_proven' => true,
                'artifact_install_evidence' => $this->artifactInstallEvidence(
                    $artifactVersions,
                    $artifactSources,
                    $artifactSourceVerification,
                ),
            ],
            'tenant_a_calls_shared_service' => $base + [
                'caller_namespace' => 'tenant-a',
                'target_namespace' => 'shared',
                'endpoint_name' => 'shared-greeter',
                'service_name' => 'Greeter',
                'operation_name' => 'greet',
                'workflow_result' => 'hello, tenant-a',
                'request' => $this->sharedServiceRequestEvidence('tenant-a', 'svc-'.$scenarioId),
                'response' => $this->sharedServiceResponseEvidence('tenant-a', 'svc-'.$scenarioId),
                'service_call_record' => $this->sharedServiceCallRecord('tenant-a', 'svc-'.$scenarioId),
                'caller_history_evidence' => $this->sharedServiceCallerHistoryEvidence('tenant-a', 'svc-'.$scenarioId),
                'caller_history_recorded' => true,
            ],
            'tenant_b_calls_shared_service' => $base + [
                'caller_namespace' => 'tenant-b',
                'target_namespace' => 'shared',
                'endpoint_name' => 'shared-greeter',
                'service_name' => 'Greeter',
                'operation_name' => 'greet',
                'workflow_result' => 'hello, tenant-b',
                'request' => $this->sharedServiceRequestEvidence('tenant-b', 'svc-'.$scenarioId),
                'response' => $this->sharedServiceResponseEvidence('tenant-b', 'svc-'.$scenarioId),
                'service_call_record' => $this->sharedServiceCallRecord('tenant-b', 'svc-'.$scenarioId),
                'caller_history_evidence' => $this->sharedServiceCallerHistoryEvidence('tenant-b', 'svc-'.$scenarioId),
                'caller_history_recorded' => true,
            ],
            'transient_failure_retries_with_policy' => $base + [
                'retry_policy' => ['maximum_attempts' => 3],
                'retry_attempts' => [
                    ['attempt' => 1, 'outcome' => 'handler_failed'],
                    ['attempt' => 2, 'outcome' => 'handler_failed'],
                    ['attempt' => 3, 'outcome' => 'completed'],
                ],
                'history_attempt_visibility_includes_retry_attempts' => true,
                'completed_after_retry' => true,
            ],
            'permanent_failure_preserves_typed_error' => $base + [
                'service_error_type' => 'SharedGreeterUnavailable',
                'caller_observed_error_type' => 'SharedGreeterUnavailable',
                'typed_error_preserved' => true,
            ],
            'worker_restart_replay_does_not_reissue_call' => $base + [
                'published_artifact_worker_execution' => $this->publishedWorkerExecution($artifactVersions, $artifactSources),
                'issued_call_ids' => ['svc-'.$scenarioId],
                'caller_history_rows' => [
                    [
                        'service_call_id' => 'svc-'.$scenarioId,
                        'status' => 'started',
                        'outcome' => 'accepted',
                    ],
                    [
                        'service_call_id' => 'svc-'.$scenarioId,
                        'status' => 'completed',
                        'outcome' => 'completed',
                    ],
                ],
                'service_logs' => [
                    [
                        'at' => '2026-06-02T12:00:02Z',
                        'message' => 'Greeter.greet accepted svc-'.$scenarioId,
                    ],
                    [
                        'at' => '2026-06-02T12:00:35Z',
                        'message' => 'Greeter.greet completed svc-'.$scenarioId,
                    ],
                ],
                'call_issued_at' => '2026-06-02T12:00:01Z',
                'caller_worker_stopped_at' => '2026-06-02T12:00:04Z',
                'caller_worker_restarted_at' => '2026-06-02T12:00:12Z',
                'call_completed_at' => '2026-06-02T12:00:35Z',
                'worker_restart_observed' => true,
                'history_replay_recovered_call' => true,
                'replay_transport' => [
                    'strategy' => 'retry_once_on_stale_socket',
                    'max_retries' => 1,
                    'retry_count' => 0,
                    'recovery_needed' => false,
                    'recovery_attempted' => false,
                    'fresh_connection_used' => false,
                    'transport_recovered' => false,
                    'request' => [
                        'method' => 'POST',
                        'path' => '/api/service-endpoints/shared-greeter/services/Greeter/operations/greet/execute',
                        'namespace' => 'shared',
                        'request_body_sha256' => str_repeat('a', 64),
                        'idempotency_key_sha256' => str_repeat('b', 64),
                    ],
                    'attempts' => [[
                        'attempt' => 1,
                        'connection' => 'pooled',
                        'outcome' => 'http_success',
                        'http_status' => 200,
                        'request_body_sha256' => str_repeat('a', 64),
                        'idempotency_key_sha256' => str_repeat('b', 64),
                    ]],
                ],
                'service_invocation_count' => 1,
                'duplicate_call_assertion' => [
                    'expected_service_invocations' => 1,
                    'observed_service_invocations' => 1,
                    'duplicate_call_issue_count' => 0,
                ],
                'duplicate_call_issue_count' => 0,
            ],
            'caller_cancellation_propagates_to_service' => $base + [
                'published_artifact_worker_execution' => $this->publishedWorkerExecution($artifactVersions, $artifactSources),
                'caller_history_rows' => [
                    [
                        'service_call_id' => 'svc-'.$scenarioId,
                        'status' => 'cancelled',
                        'outcome' => 'cancelled',
                    ],
                ],
                'service_logs' => [
                    [
                        'at' => '2026-06-02T12:01:03Z',
                        'message' => 'Greeter.greet observed NexusCancellation for svc-'.$scenarioId,
                    ],
                ],
                'caller_cancelled_at' => '2026-06-02T12:01:00Z',
                'target_cancelled_at' => '2026-06-02T12:01:03Z',
                'cancellation_propagation_ms' => 3000,
                'within_propagation_window' => true,
                'cancellation_type' => 'NexusCancellation',
                'typed_cancellation_observed' => true,
            ],
            'php_caller_python_service' => $base + [
                'caller_workflow_instance_id' => 'caller-'.$scenarioId,
                'caller_workflow_run_id' => 'run-'.$scenarioId,
                'caller_sdk_language' => 'sdk-php',
                'service_sdk_language' => 'sdk-python',
                'operation_name' => 'Greeter.greet',
                'request_payload' => [
                    'name' => 'world',
                    'scenario' => $scenarioId,
                ],
                'response_or_failure_surface' => [
                    'status' => 'completed',
                    'body' => [
                        'result' => 'hello from python',
                    ],
                ],
                'artifact_tuple' => $artifactVersions,
                'published_artifact_worker_execution' => $this->publishedCrossLanguageWorkerExecution(
                    $artifactVersions,
                    $artifactSources,
                ),
                'service_health' => $this->publishedServiceHealth(
                    'sdk-python',
                    $artifactVersions['sdk-python'],
                ),
                'service_probe_succeeded' => true,
                'service_response_payload' => [
                    'message' => 'hello from sdk-python, world',
                    'scenario' => $scenarioId,
                    'request_payload' => [
                        'name' => 'world',
                        'scenario' => $scenarioId,
                        'caller_sdk_language' => 'sdk-php',
                        'service_sdk_language' => 'sdk-python',
                    ],
                ],
                'payload_round_trip' => true,
                'typed_error_probe_succeeded' => true,
                'typed_error_round_trip' => true,
            ],
            'python_caller_php_service' => $base + [
                'caller_workflow_instance_id' => 'caller-'.$scenarioId,
                'caller_workflow_run_id' => 'run-'.$scenarioId,
                'caller_sdk_language' => 'sdk-python',
                'service_sdk_language' => 'workflow-php',
                'operation_name' => 'Greeter.greet',
                'request_payload' => [
                    'name' => 'world',
                    'scenario' => $scenarioId,
                ],
                'response_or_failure_surface' => [
                    'status' => 'completed',
                    'body' => [
                        'result' => 'hello from php',
                    ],
                ],
                'artifact_tuple' => $artifactVersions,
                'published_artifact_worker_execution' => $this->publishedCrossLanguageWorkerExecution(
                    $artifactVersions,
                    $artifactSources,
                ),
                'service_health' => $this->publishedServiceHealth(
                    'workflow-php',
                    $artifactVersions['workflow'],
                ),
                'service_probe_succeeded' => true,
                'service_response_payload' => [
                    'message' => 'hello from workflow-php, world',
                    'scenario' => $scenarioId,
                    'request_payload' => [
                        'name' => 'world',
                        'scenario' => $scenarioId,
                        'caller_sdk_language' => 'sdk-python',
                        'service_sdk_language' => 'workflow-php',
                    ],
                ],
                'payload_round_trip' => true,
                'typed_error_probe_succeeded' => true,
                'typed_error_round_trip' => true,
            ],
            'endpoint_permission_denied_without_information_leak' => [
                'caller_namespace' => 'denied',
                'refusal_status' => 'rejected_forbidden',
                'service_call_id' => 'svc-endpoint-permission-denied',
                'request' => [
                    'method' => 'POST',
                    'path' => '/api/service-endpoints/shared-greeter/services/Greeter/operations/greet/execute',
                    'namespace' => 'shared',
                    'body' => [
                        'caller_namespace' => 'denied',
                        'caller_workflow_instance_id' => 'denied-call-greeter',
                        'caller_workflow_run_id' => '01JDENIED000000000000000',
                    ],
                ],
                'response' => [
                    'status' => 403,
                    'body' => [
                        'accepted' => false,
                        'reason' => 'caller_namespace_denied',
                        'outcome' => 'rejected_forbidden',
                    ],
                ],
                'dispatch_evidence' => [
                    'handler_dispatch_count' => 0,
                    'service_invoked' => false,
                    'caller_history_query_succeeded' => true,
                    'caller_history_state_proven' => true,
                    'matching_rejected_history_count' => 1,
                    'caller_history_rows' => [
                        [
                            'service_call_id' => 'svc-endpoint-permission-denied',
                            'status' => 'failed',
                            'outcome' => 'rejected_forbidden',
                        ],
                    ],
                    'caller_history_response' => [
                        'status' => 200,
                        'body' => [
                            'nexus_operations' => [
                                [
                                    'service_call_id' => 'svc-endpoint-permission-denied',
                                    'status' => 'failed',
                                    'outcome' => 'rejected_forbidden',
                                ],
                            ],
                        ],
                    ],
                ],
                'caller_history_evidence' => [
                    'nexus_operations' => [
                        [
                            'service_call_id' => 'svc-endpoint-permission-denied',
                            'status' => 'failed',
                            'outcome' => 'rejected_forbidden',
                        ],
                    ],
                ],
                'caller_history_query_succeeded' => true,
                'caller_history_state_proven' => true,
                'authorization_refusal_disclosed_endpoint_existence' => false,
                'handler_dispatch_count' => 0,
                'service_invoked' => false,
            ],
            'malformed_payload_refused_before_dispatch' => [
                'refusal_status' => 'rejected_bad_payload',
                'typed_error' => 'MalformedNexusPayload',
                'request' => [
                    'method' => 'POST',
                    'path' => '/api/service-endpoints/shared-greeter/services/Greeter/operations/greet/execute',
                    'namespace' => 'shared',
                    'body' => [
                        'wait_for' => 'dispatch_anyway',
                    ],
                ],
                'response' => [
                    'status' => 422,
                    'body' => [
                        'reason' => 'validation_failed',
                        'validation_errors' => ['wait_for' => ['The selected wait for is invalid.']],
                    ],
                ],
                'dispatch_evidence' => [
                    'handler_dispatch_count' => 0,
                    'service_invoked' => false,
                    'caller_history_query_succeeded' => true,
                    'caller_history_state_proven' => true,
                    'caller_history_rows' => [],
                    'caller_history_response' => [
                        'status' => 200,
                        'body' => [
                            'nexus_operations' => [],
                        ],
                    ],
                ],
                'caller_history_evidence' => [
                    'nexus_operations' => [],
                ],
                'caller_history_query_succeeded' => true,
                'caller_history_state_proven' => true,
                'handler_dispatch_count' => 0,
                'service_invoked' => false,
            ],
            'nonexistent_endpoint_typed_not_found' => [
                'refusal_status' => 'rejected_not_found',
                'typed_error' => 'NexusEndpointNotFound',
                'request' => [
                    'method' => 'POST',
                    'path' => '/api/service-endpoints/missing-greeter/services/Greeter/operations/greet/execute',
                    'namespace' => 'shared',
                    'body' => [
                        'caller_namespace' => 'tenant-a',
                    ],
                ],
                'response' => [
                    'status' => 404,
                    'body' => [
                        'accepted' => false,
                        'reason' => 'endpoint_not_found',
                    ],
                ],
                'dispatch_evidence' => [
                    'handler_dispatch_count' => 0,
                    'service_invoked' => false,
                    'caller_history_query_succeeded' => true,
                    'caller_history_state_proven' => true,
                    'caller_history_rows' => [],
                    'caller_history_response' => [
                        'status' => 200,
                        'body' => [
                            'nexus_operations' => [],
                        ],
                    ],
                ],
                'caller_history_evidence' => [
                    'nexus_operations' => [],
                ],
                'caller_history_query_succeeded' => true,
                'caller_history_state_proven' => true,
                'handler_dispatch_count' => 0,
                'service_invoked' => false,
            ],
            'caller_history_attempt_visibility' => $base + [
                'caller_history_attempts' => [
                    ['attempt' => 1, 'outcome' => 'handler_failed'],
                    ['attempt' => 2, 'outcome' => 'handler_failed'],
                    ['attempt' => 3, 'outcome' => 'completed'],
                ],
                'history_attempt_visibility_includes_retry_attempts' => true,
                'service_call_detail_attempts' => [
                    ['attempt' => 1, 'outcome' => 'handler_failed'],
                    ['attempt' => 2, 'outcome' => 'handler_failed'],
                    ['attempt' => 3, 'outcome' => 'completed'],
                ],
            ],
            'result_record_and_product_finding_routing' => [
                'result_record_emitted' => true,
                'finding_links_emitted' => true,
                'waterline_operator_visibility' => true,
            ],
            default => $base,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedServiceRequestEvidence(string $callerNamespace, string $serviceCallId): array
    {
        return [
            'method' => 'POST',
            'path' => '/api/service-endpoints/shared-greeter/services/Greeter/operations/greet/execute',
            'caller_namespace' => $callerNamespace,
            'target_namespace' => 'shared',
            'endpoint_name' => 'shared-greeter',
            'service_name' => 'Greeter',
            'operation_name' => 'greet',
            'idempotency_key' => $serviceCallId.'-idem',
            'payload' => ['name' => 'world'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedServiceResponseEvidence(string $callerNamespace, string $serviceCallId): array
    {
        return [
            'status' => 200,
            'service_call_id' => $serviceCallId,
            'workflow_result' => 'hello, '.$callerNamespace,
            'body' => ['result' => 'hello, '.$callerNamespace],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedServiceCallRecord(string $callerNamespace, string $serviceCallId): array
    {
        return [
            'id' => $serviceCallId,
            'caller_namespace' => $callerNamespace,
            'target_namespace' => 'shared',
            'endpoint_name' => 'shared-greeter',
            'service_name' => 'Greeter',
            'operation_name' => 'greet',
            'status' => 'completed',
            'outcome' => 'completed',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedServiceCallerHistoryEvidence(string $callerNamespace, string $serviceCallId): array
    {
        return [
            'route' => '/api/workflows/caller-'.$callerNamespace.'/runs/run-'.$callerNamespace.'/nexus-operations',
            'caller_namespace' => $callerNamespace,
            'contains_service_call_id' => $serviceCallId,
            'nexus_operation' => [
                'service_call_id' => $serviceCallId,
                'target_namespace' => 'shared',
                'endpoint_name' => 'shared-greeter',
                'service_name' => 'Greeter',
                'operation_name' => 'greet',
                'status' => 'completed',
                'outcome' => 'completed',
            ],
        ];
    }

    /**
     * @param  array<string, string>  $artifactVersions
     * @param  array<string, string>  $artifactSources
     * @return array<string, mixed>
     */
    private function publishedWorkerExecution(array $artifactVersions, array $artifactSources): array
    {
        return [
            'local_product_source_checkouts_used' => false,
            'worker_execution_mode' => 'published_workflow_php_worker',
            'artifacts' => [
                [
                    'artifact' => 'workflow-php',
                    'version' => $artifactVersions['workflow'],
                    'source' => $artifactSources['workflow'],
                    'status' => 'pass',
                    'local_product_source_checkouts_used' => false,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, string>  $artifactVersions
     * @param  array<string, string>  $artifactSources
     * @return array<string, mixed>
     */
    private function publishedCrossLanguageWorkerExecution(array $artifactVersions, array $artifactSources): array
    {
        $phpHealth = $this->publishedServiceHealth('workflow-php', $artifactVersions['workflow']);
        $pythonHealth = $this->publishedServiceHealth('sdk-python', $artifactVersions['sdk-python']);

        return [
            'local_product_source_checkouts_used' => false,
            'worker_execution_mode' => 'published_php_python_worker_matrix',
            'service_health' => [
                'workflow-php' => $phpHealth,
                'sdk-python' => $pythonHealth,
            ],
            'artifacts' => [
                [
                    'artifact' => 'workflow-php',
                    'version' => $artifactVersions['workflow'],
                    'source' => $artifactSources['workflow'],
                    'status' => 'pass',
                    'service_health_succeeded' => true,
                    'service_health' => $phpHealth,
                    'local_product_source_checkouts_used' => false,
                ],
                [
                    'artifact' => 'sdk-php',
                    'version' => $artifactVersions['sdk-php'],
                    'source' => $artifactSources['sdk-php'],
                    'status' => 'pass',
                    'service_health_succeeded' => true,
                    'service_health' => $phpHealth,
                    'local_product_source_checkouts_used' => false,
                ],
                [
                    'artifact' => 'sdk-python',
                    'version' => $artifactVersions['sdk-python'],
                    'source' => $artifactSources['sdk-python'],
                    'status' => 'pass',
                    'service_health_succeeded' => true,
                    'service_health' => $pythonHealth,
                    'local_product_source_checkouts_used' => false,
                ],
            ],
            'workers' => [
                [
                    'role' => 'workflow_php_runtime_service',
                    'sdk_language' => 'workflow-php',
                    'package_version' => $artifactVersions['workflow'],
                    'service_started' => true,
                    'service_health_succeeded' => true,
                    'service_health' => $phpHealth,
                ],
                [
                    'role' => 'sdk_python_runtime_service',
                    'sdk_language' => 'sdk-python',
                    'package_version' => $artifactVersions['sdk-python'],
                    'service_started' => true,
                    'service_health_succeeded' => true,
                    'service_health' => $pythonHealth,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publishedServiceHealth(string $runtime, string $version): array
    {
        return [
            'sdk_language' => $runtime,
            'endpoint' => '/health',
            'health_succeeded' => true,
            'service_started' => true,
            'package_imported' => true,
            'package_version' => $version,
            'health_response' => [
                'ok' => true,
                'status' => 200,
                'body' => [
                    'runtime' => $runtime,
                    'service_started' => true,
                    'package_imported' => true,
                    'package_version' => $version,
                ],
            ],
            'local_product_source_checkouts_used' => false,
        ];
    }

    /**
     * @param  array<string, string>  $artifactVersions
     * @param  array<string, string>  $artifactSources
     * @return array<string, array<string, mixed>>
     */
    private function artifactSourceVerification(array $artifactVersions, array $artifactSources): array
    {
        $verification = [];

        foreach ($artifactSources as $artifact => $source) {
            $verification[$artifact] = [
                'version' => $artifactVersions[$artifact],
                'source' => $source,
                'downloadable' => true,
                'verified_at' => '2026-06-02T12:00:00Z',
            ];
        }

        return $verification;
    }

    /**
     * @param  array<string, string>  $artifactVersions
     * @param  array<string, string>  $artifactSources
     * @param  array<string, array<string, mixed>>  $artifactSourceVerification
     * @return array<string, mixed>
     */
    private function artifactInstallEvidence(
        array $artifactVersions,
        array $artifactSources,
        array $artifactSourceVerification,
    ): array {
        $installChannels = [
            'server' => 'docker',
            'cli' => 'github_release_asset',
            'workflow' => 'packagist',
            'sdk-php' => 'packagist',
            'sdk-python' => 'pypi',
            'waterline' => 'packagist',
        ];

        return [
            'schema' => 'durable-workflow.v2.nexus-runtime.install-evidence',
            'published_install_tuple_proven' => true,
            'local_product_source_checkouts_used' => false,
            'artifacts' => array_map(
                static fn (string $artifact): array => [
                    'artifact' => $artifact,
                    'version' => $artifactVersions[$artifact],
                    'source' => $artifactSources[$artifact],
                    'install_channel' => $installChannels[$artifact],
                    'source_verification' => $artifactSourceVerification[$artifact],
                    'local_product_source_checkout_used_as_artifact' => false,
                    'status' => 'pass',
                ],
                array_keys($installChannels),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function withObservedPythonArtifactVersion(array $evidence, string $observedVersion): array
    {
        $evidence['artifact_source_verification']['sdk-python']['version'] = $observedVersion;

        foreach ($evidence['artifact_install_evidence']['artifacts'] as &$artifact) {
            if (($artifact['artifact'] ?? null) === 'sdk-python') {
                $artifact['version'] = $observedVersion;
                $artifact['source_verification']['version'] = $observedVersion;
            }
        }
        unset($artifact);

        foreach ($evidence['scenario_results'] as &$scenario) {
            $outputs = &$scenario['observed_outputs'];
            if (($scenario['scenario_id'] ?? null) === 'published_artifact_install_only') {
                $outputs['artifact_versions']['sdk-python'] = $observedVersion;
                $outputs['artifact_source_verification']['sdk-python']['version'] = $observedVersion;
                foreach ($outputs['artifact_install_evidence']['artifacts'] as &$artifact) {
                    if (($artifact['artifact'] ?? null) === 'sdk-python') {
                        $artifact['version'] = $observedVersion;
                        $artifact['source_verification']['version'] = $observedVersion;
                    }
                }
                unset($artifact);
            }

            if (($scenario['scenario_id'] ?? null) !== 'php_caller_python_service') {
                unset($outputs);

                continue;
            }

            $outputs['service_health']['package_version'] = $observedVersion;
            $outputs['service_health']['health_response']['body']['package_version'] = $observedVersion;
            $workerExecution = &$outputs['published_artifact_worker_execution'];
            $workerExecution['service_health']['sdk-python']['package_version'] = $observedVersion;
            $workerExecution['service_health']['sdk-python']['health_response']['body']['package_version'] = $observedVersion;
            foreach ($workerExecution['artifacts'] as &$artifact) {
                if (($artifact['artifact'] ?? null) === 'sdk-python') {
                    $artifact['version'] = $observedVersion;
                    $artifact['service_health']['package_version'] = $observedVersion;
                    $artifact['service_health']['health_response']['body']['package_version'] = $observedVersion;
                }
            }
            unset($artifact);
            foreach ($workerExecution['workers'] as &$worker) {
                if (($worker['sdk_language'] ?? null) === 'sdk-python') {
                    $worker['package_version'] = $observedVersion;
                    $worker['service_health']['package_version'] = $observedVersion;
                    $worker['service_health']['health_response']['body']['package_version'] = $observedVersion;
                }
            }
            unset($worker, $workerExecution, $outputs);
        }
        unset($scenario);

        return $evidence;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function scenarioResult(array $result, string $scenarioId): array
    {
        foreach ($result['scenario_results'] as $scenario) {
            if (($scenario['scenario_id'] ?? null) === $scenarioId) {
                return $scenario;
            }
        }

        $this->fail(sprintf('missing scenario result for %s', $scenarioId));
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function hasArtifactPolicyFailure(
        array $result,
        string $artifact,
        string $field,
        string $code,
        ?string $value = null,
        ?string $path = null,
    ): bool {
        foreach ($result['artifact_policy_failures'] as $failure) {
            if (($failure['artifact'] ?? null) === $artifact
                && ($failure['field'] ?? null) === $field
                && ($failure['code'] ?? null) === $code
                && ($value === null || ($failure['value'] ?? null) === $value)
                && ($path === null || ($failure['path'] ?? null) === $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function focusedFinding(string $scenarioId, string $findingType): array
    {
        return [
            'scenario_id' => $scenarioId,
            'type' => $findingType,
            'finding_type' => $findingType,
            'owning_surface' => 'server',
            'artifact_versions' => [
                'server' => '0.2.247',
                'cli' => '0.1.75',
                'workflow' => '2.0.0-alpha.190',
                'sdk-php' => '0.1.1',
                'sdk-python' => '0.4.84',
                'waterline' => '2.0.0-alpha.77',
            ],
            'observed_behavior' => sprintf('Nexus scenario %s recorded non-pass evidence.', $scenarioId),
            'expected_behavior' => sprintf('Nexus scenario %s satisfies its published result contract.', $scenarioId),
            'next_acceptance_criterion' => sprintf('rerun the %s Nexus cell with product evidence that reaches pass', $scenarioId),
        ];
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($path);
    }
}
