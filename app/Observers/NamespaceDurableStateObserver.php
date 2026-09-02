<?php

namespace App\Observers;

use App\Models\WorkerRegistration;
use App\Support\NamespaceDurableStateQuota;
use Illuminate\Database\Eloquent\Model;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;

final class NamespaceDurableStateObserver
{
    public function __construct(
        private readonly NamespaceDurableStateQuota $quota,
    ) {}

    public function creating(Model $model): void
    {
        $resources = $this->resourcesFor($model);

        if ($resources === []) {
            return;
        }

        $namespace = $model->getAttribute('namespace');
        $namespace = is_string($namespace) && trim($namespace) !== ''
            ? $namespace
            : (string) config('server.default_namespace', 'default');

        $this->quota->admitCreate($namespace, $resources);
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

        return [];
    }
}
