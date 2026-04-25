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
            'worker:',
            'DW_V2_MATCHING_ROLE_QUEUE_WAKE: "false"',
            'matching:',
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

    public function test_override_uses_the_same_image_alias_as_published_compose(): void
    {
        $override = $this->read('docker-compose.dedicated-matching.yml');
        $published = $this->read('docker-compose.published.yml');

        foreach ([
            'DW_SERVER_IMAGE:-durableworkflow/server:${DW_SERVER_TAG:-0.2}',
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

    public function test_readme_documents_the_override_alongside_the_dedicated_daemon_section(): void
    {
        $readme = $this->read('README.md');

        foreach ([
            '### Dedicated Matching-Role Daemon',
            'docker-compose.dedicated-matching.yml',
            '-f docker-compose.published.yml',
            '-f docker-compose.dedicated-matching.yml',
            'php artisan workflow:v2:repair-pass --loop',
            'DW_V2_MATCHING_ROLE_QUEUE_WAKE',
        ] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $readme,
                "README must document {$needle} so operators can discover the override",
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
