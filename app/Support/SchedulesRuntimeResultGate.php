<?php

namespace App\Support;

/**
 * Evaluates schedules conformance results against the full recurring-work
 * matrix exposed by SchedulesRuntimeContract.
 */
final class SchedulesRuntimeResultGate
{
    public const SCHEMA = 'durable-workflow.v2.schedules-runtime.result-gate';

    public const VERSION = 1;

    private const FORBIDDEN_ARTIFACT_SOURCE_TOKENS = [
        'local_product_source_checkout',
        'workspace_repo_as_artifact_under_test',
        'local_checkout_artifact',
        'local_checkout',
        'local_source_checkout',
        'workspace_repo',
        'unverified_artifact_source',
    ];

    private const PUBLISHED_ARTIFACT_SOURCE_LABELS = [
        'server' => [
            'published_docker_image',
            'existing_published_server_url',
        ],
        'cli' => [
            'official_install_script',
            'published_cli_release',
            'github_release',
        ],
        'sdk-python' => [
            'pypi',
            'pypi_release',
            'published_pypi_release',
        ],
        'sdk-php' => [
            'composer_packagist',
            'composer_release',
            'packagist',
            'published_packagist_release',
        ],
        'waterline' => [
            'published_waterline_artifact',
            'published_waterline_release',
            'composer_packagist',
            'composer_release',
            'packagist',
            'published_packagist_release',
        ],
    ];

    private const PUBLISHED_SERVER_IMAGE_REPOSITORIES = [
        'durableworkflow/server',
        'docker.io/durableworkflow/server',
        'index.docker.io/durableworkflow/server',
        'registry-1.docker.io/durableworkflow/server',
        'ghcr.io/durable-workflow/server',
    ];

