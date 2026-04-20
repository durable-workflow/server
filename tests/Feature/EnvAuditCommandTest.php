<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class EnvAuditCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dw-contract' => [
                'prefix' => 'DW_',
                'vars' => [
                    'DW_MODE' => ['since' => '2.0.0', 'legacy' => 'WORKFLOW_SERVER_MODE'],
                    'DW_AUTH_DRIVER' => ['since' => '2.0.0', 'legacy' => 'WORKFLOW_SERVER_AUTH_DRIVER'],
                ],
                'framework' => ['APP_KEY'],
            ],
        ]);
    }

    public function test_audit_exits_zero_when_environment_is_clean(): void
    {
        $this->withCleanEnv(function (): void {
            putenv('DW_MODE=service');
            $_ENV['DW_MODE'] = 'service';

            $this->artisan('env:audit')
                ->assertExitCode(0);
        });
    }

    public function test_audit_warns_on_unknown_dw_vars_but_returns_zero_without_strict(): void
    {
        $this->withCleanEnv(function (): void {
            putenv('DW_TYPO_VAR=1');
            $_ENV['DW_TYPO_VAR'] = '1';

            $this->artisan('env:audit')
                ->expectsOutputToContain('DW_TYPO_VAR')
                ->assertExitCode(0);
        });
    }

    public function test_audit_fails_with_strict_when_unknown_dw_vars_are_present(): void
    {
        $this->withCleanEnv(function (): void {
            putenv('DW_TYPO_VAR=1');
            $_ENV['DW_TYPO_VAR'] = '1';

            $this->artisan('env:audit --strict')
                ->expectsOutputToContain('DW_TYPO_VAR')
                ->assertExitCode(1);
        });
    }

    public function test_audit_warns_on_legacy_names_and_suggests_the_replacement(): void
    {
        $this->withCleanEnv(function (): void {
            putenv('WORKFLOW_SERVER_AUTH_DRIVER=token');
            $_ENV['WORKFLOW_SERVER_AUTH_DRIVER'] = 'token';

            $this->artisan('env:audit')
                ->expectsOutputToContain('rename to DW_AUTH_DRIVER')
                ->assertExitCode(0);
        });
    }

    public function test_audit_fails_with_strict_on_legacy_names(): void
    {
        $this->withCleanEnv(function (): void {
            putenv('WORKFLOW_SERVER_AUTH_DRIVER=token');
            $_ENV['WORKFLOW_SERVER_AUTH_DRIVER'] = 'token';

            $this->artisan('env:audit --strict')
                ->assertExitCode(1);
        });
    }

    /**
     * Drop any DW_* / WORKFLOW_* / ACTIVITY_* env vars leaking in from
     * phpunit.xml or the shell before running the callback, then restore.
     */
    private function withCleanEnv(callable $fn): void
    {
        $snapshot = [];

        foreach (array_merge(array_keys($_ENV), array_keys($_SERVER), array_keys(getenv() ?: [])) as $name) {
            if (! is_string($name)) {
                continue;
            }

            if (str_starts_with($name, 'DW_')
                || str_starts_with($name, 'WORKFLOW_')
                || str_starts_with($name, 'ACTIVITY_')
            ) {
                $snapshot[$name] = getenv($name);
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            }
        }

        try {
            $fn();
        } finally {
            foreach ($snapshot as $name => $value) {
                if ($value === false) {
                    continue;
                }

                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
            }
        }
    }
}
