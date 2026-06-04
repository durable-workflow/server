<?php

namespace App\Support;

/**
 * Evaluates principal-attribution conformance results against the public
 * scenario manifest exposed by PrincipalAttributionContract.
 */
final class PrincipalAttributionResultGate
{
    public const SCHEMA = 'durable-workflow.v2.principal-attribution.result-gate';

    public const VERSION = 2;

    private const REQUIRED_SPOOFING_BODY_FIELD_VALUES = [
        'principal' => 'mallory',
        'principal_id' => 'mallory',
        'principal_type' => 'attacker',
        'actor' => 'mallory',
        'user' => 'mallory',
    ];

    private const REQUIRED_SPOOFING_HEADER_VALUES = [
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
    ];

    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'evaluates_result_schema' => PrincipalAttributionContract::RESULT_SCHEMA,
            'scenario_statuses_source' => 'principal_attribution_contract.scenario_statuses',
            'required_scenarios_source' => 'principal_attribution_contract.required_scenarios',
            'artifact_versions_fields' => [
                'artifact_versions',
                'artifactVersions',
                'published_artifact_versions',
                'publishedArtifactVersions',
                'resolved_artifact_versions',
                'resolvedArtifactVersions',
            ],
            'declared_outcome_fields' => [
                'outcome',
                'status',
                'verdict',
            ],
            'scenario_results_fields' => [
                'scenario_results',
                'scenarioResults',
            ],
            'non_pass_statuses' => [
                'fail',
                'unsupported',
                'not_covered',
                'runner_blocked',
            ],
            'pass_requires' => [
                'every_required_scenario_has_one_result',
                'every_result_uses_a_published_status',
                'start_signal_query_cancel_completion_failure_principals_reported',
                'rotated_credential_actions_record_before_after_labels_and_observed_principals',
                'alice_bob_rotation_anonymous_python_php_cli_and_waterline_cells_reported',
                'spoofing_payload_and_gateway_header_attempts_reported',
                'spoofing_matrix_records_exact_requested_values_and_observed_principals',
                'anonymous_no_auth_topology_reported',
                'anonymous_start_signal_cancel_principals_reported',
                'anonymous_spoofing_payload_and_gateway_header_attempts_reported',
                'each_pass_scenario_has_required_evidence_fields',
                'python_php_sdk_principals_match_expected_ids_and_raw_http_shape',
                'each_non_pass_scenario_has_focused_linked_findings',
                'omitted_required_scenarios_link_focused_findings',
                'run_timestamps_outcome_runner_blocked_and_findings_are_recorded',
                'overall_pass_requires_all_required_scenarios_to_pass',
                'published_artifact_versions_are_recorded_and_pinned',
                'resolved_artifact_versions_are_recorded_and_pinned',
                'published_artifact_install_sources_are_complete',
                'published_artifact_install_local_product_source_checkouts_used_false',
                'no_local_product_source_artifacts_are_reported',
            ],
            'smoke_subset_outcome' => 'non_passing',
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed>|null $contract
     *
     * @return array<string, mixed>
     */
    public static function evaluate(array $result, ?array $contract = null): array
    {
        $contract ??= PrincipalAttributionContract::manifest();

        $failures = [];
        $requiredScenarios = self::stringList($contract['required_scenarios'] ?? []);
        $allowedStatuses = self::stringList($contract['scenario_statuses'] ?? []);
        $duplicateScenarioCounts = [];
        $scenarioResults = self::scenarioResultsById($result, $duplicateScenarioCounts);
        $scenarioStatuses = [];
        $missingScenarios = [];
        $nonPassScenarios = [];

        foreach ($duplicateScenarioCounts as $scenarioId => $count) {
            $failures[] = [
                'code' => 'duplicate_scenario_result',
                'scenario_id' => $scenarioId,
                'count' => $count,
            ];
        }

        foreach ($requiredScenarios as $scenarioId) {
            if (! array_key_exists($scenarioId, $scenarioResults)) {
                $missingScenarios[] = $scenarioId;
                $failures[] = [
                    'code' => 'missing_required_scenario',
                    'scenario_id' => $scenarioId,
                ];
                continue;
            }

            $scenarioResult = $scenarioResults[$scenarioId];
            $status = self::stringValue($scenarioResult['status'] ?? null);
            $scenarioStatuses[$scenarioId] = $status;

            if (! in_array($status, $allowedStatuses, true)) {
                $failures[] = [
                    'code' => 'invalid_scenario_status',
                    'scenario_id' => $scenarioId,
                    'status' => $status,
                    'allowed_statuses' => $allowedStatuses,
                ];
                continue;
            }

            if ($status === 'pass') {
                foreach (self::requiredEvidenceFields($contract, $scenarioId) as $field) {
                    if (self::hasScenarioField($scenarioResult, $field)) {
                        continue;
                    }

                    $failures[] = [
                        'code' => 'missing_scenario_evidence',
                        'scenario_id' => $scenarioId,
                        'field' => $field,
                    ];
                }

                array_push($failures, ...self::passingScenarioEvidenceValueFailures($scenarioResult, $scenarioId));

                continue;
            }

            $nonPassScenarios[] = $scenarioId;
            if (! self::hasFocusedLinkedFinding($scenarioResult, $result, $scenarioId)) {
                $failures[] = [
                    'code' => 'missing_focused_linked_finding',
                    'scenario_id' => $scenarioId,
                    'status' => $status,
                ];
            }
        }

        $reportedScenarioIds = array_keys($scenarioResults);
        $unknownScenarios = array_values(array_diff($reportedScenarioIds, $requiredScenarios));
        foreach ($unknownScenarios as $scenarioId) {
            $status = self::stringValue($scenarioResults[$scenarioId]['status'] ?? null);
            if (! in_array($status, $allowedStatuses, true)) {
                $failures[] = [
                    'code' => 'invalid_extra_scenario_status',
                    'scenario_id' => $scenarioId,
                    'status' => $status,
                    'allowed_statuses' => $allowedStatuses,
                ];
            }
        }

        array_push($failures, ...self::runRecordFailures($result, $contract));
        array_push($failures, ...self::anonymousTopologyFailures($result));
        array_push($failures, ...self::artifactVersionFailures($result, $contract));
        array_push($failures, ...self::sourcePolicyFailures($result, $contract, $scenarioResults));
        array_push($failures, ...self::missingScenarioFindingFailures($missingScenarios, $result));

        $declaredOutcome = self::declaredOutcome($result);
        $evidencePasses = $failures === []
            && $missingScenarios === []
            && $nonPassScenarios === []
            && count($scenarioStatuses) >= count($requiredScenarios);
        $evaluatedStatus = $evidencePasses ? 'pass' : 'non_passing';

        if ($declaredOutcome === 'pass' && $evaluatedStatus !== 'pass') {
            $failures[] = [
                'code' => 'declared_pass_with_non_passing_evidence',
                'declared_outcome' => $declaredOutcome,
                'evaluated_status' => $evaluatedStatus,
            ];
        }

        array_push($failures, ...self::declaredOutcomeFailures($result, $contract));
        array_push($failures, ...self::declaredOutcomeStatusFailures($result, $contract, $evaluatedStatus));

        $smokeSubsetDetected = count($scenarioStatuses) > 0 && count($scenarioStatuses) < count($requiredScenarios);
        if ($smokeSubsetDetected && $declaredOutcome === 'pass') {
            $failures[] = [
                'code' => 'smoke_subset_cannot_pass',
                'reason' => 'Role-token smoke coverage is not a complete principal-attribution conformance result.',
            ];
        }

        $passes = $evaluatedStatus === 'pass' && $failures === [];

        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'status' => $passes ? 'pass' : 'non_passing',
            'required_scenarios' => $requiredScenarios,
            'reported_scenarios' => $reportedScenarioIds,
            'missing_scenarios' => $missingScenarios,
            'non_pass_scenarios' => $nonPassScenarios,
            'unknown_scenarios' => $unknownScenarios,
            'duplicate_scenarios' => $duplicateScenarioCounts,
            'scenario_statuses' => $scenarioStatuses,
            'smoke_subset_detected' => $smokeSubsetDetected,
            'gate_failures' => $failures,
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, int> $duplicateScenarioCounts
     *
     * @return array<string, array<string, mixed>>
     */
    private static function scenarioResultsById(array $result, array &$duplicateScenarioCounts): array
    {
        $raw = self::arrayValue($result, 'scenario_results')
            ?? self::arrayValue($result, 'scenarioResults')
            ?? [];

        $results = [];
        $seen = [];
        foreach ($raw as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $scenarioId = is_string($key) ? $key : self::stringValue($value['scenario_id'] ?? $value['id'] ?? null);
            if ($scenarioId === '') {
                continue;
            }

            if (isset($seen[$scenarioId])) {
                $duplicateScenarioCounts[$scenarioId] = ($duplicateScenarioCounts[$scenarioId] ?? 1) + 1;
            } else {
                $seen[$scenarioId] = true;
            }

            $value['scenario_id'] = $scenarioId;
            $results[$scenarioId] = $value;
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $contract
     *
     * @return list<string>
     */
    private static function requiredEvidenceFields(array $contract, string $scenarioId): array
    {
        return self::stringList($contract['scenario_requirements'][$scenarioId]['required_fields'] ?? []);
    }

    /**
     * @param array<string, mixed> $scenarioResult
     */
    private static function hasScenarioField(array $scenarioResult, string $field): bool
    {
        $value = self::scenarioFieldValue($scenarioResult, $field);

        return $value !== null && $value !== '';
    }

    /**
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function passingScenarioEvidenceValueFailures(array $scenarioResult, string $scenarioId): array
    {
        $failures = [];

        if (in_array($scenarioId, ['named_token_actor_matrix', 'start_signal_cancel_spoofing'], true)) {
            array_push($failures, ...self::actionCredentialEvidenceFailures($scenarioResult, $scenarioId));
        }

        if ($scenarioId === 'named_token_actor_matrix') {
            array_push($failures, ...self::credentialRotationEvidenceFailures($scenarioResult, $scenarioId));
        }

        if ($scenarioId === 'start_signal_cancel_spoofing') {
            array_push($failures, ...self::spoofingMatrixEvidenceFailures($scenarioResult, $scenarioId, [
                'start' => ['type' => 'auth:token', 'id' => 'alice'],
                'signal' => ['type' => 'auth:token', 'id' => 'bob'],
                'cancel' => ['type' => 'auth:token', 'id' => 'alice'],
            ]));
        }

        if ($scenarioId === 'query_attribution') {
            array_push($failures, ...self::spoofingMatrixEvidenceFailures($scenarioResult, $scenarioId, [
                'query' => ['type' => 'auth:token', 'id' => 'bob'],
            ]));
        }

        if ($scenarioId === 'anonymous_attribution') {
            array_push($failures, ...self::anonymousAttributionEvidenceFailures($scenarioResult, $scenarioId));
            array_push($failures, ...self::spoofingMatrixEvidenceFailures($scenarioResult, $scenarioId, [
                'anonymous_start' => ['type' => 'server', 'id' => 'anonymous'],
                'anonymous_signal' => ['type' => 'server', 'id' => 'anonymous'],
                'anonymous_cancel' => ['type' => 'server', 'id' => 'anonymous'],
            ]));
        }

        if (in_array($scenarioId, ['python_sdk_visibility', 'php_client_visibility'], true)) {
            array_push($failures, ...self::sdkVisibilityEvidenceFailures($scenarioResult, $scenarioId));
        }

        if ($scenarioId !== 'waterline_operator_visibility') {
            return $failures;
        }

        if (self::scenarioFieldValue($scenarioResult, 'principal_visible') !== true) {
            $failures[] = [
                'code' => 'waterline_principal_visibility_not_true',
                'scenario_id' => $scenarioId,
                'field' => 'principal_visible',
            ];
        }

        if (self::isEmptyEvidenceValue(self::scenarioFieldValue($scenarioResult, 'output_sample'))) {
            $failures[] = [
                'code' => 'missing_waterline_operator_output_sample',
                'scenario_id' => $scenarioId,
                'field' => 'output_sample',
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function sdkVisibilityEvidenceFailures(array $scenarioResult, string $scenarioId): array
    {
        $expectations = [
            'python_sdk_visibility' => [
                'principal' => ['type' => 'auth:token', 'id' => 'bob'],
                'actor' => 'bob',
                'credential_ref' => 'bob-token',
            ],
            'php_client_visibility' => [
                'principal' => ['type' => 'auth:token', 'id' => 'alice'],
                'actor' => 'alice',
                'credential_ref' => 'alice-token-v1',
            ],
        ];

        $expected = $expectations[$scenarioId] ?? null;
        if ($expected === null) {
            return [];
        }

        $expectedPrincipal = $expected['principal'];
        $failures = [];

        $clientOperation = self::scenarioFieldValue($scenarioResult, 'client_operation');
        if (! is_array($clientOperation) || self::stringValue($clientOperation['status'] ?? null) !== 'pass') {
            $failures[] = [
                'code' => 'sdk_client_operation_not_passed',
                'scenario_id' => $scenarioId,
                'field' => 'client_operation.status',
                'actual_status' => is_array($clientOperation) ? self::stringValue($clientOperation['status'] ?? null) : null,
            ];
        }

        $operationOutputs = self::arrayValue($scenarioResult, 'operation_outputs');
        if ($operationOutputs === null && is_array($clientOperation)) {
            $operationOutputs = self::arrayValue($clientOperation, 'operation_outputs');
        }

        if ($operationOutputs === null) {
            $failures[] = [
                'code' => 'missing_sdk_operation_outputs',
                'scenario_id' => $scenarioId,
                'field' => 'operation_outputs',
            ];
        } else {
            foreach (['start_workflow', 'signal_workflow'] as $operation) {
                if (! self::isEmptyEvidenceValue($operationOutputs[$operation] ?? null)) {
                    continue;
                }

                $failures[] = [
                    'code' => 'missing_sdk_operation_output',
                    'scenario_id' => $scenarioId,
                    'operation' => $operation,
                    'field' => 'operation_outputs.'.$operation,
                ];
            }
        }

        if (self::isEmptyEvidenceValue(self::scenarioFieldValue($scenarioResult, 'operation_output_sample'))) {
            $failures[] = [
                'code' => 'missing_sdk_operation_output_sample',
                'scenario_id' => $scenarioId,
                'field' => 'operation_output_sample',
            ];
        }

        $credentialUsed = self::scenarioFieldValue($scenarioResult, 'credential_used');
        if (! is_array($credentialUsed)) {
            $failures[] = [
                'code' => 'missing_sdk_credential_used',
                'scenario_id' => $scenarioId,
                'field' => 'credential_used',
            ];
        } else {
            if (self::stringValue($credentialUsed['actor'] ?? null) !== $expected['actor']) {
                $failures[] = [
                    'code' => 'sdk_credential_actor_mismatch',
                    'scenario_id' => $scenarioId,
                    'expected_actor' => $expected['actor'],
                    'actual_actor' => self::stringValue($credentialUsed['actor'] ?? null),
                ];
            }

            if (self::actionCredentialRef($credentialUsed) !== $expected['credential_ref']) {
                $failures[] = [
                    'code' => 'sdk_credential_ref_mismatch',
                    'scenario_id' => $scenarioId,
                    'expected_credential_ref' => $expected['credential_ref'],
                    'actual_credential_ref' => self::actionCredentialRef($credentialUsed),
                ];
            }
        }

        foreach ([
            'expected_principal' => self::scenarioFieldValue($scenarioResult, 'expected_principal'),
            'recorded_principal' => self::scenarioFieldValue($scenarioResult, 'recorded_principal'),
            'raw_http_reference_principal' => self::scenarioFieldValue($scenarioResult, 'raw_http_reference_principal'),
        ] as $field => $principal) {
            if (self::principalMatches($principal, $expectedPrincipal)) {
                continue;
            }

            $failures[] = [
                'code' => 'sdk_'.$field.'_mismatch',
                'scenario_id' => $scenarioId,
                'field' => $field,
                'expected_principal' => $expectedPrincipal,
                'actual_principal' => $principal,
            ];
        }

        $samples = self::scenarioFieldValue($scenarioResult, 'history_api_principal_samples');
        if (! is_array($samples)) {
            $failures[] = [
                'code' => 'missing_sdk_history_api_principal_samples',
                'scenario_id' => $scenarioId,
                'field' => 'history_api_principal_samples',
            ];
        } else {
            foreach (['WorkflowStarted', 'SignalReceived'] as $eventType) {
                if (self::principalMatches($samples[$eventType] ?? null, $expectedPrincipal)) {
                    continue;
                }

                $failures[] = [
                    'code' => 'sdk_history_api_principal_sample_mismatch',
                    'scenario_id' => $scenarioId,
                    'event_type' => $eventType,
                    'expected_principal' => $expectedPrincipal,
                    'actual_principal' => $samples[$eventType] ?? null,
                ];
            }
        }

        if (! self::isTruthyFlag(self::scenarioFieldValue($scenarioResult, 'shape_matches_http'))) {
            $failures[] = [
                'code' => 'sdk_shape_matches_http_not_true',
                'scenario_id' => $scenarioId,
                'field' => 'shape_matches_http',
                'value' => self::scenarioFieldValue($scenarioResult, 'shape_matches_http'),
            ];
        }

        $recorded = self::scenarioFieldValue($scenarioResult, 'recorded_principal');
        $rawHttp = self::scenarioFieldValue($scenarioResult, 'raw_http_reference_principal');
        if (! self::principalShapeMatches($recorded, $rawHttp)) {
            $failures[] = [
                'code' => 'sdk_principal_shape_mismatch',
                'scenario_id' => $scenarioId,
                'recorded_signature' => self::principalShapeSignature($recorded),
                'raw_http_signature' => self::principalShapeSignature($rawHttp),
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function anonymousAttributionEvidenceFailures(array $scenarioResult, string $scenarioId): array
    {
        $expected = ['type' => 'server', 'id' => 'anonymous'];
        $failures = [];

        $documented = self::scenarioFieldValue($scenarioResult, 'documented_value');
        if (! self::principalMatches($documented, $expected)) {
            $failures[] = [
                'code' => 'documented_anonymous_principal_mismatch',
                'scenario_id' => $scenarioId,
                'expected_principal' => $expected,
                'actual_principal' => $documented,
            ];
        }

        $anonymousPrincipal = self::scenarioFieldValue($scenarioResult, 'anonymous_principal');
        if (! self::principalMatches($anonymousPrincipal, $expected)) {
            $failures[] = [
                'code' => 'anonymous_principal_mismatch',
                'scenario_id' => $scenarioId,
                'expected_principal' => $expected,
                'actual_principal' => $anonymousPrincipal,
            ];
        }

        $authDriver = self::stringValue(self::scenarioFieldValue($scenarioResult, 'anonymous_auth_driver'));
        if ($authDriver !== 'none') {
            $failures[] = [
                'code' => 'anonymous_auth_driver_not_none',
                'scenario_id' => $scenarioId,
                'expected_auth_driver' => 'none',
                'actual_auth_driver' => $authDriver,
            ];
        }

        $recorded = self::scenarioFieldValue($scenarioResult, 'recorded_principals');
        if (! is_array($recorded) || $recorded === []) {
            $failures[] = [
                'code' => 'missing_anonymous_recorded_principals',
                'scenario_id' => $scenarioId,
                'field' => 'recorded_principals',
            ];
            $recorded = [];
        }

        $historyEvents = self::scenarioFieldValue($scenarioResult, 'history_events');
        foreach (['WorkflowStarted', 'SignalReceived', 'WorkflowCancelled'] as $eventType) {
            if (! self::historyEventsContain($historyEvents, $eventType)) {
                $failures[] = [
                    'code' => 'missing_anonymous_history_event',
                    'scenario_id' => $scenarioId,
                    'event_type' => $eventType,
                ];
            }

            $actual = $recorded[$eventType] ?? null;
            if (! self::principalMatches($actual, $expected)) {
                $failures[] = [
                    'code' => 'anonymous_event_principal_mismatch',
                    'scenario_id' => $scenarioId,
                    'event_type' => $eventType,
                    'expected_principal' => $expected,
                    'actual_principal' => $actual,
                ];
            }
        }

        foreach ($recorded as $eventType => $principal) {
            if (! is_array($principal) || self::stringValue($principal['id'] ?? null) !== 'mallory') {
                continue;
            }

            $failures[] = [
                'code' => 'anonymous_spoofed_principal_recorded',
                'scenario_id' => $scenarioId,
                'event_type' => (string) $eventType,
                'actual_principal' => $principal,
            ];
        }

        $spoofing = self::scenarioFieldValue($scenarioResult, 'spoofing_attempts');
        if (! is_array($spoofing) || $spoofing === []) {
            $failures[] = [
                'code' => 'missing_anonymous_spoofing_attempts',
                'scenario_id' => $scenarioId,
                'field' => 'spoofing_attempts',
            ];

            return $failures;
        }

        $payloadFields = self::stringSet($spoofing['payload_fields'] ?? $spoofing['payloadFields'] ?? $spoofing['body_fields'] ?? $spoofing['bodyFields'] ?? []);
        if (array_intersect(['principal', 'principal_id', 'principal_type', 'actor', 'user'], $payloadFields) === []) {
            $failures[] = [
                'code' => 'missing_anonymous_spoofing_payload_fields',
                'scenario_id' => $scenarioId,
                'field' => 'spoofing_attempts.payload_fields',
            ];
        }

        $headers = self::stringSet($spoofing['headers'] ?? []);
        $requiredHeaders = ['X-Workflow-Caller-Type', 'X-Workflow-Auth-Method', 'X-Forwarded-User'];
        foreach ($requiredHeaders as $header) {
            if (in_array($header, $headers, true)) {
                continue;
            }

            $failures[] = [
                'code' => 'missing_anonymous_spoofing_gateway_header',
                'scenario_id' => $scenarioId,
                'header' => $header,
            ];
        }

        $actions = self::stringSet($spoofing['actions'] ?? []);
        foreach (['start', 'signal', 'cancel'] as $action) {
            if (in_array($action, $actions, true)) {
                continue;
            }

            $failures[] = [
                'code' => 'missing_anonymous_spoofing_action',
                'scenario_id' => $scenarioId,
                'action' => $action,
            ];
        }

        $executed = $spoofing['executed'] ?? $spoofing['wasExecuted'] ?? null;
        if (! self::isTruthyFlag($executed)) {
            $failures[] = [
                'code' => 'anonymous_spoofing_attempts_not_executed',
                'scenario_id' => $scenarioId,
                'field' => 'spoofing_attempts.executed',
                'value' => $executed,
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, array<string, string>> $requiredActions
     *
     * @return array<int, array<string, mixed>>
     */
    private static function spoofingMatrixEvidenceFailures(array $scenarioResult, string $scenarioId, array $requiredActions): array
    {
        $matrix = self::scenarioFieldValue($scenarioResult, 'spoofing_matrix');
        if (! is_array($matrix) || $matrix === []) {
            return [[
                'code' => 'missing_spoofing_matrix',
                'scenario_id' => $scenarioId,
                'field' => 'spoofing_matrix',
            ]];
        }

        $rowsByAction = [];
        foreach ($matrix as $key => $row) {
            if (! is_array($row)) {
                continue;
            }

            $action = self::stringValue($row['action'] ?? null);
            if ($action === '' && is_string($key)) {
                $action = $key;
            }

            if ($action !== '') {
                $rowsByAction[$action] = $row;
            }
        }

        $failures = [];
        foreach ($requiredActions as $action => $expectedPrincipal) {
            $row = $rowsByAction[$action] ?? null;
            if (! is_array($row)) {
                $failures[] = [
                    'code' => 'missing_spoofing_matrix_action',
                    'scenario_id' => $scenarioId,
                    'action' => $action,
                ];
                continue;
            }

            $requested = self::arrayFromAliases($row, [
                'requested_spoof_values',
                'requestedSpoofValues',
                'requested_values',
                'requestedValues',
                'attempted_values',
                'attemptedValues',
            ]) ?? $row;

            $bodyFields = self::arrayFromAliases($requested, [
                'body_fields',
                'bodyFields',
                'payload_fields',
                'payloadFields',
                'request_body_fields',
                'requestBodyFields',
            ]);
            foreach (self::REQUIRED_SPOOFING_BODY_FIELD_VALUES as $field => $value) {
                if (is_array($bodyFields) && self::stringValue($bodyFields[$field] ?? null) === $value) {
                    continue;
                }

                $failures[] = [
                    'code' => 'missing_spoofing_matrix_body_value',
                    'scenario_id' => $scenarioId,
                    'action' => $action,
                    'field' => $field,
                    'expected_value' => $value,
                    'actual_value' => is_array($bodyFields) ? ($bodyFields[$field] ?? null) : null,
                ];
            }

            $headers = self::arrayFromAliases($requested, [
                'headers',
                'request_headers',
                'requestHeaders',
                'header_values',
                'headerValues',
            ]);
            foreach (self::REQUIRED_SPOOFING_HEADER_VALUES as $header => $value) {
                if (is_array($headers) && self::stringValue($headers[$header] ?? null) === $value) {
                    continue;
                }

                $failures[] = [
                    'code' => 'missing_spoofing_matrix_header_value',
                    'scenario_id' => $scenarioId,
                    'action' => $action,
                    'header' => $header,
                    'expected_value' => $value,
                    'actual_value' => is_array($headers) ? ($headers[$header] ?? null) : null,
                ];
            }

            $observed = $row['observed_principal']
                ?? $row['observedPrincipal']
                ?? $row['recorded_principal']
                ?? $row['recordedPrincipal']
                ?? null;
            if (! is_array($observed)) {
                $failures[] = [
                    'code' => 'missing_spoofing_matrix_observed_principal',
                    'scenario_id' => $scenarioId,
                    'action' => $action,
                ];
                continue;
            }

            if (! self::principalMatches($observed, $expectedPrincipal)) {
                $failures[] = [
                    'code' => 'spoofing_matrix_observed_principal_mismatch',
                    'scenario_id' => $scenarioId,
                    'action' => $action,
                    'expected_principal' => $expectedPrincipal,
                    'actual_principal' => $observed,
                ];
            }

            $hits = self::callerControlledPrincipalValues($observed);
            if ($hits !== []) {
                $failures[] = [
                    'code' => 'caller_controlled_principal_recorded',
                    'scenario_id' => $scenarioId,
                    'action' => $action,
                    'security_severity' => 'P0',
                    'owning_surface' => 'server',
                    'caller_controlled_values' => $hits,
                    'actual_principal' => $observed,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function actionCredentialEvidenceFailures(array $scenarioResult, string $scenarioId): array
    {
        $requiredActions = [
            'start' => [
                'actor' => 'alice',
                'credential_ref' => 'alice-token-v1',
                'credential_label' => 'alice credential v1',
                'principal_id' => 'alice',
            ],
            'signal' => [
                'actor' => 'bob',
                'credential_ref' => 'bob-token',
                'credential_label' => 'bob credential',
                'principal_id' => 'bob',
            ],
            'cancel' => [
                'actor' => 'alice',
                'credential_ref' => 'alice-token-v2',
                'credential_label' => 'alice credential v2',
                'principal_id' => 'alice',
            ],
        ];

        $actions = self::scenarioFieldValue($scenarioResult, 'action_credentials');
        if (! is_array($actions) || $actions === []) {
            return [[
                'code' => 'missing_action_credentials',
                'scenario_id' => $scenarioId,
                'field' => 'action_credentials',
            ]];
        }

        $failures = [];
        foreach ($requiredActions as $action => $expected) {
            $evidence = $actions[$action] ?? null;
            if (! is_array($evidence)) {
                $failures[] = [
                    'code' => 'missing_action_credential',
                    'scenario_id' => $scenarioId,
                    'action' => $action,
                ];
                continue;
            }

            if (self::stringValue($evidence['actor'] ?? null) !== $expected['actor']) {
                $failures[] = [
                    'code' => 'action_credential_actor_mismatch',
                    'scenario_id' => $scenarioId,
                    'action' => $action,
                    'expected_actor' => $expected['actor'],
                    'actual_actor' => self::stringValue($evidence['actor'] ?? null),
                ];
            }

            if (self::actionCredentialRef($evidence) !== $expected['credential_ref']) {
                $failures[] = [
                    'code' => 'action_credential_ref_mismatch',
                    'scenario_id' => $scenarioId,
                    'action' => $action,
                    'expected_credential_ref' => $expected['credential_ref'],
                    'actual_credential_ref' => self::actionCredentialRef($evidence),
                ];
            }

            if (self::actionCredentialLabel($evidence) !== $expected['credential_label']) {
                $failures[] = [
                    'code' => 'action_credential_label_mismatch',
                    'scenario_id' => $scenarioId,
                    'action' => $action,
                    'expected_credential_label' => $expected['credential_label'],
                    'actual_credential_label' => self::actionCredentialLabel($evidence),
                ];
            }

            if (self::actionCredentialPrincipalId($evidence) !== $expected['principal_id']) {
                $failures[] = [
                    'code' => 'action_credential_principal_mismatch',
                    'scenario_id' => $scenarioId,
                    'action' => $action,
                    'expected_principal_id' => $expected['principal_id'],
                    'actual_principal_id' => self::actionCredentialPrincipalId($evidence),
                ];
            }

            if (self::actionCredentialObservedPrincipalId($evidence) !== $expected['principal_id']) {
                $failures[] = [
                    'code' => 'action_credential_observed_principal_mismatch',
                    'scenario_id' => $scenarioId,
                    'action' => $action,
                    'expected_principal_id' => $expected['principal_id'],
                    'actual_principal_id' => self::actionCredentialObservedPrincipalId($evidence),
                ];
            }

            if (self::actionCredentialObservedPrincipalId($evidence) === self::actionCredentialRef($evidence)) {
                $failures[] = [
                    'code' => 'credential_ref_recorded_as_principal',
                    'scenario_id' => $scenarioId,
                    'action' => $action,
                    'credential_ref' => self::actionCredentialRef($evidence),
                ];
            }

            if (self::actionCredentialMaterialRecordedAsPrincipal($evidence) !== false) {
                $failures[] = [
                    'code' => 'credential_material_recorded_as_principal_not_false',
                    'scenario_id' => $scenarioId,
                    'action' => $action,
                    'value' => self::actionCredentialMaterialRecordedAsPrincipal($evidence),
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function credentialRotationEvidenceFailures(array $scenarioResult, string $scenarioId): array
    {
        $rotation = self::scenarioFieldValue($scenarioResult, 'credential_rotation');
        if (! is_array($rotation) || $rotation === []) {
            return [[
                'code' => 'missing_credential_rotation_evidence',
                'scenario_id' => $scenarioId,
                'field' => 'credential_rotation',
            ]];
        }

        $failures = [];
        if (self::stringValue($rotation['actor'] ?? null) !== 'alice') {
            $failures[] = [
                'code' => 'credential_rotation_actor_mismatch',
                'scenario_id' => $scenarioId,
                'expected_actor' => 'alice',
                'actual_actor' => self::stringValue($rotation['actor'] ?? null),
            ];
        }

        if (self::stringValue($rotation['stable_principal_id'] ?? $rotation['stablePrincipalId'] ?? null) !== 'alice') {
            $failures[] = [
                'code' => 'credential_rotation_stable_principal_mismatch',
                'scenario_id' => $scenarioId,
                'expected_principal_id' => 'alice',
                'actual_principal_id' => self::stringValue($rotation['stable_principal_id'] ?? $rotation['stablePrincipalId'] ?? null),
            ];
        }

        if (self::booleanValue($rotation['credential_material_recorded_as_principal'] ?? $rotation['credentialMaterialRecordedAsPrincipal'] ?? null) !== false) {
            $failures[] = [
                'code' => 'credential_rotation_material_recorded_as_principal_not_false',
                'scenario_id' => $scenarioId,
                'value' => $rotation['credential_material_recorded_as_principal'] ?? $rotation['credentialMaterialRecordedAsPrincipal'] ?? null,
            ];
        }

        foreach ([
            'before' => [
                'credential_ref' => 'alice-token-v1',
                'credential_label' => 'alice credential v1',
                'principal_id' => 'alice',
            ],
            'after' => [
                'credential_ref' => 'alice-token-v2',
                'credential_label' => 'alice credential v2',
                'principal_id' => 'alice',
            ],
        ] as $phase => $expected) {
            $evidence = $rotation[$phase] ?? null;
            if (! is_array($evidence)) {
                $failures[] = [
                    'code' => 'missing_credential_rotation_phase',
                    'scenario_id' => $scenarioId,
                    'phase' => $phase,
                ];
                continue;
            }

            if (self::actionCredentialRef($evidence) !== $expected['credential_ref']) {
                $failures[] = [
                    'code' => 'credential_rotation_ref_mismatch',
                    'scenario_id' => $scenarioId,
                    'phase' => $phase,
                    'expected_credential_ref' => $expected['credential_ref'],
                    'actual_credential_ref' => self::actionCredentialRef($evidence),
                ];
            }

            if (self::actionCredentialLabel($evidence) !== $expected['credential_label']) {
                $failures[] = [
                    'code' => 'credential_rotation_label_mismatch',
                    'scenario_id' => $scenarioId,
                    'phase' => $phase,
                    'expected_credential_label' => $expected['credential_label'],
                    'actual_credential_label' => self::actionCredentialLabel($evidence),
                ];
            }

            if (self::actionCredentialObservedPrincipalId($evidence) !== $expected['principal_id']) {
                $failures[] = [
                    'code' => 'credential_rotation_observed_principal_mismatch',
                    'scenario_id' => $scenarioId,
                    'phase' => $phase,
                    'expected_principal_id' => $expected['principal_id'],
                    'actual_principal_id' => self::actionCredentialObservedPrincipalId($evidence),
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private static function actionCredentialRef(array $evidence): string
    {
        return self::stringValue(
            $evidence['credential_ref']
                ?? $evidence['credentialRef']
                ?? $evidence['credential']
                ?? null,
        );
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private static function actionCredentialLabel(array $evidence): string
    {
        return self::stringValue(
            $evidence['credential_label']
                ?? $evidence['credentialLabel']
                ?? $evidence['label']
                ?? null,
        );
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private static function actionCredentialPrincipalId(array $evidence): string
    {
        $principal = $evidence['expected_principal'] ?? $evidence['expectedPrincipal'] ?? null;
        if (is_array($principal)) {
            $id = self::stringValue($principal['id'] ?? null);
            if ($id !== '') {
                return $id;
            }
        }

        return self::stringValue(
            $evidence['principal_id']
                ?? $evidence['principalId']
                ?? $evidence['expected_principal_id']
                ?? $evidence['expectedPrincipalId']
                ?? null,
        );
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private static function actionCredentialObservedPrincipalId(array $evidence): string
    {
        $principal = $evidence['observed_principal']
            ?? $evidence['observedPrincipal']
            ?? $evidence['recorded_principal']
            ?? $evidence['recordedPrincipal']
            ?? null;
        if (is_array($principal)) {
            $id = self::stringValue($principal['id'] ?? null);
            if ($id !== '') {
                return $id;
            }
        }

        return self::stringValue(
            $evidence['observed_principal_id']
                ?? $evidence['observedPrincipalId']
                ?? $evidence['recorded_principal_id']
                ?? $evidence['recordedPrincipalId']
                ?? null,
        );
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private static function actionCredentialMaterialRecordedAsPrincipal(array $evidence): ?bool
    {
        return self::booleanValue(
            $evidence['credential_material_recorded_as_principal']
                ?? $evidence['credentialMaterialRecordedAsPrincipal']
                ?? null,
        );
    }

    private static function booleanValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     */
    private static function scenarioFieldValue(array $scenarioResult, string $field): mixed
    {
        return $scenarioResult[$field] ?? $scenarioResult[self::camelize($field)] ?? null;
    }

    private static function isEmptyEvidenceValue(mixed $value): bool
    {
        return $value === null
            || $value === []
            || (is_string($value) && trim($value) === '');
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $result
     */
    private static function hasFocusedLinkedFinding(array $scenarioResult, array $result, string $scenarioId): bool
    {
        $findingReferences = self::structuredFindingsByReference($scenarioResult, $result);

        foreach (['linked_findings', 'linkedFindings'] as $field) {
            $linked = self::arrayValue($scenarioResult, $field);
            if (self::containsFocusedFinding($linked, $scenarioId, $findingReferences)) {
                return true;
            }
        }

        foreach (['finding_links', 'findingLinks'] as $field) {
            $linked = self::arrayValue($result, $field);
            if ($linked === null) {
                continue;
            }

            if (array_key_exists($scenarioId, $linked)
                && self::containsFocusedFinding($linked[$scenarioId], $scenarioId, $findingReferences)
            ) {
                return true;
            }

            if (self::containsFocusedFinding($linked, $scenarioId, $findingReferences)) {
                return true;
            }
        }

        foreach (['findings'] as $field) {
            $findings = self::arrayValue($scenarioResult, $field);
            if (self::containsFocusedFinding($findings, $scenarioId, $findingReferences)) {
                return true;
            }
        }

        $findings = self::arrayValue($result, 'findings');
        if ($findings !== null) {
            if (array_key_exists($scenarioId, $findings)
                && self::containsFocusedFinding($findings[$scenarioId], $scenarioId, $findingReferences)
            ) {
                return true;
            }

            if (self::containsFocusedFinding($findings, $scenarioId, $findingReferences)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $linked
     * @param array<string, array<string, mixed>> $findingReferences
     */
    private static function containsFocusedFinding(mixed $linked, string $scenarioId, array $findingReferences): bool
    {
        if (is_string($linked)) {
            $reference = trim($linked);

            return $reference !== ''
                && array_key_exists($reference, $findingReferences)
                && self::isFocusedFinding($findingReferences[$reference], $scenarioId);
        }

        if (! is_array($linked)) {
            return false;
        }

        if (self::isFocusedFinding($linked, $scenarioId)) {
            return true;
        }

        if (self::looksLikeFinding($linked)) {
            return false;
        }

        foreach ($linked as $item) {
            if (self::containsFocusedFinding($item, $scenarioId, $findingReferences)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $finding
     */
    private static function isFocusedFinding(mixed $finding, string $scenarioId): bool
    {
        if (! is_array($finding)) {
            return false;
        }

        $linkedScenario = self::stringValue(
            self::findingFieldValue($finding, 'scenario_id'),
        );
        if ($linkedScenario !== $scenarioId) {
            return false;
        }

        foreach ([
            'owning_surface',
            'artifact_versions',
            'observed_behavior',
            'expected_behavior',
            'next_acceptance_criterion',
        ] as $field) {
            if (self::isEmptyFindingValue(self::findingFieldValue($finding, $field))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $result
     *
     * @return array<string, array<string, mixed>>
     */
    private static function structuredFindingsByReference(array $scenarioResult, array $result): array
    {
        $references = [];
        foreach ([
            self::arrayValue($scenarioResult, 'linked_findings'),
            self::arrayValue($scenarioResult, 'findings'),
            self::arrayValue($scenarioResult, 'finding_links'),
            self::arrayValue($result, 'findings'),
            self::arrayValue($result, 'linked_findings'),
            self::arrayValue($result, 'finding_links'),
        ] as $container) {
            self::collectStructuredFindingReferences($container, $references);
        }

        return $references;
    }

    /**
     * @param array<string, array<string, mixed>> $references
     */
    private static function collectStructuredFindingReferences(mixed $container, array &$references, ?string $mapKey = null): void
    {
        if (! is_array($container)) {
            return;
        }

        if (self::looksLikeFinding($container)) {
            if (self::findingFieldValue($container, 'scenario_id') !== null) {
                foreach (self::findingReferenceKeys($container, $mapKey) as $key) {
                    $references[$key] = $container;
                }
            }

            return;
        }

        foreach ($container as $key => $value) {
            self::collectStructuredFindingReferences(
                $value,
                $references,
                is_string($key) ? $key : null,
            );
        }
    }

    /**
     * @param array<string, mixed> $finding
     *
     * @return list<string>
     */
    private static function findingReferenceKeys(array $finding, ?string $mapKey): array
    {
        $keys = [];
        foreach (['id', 'finding_id', 'findingId', 'link', 'url'] as $field) {
            $key = self::stringValue($finding[$field] ?? null);
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        if ($mapKey !== null && $mapKey !== '') {
            $keys[] = $mapKey;
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param array<string, mixed> $finding
     */
    private static function findingFieldValue(array $finding, string $field): mixed
    {
        $aliases = [
            'scenario_id' => ['scenario_id', 'scenarioId', 'scenario'],
            'owning_surface' => ['owning_surface', 'owningSurface', 'surface', 'owner'],
            'artifact_versions' => ['artifact_versions', 'artifactVersions'],
            'observed_behavior' => ['observed_behavior', 'observedBehavior', 'current_evidence'],
            'expected_behavior' => ['expected_behavior', 'expectedBehavior'],
            'next_acceptance_criterion' => ['next_acceptance_criterion', 'nextAcceptanceCriterion', 'acceptance'],
        ];

        foreach ($aliases[$field] ?? [$field] as $key) {
            if (array_key_exists($key, $finding)) {
                return $finding[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function looksLikeFinding(array $value): bool
    {
        foreach ([
            'id',
            'finding_id',
            'findingId',
            'scenario_id',
            'scenarioId',
            'scenario',
            'owning_surface',
            'owningSurface',
            'surface',
            'owner',
            'observed_behavior',
            'observedBehavior',
            'current_evidence',
            'expected_behavior',
            'expectedBehavior',
            'next_acceptance_criterion',
            'nextAcceptanceCriterion',
            'acceptance',
        ] as $field) {
            if (array_key_exists($field, $value)) {
                return true;
            }
        }

        return false;
    }

    private static function isEmptyFindingValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * @param list<string> $missingScenarios
     * @param array<string, mixed> $result
     *
     * @return array<int, array<string, mixed>>
     */
    private static function missingScenarioFindingFailures(array $missingScenarios, array $result): array
    {
        $failures = [];
        foreach ($missingScenarios as $scenarioId) {
            if (self::hasFocusedLinkedFinding(['scenario_id' => $scenarioId], $result, $scenarioId)) {
                continue;
            }

            $failures[] = [
                'code' => 'missing_focused_finding_for_omitted_scenario',
                'scenario_id' => $scenarioId,
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return array<int, array<string, mixed>>
     */
    private static function runRecordFailures(array $result, array $contract): array
    {
        $requiredFields = self::stringList($contract['artifact_policy']['required_run_record_fields'] ?? []);
        $failures = [];
        foreach ($requiredFields as $field) {
            if (self::hasRunRecordField($result, $field)) {
                continue;
            }

            $failures[] = [
                'code' => 'missing_run_record_field',
                'field' => $field,
            ];
        }

        $runnerBlocked = self::runnerBlockedValue($result);
        if ($runnerBlocked !== null && $runnerBlocked !== false) {
            $failures[] = [
                'code' => 'runner_blocked_result_is_not_product_evidence',
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<int, array<string, mixed>>
     */
    private static function anonymousTopologyFailures(array $result): array
    {
        if (self::declaredOutcomeStatus(self::declaredOutcome($result)) !== 'pass') {
            return [];
        }

        $topology = self::arrayValue($result, 'topology');
        if ($topology === null) {
            return [[
                'code' => 'missing_anonymous_no_auth_topology',
                'field' => 'topology',
            ]];
        }

        $failures = [];
        $authDriver = self::stringValue($topology['anonymous_auth_driver'] ?? $topology['anonymousAuthDriver'] ?? null);
        if ($authDriver !== 'none') {
            $failures[] = [
                'code' => 'anonymous_topology_auth_driver_not_none',
                'field' => 'topology.anonymous_auth_driver',
                'expected_auth_driver' => 'none',
                'actual_auth_driver' => $authDriver,
            ];
        }

        $serverUrl = self::stringValue($topology['anonymous_server_url'] ?? $topology['anonymousServerUrl'] ?? null);
        if ($serverUrl === '') {
            $failures[] = [
                'code' => 'anonymous_topology_server_url_missing',
                'field' => 'topology.anonymous_server_url',
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function hasRunRecordField(array $result, string $field): bool
    {
        return match ($field) {
            'published_artifact_versions' => self::arrayValue($result, 'published_artifact_versions') !== null,
            'resolved_artifact_versions' => self::arrayValue($result, 'resolved_artifact_versions') !== null,
            'started_at' => self::hasScalarField($result, ['started_at', 'startedAt']),
            'finished_at' => self::hasScalarField($result, ['finished_at', 'finishedAt']),
            'generated_at' => self::hasScalarField($result, ['generated_at', 'generatedAt']),
            'outcome' => self::hasScalarField($result, ['outcome', 'status', 'verdict']),
            'runner_blocked' => array_key_exists('runner_blocked', $result) || array_key_exists('runnerBlocked', $result),
            'scenario_results' => self::hasArrayField($result, ['scenario_results', 'scenarioResults']),
            'findings' => array_key_exists('findings', $result),
            default => self::hasScalarField($result, [$field, self::camelize($field)])
                || self::hasArrayField($result, [$field, self::camelize($field)]),
        };
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return array<int, array<string, mixed>>
     */
    private static function artifactVersionFailures(array $result, array $contract): array
    {
        $required = array_keys(self::arrayValue($contract['artifact_policy'] ?? [], 'install_channels') ?? []);
        $aliases = self::arrayValue($contract['artifact_policy'] ?? [], 'release_artifact_aliases') ?? [];
        $failures = [];

        foreach ([
            'published_artifact_versions',
            'resolved_artifact_versions',
        ] as $field) {
            $versions = self::arrayValue($result, $field);
            if ($versions === null) {
                continue;
            }

            foreach ($required as $artifact) {
                $version = self::artifactVersionValue($versions, (string) $artifact, $aliases);
                if ($version === '') {
                    $failures[] = [
                        'code' => 'missing_artifact_version',
                        'field' => $field,
                        'artifact' => $artifact,
                    ];
                    continue;
                }

                if (self::isPlaceholderVersion($version)) {
                    $failures[] = [
                        'code' => 'placeholder_artifact_version',
                        'field' => $field,
                        'artifact' => $artifact,
                        'version' => $version,
                    ];
                }
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return array<int, array<string, mixed>>
     */
    private static function sourcePolicyFailures(array $result, array $contract, array $scenarioResults): array
    {
        $failures = [];

        foreach (self::localProductSourceCheckoutValues($result, $scenarioResults) as $flag) {
            if (! self::isTruthyFlag($flag['value'] ?? null)) {
                continue;
            }

            $failure = [
                'code' => 'local_product_source_checkout_used',
                'field' => $flag['field'],
            ];
            if (($flag['scenario_id'] ?? null) !== null) {
                $failure['scenario_id'] = $flag['scenario_id'];
            }

            $failures[] = $failure;
        }

        $forbiddenSources = self::stringList(
            ($contract['artifact_policy']['forbidden_sources'] ?? null)
                ?? ($contract['artifactPolicy']['forbiddenSources'] ?? null)
                ?? [
                    'local_product_source_checkout',
                    'workspace_repo_as_artifact_under_test',
                    'caller_supplied_principal_as_authority',
                    'rolling_server_image_tag',
                ],
        );
        $forbiddenSources[] = 'repos/';

        foreach (self::artifactSourceSets($result, $scenarioResults) as $sourceSet) {
            foreach ($sourceSet['sources'] as $artifact => $source) {
                $sourceText = self::stringValue($source);
                if (! self::isForbiddenArtifactSource($sourceText, $forbiddenSources)) {
                    continue;
                }

                $failure = [
                    'code' => 'forbidden_artifact_source',
                    'artifact' => (string) $artifact,
                    'source' => $source,
                    'field' => $sourceSet['field'],
                ];
                if (($sourceSet['scenario_id'] ?? null) !== null) {
                    $failure['scenario_id'] = $sourceSet['scenario_id'];
                }

                $failures[] = $failure;
            }
        }

        $install = $scenarioResults['published_artifact_install_only'] ?? [];
        if (self::stringValue($install['status'] ?? null) === 'pass') {
            array_push(
                $failures,
                ...self::publishedArtifactInstallEvidenceFailures($contract, $install),
            );
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function publishedArtifactInstallEvidenceFailures(array $contract, array $scenarioResult): array
    {
        $required = array_keys(self::arrayValue($contract['artifact_policy'] ?? [], 'install_channels') ?? []);
        $aliases = self::arrayValue($contract['artifact_policy'] ?? [], 'release_artifact_aliases') ?? [];
        $outputs = self::arrayValue($scenarioResult, 'observed_outputs') ?? [];
        $sources = [];

        foreach ([$scenarioResult, $outputs] as $container) {
            foreach (['artifact_sources', 'install_sources'] as $field) {
                $reportedSources = self::arrayValue($container, $field);
                if ($reportedSources !== null) {
                    $sources = array_replace($sources, $reportedSources);
                }
            }
        }

        $failures = [];
        foreach ($required as $artifact) {
            $artifact = (string) $artifact;
            if (self::artifactVersionValue($sources, $artifact, $aliases) === '') {
                $failures[] = [
                    'code' => 'missing_published_artifact_install_source',
                    'scenario_id' => 'published_artifact_install_only',
                    'artifact' => $artifact,
                ];
            }
        }

        $installVersions = self::arrayValue($scenarioResult, 'resolved_artifact_versions')
            ?? self::arrayValue($outputs, 'resolved_artifact_versions')
            ?? [];
        foreach ($required as $artifact) {
            $artifact = (string) $artifact;
            $version = self::artifactVersionValue($installVersions, $artifact, $aliases);
            if ($version === '') {
                $failures[] = [
                    'code' => 'missing_published_artifact_install_version',
                    'scenario_id' => 'published_artifact_install_only',
                    'field' => 'resolved_artifact_versions',
                    'artifact' => $artifact,
                ];
                continue;
            }

            if (self::isPlaceholderVersion($version)) {
                $failures[] = [
                    'code' => 'placeholder_published_artifact_install_version',
                    'scenario_id' => 'published_artifact_install_only',
                    'field' => 'resolved_artifact_versions',
                    'artifact' => $artifact,
                    'version' => $version,
                ];
            }
        }

        if (($scenarioResult['local_product_source_checkouts_used'] ?? null) !== false
            && ($scenarioResult['localProductSourceCheckoutsUsed'] ?? null) !== false
        ) {
            $failures[] = [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'scenario_id' => 'published_artifact_install_only',
                'field' => 'local_product_source_checkouts_used',
                'value' => $scenarioResult['local_product_source_checkouts_used']
                    ?? $scenarioResult['localProductSourceCheckoutsUsed']
                    ?? null,
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return list<array{sources: array<string, mixed>, field: string, scenario_id: string|null}>
     */
    private static function artifactSourceSets(array $result, array $scenarioResults): array
    {
        $sets = [];
        foreach (['artifact_sources', 'install_sources'] as $field) {
            $sources = self::arrayValue($result, $field);
            if ($sources !== null) {
                $sets[] = [
                    'sources' => $sources,
                    'field' => $field,
                    'scenario_id' => null,
                ];
            }
        }

        foreach ($scenarioResults as $scenarioId => $scenarioResult) {
            foreach ([
                $scenarioResult,
                self::arrayValue($scenarioResult, 'observed_outputs') ?? [],
            ] as $container) {
                foreach (['artifact_sources', 'install_sources'] as $field) {
                    $sources = self::arrayValue($container, $field);
                    if ($sources === null) {
                        continue;
                    }

                    $sets[] = [
                        'sources' => $sources,
                        'field' => $field,
                        'scenario_id' => $scenarioId,
                    ];
                }
            }
        }

        return $sets;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return list<array{field: string, value: mixed, scenario_id: string|null}>
     */
    private static function localProductSourceCheckoutValues(array $result, array $scenarioResults): array
    {
        $values = [];
        foreach (['local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed'] as $field) {
            if (array_key_exists($field, $result)) {
                $values[] = [
                    'field' => $field,
                    'value' => $result[$field],
                    'scenario_id' => null,
                ];
            }
        }

        foreach ($scenarioResults as $scenarioId => $scenarioResult) {
            foreach ([
                $scenarioResult,
                self::arrayValue($scenarioResult, 'observed_outputs') ?? [],
            ] as $container) {
                foreach (['local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed'] as $field) {
                    if (! array_key_exists($field, $container)) {
                        continue;
                    }

                    $values[] = [
                        'field' => $field,
                        'value' => $container[$field],
                        'scenario_id' => $scenarioId,
                    ];
                }
            }
        }

        return $values;
    }

    private static function isTruthyFlag(mixed $value): bool
    {
        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }

    /**
     * @param list<string> $forbiddenSources
     */
    private static function isForbiddenArtifactSource(string $source, array $forbiddenSources): bool
    {
        $normalized = strtolower(trim($source));
        if ($normalized === '') {
            return false;
        }

        foreach ($forbiddenSources as $forbiddenSource) {
            $forbiddenSource = strtolower(trim($forbiddenSource));
            if ($forbiddenSource === '') {
                continue;
            }

            if ($normalized === $forbiddenSource || str_contains($normalized, $forbiddenSource)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $versions
     * @param array<string, mixed> $aliases
     */
    private static function artifactVersionValue(array $versions, string $artifact, array $aliases = []): string
    {
        $candidateNames = [$artifact, ...(self::stringList($aliases[$artifact] ?? []))];
        foreach ($candidateNames as $name) {
            $version = self::stringValue($versions[$name] ?? null);
            if (array_key_exists($name, $versions) && $version !== '') {
                return $version;
            }
        }

        return '';
    }

    private static function isPlaceholderVersion(string $version): bool
    {
        $normalized = strtolower(trim($version));

        return $normalized === ''
            || str_contains($normalized, 'latest')
            || str_contains($normalized, 'current')
            || str_contains($normalized, 'head')
            || str_contains($normalized, 'unresolved')
            || str_contains($normalized, 'placeholder')
            || str_contains($normalized, '<')
            || str_contains($normalized, '>')
            || str_contains($normalized, '${')
            || str_contains($normalized, '{{');
    }

    /**
     * @return list<string>
     */
    private static function callerControlledPrincipalValues(mixed $principal): array
    {
        $markers = array_map(
            static fn (string $value): string => strtolower(trim($value)),
            array_merge(
                array_values(self::REQUIRED_SPOOFING_BODY_FIELD_VALUES),
                array_values(self::REQUIRED_SPOOFING_HEADER_VALUES),
            ),
        );
        $markers = array_values(array_unique(array_filter($markers, static fn (string $value): bool => $value !== '')));
        $hits = [];

        foreach (self::recursiveStringValues($principal) as $value) {
            $normalized = strtolower(trim($value));
            if ($normalized === '' || ! in_array($normalized, $markers, true)) {
                continue;
            }

            $hits[] = $value;
        }

        return array_values(array_unique($hits));
    }

    /**
     * @return list<string>
     */
    private static function recursiveStringValues(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            array_push($strings, ...self::recursiveStringValues($item));
        }

        return $strings;
    }

    /**
     * @param array<string, string> $expected
     */
    private static function principalMatches(mixed $principal, array $expected): bool
    {
        if (! is_array($principal)) {
            return false;
        }

        foreach ($expected as $field => $value) {
            if (self::stringValue($principal[$field] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private static function principalShapeMatches(mixed $principal, mixed $reference): bool
    {
        $signature = self::principalShapeSignature($principal);
        $referenceSignature = self::principalShapeSignature($reference);

        return $signature !== null && $signature === $referenceSignature;
    }

    /**
     * @return array<string, string>|null
     */
    private static function principalShapeSignature(mixed $principal): ?array
    {
        if (! is_array($principal)) {
            return null;
        }

        $signature = [];
        foreach ($principal as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $signature[$key] = get_debug_type($value);
        }

        ksort($signature);

        return $signature;
    }

    private static function historyEventsContain(mixed $historyEvents, string $eventType): bool
    {
        if (! is_array($historyEvents)) {
            return false;
        }

        if (array_key_exists($eventType, $historyEvents)) {
            return true;
        }

        foreach ($historyEvents as $event) {
            if ($event === $eventType) {
                return true;
            }

            if (! is_array($event)) {
                continue;
            }

            $reported = self::stringValue(
                $event['event_type']
                    ?? $event['eventType']
                    ?? $event['type']
                    ?? null,
            );
            if ($reported === $eventType) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function stringSet(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && $key !== '') {
                $strings[] = $key;
            }

            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }

        return array_values(array_unique($strings));
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function declaredOutcome(array $result): string
    {
        foreach (['outcome', 'status', 'verdict'] as $field) {
            $value = self::stringValue($result[$field] ?? null);
            if ($value !== '') {
                return strtolower($value);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return array<int, array<string, mixed>>
     */
    private static function declaredOutcomeFailures(array $result, array $contract): array
    {
        $declaredOutcomes = self::declaredOutcomeTokens($result);
        if ($declaredOutcomes === []) {
            return [];
        }

        $allowedOutcomes = self::declaredOutcomes($contract);
        $failures = [];
        foreach ($declaredOutcomes as $field => $outcome) {
            if (in_array($outcome, $allowedOutcomes, true)) {
                continue;
            }

            $failures[] = [
                'code' => 'invalid_declared_outcome',
                'field' => $field,
                'outcome' => $outcome,
                'allowed_outcomes' => $allowedOutcomes,
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return array<int, array<string, mixed>>
     */
    private static function declaredOutcomeStatusFailures(
        array $result,
        array $contract,
        string $evaluatedStatus,
    ): array
    {
        $declaredOutcomes = self::declaredOutcomeTokens($result);
        if ($declaredOutcomes === []) {
            return [];
        }

        $allowedOutcomes = self::declaredOutcomes($contract);
        $failures = [];
        $declaredStatuses = [];
        foreach ($declaredOutcomes as $field => $outcome) {
            if (! in_array($outcome, $allowedOutcomes, true)) {
                continue;
            }

            $declaredStatus = self::declaredOutcomeStatus($outcome);
            $declaredStatuses[$field] = $declaredStatus;
            if ($declaredStatus === $evaluatedStatus) {
                continue;
            }

            $failures[] = [
                'code' => 'declared_outcome_status_mismatch',
                'field' => $field,
                'outcome' => $outcome,
                'declared_status' => $declaredStatus,
                'evaluated_status' => $evaluatedStatus,
            ];
        }

        if (count(array_unique($declaredStatuses)) > 1) {
            $conflictingOutcomes = array_intersect_key($declaredOutcomes, $declaredStatuses);
            $failure = [
                'code' => 'conflicting_outcome_tokens',
                'declared_outcomes' => $conflictingOutcomes,
                'declared_statuses' => $declaredStatuses,
            ];
            foreach (['outcome', 'status', 'verdict'] as $field) {
                if (array_key_exists($field, $conflictingOutcomes)) {
                    $failure[$field] = $conflictingOutcomes[$field];
                }
            }

            $failures[] = $failure;
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function runnerBlockedValue(array $result): ?bool
    {
        foreach (['runner_blocked', 'runnerBlocked'] as $field) {
            if (! array_key_exists($field, $result)) {
                continue;
            }

            if (is_bool($result[$field])) {
                return $result[$field];
            }

            $value = strtolower(trim(self::stringValue($result[$field])));
            if (in_array($value, ['true', '1'], true)) {
                return true;
            }

            if (in_array($value, ['false', '0'], true)) {
                return false;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, string>
     */
    private static function declaredOutcomeTokens(array $result): array
    {
        $declaredOutcomes = [];
        foreach (['outcome', 'status', 'verdict'] as $field) {
            $value = strtolower(trim(self::stringValue($result[$field] ?? null)));
            if ($value !== '') {
                $declaredOutcomes[$field] = $value;
            }
        }

        return $declaredOutcomes;
    }

    /**
     * @param array<string, mixed> $contract
     *
     * @return list<string>
     */
    private static function declaredOutcomes(array $contract): array
    {
        $outcomes = [
            'pass',
            'passed',
            'success',
            'full',
            'fail',
            'failed',
            'failure',
            'error',
            'non_passing',
        ];

        foreach (self::stringList($contract['scenario_statuses'] ?? []) as $status) {
            $outcomes[] = $status;
        }

        $coverageGate = self::arrayValue($contract, 'coverage_gate') ?? [];
        foreach ($coverageGate as $key => $value) {
            if (! is_string($key) || ! str_ends_with($key, '_outcome')) {
                continue;
            }

            $outcome = strtolower(trim(self::stringValue($value)));
            if ($outcome !== '') {
                $outcomes[] = $outcome;
            }
        }

        return array_values(array_unique($outcomes));
    }

    private static function declaredOutcomeStatus(string $outcome): string
    {
        return in_array($outcome, ['pass', 'passed', 'success', 'full'], true) ? 'pass' : 'non_passing';
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @param array<string, mixed> $array
     *
     * @return array<string, mixed>|null
     */
    private static function arrayValue(array $array, string $field): ?array
    {
        $value = $array[$field] ?? $array[self::camelize($field)] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $array
     * @param list<string> $fields
     *
     * @return array<string, mixed>|null
     */
    private static function arrayFromAliases(array $array, array $fields): ?array
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $array)) {
                continue;
            }

            return is_array($array[$field]) ? $array[$field] : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $array
     * @param list<string> $fields
     */
    private static function hasScalarField(array $array, array $fields): bool
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $array)) {
                continue;
            }

            if (is_scalar($array[$field]) && $array[$field] !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $array
     * @param list<string> $fields
     */
    private static function hasArrayField(array $array, array $fields): bool
    {
        foreach ($fields as $field) {
            $value = self::arrayValue($array, $field);
            if ($value !== null && $value !== []) {
                return true;
            }
        }

        return false;
    }

    private static function stringValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function camelize(string $field): string
    {
        return preg_replace_callback(
            '/_([a-z])/',
            static fn (array $matches): string => strtoupper($matches[1]),
            $field,
        ) ?? $field;
    }
}
