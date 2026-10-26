<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Keep HTTP ingress's verified objects until its reference writer commits. */
final class RuntimeExternalPayloadWriteClaims
{
    private bool $active = false;

    private bool $committing = false;

    private int $preparing = 0;

    /** @var array<string, array<string, array<string, array{size_bytes: int, sha256: string, level: int}>>> */
    private array $verified = [];

    public function transport(Closure $operation): mixed
    {
        $previous = [$this->active, $this->verified];
        $this->active = true;
        $this->verified = [];
        try {
            return $operation();
        } finally {
            [$this->active, $this->verified] = $previous;
        }
    }

    /** Storage preparation may commit metadata without writing a reference. */
    public function prepare(Closure $operation): mixed
    {
        $this->preparing++;
        try {
            return $operation();
        } finally {
            $this->preparing--;
        }
    }

    public function verified(string $namespace, string $uri, int $sizeBytes, string $sha256): void
    {
        if ($this->active) {
            $connection = DB::connection();
            $name = $connection->getName();
            $this->verified[$name][$uri][$namespace] = [
                'size_bytes' => $sizeBytes, 'sha256' => $sha256,
                'level' => min($connection->transactionLevel(), $this->verified[$name][$uri][$namespace]['level'] ?? PHP_INT_MAX),
            ];
        }
    }

    public function beforeCommit(Connection $connection): void
    {
        $verified = $this->verified[$connection->getName()] ?? [];
        if (! $this->active || $this->preparing > 0 || $this->committing || $verified === []) {
            return;
        }

        // This runs before the actual outer commit, after the writer's run,
        // task and attempt locks. All objects are claimed in one order, and
        // those locks stay held until the references become visible. Storage
        // preparation's own metadata commits are deliberately excluded.
        $this->committing = true;
        try {
            $registry = app(RuntimeExternalPayloadRegistry::class);
            foreach (RuntimeExternalPayloadObjectLock::inLockOrder(array_keys($verified)) as $uri) {
                foreach ($verified[$uri] as $namespace => $bytes) {
                    $registry->claimVerified($namespace, $uri, $bytes['size_bytes'], $bytes['sha256']);
                }
            }
        } catch (Throwable $exception) {
            // Laravel decrements its transaction level on a commit-listener
            // exception but does not roll back a failed final commit attempt.
            // Roll back here, while its transaction and callbacks still exist.
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            throw $exception;
        } finally {
            $this->committing = false;
        }
    }

    public function afterCommit(Connection $connection): void
    {
        $name = $connection->getName();
        foreach ($this->verified[$name] ?? [] as $uri => $namespaces) {
            foreach ($namespaces as $namespace => $bytes) {
                // A released savepoint's registration now belongs to its
                // parent. A later sibling rollback must not discard it.
                $this->verified[$name][$uri][$namespace]['level'] = min($bytes['level'], $connection->transactionLevel());
            }
        }
    }

    public function afterRollback(Connection $connection): void
    {
        $name = $connection->getName();
        foreach ($this->verified[$name] ?? [] as $uri => $namespaces) {
            foreach ($namespaces as $namespace => $bytes) {
                if ($bytes['level'] > $connection->transactionLevel()) {
                    unset($this->verified[$name][$uri][$namespace]);
                }
            }
            if ($this->verified[$name][$uri] === []) {
                unset($this->verified[$name][$uri]);
            }
        }
    }
}
