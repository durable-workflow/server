<?php

namespace App\Support;

use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Support\PlatformConformanceSuite;
use Workflow\V2\Support\WorkflowTaskLease;

/**
 * Published-artifact handoff for the self-serve single-region operating
 * envelope. The executable runner lives in this repository, while every
 * product behavior under test must come from an immutable public image.
 */
final class SingleRegionFailoverContract
{
    public const SCHEMA = 'durable-workflow.v2.single-region-failover.contract';

    public const VERSION = 10;

    public const RESULT_SCHEMA = 'durable-workflow.v2.single-region-failover.result';

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
            'fixture_category' => 'single_region_failover_contract',
            'platform_conformance_suite_authority' => PlatformConformanceSuite::SCHEMA,
            'scenario_manifest' => [
                'schema' => 'durable-workflow.v2.platform-conformance.runtime-scenarios',
                'category' => 'single_region_failover_contract',
                'suite_schema' => PlatformConformanceSuite::SCHEMA,
                'suite_version' => PlatformConformanceSuite::VERSION,
                'public_path' => 'https://durable-workflow.com/platform-conformance/single-region-failover-scenarios.json',
                'source_path' => 'static/platform-conformance/single-region-failover-scenarios.json',
            ],
            'artifact_policy' => [
                'published_artifacts_only' => true,
                'required_server_reference' => 'durableworkflow/server:<concrete-tag-or-digest>',
                'runtime_reference' => 'resolved_repo_digest',
                'supporting_images_resolved_to_repo_digests' => true,
                'local_product_source_checkouts_used' => false,
                'compose_build_sections_forbidden' => true,
                'product_source_bind_mounts_forbidden' => true,
                'rolling_or_local_image_references_rejected' => true,
                'required_version_records' => [
                    'server_image_requested',
                    'server_image_digest',
                    'mysql_image_digest',
                    'redis_image_digest',
                    'load_balancer_image_digest',
                    'docker_engine_version',
                    'docker_compose_version',
                    'bash_version',
                    'python_version',
                    'embedded_workflow_package_version_and_reference',
                    'runner_version',
                ],
            ],
            'required_topology' => [
                'api_nodes' => 2,
                'shared_endpoint' => 1,
                'durable_database' => 1,
                'shared_redis' => 1,
                'queue_workers' => 1,
                'scheduler_maintenance_runners' => 1,
                'sticky_sessions' => false,
                'database_engines' => ['mysql'],
            ],
            'required_scenarios' => [
                'published_artifact_provenance',
                'cross_node_workflow_completion',
                'api_node_loss',
                'database_interruption',
                'redis_interruption',
                'worker_lease_loss',
                'singleton_scheduler_restart',
            ],
            'recovery_bounds' => [
                'api_node_useful_traffic_seconds' => 15,
                'database_ready_after_return_seconds' => 30,
                'database_reclaim_after_lease_seconds' => 10,
                'redis_poll_discovery_seconds' => 10,
                'redis_recovered_poll_discovery_seconds' => 3,
                'redis_ready_after_return_seconds' => 15,
                'workflow_task_lease_seconds' => WorkflowTaskLease::seconds(),
                'worker_repair_after_lease_seconds' => 10,
                'scheduler_fire_after_restart_seconds' => 15,
            ],
            'readiness_contract' => [
                'database_interruption' => [
                    'http_status' => 503,
                    'status' => 'not_ready',
                    'database_check' => 'unavailable',
                    'writes' => 'fail_without_acknowledgement',
                ],
                'redis_interruption' => [
                    'http_status' => 200,
                    'status' => 'ready',
                    'cache_check' => 'warning',
                    'durable_correctness' => 'preserved_by_database_poll_fallback',
                ],
            ],
            'run_status_contract' => array_reduce(
                RunStatus::cases(),
                static function (array $contract, RunStatus $status): array {
                    $contract[$status->value] = [
                        'status_bucket' => $status->statusBucket()->value,
                        'is_terminal' => $status->isTerminal(),
                    ];

                    return $contract;
                },
                [],
            ),
            'result_requirements' => [
                'topology',
                'artifacts',
                'tools',
                'phase_outcomes',
                'readiness_transitions',
                'recovery_timings_ms',
                'identities',
                'duplicate_assertions',
                'loss_assertions',
                'recovery_bounds',
                'started_at',
                'finished_at',
                'outcome',
            ],
            'host_runner_contract' => [
                'status' => 'executable_published_artifact_rehearsal',
                'runner_repository' => 'server',
                'runner_key' => 'single-region-failover',
                'compose_path' => 'docker-compose.failover-rehearsal.yml',
                'runner_path' => 'scripts/conformance/single-region-failover-published-artifacts.sh',
                'invocation' => 'DW_SERVER_IMAGE=<public-image-tag-or-digest> scripts/conformance/single-region-failover-published-artifacts.sh --result-dir <result-dir>',
                'bounded_invocation' => 'DW_FAILOVER_MODE=bounded DW_SERVER_IMAGE=<public-image-tag-or-digest> scripts/conformance/single-region-failover-published-artifacts.sh --result-dir <result-dir>',
                'connect_host_environment' => 'DW_FAILOVER_CONNECT_HOST',
                'connect_host_default' => '127.0.0.1',
                'connect_host_value' => 'hostname_or_ip_without_url_scheme_path_or_port',
                'published_port_environment' => [
                    'server_a' => 'DW_FAILOVER_SERVER_A_PORT',
                    'server_b' => 'DW_FAILOVER_SERVER_B_PORT',
                    'load_balancer' => 'DW_FAILOVER_LB_PORT',
                ],
                'topology_start_failure_evidence' => [
                    'resolved_probe_endpoints',
                    'compose_ps',
                    'published_port_mappings',
                    'readiness_observations',
                ],
                'api_node_loss_evidence' => [
                    'acknowledged_task',
                    'lease_timing',
                    'topology_after_loss',
                    'surviving_node_readiness',
                    'survivor_traffic',
                    'survivor_completion',
                    'final_description',
                    'compose_ps',
                    'load_balancer_logs',
                    'surviving_node_logs',
                ],
                'database_interruption_evidence' => [
                    'acknowledged_task',
                    'lease_timing',
                    'readiness_down',
                    'database_down_write',
                    'readiness_recovered',
                    'post_recovery_description',
                    'stale_owner_fence',
                    'replacement_reclaim',
                    'completion',
                    'duplicate_completion',
                    'final_description',
                ],
                'worker_lease_loss_evidence' => [
                    'acknowledged_task',
                    'pre_recovery_description',
                    'recovered_lease',
                    'completion',
                    'duplicate_completion',
                    'final_description',
                ],
                'required_host_capabilities' => [
                    'bash',
                    'docker_engine',
                    'docker_compose_v2',
                    'python_3_11_or_newer',
                    'public_registry_network_access',
                ],
                'result_file' => 'single-region-failover-result.json',
                'must_execute_against_published_artifacts' => true,
                'must_fail_closed_on_local_product_runtime' => true,
            ],
            'coverage_gate' => [
                'passing_outcome_requires' => [
                    'all_required_scenarios_pass',
                    'all_runtime_images_are_repo_digest_pinned',
                    'no_compose_build_or_product_source_mounts',
                    'exactly_one_scheduler_runner',
                    'all_acknowledged_workflow_state_is_present',
                    'every_completion_identity_is_unique',
                    'every_recovery_timing_is_within_bound',
                ],
                'missing_scenario_outcome' => 'non_passing',
                'provenance_failure_outcome' => 'fail_closed',
                'runner_blocked_outcome' => 'non_passing_runner_blocked',
            ],
        ];
    }
}
