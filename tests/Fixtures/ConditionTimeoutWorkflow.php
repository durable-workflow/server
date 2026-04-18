<?php

namespace Tests\Fixtures;

use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

use function Workflow\V2\await;

#[Type('tests.condition-timeout-workflow')]
class ConditionTimeoutWorkflow extends Workflow
{
    private bool $approved = false;

    public function handle(): array
    {
        $approved = await(
            fn (): bool => $this->approved,
            timeout: 5,
            conditionKey: 'approval.ready',
        );

        return [
            'approved' => $approved,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }

    #[UpdateMethod]
    public function approve(bool $approved = true): array
    {
        $this->approved = $approved;

        return [
            'approved' => $this->approved,
        ];
    }
}
