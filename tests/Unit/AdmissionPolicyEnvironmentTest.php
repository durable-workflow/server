<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdmissionPolicyEnvironmentTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function policyVariables(): iterable
    {
        yield 'queue admission' => ['DW_TASK_QUEUE_ADMISSION_OVERRIDES', 'admission.queue_overrides'];
        yield 'namespace admission' => ['DW_NAMESPACE_ADMISSION_OVERRIDES', 'namespace_admission.overrides'];
        yield 'durable limits' => ['DW_NAMESPACE_DURABLE_LIMITS', 'namespace_durable_state.limits'];
        yield 'durable hard limits' => ['DW_NAMESPACE_DURABLE_HARD_LIMITS', 'namespace_durable_state.hard_limits'];
        yield 'durable overrides' => ['DW_NAMESPACE_DURABLE_OVERRIDES', 'namespace_durable_state.overrides'];
        yield 'payload overrides' => ['DW_EXTERNAL_PAYLOAD_NAMESPACE_OVERRIDES', 'external_payload_transport.namespace_overrides'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidPolicyValues(): iterable
    {
        foreach (self::policyVariables() as $policy => [$name]) {
            foreach (['unfinished' => '{"private-policy":', 'null' => 'null', 'array' => '[]',
                'string' => '"private-policy"', 'number' => '0', 'boolean' => 'false', 'blank' => ''] as $kind => $value) {
                yield $policy.' / '.$kind => [$name, $value];
            }
        }
    }

    #[DataProvider('invalidPolicyValues')]
    public function test_invalid_primary_policy_refuses_without_printing_its_value(string $name, string $value): void
    {
        $this->withEnvironment([$name => $value], function () use ($name): void {
            try {
                require dirname(__DIR__, 2).'/config/server.php';
                self::fail('Invalid policy configuration was silently accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString($name, $exception->getMessage());
                self::assertStringNotContainsString('private-policy', $exception->getMessage());
                self::assertNull($exception->getPrevious());
            }
        });
    }

    #[DataProvider('policyVariables')]
    public function test_empty_objects_and_valid_primary_policies_keep_their_values(string $name, string $path): void
    {
        $legacy = str_replace('DW_', 'WORKFLOW_SERVER_', $name);
        $this->withEnvironment([$name => '{}', $legacy => '{invalid'], function () use ($path): void {
            $config = require dirname(__DIR__, 2).'/config/server.php';
            self::assertSame([], $this->atPath($config, $path));
        });
        $this->withEnvironment([$name => '{"default":{"max_requests_per_minute":1}}'], function () use ($path): void {
            $config = require dirname(__DIR__, 2).'/config/server.php';
            self::assertSame(['default' => ['max_requests_per_minute' => 1]], $this->atPath($config, $path));
        });
    }

    #[DataProvider('policyVariables')]
    public function test_omitted_policy_and_valid_legacy_policy_keep_existing_resolution(string $name, string $path): void
    {
        $legacy = str_replace('DW_', 'WORKFLOW_SERVER_', $name);
        $this->withEnvironment([$name => null, $legacy => null], function () use ($path): void {
            $config = require dirname(__DIR__, 2).'/config/server.php';
            self::assertSame([], $this->atPath($config, $path));
        });
        $this->withEnvironment([$name => null, $legacy => '{"payments":{"max_requests_per_minute":1}}'], function () use ($path): void {
            $config = require dirname(__DIR__, 2).'/config/server.php';
            self::assertSame(['payments' => ['max_requests_per_minute' => 1]], $this->atPath($config, $path));
        });
    }

    #[DataProvider('policyVariables')]
    public function test_invalid_primary_does_not_fall_back_to_legacy(string $name, string $path): void
    {
        $legacy = str_replace('DW_', 'WORKFLOW_SERVER_', $name);
        $this->withEnvironment([$name => 'null', $legacy => '{}'], function () use ($name): void {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage($name);
            require dirname(__DIR__, 2).'/config/server.php';
        });
    }

    #[DataProvider('policyVariables')]
    public function test_invalid_legacy_policy_names_the_selected_legacy_variable(string $name, string $path): void
    {
        $legacy = str_replace('DW_', 'WORKFLOW_SERVER_', $name);
        $this->withEnvironment([$name => null, $legacy => '{invalid'], function () use ($legacy): void {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage($legacy);
            require dirname(__DIR__, 2).'/config/server.php';
        });
    }

    /** @param array<string, string|null> $values */
    private function withEnvironment(array $values, callable $action): void
    {
        $application = Container::getInstance();
        new Application(dirname(__DIR__, 2));
        $previous = [];
        foreach ($values as $name => $value) {
            $previous[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            if ($value === null) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv($name.'='.$value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }

        try {
            $action();
        } finally {
            Container::setInstance($application);
            foreach ($previous as $name => [$process, $environment, $server]) {
                $process === false ? putenv($name) : putenv($name.'='.$process);
                if ($environment === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $environment;
                }
                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
            }
        }
    }

    /** @param array<string, mixed> $config */
    private function atPath(array $config, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            $config = $config[$key];
        }

        return $config;
    }
}
