<?php

namespace App\Support;

/**
 * Evaluates principal-attribution conformance results against the public
 * scenario manifest exposed by PrincipalAttributionContract.
 */
final class PrincipalAttributionResultGate
{
    public const SCHEMA = 'durable-workflow.v2.principal-attribution.result-gate';

    public const VERSION = 1;

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
                'alice_bob_rotation_anonymous_python_php_cli_and_waterline_cells_reported',
                'spoofing_payload_and_gateway_header_attempts_reported',
                'each_pass_scenario_has_required_evidence_fields',
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
        $value = $scenarioResult[$field] ?? $scenarioResult[self::camelize($field)] ?? null;

        return $value !== null && $value !== '';
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
    ): array {
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
