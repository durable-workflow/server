<?php

namespace App\Support;

use App\Models\RuntimeExternalPayload;
use App\Models\RuntimeExternalPayloadCleanupStat;
use App\Models\WorkflowNamespace;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RuntimeExternalPayloadCleanup
{
    public const DEFAULT_BATCH_SIZE = 100;

    public const MAX_BATCH_SIZE = 1000;

    public function __construct(
        private readonly RuntimeExternalPayloadObjectLock $objectLock,
        private readonly NamespaceExternalPayloadStorage $storage,
    ) {}

    /**
     * @return array{
     *     processed: int,
     *     deleted_references: int,
     *     deleted_backing_objects: int,
     *     shared_objects_preserved: int,
     *     skipped: int,
     *     blocked: int,
     *     storage_driver_failures: int,
     *     backlog: int,
     *     scan_limit: int,
     *     scan_pressure: bool
     * }
     */
    public function runPass(
        ?string $namespace = null,
        int $limit = self::DEFAULT_BATCH_SIZE,
        ?CarbonInterface $cutoff = null,
    ): array {
        $namespace = $namespace !== null ? strtolower($namespace) : null;
        $limit = min(self::MAX_BATCH_SIZE, max(1, $limit));
        $cutoff ??= now();

        $candidates = $this->expiredQuery($namespace, $cutoff)
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'namespace']);

        $report = $this->emptyReport($limit);
        $namespaceReports = [];

        foreach ($candidates as $candidate) {
            $report['processed']++;
            $candidateNamespace = (string) $candidate->namespace;
            $namespaceReports[$candidateNamespace] ??= $this->emptyReport($limit);
            $namespaceReports[$candidateNamespace]['processed']++;

            try {
                $outcome = $this->cleanupReference((string) $candidate->id, $cutoff);
            } catch (RuntimeExternalPayloadCleanupStorageFailure $exception) {
                $report['blocked']++;
                $report['storage_driver_failures']++;
                $namespaceReports[$candidateNamespace]['blocked']++;
                $namespaceReports[$candidateNamespace]['storage_driver_failures']++;

                Log::warning('Runtime external payload cleanup storage operation failed.', [
                    'namespace' => $candidateNamespace,
                    'exception_class' => $exception->getPrevious() !== null
                        ? $exception->getPrevious()::class
                        : $exception::class,
                ]);

                continue;
            }

            match ($outcome) {
                'deleted_object' => $this->incrementDeletedObject($report, $namespaceReports[$candidateNamespace]),
                'deleted_shared' => $this->incrementShared($report, $namespaceReports[$candidateNamespace]),
                'blocked' => $this->increment($report, $namespaceReports[$candidateNamespace], 'blocked'),
                default => $this->increment($report, $namespaceReports[$candidateNamespace], 'skipped'),
            };
        }

        if ($namespace !== null && ! array_key_exists($namespace, $namespaceReports)) {
            $namespaceReports[$namespace] = $this->emptyReport($limit);
        }

        foreach ($namespaceReports as $reportNamespace => $namespaceReport) {
            $this->recordStats($reportNamespace, $namespaceReport);
        }

        $report['backlog'] = $this->expiredQuery($namespace, $cutoff)->count();
        $report['scan_pressure'] = $report['backlog'] > 0 && $candidates->count() >= $limit;

        return $report;
    }

    private function cleanupReference(string $id, CarbonInterface $cutoff): string
    {
        $candidate = RuntimeExternalPayload::query()->find($id, ['id', 'storage_uri']);
        if ($candidate === null) {
            return 'skipped';
        }

        return $this->objectLock->transaction($candidate->storage_uri, function () use ($id, $cutoff): string {
            $row = RuntimeExternalPayload::query()->whereKey($id)->lockForUpdate()->first();

            if (
                $row === null
                || $row->retained_at !== null
                || $row->expires_at === null
                || $row->expires_at->greaterThan($cutoff)
            ) {
                return 'skipped';
            }

            $owners = RuntimeExternalPayload::query()
                ->where('storage_uri_sha256', $row->storage_uri_sha256)
                ->where('storage_uri', $row->storage_uri)
                ->lockForUpdate()
                ->get();

            if ($owners->count() > 1) {
                $row->delete();

                return 'deleted_shared';
            }

            $driver = $this->storage->untrackedDriverFor($row->namespace);
            if ($driver === null) {
                return 'blocked';
            }

            try {
                $driver->delete($row->storage_uri);
            } catch (Throwable $exception) {
                throw new RuntimeExternalPayloadCleanupStorageFailure($exception);
            }

            $row->delete();

            return 'deleted_object';
        });
    }

    private function expiredQuery(?string $namespace, CarbonInterface $cutoff): mixed
    {
        return RuntimeExternalPayload::query()
            ->when($namespace !== null, static fn ($query) => $query->where('namespace', $namespace))
            ->whereNull('retained_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $cutoff);
    }

    /** @return array<string, int|bool> */
    private function emptyReport(int $limit): array
    {
        return [
            'processed' => 0,
            'deleted_references' => 0,
            'deleted_backing_objects' => 0,
            'shared_objects_preserved' => 0,
            'skipped' => 0,
            'blocked' => 0,
            'storage_driver_failures' => 0,
            'backlog' => 0,
            'scan_limit' => $limit,
            'scan_pressure' => false,
        ];
    }

    /** @param array<string, int|bool> $total @param array<string, int|bool> $namespace */
    private function increment(array &$total, array &$namespace, string $key): void
    {
        $total[$key]++;
        $namespace[$key]++;
    }

    /** @param array<string, int|bool> $total @param array<string, int|bool> $namespace */
    private function incrementDeletedObject(array &$total, array &$namespace): void
    {
        $this->increment($total, $namespace, 'deleted_references');
        $this->increment($total, $namespace, 'deleted_backing_objects');
    }

    /** @param array<string, int|bool> $total @param array<string, int|bool> $namespace */
    private function incrementShared(array &$total, array &$namespace): void
    {
        $this->increment($total, $namespace, 'deleted_references');
        $this->increment($total, $namespace, 'shared_objects_preserved');
    }

    /** @param array<string, int|bool> $report */
    private function recordStats(string $namespace, array $report): void
    {
        if (! WorkflowNamespace::query()->where('name', $namespace)->exists()) {
            return;
        }

        DB::transaction(function () use ($namespace, $report): void {
            RuntimeExternalPayloadCleanupStat::query()->insertOrIgnore([
                'namespace' => $namespace,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $stats = RuntimeExternalPayloadCleanupStat::query()
                ->whereKey($namespace)
                ->lockForUpdate()
                ->firstOrFail();
            $storageFailures = (int) $report['storage_driver_failures'];
            $blocked = (int) $report['blocked'];

            $stats->forceFill([
                'passes_total' => $stats->passes_total + 1,
                'deleted_references_total' => $stats->deleted_references_total + (int) $report['deleted_references'],
                'deleted_backing_objects_total' => $stats->deleted_backing_objects_total + (int) $report['deleted_backing_objects'],
                'shared_objects_preserved_total' => $stats->shared_objects_preserved_total + (int) $report['shared_objects_preserved'],
                'blocked_outcomes_total' => $stats->blocked_outcomes_total + $blocked,
                'storage_driver_failures_total' => $stats->storage_driver_failures_total + $storageFailures,
                'last_processed' => (int) $report['processed'],
                'last_deleted_references' => (int) $report['deleted_references'],
                'last_deleted_backing_objects' => (int) $report['deleted_backing_objects'],
                'last_shared_objects_preserved' => (int) $report['shared_objects_preserved'],
                'last_blocked_outcomes' => $blocked,
                'last_storage_driver_failures' => $storageFailures,
                'last_pass_status' => $storageFailures > 0 ? 'failed' : ($blocked > 0 ? 'blocked' : 'completed'),
                'last_completed_at' => now(),
                'last_storage_failure_at' => $storageFailures > 0 ? now() : $stats->last_storage_failure_at,
            ])->save();
        }, 3);
    }
}
