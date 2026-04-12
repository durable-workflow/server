<?php

namespace App\Observers;

use App\Support\NamespaceWorkflowScope;
use Workflow\V2\Models\WorkflowLink;

class WorkflowLinkObserver
{
    public function created(WorkflowLink $link): void
    {
        NamespaceWorkflowScope::bindLinkedChildWorkflow($link);
    }
}
