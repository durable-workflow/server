<?php

namespace App\Database;

use PDO;
use Pdo\Mysql;

final class DatabaseTlsConfiguration
{
    public static function configure(array $config): array
    {
        $driver = $config['driver'] ?? null;
        if (! in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            return $config;
        }

        $mode = self::mode($config['sslmode'] ?? null);
        $alias = self::mode($config['sslmode_alias'] ?? null);
        $requested = self::mode($config['sslmode_requested'] ?? null);
        if ($alias !== null && $mode !== null && $alias !== $mode) {
            self::invalid('DB_SSLMODE and DB_SSL_MODE request different TLS modes.');
        }
        if ($requested !== null && $mode !== null && $requested !== $mode) {
            self::invalid('The database URL conflicts with DB_SSLMODE.');
        }
        $mode ??= $alias;
        $certificate = self::certificate($config);
        $config['sslmode'] = $mode;
        $config['sslrootcert'] = $certificate;

        if ($mode === null) {
            if ($certificate !== null) {
                self::invalid('Set DB_SSLMODE=verify-full with DB_SSLROOTCERT.');
            }
        } else {
            $config = self::applyMode($config, $mode, $certificate);
        }

        return $config;
    }

    private static function applyMode(array $config, string $mode, ?string $certificate): array
    {
        $driver = $config['driver'];
        $allowed = $driver === 'pgsql'
            ? ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full']
            : ['disable', 'require', 'verify-full'];
        if (! in_array($mode, $allowed, true)) {
            self::invalid('The selected database driver does not support the requested TLS mode. MySQL/MariaDB support disable, require and verify-full.');
        }

        if (in_array($mode, ['require', 'verify-ca', 'verify-full'], true)) {
            self::requireTcp($config);
        }
        if ($certificate === null && $driver !== 'pgsql' && $mode === 'verify-full') {
            $ca = PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA;
            $certificate = self::certificate(['sslrootcert' => $config['options'][$ca] ?? null]);
            $config['sslrootcert'] = $certificate;
        }
        if (in_array($mode, ['verify-ca', 'verify-full'], true) && $certificate === null) {
            self::invalid('Certificate verification requires a readable CA bundle in DB_SSLROOTCERT.');
        }

        if ($driver === 'pgsql') {
            return $config;
        }

        return self::mysqlOptions($config, $mode, $certificate);
    }

    public static function snapshot(array $config): array
    {
        $config = self::configure($config);
        $mode = $config['sslmode'] ?? null;
        $network = in_array($config['driver'] ?? null, ['pgsql', 'mysql', 'mariadb'], true);
        $required = in_array($mode, ['require', 'verify-ca', 'verify-full'], true);

        return [
            'mode' => $network ? $mode : null,
            'encrypted' => $required ? true : ($mode === 'disable' ? false : null),
            'ca_verified' => $required ? in_array($mode, ['verify-ca', 'verify-full'], true) : null,
            'hostname_verified' => $required ? $mode === 'verify-full' : null,
            'evidence' => $required ? 'successful_driver_enforced_connection' : 'not_verified',
            'scope' => 'php_to_configured_database_endpoint',
        ];
    }

    private static function mode(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            self::invalid('The database TLS mode must be a string.');
        }

        return match (strtolower(trim($value))) {
            'disabled' => 'disable',
            'preferred' => 'prefer',
            'required' => 'require',
            'verify_ca' => 'verify-ca',
            'verify_identity', 'verify-identity' => 'verify-full',
            default => strtolower(trim($value)),
        };
    }

    private static function certificate(array $config): ?string
    {
        $certificate = $config['sslrootcert'] ?? null;
        $alias = $config['sslrootcert_alias'] ?? null;
        $requested = $config['sslrootcert_requested'] ?? null;
        if ($alias !== null && $alias !== '' && $certificate !== null && $certificate !== $alias) {
            self::invalid('DB_SSLROOTCERT and DB_SSL_ROOT_CERT name different CA bundles.');
        }
        $certificate ??= $alias;
        if ($requested !== null && $requested !== '' && $certificate !== null && $certificate !== $requested) {
            self::invalid('The database URL conflicts with DB_SSLROOTCERT.');
        }
        if ($certificate === null || $certificate === '') {
            return null;
        }
        if (! is_string($certificate) || strpbrk($certificate, ";\r\n\0") !== false || ! is_file($certificate) || ! is_readable($certificate)) {
            self::invalid('The configured database CA bundle must be a readable regular file.');
        }

        return $certificate;
    }

    private static function requireTcp(array $config): void
    {
        $host = $config['host'] ?? '';
        if (! is_string($host) || trim($host) === '' || ($config['unix_socket'] ?? '') !== '') {
            self::invalid('Required database TLS needs an explicit TCP host, not a Unix socket.');
        }
        foreach (explode(',', $host) as $endpoint) {
            $endpoint = trim($endpoint);
            if (str_starts_with($endpoint, '/') || str_starts_with($endpoint, '@') || (in_array($config['driver'], ['mysql', 'mariadb'], true) && strtolower($endpoint) === 'localhost')) {
                self::invalid('Required database TLS needs an explicit TCP host, not a Unix socket.');
            }
        }
    }

    private static function mysqlOptions(array $config, string $mode, ?string $certificate): array
    {
        if (! extension_loaded('pdo_mysql') || ! extension_loaded('mysqlnd') || ! extension_loaded('openssl')) {
            self::invalid('Explicit MySQL/MariaDB TLS requires PDO MySQL with mysqlnd and OpenSSL.');
        }
        $ca = PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA;
        $cipher = PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CIPHER : PDO::MYSQL_ATTR_SSL_CIPHER;
        $verify = PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_VERIFY_SERVER_CERT : PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT;
        $options = $config['options'] ?? [];
        $legacyCertificate = $options[$ca] ?? null;
        if ($certificate !== null && $legacyCertificate !== null && $certificate !== $legacyCertificate) {
            self::invalid('DB_SSLROOTCERT and MYSQL_ATTR_SSL_CA name different CA bundles.');
        }
        if ($mode === 'disable') {
            if ($certificate !== null || $legacyCertificate !== null || isset($options[$cipher])) {
                self::invalid('Disabled database TLS conflicts with configured TLS connection options.');
            }

            return $config;
        }

        // A nonempty TLS option sets mysqlnd CLIENT_SSL before authentication.
        // mysqlnd refuses a server without TLS instead of retrying plaintext.
        $options[$cipher] ??= 'DEFAULT';
        $options[$verify] = $mode === 'verify-full';
        if ($certificate !== null) {
            $options[$ca] = $certificate;
        }
        $config['options'] = $options;

        return $config;
    }

    private static function invalid(string $message): never
    {
        throw new DatabaseTlsRefused('database_tls_configuration_invalid', $message);
    }
}
