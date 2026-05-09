<?php

namespace App\Support;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Contracts\MatchingRole;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Support\BackendCapabilities;
use Workflow\V2\Support\ChildWorkflowNamespaceProjection;
use Workflow\V2\Support\DefaultMatchingRole;
use Workflow\V2\Support\MatchingRoleSnapshot;
use Workflow\V2\Support\ServiceExecutionContract;
use Workflow\V2\Support\WorkerProtocolVersion;

/**
 * Enforces the minimum `durable-workflow/workflow` API surface the server
 * depends on at runtime.
 *
 * The server's composer constraint for the workflow package is a floating
 * `dev-v2` path/Git source. A stale build or cached install can resolve to
 * an older v2 snapshot that lacks APIs the server assumes are present,
 * producing hard-to-diagnose fatals on `/api/cluster/info` (missing
 * `CodecRegistry::universal()`), workflow-task polling regressions
 * (missing workflow-type filtering), or service-mode queue capability
 * failures (missing poll-mode queue demotion).
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
        [CodecRegistry::class, 'universal'],
        [CodecRegistry::class, 'engineSpecific'],
        // MatchingRoleSnapshot::current() — commit cfd8e95.
        // Cluster discovery now reuses the package-owned matching-role
        // contract instead of duplicating the routing fields in server code.
        [MatchingRoleSnapshot::class, 'current'],
        // ServiceExecutionContract::manifest() publishes the service-layer
        // execution contract that /api/cluster/info re-exports.
        [ServiceExecutionContract::class, 'manifest'],
        // Worker-session protocol contract: worker-plane capabilities and
        // cluster info re-export the package-owned runtime semantics.
        [WorkerProtocolVersion::class, 'workerSessionVerbs'],
        [WorkerProtocolVersion::class, 'workerSessionSemantics'],
    ];

    /**
     * Public instance methods the server depends on through package-registered
     * model observers.
     */
    private const REQUIRED_INSTANCE_APIS = [
        // Package-owned child namespace projection lets the server remove its
        // local WorkflowLink / WorkflowRunLineageEntry observer glue.
        [ChildWorkflowNamespaceProjection::class, 'projectLink'],
        [ChildWorkflowNamespaceProjection::class, 'projectLineageEntry'],
        // Server control-plane repair passes resolve through the package's
        // dedicated matching-role implementation instead of hard-coding the
        // in-process watchdog.
        [DefaultMatchingRole::class, 'wake'],
        [DefaultMatchingRole::class, 'runPass'],
    ];

    /**
     * Interface methods the server type-hints directly.
     */
    private const REQUIRED_INTERFACE_APIS = [
        [MatchingRole::class, 'wake'],
        [MatchingRole::class, 'runPass'],
        [ServiceControlPlane::class, 'execute'],
        [ServiceControlPlane::class, 'describeCall'],
        [ServiceControlPlane::class, 'cancelCall'],
    ];

    /**
     * Workflow-task polling contract — commit a1d442d. The bridge must
     * accept the workflow-type filter parameter; the server's API floor
     * asserts that signature at boot. Beyond the signature, dispatch
     * also runs a server-owned safety net: WorkflowTaskPoller and
     * ActivityTaskPoller fall back to a typed app-side join when the
     * bridge poll surfaces no candidate and the worker registered a
     * non-empty supportedWorkflowTypes / supportedActivityTypes list.
     * The fallback only identifies candidates and reuses the bridge
     * for the claim transaction, so the bridge stays authoritative for
     * leasing while a polyglot two-worker queue keeps moving even if
     * the bridge's predicate shape ever drifts under it.
     */
    private const WORKFLOW_TASK_POLL_CLASS = WorkflowTaskBridge::class;

    private const WORKFLOW_TASK_POLL_METHOD = 'poll';

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
    public const POLL_MODE_DEMOTION_CLASS = BackendCapabilities::class;

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

        foreach (self::REQUIRED_INTERFACE_APIS as [$class, $method]) {
            if (! self::hasInterfaceMethod($class, $method)) {
                $missing[] = sprintf('%s::%s()', $class, $method);
            }
        }

        if (! self::confirmsWorkflowTaskPollSignature(self::WORKFLOW_TASK_POLL_CLASS, self::WORKFLOW_TASK_POLL_METHOD)) {
            $missing[] = sprintf(
                '%s::%s() with workflow-type filtering',
                self::WORKFLOW_TASK_POLL_CLASS,
                self::WORKFLOW_TASK_POLL_METHOD,
            );
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
            .'Missing: %s. Re-run `composer update durable-workflow/workflow` against a v2 snapshot that '
            .'includes CodecRegistry::universal(), CodecRegistry::engineSpecific(), MatchingRoleSnapshot::current(), '
            .'the filtered WorkflowTaskBridge::poll() contract, '
            .'the poll-mode queue capability demotion, the matching-role repair-pass contract, '
            .'the service execution control-plane contract, the worker-session protocol contract, plus '
            .'ChildWorkflowNamespaceProjection for package-owned child namespace propagation '
            .'(install a current v2 workflow package snapshot or newer).',
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

    private static function hasInterfaceMethod(string $class, string $method): bool
    {
        if (! interface_exists($class)) {
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

    private static function confirmsWorkflowTaskPollSignature(string $class, string $method): bool
    {
        if (! interface_exists($class) && ! class_exists($class)) {
            return false;
        }

        try {
            $reflection = new ReflectionMethod($class, $method);
        } catch (ReflectionException) {
            return false;
        }

        if (! $reflection->isPublic() || $reflection->isStatic()) {
            return false;
        }

        $returnType = $reflection->getReturnType();
        if (! $returnType instanceof \ReflectionNamedType || $returnType->allowsNull() || $returnType->getName() !== 'array') {
            return false;
        }

        $parameters = $reflection->getParameters();

        if (count($parameters) !== 6) {
            return false;
        }

        return self::matchesParameter($parameters[0], 'connection', 'string', true, false, null)
            && self::matchesParameter($parameters[1], 'queue', 'string', true, false, null)
            && self::matchesParameter($parameters[2], 'limit', 'int', false, true, 1)
            && self::matchesParameter($parameters[3], 'compatibility', 'string', true, true, null)
            && self::matchesParameter($parameters[4], 'namespace', 'string', true, true, null)
            && self::matchesParameter($parameters[5], 'workflowTypes', 'array', false, true, []);
    }

    private static function matchesParameter(
        \ReflectionParameter $parameter,
        string $name,
        string $type,
        bool $allowsNull,
        bool $hasDefault,
        mixed $default,
    ): bool {
        $parameterType = $parameter->getType();

        if (! $parameterType instanceof \ReflectionNamedType) {
            return false;
        }

        if ($parameter->getName() !== $name
            || $parameterType->getName() !== $type
            || $parameterType->allowsNull() !== $allowsNull
            || $parameter->isDefaultValueAvailable() !== $hasDefault) {
            return false;
        }

        if (! $hasDefault) {
            return true;
        }

        return $parameter->getDefaultValue() === $default;
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
