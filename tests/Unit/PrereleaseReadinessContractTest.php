<?php

namespace Tests\Unit;

use App\Support\PrereleaseReadinessContract;
use App\Support\PrereleaseReadinessResultGate;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;

class PrereleaseReadinessContractTest extends TestCase
{
    public function test_manifest_names_the_full_prerelease_readiness_matrix(): void
    {
        $manifest = PrereleaseReadinessContract::manifest();

        $this->assertSame('durable-workflow.v2.prerelease-readiness.contract', $manifest['schema']);
        $this->assertSame(PrereleaseReadinessContract::VERSION, $manifest['version']);
        $this->assertSame(
            'durable-workflow.v2.prerelease-readiness.result',
            $manifest['result_schema'],
        );
        $this->assertSame(
            PlatformConformanceSuite::SCHEMA,
            $manifest['platform_conformance_suite_authority'],
        );
        $this->assertSame(
            'static/platform-conformance/prerelease-readiness-scenarios.json',
            $manifest['scenario_manifest']['source_path'],
        );
        $this->assertSame(
            PlatformConformanceSuite::VERSION,
            $manifest['scenario_manifest']['suite_version'],
        );
        $this->assertSame(
            PrereleaseReadinessResultGate::SCHEMA,
            $manifest['result_gate']['schema'],
        );

        foreach (['server', 'cli', 'sdk-python', 'workflow', 'waterline', 'sample-app', 'public-docs'] as $artifact) {
            $this->assertContains($artifact, $manifest['required_matrix']['ecosystem_artifacts']);
            $this->assertArrayHasKey($artifact, $manifest['artifact_policy']['install_channels']);
        }

        foreach ([
            'core_feature_completeness',
            'migration_readiness',
            'public_api_stability',
            'documentation_accuracy',
            'configuration_understandability',
            'cross_component_compatibility',
        ] as $category) {
            $this->assertContains($category, $manifest['required_matrix']['readiness_categories']);
        }
    }

    public function test_manifest_requires_quickstarts_and_focused_finding_routing(): void
    {
        $manifest = PrereleaseReadinessContract::manifest();

        foreach ([
            'published_artifact_release_set',
            'workflow_feature_completeness_verdict',
            'workflow_migration_readiness_verdict',
            'workflow_public_api_stability_verdict',
            'workflow_documentation_and_config_verdict',
            'quickstart_local_server_hosted_completion',
            'quickstart_laravel_branch_completion',
            'waterline_feature_completeness_verdict',
            'waterline_migration_and_config_verdict',
            'waterline_public_api_and_docs_verdict',
            'ecosystem_compatibility_verdict',
            'focused_finding_routing',
        ] as $scenario) {
            $this->assertContains($scenario, $manifest['required_scenarios']);
        }

        $this->assertSame(
            'non_passing',
            $manifest['coverage_gate']['installability_smoke_only_outcome'],
        );
        $this->assertTrue($manifest['host_runner_contract']['must_link_focused_findings_for_every_non_pass_scenario']);
        $this->assertSame(
            'conformance_runner_coverage_gap',
            $manifest['host_runner_contract']['routing_policy']['missing_required_scenario']['finding_type'],
        );
        $this->assertSame(
            'public_docs_release_metadata_stale',
            $manifest['host_runner_contract']['routing_policy']['docs_metadata_stale']['finding_type'],
        );
    }

