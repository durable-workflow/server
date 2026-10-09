<?php

namespace App\Database;

use Closure;
use PDO;
use PDOException;

/** Inspect a crashed rollback-mode database without recovering its original. */
final class SqliteOwnershipRecovery
{
    private const FILES = ['probe.sqlite', 'probe.sqlite-journal', 'probe.sqlite-wal', 'probe.sqlite-shm'];

    public function withVerifiedOwnership(string $path, int $timeout, Closure $accepted): mixed
    {
        $directory = $this->directory($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new SqliteOwnershipUnknown('Cannot prepare SQLite ownership recovery. Check database-directory permissions.');
        }
        $this->assertPrivateDirectory($directory);
        $lock = $this->openLock($directory);
        $locked = false;
        try {
            $deadline = microtime(true) + max(0, $timeout) / 1000;
            while (! flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new SqliteOwnershipUnknown('SQLite ownership recovery is busy. Retry after the current PHP startup finishes.');
                }
                usleep(10000);
            }
            $locked = true;
            // A killed process releases flock. Never reuse its partial copy.
            $this->discardCopy($directory);
            // Another PHP role can have completed recovery while we waited.
            $original = new PDO('sqlite:file:'.str_replace('%2F', '/', rawurlencode($path)).'?mode=ro', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $healthy = false;
            try {
                $original->exec('PRAGMA busy_timeout = '.max(0, $timeout));
                PhpDatabaseOwnership::assertSqlite($original);

                $healthy = true;
            } catch (PDOException $exception) {
                if (((int) ($exception->errorInfo[1] ?? 0) & 255) !== 8 || ! is_file($path.'-journal')) {
                    throw $exception;
                }
            } finally {
                unset($original);
            }
            if ($healthy) {
                return $accepted();
            }
            $before = $this->identity($path);
            $bytes = $before[0]['size'] + $before[1]['size'];
            $available = disk_free_space($directory);
            if ($available === false || $available < $bytes + 64 * 1024 * 1024) {
                throw new SqliteOwnershipUnknown('Insufficient space for SQLite ownership recovery. Free the database plus journal size and 64 MiB of headroom.');
            }
            $copy = $directory.'/probe.sqlite';
            $this->copy($path, $copy, $before[0]);
            $this->copy($path.'-journal', $copy.'-journal', $before[1]);
            $this->assertUnchanged($path, $before);
            $this->assertNoSuperJournal($copy.'-journal');

            // SQLite itself performs rollback; no journal/page decoder is used.
            // No original filename or URI options reach this writable handle.
            $probe = new PDO('sqlite:'.$copy, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            try {
                PhpDatabaseOwnership::assertSqlite($probe);
                if ($probe->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
                    throw new SqliteOwnershipUnknown('SQLite recovery copy is inconsistent. Preserve the original and investigate its backup.');
                }
            } finally {
                unset($probe);
            }
            // Do not authorize a different database/journal generation. Rust
            // takeover still requires stopping every PHP write-capable role.
            $this->assertUnchanged($path, $before);

            return $accepted();
        } finally {
            // Only the lock holder owns these files. A timeout must not remove
            // a live probe belonging to another PHP role.
            try {
                if ($locked) {
                    $this->discardCopy($directory);
                }
            } finally {
                fclose($lock);
            }
        }
    }

    public function discardAbandoned(string $path): void
    {
        $directory = $this->directory($path);
        if (! is_dir($directory)) {
            return;
        }
        $this->assertPrivateDirectory($directory);
        $lock = $this->openLock($directory);
        try {
            if (flock($lock, LOCK_EX | LOCK_NB)) {
                $this->discardCopy($directory);
            }
        } finally {
            fclose($lock);
        }
    }

    private function directory(string $path): string
    {
        // The official Apache image can start as root and serve as www-data.
        // One role's private directory must not deny another role access to an
        // otherwise usable PHP database. Each UID owns and cleans its copies.
        if (function_exists('posix_geteuid')) {
            $uid = posix_geteuid();
        } else {
            $temporary = tmpfile();
            if ($temporary === false) {
                throw new SqliteOwnershipUnknown('Cannot identify the PHP runtime owner. Check temporary-directory permissions.');
            }
            try {
                $uid = fstat($temporary)['uid'];
            } finally {
                fclose($temporary);
            }
        }

        return dirname($path).'/.dw-php-ownership-'.hash('sha256', basename($path)).'-'.$uid;
    }

    private function assertPrivateDirectory(string $directory): void
    {
        clearstatcache(true, $directory);
        if (is_link($directory) || (@fileperms($directory) & 0777) !== 0700) {
            throw new SqliteOwnershipUnknown('SQLite ownership recovery directory is unsafe. Check its private permissions.');
        }
    }

    private function openLock(string $directory)
    {
        $path = $directory.'/lock';
        if (is_link($path) || ($lock = @fopen($path, 'c+b')) === false) {
            throw new SqliteOwnershipUnknown('Cannot lock SQLite ownership recovery. Check database-directory permissions.');
        }
        chmod($path, 0600);

        return $lock;
    }

    private function discardCopy(string $directory): void
    {
        foreach (self::FILES as $name) {
            $file = $directory.'/'.$name;
            if ((file_exists($file) || is_link($file)) && ! @unlink($file)) {
                throw new SqliteOwnershipUnknown('Cannot remove SQLite ownership recovery copy. Check its private permissions.');
            }
        }
    }

    private function identity(string $path): array
    {
        clearstatcache();
        // WAL and attached-database recovery need their own consistent backup
        // procedures; never improvise a partial snapshot of either cohort.
        if (file_exists($path.'-wal') || file_exists($path.'-shm')) {
            throw new SqliteOwnershipUnknown('Unexpected SQLite recovery sidecars. Preserve the complete database and journals for recovery.');
        }

        return [$this->fileIdentity($path), $this->fileIdentity($path.'-journal')];
    }

    private function fileIdentity(string $path): array
    {
        $stat = @stat($path);
        $hash = @hash_file('sha256', $path);
        if ($stat === false || $hash === false || ! is_file($path) || is_link($path)) {
            throw new SqliteOwnershipUnknown('Cannot identify SQLite recovery input. Check availability and permissions.');
        }

        return ['device' => $stat['dev'], 'inode' => $stat['ino'], 'size' => $stat['size'], 'hash' => $hash];
    }

    private function assertUnchanged(string $path, array $before): void
    {
        if ($this->identity($path) !== $before) {
            throw new SqliteOwnershipUnknown('SQLite recovery input changed. Stop competing write-capable roles and retry.');
        }
    }

    private function copy(string $source, string $destination, array $identity): void
    {
        if (! @copy($source, $destination)
            || filesize($destination) !== $identity['size']
            || hash_file('sha256', $destination) !== $identity['hash']) {
            throw new SqliteOwnershipUnknown('Cannot copy SQLite recovery input. Check free space and permissions.');
        }
        chmod($destination, 0600);
    }

    private function assertNoSuperJournal(string $path): void
    {
        // SQLite's readSuperJournal recognizes a footer ending in this magic.
        // Reject it conservatively before opening the copy: recovery must not
        // follow a filename outside our private directory or delete its target.
        // https://github.com/sqlite/sqlite/blob/master/src/pager.c
        $file = @fopen($path, 'rb');
        if ($file === false) {
            throw new SqliteOwnershipUnknown('Cannot read SQLite recovery journal. Check availability and permissions.');
        }
        try {
            if (fseek($file, -8, SEEK_END) !== 0
                || fread($file, 8) === "\xd9\xd5\x05\xf9\x20\xa1\x63\xd7") {
                throw new SqliteOwnershipUnknown('Unsupported SQLite recovery journal. Preserve the complete database cohort for recovery; do not remove its journals.');
            }
        } finally {
            fclose($file);
        }
    }
}
