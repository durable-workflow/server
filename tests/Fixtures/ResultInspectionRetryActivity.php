<?php

namespace Tests\Fixtures;

use Workflow\V2\Attributes\Type;

#[Type('tests.result-inspection-retry-activity')]
class ResultInspectionRetryActivity extends ExternalGreetingActivity
{
    public int $tries = 2;

    public function backoff(): int|array
    {
        return [0];
    }
}
