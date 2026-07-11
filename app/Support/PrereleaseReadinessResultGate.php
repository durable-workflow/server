<?php

namespace App\Support;

/**
 * Evaluates prerelease readiness results against the published 2.0 matrix.
 */
final class PrereleaseReadinessResultGate
{
    public const SCHEMA = 'durable-workflow.v2.prerelease-readiness.result-gate';

    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'evaluates_result_schema' => PrereleaseReadinessContract::RESULT_SCHEMA,
            'scenario_statuses_source' => 'prerelease_readiness_contract.scenario_statuses',
            'required_scenarios_source' => 'prerelease_readiness_contract.required_scenarios',
            'required_matrix_source' => 'prerelease_readiness_contract.required_matrix',
            'required_run_record_fields_source' => 'prerelease_readiness_contract.artifact_policy.required_run_record_fields',
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
                'separate_workflow_and_waterline_go_verdicts',
                'all_readiness_category_verdicts_go',
                'published_artifact_versions_are_recorded_and_pinned',
                'artifact_source_recorded_for_each_required_artifact',
                'public_docs_urls_are_versioned_prerelease_routes',
                'stable_default_docs_line_remains_1_x',
                'no_local_product_source_artifacts_are_reported',
                'each_pass_scenario_has_observed_outputs',
                'each_pass_scenario_has_scenario_specific_evidence',
                'each_non_pass_scenario_has_linked_findings',
                'run_timestamps_outcome_and_finding_links_are_recorded',
                'overall_outcome_matches_gate_status',
                'runner_blocked_false_for_product_evidence',
            ],
            'installability_smoke_only_outcome' => 'non_passing',
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
        $contract ??= PrereleaseReadinessContract::manifest();

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

                foreach (self::missingScenarioEvidence($scenarioId, $scenarioResult, $result, $contract) as $field) {
                    $failures[] = [
                        'code' => 'missing_scenario_required_field',
                        'scenario_id' => $scenarioId,
                        'field' => $field,
                    ];
                }
            } else {
                $nonPassScenarios[] = $scenarioId;
                if (! self::hasLinkedFindings($scenarioId, $scenarioResult, $result)) {
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
            if ($status !== '' && ! in_array($status, $allowedStatuses, true)) {
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
        array_push($failures, ...self::artifactSourceFailures($result, $contract));
        array_push($failures, ...self::verdictFailures($result, $contract));
        array_push($failures, ...self::sourcePolicyFailures($result, $contract, $scenarioResults));
        array_push($failures, ...self::publicDocsFailures($result, $scenarioResults));
        array_push($failures, ...self::declaredOutcomeFailures($result));

        $smokeSubsetDetected = self::isSmokeSubset($reportedScenarioIds);
        if ($smokeSubsetDetected) {
            $failures[] = [
                'code' => 'installability_smoke_subset_cannot_pass',
                'reason' => 'Published-artifact installability is required evidence but is not the full prerelease readiness matrix.',
            ];
        }

        $evidencePasses = $failures === []
            && $missingScenarios === []
            && $nonPassScenarios === []
            && count($scenarioStatuses) >= count($requiredScenarios);
        $evaluatedStatus = $evidencePasses ? 'pass' : 'non_passing';

        array_push($failures, ...self::declaredOutcomeStatusFailures($result, $evaluatedStatus));

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
        $raw = self::arrayField($result, ['scenario_results', 'scenarioResults']) ?? [];
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
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return list<array<string, mixed>>
     */
    private static function runRecordFailures(array $result, array $contract): array
    {
        $failures = [];
        $requiredFields = self::stringList($contract['artifact_policy']['required_run_record_fields'] ?? []);

        foreach ($requiredFields as $field) {
            if (in_array($field, ['findings', 'finding_links'], true)) {
                $present = array_key_exists($field, $result);
            } elseif ($field === 'runner_blocked') {
                $present = self::hasAnyField($result, ['runner_blocked', 'runnerBlocked']);
            } else {
                $present = self::hasNonEmptyField($result, $field);
            }

            if (! $present) {
                $failures[] = [
                    'code' => 'missing_required_run_record_field',
                    'field' => $field,
                ];
            }
        }

        if (! self::hasExplicitFalseField($result, ['runner_blocked', 'runnerBlocked'])) {
            $failures[] = [
                'code' => 'runner_blocked_result_is_not_product_evidence',
                'field' => 'runner_blocked',
                'value' => self::firstFieldValue($result, ['runner_blocked', 'runnerBlocked']),
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return list<array<string, mixed>>
     */
    private static function artifactVersionFailures(array $result, array $contract): array
    {
        $failures = [];
        $versions = self::arrayField($result, [
            'artifact_versions',
            'artifactVersions',
            'published_artifact_versions',
            'publishedArtifactVersions',
            'resolved_artifact_versions',
            'resolvedArtifactVersions',
        ]) ?? [];

        foreach (self::stringList($contract['required_matrix']['ecosystem_artifacts'] ?? []) as $artifact) {
            $version = self::stringValue($versions[$artifact] ?? null);
            if ($version === '') {
                $failures[] = [
                    'code' => 'missing_published_artifact_version',
                    'artifact' => $artifact,
                ];
                continue;
            }

            if (self::isPlaceholderVersion($version, $contract)) {
                $failures[] = [
                    'code' => 'placeholder_published_artifact_version',
                    'artifact' => $artifact,
                    'version' => $version,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return list<array<string, mixed>>
     */
    private static function artifactSourceFailures(array $result, array $contract): array
    {
        $failures = [];
        $sources = self::arrayField($result, ['artifact_sources', 'artifactSources']) ?? [];

        foreach (self::stringList($contract['required_matrix']['ecosystem_artifacts'] ?? []) as $artifact) {
            if (! self::hasNonEmptyValue($sources[$artifact] ?? null)) {
                $failures[] = [
                    'code' => 'missing_artifact_source',
                    'artifact' => $artifact,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return list<array<string, mixed>>
     */
    private static function verdictFailures(array $result, array $contract): array
    {
        $failures = [];
        foreach (['workflow_readiness_verdict', 'waterline_readiness_verdict'] as $field) {
            if (! self::isGoVerdict($result[$field] ?? null)) {
                $failures[] = [
                    'code' => 'readiness_verdict_not_go',
                    'field' => $field,
                    'verdict' => $result[$field] ?? null,
                ];
            }
        }

        $categoryVerdicts = self::arrayField($result, ['category_verdicts', 'categoryVerdicts']) ?? [];
        foreach (self::stringList($contract['required_matrix']['readiness_categories'] ?? []) as $category) {
            if (! array_key_exists($category, $categoryVerdicts)) {
                $failures[] = [
                    'code' => 'missing_category_verdict',
                    'category' => $category,
                ];
                continue;
            }

            if (! self::isGoVerdict($categoryVerdicts[$category])) {
                $failures[] = [
                    'code' => 'category_verdict_not_go',
                    'category' => $category,
                    'verdict' => $categoryVerdicts[$category],
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return list<array<string, mixed>>
     */
    private static function sourcePolicyFailures(array $result, array $contract, array $scenarioResults): array
    {
        $failures = [];
        if (! self::hasExplicitFalseField($result, [
            'local_product_source_checkouts_used',
            'localProductSourceCheckoutsUsed',
        ])) {
            $failures[] = [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'field' => 'local_product_source_checkouts_used',
                'value' => self::firstFieldValue($result, [
                    'local_product_source_checkouts_used',
                    'localProductSourceCheckoutsUsed',
                ]),
            ];
        }

        array_push(
            $failures,
            ...self::localProductSourceFlagFailures($scenarioResults, 'scenario_results'),
        );

        $sources = self::arrayField($result, ['artifact_sources', 'artifactSources']) ?? [];
        $forbiddenSources = self::stringList($contract['artifact_policy']['forbidden_sources'] ?? []);
        foreach ($sources as $artifact => $source) {
            $sourceText = self::sourceText($source);
            foreach ($forbiddenSources as $forbiddenSource) {
                if ($sourceText !== '' && str_contains($sourceText, $forbiddenSource)) {
                    $failures[] = [
                        'code' => 'forbidden_published_artifact_source',
                        'artifact' => is_string($artifact) ? $artifact : (string) $artifact,
                        'source' => $sourceText,
                        'forbidden_source' => $forbiddenSource,
                    ];
                }
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return list<array<string, mixed>>
     */
    private static function publicDocsFailures(array $result, array $scenarioResults): array
    {
        $failures = [];
        $urls = self::publicDocsUrls($result);

        if ($urls === []) {
            $failures[] = [
                'code' => 'missing_public_docs_url',
                'field' => 'public_docs_urls',
                'expected' => 'at least one explicit versioned 2.0 prerelease docs route',
            ];
        } elseif (! self::hasVersionedPrereleaseDocsUrl($urls)) {
            $failures[] = [
                'code' => 'missing_versioned_prerelease_public_docs_url',
                'field' => 'public_docs_urls',
                'expected' => 'at least one explicit versioned 2.0 prerelease docs route',
                'urls' => $urls,
            ];
        }

        if (! self::hasStableDefaultDocsEvidence($result, $scenarioResults)) {
            $failures[] = [
                'code' => 'stable_default_docs_line_not_recorded',
                'field' => 'release_channel_observations',
                'expected' => 'stable_default_docs_version=1.x',
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $fields
     */
    private static function hasAnyField(array $value, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $fields
     */
    private static function hasExplicitFalseField(array $value, array $fields): bool
    {
        $found = false;
        foreach ($fields as $field) {
            if (! array_key_exists($field, $value)) {
                continue;
            }

            $found = true;
            if ($value[$field] !== false) {
                return false;
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $fields
     */
    private static function firstFieldValue(array $value, array $fields): mixed
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $value)) {
                return $value[$field];
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     *
     * @return list<array<string, mixed>>
     */
    private static function localProductSourceFlagFailures(mixed $value, string $path): array
    {
        if (! is_array($value)) {
            return [];
        }

        $failures = [];
        foreach (['local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed'] as $field) {
            if (! array_key_exists($field, $value) || $value[$field] === false) {
                continue;
            }

            $failures[] = [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'field' => self::fieldPath($path, $field),
                'value' => $value[$field],
            ];
        }

        foreach ($value as $key => $child) {
            if (! is_array($child)) {
                continue;
            }

            array_push(
                $failures,
                ...self::localProductSourceFlagFailures($child, self::fieldPath($path, (string) $key)),
            );
        }

        return $failures;
    }

    private static function fieldPath(string $path, string $field): string
    {
        return $path === '' ? $field : $path.'.'.$field;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<string>
     */
    private static function publicDocsUrls(array $result): array
    {
        return self::stringValues(self::firstFieldValue($result, ['public_docs_urls', 'publicDocsUrls']));
    }

    /**
     * @param list<string> $urls
     */
    private static function hasVersionedPrereleaseDocsUrl(array $urls): bool
    {
        foreach ($urls as $url) {
            if (preg_match('~/docs/(?:v?2(?:\.0)?|version-2(?:\.0)?)(?:[/?#]|$)~', strtolower($url)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private static function stringValues(mixed $value): array
    {
        if (is_scalar($value)) {
            $string = self::stringValue($value);

            return $string === '' ? [] : [$string];
        }

        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $child) {
            array_push($strings, ...self::stringValues($child));
        }

        return $strings;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, array<string, mixed>> $scenarioResults
     */
    private static function hasStableDefaultDocsEvidence(array $result, array $scenarioResults): bool
    {
        foreach (self::docsEvidenceContainers($result, $scenarioResults) as $container) {
            foreach ([
                'release_channel_observations',
                'releaseChannelObservations',
                'docs_guardrail_evidence',
                'docsGuardrailEvidence',
                'public_docs_guardrail',
                'publicDocsGuardrail',
                'stable_docs_guardrail',
                'stableDocsGuardrail',
            ] as $field) {
                if (
                    array_key_exists($field, $container)
                    && self::containsStableDefaultDocsEvidence($container[$field], $field)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, array<string, mixed>> $scenarioResults
     *
     * @return list<array<string, mixed>>
     */
    private static function docsEvidenceContainers(array $result, array $scenarioResults): array
    {
        $containers = [$result];
        foreach ($scenarioResults as $scenarioResult) {
            $containers[] = $scenarioResult;
            foreach (['observed_outputs', 'observedOutputs'] as $field) {
                if (isset($scenarioResult[$field]) && is_array($scenarioResult[$field])) {
                    $containers[] = $scenarioResult[$field];
                }
            }
        }

        return $containers;
    }

    private static function containsStableDefaultDocsEvidence(mixed $value, string $key = ''): bool
    {
        if (is_array($value)) {
            foreach ($value as $childKey => $child) {
                if (self::containsStableDefaultDocsEvidence($child, (string) $childKey)) {
                    return true;
                }
            }

            return false;
        }

        $text = strtolower(self::stringValue($value));
        if ($text === '') {
            return false;
        }

        $keyText = strtolower(str_replace(['-', ' '], '_', $key));
        $keyNamesStableDocs = str_contains($keyText, 'stable')
            && str_contains($keyText, 'default')
            && str_contains($keyText, 'docs');
        $keyNamesDefaultDocs = str_contains($keyText, 'default')
            && str_contains($keyText, 'docs')
            && (str_contains($keyText, 'version') || str_contains($keyText, 'line'));

        if (($keyNamesStableDocs || $keyNamesDefaultDocs) && self::isStableOneXDocsValue($text)) {
            return true;
        }

        return str_contains($text, '1.x')
            && str_contains($text, 'stable')
            && str_contains($text, 'default')
            && str_contains($text, 'docs');
    }

    private static function isStableOneXDocsValue(string $value): bool
    {
        $normalized = strtolower(trim($value));

        return $normalized === '1.x'
            || $normalized === 'v1.x'
            || str_contains($normalized, '1.x')
            || str_contains($normalized, 'stable 1')
            || str_contains($normalized, 'stable-1')
            || str_contains($normalized, 'stable_1');
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<array<string, mixed>>
     */
    private static function declaredOutcomeFailures(array $result): array
    {
        foreach (['outcome', 'status', 'verdict'] as $field) {
            if (array_key_exists($field, $result) && self::stringValue($result[$field]) === '') {
                return [[
                    'code' => 'empty_declared_outcome',
                    'field' => $field,
                ]];
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<array<string, mixed>>
     */
    private static function declaredOutcomeStatusFailures(array $result, string $evaluatedStatus): array
    {
        $declared = self::stringValue($result['outcome'] ?? $result['status'] ?? $result['verdict'] ?? null);
        if ($declared === '') {
            return [];
        }

        $declaredStatus = in_array(strtolower($declared), ['pass', 'passing', 'go'], true)
            ? 'pass'
            : 'non_passing';

        if ($declaredStatus !== $evaluatedStatus) {
            return [[
                'code' => 'declared_outcome_does_not_match_gate',
                'declared_outcome' => $declared,
                'evaluated_status' => $evaluatedStatus,
            ]];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     *
     * @return list<string>
     */
    private static function missingScenarioEvidence(
        string $scenarioId,
        array $scenarioResult,
        array $result,
        array $contract,
    ): array {
        $requirements = $contract['scenario_requirements'][$scenarioId]['evidence'] ?? [];
        $missing = [];
        $observedOutputs = is_array($scenarioResult['observed_outputs'] ?? null)
            ? $scenarioResult['observed_outputs']
            : [];

        foreach (self::stringList($requirements) as $field) {
            if (
                $scenarioId === 'focused_finding_routing'
                && in_array($field, ['findings', 'finding_links'], true)
                && (array_key_exists($field, $scenarioResult)
                    || array_key_exists($field, $observedOutputs)
                    || array_key_exists($field, $result))
            ) {
                continue;
            }

            if (
                ! self::hasNonEmptyField($scenarioResult, $field)
                && ! self::hasNonEmptyField($observedOutputs, $field)
                && ! self::hasNonEmptyField($result, $field)
            ) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $scenarioResult
     */
    private static function hasObservedOutputs(array $scenarioResult): bool
    {
        return self::hasNonEmptyField($scenarioResult, 'observed_outputs')
            || self::hasNonEmptyField($scenarioResult, 'observedOutputs');
    }

    /**
     * @param array<string, mixed> $scenarioResult
     * @param array<string, mixed> $result
     */
    private static function hasLinkedFindings(string $scenarioId, array $scenarioResult, array $result): bool
    {
        if (self::hasNonEmptyField($scenarioResult, 'linked_findings')
            || self::hasNonEmptyField($scenarioResult, 'linkedFindings')
        ) {
            return true;
        }

        $findingLinks = self::arrayField($result, ['finding_links', 'findingLinks']) ?? [];
        if (self::hasNonEmptyValue($findingLinks[$scenarioId] ?? null)) {
            return true;
        }

        foreach ($findingLinks as $findingLink) {
            if (! is_array($findingLink)) {
                continue;
            }

            if (
                self::stringValue($findingLink['scenario_id'] ?? $findingLink['scenario'] ?? null) === $scenarioId
                && self::hasNonEmptyValue($findingLink)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $reportedScenarioIds
     */
    private static function isSmokeSubset(array $reportedScenarioIds): bool
    {
        return $reportedScenarioIds !== []
            && array_values(array_diff($reportedScenarioIds, ['published_artifact_release_set'])) === [];
    }

    /**
     * @param array<string, mixed> $contract
     */
    private static function isPlaceholderVersion(string $version, array $contract): bool
    {
        $normalized = strtolower(trim($version));
        foreach (self::stringList($contract['artifact_policy']['placeholder_version_examples'] ?? []) as $placeholder) {
            $placeholder = strtolower(trim($placeholder));
            if ($placeholder !== '' && str_contains($normalized, $placeholder)) {
                return true;
            }
        }

        return preg_match('/<[^>]+>|\$\{[^}]+}|{{[^}]+}}/', $normalized) === 1;
    }

    private static function isGoVerdict(mixed $value): bool
    {
        if (is_array($value)) {
            $value = $value['verdict'] ?? $value['outcome'] ?? $value['status'] ?? null;
        }

        return in_array(strtolower(self::stringValue($value)), ['go', 'pass', 'passing'], true);
    }

    /**
     * @param mixed $source
     */
    private static function sourceText(mixed $source): string
    {
        if (is_scalar($source) || $source === null) {
            return self::stringValue($source);
        }

        $encoded = json_encode($source);

        return is_string($encoded) ? $encoded : '';
    }

    /**
     * @param array<string, mixed>|mixed $value
     */
    private static function hasNonEmptyField(mixed $value, string $field): bool
    {
        return is_array($value) && array_key_exists($field, $value) && self::hasNonEmptyValue($value[$field]);
    }

    private static function hasNonEmptyValue(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    /**
     * @param mixed $value
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
            if (is_scalar($item)) {
                $strings[] = (string) $item;
            }
        }

        return $strings;
    }

    /**
     * @param array<string, mixed> $array
     * @param list<string> $fields
     *
     * @return array<string, mixed>|null
     */
    private static function arrayField(array $array, array $fields): ?array
    {
        foreach ($fields as $field) {
            if (isset($array[$field]) && is_array($array[$field])) {
                return $array[$field];
            }
        }

        return null;
    }

    private static function stringValue(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }
}
