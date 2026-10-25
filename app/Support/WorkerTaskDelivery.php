<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use App\Models\WorkflowUpdateValidationTask;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/** Offers convey no lease. Only an authenticated, fenced acceptance may claim. */
final class WorkerTaskDelivery
{
    public const HEADER = 'X-Durable-Workflow-Task-Delivery';

    public const VERSION = 'offer-accept.v1';

    public const SCHEMA = 'durable-workflow.task-delivery.v1';

    public const CAPABILITY = 'task_delivery_offers';

    public const RECEIPT_KEY = '_server_task_delivery';

    private const CANDIDATE_FIELDS = [
        'task_id', 'workflow_run_id', 'workflow_instance_id', 'namespace',
        'workflow_type', 'workflow_class', 'connection', 'queue', 'compatibility',
        'sticky_worker_id', 'sticky_until', 'available_at', 'priority',
        'fairness_key', 'fairness_weight', 'activity_execution_id', 'activity_type',
    ];

    private function __construct(
        private readonly string $namespace,
        private readonly string $queue,
        private readonly string $pollRequestId,
        private readonly string $route,
        private readonly array $taskKinds,
        private readonly array $worker,
        private readonly ?array $offer,
    ) {}

    public static function forRequest(Request $request, WorkerRegistration $worker, string $namespace, string $queue, ?string $pollRequestId): ?self
    {
        $version = $request->header(self::HEADER);
        $token = $request->input('task_offer_token');
        $offerEndpoint = str_ends_with($request->path(), '-tasks/offers');
        if (! $offerEndpoint && $version === null && $token === null) {
            return null;
        }
        if (! $offerEndpoint) {
            throw new TaskDeliveryOfferRejected('task_delivery_offer_endpoint_required');
        }
        if ($version !== self::VERSION || version_compare(WorkerProtocol::requestVersion($request) ?? '0', '1.20', '<')) {
            throw new TaskDeliveryOfferRejected('task_delivery_protocol_required');
        }
        if (! in_array(self::CAPABILITY, $worker->capabilities ?? [], true)
            || ! is_string($pollRequestId) || $pollRequestId === '') {
            throw new TaskDeliveryOfferRejected('task_delivery_registration_required');
        }
        $registrationToken = $request->input('registration_token');
        if (! is_string($registrationToken) || ! is_string($worker->registration_token)
            || ! hash_equals($worker->registration_token, $registrationToken)) {
            throw new TaskDeliveryOfferRejected('worker_registration_lost_authority');
        }
        $snapshot = CooperativeCancellationPolicy::workerSnapshot($worker, WorkerProtocol::requestVersion($request));
        $route = $request->path();
        $offer = $token === null ? null : self::decode($token);
        $taskKinds = str_contains($route, '/workflow-tasks/')
            ? WorkflowTaskPollRequestStore::normalizeTaskKinds($request->input('task_kinds', ['workflow']) ?? ['workflow'])
            : [];
        $context = new self($namespace, $queue, $pollRequestId, $route, $taskKinds, $snapshot, $offer);
        if ($offer !== null) {
            $context->assertContext($offer);
        }

        return $context;
    }

    public function accepting(): bool
    {
        return $this->offer !== null;
    }

    /** @return list<array<string, mixed>> */
    public function candidates(string $kind): array
    {
        return ($this->offer['task_kind'] ?? null) === $kind ? [$this->offer['candidate']] : [];
    }

    /** @param array<string, mixed> $candidate */
    public function offerTask(array $candidate, string $kind): ?array
    {
        $task = NamespaceWorkflowScope::task($this->namespace, (string) ($candidate['task_id'] ?? ''));
        if (! $task instanceof WorkflowTask || $task->status !== TaskStatus::Ready
            || $task->task_type->value !== $kind || $task->queue !== $this->queue
            || $task->available_at?->isFuture()) {
            return null;
        }

        return $this->issue($task->id, $kind, (int) $task->attempt_count, $this->fingerprint($task),
            array_intersect_key($candidate, array_flip(self::CANDIDATE_FIELDS)));
    }

