<?php

namespace App\Support;

use App\Models\RuntimeExternalPayload;
use App\Models\RuntimePayloadCompletionBudget;
use Carbon\CarbonInterface;

final class RuntimePayloadCompletionUploads
{
    public const MAX_SLOTS = 128;

    public function __construct(
        private readonly RuntimePayloadCompletionLease $leases,
        private readonly RuntimeExternalPayloadObjectLock $locks,
        private readonly StoragePressure $pressure,
    ) {}

    public function authorize(string $namespace, RuntimePayloadCompletionContext $context, string $sha256, int $size): void
    {
        $this->requireWritable();
        if ($this->reference($namespace, $context, $sha256, $size) === null) {
            $this->leases->expiresAt($namespace, $context);
        }
    }

    public function upload(
        string $namespace, RuntimePayloadCompletionContext $context, ExternalPayloadStream $data,
        string $codec, string $sha256, RuntimeExternalPayloadRegistry $registry,
    ): array {
        $this->requireWritable();
        try {
            PayloadCodecContract::canonicalize($codec);
        } catch (\InvalidArgumentException $exception) {
            throw new RuntimeExternalPayloadException('external_payload_unsupported', 422, false, $exception->getMessage());
        }
        if (! hash_equals($data->sha256, $sha256)) {
            throw new RuntimeExternalPayloadException('external_payload_integrity_mismatch', 422, false,
                'Declared external payload integrity metadata does not match the uploaded bytes.');
        }
        if (($reference = $this->reference($namespace, $context, $sha256, $data->sizeBytes)) !== null) {
            // Lost upload/completion responses may be reconciled after the lease closes.
            // This path never creates, rewrites, retains or extends an object.
            return $reference;
        }

        $expiresAt = $this->leases->expiresAt($namespace, $context);
        $scope = $context->scope($namespace);
        $this->locks->transaction($scope, function () use ($namespace, $context, $sha256, $data, $expiresAt, $scope): void {
            $this->requireWritable();
            $budget = RuntimePayloadCompletionBudget::query()->find($scope)
                ?? new RuntimePayloadCompletionBudget(['id' => $scope, 'namespace' => $namespace,
                    'context' => $context->toArray(), 'slots' => [], 'objects' => []]);
            $slots = $budget->slots;
            $objects = $budget->objects;
            $slot = $context->slotIdentity();
            $identity = ['sha256' => $sha256, 'size_bytes' => $data->sizeBytes];
            if (isset($slots[$slot]) && array_intersect_key($slots[$slot], $identity) !== $identity) {
                throw new RuntimeExternalPayloadException('external_payload_completion_conflict', 409, false,
                    'A completion payload slot is already bound to different bytes.');
            }
            if (! isset($slots[$slot]) && count($slots) >= self::MAX_SLOTS
                || ! isset($objects[$sha256]) && $data->sizeBytes > self::maxBytes() - array_sum($objects)) {
                throw new RuntimeExternalPayloadException('external_payload_completion_budget_exhausted', 429, true,
                    'The bounded payload allowance for this completion lease is exhausted.', retryAfterSeconds: 5);
            }
            $slots[$slot] ??= $identity;
            $objects[$sha256] = $data->sizeBytes;
            $budget->forceFill(['slots' => $slots, 'objects' => $objects,
                'expires_at' => $expiresAt->addSeconds($this->retryRetention())])->save();
        });

        $reference = $registry->upload($namespace, $data, $codec, $sha256, function () use ($namespace, $context): void {
            $this->requireWritable();
            $this->leases->expiresAt($namespace, $context);
        });
        $this->locks->transaction($scope, function () use ($scope, $context, $reference): void {
            $this->requireWritable();
            $budget = RuntimePayloadCompletionBudget::query()->findOrFail($scope);
            $slots = $budget->slots;
            $slots[$context->slotIdentity()]['reference'] = $reference;
            $budget->forceFill(['slots' => $slots])->save();
        });

        return $reference;
    }

    public static function maxBytes(): int
    {
        return max(1, (int) (config('server.external_payload_transport.completion_max_bytes')
            ?? config('server.external_payload_transport.max_payload_bytes')));
    }

    public function cleanup(?string $namespace, int $limit, CarbonInterface $cutoff): void
    {
        $candidates = RuntimePayloadCompletionBudget::query()->where('expires_at', '<=', $cutoff)
            ->when($namespace !== null, fn ($query) => $query->where('namespace', $namespace))
            ->orderBy('expires_at')->limit($limit)->get();
        foreach ($candidates as $candidate) {
            $context = RuntimePayloadCompletionContext::parse(json_encode($candidate->context, JSON_THROW_ON_ERROR));
            $this->locks->transaction($candidate->id, function () use ($candidate, $context, $cutoff): void {
                $this->pressure->requireNewWork();
                $row = RuntimePayloadCompletionBudget::query()->find($candidate->id);
                if ($row === null || $row->expires_at->gt($cutoff)) {
                    return;
                }
                // A heartbeat may keep an old lease alive: never reset its allowance.
                if ($this->leases->mayResume($candidate->namespace, $context)) {
                    $row->forceFill(['expires_at' => now()->addSeconds($this->retryRetention())])->save();
                } else {
                    $row->delete();
                }
            });
        }
    }

    private function reference(string $namespace, RuntimePayloadCompletionContext $context, string $sha256, int $size): ?array
    {
        $budget = RuntimePayloadCompletionBudget::query()->find($context->scope($namespace));
        $slot = $budget?->slots[$context->slotIdentity()] ?? null;
        $reference = $slot['reference'] ?? null;
        if (! is_array($reference) || ($slot['sha256'] ?? null) !== $sha256 || ($slot['size_bytes'] ?? null) !== $size) {
            return null;
        }
        $row = RuntimeExternalPayload::query()->whereKey($reference['reference_id'])->where('namespace', $namespace)
            ->where('sha256', $sha256)->where('size_bytes', $size)->where('codec', 'avro')
            ->where('upload_status', RuntimeExternalPayload::UPLOAD_READY)->first();
        if ($row === null || ($row->retained_at === null && ($row->expires_at === null || $row->expires_at->lte(now())))) {
            return null;
        }

        return $reference;
    }

    private function requireWritable(): void
    {
        $snapshot = $this->pressure->snapshot();
        if (! in_array($snapshot['state'], ['normal', 'draining'], true)) {
            throw new StorageAdmissionPaused($snapshot);
        }
    }

    private function retryRetention(): int
    {
        return max(1, (int) config('server.external_payload_transport.abandoned_upload_expiry_seconds'));
    }
}
