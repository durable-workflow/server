<?php

namespace Tests\Fixtures;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('tests.internal-child-workflow')]
class InternalChildWorkflow extends Workflow
{
    public function handle(string $name): array
    {
        return [
            'greeting' => sprintf('Hello from child, %s!', $name),
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
