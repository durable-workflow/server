<?php

namespace App\Support;

use Illuminate\Contracts\Cache\LockProvider;
use InvalidArgumentException;
use Throwable;
use WeakMap;
use Workflow\V2\Contracts\ServiceBoundaryPolicy;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Support\DefaultServiceBoundaryPolicy;
use Workflow\V2\Support\ServiceBoundaryDecision;
use Workflow\V2\Support\ServiceBoundaryRequest;

final class SharedServiceBoundaryPolicy implements ServiceBoundaryPolicy
{
    public const POLICY_NAME = 'server.shared-service-boundary';

    /** @var WeakMap<ServiceBoundaryRequest, array{boundary: string|null, namespace: string|null}> */
    private WeakMap $reservations;

    /** @param array<string, mixed> $rules */
    public function __construct(
        private readonly ServerPollingCache $cache,
        private readonly array $rules,
    ) {
        $this->reservations = new WeakMap;
    }

    public function evaluate(ServiceBoundaryRequest $request): ServiceBoundaryDecision
    {
        $baseDecision = $this->basePolicy()->evaluate($this->withoutAdmissionControlsFromRequest($request));

        if ($baseDecision->isDenied()) {
            return $baseDecision;
        }

        try {
            $limits = $this->limits($request);
        } catch (Throwable) {
            return $this->unavailable();
        }

        if (! $this->hasAdmissionLimit($limits)) {
            return ServiceBoundaryDecision::allow(
                policyName: self::POLICY_NAME,
                metadata: $baseDecision->metadata,
            );
        }

        if (! $this->cache->available()) {
            return $this->unavailable();
        }

        try {
            $store = $this->cache->store();

            if (! $store->getStore() instanceof LockProvider) {
                return $this->unavailable();
            }

            return $store->getStore()
                ->lock($this->lockKey($request), $this->lockTtlSeconds())
                ->block(
                    $this->lockWaitSeconds(),
                    fn (): ServiceBoundaryDecision => $this->admitWithLock(
                        $request,
                        $limits,
                        $baseDecision->metadata,
                    ),
                );
        } catch (Throwable) {
            return $this->unavailable();
        }
    }

    /**
     * Replays re-check authorization and circuit policy without consuming a
     * fresh rate or concurrency reservation.
     */
    public function authorizeReplay(ServiceBoundaryRequest $request): ServiceBoundaryDecision
    {
        return $this->basePolicy()->evaluate($this->withoutAdmissionControlsFromRequest($request));
    }

    public function release(ServiceBoundaryRequest $request): void
    {
        $reservation = $this->reservations[$request] ?? null;

        if (! is_array($reservation)) {
            return;
        }

        unset($this->reservations[$request]);

        try {
            if (! $this->cache->available()) {
                return;
            }

            $store = $this->cache->store();
            if (! $store->getStore() instanceof LockProvider) {
                return;
            }

            $store->getStore()
                ->lock($this->lockKey($request), $this->lockTtlSeconds())
                ->block($this->lockWaitSeconds(), function () use ($reservation): void {
                    if ($reservation['boundary'] !== null) {
                        $this->decrement($reservation['boundary']);
                    }

                    if ($reservation['namespace'] !== null) {
                        $this->decrement($reservation['namespace']);
                    }
                });
        } catch (Throwable) {
            // Admission counters have a finite lease and self-heal if a
            // completion races a transient cache outage.
        }
    }

