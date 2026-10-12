<?php

namespace Tests\Fixtures;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Exceptions\WorkflowCancelledException;
use Workflow\V2\Exceptions\WorkflowTerminatedException;
use Workflow\V2\Workflow;

use function Workflow\V2\child;

#[Type('tests.post-commit-parent')]
class PostCommitParentWorkflow extends Workflow
{
    public function handle(): string
    {
        try {
            return child(PostCommitChildWorkflow::class);
        } catch (WorkflowCancelledException|WorkflowTerminatedException $exception) {
            return $exception::class;
        }
    }
}
