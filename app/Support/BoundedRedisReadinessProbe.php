<?php

namespace App\Support;

use Illuminate\Support\ConfigurationUrlParser;
use JsonException;
use RuntimeException;

final class BoundedRedisReadinessProbe
{
    /** The complete readiness response must stay below the three-second probe timeout. */
    public const RESPONSE_BOUND_SECONDS = 2.0;

    /**
     * The longest supported path has six blocking phases: connect, AUTH,
     * SELECT, SETEX, GET, and DEL. Giving each phase 200 ms keeps their
     * cumulative transport budget at 1.2 seconds, leaving 800 ms for the
     * database and application portions of the two-second response contract.
     */
    private const TRANSPORT_TIMEOUT_SECONDS = 0.2;

    public function __construct(
        private readonly RedisReadinessProcess $process,
    ) {}

    public function roundTrip(string $key, string $value, int $ttlSeconds): string
    {
        [$connectionName, $configuration] = $this->configuration();
        $output = $this->process->run(serialize([
            'version' => 1,
            'connection_name' => $connectionName,
            'configuration' => $configuration,
            'key' => $this->cachePrefix().$key,
            'value' => $value,
            'ttl_seconds' => max(1, $ttlSeconds),
        ]));

        try {
            $result = json_decode($output, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Redis readiness child returned malformed output.', previous: $exception);
        }

        if (! is_array($result) || ($result['ok'] ?? null) !== true || ! is_string($result['value'] ?? null)) {
            throw new RuntimeException('Redis readiness child returned an invalid result.');
        }

        return $result['value'];
    }

    /**
     * Build a private Laravel Redis manager from the configured connection.
     * Laravel's URL parser and selected connector retain runtime endpoint,
     * credential, database, TLS, IPv6, serializer, and prefix behavior. Only
     * persistence, retry, and transport timing differ for readiness.
     *
     * @return array{string, array<string, mixed>}
     */
    private function configuration(): array
    {
        $store = config('cache.stores.redis');
        $connectionName = is_array($store) && is_string($store['connection'] ?? null)
            ? $store['connection']
            : 'cache';
        $configuration = config('database.redis');

        if (! is_array($configuration) || ! is_array($configuration[$connectionName] ?? null)) {
            throw new RuntimeException(sprintf(
                'Redis readiness connection [%s] is not configured.',
                $connectionName,
            ));
        }

        $connection = (new ConfigurationUrlParser)->parseConfiguration($configuration[$connectionName]);
        $transportOverrides = [
            'persistent' => false,
            'persistent_id' => null,
            'timeout' => self::TRANSPORT_TIMEOUT_SECONDS,
            'read_timeout' => self::TRANSPORT_TIMEOUT_SECONDS,
            'read_write_timeout' => self::TRANSPORT_TIMEOUT_SECONDS,
            'retry_interval' => 0,
            'max_retries' => 0,
        ];
        $client = (string) ($configuration['client'] ?? 'phpredis');
        $optionOverrides = $client === 'phpredis'
            ? $transportOverrides
            : [
                'persistent' => false,
                'timeout' => self::TRANSPORT_TIMEOUT_SECONDS,
            ];
        $connection = array_replace($connection, $transportOverrides);
        $connection['options'] = array_replace(
            is_array($connection['options'] ?? null) ? $connection['options'] : [],
            $optionOverrides,
        );
        $options = array_replace(
            is_array($configuration['options'] ?? null) ? $configuration['options'] : [],
            $optionOverrides,
        );

        // The private manager intentionally knows only the selected cache
        // connection. Cleanup therefore cannot resolve or contact an unrelated
        // runtime/default endpoint even if this code changes in the future.
        return [$connectionName, [
            'client' => $client,
            'options' => $options,
            $connectionName => $connection,
        ]];
    }

    private function cachePrefix(): string
    {
        $prefix = config('cache.prefix');

        return is_string($prefix) ? $prefix : '';
    }
}