    /**
     * @param array{
     *     boundary_rate: int|null,
     *     boundary_concurrency: int|null,
     *     namespace_rate: int|null,
     *     namespace_concurrency: int|null,
     *     boundary_rate_retry_after: int,
     *     boundary_concurrency_retry_after: int
     * } $limits
     * @param  array<string, scalar|array<mixed>|null>  $baseMetadata
     */
    private function admitWithLock(
        ServiceBoundaryRequest $request,
        array $limits,
        array $baseMetadata,
    ): ServiceBoundaryDecision {
        $boundaryRate = $this->counter($this->boundaryRateKey($request));
        $namespaceRate = $this->counter($this->namespaceRateKey($request));
        $boundaryConcurrency = $this->counter($this->boundaryConcurrencyKey($request));
        $namespaceConcurrency = $this->counter($this->namespaceConcurrencyKey($request));

        if ($limits['boundary_rate'] !== null && $boundaryRate >= $limits['boundary_rate']) {
            return $this->rateLimited(
                $limits['boundary_rate'],
                $boundaryRate,
                'boundary',
                $limits['boundary_rate_retry_after'],
            );
        }

        if ($limits['namespace_rate'] !== null && $namespaceRate >= $limits['namespace_rate']) {
            return $this->rateLimited(
                $limits['namespace_rate'],
                $namespaceRate,
                'caller_namespace',
                $this->retryAfterSeconds(),
            );
        }

        if (
            $limits['boundary_concurrency'] !== null
            && $boundaryConcurrency >= $limits['boundary_concurrency']
        ) {
            return $this->concurrencyLimited(
                $limits['boundary_concurrency'],
                $boundaryConcurrency,
                'boundary',
                $limits['boundary_concurrency_retry_after'],
            );
        }

        if (
            $limits['namespace_concurrency'] !== null
            && $namespaceConcurrency >= $limits['namespace_concurrency']
        ) {
            return $this->concurrencyLimited(
                $limits['namespace_concurrency'],
                $namespaceConcurrency,
                'caller_namespace',
                $this->retryAfterSeconds(),
            );
        }

        if ($limits['boundary_rate'] !== null) {
            $this->increment($this->boundaryRateKey($request), $this->rateTtlSeconds());
        }
        if ($limits['namespace_rate'] !== null) {
            $this->increment($this->namespaceRateKey($request), $this->rateTtlSeconds());
        }
        if ($limits['boundary_concurrency'] !== null) {
            $boundaryConcurrencyKey = $this->boundaryConcurrencyKey($request);
            $this->increment($boundaryConcurrencyKey, $this->concurrencyTtlSeconds());
        }
        if ($limits['namespace_concurrency'] !== null) {
            $namespaceConcurrencyKey = $this->namespaceConcurrencyKey($request);
            $this->increment($namespaceConcurrencyKey, $this->concurrencyTtlSeconds());
        }

        if (isset($boundaryConcurrencyKey) || isset($namespaceConcurrencyKey)) {
            $this->reservations[$request] = [
                'boundary' => $boundaryConcurrencyKey ?? null,
                'namespace' => $namespaceConcurrencyKey ?? null,
            ];
        }

        return ServiceBoundaryDecision::allow(
            policyName: self::POLICY_NAME,
            metadata: $baseMetadata + [
                'admission_scope' => 'shared_cache',
                'boundary_rate_limit' => $limits['boundary_rate'],
                'namespace_rate_limit' => $limits['namespace_rate'],
                'boundary_concurrency_limit' => $limits['boundary_concurrency'],
                'namespace_concurrency_limit' => $limits['namespace_concurrency'],
            ],
        );
    }

