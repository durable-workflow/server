<?php

namespace App\Support;

/**
 * Evaluates skew-refusal conformance results against the full published
 * artifact matrix advertised by SkewRefusalMatrixContract.
 */
final class SkewRefusalMatrixResultGate
{
    public const SCHEMA = 'durable-workflow.v2.skew-refusal-matrix.result-gate';

    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'evaluates_result_schema' => SkewRefusalMatrixContract::RESULT_SCHEMA,
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
            'surface_results_fields' => [
                'surface_results',
                'surfaceResults',
            ],
            'pairing_results_fields' => [
                'pairing_results',
                'pairingResults',
            ],
            'operation_evidence_fields' => [
                'operation_evidence',
                'operationEvidence',
            ],
            'artifact_version_policy' => [
                'requires_recorded_and_pinned_versions' => true,
                'rejects_placeholder_versions' => true,
                'placeholder_version_examples' => [
                    'latest',
                    'current',
                    'head',
                    'unresolved',
                    'placeholder',
                    '<latest>',
                    '${VERSION}',
                    '{{ version }}',
                ],
            ],
            'pass_requires' => [
                'current_published_artifact_versions_are_recorded',
                'run_timestamps_outcome_runner_blocked_and_finding_links_are_recorded',
                'every_required_surface_has_a_surface_result',
                'every_required_surface_reports_compatible_backward_forward_and_outside_window_pairings',
                'every_required_operation_group_has_evidence_for_every_pairing_class',
                'every_advertised_operation_request_has_matching_evidence',
                'compatible_pairings_pass',
                'outside_window_pairings_loud_refuse',
                'loud_refusals_name_both_versions_protocol_window_and_next_step',
                'worker_skew_is_classified_as_register_refused_or_register_and_serve',
                'waterline_skew_is_classified_as_banner_or_render_refused',
                'non_pass_pairings_or_operations_have_linked_findings',
                'each_non_pass_cell_has_a_focused_finding_link',
                'request_response_evidence_is_present_for_each_skewed_operation',
                'operation_capture_ids_resolve_to_attached_request_response_captures',
                'compatible_client_optional_gaps_are_allowed_only_with_passing_inside_window_interop_evidence',
                'smoke_only_results_remain_non_passing',
                'overall_outcome_matches_gate_status',
            ],
            'compatible_client_optional_gap_policies' => [
                'cli' => [
                    'surface' => 'cli',
                    'pairing_class' => 'compatible',
                    'status' => 'not_covered',
                    'coverage_gap_scope' => 'compatible_cli_inside_window_interop',
                    'requires_row_fields' => [
                        'optional_coverage_gap',
                        'coverage_gap_scope',
                        'coverage_gap_reason',
                        'request_response_capture_id',
                    ],
                    'interop_operation_groups' => [
                        'workflow_control_plane',
                        'schedule_control_plane',
                    ],
                    'operation_group_requests' => [
                        'workflow_control_plane' => [
                            'GET /api/workflows/{workflowId}/runs/{runId}/history',
                        ],
                    ],
                ],
                'sdk-python' => [
                    'surface' => 'sdk-python',
                    'pairing_class' => 'compatible',
                    'status' => 'not_covered',
                    'coverage_gap_scope' => 'compatible_sdk_python_inside_window_interop',
                    'requires_typed_sdk_evidence' => true,
                    'requires_row_fields' => [
                        'optional_coverage_gap',
                        'coverage_gap_scope',
                        'coverage_gap_reason',
                        'request_response_capture_id',
                    ],
                    'requires_interop_evidence_fields' => [
                        'sdk_python_version',
                        'sdk_version',
                        'typed_sdk_evidence',
                    ],
                    'interop_operation_groups' => [
                        'workflow_control_plane',
                        'schedule_control_plane',
                        'worker_lifecycle',
                    ],
                    'operation_request_policy' => 'all_advertised_requests',
                    'operation_groups' => [
                        'workflow_control_plane',
                        'schedule_control_plane',
                        'worker_lifecycle',
                    ],
                ],
            ],
            'compatible_cli_optional_gap_policy' => [
                'surface' => 'cli',
                'pairing_class' => 'compatible',
                'status' => 'not_covered',
                'coverage_gap_scope' => 'compatible_cli_inside_window_interop',
                'requires_row_fields' => [
                    'optional_coverage_gap',
                    'coverage_gap_scope',
                    'coverage_gap_reason',
                    'request_response_capture_id',
                ],
                'operation_group_requests' => [
                    'workflow_control_plane' => [
                        'GET /api/workflows/{workflowId}/runs/{runId}/history',
                    ],
                ],
            ],
            'non_pass_statuses' => [
                'fail',
                'error',
                'non_passing',
                'non_passing_smoke_only',
                'non_passing_not_covered',
                'non_passing_runner_blocked',
                'runner_blocked',
            ],
            'blocking_statuses' => [
                'mutation_before_refusal',
                'silent_success',
                'silent_failure',
                'corrupt',
                'register_and_drop',
                'stale_render',
                'not_covered',
                'runner_blocked',
            ],
            'smoke_subset_outcome' => 'non_passing_smoke_only',
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
        $contract ??= SkewRefusalMatrixContract::manifest();

        $failures = [];
        $nonPassCells = [];
        $requiredSurfaces = self::requiredSurfaces($contract);
        $statusTaxonomy = self::stringList($contract['status_taxonomy'] ?? []);
        $surfaceResults = self::surfaceResults($result);
        $pairingResults = self::pairingResults($result, $requiredSurfaces);
        $operationEvidence = self::operationEvidence($result, $requiredSurfaces);
        $requestResponseCaptureIds = self::requestResponseCaptureIds($result);
        $reportedSurfaces = array_values(array_unique(array_merge(
            array_keys($surfaceResults),
            array_keys($pairingResults),
            array_keys($operationEvidence),
        )));
        sort($reportedSurfaces);

        array_push($failures, ...self::runRecordFailures($result, $contract));
        array_push($failures, ...self::artifactVersionFailures($result, $contract));
        array_push(
            $failures,
            ...self::sourcePolicyFailures($result, $contract, $surfaceResults, $pairingResults, $operationEvidence),
        );

