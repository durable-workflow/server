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

    public function test_clean_environment_scope_removes_variables_introduced_by_the_callback(): void
    {
        $name = 'WORKFLOW_ENV_AUDIT_SCOPE_TEST';
        $previous = getenv($name);
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);

        try {
            $this->withCleanEnv(function () use ($name): void {
                putenv("{$name}=temporary");
                $_ENV[$name] = 'temporary';
                $_SERVER[$name] = 'temporary';
            });

            $this->assertFalse(getenv($name));
            $this->assertArrayNotHasKey($name, $_ENV);
            $this->assertArrayNotHasKey($name, $_SERVER);
        } finally {
            if ($previous !== false) {
                putenv("{$name}={$previous}");
            }
        }
    }

    /**
     * Drop any DW_* / WORKFLOW_* / ACTIVITY_* env vars leaking in from
     * phpunit.xml or the shell before running the callback, then restore.
     */
    private function withCleanEnv(callable $fn): void
    {
        $snapshot = [];

        foreach ($this->contractEnvironmentNames() as $name) {
            $snapshot[$name] = [
                'process' => getenv($name),
                'env_exists' => array_key_exists($name, $_ENV),
                'env' => $_ENV[$name] ?? null,
                'server_exists' => array_key_exists($name, $_SERVER),
                'server' => $_SERVER[$name] ?? null,
            ];
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        try {
            $fn();
        } finally {
            foreach ($this->contractEnvironmentNames() as $name) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            }

            foreach ($snapshot as $name => $value) {
                if ($value['process'] !== false) {
                    putenv(sprintf('%s=%s', $name, $value['process']));
                }

                if ($value['env_exists']) {
                    $_ENV[$name] = $value['env'];
                }
                if ($value['server_exists']) {
                    $_SERVER[$name] = $value['server'];
                }
            }
        }
    }

    /** @return list<string> */
    private function contractEnvironmentNames(): array
    {
        $names = array_filter(
            array_merge(array_keys($_ENV), array_keys($_SERVER), array_keys(getenv() ?: [])),
            static fn (mixed $name): bool => is_string($name)
                && (str_starts_with($name, 'DW_')
                    || str_starts_with($name, 'WORKFLOW_')
                    || str_starts_with($name, 'ACTIVITY_')),
        );

        return array_values(array_unique($names));
    }
}
