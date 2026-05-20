<?php

namespace App\Support;

/**
 * Evaluates namespace conformance results against the full runtime surface
 * exposed by NamespaceRuntimeContract.
 */
final class NamespaceRuntimeResultGate
{
    public const SCHEMA = 'durable-workflow.v2.namespace-runtime.result-gate';

    public const VERSION = 1;

    private const SCENARIO_EVIDENCE_REQUIREMENTS = [
        'published_artifact_install_only' => [
            'section' => 'published_artifact_install',
            'fields' => [
                'server_image' => ['server_image', 'serverImage'],
                'cli_release' => ['cli_release', 'cliRelease'],
                'workflow_php_package' => ['workflow_php_package', 'workflowPhpPackage', 'workflow_package'],
                'sdk_python_package' => ['sdk_python_package', 'sdkPythonPackage', 'python_package'],
                'waterline_artifact' => ['waterline_artifact', 'waterlineArtifact'],
            ],
        ],
        'namespace_create_update_describe_and_list' => [
            'section' => 'namespace_crud_behavior',
            'fields' => [
                'created_namespaces' => ['created_namespaces', 'createdNamespaces'],
                'updated_namespace' => ['updated_namespace', 'updatedNamespace'],
                'described_namespaces' => ['described_namespaces', 'describedNamespaces'],
                'listed_namespaces' => ['listed_namespaces', 'listedNamespaces'],
            ],
        ],
        'workflow_cross_namespace_visibility_isolation' => [
            'section' => 'workflow_visibility_isolation',
            'fields' => [
                'tenant_a_workflow' => ['tenant_a_workflow', 'tenantAWorkflow'],
                'tenant_b_workflow' => ['tenant_b_workflow', 'tenantBWorkflow'],
                'tenant_a_list_excludes_tenant_b' => [
                    'tenant_a_list_excludes_tenant_b',
                    'tenantAListExcludesTenantB',
                ],
                'tenant_b_describe_tenant_a_denied' => [
                    'tenant_b_describe_tenant_a_denied',
                    'tenantBDescribeTenantADenied',
                ],
            ],
        ],
        'workflow_cross_namespace_mutation_isolation' => [
            'section' => 'workflow_mutation_isolation',
            'fields' => [
                'same_namespace_signal_succeeds' => [
                    'same_namespace_signal_succeeds',
                    'sameNamespaceSignalSucceeds',
                ],
                'same_namespace_cancel_succeeds' => [
                    'same_namespace_cancel_succeeds',
                    'sameNamespaceCancelSucceeds',
                ],
                'cross_namespace_signal_denied' => [
                    'cross_namespace_signal_denied',
                    'crossNamespaceSignalDenied',
                ],
                'cross_namespace_cancel_denied' => [
                    'cross_namespace_cancel_denied',
                    'crossNamespaceCancelDenied',
                ],
            ],
        ],
        'namespace_lifecycle_cleanup_and_recreate' => [
            'section' => 'namespace_lifecycle_cleanup',
            'fields' => [
                'deleted_namespace' => ['deleted_namespace', 'deletedNamespace'],
                'workflow_cleanup' => ['workflow_cleanup', 'workflowCleanup'],
                'schedule_cleanup' => ['schedule_cleanup', 'scheduleCleanup'],
                'search_attribute_cleanup' => ['search_attribute_cleanup', 'searchAttributeCleanup'],
                'worker_registration_cleanup' => ['worker_registration_cleanup', 'workerRegistrationCleanup'],
                'recreate_state_empty' => ['recreate_state_empty', 'recreateStateEmpty'],
                'external_payload_contexts_checked' => [
                    'external_payload_contexts_checked',
                    'externalPayloadContextsChecked',
                ],
            ],
        ],
        'nexus_explicit_cross_namespace_invocation' => [
            'section' => 'nexus_cross_namespace',
            'fields' => [
                'service_endpoint_namespace' => ['service_endpoint_namespace', 'serviceEndpointNamespace'],
                'caller_namespaces' => ['caller_namespaces', 'callerNamespaces'],
                'target_namespace' => ['target_namespace', 'targetNamespace'],
                'successful_results' => ['successful_results', 'successfulResults'],
                'direct_access_without_nexus_blocked' => [
                    'direct_access_without_nexus_blocked',
                    'directAccessWithoutNexusBlocked',
                ],
            ],
        ],
        'cli_namespace_context_and_default_scope' => [
            'section' => 'cli_namespace_behavior',
            'fields' => [
                'explicit_namespace_json' => ['explicit_namespace_json', 'explicitNamespaceJson'],
                'explicit_namespace_human_output' => [
                    'explicit_namespace_human_output',
                    'explicitNamespaceHumanOutput',
                ],
                'default_scope_behavior' => ['default_scope_behavior', 'defaultScopeBehavior'],
            ],
        ],
        'sdk_namespace_selection_parity' => [
            'section' => 'sdk_namespace_selection',
            'fields' => [
                'python_client_namespace' => ['python_client_namespace', 'pythonClientNamespace'],
                'php_client_namespace' => ['php_client_namespace', 'phpClientNamespace'],
                'default_namespace_behavior' => ['default_namespace_behavior', 'defaultNamespaceBehavior'],
                'cross_namespace_lookup_denied' => [
                    'cross_namespace_lookup_denied',
                    'crossNamespaceLookupDenied',
                ],
            ],
        ],
        'php_worker_task_queue_namespace_isolation' => [
            'section' => 'php_worker_behavior',
            'fields' => [
                'tenant_a_worker_registration' => [
                    'tenant_a_worker_registration',
                    'tenantAWorkerRegistration',
                ],
                'tenant_b_worker_registration' => [
                    'tenant_b_worker_registration',
                    'tenantBWorkerRegistration',
                ],
                'tenant_a_delivery' => ['tenant_a_delivery', 'tenantADelivery'],
                'tenant_b_delivery' => ['tenant_b_delivery', 'tenantBDelivery'],
                'cross_delivery_absent' => ['cross_delivery_absent', 'crossDeliveryAbsent'],
            ],
        ],
        'waterline_operator_namespace_visibility' => [
            'section' => 'waterline_operator_visibility',
            'fields' => [
                'tenant_a_scoped_views' => ['tenant_a_scoped_views', 'tenantAScopedViews'],
                'tenant_b_scoped_views' => ['tenant_b_scoped_views', 'tenantBScopedViews'],
                'detail_namespace_identity' => [
                    'detail_namespace_identity',
                    'detailNamespaceIdentity',
                ],
                'unscoped_view_authority' => ['unscoped_view_authority', 'unscopedViewAuthority'],
            ],
        ],
        'search_attribute_schema_and_value_query_isolation' => [
            'section' => 'search_attribute_value_query_isolation',
            'fields' => [
                'schema_isolation' => ['schema_isolation', 'schemaIsolation'],
                'value_query_isolation' => ['value_query_isolation', 'valueQueryIsolation'],
                'tenant_a_value' => ['tenant_a_value', 'tenantAValue'],
                'tenant_b_observed_result' => ['tenant_b_observed_result', 'tenantBObservedResult'],
            ],
        ],
        'schedule_namespace_isolation' => [
            'section' => 'schedule_namespace_isolation',
            'fields' => [
                'tenant_a_schedule' => ['tenant_a_schedule', 'tenantASchedule'],
                'tenant_b_schedule' => ['tenant_b_schedule', 'tenantBSchedule'],
                'tenant_a_list_excludes_tenant_b' => [
                    'tenant_a_list_excludes_tenant_b',
                    'tenantAListExcludesTenantB',
                ],
                'cross_namespace_schedule_mutation_denied' => [
                    'cross_namespace_schedule_mutation_denied',
                    'crossNamespaceScheduleMutationDenied',
                ],
            ],
        ],
        'reserved_namespace_name_refusal' => [
            'section' => 'adversarial_namespace_names',
            'fields' => [
                'refused_names' => ['refused_names', 'refusedNames'],
                'typed_errors' => ['typed_errors', 'typedErrors'],
                'valid_control_name_accepted' => ['valid_control_name_accepted', 'validControlNameAccepted'],
                'stored_namespace_names' => ['stored_namespace_names', 'storedNamespaceNames'],
            ],
        ],
        'result_record_and_product_finding_routing' => [
            'section' => 'result_record_and_product_finding_routing',
            'fields' => [
                'artifact_versions_recorded' => ['artifact_versions_recorded', 'artifactVersionsRecorded'],
                'timestamps_recorded' => ['timestamps_recorded', 'timestampsRecorded'],
                'outcome_recorded' => ['outcome_recorded', 'outcomeRecorded'],
                'finding_links_recorded' => ['finding_links_recorded', 'findingLinksRecorded'],
                'product_finding_routes_checked' => [
                    'product_finding_routes_checked',
                    'productFindingRoutesChecked',
                ],
            ],
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'evaluates_result_schema' => NamespaceRuntimeContract::RESULT_SCHEMA,
            'scenario_statuses_source' => 'namespace_runtime_contract.scenario_statuses',
            'required_scenarios_source' => 'namespace_runtime_contract.required_scenarios',
            'required_matrix_source' => 'namespace_runtime_contract.required_matrix',
            'artifact_versions_fields' => [
                'artifact_versions',
                'artifactVersions',
                'published_artifact_versions',
                'publishedArtifactVersions',
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
            'declared_outcomes_source' => 'namespace_runtime_contract.coverage_gate.*_outcome',
            'non_pass_statuses' => [
                'fail',
                'unsupported',
                'not_covered',
                'runner_blocked',
            ],
            'pass_requires' => [
                'every_required_scenario_has_one_result',
                'every_result_uses_a_published_status',
                'required_namespaces_are_reported',
                'required_php_and_python_workers_are_reported',
                'cli_php_waterline_nexus_cleanup_and_search_value_sections_are_reported',
                'each_pass_scenario_has_observed_outputs',
                'each_pass_scenario_has_scenario_specific_evidence',
                'each_pass_scenario_has_concrete_named_evidence_fields',
                'each_non_pass_scenario_has_linked_findings',
                'run_timestamps_outcome_and_finding_links_are_recorded',
                'overall_outcome_matches_gate_status',
                'published_artifact_versions_are_recorded_and_pinned',
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
        $contract ??= NamespaceRuntimeContract::manifest();

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
                if (! self::hasObservedOutputs($scenarioResult)) {
                    $failures[] = [
                        'code' => 'missing_pass_observed_outputs',
                        'scenario_id' => $scenarioId,
                    ];
                }
            } else {
                $nonPassScenarios[] = $scenarioId;
                if (! self::hasLinkedFindings($scenarioResult, $result)) {
                    $failures[] = [
                        'code' => 'missing_non_pass_finding',
                        'scenario_id' => $scenarioId,
                        'status' => $status,
                    ];
                }
            }
        }

        $reportedScenarioIds = array_keys($scenarioResults);
        $unknownScenarios = array_values(array_diff($reportedScenarioIds, $requiredScenarios));
        foreach ($unknownScenarios as $scenarioId) {
            $scenarioResult = $scenarioResults[$scenarioId];
            $status = self::stringValue($scenarioResult['status'] ?? null);
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
        array_push($failures, ...self::declaredOutcomeFailures($result, $contract));
        array_push($failures, ...self::artifactVersionFailures($result, $contract));
        array_push($failures, ...self::sourcePolicyFailures($result, $contract));
        array_push($failures, ...self::matrixFailures($result, $contract));
        array_push($failures, ...self::requiredSectionFailures($result, $scenarioResults));
        array_push($failures, ...self::scenarioSpecificEvidenceFailures($result, $scenarioResults));

        $smokeSubsetDetected = self::isSmokeSubset($scenarioStatuses, $contract);
        if ($smokeSubsetDetected) {
            $failures[] = [
                'code' => 'smoke_subset_cannot_pass',
                'reason' => 'Namespace smoke coverage is not a complete namespace runtime conformance result.',
            ];
        }

        $evidencePasses = $failures === []
            && $missingScenarios === []
            && $nonPassScenarios === []
            && count($scenarioStatuses) >= count($requiredScenarios);
        $evaluatedStatus = $evidencePasses ? 'pass' : 'non_passing';

        array_push($failures, ...self::declaredOutcomeStatusFailures($result, $contract, $evaluatedStatus));

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
     * @param array<string, mixed> $scenarioResult
     */
    private static function hasObservedOutputs(array $scenarioResult): bool
    {
        foreach ([
            'observed_outputs',
            'observedOutputs',
            'runtime_matrix',
            'runtimeMatrix',
            'api_captures',
            'apiCaptures',
            'cleanup_evidence',
            'cleanupEvidence',
            'namespace_topology',
            'namespaceTopology',
        ] as $field) {
            $value = self::arrayValue($scenarioResult, $field);
            if ($value !== null && $value !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $result
     */
    private static function hasLinkedFindings(array $scenarioResult, array $result): bool
    {
        foreach (['linked_findings', 'linkedFindings', 'finding_links', 'findingLinks'] as $field) {
            $value = self::arrayValue($scenarioResult, $field);
            if ($value !== null && $value !== []) {
                return true;
            }
        }

        $scenarioId = self::stringValue($scenarioResult['scenario_id'] ?? null);
        foreach (['finding_links', 'findingLinks', 'findings'] as $field) {
            $links = self::arrayValue($result, $field);
            if ($links === null) {
                continue;
            }

            if (array_key_exists($scenarioId, $links) && $links[$scenarioId] !== []) {
                return true;
            }

            foreach ($links as $link) {
                if (! is_array($link)) {
                    continue;
                }

                $linkedScenario = self::stringValue($link['scenario_id'] ?? $link['scenario'] ?? null);
                if ($linkedScenario === $scenarioId) {
                    return true;
                }
            }
        }

        return false;
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

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function hasRunRecordField(array $result, string $field): bool
    {
        return match ($field) {
            'artifact_versions' => self::artifactVersions($result) !== [],
            'started_at' => self::hasScalarField($result, ['started_at', 'startedAt']),
            'finished_at' => self::hasScalarField($result, ['finished_at', 'finishedAt']),
            'generated_at' => self::hasScalarField($result, ['generated_at', 'generatedAt']),
            'outcome' => self::hasScalarField($result, ['outcome', 'status', 'verdict']),
            'scenario_results' => self::hasArrayField($result, ['scenario_results', 'scenarioResults']),
            'findings' => self::hasArrayField($result, ['findings']),
            'finding_links' => self::hasArrayField($result, ['finding_links', 'findingLinks']),
            default => self::hasScalarField($result, [$field]) || self::hasArrayField($result, [$field]),
        };
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
    private static function declaredOutcomeStatusFailures(array $result, array $contract, string $evaluatedStatus): array
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

            $declaredStatus = $outcome === 'pass' ? 'pass' : 'non_passing';
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
            $failures[] = [
                'code' => 'conflicting_outcome_tokens',
                'declared_outcomes' => array_intersect_key($declaredOutcomes, $declaredStatuses),
                'declared_statuses' => $declaredStatuses,
            ];
        }

        return $failures;
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
            $value = self::stringValue($result[$field] ?? null);
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
        $outcomes = ['pass'];
        $coverageGate = self::arrayValue($contract, 'coverage_gate') ?? [];
        foreach ($coverageGate as $key => $value) {
            if (! is_string($key) || ! str_ends_with($key, '_outcome')) {
                continue;
            }

            $outcome = self::stringValue($value);
            if ($outcome !== '') {
                $outcomes[] = $outcome;
            }
        }

        return array_values(array_unique($outcomes));
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return array<int, array<string, mixed>>
     */
    private static function artifactVersionFailures(array $result, array $contract): array
    {
        $versions = self::artifactVersions($result);
        $failures = [];
        $installChannels = self::arrayValue($contract['artifact_policy'] ?? [], 'install_channels') ?? [];

        foreach (array_keys($installChannels) as $artifact) {
            $version = self::artifactVersionValue($versions, (string) $artifact);
            if ($version === '') {
                $failures[] = [
                    'code' => 'missing_artifact_version',
                    'artifact' => $artifact,
                ];
                continue;
            }

            if (self::isPlaceholderVersion($version)) {
                $failures[] = [
                    'code' => 'placeholder_artifact_version',
                    'artifact' => $artifact,
                    'version' => $version,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<mixed>
     */
    private static function artifactVersions(array $result): array
    {
        return self::arrayValue($result, 'artifact_versions')
            ?? self::arrayValue($result, 'artifactVersions')
            ?? self::arrayValue($result, 'published_artifact_versions')
            ?? self::arrayValue($result, 'publishedArtifactVersions')
            ?? [];
    }

    /**
     * @param array<mixed> $versions
     */
    private static function artifactVersionValue(array $versions, string $artifact): string
    {
        $aliases = [
            'workflow-php' => ['workflow-php', 'workflow_php', 'workflow'],
            'sdk-python' => ['sdk-python', 'sdk_python', 'python'],
            'waterline' => ['waterline', 'waterline-ui', 'waterline_ui'],
        ];

        foreach ($aliases[$artifact] ?? [$artifact] as $key) {
            $version = self::stringValue($versions[$key] ?? null);
            if (array_key_exists($key, $versions) && $version !== '') {
                return $version;
            }
        }

        return '';
    }

    private static function isPlaceholderVersion(string $version): bool
    {
        $normalized = strtolower(trim($version));
        if ($normalized === '') {
            return false;
        }

        if (preg_match('/<[^>]+>|\$\{[^}]+}|{{[^}]+}}/', $normalized) === 1) {
            return true;
        }

        return preg_match('/(^|[^a-z0-9])latest([^a-z0-9]|$)/', $normalized) === 1
            || in_array($normalized, ['latest', 'current', 'head', 'unresolved', 'placeholder'], true);
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return array<int, array<string, mixed>>
     */
    private static function sourcePolicyFailures(array $result, array $contract): array
    {
        $artifactPolicy = self::arrayValue($contract, 'artifact_policy') ?? [];
        $forbiddenSources = self::stringList($artifactPolicy['forbidden_sources'] ?? []);
        $reportedSources = self::arrayValue($result, 'artifact_sources')
            ?? self::arrayValue($result, 'artifactSources')
            ?? [];

        $failures = [];
        foreach ($reportedSources as $artifact => $source) {
            $source = self::stringValue($source);
            if (in_array($source, $forbiddenSources, true)) {
                $failures[] = [
                    'code' => 'forbidden_artifact_source',
                    'artifact' => $artifact,
                    'source' => $source,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return array<int, array<string, mixed>>
     */
    private static function matrixFailures(array $result, array $contract): array
    {
        $matrix = self::arrayValue($result, 'runtime_matrix')
            ?? self::arrayValue($result, 'runtimeMatrix')
            ?? [];
        $topology = self::arrayValue($result, 'namespace_topology')
            ?? self::arrayValue($result, 'namespaceTopology')
            ?? $matrix;
        $contractMatrix = self::arrayValue($contract, 'required_matrix') ?? [];
        $failures = [];

        foreach (self::stringList($contractMatrix['namespaces'] ?? []) as $namespace) {
            if (! self::hasToken($topology, ['namespaces', 'namespace_names', 'namespaceNames'], $namespace)) {
                $failures[] = [
                    'code' => 'missing_required_namespace',
                    'namespace' => $namespace,
                ];
            }
        }

        foreach (self::stringList($contractMatrix['runtimes'] ?? []) as $runtime) {
            if (! self::hasRuntime($matrix, $runtime)) {
                $failures[] = [
                    'code' => 'missing_required_runtime',
                    'runtime' => $runtime,
                ];
            }
        }

        foreach (self::stringList($contractMatrix['client_paths'] ?? []) as $clientPath) {
            if (! self::hasToken($matrix, ['client_paths', 'clientPaths', 'clients'], $clientPath)) {
                $failures[] = [
                    'code' => 'missing_required_client_path',
                    'client_path' => $clientPath,
                ];
            }
        }

        foreach (self::stringList($contractMatrix['observer_paths'] ?? []) as $observerPath) {
            if (! self::hasToken($matrix, ['observer_paths', 'observerPaths', 'observers'], $observerPath)) {
                $failures[] = [
                    'code' => 'missing_required_observer_path',
                    'observer_path' => $observerPath,
                ];
            }
        }

        foreach (['worker_isolation_cells', 'cross_namespace_cells'] as $cellGroup) {
            foreach ($contractMatrix[$cellGroup] ?? [] as $requiredCell) {
                if (! is_array($requiredCell) || self::matrixHasCell($matrix, $cellGroup, $requiredCell)) {
                    continue;
                }

                $failures[] = [
                    'code' => 'missing_required_matrix_cell',
                    'cell_group' => $cellGroup,
                    'scenario' => $requiredCell['scenario'] ?? null,
                    'namespace' => $requiredCell['namespace'] ?? null,
                    'from' => $requiredCell['from'] ?? null,
                    'to' => $requiredCell['to'] ?? null,
                    'surface' => $requiredCell['surface'] ?? null,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<mixed> $matrix
     */
    private static function hasRuntime(array $matrix, string $runtime): bool
    {
        foreach (['runtimes', 'workers', 'worker_runtimes', 'workerRuntimes'] as $field) {
            foreach (self::stringList($matrix[$field] ?? []) as $reportedRuntime) {
                if (self::sameRuntime($reportedRuntime, $runtime)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $fields
     */
    private static function hasToken(array $value, array $fields, string $token): bool
    {
        foreach ($fields as $field) {
            foreach (self::stringList($value[$field] ?? []) as $reported) {
                if ($reported === $token) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $matrix
     * @param array<string, mixed> $requiredCell
     */
    private static function matrixHasCell(array $matrix, string $cellGroup, array $requiredCell): bool
    {
        $reportedCells = [];
        foreach ([$cellGroup, 'cells', 'runtime_cells', 'runtimeCells'] as $field) {
            $value = self::arrayValue($matrix, $field);
            if ($value !== null) {
                $reportedCells = array_merge($reportedCells, $value);
            }
        }

        foreach ($reportedCells as $reportedCell) {
            if (! is_array($reportedCell)) {
                continue;
            }

            if (self::stringValue($reportedCell['scenario'] ?? $reportedCell['scenario_id'] ?? null)
                !== self::stringValue($requiredCell['scenario'] ?? null)) {
                continue;
            }

            if (array_key_exists('runtime', $requiredCell)
                && ! self::sameRuntime(
                    self::stringValue($reportedCell['runtime'] ?? $reportedCell['worker'] ?? null),
                    self::stringValue($requiredCell['runtime'] ?? null),
                )) {
                continue;
            }

            foreach (['namespace', 'task_queue', 'from', 'to', 'surface'] as $field) {
                $requiredValue = self::stringValue($requiredCell[$field] ?? null);
                if ($requiredValue === '') {
                    continue;
                }

                if (self::stringValue($reportedCell[$field] ?? null) !== $requiredValue) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return array<int, array<string, mixed>>
     */
    private static function requiredSectionFailures(array $result, array $scenarioResults): array
    {
        $failures = [];
        foreach (self::SCENARIO_EVIDENCE_REQUIREMENTS as $scenarioId => $requirement) {
            if (! self::isPassScenario($scenarioResults, $scenarioId)) {
                continue;
            }

            $section = self::stringValue($requirement['section'] ?? null);
            if ($section === '' || self::scenarioEvidenceSection($result, $scenarioResults[$scenarioId], $section) !== null) {
                continue;
            }

            $failures[] = [
                'code' => 'missing_required_evidence_section',
                'scenario_id' => $scenarioId,
                'section' => $section,
                'scenarios' => [$scenarioId],
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return array<int, array<string, mixed>>
     */
    private static function scenarioSpecificEvidenceFailures(array $result, array $scenarioResults): array
    {
        $failures = [];

        foreach (self::SCENARIO_EVIDENCE_REQUIREMENTS as $scenarioId => $requirement) {
            if (! self::isPassScenario($scenarioResults, $scenarioId)) {
                continue;
            }

            $section = self::stringValue($requirement['section'] ?? null);
            $fields = self::evidenceFields($requirement);
            $evidence = $section !== ''
                ? self::scenarioEvidenceSection($result, $scenarioResults[$scenarioId], $section)
                : null;

            if ($section === '' || $fields === [] || $evidence === null) {
                continue;
            }

            array_push(
                $failures,
                ...self::requiredFieldsFailures(
                    $scenarioId,
                    $section,
                    $evidence,
                    $fields,
                ),
            );
        }

        return $failures;
    }

    /**
     * @param array<string, array<string, mixed>> $scenarioResults
     */
    private static function isPassScenario(array $scenarioResults, string $scenarioId): bool
    {
        return isset($scenarioResults[$scenarioId])
            && self::stringValue($scenarioResults[$scenarioId]['status'] ?? null) === 'pass';
    }

    /**
     * @param array<mixed> $section
     * @param array<string, list<string>> $fields
     *
     * @return array<int, array<string, mixed>>
     */
    private static function requiredFieldsFailures(
        string $scenarioId,
        string $sectionName,
        array $section,
        array $fields,
    ): array {
        $failures = [];
        foreach ($fields as $field => $aliases) {
            if (self::hasNonEmptyField($section, $aliases)) {
                continue;
            }

            $failures[] = [
                'code' => 'missing_scenario_specific_evidence',
                'scenario_id' => $scenarioId,
                'section' => $sectionName,
                'field' => $field,
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $requirement
     *
     * @return array<string, list<string>>
     */
    private static function evidenceFields(array $requirement): array
    {
        $fields = self::arrayValue($requirement, 'fields') ?? [];
        $normalized = [];

        foreach ($fields as $field => $aliases) {
            if (! is_string($field) || ! is_array($aliases)) {
                continue;
            }

            $normalized[$field] = self::stringList($aliases);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $scenarioResult
     */
    private static function scenarioEvidenceSection(
        array $result,
        array $scenarioResult,
        string $section,
    ): ?array {
        $sectionFields = array_values(array_filter([$section, self::camelCase($section)]));
        foreach ($sectionFields as $field) {
            $topLevel = self::arrayValue($result, $field);
            if ($topLevel !== null) {
                return $topLevel;
            }

            $scenarioLevel = self::arrayValue($scenarioResult, $field);
            if ($scenarioLevel !== null) {
                return $scenarioLevel;
            }
        }

        foreach (['evidence', 'scenario_evidence', 'scenarioEvidence', 'observed_outputs', 'observedOutputs'] as $field) {
            $scenarioLevel = self::arrayValue($scenarioResult, $field);
            if ($scenarioLevel !== null) {
                return $scenarioLevel;
            }
        }

        return null;
    }

    private static function camelCase(string $value): string
    {
        return preg_replace_callback(
            '/_([a-z])/',
            static fn (array $matches): string => strtoupper($matches[1]),
            $value,
        ) ?? $value;
    }

    /**
     * @param array<string, string> $scenarioStatuses
     * @param array<string, mixed> $contract
     */
    private static function isSmokeSubset(array $scenarioStatuses, array $contract): bool
    {
        $requiredScenarios = self::stringList($contract['required_scenarios'] ?? []);
        if (count($scenarioStatuses) >= count($requiredScenarios)) {
            return false;
        }

        $coveredScenarios = array_keys(array_filter(
            $scenarioStatuses,
            static fn (string $status): bool => $status === 'pass',
        ));
        $smokeScenarios = [
            'published_artifact_install_only',
            'namespace_create_update_describe_and_list',
            'workflow_cross_namespace_visibility_isolation',
            'workflow_cross_namespace_mutation_isolation',
            'search_attribute_schema_and_value_query_isolation',
            'schedule_namespace_isolation',
        ];

        return $coveredScenarios !== [] && array_diff($coveredScenarios, $smokeScenarios) === [];
    }

    private static function sameRuntime(string $reported, string $required): bool
    {
        $aliases = [
            'workflow-php' => ['workflow-php', 'workflow_php', 'php', 'php_worker'],
            'sdk-python' => ['sdk-python', 'sdk_python', 'python', 'python_worker'],
        ];

        return in_array($reported, $aliases[$required] ?? [$required], true);
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $fields
     */
    private static function hasNonEmptyField(array $value, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $value) && self::nonEmptyValue($value[$field])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $fields
     */
    private static function hasScalarField(array $value, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $value) && self::stringValue($value[$field]) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $fields
     */
    private static function hasArrayField(array $value, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $value) && is_array($value[$field])) {
                return true;
            }
        }

        return false;
    }

    private static function nonEmptyValue(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }

        if (is_bool($value)) {
            return true;
        }

        return self::stringValue($value) !== '';
    }

    /**
     * @param mixed $value
     *
     * @return array<int, string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $item): string => self::stringValue($item),
                $value,
            ),
            static fn (string $item): bool => $item !== '',
        ));
    }

    private static function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>|null
     */
    private static function arrayValue(array $value, string $key): ?array
    {
        return isset($value[$key]) && is_array($value[$key]) ? $value[$key] : null;
    }
}