        foreach ($requiredSurfaces as $surface => $surfaceContract) {
            if (! isset($surfaceResults[$surface])) {
                $failures[] = [
                    'code' => 'missing_required_surface_result',
                    'surface' => $surface,
                ];
                $nonPassCells[] = $surface;
            }

            foreach (self::stringList($surfaceContract['required_pairing_classes'] ?? []) as $pairingClass) {
                $pairing = $pairingResults[$surface][$pairingClass] ?? null;

                if (! is_array($pairing)) {
                    $failures[] = [
                        'code' => 'missing_required_pairing',
                        'surface' => $surface,
                        'pairing_class' => $pairingClass,
                    ];
                    $nonPassCells[] = $surface.'.'.$pairingClass;
                } else {
                    array_push(
                        $failures,
                        ...self::pairingFailures(
                            $surface,
                            $surfaceContract,
                            $pairingClass,
                            $pairing,
                            $contract,
                            $statusTaxonomy,
                            $nonPassCells,
                        ),
                    );
                }

                foreach (self::stringList($surfaceContract['operation_groups'] ?? []) as $operationGroup) {
                    $evidenceRows = $operationEvidence[$surface][$pairingClass][$operationGroup] ?? [];
                    if ($evidenceRows === []) {
                        $failures[] = [
                            'code' => 'missing_operation_evidence',
                            'surface' => $surface,
                            'pairing_class' => $pairingClass,
                            'operation_group' => $operationGroup,
                        ];
                        $nonPassCells[] = $surface.'.'.$pairingClass.'.'.$operationGroup;
                        continue;
                    }

                    array_push(
                        $failures,
                        ...self::operationRequestCoverageFailures(
                            $surface,
                            $pairingClass,
                            $operationGroup,
                            $evidenceRows,
                            $contract,
                            $nonPassCells,
                        ),
                    );

                    foreach ($evidenceRows as $index => $evidence) {
                        array_push(
                            $failures,
                            ...self::operationEvidenceFailures(
                                $surface,
                                $surfaceContract,
                                $pairingClass,
                                $operationGroup,
                                is_array($pairing) ? $pairing : [],
                                $evidence,
                                $index,
                                $contract,
                                $statusTaxonomy,
                                $requestResponseCaptureIds,
                                $nonPassCells,
                            ),
                        );
                    }
                }
            }
        }

        $smokeSubsetDetected = self::isSmokeSubset($operationEvidence, $requiredSurfaces);
        if ($smokeSubsetDetected) {
            $failures[] = [
                'code' => 'smoke_only_result_cannot_pass',
                'reason' => 'Cluster-info skew smoke is not a full CLI, Python SDK, PHP worker, and Waterline skew-refusal matrix.',
            ];
            $nonPassCells[] = 'smoke_only';
        }

        $uniqueNonPassCells = array_values(array_unique($nonPassCells));
        if ($uniqueNonPassCells !== [] && ! self::hasLinkedFindings($result)) {
            $failures[] = [
                'code' => 'missing_linked_findings_for_non_pass_cells',
                'non_pass_cells' => $uniqueNonPassCells,
            ];
        } elseif ($uniqueNonPassCells !== []) {
            $missingFocusedFindingCells = self::nonPassCellsMissingFocusedFindings($result, $uniqueNonPassCells);
            if ($missingFocusedFindingCells !== []) {
                $failures[] = [
                    'code' => 'missing_focused_findings_for_non_pass_cells',
                    'non_pass_cells' => $missingFocusedFindingCells,
                ];
            }
        }

        $evidencePasses = $failures === []
            && $uniqueNonPassCells === []
            && ! self::boolField($result, ['runner_blocked', 'runnerBlocked']);
        $evaluatedStatus = $evidencePasses ? 'pass' : 'non_passing';

