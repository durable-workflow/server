<?php

namespace Tests\Fixtures;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;
use function Workflow\V2\child;

#[Type('tests.internal-parent-workflow')]
class InternalParentWorkflow extends Workflow
{
    public function handle(string $name): array
    {
        return [
            'child' => child(InternalChildWorkflow::class, $name),
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
