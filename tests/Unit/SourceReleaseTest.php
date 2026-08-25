<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SourceRelease;
use Tests\TestCase;

final class SourceReleaseTest extends TestCase
{
    public function test_runtime_version_fallback_reads_the_source_release_authority(): void
    {
        $release = json_decode(
            (string) file_get_contents(base_path('resources/release/source-release.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame($release['server']['version'], SourceRelease::version());
    }
}
