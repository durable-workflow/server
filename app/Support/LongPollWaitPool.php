<?php

namespace App\Support;

use DirectoryIterator;

final class LongPollWaitPool
{
    public function tryAcquire(
        string $directory,
        ?int $globalLimit,
        ?string $namespace,
        ?int $namespaceLimit,
        int $waitSeconds,
    ): ?LongPollWaitSlot {
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new LongPollWaitStorageUnavailable('Cannot create the node-local long-poll reservation directory.');
        }

        $coordinator = $this->open($directory.'/admission.lock');
        $candidate = null;

        try {
            // All admissions inspect one coherent node-local namespace budget.
            // Never occupy a PHP request worker waiting for this short mutex.
            if (! flock($coordinator, LOCK_EX | LOCK_NB)) {
                return null;
            }

            [$candidate, $held, $namespaceHeld] = $this->inspect($directory, $namespace);

            if (($globalLimit !== null && $held >= $globalLimit)
                || ($namespaceLimit !== null && $namespaceHeld >= $namespaceLimit)) {
                return null;
            }

            if ($candidate === null) {
                $candidate = $this->open($directory.'/slot-'.bin2hex(random_bytes(16)).'.lock');

                if (! flock($candidate, LOCK_EX | LOCK_NB)) {
                    throw new LongPollWaitStorageUnavailable('Cannot own the new long-poll reservation.');
                }
            }

            $metadata = json_encode([
                'namespace' => $namespace,
                'process_id' => getmypid(),
                'admitted_at' => microtime(true),
                'wait_seconds' => max(0, $waitSeconds),
            ], JSON_THROW_ON_ERROR);
            rewind($candidate);

            if (! ftruncate($candidate, 0)
                || fwrite($candidate, $metadata) !== strlen($metadata)
                || ! fflush($candidate)) {
                throw new LongPollWaitStorageUnavailable('Cannot record the long-poll reservation.');
            }

            $slot = LongPollWaitSlot::acquired($candidate);
            $candidate = null;

            return $slot;
        } finally {
            if (is_resource($candidate)) {
                fclose($candidate);
            }
            flock($coordinator, LOCK_UN);
            fclose($coordinator);
        }
    }

    /** @return array{resource|null, int, int} */
    private function inspect(string $directory, ?string $namespace): array
    {
        $candidate = null;
        $held = 0;
        $namespaceHeld = 0;

        try {
            foreach ($this->paths($directory) as $path) {
                $handle = $this->open($path);

                if (! flock($handle, LOCK_EX | LOCK_NB)) {
                    $held++;
                    $namespaceHeld += $this->belongsToNamespace($handle, $namespace) ? 1 : 0;
                    fclose($handle);

                    continue;
                }

                if ($candidate === null) {
                    $candidate = $handle;

                    continue;
                }

                fclose($handle);

                // Only admissions open files, under admission.lock.
                // No blocked contender can retain an unlinked inode.
                if (! unlink($path)) {
                    throw new LongPollWaitStorageUnavailable('Cannot retire an unused long-poll reservation.');
                }
            }

            return [$candidate, $held, $namespaceHeld];
        } catch (\Throwable $exception) {
            if (is_resource($candidate)) {
                fclose($candidate);
            }

            throw $exception;
        }
    }

    /** @return list<string> */
    private function paths(string $directory): array
    {
        // DirectoryIterator fails closed when the directory cannot be listed.
        // Snapshot names before retiring idle files during admission.
        $paths = [];

        foreach (new DirectoryIterator($directory) as $entry) {
            if (str_starts_with($entry->getFilename(), 'slot-') && str_ends_with($entry->getFilename(), '.lock')) {
                $paths[] = $entry->getPathname();
            }
        }

        return $paths;
    }

    /** @param resource $handle */
    private function belongsToNamespace(mixed $handle, ?string $namespace): bool
    {
        $metadata = json_decode(stream_get_contents($handle, 4096), true);

        // Unknown live metadata cannot grant another namespace slot.
        return ! is_array($metadata) || ! array_key_exists('namespace', $metadata)
            || (! is_string($metadata['namespace']) && $metadata['namespace'] !== null)
            || $metadata['namespace'] === $namespace;
    }

    /** @return resource */
    private function open(string $path): mixed
    {
        // Prevent exec'd children from extending a request's lock lifetime.
        $mode = PHP_OS_FAMILY === 'Windows' ? 'c+b' : 'c+be';
        $handle = @fopen($path, $mode);

        if ($handle === false) {
            throw new LongPollWaitStorageUnavailable('Cannot open the node-local long-poll reservation.');
        }

        return $handle;
    }
}
