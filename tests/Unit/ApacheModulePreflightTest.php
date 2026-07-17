<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ApacheModulePreflightTest extends TestCase
{
    public function test_preflight_distinguishes_missing_modules_from_execution_failures(): void
    {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === '') {
            $this->markTestSkipped('bash is required to exercise the Apache module preflight.');
        }

        $test = __DIR__.'/Support/apache_module_preflight_test.sh';
        exec('bash '.escapeshellarg($test).' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertSame(['Apache module preflight regression passed.'], $output);
    }
}
