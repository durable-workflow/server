<?php

namespace App\Observers;

use App\Models\WorkflowNamespaceWorkflow;
use App\Support\LongPollSignalStore;

class WorkflowNamespaceWorkflowObserver
{
    public function created(WorkflowNamespaceWorkflow $binding): void
    {
        $this->signalQueues($binding);
    }

    public function updated(WorkflowNamespaceWorkflow $binding): void
    {
        $this->signalQueues($binding);
    }

    private function signalQueues(WorkflowNamespaceWorkflow $binding): void
    {
        if (! is_string($binding->workflow_instance_id) || $binding->workflow_instance_id === '') {
            return;
        }

        app(LongPollSignalStore::class)->signalWorkflowTaskQueuesForWorkflow(
            $binding->workflow_instance_id,
            is_string($binding->namespace) ? $binding->namespace : null,
        );
    }
}
