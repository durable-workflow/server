<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\EnvAuditor;
use PHPUnit\Framework\TestCase;

class EnvAuditorTest extends TestCase
{
    public function test_audit_splits_env_into_known_unknown_legacy_framework(): void
    {
        $contract = [
            'prefix' => 'DW_',
            'vars' => [
                'DW_AUTH_DRIVER' => ['legacy' => 'WORKFLOW_SERVER_AUTH_DRIVER'],
                'DW_SERVER_ID' => ['legacy' => 'WORKFLOW_SERVER_ID'],
                'DW_MODE' => [],
            ],
            'framework' => ['APP_KEY', 'DB_HOST'],
        ];

        $report = EnvAuditor::audit([
            'DW_AUTH_DRIVER' => 'token',
            'DW_SERVER_ID' => 'host-1',
            'DW_MODE' => 'service',
            'DW_TYPO_VAR' => '1',
            'WORKFLOW_SERVER_AUTH_DRIVER' => 'token',
            'APP_KEY' => 'base64:...',
            'HOME' => '/home/vscode',
        ], $contract);

        $this->assertSame(['DW_AUTH_DRIVER', 'DW_MODE', 'DW_SERVER_ID'], $report['known']);
        $this->assertSame(['DW_TYPO_VAR'], $report['unknown']);
        $this->assertSame(
            [['legacy' => 'WORKFLOW_SERVER_AUTH_DRIVER', 'replacement' => 'DW_AUTH_DRIVER']],
            $report['legacy'],
        );
        $this->assertSame(['APP_KEY'], $report['framework']);
    }

    public function test_audit_recognizes_dw_vars_missing_legacy_key(): void
    {
        $contract = [
            'prefix' => 'DW_',
            'vars' => [
                'DW_ENV_AUDIT_STRICT' => ['description' => 'boot strictness'],
            ],
            'framework' => [],
        ];

        $report = EnvAuditor::audit(['DW_ENV_AUDIT_STRICT' => '1'], $contract);

        $this->assertSame(['DW_ENV_AUDIT_STRICT'], $report['known']);
        $this->assertSame([], $report['unknown']);
        $this->assertSame([], $report['legacy']);
    }

    public function test_audit_reports_no_issues_for_clean_environment(): void
    {
        $contract = [
            'prefix' => 'DW_',
            'vars' => ['DW_MODE' => []],
            'framework' => ['APP_KEY'],
        ];

        $report = EnvAuditor::audit([
            'DW_MODE' => 'service',
            'APP_KEY' => 'x',
            'PATH' => '/usr/bin',
        ], $contract);

        $this->assertSame([], $report['unknown']);
        $this->assertSame([], $report['legacy']);
        $this->assertSame(['DW_MODE'], $report['known']);
    }

    public function test_env_prefers_dw_over_legacy(): void
    {
        putenv('DW_TEST_PRIMARY=from-dw');
        putenv('WORKFLOW_LEGACY_TEST_PRIMARY=from-legacy');
        $_ENV['DW_TEST_PRIMARY'] = 'from-dw';
        $_ENV['WORKFLOW_LEGACY_TEST_PRIMARY'] = 'from-legacy';

        try {
            $this->assertSame('from-dw', EnvAuditor::env('DW_TEST_PRIMARY', 'WORKFLOW_LEGACY_TEST_PRIMARY', 'default'));
        } finally {
            putenv('DW_TEST_PRIMARY');
            putenv('WORKFLOW_LEGACY_TEST_PRIMARY');
            unset($_ENV['DW_TEST_PRIMARY'], $_ENV['WORKFLOW_LEGACY_TEST_PRIMARY']);
        }
    }

    public function test_env_falls_back_to_legacy_when_dw_absent(): void
    {
        putenv('DW_TEST_FALLBACK');
        putenv('WORKFLOW_LEGACY_TEST_FALLBACK=from-legacy');
        unset($_ENV['DW_TEST_FALLBACK']);
        $_ENV['WORKFLOW_LEGACY_TEST_FALLBACK'] = 'from-legacy';

        try {
            $this->assertSame('from-legacy', EnvAuditor::env('DW_TEST_FALLBACK', 'WORKFLOW_LEGACY_TEST_FALLBACK', 'default'));
        } finally {
            putenv('WORKFLOW_LEGACY_TEST_FALLBACK');
            unset($_ENV['WORKFLOW_LEGACY_TEST_FALLBACK']);
        }
    }

    public function test_env_returns_default_when_neither_is_set(): void
    {
        putenv('DW_TEST_MISSING');
        putenv('WORKFLOW_LEGACY_TEST_MISSING');
        unset($_ENV['DW_TEST_MISSING'], $_ENV['WORKFLOW_LEGACY_TEST_MISSING']);

        $this->assertSame('default', EnvAuditor::env('DW_TEST_MISSING', 'WORKFLOW_LEGACY_TEST_MISSING', 'default'));
    }

    public function test_current_environment_captures_both_env_and_getenv(): void
    {
        putenv('DW_TEST_CAPTURE=via-putenv');
        $_ENV['DW_TEST_CAPTURE_ENV'] = 'via-env';

        try {
            $env = EnvAuditor::currentEnvironment();

            $this->assertArrayHasKey('DW_TEST_CAPTURE', $env);
            $this->assertArrayHasKey('DW_TEST_CAPTURE_ENV', $env);
            $this->assertSame('via-putenv', $env['DW_TEST_CAPTURE']);
            $this->assertSame('via-env', $env['DW_TEST_CAPTURE_ENV']);
        } finally {
            putenv('DW_TEST_CAPTURE');
            unset($_ENV['DW_TEST_CAPTURE_ENV']);
        }
    }
}