    private const CLI_RELEASE_ASSET_NAMES = [
        'dw.phar',
        'dw-linux-aarch64',
        'dw-linux-x86_64',
        'dw-macos-aarch64',
        'dw-windows-x86_64.exe',
        'dw.rb',
        'install.sh',
        'install.ps1',
        'verify-release.sh',
        'SHA256SUMS',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'evaluates_result_schema' => SchedulesRuntimeContract::RESULT_SCHEMA,
            'scenario_statuses_source' => 'schedules_runtime_contract.scenario_statuses',
            'required_scenarios_source' => 'schedules_runtime_contract.required_scenarios',
            'required_matrix_source' => 'schedules_runtime_contract.required_matrix',
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
            'declared_outcomes_source' => 'schedules_runtime_contract.coverage_gate.*_outcome',
            'non_pass_statuses' => [
                'fail',
                'unsupported',
                'not_covered',
                'runner_blocked',
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
                'every_required_scenario_has_one_result',
                'every_result_uses_a_published_status',
                'required_php_and_python_workers_are_reported',
                'required_cli_python_and_php_clients_are_reported',
                'cron_and_fixed_rate_schedule_types_are_reported',
                'cross_language_schedule_workflow_cells_are_reported',
                'cadence_operator_missed_restart_cross_language_and_adversarial_sections_are_reported',
                'each_pass_scenario_has_observed_outputs',
                'each_pass_scenario_has_scenario_specific_evidence',
                'cadence_observation_counts_match_scenario_requirements',
                'each_non_pass_scenario_has_linked_findings',
                'run_timestamps_outcome_and_finding_links_are_recorded',
                'overall_outcome_matches_gate_status',
                'published_artifact_versions_are_recorded_and_pinned',
                'no_local_product_source_artifacts_are_reported',
                'published_artifact_install_evidence_has_passing_non_local_entries',
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
        $contract ??= SchedulesRuntimeContract::manifest();

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
        array_push($failures, ...self::sourcePolicyFailures($result, $contract, $scenarioResults));
        array_push($failures, ...self::matrixFailures($result, $contract));
        array_push($failures, ...self::requiredSectionFailures($result, $scenarioResults));
        array_push($failures, ...self::scenarioSpecificEvidenceFailures($result, $contract, $scenarioResults));

        $smokeSubsetDetected = self::isSmokeSubset($scenarioStatuses, $contract);
        if ($smokeSubsetDetected) {
            $failures[] = [
                'code' => 'smoke_subset_cannot_pass',
                'reason' => 'Schedule CRUD smoke coverage is not a complete recurring-work conformance result.',
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
     * @param array<string, mixed> $scenarioResult
     */
    private static function hasObservedOutputs(array $scenarioResult): bool
    {
        foreach ([
            'observed_outputs',
            'observedOutputs',
            'cadence_observations',
            'cadenceObservations',
            'operator_controls',
            'operatorControls',
            'missed_fire_policy',
            'missedFirePolicy',
            'restart_survival',
            'restartSurvival',
            'cross_language_matrix',
            'crossLanguageMatrix',
            'adversarial_outcomes',
            'adversarialOutcomes',
            'client_surface',
            'clientSurface',
        ] as $field) {
            $value = self::arrayField($scenarioResult, [$field]);
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
            $value = self::arrayField($scenarioResult, [$field]);
            if ($value !== null && $value !== []) {
                return true;
            }
        }

        $scenarioId = self::stringValue($scenarioResult['scenario_id'] ?? null);
        foreach (['finding_links', 'findingLinks', 'findings'] as $field) {
            $links = self::arrayField($result, [$field]);
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
        $coverageGate = self::arrayField($contract, ['coverage_gate']) ?? [];
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
        $installChannels = self::arrayField($contract['artifact_policy'] ?? [], ['install_channels']) ?? [];
        $failures = [];

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
        return self::arrayField($result, ['artifact_versions', 'artifactVersions', 'published_artifact_versions', 'publishedArtifactVersions']) ?? [];
    }

    /**
     * @param array<mixed> $versions
     */
    private static function artifactVersionValue(array $versions, string $artifact): string
    {
        $aliases = [
            'sdk-php' => ['sdk-php', 'sdk_php'],
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

        return preg_match(
            '/(^|[^a-z0-9])(latest|current|head|unresolved|placeholder)([^a-z0-9]|$)/',
            $normalized,
        ) === 1;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function artifactEvidenceValue(array $values, string $artifact): array
    {
        $aliases = [
            'sdk-php' => ['sdk-php', 'sdk_php'],
            'sdk-python' => ['sdk-python', 'sdk_python', 'python'],
            'waterline' => ['waterline', 'waterline-ui', 'waterline_ui'],
        ];

        foreach ($aliases[$artifact] ?? [$artifact] as $key) {
            if (array_key_exists($key, $values) && is_array($values[$key]) && $values[$key] !== []) {
                return $values[$key];
            }
        }

        return [];
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

            if (preg_match('/(^|[\/:@=?&#._-])(latest|current|head)(?:$|[\/:@?&#._-])/', $candidate) === 1
                || self::isLocalArtifactSourcePath($candidate)) {
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

    private static function matchesPublishedArtifactSource(string $artifact, string $version, string $source): bool
    {
        if ($version === '') {
            return false;
        }

        if (self::publishedSourceLabelAllowed($artifact, $source)) {
            return true;
        }

        return match ($artifact) {
            'server' => self::matchesServerArtifactSource($version, $source),
            'cli' => self::matchesCliArtifactSource($version, $source),
            'sdk-python' => self::matchesPythonArtifactSource($version, $source),
            'sdk-php' => self::matchesComposerArtifactSource('durable-workflow/sdk', $version, $source),
            'waterline' => self::matchesComposerArtifactSource('durable-workflow/waterline', $version, $source),
            default => false,
        };
    }

    private static function publishedSourceLabelAllowed(string $artifact, string $source): bool
    {
        $normalizedSource = self::normalizeToken($source);
        foreach (self::PUBLISHED_ARTIFACT_SOURCE_LABELS[$artifact] ?? [] as $label) {
            if (self::normalizeToken($label) === $normalizedSource) {
                return true;
            }
        }

        return false;
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
            if (str_starts_with($source, $prefix)) {
                return in_array(substr($source, strlen($prefix)), self::CLI_RELEASE_ASSET_NAMES, true);
            }
        }

        return false;
    }

    private static function matchesComposerArtifactSource(string $packageName, string $version, string $source): bool
    {
        return $source === 'packagist://'.$packageName.'@'.$version
            || $source === 'composer://'.$packageName.':'.$version
            || $source === 'https://repo.packagist.org/p2/'.$packageName.'.json#'.$version;
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

    /**
     * @param array<string, mixed> $verification
     */
    private static function artifactSourceVerificationPasses(string $version, string $source, array $verification): bool
    {
        if ($verification === []) {
            return false;
        }

        $verifiedSource = self::stringField($verification, [
            'source',
            'artifact_source',
            'artifactSource',
            'resolved_source',
            'resolvedSource',
        ]);
        $verifiedVersion = self::stringField($verification, [
            'version',
            'artifact_version',
            'artifactVersion',
            'resolved_version',
            'resolvedVersion',
        ]);

        return $verifiedSource === $source
            && $verifiedVersion === $version
            && self::verificationConfirmsPublished($verification);
    }

    /**
     * @param array<string, mixed> $verification
     */
    private static function verificationConfirmsPublished(array $verification): bool
    {
        foreach ([
            'downloadable',
            'downloaded',
            'installable',
            'resolved',
            'exists',
            'published',
            'verified',
            'asset_exists',
            'assetExists',
            'package_exists',
            'packageExists',
            'manifest_resolved',
            'manifestResolved',
            'source_exists',
            'sourceExists',
        ] as $field) {
            if (self::hasTruthyField($verification, [$field])) {
                return true;
            }
        }

        return in_array(strtolower(self::stringValue($verification['status'] ?? null)), [
            'pass',
            'passed',
            'success',
            'successful',
            'resolved',
            'downloadable',
            'exists',
            'found',
            'verified',
            'installable',
            'asset_resolved',
            'package_resolved',
            'manifest_resolved',
        ], true);
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
        $artifactPolicy = self::arrayField($contract, ['artifact_policy']) ?? [];
        $forbiddenSources = array_values(array_unique(array_merge(
            self::stringList($artifactPolicy['forbidden_sources'] ?? []),
            [
                'local_checkout_artifact',
                'local_checkout',
                'local_source_checkout',
                'workspace_repo',
            ],
        )));
        $reportedSourceSets = [];
        $topLevelSources = self::arrayField($result, ['artifact_sources', 'artifactSources']);
        if ($topLevelSources !== null) {
            $reportedSourceSets[] = [
                'sources' => $topLevelSources,
                'scenario_id' => null,
            ];
        }

        foreach ($scenarioResults as $scenarioId => $scenarioResult) {
            $outputs = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']) ?? [];
            $scenarioSources = self::arrayField($outputs, ['artifact_sources', 'artifactSources']);
            if ($scenarioSources === null) {
                continue;
            }

            $reportedSourceSets[] = [
                'sources' => $scenarioSources,
                'scenario_id' => $scenarioId,
            ];
        }

        $failures = [];

        foreach ($reportedSourceSets as $sourceSet) {
            foreach ($sourceSet['sources'] as $artifact => $source) {
                $source = self::stringValue($source);
                if (! in_array($source, $forbiddenSources, true) && ! self::artifactSourceIsForbidden($source)) {
                    continue;
                }

                $failure = [
                    'code' => 'forbidden_artifact_source',
                    'artifact' => $artifact,
                    'source' => $source,
                ];
                if ($sourceSet['scenario_id'] !== null) {
                    $failure['scenario_id'] = $sourceSet['scenario_id'];
                }

                $failures[] = $failure;
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
        $matrix = self::arrayField($result, ['runtime_matrix', 'runtimeMatrix']) ?? [];
        $contractMatrix = self::arrayField($contract, ['required_matrix']) ?? [];
        $failures = [];

        foreach (self::stringList($contractMatrix['runtimes'] ?? []) as $runtime) {
            if (! self::matrixRuntimeListContains($matrix, ['runtimes', 'workers', 'worker_runtimes', 'workerRuntimes'], $runtime)) {
                $failures[] = [
                    'code' => 'missing_required_runtime',
                    'runtime' => $runtime,
                ];
            }
        }

        foreach (self::stringList($contractMatrix['client_paths'] ?? []) as $client) {
            if (! self::matrixClientListContains($matrix, ['client_paths', 'clientPaths', 'clients'], $client)) {
                $failures[] = [
                    'code' => 'missing_required_client_path',
                    'client_path' => $client,
                ];
            }
        }

        foreach (self::stringList($contractMatrix['schedule_types'] ?? []) as $scheduleType) {
            if (! self::matrixTokenListContains($matrix, ['schedule_types', 'scheduleTypes'], $scheduleType)) {
                $failures[] = [
                    'code' => 'missing_required_schedule_type',
                    'schedule_type' => $scheduleType,
                ];
            }
        }

        foreach (($contractMatrix['cross_language_cells'] ?? []) as $requiredCell) {
            if (! is_array($requiredCell) || self::matrixHasCrossLanguageCell($matrix, $requiredCell)) {
                continue;
            }

            $failures[] = [
                'code' => 'missing_required_cross_language_cell',
                'scenario' => $requiredCell['scenario'] ?? null,
                'schedule_creator' => $requiredCell['schedule_creator'] ?? null,
                'workflow_runtime' => $requiredCell['workflow_runtime'] ?? null,
            ];
        }

        return $failures;
    }

    /**
     * @param array<mixed> $matrix
     * @param list<string> $fields
     */
    private static function matrixRuntimeListContains(array $matrix, array $fields, string $expected): bool
    {
        foreach ($fields as $field) {
            foreach (self::stringList($matrix[$field] ?? []) as $reported) {
                if (self::sameRuntimeSurface($reported, $expected)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $matrix
     * @param list<string> $fields
     */
    private static function matrixClientListContains(array $matrix, array $fields, string $expected): bool
    {
        foreach ($fields as $field) {
            foreach (self::stringList($matrix[$field] ?? []) as $reported) {
                if (self::sameClientSurface($reported, $expected)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $matrix
     * @param list<string> $fields
     */
    private static function matrixTokenListContains(array $matrix, array $fields, string $expected): bool
    {
        foreach ($fields as $field) {
            foreach (self::stringList($matrix[$field] ?? []) as $reported) {
                if (self::sameNormalizedToken($reported, $expected)) {
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
    private static function matrixHasCrossLanguageCell(array $matrix, array $requiredCell): bool
    {
        $reportedCells = [];
        foreach (['cross_language_cells', 'crossLanguageCells', 'cells', 'runtime_cells', 'runtimeCells'] as $field) {
            $value = self::arrayField($matrix, [$field]);
            if ($value !== null) {
                $reportedCells = array_merge($reportedCells, $value);
            }
        }

        foreach ($reportedCells as $cell) {
            if (! is_array($cell)) {
                continue;
            }

            if (self::stringValue($cell['scenario'] ?? $cell['scenario_id'] ?? null)
                !== self::stringValue($requiredCell['scenario'] ?? null)) {
                continue;
            }

            if (! self::sameClientSurface(
                self::stringField($cell, ['schedule_creator', 'scheduleCreator', 'creator', 'client']),
                self::stringValue($requiredCell['schedule_creator'] ?? null),
            )) {
                continue;
            }

            if (! self::sameRuntimeSurface(
                self::stringField($cell, ['workflow_runtime', 'workflowRuntime', 'worker', 'runtime']),
                self::stringValue($requiredCell['workflow_runtime'] ?? null),
            )) {
                continue;
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
        $sections = [
            'cadence_observations' => ['cron_cadence', 'fixed_rate_cadence'],
            'operator_controls' => ['list_describe_visibility', 'pause_resume_no_fire_window', 'delete_stops_future_fires'],
            'missed_fire_policy' => ['missed_fire_policy'],
            'restart_survival' => ['restart_survival'],
            'cross_language_matrix' => ['python_created_php_workflow', 'php_created_python_workflow'],
            'adversarial_outcomes' => ['invalid_cron_refusal', 'nonexistent_workflow_type_outcome'],
        ];

        $failures = [];
        foreach ($sections as $section => $scenarios) {
            if (self::sectionValue($result, $section) !== null) {
                continue;
            }

            $coveredByScenarioOutputs = true;
            foreach ($scenarios as $scenarioId) {
                if (! isset($scenarioResults[$scenarioId]) || ! self::hasObservedOutputs($scenarioResults[$scenarioId])) {
                    $coveredByScenarioOutputs = false;
                    break;
                }
            }

            if (! $coveredByScenarioOutputs) {
                $failures[] = [
                    'code' => 'missing_required_section',
                    'section' => $section,
                    'scenarios' => $scenarios,
                ];
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
    private static function scenarioSpecificEvidenceFailures(array $result, array $contract, array $scenarioResults): array
    {
        $failures = [];
        foreach ($scenarioResults as $scenarioId => $scenarioResult) {
            if (self::stringValue($scenarioResult['status'] ?? null) !== 'pass') {
                continue;
            }

            match ($scenarioId) {
                'published_artifact_install_only' => array_push($failures, ...self::publishedArtifactEvidenceFailures($result, $contract, $scenarioResult)),
                'cron_cadence' => array_push($failures, ...self::cadenceEvidenceFailures($result, $contract, $scenarioResult, 'cron_cadence', 'cron')),
                'fixed_rate_cadence' => array_push($failures, ...self::cadenceEvidenceFailures($result, $contract, $scenarioResult, 'fixed_rate_cadence', 'fixed_rate')),
                'list_describe_visibility' => array_push($failures, ...self::listDescribeEvidenceFailures($result, $scenarioResult)),
                'pause_resume_no_fire_window' => array_push($failures, ...self::pauseResumeEvidenceFailures($result, $scenarioResult)),
                'delete_stops_future_fires' => array_push($failures, ...self::deleteEvidenceFailures($result, $scenarioResult)),
                'missed_fire_policy' => array_push($failures, ...self::missedFireEvidenceFailures($result, $contract, $scenarioResult)),
                'restart_survival' => array_push($failures, ...self::restartEvidenceFailures($result, $scenarioResult)),
                'cli_schedule_surface' => array_push($failures, ...self::clientSurfaceEvidenceFailures($result, $scenarioResult, 'cli')),
                'python_sdk_schedule_surface' => array_push($failures, ...self::clientSurfaceEvidenceFailures($result, $scenarioResult, 'sdk-python')),
                'php_schedule_surface' => array_push($failures, ...self::clientSurfaceEvidenceFailures($result, $scenarioResult, 'sdk-php')),
                'python_created_php_workflow' => array_push($failures, ...self::crossLanguageEvidenceFailures($result, $scenarioResult, 'sdk-python', 'sdk-php')),
                'php_created_python_workflow' => array_push($failures, ...self::crossLanguageEvidenceFailures($result, $scenarioResult, 'sdk-php', 'sdk-python')),
                'invalid_cron_refusal' => array_push($failures, ...self::invalidCronEvidenceFailures($result, $scenarioResult)),
                'nonexistent_workflow_type_outcome' => array_push($failures, ...self::nonexistentWorkflowEvidenceFailures($result, $contract, $scenarioResult)),
                default => null,
            };
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function publishedArtifactEvidenceFailures(array $result, array $contract, array $scenarioResult): array
    {
        $outputs = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs']) ?? [];
        $topLevelVersions = self::artifactVersions($result);
        $scenarioVersions = self::arrayField($outputs, [
            'artifact_versions',
            'artifactVersions',
            'published_artifact_versions',
            'publishedArtifactVersions',
            'resolved_artifact_versions',
            'resolvedArtifactVersions',
        ]) ?? [];
        $versions = array_replace($topLevelVersions, $scenarioVersions);
        $topLevelSources = self::arrayField($result, ['artifact_sources', 'artifactSources']) ?? [];
        $scenarioSources = self::arrayField($outputs, ['artifact_sources', 'artifactSources']) ?? [];
        $sources = array_replace($topLevelSources, $scenarioSources);
        $sourceVerification = self::arrayField($outputs, [
            'artifact_source_verification',
            'artifactSourceVerification',
            'published_artifact_source_verification',
            'publishedArtifactSourceVerification',
            'artifact_source_resolution',
            'artifactSourceResolution',
        ]) ?? [];
        $installChannels = self::arrayField($contract['artifact_policy'] ?? [], ['install_channels']) ?? [];
        $failures = [];

        if (self::hasTruthyFieldIn([$result, $scenarioResult, $outputs], [
            'local_product_source_checkouts_used',
            'localProductSourceCheckoutsUsed',
        ])) {
            $failures[] = [
                'code' => 'local_product_source_checkout_used',
                'scenario_id' => 'published_artifact_install_only',
                'field' => 'local_product_source_checkouts_used',
            ];
        } elseif (! self::hasExplicitFalseFieldIn([$result, $scenarioResult, $outputs], [
            'local_product_source_checkouts_used',
            'localProductSourceCheckoutsUsed',
        ])) {
            $failures[] = [
                'code' => 'missing_explicit_source_free_evidence',
                'scenario_id' => 'published_artifact_install_only',
                'field' => 'local_product_source_checkouts_used',
            ];
        }

        foreach (array_keys($installChannels) as $artifact) {
            $artifact = (string) $artifact;
            $source = self::artifactVersionValue($sources, $artifact);
            if ($source === '') {
                $failures[] = [
                    'code' => 'missing_published_artifact_install_source',
                    'scenario_id' => 'published_artifact_install_only',
                    'artifact' => $artifact,
                ];
                continue;
            }

            if (self::artifactSourceIsForbidden($source)) {
                $failures[] = [
                    'code' => 'forbidden_artifact_source',
                    'scenario_id' => 'published_artifact_install_only',
                    'artifact' => $artifact,
                    'source' => $source,
                ];
                continue;
            }

            $version = self::artifactVersionValue($versions, $artifact);
            $verification = self::artifactEvidenceValue($sourceVerification, $artifact);
            if (! self::matchesPublishedArtifactSource($artifact, $version, $source)
                && ! self::artifactSourceVerificationPasses($version, $source, $verification)) {
                $failures[] = [
                    'code' => 'invalid_published_artifact_install_source',
                    'scenario_id' => 'published_artifact_install_only',
                    'artifact' => $artifact,
                    'source' => $source,
                    'field' => 'artifact_sources',
                ];
            }
        }

        $installEvidence = self::arrayField($outputs, [
            'artifact_install_evidence',
            'artifactInstallEvidence',
            'install_evidence',
            'installEvidence',
        ]);
        if ($installEvidence === null && self::arrayField($outputs, ['artifacts']) !== null) {
            $installEvidence = $outputs;
        }
        if ($installEvidence === null) {
            $installEvidence = self::arrayField($result, [
                'artifact_install_evidence',
                'artifactInstallEvidence',
                'install_evidence',
                'installEvidence',
            ]);
        }
        if ($installEvidence === null) {
            $failures[] = [
                'code' => 'missing_published_artifact_install_evidence',
                'scenario_id' => 'published_artifact_install_only',
                'field' => 'artifact_install_evidence',
            ];

            return $failures;
        }

        if (! self::hasExplicitFalseField($installEvidence, [
            'local_product_source_checkouts_used',
            'localProductSourceCheckoutsUsed',
        ])) {
            $failures[] = [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'scenario_id' => 'published_artifact_install_only',
                'field' => 'artifact_install_evidence.local_product_source_checkouts_used',
                'value' => $installEvidence['local_product_source_checkouts_used']
                    ?? $installEvidence['localProductSourceCheckoutsUsed']
                    ?? null,
            ];
        }

        $installArtifacts = self::arrayField($installEvidence, ['artifacts']) ?? [];
        foreach (array_keys($installChannels) as $artifact) {
            $artifact = (string) $artifact;
            $entry = self::artifactInstallEntry($installArtifacts, $artifact);
            if ($entry === null) {
                $failures[] = [
                    'code' => 'missing_published_artifact_install_evidence_artifact',
                    'scenario_id' => 'published_artifact_install_only',
                    'artifact' => $artifact,
                    'field' => 'artifact_install_evidence.artifacts',
                ];
                continue;
            }

            $status = strtolower(self::stringField($entry, ['status', 'result', 'outcome']));
            if ($status !== 'pass') {
                $failures[] = [
                    'code' => 'published_artifact_install_evidence_not_pass',
                    'scenario_id' => 'published_artifact_install_only',
                    'artifact' => $artifact,
                    'status' => $status,
                    'field' => 'artifact_install_evidence.artifacts.status',
                ];
            }

            $version = self::stringField($entry, [
                'version',
                'resolved_version',
                'resolvedVersion',
                'artifact_version',
                'artifactVersion',
            ]);
            if ($version === '') {
                $failures[] = [
                    'code' => 'missing_published_artifact_install_evidence_version',
                    'scenario_id' => 'published_artifact_install_only',
                    'artifact' => $artifact,
                    'field' => 'artifact_install_evidence.artifacts.version',
                ];
            } elseif (self::isPlaceholderVersion($version)) {
                $failures[] = [
                    'code' => 'placeholder_published_artifact_install_evidence_version',
                    'scenario_id' => 'published_artifact_install_only',
                    'artifact' => $artifact,
                    'version' => $version,
                    'field' => 'artifact_install_evidence.artifacts.version',
                ];
            } else {
                $expectedVersion = self::artifactVersionValue($versions, $artifact);
                if ($expectedVersion !== '' && $version !== $expectedVersion) {
                    $failures[] = [
                        'code' => 'published_artifact_install_evidence_version_mismatch',
                        'scenario_id' => 'published_artifact_install_only',
                        'artifact' => $artifact,
                        'version' => $version,
                        'expected_version' => $expectedVersion,
                        'field' => 'artifact_install_evidence.artifacts.version',
                    ];
                }
            }

            $source = self::stringField($entry, [
                'source',
                'install_source',
                'installSource',
                'artifact_source',
                'artifactSource',
            ]);
            if ($source === '') {
                $failures[] = [
                    'code' => 'missing_published_artifact_install_evidence_source',
                    'scenario_id' => 'published_artifact_install_only',
                    'artifact' => $artifact,
                    'field' => 'artifact_install_evidence.artifacts.source',
                ];
            } elseif (self::artifactSourceIsForbidden($source)) {
                $failures[] = [
                    'code' => 'forbidden_published_artifact_install_evidence_source',
                    'scenario_id' => 'published_artifact_install_only',
                    'artifact' => $artifact,
                    'source' => $source,
                    'field' => 'artifact_install_evidence.artifacts.source',
                ];
            } else {
                $verification = self::arrayField($entry, [
                    'source_verification',
                    'sourceVerification',
                    'artifact_source_verification',
                    'artifactSourceVerification',
                    'artifact_source_resolution',
                    'artifactSourceResolution',
                ]) ?? self::artifactEvidenceValue($sourceVerification, $artifact);
                if (! self::matchesPublishedArtifactSource($artifact, $version, $source)
                    && ! self::artifactSourceVerificationPasses($version, $source, $verification)) {
                    $failures[] = [
                        'code' => 'invalid_published_artifact_install_evidence_source',
                        'scenario_id' => 'published_artifact_install_only',
                        'artifact' => $artifact,
                        'source' => $source,
                        'field' => 'artifact_install_evidence.artifacts.source',
                    ];
                }
            }

            if (self::hasTruthyField($entry, [
                'local_product_source_checkouts_used',
                'localProductSourceCheckoutsUsed',
            ])) {
                $failures[] = [
                    'code' => 'local_product_source_checkouts_used_must_be_false',
                    'scenario_id' => 'published_artifact_install_only',
                    'artifact' => $artifact,
                    'field' => 'artifact_install_evidence.artifacts.local_product_source_checkouts_used',
                    'value' => $entry['local_product_source_checkouts_used']
                        ?? $entry['localProductSourceCheckoutsUsed']
                        ?? null,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function cadenceEvidenceFailures(
        array $result,
        array $contract,
        array $scenarioResult,
        string $scenarioId,
        string $kind,
    ): array
    {
        $section = self::sectionValue($result, 'cadence_observations') ?? [];
        $evidence = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs'])
            ?? self::arrayField($section, [$kind])
            ?? [];
        $requirements = self::arrayField($contract, ['scenario_requirements', 'scenarioRequirements']) ?? [];
        $scenarioRequirements = self::arrayField($requirements, [$scenarioId]) ?? [];
        $requiredFields = self::stringList($scenarioRequirements['required_fields'] ?? []);
        if ($requiredFields === []) {
            $requiredFields = ['actual_fire_timestamps', 'nominal_fire_timestamps', 'drift_ms'];
        }
        $minimumObservedFires = self::intField($scenarioRequirements, [
            'minimum_observed_fires',
            'minimumObservedFires',
        ]);
        if ($minimumObservedFires < 1) {
            $minimumObservedFires = 2;
        }
        $failures = [];

        foreach ($requiredFields as $field) {
            if (! self::hasArrayField($evidence, [$field, self::camelize($field)])) {
                $failures[] = [
                    'code' => 'missing_cadence_evidence_field',
                    'scenario_id' => $scenarioId,
                    'field' => $field,
                ];
            }
        }

        foreach ($requiredFields as $field) {
            $values = self::arrayField($evidence, [$field, self::camelize($field)]) ?? [];
            if (count($values) >= $minimumObservedFires) {
                continue;
            }

            $failures[] = [
                'code' => $field === 'drift_ms'
                    ? 'insufficient_cadence_drift_samples'
                    : 'insufficient_cadence_fire_timestamps',
                'scenario_id' => $scenarioId,
                'field' => $field,
                'minimum_observed_fires' => $minimumObservedFires,
                'observed_count' => count($values),
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function listDescribeEvidenceFailures(array $result, array $scenarioResult): array
    {
        $section = self::sectionValue($result, 'operator_controls') ?? [];
        $evidence = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs'])
            ?? self::arrayField($section, ['list_describe', 'listDescribe'])
            ?? [];
        $failures = [];

        foreach (['cli_list_observed', 'sdk_list_observed', 'last_fire_at_observed', 'next_fire_at_observed', 'pause_state_observed'] as $field) {
            if (! self::hasTruthyField($evidence, [$field, self::camelize($field)])) {
                $failures[] = [
                    'code' => 'missing_list_describe_visibility_evidence',
                    'field' => $field,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function pauseResumeEvidenceFailures(array $result, array $scenarioResult): array
    {
        $section = self::sectionValue($result, 'operator_controls') ?? [];
        $evidence = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs'])
            ?? self::arrayField($section, ['pause_resume', 'pauseResume'])
            ?? [];

        if (self::intField($evidence, ['fires_during_pause_count', 'firesDuringPauseCount']) === 0
            && self::hasTruthyField($evidence, ['resumed_after_pause', 'resumedAfterPause'])) {
            return [];
        }

        return [[
            'code' => 'missing_pause_no_fire_window_evidence',
            'scenario_id' => 'pause_resume_no_fire_window',
        ]];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function deleteEvidenceFailures(array $result, array $scenarioResult): array
    {
        $section = self::sectionValue($result, 'operator_controls') ?? [];
        $evidence = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs'])
            ?? self::arrayField($section, ['delete', 'delete_control', 'deleteControl'])
            ?? [];

        if (self::hasTruthyField($evidence, ['absent_from_list_after_delete', 'absentFromListAfterDelete'])
            && self::hasTruthyField($evidence, ['no_fires_after_delete', 'noFiresAfterDelete'])) {
            return [];
        }

        return [[
            'code' => 'missing_delete_stops_future_fires_evidence',
            'scenario_id' => 'delete_stops_future_fires',
        ]];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function missedFireEvidenceFailures(array $result, array $contract, array $scenarioResult): array
    {
        $expected = self::stringValue($contract['schedule_policy']['missed_fire_policy'] ?? null);
        $evidence = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs'])
            ?? self::sectionValue($result, 'missed_fire_policy')
            ?? [];
        $observed = self::stringField($evidence, ['observed_policy', 'observedPolicy']);
        $documented = self::stringField($evidence, ['documented_policy', 'documentedPolicy']);
        $failures = [];

        if ($observed !== $expected || $documented !== $expected) {
            $failures[] = [
                'code' => 'missed_fire_policy_mismatch',
                'expected_policy' => $expected,
                'observed_policy' => $observed,
                'documented_policy' => $documented,
            ];
        }

        if (self::intField($evidence, ['catchup_fire_count', 'catchupFireCount']) !== 1) {
            $failures[] = [
                'code' => 'missing_missed_fire_catchup_count_evidence',
                'expected_count' => 1,
            ];
        }

        if (! self::hasTruthyField($evidence, ['post_resume_normal_fire_observed', 'postResumeNormalFireObserved'])) {
            $failures[] = [
                'code' => 'missing_post_resume_normal_fire_evidence',
            ];
        }

        if (! self::hasTruthyField($evidence, ['scheduler_stop_confirmed', 'schedulerStopConfirmed'])
            || self::intField($evidence, ['fires_during_scheduler_outage_count', 'firesDuringSchedulerOutageCount']) !== 0
            || ! self::hasTruthyField($evidence, [
                'stored_overdue_occurrence_elapsed_during_outage',
                'storedOverdueOccurrenceElapsedDuringOutage',
            ])) {
            $failures[] = [
                'code' => 'scheduler_outage_not_proven',
                'scheduler_stop_confirmed' => self::hasTruthyField(
                    $evidence,
                    ['scheduler_stop_confirmed', 'schedulerStopConfirmed'],
                ),
                'fires_during_scheduler_outage_count' => self::intField(
                    $evidence,
                    ['fires_during_scheduler_outage_count', 'firesDuringSchedulerOutageCount'],
                ),
                'stored_overdue_occurrence_elapsed_during_outage' => self::hasTruthyField(
                    $evidence,
                    [
                        'stored_overdue_occurrence_elapsed_during_outage',
                        'storedOverdueOccurrenceElapsedDuringOutage',
                    ],
                ),
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function restartEvidenceFailures(array $result, array $scenarioResult): array
    {
        $evidence = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs'])
            ?? self::sectionValue($result, 'restart_survival')
            ?? [];

        if (self::hasTruthyField($evidence, ['schedule_listed_after_restart', 'scheduleListedAfterRestart'])
            && self::hasTruthyField($evidence, ['fired_after_restart', 'firedAfterRestart'])) {
            return [];
        }

        return [[
            'code' => 'missing_restart_survival_evidence',
            'scenario_id' => 'restart_survival',
        ]];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function clientSurfaceEvidenceFailures(array $result, array $scenarioResult, string $client): array
    {
        $surfaces = self::arrayField($result, ['client_surfaces', 'clientSurfaces']) ?? [];
        $evidence = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs'])
            ?? self::arrayField($surfaces, [$client])
            ?? [];
        $failures = [];

        foreach (['create_or_observe', 'list_observed', 'control_observed'] as $field) {
            if (! self::hasTruthyField($evidence, [$field, self::camelize($field)])) {
                $failures[] = [
                    'code' => 'missing_client_surface_evidence',
                    'client' => $client,
                    'field' => $field,
                ];
            }
        }

        if ($client === 'cli') {
            array_push($failures, ...self::cliScheduleCommandTranscriptFailures($evidence));
        }

        if ($client === 'sdk-python') {
            foreach (['manual_trigger_observed', 'triggered_workflow_completion_observed'] as $field) {
                if (! self::hasTruthyField($evidence, [$field, self::camelize($field)])) {
                    $failures[] = [
                        'code' => 'missing_client_surface_evidence',
                        'client' => $client,
                        'field' => $field,
                    ];
                }
            }

            $operations = self::arrayField($evidence, ['operations']) ?? [];
            foreach ([
                'create',
                'list',
                'describe',
                'pause',
                'resume',
                'manual_trigger',
                'delete',
                'triggered_workflow_completion',
            ] as $operation) {
                if (! self::hasTruthyField($operations, [$operation, self::camelize($operation)])) {
                    $failures[] = [
                        'code' => 'missing_python_schedule_lifecycle_operation',
                        'operation' => $operation,
                    ];
                }
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $evidence
     *
     * @return array<int, array<string, mixed>>
     */
    private static function cliScheduleCommandTranscriptFailures(array $evidence): array
    {
        $commands = self::arrayField($evidence, [
            'command_outputs',
            'commandOutputs',
            'transcripts',
            'command_transcripts',
            'commandTranscripts',
        ]) ?? [];
        $failures = [];

        foreach (['create', 'list', 'describe', 'pause', 'resume', 'trigger', 'delete'] as $operation) {
            $transcript = self::arrayField($commands, [$operation]);
            if ($transcript === null) {
                $failures[] = [
                    'code' => 'missing_cli_schedule_command_transcript',
                    'operation' => $operation,
                ];
                continue;
            }

            $command = $transcript['command'] ?? null;
            if (! is_string($command) && ! is_array($command)) {
                $failures[] = [
                    'code' => 'missing_cli_schedule_command_field',
                    'operation' => $operation,
                    'field' => 'command',
                ];
            }

            if (! array_key_exists('exit_code', $transcript) || ! is_int($transcript['exit_code'])) {
                $failures[] = [
                    'code' => 'missing_cli_schedule_command_field',
                    'operation' => $operation,
                    'field' => 'exit_code',
                ];
            } elseif ($transcript['exit_code'] !== 0) {
                $failures[] = [
                    'code' => 'cli_schedule_command_nonzero_exit_in_pass',
                    'operation' => $operation,
                    'exit_code' => $transcript['exit_code'],
                ];
            }

            foreach (['stdout', 'stderr'] as $field) {
                if (! array_key_exists($field, $transcript) || ! is_string($transcript[$field])) {
                    $failures[] = [
                        'code' => 'missing_cli_schedule_command_field',
                        'operation' => $operation,
                        'field' => $field,
                    ];
                }
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function crossLanguageEvidenceFailures(
        array $result,
        array $scenarioResult,
        string $creator,
        string $runtime,
    ): array {
        $matrix = self::sectionValue($result, 'cross_language_matrix') ?? [];
        $evidence = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs'])
            ?? self::findCrossLanguageObservation($matrix, $creator, $runtime)
            ?? [];

        if (self::sameClientSurface(self::stringField($evidence, ['schedule_creator', 'scheduleCreator', 'creator']), $creator)
            && self::sameRuntimeSurface(self::stringField($evidence, ['workflow_runtime', 'workflowRuntime', 'runtime']), $runtime)
            && self::hasTruthyField($evidence, ['schedule_visible_in_cli', 'scheduleVisibleInCli'])
            && self::hasTruthyField($evidence, ['workflow_completed', 'workflowCompleted'])) {
            return [];
        }

        return [[
            'code' => 'missing_cross_language_schedule_workflow_evidence',
            'schedule_creator' => $creator,
            'workflow_runtime' => $runtime,
        ]];
    }

    /**
     * @param array<mixed> $matrix
     *
     * @return array<string, mixed>|null
     */
    private static function findCrossLanguageObservation(array $matrix, string $creator, string $runtime): ?array
    {
        $cells = self::arrayField($matrix, ['cross_language_cells', 'crossLanguageCells', 'cells']) ?? $matrix;
        foreach ($cells as $cell) {
            if (! is_array($cell)) {
                continue;
            }

            if (self::sameClientSurface(self::stringField($cell, ['schedule_creator', 'scheduleCreator', 'creator']), $creator)
                && self::sameRuntimeSurface(self::stringField($cell, ['workflow_runtime', 'workflowRuntime', 'runtime']), $runtime)) {
                return $cell;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function invalidCronEvidenceFailures(array $result, array $scenarioResult): array
    {
        $section = self::sectionValue($result, 'adversarial_outcomes') ?? [];
        $evidence = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs'])
            ?? self::arrayField($section, ['invalid_cron', 'invalidCron'])
            ?? [];

        if (self::hasTruthyField($evidence, ['refused'])
            && self::hasTruthyField($evidence, ['typed_error', 'typedError'])
            && self::hasExplicitFalseField($evidence, ['persisted'])
            && self::invalidCronPublicPersistenceProven($evidence)) {
            return [];
        }

        return [[
            'code' => 'missing_invalid_cron_refusal_evidence',
            'scenario_id' => 'invalid_cron_refusal',
        ]];
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private static function invalidCronPublicPersistenceProven(array $evidence): bool
    {
        $persistence = self::arrayField($evidence, [
            'persistence_evidence',
            'persistenceEvidence',
            'public_persistence_evidence',
            'publicPersistenceEvidence',
        ]) ?? [];
        $containers = [$evidence, $persistence];

        $listChecked = self::hasTruthyFieldIn($containers, ['public_list_checked', 'publicListChecked'])
            || self::hasExplicitFalseFieldIn($containers, ['list_contains_invalid_schedule', 'listContainsInvalidSchedule']);
        $describeChecked = self::hasTruthyFieldIn($containers, ['public_describe_checked', 'publicDescribeChecked'])
            || self::hasExplicitFalseFieldIn($containers, ['describe_found', 'describeFound'])
            || self::intField($persistence, ['describe_status', 'describeStatus']) === 404
            || self::intField($evidence, ['describe_status', 'describeStatus']) === 404;
        $listProvesAbsent = self::hasExplicitFalseFieldIn($containers, ['list_contains_invalid_schedule', 'listContainsInvalidSchedule']);
        $describeProvesAbsent = self::hasExplicitFalseFieldIn($containers, ['describe_found', 'describeFound'])
            || self::intField($persistence, ['describe_status', 'describeStatus']) === 404
            || self::intField($evidence, ['describe_status', 'describeStatus']) === 404;

        return ($listChecked && $listProvesAbsent) || ($describeChecked && $describeProvesAbsent);
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $scenarioResult
     *
     * @return array<int, array<string, mixed>>
     */
    private static function nonexistentWorkflowEvidenceFailures(array $result, array $contract, array $scenarioResult): array
    {
        $section = self::sectionValue($result, 'adversarial_outcomes') ?? [];
        $evidence = self::arrayField($scenarioResult, ['observed_outputs', 'observedOutputs'])
            ?? self::arrayField($section, ['nonexistent_workflow_type', 'nonexistentWorkflowType'])
            ?? [];
        $behavior = self::stringField($evidence, ['behavior', 'observed_behavior', 'observedBehavior']);
        $allowed = self::stringList($contract['scenario_requirements']['nonexistent_workflow_type_outcome']['allowed_behaviors'] ?? []);

        if (in_array($behavior, $allowed, true)
            && ($behavior !== 'accepted_pending_worker' || self::hasTruthyField($evidence, ['operator_visible_failure', 'operatorVisibleFailure']))) {
            return [];
        }

        return [[
            'code' => 'missing_nonexistent_workflow_type_outcome_evidence',
            'scenario_id' => 'nonexistent_workflow_type_outcome',
            'behavior' => $behavior,
            'allowed_behaviors' => $allowed,
        ]];
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function sectionValue(array $result, string $section): ?array
    {
        return self::arrayField($result, [$section, self::camelize($section)]);
    }

    /**
     * @param array<string, string> $scenarioStatuses
     * @param array<string, mixed> $contract
     */
    private static function isSmokeSubset(array $scenarioStatuses, array $contract): bool
    {
        if ($scenarioStatuses === []) {
            return false;
        }

        $requiredScenarios = self::stringList($contract['required_scenarios'] ?? []);
        $smokeScenarios = [
            'published_artifact_install_only',
            'python_sdk_schedule_surface',
            'invalid_cron_refusal',
        ];
        $reported = array_keys($scenarioStatuses);

        return array_diff($reported, $smokeScenarios) === []
            && count($reported) < count($requiredScenarios);
    }

    private static function sameRuntimeSurface(string $reported, string $expected): bool
    {
        return self::normalizeRuntimeSurface($reported) === self::normalizeRuntimeSurface($expected);
    }

    private static function sameClientSurface(string $reported, string $expected): bool
    {
        return self::normalizeClientSurface($reported) === self::normalizeClientSurface($expected);
    }

    private static function sameNormalizedToken(string $reported, string $expected): bool
    {
        return self::normalizeToken($reported) === self::normalizeToken($expected);
    }

    private static function normalizeRuntimeSurface(string $value): string
    {
        $normalized = self::normalizeToken($value);
        $aliases = [
            'php' => 'sdkphp',
            'phpworker' => 'sdkphp',
            'phpruntime' => 'sdkphp',
            'sdkphp' => 'sdkphp',
            'python' => 'sdkpython',
            'pythonworker' => 'sdkpython',
            'pythonruntime' => 'sdkpython',
            'pythonsdk' => 'sdkpython',
            'sdkpython' => 'sdkpython',
        ];

        return $aliases[$normalized] ?? $normalized;
    }

    private static function normalizeClientSurface(string $value): string
    {
        $normalized = self::normalizeToken($value);
        $aliases = [
            'dw' => 'cli',
            'durableworkflowcli' => 'cli',
            'python' => 'sdkpython',
            'pythonclient' => 'sdkpython',
            'pythonsdk' => 'sdkpython',
            'sdkpython' => 'sdkpython',
            'phpclient' => 'sdkphp',
            'phpsdk' => 'sdkphp',
            'sdkphp' => 'sdkphp',
        ];

        return $aliases[$normalized] ?? $normalized;
    }

    private static function normalizeToken(string $value): string
    {
        return str_replace(['_', '-', ' '], '', strtolower($value));
    }

    /**
     * @param array<mixed> $installArtifacts
     * @return array<string, mixed>|null
     */
    private static function artifactInstallEntry(array $installArtifacts, string $artifact): ?array
    {
        $expected = self::canonicalArtifactName($artifact);
        foreach ($installArtifacts as $key => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $reported = self::canonicalArtifactName(
                self::stringField($entry, ['artifact', 'name', 'id']) ?: (is_string($key) ? $key : ''),
            );
            if ($reported === $expected) {
                return $entry;
            }
        }

        return null;
    }

    private static function canonicalArtifactName(string $artifact): string
    {
        $normalized = str_replace('_', '-', strtolower(trim($artifact)));

        return match ($normalized) {
            'python', 'python-sdk', 'durable-workflow' => 'sdk-python',
            'sdk-php' => 'sdk-php',
            default => $normalized,
        };
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $fields
     */
    private static function stringField(array $value, array $fields): string
    {
        foreach ($fields as $field) {
            $string = self::stringValue($value[$field] ?? null);
            if ($string !== '') {
                return $string;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $fields
     */
    private static function hasTruthyField(array $value, array $fields): bool
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $value)) {
                continue;
            }

            $fieldValue = $value[$field];
            if ($fieldValue === true || $fieldValue === 1 || $fieldValue === '1') {
                return true;
            }

            if (is_string($fieldValue) && strtolower(trim($fieldValue)) === 'true') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<mixed>> $containers
     * @param list<string> $fields
     */
    private static function hasTruthyFieldIn(array $containers, array $fields): bool
    {
        foreach ($containers as $container) {
            if (self::hasTruthyField($container, $fields)) {
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
        foreach ($fields as $field) {
            if (! array_key_exists($field, $value)) {
                continue;
            }

            $fieldValue = $value[$field];
            if ($fieldValue === false || $fieldValue === 0 || $fieldValue === '0') {
                return true;
            }

            if (is_string($fieldValue) && strtolower(trim($fieldValue)) === 'false') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<mixed>> $containers
     * @param list<string> $fields
     */
    private static function hasExplicitFalseFieldIn(array $containers, array $fields): bool
    {
        foreach ($containers as $container) {
            if (self::hasExplicitFalseField($container, $fields)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $fields
     */
    private static function intField(array $value, array $fields): int
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $value) && is_numeric($value[$field])) {
                return (int) $value[$field];
            }
        }

        return -1;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $fields
     */
    private static function hasScalarField(array $value, array $fields): bool
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $value)) {
                continue;
            }

            if (is_scalar($value[$field]) && self::stringValue($value[$field]) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $fields
     */
    private static function hasArrayField(array $value, array $fields): bool
    {
        foreach ($fields as $field) {
            if (isset($value[$field]) && is_array($value[$field]) && $value[$field] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $fields
     *
     * @return array<mixed>|null
     */
    private static function arrayField(array $value, array $fields): ?array
    {
        foreach ($fields as $field) {
            if (isset($value[$field]) && is_array($value[$field])) {
                return $value[$field];
            }
        }

        return null;
    }

    /**
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
                $string = self::stringValue($item);
                if ($string !== '') {
                    $strings[] = $string;
                }
            }
        }

        return $strings;
    }

    private static function stringValue(mixed $value): string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }

        return trim((string) $value);
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
