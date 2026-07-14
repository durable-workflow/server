<?php

namespace App\Support;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\Facades\DB;
use Throwable;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\TimerTransportChunker;

class ServiceModeTimerDispatcher
{
    private const REQUEST_PUBLISHED_TIMER_TASK_IDS = 'service_mode_timer_dispatcher.published_timer_task_ids';

    public function dispatch(WorkflowTask $task): void
    {
        if (! $this->shouldDispatch($task)) {
            return;
        }

        $taskId = (string) $task->id;

        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->publish($taskId));

            return;
        }

        $this->publish($taskId);
    }

    /**
     * @param  iterable<array-key, mixed>  $taskIds
     */
    public function dispatchCreatedTaskIds(iterable $taskIds): void
    {
        if (! $this->isServiceModePoll()) {
            return;
        }

        foreach ($taskIds as $taskId) {
            if (is_int($taskId)) {
                $taskId = (string) $taskId;
            }

            if (! is_string($taskId) || trim($taskId) === '') {
                continue;
            }

            $this->publish($taskId);
        }
    }

    private function publish(string $taskId): void
    {
        $taskId = trim($taskId);

        if ($taskId === '' || $this->wasPublishedInCurrentRequest($taskId)) {
            return;
        }

        try {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()->find($taskId);

            if (! $task instanceof WorkflowTask || ! $this->shouldDispatch($task)) {
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
            $this->rememberPublishedInCurrentRequest($taskId);
        } catch (Throwable $throwable) {
            report($throwable);

            throw $throwable;
        }
    }

    private function shouldDispatch(WorkflowTask $task): bool
    {
        return $this->isServiceModePoll()
            && $this->isReadyTimerTask($task);
    }

    private function isServiceModePoll(): bool
    {
        return config('server.mode') === 'service'
            && config('workflows.v2.task_dispatch_mode') === 'poll';
    }

    private function isReadyTimerTask(WorkflowTask $task): bool
    {
        return ($task->task_type === TaskType::Timer || $task->task_type === TaskType::Timer->value)
            && ($task->status === TaskStatus::Ready || $task->status === TaskStatus::Ready->value);
    }

    private function wasPublishedInCurrentRequest(string $taskId): bool
    {
        $publishedTaskIds = $this->publishedTaskIdsForCurrentRequest();

        return isset($publishedTaskIds[$taskId]);
    }

    private function rememberPublishedInCurrentRequest(string $taskId): void
    {
        if (! app()->bound('request')) {
            return;
        }

        $publishedTaskIds = $this->publishedTaskIdsForCurrentRequest();
        $publishedTaskIds[$taskId] = true;

        request()->attributes->set(self::REQUEST_PUBLISHED_TIMER_TASK_IDS, $publishedTaskIds);
    }

    /**
     * @return array<string, true>
     */
    private function publishedTaskIdsForCurrentRequest(): array
    {
        if (! app()->bound('request')) {
            return [];
        }

        $publishedTaskIds = request()->attributes->get(self::REQUEST_PUBLISHED_TIMER_TASK_IDS, []);

        if (! is_array($publishedTaskIds)) {
            return [];
        }

        return $publishedTaskIds;
    }
}
