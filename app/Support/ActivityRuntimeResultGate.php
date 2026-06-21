<?php

namespace App\Support;

/**
 * Evaluates activity conformance results against the public activity contract
 * exposed by ActivityRuntimeContract.
 */
final class ActivityRuntimeResultGate
{
    public const SCHEMA = 'durable-workflow.v2.activity-runtime.result-gate';

    public const VERSION = 2;

    private const FORBIDDEN_ARTIFACT_SOURCE_TOKENS = [
        'local_product_source_checkout',
        'workspace_repo_as_artifact_under_test',
        'local_checkout_artifact',
        'local_checkout',
        'local_source_checkout',
        'source_checkout',
        'workspace_repo',
        'unverified_artifact_source',
    ];

    private const PUBLISHED_SERVER_IMAGE_REPOSITORIES = [
        'durableworkflow/server',
        'docker.io/durableworkflow/server',
        'index.docker.io/durableworkflow/server',
        'registry-1.docker.io/durableworkflow/server',
        'ghcr.io/durable-workflow/server',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'evaluates_result_schema' => ActivityRuntimeContract::RESULT_SCHEMA,
            'scenario_statuses_source' => 'activity_runtime_contract.scenario_statuses',
            'required_scenarios_source' => 'activity_runtime_contract.required_scenarios',
            'required_matrix_source' => 'activity_runtime_contract.required_matrix',
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
            'declared_outcomes_source' => 'activity_runtime_contract.coverage_gate.*_outcome',
            'non_pass_statuses' => [
                'fail',
                'unsupported',
                'not_covered',
                'runner_blocked',
            ],
            'pass_requires' => [
                'every_required_scenario_has_one_result',
                'every_result_uses_a_published_status',
                'workflow_embedded_and_standalone_activity_modes_are_reported',
                'required_php_and_python_activity_runtimes_are_reported',
                'durable_result_retry_timeout_failure_heartbeat_cancellation_idempotency_and_visibility_sections_are_reported',
                'each_pass_scenario_has_observed_outputs',
                'each_pass_scenario_has_scenario_specific_evidence',
                'published_artifact_install_evidence_reported',
                'each_non_pass_scenario_has_linked_findings',
                'run_timestamps_outcome_and_finding_links_are_recorded',
                'overall_outcome_matches_gate_status',
                'published_artifact_versions_are_recorded_and_pinned',
                'no_local_product_source_artifacts_are_reported',
                'published_artifact_install_sources_are_recorded_for_every_required_channel',
                'runner_blocked_false_for_product_evidence',
                'non_pass_cells_are_classified_by_root_cause',
            ],
            'artifact_version_policy' => [
                'rejects_placeholder_versions' => true,
                'required_artifacts' => [
                    'server',
                    'cli',
                    'sdk-python',
                    'workflow',
                    'waterline',
                ],
                'accepted_aliases' => [
                    'workflow' => ['workflow-php'],
                    'sdk-python' => ['python'],
                ],
            ],
            'classification_policy' => [
                'allowed_non_pass_classifications' => [
                    'product-gap',
                    'coverage-gap',
                    'runner-gap',
                    'stale-artifact',
                    'pipeline-churn',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>|null  $contract
     *
     * @return array<string, mixed>
     */
    public static function evaluate(array $result, ?array $contract = null): array
    {
        $contract ??= ActivityRuntimeContract::manifest();

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
                if (! self::hasScenarioSpecificEvidence($scenarioResult)) {
                    $failures[] = [
                        'code' => 'missing_pass_scenario_specific_evidence',
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
                if (! self::hasAllowedClassification($scenarioResult, $result)) {
                    $failures[] = [
                        'code' => 'missing_or_invalid_non_pass_classification',
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
        array_push($failures, ...self::artifactVersionFailures($result));
        array_push($failures, ...self::sourcePolicyFailures($result, $contract));
        array_push($failures, ...self::matrixFailures($result, $contract));
        array_push($failures, ...self::requiredSectionFailures($result));

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
            'gate_failures' => $failures,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, int>  $duplicateScenarioCounts
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
     * @param  array<string, mixed>  $scenarioResult
     */
    private static function hasObservedOutputs(array $scenarioResult): bool
    {
        foreach ([
            'observed_outputs',
            'observedOutputs',
            'activity_evidence',
            'activityEvidence',
            'history_events',
            'historyEvents',
            'operator_visibility',
            'operatorVisibility',
            'runtime_matrix',
            'runtimeMatrix',
        ] as $field) {
            $value = self::arrayValue($scenarioResult, $field);
            if ($value !== null && $value !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $scenarioResult
     */
    private static function hasScenarioSpecificEvidence(array $scenarioResult): bool
    {
        $evidence = self::arrayValue($scenarioResult, 'scenario_evidence')
            ?? self::arrayValue($scenarioResult, 'scenarioEvidence')
            ?? self::arrayValue($scenarioResult, 'observed_outputs')
            ?? self::arrayValue($scenarioResult, 'observedOutputs');

        return $evidence !== null && $evidence !== [];
    }

    /**
     * @param  array<string, mixed>  $scenarioResult
     * @param  array<string, mixed>  $result
     */
    private static function hasLinkedFindings(array $scenarioResult, array $result): bool
    {
        foreach (['linked_findings', 'linkedFindings', 'findings'] as $field) {
            $value = self::arrayValue($scenarioResult, $field);
            if ($value !== null && $value !== []) {
                return true;
            }
        }

        $scenarioId = self::stringValue($scenarioResult['scenario_id'] ?? null);
        if ($scenarioId === '') {
            return false;
        }

        $findingLinks = self::arrayValue($result, 'finding_links') ?? self::arrayValue($result, 'findingLinks') ?? [];
        if (isset($findingLinks[$scenarioId]) && is_array($findingLinks[$scenarioId]) && $findingLinks[$scenarioId] !== []) {
            return true;
        }

        foreach (self::arrayValue($result, 'findings') ?? [] as $finding) {
            if (! is_array($finding)) {
                continue;
            }
            if (self::stringValue($finding['scenario_id'] ?? $finding['scenarioId'] ?? null) === $scenarioId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $scenarioResult
     * @param  array<string, mixed>  $result
     */
    private static function hasAllowedClassification(array $scenarioResult, array $result): bool
    {
        $allowed = [
            'product-gap',
            'coverage-gap',
            'runner-gap',
            'stale-artifact',
            'pipeline-churn',
        ];

        $classification = self::stringValue(
            $scenarioResult['classification']
            ?? $scenarioResult['root_cause_classification']
            ?? $scenarioResult['rootCauseClassification']
            ?? null,
        );
        if (in_array($classification, $allowed, true)) {
            return true;
        }

        $scenarioId = self::stringValue($scenarioResult['scenario_id'] ?? null);
        foreach (self::arrayValue($result, 'findings') ?? [] as $finding) {
            if (! is_array($finding)) {
                continue;
            }
            if (self::stringValue($finding['scenario_id'] ?? $finding['scenarioId'] ?? null) !== $scenarioId) {
                continue;
            }

            $classification = self::stringValue(
                $finding['classification']
                ?? $finding['root_cause_classification']
                ?? $finding['rootCauseClassification']
                ?? null,
            );
            if (in_array($classification, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     *
     * @return list<array<string, mixed>>
     */
    private static function runRecordFailures(array $result, array $contract): array
    {
        $required = self::stringList($contract['artifact_policy']['required_run_record_fields'] ?? []);
        $failures = [];

        foreach ($required as $field) {
            if (! self::hasRunRecordField($result, $field)) {
                $failures[] = [
                    'code' => 'missing_run_record_field',
                    'field' => $field,
                ];
            }
        }

        $runnerBlocked = self::runnerBlockedValue($result);
        if ($runnerBlocked !== false) {
            $failures[] = [
                'code' => 'runner_blocked_result_is_not_product_evidence',
                'field' => 'runner_blocked',
                'expected' => false,
                'actual' => $runnerBlocked,
            ];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private static function hasRunRecordField(array $result, string $field): bool
    {
        return match ($field) {
            'artifact_versions' => self::arrayField($result, ['artifact_versions', 'artifactVersions']) !== null,
            'published_artifact_versions' => self::arrayField($result, [
                'published_artifact_versions',
                'publishedArtifactVersions',
            ]) !== null,
            'artifact_sources' => self::arrayField($result, ['artifact_sources', 'artifactSources']) !== null,
            'runner_blocked' => self::runnerBlockedValue($result) !== null,
            'scenario_results' => self::arrayField($result, ['scenario_results', 'scenarioResults']) !== null,
            'finding_links' => self::arrayField($result, ['finding_links', 'findingLinks']) !== null,
            'findings' => array_key_exists('findings', $result) && is_array($result['findings']),
            default => array_key_exists($field, $result),
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private static function runnerBlockedValue(array $result): ?bool
    {
        foreach (['runner_blocked', 'runnerBlocked'] as $field) {
            if (! array_key_exists($field, $result)) {
                continue;
            }

            return is_bool($result[$field]) ? $result[$field] : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $result
     *
     * @return list<array<string, mixed>>
     */
    private static function artifactVersionFailures(array $result): array
    {
        $versions = self::arrayValue($result, 'published_artifact_versions')
            ?? self::arrayValue($result, 'publishedArtifactVersions')
            ?? self::arrayValue($result, 'artifact_versions')
            ?? self::arrayValue($result, 'artifactVersions')
            ?? [];
        $failures = [];

        foreach (['server', 'cli', 'sdk-python', 'workflow', 'waterline'] as $artifact) {
            $value = self::artifactVersion($versions, $artifact);
            if ($value === '') {
                $failures[] = [
                    'code' => 'missing_artifact_version',
                    'artifact' => $artifact,
                ];
                continue;
            }

            if (! self::isExactVersion($value)) {
                $failures[] = [
                    'code' => 'invalid_artifact_version',
                    'artifact' => $artifact,
                    'version' => $value,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $versions
     */
    private static function artifactVersion(array $versions, string $artifact): string
    {
        $aliases = [
            'workflow' => ['workflow', 'workflow-php'],
            'sdk-python' => ['sdk-python', 'sdk_python', 'python'],
        ];

        foreach ($aliases[$artifact] ?? [$artifact] as $key) {
            $value = self::stringValue($versions[$key] ?? null);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function isExactVersion(string $value): bool
    {
        if (preg_match('/(<[^>]+>|\$\{[^}]+}|{{[^}]+}}|(^|[^a-z0-9])latest([^a-z0-9]|$)|current|head|unresolved|placeholder)/i', $value)) {
            return false;
        }

        return (bool) preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $value);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     *
     * @return list<array<string, mixed>>
     */
    private static function sourcePolicyFailures(array $result, array $contract): array
    {
        $failures = [];
        if (self::truthy($result['local_product_source_checkouts_used'] ?? null)) {
            $failures[] = [
                'code' => 'local_product_source_checkouts_used',
            ];
        }

        $sources = self::arrayField($result, ['artifact_sources', 'artifactSources']);
        if ($sources === null || $sources === []) {
            $failures[] = [
                'code' => 'missing_artifact_sources',
            ];

            return $failures;
        }

        $versions = self::arrayField($result, ['published_artifact_versions', 'publishedArtifactVersions'])
            ?? self::arrayField($result, ['artifact_versions', 'artifactVersions'])
            ?? [];
        $requiredArtifacts = array_keys($contract['artifact_policy']['install_channels'] ?? [
            'server' => true,
            'cli' => true,
            'workflow-php' => true,
            'sdk-python' => true,
            'waterline' => true,
        ]);

        foreach ($requiredArtifacts as $artifact) {
            $artifact = (string) $artifact;
            $source = self::artifactSource($sources, $artifact);
            $sourceText = self::stringValue($source);
            if (! self::sourceValueRecorded($source)) {
                $failures[] = [
                    'code' => 'missing_published_artifact_install_source',
                    'artifact' => $artifact,
                ];
                continue;
            }

            if (self::artifactSourceIsForbidden($sourceText)) {
                $failures[] = [
                    'code' => 'forbidden_artifact_source',
                    'artifact' => $artifact,
                    'source' => $sourceText,
                ];
                continue;
            }

            $version = self::artifactVersionForInstallChannel($versions, $artifact);
            if (! self::matchesPublishedArtifactSource($artifact, $version, $sourceText)) {
                $failures[] = [
                    'code' => 'unrecognized_published_artifact_install_source',
                    'artifact' => $artifact,
                    'source' => $sourceText,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $sources
     */
    private static function artifactSource(array $sources, string $artifact): mixed
    {
        $aliases = [
            'workflow-php' => ['workflow-php', 'workflow_php', 'workflow'],
            'sdk-python' => ['sdk-python', 'sdk_python', 'python'],
            'waterline' => ['waterline', 'waterline-ui', 'waterline_ui'],
        ];

        foreach ($aliases[$artifact] ?? [$artifact] as $key) {
            if (array_key_exists($key, $sources)) {
                return $sources[$key];
            }
        }

        return null;
    }

    private static function sourceValueRecorded(mixed $value): bool
    {
        $source = strtolower(self::stringValue($value));

        return $source !== ''
            && ! in_array($source, ['not_exercised', 'missing', 'unknown', 'unresolved'], true);
    }

    private static function artifactSourceIsForbidden(string $source): bool
    {
        $normalized = strtolower(trim($source));
        $decoded = urldecode($normalized);

        foreach ([$normalized, $decoded] as $candidate) {
            foreach (self::FORBIDDEN_ARTIFACT_SOURCE_TOKENS as $token) {
                if (str_contains($candidate, strtolower($token))) {
                    return true;
                }
            }

            if (self::isLocalArtifactSourcePath($candidate)
                || preg_match('/(^|[\/:@=?&#._-])(latest|current|head)(?:$|[\/:@?&#._-])/', $candidate) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function isLocalArtifactSourcePath(string $source): bool
    {
        $path = str_replace('\\', '/', trim($source));

        return str_starts_with($path, 'file:')
            || preg_match('/^local(?::|\/|$)/', $path) === 1
            || preg_match('/^~(?:[^\/]*)?(?:\/|$)/', $path) === 1
            || preg_match('/^\$(?:home|userprofile)(?:\/|$)/', $path) === 1
            || preg_match('/^\$\{(?:home|userprofile)\}(?:\/|$)/', $path) === 1
            || preg_match('/^%(?:home|userprofile|homedrive|homepath)%/', $path) === 1
            || preg_match('/^\/[^\/]+/', $path) === 1
            || preg_match('/^[a-z]:\//', $path) === 1
            || preg_match('/^\.\.?(?:\/|$)/', $path) === 1
            || preg_match('/(^|[^a-z0-9])\/?workspace\/repos\//', $path) === 1
            || preg_match('/^repos\/(?:server|workflow|waterline|cli|cloud|sample-app|sdk-python|durable-workflow\.github\.io)(?:\/|$)/', $path) === 1;
    }

    /**
     * @param  array<string, mixed>  $versions
     */
    private static function artifactVersionForInstallChannel(array $versions, string $artifact): string
    {
        $aliases = [
            'workflow-php' => ['workflow-php', 'workflow_php', 'workflow'],
            'sdk-python' => ['sdk-python', 'sdk_python', 'python'],
        ];

        foreach ($aliases[$artifact] ?? [$artifact] as $key) {
            $value = self::stringValue($versions[$key] ?? null);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function matchesPublishedArtifactSource(string $artifact, string $version, string $source): bool
    {
        if ($version === '') {
            return false;
        }

        return match ($artifact) {
            'server' => self::matchesServerArtifactSource($version, $source),
            'cli' => self::matchesCliArtifactSource($version, $source),
            'sdk-python' => self::matchesPythonArtifactSource($version, $source),
            'workflow-php' => self::matchesComposerArtifactSource('durable-workflow/workflow', $version, $source),
            'waterline' => self::matchesComposerArtifactSource('durable-workflow/waterline', $version, $source),
            default => false,
        };
    }

    private static function matchesServerArtifactSource(string $version, string $source): bool
    {
        $image = preg_replace('/^docker:\/\//i', '', trim($source));
        if ($image === null || $image === '') {
            return false;
        }

        $escapedVersion = preg_quote($version, '/');
        foreach (self::PUBLISHED_SERVER_IMAGE_REPOSITORIES as $repository) {
            $escapedRepository = preg_quote($repository, '/');

            if (strcasecmp($image, $repository.':'.$version) === 0
                || preg_match('/^'.$escapedRepository.'@sha256:[0-9a-f]{64}$/i', $image) === 1
                || preg_match('/^'.$escapedRepository.':'.$escapedVersion.'@sha256:[0-9a-f]{64}$/i', $image) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function matchesCliArtifactSource(string $version, string $source): bool
    {
        foreach ([
            'https://github.com/durable-workflow/cli/releases/download/'.$version.'/',
            'https://github.com/durable-workflow/cli/releases/download/v'.$version.'/',
        ] as $prefix) {
            if (str_starts_with($source, $prefix) && substr($source, strlen($prefix)) !== '') {
                return true;
            }
        }

        return $source === 'github://durable-workflow/cli@'.$version
            || $source === 'github://durable-workflow/cli@v'.$version;
    }

    private static function matchesPythonArtifactSource(string $version, string $source): bool
    {
        return $source === 'pypi://durable-workflow=='.$version
            || $source === 'https://pypi.org/project/durable-workflow/'.$version.'/'
            || (
                (str_starts_with($source, 'https://files.pythonhosted.org/') || str_starts_with($source, 'https://pypi.io/packages/'))
                && (str_contains($source, '/durable_workflow-'.$version) || str_contains($source, '/durable-workflow-'.$version))
            );
    }

    private static function matchesComposerArtifactSource(string $packageName, string $version, string $source): bool
    {
        return $source === 'packagist://'.$packageName.'@'.$version
            || $source === 'composer://'.$packageName.':'.$version
            || $source === 'https://repo.packagist.org/p2/'.$packageName.'.json#'.$version
            || $source === 'https://packagist.org/packages/'.$packageName.'#'.$version;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $contract
     *
     * @return list<array<string, mixed>>
     */
    private static function matrixFailures(array $result, array $contract): array
    {
        $matrix = self::arrayValue($result, 'runtime_matrix') ?? self::arrayValue($result, 'runtimeMatrix') ?? [];
        $requiredMatrix = self::arrayValue($contract, 'required_matrix') ?? [];
        $failures = [];

        foreach (['workflow-embedded', 'standalone'] as $mode) {
            if (! in_array($mode, self::stringList($matrix['execution_modes'] ?? []), true)) {
                $failures[] = [
                    'code' => 'missing_execution_mode',
                    'mode' => $mode,
                ];
            }
        }

        foreach (self::stringList($requiredMatrix['runtimes'] ?? ['workflow-php', 'sdk-python']) as $runtime) {
            if (! in_array($runtime, self::stringList($matrix['runtimes'] ?? []), true)) {
                $failures[] = [
                    'code' => 'missing_activity_runtime',
                    'runtime' => $runtime,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     *
     * @return list<array<string, mixed>>
     */
    private static function requiredSectionFailures(array $result): array
    {
        $sections = [
            'published_artifact_install',
            'runtime_matrix',
            'durable_result_recording',
            'retry_backoff',
            'timeout_behavior',
            'typed_failure_propagation',
            'heartbeat_cancellation',
            'idempotent_completion',
            'operator_visibility',
        ];
        $failures = [];

        foreach ($sections as $section) {
            $value = self::arrayValue($result, $section);
            if ($value === null || $value === []) {
                $failures[] = [
                    'code' => 'missing_required_section',
                    'section' => $section,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     *
     * @return list<array<string, mixed>>
     */
    private static function declaredOutcomeStatusFailures(array $result, string $evaluatedStatus): array
    {
        $declared = self::stringValue(
            $result['outcome']
            ?? $result['status']
            ?? $result['verdict']
            ?? null,
        );

        if ($declared === '') {
            return [[
                'code' => 'missing_declared_outcome',
            ]];
        }

        $declaredStatus = in_array($declared, ['pass', 'passed', 'success'], true)
            ? 'pass'
            : 'non_passing';

        if ($declaredStatus !== $evaluatedStatus) {
            return [[
                'code' => 'declared_outcome_mismatch',
                'declared_outcome' => $declared,
                'evaluated_status' => $evaluatedStatus,
            ]];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            $text = self::stringValue($item);
            if ($text !== '') {
                $result[] = $text;
            }
        }

        return $result;
    }

    private static function stringValue(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return trim((string) $value);
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $array
     *
     * @return array<string, mixed>|null
     */
    private static function arrayValue(array $array, string $key): ?array
    {
        $value = $array[$key] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $array
     * @param  list<string>  $keys
     *
     * @return array<string, mixed>|null
     */
    private static function arrayField(array $array, array $keys): ?array
    {
        foreach ($keys as $key) {
            $value = $array[$key] ?? null;
            if (is_array($value)) {
                return $value;
            }
        }

        return null;
    }

    private static function truthy(mixed $value): bool
    {
        if ($value === true || $value === 1) {
            return true;
        }

        return is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