    /**
     * @return array{
     *     boundary_rate: int|null,
     *     boundary_concurrency: int|null,
     *     namespace_rate: int|null,
     *     namespace_concurrency: int|null,
     *     boundary_rate_retry_after: int,
     *     boundary_concurrency_retry_after: int
     * }
     */
    private function limits(ServiceBoundaryRequest $request): array
    {
        $rateRules = $this->effectiveRules($request, 'rate_limit');
        $concurrencyRules = $this->effectiveRules($request, 'concurrency_limit', 'concurrency');
        $shared = config('server.service_boundary.shared_admission', []);

        if (! is_array($shared)) {
            throw new InvalidArgumentException('server.service_boundary.shared_admission must be an object.');
        }

        $namespace = $this->budgetNamespace($request);
        $override = $this->namespaceOverrides($shared)[$namespace] ?? [];

        $hardRate = $this->limit($shared['hard_max_requests_per_minute'] ?? null);
        $hardConcurrency = $this->limit($shared['hard_max_in_flight'] ?? null);
        $boundaryRate = $this->syncOnlySkips($rateRules, $request)
            ? null
            : $this->boundedLimit(
                $this->limit($rateRules['requests_per_minute'] ?? null),
                $hardRate,
            );
        $boundaryConcurrency = $this->syncOnlySkips($concurrencyRules, $request)
            ? null
            : $this->boundedLimit(
                $this->limit($concurrencyRules['max_in_flight'] ?? null),
                $hardConcurrency,
            );
        $namespaceRate = $this->boundedLimit(
            $this->limit(
                array_key_exists('max_requests_per_minute', $override)
                    ? $override['max_requests_per_minute']
                    : ($shared['max_requests_per_minute_per_namespace'] ?? null),
            ),
            $hardRate,
        );
        $namespaceConcurrency = $this->boundedLimit(
            $this->limit(
                array_key_exists('max_in_flight', $override)
                    ? $override['max_in_flight']
                    : ($shared['max_in_flight_per_namespace'] ?? null),
            ),
            $hardConcurrency,
        );

        return [
            'boundary_rate' => $boundaryRate,
            'boundary_concurrency' => $boundaryConcurrency,
            'namespace_rate' => $namespaceRate,
            'namespace_concurrency' => $namespaceConcurrency,
            'boundary_rate_retry_after' => $this->configuredRetryAfterSeconds($rateRules),
            'boundary_concurrency_retry_after' => $this->configuredRetryAfterSeconds($concurrencyRules),
        ];
    }

    /**
     * @param  array<string, mixed>  $shared
     * @return array<string, array{max_requests_per_minute?: mixed, max_in_flight?: mixed}>
     */
    private function namespaceOverrides(array $shared): array
    {
        $configured = $shared['namespace_overrides'] ?? [];

        if (! is_array($configured)) {
            throw new InvalidArgumentException('Service-boundary namespace overrides must be an object.');
        }

        $overrides = [];

        foreach ($configured as $configuredNamespace => $values) {
            if (! is_string($configuredNamespace) || trim($configuredNamespace) === '') {
                throw new InvalidArgumentException(
                    'Service-boundary namespace override keys must be non-empty namespace names.',
                );
            }

            $namespace = strtolower(trim($configuredNamespace));
            if (array_key_exists($namespace, $overrides)) {
                throw new InvalidArgumentException(
                    "Service-boundary namespace overrides contain duplicate normalized namespace [{$namespace}].",
                );
            }

            if (! is_array($values) || ($values !== [] && array_is_list($values))) {
                throw new InvalidArgumentException(
                    "Service-boundary namespace override [{$configuredNamespace}] must be an object.",
                );
            }

            $unknown = array_diff(array_keys($values), ['max_requests_per_minute', 'max_in_flight']);
            if ($unknown !== []) {
                throw new InvalidArgumentException(sprintf(
                    'Service-boundary namespace override [%s] contains unknown field [%s].',
                    $configuredNamespace,
                    (string) reset($unknown),
                ));
            }

            $overrides[$namespace] = $values;
        }

        return $overrides;
    }

    private function basePolicy(): DefaultServiceBoundaryPolicy
    {
        return new DefaultServiceBoundaryPolicy($this->withoutAdmissionControls($this->rules));
    }

    /** @return array<string, mixed> */
    private function effectiveRules(
        ServiceBoundaryRequest $request,
        string $policyKey,
        ?string $globalKey = null,
    ): array {
        $global = $this->arrayAt($this->rules, [$globalKey ?? $policyKey]);
        $operation = $this->arrayAt($request->effectiveBoundaryPolicy(), [$policyKey]);

        return ServiceBoundaryRequest::mergePolicy($global, $operation);
    }

    /** @param array<string, mixed> $rules */
    private function syncOnlySkips(array $rules, ServiceBoundaryRequest $request): bool
    {
        return ($rules['sync_only'] ?? false) === true
            && $request->operationMode->value !== 'sync';
    }

