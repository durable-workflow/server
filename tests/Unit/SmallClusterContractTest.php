<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SmallClusterContractTest extends TestCase
{
    public function test_validation_note_records_the_narrow_proceed_decision(): void
    {
        $source = $this->read('docs/small-cluster-validation.md');

        foreach ([
            'Proceed with a narrow small-cluster contract.',
            'External MySQL or external PostgreSQL',
            'Shared Redis',
            'One scheduler or maintenance process',
            'Stop-the-world upgrades',
            'without sticky sessions',
            'Redis-less multi-node mode',
            'Duplicate scheduler or maintenance runners',
            'Rolling upgrades',
            'Multi-region deployments',
            'Helm charts',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
    }

    public function test_readme_links_to_the_validation_note(): void
    {
        $source = $this->read('README.md');

        foreach ([
            '### Small Cluster Status',
            'docs/small-cluster-validation.md',
            'external MySQL or PostgreSQL',
            'shared Redis',
            'exactly one scheduler or maintenance runner',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
    }

    private function read(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        $this->assertNotFalse($source, "{$path} must be readable");

        return $source;
    }
}
