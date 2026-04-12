<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Workflow\V2\Support\WorkerCompatibilityFleet;

final class StandaloneWorkerFleet
{
    public function record(string $namespace, string $workerId, ?string $taskQueue, ?string $buildId): void
    {
        $this->withinNamespace($namespace, static function () use ($workerId, $taskQueue, $buildId): void {
            WorkerCompatibilityFleet::record(
                self::markers($buildId),
                connection: null,
                queue: $taskQueue,
                workerId: $workerId,
            );
        });
    }

    /**
     * @return array{
     *     namespace: string,
     *     active_workers: int,
     *     active_worker_scopes: int,
     *     queues: list<string>,
     *     build_ids: list<string>,
     *     workers: list<array{
     *         worker_id: string,
     *         queues: list<string>,
     *         build_ids: list<string>,
     *         recorded_at: string|null,
     *         expires_at: string|null,
     *     }>,
     * }
     */
    public function summary(string $namespace): array
    {
        return $this->withinNamespace($namespace, static function () use ($namespace): array {
            $details = WorkerCompatibilityFleet::details(null);

            $workers = collect($details)
                ->groupBy(static fn (array $snapshot): string => (string) ($snapshot['worker_id'] ?? ''))
                ->filter(static fn ($snapshots, string $workerId): bool => $workerId !== '')
                ->map(static function ($snapshots, string $workerId): array {
                    $recordedAt = $snapshots
                        ->pluck('recorded_at')
                        ->filter(static fn (mixed $value): bool => $value instanceof Carbon)
                        ->sortByDesc(static fn (Carbon $value): int => $value->getTimestamp())
                        ->first();
                    $expiresAt = $snapshots
                        ->pluck('expires_at')
                        ->filter(static fn (mixed $value): bool => $value instanceof Carbon)
                        ->sortByDesc(static fn (Carbon $value): int => $value->getTimestamp())
                        ->first();

                    return [
                        'worker_id' => $workerId,
                        'queues' => self::uniqueStrings($snapshots->pluck('queue')->all()),
                        'build_ids' => self::uniqueStrings($snapshots->flatMap(
                            static fn (array $snapshot): array => is_array($snapshot['supported'] ?? null)
                                ? $snapshot['supported']
                                : [],
                        )->all()),
                        'recorded_at' => $recordedAt?->toJSON(),
                        'expires_at' => $expiresAt?->toJSON(),
                    ];
                })
                ->sortBy('worker_id')
                ->values()
                ->all();

            return [
                'namespace' => $namespace,
                'active_workers' => count($workers),
                'active_worker_scopes' => count($details),
                'queues' => self::uniqueStrings(array_map(
                    static fn (array $snapshot): mixed => $snapshot['queue'] ?? null,
                    $details,
                )),
                'build_ids' => self::uniqueStrings($details === []
                    ? []
                    : array_merge(
                        ...array_map(
                            static fn (array $snapshot): array => is_array($snapshot['supported'] ?? null)
                                ? $snapshot['supported']
                                : [],
                            $details,
                        ),
                    )),
                'workers' => $workers,
            ];
        });
    }

    /**
     * @return list<string>
     */
    private static function markers(?string $buildId): array
    {
        return self::uniqueStrings([$buildId]);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private static function uniqueStrings(array $values): array
    {
        $strings = array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
                    ? trim($value)
                    : null,
                $values,
            ),
            static fn (?string $value): bool => $value !== null,
        )));

        sort($strings);

        return $strings;
    }

    private function withinNamespace(string $namespace, callable $callback): mixed
    {
        $previousNamespace = config('workflows.v2.compatibility.namespace');

        config([
            'workflows.v2.compatibility.namespace' => $namespace,
        ]);

        try {
            return $callback();
        } finally {
            config([
                'workflows.v2.compatibility.namespace' => $previousNamespace,
            ]);
        }
    }
}
