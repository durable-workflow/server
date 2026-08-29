<?php

namespace App\Support;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;
use Workflow\V2\Contracts\MatchingRole;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Exceptions\ExternalPayloadIntegrityException;
use Workflow\V2\Exceptions\WorkflowOutputCodecUnavailableException;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Support\BackendCapabilities;
use Workflow\V2\Support\ChildWorkflowNamespaceProjection;
use Workflow\V2\Support\DefaultMatchingRole;
use Workflow\V2\Support\DefaultWorkflowControlPlane;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\LocalFilesystemExternalPayloadStorage;
use Workflow\V2\Support\MatchingRoleSnapshot;
use Workflow\V2\Support\PayloadEnvelopeResolver;
use Workflow\V2\Support\RunCommandContract;
use Workflow\V2\Support\ServiceExecutionContract;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Support\WorkerHistoryPayloadContract;
use Workflow\V2\Support\WorkerProtocolVersion;
use Workflow\V2\Support\WorkflowCommandNormalizer;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\Support\WorkflowQueryContract;
use Workflow\V2\Support\WorkflowTaskLease;

/**
 * Enforces the minimum `durable-workflow/workflow` API surface the server
 * depends on at runtime.
 *
 * The server's composer constraint pins the workflow package release that
 * owns its current bridge contract. A stale build or cached install can still
 * expose an older v2 snapshot that lacks APIs the server assumes are present,
 * producing hard-to-diagnose fatals on `/api/cluster/info` (missing
 * `CodecRegistry::universal()`), typed task polling regressions
 * (missing workflow/activity type filtering), or service-mode queue
 * capability failures (missing poll-mode queue demotion).
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
        // CodecRegistry::universal() is the public Avro-only codec authority
        // used by /api/cluster/info and the embedded control-plane request
        // contract.
        [CodecRegistry::class, 'universal'],
        // MatchingRoleSnapshot::current() — commit cfd8e95.
        // Cluster discovery now reuses the package-owned matching-role
        // contract instead of duplicating the routing fields in server code.
        [MatchingRoleSnapshot::class, 'current'],
        // WorkflowTaskLease is the package-owned authority for every
        // workflow-task claim and renewal duration in standalone mode.
        [WorkflowTaskLease::class, 'seconds'],
        [WorkflowTaskLease::class, 'expiresAt'],
        // ServiceExecutionContract::manifest() publishes the service-layer
        // execution contract that /api/cluster/info re-exports.
        [ServiceExecutionContract::class, 'manifest'],
        // Worker-session protocol contract: worker-plane capabilities and
        // cluster info re-export the package-owned runtime semantics.
        [WorkerProtocolVersion::class, 'workerSessionVerbs'],
        [WorkerProtocolVersion::class, 'workerSessionSemantics'],
        // Portable worker-affinity discovery consumes the exact package-owned
        // local-activity and sticky-execution contracts without prerelease
        // feature detection or Server-owned placeholder semantics.
        [WorkerProtocolVersion::class, 'localActivitySemantics'],
        [WorkerProtocolVersion::class, 'describe'],
        // Worker protocol 1.7 query-task contract: PHP workers at this
        // protocol level advertise query capability and poll/complete/fail
        // query work through the standalone worker-plane routes with
        // idempotent poll request IDs.
        [WorkerProtocolVersion::class, 'queryTaskVerbs'],
        [WorkerProtocolVersion::class, 'workerCapabilities'],
        [WorkerProtocolVersion::class, 'queryTaskSemantics'],
        // Protocol 1.14 workflow memo command/history contract. The server
        // advertises this package-owned shape and applies it at completion.
        [WorkerProtocolVersion::class, 'upsertMemoCommandShape'],
        // Complete bounded worker-history budget contract. The standalone
        // server consumes this shape directly from full and paginated bridge
        // responses and advertises the same manifest in discovery.
        [WorkerHistoryPayloadContract::class, 'manifest'],
        // External payload storage protocol: server controllers and
        // envelope services depend on the package-owned wire helpers.
        [PayloadEnvelopeResolver::class, 'resolve'],
        [PayloadEnvelopeResolver::class, 'resolveToArray'],
        [PayloadEnvelopeResolver::class, 'resolveCommandPayload'],
        [PayloadEnvelopeResolver::class, 'resolveCommandPayloadWithCodec'],
        [ExternalPayloads::class, 'externalizeForNamespace'],
        [ExternalPayloads::class, 'isStoredReference'],
        [ExternalPayloads::class, 'wireEnvelope'],
        [ExternalPayloads::class, 'encodeStoredEnvelope'],
        [ExternalPayloads::class, 'historyValue'],
        [ExternalPayloads::class, 'storedEnvelope'],
        // Durable command-contract APIs used by server-side signal/query
        // validation for external workers.
        [RunCommandContract::class, 'forRun'],
        [RunCommandContract::class, 'signalContract'],
        [TypeRegistry::class, 'resolveWorkflowClass'],
        [WorkflowDefinition::class, 'hasSignal'],
        [WorkflowDefinition::class, 'signalContract'],
        [WorkflowQueryContract::class, 'resolveTargetForRun'],
        [WorkflowQueryContract::class, 'validatedArgumentsForRun'],
    ];

    /**
     * Public constants the server embeds in HTTP/control-plane payloads.
     */
    private const REQUIRED_CLASS_CONSTANTS = [
        [RunCommandContract::class, 'SOURCE_DURABLE_HISTORY'],
        [RunCommandContract::class, 'SOURCE_UNAVAILABLE'],
        [WorkerProtocolVersion::class, 'CAPABILITY_QUERY_TASKS'],
        [WorkflowCommandNormalizer::class, 'MAX_LOCAL_ACTIVITY_ATTEMPTS'],
        [WorkflowCommandNormalizer::class, 'MAX_LOCAL_ACTIVITY_HEARTBEATS_PER_ATTEMPT'],
        [WorkflowCommandNormalizer::class, 'MAX_LOCAL_ACTIVITY_HEARTBEATS'],
        [WorkerProtocolVersion::class, 'LOCAL_ACTIVITY_ATTEMPT_REPORTS_MINIMUM_PROTOCOL_VERSION'],
        [ExternalPayloadReference::class, 'SCHEMA'],
        [ExternalPayloads::class, 'STORED_REFERENCE_PREFIX'],
        [WorkflowSearchAttribute::class, 'MAX_KEYWORD_LENGTH'],
        [WorkflowSearchAttribute::class, 'TYPE_STRING'],
        [WorkflowSearchAttribute::class, 'TYPE_FLOAT'],
        [WorkflowSearchAttribute::class, 'TYPE_KEYWORD_LIST'],
        [WorkflowTaskLease::class, 'CONFIG_KEY'],
        [WorkflowTaskLease::class, 'DEFAULT_SECONDS'],
    ];

    /**
     * Workflow package protocol contract required by this server.
     */
    private const MINIMUM_WORKFLOW_PACKAGE_WORKER_PROTOCOL_VERSION = WorkerProtocol::VERSION;

    /**
     * Concrete classes the server instantiates or catches directly.
     */
    private const REQUIRED_CLASSES = [
        ExternalPayloadIntegrityException::class,
        LocalFilesystemExternalPayloadStorage::class,
        WorkflowOutputCodecUnavailableException::class,
    ];

    /**
     * Public instance methods the server depends on at runtime.
     */
    private const REQUIRED_INSTANCE_APIS = [
        // Terminal reads use the run-row output codec projection. These APIs
        // must remain independent of completion-history scans.
        [WorkflowRun::class, 'outputEnvelope'],
        [WorkflowRun::class, 'outputPayloadCodec'],
        [WorkflowRun::class, 'workflowOutput'],
        // Package-owned child namespace projection lets the server remove its
        // local WorkflowLink / WorkflowRunLineageEntry observer glue.
        [ChildWorkflowNamespaceProjection::class, 'projectLink'],
        [ChildWorkflowNamespaceProjection::class, 'projectLineageEntry'],
        // Server control-plane repair passes resolve through the package's
        // dedicated matching-role implementation instead of hard-coding the
        // in-process watchdog.
        [DefaultMatchingRole::class, 'wake'],
        [DefaultMatchingRole::class, 'runPass'],
        // Runtime-reserved signal delivery is consumed through the server's
        // narrow internal contract, not the host-decoratable public interface.
        [DefaultWorkflowControlPlane::class, 'runtimeSignal'],
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
        [ExternalPayloadStorageDriver::class, 'put'],
        [ExternalPayloadStorageDriver::class, 'get'],
        [ExternalPayloadStorageDriver::class, 'delete'],
        [ExternalPayloadStoragePolicy::class, 'driverFor'],
        [ExternalPayloadStoragePolicy::class, 'thresholdBytesFor'],
    ];

    /**
     * Typed poll contracts. The package bridges own ready-task
     * discovery, including workflow_type/activity_type predicates for
     * shared queues; server pollers intentionally do not duplicate
     * those SQL predicates. Assert the signatures at boot so a stale
     * workflow package cannot silently reintroduce broad polling.
     */
    private const WORKFLOW_TASK_POLL_CLASS = WorkflowTaskBridge::class;

    private const WORKFLOW_TASK_POLL_METHOD = 'poll';

    private const ACTIVITY_TASK_POLL_CLASS = ActivityTaskBridge::class;

    private const ACTIVITY_TASK_POLL_METHOD = 'poll';

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
     * Assert every required API is present. Throws with a single aggregated
     * diagnostic when the installed workflow package does not match the
     * server-owned worker protocol surface.
     */
    public static function assert(): void
    {
        $missing = [];

        if (! self::hasMinimumWorkerProtocolVersion(self::MINIMUM_WORKFLOW_PACKAGE_WORKER_PROTOCOL_VERSION)) {
            $installed = self::installedWorkerProtocolVersion();
            $missing[] = sprintf(
                '%s::VERSION >= %s%s',
                WorkerProtocolVersion::class,
                self::MINIMUM_WORKFLOW_PACKAGE_WORKER_PROTOCOL_VERSION,
                $installed === null ? '' : sprintf(' (installed %s)', $installed),
            );
        }

        foreach (self::REQUIRED_APIS as [$class, $method]) {
            if (! self::hasStaticMethod($class, $method)) {
                $missing[] = sprintf('%s::%s()', $class, $method);
            }
        }

        foreach (self::REQUIRED_CLASS_CONSTANTS as [$class, $constant]) {
            if (! self::hasPublicConstant($class, $constant)) {
                $missing[] = sprintf('%s::%s', $class, $constant);
            }
        }

        foreach (self::REQUIRED_CLASSES as $class) {
            if (! class_exists($class)) {
                $missing[] = $class;
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

        if (! self::confirmsActivityTaskPollSignature(self::ACTIVITY_TASK_POLL_CLASS, self::ACTIVITY_TASK_POLL_METHOD)) {
            $missing[] = sprintf(
                '%s::%s() with activity-type filtering',
                self::ACTIVITY_TASK_POLL_CLASS,
                self::ACTIVITY_TASK_POLL_METHOD,
            );
        }

        if (! self::confirmsPayloadEnvelopeResolverSignature()) {
            $missing[] = PayloadEnvelopeResolver::class.' external-storage envelope signatures';
        }

        if (! self::confirmsExternalPayloadsSignature()) {
            $missing[] = ExternalPayloads::class.' external payload helper signatures';
        }

        if (! self::confirmsExternalPayloadStorageInterfaces()) {
            $missing[] = 'external payload storage driver/policy signatures';
        }

        if (! self::confirmsWorkflowCommandNormalizerPayloadEnvelopeContract()) {
            $missing[] = WorkflowCommandNormalizer::class.' command ingress contracts';
        }

        if (! self::confirmsRunCommandContractSignature()) {
            $missing[] = RunCommandContract::class.' run command-contract signatures';
        }

        if (! self::confirmsSignalPreviewValidationSignatures()) {
            $missing[] = 'signal dry-run preview validation signatures';
        }

        if (! self::confirmsWorkflowQueryContractSignature()) {
            $missing[] = WorkflowQueryContract::class.' workflow query-contract signatures';
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
            "Installed durable-workflow/workflow package does not match the server's API floor. "
            .'Missing or incompatible: %s. Re-run `composer update durable-workflow/workflow` '
            .'against a v2 snapshot that '
            .'advertises worker protocol %s or newer and '
            .'includes CodecRegistry::universal(), MatchingRoleSnapshot::current(), '
            .'WorkflowTaskLease::seconds(), WorkflowTaskLease::expiresAt(), '
            .'the filtered WorkflowTaskBridge::poll() and ActivityTaskBridge::poll() contracts, '
            .'the poll-mode queue capability demotion, the matching-role repair-pass contract, '
            .'the service execution control-plane contract, the worker-session protocol contract, '
            .'the external payload storage protocol APIs, the command payload-envelope contract, '
            .'the external command/query contract APIs, the signal dry-run preview validation APIs, '
            .'the typed search-attribute storage '
            .'constants, plus ChildWorkflowNamespaceProjection for package-owned child namespace propagation '
            .'(install the v2 workflow package snapshot that matches this server release).',
            implode(', ', $missing),
            self::MINIMUM_WORKFLOW_PACKAGE_WORKER_PROTOCOL_VERSION,
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

    private static function hasPublicConstant(string $class, string $constant): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($class);
            $constantReflection = $reflection->getReflectionConstant($constant);
        } catch (ReflectionException) {
            return false;
        }

        return $constantReflection !== false && $constantReflection->isPublic();
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
        return self::confirmsTypedPollSignature($class, $method, 'workflowTypes');
    }

    private static function confirmsActivityTaskPollSignature(string $class, string $method): bool
    {
        return self::confirmsTypedPollSignature($class, $method, 'activityTypes');
    }

    private static function confirmsTypedPollSignature(string $class, string $method, string $typeFilterParameter): bool
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
            && self::matchesParameter($parameters[5], $typeFilterParameter, 'array', false, true, []);
    }

    private static function confirmsPayloadEnvelopeResolverSignature(): bool
    {
        return self::matchesStaticMethod(
            PayloadEnvelopeResolver::class,
            'resolve',
            [
                ['input', null, false, false, null],
                ['field', 'string', false, true, 'input'],
                ['externalStorage', ExternalPayloadStorageDriver::class, true, true, null],
            ],
            'array',
            false,
        ) && self::matchesStaticMethod(
            PayloadEnvelopeResolver::class,
            'resolveToArray',
            [
                ['input', null, false, false, null],
                ['field', 'string', false, true, 'input'],
                ['externalStorage', ExternalPayloadStorageDriver::class, true, true, null],
            ],
            'array',
            false,
        ) && self::matchesStaticMethod(
            PayloadEnvelopeResolver::class,
            'resolveCommandPayload',
            [
                ['value', null, false, false, null],
                ['field', 'string', false, true, 'result'],
                ['externalStorage', ExternalPayloadStorageDriver::class, true, true, null],
            ],
            'mixed',
            true,
        ) && self::matchesStaticMethod(
            PayloadEnvelopeResolver::class,
            'resolveCommandPayloadWithCodec',
            [
                ['value', null, false, false, null],
                ['field', 'string', false, true, 'result'],
                ['externalStorage', ExternalPayloadStorageDriver::class, true, true, null],
            ],
            'array',
            false,
        );
    }

    private static function confirmsExternalPayloadsSignature(): bool
    {
        return self::matchesStaticMethod(
            ExternalPayloads::class,
            'externalizeForNamespace',
            [
                ['payload', 'string', true, false, null],
                ['codec', 'string', true, false, null],
                ['namespace', 'string', true, false, null],
            ],
            'string',
            true,
        ) && self::matchesStaticMethod(
            ExternalPayloads::class,
            'isStoredReference',
            [
                ['payload', 'string', false, false, null],
            ],
            'bool',
            false,
        ) && self::matchesStaticMethod(
            ExternalPayloads::class,
            'wireEnvelope',
            [
                ['payload', 'string', true, false, null],
                ['codec', 'string', true, false, null],
                ['namespace', 'string', true, false, null],
            ],
            'array',
            true,
        ) && self::matchesStaticMethod(
            ExternalPayloads::class,
            'historyValue',
            [
                ['payload', 'string', true, false, null],
                ['codec', 'string', true, false, null],
                ['namespace', 'string', true, false, null],
            ],
            'mixed',
            true,
        ) && self::matchesStaticMethod(
            ExternalPayloads::class,
            'storedEnvelope',
            [
                ['payload', 'string', false, false, null],
            ],
            'array',
            true,
        );
    }

    private static function confirmsExternalPayloadStorageInterfaces(): bool
    {
        return self::matchesInstanceMethod(
            ExternalPayloadStorageDriver::class,
            'put',
            [
                ['data', 'string', false, false, null],
                ['sha256', 'string', false, false, null],
                ['codec', 'string', false, false, null],
            ],
            'string',
            false,
            true,
        ) && self::matchesInstanceMethod(
            ExternalPayloadStorageDriver::class,
            'get',
            [
                ['uri', 'string', false, false, null],
            ],
            'string',
            false,
            true,
        ) && self::matchesInstanceMethod(
            ExternalPayloadStorageDriver::class,
            'delete',
            [
                ['uri', 'string', false, false, null],
            ],
            'void',
            false,
            true,
        ) && self::matchesInstanceMethod(
            ExternalPayloadStoragePolicy::class,
            'driverFor',
            [
                ['namespace', 'string', true, false, null],
            ],
            ExternalPayloadStorageDriver::class,
            true,
            true,
        ) && self::matchesInstanceMethod(
            ExternalPayloadStoragePolicy::class,
            'thresholdBytesFor',
            [
                ['namespace', 'string', true, false, null],
            ],
            'int',
            true,
            true,
        );
    }

    private static function confirmsWorkflowCommandNormalizerPayloadEnvelopeContract(): bool
    {
        return self::matchesStaticMethod(
            WorkflowCommandNormalizer::class,
            'payloadEnvelopeFields',
            [],
            'array',
            false,
        ) && self::matchesStaticMethod(
            WorkflowCommandNormalizer::class,
            'acceptsPayloadEnvelope',
            [
                ['commandType', 'string', false, false, null],
                ['field', 'string', false, false, null],
            ],
            'bool',
            false,
        ) && self::matchesStaticMethod(
            WorkflowCommandNormalizer::class,
            'parallelMetadataValidationRules',
            [],
            'array',
            false,
        ) && self::matchesStaticMethod(
            WorkflowCommandNormalizer::class,
            'preflightParallelMetadata',
            [
                ['commands', 'array', false, false, null],
            ],
            'array',
            false,
        ) && self::matchesStaticMethod(
            WorkflowCommandNormalizer::class,
            'normalize',
            [
                ['commands', 'array', false, false, null],
                ['protocolVersion', 'string', true, true, null],
            ],
            'array',
            false,
        ) && WorkflowCommandNormalizer::acceptsPayloadEnvelope('complete_workflow', 'result')
            && WorkflowCommandNormalizer::acceptsPayloadEnvelope('schedule_activity', 'arguments')
            && WorkflowCommandNormalizer::acceptsPayloadEnvelope('start_child_workflow', 'arguments')
            && WorkflowCommandNormalizer::acceptsPayloadEnvelope('continue_as_new', 'arguments')
            && WorkflowCommandNormalizer::acceptsPayloadEnvelope('complete_update', 'result')
            && WorkflowCommandNormalizer::acceptsPayloadEnvelope('record_side_effect', 'result')
            && ! WorkflowCommandNormalizer::acceptsPayloadEnvelope('complete_update', 'arguments')
            && ! WorkflowCommandNormalizer::acceptsPayloadEnvelope('fail_update', 'result')
            && isset(WorkflowCommandNormalizer::parallelMetadataValidationRules()[
                'commands.*.parallel_group_path.*.parallel_group_kind'
            ]);
    }

    private static function confirmsRunCommandContractSignature(): bool
    {
        return self::matchesStaticMethod(
            RunCommandContract::class,
            'forRun',
            [
                ['run', WorkflowRun::class, false, false, null],
            ],
            'array',
            false,
        );
    }

    private static function confirmsSignalPreviewValidationSignatures(): bool
    {
        return self::matchesStaticMethod(
            RunCommandContract::class,
            'signalContract',
            [
                ['run', WorkflowRun::class, false, false, null],
                ['target', 'string', false, false, null],
            ],
            'array',
            true,
        ) && self::matchesStaticMethod(
            TypeRegistry::class,
            'resolveWorkflowClass',
            [
                ['storedClass', 'string', false, false, null],
                ['workflowType', 'string', true, false, null],
            ],
            'string',
            false,
        ) && self::matchesStaticMethod(
            WorkflowDefinition::class,
            'hasSignal',
            [
                ['class', 'string', false, false, null],
                ['name', 'string', false, false, null],
            ],
            'bool',
            false,
        ) && self::matchesStaticMethod(
            WorkflowDefinition::class,
            'signalContract',
            [
                ['class', 'string', false, false, null],
                ['target', 'string', false, false, null],
            ],
            'array',
            true,
        );
    }

    private static function confirmsWorkflowQueryContractSignature(): bool
    {
        return self::matchesStaticMethod(
            WorkflowQueryContract::class,
            'resolveTargetForRun',
            [
                ['run', WorkflowRun::class, false, false, null],
                ['target', 'string', false, false, null],
            ],
            'array',
            true,
        ) && self::matchesStaticMethod(
            WorkflowQueryContract::class,
            'validatedArgumentsForRun',
            [
                ['run', WorkflowRun::class, false, false, null],
                ['queryName', 'string', false, false, null],
                ['arguments', 'array', false, false, null],
            ],
            'array',
            false,
        );
    }

    /**
     * @param  array<int, array{0: string, 1: string|null, 2: bool, 3: bool, 4: mixed}>  $expectedParameters
     */
    private static function matchesStaticMethod(
        string $class,
        string $method,
        array $expectedParameters,
        string $returnType,
        bool $returnAllowsNull,
    ): bool {
        return self::matchesMethodSignature(
            $class,
            $method,
            $expectedParameters,
            $returnType,
            $returnAllowsNull,
            true,
            false,
        );
    }

    /**
     * @param  array<int, array{0: string, 1: string|null, 2: bool, 3: bool, 4: mixed}>  $expectedParameters
     */
    private static function matchesInstanceMethod(
        string $class,
        string $method,
        array $expectedParameters,
        string $returnType,
        bool $returnAllowsNull,
        bool $allowInterface,
    ): bool {
        return self::matchesMethodSignature(
            $class,
            $method,
            $expectedParameters,
            $returnType,
            $returnAllowsNull,
            false,
            $allowInterface,
        );
    }

    /**
     * @param  array<int, array{0: string, 1: string|null, 2: bool, 3: bool, 4: mixed}>  $expectedParameters
     */
    private static function matchesMethodSignature(
        string $class,
        string $method,
        array $expectedParameters,
        string $returnType,
        bool $returnAllowsNull,
        bool $static,
        bool $allowInterface,
    ): bool {
        if (! class_exists($class) && (! $allowInterface || ! interface_exists($class))) {
            return false;
        }

        try {
            $reflection = new ReflectionMethod($class, $method);
        } catch (ReflectionException) {
            return false;
        }

        if (! $reflection->isPublic() || $reflection->isStatic() !== $static) {
            return false;
        }

        if (! self::matchesReturnType($reflection, $returnType, $returnAllowsNull)) {
            return false;
        }

        $parameters = $reflection->getParameters();

        if (count($parameters) !== count($expectedParameters)) {
            return false;
        }

        foreach ($expectedParameters as $index => [$name, $type, $allowsNull, $hasDefault, $default]) {
            if (! self::matchesFlexibleParameter($parameters[$index], $name, $type, $allowsNull, $hasDefault, $default)) {
                return false;
            }
        }

        return true;
    }

    private static function matchesReturnType(
        ReflectionMethod $reflection,
        string $returnType,
        bool $allowsNull,
    ): bool {
        $type = $reflection->getReturnType();

        if (! $type instanceof \ReflectionNamedType) {
            return false;
        }

        return $type->getName() === $returnType
            && $type->allowsNull() === $allowsNull;
    }

    private static function matchesFlexibleParameter(
        \ReflectionParameter $parameter,
        string $name,
        ?string $type,
        bool $allowsNull,
        bool $hasDefault,
        mixed $default,
    ): bool {
        if ($parameter->getName() !== $name
            || $parameter->isDefaultValueAvailable() !== $hasDefault) {
            return false;
        }

        $parameterType = $parameter->getType();

        if ($type === null) {
            if ($parameterType !== null) {
                return false;
            }
        } elseif (! $parameterType instanceof \ReflectionNamedType
            || $parameterType->getName() !== $type
            || $parameterType->allowsNull() !== $allowsNull) {
            return false;
        }

        if (! $hasDefault) {
            return true;
        }

        return $parameter->getDefaultValue() === $default;
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

    private static function hasMinimumWorkerProtocolVersion(string $required): bool
    {
        $installed = self::installedWorkerProtocolVersion();
        if ($installed === null
            || preg_match('/^(\d+)\.(\d+)$/', $installed, $installedParts) !== 1
            || preg_match('/^(\d+)\.(\d+)$/', $required, $requiredParts) !== 1) {
            return false;
        }

        return (int) $installedParts[1] === (int) $requiredParts[1]
            && (int) $installedParts[2] >= (int) $requiredParts[2];
    }

    private static function installedWorkerProtocolVersion(): ?string
    {
        if (! defined(WorkerProtocolVersion::class.'::VERSION')) {
            return null;
        }

        $version = WorkerProtocolVersion::VERSION;

        return is_string($version) && trim($version) !== '' ? trim($version) : null;
    }
}