    /** @param array<string, mixed> $policy @return array<string, mixed> */
    private function withoutAdmissionControls(array $policy): array
    {
        foreach (['rate_limit', 'concurrency', 'concurrency_limit'] as $key) {
            unset($policy[$key]);
        }

        foreach ($policy as $key => $value) {
            if (is_array($value) && ! array_is_list($value)) {
                $policy[$key] = $this->withoutAdmissionControls($value);
            }
        }

        return $policy;
    }

    private function withoutAdmissionControlsFromRequest(ServiceBoundaryRequest $request): ServiceBoundaryRequest
    {
        return new ServiceBoundaryRequest(
            principal: $request->principal,
            callerNamespace: $request->callerNamespace,
            targetNamespace: $request->targetNamespace,
            endpointName: $request->endpointName,
            serviceName: $request->serviceName,
            operationName: $request->operationName,
            operationMode: $request->operationMode,
            resolvedBindingKind: $request->resolvedBindingKind,
            resolvedTargetReference: $request->resolvedTargetReference,
            callerWorkflowInstanceId: $request->callerWorkflowInstanceId,
            callerWorkflowRunId: $request->callerWorkflowRunId,
            linkedWorkflowInstanceId: $request->linkedWorkflowInstanceId,
            linkedWorkflowRunId: $request->linkedWorkflowRunId,
            linkedWorkflowUpdateId: $request->linkedWorkflowUpdateId,
            idempotencyKey: $request->idempotencyKey,
            context: $request->context,
            endpointBoundaryPolicy: $this->withoutAdmissionControls($request->endpointBoundaryPolicy),
            serviceBoundaryPolicy: $this->withoutAdmissionControls($request->serviceBoundaryPolicy),
            operationBoundaryPolicy: $this->withoutAdmissionControls($request->operationBoundaryPolicy),
            deadlinePolicy: $request->deadlinePolicy,
            idempotencyPolicy: $request->idempotencyPolicy,
            cancellationPolicy: $request->cancellationPolicy,
            retryPolicy: $request->retryPolicy,
        );
    }

