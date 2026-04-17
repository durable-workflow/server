<?php

namespace App\Support;

use ReflectionClass;
use ReflectionException;
use RuntimeException;

/**
 * Enforces the minimum `durable-workflow/workflow` API surface the server
 * depends on at runtime.
 *
 * The server's composer constraint for the workflow package is a floating
 * `dev-v2` path/Git source. A stale build or cached install can resolve to
 * an older v2 snapshot that lacks APIs the server assumes are present,
 * producing hard-to-diagnose fatals on `/api/cluster/info` (missing
 * `CodecRegistry::universal()`) or service-mode queue capability failures
 * (missing poll-mode queue demotion).
 *
 * Rather than fail at first request, assert the floor at boot so broken
 * installs surface a clear diagnostic during `php artisan package:discover`
 * or the first Laravel request.
 *
 * @see https://github.com/zorporation/durable-workflow/issues/346
 */
final class WorkflowPackageApiFloor
{
    /**
     * Each entry is `[FQCN, method]` — the method must be public and static,
     * and must be declared (or inherited) on the class. Missing entries
     * produce a single aggregated diagnostic listing every shortfall plus a
     * remediation hint that points at the upgrade.
     */
    private const REQUIRED_APIS = [
        // CodecRegistry::universal() and engineSpecific() — commit 8e132d0.
        // Polyglot codec split used by /api/cluster/info and the embedded
        // control-plane request contract.
        [\Workflow\Serializers\CodecRegistry::class, 'universal'],
        [\Workflow\Serializers\CodecRegistry::class, 'engineSpecific'],
    ];

    /**
     * Poll-mode queue capability demotion — commit f666b25. Detected
     * functionally because it is expressed as data in
     * BackendCapabilities::snapshot(), not a new method signature.
     *
     * Older v2 snapshots flag `queue_sync_unsupported` / `queue_connection_missing`
     * as hard 'error' regardless of dispatch mode; the API floor requires
     * that poll mode downgrades those to 'info' so the server can run on a
     * sync/missing queue driver without being reported as unsupported.
     */
    public const POLL_MODE_DEMOTION_CLASS = \Workflow\V2\Support\BackendCapabilities::class;

    /**
     * Assert every required API is present. Throws with a single
     * aggregated diagnostic when the installed workflow package is too old.
     */
    public static function assert(): void
    {
        $missing = [];

        foreach (self::REQUIRED_APIS as [$class, $method]) {
            if (! self::hasStaticMethod($class, $method)) {
                $missing[] = sprintf('%s::%s()', $class, $method);
            }
        }

        if (! class_exists(self::POLL_MODE_DEMOTION_CLASS)) {
            $missing[] = self::POLL_MODE_DEMOTION_CLASS;
        }

        if ($missing === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            "Installed durable-workflow/workflow package is older than the server's API floor. "
            ."Missing: %s. Re-run `composer update durable-workflow/workflow` against a v2 snapshot that "
            .'includes CodecRegistry::universal(), CodecRegistry::engineSpecific(), and the '
            .'poll-mode queue capability demotion (see repos/workflow commits 8e132d0 and f666b25).',
            implode(', ', $missing),
        ));
    }

    private static function hasStaticMethod(string $class, string $method): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($class);
            $methodReflection = $reflection->getMethod($method);
        } catch (ReflectionException) {
            return false;
        }

        return $methodReflection->isPublic() && $methodReflection->isStatic();
    }
}
