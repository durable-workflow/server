<?php

namespace App\Observers;

use App\Models\WorkerRegistration;
use App\Models\WorkflowDurableStream;
use App\Models\WorkflowDurableStreamItem;
use App\Models\WorkflowInboundStream;
use App\Models\WorkflowInboundStreamItem;
use App\Support\NamespaceDurableStateQuota;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;

final class NamespaceDurableStateObserver
{
    public function __construct(
        private readonly NamespaceDurableStateQuota $quota,
    ) {}

    public function creating(Model $model): void
    {
        $resources = $this->resourcesFor($model);

        if ($resources === [] || ! $this->quota->mayConstrain($resources)) {
            return;
        }

        $this->quota->admitCreate($this->namespaceFor($model), $resources);
    }

    /** @return list<string> */
    private function resourcesFor(Model $model): array
    {
        if ($model instanceof WorkflowInstance) {
            return [NamespaceDurableStateQuota::WORKFLOW_INSTANCES];
        }

        if ($model instanceof WorkflowRun) {
            $resources = [NamespaceDurableStateQuota::WORKFLOW_RUNS];
            $status = $model->getAttribute('status');
            $runStatus = $status instanceof RunStatus
                ? $status
                : (is_string($status) ? RunStatus::tryFrom($status) : null);

            if ($runStatus === null || ! $runStatus->isTerminal()) {
                $resources[] = NamespaceDurableStateQuota::OPEN_WORKFLOW_RUNS;
            }

            return $resources;
        }

        if ($model instanceof WorkflowSchedule) {
            return [NamespaceDurableStateQuota::SCHEDULES];
        }

        if ($model instanceof WorkflowScheduleHistoryEvent) {
            return [NamespaceDurableStateQuota::SCHEDULE_HISTORY_EVENTS];
        }

        if ($model instanceof WorkerRegistration) {
            return [NamespaceDurableStateQuota::WORKER_REGISTRATIONS];
        }

        if ($model instanceof WorkflowHistoryEvent) {
            return [NamespaceDurableStateQuota::WORKFLOW_HISTORY_EVENTS];
        }

        if ($model instanceof WorkflowTask) {
            $resources = [NamespaceDurableStateQuota::WORKFLOW_TASKS];
            $status = $model->getAttribute('status');
            $taskStatus = $status instanceof TaskStatus
                ? $status
                : (is_string($status) ? TaskStatus::tryFrom($status) : null);

            if ($taskStatus === null || $taskStatus === TaskStatus::Ready) {
                $resources[] = NamespaceDurableStateQuota::PENDING_WORKFLOW_TASKS;
            }

            return $resources;
        }

        if ($model instanceof WorkflowTimer) {
            $resources = [NamespaceDurableStateQuota::WORKFLOW_TIMERS];
            $status = $model->getAttribute('status');
            $timerStatus = $status instanceof TimerStatus
                ? $status
                : (is_string($status) ? TimerStatus::tryFrom($status) : null);

            if ($timerStatus === null || $timerStatus === TimerStatus::Pending) {
                $resources[] = NamespaceDurableStateQuota::PENDING_WORKFLOW_TIMERS;
            }

            return $resources;
        }

        if ($model instanceof WorkflowRunWait) {
            $resources = [NamespaceDurableStateQuota::WORKFLOW_RUN_WAITS];

            if (($model->getAttribute('status') ?? 'open') === 'open') {
                $resources[] = NamespaceDurableStateQuota::OPEN_WORKFLOW_RUN_WAITS;
            }

            return $resources;
        }

        if ($model instanceof WorkflowCommand) {
            return [NamespaceDurableStateQuota::WORKFLOW_COMMANDS];
        }

        if ($model instanceof WorkflowDurableStream || $model instanceof WorkflowInboundStream) {
            return [NamespaceDurableStateQuota::WORKFLOW_STREAMS];
        }

        if ($model instanceof WorkflowDurableStreamItem || $model instanceof WorkflowInboundStreamItem) {
            return [NamespaceDurableStateQuota::WORKFLOW_STREAM_ITEMS];
        }

        return [];
    }

    private function namespaceFor(Model $model): string
    {
        $namespace = $model->getAttribute('namespace');

        if (is_string($namespace) && trim($namespace) !== '') {
            return $namespace;
        }

        $runId = $model->getAttribute('workflow_run_id');

        if (is_string($runId) && $runId !== '') {
            $namespace = WorkflowRun::query()->whereKey($runId)->value('namespace');

            if (is_string($namespace) && $namespace !== '') {
                return $namespace;
            }
        }

        $instanceId = $model->getAttribute('workflow_instance_id');

        if (is_string($instanceId) && $instanceId !== '') {
            $namespace = WorkflowInstance::query()->whereKey($instanceId)->value('namespace');

            if (is_string($namespace) && $namespace !== '') {
                return $namespace;
            }
        }

        $scheduleId = $model->getAttribute('workflow_schedule_id');

        if (is_string($scheduleId) && $scheduleId !== '') {
            $namespace = WorkflowSchedule::query()->whereKey($scheduleId)->value('namespace');

            if (is_string($namespace) && $namespace !== '') {
                return $namespace;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Namespace durable-state quota could not resolve ownership for [%s].',
            $model::class,
        ));
    }
}
