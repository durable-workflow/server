<?php

namespace App\Support;

use Workflow\V2\Support\PlatformConformanceSuite;

/**
 * Machine-readable handoff for the coordinated 2.0 prerelease readiness matrix.
 *
 * The public docs own the scenario manifest. This server-side contract exposes
 * the same category through cluster info so host conformance runners can treat
 * installability, migration, API stability, documentation, configuration, and
 * cross-component coupling as required cells instead of as a smoke-only summary.
 */
final class PrereleaseReadinessContract
{
    public const SCHEMA = 'durable-workflow.v2.prerelease-readiness.contract';

    public const VERSION = 1;

    public const RESULT_SCHEMA = 'durable-workflow.v2.prerelease-readiness.result';

    public const RESULT_VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'result_schema' => self::RESULT_SCHEMA,
            'result_version' => self::RESULT_VERSION,
            'fixture_category' => 'prerelease_readiness_contract',
            'platform_conformance_suite_authority' => PlatformConformanceSuite::SCHEMA,
            'scenario_manifest' => [
                'schema' => 'durable-workflow.v2.platform-conformance.runtime-scenarios',
                'category' => 'prerelease_readiness_contract',
                'public_path' => 'https://durable-workflow.github.io/platform-conformance/prerelease-readiness-scenarios.json',
                'source_path' => 'static/platform-conformance/prerelease-readiness-scenarios.json',
                'suite_version' => PlatformConformanceSuite::VERSION,
            ],
            'artifact_policy' => [
                'version_source' => 'latest_complete_published_artifact_set_at_run_time',
                'version_requirement' => 'concrete_published_versions_with_downloadable_install_assets_pinned_at_run_time',
                'published_artifacts_only' => true,
                'requires_public_user_facing_docs' => true,
                'requires_resolved_versions' => true,
                'requires_artifact_sources_for_each_required_artifact' => true,
                'requires_local_product_source_checkouts_used_false' => true,
                'placeholder_versions_rejected' => true,
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
                'install_channels' => [
                    'server' => 'Docker image durableworkflow/server:<resolved-version>',
                    'cli' => 'official dw install script pinned to the resolved release tag',
                    'sdk-python' => 'PyPI package durable-workflow==<resolved-version>',
                    'workflow' => 'Composer package durable-workflow/workflow:<resolved-version>',
                    'waterline' => 'Composer package durable-workflow/waterline:<resolved-version>',
                    'sample-app' => 'published sample application release surface matching the resolved tuple',
                    'public-docs' => 'versioned public docs route and release-audit manifest for the resolved tuple',
                ],
                'forbidden_sources' => [
                    'local_product_source_checkout',
                    'workspace_repo_as_artifact_under_test',
                    'release_tag_without_required_assets',
                    'rolling_server_image_tag',
                    'unversioned_prerelease_docs_alias',
                ],
                'required_run_record_fields' => [
                    'artifact_versions',
                    'artifact_sources',
                    'started_at',
                    'finished_at',
                    'generated_at',
                    'outcome',
                    'runner_blocked',
                    'scenario_results',
                    'workflow_readiness_verdict',
                    'waterline_readiness_verdict',
                    'category_verdicts',
                    'public_docs_urls',
                    'findings',
                    'finding_links',
                ],
            ],
            'scenario_statuses' => self::scenarioStatuses(),
            'required_matrix' => [
                'primary_verdicts' => [
                    'workflow',
                    'waterline',
                ],
                'readiness_categories' => self::readinessCategories(),
                'ecosystem_artifacts' => self::requiredArtifacts(),
                'release_channels' => [
                    'server-release',
                    'cli-release',
                    'python-package',
                    'composer-prerelease',
                    'waterline-prerelease',
                    'versioned-prerelease-docs',
                ],
            ],
            'required_scenarios' => self::requiredScenarios(),
            'scenario_requirements' => self::scenarioRequirements(),
            'coverage_gate' => [
                'passing_outcome_requires' => [
                    'all_required_scenarios_reported',
                    'all_required_scenarios_pass',
                    'separate_workflow_and_waterline_go_verdicts',
                    'all_readiness_category_verdicts_go',
                    'published_artifact_versions_recorded_and_pinned',
                    'artifact_sources_recorded_for_each_required_artifact',
                    'public_docs_urls_are_versioned_prerelease_routes',
                    'stable_default_docs_line_remains_1_x',
                    'quickstart_local_server_branch_reaches_completed_workflow',
                    'quickstart_laravel_branch_reaches_completed_workflow',
                    'migration_api_config_docs_and_coupling_observations_recorded',
                    'each_pass_scenario_has_observed_outputs',
                    'each_pass_scenario_has_scenario_specific_evidence',
                    'each_non_pass_scenario_has_linked_findings',
                    'declared_outcome_matches_evaluated_status',
                    'no_local_product_source_artifacts_are_reported',
                    'runner_blocked_false_for_product_evidence',
                ],
                'installability_smoke_only_outcome' => 'non_passing',
                'discovery_only_quickstart_outcome' => 'non_passing',
                'uncovered_required_scenario_outcome' => 'non_passing',
                'runner_blocked_outcome' => 'non_passing_runner_blocked',
            ],
            'host_runner_contract' => [
                'status' => 'required_for_passing_prerelease_readiness_conformance',
                'runner_repository' => 'server',
                'runner_key' => 'prerelease',
                'result_schema' => self::RESULT_SCHEMA,
                'must_execute_against_published_artifacts' => true,
                'must_use_versioned_prerelease_docs_routes' => true,
                'must_preserve_stable_default_docs_line' => true,
                'must_record_runner_blocked_false_for_product_evidence' => true,
                'must_emit_result_for_every_required_scenario' => true,
                'must_link_focused_findings_for_every_non_pass_scenario' => true,
                'smoke_summary_only_outcome' => 'non_passing_smoke_only',
                'required_execution_scopes' => [
                    'published-artifact-release-set',
                    'workflow-feature-completeness',
                    'workflow-migration-readiness',
                    'workflow-public-api-stability',
                    'workflow-documentation-and-configuration',
                    'quickstart-local-server-hosted-completion',
                    'quickstart-laravel-branch-completion',
                    'waterline-feature-completeness',
                    'waterline-migration-and-configuration',
                    'waterline-public-api-and-documentation',
                    'ecosystem-compatibility',
                    'focused-finding-routing',
                ],
                'routing_policy' => [
                    'missing_required_scenario' => [
                        'scenario_status' => 'not_covered',
                        'finding_type' => 'conformance_runner_coverage_gap',
                        'owner' => 'conformance_harness',
                    ],
                    'product_behavior_failure' => [
                        'scenario_status' => 'fail',
                        'finding_type' => 'product_behavior_gap',
                        'owner' => 'owning_product_surface',
                    ],
                    'docs_metadata_stale' => [
                        'scenario_status' => 'fail',
                        'finding_type' => 'public_docs_release_metadata_stale',
                        'owner' => 'public_docs',
                    ],
                    'host_environment_failure' => [
                        'scenario_status' => 'runner_blocked',
                        'finding_type' => 'runner_gap',
                        'owner' => 'conformance_harness',
                    ],
                ],
            ],
            'result_gate' => PrereleaseReadinessResultGate::spec(),
            'finding_policy' => [
                'non_pass_scenarios_require_focused_findings' => true,
                'aggregate_blocker_must_not_absorb_owning_surface_findings' => true,
                'missing_or_stale_public_docs_release_audit' => [
                    'owner' => 'public_docs',
                    'finding_type' => 'public_docs_release_metadata_stale',
                ],
                'incomplete_workflow_or_waterline_surface' => [
                    'owner' => 'workflow_or_waterline',
                    'finding_type' => 'product_behavior_gap',
                ],
                'unexecuted_matrix_area' => [
                    'owner' => 'conformance_harness',
                    'finding_type' => 'conformance_runner_coverage_gap',
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function scenarioStatuses(): array
    {
        return [
            'pass',
            'fail',
            'unsupported',
            'not_covered',
            'runner_blocked',
        ];
    }

    /**
     * @return list<string>
     */
    public static function requiredArtifacts(): array
    {
        return [
            'server',
            'cli',
            'sdk-python',
            'workflow',
            'waterline',
            'sample-app',
            'public-docs',
        ];
    }

    /**
     * @return list<string>
     */
    public static function readinessCategories(): array
    {
        return [
            'core_feature_completeness',
            'migration_readiness',
            'public_api_stability',
            'documentation_accuracy',
            'configuration_understandability',
            'cross_component_compatibility',
        ];
    }

    /**
     * @return list<string>
     */
    public static function requiredScenarios(): array
    {
        return [
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
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function scenarioRequirements(): array
    {
        return [
            'published_artifact_release_set' => [
                'evidence' => [
                    'published_artifact_versions',
                    'artifact_sources',
                    'install_logs',
                    'local_product_source_checkouts_used',
                ],
            ],
            'workflow_feature_completeness_verdict' => [
                'readiness_category' => 'core_feature_completeness',
                'primary_verdict' => 'workflow',
                'evidence' => [
                    'workflow_readiness_verdict',
                    'feature_completeness_observations',
                    'public_docs_urls',
                ],
            ],
            'workflow_migration_readiness_verdict' => [
                'readiness_category' => 'migration_readiness',
                'primary_verdict' => 'workflow',
                'evidence' => [
                    'migration_observations',
                    'public_docs_urls',
                    'rollback_or_skew_observations',
                ],
            ],
            'workflow_public_api_stability_verdict' => [
                'readiness_category' => 'public_api_stability',
                'primary_verdict' => 'workflow',
                'evidence' => [
                    'api_stability_observations',
                    'cross_component_observations',
                ],
            ],
            'workflow_documentation_and_config_verdict' => [
                'readiness_category' => 'documentation_accuracy',
                'primary_verdict' => 'workflow',
                'evidence' => [
                    'public_docs_urls',
                    'configuration_observations',
                    'sample_app_observations',
                ],
            ],
            'quickstart_local_server_hosted_completion' => [
                'readiness_category' => 'documentation_accuracy',
                'evidence' => [
                    'quickstart_observations',
                    'wall_clock_times',
                    'observed_completed_workflow',
                ],
            ],
            'quickstart_laravel_branch_completion' => [
                'readiness_category' => 'documentation_accuracy',
                'evidence' => [
                    'quickstart_laravel_observations',
                    'wall_clock_times',
                    'observed_completed_workflow',
                ],
            ],
            'waterline_feature_completeness_verdict' => [
                'readiness_category' => 'core_feature_completeness',
                'primary_verdict' => 'waterline',
                'evidence' => [
                    'waterline_readiness_verdict',
                    'feature_completeness_observations',
                    'operator_visibility_observations',
                ],
            ],
            'waterline_migration_and_config_verdict' => [
                'readiness_category' => 'configuration_understandability',
                'primary_verdict' => 'waterline',
                'evidence' => [
                    'migration_observations',
                    'configuration_observations',
                    'operator_readiness_observations',
                ],
            ],
            'waterline_public_api_and_docs_verdict' => [
                'readiness_category' => 'public_api_stability',
                'primary_verdict' => 'waterline',
                'evidence' => [
                    'api_stability_observations',
                    'public_docs_urls',
                    'cross_component_observations',
                ],
            ],
            'ecosystem_compatibility_verdict' => [
                'readiness_category' => 'cross_component_compatibility',
                'evidence' => [
                    'cross_component_observations',
                    'release_channel_observations',
                    'sample_app_observations',
                ],
            ],
            'focused_finding_routing' => [
                'evidence' => [
                    'findings',
                    'finding_links',
                    'non_pass_scenario_routing',
                ],
            ],
        ];
    }
}
