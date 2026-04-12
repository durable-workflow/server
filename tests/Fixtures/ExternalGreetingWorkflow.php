<?php

namespace Tests\Fixtures;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;
use function Workflow\V2\activity;

#[Type('tests.external-greeting-workflow')]
class ExternalGreetingWorkflow extends Workflow
{
    public function handle(string $name): array
    {
        return [
            'greeting' => activity(ExternalGreetingActivity::class, $name),
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
