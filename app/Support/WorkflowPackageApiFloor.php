<?php

namespace App\Support;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
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
     * Public instance methods the server depends on through package-registered
     * model observers.
     */
    private const REQUIRED_INSTANCE_APIS = [
        // Package-owned child namespace projection lets the server remove its
        // local WorkflowLink / WorkflowRunLineageEntry observer glue.
        [\Workflow\V2\Support\ChildWorkflowNamespaceProjection::class, 'projectLink'],
        [\Workflow\V2\Support\ChildWorkflowNamespaceProjection::class, 'projectLineageEntry'],
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
     * Method on {@see self::POLL_MODE_DEMOTION_CLASS} whose body is inspected
     * for the poll-mode demotion keywords. Kept as a constant so regression
     * tests can point the floor at fixture implementations.
     */
    private const POLL_MODE_DEMOTION_METHOD = 'queue';

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

        foreach (self::REQUIRED_INSTANCE_APIS as [$class, $method]) {
            if (! self::hasInstanceMethod($class, $method)) {
                $missing[] = sprintf('%s::%s()', $class, $method);
            }
        }

        if (! class_exists(self::POLL_MODE_DEMOTION_CLASS)) {
            $missing[] = self::POLL_MODE_DEMOTION_CLASS;
        } elseif (! self::confirmsPollModeDemotion(self::POLL_MODE_DEMOTION_CLASS, self::POLL_MODE_DEMOTION_METHOD)) {
            $missing[] = sprintf(
                '%s::%s() lacks poll-mode queue capability demotion',
                self::POLL_MODE_DEMOTION_CLASS,
                self::POLL_MODE_DEMOTION_METHOD,
            );
        }

        if ($missing === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            "Installed durable-workflow/workflow package is older than the server's API floor. "
            ."Missing: %s. Re-run `composer update durable-workflow/workflow` against a v2 snapshot that "
            .'includes CodecRegistry::universal(), CodecRegistry::engineSpecific(), and the '
            .'poll-mode queue capability demotion, plus ChildWorkflowNamespaceProjection for package-owned '
            .'child namespace propagation (see repos/workflow commits 8e132d0 and f666b25, or newer).',
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

    private static function hasInstanceMethod(string $class, string $method): bool
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

        return $methodReflection->isPublic() && ! $methodReflection->isStatic();
    }

    /**
     * Prove the installed BackendCapabilities::queue() contains the
     * poll-mode demotion logic from workflow@f666b25.
     *
     * A method-existence check is insufficient because `queue()` predates
     * the demotion. Instead, inspect the method's declared source and
     * require the three co-located keywords that exist only once the
     * demotion is in place: the config key `workflows.v2.task_dispatch_mode`
     * (read via `task_dispatch_mode`), the demoted severity `'info'`, and
     * the issue code `queue_sync_unsupported`. A stale package flagged the
     * two issue codes as `'error'` unconditionally and never referenced
     * `task_dispatch_mode`, so the three-way coincidence is specific to
     * the post-f666b25 snapshot.
     *
     * Source-level inspection is used instead of invoking `queue()` because
     * the method reads Laravel config at call time; the API floor runs in
     * service-provider boot where the config facade is available but the
     * broader container (cache store, DB connection) may not yet be ready,
     * and the existing call path threads `assert()` from boot — we do not
     * want to accidentally touch those services here.
     */
    private static function confirmsPollModeDemotion(string $class, string $method): bool
    {
        try {
            $reflection = new ReflectionMethod($class, $method);
        } catch (ReflectionException) {
            return false;
        }

        $file = $reflection->getFileName();
        if (! is_string($file) || ! is_readable($file)) {
            return false;
        }

        $lines = @file($file);
        if (! is_array($lines)) {
            return false;
        }

        $start = max(0, $reflection->getStartLine() - 1);
        $end = $reflection->getEndLine();
        $body = implode('', array_slice($lines, $start, max(0, $end - $start)));

        return str_contains($body, 'task_dispatch_mode')
            && str_contains($body, "'info'")
            && str_contains($body, 'queue_sync_unsupported');
    }
}
