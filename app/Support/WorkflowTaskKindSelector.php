<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;

final class WorkflowTaskKindSelector
{
    private const TABLE = 'workflow_task_poll_cursors';

    private const WORKFLOW = 'workflow';

    private const UPDATE_VALIDATION = 'update_validation';

    /**
     * Choose and lease at most one task while durably alternating the first
     * claim attempt when both multiplexed task kinds are requested.
     *
     * @param  list<string>  $taskKinds
     * @param  Closure(string): array{task: array<string, mixed>|null, poll_status: string, next_probe_at: \DateTimeInterface|null}  $claim
     * @return array{task: array<string, mixed>|null, poll_status: string, next_probe_at: \DateTimeInterface|null}
     */
    public function select(
        string $namespace,
        string $taskQueue,
        array $taskKinds,
        Closure $claim,
    ): array {
        if (! $this->isMultiplexed($taskKinds)) {
            return $this->claimInOrder($taskKinds, $claim);
        }

        return DB::transaction(function () use ($namespace, $taskQueue, $claim): array {
            $now = now();

            DB::table(self::TABLE)->insertOrIgnore([
                'namespace' => $namespace,
                'task_queue' => $taskQueue,
                'next_task_kind' => self::UPDATE_VALIDATION,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $nextTaskKind = DB::table(self::TABLE)
                ->where('namespace', $namespace)
                ->where('task_queue', $taskQueue)
                ->lockForUpdate()
                ->value('next_task_kind');
            $first = $nextTaskKind === self::WORKFLOW
                ? self::WORKFLOW
                : self::UPDATE_VALIDATION;
            $second = $this->otherKind($first);
            $result = $this->claimInOrder([$first, $second], $claim);
            $leasedKind = $result['task']['task_kind'] ?? null;

            if (is_string($leasedKind) && in_array($leasedKind, [self::WORKFLOW, self::UPDATE_VALIDATION], true)) {
                DB::table(self::TABLE)
                    ->where('namespace', $namespace)
                    ->where('task_queue', $taskQueue)
                    ->update([
                        'next_task_kind' => $this->otherKind($leasedKind),
                        'updated_at' => now(),
                    ]);
            }

            return $result;
        }, 3);
    }

    /**
     * @param  list<string>  $taskKinds
     */
    private function isMultiplexed(array $taskKinds): bool
    {
        return in_array(self::WORKFLOW, $taskKinds, true)
            && in_array(self::UPDATE_VALIDATION, $taskKinds, true);
    }

    /**
     * @param  list<string>  $taskKinds
     * @param  Closure(string): array{task: array<string, mixed>|null, poll_status: string, next_probe_at: \DateTimeInterface|null}  $claim
     * @return array{task: array<string, mixed>|null, poll_status: string, next_probe_at: \DateTimeInterface|null}
     */
    private function claimInOrder(array $taskKinds, Closure $claim): array
    {
        $workflowResult = null;
        $lastResult = [
            'task' => null,
            'poll_status' => 'empty',
            'next_probe_at' => null,
        ];

        foreach ($taskKinds as $taskKind) {
            $result = $claim($taskKind);

            if (is_array($result['task'] ?? null)) {
                return $result;
            }

            if ($taskKind === self::WORKFLOW) {
                $workflowResult = $result;
            }

            $lastResult = $result;
        }

        return $workflowResult ?? $lastResult;
    }

    private function otherKind(string $taskKind): string
    {
        return $taskKind === self::WORKFLOW
            ? self::UPDATE_VALIDATION
            : self::WORKFLOW;
    }
}