    public function test_result_gate_rejects_installability_smoke_as_prerelease_pass(): void
    {
        $result = [
            'outcome' => 'pass',
            'artifact_versions' => $this->artifactVersions(),
            'artifact_sources' => $this->artifactSources(),
            'started_at' => '2026-06-05T16:00:00Z',
            'finished_at' => '2026-06-05T16:10:00Z',
            'generated_at' => '2026-06-05T16:10:01Z',
            'runner_blocked' => false,
            'workflow_readiness_verdict' => 'GO',
            'waterline_readiness_verdict' => 'GO',
            'category_verdicts' => $this->categoryVerdicts(),
            'public_docs_urls' => [
                'quickstart' => 'https://durable-workflow.github.io/docs/2.0/quickstart/',
            ],
            'release_channel_observations' => ['stable_default_docs_version' => '1.x'],
            'findings' => [],
            'finding_links' => [],
            'local_product_source_checkouts_used' => false,
            'scenario_results' => [
                'published_artifact_release_set' => [
                    'status' => 'pass',
                    'observed_outputs' => [
                        'published_artifact_versions' => $this->artifactVersions(),
                        'artifact_sources' => $this->artifactSources(),
                        'install_logs' => ['server' => 'installed'],
                        'local_product_source_checkouts_used' => false,
                    ],
                ],
            ],
        ];

        $evaluation = PrereleaseReadinessResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertTrue($evaluation['smoke_subset_detected']);
        $this->assertContains('quickstart_local_server_hosted_completion', $evaluation['missing_scenarios']);
        $this->assertContains(
            'installability_smoke_subset_cannot_pass',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertContains(
            'declared_outcome_does_not_match_gate',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_accepts_complete_matrix_evidence(): void
    {
        $result = $this->completeMatrixResult();

        $evaluation = PrereleaseReadinessResultGate::evaluate($result);

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['missing_scenarios']);
        $this->assertSame([], $evaluation['non_pass_scenarios']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_accepts_documented_versioned_prerelease_docs_routes(): void
    {
        foreach ([
            'https://durable-workflow.com/docs/2.0/introduction/',
            'https://durable-workflow.com/docs/2/introduction/',
            'https://durable-workflow.com/docs/v2/introduction/',
            'https://durable-workflow.com/docs/v2.0/introduction/',
            'https://durable-workflow.com/docs/version-2/introduction/',
            'https://durable-workflow.com/docs/version-2.0/introduction/',
        ] as $url) {
            $result = $this->completeMatrixResult();
            $result['public_docs_urls'] = ['introduction' => $url];

            $evaluation = PrereleaseReadinessResultGate::evaluate($result);

            $this->assertSame('pass', $evaluation['status'], $url);
            $this->assertSame([], $evaluation['gate_failures'], $url);
        }
    }

    public function test_result_gate_requires_runner_blocked_false_for_product_evidence(): void
    {
        $result = $this->completeMatrixResult();
        unset($result['runner_blocked']);

        $missingEvaluation = PrereleaseReadinessResultGate::evaluate($result);

        $this->assertSame('non_passing', $missingEvaluation['status']);
        $this->assertContains(
            'runner_blocked_result_is_not_product_evidence',
            array_column($missingEvaluation['gate_failures'], 'code'),
        );
        $this->assertContains('runner_blocked', array_column($missingEvaluation['gate_failures'], 'field'));

        $result = $this->completeMatrixResult();
        $result['runner_blocked'] = true;

        $blockedEvaluation = PrereleaseReadinessResultGate::evaluate($result);

        $this->assertSame('non_passing', $blockedEvaluation['status']);
        $this->assertContains(
            'runner_blocked_result_is_not_product_evidence',
            array_column($blockedEvaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_nested_local_product_source_checkout_flags(): void
    {
        $result = $this->completeMatrixResult();
        $result['scenario_results']['waterline_feature_completeness_verdict']['observed_outputs'][
            'local_product_source_checkouts_used'
        ] = true;

        $evaluation = PrereleaseReadinessResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'local_product_source_checkouts_used_must_be_false',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertContains(
            'scenario_results.waterline_feature_completeness_verdict.observed_outputs.local_product_source_checkouts_used',
            array_column($evaluation['gate_failures'], 'field'),
        );
    }

    public function test_result_gate_rejects_each_advertised_placeholder_word_inside_an_artifact_version(): void
    {
        foreach (['latest', 'current', 'head', 'unresolved', 'placeholder'] as $placeholder) {
            $result = $this->completeMatrixResult();
            $result['artifact_versions']['server'] = 'durableworkflow/server:' . $placeholder;

            $evaluation = PrereleaseReadinessResultGate::evaluate($result);
            $serverPlaceholderFailures = array_values(array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['code'] ?? null) === 'placeholder_published_artifact_version'
                    && ($failure['artifact'] ?? null) === 'server',
            ));

            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertCount(1, $serverPlaceholderFailures);
            $this->assertSame('durableworkflow/server:' . $placeholder, $serverPlaceholderFailures[0]['version']);
        }
    }

    public function test_result_gate_requires_versioned_prerelease_docs_and_stable_default_docs_evidence(): void
    {
        $result = $this->completeMatrixResult();
        $result['public_docs_urls'] = [
            'quickstart' => 'https://durable-workflow.github.io/docs/quickstart/',
        ];

        $unversionedEvaluation = PrereleaseReadinessResultGate::evaluate($result);

        $this->assertSame('non_passing', $unversionedEvaluation['status']);
        $this->assertContains(
            'missing_versioned_prerelease_public_docs_url',
            array_column($unversionedEvaluation['gate_failures'], 'code'),
        );

        $result = $this->completeMatrixResult();
        unset($result['release_channel_observations']['stable_default_docs_version']);
        foreach ($result['scenario_results'] as &$scenarioResult) {
            unset($scenarioResult['observed_outputs']['release_channel_observations']);
        }
        unset($scenarioResult);

        $missingStableDefaultEvaluation = PrereleaseReadinessResultGate::evaluate($result);

        $this->assertSame('non_passing', $missingStableDefaultEvaluation['status']);
        $this->assertContains(
            'stable_default_docs_line_not_recorded',
            array_column($missingStableDefaultEvaluation['gate_failures'], 'code'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function completeMatrixResult(): array
    {
        return [
            'outcome' => 'pass',
            'runner_blocked' => false,
            'artifact_versions' => $this->artifactVersions(),
            'artifact_sources' => $this->artifactSources(),
            'started_at' => '2026-06-05T16:00:00Z',
            'finished_at' => '2026-06-05T16:50:00Z',
            'generated_at' => '2026-06-05T16:50:01Z',
            'workflow_readiness_verdict' => 'GO',
            'waterline_readiness_verdict' => 'GO',
            'category_verdicts' => $this->categoryVerdicts(),
            'public_docs_urls' => [
                'quickstart' => 'https://durable-workflow.github.io/docs/2.0/quickstart/',
                'release_audit' => 'https://durable-workflow.github.io/docs-page-release-audit.json',
            ],
            'install_logs' => ['server' => 'installed'],
            'migration_observations' => ['upgrade' => 'preserved'],
            'api_stability_observations' => ['workflow' => 'stable'],
            'configuration_observations' => ['defaults' => 'understandable'],
            'cross_component_observations' => ['tuple' => 'compatible'],
            'sample_app_observations' => ['quickstart' => 'matches docs'],
            'quickstart_observations' => ['status' => 'completed'],
            'quickstart_laravel_observations' => ['output' => 'Hello, Laravel!'],
            'feature_completeness_observations' => ['workflow' => 'covered'],
            'operator_visibility_observations' => ['waterline' => 'covered'],
            'operator_readiness_observations' => ['waterline' => 'actionable'],
            'rollback_or_skew_observations' => ['skew' => 'refused'],
            'release_channel_observations' => [
                'docs' => 'versioned',
                'stable_default_docs_version' => '1.x',
            ],
            'non_pass_scenario_routing' => ['none' => 'all pass'],
            'findings' => [],
            'finding_links' => [],
            'local_product_source_checkouts_used' => false,
            'scenario_results' => $this->passingScenarioResults(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function artifactVersions(): array
    {
        return [
            'server' => '0.2.348',
            'cli' => '0.1.77',
            'sdk-python' => '0.4.85',
            'workflow' => '2.0.0-alpha.200',
            'waterline' => '2.0.0-alpha.83',
            'sample-app' => '0.1.0',
            'public-docs' => '2026-06-05',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function artifactSources(): array
    {
        return [
            'server' => 'dockerhub:durableworkflow/server:0.2.348',
            'cli' => 'github-release:durable-workflow/cli:0.1.77',
            'sdk-python' => 'pypi:durable-workflow:0.4.85',
            'workflow' => 'packagist:durable-workflow/workflow:2.0.0-alpha.200',
            'waterline' => 'packagist:durable-workflow/waterline:2.0.0-alpha.83',
            'sample-app' => 'public-release:sample-app:0.1.0',
            'public-docs' => 'https://durable-workflow.github.io/docs/2.0/',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function categoryVerdicts(): array
    {
        return [
            'core_feature_completeness' => 'GO',
            'migration_readiness' => 'GO',
            'public_api_stability' => 'GO',
            'documentation_accuracy' => 'GO',
            'configuration_understandability' => 'GO',
            'cross_component_compatibility' => 'GO',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function passingScenarioResults(): array
    {
        $results = [];
        foreach (PrereleaseReadinessContract::requiredScenarios() as $scenarioId) {
            $results[$scenarioId] = [
                'status' => 'pass',
                'observed_outputs' => [
                    'scenario_id' => $scenarioId,
                    'published_artifact_versions' => $this->artifactVersions(),
                    'artifact_sources' => $this->artifactSources(),
                    'install_logs' => ['ok' => true],
                    'local_product_source_checkouts_used' => false,
                    'feature_completeness_observations' => ['ok' => true],
                    'public_docs_urls' => ['https://durable-workflow.github.io/docs/2.0/quickstart/'],
                    'migration_observations' => ['ok' => true],
                    'rollback_or_skew_observations' => ['ok' => true],
                    'api_stability_observations' => ['ok' => true],
                    'cross_component_observations' => ['ok' => true],
                    'configuration_observations' => ['ok' => true],
                    'sample_app_observations' => ['ok' => true],
                    'quickstart_observations' => ['status' => 'completed'],
                    'quickstart_laravel_observations' => ['status' => 'completed'],
                    'wall_clock_times' => ['seconds' => 120],
                    'observed_completed_workflow' => ['status' => 'completed'],
                    'waterline_readiness_verdict' => 'GO',
                    'workflow_readiness_verdict' => 'GO',
                    'operator_visibility_observations' => ['ok' => true],
                    'operator_readiness_observations' => ['ok' => true],
                    'release_channel_observations' => ['stable_default_docs_version' => '1.x'],
                    'non_pass_scenario_routing' => ['none' => true],
                    'findings' => [],
                    'finding_links' => [],
                ],
            ];
        }

        return $results;
    }
}
