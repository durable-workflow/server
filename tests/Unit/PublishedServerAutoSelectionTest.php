<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Native releases publish to durableworkflow/server as major version 3
 * (Server #325). A published-artifact runner that picks a server tag on its
 * own must stay on the PHP 2.x line, so a native release never silently
 * becomes the server those runners qualify.
 */
class PublishedServerAutoSelectionTest extends TestCase
{
    private const RUNNERS = [
        'scripts/conformance/namespaces-published-artifacts.sh',
        'scripts/conformance/principal-attribution-published-artifacts.sh',
        'scripts/conformance/python-published-artifacts.sh',
        'scripts/conformance/replay-published-artifacts.sh',
        'scripts/conformance/sagas-published-artifacts.sh',
    ];

    public function test_every_tag_listing_runner_is_known(): void
    {
        $listing = [];
        foreach (glob($this->root().'/scripts/conformance/*') ?: [] as $path) {
            if (! is_file($path)) {
                continue;
            }
            $source = (string) file_get_contents($path);
            if (str_contains($source, 'repositories/durableworkflow/server/tags?')) {
                $listing[] = 'scripts/conformance/'.basename($path);
            }
        }
        sort($listing);
        $known = self::RUNNERS;
        sort($known);

        $this->assertSame($known, $listing, 'a runner that lists durableworkflow/server tags must restrict its choice to the PHP line');
    }

    public function test_python_runners_choose_only_php_2_tags(): void
    {
        foreach (self::RUNNERS as $runner) {
            $source = (string) file_get_contents($this->root().'/'.$runner);
            $this->assertMatchesRegularExpression(
                '/(?:is_exact_server_patch_tag|exact_server_tag)\([^\n]*\)\s+and\s+[^\n]*\.startswith\("2\."\)/',
                $source,
                "$runner must restrict automatic server selection to 2.x",
            );
        }
    }

    public function test_migration_runner_pins_each_server_line_below_native(): void
    {
        $source = (string) file_get_contents($this->root().'/scripts/conformance/migration-published-artifacts.mjs');
        preg_match_all("/latestDockerHubTag\\('durableworkflow\\/server',\\s*([^)]*)\\)/", $source, $calls);

        $this->assertNotEmpty($calls[1]);
        foreach ($calls[1] as $pattern) {
            $this->assertMatchesRegularExpression('/^\/\^v\?[12]\\\\\.\/$/', trim($pattern));
        }
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
