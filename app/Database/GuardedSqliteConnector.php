<?php

namespace App\Database;

use Illuminate\Database\Connectors\SQLiteConnector;
use PDO;

final class GuardedSqliteConnector extends SQLiteConnector
{
    public function connect(array $config)
    {
        $this->assertOwnership($config);

        // The read-only probe precedes PDO's normal open and every pragma,
        // including user-configured pragmas and journal-mode changes.
        return parent::connect($config);
    }

    public function assertOwnership(array $config): void
    {
        $path = $this->parseDatabasePath($config['database']);
        if ($path !== ':memory:') {
            $probe = $this->createConnection($this->probeDsn($path), $config, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_PERSISTENT => false,
            ]);
            // A connection-local timeout is safe on a read-only handle. Keep
            // the existing lock budget rather than PDO's longer default wait.
            $probe->exec('PRAGMA busy_timeout = '.(int) ($config['busy_timeout'] ?? 5000));
            PhpDatabaseOwnership::assertSqlite($probe);
            unset($probe);
        }

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
