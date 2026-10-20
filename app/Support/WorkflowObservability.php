<?php

namespace App\Support;

use Composer\InstalledVersions;
use Fiber;
use Illuminate\Http\Request;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use Throwable;
use WeakMap;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\TraceCarrier;

/** Optional metadata for an existing, authorized business operation. */
final class WorkflowObservability
{
    /** @var list<WorkflowObservabilityAdmission> */
    private static array $rootAdmissions = [];

    /** @var WeakMap<Fiber, list<WorkflowObservabilityAdmission>>|null */
    private static ?WeakMap $fiberAdmissions = null;

    public static function enabled(): bool
    {
        try {
            $api = InstalledVersions::isInstalled('open-telemetry/api')
                ? InstalledVersions::getVersion('open-telemetry/api') : null;

            return config('server.observability_context.enabled', false) === true
                && is_string($api) && version_compare($api, '1.10.1', '>=') && version_compare($api, '2.0', '<')
                && class_exists(TraceCarrier::class)
                && class_exists(TraceContextPropagator::class);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<string> */
    public static function baggageAllowlist(): array
    {
        try {
            $value = config('server.observability_context.baggage_allowlist', []);

            return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
        } catch (Throwable) {
            return [];
        }
    }

    public static function normalize(mixed $value, bool $allowBaggage = true): ?array
    {
        if (! self::enabled()) {
            return null;
        }

        try {
            $carrier = TraceCarrier::normalize($value, $allowBaggage ? self::baggageAllowlist() : [], $diagnostic);
            if (in_array($diagnostic, ['discarded', 'filtered'], true) && Span::getCurrent()->isRecording()) {
                Span::getCurrent()->setAttribute('durable.trace_context.disposition', $diagnostic);
            }

            return $carrier;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Called after request authentication, namespace and operation validation.
     * The scope stores the request without reading its optional carrier. Only
     * a new admitted model's creating hook may extract that metadata.
     */
    public static function admission(Request $request, ?string $workflowId, string $operation, callable $business): mixed
    {
        if (! self::enabled()) {
            return $business();
        }
        $scope = new WorkflowObservabilityAdmission($request, $workflowId, $operation);
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            self::$rootAdmissions[] = $scope;
        } else {
            self::$fiberAdmissions ??= new WeakMap;
            self::$fiberAdmissions[$fiber] = [...(self::$fiberAdmissions[$fiber] ?? []), $scope];
        }

        try {
            return $business();
        } finally {
            if ($fiber === null) {
                array_pop(self::$rootAdmissions);
            } else {
                $stack = self::$fiberAdmissions[$fiber] ?? [];
                array_pop($stack);
                if ($stack === []) {
                    unset(self::$fiberAdmissions[$fiber]);
                } else {
                    self::$fiberAdmissions[$fiber] = $stack;
                }
            }
        }
    }

    public static function creatingRun(WorkflowRun $run): void
    {
        try {
            self::currentAdmission()?->attachRun($run);
        } catch (Throwable) {
            // Optional metadata cannot prevent the original business insert.
        }
    }

    public static function creatingCommand(WorkflowCommand $command): void
    {
        try {
            self::currentAdmission()?->attachCommand($command);
        } catch (Throwable) {
            // No raw carrier, baggage, request body or exception is logged.
        }
    }

    private static function currentAdmission(): ?WorkflowObservabilityAdmission
    {
        $fiber = Fiber::getCurrent();
        $stack = $fiber === null ? self::$rootAdmissions : (self::$fiberAdmissions[$fiber] ?? []);
        $scope = $stack === [] ? null : $stack[array_key_last($stack)];

        return $scope?->ownerPid === getmypid() ? $scope : null;
    }
}
