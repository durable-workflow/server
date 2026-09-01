<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pins the dedicated matching-role Compose override shape so the
 * documentation, the contract test for the env var, and the deployment
 * artifact stay in lockstep.
 */
class DedicatedMatchingComposeContractTest extends TestCase
{
    public function test_override_swaps_worker_to_execution_only_and_adds_dedicated_matching_service(): void
    {
        $compose = $this->read('docker-compose.dedicated-matching.yml');

        foreach ([
            'server:',
            'DW_SERVER_PROCESS_CLASS: control_plane_node',
            'worker:',
            'DW_V2_MATCHING_ROLE_QUEUE_WAKE: "false"',
            'DW_SERVER_TOPOLOGY_SHAPE: split_control_execution',
            'DW_SERVER_PROCESS_CLASS: execution_node',
            'scheduler:',
            'DW_SERVER_PROCESS_CLASS: scheduler_node',
            'matching:',
            'DW_SERVER_PROCESS_CLASS: matching_node',
            'command: php artisan workflow:v2:repair-pass --loop',
            'bootstrap:',
            'condition: service_completed_successfully',
            'mysql:',
            'condition: service_healthy',
            'redis:',
            'init: true',
        ] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $compose,
                "docker-compose.dedicated-matching.yml must contain {$needle}",
            );
        }
    }

    public function test_override_disables_in_worker_matching_wake_on_every_long_running_service(): void
    {
        $compose = $this->read('docker-compose.dedicated-matching.yml');

        $this->assertSame(
            4,
            substr_count($compose, 'DW_V2_MATCHING_ROLE_QUEUE_WAKE: "false"'),
            'dedicated matching override must disable queue-wake ownership on server, worker, scheduler, and matching services so their process-local diagnostics all report the dedicated repair pass as the wake owner',
        );
    }

    public function test_override_uses_the_same_image_alias_as_published_compose(): void
    {
        $override = $this->read('docker-compose.dedicated-matching.yml');
        $published = $this->read('docker-compose.published.yml');
        $source = json_decode(
            $this->read('resources/release/source-release.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $version = $source['server']['version'] ?? null;
        $this->assertIsString($version);

        foreach ([
            'DW_SERVER_IMAGE:-durableworkflow/server:${DW_SERVER_TAG:-'.$version.'}',
        ] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $override,
                "override must reuse {$needle} so layered compose runs the same image",
            );
            $this->assertStringContainsString(
                $needle,
                $published,
                "published compose must declare {$needle} so the override remains consistent",
            );
        }
    }

    public function test_published_compose_pins_topology_identity_for_supported_processes(): void
    {
        $published = $this->read('docker-compose.published.yml');

        foreach ([
            'DW_SERVER_TOPOLOGY_SHAPE: standalone_server',
            'DW_SERVER_PROCESS_CLASS: server_http_node',
            'DW_SERVER_PROCESS_CLASS: worker_node',
            'DW_SERVER_PROCESS_CLASS: scheduler_node',
        ] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $published,
                "published compose must contain {$needle} so long-running services advertise their real role class",
            );
        }
    }

    public function test_override_promotes_all_long_running_services_to_split_role_identity(): void
    {
        $override = $this->read('docker-compose.dedicated-matching.yml');

        foreach ([
            'DW_SERVER_TOPOLOGY_SHAPE: split_control_execution',
            'DW_SERVER_PROCESS_CLASS: control_plane_node',
            'DW_SERVER_PROCESS_CLASS: execution_node',
            'DW_SERVER_PROCESS_CLASS: scheduler_node',
            'DW_SERVER_PROCESS_CLASS: matching_node',
        ] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $override,
                "dedicated matching override must contain {$needle} so migration-shape diagnostics expose the split role class",
            );
        }
    }

    private function read(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        $this->assertNotFalse($source, "{$path} must be readable");

        return $source;
    }
}
