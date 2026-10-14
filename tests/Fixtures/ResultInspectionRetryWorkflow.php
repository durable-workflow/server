<?php

namespace Tests\Fixtures;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

use function Workflow\V2\activity;

#[Type('tests.result-inspection-retry-workflow')]
class ResultInspectionRetryWorkflow extends Workflow
{
    public function handle(string $name): string
    {
        return activity(ResultInspectionRetryActivity::class, $name);
    }
}
