<?php

namespace App\Database;

use Closure;
use Illuminate\Database\Connectors\SQLiteConnector;
use PDO;
use PDOException;

final class GuardedSqliteConnector extends SQLiteConnector
{
    public function connect(array $config)
    {
        return $this->withOwnership($config, function () use ($config): PDO {
            // The read-only probe precedes normal open and every pragma. After
            // a crash, keep the recovery lock until SQLite has recovered the
            // original using this normal, configured connection.
            $connection = parent::connect($config);
            PhpDatabaseOwnership::assertSqlite($connection);

            return $connection;
        });
    }

    public function assertOwnership(array $config): void
    {
        $this->withOwnership($config, static fn () => null);
    }

    private function withOwnership(array $config, Closure $accepted): mixed
    {
        $path = $this->parseDatabasePath($config['database']);
        if ($path !== ':memory:') {
            $this->prepareNewUri($path);
            $filename = $this->filename($path);
            if ($filename !== null) {
                (new SqliteOwnershipRecovery)->discardAbandoned($filename);
            }
            $probe = $this->createConnection($this->probeDsn($path), $config, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_PERSISTENT => false,
            ]);
            // A connection-local timeout is safe on a read-only handle. Keep
            // the existing lock budget rather than PDO's longer default wait.
            $probe->exec('PRAGMA busy_timeout = '.(int) ($config['busy_timeout'] ?? 5000));
            try {
                PhpDatabaseOwnership::assertSqlite($probe);
            } catch (PDOException $exception) {
                unset($probe);
                // A hot rollback journal must be recovered before any schema
                // read. Inspect a private recovered copy first; SQLITE_BUSY,
                // permissions, corruption and all other failures stay closed.
                if (((int) ($exception->errorInfo[1] ?? 0) & 255) !== 8
                    || $filename === null || ! is_file($filename.'-journal')
                    || $this->hasCustomVfs($path)) {
                    throw $exception;
                }

                return (new SqliteOwnershipRecovery)->withVerifiedOwnership(
                    $filename, (int) ($config['busy_timeout'] ?? 5000), $accepted,
                );
            } finally {
                unset($probe);
            }
        }

        return $accepted();
    }

    private function hasCustomVfs(string $path): bool
    {
        if (! str_starts_with($path, 'file:')) {
            return false;
        }
        parse_str(parse_url($path, PHP_URL_QUERY) ?: '', $options);

        return isset($options['vfs']);
    }

    private function prepareNewUri(string $path): void
    {
        if (! str_starts_with($path, 'file:')) {
            return;
        }
        $uri = parse_url($path);
        if ($uri === false || ! isset($uri['path']) || $uri['path'] === ''
            || isset($uri['user']) || isset($uri['pass']) || isset($uri['port'])
            || (isset($uri['host']) && $uri['host'] !== 'localhost')) {
            return;
        }
        parse_str($uri['query'] ?? '', $options);
        if (isset($options['vfs']) || isset($options['8_3_names'])
            || ($options['mode'] ?? 'rwc') !== 'rwc'
            || rawurldecode($uri['path']) === ':memory:') {
            return;
        }
        // Laravel permits file: URIs to create fresh databases. Reserve only a
        // genuinely new file, atomically; never truncate an existing owner.
        $filename = rawurldecode($uri['path']);
        if (! file_exists($filename) && ($file = @fopen($filename, 'xb')) !== false) {
            fclose($file);
        }
    }

    private function filename(string $path): ?string
    {
        if (! str_starts_with($path, 'file:')) {
            return $path;
        }
        [$file, $query] = array_pad(explode('?', explode('#', $path, 2)[0], 2), 2, '');
        parse_str($query, $options);
        if (($options['mode'] ?? null) === 'memory' || $file === 'file::memory:') {
            return null;
        }
        // Let SQLite identify URI paths. This also rejects unavailable/custom
        // VFS locations rather than interpreting them as ordinary local files.
        $connection = new PDO($this->probeDsn($path));
        $filename = $connection->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC)['file'] ?? '';

        return $filename === '' ? null : (realpath($filename) ?: null);
    }

    private function probeDsn(string $path): string
    {
        if (! str_starts_with($path, 'file:')) {
            return 'sqlite:file:'.str_replace('%2F', '/', rawurlencode($path)).'?mode=ro';
        }

        [$file, $query] = array_pad(explode('?', explode('#', $path, 2)[0], 2), 2, '');
        parse_str($query, $options);
        // Shared named memory databases have no filesystem to open read-only.
        if (($options['mode'] ?? null) === 'memory' || $file === 'file::memory:') {
            return 'sqlite:'.$path;
        }

        // Preserve URI filenames/options, but never ignore uncheckpointed WAL
        // using immutable/nolock and never accept a write-capable probe mode.
        unset($options['immutable'], $options['nolock']);
        $options['mode'] = 'ro';

        return 'sqlite:'.$file.'?'.http_build_query($options, '', '&', PHP_QUERY_RFC3986);
    }
}
