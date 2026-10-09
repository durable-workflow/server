<?php

namespace App\Database;

use PDO;
use PDOException;

final class PhpDatabaseOwnership
{
    public const MARKER_TABLE = 'dw_server_schema';

    public static function assertSqlite(PDO $connection): void
    {
        if ($connection->query("SELECT 1 FROM sqlite_schema WHERE name = 'dw_server_schema' COLLATE NOCASE LIMIT 1")->fetchColumn() !== false) {
            throw new PhpDatabaseRefused;
        }
    }

    public static function assertMysql(PDO $connection): void
    {
        // Visible markers are ownership fences even when they are malformed
        // views or contain no rows; do not execute their definitions.
        if ($connection->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dw_server_schema' LIMIT 1")->fetchColumn() !== false) {
            throw new PhpDatabaseRefused;
        }

        // Probe the relation directly: information_schema can hide a marker
        // from a user who has workflow-table privileges but cannot read it.
        try {
            $connection->query('SELECT 1 FROM `dw_server_schema` LIMIT 0');
        } catch (PDOException $exception) {
            if (($exception->errorInfo[0] ?? null) === '42S02'
                && (int) ($exception->errorInfo[1] ?? 0) === 1146) {
                return;
            }

            throw $exception;
        }

        throw new PhpDatabaseRefused;
    }

    /** @param list<string> $schemas */
    public static function assertPostgres(PDO $connection, array $schemas): void
    {
        // Inspect the catalog before Laravel sets search_path. Include both
        // the connection's actual path and every configured target schema.
        $schemas = array_map(static fn (string $schema): string => $schema === '$user'
            ? (string) $connection->query('SELECT current_user')->fetchColumn()
            : $schema, $schemas);
        $extra = $schemas === [] ? '' : ' OR n.nspname IN ('.implode(', ', array_fill(0, count($schemas), '?')).')';
        $query = $connection->prepare(
            'SELECT 1 FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace '
            .'WHERE c.relname = ? AND (n.nspname = ANY(current_schemas(false))'.$extra.') LIMIT 1',
        );
        $query->execute([self::MARKER_TABLE, ...$schemas]);
        if ($query->fetchColumn() !== false) {
            throw new PhpDatabaseRefused;
        }
    }
}
