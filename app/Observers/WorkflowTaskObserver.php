<?php

namespace App\Observers;

use App\Support\LongPollSignalStore;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\Facades\DB;
use Throwable;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\TimerTransportChunker;

class WorkflowTaskObserver
{
    public function created(WorkflowTask $task): void
    {
        app(LongPollSignalStore::class)->signalTask($task);
        $this->dispatchServiceModeTimer($task);
    }

    public function updated(WorkflowTask $task): void
    {
        app(LongPollSignalStore::class)->signalTask($task);

        if ($this->timerBecameDispatchable($task)) {
            $this->dispatchServiceModeTimer($task);
        }
    }

    public function deleted(WorkflowTask $task): void
    {
        app(LongPollSignalStore::class)->signalTask($task);
    }

    private function timerBecameDispatchable(WorkflowTask $task): bool
    {
        if (! $this->isReadyTimerTask($task)) {
            return false;
        }

        return $task->wasChanged('status')
            || $task->wasChanged('available_at')
            || $task->wasChanged('repair_count');
    }

    private function dispatchServiceModeTimer(WorkflowTask $task): void
    {
        if (! $this->shouldDispatchServiceModeTimer($task)) {
            return;
        }

        $taskId = (string) $task->id;

        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->publishServiceModeTimer($taskId));

            return;
        }

        $this->publishServiceModeTimer($taskId);
    }

    private function publishServiceModeTimer(string $taskId): void
    {
        try {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()->find($taskId);

            if (! $task instanceof WorkflowTask || ! $this->shouldDispatchServiceModeTimer($task)) {
                return;
            }

            $job = new RunTimerTask($taskId);

            if ($task->connection !== null) {
                $job->onConnection($task->connection);
            }

            // Timer jobs are server infrastructure work; external workflow
            // task queues remain HTTP-polled by user workers in service mode.
            if ($task->available_at !== null && $task->available_at->isFuture()) {
                $job->delay(TimerTransportChunker::cappedDispatchDelay($task->available_at, $task->connection));
            }

            app(BusDispatcher::class)->dispatch($job);
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    private function shouldDispatchServiceModeTimer(WorkflowTask $task): bool
    {
        return config('server.mode') === 'service'
            && config('workflows.v2.task_dispatch_mode') === 'poll'
            && $this->isReadyTimerTask($task);
    }

    private function isReadyTimerTask(WorkflowTask $task): bool
    {
        return ($task->task_type === TaskType::Timer || $task->task_type === TaskType::Timer->value)
            && ($task->status === TaskStatus::Ready || $task->status === TaskStatus::Ready->value);
    }
}