        array_push($failures, ...self::declaredOutcomeFailures($result, $evaluatedStatus));
        $passes = $evaluatedStatus === 'pass' && $failures === [];

        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'status' => $passes ? 'pass' : 'non_passing',
            'required_surfaces' => array_keys($requiredSurfaces),
            'reported_surfaces' => $reportedSurfaces,
            'missing_surfaces' => array_values(array_diff(array_keys($requiredSurfaces), $reportedSurfaces)),
            'smoke_subset_detected' => $smokeSubsetDetected,
            'non_pass_cells' => $uniqueNonPassCells,
            'gate_failures' => $failures,
        ];
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, array<string, mixed>>
     */
    private static function requiredSurfaces(array $contract): array
    {
        $surfaces = [];
        foreach (($contract['required_surfaces'] ?? []) as $surface => $value) {
            if (is_string($surface) && is_array($value)) {
                $surfaces[$surface] = $value;
            }
        }

        return $surfaces;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, array<string, mixed>>
     */
    private static function surfaceResults(array $result): array
    {
        $raw = self::arrayField($result, ['surface_results', 'surfaceResults']) ?? [];
        $results = [];

        foreach ($raw as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $surface = is_string($key) ? $key : self::stringValue($value['surface'] ?? $value['id'] ?? null);
            if ($surface !== '') {
                $results[$surface] = $value;
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, array<string, mixed>> $requiredSurfaces
     * @return array<string, array<string, array<string, mixed>>>
     */
    private static function pairingResults(array $result, array $requiredSurfaces): array
    {
        $results = [];
        self::collectPairingResults(
            self::arrayField($result, ['pairing_results', 'pairingResults']) ?? [],
            $results,
            $requiredSurfaces,
            null,
        );

        foreach (self::surfaceResults($result) as $surface => $surfaceResult) {
            self::collectPairingResults(
                self::arrayField($surfaceResult, ['pairing_results', 'pairingResults', 'pairings']) ?? [],
                $results,
                $requiredSurfaces,
                $surface,
            );
        }

        return $results;
    }

    /**
     * @param mixed $raw
     * @param array<string, array<string, array<string, mixed>>> $results
     * @param array<string, array<string, mixed>> $requiredSurfaces
     */
    private static function collectPairingResults(mixed $raw, array &$results, array $requiredSurfaces, ?string $surfaceHint): void
    {
        if (! is_array($raw)) {
            return;
        }

        foreach ($raw as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if ($surfaceHint === null && is_string($key) && isset($requiredSurfaces[$key])) {
                self::collectPairingResults($value, $results, $requiredSurfaces, $key);
                continue;
            }

            $surface = $surfaceHint ?? self::stringValue($value['surface'] ?? null);
            if ($surface === '') {
                continue;
            }

            if (is_string($key) && self::isPairingClass($surface, $key, $requiredSurfaces)) {
                $pairingClass = $key;
            } else {
                $pairingClass = self::stringValue($value['pairing_class'] ?? $value['pairingClass'] ?? $value['class'] ?? null);
            }

            if ($pairingClass === '') {
                continue;
            }

            $results[$surface][$pairingClass] = $value;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $requiredSurfaces
     */
    private static function isPairingClass(string $surface, string $value, array $requiredSurfaces): bool
    {
        return in_array($value, self::stringList($requiredSurfaces[$surface]['required_pairing_classes'] ?? []), true);
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, array<string, mixed>> $requiredSurfaces
     * @return array<string, array<string, array<string, list<array<string, mixed>>>>>
     */
    private static function operationEvidence(array $result, array $requiredSurfaces): array
    {
        $evidence = [];
        self::collectOperationEvidence(
            self::arrayField($result, ['operation_evidence', 'operationEvidence']) ?? [],
            $evidence,
            $requiredSurfaces,
            null,
            null,
            null,
        );

        foreach (self::surfaceResults($result) as $surface => $surfaceResult) {
            self::collectOperationEvidence(
                self::arrayField($surfaceResult, ['operation_evidence', 'operationEvidence', 'operations']) ?? [],
                $evidence,
                $requiredSurfaces,
                $surface,
                null,
                null,
            );
        }

        return $evidence;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, true>
     */
    private static function requestResponseCaptureIds(array $result): array
    {
        $raw = self::arrayField($result, ['request_response_captures', 'requestResponseCaptures']) ?? [];
        $captures = self::arrayField($raw, ['captures']) ?? $raw;
        $ids = [];

        foreach ($captures as $key => $capture) {
            if (is_string($key) && $key !== '') {
                $ids[$key] = true;
            }

            if (! is_array($capture)) {
                continue;
            }

            $id = self::stringValue($capture['id'] ?? $capture['capture_id'] ?? $capture['captureId'] ?? null);
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * @param mixed $raw
     * @param array<string, array<string, array<string, list<array<string, mixed>>>>> $evidence
     * @param array<string, array<string, mixed>> $requiredSurfaces
     */
    private static function collectOperationEvidence(
        mixed $raw,
        array &$evidence,
        array $requiredSurfaces,
        ?string $surfaceHint,
        ?string $pairingHint,
        ?string $groupHint,
    ): void {
        if (! is_array($raw)) {
            return;
        }

        foreach ($raw as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if ($surfaceHint === null && is_string($key) && isset($requiredSurfaces[$key])) {
                self::collectOperationEvidence($value, $evidence, $requiredSurfaces, $key, $pairingHint, $groupHint);
                continue;
            }

            if (
                $surfaceHint !== null
                && $pairingHint === null
                && is_string($key)
                && self::isPairingClass($surfaceHint, $key, $requiredSurfaces)
            ) {
                self::collectOperationEvidence($value, $evidence, $requiredSurfaces, $surfaceHint, $key, $groupHint);
                continue;
            }

            if ($surfaceHint !== null && $pairingHint !== null && $groupHint === null && is_string($key)) {
                self::collectOperationEvidence($value, $evidence, $requiredSurfaces, $surfaceHint, $pairingHint, $key);
                continue;
            }

            $surface = $surfaceHint ?? self::stringValue($value['surface'] ?? null);
            $pairingClass = $pairingHint ?? self::stringValue($value['pairing_class'] ?? $value['pairingClass'] ?? null);
            $operationGroup = $groupHint ?? self::stringValue($value['operation_group'] ?? $value['operationGroup'] ?? null);

            if ($surface === '' || $pairingClass === '' || $operationGroup === '') {
                continue;
            }

            $evidence[$surface][$pairingClass][$operationGroup][] = $value;
        }
    }

    /**
     * @param array<string, mixed> $surfaceContract
     * @param array<string, mixed> $pairing
     * @param array<string, mixed> $contract
     * @param list<string> $statusTaxonomy
     * @param list<string> $nonPassCells
     * @return list<array<string, mixed>>
     */
    private static function pairingFailures(
        string $surface,
        array $surfaceContract,
        string $pairingClass,
        array $pairing,
        array $contract,
        array $statusTaxonomy,
        array &$nonPassCells,
    ): array {
        $failures = [];
        $status = self::resultStatus($pairing);

        if ($status === '') {
            $failures[] = [
                'code' => 'missing_pairing_status',
                'surface' => $surface,
                'pairing_class' => $pairingClass,
            ];
            $nonPassCells[] = $surface.'.'.$pairingClass;

            return $failures;
        }

        if (! in_array($status, $statusTaxonomy, true)) {
            $failures[] = [
                'code' => 'invalid_pairing_status',
                'surface' => $surface,
                'pairing_class' => $pairingClass,
                'status' => $status,
                'allowed_statuses' => $statusTaxonomy,
            ];
            $nonPassCells[] = $surface.'.'.$pairingClass;

            return $failures;
        }

        if (! self::statusAllowedForPairingClass($status, $pairingClass, $contract)) {
            $failures[] = [
                'code' => 'unexpected_pairing_status',
                'surface' => $surface,
                'pairing_class' => $pairingClass,
                'status' => $status,
                'expected_statuses' => self::stringList($contract['pairing_classes'][$pairingClass]['expected_statuses'] ?? []),
            ];
            $nonPassCells[] = $surface.'.'.$pairingClass;
        }

        if (in_array($status, self::blockingStatuses(), true)) {
            $failures[] = [
                'code' => 'blocking_pairing_status',
                'surface' => $surface,
                'pairing_class' => $pairingClass,
                'status' => $status,
            ];
            $nonPassCells[] = $surface.'.'.$pairingClass;
        }

        if ($status === 'loud_refuse') {
            $refusalFailures = self::refusalRequirementFailures($surface, $surfaceContract, $pairingClass, $pairing, 'pairing');
            if ($refusalFailures !== []) {
                $nonPassCells[] = $surface.'.'.$pairingClass;
                array_push($failures, ...$refusalFailures);
            }
        }

        $classificationFailures = self::surfaceSpecificClassificationFailures($surface, $pairingClass, $pairing, $contract);
        if ($classificationFailures !== []) {
            $nonPassCells[] = $surface.'.'.$pairingClass;
            array_push($failures, ...$classificationFailures);
        }

        return $failures;
    }

    /**
     * @param list<array<string, mixed>> $evidenceRows
     * @param array<string, mixed> $contract
     * @param list<string> $nonPassCells
     * @return list<array<string, mixed>>
     */
    private static function operationRequestCoverageFailures(
        string $surface,
        string $pairingClass,
        string $operationGroup,
        array $evidenceRows,
        array $contract,
        array &$nonPassCells,
    ): array {
        $advertisedRequests = self::advertisedRequestMap($contract, $operationGroup);
        if ($advertisedRequests === []) {
            return [];
        }

        $failures = [];
        $matchedRequests = [];
        $cell = $surface.'.'.$pairingClass.'.'.$operationGroup;

        foreach ($evidenceRows as $index => $evidence) {
            $observedRequests = self::operationEvidenceRequests($evidence);
            if ($observedRequests === []) {
                $failures[] = [
                    'code' => 'missing_operation_request',
                    'surface' => $surface,
                    'pairing_class' => $pairingClass,
                    'operation_group' => $operationGroup,
                    'index' => $index,
                    'advertised_requests' => array_values($advertisedRequests),
                ];
                $nonPassCells[] = $cell;

                continue;
            }

            foreach ($observedRequests as $observedRequest) {
                $matchedRequest = self::matchingAdvertisedRequest($observedRequest, $advertisedRequests);
                if ($matchedRequest !== null) {
                    $matchedRequests[$matchedRequest] = true;
                    continue;
                }

                $failures[] = [
                    'code' => 'unexpected_operation_request',
                    'surface' => $surface,
                    'pairing_class' => $pairingClass,
                    'operation_group' => $operationGroup,
                    'index' => $index,
                    'request' => $observedRequest,
                    'advertised_requests' => array_values($advertisedRequests),
                ];
                $nonPassCells[] = $cell;
            }
        }

        foreach ($advertisedRequests as $normalizedRequest => $advertisedRequest) {
            if (isset($matchedRequests[$normalizedRequest])) {
                continue;
            }

            $failures[] = [
                'code' => 'missing_operation_request_evidence',
                'surface' => $surface,
                'pairing_class' => $pairingClass,
                'operation_group' => $operationGroup,
                'advertised_request' => $advertisedRequest,
            ];
            $nonPassCells[] = $cell;
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $surfaceContract
     * @param array<string, mixed> $evidence
     * @param array<string, mixed> $contract
     * @param list<string> $statusTaxonomy
     * @param array<string, true> $requestResponseCaptureIds
     * @param list<string> $nonPassCells
     * @return list<array<string, mixed>>
     */
    private static function operationEvidenceFailures(
        string $surface,
        array $surfaceContract,
        string $pairingClass,
        string $operationGroup,
        array $pairing,
        array $evidence,
        int $index,
        array $contract,
        array $statusTaxonomy,
        array $requestResponseCaptureIds,
        array &$nonPassCells,
    ): array {
        $failures = [];
        $status = self::resultStatus($evidence);
        $cell = $surface.'.'.$pairingClass.'.'.$operationGroup;
        $compatibleClientInteropGapExempt = self::isCompatibleClientInteropCoverageGapExempt(
            $surface,
            $pairingClass,
            $operationGroup,
            $status,
            $pairing,
            $evidence,
            $contract,
            $requestResponseCaptureIds,
        );

        if ($status === '') {
            $failures[] = [
                'code' => 'missing_operation_evidence_status',
                'surface' => $surface,
                'pairing_class' => $pairingClass,
                'operation_group' => $operationGroup,
                'index' => $index,
            ];
            $nonPassCells[] = $cell;
        } elseif (! in_array($status, $statusTaxonomy, true)) {
            $failures[] = [
                'code' => 'invalid_operation_status',
                'surface' => $surface,
                'pairing_class' => $pairingClass,
                'operation_group' => $operationGroup,
                'index' => $index,
                'status' => $status,
                'allowed_statuses' => $statusTaxonomy,
            ];
            $nonPassCells[] = $cell;
        } elseif (! self::statusAllowedForPairingClass($status, $pairingClass, $contract)) {
            $failures[] = [
                'code' => 'unexpected_operation_status',
                'surface' => $surface,
                'pairing_class' => $pairingClass,
                'operation_group' => $operationGroup,
                'index' => $index,
                'status' => $status,
                'expected_statuses' => self::stringList($contract['pairing_classes'][$pairingClass]['expected_statuses'] ?? []),
            ];
            $nonPassCells[] = $cell;
        }

        if (in_array($status, self::blockingStatuses(), true) && ! $compatibleClientInteropGapExempt) {
            $failures[] = [
                'code' => 'blocking_operation_status',
                'surface' => $surface,
                'pairing_class' => $pairingClass,
                'operation_group' => $operationGroup,
                'index' => $index,
                'status' => $status,
            ];
            $nonPassCells[] = $cell;
        }

        if (! self::isCoverageGapStatus($status)) {
            foreach (self::stringList($contract['operation_groups'][$operationGroup]['evidence'] ?? []) as $field) {
                if (self::hasRunRecordField($evidence, $field)) {
                    continue;
                }

                $failures[] = [
                    'code' => 'missing_operation_evidence_field',
                    'surface' => $surface,
                    'pairing_class' => $pairingClass,
                    'operation_group' => $operationGroup,
                    'index' => $index,
                    'field' => $field,
                ];
                $nonPassCells[] = $cell;
            }
        }

        $captureId = self::requestResponseCaptureId($evidence);
        if ($captureId === '') {
            $failures[] = [
                'code' => 'missing_request_response_capture_id',
                'surface' => $surface,
                'pairing_class' => $pairingClass,
                'operation_group' => $operationGroup,
                'index' => $index,
            ];
            $nonPassCells[] = $cell;
        } elseif (! isset($requestResponseCaptureIds[$captureId])) {
            $failures[] = [
                'code' => 'missing_request_response_capture',
                'surface' => $surface,
                'pairing_class' => $pairingClass,
                'operation_group' => $operationGroup,
                'index' => $index,
                'request_response_capture_id' => $captureId,
            ];
            $nonPassCells[] = $cell;
        }

        if ($status === 'loud_refuse') {
            $refusalFailures = self::refusalRequirementFailures($surface, $surfaceContract, $pairingClass, $evidence, 'operation');
            if ($refusalFailures !== []) {
                $nonPassCells[] = $cell;
                array_push($failures, ...$refusalFailures);
            }
        }

        $classificationFailures = self::surfaceSpecificClassificationFailures($surface, $pairingClass, $evidence, $contract);
        if ($classificationFailures !== []) {
            $nonPassCells[] = $cell;
            array_push($failures, ...$classificationFailures);
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $pairing
     * @param array<string, true> $requestResponseCaptureIds
     */
    private static function isCompatibleClientInteropCoverageGapExempt(
        string $surface,
        string $pairingClass,
        string $operationGroup,
        string $status,
        array $pairing,
        array $evidenceRow,
        array $contract,
        array $requestResponseCaptureIds,
    ): bool {
        if ($pairingClass !== 'compatible' || $status !== 'not_covered') {
            return false;
        }

        $policy = self::compatibleClientOptionalGapPolicy($surface, $contract);
        if ($policy === []) {
            return false;
        }

        if (self::stringValue($policy['pairing_class'] ?? $policy['pairingClass'] ?? 'compatible') !== $pairingClass) {
            return false;
        }

        if (self::stringValue($policy['status'] ?? 'not_covered') !== $status) {
            return false;
        }

        if (! self::isCompatibleClientOptionalCoverageGapRow($operationGroup, $evidenceRow, $policy, $contract)) {
            return false;
        }

        if (self::resultStatus($pairing) !== 'pass') {
            return false;
        }

        $evidence = $pairing['compatible_interop_evidence']
            ?? $pairing['compatibleInteropEvidence']
            ?? null;
        if (! is_array($evidence)) {
            return false;
        }

        $observedResult = self::stringValue(
            $evidence['observed_result'] ?? $evidence['observedResult'] ?? $evidence['status'] ?? null,
        );
        if ($observedResult !== 'pass') {
            return false;
        }

        if (self::stringValue($evidence['surface'] ?? null) !== $surface) {
            return false;
        }

        if (self::stringValue($evidence['pairing_class'] ?? $evidence['pairingClass'] ?? null) !== 'compatible') {
            return false;
        }

        $interopOperationGroups = self::stringList(
            $policy['interop_operation_groups']
                ?? $policy['interopOperationGroups']
                ?? ['workflow_control_plane', 'schedule_control_plane'],
        );
        if (! in_array(self::stringValue($evidence['operation_group'] ?? $evidence['operationGroup'] ?? null), $interopOperationGroups, true)) {
            return false;
        }

        foreach ([
            ['client_or_worker_version', 'clientOrWorkerVersion', 'client_or_observer_version', 'clientOrObserverVersion'],
            ['server_version', 'serverVersion'],
            ['compatibility_window', 'compatibilityWindow'],
            ['next_step', 'nextStep'],
        ] as $fields) {
            if (self::firstStringField($evidence, $fields) === '') {
                return false;
            }
        }

        if (self::boolField($policy, ['requires_typed_sdk_evidence', 'requiresTypedSdkEvidence'])) {
            if (! self::boolField($evidence, ['typed_sdk_evidence', 'typedSdkEvidence'])) {
                return false;
            }

            foreach ([
                ['sdk_python_version', 'sdkPythonVersion'],
                ['sdk_version', 'sdkVersion'],
            ] as $fields) {
                if (self::firstStringField($evidence, $fields) === '') {
                    return false;
                }
            }
        }

        $captureId = self::requestResponseCaptureId($evidence);

        return $captureId !== '' && isset($requestResponseCaptureIds[$captureId]);
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    private static function compatibleClientOptionalGapPolicy(string $surface, array $contract): array
    {
        $resultGate = self::arrayField($contract, ['result_gate', 'resultGate']) ?? [];
        $policies = self::arrayField(
            $resultGate,
            ['compatible_client_optional_gap_policies', 'compatibleClientOptionalGapPolicies'],
        ) ?? [];

        $policy = $policies[$surface] ?? null;
        if (is_array($policy)) {
            return $policy;
        }

        if ($surface === 'cli') {
            return self::arrayField(
                $resultGate,
                ['compatible_cli_optional_gap_policy', 'compatibleCliOptionalGapPolicy'],
            ) ?? [];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $evidenceRow
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $contract
     */
    private static function isCompatibleClientOptionalCoverageGapRow(
        string $operationGroup,
        array $evidenceRow,
        array $policy,
        array $contract,
    ): bool {
        if (! self::boolField($evidenceRow, ['optional_coverage_gap', 'optionalCoverageGap'])) {
            return false;
        }

        $coverageGapScope = self::stringValue(
            $policy['coverage_gap_scope']
                ?? $policy['coverageGapScope']
                ?? 'compatible_cli_inside_window_interop',
        );
        if (self::firstStringField($evidenceRow, ['coverage_gap_scope', 'coverageGapScope']) !== $coverageGapScope) {
            return false;
        }

        if (self::firstStringField($evidenceRow, ['coverage_gap_reason', 'coverageGapReason']) === '') {
            return false;
        }

        $optionalRequests = self::optionalGapRequestsForOperationGroup($operationGroup, $policy, $contract);
        if ($optionalRequests === []) {
            return false;
        }

        $observedRequests = self::operationEvidenceRequests($evidenceRow);
        if ($observedRequests === []) {
            return false;
        }

        $optionalRequestMap = [];
        foreach ($optionalRequests as $request) {
            $normalizedRequest = self::normalizeOperationRequest($request);
            if ($normalizedRequest !== '') {
                $optionalRequestMap[$normalizedRequest] = $request;
            }
        }

        foreach ($observedRequests as $observedRequest) {
            if (self::matchingAdvertisedRequest($observedRequest, $optionalRequestMap) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $contract
     * @return list<string>
     */
    private static function optionalGapRequestsForOperationGroup(string $operationGroup, array $policy, array $contract): array
    {
        $optionalRequestsByGroup = self::arrayField(
            $policy,
            ['operation_group_requests', 'operationGroupRequests'],
        ) ?? [];
        $optionalRequests = self::stringList($optionalRequestsByGroup[$operationGroup] ?? []);
        if ($optionalRequests !== []) {
            return $optionalRequests;
        }

        if (self::stringValue($policy['operation_request_policy'] ?? $policy['operationRequestPolicy'] ?? null) !== 'all_advertised_requests') {
            return [];
        }

        if (! in_array($operationGroup, self::stringList($policy['operation_groups'] ?? $policy['operationGroups'] ?? []), true)) {
            return [];
        }

        return self::stringList($contract['operation_groups'][$operationGroup]['requests'] ?? []);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $fields
     */
    private static function firstStringField(array $row, array $fields): string
    {
        foreach ($fields as $field) {
            $value = self::stringValue($row[$field] ?? null);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, string>
     */
    private static function advertisedRequestMap(array $contract, string $operationGroup): array
    {
        $requests = [];
        foreach (self::stringList($contract['operation_groups'][$operationGroup]['requests'] ?? []) as $request) {
            $normalizedRequest = self::normalizeOperationRequest($request);
            if ($normalizedRequest !== '') {
                $requests[$normalizedRequest] = $request;
            }
        }

        return $requests;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private static function operationEvidenceRequests(array $row): array
    {
        $requests = [];
        $method = self::stringValue($row['request_method'] ?? $row['requestMethod'] ?? null);

        foreach (['request', 'request_path', 'requestPath'] as $field) {
            $request = self::stringValue($row[$field] ?? null);
            if ($request === '') {
                continue;
            }

            $normalizedRequest = self::normalizeOperationRequest($request, $method);
            if ($normalizedRequest !== '') {
                $requests[$normalizedRequest] = true;
            }
        }

        $request = $row['request'] ?? null;
        if (is_array($request)) {
            $nestedMethod = self::stringValue($request['method'] ?? $request['request_method'] ?? $request['requestMethod'] ?? null);
            $nestedPath = self::stringValue($request['path'] ?? $request['url'] ?? $request['request_path'] ?? $request['requestPath'] ?? null);
            $normalizedRequest = self::normalizeOperationRequest($nestedPath, $nestedMethod);
            if ($normalizedRequest !== '') {
                $requests[$normalizedRequest] = true;
            }
        }

        return array_keys($requests);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function requestResponseCaptureId(array $row): string
    {
        return self::stringValue(
            $row['request_response_capture_id']
                ?? $row['requestResponseCaptureId']
                ?? $row['capture_id']
                ?? $row['captureId']
                ?? null,
        );
    }

    /**
     * @param array<string, string> $advertisedRequests
     */
    private static function matchingAdvertisedRequest(string $observedRequest, array $advertisedRequests): ?string
    {
        foreach ($advertisedRequests as $normalizedRequest => $_advertisedRequest) {
            if (
                $observedRequest === $normalizedRequest
                || self::operationRequestMatchesTemplate($normalizedRequest, $observedRequest)
            ) {
                return $normalizedRequest;
            }
        }

        return null;
    }

    private static function operationRequestMatchesTemplate(string $templateRequest, string $observedRequest): bool
    {
        if (! str_contains($templateRequest, '{')) {
            return false;
        }

        $template = self::splitOperationRequest($templateRequest);
        $observed = self::splitOperationRequest($observedRequest);
        if (
            $template['method'] === ''
            || $observed['method'] === ''
            || $template['method'] !== $observed['method']
            || $template['path'] === ''
            || $observed['path'] === ''
        ) {
            return false;
        }

        return preg_match('#^'.self::operationPathTemplateRegex($template['path']).'$#', $observed['path']) === 1;
    }

    /**
     * @return array{method: string, path: string}
     */
    private static function splitOperationRequest(string $request): array
    {
        $normalizedRequest = self::normalizeOperationRequest($request);
        $parts = explode(' ', $normalizedRequest, 2);
        if (count($parts) === 2 && self::isHttpMethod($parts[0])) {
            return [
                'method' => $parts[0],
                'path' => $parts[1],
            ];
        }

        return [
            'method' => '',
            'path' => $normalizedRequest,
        ];
    }

    private static function normalizeOperationRequest(string $request, string $methodHint = ''): string
    {
        $request = trim((string) preg_replace('/\s+/', ' ', $request));
        if ($request === '') {
            return '';
        }

        $method = strtoupper(trim($methodHint));
        $path = $request;
        $parts = explode(' ', $request, 2);
        if (count($parts) === 2 && self::isHttpMethod($parts[0])) {
            $method = strtoupper($parts[0]);
            $path = $parts[1];
        }

        $path = self::normalizeOperationPath($path);
        if ($path === '') {
            return '';
        }

        return $method !== '' ? $method.' '.$path : $path;
    }

    private static function normalizeOperationPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            $parsedPath = parse_url($path, PHP_URL_PATH);
            if (is_string($parsedPath) && $parsedPath !== '') {
                $path = $parsedPath;
            }
        }

        $path = explode('#', $path, 2)[0];
        $path = explode('?', $path, 2)[0];
        $path = trim($path);
        if ($path === '') {
            return '/';
        }

        return str_starts_with($path, '/') ? $path : '/'.$path;
    }

    private static function operationPathTemplateRegex(string $path): string
    {
        if ($path === '/') {
            return '/';
        }

        $segments = explode('/', trim($path, '/'));
        $regexSegments = [];
        foreach ($segments as $segment) {
            $regexSegments[] = preg_match('/^\{[^\/{}]+\}$/', $segment) === 1
                ? '[^/]+'
                : preg_quote($segment, '#');
        }

        return '/'.implode('/', $regexSegments);
    }

    private static function isHttpMethod(string $method): bool
    {
        return in_array(strtoupper($method), [
            'GET',
            'POST',
            'PUT',
            'PATCH',
            'DELETE',
            'HEAD',
            'OPTIONS',
        ], true);
    }

    /**
     * @param array<string, mixed> $surfaceContract
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private static function refusalRequirementFailures(
        string $surface,
        array $surfaceContract,
        string $pairingClass,
        array $row,
        string $scope,
    ): array {
        $failures = [];
        $met = self::stringList($row['refusal_requirements_met'] ?? $row['refusalRequirementsMet'] ?? []);

        foreach (self::stringList($surfaceContract['refusal_requirements'] ?? []) as $requirement) {
            $value = $row[$requirement] ?? null;
            if ($value === true || in_array($requirement, $met, true)) {
                continue;
            }

            $failures[] = [
                'code' => 'missing_loud_refusal_requirement',
                'surface' => $surface,
                'pairing_class' => $pairingClass,
                'scope' => $scope,
                'requirement' => $requirement,
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $contract
     * @return list<array<string, mixed>>
     */
    private static function surfaceSpecificClassificationFailures(string $surface, string $pairingClass, array $row, array $contract): array
    {
        $failures = [];
        $status = self::resultStatus($row);

        if (self::isCoverageGapStatus($status)) {
            return [];
        }

        if ($surface === 'sdk-php') {
            $classification = self::stringValue(
                $row['worker_skew_classification']
                    ?? $row['workerSkewClassification']
                    ?? null,
            );
            if ($classification === '') {
                $failures[] = [
                    'code' => 'missing_worker_skew_classification',
                    'surface' => $surface,
                    'pairing_class' => $pairingClass,
                ];
            } elseif (! in_array($classification, self::stringList($contract['worker_skew_classification']['allowed'] ?? []), true)) {
                $failures[] = [
                    'code' => 'invalid_worker_skew_classification',
                    'surface' => $surface,
                    'pairing_class' => $pairingClass,
                    'classification' => $classification,
                ];
            } elseif (in_array($classification, self::stringList($contract['worker_skew_classification']['blocking'] ?? []), true)) {
                $failures[] = [
                    'code' => 'blocking_worker_skew_classification',
                    'surface' => $surface,
                    'pairing_class' => $pairingClass,
                    'classification' => $classification,
                ];
            }
        }

        if ($surface === 'waterline') {
            $classification = self::stringValue(
                $row['waterline_skew_classification']
                    ?? $row['waterlineSkewClassification']
                    ?? null,
            );
            if ($classification === '') {
                $failures[] = [
                    'code' => 'missing_waterline_skew_classification',
                    'surface' => $surface,
                    'pairing_class' => $pairingClass,
                ];
            } elseif (! in_array($classification, self::stringList($contract['waterline_skew_classification']['allowed'] ?? []), true)) {
                $failures[] = [
                    'code' => 'invalid_waterline_skew_classification',
                    'surface' => $surface,
                    'pairing_class' => $pairingClass,
                    'classification' => $classification,
                ];
            } elseif (in_array($classification, self::stringList($contract['waterline_skew_classification']['blocking'] ?? []), true)) {
                $failures[] = [
                    'code' => 'blocking_waterline_skew_classification',
                    'surface' => $surface,
                    'pairing_class' => $pairingClass,
                    'classification' => $classification,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $contract
     * @return list<array<string, mixed>>
     */
    private static function runRecordFailures(array $result, array $contract): array
    {
        $failures = [];
        foreach (self::stringList($contract['artifact_policy']['required_run_record_fields'] ?? []) as $field) {
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
     * @param array<string, mixed> $contract
     * @return list<array<string, mixed>>
     */
    private static function artifactVersionFailures(array $result, array $contract): array
    {
        $failures = [];
        $versions = self::artifactVersions($result);

        foreach (self::stringList($contract['artifact_policy']['required_artifacts'] ?? []) as $artifact) {
            $version = self::stringValue($versions[$artifact] ?? null);
            if ($version === '') {
                $failures[] = [
                    'code' => 'missing_artifact_version',
                    'artifact' => $artifact,
                ];
                continue;
            }

            foreach (self::placeholderVersions($contract) as $placeholder) {
                if (str_contains(strtolower($version), strtolower($placeholder))) {
                    $failures[] = [
                        'code' => 'placeholder_artifact_version',
                        'artifact' => $artifact,
                        'version' => $version,
                        'placeholder' => $placeholder,
                    ];
                    break;
                }
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     * @param array<string, array<string, mixed>> $surfaceResults
     * @param array<string, array<string, array<string, mixed>>> $pairingResults
     * @param array<string, array<string, array<string, list<array<string, mixed>>>>> $operationEvidence
     * @return list<array<string, mixed>>
     */
    private static function sourcePolicyFailures(
        array $result,
        array $contract,
        array $surfaceResults,
        array $pairingResults,
        array $operationEvidence,
    ): array {
        $forbiddenSources = self::stringList($contract['artifact_policy']['forbidden_sources'] ?? []);
        if ($forbiddenSources === []) {
            return [];
        }

        $reportedSourceSets = [];
        self::collectArtifactSourceSets($result, $reportedSourceSets, 'result');

        foreach ($surfaceResults as $surface => $surfaceResult) {
            self::collectArtifactSourceSets($surfaceResult, $reportedSourceSets, 'surface', $surface);
        }

        foreach ($pairingResults as $surface => $surfacePairings) {
            foreach ($surfacePairings as $pairingClass => $pairing) {
                self::collectArtifactSourceSets(
                    $pairing,
                    $reportedSourceSets,
                    'pairing',
                    $surface,
                    $pairingClass,
                );
            }
        }

        foreach ($operationEvidence as $surface => $surfaceEvidence) {
            foreach ($surfaceEvidence as $pairingClass => $pairings) {
                foreach ($pairings as $operationGroup => $rows) {
                    foreach ($rows as $index => $row) {
                        self::collectArtifactSourceSets(
                            $row,
                            $reportedSourceSets,
                            'operation',
                            $surface,
                            $pairingClass,
                            $operationGroup,
                            $index,
                        );
                    }
                }
            }
        }

        $failures = [];
        foreach ($reportedSourceSets as $sourceSet) {
            foreach ($sourceSet['sources'] as $artifact => $source) {
                $source = self::stringValue($source);
                if (! in_array($source, $forbiddenSources, true)) {
                    continue;
                }

                $failure = [
                    'code' => 'forbidden_artifact_source',
                    'artifact' => is_string($artifact) ? $artifact : null,
                    'source' => $source,
                    'field' => $sourceSet['field'],
                    'scope' => $sourceSet['scope'],
                ];

                foreach (['surface', 'pairing_class', 'operation_group', 'index'] as $contextField) {
                    if (array_key_exists($contextField, $sourceSet)) {
                        $failure[$contextField] = $sourceSet[$contextField];
                    }
                }

                $failures[] = $failure;
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $reportedSourceSets
     */
    private static function collectArtifactSourceSets(
        array $row,
        array &$reportedSourceSets,
        string $scope,
        ?string $surface = null,
        ?string $pairingClass = null,
        ?string $operationGroup = null,
        ?int $index = null,
    ): void {
        foreach (['artifact_sources', 'artifactSources', 'source_paths', 'sourcePaths'] as $field) {
            $sources = self::arrayField($row, [$field]);
            if ($sources === null) {
                continue;
            }

            $sourceSet = [
                'sources' => $sources,
                'field' => $field,
                'scope' => $scope,
            ];

            if ($surface !== null) {
                $sourceSet['surface'] = $surface;
            }

            if ($pairingClass !== null) {
                $sourceSet['pairing_class'] = $pairingClass;
            }

            if ($operationGroup !== null) {
                $sourceSet['operation_group'] = $operationGroup;
            }

            if ($index !== null) {
                $sourceSet['index'] = $index;
            }

            $reportedSourceSets[] = $sourceSet;
        }
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private static function artifactVersions(array $result): array
    {
        foreach (['artifact_versions', 'artifactVersions', 'published_artifact_versions', 'publishedArtifactVersions'] as $field) {
            $versions = self::arrayField($result, [$field]);
            if ($versions !== null) {
                return $versions;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $contract
     * @return list<string>
     */
    private static function placeholderVersions(array $contract): array
    {
        $examples = self::stringList($contract['artifact_policy']['placeholder_version_examples'] ?? []);
        if ($examples !== []) {
            return $examples;
        }

        return [
            'latest',
            'current',
            'head',
            'unresolved',
            'placeholder',
            '<latest>',
            '${VERSION}',
            '{{ version }}',
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return list<array<string, mixed>>
     */
    private static function declaredOutcomeFailures(array $result, string $evaluatedStatus): array
    {
        $declaredOutcomes = self::declaredOutcomeTokens($result);
        if ($declaredOutcomes === []) {
            return [[
                'code' => 'missing_declared_outcome',
            ]];
        }

        $failures = [];
        $declaredStatuses = [];
        foreach ($declaredOutcomes as $field => $outcome) {
            if (! self::isKnownDeclaredOutcome($outcome)) {
                $failures[] = [
                    'code' => 'invalid_declared_outcome',
                    'field' => $field,
                    'outcome' => $outcome,
                ];
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

    private static function isKnownDeclaredOutcome(string $outcome): bool
    {
        return $outcome === 'pass'
            || in_array($outcome, self::stringList(self::spec()['non_pass_statuses']), true)
            || str_starts_with($outcome, 'non_passing');
    }

    /**
     * @param array<string, mixed> $operationEvidence
     * @param array<string, array<string, mixed>> $requiredSurfaces
     */
    private static function isSmokeSubset(array $operationEvidence, array $requiredSurfaces): bool
    {
        $groups = [];
        foreach ($operationEvidence as $surface => $surfaceEvidence) {
            if (! isset($requiredSurfaces[$surface])) {
                continue;
            }

            foreach ($surfaceEvidence as $pairings) {
                foreach ($pairings as $operationGroup => $_rows) {
                    $groups[$operationGroup] = true;
                }
            }
        }

        return $groups !== [] && array_keys($groups) === ['cluster_info_probe'];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function resultStatus(array $row): string
    {
        return self::stringValue($row['status'] ?? $row['outcome'] ?? null);
    }

    /**
     * @param array<string, mixed> $contract
     */
    private static function statusAllowedForPairingClass(string $status, string $pairingClass, array $contract): bool
    {
        if (self::isCoverageGapStatus($status)) {
            return true;
        }

        return in_array($status, self::stringList($contract['pairing_classes'][$pairingClass]['expected_statuses'] ?? []), true);
    }

    private static function isCoverageGapStatus(string $status): bool
    {
        return in_array($status, ['not_covered', 'runner_blocked'], true);
    }

    /**
     * @return list<string>
     */
    private static function blockingStatuses(): array
    {
        return [
            'mutation_before_refusal',
            'silent_success',
            'silent_failure',
            'corrupt',
            'not_covered',
            'runner_blocked',
        ];
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function hasLinkedFindings(array $result): bool
    {
        foreach (['finding_links', 'findingLinks', 'findings', 'linked_findings', 'linkedFindings'] as $field) {
            $value = self::arrayField($result, [$field]);
            if ($value !== null && $value !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $result
     * @param list<string> $nonPassCells
     * @return list<string>
     */
    private static function nonPassCellsMissingFocusedFindings(array $result, array $nonPassCells): array
    {
        $tokens = self::focusedFindingTokens($result);

        return array_values(array_filter(
            $nonPassCells,
            static fn (string $cell): bool => ! self::cellHasFocusedFinding($cell, $tokens),
        ));
    }

    /**
     * @param array<string, mixed> $result
     * @return list<string>
     */
    private static function focusedFindingTokens(array $result): array
    {
        $tokens = [];
        foreach (['finding_links', 'findingLinks', 'findings', 'linked_findings', 'linkedFindings'] as $field) {
            self::collectFocusedFindingTokens(self::arrayField($result, [$field]) ?? [], $tokens);
        }

        return array_keys($tokens);
    }

    /**
     * @param mixed $raw
     * @param array<string, true> $tokens
     */
    private static function collectFocusedFindingTokens(mixed $raw, array &$tokens): void
    {
        if (! is_array($raw)) {
            if (is_string($raw) && self::isFindingCellToken($raw)) {
                $tokens[$raw] = true;
            }

            return;
        }

        $composedToken = self::composedFindingToken($raw);
        if ($composedToken !== '') {
            $tokens[$composedToken] = true;
        }

        foreach ($raw as $key => $value) {
            if (is_string($key) && self::isFindingCellToken($key)) {
                $tokens[$key] = true;
            }

            if (is_string($value) && self::isFindingCellToken($value)) {
                $tokens[$value] = true;
            }

            if (is_array($value)) {
                self::collectFocusedFindingTokens($value, $tokens);
            }
        }
    }

    /**
     * @param array<string, mixed> $finding
     */
    private static function composedFindingToken(array $finding): string
    {
        foreach (['cell', 'matrix_cell', 'matrixCell', 'non_pass_cell', 'nonPassCell'] as $field) {
            $cell = self::stringValue($finding[$field] ?? null);
            if (self::isFindingCellToken($cell)) {
                return $cell;
            }
        }

        $parts = [];
        foreach (['surface', 'pairing_class', 'pairingClass', 'operation_group', 'operationGroup'] as $field) {
            $value = self::stringValue($finding[$field] ?? null);
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        $token = implode('.', $parts);

        return self::isFindingCellToken($token) ? $token : '';
    }

    /**
     * @param list<string> $tokens
     */
    private static function cellHasFocusedFinding(string $cell, array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (
                $token === $cell
                || ($token !== '' && str_starts_with($cell, $token.'.'))
                || str_starts_with($token, $cell.'.')
            ) {
                return true;
            }
        }

        return false;
    }

    private static function isFindingCellToken(string $token): bool
    {
        return $token === 'smoke_only'
            || preg_match('/^(cli|sdk-python|sdk-php|waterline)(\.[A-Za-z0-9_-]+){0,3}$/', $token) === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hasRunRecordField(array $row, string $field): bool
    {
        return match ($field) {
            'artifact_versions' => self::artifactVersions($row) !== [],
            'runner_blocked' => array_key_exists('runner_blocked', $row) || array_key_exists('runnerBlocked', $row),
            'started_at' => self::hasScalarField($row, ['started_at', 'startedAt']),
            'finished_at' => self::hasScalarField($row, ['finished_at', 'finishedAt']),
            'outcome' => self::hasScalarField($row, ['outcome', 'status', 'verdict']),
            'surface_results' => self::hasArrayField($row, ['surface_results', 'surfaceResults']),
            'pairing_results' => self::hasArrayField($row, ['pairing_results', 'pairingResults']),
            'operation_evidence' => self::hasArrayField($row, ['operation_evidence', 'operationEvidence']),
            'request_response_captures' => array_key_exists('request_response_captures', $row)
                || array_key_exists('requestResponseCaptures', $row),
            'findings' => array_key_exists('findings', $row) && is_array($row['findings']),
            'finding_links' => (array_key_exists('finding_links', $row) && is_array($row['finding_links']))
                || (array_key_exists('findingLinks', $row) && is_array($row['findingLinks'])),
            'request' => self::operationEvidenceRequests($row) !== [],
            'request_headers' => array_key_exists('request_headers', $row) || array_key_exists('requestHeaders', $row),
            'request_body' => array_key_exists('request_body', $row) || array_key_exists('requestBody', $row),
            'response_headers' => array_key_exists('response_headers', $row) || array_key_exists('responseHeaders', $row),
            'response_body' => array_key_exists('response_body', $row) || array_key_exists('responseBody', $row),
            'request_response_capture_id' => self::requestResponseCaptureId($row) !== '',
            default => self::hasScalarField($row, [$field, self::camelize($field)])
                || self::hasArrayField($row, [$field, self::camelize($field)]),
        };
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $fields
     */
    private static function hasScalarField(array $row, array $fields): bool
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $row)) {
                continue;
            }

            $value = $row[$field];
            if (is_scalar($value) && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $fields
     */
    private static function hasArrayField(array $row, array $fields): bool
    {
        foreach ($fields as $field) {
            $value = self::arrayField($row, [$field]);
            if ($value !== null && $value !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $fields
     */
    private static function boolField(array $row, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $row) && $row[$field] === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $fields
     * @return array<string, mixed>|null
     */
    private static function arrayField(array $row, array $fields): ?array
    {
        foreach ($fields as $field) {
            $value = $row[$field] ?? null;
            if (is_array($value)) {
                return $value;
            }
        }

        return null;
    }

    private static function camelize(string $field): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $field))));
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $string = trim((string) $item);
                if ($string !== '') {
                    $strings[] = $string;
                }
            }
        }

        return array_values($strings);
    }

    private static function stringValue(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }
}