    /** Seal an observation of the existing task authority without reserving it. */
    public function issue(string $taskId, string $kind, int $attempt, string $fingerprint, array $candidate = []): array
    {
        $data = [
            'schema' => self::SCHEMA, 'namespace' => $this->namespace, 'queue' => $this->queue,
            'poll_request_id' => $this->pollRequestId, 'route' => $this->route, 'task_kinds' => $this->taskKinds,
            'worker' => $this->worker, 'task_id' => $taskId, 'task_kind' => $kind,
            'offered_attempt' => $attempt, 'fingerprint' => $fingerprint, 'candidate' => $candidate,
            'expires_at_ms' => now()->getTimestampMs() + $this->acceptanceMilliseconds(),
        ];
        $data['delivery_id'] = $this->deliveryId($data);

        return ['delivery_offer' => [
            'schema' => self::SCHEMA, 'task_id' => $taskId, 'task_kind' => $kind,
            'token' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)),
            'expires_at_ms' => $data['expires_at_ms'],
        ]];
    }

    /** Called inside the existing claim transaction, after worker/run locking. */
    public function assertClaimable(string $taskId, string $kind): void
    {
        $data = $this->offer;
        if ($data === null || $data['task_id'] !== $taskId || $data['task_kind'] !== $kind) {
            throw new TaskDeliveryOfferRejected('task_offer_mismatch');
        }
        $this->assertWorkerCurrent($data);
        $task = NamespaceWorkflowScope::lockTaskForMutation($this->namespace, $taskId);
        if (! $task instanceof WorkflowTask || $task->status !== TaskStatus::Ready
            || $task->queue !== $this->queue || $task->task_type->value !== $kind
            || (int) $task->attempt_count !== $data['offered_attempt']
            || $task->available_at?->isFuture()) {
            throw new TaskDeliveryOfferRejected('task_offer_unavailable');
        }
        if ($kind === 'activity') {
            // Acquire the remaining domain lock before checking the short
            // acceptance deadline. The bridge will reuse these locks.
            ActivityExecution::query()->whereKey($task->payload['activity_execution_id'] ?? null)
                ->lockForUpdate()->first();
        }
        $this->assertAcceptance($taskId, $kind, (int) $task->attempt_count, $this->fingerprint($task));
        $this->assertSqlPollUnbound();
    }

    public function offeredTaskId(string $kind): ?string
    {
        return ($this->offer['task_kind'] ?? null) === $kind ? $this->offer['task_id'] : null;
    }

    /** Call under the domain's normal claim lock before changing its authority. */
    public function assertAcceptance(string $taskId, string $kind, int $attempt, string $fingerprint): void
    {
        $data = $this->offer;
        if ($data === null || $data['task_id'] !== $taskId || $data['task_kind'] !== $kind
            || $data['offered_attempt'] !== $attempt) {
            throw new TaskDeliveryOfferRejected('task_offer_unavailable');
        }
        $this->lockWorker();
        if (! hash_equals($data['fingerprint'], $fingerprint)) {
            throw new TaskDeliveryOfferRejected('task_offer_changed');
        }
        $this->assertUnexpired($data);
    }

    public function lockWorker(): void
    {
        $this->assertWorkerCurrent($this->offer ?? ['worker' => $this->worker]);
    }

    /** One SQL claim per logical poll, including multiplexed validation tasks. */
    public function assertSqlPollUnbound(): void
    {
        $this->lockWorker();
        if (WorkflowTask::query()->where('delivery_poll_key', $this->pollKey())->lockForUpdate()->first(['id']) !== null
            || WorkflowUpdateValidationTask::query()->where('delivery_poll_key', $this->pollKey())->lockForUpdate()->first(['id']) !== null) {
            throw new TaskDeliveryOfferRejected('task_poll_already_accepted');
        }
    }

    public function pollKey(): string
    {
        return hash('sha256', json_encode([
            self::SCHEMA, $this->namespace, $this->queue, $this->route, $this->pollRequestId,
            $this->worker['registration_id'], $this->worker['registration_incarnation'],
        ], JSON_THROW_ON_ERROR));
    }

    public function acceptedReceipt(int $attempt): array
    {
        if ($this->offer === null) {
            throw new TaskDeliveryOfferRejected('task_offer_mismatch');
        }
        $this->assertUnexpired($this->offer);

        return [
            'schema' => self::SCHEMA, 'delivery_id' => $this->offer['delivery_id'],
            'incarnation' => $this->worker['registration_incarnation'],
            'route' => $this->route, 'poll_request_id' => $this->pollRequestId,
            'task_kinds' => $this->taskKinds, 'accepted_attempt' => $attempt,
        ];
    }

    public function assertTimelyAcceptance(): void
    {
        if ($this->offer === null) {
            throw new TaskDeliveryOfferRejected('task_offer_mismatch');
        }
        $this->assertUnexpired($this->offer);
    }

    public function assertReceipt(mixed $receipt, string $taskId, int $attempt): void
    {
        $this->lockWorker();
        if (! is_array($receipt) || ($receipt['schema'] ?? null) !== self::SCHEMA
            || ($receipt['incarnation'] ?? null) !== $this->worker['registration_incarnation']
            || ($receipt['route'] ?? null) !== $this->route || ($receipt['poll_request_id'] ?? null) !== $this->pollRequestId
            || ($receipt['task_kinds'] ?? null) !== $this->taskKinds
            || ($receipt['accepted_attempt'] ?? null) !== $attempt
            || ($this->offer !== null && ($this->offer['task_id'] !== $taskId
                || ($receipt['delivery_id'] ?? null) !== $this->offer['delivery_id']))) {
            throw new TaskDeliveryOfferRejected('task_offer_lost_authority');
        }
    }

    /** Persist with the normal claim. Late failure rolls back the whole claim. */
    public function bindAccepted(string $taskId): void
    {
        $data = $this->offer;
        if ($data === null) {
            throw new TaskDeliveryOfferRejected('task_offer_mismatch');
        }
        $this->assertUnexpired($data);
        $task = NamespaceWorkflowScope::taskQuery($this->namespace)->whereKey($taskId)->lockForUpdate()->first();
        if (! $task instanceof WorkflowTask || $task->id !== $data['task_id']
            || $task->status !== TaskStatus::Leased || $task->lease_owner !== $this->worker['worker_id']
            || (int) $task->attempt_count !== $data['offered_attempt'] + 1) {
            throw new TaskDeliveryOfferRejected('task_offer_lost_authority');
        }
        $payload = $task->payload ?? [];
        $payload[self::RECEIPT_KEY] = $this->acceptedReceipt((int) $task->attempt_count);
        $task->forceFill(['payload' => $payload, 'delivery_poll_key' => $this->pollKey()])->save();
        $this->assertTimelyAcceptance();
    }

    /** Expired offer packets may still replay their already-accepted live claim. */
    public function assertReplay(string $taskId): void
    {
        $task = NamespaceWorkflowScope::task($this->namespace, $taskId);
        $receipt = $task?->payload[self::RECEIPT_KEY] ?? null;
        if (! $task instanceof WorkflowTask || ! is_array($receipt)
            || $task->status !== TaskStatus::Leased || $task->lease_owner !== $this->worker['worker_id']
            || $task->queue !== $this->queue || ! $task->lease_expires_at?->isFuture()
        ) {
            throw new TaskDeliveryOfferRejected('task_offer_lost_authority');
        }
        $this->assertReceipt($receipt, $taskId, (int) $task->attempt_count);
    }

    private static function decode(mixed $token): array
    {
        try {
            $data = is_string($token) && strlen($token) <= 8192
                ? json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (DecryptException|JsonException) {
            $data = null;
        }
        if (! is_array($data) || ($data['schema'] ?? null) !== self::SCHEMA
            || ! is_string($data['task_id'] ?? null) || ! is_string($data['task_kind'] ?? null)
            || ! is_array($data['worker'] ?? null) || ! is_array($data['candidate'] ?? null)
            || ! is_int($data['offered_attempt'] ?? null) || ! is_int($data['expires_at_ms'] ?? null)
            || ! is_string($data['fingerprint'] ?? null) || ! is_string($data['delivery_id'] ?? null)) {
            throw new TaskDeliveryOfferRejected('task_offer_invalid');
        }

        return $data;
    }

    private function assertContext(array $data): void
    {
        foreach (['namespace' => $this->namespace, 'queue' => $this->queue, 'poll_request_id' => $this->pollRequestId, 'route' => $this->route, 'task_kinds' => $this->taskKinds] as $key => $expected) {
            if (($data[$key] ?? null) !== $expected) {
                throw new TaskDeliveryOfferRejected('task_offer_mismatch');
            }
        }
        if (($data['worker']['registration_incarnation'] ?? null) !== $this->worker['registration_incarnation']
            || ($data['worker']['registration_id'] ?? null) !== $this->worker['registration_id']
            || ! hash_equals($data['delivery_id'], $this->deliveryId($data))) {
            throw new TaskDeliveryOfferRejected('worker_registration_lost_authority');
        }
    }

    private function assertWorkerCurrent(array $data): void
    {
        $current = WorkerRegistration::query()->where('namespace', $this->namespace)
            ->whereKey($this->worker['registration_id'])->lockForUpdate()->first();
        if (! $current instanceof WorkerRegistration || ! is_string($current->registration_token)
            || hash('sha256', $current->registration_token) !== $this->worker['registration_incarnation']
            || ! WorkerPollFence::isCurrentForUpdate($data['worker'])) {
            throw new TaskDeliveryOfferRejected('worker_registration_lost_authority');
        }
    }

    private function fingerprint(WorkflowTask $task): string
    {
        $run = WorkflowRun::query()->where('namespace', $this->namespace)->find($task->workflow_run_id);
        $executionId = $task->task_type->value === 'activity' ? ($task->payload['activity_execution_id'] ?? null) : null;
        $activityType = $executionId === null ? null : ActivityExecution::query()->whereKey($executionId)->value('activity_type');

        return hash('sha256', json_encode([
            $task->id, $task->namespace, $task->workflow_run_id, $task->task_type->value,
            $task->queue, $task->compatibility, $task->available_at?->toJSON(),
            $run?->workflow_type, $executionId, $activityType,
        ], JSON_THROW_ON_ERROR));
    }

    private function deliveryId(array $data): string
    {
        return hash('sha256', json_encode([
            $data['schema'], $data['namespace'], $data['queue'], $data['route'], $data['poll_request_id'],
            $data['task_kinds'],
            $data['worker']['registration_id'], $data['worker']['registration_incarnation'],
            $data['task_id'], $data['task_kind'], $data['offered_attempt'], $data['fingerprint'],
        ], JSON_THROW_ON_ERROR));
    }

    private function assertUnexpired(array $data): void
    {
        if (now()->getTimestampMs() >= $data['expires_at_ms']) {
            throw new TaskDeliveryOfferRejected('task_offer_expired');
        }
    }

    private function acceptanceMilliseconds(): int
    {
        return max(1000, min(10000, (int) config('server.polling.task_offer_accept_seconds', 2) * 1000));
    }
}