    /** @param array<string, mixed> $source @param list<string> $path @return array<string, mixed> */
    private function arrayAt(array $source, array $path): array
    {
        $value = $source;

        foreach ($path as $segment) {
            if (! isset($value[$segment]) || ! is_array($value[$segment])) {
                return [];
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private function limit(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($parsed !== false) {
                return $parsed;
            }
        }

        throw new InvalidArgumentException('Service-boundary limits must be null or positive integers.');
    }

    private function boundedLimit(?int $configured, ?int $hard): ?int
    {
        if ($configured === null) {
            return $hard;
        }

        return $hard === null ? $configured : min($configured, $hard);
    }

    /**
     * @param array{
     *     boundary_rate: int|null,
     *     boundary_concurrency: int|null,
     *     namespace_rate: int|null,
     *     namespace_concurrency: int|null
     * } $limits
     */
    private function hasAdmissionLimit(array $limits): bool
    {
        return $limits['boundary_rate'] !== null
            || $limits['boundary_concurrency'] !== null
            || $limits['namespace_rate'] !== null
            || $limits['namespace_concurrency'] !== null;
    }

    private function budgetNamespace(ServiceBoundaryRequest $request): string
    {
        $namespace = strtolower(trim($request->callerNamespace ?? $request->targetNamespace));

        return $namespace === '' ? '_unknown' : $namespace;
    }

    private function counter(string $key): int
    {
        return max(0, (int) $this->cache->store()->get($key, 0));
    }

    private function increment(string $key, int $ttlSeconds): void
    {
        $this->cache->store()->put($key, $this->counter($key) + 1, now()->addSeconds($ttlSeconds));
    }

    private function decrement(string $key): void
    {
        $current = $this->counter($key);

        if ($current <= 1) {
            $this->cache->store()->forget($key);

            return;
        }

        $this->cache->store()->put(
            $key,
            $current - 1,
            now()->addSeconds($this->concurrencyTtlSeconds()),
        );
    }

    private function rateLimited(
        int $limit,
        int $observed,
        string $scope,
        int $retryAfterSeconds,
    ): ServiceBoundaryDecision {
        return ServiceBoundaryDecision::denyRateLimit(
            retryAfterSeconds: $retryAfterSeconds,
            message: 'Shared service-call rate capacity is exhausted.',
            policyName: self::POLICY_NAME,
            metadata: [
                'admission_scope' => $scope,
                'observed_window_count' => $observed,
                'requests_per_minute' => $limit,
            ],
        );
    }

    private function concurrencyLimited(
        int $limit,
        int $observed,
        string $scope,
        int $retryAfterSeconds,
    ): ServiceBoundaryDecision {
        return ServiceBoundaryDecision::denyConcurrency(
            retryAfterSeconds: $retryAfterSeconds,
            message: 'Shared service-call concurrency capacity is exhausted.',
            policyName: self::POLICY_NAME,
            metadata: [
                'admission_scope' => $scope,
                'observed_in_flight' => $observed,
                'max_in_flight' => $limit,
            ],
        );
    }

    private function unavailable(): ServiceBoundaryDecision
    {
        return new ServiceBoundaryDecision(
            outcome: ServiceCallOutcome::RejectedThrottled,
            reason: 'service_boundary_admission_unavailable',
            message: 'Shared service-call admission could not be evaluated.',
            policyName: self::POLICY_NAME,
            retryAfterSeconds: 1,
            metadata: [
                'failure_reason' => 'policy_rejection',
                'admission_scope' => 'shared_cache',
            ],
        );
    }

    private function lockKey(ServiceBoundaryRequest $request): string
    {
        return 'server:service-boundary:lock:'.hash('sha256', $this->budgetNamespace($request));
    }

    private function boundaryRateKey(ServiceBoundaryRequest $request): string
    {
        return sprintf(
            'server:service-boundary:rate:%s:%d',
            hash('sha256', $request->boundaryKey()),
            $this->minuteBucket(),
        );
    }

    private function namespaceRateKey(ServiceBoundaryRequest $request): string
    {
        return sprintf(
            'server:service-boundary:namespace-rate:%s:%d',
            hash('sha256', $this->budgetNamespace($request)),
            $this->minuteBucket(),
        );
    }

    private function boundaryConcurrencyKey(ServiceBoundaryRequest $request): string
    {
        return 'server:service-boundary:concurrency:'.hash('sha256', $request->boundaryKey());
    }

    private function namespaceConcurrencyKey(ServiceBoundaryRequest $request): string
    {
        return 'server:service-boundary:namespace-concurrency:'
            .hash('sha256', $this->budgetNamespace($request));
    }

    private function minuteBucket(): int
    {
        return intdiv(now()->getTimestamp(), 60);
    }

    private function rateTtlSeconds(): int
    {
        return 120;
    }

    private function concurrencyTtlSeconds(): int
    {
        return max(60, min(604800, (int) config(
            'server.service_boundary.shared_admission.concurrency_lease_ttl_seconds',
            86400,
        )));
    }

    private function retryAfterSeconds(): int
    {
        return max(1, min(60, (int) config(
            'server.service_boundary.shared_admission.retry_after_seconds',
            1,
        )));
    }

    /** @param array<string, mixed> $rules */
    private function configuredRetryAfterSeconds(array $rules): int
    {
        $value = $rules['retry_after_seconds'] ?? null;

        if ($value === null || $value === '') {
            return $this->retryAfterSeconds();
        }

        if (is_int($value) && $value >= 0) {
            return min(60, $value);
        }

        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

            if ($parsed !== false) {
                return min(60, $parsed);
            }
        }

        throw new InvalidArgumentException('Service-boundary retry_after_seconds must be a non-negative integer.');
    }

    private function lockTtlSeconds(): int
    {
        return 5;
    }

    private function lockWaitSeconds(): int
    {
        return 1;
    }
}
