<?php

namespace App\Observers;

use App\Support\NamespaceWorkflowScope;
use Workflow\V2\Models\WorkflowRunLineageEntry;

class WorkflowRunLineageEntryObserver
{
    public function created(WorkflowRunLineageEntry $entry): void
    {
        NamespaceWorkflowScope::bindChildWorkflowLineage($entry);
    }

    public function updated(WorkflowRunLineageEntry $entry): void
    {
        NamespaceWorkflowScope::bindChildWorkflowLineage($entry);
    }
}
