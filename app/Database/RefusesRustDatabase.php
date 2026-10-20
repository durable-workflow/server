<?php

namespace App\Database;

use PDO;
use Pdo\Mysql;

trait RefusesRustDatabase
{
    public function connect(array $config)
    {
        return parent::connect(DatabaseTlsConfiguration::configure($config));
    }

    public function createConnection($dsn, array $config, array $options)
    {
        $mysql = in_array($config['driver'], ['mysql', 'mariadb'], true);
        $initCommand = null;
        if ($mysql) {
            $attribute = PHP_VERSION_ID >= 80500 ? Mysql::ATTR_INIT_COMMAND : PDO::MYSQL_ATTR_INIT_COMMAND;
            $initCommand = $options[$attribute] ?? null;
            unset($options[$attribute]);
        }

        try {
            $connection = parent::createConnection($dsn, $config, $options);
        } catch (\PDOException $exception) {
            if (in_array($config['sslmode'] ?? null, ['require', 'verify-ca', 'verify-full'], true)) {
                throw new DatabaseTlsRefused('database_tls_connection_failed', 'The required database TLS connection could not be established. Check the endpoint, CA bundle, certificate hostname and credentials.', $exception);
            }

            throw $exception;
        }
        $errorMode = $connection->getAttribute(PDO::ATTR_ERRMODE);
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try {
            if ($mysql) {
                PhpDatabaseOwnership::assertMysql($connection);
            } else {
                PhpDatabaseOwnership::assertPostgres(
                    $connection, $this->parseSearchPath($config['search_path'] ?? $config['schema'] ?? null),
                );
            }
        } finally {
            $connection->setAttribute(PDO::ATTR_ERRMODE, $errorMode);
        }

        // PDO otherwise runs this command during construction, before a
        // connector can inspect ownership. Release it only after the fence.
        if ($initCommand !== null) {
            $connection->exec($initCommand);
        }

        return $connection;
    }
}
